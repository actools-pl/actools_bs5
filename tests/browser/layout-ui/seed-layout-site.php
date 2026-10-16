<?php

/**
 * @file
 * Fixtures for an isolated CI site, invoked after site install and recipe apply.
 *
 * The first two topics deliberately have no blocks or layout overrides: the
 * browser must create those through the UI. A separate published teaching topic
 * supplies a real Drupal page for desktop/phone presentation acceptance.
 */

declare(strict_types=1);

use Drupal\block_content\Entity\BlockContent;
use Drupal\block\Entity\Block;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\Entity\File;
use Drupal\filter\Entity\FilterFormat;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\node\Entity\Node;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;

if (PHP_SAPI !== 'cli' || getenv('ACTOOLS_LAYOUT_UI_FIXTURE') !== '1') {
  throw new RuntimeException('This fixture is only for the disposable Layout Builder CI site.');
}
$fixture_path = getenv('ACTOOLS_LAYOUT_FIXTURE');
$password = getenv('ACTOOLS_LAYOUT_EDITOR_PASSWORD');
if (!$fixture_path || !$password) {
  throw new RuntimeException('The browser fixture output and ephemeral editor password are required.');
}
if (!FilterFormat::load('plain_text')) {
  throw new RuntimeException('Install the site normally so core supplies its plain-text fallback format.');
}
$display = LayoutBuilderEntityViewDisplay::load('node.actools_topic.default');
if (!$display || !$display->isLayoutBuilderEnabled() || !$display->isOverridable()) {
  throw new RuntimeException('Apply the real Actools topic starter recipe before creating fixtures.');
}

\Drupal::service('module_installer')->install(['contextual']);
\Drupal::configFactory()->getEditable('system.theme')->set('default', 'actools_bs5')->save();
\Drupal::configFactory()->getEditable('system.logging')->set('error_level', 'verbose')->save();

// Use one of each essential placement, avoiding optional theme config duplicates.
foreach (Block::loadMultiple() as $block) {
  if ($block->getTheme() === 'actools_bs5') {
    $block->delete();
  }
}
foreach ([
  ['messages', 'system_messages_block', -30],
  ['tasks', 'local_tasks_block', -20],
  ['title', 'page_title_block', -10],
  ['content', 'system_main_block', 0],
] as [$id, $plugin, $weight]) {
  Block::create([
    'id' => 'actools_ui_' . $id,
    'theme' => 'actools_bs5',
    'region' => 'content',
    'weight' => $weight,
    'plugin' => $plugin,
    'settings' => ['label_display' => FALSE],
  ])->save();
}

Role::load(Role::ANONYMOUS_ID)->grantPermission('access content')->save();
$role = Role::create(['id' => 'actools_layout_editor', 'label' => 'Disposable layout editor']);
foreach ([
  'access content',
  'access contextual links',
  'edit own actools_topic content',
  'edit own actools_resource content',
  'configure editable actools_topic node layout overrides',
  'create and edit custom blocks',
] as $permission) {
  $role->grantPermission($permission);
}
$role->save();
$editor = User::create([
  'name' => 'actools-layout-editor',
  'mail' => 'layout-editor@example.invalid',
  'status' => 1,
  'roles' => [$role->id()],
]);
$editor->setPassword($password);
$editor->save();

$resources = [];
foreach (['Capillarity solved problems', 'Surface tension concepts'] as $title) {
  $resource = Node::create([
    'type' => 'actools_resource',
    'title' => $title,
    'status' => 1,
    'uid' => $editor->id(),
    'field_actools_summary' => 'Public teaching resource for the browser editing check.',
    'field_actools_body' => ['value' => 'Complete resource content.', 'format' => 'plain_text'],
    // The browser changes the first resource through the actual node form.
    'field_actools_kind' => 'notes',
    'field_actools_level' => 'foundation',
  ]);
  $resource->save();
  $resources[] = ['id' => (int) $resource->id(), 'title' => $title, 'editPath' => '/node/' . $resource->id() . '/edit'];
}
$topics = [];
foreach (['UI-built topic', 'Unchanged shared topic'] as $title) {
  $topic = Node::create([
    'type' => 'actools_topic',
    'title' => $title,
    'uid' => $editor->id(),
    'status' => 1,
    'field_actools_body' => ['value' => 'Shared default introduction.', 'format' => 'plain_text'],
  ]);
  $topic->save();
  $topics[] = (int) $topic->id();
}

