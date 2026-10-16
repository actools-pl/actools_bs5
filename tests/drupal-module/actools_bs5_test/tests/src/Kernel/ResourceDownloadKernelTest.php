<?php

declare(strict_types=1);

namespace Drupal\Tests\actools_bs5_test\Kernel;

use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Recipe\Recipe;
use Drupal\Core\Recipe\RecipeRunner;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Core\StringTranslation\ByteSizeMarkup;
use Drupal\file\Entity\File;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\user\RoleInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Native Resource downloads retain access, descriptions, URLs and cache tags.
 */
#[Group('actools_bs5')]
#[RunTestsInSeparateProcesses]
final class ResourceDownloadKernelTest extends KernelTestBase {

  protected static $modules = [
    'system', 'user', 'field', 'field_ui', 'text', 'filter', 'file', 'image',
    'breakpoint', 'responsive_image', 'options', 'node', 'block', 'block_content',
    'contextual', 'path_alias', 'layout_discovery', 'layout_builder',
    'actools_bs5_test', 'actools_download_test',
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
    $this->container->get('theme_installer')->install(['actools_bs5']);
    $path = $this->container->get('extension.list.theme')->getPath('actools_bs5');
    RecipeRunner::processRecipe(Recipe::createFromDirectory(DRUPAL_ROOT . '/' . $path . '/recipes/actools_topic_starter'));
    $this->container = \Drupal::getContainer();
    $this->config('system.theme')->set('default', 'actools_bs5')->save();
    $active = $this->container->get('theme.initialization')->getActiveThemeByName('actools_bs5');
    $this->container->get('theme.manager')->setActiveTheme($active);
    $this->container->get('theme.registry')->reset();
    $this->container->get('router.builder')->rebuild();
    foreach ([RoleInterface::ANONYMOUS_ID, RoleInterface::AUTHENTICATED_ID] as $role_id) {
      Role::load($role_id)->grantPermission('access content')->save();
    }
    User::create(['uid' => 1, 'name' => 'Download author', 'status' => 1])->save();
    $directory = 'public://actools-download-tests';
    $this->assertTrue($this->container->get('file_system')->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY));
  }

  /**
   * Descriptions stay escaped; actual core file links retain type, size and URL.
   */
  public function testPdfAndDocxUseOriginalLinksAndNativeSizeMarkup(): void {
    foreach (['pdf' => 'application/pdf', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'] as $extension => $mime) {
      $file = $this->file($extension, $mime, 4096);
      $description = 'Surface tension <script>bad()</script> & practice';
      $resource = $this->resource($file, $description);
      foreach ([1, 2] as $pass) {
        [$html, $metadata] = $this->renderResource($resource);
        $xpath = $this->document($html);
        $this->assertSame(1, $xpath->query('//*[contains(@class, "actools-resource-downloads__item")]')->length);
        $this->assertSame('Download', trim($xpath->evaluate('string(//*[contains(@class, "actools-resource-downloads__label")])')));
        $link = $xpath->query('//*[contains(@class, "actools-resource-downloads__link")]/a')->item(0);
        $this->assertInstanceOf(\DOMElement::class, $link);
        $this->assertSame($description, $link->textContent);
        $this->assertSame($mime, $link->getAttribute('type'));
        $this->assertSame($file->getFilename(), $link->getAttribute('title'));
        $original = $this->container->get('file_url_generator')->generateString($file->getFileUri());
        $this->assertSame(rawurldecode((string) parse_url($original, PHP_URL_PATH)), rawurldecode((string) parse_url($link->getAttribute('href'), PHP_URL_PATH)));
        $this->assertSame(strtoupper($extension), trim($xpath->evaluate('string(//*[contains(@class, "actools-resource-downloads__type")])')));
        $this->assertSame((string) ByteSizeMarkup::create($file->getSize()), trim($xpath->evaluate('string(//*[contains(@class, "actools-resource-downloads__size")])')));
        $this->assertSame(0, $xpath->query('//script')->length);
        $this->assertContains('file:' . $file->id(), $metadata->getCacheTags());
        $this->assertContains('node:' . $resource->id(), $metadata->getCacheTags());
      }
    }

    // A file link outside the specific Resource field keeps core's template.
    $generic = ['#theme' => 'file_link', '#file' => $file, '#description' => 'Generic file'];
    $generic_html = (string) $this->container->get('renderer')->renderRoot($generic);
    $this->assertStringContainsString('Generic file', $generic_html);
    $this->assertStringNotContainsString('actools-resource-downloads', $generic_html);
  }

  /**
   * Warm node and file cache entries invalidate after independent saved edits.
   */
  public function testDescriptionAndFileSizeEditsInvalidateWarmOutput(): void {
    $file = $this->file('pdf', 'application/pdf', 4096);
    $resource = $this->resource($file, 'Original assignment');
    foreach ([1, 2] as $pass) {
      [$html] = $this->renderResource($resource);
      $this->assertStringContainsString('Original assignment', $html);
      $this->assertStringContainsString('4 KB', $html);
    }
    $resource->set('field_actools_download', ['target_id' => $file->id(), 'description' => 'Revised assignment'])->save();
    [$html] = $this->renderResource($resource);
    $this->assertStringContainsString('Revised assignment', $html);
    $this->assertStringNotContainsString('Original assignment', $html);
    file_put_contents($file->getFileUri(), str_repeat('B', 8192));
    clearstatcache(TRUE, $file->getFileUri());
    $file->setSize(8192);
    $file->save();
    // Simulate a subsequent request's entity memory without clearing render
    // caches: the original file:<id> tag must invalidate its parent's HTML.
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $storage->resetCache([$resource->id()]);
    $this->container->get('entity_type.manager')->getStorage('file')->resetCache([$file->id()]);
    $resource = $storage->load($resource->id());
    [$html] = $this->renderResource($resource);
    $this->assertSame('8 KB', trim($this->document($html)->evaluate('string(//*[contains(@class, "actools-resource-downloads__size")])')));
    $this->assertStringNotContainsString('4 KB', $html);
  }

  /**
   * Empty/display-hidden fields have no row; blank descriptions get a label.
   */
  public function testEmptyHiddenAndDescriptionlessDownloads(): void {
    $resource = $this->resource();
    [$html] = $this->renderResource($resource);
    $this->assertStringNotContainsString('actools-resource-downloads', $html);
    $file = $this->file('pdf', 'application/pdf', 512);
    foreach (['', '   '] as $description) {
      $resource->set('field_actools_download', ['target_id' => $file->id(), 'description' => $description])->save();
      [$html] = $this->renderResource($resource);
      $link_text = $this->document($html)->evaluate('string(//*[contains(@class, "actools-resource-downloads__link")]/a)');
      $this->assertSame('Download PDF', $link_text, 'A blank description gets a translated useful label without rebuilding the link.');
    }
    $generic = ['#theme' => 'file_link', '#file' => $file, '#description' => ''];
    $generic_html = (string) $this->container->get('renderer')->renderRoot($generic);
    $this->assertStringContainsString($file->getFilename(), $generic_html, 'Unrelated generic links keep the native filename fallback.');
    $this->assertStringNotContainsString('Download PDF', $generic_html);
    EntityViewDisplay::load('node.actools_resource.default')->removeComponent('field_actools_download')->save();
    [$html] = $this->renderResource($resource);
    $this->assertStringNotContainsString('actools-resource-downloads', $html);
    $this->assertStringNotContainsString($file->getFilename(), $html);
  }

  /**
   * No label, metadata or URL survives a denied field, item or nested link.
   */
  public function testDeferredDenialsSuppressCompleteDownloadRow(): void {
    foreach (['field', 'item', 'link'] as $target) {
      foreach (['allowed', 'forbidden', 'neutral', 'boolean_denied', 'explicit_boolean_denied'] as $mode) {
        $file = $this->file('pdf', 'application/pdf', 4096);
        $resource = $this->resource($file, 'Protected assignment ' . $target . '-' . $mode);
        $this->probe($resource, $file, $target, $mode);
        foreach ([1, 2] as $pass) {
          [$html, $metadata] = $this->renderResource($resource);
          $xpath = $this->document($html);
          $rows = $xpath->query('//*[contains(@class, "actools-resource-downloads__item")]');
          $this->assertSame($mode === 'allowed' ? 1 : 0, $rows->length, $target . '/' . $mode);
          if ($mode !== 'allowed') {
            $this->assertStringNotContainsString('actools-resource-downloads', $html, $target . '/' . $mode);
            $this->assertStringNotContainsString('Protected assignment', $html);
            $this->assertStringNotContainsString($file->getFilename(), $html);
            $this->assertStringNotContainsString('4 KB', $html);
          }
          if (!in_array($mode, ['boolean_denied', 'explicit_boolean_denied'], TRUE)) {
            $this->assertContains('actools_download_access:' . $mode, $metadata->getCacheTags());
            $this->assertContains('actools_download_element:' . $target, $metadata->getCacheTags());
          }
        }
      }
    }
  }

  /**
   * Equal-permission viewers must not share an authorised row from warm caches.
   */
  public function testDeferredAccessVariesByViewerWithWarmCaches(): void {
    $allowed = User::create(['uid' => 2, 'name' => 'Allowed download reader', 'status' => 1]);
    $allowed->save();
    $denied = User::create(['uid' => 3, 'name' => 'Denied download reader', 'status' => 1]);
    $denied->save();
    foreach (['field', 'item', 'link'] as $target) {
      $file = $this->file('docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 4096);
      $resource = $this->resource($file, 'Viewer-specific assignment');
      $this->probe($resource, $file, $target, 'viewer');
      foreach ([$allowed, $denied, $allowed, $denied] as $viewer) {
        [$html, $metadata] = $this->renderResource($resource, $viewer);
        $this->assertSame((int) $viewer->id() === 2 ? 1 : 0, $this->document($html)->query('//*[contains(@class, "actools-resource-downloads__item")]')->length, $target);
        $this->assertContains('user', $metadata->getCacheContexts());
        $this->assertNotSame(0, $metadata->getCacheMaxAge());
        if ((int) $viewer->id() !== 2) {
          $this->assertStringNotContainsString('actools-resource-downloads', $html);
          $this->assertStringNotContainsString('Viewer-specific assignment', $html);
          $this->assertStringNotContainsString($file->getFilename(), $html);
          $this->assertStringNotContainsString('4 KB', $html);
        }
      }
    }
  }

  private function file(string $extension, string $mime, int $size): File {
    $filename = 'assignment-' . $this->randomMachineName() . '.' . $extension;
    $uri = 'public://actools-download-tests/' . $filename;
    file_put_contents($uri, str_repeat('A', $size));
    $file = File::create(['uri' => $uri, 'filename' => $filename, 'filemime' => $mime, 'status' => 1]);
    $file->save();
    return $file;
  }

  private function resource(?File $file = NULL, string $description = ''): NodeInterface {
    $resource = Node::create(['type' => 'actools_resource', 'title' => 'Resource download fixture', 'status' => 1, 'uid' => 1]);
    if ($file) {
      $resource->set('field_actools_download', ['target_id' => $file->id(), 'description' => $description]);
    }
    $resource->save();
    return $resource;
  }

  private function probe(NodeInterface $resource, File $file, string $target, string $mode): void {
    $this->container->get('state')->set('actools_download_test.access_probe', [
      'node_id' => $resource->id(), 'file_id' => $file->id(), 'target' => $target, 'mode' => $mode,
    ]);
  }

  /**
   * @return array{0: string, 1: \Drupal\Core\Render\BubbleableMetadata}
   *   Actual full Resource HTML and bubbled metadata; render caches stay warm.
   */
  private function renderResource(NodeInterface $resource, ?AccountInterface $viewer = NULL): array {
    $switcher = $this->container->get('account_switcher');
    $switcher->switchTo($viewer ?? new AnonymousUserSession());
    try {
      $build = $this->container->get('entity_type.manager')->getViewBuilder('node')->view($resource, 'full');
      $html = (string) $this->container->get('renderer')->renderRoot($build);
      return [$html, BubbleableMetadata::createFromRenderArray($build)];
    }
    finally {
      $switcher->switchBack();
    }
  }

  private function document(string $html): \DOMXPath {
    $document = new \DOMDocument();
    @$document->loadHTML($html);
    return new \DOMXPath($document);
  }

}
