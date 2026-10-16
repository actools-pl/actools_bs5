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
$diagram_directory = 'public://actools-preview';
if (!$file_system->prepareDirectory($diagram_directory, FileSystemInterface::CREATE_DIRECTORY)) {
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

// A naturally narrow diagram catches captions accidentally spanning the full
// half-page column. The resource cards retain the full original above.
$narrow_source = imagecreatefrompng($image_path);
$narrow_image = imagescale($narrow_source, 300, 180);
$narrow_path = dirname($fixture_path) . '/meniscus-diagram-small.png';
imagepng($narrow_image, $narrow_path);
imagedestroy($narrow_source);
imagedestroy($narrow_image);
$narrow_uri = $file_system->copy($narrow_path, 'public://actools-preview/capillary-rise-small.png', FileExists::Replace);
$narrow_diagram = File::create(['uri' => $narrow_uri, 'status' => 1]);
$narrow_diagram->save();
$narrow_diagram_field = $diagram_field;
$narrow_diagram_field['target_id'] = $narrow_diagram->id();
$narrow_diagram_field['width'] = 300;
$narrow_diagram_field['height'] = 180;

// These are actual public teaching-download fixtures, rather than links to a
// missing file. Predictable binary lengths make the native, rounded size labels
// independently checkable in the browser. No ZIP extension is needed to seed
// the minimal standards-compliant OOXML document below.
$pdf_stream = "BT /F1 16 Tf 50 750 Td (Surface tension practice assignment) Tj ET";
$pdf_objects = [
  '<< /Type /Catalog /Pages 2 0 R >>',
  '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
  '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>',
  '<< /Length ' . strlen($pdf_stream) . ">>\nstream\n" . $pdf_stream . "\nendstream",
  '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
];
$pdf_bytes = "%PDF-1.4\n";
$pdf_offsets = [0];
foreach ($pdf_objects as $index => $object) {
  $pdf_offsets[] = strlen($pdf_bytes);
  $pdf_bytes .= ($index + 1) . " 0 obj\n" . $object . "\nendobj\n";
}
$pdf_xref = strlen($pdf_bytes);
$pdf_bytes .= "xref\n0 6\n0000000000 65535 f \n";
foreach (array_slice($pdf_offsets, 1) as $offset) {
  $pdf_bytes .= sprintf("%010d 00000 n \n", $offset);
}
$pdf_bytes .= "trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n" . $pdf_xref . "\n%%EOF\n";
if (strlen($pdf_bytes) > 1024) {
  throw new RuntimeException('Keep the public PDF fixture below its predictable 1 KB length.');
}
$pdf_bytes = str_pad($pdf_bytes, 1024, "\n");
$docx_bytes = base64_decode(<<<'DOCX'
UEsDBBQAAAAIAAAAUF15bjPX6AAAAK0BAAATAAAAW0NvbnRlbnRfVHlwZXNdLnhtbH1QyU7DMBD9FWuuKHHggBCK0wPLETiUDxjZk8SqN3nc0v49Tlt6QIXjzFv1
+tXeO7GjzDYGBbdtB4KCjsaGScHn+rV5AMEFg0EXAyk4EMNq6NeHRCyqNrCCuZT0KCXrmTxyGxOFiowxeyz1zJNMqDc4kbzrunupYygUSlMWDxj6Zxpx64p42df3
qUcmxyCeTsQlSwGm5KzGUnG5C+ZXSnNOaKvyyOHZJr6pBJBXExbk74Cz7r0Ok60h8YG5vKGvLPkVs5Em6q2vyvZ/mys94zhaTRf94pZy1MRcF/euvSAebfjpL49
zD99QSwMEFAAAAAgAAABQXZv9N+qtAAAAKQEAAAsAAABfcmVscy8ucmVsc43POw7CMAwG4KtE3mlaBoRQ0y4IqSsqB7ASN61oHkrCo7cnAwNFDIy2f3+W6/ZpZnan
ECdnBVRFCYysdGqyWsClP232wGJCq3B2lgQsFKFt6jPNmPJKHCcfWTZsFDCm5A+cRzmSwVg4TzZPBhcMplwGzT3KK2ri27Lc8fBpwNpknRIQOlUB6xdP/9huGCZJ
Rydvhmz6ceIrkWUMmpKAhwuKq3e7yCzwpuarF5sXUEsDBBQAAAAIAAAAUF0yD6L0xAAAAAwBAAARAAAAd29yZC9kb2N1bWVudC54bWxFjzFuwzAMRa8iaK/ldggK
w3aGAJ07tAegJSYRKokCqcT27Su5Q5dHkMR/BMfzFoN6IounNOnXrtcKkyXn023S318fL+9aSYHkIFDCSe8o+jyP6+DIPiKmoqogybBO+l5KHowRe8cI0lHGVHdX
4giltnwzK7HLTBZFqj8G89b3JxPBJ92UC7m91dzADWW+QPYhAPuyqxr/Qadwg5gDyqCcl0wCS0C1MK2CrK5+Kw/GbjQt3cgHD6egLZ9sjsHfMfP/yPwLUEsBAhQD
FAAAAAgAAABQXXluM9foAAAArQEAABMAAAAAAAAAAAAAAIABAAAAAFtDb250ZW50X1R5cGVzXS54bWxQSwECFAMUAAAACAAAAFBdm/036q0AAAApAQAACwAAAAAAAAAAAAAA
gAEZAQAAX3JlbHMvLnJlbHNQSwECFAMUAAAACAAAAFBdMg+i9MQAAAAMAQAAEQAAAAAAAAAAAAAAgAHvAQAAd29yZC9kb2N1bWVudC54bWxQSwUGAAAAAAMAAwC5AAAA4gIA
AAAA
DOCX, TRUE);
if ($docx_bytes === FALSE || strlen($docx_bytes) >= 2048 || substr($docx_bytes, -2) !== "\0\0") {
  throw new RuntimeException('The browser DOCX fixture must end with an empty ZIP comment.');
}
// A ZIP end-record comment is legal archive metadata and pads this to 2 KB.
$zip_comment = str_repeat(' ', 2048 - strlen($docx_bytes));
$docx_bytes = substr($docx_bytes, 0, -2) . pack('v', strlen($zip_comment)) . $zip_comment;
$preview_downloads = [];
foreach ([
  ['Surface tension assignment: forces and energy', 'Surface tension practice assignment', 'PDF', 'application/pdf', '1 KB', $pdf_bytes],
  ['Rise of water in a narrow tube', 'Capillary rise worked examples', 'DOCX', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', '2 KB', $docx_bytes],
] as $index => [$resource_title, $description, $type, $mime, $size_label, $bytes]) {
  $download = \Drupal::service('file.repository')->writeData($bytes, 'public://actools-preview/download-' . ($index + 1) . '.' . strtolower($type), FileExists::Replace);
  $download->setPermanent();
  $download->save();
  $preview_downloads[$resource_title] = [
    'target_id' => $download->id(), 'display' => 1, 'description' => $description,
    'type' => $type, 'mime' => $mime, 'size' => strlen($bytes), 'sizeLabel' => $size_label,
    'path' => \Drupal::service('file_url_generator')->generateString($download->getFileUri()),
  ];
}

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
  'field_actools_image' => $narrow_diagram_field,
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
      'field_actools_body' => [
        'value' => isset($preview_downloads[$title])
          ? 'Download the accompanying file to work through the questions and compare your reasoning with the explanations.'
          : 'The explanation and accompanying problems develop the topic step by step. Use the examples to identify the assumptions before attempting the exercises.',
        'format' => 'plain_text',
      ],
      'field_actools_kind' => $kind,
      'field_actools_level' => $level,
    ];
    if ($has_image) {
      $values['field_actools_image'] = $diagram_field;
    }
    if (isset($preview_downloads[$title])) {
      $download = $preview_downloads[$title];
      $values['field_actools_download'] = array_intersect_key($download, array_flip(['target_id', 'display', 'description']));
    }
    $resource = Node::create($values);
    $resource->save();
    $references[] = ['target_id' => $resource->id()];
    $preview_resources[] = [
      'title' => $title, 'hasImage' => $has_image, 'path' => '/node/' . $resource->id(),
      'download' => $preview_downloads[$title] ?? NULL,
    ];
  }
  $cards = BlockContent::create([
    'type' => 'actools_resource_cards', 'info' => $group['heading'], 'status' => 1, 'reusable' => FALSE,
    'field_actools_heading' => $group['heading'], 'field_actools_resources' => $references,
  ]);
  $cards->save();
  $preview_blocks[] = $cards;
}
$preview_components = [(new SectionComponent(\Drupal::service('uuid')->generate(), 'content', [
  'id' => 'field_block:node:actools_topic:field_actools_body',
  'label' => 'Introduction', 'label_display' => FALSE,
  'provider' => 'layout_builder',
  'context_mapping' => ['entity' => 'layout_builder.entity'],
  'formatter' => [
    'type' => 'text_default', 'label' => 'hidden',
    'settings' => [], 'third_party_settings' => [],
  ],
]))->setWeight(0)];
foreach ($preview_blocks as $weight => $block) {
  $preview_components[] = (new SectionComponent($block->uuid(), 'content', [
    'id' => 'inline_block:' . $block->bundle(),
    'label' => $block->label(), 'label_display' => FALSE,
    'provider' => 'layout_builder', 'view_mode' => 'default',
    'block_id' => $block->id(), 'block_revision_id' => $block->getRevisionId(), 'block_serialized' => NULL,
  ]))->setWeight($weight + 1);
}
$preview_introduction = 'Surface tension links the force along a liquid surface with the work needed to increase its area. This topic connects that idea to droplets, pressure differences and capillary rise.';
$preview_topic = Node::create([
  'type' => 'actools_topic', 'title' => 'Surface tension', 'uid' => $editor->id(), 'status' => 1,
  'field_actools_body' => ['value' => $preview_introduction, 'format' => 'plain_text'],
  'layout_builder__layout' => [new Section('actools_bs5_onecol_contained', [], $preview_components)],
]);
$preview_topic->save();
$preview_violations = $preview_topic->validate();
if (count($preview_violations) !== 0) {
  throw new RuntimeException('The native Topic preview failed validation: ' . (string) $preview_violations);
}

file_put_contents($fixture_path, json_encode([
  'username' => $editor->getAccountName(),
  'topicPath' => '/node/' . $topics[0],
  'sharedTopicPath' => '/node/' . $topics[1],
  'resources' => $resources,
  'imagePath' => $image_path,
  'previewTopicPath' => '/node/' . $preview_topic->id(),
  'previewIntroduction' => $preview_introduction,
  'previewHeadings' => ['Capillary rise in a glass tube', 'Concepts and notes', 'Worked examples', 'Practice assignments'],
  'previewResources' => $preview_resources,
], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
print "Created two UI topics, two editable resources, a published Surface tension preview and a non-admin layout editor.\n";
