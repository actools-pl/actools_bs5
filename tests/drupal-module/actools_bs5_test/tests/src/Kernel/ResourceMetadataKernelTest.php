<?php

declare(strict_types=1);

namespace Drupal\Tests\actools_bs5_test\Kernel;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Recipe\Recipe;
use Drupal\Core\Recipe\RecipeRunner;
use Drupal\field\Entity\FieldConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\user\RoleInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Renders optional resource metadata through native Drupal list formatters.
 */
#[Group('actools_bs5')]
#[RunTestsInSeparateProcesses]
final class ResourceMetadataKernelTest extends KernelTestBase {

  protected static $modules = [
    'system', 'user', 'field', 'field_ui', 'text', 'filter', 'file', 'image', 'breakpoint',
    'responsive_image', 'options', 'node', 'block', 'block_content', 'contextual',
    'path_alias', 'layout_discovery', 'layout_builder',
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
    $this->container->get('theme_installer')->install(['actools_bs5']);
    $path = $this->container->get('extension.list.theme')->getPath('actools_bs5');
    RecipeRunner::processRecipe(Recipe::createFromDirectory(DRUPAL_ROOT . '/' . $path . '/recipes/actools_topic_starter'));
    $this->container = \Drupal::getContainer();
    $this->config('system.theme')->set('default', 'actools_bs5')->save();
    $this->container->get('theme.manager')->setActiveTheme($this->container->get('theme.initialization')->getActiveThemeByName('actools_bs5'));
    $this->container->get('theme.registry')->reset();
    $this->container->get('router.builder')->rebuild();
    // Native Layout Builder field blocks check their parent node's access.
    // Published Topic fixtures are public content for this rendering test.
    Role::load(RoleInterface::ANONYMOUS_ID)->grantPermission('access content')->save();
    User::create(['uid' => 1, 'name' => 'Resource metadata author', 'status' => 1])->save();
  }

