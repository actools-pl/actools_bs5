<?php

declare(strict_types=1);

namespace Drupal\Tests\actools_bs5_test\Functional;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

use Drupal\Tests\BrowserTestBase;

/**
 * Tests the Actools BS5 theme settings form integration.
 *
 */
#[Group('actools_bs5')]
#[RunTestsInSeparateProcesses]
final class ThemeSettingsFormFunctionalTest extends BrowserTestBase {

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
  }

  /**
   * Theme settings form exposes and saves the design-token fields.
   */
  public function testThemeSettingsFormSavesDesignTokens(): void {
    $account = $this->drupalCreateUser(['administer themes']);
    $this->drupalGet('user/login');
    $this->assertResponseStatus(200);
    $this->drupalLogin($account);

    $this->drupalGet('admin/appearance/settings/actools_bs5');
    $this->assertResponseStatus(200);
    $this->assertSession()->fieldExists('tokens_brand_color');
    $this->assertSession()->fieldExists('tokens_body_font');
    $this->assertSession()->fieldExists('tokens_base_font_size');
    $this->assertSession()->fieldExists('tokens_corner_radius');

    $this->submitForm([
      'tokens_brand_color' => '#123456',
      'tokens_body_font' => 'serif',
      'tokens_base_font_size' => '19',
      'tokens_corner_radius' => 'sharp',
    ], 'Save configuration');

    $settings = $this->config('actools_bs5.settings');
    $this->assertSame('#123456', $settings->get('tokens_brand_color'));
    $this->assertSame('serif', $settings->get('tokens_body_font'));
    $this->assertSame('19', $settings->get('tokens_base_font_size'));
    $this->assertSame('sharp', $settings->get('tokens_corner_radius'));

    $files = $this->getGeneratedTokenFiles();
    $this->assertNotEmpty($files, 'Settings submit generated the token CSS immediately.');
    $css = file_get_contents(reset($files));
    $this->assertIsString($css);
    $this->assertStringContainsString('--bs-primary:#123456', $css);
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
