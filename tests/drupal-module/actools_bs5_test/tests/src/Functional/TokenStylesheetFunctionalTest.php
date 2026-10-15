<?php

declare(strict_types=1);

namespace Drupal\Tests\actools_bs5_test\Functional;

use PHPUnit\Framework\Attributes\Group;

use Drupal\Tests\BrowserTestBase;

/**
 * Tests token stylesheet attachment and file output on rendered pages.
 *
 */
#[Group('actools_bs5')]
final class TokenStylesheetFunctionalTest extends BrowserTestBase {

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
    $this->container->get('theme_installer')->install(['actools_bs5']);
    $this->config('system.theme')->set('default', 'actools_bs5')->save();
    $this->drupalPlaceBlock('system_main_block', ['region' => 'content']);
  }

  /**
   * Default token settings should not attach a generated override stylesheet.
   */
  public function testDefaultSettingsDoNotAttachGeneratedTokenCss(): void {
    $this->drupalGet('');

    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseContains('css/base/bootstrap.base.css');
    $this->assertSession()->responseContains('css/base/tokens.base.css');
    $this->assertSession()->responseNotContains('actools_bs5/tokens-');
    $this->assertSession()->responseNotContains('<style id="actools-bs5-tokens"');
  }

  /**
   * Changed token settings should write and attach an external hashed CSS file.
   */
  public function testChangedTokensAttachExternalHashedStylesheet(): void {
    $this->config('actools_bs5.settings')
      ->set('tokens_brand_color', '#123456')
      ->set('tokens_body_font', 'system')
      ->set('tokens_base_font_size', '20')
      ->set('tokens_corner_radius', 'rounded')
      ->save();
    drupal_flush_all_caches();

    $this->drupalGet('');

    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseContains('actools_bs5/tokens-');
    $this->assertSession()->responseNotContains('<style id="actools-bs5-tokens"');

    $files = $this->getGeneratedTokenFiles();
    $this->assertNotEmpty($files, 'A generated token stylesheet exists under public://actools_bs5.');
    $css = file_get_contents(reset($files));
    $this->assertIsString($css);
    $this->assertStringContainsString('--bs-primary:#123456', $css);
    $this->assertStringContainsString('--bs-body-font-family:system-ui', $css);
    $this->assertStringContainsString('--bs-body-font-size:1.25rem', $css);
    $this->assertStringContainsString('--bs-border-radius:1rem', $css);
  }

  /**
   * Malicious-looking config values must not reach the response or token file.
   */
  public function testInjectedTokenValuesAreNotRendered(): void {
    $payload = '#123456;}body{background:red}/*';
    $this->config('actools_bs5.settings')
      ->set('tokens_brand_color', $payload)
      ->set('tokens_body_font', 'system;}body{font-family:evil}/*')
      ->set('tokens_base_font_size', '20;}body{font-size:999px}/*')
      ->set('tokens_corner_radius', 'rounded;}body{border:999px solid red}/*')
      ->save();
    drupal_flush_all_caches();

    $this->drupalGet('');

    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseNotContains('background:red');
    $this->assertSession()->responseNotContains('font-family:evil');
    $this->assertSession()->responseNotContains('999px');

    foreach ($this->getGeneratedTokenFiles() as $file) {
      $css = file_get_contents($file);
      $this->assertIsString($css);
      $this->assertStringNotContainsString('background:red', $css);
      $this->assertStringNotContainsString('font-family:evil', $css);
      $this->assertStringNotContainsString('999px', $css);
    }
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
