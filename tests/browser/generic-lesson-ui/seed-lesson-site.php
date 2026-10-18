<?php

/** Disposable native Drupal fixtures for the Generic Lesson theme integration. */
declare(strict_types=1);

use Drupal\block\Entity\Block;
use Drupal\filter\Entity\FilterFormat;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\menu_link_content\Entity\MenuLinkContent;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\system\Entity\Menu;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;

if (PHP_SAPI !== 'cli' || getenv('ACTOOLS_GL_THEME_FIXTURE') !== '1') {
  throw new RuntimeException('Only the explicitly isolated theme integration site may run this fixture.');
}
$output = getenv('ACTOOLS_GL_FIXTURE');
$base_url = rtrim((string) getenv('ACTOOLS_GL_BASE_URL'), '/');
$url = parse_url($base_url);
if (!$output || ($url['scheme'] ?? '') !== 'http' || !in_array($url['host'] ?? '', ['127.0.0.1', 'localhost', '[::1]'], TRUE)) {
  throw new RuntimeException('Provide a fixture JSON path and explicit loopback HTTP base URL.');
}
$credentials = ['learnerPassword' => getenv('ACTOOLS_GL_EDITOR_PASSWORD'), 'adminPassword' => getenv('ACTOOLS_GL_ADMIN_PASSWORD')];
if (!$credentials['learnerPassword'] || !$credentials['adminPassword']) {
  throw new RuntimeException('Provide disposable learner and administrator passwords in the environment.');
}
if (!\Drupal::moduleHandler()->moduleExists('generic_lesson')) {
  throw new RuntimeException('Install Generic Lesson 2.1.2 before seeding this test site.');
}
\Drupal::service('theme_installer')->install(['actools_bs5']);
if (\Drupal::state()->get('actools_generic_lesson_theme_fixture')) {
  throw new RuntimeException('Fixture already installed; restore the isolated database before rerunning.');
}
\Drupal::service('module_installer')->install(['menu_ui', 'menu_link_content', 'contextual', 'toolbar']);
\Drupal::configFactory()->getEditable('system.theme')->set('default', 'actools_bs5')->save();
\Drupal::configFactory()->getEditable('system.site')->set('name', 'Aarin Learning')->set('slogan', 'Understand. Practise. Progress.')->save();
\Drupal::configFactory()->getEditable('node.settings')->set('use_admin_theme', FALSE)->save();
\Drupal::configFactory()->getEditable('actools_bs5.settings')
  ->set('container_header', 'container')->set('container_footer', 'container')
  ->set('sticky_header', TRUE)->set('tokens_brand_color', '#0d6efd')->set('tokens_base_font_size', '18')->save();
\Drupal::configFactory()->getEditable('generic_lesson.settings')
  ->set('enable_progress', TRUE)->set('require_quiz_for_completion', TRUE)
  ->set('reveal_answer', TRUE)->set('anonymous_checking', FALSE)
  // Use the locally installed, pinned MathJax bundle; never contact a remote CDN.
  ->set('mathjax_source', 'local')->set('mathjax_local_path', '/libraries/mathjax/es5/tex-chtml.js')->save();
Role::load(Role::ANONYMOUS_ID)->grantPermission('access content')->save();
foreach (['gl_theme_learner' => FALSE, 'gl_theme_admin' => TRUE] as $id => $is_admin) {
  $role = Role::create(['id' => $id, 'label' => $is_admin ? 'Theme fixture administrator' : 'Theme fixture learner', 'is_admin' => $is_admin]);
  $role->grantPermission('access content');
  if ($is_admin) $role->grantPermission('access toolbar');
  $role->save();
}
$users = [];
foreach (['learner', 'admin'] as $kind) {
  $u = User::create(['name' => 'gl-theme-' . $kind, 'mail' => 'gl-theme-' . $kind . '@example.invalid', 'status' => 1, 'roles' => ['gl_theme_' . $kind]]);
  $u->setPassword($credentials[$kind . 'Password']); $u->save(); $users[$kind] = $u;
}
$format = 'gl_theme_fixture';
FilterFormat::create(['format' => $format, 'name' => 'Trusted CLI-only theme fixture HTML', 'filters' => []])->save();
$rich = static fn(string $text): array => ['value' => $text, 'format' => $format];
$p = static function (string $type, array $fields): Paragraph {
  $paragraph = Paragraph::create(['type' => $type, 'status' => 1] + $fields); $paragraph->save(); return $paragraph;
};
$text = static fn(string $html): Paragraph => $p('gl_text', ['field_gl_text' => $rich($html)]);
$file_repository = \Drupal::service('file.repository');
$files = [];
foreach (['wide', 'small'] as $size) {
  $f = $file_repository->writeData(file_get_contents(__DIR__ . '/assets/fixture-' . $size . '.png'), 'public://theme-fixture-' . $size . '.png', \Drupal\Core\File\FileExists::Replace);
  $f->setPermanent(); $f->save(); $files[$size] = $f;
}
$figure = static function (string $size) use ($p, $files): Paragraph {
  $wide = $size === 'wide';
  return $p('gl_image', [
    'field_gl_image' => ['target_id' => $files[$size]->id(), 'alt' => $wide ? 'Comparison of a liquid drop and a soap bubble showing their curved surfaces.' : 'Surface tension force tangent to a liquid surface.', 'width' => $wide ? 1800 : 360, 'height' => $wide ? 600 : 240],
    'field_gl_caption' => $wide ? 'Figure 1. Compare the single liquid–air interface of a drop with the two interfaces of a soap bubble. The same surface tension acts at each interface.' : 'Figure 2. Surface tension acts along the tangent to the interface.',
  ]);
};
$intro = '<p class="fixture-intro">Build a clear physical picture of a curved liquid surface before using the pressure equation. This lesson connects the concepts with worked examples and independent practice.</p>'
  . '<p><a href="#capillary-pressure">Jump to capillary pressure</a> or compare the <a href="#comparison-table">comparison table</a>. Each step can be read at your own pace.</p>';
