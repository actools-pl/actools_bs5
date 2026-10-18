<?php

declare(strict_types=1);

namespace Drupal\Tests\actools_bs5_test\Kernel;

use Drupal\block_content\Entity\BlockContent;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Plugin\Context\EntityContext;
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
    $this->installSchema('layout_builder', ['inline_block_usage']);
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
    $directory = 'public://actools-tests';
    $this->container->get('file_system')->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY);
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
    // Access decisions are memoized within one PHP request. Simulate a new
    // request while retaining the render caches under test.
    $this->container->get('entity_type.manager')->getAccessControlHandler('node')->resetCache();
    [$hidden_html] = $this->renderBlockAs($block, new AnonymousUserSession());
    $this->assertStringNotContainsString('Updated capillarity lesson', $hidden_html, 'Unpublishing also invalidates previously warm cards.');
    $published->setPublished()->save();
    $this->container->get('entity_type.manager')->getAccessControlHandler('node')->resetCache();
    [$restored_html] = $this->renderBlockAs($block, new AnonymousUserSession());
    $this->assertStringContainsString('Updated capillarity lesson', $restored_html);
  }

  /**
   * Native inline blocks keep fields and access after a saved layout reload.
   */
  public function testNativeInlineBlocksPersistAndRenderWithParentAccess(): void {
    $resource = $this->resource('Inline capillarity worksheet', 'Resource selected in an inline block.');
    $draft = $this->resource('Inline private answer key', 'Do not expose this draft.', FALSE);
    $text = BlockContent::create([
      'type' => 'actools_text_image', 'info' => 'Inline introduction',
      'status' => 1, 'reusable' => FALSE,
      'field_actools_heading' => 'Inline surface forces',
      'field_actools_body' => ['value' => '<p>Explore <strong>capillary pressure</strong>.</p>', 'format' => 'actools_test_html'],
      'field_actools_position' => 'right',
    ]);
    $text->save();
    $cards = BlockContent::create([
      'type' => 'actools_resource_cards', 'info' => 'Inline resources',
      'status' => 1, 'reusable' => FALSE,
      'field_actools_heading' => 'Inline practice resources',
      'field_actools_resources' => [['target_id' => $resource->id()], ['target_id' => $draft->id()]],
    ]);
    $cards->save();

    $topic = Node::create([
      'type' => 'actools_topic', 'title' => 'Topic with inline custom blocks',
      'status' => 1,
      'field_actools_body' => ['value' => 'Topic overview.', 'format' => 'plain_text'],
    ]);
    $topic->save();
    $components = [];
    foreach ([$text, $cards] as $weight => $block) {
      // These are the same derivative plugins and revision configuration used
      // by Layout Builder's "Create custom block" operation.
      $plugin_id = 'inline_block:' . $block->bundle();
      $this->assertTrue($this->container->get('plugin.manager.block')->hasDefinition($plugin_id));
      $components[] = (new SectionComponent($block->uuid(), 'content', [
        'id' => $plugin_id, 'label' => $block->label(), 'label_display' => FALSE,
        'provider' => 'layout_builder', 'view_mode' => 'default',
        'block_id' => $block->id(),
        'block_revision_id' => $block->getRevisionId(),
        'block_serialized' => NULL,
      ]))->setWeight($weight);
    }
    $topic->set('layout_builder__layout', [new Section('actools_bs5_onecol_contained', [], $components)]);
    $topic->save();
    $node_storage = $this->container->get('entity_type.manager')->getStorage('node');
    $node_storage->resetCache([$topic->id()]);
    $topic = $node_storage->load($topic->id());
    $section = Section::fromArray($topic->get('layout_builder__layout')->first()->section->toArray());
    $saved_components = array_values($section->getComponents());
    $this->assertCount(2, $saved_components);
    $block_storage = $this->container->get('entity_type.manager')->getStorage('block_content');
    foreach ($saved_components as $component) {
      $configuration = $component->toArray()['configuration'];
      $saved_block = $block_storage->loadRevision($configuration['block_revision_id']);
      $this->assertFalse($saved_block->isReusable());
      if ($saved_block->bundle() === 'actools_text_image') {
        $this->assertSame('Inline surface forces', $saved_block->get('field_actools_heading')->value);
        $this->assertStringContainsString('<strong>capillary pressure</strong>', $saved_block->get('field_actools_body')->value);
      }
      else {
        $this->assertSame('actools_resource_cards', $saved_block->bundle());
        $this->assertSame([(string) $resource->id(), (string) $draft->id()], array_map('strval', array_column($saved_block->get('field_actools_resources')->getValue(), 'target_id')));
      }
    }

    $switcher = $this->container->get('account_switcher');
    $switcher->switchTo(new AnonymousUserSession());
    try {
      foreach ([1, 2] as $pass) {
        // Core sets each InlineBlock's access dependency from this context.
        $build = Section::fromArray($section->toArray())->toRenderArray([
          'layout_builder.entity' => EntityContext::fromEntity($topic),
        ], FALSE);
        $html = (string) $this->container->get('renderer')->renderRoot($build);
        $this->assertStringContainsString('actools-text-image--text-only', $html);
        $this->assertStringContainsString('Inline surface forces', $html);
        $this->assertStringContainsString('<strong>capillary pressure</strong>', $html);
        $this->assertStringContainsString('actools-resource-cards__items', $html);
        $this->assertStringContainsString('Inline capillarity worksheet', $html);
        $this->assertStringNotContainsString('Inline private answer key', $html);
      }
      $topic->setUnpublished()->save();
      // A real request has fresh access-handler memoization. Keep the render
      // cache warm while discarding only that per-request decision cache.
      $this->container->get('entity_type.manager')->getAccessControlHandler('node')->resetCache();
      $this->container->get('entity_type.manager')->getAccessControlHandler('block_content')->resetCache();
      $build = Section::fromArray($section->toArray())->toRenderArray([
        'layout_builder.entity' => EntityContext::fromEntity($topic),
      ], FALSE);
      $html = (string) $this->container->get('renderer')->renderRoot($build);
      $this->assertStringNotContainsString('Inline surface forces', $html, 'An inline block follows its parent topic access.');
      $this->assertStringNotContainsString('Inline capillarity worksheet', $html);
    }
    finally {
      $switcher->switchBack();
    }
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
   * Core widget tables defer live render arrays instead of JSON-encoding them.
   */
  public function testCoreTablesKeepLiveRenderArraysOutsideJsonProps(): void {
    // Entity-reference widgets can carry cyclic entity/form objects. They are
    // legitimate render-array metadata, not serializable SDC properties.
    $cycle = new \stdClass();
    $cycle->self = $cycle;
    $cell = [
      '#type' => 'inline_template',
      '#template' => '{{ label }}',
      '#context' => ['label' => 'Select a teaching resource'],
      '#actools_cyclic_probe' => $cycle,
      '#cache' => ['tags' => ['actools_table:probe']],
      '#attached' => ['library' => ['core/drupal']],
    ];
    $build = ['#type' => 'table', '#header' => ['Resource'], '#rows' => [
      ['data' => [['data' => $cell]]],
    ]];
    $html = (string) $this->container->get('renderer')->renderRoot($build);
    $this->assertStringContainsString('Select a teaching resource', $html);
    $this->assertStringContainsString('<table', $html);
    $this->assertContains('actools_table:probe', CacheableMetadata::createFromRenderArray($build)->getCacheTags());
    $this->assertContains('core/drupal', $build['#attached']['library']);
  }

  /**
   * Hidden controls work while preprocessing never consumes visible headings.
   */
  public function testPreprocessingLeavesHeadingRenderArraysUntouched(): void {
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
    foreach ([\Drupal\Core\Access\AccessResult::forbidden(), \Drupal\Core\Access\AccessResult::neutral(), \Drupal\Core\Access\AccessResult::allowed()] as $access) {
      $variables = ['content' => $view];
      $variables['content']['field_actools_heading']['#access'] = $access->cachePerPermissions();
      $variables['content']['field_actools_heading']['#cache']['tags'] = ['actools_heading:probe'];
      $variables['content']['field_actools_heading']['#attached']['drupalSettings']['restricted_heading'] = 'Private heading setting';
      $original_heading = $variables['content']['field_actools_heading'];
      actools_bs5_preprocess_block($variables);
      $this->assertArrayNotHasKey('actools_heading', $variables, 'No raw heading variable exists for any access result.');
      $this->assertSame($original_heading, $variables['content']['field_actools_heading']);
      $this->assertSame('left', $variables['actools_position'], 'The hidden position control remains effective.');
      $metadata = BubbleableMetadata::createFromRenderArray($variables['content']);
      $this->assertContains('user.permissions', $metadata->getCacheContexts());
      $this->assertContains('actools_heading:probe', $metadata->getCacheTags());
      $this->assertArrayNotHasKey('restricted_heading', $metadata->getAttachments()['drupalSettings'] ?? [], 'Preprocessing never promotes field attachments, even before an allowed field renders.');
    }
    unset($view['field_actools_heading']);
    $variables = ['content' => $view];
    actools_bs5_preprocess_block($variables);
    $this->assertArrayNotHasKey('field_actools_heading', $variables['content'], 'A display-hidden heading stays absent.');
    $this->assertArrayNotHasKey('actools_heading', $variables);
    $this->assertSame('left', $variables['actools_position']);
  }

  /**
   * Deferred decisions gate heading markup and descendant formatter settings.
   */
  public function testPresentationMappingPreservesElementAccessAndMetadata(): void {
    foreach (['actools_text_image', 'actools_resource_cards'] as $bundle) {
      foreach (['forbidden', 'neutral', 'boolean_denied', 'allowed', 'boolean_allowed'] as $mode) {
        $heading = 'Restricted heading ' . $bundle . ' ' . $mode;
        $block = BlockContent::create([
          'type' => $bundle, 'info' => $heading, 'status' => 1,
          'reusable' => TRUE, 'field_actools_heading' => $heading,
        ]);
        if ($bundle === 'actools_text_image') {
          $block->set('field_actools_body', ['value' => 'A public introduction.', 'format' => 'plain_text']);
          $block->set('field_actools_position', 'left');
        }
        $block->save();
        $this->container->get('state')->set('actools_bs5_test.heading_access_probe', ['block_id' => $block->id(), 'mode' => $mode]);
        foreach ([1, 2] as $pass) {
          [$html, $metadata] = $this->renderBlockAs($block, new AnonymousUserSession());
          $allowed = in_array($mode, ['allowed', 'boolean_allowed'], TRUE);
          if ($allowed) {
            $this->assertStringContainsString($heading, $html);
            $this->assertMatchesRegularExpression('/<h2[^>]*>\s*<span[^>]*>' . preg_quote($heading, '/') . '<\/span>\s*<\/h2>/', $html);
            $this->assertContains('core/drupal.debounce', $metadata->getAttachments()['library'] ?? []);
            $this->assertSame('Private heading setting', $metadata->getAttachments()['drupalSettings']['actoolsRestrictedHeading'] ?? NULL);
          }
          else {
            $this->assertStringNotContainsString($heading, $html, 'A denied heading cannot reappear through its block label (' . $bundle . ', ' . $mode . ').');
            $this->assertDoesNotMatchRegularExpression('/<h2\b/', $html, 'Denied fields produce no empty heading.');
            $this->assertNotContains('core/drupal.debounce', $metadata->getAttachments()['library'] ?? []);
            $this->assertArrayNotHasKey('actoolsRestrictedHeading', $metadata->getAttachments()['drupalSettings'] ?? []);
          }
          $this->assertContains('actools_heading_element:probe', $metadata->getCacheTags());
          if (!str_starts_with($mode, 'boolean_')) {
            $this->assertContains('actools_heading_access:' . $mode, $metadata->getCacheTags(), 'AccessResult metadata comes from core running the callback.');
          }
        }
      }
    }
    $this->container->get('state')->delete('actools_bs5_test.heading_access_probe');
  }

  /**
   * Equal-permission viewers cannot reuse another viewer's allowed heading.
   */
  public function testDeferredHeadingAccessSurvivesWarmViewerCaches(): void {
    // Neither viewer has special permissions; only the callback's user cache
    // context can distinguish these otherwise equivalent authenticated users.
    Role::load(RoleInterface::AUTHENTICATED_ID)->grantPermission('access content')->save();
    $allowed_viewer = User::create(['uid' => 2, 'name' => 'Allowed heading reader', 'status' => 1]);
    $allowed_viewer->save();
    $denied_viewer = User::create(['uid' => 3, 'name' => 'Denied heading reader', 'status' => 1]);
    $denied_viewer->save();
    foreach (['actools_text_image', 'actools_resource_cards'] as $bundle) {
      $block = BlockContent::create([
        'type' => $bundle, 'info' => 'Private viewer heading', 'status' => 1,
        'reusable' => TRUE, 'field_actools_heading' => 'Private viewer heading',
      ]);
      if ($bundle === 'actools_text_image') {
        $block->set('field_actools_body', ['value' => 'A public introduction.', 'format' => 'plain_text']);
      }
      $block->save();
      $this->container->get('state')->set('actools_bs5_test.heading_access_probe', ['block_id' => $block->id(), 'mode' => 'viewer']);
      foreach ([$allowed_viewer, $denied_viewer, $denied_viewer, $allowed_viewer, $allowed_viewer, $denied_viewer] as $viewer) {
        [$html, $metadata] = $this->renderBlockAs($block, $viewer);
        if ((int) $viewer->id() === 2) {
          $this->assertStringContainsString('Private viewer heading', $html);
          $this->assertArrayHasKey('actoolsRestrictedHeading', $metadata->getAttachments()['drupalSettings'] ?? []);
        }
        else {
          $this->assertStringNotContainsString('Private viewer heading', $html);
          $this->assertDoesNotMatchRegularExpression('/<h2\b/', $html);
          $this->assertArrayNotHasKey('actoolsRestrictedHeading', $metadata->getAttachments()['drupalSettings'] ?? []);
        }
        $this->assertContains('user', $metadata->getCacheContexts());
        $this->assertContains('actools_heading_access:viewer', $metadata->getCacheTags());
        $this->assertNotSame(0, $metadata->getCacheMaxAge(), 'Viewer transitions exercise cacheable block output.');
      }
    }
    $this->container->get('state')->delete('actools_bs5_test.heading_access_probe');
  }

  /**
   * Renders a reusable block through Layout Builder without clearing caches.
   *
   * @return array{0: string, 1: \Drupal\Core\Render\BubbleableMetadata}
   *   HTML and bubbled metadata, including authorised attachments.
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
      return [$html, BubbleableMetadata::createFromRenderArray($build)];
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
      return [$html, BubbleableMetadata::createFromRenderArray($build)];
    }
    finally {
      $switcher->switchBack();
    }
  }

}
