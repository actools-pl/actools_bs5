<?php

declare(strict_types=1);

namespace Drupal\Tests\actools_bs5_test\Kernel;

use Drupal\Core\Recipe\Recipe;
use Drupal\Core\Recipe\RecipeRunner;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\user\RoleInterface;
use Drupal\views\Views;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Executes the optional finder against real resource fields and node grants.
 */
#[Group('actools_bs5')]
#[RunTestsInSeparateProcesses]
final class ResourceDiscoveryKernelTest extends KernelTestBase {

  protected static $modules = [
    'system', 'user', 'field', 'field_ui', 'text', 'filter', 'file', 'image',
    'breakpoint', 'responsive_image', 'options', 'node', 'block', 'block_content',
    'contextual', 'path_alias', 'layout_discovery', 'layout_builder', 'views',
    'actools_bs5_test', 'actools_discovery_test',
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
    $this->installConfig(['system', 'user', 'node', 'file', 'image', 'filter', 'block_content', 'views']);
    $this->container->get('theme_installer')->install(['actools_bs5']);
    $path = $this->container->get('extension.list.theme')->getPath('actools_bs5');
    foreach (['actools_topic_starter', 'actools_resource_discovery'] as $recipe) {
      RecipeRunner::processRecipe(Recipe::createFromDirectory(DRUPAL_ROOT . '/' . $path . '/recipes/' . $recipe));
      $this->container = \Drupal::getContainer();
    }
    $this->config('system.theme')->set('default', 'actools_bs5')->save();
    $this->container->get('theme.manager')->setActiveTheme($this->container->get('theme.initialization')->getActiveThemeByName('actools_bs5'));
    $this->container->get('theme.registry')->reset();
    $this->container->get('router.builder')->rebuild();
    foreach ([RoleInterface::ANONYMOUS_ID, RoleInterface::AUTHENTICATED_ID] as $role) {
      Role::load($role)->grantPermission('access content')->save();
    }
    User::create(['uid' => 1, 'name' => 'Fixture administrator', 'status' => 1])->save();
  }

  /**
   * One query searches title OR summary and combines the native select filters.
   */
  public function testCombinedTextAndMetadataFiltersRenderNativeCards(): void {
    $title = $this->resource('Capillary rise', 'A water column.', 'notes', 'foundation');
    $summary = $this->resource('Water in glass', 'Compare capillary pressure.', 'worked_examples', 'jee_advanced');
    $empty = $this->resource('Resource with no metadata', 'Useful reading without a thumbnail.');
    $this->resource('Unrelated lesson', 'A thermal balance.', 'notes', 'foundation');
    $draft = $this->resource('Draft capillary answer', 'capillary', 'notes', 'foundation', FALSE);
    $topic = Node::create([
      'type' => 'actools_topic', 'title' => 'Capillary Topic', 'status' => 1,
      'field_actools_body' => ['value' => 'Topic, not a Resource result.', 'format' => 'plain_text'],
    ]);
    $topic->save();

    [$html, $ids, $total] = $this->find(['q' => 'capillary']);
    $this->assertSame([(int) $title->id(), (int) $summary->id()], $ids);
    $this->assertSame(2, $total);
    $this->assertStringContainsString('actools-resource-card__heading', $html);
    $this->assertStringContainsString('<h2>Resources</h2>', $html);
    $this->assertStringNotContainsString($draft->label(), $html);
    $this->assertStringNotContainsString($topic->label(), $html);
    [, $ids, $total] = $this->find(['q' => 'capillary', 'kind' => 'worked_examples', 'level' => 'jee_advanced']);
    $this->assertSame([(int) $summary->id()], $ids);
    $this->assertSame(1, $total);
    [$html, $ids] = $this->find(['kind' => 'notes', 'level' => 'olympiad']);
    $this->assertSame([], $ids);
    $this->assertStringContainsString('No resources match your filters.', $html);
    [, $ids] = $this->find(['q' => 'no metadata']);
    $this->assertSame([(int) $empty->id()], $ids, 'Empty optional metadata never removes an unfiltered resource.');
  }

  /**
   * SQL escaping treats wildcard-like and non-Latin text as user search text.
   */
  public function testNonLatinAndWildcardSearchesUseLiteralText(): void {
    $resource = $this->resource('Поверхностное натяжение', 'Work with 100%_complete data.');
    $this->resource('Other resource', 'Work with 1000xcomplete data.');
    [, $ids, $total] = $this->find(['q' => 'натяжение']);
    $this->assertSame([(int) $resource->id()], $ids);
    $this->assertSame(1, $total);
    [, $ids, $total] = $this->find(['q' => '100%_complete']);
    $this->assertSame([(int) $resource->id()], $ids);
    $this->assertSame(1, $total);
    [$html, $ids] = $this->find(['q' => "' OR 1=1 --"]);
    $this->assertSame([], $ids);
    $this->assertStringContainsString('No resources match your filters.', $html);
  }