$long = '';
foreach (range(1, 5) as $index) $long .= '<p>Choose a small part of the interface and identify the pressure on each side. Surface tension acts tangentially at the boundary of that part. The normal components of the forces balance the pressure force in equilibrium. Keep the radius of curvature and the number of interfaces separate when writing the equation.</p>';
$table = '<h3 id="comparison-table" tabindex="-1">Comparing curved surfaces</h3><table><caption>Pressure difference for common interfaces</caption><thead><tr><th scope="col">System</th><th scope="col">Number of interfaces</th><th scope="col">Radius</th><th scope="col">Surface tension</th><th scope="col">Pressure difference</th><th scope="col">Physical assumption</th></tr></thead><tbody>'
  . '<tr><th scope="row">Liquid drop</th><td>1</td><td>r</td><td>T</td><td>2T/r</td><td>Uniform spherical interface in mechanical equilibrium</td></tr>'
  . '<tr><th scope="row">Soap bubble</th><td>2</td><td>r</td><td>T</td><td>4T/r</td><td>A thin film whose inner and outer radii are approximately equal</td></tr>'
  . '<tr><th scope="row">Air bubble in a liquid</th><td>1</td><td>r</td><td>T</td><td>2T/r</td><td>A single gas–liquid interface; hydrostatic pressure is treated separately</td></tr></tbody></table>';
