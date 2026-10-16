<?php

declare(strict_types=1);

namespace Drupal\Tests\actools_bs5_test\Kernel;

use Drupal\block_content\Entity\BlockContent;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Recipe\Recipe;
use Drupal\Core\Recipe\RecipeRunner;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\file\Entity\File;
use Drupal\filter\Entity\FilterFormat;
use Drupal\KernelTests\KernelTestBase;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\user\RoleInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Applies the real optional recipe and renders its entities through core.
 */
#[Group('actools_bs5')]
#[RunTestsInSeparateProcesses]
final class TopicStarterRecipeKernelTest extends KernelTestBase {

  protected static $modules = [
    'system', 'user', 'field', 'field_ui', 'text', 'filter', 'file', 'image',
    'options', 'link', 'node', 'block', 'block_content', 'contextual', 'path_alias',
    'layout_discovery', 'layout_builder', 'actools_bs5_test',
  ];

  protected function setUp(): void {
    parent::setUp();
    foreach (['user', 'file', 'node', 'block_content', 'path_alias'] as $entity_type) {
      $this->installEntitySchema($entity_type);
    }
    $this->installSchema('user', ['users_data']);
    $this->installSchema('file', ['file_usage']);
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'user', 'node', 'file', 'image', 'filter', 'block_content']);

    // Installing this theme is a fixture choice, not a side effect permitted to
    // the recipe. The recipe must preserve the site's current default theme.
    $this->container->get('theme_installer')->install(['actools_bs5', 'stark']);
    $this->config('system.theme')->set('default', 'stark')->save();
    $theme_before = $this->config('system.theme')->getRawData();
    $roles_before = $this->container->get('config.storage')->listAll('user.role.');
    $role_data_before = [];
    foreach ($roles_before as $name) {
      $role_data_before[$name] = $this->config($name)->getRawData();
    }

    $theme_path = $this->container->get('extension.list.theme')->getPath('actools_bs5');
    RecipeRunner::processRecipe(Recipe::createFromDirectory(DRUPAL_ROOT . '/' . $theme_path . '/recipes/actools_topic_starter'));
    // Recipe actions can rebuild the container.
    $this->container = \Drupal::getContainer();
    $this->assertSame($theme_before, $this->config('system.theme')->getRawData());
    $this->assertSame($roles_before, $this->container->get('config.storage')->listAll('user.role.'));
    foreach ($role_data_before as $name => $data) {
      $this->assertSame($data, $this->config($name)->getRawData());
    }
    foreach (['node', 'block_content'] as $entity_type) {
      $this->assertSame(0, (int) $this->container->get('entity_type.manager')->getStorage($entity_type)->getQuery()->accessCheck(FALSE)->count()->execute(), 'Applying the recipe creates configuration without demo content.');
    }

    // Rendering tests explicitly opt in to the theme after the safety checks.
    $this->config('system.theme')->set('default', 'actools_bs5')->save();
    $active = $this->container->get('theme.initialization')->getActiveThemeByName('actools_bs5');
    $this->container->get('theme.manager')->setActiveTheme($active);
    $this->container->get('plugin.manager.core.layout')->clearCachedDefinitions();
    $this->container->get('theme.registry')->reset();
    $this->container->get('router.builder')->rebuild();
    Role::load(RoleInterface::ANONYMOUS_ID)->grantPermission('access content')->save();
    User::create(['uid' => 1, 'name' => 'Recipe test administrator', 'status' => 1])->save();
    FilterFormat::create([
      'format' => 'actools_test_html',
      'name' => 'Restricted fixture HTML',
      'filters' => ['filter_html' => [
        'status' => TRUE,
        'settings' => ['allowed_html' => '<p> <strong> <em>', 'filter_html_help' => FALSE],
      ]],
    ])->save();
  }

  /**
   * Default topic sections render real fields and survive per-node overrides.
   */
  public function testTopicDefaultLayoutAndSavedOverride(): void {
    $display = LayoutBuilderEntityViewDisplay::load('node.actools_topic.default');
    $this->assertInstanceOf(LayoutBuilderEntityViewDisplay::class, $display);
    $this->assertTrue($display->isLayoutBuilderEnabled());
    $this->assertTrue($display->isOverridable());
    $sections = $display->getSections();
    $this->assertCount(1, $sections);
    $this->assertSame('actools_bs5_onecol_contained', $sections[0]->getLayoutId());
    $components = array_values($sections[0]->getComponents());
    usort($components, static fn (SectionComponent $a, SectionComponent $b): int => $a->getWeight() <=> $b->getWeight());
    $this->assertSame([
      'field_block:node:actools_topic:field_actools_body',
      'field_block:node:actools_topic:field_actools_resources',
    ], array_map(static fn (SectionComponent $component): string => $component->getPluginId(), $components));

    $resource = $this->resource('Capillary rise lesson', 'Learn how pressure balances the liquid column.');
    $topic = Node::create([
      'type' => 'actools_topic', 'title' => 'Surface tension', 'status' => 1,
      'field_actools_body' => ['value' => '<p>Start with <strong>surface forces</strong>.</p>', 'format' => 'actools_test_html'],
      'field_actools_resources' => [['target_id' => $resource->id()]],
    ]);
    $topic->save();
    [$html] = $this->renderTopicAs($topic, new AnonymousUserSession());
    $this->assertStringContainsString('<strong>surface forces</strong>', $html);
    $this->assertStringContainsString('actools-layout--contained', $html);
    $this->assertStringContainsString('actools-resource-card', $html);
    $this->assertStringContainsString('Capillary rise lesson', $html);
    $this->assertLessThan(strpos($html, 'Capillary rise lesson'), strpos($html, 'surface forces'));

    // Core's actual section field stores the default sections as an override.
    $topic->set('layout_builder__layout', array_map(static fn (Section $section): Section => Section::fromArray($section->toArray()), $sections));
    $topic->save();
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $storage->resetCache([$topic->id()]);
    $reloaded = $storage->load($topic->id());
    $this->assertSame($sections[0]->toArray(), $reloaded->get('layout_builder__layout')->first()->section->toArray());
    [$reloaded_html] = $this->renderTopicAs($reloaded, new AnonymousUserSession());
    $this->assertStringContainsString('Capillary rise lesson', $reloaded_html);
    $this->assertStringContainsString('<strong>surface forces</strong>', $reloaded_html);
    $this->assertSame($sections[0]->toArray(), LayoutBuilderEntityViewDisplay::load('node.actools_topic.default')->getSection(0)->toArray(), 'Saving a node override leaves the content-type default unchanged.');
  }

  /**
   * Core block plugins preserve filtering, images and position in a real section.
   */
  public function testTextImageBlockUsesCoreFieldsAndFilteredRendering(): void {
    $block = BlockContent::create([
      'type' => 'actools_text_image', 'info' => 'Introduction block', 'status' => 1,
      'reusable' => TRUE,
      'field_actools_heading' => 'Reading a meniscus',
      'field_actools_body' => ['value' => '<p>Keep <strong>curvature</strong>.</p><script>alert("probe")</script>', 'format' => 'actools_test_html'],
      'field_actools_position' => 'left',
    ]);
    $this->container->get('file_system')->prepareDirectory('public://actools-tests', FileSystemInterface::CREATE_DIRECTORY);
    $uri = 'public://actools-tests/diagram.png';
    file_put_contents($uri, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAQAAAAECAIAAAAmkwkpAAAAFElEQVR4nGP8//8/AwwwMSAB3BwAlm4DBfIlvvkAAAAASUVORK5CYII='));
    $file = File::create(['uri' => $uri, 'status' => 1]);
    $file->save();
    $block->set('field_actools_image', ['target_id' => $file->id(), 'alt' => 'Concave water meniscus diagram', 'width' => 4, 'height' => 4]);
    $block->save();
    [$html, $metadata] = $this->renderBlockAs($block, new AnonymousUserSession());
    $this->assertStringContainsString('actools-text-image', $html);
    $this->assertStringContainsString('actools-text-image--media-left', $html);
    $this->assertStringContainsString('Reading a meniscus', $html);
    $this->assertStringContainsString('<strong>curvature</strong>', $html);
    $this->assertStringNotContainsString('<script', $html);
    $this->assertStringContainsString('alt="Concave water meniscus diagram"', $html);
    $this->assertContains('block_content:' . $block->id(), $metadata->getCacheTags());
    $this->assertContains('config:filter.format.actools_test_html', $metadata->getCacheTags());
  }

  /**
   * Warm editor output cannot expose drafts; node edits invalidate card output.
   */
  public function testResourceCardsRespectAccessAndInvalidateAcrossWarmCaches(): void {
    $published = $this->resource('Published capillarity lesson', 'Original public summary');
    $draft = $this->resource('Unpublished teacher answer', 'Private answer summary', FALSE);
    $block = BlockContent::create([
      'type' => 'actools_resource_cards', 'info' => 'Resources block', 'status' => 1,
      'reusable' => TRUE, 'field_actools_heading' => 'Study resources',
      'field_actools_resources' => [['target_id' => $published->id()], ['target_id' => $draft->id()]],
    ]);
    $block->save();
    [$editor_html] = $this->renderBlockAs($block, User::load(1));
    $this->assertStringContainsString('Unpublished teacher answer', $editor_html);
    foreach ([1, 2] as $pass) {
      [$html, $metadata] = $this->renderBlockAs($block, new AnonymousUserSession());
      $this->assertStringContainsString('actools-resource-cards__items', $html);
      $this->assertStringContainsString('Published capillarity lesson', $html);
      $this->assertStringContainsString('Original public summary', $html);
      $this->assertStringNotContainsString('Unpublished teacher answer', $html);
      $this->assertStringNotContainsString('Private answer summary', $html);
      $this->assertContains('node:' . $published->id(), $metadata->getCacheTags());
      $this->assertNotSame(0, $metadata->getCacheMaxAge(), 'The access assertions exercise cacheable output.');
    }

    $published->setTitle('Updated capillarity lesson')->set('field_actools_summary', 'Revised public summary')->save();
    [$updated_html] = $this->renderBlockAs($block, new AnonymousUserSession());
    $this->assertStringContainsString('Updated capillarity lesson', $updated_html);
    $this->assertStringContainsString('Revised public summary', $updated_html);
    $this->assertStringNotContainsString('Published capillarity lesson', $updated_html);
    $this->assertStringNotContainsString('Original public summary', $updated_html);
    $this->assertStringNotContainsString('Unpublished teacher answer', $updated_html);

    $published->setUnpublished()->save();
    [$hidden_html] = $this->renderBlockAs($block, new AnonymousUserSession());
    $this->assertStringNotContainsString('Updated capillarity lesson', $hidden_html, 'Unpublishing also invalidates previously warm cards.');
    $published->setPublished()->save();
    [$restored_html] = $this->renderBlockAs($block, new AnonymousUserSession());
    $this->assertStringContainsString('Updated capillarity lesson', $restored_html);
  }

  private function resource(string $title, string $summary, bool $published = TRUE): NodeInterface {
    $node = Node::create([
      'type' => 'actools_resource', 'title' => $title, 'status' => (int) $published,
      'field_actools_summary' => $summary,
    ]);
    $node->save();
    return $node;
  }

  /**
   * Hidden editing controls work without bypassing denied presentation fields.
   */
  public function testPresentationMappingPreservesElementAccessAndMetadata(): void {
    $block = BlockContent::create([
      'type' => 'actools_text_image', 'info' => 'Mapping regression', 'status' => 1,
      'field_actools_heading' => 'Restricted heading',
      'field_actools_body' => ['value' => 'A public introduction.', 'format' => 'plain_text'],
      'field_actools_position' => 'left',
    ]);
    $block->save();
    $theme_path = $this->container->get('extension.list.theme')->getPath('actools_bs5');
    require_once DRUPAL_ROOT . '/' . $theme_path . '/actools_bs5.theme';
    $view = $this->container->get('entity_type.manager')->getViewBuilder('block_content')->view($block);
    $this->assertArrayNotHasKey('field_actools_position', $view, 'Position is an editing control, not displayed text.');
    foreach ([\Drupal\Core\Access\AccessResult::forbidden(), \Drupal\Core\Access\AccessResult::neutral()] as $access) {
      $variables = ['content' => $view];
      $variables['content']['field_actools_heading']['#access'] = $access->cachePerPermissions();
      $variables['content']['field_actools_heading']['#cache']['tags'] = ['actools_heading:probe'];
      $variables['content']['field_actools_heading']['#attached']['library'] = ['core/drupal'];
      $variables['content']['field_actools_heading']['#attached']['drupalSettings']['restricted_heading'] = 'Private heading setting';
      actools_bs5_preprocess_block($variables);
      $this->assertSame('', $variables['actools_heading'], 'A denied/neutral render element must not expose its raw field value.');
      $this->assertSame('left', $variables['actools_position'], 'The hidden position control remains effective.');
      $metadata = \Drupal\Core\Render\BubbleableMetadata::createFromRenderArray($variables['content']);
      $this->assertContains('user.permissions', $metadata->getCacheContexts());
      $this->assertContains('actools_heading:probe', $metadata->getCacheTags());
      $this->assertNotContains('core/drupal', $metadata->getAttachments()['library'] ?? []);
      $this->assertArrayNotHasKey('restricted_heading', $metadata->getAttachments()['drupalSettings'] ?? []);
    }
    $variables = ['content' => $view];
    $variables['content']['field_actools_heading']['#access'] = \Drupal\Core\Access\AccessResult::allowed();
    $variables['content']['field_actools_heading']['#attached']['library'] = ['core/drupal'];
    actools_bs5_preprocess_block($variables);
    $this->assertSame('Restricted heading', $variables['actools_heading']);
    $this->assertContains('core/drupal', $variables['content']['#attached']['library']);
    unset($view['field_actools_heading']);
    $variables = ['content' => $view];
    actools_bs5_preprocess_block($variables);
    $this->assertSame('', $variables['actools_heading'], 'A display-hidden heading stays hidden.');
    $this->assertSame('left', $variables['actools_position']);
  }

  /**
   * Renders a reusable block through Layout Builder without clearing caches.
   *
   * @return array{0: string, 1: \Drupal\Core\Cache\CacheableMetadata}
   *   HTML and bubbled metadata.
   */
  private function renderBlockAs(BlockContent $block, AccountInterface $viewer): array {
    $switcher = $this->container->get('account_switcher');
    $switcher->switchTo($viewer);
    try {
      // Reset the entity object, not render caches, to mirror another request.
      $this->container->get('entity_type.manager')->getStorage('block_content')->resetCache([$block->id()]);
      // A persisted Layout Builder component retains its UUID across requests.
      // Reuse it here so viewer transitions exercise the same block cache key.
      $component = new SectionComponent($block->uuid(), 'content', [
        'id' => 'block_content:' . $block->uuid(),
        'label' => $block->label(), 'label_display' => FALSE,
        'provider' => 'block_content', 'view_mode' => 'default',
      ]);
      $build = (new Section('actools_bs5_onecol_contained', [], [$component]))->toRenderArray([], FALSE);
      $html = (string) $this->container->get('renderer')->renderRoot($build);
      return [$html, CacheableMetadata::createFromRenderArray($build)];
    }
    finally {
      $switcher->switchBack();
    }
  }

  /**
   * @return array{0: string, 1: \Drupal\Core\Cache\CacheableMetadata}
   *   HTML and bubbled metadata from the node's real view display.
   */
  private function renderTopicAs(NodeInterface $topic, AccountInterface $viewer): array {
    $switcher = $this->container->get('account_switcher');
    $switcher->switchTo($viewer);
    try {
      $build = $this->container->get('entity_type.manager')->getViewBuilder('node')->view($topic, 'default');
      $html = (string) $this->container->get('renderer')->renderRoot($build);
      return [$html, CacheableMetadata::createFromRenderArray($build)];
    }
    finally {
      $switcher->switchBack();
    }
  }

}
