<?php

declare(strict_types=1);

namespace Drupal\Tests\actools_bs5_test\Kernel;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Ajax\InsertCommand;
use Drupal\Core\Asset\AssetResolver;
use Drupal\Core\Asset\AttachedAssets;
use Drupal\Core\Asset\LibraryDependencyResolver;
use Drupal\Core\Asset\LibraryDiscoveryCollector;
use Drupal\Core\Asset\LibraryDiscoveryParser;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\MemoryBackend;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Exercises the optional reader skin through Drupal's native asset pipeline.
 *
 * The optional module is not installed. Only its parsed library declaration is
 * supplied by a fixture; the theme, discovery collector, resolver, renderer,
 * cache and AJAX command are real. This avoids a fake generic_lesson extension
 * shadowing the real module on sites that run both projects' test suites.
 */
#[Group('actools_bs5')]
#[RunTestsInSeparateProcesses]
final class GenericLessonAssetsKernelTest extends KernelTestBase {

  protected static $modules = ['system', 'file', 'user'];

  private string $skinPath;

  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system']);
    $this->container->get('theme_installer')->install(['actools_bs5']);
    $this->config('system.theme')->set('default', 'actools_bs5')->save();
    $theme = $this->container->get('theme.initialization')->getActiveThemeByName('actools_bs5');
    $this->container->get('theme.manager')->setActiveTheme($theme);
    $this->skinPath = $this->container->get('extension.list.theme')->getPath('actools_bs5') . '/css/theme/generic-lesson.theme.css';
  }

  /**
   * An ordinary site needs neither the optional module nor its reader assets.
   */
  public function testThemeAssetsWorkWithoutGenericLesson(): void {
    $this->assertFalse($this->container->get('module_handler')->moduleExists('generic_lesson'));
    $assets = (new AttachedAssets())->setLibraries(['actools_bs5/framework']);
    $css = $this->container->get('asset.resolver')->getCssAssets($assets, FALSE);
    $this->assertNotEmpty($css);
    $this->assertArrayNotHasKey($this->skinPath, $css);
    $libraries = $this->container->get('library.dependency_resolver')->getLibrariesWithDependencies($assets->getLibraries());
    $this->assertNotContains('generic_lesson/lesson', $libraries);
    $this->assertNotContains('actools_bs5/generic-lesson', $libraries);
  }

  /**
   * The skin follows the module stylesheet without replacing JS or dependencies.
   */
  public function testExtensionPreservesReaderLibraryAndCssOrder(): void {
    [$discovery, $resolver, $original] = $this->readerFixture();
    $extended = $discovery->getLibraryByName('generic_lesson', 'lesson');
    $this->assertSame($original['js'], $extended['js']);
    $this->assertSame($original['dependencies'], $extended['dependencies']);
    $this->assertSame($original['drupalSettings'], $extended['drupalSettings']);
    $skin = $discovery->getLibraryByName('actools_bs5', 'generic-lesson');
    $this->assertSame([], $skin['js'], 'The theme adds no replacement reader behavior.');
    $assets = (new AttachedAssets())->setLibraries(['generic_lesson/lesson']);
    $css = $resolver->getCssAssets($assets, FALSE);
    $paths = array_keys($css);
    $this->assertSame(['test-only/generic-lesson.css', $this->skinPath], $paths);
    $this->assertFileExists(DRUPAL_ROOT . '/' . $this->skinPath);
  }

  /**
   * Nested, cacheable reader output carries the extension on cold and warm loads.
   */
  public function testNestedRenderAndWarmCacheRetainAttachments(): void {
    [, $resolver] = $this->readerFixture();
    foreach ([1, 2] as $pass) {
      $build = [
        '#type' => 'container',
        '#cache' => ['keys' => ['actools_lesson_asset_probe'], 'tags' => ['lesson:fixture'], 'max-age' => 900],
        'layout_section' => ['reader' => $this->reader()],
      ];
      $html = (string) $this->container->get('renderer')->renderRoot($build);
      $this->assertStringContainsString('Reader fixture', $html);
      $this->assertContains('generic_lesson/lesson', $build['#attached']['library']);
      $metadata = CacheableMetadata::createFromRenderArray($build);
      $this->assertContains('lesson:fixture', $metadata->getCacheTags());
      $this->assertContains('lesson_reader:fixture', $metadata->getCacheTags());
      $this->assertSame(900, $metadata->getCacheMaxAge());
      $css = $resolver->getCssAssets(AttachedAssets::createFromRenderArray($build), FALSE);
      $this->assertArrayHasKey($this->skinPath, $css, "The skin follows the nested reader on render pass {$pass}.");
    }
  }

  /**
   * A denied parent does not render its nested reader or discover its assets.
   */
  public function testDeniedParentDoesNotRenderOrAttachReader(): void {
    [, $resolver] = $this->readerFixture();
    $build = [
      '#type' => 'container',
      'restricted_section' => [
        '#access' => AccessResult::forbidden()->addCacheTags(['lesson_access:denied']),
        'reader' => $this->reader(),
      ],
    ];
    $html = (string) $this->container->get('renderer')->renderRoot($build);
    $this->assertStringNotContainsString('Reader fixture', $html);
    $this->assertNotContains('generic_lesson/lesson', $build['#attached']['library'] ?? []);
    $this->assertContains('lesson_access:denied', CacheableMetadata::createFromRenderArray($build)->getCacheTags());
    $this->assertSame([], $resolver->getCssAssets(AttachedAssets::createFromRenderArray($build), FALSE));
  }

  /**
   * AJAX fragments receive the skin once, and already loaded assets stay absent.
   */
  public function testAjaxReaderAttachmentsAreDeduplicated(): void {
    [, $resolver] = $this->readerFixture();
    $command = new InsertCommand('#lesson-region', ['first' => $this->reader(), 'second' => $this->reader()]);
    $rendered = $command->render();
    $this->assertStringContainsString('Reader fixture', (string) $rendered['data']);
    $assets = $command->getAttachedAssets();
    $this->assertSame(['generic_lesson/lesson'], array_values($assets->getLibraries()));
    $this->assertSame(['genericLesson' => ['fixture' => TRUE]], $assets->getSettings());
    $css = $resolver->getCssAssets($assets, FALSE);
    $this->assertSame(1, count(array_filter(array_keys($css), fn (string $path): bool => $path === $this->skinPath)));
    $assets->setAlreadyLoadedLibraries(['generic_lesson/lesson']);
    $this->assertSame([], $resolver->getCssAssets($assets, FALSE));
  }

  private function reader(): array {
    return [
      '#markup' => '<article class="generic-lesson">Reader fixture</article>',
      '#attached' => ['library' => ['generic_lesson/lesson'], 'drupalSettings' => ['genericLesson' => ['fixture' => TRUE]]],
      '#cache' => ['tags' => ['lesson_reader:fixture']],
    ];
  }

  /**
   * Supplies only an optional module's declaration to the real core pipeline.
   */
  private function readerFixture(): array {
    $real_parser = $this->container->get('library.discovery.parser');
    $core = $real_parser->buildByExtension('core');
    $original = $core['drupal'];
    $original['dependencies'] = ['core/drupal', 'core/once'];
    $original['js'][0]['data'] = 'test-only/generic-lesson.js';
    $original['css'] = [['data' => 'test-only/generic-lesson.css', 'weight' => CSS_COMPONENT]];
    $original['drupalSettings'] = ['genericLesson' => ['libraryFixture' => TRUE]];
    $parser = $this->createMock(LibraryDiscoveryParser::class);
    $parser->method('buildByExtension')->willReturnCallback(static fn (string $extension): array => $extension === 'generic_lesson'
      ? ['lesson' => $original]
      : $real_parser->buildByExtension($extension));
    $cache = new MemoryBackend($this->container->get('datetime.time'));
    $discovery = new LibraryDiscoveryCollector($cache, $this->container->get('lock'), $parser, $this->container->get('theme.manager'));
    $resolver = new AssetResolver(
      $discovery,
      new LibraryDependencyResolver($discovery),
      $this->container->get('module_handler'),
      $this->container->get('theme.manager'),
      $this->container->get('language_manager'),
      $cache,
      $this->container->get('theme_handler'),
    );
    return [$discovery, $resolver, $original];
  }

}