$image_path = dirname($fixture_path) . '/meniscus-diagram.png';
// CI may have either font family, or neither. Both paths produce labels around
// 50–60 native pixels high, so they remain legible in small resource cards.
$label_font = NULL;
if (function_exists('imagettftext') && function_exists('imagettfbbox')) {
  foreach ([
    '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
    '/usr/share/fonts/truetype/liberation2/LiberationSans-Regular.ttf',
    '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
  ] as $candidate) {
    if (is_readable($candidate)) {
      $label_font = $candidate;
      break;
    }
  }
}
$draw_label = static function (GdImage $target, int $x, int $y, string $text, int $color) use ($label_font): void {
  if ($label_font !== NULL) {
    $box = imagettfbbox(42, 0, $label_font, $text);
    $left = min($box[0], $box[2], $box[4], $box[6]);
    $top = min($box[1], $box[3], $box[5], $box[7]);
    imagettftext($target, 42, 0, $x - $left, $y - $top, $color, $label_font, $text);
    return;
  }
  $width = imagefontwidth(5) * strlen($text);
  $height = imagefontheight(5);
  $bitmap = imagecreatetruecolor($width, $height);
  imagealphablending($bitmap, FALSE);
  imagefill($bitmap, 0, 0, imagecolorallocatealpha($bitmap, 0, 0, 0, 127));
  imagesavealpha($bitmap, TRUE);
  $rgb = imagecolorsforindex($target, $color);
  $bitmap_ink = imagecolorallocate($bitmap, $rgb['red'], $rgb['green'], $rgb['blue']);
  imagestring($bitmap, 5, 0, 0, $text, $bitmap_ink);
  imagecopyresampled($target, $bitmap, $x, $y, 0, 0, $width * 4, $height * 4, $width, $height);
  imagedestroy($bitmap);
};
$image = imagecreatetruecolor(1200, 720);
$white = imagecolorallocate($image, 255, 255, 255);
$ink = imagecolorallocate($image, 27, 42, 58);
$water = imagecolorallocate($image, 221, 236, 246);
$blue = imagecolorallocate($image, 32, 92, 133);
imagefilledrectangle($image, 0, 0, 1199, 719, $white);
imagesetthickness($image, 5);
// Complete, uncropped schematic: a wetting liquid rises above the reservoir.
imagefilledrectangle($image, 200, 500, 1000, 650, $water);
imagefilledrectangle($image, 520, 256, 680, 500, $water);
for ($x = 520; $x <= 680; $x++) {
  $meniscus_y = (int) round(256 - 36 * (($x - 600) / 80) ** 2);
  imageline($image, $x, $meniscus_y, $x, 256, $water);
}
imageline($image, 200, 410, 200, 650, $ink);
imageline($image, 200, 650, 1000, 650, $ink);
imageline($image, 1000, 410, 1000, 650, $ink);
imageline($image, 200, 500, 520, 500, $blue);
imageline($image, 680, 500, 1000, 500, $blue);
imageline($image, 520, 110, 520, 550, $ink);
imageline($image, 680, 110, 680, 550, $ink);
for ($x = 520; $x < 680; $x++) {
  $y1 = (int) round(256 - 36 * (($x - 600) / 80) ** 2);
  $y2 = (int) round(256 - 36 * (($x + 1 - 600) / 80) ** 2);
  imageline($image, $x, $y1, $x + 1, $y2, $blue);
}
// Dashed datum and a rise arrow outside the tube leave the meniscus visible.
for ($x = 710; $x < 880; $x += 20) {
  imageline($image, $x, 256, min($x + 10, 880), 256, $ink);
  imageline($image, $x, 500, min($x + 10, 880), 500, $ink);
}
imageline($image, 820, 266, 820, 490, $ink);
imageline($image, 820, 266, 812, 280, $ink);
imageline($image, 820, 266, 828, 280, $ink);
imageline($image, 820, 490, 812, 476, $ink);
imageline($image, 820, 490, 828, 476, $ink);
$draw_label($image, 850, 340, 'h', $ink);
$draw_label($image, 200, 30, 'Capillary rise of water', $ink);
$draw_label($image, 715, 135, 'Glass tube', $ink);
$draw_label($image, 260, 565, 'Water', $ink);
imagepng($image, $image_path);
imagedestroy($image);

$file_system = \Drupal::service('file_system');
if (!$file_system->prepareDirectory('public://actools-preview', FileSystemInterface::CREATE_DIRECTORY)) {
  throw new RuntimeException('The disposable public diagram directory is not writable.');
}
$diagram_uri = $file_system->copy($image_path, 'public://actools-preview/capillary-rise.png', FileExists::Replace);
$diagram = File::create(['uri' => $diagram_uri, 'status' => 1]);
$diagram->save();
$diagram_field = [
  'target_id' => $diagram->id(),
  'alt' => 'Water rises in a narrow glass tube above the surrounding reservoir; h marks the rise.',
  'width' => 1200,
  'height' => 720,
];

