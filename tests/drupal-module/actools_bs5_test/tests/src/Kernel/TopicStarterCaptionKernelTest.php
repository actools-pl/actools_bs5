<?php

declare(strict_types=1);

namespace Drupal\Tests\actools_bs5_test\Kernel;

use Drupal\block_content\Entity\BlockContent;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Recipe\Recipe;
use Drupal\Core\Recipe\RecipeRunner;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\file\Entity\File;
use Drupal\KernelTests\KernelTestBase;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Renders optional captions through the real recipe, field and block pipelines.
 */
#[Group('actools_bs5')]
#[RunTestsInSeparateProcesses]
final class TopicStarterCaptionKernelTest extends KernelTestBase {

  protected static $modules = [
    'system', 'user', 'field', 'field_ui', 'text', 'filter', 'file', 'image',
    'breakpoint', 'responsive_image', 'options', 'node', 'block', 'block_content',
    'contextual', 'path_alias', 'layout_discovery', 'layout_builder',
    'actools_bs5_test', 'actools_caption_test',
  ];

  protected function setUp(): void {
    parent::setUp();
    foreach (['user', 'file', 'node', 'block_content', 'path_alias'] as $entity_type) {
      $this->installEntitySchema($entity_type);
    }
    $this->installSchema('user', ['users_data']);
    $this->installSchema('file', ['file_usage']);
    $this->installSchema('node', ['node_access']);
    $this->installSchema('layout_builder', ['inline_block_usage']);
    $this->installConfig(['system', 'user', 'node', 'file', 'image', 'filter', 'block_content']);
    $this->config('system.image')->set('toolkit', 'gd')->save();
    $this->container->get('theme_installer')->install(['actools_bs5']);
    $path = $this->container->get('extension.list.theme')->getPath('actools_bs5');
    RecipeRunner::processRecipe(Recipe::createFromDirectory(DRUPAL_ROOT . '/' . $path . '/recipes/actools_topic_starter'));
    $this->container = \Drupal::getContainer();
    $this->config('system.theme')->set('default', 'actools_bs5')->save();
    $active = $this->container->get('theme.initialization')->getActiveThemeByName('actools_bs5');
    $this->container->get('theme.manager')->setActiveTheme($active);
    $this->container->get('theme.registry')->reset();
    $this->container->get('router.builder')->rebuild();
    $directory = 'public://actools-caption-tests';
    $this->assertTrue($this->container->get('file_system')->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY));
  }

  /**
   * Plain captions are escaped inside a semantic figure without changing media.
   */
  public function testCaptionEscapingAndOriginalImageLink(): void {
    $caption = 'Curvature <script>alert("caption")</script> & pressure';
    $block = $this->captionBlock($caption);
    [$html, $metadata] = $this->renderCaptionBlock($block, new AnonymousUserSession());
    $xpath = $this->captionDocument($html);
    $captions = $xpath->query('//figure[contains(@class, "actools-text-image__media")]/figcaption');
    $this->assertSame(1, $captions->length);
    $this->assertSame($caption, trim($captions->item(0)->textContent));
    $this->assertSame(0, $xpath->query('//figcaption//script')->length);
    $this->assertStringContainsString('&lt;script&gt;', $html);
    $images = $xpath->query('//figure[contains(@class, "actools-text-image__media")]//a//img[@alt="Water meniscus diagram"]');
    $this->assertSame(1, $images->length);
    $image = $images->item(0);
    $this->assertNotSame('', $image->getAttribute('srcset'));
    $link = $xpath->query('ancestor::a[1]', $image)->item(0);
    $this->assertInstanceOf(\DOMElement::class, $link);
    $original = $this->container->get('file_url_generator')->generateString($block->get('field_actools_image')->entity->getFileUri());
    $this->assertSame(rawurldecode((string) parse_url($original, PHP_URL_PATH)), rawurldecode((string) parse_url($link->getAttribute('href'), PHP_URL_PATH)));
    $this->assertStringNotContainsString('/styles/', $link->getAttribute('href'));
    $this->assertContains('block_content:' . $block->id(), $metadata->getCacheTags());
    $this->assertContains('config:responsive_image.styles.actools_diagram', $metadata->getCacheTags());
  }

  /**
   * Existing image blocks need no caption and have no empty figcaption wrapper.
   */
  public function testEmptyCaptionLeavesImageAndBodyUnchanged(): void {
    $block = $this->captionBlock('');
    [$html] = $this->renderCaptionBlock($block, new AnonymousUserSession());
    $xpath = $this->captionDocument($html);
    $this->assertSame(1, $xpath->query('//figure[contains(@class, "actools-text-image__media")]//img')->length);
    $this->assertSame(0, $xpath->query('//figcaption')->length);
    $this->assertStringContainsString('Explain the meniscus.', $html);
  }

  /**
   * A stored caption without an image produces neither caption nor media gap.
   */
  public function testCaptionIsNotRenderedWithoutAnImage(): void {
    $block = $this->captionBlock('A caption awaiting its diagram', FALSE);
    [$html] = $this->renderCaptionBlock($block, new AnonymousUserSession());
    $xpath = $this->captionDocument($html);
    $this->assertSame(0, $xpath->query('//figure | //figcaption')->length);
    $this->assertStringNotContainsString('A caption awaiting its diagram', $html);
    $this->assertStringContainsString('actools-text-image--text-only', $html);
    $this->assertStringContainsString('Explain the meniscus.', $html);
  }

  /**
   * Removing a caption from the view display never exposes its stored value.
   */
  public function testDisplayHiddenCaptionStaysHidden(): void {
    $block = $this->captionBlock('Display-hidden caption');
    $display = EntityViewDisplay::load('block_content.actools_text_image.default');
    $display->removeComponent('field_actools_caption')->save();
    [$html] = $this->renderCaptionBlock($block, new AnonymousUserSession());
    $this->assertStringNotContainsString('Display-hidden caption', $html);
    $this->assertSame(0, $this->captionDocument($html)->query('//figcaption')->length);
  }

  /**
   * Deferred access checks gate markup and descendants before the field template.
   */
  public function testDeferredCaptionAccessControlsMarkupAndAttachments(): void {
    foreach (['allowed', 'forbidden', 'neutral', 'boolean_denied'] as $mode) {
      $block = $this->captionBlock('Protected caption ' . $mode);
      $this->container->get('state')->set('actools_caption_test.access_probe', ['block_id' => $block->id(), 'mode' => $mode]);
      [$html, $metadata] = $this->renderCaptionBlock($block, new AnonymousUserSession());
      $captions = $this->captionDocument($html)->query('//figcaption');
      if ($mode === 'allowed') {
        $this->assertSame(1, $captions->length);
        $this->assertStringContainsString('Protected caption allowed', $html);
        $this->assertSame('Caption-only attachment', $metadata->getAttachments()['drupalSettings']['actoolsCaptionProbe'] ?? NULL);
      }
      else {
        $this->assertSame(0, $captions->length);
        $this->assertStringNotContainsString('Protected caption ' . $mode, $html);
        $this->assertArrayNotHasKey('actoolsCaptionProbe', $metadata->getAttachments()['drupalSettings'] ?? []);
      }
      $this->assertContains('actools_caption_element:probe', $metadata->getCacheTags());
      if ($mode !== 'boolean_denied') {
        $this->assertContains('actools_caption_access:' . $mode, $metadata->getCacheTags());
      }
    }
  }

  /**
   * Warm cache transitions between equal-permission readers preserve access.
   */
  public function testCaptionAccessVariesByViewerAcrossWarmCaches(): void {
    $allowed = User::create(['uid' => 2, 'name' => 'Allowed caption reader', 'status' => 1]);
    $allowed->save();
    $denied = User::create(['uid' => 3, 'name' => 'Denied caption reader', 'status' => 1]);
    $denied->save();
    $block = $this->captionBlock('Viewer-specific caption');
    $this->container->get('state')->set('actools_caption_test.access_probe', ['block_id' => $block->id(), 'mode' => 'viewer']);
    foreach ([$allowed, $denied, $allowed, $denied] as $viewer) {
      [$html, $metadata] = $this->renderCaptionBlock($block, $viewer);
      $this->assertSame((int) $viewer->id() === 2 ? 1 : 0, $this->captionDocument($html)->query('//figcaption')->length);
      $this->assertContains('user', $metadata->getCacheContexts());
      if ((int) $viewer->id() !== 2) {
        $this->assertStringNotContainsString('Viewer-specific caption', $html);
        $this->assertArrayNotHasKey('actoolsCaptionProbe', $metadata->getAttachments()['drupalSettings'] ?? []);
      }
    }
  }

  /**
   * An image's deferred denial also removes its otherwise accessible caption.
   */
  public function testDeferredImageDenialSuppressesFigureAndCaption(): void {
    $allowed = User::create(['uid' => 2, 'name' => 'Allowed image reader', 'status' => 1]);
    $allowed->save();
    $denied = User::create(['uid' => 3, 'name' => 'Denied image reader', 'status' => 1]);
    $denied->save();
    $block = $this->captionBlock('Caption of an access-controlled diagram');
    $this->container->get('state')->set('actools_caption_test.image_access_probe', ['block_id' => $block->id(), 'mode' => 'viewer']);
    foreach ([$allowed, $denied, $allowed, $denied] as $viewer) {
      [$html, $metadata] = $this->renderCaptionBlock($block, $viewer);
      $xpath = $this->captionDocument($html);
      if ((int) $viewer->id() === 2) {
        $this->assertSame(1, $xpath->query('//figure[contains(@class, "actools-text-image__media")]/figcaption')->length);
        $this->assertStringContainsString('Caption of an access-controlled diagram', $html);
        $this->assertSame('Image-only attachment', $metadata->getAttachments()['drupalSettings']['actoolsImageProbe'] ?? NULL);
      }
      else {
        $this->assertSame(0, $xpath->query('//figure | //figcaption')->length);
        $this->assertStringNotContainsString('Caption of an access-controlled diagram', $html);
        $this->assertStringContainsString('actools-text-image--text-only', $html);
        $this->assertArrayNotHasKey('actoolsImageProbe', $metadata->getAttachments()['drupalSettings'] ?? []);
      }
      $this->assertContains('user', $metadata->getCacheContexts());
      $this->assertContains('actools_image_element:caption_probe', $metadata->getCacheTags());
    }
  }

  private function captionBlock(string $caption, bool $with_image = TRUE): BlockContent {
    $block = BlockContent::create([
      'type' => 'actools_text_image', 'info' => 'Caption example', 'status' => 1,
      'reusable' => TRUE, 'field_actools_heading' => 'Capillary pressure',
      'field_actools_body' => ['value' => 'Explain the meniscus.', 'format' => 'plain_text'],
      'field_actools_position' => 'right', 'field_actools_caption' => $caption,
    ]);
    if ($with_image) {
      $uri = 'public://actools-caption-tests/' . $this->container->get('uuid')->generate() . '.png';
      $image = imagecreatetruecolor(32, 16);
      imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
      $this->assertTrue(imagepng($image, $uri));
      imagedestroy($image);
      $file = File::create(['uri' => $uri, 'status' => 1]);
      $file->save();
      $block->set('field_actools_image', ['target_id' => $file->id(), 'alt' => 'Water meniscus diagram', 'width' => 32, 'height' => 16]);
    }
    $block->save();
    return $block;
  }

  /**
   * @return array{0: string, 1: \Drupal\Core\Render\BubbleableMetadata}
   *   HTML and metadata from a real reusable Layout Builder block.
   */
  private function renderCaptionBlock(BlockContent $block, AccountInterface $viewer): array {
    $switcher = $this->container->get('account_switcher');
    $switcher->switchTo($viewer);
    try {
      $this->container->get('entity_type.manager')->getStorage('block_content')->resetCache([$block->id()]);
      $component = new SectionComponent($block->uuid(), 'content', [
        'id' => 'block_content:' . $block->uuid(), 'label' => $block->label(),
        'label_display' => FALSE, 'provider' => 'block_content', 'view_mode' => 'default',
      ]);
      $build = (new Section('actools_bs5_onecol_contained', [], [$component]))->toRenderArray([], FALSE);
      $html = (string) $this->container->get('renderer')->renderRoot($build);
      return [$html, BubbleableMetadata::createFromRenderArray($build)];
    }
    finally {
      $switcher->switchBack();
    }
  }

  private function captionDocument(string $html): \DOMXPath {
    $document = new \DOMDocument();
    @$document->loadHTML($html);
    return new \DOMXPath($document);
  }

}
