<?php

declare(strict_types=1);

namespace Drupal\Tests\actools_bs5_test\Kernel;

use PHPUnit\Framework\Attributes\Group;

use Drupal\Core\Config\Schema\SchemaCheckTrait;
use Drupal\KernelTests\KernelTestBase;

/**
 * Hardening regression tests for the design-token pipeline.
 *
 * Complements TokenCssGenerationKernelTest with the robustness cases the audit
 * flagged: graceful behaviour when public:// is not writable (M-3), a dark-mode
 * companion for the brand override (M-2), and runtime config-schema validation.
 *
 * The M-2/M-3 checks are intentionally adversarial and fail on the audited beta
 * until fixed. The schema check should pass today and guards against drift.
 *
 */
#[Group('actools_bs5')]
final class TokenPipelineHardeningKernelTest extends KernelTestBase {

  use SchemaCheckTrait;

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
   * Path used to make the token output directory unwritable.
   */
  private ?string $blockingPath = NULL;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system']);
    $this->container->get('theme_installer')->install(['actools_bs5']);
    $theme_path = $this->container->get('extension.list.theme')->getPath('actools_bs5');
    $theme_file = DRUPAL_ROOT . '/' . $theme_path . '/actools_bs5.theme';
    $this->assertFileExists($theme_file);
    require_once $theme_file;
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    if ($this->blockingPath !== NULL && is_file($this->blockingPath)) {
      @unlink($this->blockingPath);
    }
    parent::tearDown();
  }

  /**
   * The writer must not throw (WSOD) when public:// cannot be written.
   *
   * Regression for M-3. We place a regular file where the token directory should
   * be, so prepareDirectory() cannot create it. A hardened writer checks the
   * prepareDirectory() return and/or catches FileException, returning NULL so the
   * caller simply skips attaching the stylesheet. The audited beta returns the URI
   * unconditionally and lets saveData() throw, which fails this test.
   */
  public function testWriterDegradesGracefullyWhenPublicNotWritable(): void {
    /** @var \Drupal\Core\File\FileSystemInterface $fs */
    $fs = $this->container->get('file_system');
    $public = $fs->realpath('public://');
    $this->assertNotEmpty($public, 'The public:// stream resolves to a real path.');
    if (!is_dir($public)) {
      @mkdir($public, 0777, TRUE);
    }
    // Block the token directory by occupying its name with a file.
    $this->blockingPath = $public . '/actools_bs5';
    if (is_dir($this->blockingPath)) {
      // Remove any pre-existing directory so we can occupy the name with a file.
      array_map('unlink', glob($this->blockingPath . '/*') ?: []);
      @rmdir($this->blockingPath);
    }
    file_put_contents($this->blockingPath, "not a directory\n");

    $css = _actools_bs5_build_tokens_css([
      'tokens_brand_color' => '#123456',
      'tokens_body_font' => 'system',
      'tokens_base_font_size' => '20',
      'tokens_corner_radius' => 'rounded',
    ]);
    $this->assertNotSame('', $css, 'A changed brand colour produces override CSS to write.');

    $threw = FALSE;
    $result = 'unset';
    try {
      $result = _actools_bs5_write_tokens_file($css);
    }
    catch (\Throwable $e) {
      $threw = TRUE;
      $message = $e->getMessage();
    }

    $this->assertFalse(
      $threw,
      'M-3: token writer must catch file-system failures instead of throwing during page render'
      . (isset($message) ? " (threw: {$message})" : '')
    );
    $this->assertNull(
      $result,
      'M-3: when the token file cannot be written, the writer should return NULL so no stylesheet is attached.'
    );
  }

  /**
   * A dark brand colour must not blanket-override dark mode.
   *
   * Regression for M-2. The override uses `:root:root`, which beats Bootstrap's
   * `[data-bs-theme=dark]` block, so a dark brand can make dark mode unreadable.
   * Either accepted fix — an explicit `[data-bs-theme="dark"]` companion, or
   * scoping the override to light mode — makes the generated CSS reference
   * `data-bs-theme`. The audited beta emits neither and fails this test.
   */
  public function testDarkBrandProducesDarkModeAwareCss(): void {
    $css = _actools_bs5_build_tokens_css([
      'tokens_brand_color' => '#0b1020',
      'tokens_body_font' => 'figtree',
      'tokens_base_font_size' => '18',
      'tokens_corner_radius' => 'default',
    ]);
    $this->assertNotSame('', $css);
    $this->assertStringContainsString(
      'data-bs-theme',
      $css,
      'M-2: brand override should provide a dark-mode companion (or be light-scoped) so dark mode stays readable.'
    );
  }

  /**
   * A white brand cannot make links white on a white standard page surface.
   */
  public function testPaleBrandKeepsLinksAndButtonsReadable(): void {
    $css = _actools_bs5_build_tokens_css(['tokens_brand_color' => '#ffffff']);
    $this->assertMatchesRegularExpression('/--bs-link-color:#[0-9a-f]{6}/', $css);
    preg_match('/--bs-link-color:(#[0-9a-f]{6})/', $css, $matches);
    $this->assertSame('#737373', $matches[1], 'White-brand links are darkened beyond the 4.5:1 contrast threshold.');
    $this->assertStringContainsString('--bs-btn-color:#000000', $css);
    $this->assertStringContainsString('--bs-primary:#ffffff', $css, 'The selected brand fill is preserved.');
  }

  /**
   * The shipped theme settings must validate against their schema at runtime.
   *
   * Complements the static key-alignment check with real typed-data validation.
   * Should pass today; guards against future schema/settings drift.
   */
  public function testShippedSettingsMatchSchema(): void {
    // Populate representative values across every typed key.
    $this->config('actools_bs5.settings')
      ->set('sticky_header', TRUE)
      ->set('tokens_brand_color', '#0d6efd')
      ->set('tokens_body_font', 'figtree')
      ->set('tokens_base_font_size', '18')
      ->set('tokens_corner_radius', 'default')
      ->save();

    $typed = $this->container->get('config.typed');
    $data = $this->config('actools_bs5.settings')->get();
    $result = $this->checkConfigSchema($typed, 'actools_bs5.settings', $data);

    $this->assertTrue(
      $result === TRUE,
      'actools_bs5.settings must validate against config/schema: '
      . (is_array($result) ? print_r($result, TRUE) : var_export($result, TRUE))
    );
  }

}