$preview_groups = [
  [
    'heading' => 'Concepts and notes',
    'resources' => [
      ['Surface tension and surface energy', 'Relate the work needed to increase a liquid surface to its surface tension.', 'notes', 'foundation', FALSE],
      ['Contact angle and capillary rise', 'Use the contact angle to determine the direction of the surface force on a wetting liquid.', 'lesson', 'jee_advanced', TRUE],
      ['Pressure difference across a curved liquid surface', 'Choose the radius of curvature and distinguish a liquid drop from a soap bubble.', 'notes', 'jee_advanced', FALSE],
    ],
  ],
  [
    'heading' => 'Worked examples',
    'resources' => [
      ['Rise of water in a narrow tube', 'Balance the upward component of surface force against the weight of the raised liquid.', 'worked_examples', 'jee_advanced', TRUE],
      ['A liquid bridge between two parallel plates: connecting the surface force, excess pressure and contact angle in a single equilibrium calculation', 'Identify the separate contributions of pressure and surface tension before writing the force balance.', 'worked_examples', 'olympiad', FALSE],
      ['Excess pressure inside a soap bubble', 'Account for both surfaces of the thin liquid film.', 'worked_examples', 'foundation', FALSE],
    ],
  ],
  [
    'heading' => 'Practice assignments',
    'resources' => [
      ['Surface tension assignment: forces and energy', 'A graded set of questions about surface forces, surface energy and droplets.', 'assignment', 'jee_advanced', FALSE],
      ['Capillarity practice: contact angle and pressure', 'Practise choosing the correct liquid level and pressure reference.', 'assignment', 'olympiad', TRUE],
      ['Chapter review questions', 'Check the basic definitions, units and conditions used in this topic.', 'assignment', 'foundation', FALSE],
    ],
  ],
];
$preview_blocks = [];
$explanation = BlockContent::create([
  'type' => 'actools_text_image',
  'info' => 'Surface tension teaching introduction',
  'status' => 1,
  'reusable' => FALSE,
  'field_actools_heading' => 'Capillary rise in a glass tube',
  'field_actools_body' => [
    'value' => "Surface tension acts along the liquid surface. In a narrow glass tube, the upward component of this force can support a column of water above the reservoir.\n\nStart with the concepts and notes, study the worked examples, then attempt the practice assignments.",
    'format' => 'plain_text',
  ],
  'field_actools_image' => $diagram_field,
  'field_actools_caption' => 'Figure 1. Capillary rise in a narrow glass tube.',
  'field_actools_position' => 'right',
]);
$explanation->save();
$preview_blocks[] = $explanation;
$preview_resources = [];
foreach ($preview_groups as $group) {
  $references = [];
  foreach ($group['resources'] as [$title, $summary, $kind, $level, $has_image]) {
    $values = [
      'type' => 'actools_resource', 'title' => $title, 'status' => 1, 'uid' => $editor->id(),
      'field_actools_summary' => $summary,
      'field_actools_body' => ['value' => $summary, 'format' => 'plain_text'],
      'field_actools_kind' => $kind,
      'field_actools_level' => $level,
    ];
    if ($has_image) {
      $values['field_actools_image'] = $diagram_field;
    }
    $resource = Node::create($values);
    $resource->save();
    $references[] = ['target_id' => $resource->id()];
    $preview_resources[] = ['title' => $title, 'hasImage' => $has_image];
  }
  $cards = BlockContent::create([
    'type' => 'actools_resource_cards', 'info' => $group['heading'], 'status' => 1, 'reusable' => FALSE,
    'field_actools_heading' => $group['heading'], 'field_actools_resources' => $references,
  ]);
  $cards->save();
  $preview_blocks[] = $cards;
}
$preview_components = [];
foreach ($preview_blocks as $weight => $block) {
  $preview_components[] = (new SectionComponent($block->uuid(), 'content', [
    'id' => 'inline_block:' . $block->bundle(),
    'label' => $block->label(), 'label_display' => FALSE,
    'provider' => 'layout_builder', 'view_mode' => 'default',
    'block_id' => $block->id(), 'block_revision_id' => $block->getRevisionId(), 'block_serialized' => NULL,
  ]))->setWeight($weight);
}
$preview_topic = Node::create([
  'type' => 'actools_topic', 'title' => 'Surface tension', 'uid' => $editor->id(), 'status' => 1,
  'layout_builder__layout' => [new Section('actools_bs5_onecol_contained', [], $preview_components)],
]);
$preview_topic->save();

file_put_contents($fixture_path, json_encode([
  'username' => $editor->getAccountName(),
  'topicPath' => '/node/' . $topics[0],
  'sharedTopicPath' => '/node/' . $topics[1],
  'resources' => $resources,
  'imagePath' => $image_path,
  'previewTopicPath' => '/node/' . $preview_topic->id(),
  'previewHeadings' => ['Capillary rise in a glass tube', 'Concepts and notes', 'Worked examples', 'Practice assignments'],
  'previewResources' => $preview_resources,
], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
print "Created two UI topics, two editable resources, a published Surface tension preview and a non-admin layout editor.\n";
