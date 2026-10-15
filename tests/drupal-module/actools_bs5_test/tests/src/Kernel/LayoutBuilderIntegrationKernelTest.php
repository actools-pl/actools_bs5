<?php

declare(strict_types=1);

namespace Drupal\Tests\actools_bs5_test\Kernel;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Layout\LayoutPluginManagerInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Exercises the six theme layouts through core Layout Builder's render pipeline.
 *
 * Pixel widths and browser drag/drop behavior require separate browser tests.
 */
#[Group('actools_bs5')]
#[RunTestsInSeparateProcesses]
final class LayoutBuilderIntegrationKernelTest extends KernelTestBase {

  protected static $modules = [
    'system', 'user', 'field', 'field_ui', 'file', 'block', 'contextual', 'path_alias',
    'layout_discovery', 'layout_builder', 'actools_bs5_test',
  ];

  private LayoutPluginManagerInterface $layoutManager;

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('path_alias');
    $this->installConfig(['system', 'user']);
    $this->container->get('theme_installer')->install(['actools_bs5']);
    $this->config('system.theme')->set('default', 'actools_bs5')->save();
    $active = $this->container->get('theme.initialization')->getActiveThemeByName('actools_bs5');
    $this->container->get('theme.manager')->setActiveTheme($active);
    $this->layoutManager = $this->container->get('plugin.manager.core.layout');
    $this->layoutManager->clearCachedDefinitions();
    $this->container->get('theme.registry')->reset();
  }

  /**
   * Each theme section is available to core with its intended region contract.
   */
  #[DataProvider('layoutCases')]
  public function testThemeSectionsAreDiscovered(string $layout_id, array $regions, string $modifier, bool $contained): void {
    $definition = $this->layoutManager->getDefinition($layout_id);
    $this->assertSame('actools_bs5', $definition->getProvider());
    $this->assertSame($regions, $definition->getRegionNames());
    $this->assertSame($regions[0], $definition->getDefaultRegion());
    $this->assertSame('actools_bs5/layout-builder', $definition->getLibrary());
    $this->assertFalse($this->container->get('module_handler')->moduleExists('canvas'));
  }

  /**
   * Actual SectionComponent blocks reach every region and bubble cache metadata.
   */
  #[DataProvider('layoutCases')]
  public function testPublishedSectionsRenderBlocksAndCacheMetadata(string $layout_id, array $regions, string $modifier, bool $contained): void {
    $section = $this->section($layout_id, $regions);
    // This is the serialization used by Layout Builder's section storage.
    $section = Section::fromArray($section->toArray());
    $build = $section->toRenderArray([], FALSE);
    $this->assertFalse($build['#in_preview']);
    $result = (string) $this->container->get('renderer')->renderRoot($build);
    $xpath = $this->layoutXpath($result);
    $this->assertLayoutMarkup($xpath, $regions, $modifier, $contained);

    $metadata = CacheableMetadata::createFromRenderArray($build);
    $this->assertContains('actools_bs5_test:layout_probe', $metadata->getCacheTags());
    $this->assertContains('url.path', $metadata->getCacheContexts());
    $this->assertSame(3600, $metadata->getCacheMaxAge());
    $this->assertContains('actools_bs5/layout-builder', $build['#attached']['library']);
  }

  /**
   * Core preview output and editor-supplied attributes survive theme rendering.
   */
  #[DataProvider('layoutCases')]
  public function testPreviewPreservesEditorAttributes(string $layout_id, array $regions, string $modifier, bool $contained): void {
    $build = $this->section($layout_id, $regions)->toRenderArray([], TRUE);
    $this->assertTrue($build['#in_preview']);
    $build['#attributes']['data-actools-layout-editor-probe'] = $layout_id;
    foreach ($regions as $region) {
      $build[$region]['#attributes']['data-actools-region-editor-probe'] = $region;
    }
    $result = (string) $this->container->get('renderer')->renderRoot($build);
    $xpath = $this->layoutXpath($result);
    $this->assertLayoutMarkup($xpath, $regions, $modifier, $contained);
    $this->assertSame(1, $xpath->query('//*[@data-actools-layout-editor-probe="' . $layout_id . '"]')->length);
    foreach ($regions as $region) {
      $this->assertSame(1, $xpath->query('//*[@data-actools-region-editor-probe="' . $region . '"]')->length);
    }
    // This attribute is supplied by core's real preview block subscriber.
    $this->assertSame(count($regions), $xpath->query('//*[@data-layout-content-preview-placeholder-label]')->length);
    $this->assertSame(0, CacheableMetadata::createFromRenderArray($build)->getCacheMaxAge());
  }

  /**
   * Reordered components follow weight within a region without moving regions.
   */
  public function testComponentWeightsPreserveRegionAndSourceOrder(): void {
    $later = $this->component('first', 'Later first-region block')->setWeight(20);
    $earlier = $this->component('first', 'Earlier first-region block')->setWeight(-20);
    $second = $this->component('second', 'Second-region block')->setWeight(-100);
    $section = new Section('actools_bs5_twocol_equal', [], [$later, $second, $earlier]);
    $build = $section->toRenderArray();
    $result = (string) $this->container->get('renderer')->renderRoot($build);
    $xpath = $this->layoutXpath($result);
    $first = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " layout__region--first ")]')->item(0);
    $this->assertNotNull($first);
    $text = $first->textContent;
    $this->assertLessThan(strpos($text, 'Later first-region block'), strpos($text, 'Earlier first-region block'));
    $this->assertStringNotContainsString('Second-region block', $text);
  }

  public static function layoutCases(): array {
    return [
      'one column contained' => ['actools_bs5_onecol_contained', ['content'], 'actools-layout--onecol', TRUE],
      'one column full available width' => ['actools_bs5_onecol_full', ['content'], 'actools-layout--onecol', FALSE],
      'two columns equal' => ['actools_bs5_twocol_equal', ['first', 'second'], 'actools-layout--twocol-equal', TRUE],
      'two columns narrow wide' => ['actools_bs5_twocol_narrow_wide', ['first', 'second'], 'actools-layout--twocol-narrow-wide', TRUE],
      'two columns wide narrow' => ['actools_bs5_twocol_wide_narrow', ['first', 'second'], 'actools-layout--twocol-wide-narrow', TRUE],
      'three columns equal' => ['actools_bs5_threecol_equal', ['first', 'second', 'third'], 'actools-layout--threecol-equal', TRUE],
    ];
  }

  private function section(string $layout_id, array $regions): Section {
    $components = [];
    foreach ($regions as $region) {
      $components[] = $this->component($region, 'Content for ' . $region);
    }
    return new Section($layout_id, ['label' => 'Test section'], $components);
  }

  private function component(string $region, string $text): SectionComponent {
    return new SectionComponent($this->container->get('uuid')->generate(), $region, [
      'id' => 'actools_bs5_layout_probe',
      'label' => 'Probe ' . $region,
      'label_display' => FALSE,
      'provider' => 'actools_bs5_test',
      'probe_text' => $text,
    ]);
  }

  private function assertLayoutMarkup(\DOMXPath $xpath, array $regions, string $modifier, bool $contained): void {
    $wrappers = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " actools-layout ")]');
    $this->assertSame(1, $wrappers->length);
    $wrapper = $wrappers->item(0);
    $classes = explode(' ', $wrapper->getAttribute('class'));
    $this->assertContains($modifier, $classes);
    $this->assertSame($contained, in_array('container', $classes, TRUE));
    $this->assertContains($contained ? 'actools-layout--contained' : 'actools-layout--full', $classes);
    $region_nodes = $xpath->query('./div[contains(concat(" ", normalize-space(@class), " "), " actools-layout__grid ")]/div[contains(concat(" ", normalize-space(@class), " "), " layout__region ")]', $wrapper);
    $this->assertSame(count($regions), $region_nodes->length);
    foreach ($regions as $index => $region) {
      $region_node = $region_nodes->item($index);
      $this->assertStringContainsString('layout__region--' . $region, $region_node->getAttribute('class'));
      $this->assertStringContainsString('Content for ' . $region, $region_node->textContent);
      $this->assertSame(1, $xpath->query('.//*[@data-actools-block-probe]', $region_node)->length);
    }
  }

  private function layoutXpath(string $html): \DOMXPath {
    $previous = libxml_use_internal_errors(TRUE);
    $document = new \DOMDocument();
    $document->loadHTML('<!DOCTYPE html><html><body>' . $html . '</body></html>');
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    return new \DOMXPath($document);
  }

}