  /**
   * Warm results and totals respect per-reader grants and publication changes.
   */
  public function testWarmAccessCountsAndContentChanges(): void {
    $allowed = User::create(['uid' => 2, 'name' => 'Allowed finder reader', 'status' => 1]);
    $allowed->save();
    $denied = User::create(['uid' => 3, 'name' => 'Denied finder reader', 'status' => 1]);
    $denied->save();
    $public = $this->resource('Public pressure notes', 'Public pressure summary.');
    $restricted = $this->resource('Restricted pressure answers', 'Private pressure answer summary.', owner: 2);
    foreach ([$allowed, $denied, $allowed, $denied, new AnonymousUserSession()] as $viewer) {
      [$html, $ids, $total, $metadata] = $this->find(['q' => 'pressure'], $viewer);
      $expected = (int) $viewer->id() === 2 ? [(int) $public->id(), (int) $restricted->id()] : [(int) $public->id()];
      $this->assertSame($expected, $ids);
      $this->assertSame(count($expected), $total, 'Pager totals must not disclose inaccessible results.');
      $this->assertNotSame(0, $metadata->getCacheMaxAge());
      if ((int) $viewer->id() !== 2) {
        $this->assertStringNotContainsString('Restricted pressure answers', $html);
        $this->assertStringNotContainsString('Private pressure answer summary.', $html);
      }
    }
    $public->setTitle('Public energy notes')->set('field_actools_summary', 'An energy summary.')->save();
    [, $ids, $total] = $this->find(['q' => 'pressure']);
    $this->assertSame([], $ids, 'Saved edits remove stale matches while view caches remain warm.');
    $this->assertSame(0, $total);
    [, $ids, $total] = $this->find(['q' => 'energy']);
    $this->assertSame([(int) $public->id()], $ids);
    $this->assertSame(1, $total);
    $public->setUnpublished()->save();
    $this->container->get('entity_type.manager')->getAccessControlHandler('node')->resetCache();
    [, $ids, $total] = $this->find(['q' => 'energy']);
    $this->assertSame([], $ids);
    $this->assertSame(0, $total);
  }

  /**
   * Stable paged results neither duplicate rows nor count hidden resources.
   */
  public function testPagerKeepsTwelveStableAccessibleRows(): void {
    $expected = [];
    for ($i = 0; $i < 14; $i++) {
      $expected[] = (int) $this->resource(sprintf('Paging %02d', $i), 'Pager fixture.')->id();
    }
    $this->resource('Restricted Paging answer', 'Pager fixture.', owner: 2);
    $this->resource('Paging draft', 'Pager fixture.', published: FALSE);
    [, $first, $total] = $this->find(['q' => 'Pager fixture.']);
    $this->assertSame(array_slice($expected, 0, 12), $first);
    $this->assertSame(14, $total);
    [, $second, $total] = $this->find(['q' => 'Pager fixture.'], page: 1);
    $this->assertSame(array_slice($expected, 12), $second);
    $this->assertSame(14, $total);
    $this->assertSame([], array_intersect($first, $second));
  }

  private function resource(string $title, string $summary, ?string $kind = NULL, ?string $level = NULL, bool $published = TRUE, int $owner = 1): NodeInterface {
    $values = [
      'type' => 'actools_resource', 'title' => $title, 'status' => (int) $published, 'uid' => $owner,
      'field_actools_summary' => $summary,
    ];
    if ($kind !== NULL) {
      $values['field_actools_kind'] = $kind;
    }
    if ($level !== NULL) {
      $values['field_actools_level'] = $level;
    }
    $resource = Node::create($values);
    $resource->save();
    return $resource;
  }

  /**
   * @return array{0: string, 1: int[], 2: int, 3: \Drupal\Core\Render\BubbleableMetadata}
   *   Real View output, accessible query rows, total and bubbled cacheability.
   */
  private function find(array $input, ?AccountInterface $viewer = NULL, int $page = 0): array {
    $request = Request::create('/resources', 'GET', $input + ($page ? ['page' => $page] : []));
    $stack = $this->container->get('request_stack');
    $stack->push($request);
    $switcher = $this->container->get('account_switcher');
    $switcher->switchTo($viewer ?? new AnonymousUserSession());
    try {
      $view = Views::getView('actools_resources');
      $view->setDisplay('page_resources');
      $view->setExposedInput($input);
      $view->setCurrentPage($page);
      $this->assertSame([], $view->validate(), 'The exported View must validate as native core configuration.');
      // Execute the real query (including its result cache) before rendering.
      // A warm render-cache hit can otherwise legitimately skip pre_render and
      // leave this fresh View object's result property unpopulated.
      $view->execute();
      $build = $view->buildRenderable('page_resources');
      $html = (string) $this->container->get('renderer')->renderRoot($build);
      $ids = array_map(static fn ($row): int => (int) $row->_entity->id(), $view->result);
      return [$html, $ids, (int) $view->total_rows, BubbleableMetadata::createFromRenderArray($build)];
    }
    finally {
      $switcher->switchBack();
      $stack->pop();
    }
  }

}
