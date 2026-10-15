<?php

declare(strict_types=1);

namespace Drupal\Tests\actools_bs5_test\Kernel;

use PHPUnit\Framework\Attributes\Group;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Core\Template\Attribute;
use Drupal\Component\Serialization\Yaml;

/**
 * Renders components to test URL filtering, HTML safety and slide wiring.
 *
 */
#[Group('actools_bs5')]
final class ComponentRenderingRegressionTest extends KernelTestBase {

  protected static $modules = ['system', 'file', 'user'];

  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system']);
    $this->container->get('theme_installer')->install(['actools_bs5']);
    $this->config('system.theme')->set('default', 'actools_bs5')->save();
    $theme = $this->container->get('theme.initialization')->getActiveThemeByName('actools_bs5');
    $this->container->get('theme.manager')->setActiveTheme($theme);
    $this->container->get('plugin.manager.sdc')->clearCachedDefinitions();
  }

  /**
   * Browser-normalized schemes must be blocked while ordinary links survive.
   */
  public function testUrlGuardsBeforeSchemaValidationRejectUnsafeInput(): void {
    $unsafe = [
      'javascript:alert(1)',
      "java\nscript:alert(1)",
      "java\tscript:alert(1)",
      "java\rscript:alert(1)",
      'data:text/html,<svg/onload=alert(1)>',
      'vbscript:msgbox(1)',
      '//example.com/path',
      "\\\\example.com\\path",
      'ftp://example.com/path',
    ];
    $safe = [
      'https://example.com/?x=1&y=2',
      'http://example.com/path',
      'mailto:hello@example.com',
      'tel:+911234567890',
      '/node/1', './details', '../details', '#course', '?page=2', 'courses/physics',
    ];
    foreach (['link' => 'url', 'button' => 'href', 'badge' => 'url', 'taxonomy' => 'url', 'list-group' => 'items', 'dynamic-responsive-image' => 'url', 'navbar-brand' => 'path'] as $component => $prop) {
      foreach ($unsafe as $url) {
        $xpath = $this->xpath($this->componentTemplate($component, $this->urlProps($component, $prop, $url)));
        $this->assertSame('#', $xpath->evaluate('string((//a)[1]/@href)'), $component . ' rejects ' . json_encode($url));
      }
      foreach ($safe as $url) {
        $xpath = $this->xpath($this->componentTemplate($component, $this->urlProps($component, $prop, $url)));
        $this->assertSame($url, $xpath->evaluate('string((//a)[1]/@href)'), $component . ' preserves ' . $url);
      }
    }
  }

  /**
   * Plain HTML strings retain formatting but cannot inject executable markup.
   */
  public function testRichHtmlStringsUseCoreXssFiltering(): void {
    $html = '<p>Allowed <strong>formatting</strong><script>alert(1)</script><img src="/image.png" onerror="alert(2)"><a href="javascript:alert(3)">Link</a></p>';
    $cases = [
      ['text', ['text' => $html]],
      ['html-code', ['code' => $html]],
      ['rich-text', ['text' => $html, 'trim' => FALSE]],
      ['card-icon', ['description' => $html]],
      ['card-pricing', ['text' => $html]],
      ['card-hero', ['content_text' => $html]],
      ['hero-side-by-side', ['text' => $html]],
    ];
    foreach ($cases as [$component, $props]) {
      $xpath = $this->xpath($this->component($component, $props));
      $this->assertSame(0, $xpath->query('//script')->length, $component);
      $this->assertSame(0, $xpath->query('//@*[starts-with(translate(name(), "ON", "on"), "on")]')->length, $component);
      $this->assertSame(0, $xpath->query('//a[starts-with(@href, "javascript:")]')->length, $component);
      $this->assertGreaterThan(0, $xpath->query('//strong[text()="formatting"]')->length, $component);
    }
  }

  /**
   * Cutting a rich-text summary cannot leave open tags or suffix markup.
   */
  public function testTrimmedRichTextProducesEscapedPlainText(): void {
    $html = '<p><a href="https://example.com">Read the full article here</a></p>';
    $result = $this->component('rich-text', [
      'text' => $html,
      'trim' => TRUE,
      'strip_html' => FALSE,
      'trim_length' => 2,
      'trim_units' => 'words',
      'suffix' => '<img src=x onerror=alert(1)>',
    ]);
    $xpath = $this->xpath($result);
    $this->assertSame(0, $xpath->query('//a | //p | //img')->length);
    $this->assertStringContainsString('Read the&lt;img', $result);
    $this->assertStringNotContainsString('full article', $result);
    $entity_result = $this->component('rich-text', [
      'text' => '<p>Heat &amp; temperature are related</p>',
      'trim' => TRUE,
      'trim_length' => 3,
      'trim_units' => 'words',
    ]);
    $this->assertStringContainsString('Heat &amp; temperature...', $entity_result);
    $this->assertStringNotContainsString('&amp;amp;', $entity_result);
    $paragraph_result = $this->component('rich-text', [
      'text' => '<p>Heat &amp; temperature</p><P>are related</P><div>but different</div><br>in physics',
      'trim' => TRUE,
      'trim_length' => 5,
      'trim_units' => 'words',
    ]);
    $this->assertStringContainsString('Heat &amp; temperature are related...', $paragraph_result);
    $this->assertStringNotContainsString('temperatureare', $paragraph_result);
  }

  /**
   * The native image path uses the normalized source, including object input.
   */
  public function testNativeImageUsesNormalizedSource(): void {
    foreach ([['url' => '/example.jpg'], ['uri' => '/example.jpg'], ['value' => '/example.jpg'], '/example.jpg'] as $src) {
      $xpath = $this->xpath($this->componentTemplate('image', [
        'media' => ['src' => $src, 'alt' => 'Example', 'width' => 400, 'height' => 200],
      ]));
      $this->assertSame('/example.jpg', $xpath->evaluate('string((//img)[1]/@src)'));
    }
  }

  /**
   * Repeated carousels have distinct targets and use their actual slide data.
   */
  public function testCarouselUsesSlideContentAndUniqueControlTargets(): void {
    $props = [
      'slide_component' => 'actools_bs5:card-hero',
      'controls' => TRUE,
      'indicators' => TRUE,
      'items' => [[
        'title' => 'Actual slide title',
        'content' => '<p>Actual slide content</p>',
        'media' => '<img src="/slide.jpg" alt="Slide image">',
        'indicator_label' => 'First slide',
      ]],
    ];
    $result = $this->renderInline("{{ include('actools_bs5:carousel', props, false) }}{{ include('actools_bs5:carousel', props, false) }}", ['props' => $this->withDefaults('carousel', $props)]);
    $xpath = $this->xpath($result);
    $carousels = $xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " carousel ")]');
    $this->assertSame(2, $carousels->length);
    $ids = [];
    foreach ($carousels as $carousel) {
      $id = $carousel->getAttribute('id');
      $this->assertNotSame('', $id);
      $this->assertNotContains($id, $ids);
      $ids[] = $id;
      foreach ($xpath->query('.//button[@data-bs-target]', $carousel) as $button) {
        $this->assertSame('#' . $id, $button->getAttribute('data-bs-target'));
      }
      $this->assertStringContainsString('Actual slide content', $carousel->textContent);
      $this->assertStringContainsString('Actual slide title', $carousel->textContent);
      $this->assertSame(1, $xpath->query('.//img[@src="/slide.jpg"]', $carousel)->length);
    }
    $this->assertStringNotContainsString('Demo content text', $result);
  }

  /**
   * Valid native media props render through SDC with no Canvas dependency.
   */
  public function testStandardMediaComponentsRenderWithoutCanvas(): void {
    $this->assertFalse($this->container->get('module_handler')->moduleExists('canvas'));
    $image = $this->xpath($this->component('image', [
      'media' => ['src' => '/example.jpg', 'alt' => 'Example', 'width' => 400, 'height' => 200],
    ]));
    $this->assertSame('/example.jpg', $image->evaluate('string((//img)[1]/@src)'));
    $video = $this->xpath($this->component('video', [
      'video_file' => ['src' => '/example.mp4', 'poster' => '/poster.jpg'],
    ]));
    $this->assertSame('/example.mp4', $video->evaluate('string((//source)[1]/@src)'));
    $this->assertSame('/poster.jpg', $video->evaluate('string((//video)[1]/@poster)'));
  }

  /**
   * A card without a button label links its title to the requested URL.
   */
  public function testCardWithoutButtonHasLinkedTitle(): void {
    $xpath = $this->xpath($this->component('card', [
      'heading_text' => 'Physics course',
      'url' => '/courses/physics',
      'button_label' => '',
    ]));
    $this->assertSame(1, $xpath->query('//a[@href="/courses/physics"]')->length);
    $this->assertSame('Physics course', trim($xpath->evaluate('string(//h3/a)')));
    $this->assertSame(0, $xpath->query('//a[contains(concat(" ", normalize-space(@class), " "), " btn ")]')->length);
  }

  /**
   * A card with a button exposes one usable link, with no duplicate title link.
   */
  public function testCardWithButtonHasOneLink(): void {
    $xpath = $this->xpath($this->component('card', [
      'heading_text' => 'Physics course',
      'url' => '/courses/physics',
      'button_label' => 'View course',
      'button_variant' => 'btn-primary',
    ]));
    $this->assertSame(1, $xpath->query('//a[@href="/courses/physics"]')->length);
    $this->assertSame(0, $xpath->query('//h3/a')->length);
    $this->assertSame(1, $xpath->query('//a[contains(concat(" ", normalize-space(@class), " "), " btn ")]')->length);
    $this->assertSame('View course', trim($xpath->evaluate('string((//a)[1])')));
  }

  /**
   * Two navbars each control their own collapse container.
   */
  public function testRepeatedNavbarsHaveDistinctMatchingTargets(): void {
    $result = $this->renderInline("{{ include('actools_bs5:navbar', props, false) }}{{ include('actools_bs5:navbar', props, false) }}", ['props' => $this->withDefaults('navbar', [])]);
    $xpath = $this->xpath($result);
    $navbars = $xpath->query('//nav');
    $this->assertSame(2, $navbars->length);
    $ids = [];
    foreach ($navbars as $navbar) {
      $buttons = $xpath->query('.//button[@data-bs-toggle="collapse"]', $navbar);
      $this->assertSame(1, $buttons->length);
      $button = $buttons->item(0);
      $id = $button->getAttribute('aria-controls');
      $this->assertNotSame('', $id);
      $this->assertNotContains($id, $ids);
      $ids[] = $id;
      $this->assertSame('#' . $id, $button->getAttribute('data-bs-target'));
      $this->assertSame(1, $xpath->query('.//div[@id="' . $id . '"]', $navbar)->length);
    }
  }

  /**
   * Drupal block labels remain visible as a level-two heading.
   */
  public function testBlockLabelRendersAsHeading(): void {
    $xpath = $this->xpath($this->component('block', [
      'label' => 'Latest teaching resources',
      'html_tag' => 'section',
      'plugin_id' => 'test_block',
      'configuration' => ['provider' => 'block'],
      'title_attributes' => new Attribute(),
      'content_attributes' => new Attribute(),
      'content' => 'Resource content',
    ]));
    $this->assertSame('Latest teaching resources', trim($xpath->evaluate('string(//h2)')));
  }

  private function component(string $component, array $props): string {
    return $this->renderInline("{{ include('actools_bs5:" . $component . "', props, false) }}", ['props' => $this->withDefaults($component, $props)]);
  }

  /**
   * User fields survive, compact views omit actions, profiles avoid duplicate titles.
   */
  public function testUserTemplateDistinguishesAuthorPicturesFromProfiles(): void {
    $theme_path = DRUPAL_ROOT . '/' . $this->container->get('extension.list.theme')->getPath('actools_bs5');
    $template = file_get_contents($theme_path . '/templates/user/user.html.twig');
    $this->assertIsString($template);
    $user = \Drupal\user\Entity\User::create(['name' => 'Author name']);
    foreach (['compact' => 0, 'full' => 1] as $mode => $profile_elements) {
      $result = $this->renderInline($template, [
        'elements' => ['#view_mode' => $mode],
        'user' => $user,
        'user_actions' => ['#markup' => '<a href="/user/1/edit">Edit profile</a>'],
        'content' => ['user_picture' => ['#markup' => '<img src="/author.png" alt="Author picture">']],
        'attributes' => new Attribute(),
      ]);
      $xpath = $this->xpath($result);
      $this->assertSame(0, $xpath->query('//h2[contains(@class, "user__name")]')->length, $mode);
      $this->assertSame($profile_elements, $xpath->query('//nav[contains(@class, "user__actions")]')->length, $mode);
      $this->assertSame(1, $xpath->query('//img[@src="/author.png"]')->length, $mode);
      $this->assertSame(1, $xpath->query('//article[contains(@class, "user--' . $mode . '")]')->length, $mode);
    }
  }

  /**
   * Supply declared defaults explicitly instead of assuming SDC inserts them.
   */
  private function withDefaults(string $component, array $props): array {
    $theme_path = DRUPAL_ROOT . '/' . $this->container->get('extension.list.theme')->getPath('actools_bs5');
    $metadata_files = glob($theme_path . '/components/*/' . $component . '/' . $component . '.component.yml');
    $this->assertCount(1, $metadata_files);
    $metadata = Yaml::decode(file_get_contents($metadata_files[0]));
    $properties = $metadata['props']['properties'] ?? [];
    foreach ($properties as $key => $schema) {
      if (!array_key_exists($key, $props) && array_key_exists('default', $schema)) {
        $props[$key] = $schema['default'];
      }
    }
    foreach ($metadata['props']['required'] ?? [] as $key) {
      if (!array_key_exists($key, $props) && isset($properties[$key]['enum'][0])) {
        $props[$key] = $properties[$key]['enum'][0];
      }
      $this->assertArrayHasKey($key, $props, 'The fixture must supply required prop ' . $key);
    }
    return $props;
  }

  /**
   * Hostile values exercise Twig guards before optional SDC schema validation.
   */
  private function componentTemplate(string $component, array $props): string {
    $theme_path = DRUPAL_ROOT . '/' . $this->container->get('extension.list.theme')->getPath('actools_bs5');
    $templates = glob($theme_path . '/components/*/' . $component . '/' . $component . '.twig');
    $this->assertCount(1, $templates);
    $source = file_get_contents($templates[0]);
    $this->assertIsString($source);
    return $this->renderInline($source, $props + ['attributes' => new Attribute()]);
  }

  private function urlProps(string $component, string $prop, string $url): array {
    $props = [$prop => $url, 'label' => 'Link', 'text' => 'Brand'];
    if ($component === 'list-group') {
      $props['items'] = [['href' => $url, 'content' => 'Link']];
    }
    if ($component === 'dynamic-responsive-image') {
      $props += [
        'width' => '400', 'height' => '200', 'alt' => 'Example',
        'data' => ['original_source' => '/example.jpg', 'original_width' => 400, 'original_height' => 200],
      ];
    }
    return $props;
  }

  private function renderInline(string $template, array $context): string {
    $build = ['#type' => 'inline_template', '#template' => $template, '#context' => $context];
    return (string) $this->container->get('renderer')->renderRoot($build);
  }

  private function xpath(string $html): \DOMXPath {
    $previous = libxml_use_internal_errors(TRUE);
    $document = new \DOMDocument();
    $document->loadHTML('<!DOCTYPE html><html><body>' . $html . '</body></html>');
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    return new \DOMXPath($document);
  }

}
