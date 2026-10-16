<?php

/**
 * @file
 * Real navigation fixtures for a disposable, loopback-only Drupal CI site.
 */

declare(strict_types=1);

use Drupal\block\Entity\Block;
use Drupal\filter\Entity\FilterFormat;
use Drupal\menu_link_content\Entity\MenuLinkContent;
use Drupal\node\Entity\Node;
use Drupal\system\Entity\Menu;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;

if (PHP_SAPI !== 'cli' || getenv('ACTOOLS_NAV_UI_FIXTURE') !== '1') {
  throw new RuntimeException('Navigation fixtures are restricted to the disposable CI site.');
}
$fixture_path = getenv('ACTOOLS_NAV_FIXTURE');
$admin_interface = getenv('ACTOOLS_NAV_ADMIN_INTERFACE');
$base_url = rtrim((string) getenv('ACTOOLS_NAV_BASE_URL'), '/');
$base_parts = parse_url($base_url);
if (($base_parts['scheme'] ?? '') !== 'http' || !in_array($base_parts['host'] ?? '', ['127.0.0.1', 'localhost', '[::1]'], TRUE)) {
  throw new RuntimeException('Navigation fixtures require an explicit loopback HTTP base URL.');
}
if (!$fixture_path || !in_array($admin_interface, ['toolbar', 'navigation'], TRUE)) {
  throw new RuntimeException('Specify the disposable fixture path and real core admin interface.');
}
\Drupal::service('module_installer')->install(['menu_ui', 'menu_link_content', 'contextual', $admin_interface]);
\Drupal::configFactory()->getEditable('system.theme')->set('default', 'actools_bs5')->save();
\Drupal::configFactory()->getEditable('system.site')->set('name', 'Aarin Content Tools')->save();
\Drupal::configFactory()->getEditable('node.settings')->set('use_admin_theme', FALSE)->save();
\Drupal::configFactory()->getEditable('system.logging')->set('error_level', 'verbose')->save();
\Drupal::configFactory()->getEditable('actools_bs5.settings')
  ->set('container_header', 'container')->set('container_footer', 'container')
  ->set('sticky_header', TRUE)->save();

Role::load(Role::ANONYMOUS_ID)->grantPermission('access content')->save();
$role = Role::create(['id' => 'actools_navigation_editor', 'label' => 'Disposable navigation editor']);
$permissions = [
  'access content', 'access contextual links', 'access administration pages', 'access content overview',
  'edit own actools_topic content', 'view own unpublished content',
  'configure editable actools_topic node layout overrides', 'create and edit custom blocks',
  $admin_interface === 'toolbar' ? 'access toolbar' : 'access navigation',
];
$available_permissions = \Drupal::service('user.permissions')->getPermissions();
foreach ($permissions as $permission) {
  if (!isset($available_permissions[$permission])) {
    throw new RuntimeException('The installed interface did not provide fixture permission: ' . $permission);
  }
  $role->grantPermission($permission);
}
$role->save();
$editor = User::create([
  'name' => 'actools-navigation-editor', 'mail' => 'navigation-editor@example.invalid',
  'status' => 1, 'roles' => [$role->id()],
]);
$password = getenv('ACTOOLS_NAV_EDITOR_PASSWORD');
if (!$password) {
  throw new RuntimeException('The disposable editor password is required.');
}
$editor->setPassword($password);
$editor->save();
if (!FilterFormat::load('actools_navigation_fixture')) {
  // Trusted HTML entered only by this CLI fixture, never an exposed format.
  FilterFormat::create(['format' => 'actools_navigation_fixture', 'name' => 'Disposable navigation fixture', 'filters' => []])->save();
}
$body = '<p>A reading lesson with complete explanations and useful teaching resources.</p>';
$section_ids = ['concepts', 'worked-examples', 'practice-assignments', 'related-topics'];
foreach (array_combine($section_ids, ['Concepts and notes', 'Worked examples', 'Practice assignments', 'Related topics']) as $id => $heading) {
  $body .= '<h2 id="' . $id . '">' . $heading . '</h2>';
  for ($paragraph = 0; $paragraph < 10; $paragraph++) {
    $body .= '<p>Read the physical assumptions carefully. Identify the liquid surface, the contact angle and the direction of each force before choosing a pressure reference and writing the equilibrium equation. Compare the complete diagram with the explanation and check the dimensions of the final expression.</p>';
  }
}
$topic = Node::create([
  'type' => 'actools_topic', 'title' => 'Capillary rise: reading lesson', 'status' => 1, 'uid' => $editor->id(),
  'field_actools_body' => ['value' => $body, 'format' => 'actools_navigation_fixture'],
]);
$topic->save();
$resource_fixtures = [];
foreach ([
  ['Physics topics and teaching resources', 'notes', 'foundation', TRUE],
  ['Surface tension and capillarity', 'lesson', 'foundation', TRUE],
  ['Capillary rise worked examples', 'worked_examples', 'jee_advanced', TRUE],
  ['Surface tension concept notes', 'notes', 'foundation', TRUE],
  ['Capillary pressure practice assignment', 'assignment', 'olympiad', TRUE],
  ['Restricted teaching draft', 'notes', 'foundation', FALSE],
] as [$title, $kind, $level, $published]) {
  $resource = Node::create([
    'type' => 'actools_resource', 'title' => $title, 'status' => (int) $published, 'uid' => $editor->id(),
    'field_actools_kind' => $kind, 'field_actools_level' => $level,
    'field_actools_summary' => 'A concise teaching resource about capillarity and the surface force.',
    'field_actools_body' => ['value' => 'Study the explanation and work through the accompanying examples.', 'format' => 'plain_text'],
  ]);
  $resource->save();
  $resource_fixtures[$title] = ['id' => (int) $resource->id(), 'path' => '/node/' . $resource->id(), 'published' => $published];
}
if (!Menu::load('main')) {
  Menu::create(['id' => 'main', 'label' => 'Main navigation'])->save();
}
$add_link = static function (string $title, string $uri, int $weight, ?MenuLinkContent $parent = NULL, array $options = []): MenuLinkContent {
  $link = MenuLinkContent::create([
    'title' => $title, 'menu_name' => 'main', 'enabled' => TRUE, 'expanded' => TRUE,
    'weight' => $weight, 'parent' => $parent ? $parent->getPluginId() : '',
    'link' => ['uri' => $uri, 'options' => $options],
  ]);
  $link->save();
  return $link;
};
$parent_title = 'Physics topics and teaching resources';
$child_title = 'Surface tension and capillarity';
$parent = $add_link($parent_title, 'entity:node/' . $resource_fixtures[$parent_title]['id'], 0);
$child = $add_link($child_title, 'entity:node/' . $resource_fixtures[$child_title]['id'], 0, $parent);
$add_link($topic->label(), 'entity:node/' . $topic->id(), 0, $child);
$add_link('Restricted teaching draft', 'entity:node/' . $resource_fixtures['Restricted teaching draft']['id'], 1, $child);
$add_link('Resources', 'internal:/resources', 1);
$support = $add_link('Study support', 'route:<nolink>', 2);
$add_link('A practical guide to reading diagrams and checking long worked solutions', 'entity:node/' . $resource_fixtures['Surface tension concept notes']['id'], 0, $support);
$add_link('Jump to worked examples', 'entity:node/' . $resource_fixtures['Surface tension concept notes']['id'], 1, $support, [
  'query' => ['ref' => 'navigation'], 'fragment' => 'worked-examples',
  'attributes' => ['data-publisher-link' => 'preserved', 'title' => 'Read the worked examples', 'rel' => ['help'], 'class' => ['fixture-native-url']],
]);
// A same-origin absolute URL keeps this anchor out of canonical node route
// matching. Core's generation-only <current> route is intentionally not allowed
// by native menu-tree access; do not override that access behavior for a test.
$add_link('Continue to practice assignments', $base_url . '/node/' . $topic->id(), 2, $support, ['fragment' => 'practice-assignments']);

