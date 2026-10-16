<?php

declare(strict_types=1);

namespace Drupal\Tests\actools_bs5_test\Kernel;

use Drupal\block\Entity\Block;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\EventSubscriber\ActiveLinkResponseFilter;
use Drupal\Core\Template\Attribute;
use Drupal\Core\Url;
use Drupal\KernelTests\KernelTestBase;
use Drupal\system\Entity\Menu;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Renders native menu links and cached header/footer menu block instances.
 */
#[Group('actools_bs5')]
#[RunTestsInSeparateProcesses]
final class NavigationRenderingKernelTest extends KernelTestBase {

  protected static $modules = ['system', 'user', 'file', 'block', 'path_alias'];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    $this->installSchema('user', ['users_data']);
    $this->installConfig(['system', 'user', 'block']);
    if (!Menu::load('main')) {
      Menu::create(['id' => 'main', 'label' => 'Main navigation'])->save();
    }
    $this->container->get('theme_installer')->install(['actools_bs5']);
    $this->config('system.theme')->set('default', 'actools_bs5')->save();
    $theme = $this->container->get('theme.initialization')->getActiveThemeByName('actools_bs5');
    $this->container->get('theme.manager')->setActiveTheme($theme);
    $this->container->get('plugin.manager.sdc')->clearCachedDefinitions();
    $this->container->get('router.builder')->rebuild();
  }

  /**
   * A parent remains a real core link, with all native URL/list attributes.
   */
  public function testNativeParentLinksNonlinksAndAllDescendants(): void {
    $items = $this->items();
    $original_options = $items['parent']['url']->getOptions();
    $build = $this->menu($items, 'header', 'primary');
    $html = (string) $this->container->get('renderer')->renderRoot($build);
    $xpath = $this->document($html);
    $parent = $xpath->query('//a[text()="Topics landing page"]')->item(0);
    $this->assertNotNull($parent);
    $this->assertSame('external-course', $parent->getAttribute('data-course'));
    $this->assertSame('_blank', $parent->getAttribute('target'));
    $this->assertSame('noopener', $parent->getAttribute('rel'));
    $this->assertSame('Configured tooltip', $parent->getAttribute('title'));
    $this->assertStringContainsString('configured-link', $parent->getAttribute('class'));
    $this->assertStringContainsString('#capillarity', $parent->getAttribute('href'));
    parse_str(parse_url($parent->getAttribute('href'), PHP_URL_QUERY), $query);
    $this->assertSame(['subject' => 'Surface tension'], $query);
    $this->assertSame($original_options, $items['parent']['url']->getOptions(), 'Rendering does not mutate the source Url.');
    $this->assertSame(1, $xpath->query('//li[@data-item="preserved" and contains(@class, "configured-item") and contains(@class, "is-active-trail")]')->length);
    $this->assertSame(1, $xpath->query('//button[@aria-label="Toggle Topics landing page submenu"]')->length);
    $this->assertSame(1, $xpath->query('//li[@data-item="preserved"]/ul/li/ul/li/div/a[text()="Grandchild resource"]')->length, 'The third menu level remains reachable below its original parent.');
    $this->assertSame(3, $xpath->query('//a[text()="Grandchild resource"]')->length, 'The resource appears under the linked, non-link and button parents in this fixture.');
    $this->assertSame(1, $xpath->query('//span[text()="Non-link group"]')->length);
    $this->assertSame(0, $xpath->query('//a[text()="Non-link group"]')->length);
    $this->assertSame(1, $xpath->query('//button[normalize-space(.)="Button group" and @data-actools-submenu-toggle]')->length);
    $this->assertSame(0, $xpath->query('//*[@data-bs-toggle="dropdown"] | //*[@role="menu"] | //*[@role="menuitem"]')->length);
    $this->assertSame(0, $xpath->query('//ul[@hidden]')->length, 'All links are reachable before JavaScript enhancement.');
    foreach ($xpath->query('//*[@aria-controls]') as $control) {
      $target = $xpath->query('//*[@id="' . $control->getAttribute('aria-controls') . '"]');
      $this->assertSame(1, $target->length);
    }
    // Older menu alters sometimes supply a class string rather than an array.
    $items['parent']['url']->setOption('attributes', ['class' => 'legacy-one legacy-two']);
    $build = $this->menu($items, 'header', 'string-classes');
    $build['#attributes'] = new Attribute($build['#attributes']);
    $legacy = $this->document((string) $this->container->get('renderer')->renderRoot($build));
    $this->assertStringContainsString('legacy-one legacy-two', $legacy->evaluate('string(//a[text()="Topics landing page"]/@class)'));
  }

  /**
   * The native exact-page path is distinct from ancestor active-trail styling.
   */
  public function testCoreCurrentLinkAttributesSurviveForParentsAndChildren(): void {
    $items = $this->items();
    $items['parent']['url'] = Url::fromRoute('user.login', [], ['set_active_class' => TRUE]);
    $build = $this->menu($items, 'header', 'current');
    $html = (string) $this->container->get('renderer')->renderRoot($build);
    $this->assertStringContainsString('data-drupal-link-system-path="user/login"', $html);
    $filtered = ActiveLinkResponseFilter::setLinkActiveClass($html, 'user/login', FALSE, 'en', []);
    $xpath = $this->document($filtered);
    $this->assertSame(1, $xpath->query('//a[text()="Topics landing page" and @aria-current="page"]')->length);
    $this->assertSame(0, $xpath->query('//li[@aria-current] | //button[@aria-current]')->length);
  }

  /**
   * Warm inner caches cannot exchange IDs or disclosure modes across blocks.
   */
  public function testHeaderAndFooterInstancesKeepSeparateWarmCaches(): void {
    $header = $this->placement('navigation_header_probe', 'header', 'Primary navigation');
    $second = $this->placement('navigation_second_probe', 'header', 'Additional navigation');
    $footer = $this->placement('navigation_footer_probe', 'footer', 'Footer navigation');
    foreach ([1, 2] as $pass) {
      $output = '';
      foreach ([$header, $second, $footer] as $placement) {
        $build = $this->block($placement);
        $output .= (string) $this->container->get('renderer')->renderRoot($build);
        $this->assertContains('actools_menu_access:probe', CacheableMetadata::createFromRenderArray($build)->getCacheTags());
      }
      $xpath = $this->document($output);
      $this->assertSame(1, $xpath->query('//nav[@aria-label="Primary navigation"]')->length);
      $this->assertSame(1, $xpath->query('//nav[@aria-label="Footer navigation"]')->length);
      $this->assertSame(2, $xpath->query('//*[@data-actools-nav-toggle]')->length);
      $this->assertSame(0, $xpath->query('//nav[@aria-label="Footer navigation"]//*[@data-actools-submenu-toggle or @data-actools-nav-toggle or @data-actools-nav-panel]')->length);
      $this->assertSame(3, $xpath->query('//nav[@aria-label="Footer navigation"]//a[text()="Grandchild resource"]')->length);
      $this->assertSame(1, $xpath->query('//nav[@aria-label="Footer navigation"]//li[@data-item="preserved"]/ul/li/ul/li/div/a[text()="Grandchild resource"]')->length, 'The footer retains all three levels without disclosure controls.');
      $ids = [];
      foreach ($xpath->query('//*[@id]') as $element) {
        $id = $element->getAttribute('id');
        $this->assertNotContains($id, $ids, 'Each rendered menu placement has unique IDs, including warm caches.');
        $ids[] = $id;
      }
    }
    $header->setRegion('footer')->save();
    $build = $this->block($header);
    $moved = (string) $this->container->get('renderer')->renderRoot($build);
    $this->assertStringNotContainsString('data-actools-nav-toggle', $moved, 'Moving a cached block changes its rendering mode.');
  }

  private function placement(string $id, string $region, string $label): Block {
    $block = Block::create([
      'id' => $id, 'theme' => 'actools_bs5', 'region' => $region,
      'plugin' => 'system_menu_block:main',
      'settings' => ['id' => 'system_menu_block:main', 'label' => $label, 'label_display' => FALSE, 'provider' => 'system'],
    ]);
    $block->save();
    return $block;
  }

  private function block(Block $block): array {
    // The real block/menu theme hooks share a deliberately identical inner
    // cache key, mirroring a contributed builder that caches menu markup.
    $menu = $this->menu($this->items(), 'content', 'unused');
    unset($menu['#attributes']);
    $menu['#cache']['keys'] = ['actools_shared_menu_probe'];
    return [
      '#theme' => 'block', '#id' => $block->id(),
      '#configuration' => $block->getPlugin()->getConfiguration(),
      '#plugin_id' => 'system_menu_block:main', '#base_plugin_id' => 'system_menu_block', '#derivative_plugin_id' => 'main',
      '#cache' => ['tags' => $block->getCacheTags()],
      'content' => $menu,
    ];
  }

  private function menu(array $items, string $region, string $instance): array {
    return [
      '#theme' => 'menu__main', '#menu_name' => 'main', '#items' => $items,
      '#attributes' => ['data-actools-menu-region' => $region, 'data-actools-menu-instance' => $instance],
      '#cache' => ['tags' => ['actools_menu_access:probe'], 'contexts' => ['user.permissions']],
    ];
  }

  private function items(): array {
    $leaf = static fn (string $title, Url $url, array $below = []): array => [
      'title' => $title, 'url' => $url, 'attributes' => new Attribute(),
      'in_active_trail' => FALSE, 'is_expanded' => (bool) $below, 'below' => $below,
    ];
    $grandchild = $leaf('Grandchild resource', Url::fromUri('https://example.com/resource'));
    $child = $leaf('Child resources', Url::fromUri('https://example.com/child'), ['grandchild' => $grandchild]);
    $parent = $leaf('Topics landing page', Url::fromUri('https://example.com/topics', [
      'query' => ['subject' => 'Surface tension'], 'fragment' => 'capillarity',
      'attributes' => ['class' => ['configured-link'], 'data-course' => 'external-course', 'target' => '_blank', 'rel' => 'noopener', 'title' => 'Configured tooltip'],
    ]), ['child' => $child]);
    $parent['attributes'] = new Attribute(['class' => ['configured-item'], 'data-item' => 'preserved']);
    $parent['in_active_trail'] = TRUE;
    return [
      'parent' => $parent,
      'nolink' => $leaf('Non-link group', Url::fromRoute('<nolink>'), ['resource' => $grandchild]),
      'button' => $leaf('Button group', Url::fromRoute('<button>'), ['resource' => $grandchild]),
    ];
  }

  private function document(string $html): \DOMXPath {
    $previous = libxml_use_internal_errors(TRUE);
    $document = new \DOMDocument();
    $document->loadHTML('<!DOCTYPE html><html><body>' . $html . '</body></html>');
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    return new \DOMXPath($document);
  }

}