  /**
   * Human-readable option labels appear once on cards and full resources.
   */
  public function testOptionLabelsRenderAndNodeEditsInvalidateWarmMetadata(): void {
    $kinds = ['lesson' => 'Lesson', 'notes' => 'Notes', 'worked_examples' => 'Worked examples', 'assignment' => 'Assignment'];
    $levels = ['foundation' => 'Foundation', 'jee_advanced' => 'JEE Advanced', 'olympiad' => 'Olympiad'];
    $index = 0;
    foreach ($kinds as $kind => $kind_label) {
      $level = array_keys($levels)[$index++ % count($levels)];
      $resource = $this->resource($kind, $level);
      foreach (['actools_resource_card', 'full'] as $mode) {
        foreach ([1, 2] as $pass) {
          [$html, $metadata] = $this->renderResource($resource, $mode);
          $xpath = $this->metadataDocument($html);
          $items = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " actools-resource-metadata__item ")]');
          $this->assertSame(2, $items->length);
          $this->assertSame($kind_label, trim($items->item(0)->textContent));
          $this->assertSame($levels[$level], trim($items->item(1)->textContent));
          $this->assertSame(0, $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " field__label ")]')->length, 'Metadata field labels are configured hidden.');
          $this->assertContains('node:' . $resource->id(), $metadata->getCacheTags());
          $this->assertNotSame(0, $metadata->getCacheMaxAge());
          if ($mode === 'actools_resource_card') {
            $this->assertSame(1, $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " actools-resource-card__metadata ")]')->length);
          }
        }
      }
    }

    $resource = $this->resource('worked_examples', 'jee_advanced');
    [$original] = $this->renderResource($resource, 'actools_resource_card');
    $this->assertStringContainsString('Worked examples', $original);
    $resource->set('field_actools_kind', 'assignment')->set('field_actools_level', 'olympiad')->save();
    [$updated] = $this->renderResource($resource, 'actools_resource_card');
    $this->assertStringContainsString('Assignment', $updated);
    $this->assertStringContainsString('Olympiad', $updated);
    $this->assertStringNotContainsString('Worked examples', $updated);
    $this->assertStringNotContainsString('JEE Advanced', $updated);
  }

  /**
   * Existing resources with no values require no migration or empty badge row.
   */
  public function testEmptyOptionalMetadataDoesNotAddPresentationMarkup(): void {
    foreach (['kind', 'level'] as $name) {
      $field = FieldConfig::load('node.actools_resource.field_actools_' . $name);
      $this->assertInstanceOf(FieldConfig::class, $field);
      $this->assertFalse($field->isRequired());
      $this->assertSame([], $field->getDefaultValueLiteral());
    }
    $resource = $this->resource();
    $this->assertCount(0, $resource->validate(), 'A title-only resource remains valid with both new fields empty.');
    foreach (['actools_resource_card', 'full'] as $mode) {
      [$html] = $this->renderResource($resource, $mode);
      $xpath = $this->metadataDocument($html);
      $this->assertSame(0, $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " actools-resource-metadata ")]')->length);
    }
    // Either field can stand alone; there is no invented default or empty
    // second item when an editor describes only one aspect of a resource.
    $resource->set('field_actools_kind', 'notes')->save();
    [$html] = $this->renderResource($resource, 'actools_resource_card');
    $items = $this->metadataDocument($html)->query('//*[contains(concat(" ", normalize-space(@class), " "), " actools-resource-metadata__item ")]');
    $this->assertSame(1, $items->length);
    $this->assertSame('Notes', trim($items->item(0)->textContent));
  }

  /**
   * Denied native fields produce no labels but still bubble access cacheability.
   */
  public function testMetadataSlotHonoursDeferredAccessAndPreservesCacheability(): void {
    $resource = $this->resource('assignment', 'olympiad');
    $fields = [];
    foreach (['kind', 'level'] as $name) {
      $field = $resource->get('field_actools_' . $name)->view(['type' => 'list_default', 'label' => 'hidden']);
      // Use a real formatted field and core's deferred access API. A scalar
      // reconstruction of its option label would bypass this denial.
      unset($field['#access']);
      $field['#access_callback'] = static fn () => AccessResult::forbidden()->cachePerPermissions()->addCacheTags(['actools_metadata_access:probe']);
      $field['#cache']['tags'][] = 'actools_metadata_field:' . $name;
      $field[0]['#attached']['drupalSettings']['restrictedMetadata'] = 'Do not expose this setting';
      $fields[$name] = $field;
    }
    $build = [
      '#type' => 'inline_template',
      '#template' => "{% embed 'actools_bs5:resource-card' with {heading: 'Restricted resource', url: '/node/1', kind_content: fields.kind, level_content: fields.level} only %}{% block metadata %}{{ kind_content }}{{ level_content }}{% endblock %}{% endembed %}",
      '#context' => ['fields' => $fields],
    ];
    $html = (string) $this->container->get('renderer')->renderRoot($build);
    $this->assertStringNotContainsString('Assignment', $html);
    $this->assertStringNotContainsString('Olympiad', $html);
    $this->assertStringNotContainsString('actools-resource-card__metadata', $html);
    $metadata = CacheableMetadata::createFromRenderArray($build);
    $this->assertContains('user.permissions', $metadata->getCacheContexts());
    $this->assertContains('actools_metadata_access:probe', $metadata->getCacheTags());
    $this->assertContains('actools_metadata_field:kind', $metadata->getCacheTags());
    $this->assertContains('actools_metadata_field:level', $metadata->getCacheTags());
    $this->assertArrayNotHasKey('restrictedMetadata', $build['#attached']['drupalSettings'] ?? []);
  }

  /**
   * Topic publishing styles are scoped to the full view, preserving teasers.
   */
  public function testTopicPublishingWrapperIsRestrictedToFullView(): void {
    $topic = Node::create([
      'type' => 'actools_topic', 'title' => 'Topic rendering regression', 'status' => 1, 'uid' => 1,
      'field_actools_body' => ['value' => 'Start with the balance of forces.', 'format' => 'plain_text'],
    ]);
    $topic->save();
    foreach (['full', 'teaser'] as $view_mode) {
      $build = $this->container->get('entity_type.manager')->getViewBuilder('node')->view($topic, $view_mode);
      $html = (string) $this->container->get('renderer')->renderRoot($build);
      $xpath = $this->metadataDocument($html);
      $publishing = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " actools-topic--publishing ")]');
      $content = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " actools-topic__content ")]');
      if ($view_mode === 'full') {
        $this->assertSame(1, $publishing->length);
        $this->assertSame(1, $content->length);
        $this->assertContains('actools_bs5/publishing', $build['#attached']['library']);
        $this->assertStringContainsString('Start with the balance of forces.', $html);
      }
      else {
        $this->assertSame(0, $publishing->length);
        $this->assertSame(0, $content->length);
        $this->assertSame('Topic rendering regression', trim($xpath->evaluate('string(//h2/a)')));
        $this->assertStringContainsString('/node/' . $topic->id(), $xpath->evaluate('string(//h2/a/@href)'));
      }
    }
  }

  private function resource(?string $kind = NULL, ?string $level = NULL): NodeInterface {
    $resource = Node::create([
      'type' => 'actools_resource', 'title' => 'Metadata fixture', 'status' => 1, 'uid' => 1,
    ]);
    if ($kind !== NULL) {
      $resource->set('field_actools_kind', $kind);
    }
    if ($level !== NULL) {
      $resource->set('field_actools_level', $level);
    }
    $resource->save();
    return $resource;
  }

  /**
   * @return array{0: string, 1: \Drupal\Core\Cache\CacheableMetadata}
   *   Actual node output and its bubbled metadata, keeping render caches warm.
   */
  private function renderResource(NodeInterface $resource, string $view_mode): array {
    $build = $this->container->get('entity_type.manager')->getViewBuilder('node')->view($resource, $view_mode);
    $html = (string) $this->container->get('renderer')->renderRoot($build);
    return [$html, CacheableMetadata::createFromRenderArray($build)];
  }

  private function metadataDocument(string $html): \DOMXPath {
    $document = new \DOMDocument();
    @$document->loadHTML($html);
    return new \DOMXPath($document);
  }

}
