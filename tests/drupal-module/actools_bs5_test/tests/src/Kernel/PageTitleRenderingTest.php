<?php

declare(strict_types=1);

namespace Drupal\Tests\actools_bs5_test\Kernel;

use Drupal\Core\Template\Attribute;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Exercises Drupal page-title rendering with SDC prop validation enabled.
 */
#[Group('actools_bs5')]
#[RunTestsInSeparateProcesses]
final class PageTitleRenderingTest extends KernelTestBase {

  protected static $modules = ['system', 'file', 'user'];

  protected function setUp(): void {
    parent::setUp();
    $this->assertSame('1', ini_get('zend.assertions'), 'Run this rendering regression with -d zend.assertions=1.');
    $this->assertTrue(class_exists(\JsonSchema\Validator::class), 'The core-dev JSON Schema validator is required.');
    $this->installConfig(['system']);
    $this->container->get('theme_installer')->install(['actools_bs5']);
    $this->config('system.theme')->set('default', 'actools_bs5')->save();
    $active = $this->container->get('theme.initialization')->getActiveThemeByName('actools_bs5');
    $this->container->get('theme.manager')->setActiveTheme($active);
    $this->container->get('plugin.manager.sdc')->clearCachedDefinitions();
  }

  /**
   * A core page-title element can use the theme's default heading appearance.
   */
  public function testDrupalPageTitleRendersWithDefaultHeadingSize(): void {
    // Exercise the real theme-hook wrapper and its page-title -> heading SDC
    // include. The default empty display becomes text_size='', which must pass
    // heading schema validation before its Twig defaults can run.
    $build = ['#type' => 'page_title', '#title' => 'Physics resources'];
    $html = (string) $this->container->get('renderer')->renderRoot($build);
    $xpath = $this->pageTitleXpath($html);
    $this->assertSame(1, $xpath->query('//h1')->length);
    $this->assertSame('Physics resources', trim($xpath->evaluate('string(//h1)')));
  }

  /**
   * An explicit page-title display size still reaches the nested heading.
   */
  public function testExplicitPageTitleDisplaySizeRemainsAvailable(): void {
    $build = [
      '#type' => 'inline_template',
      '#template' => "{{ include('actools_bs5:page-title', props, false) }}",
      '#context' => ['props' => [
        'title' => 'Teaching notes',
        'display' => 'display-3',
        'title_attributes' => new Attribute(),
      ]],
    ];
    $html = (string) $this->container->get('renderer')->renderRoot($build);
    $xpath = $this->pageTitleXpath($html);
    $this->assertSame('Teaching notes', trim($xpath->evaluate('string(//h1)')));
    $this->assertStringContainsString('display-3', $xpath->evaluate('string(//h1/@class)'));
  }

  /**
   * The heading's documented empty optional modifiers pass schema validation.
   */
  public function testHeadingAcceptsItsEmptyOptionalModifiers(): void {
    $build = [
      '#type' => 'inline_template',
      '#template' => "{{ include('actools_bs5:heading', props, false) }}",
      '#context' => ['props' => [
        'heading_text' => 'Thermal physics',
        'level' => 2,
        'text_size' => '',
        'text_color' => '',
        'align' => '',
        'font_weight' => '',
        'font_style' => '',
      ]],
    ];
    $html = (string) $this->container->get('renderer')->renderRoot($build);
    $this->assertSame('Thermal physics', trim($this->pageTitleXpath($html)->evaluate('string(//h2)')));
  }

  private function pageTitleXpath(string $html): \DOMXPath {
    $previous = libxml_use_internal_errors(TRUE);
    $document = new \DOMDocument();
    $document->loadHTML('<!DOCTYPE html><html><body>' . $html . '</body></html>');
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    return new \DOMXPath($document);
  }

}
