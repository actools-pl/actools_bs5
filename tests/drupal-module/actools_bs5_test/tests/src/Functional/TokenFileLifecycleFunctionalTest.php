<?php

declare(strict_types=1);

namespace Drupal\Tests\actools_bs5_test\Functional;

use PHPUnit\Framework\Attributes\Group;

use Drupal\Tests\BrowserTestBase;

/**
 * Tests the runtime lifecycle of the generated token stylesheet and settings.
 *
 * Covers behaviour the other functional tests do not: what happens to the old
 * hashed file when settings change (M-4), that a dark brand colour still renders,
 * and that the sticky-header setting actually controls its library.
 *
 */
#[Group('actools_bs5')]
final class TokenFileLifecycleFunctionalTest extends BrowserTestBase {

  use FunctionalResponseDiagnosticsTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'block',
    'user',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'actools_bs5';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->config('system.logging')->set('error_level', 'verbose')->save();
    $this->container->get('theme_installer')->install(['actools_bs5']);
    $this->config('system.theme')->set('default', 'actools_bs5')->save();
    $theme_path = $this->container->get('extension.list.theme')->getPath('actools_bs5');
    require_once DRUPAL_ROOT . '/' . $theme_path . '/actools_bs5.theme';
    $this->drupalPlaceBlock('system_main_block', ['region' => 'content']);
  }

  /**
   * Locks M-4 (fixed): the superseded hashed token file is RETAINED on change.
   *
   * Content-hashed URLs are safe to cache far-future only while old names stay
   * valid. Callable, throttled cleanup retains recent files, so HTML cached by a
   * browser/CDN/proxy that still references
   * the previous hash keeps resolving. The old file must therefore still exist
   * immediately after a settings change (cleanup removes it once stale).
   */
  public function testSupersededTokenFileIsRetainedForCacheCoherence(): void {
    $this->setTokens('#123456');
    $this->drupalGet('');
    $this->assertResponseStatus(200);
    $first = $this->getGeneratedTokenFiles();
    $this->assertNotEmpty($first, 'A token file exists after the first change.');
    $old = reset($first);
    $this->assertFileExists($old);

    $this->setTokens('#654321');
    $this->drupalGet('');
    $this->assertResponseStatus(200);
    $second = $this->getGeneratedTokenFiles();
    $this->assertNotEmpty($second, 'A token file exists after the second change.');
    $added = array_values(array_diff($second, $first));
    $this->assertCount(1, $added, 'Exactly one new token hash is written.');
    $new = reset($added);
    $this->assertContains($new, $second);

    $this->assertNotSame($old, $new, 'Changing the brand colour produces a new content hash.');
    $this->assertFileExists(
      $old,
      'The superseded token file survives the retention window for cached HTML.'
    );
  }

  /**
   * The actual writer removes expired files while keeping recent/current ones.
   */
  public function testWriterInvokesRetainedCleanupWithoutThemeCron(): void {
    $this->setTokens('#123456');
    $css = _actools_bs5_build_tokens_css(_actools_bs5_token_values('actools_bs5'));
    $current = _actools_bs5_write_tokens_file($css);
    $this->assertNotNull($current);
    $fs = $this->container->get('file_system');
    $expired = 'public://actools_bs5/tokens-0000000000000000.css';
    $recent = 'public://actools_bs5/tokens-1111111111111111.css';
    $fs->saveData('.expired{}', $expired, \Drupal\Core\File\FileExists::Replace);
    $fs->saveData('.recent{}', $recent, \Drupal\Core\File\FileExists::Replace);
    $old_time = \Drupal::time()->getRequestTime() - 8 * 86400;
    touch($fs->realpath($expired), $old_time);
    touch($fs->realpath($current), $old_time);
    $this->container->get('state')->set('actools_bs5.tokens_cleanup_after', 0);

    _actools_bs5_write_tokens_file($css);

    clearstatcache();
    $this->assertFileDoesNotExist($fs->realpath('public://actools_bs5') . '/tokens-0000000000000000.css');
    $this->assertFileExists($fs->realpath($recent));
    $this->assertFileExists($fs->realpath($current), 'The active token file is protected even when old.');
    $this->assertGreaterThan(\Drupal::time()->getRequestTime(), $this->container->get('state')->get('actools_bs5.tokens_cleanup_after'));
  }

  /**
   * A dark brand colour must still render a valid page with the override applied.
   */
  public function testDarkBrandColorStillRenders(): void {
    $this->setTokens('#0b1020');
    $this->drupalGet('');
    $this->assertResponseStatus(200);
    $this->assertSession()->responseContains('actools_bs5/tokens-');
  }

  /**
   * The sticky-header setting must control whether its library is attached.
   */
  public function testStickyHeaderSettingControlsLibrary(): void {
    // Default (enabled): the sticky-header assets are attached.
    $this->drupalGet('');
    $this->assertResponseStatus(200);
    $this->assertSession()->responseContains('sticky-header');

    // Disabled: the sticky-header assets must not be attached.
    $this->config('actools_bs5.settings')->set('sticky_header', FALSE)->save();
    drupal_flush_all_caches();
    $this->drupalGet('');
    $this->assertResponseStatus(200);
    $this->assertSession()->responseNotContains('sticky-header');
  }

  /**
   * Persist a full set of token settings with the given brand colour.
   */
  private function setTokens(string $brand): void {
    $this->config('actools_bs5.settings')
      ->set('tokens_brand_color', $brand)
      ->set('tokens_body_font', 'system')
      ->set('tokens_base_font_size', '20')
      ->set('tokens_corner_radius', 'rounded')
      ->save();
    drupal_flush_all_caches();
  }

  /**
   * Returns real paths for generated token CSS files.
   *
   * @return string[]
   *   Generated token file paths.
   */
  private function getGeneratedTokenFiles(): array {
    $directory = $this->container->get('file_system')->realpath('public://actools_bs5');
    if (!$directory || !is_dir($directory)) {
      return [];
    }
    $files = glob($directory . '/tokens-*.css');
    return is_array($files) ? $files : [];
  }

}