$section = $p('gl_section', ['field_gl_layout' => 'two_column', 'field_gl_section_content' => [
  $p('gl_callout', ['field_gl_callout_type' => 'tip', 'field_gl_text' => $rich('<p><strong>First count the interfaces.</strong> A drop and a soap bubble are different physical systems even when they have the same radius.</p>')]),
  $figure('small'),
]]);
$step1 = $p('gl_step', ['field_gl_step_title' => 'Concepts and physical picture', 'field_gl_step_content' => [
  $text($intro), $figure('wide'), $section, $text($long . '<h3 id="capillary-pressure" tabindex="-1">Capillary pressure</h3><p>The pressure inside a spherical liquid drop exceeds the pressure outside it. For one interface, \\(\\Delta p = 2T/r\\). For a thin soap film, the two interfaces give \\(\\Delta p = 4T/r\\).</p>'),
  $p('gl_equation', ['field_gl_tex' => '\\Delta p = T\\left(\\frac{1}{R_1}+\\frac{1}{R_2}\\right)', 'field_gl_equation_label' => 'Young–Laplace relation']),
  $text($table),
  $p('gl_takeaway', ['field_gl_text' => $rich('<p>Smaller radius means a larger pressure difference when surface tension and the number of interfaces are unchanged.</p>')]),
]]);
$example = $p('gl_example', [
  'field_gl_example_problem' => $rich('<p>A spherical water drop has radius 2 mm. Its surface tension is 0.072 N m<sup>−1</sup>. Find the excess pressure inside the drop.</p>'),
  'field_gl_example_hint' => $rich('<p>Count one liquid–air interface and convert the radius to metres.</p>'),
  'field_gl_example_answer' => $rich('<p>The excess pressure is <strong>72 Pa</strong>.</p>'),
  'field_gl_example_solution' => $rich('<p>The pressure difference is \\(2T/r\\). Substitution gives \\(2(0.072)/(0.002)=72\\,\\mathrm{Pa}\\). This is a pressure difference; it is added to the outside pressure to obtain the absolute pressure inside.</p>'),
]);
$practice = $p('gl_practice', [
  'field_gl_example_problem' => $rich('<p>A soap bubble has radius 5 mm and surface tension 0.030 N m<sup>−1</sup>. Calculate the excess pressure inside it.</p>'),
  'field_gl_example_hint' => $rich('<p>A thin soap film has two interfaces. Use the radius in metres.</p>'),
  'field_gl_example_answer' => $rich('<p><strong>24 Pa.</strong></p>'),
  'field_gl_example_solution' => $rich('<p>For a thin soap bubble, \\(\\Delta p=4T/r=4(0.030)/(0.005)=24\\,\\mathrm{Pa}\\).</p>'),
]);
$step2 = $p('gl_step', ['field_gl_step_title' => 'Worked example and practice', 'field_gl_step_content' => [
  $text('<p>Read the worked example, then solve the practice problem before opening its hint or answer.</p>'), $example, $practice,
  $p('gl_accordion', ['field_gl_accordion_title' => 'Why does the factor of two change?', 'field_gl_text' => $rich('<p>A thin soap film has two interfaces. Each contributes a surface-tension force to the balance.</p>')]),
]]);
$quiz = static function (string $type, string $stem, string $key, string $explanation) use ($p, $rich): Paragraph {
  return $p('gl_quiz', ['field_gl_quiz_type' => $type, 'field_gl_quiz_question' => $rich($stem), 'field_gl_quiz_options' => $key, 'field_gl_quiz_explanation' => $rich('<p>' . $explanation . '</p>')]);
};
$single = $quiz('single', '<p>Which object has two liquid–air interfaces?</p>', "*A thin soap bubble\nA liquid drop\nAn air bubble within water\nA plane liquid surface", 'PRIVATE_SINGLE_SOLUTION: A thin soap film separates air on both sides and has two interfaces.');
$numerical = $quiz('numerical', '<p>The surface tension of a liquid is 0.030 N m<sup>−1</sup>. Find the excess pressure, in Pa, in a drop of radius 5 mm.</p>', '12', 'PRIVATE_NUMERICAL_SOLUTION: The pressure difference is 2T/r = 12 Pa.');
$matrix = $quiz('matrix', '<p>Match each system in List-I with every applicable property in List-II.</p>', "A. A thin soap bubble\nB. A liquid drop\nP. Two interfaces\nQ. Excess pressure 4T/r\nR. One interface\n*A-PQ, B-R", 'PRIVATE_MATRIX_SOLUTION: A soap bubble has two interfaces and pressure 4T/r; a drop has one interface.');
$multiple = $quiz('multiple', '<p>For a spherical liquid drop in mechanical equilibrium, select every correct statement.</p>', "*Excess pressure increases when its radius decreases\n*Excess pressure is proportional to surface tension\nExcess pressure is independent of the radius\nThere are always two interfaces", 'PRIVATE_MULTIPLE_SOLUTION: Excess pressure is 2T/r.');
$integer = $quiz('integer', '<p>How many interfaces does a thin soap bubble have?</p>', '2', 'PRIVATE_INTEGER_SOLUTION: The soap film has an inner and an outer interface.');
$assertion = $quiz('assertion', '<p><strong>Assertion:</strong> A smaller drop has a larger excess pressure for the same liquid.<br><strong>Reason:</strong> Excess pressure is inversely proportional to its radius.</p>', '*1', 'PRIVATE_ASSERTION_SOLUTION: Both statements are true and the reason explains the assertion.');
$part1 = $p('gl_quiz_part', ['field_gl_part_type' => 'integer', 'field_gl_quiz_question' => $rich('<p>A drop is divided into eight identical drops without loss of liquid. By what factor does excess pressure increase?</p>'), 'field_gl_quiz_options' => '2', 'field_gl_quiz_explanation' => $rich('<p>PRIVATE_COMPREHENSION_SOLUTION: Each new radius is half the original radius, so the pressure difference doubles.</p>')]);
$part2 = $p('gl_quiz_part', ['field_gl_part_type' => 'single', 'field_gl_quiz_question' => $rich('<p>Which quantity increases during this division?</p>'), 'field_gl_quiz_options' => "*Total surface area\nTotal liquid volume\nDensity of the liquid\nMass of the liquid", 'field_gl_quiz_explanation' => $rich('<p>PRIVATE_COMPREHENSION_AREA: The combined surface area doubles.</p>')]);
$comprehension = $p('gl_quiz', ['field_gl_quiz_type' => 'paragraph', 'field_gl_quiz_question' => $rich('<p>A spherical drop breaks into eight identical spherical drops. The liquid is incompressible and its surface tension remains constant. Answer both questions.</p>'), 'field_gl_quiz_parts' => [$part1, $part2]]);
$step3 = $p('gl_step', ['field_gl_step_title' => 'Check your understanding', 'field_gl_step_content' => [$text('<p>Answer the questions independently. Your attempts and reading progress are saved when you are signed in.</p>'), $single, $numerical, $matrix, $multiple, $integer, $assertion, $comprehension]]);
$lesson = Node::create(['type' => 'gl_lesson', 'title' => 'Surface tension: from concepts to confident practice', 'status' => 1, 'uid' => $users['admin']->id(), 'field_gl_content' => [$step1, $step2, $step3], 'field_gl_navigation_mode' => 'free', 'field_gl_reading_mode' => 'guided', 'field_gl_summary' => $rich('<p>Understand curved interfaces, solve pressure examples and check your reasoning with focused practice.</p>')]);
$lesson->save();
$flat1 = $p('gl_step', ['field_gl_step_title' => 'Read the physical picture', 'field_gl_step_content' => [$text($intro . $long), $figure('wide')]]);
$flat2 = $p('gl_step', ['field_gl_step_title' => 'Compare the systems', 'field_gl_step_content' => [$text('<h3 id="capillary-pressure" tabindex="-1">Capillary pressure</h3>' . $table . $long), $figure('small')]]);
$flat = Node::create(['type' => 'gl_lesson', 'title' => 'Continuous reading: curved liquid surfaces', 'status' => 1, 'uid' => $users['admin']->id(), 'field_gl_content' => [$flat1, $flat2], 'field_gl_navigation_mode' => 'free', 'field_gl_reading_mode' => 'continuous']); $flat->save();
if (!NodeType::load('gl_theme_control')) NodeType::create(['type' => 'gl_theme_control', 'name' => 'Theme control page'])->save();
if (!FieldStorageConfig::loadByName('node', 'body')) FieldStorageConfig::create(['entity_type' => 'node', 'field_name' => 'body', 'type' => 'text_with_summary'])->save();
if (!FieldConfig::loadByName('node', 'gl_theme_control', 'body')) FieldConfig::create(['entity_type' => 'node', 'field_name' => 'body', 'bundle' => 'gl_theme_control', 'label' => 'Body'])->save();
\Drupal::service('entity_display.repository')->getViewDisplay('node', 'gl_theme_control')->setComponent('body', ['label' => 'hidden', 'type' => 'text_default'])->save();
$ordinary = Node::create(['type' => 'gl_theme_control', 'title' => 'Study resources and learning support', 'status' => 1, 'uid' => $users['admin']->id(), 'body' => $rich('<p>A normal content page uses the site theme without lesson-specific controls.</p><h2>Learning resources</h2><p>Choose a lesson, work through the examples and return to the practice problems when you are ready.</p><p><a href="/node/' . $lesson->id() . '">Open the surface tension lesson</a>.</p>')]); $ordinary->save();
// A separate configured lesson bundle exercises real Layout Builder while the
// main fixture retains the normal Paragraphs field display.
\Drupal::service('module_installer')->install(['layout_builder']);
NodeType::create(['type' => 'gl_theme_layout', 'name' => 'Theme Layout Builder lesson'])->save();
foreach (FieldConfig::loadMultiple() as $field) {
  if ($field->getTargetEntityTypeId() !== 'node' || $field->getTargetBundle() !== 'gl_lesson') continue;
  $raw = $field->toArray(); unset($raw['uuid'], $raw['id']);
  $raw['bundle'] = 'gl_theme_layout';
  unset($raw['dependencies']);
  FieldConfig::create($raw)->save();
}
\Drupal::configFactory()->getEditable('generic_lesson.settings')->set('lesson_bundles', ['gl_lesson', 'gl_theme_layout'])->save();
\Drupal::service('entity_field.manager')->clearCachedFieldDefinitions();
$layout_display = EntityViewDisplay::create(['targetEntityType' => 'node', 'bundle' => 'gl_theme_layout', 'mode' => 'default', 'status' => TRUE]);
foreach (EntityViewDisplay::load('node.gl_lesson.default')->getComponents() as $name => $component) $layout_display->setComponent($name, $component);
$layout_display->enableLayoutBuilder()->setOverridable()->save();
$layout_step = $p('gl_step', ['field_gl_step_title' => 'A Layout Builder lesson step', 'field_gl_step_content' => [
  $text('<p>This lesson uses a real Layout Builder override with the Generic Lesson content field, outline, progress and controls. Its Paragraphs remain the structured lesson content.</p>'),
  $figure('small'), $p('gl_callout', ['field_gl_callout_type' => 'info', 'field_gl_text' => $rich('<p>Layout Builder controls placement; Generic Lesson controls the reading and question behaviour.</p>')]),
]]);
$layout_node = Node::create(['type' => 'gl_theme_layout', 'title' => 'Surface tension through Layout Builder', 'status' => 1, 'uid' => $users['admin']->id(), 'field_gl_content' => [$layout_step], 'field_gl_navigation_mode' => 'free', 'field_gl_reading_mode' => 'guided']);
foreach ($layout_display->getSections() as $section) $layout_node->get('layout_builder__layout')->appendItem(['section' => clone $section]);
$layout_node->save();
if (!Menu::load('main')) Menu::create(['id' => 'main', 'label' => 'Main navigation'])->save();
foreach ([['Lessons', $lesson], ['Reading notes', $flat], ['Study support', $ordinary]] as $weight => [$label, $node]) MenuLinkContent::create(['title' => $label, 'menu_name' => 'main', 'enabled' => TRUE, 'weight' => $weight, 'link' => ['uri' => 'entity:node/' . $node->id()]])->save();
foreach (Block::loadMultiple() as $block) if ($block->getTheme() === 'actools_bs5') $block->delete();
foreach ([
  ['branding', 'header', 'system_branding_block', -20, ['use_site_logo' => FALSE, 'use_site_name' => TRUE, 'use_site_slogan' => FALSE]],
  ['menu', 'header', 'system_menu_block:main', 0, ['label' => 'Primary navigation', 'level' => 1, 'depth' => 0, 'expand_all_items' => TRUE]],
  ['messages', 'content', 'system_messages_block', -30, []],
  ['tasks', 'content', 'local_tasks_block', -20, []],
  ['title', 'content', 'page_title_block', -10, []],
  ['content', 'content', 'system_main_block', 0, []],
  ['footer', 'footer', 'system_menu_block:main', 0, ['label' => 'Learning resources', 'level' => 1, 'depth' => 0]],
] as [$id, $region, $plugin, $weight, $settings]) Block::create(['id' => 'gl_theme_' . $id, 'theme' => 'actools_bs5', 'region' => $region, 'weight' => $weight, 'plugin' => $plugin, 'settings' => $settings + ['label_display' => FALSE]])->save();
\Drupal::configFactory()->getEditable('system.site')->set('page.front', '/node/' . $lesson->id())->save();
\Drupal::state()->set('actools_generic_lesson_theme_fixture', TRUE);
$fixture = [
  'baseUrl' => $base_url, 'username' => $users['learner']->getAccountName(), 'adminUsername' => $users['admin']->getAccountName(),
  'lessonPath' => '/node/' . $lesson->id(), 'flatLessonPath' => '/node/' . $flat->id(), 'ordinaryPath' => '/node/' . $ordinary->id(), 'layoutLessonPath' => '/node/' . $layout_node->id(),
  'lessonId' => (int) $lesson->id(), 'stepIds' => [(int) $step1->id(), (int) $step2->id(), (int) $step3->id()],
  'targetFragment' => 'capillary-pressure', 'privateMarker' => 'PRIVATE_', 'quizStepIndex' => 2,
  'tableCaption' => 'Pressure difference for common interfaces',
  'workedSolution' => 'This is a pressure difference; it is added to the outside pressure to obtain the absolute pressure inside.',
  'practiceSolution' => 'For a thin soap bubble',
  'secretSolution' => 'PRIVATE_NUMERICAL_SOLUTION',
  'quiz' => [
    'single' => ['id' => (int) $single->id(), 'correct' => ['0']],
    'numerical' => ['id' => (int) $numerical->id(), 'correct' => '12'],
    'matrix' => ['id' => (int) $matrix->id(), 'correct' => ['A' => ['P', 'Q'], 'B' => ['R']]],
    'multiple' => ['id' => (int) $multiple->id(), 'correct' => ['0', '1']],
    'integer' => ['id' => (int) $integer->id(), 'correct' => '2'],
    'assertion' => ['id' => (int) $assertion->id(), 'correct' => ['0']],
    'comprehension' => ['id' => (int) $comprehension->id(), 'parts' => [
      ['id' => (int) $part1->id(), 'type' => 'integer', 'correct' => '2'],
      ['id' => (int) $part2->id(), 'type' => 'single', 'correct' => ['0']],
    ]],
  ],
];
file_put_contents($output, json_encode($fixture, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
print "Native theme integration fixture written to $output\n";
