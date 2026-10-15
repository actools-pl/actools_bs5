<?php

declare(strict_types=1);

namespace Drupal\Tests\actools_bs5_test\Kernel;

use Drupal\KernelTests\KernelTestBase;

/**
 * Tests Actools BS5 design-token generation without a browser.
 *
 * @group actools_bs5
 */
final class TokenCssGenerationKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'file',
    'user',
  ];

  /**
   * {@inheritdoc}
   */
  protected $strictConfigSchema = TRUE;

  /**
   * Includes the theme file so internal token helpers are callable.
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system']);
    $this->installThemeAndIncludeFile();
  }

  /**
   * Installs the target theme and require_once's its .theme file.
   */
  private function installThemeAndIncludeFile(): void {
    $this->container->get('theme_installer')->install(['actools_bs5']);
    $theme_path = $this->container->get('extension.list.theme')->getPath('actools_bs5');
    $this->assertNotEmpty($theme_path, 'The actools_bs5 theme is discoverable by Drupal.');
    $theme_file = DRUPAL_ROOT . '/' . $theme_path . '/actools_bs5.theme';
    $this->assertFileExists($theme_file);
    require_once $theme_file;
  }

  /**
   * Invalid token values must not break out into arbitrary CSS.
   */
  public function testTokenValuesRejectCssInjection(): void {
    $values = [
      'tokens_brand_color' => '#123456;}body{background:red}/*',
      'tokens_body_font' => 'system;}body{font-family:evil}/*',
      'tokens_base_font_size' => '18;}body{font-size:999px}/*',
      'tokens_corner_radius' => 'default;}body{border:999px solid red}/*',
    ];

    $css = _actools_bs5_build_tokens_css($values);

    $this->assertSame('', $css, 'All invalid injected token values collapse to defaults/no override CSS.');
    $this->assertStringNotContainsString('body{', $css);
    $this->assertStringNotContainsString('background:red', $css);
    $this->assertStringNotContainsString('evil', $css);
  }

  /**
   * Valid changed tokens produce the expected Bootstrap variable overrides.
   */
  public function testValidChangedTokensGenerateExpectedCss(): void {
    $css = _actools_bs5_build_tokens_css([
      'tokens_brand_color' => '#123456',
      'tokens_body_font' => 'system',
      'tokens_base_font_size' => '20',
      'tokens_corner_radius' => 'rounded',
    ]);

    $this->assertStringStartsWith(':root:root{', $css);
    $this->assertStringContainsString('--bs-primary:#123456', $css);
    $this->assertStringContainsString('--bs-primary-rgb:18, 52, 86', $css);
    $this->assertStringContainsString('--bs-body-font-family:system-ui', $css);
    $this->assertStringContainsString('--bs-body-font-size:1.25rem', $css);
    $this->assertStringContainsString('--bs-border-radius:1rem', $css);
    $this->assertStringContainsString('.btn.btn-primary{', $css);
    $this->assertStringContainsString('.btn.btn-outline-primary{', $css);
  }

  /**
   * Bootstrap shade-color parity for primary brand derivatives.
   */
  public function testShadeColorMatchesBootstrapPrimaryDefaults(): void {
    $this->assertSame('#0b5ed7', _actools_bs5_shade_color('#0d6efd', 15));
    $this->assertSame('#0a58ca', _actools_bs5_shade_color('#0d6efd', 20));
    $this->assertSame('#0a53be', _actools_bs5_shade_color('#0d6efd', 25));
  }

  /**
   * Link-primary hover must override Bootstrap's hard-coded !important utility.
   *
   * This is intentionally a regression test for the red-team finding. It fails on
   * the audited beta until the generator emits real color declarations.
   */
  public function testLinkPrimaryHoverOverrideUsesRealCssProperties(): void {
    $css = _actools_bs5_build_tokens_css([
      'tokens_brand_color' => '#123456',
      'tokens_body_font' => 'figtree',
      'tokens_base_font_size' => '18',
      'tokens_corner_radius' => 'default',
    ]);

    $this->assertMatchesRegularExpression('/\.link-primary:hover,\.link-primary:focus\{[^}]*color:/', $css);
    $this->assertMatchesRegularExpression('/\.link-primary:hover,\.link-primary:focus\{[^}]*text-decoration-color:/', $css);
  }

  /**
   * ThemeSettingsProvider should read persisted theme settings by theme key.
   */
  public function testThemeSettingsProviderReadsConfiguredTokenValues(): void {
    $this->config('actools_bs5.settings')
      ->set('tokens_brand_color', '#abcdef')
      ->set('tokens_body_font', 'serif')
      ->set('tokens_base_font_size', '19')
      ->set('tokens_corner_radius', 'sharp')
      ->save();

    $values = _actools_bs5_token_values('actools_bs5');

    $this->assertSame('#abcdef', $values['tokens_brand_color']);
    $this->assertSame('serif', $values['tokens_body_font']);
    $this->assertSame('19', $values['tokens_base_font_size']);
    $this->assertSame('sharp', $values['tokens_corner_radius']);
  }

}
