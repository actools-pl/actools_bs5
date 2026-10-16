<?php

declare(strict_types=1);

namespace Drupal\Tests\actools_bs5_test\Functional;

use Drupal\block_content\Entity\BlockContent;
use Drupal\Core\File\FileSystemInterface;
use Drupal\FunctionalTests\Core\Recipe\RecipeTestTrait;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\node\Entity\Node;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests native editor forms after applying the optional recipe to a real site.
 */
#[Group('actools_bs5')]
#[RunTestsInSeparateProcesses]
final class TopicStarterEditorFunctionalTest extends BrowserTestBase {

  use FunctionalResponseDiagnosticsTrait;
  use RecipeTestTrait;

  // Text formats belong to the site. Normal installation supplies core's
  // plain-text fallback without making the optional recipe change formats.
  protected static $modules = ['block', 'filter', 'actools_bs5_test'];

  protected $defaultTheme = 'actools_bs5';

  protected function setUp(): void {
    parent::setUp();
    $this->config('system.logging')->set('error_level', 'verbose')->save();
    $path = $this->container->get('extension.list.theme')->getPath('actools_bs5');
    // Core's test helper invokes the real CLI, checks its exit code, and
    // rebuilds the test runner after any recipe-installed modules.
    $this->applyRecipe($this->root . '/' . $path . '/recipes/actools_topic_starter');
    $this->drupalPlaceBlock('system_main_block', ['region' => 'content']);
    $this->drupalPlaceBlock('system_messages_block', ['region' => 'content']);
    $this->drupalPlaceBlock('page_title_block', ['region' => 'content']);
  }

  /**
   * An editor creates, revises and places both blocks using core field widgets.
   */
  public function testCreateEditAndPlaceNativeBlocks(): void {
    $editor = $this->drupalCreateUser([
      'access content', 'access block library',
      'create actools_text_image block content',
      'edit any actools_text_image block content',
      'create actools_resource_cards block content',
      'edit any actools_resource_cards block content',
    ]);
    $this->drupalLogin($editor);

    $this->drupalGet('block/add/actools_text_image');
    $this->assertResponseStatus(200);
    $this->assertSession()->fieldExists('field_actools_heading[0][value]');
    $this->assertSession()->fieldExists('field_actools_body[0][value]');
    $this->assertSession()->fieldExists('files[field_actools_image_0]');
    $this->assertSession()->optionExists('field_actools_position', 'left');
    $this->assertSession()->optionExists('field_actools_position', 'right');

    $directory = 'public://actools-editor-fixture';
    $this->container->get('file_system')->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY);
    $uri = 'public://actools-editor-fixture/diagram.png';
    file_put_contents($uri, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAQAAAAECAIAAAAmkwkpAAAAFElEQVR4nGP8//8/AwwwMSAB3BwAlm4DBfIlvvkAAAAASUVORK5CYII='));
    $this->submitForm([
      'info[0][value]' => 'Meniscus introduction',
      'field_actools_heading[0][value]' => 'Reading a meniscus',
      'field_actools_body[0][value]' => 'First introduction written by an editor.',
      'field_actools_position' => 'right',
      'files[field_actools_image_0]' => $this->container->get('file_system')->realpath($uri),
    ], 'Save');
    $this->assertResponseStatus(200);
    // The managed image widget requires alternative text after upload. A
    // recipe setting alone is insufficient: prove the real form enforces it.
    $alt = $this->assertSession()->fieldExists('field_actools_image[0][alt]');
    $this->assertTrue($alt->hasAttribute('required'));
    // Submit the uploaded image again without alt text. Check persistence,
    // rather than assuming a front-end theme uses core's message CSS/ARIA DOM.
    $this->submitForm([], 'Save');
    $this->assertResponseStatus(200);
    $this->assertSession()->fieldExists('field_actools_image[0][alt]');
    $unsaved = $this->container->get('entity_type.manager')->getStorage('block_content')->loadByProperties(['info' => 'Meniscus introduction']);
    $this->assertCount(0, $unsaved, 'An image block cannot be saved without required alternative text.');
    $this->submitForm(['field_actools_image[0][alt]' => 'Concave water meniscus'], 'Save');
    $this->assertResponseStatus(200);
    $blocks = $this->container->get('entity_type.manager')->getStorage('block_content')->loadByProperties(['info' => 'Meniscus introduction']);
    $this->assertCount(1, $blocks);
    $text_image = reset($blocks);
    $this->assertSame('Concave water meniscus', $text_image->get('field_actools_image')->alt);

    $this->drupalGet($text_image->toUrl('edit-form'));
    $this->assertResponseStatus(200);
    $this->submitForm([
      'field_actools_heading[0][value]' => 'Understanding a meniscus',
      'field_actools_body[0][value]' => 'Revised introduction after reviewing the diagram.',
      'field_actools_position' => 'left',
    ], 'Save');
    $this->assertResponseStatus(200);

    $resource = Node::create([
      'type' => 'actools_resource', 'title' => 'Capillary rise worksheet', 'status' => 1,
      'field_actools_summary' => 'Worked questions for the surface tension topic.',
    ]);
    $resource->save();
    $this->drupalGet('block/add/actools_resource_cards');
    $this->assertResponseStatus(200);
    $this->submitForm([
      'info[0][value]' => 'Selected teaching resources',
      'field_actools_heading[0][value]' => 'Practice resources',
      'field_actools_resources[0][target_id]' => $resource->label() . ' (' . $resource->id() . ')',
    ], 'Save');
    $this->assertResponseStatus(200);
    $storage = $this->container->get('entity_type.manager')->getStorage('block_content');
    $cards = $storage->loadByProperties(['info' => 'Selected teaching resources']);
    $this->assertCount(1, $cards);
    $cards = reset($cards);
    $storage->resetCache([$text_image->id()]);
    $text_image = BlockContent::load($text_image->id());

    $components = [];
    foreach ([$text_image, $cards] as $weight => $block) {
      $components[] = (new SectionComponent($this->container->get('uuid')->generate(), 'content', [
        'id' => 'block_content:' . $block->uuid(),
        'label' => $block->label(), 'label_display' => FALSE,
        'provider' => 'block_content', 'view_mode' => 'default',
      ]))->setWeight($weight);
    }
    $topic = Node::create([
      'type' => 'actools_topic', 'title' => 'Editor-built topic', 'status' => 1,
      'field_actools_body' => ['value' => 'Topic overview.', 'format' => 'plain_text'],
      'layout_builder__layout' => [new Section('actools_bs5_onecol_contained', [], $components)],
    ]);
    $topic->save();
    // These are actual saved core Layout Builder components, not a fixture
    // template include. Reload the page twice to include warm render caches.
    foreach ([1, 2] as $pass) {
      $this->drupalGet($topic->toUrl());
      $this->assertResponseStatus(200);
      $this->assertSession()->elementsCount('css', 'h1', 1);
      $this->assertSession()->pageTextContains('Understanding a meniscus');
      $this->assertSession()->pageTextContains('Revised introduction after reviewing the diagram.');
      $this->assertSession()->pageTextNotContains('First introduction written by an editor.');
      $this->assertSession()->elementExists('css', '.actools-text-image--media-left img[alt="Concave water meniscus"]');
      $this->assertSession()->elementExists('css', '.actools-resource-cards .actools-resource-card');
      $this->assertSession()->linkExists('Capillary rise worksheet');
      $this->assertSession()->pageTextContains('Worked questions for the surface tension topic.');
    }
  }

}
