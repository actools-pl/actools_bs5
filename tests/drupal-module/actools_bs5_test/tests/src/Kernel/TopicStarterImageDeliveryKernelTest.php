<?php

declare(strict_types=1);

namespace Drupal\Tests\actools_bs5_test\Kernel;

use Drupal\block_content\Entity\BlockContent;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Recipe\Recipe;
use Drupal\Core\Recipe\RecipeRunner;
use Drupal\file\Entity\File;
use Drupal\image\Entity\ImageStyle;
use Drupal\KernelTests\KernelTestBase;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\node\Entity\Node;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Verifies actual recipe formatters and generated, uncropped image derivatives.
 */
#[Group('actools_bs5')]
#[RunTestsInSeparateProcesses]
final class TopicStarterImageDeliveryKernelTest extends KernelTestBase {

  protected static $modules = [
    'system', 'user', 'field', 'field_ui', 'text', 'filter', 'file', 'image', 'breakpoint',
    'responsive_image', 'options', 'node', 'block', 'block_content', 'contextual',
    'path_alias', 'layout_discovery', 'layout_builder', 'actools_bs5_test',
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
    $this->config('system.image')->set('toolkit', 'gd')->save();
    $this->container->get('theme_installer')->install(['actools_bs5']);
    $theme_path = $this->container->get('extension.list.theme')->getPath('actools_bs5');
    RecipeRunner::processRecipe(Recipe::createFromDirectory(DRUPAL_ROOT . '/' . $theme_path . '/recipes/actools_topic_starter'));
    $this->container = \Drupal::getContainer();
    $this->config('system.theme')->set('default', 'actools_bs5')->save();
    $this->container->get('theme.manager')->setActiveTheme($this->container->get('theme.initialization')->getActiveThemeByName('actools_bs5'));
    $this->container->get('theme.registry')->reset();
    $this->container->get('router.builder')->rebuild();
    $directory = 'public://actools-image-tests';
    $this->assertTrue($this->container->get('file_system')->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY));
  }

  /**
   * Both real displays expose derivative srcsets, with the original only linked.
   */
  public function testResourceAndLayoutBlockUseResponsiveDerivatives(): void {
    $file = $this->diagram('large', 3200, 1600);
    $original_hash = hash_file('sha256', $file->getFileUri());
    foreach ([FALSE, TRUE] as $is_block) {
      $entity = $this->entityWithImage($file, 3200, 1600, $is_block);
      foreach ([1, 2] as $pass) {
        [$html, $metadata] = $this->renderEntity($entity);
        $xpath = $this->imageDocument($html);
        $image = $xpath->query('//img[@alt="Uncropped physics diagram"]')->item(0);
        $this->assertInstanceOf(\DOMElement::class, $image);
        $this->assertSame('lazy', $image->getAttribute('loading'));
        $this->assertStringContainsString('/styles/actools_diagram_960/', $image->getAttribute('src'));
        $this->assertStringStartsWith('auto,', $image->getAttribute('sizes'));
        foreach ([480, 960, 1440, 1920] as $width) {
          $this->assertMatchesRegularExpression('~/styles/actools_diagram_' . $width . '/[^,]+ ' . $width . 'w(?:,|$)~', $image->getAttribute('srcset'));
          $this->assertContains('config:image.style.actools_diagram_' . $width, $metadata->getCacheTags());
        }
        $link = $xpath->query('//a[.//img[@alt="Uncropped physics diagram"]]')->item(0);
        $this->assertInstanceOf(\DOMElement::class, $link);
        $original_url = $this->container->get('file_url_generator')->generateString($file->getFileUri());
        // The link generator encodes the colon in Kernel's virtual public
        // filesystem path; compare the decoded paths, not encoding variants.
        $this->assertSame(rawurldecode((string) parse_url($original_url, PHP_URL_PATH)), rawurldecode((string) parse_url($link->getAttribute('href'), PHP_URL_PATH)));
        $this->assertStringNotContainsString('/styles/', $link->getAttribute('href'));
        $this->assertContains('config:responsive_image.styles.actools_diagram', $metadata->getCacheTags());
      }
    }

    // Exercise GD's real delivery transforms, rather than only checking YAML.
    foreach ([480, 960, 1440, 1920] as $width) {
      $style = ImageStyle::load('actools_diagram_' . $width);
      $this->assertInstanceOf(ImageStyle::class, $style);
      $effects = iterator_to_array($style->getEffects());
      $this->assertCount(1, $effects);
      $effect = reset($effects);
      $this->assertSame('image_scale', $effect->getPluginId());
      $this->assertFalse($effect->getConfiguration()['data']['upscale']);
      $destination = $style->buildUri($file->getFileUri());
      $this->assertTrue($style->createDerivative($file->getFileUri(), $destination));
      $derivative = $this->container->get('image.factory')->get($destination);
      $this->assertTrue($derivative->isValid());
      $this->assertSame($width, $derivative->getWidth());
      $this->assertSame((int) ($width / 2), $derivative->getHeight());
    }
    $this->assertSame($original_hash, hash_file('sha256', $file->getFileUri()), 'Responsive delivery leaves the full-resolution original unchanged.');
  }

  /**
   * A small upload retains its real dimensions in markup and every derivative.
   */
  public function testSmallDiagramsAreNeverUpscaled(): void {
    $file = $this->diagram('small', 320, 160);
    foreach ([480, 960, 1440, 1920] as $width) {
      $style = ImageStyle::load('actools_diagram_' . $width);
      $destination = $style->buildUri($file->getFileUri());
      $this->assertTrue($style->createDerivative($file->getFileUri(), $destination));
      $derivative = $this->container->get('image.factory')->get($destination);
      $this->assertSame(320, $derivative->getWidth());
      $this->assertSame(160, $derivative->getHeight());
    }
    foreach ([FALSE, TRUE] as $is_block) {
      [$html] = $this->renderEntity($this->entityWithImage($file, 320, 160, $is_block));
      $image = $this->imageDocument($html)->query('//img[@alt="Uncropped physics diagram"]')->item(0);
      $this->assertInstanceOf(\DOMElement::class, $image);
      $this->assertSame('320', $image->getAttribute('width'));
      $this->assertSame('160', $image->getAttribute('height'));
      // Core deduplicates equal-width candidates instead of advertising an
      // invented 480w/960w size for an image that was not enlarged.
      $this->assertMatchesRegularExpression('/ 320w$/', $image->getAttribute('srcset'));
      $this->assertDoesNotMatchRegularExpression('/ (?:480|960|1440|1920)w/', $image->getAttribute('srcset'));
    }
  }

  private function diagram(string $name, int $width, int $height): File {
    $image = imagecreatetruecolor($width, $height);
    $this->assertNotFalse($image);
    imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
    $uri = 'public://actools-image-tests/' . $name . '.png';
    $this->assertTrue(imagepng($image, $uri));
    imagedestroy($image);
    $file = File::create(['uri' => $uri, 'status' => 1]);
    $file->save();
    return $file;
  }

  private function entityWithImage(File $file, int $width, int $height, bool $is_block): ContentEntityInterface {
    $values = [
      'type' => $is_block ? 'actools_text_image' : 'actools_resource',
      'status' => 1,
      'field_actools_body' => ['value' => 'Read the whole diagram.', 'format' => 'plain_text'],
      'field_actools_image' => ['target_id' => $file->id(), 'alt' => 'Uncropped physics diagram', 'width' => $width, 'height' => $height],
    ];
    if ($is_block) {
      $entity = BlockContent::create($values + ['info' => 'Responsive text and image', 'reusable' => TRUE, 'field_actools_position' => 'left']);
    }
    else {
      $entity = Node::create($values + ['title' => 'Responsive resource']);
    }
    $entity->save();
    return $entity;
  }

  /**
   * @return array{0: string, 1: \Drupal\Core\Cache\CacheableMetadata}
   *   HTML and metadata from the actual full Resource or Layout Builder block.
   */
  private function renderEntity(ContentEntityInterface $entity): array {
    if ($entity instanceof BlockContent) {
      $component = new SectionComponent($entity->uuid(), 'content', [
        'id' => 'block_content:' . $entity->uuid(),
        'label' => $entity->label(), 'label_display' => FALSE,
        'provider' => 'block_content', 'view_mode' => 'default',
      ]);
      $build = (new Section('actools_bs5_onecol_contained', [], [$component]))->toRenderArray([], FALSE);
    }
    else {
      $build = $this->container->get('entity_type.manager')->getViewBuilder('node')->view($entity, 'default');
    }
    $html = (string) $this->container->get('renderer')->renderRoot($build);
    return [$html, CacheableMetadata::createFromRenderArray($build)];
  }

  private function imageDocument(string $html): \DOMXPath {
    $document = new \DOMDocument();
    @$document->loadHTML($html);
    return new \DOMXPath($document);
  }

}
