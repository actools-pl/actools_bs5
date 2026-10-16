<?php

declare(strict_types=1);

namespace Drupal\Tests\actools_bs5_test\Functional;

use Drupal\FunctionalTests\Core\Recipe\RecipeTestTrait;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\Tests\BrowserTestBase;
use Drupal\user\Entity\Role;
use Drupal\user\RoleInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Exercises native GET forms, pagination and negotiated-language result pages.
 */
#[Group('actools_bs5')]
#[RunTestsInSeparateProcesses]
final class ResourceDiscoveryFunctionalTest extends BrowserTestBase {

  use FunctionalResponseDiagnosticsTrait;
  use RecipeTestTrait;

  protected static $modules = ['block', 'filter', 'language', 'content_translation', 'actools_discovery_test'];

  protected $defaultTheme = 'actools_bs5';

  protected function setUp(): void {
    parent::setUp();
    $this->config('system.logging')->set('error_level', 'verbose')->save();
    $path = $this->container->get('extension.list.theme')->getPath('actools_bs5');
    foreach (['actools_topic_starter', 'actools_resource_discovery'] as $recipe) {
      $this->applyRecipe($this->root . '/' . $path . '/recipes/' . $recipe);
    }
    if (!\Drupal\block\Entity\Block::load('actools_bs5_content')) {
      $this->drupalPlaceBlock('system_main_block', ['region' => 'content']);
    }
    if (!\Drupal\block\Entity\Block::load('actools_bs5_page_title')) {
      $this->drupalPlaceBlock('page_title_block', ['region' => 'content']);
    }
    Role::load(RoleInterface::ANONYMOUS_ID)->grantPermission('access content')->save();
  }

  /**
   * Apply, reset, empty results and pagination work through real HTML GET forms.
   */
  public function testNativeFiltersAndPagerPreserveQueryState(): void {
    for ($i = 0; $i < 14; $i++) {
      $this->resource(sprintf('Capillary notes %02d', $i), 'Foundation diagrams.', 'notes', 'foundation');
    }
    $this->resource('Water meniscus examples', 'Capillary pressure calculations.', 'worked_examples', 'jee_advanced');
    $this->resource('Unpublished capillary answer', 'Capillary answer key.', 'notes', 'foundation', FALSE);
    $this->drupalGet('resources');
    $this->assertResponseStatus(200);
    $this->assertSession()->elementTextContains('css', 'h1', 'Find resources');
    $this->assertSession()->elementTextContains('css', '.view-actools-resources h2', 'Resources');
    $form = $this->assertSession()->elementExists('css', 'form.views-exposed-form');
    $this->assertSame('get', strtolower($form->getAttribute('method')));
    foreach (['keywords', 'kind', 'level'] as $name) {
      $this->assertSession()->fieldExists($name);
    }
    $this->submitForm(['keywords' => 'capillary', 'kind' => 'notes', 'level' => 'foundation'], 'Apply filters');
    $this->assertResponseStatus(200);
    $this->assertSession()->elementsCount('css', '.actools-resource-card', 12);
    $this->assertSession()->fieldValueEquals('keywords', 'capillary');
    $this->assertSession()->fieldValueEquals('kind', 'notes');
    $this->assertSession()->fieldValueEquals('level', 'foundation');
    $this->assertSession()->pageTextNotContains('Unpublished capillary answer');
    $next = $this->assertSession()->elementExists('css', '.pager__item--next a');
    parse_str((string) parse_url($next->getAttribute('href'), PHP_URL_QUERY), $query);
    $this->assertSame('capillary', $query['keywords']);
    $this->assertSame('notes', $query['kind']);
    $this->assertSame('foundation', $query['level']);
    $next->click();
    $this->assertResponseStatus(200);
    $this->assertSession()->elementsCount('css', '.actools-resource-card', 2);
    $this->assertSession()->pageTextContains('Capillary notes 12');
    $this->assertSession()->pageTextContains('Capillary notes 13');
    $this->submitForm([], 'Reset');
    $this->assertResponseStatus(200);
    $this->assertSession()->fieldValueEquals('keywords', '');
    $this->assertSession()->elementsCount('css', '.actools-resource-card', 12);
    $this->submitForm(['keywords' => 'capillary', 'kind' => 'worked_examples', 'level' => 'jee_advanced'], 'Apply filters');
    $this->assertResponseStatus(200);
    $this->assertSession()->elementsCount('css', '.actools-resource-card', 1);
    $this->assertSession()->pageTextContains('Water meniscus examples');
    $this->submitForm(['keywords' => 'unmatched query'], 'Apply filters');
    $this->assertResponseStatus(200);
    $this->assertSession()->pageTextContains('No resources match your filters.');
    $this->assertSession()->elementNotExists('css', '.actools-resource-card');
    $this->assertSession()->fieldValueEquals('keywords', 'unmatched query');
  }

