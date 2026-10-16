<?php

/**
 * @file
 * Fixtures for an isolated CI site, invoked after site install and recipe apply.
 *
 * This creates content and an editor only. It deliberately does not create
 * content blocks or layout overrides: the browser must do that through the UI.
 */

declare(strict_types=1);

use Drupal\block\Entity\Block;
use Drupal\filter\Entity\FilterFormat;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
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
  ]);
  $resource->save();
  $resources[] = ['id' => (int) $resource->id(), 'title' => $title];
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
$image = imagecreatetruecolor(1600, 900);
$white = imagecolorallocate($image, 255, 255, 255);
$blue = imagecolorallocate($image, 20, 70, 120);
imagefilledrectangle($image, 0, 0, 1599, 899, $white);
imagerectangle($image, 160, 100, 1440, 800, $blue);
imageline($image, 160, 300, 1440, 600, $blue);
imagepng($image, $image_path);
imagedestroy($image);

file_put_contents($fixture_path, json_encode([
  'username' => $editor->getAccountName(),
  'topicPath' => '/node/' . $topics[0],
  'sharedTopicPath' => '/node/' . $topics[1],
  'resources' => $resources,
  'imagePath' => $image_path,
], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
print "Created two published topics, two resources and a non-admin layout editor.\n";
