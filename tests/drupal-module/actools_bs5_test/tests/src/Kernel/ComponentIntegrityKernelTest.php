<?php

declare(strict_types=1);

namespace Drupal\Tests\actools_bs5_test\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Twig\Error\SyntaxError;
use Twig\Source;

/**
 * Validates every Actools BS5 Twig template and SDC component definition.
 *
 * The static runner checks *literal* include references and asset paths; this
 * test uses Drupal's real Twig environment and SDC plugin manager, so it also
 * catches Twig syntax errors and component-metadata/discovery problems that a
 * grep cannot see.
 *
 * @group actools_bs5
 */
final class ComponentIntegrityKernelTest extends KernelTestBase {

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
   * Absolute path to the installed theme.
   */
  private string $themePath = '';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system']);
    $this->container->get('theme_installer')->install(['actools_bs5']);
    // Make the theme the active default so SDC discovery includes it.
    $this->config('system.theme')->set('default', 'actools_bs5')->save();
    $active = $this->container->get('theme.initialization')->getActiveThemeByName('actools_bs5');
    $this->container->get('theme.manager')->setActiveTheme($active);
    $relative = $this->container->get('extension.list.theme')->getPath('actools_bs5');
    $this->assertNotEmpty($relative, 'The actools_bs5 theme is discoverable by Drupal.');
    $this->themePath = DRUPAL_ROOT . '/' . $relative;
    $this->assertDirectoryExists($this->themePath);
  }

  /**
   * Every shipped Twig template must compile with Drupal's Twig environment.
   */
  public function testAllTwigTemplatesCompile(): void {
    /** @var \Drupal\Core\Template\TwigEnvironment $twig */
    $twig = $this->container->get('twig');

    $templates = $this->findBySuffix($this->themePath, '.twig');
    $this->assertNotEmpty($templates, 'The theme ships Twig templates to validate.');

    $errors = [];
    foreach ($templates as $file) {
      $source = @file_get_contents($file);
      if ($source === FALSE) {
        $errors[] = $this->relative($file) . ': unreadable';
        continue;
      }
      try {
        // Tokenize + parse catches syntax errors and unknown tags/filters
        // without needing valid runtime variables or a resolvable loader.
        $twig->parse($twig->tokenize(new Source($source, $file)));
      }
      catch (SyntaxError $e) {
        $errors[] = $this->relative($file) . ': ' . $e->getRawMessage();
      }
    }

    $this->assertSame([], $errors, "Twig templates with syntax errors:\n" . implode("\n", $errors));
  }

  /**
   * Every component under components/ must be discoverable by the SDC manager.
   */
  public function testAllComponentsAreDiscoverable(): void {
    $this->assertTrue($this->container->has('plugin.manager.sdc'), 'Drupal 11 provides the SDC manager.');
    $manager = $this->container->get('plugin.manager.sdc');

    $definitions = $manager->getDefinitions();
    $ours = array_filter(
      $definitions,
      static fn ($id): bool => is_string($id) && str_starts_with($id, 'actools_bs5:'),
      ARRAY_FILTER_USE_KEY
    );

    $this->assertNotEmpty($ours, 'An installed active theme must expose its components.');

    // Cross-check: at least one definition per *.component.yml on disk.
    $onDisk = $this->findBySuffix($this->themePath . '/components', '.component.yml');
    $this->assertNotEmpty($onDisk, 'The theme ships SDC component definitions.');
    $this->assertGreaterThanOrEqual(
      count($onDisk),
      count($ours),
      'Every *.component.yml on disk is discovered as an SDC component.'
    );

    foreach ($ours as $id => $definition) {
      $this->assertIsArray($definition, "Definition for {$id} is an array.");
      // When the definition exposes its path/template, the file must exist.
      if (!empty($definition['path']) && !empty($definition['template'])) {
        $template = rtrim((string) $definition['path'], '/') . '/' . $definition['template'];
        $this->assertFileExists($template, "Template for {$id} exists on disk.");
      }
    }
  }

  /**
   * Recursively find files whose path ends with the given suffix.
   *
   * @return string[]
   *   Absolute file paths, sorted.
   */
  private function findBySuffix(string $dir, string $suffix): array {
    if (!is_dir($dir)) {
      return [];
    }
    $found = [];
    $iterator = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
      $path = $file->getPathname();
      if (str_ends_with($path, $suffix)) {
        $found[] = $path;
      }
    }
    sort($found);
    return $found;
  }

  /**
   * Theme-relative path for readable failure messages.
   */
  private function relative(string $absolute): string {
    return ltrim(str_replace($this->themePath, '', $absolute), '/');
  }

}
