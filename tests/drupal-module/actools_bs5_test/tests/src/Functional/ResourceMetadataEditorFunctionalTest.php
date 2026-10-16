<?php

declare(strict_types=1);

namespace Drupal\Tests\actools_bs5_test\Functional;

use Drupal\FunctionalTests\Core\Recipe\RecipeTestTrait;
use Drupal\node\Entity\Node;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Creates and revises resource metadata through the real native select widgets.
 */
#[Group('actools_bs5')]
#[RunTestsInSeparateProcesses]
final class ResourceMetadataEditorFunctionalTest extends BrowserTestBase {

  use FunctionalResponseDiagnosticsTrait;
  use RecipeTestTrait;

  protected static $modules = ['block', 'filter'];

  protected $defaultTheme = 'actools_bs5';

  protected function setUp(): void {
    parent::setUp();
    $this->config('system.logging')->set('error_level', 'verbose')->save();
    $path = $this->container->get('extension.list.theme')->getPath('actools_bs5');
    $this->applyRecipe($this->root . '/' . $path . '/recipes/actools_topic_starter');
    if (!\Drupal\block\Entity\Block::load('actools_bs5_content')) {
      $this->drupalPlaceBlock('system_main_block', ['region' => 'content']);
    }
  }

  /**
   * Metadata is optional, stores machine values, and displays native labels.
   */
  public function testEditorCanLeaveMetadataEmptyThenSelectAndClearValues(): void {
    $editor = $this->drupalCreateUser([
      'access content', 'create actools_resource content', 'edit own actools_resource content',
    ]);
    $this->drupalLogin($editor);
    $this->drupalGet('node/add/actools_resource');
    $this->assertResponseStatus(200);
    foreach (['field_actools_kind' => ['lesson', 'notes', 'worked_examples', 'assignment'], 'field_actools_level' => ['foundation', 'jee_advanced', 'olympiad']] as $field => $values) {
      $widget = $this->assertSession()->fieldExists($field);
      $this->assertFalse($widget->hasAttribute('required'));
      $this->assertSession()->optionExists($field, '_none');
      foreach ($values as $value) {
        $this->assertSession()->optionExists($field, $value);
      }
    }
    $this->submitForm(['title[0][value]' => 'Optional metadata resource'], 'Save');
    $this->assertResponseStatus(200);
    $nodes = $this->container->get('entity_type.manager')->getStorage('node')->loadByProperties(['title' => 'Optional metadata resource']);
    $this->assertCount(1, $nodes);
    $resource = reset($nodes);
    $this->assertTrue($resource->get('field_actools_kind')->isEmpty());
    $this->assertTrue($resource->get('field_actools_level')->isEmpty());
    $this->assertSession()->elementNotExists('css', '.actools-resource-metadata');

    $this->drupalGet($resource->toUrl('edit-form'));
    $this->assertResponseStatus(200);
    $this->submitForm([
      'field_actools_kind' => 'worked_examples',
      'field_actools_level' => 'jee_advanced',
    ], 'Save');
    $this->assertResponseStatus(200);
    $this->assertSession()->pageTextContains('Worked examples');
    $this->assertSession()->pageTextContains('JEE Advanced');
    $this->assertSession()->elementsCount('css', '.actools-resource-metadata__item', 2);
    $this->container->get('entity_type.manager')->getStorage('node')->resetCache([$resource->id()]);
    $resource = Node::load($resource->id());
    $this->assertSame('worked_examples', $resource->get('field_actools_kind')->value);
    $this->assertSame('jee_advanced', $resource->get('field_actools_level')->value);

    $this->drupalGet($resource->toUrl('edit-form'));
    $this->submitForm(['field_actools_kind' => '_none', 'field_actools_level' => '_none'], 'Save');
    $this->assertResponseStatus(200);
    $this->assertSession()->elementNotExists('css', '.actools-resource-metadata');
  }

}