foreach (Block::loadMultiple() as $block) {
  if ($block->getTheme() === 'actools_bs5') {
    $block->delete();
  }
}
$placements = [
  ['branding', 'header', 'system_branding_block', -20, ['use_site_logo' => FALSE, 'use_site_name' => TRUE, 'use_site_slogan' => FALSE]],
  ['header_menu', 'header', 'system_menu_block:main', 0, ['label' => 'Primary navigation', 'level' => 1, 'depth' => 0, 'expand_all_items' => TRUE]],
  ['messages', 'content', 'system_messages_block', -30, []],
  ['tasks', 'content', 'local_tasks_block', -20, []],
  ['title', 'content', 'page_title_block', -10, []],
  ['content', 'content', 'system_main_block', 0, []],
  ['footer_menu', 'footer', 'system_menu_block:main', 0, ['label' => 'Footer topic navigation', 'level' => 1, 'depth' => 0, 'expand_all_items' => TRUE]],
];
foreach ($placements as [$id, $region, $plugin, $weight, $settings]) {
  Block::create([
    'id' => 'actools_nav_' . $id, 'theme' => 'actools_bs5', 'region' => $region,
    'weight' => $weight, 'plugin' => $plugin, 'settings' => $settings + ['label_display' => FALSE],
  ])->save();
}
\Drupal::configFactory()->getEditable('system.site')->set('page.front', '/node/' . $topic->id())->save();
$frame_uri = 'public://actools-navigation-frame.html';
$frame_html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Disposable preview-frame compatibility fixture</title></head><body><iframe title="Themed page preview" data-canvas-preview src="/node/' . $topic->id() . '" style="width:100%;height:1000px;border:0"></iframe></body></html>';
$frame_file = \Drupal::service('file.repository')->writeData($frame_html, $frame_uri, \Drupal\Core\File\FileExists::Replace);
$frame_file->setPermanent();
$frame_file->save();
file_put_contents($fixture_path, json_encode([
  'username' => $editor->getAccountName(), 'adminInterface' => $admin_interface,
  'currentTopicPath' => '/node/' . $topic->id(), 'currentTopicTitle' => $topic->label(),
  'parentTitle' => $parent_title, 'childTitle' => $child_title,
  'parentMenuLinkId' => (int) $parent->id(),
  'renamedParentTitle' => 'Physics topics and learning materials',
  'parentPath' => $resource_fixtures[$parent_title]['path'],
  'childPath' => $resource_fixtures[$child_title]['path'],
  'sectionIds' => $section_ids, 'resources' => $resource_fixtures,
  'resourceFinderPath' => '/resources', 'unpublishedTitle' => 'Restricted teaching draft',
  'previewFramePath' => \Drupal::service('file_url_generator')->generateString($frame_uri),
], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
print 'Created native header/footer menus, a long reading Topic, real Resource fixtures and core ' . $admin_interface . ".\n";