  /**
   * Language-prefixed requests search and render the same published translation.
   */
  public function testNegotiatedContentLanguageAndWarmTranslationChanges(): void {
    ConfigurableLanguage::createFromLangcode('fr')->save();
    $this->container->get('content_translation.manager')->setEnabled('node', 'actools_resource', TRUE);
    $this->config('language.negotiation')->set('url.prefixes', ['en' => 'en', 'fr' => 'fr'])->save();
    $this->container->get('language_manager')->reset();
    $resource = $this->resource('Surface tension notes', 'Pressure diagrams.', 'notes', 'foundation');
    $translation = $resource->addTranslation('fr', [
      'title' => 'Tension superficielle', 'status' => 1,
      'field_actools_summary' => 'Diagrammes de pression.',
      'field_actools_kind' => 'worked_examples', 'field_actools_level' => 'jee_advanced',
    ]);
    $translation->save();
    $english_only = $this->resource('English only pressure', 'No French translation.', 'notes', 'foundation');
    drupal_flush_all_caches();
    $french = ConfigurableLanguage::load('fr');
    $english = ConfigurableLanguage::load('en');
    foreach ([1, 2] as $pass) {
      $this->drupalGet('resources', ['language' => $french, 'query' => ['keywords' => 'pression', 'kind' => 'worked_examples']]);
      $this->assertResponseStatus(200);
      $this->assertSession()->elementsCount('css', '.actools-resource-card', 1);
      $this->assertSession()->pageTextContains('Tension superficielle');
      $this->assertSession()->pageTextContains('Diagrammes de pression.');
      $this->assertSession()->pageTextNotContains($english_only->label());
      $this->assertSession()->pageTextNotContains('Surface tension notes');
      $this->drupalGet('resources', ['language' => $english, 'query' => ['keywords' => 'pressure', 'kind' => 'notes']]);
      $this->assertResponseStatus(200);
      $this->assertSession()->elementsCount('css', '.actools-resource-card', 2);
      $this->assertSession()->pageTextNotContains('Tension superficielle');
    }
    $translation->setUnpublished()->save();
    $this->drupalGet('resources', ['language' => $french, 'query' => ['keywords' => 'pression']]);
    $this->assertResponseStatus(200);
    $this->assertSession()->elementNotExists('css', '.actools-resource-card');
    $this->assertSession()->pageTextContains('No resources match your filters.');
    $this->drupalGet('resources', ['language' => $english, 'query' => ['keywords' => 'pressure']]);
    $this->assertResponseStatus(200);
    $this->assertSession()->elementsCount('css', '.actools-resource-card', 2);
  }

  private function resource(string $title, string $summary, string $kind, string $level, bool $published = TRUE): NodeInterface {
    $node = Node::create([
      'type' => 'actools_resource', 'title' => $title, 'status' => (int) $published,
      'langcode' => 'en', 'field_actools_summary' => $summary,
      'field_actools_kind' => $kind, 'field_actools_level' => $level,
    ]);
    $node->save();
    return $node;
  }

}
