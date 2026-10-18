<?php

/**
 * @file
 * Presentation scenarios for the disposable Generic Lesson browser fixture.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli' || getenv('ACTOOLS_GL_THEME_FIXTURE') !== '1'
    || \Drupal::state()->get('actools_generic_lesson_theme_fixture') !== TRUE) {
  throw new RuntimeException('Only the disposable Generic Lesson fixture may use these scenarios.');
}
$scenario = getenv('ACTOOLS_GL_SCENARIO');
if (!in_array($scenario, ['default', 'sticky-off', 'pale-large', 'reset-learner'], TRUE)) {
  throw new RuntimeException('Unknown Generic Lesson fixture scenario.');
}
if ($scenario === 'reset-learner') {
  $fixture = json_decode(file_get_contents(getenv('ACTOOLS_GL_FIXTURE')), TRUE, flags: JSON_THROW_ON_ERROR);
  $accounts = \Drupal::entityTypeManager()->getStorage('user')->loadByProperties(['name' => $fixture['username']]);
  $account = reset($accounts);
  if (!$account || $account->getAccountName() !== 'gl-theme-learner' || !$account->hasRole('gl_theme_learner')) {
    throw new RuntimeException('The disposable learner account was not found.');
  }
  $lesson = \Drupal::entityTypeManager()->getStorage('node')->load($fixture['lessonId']);
  if (!$lesson || $lesson->bundle() !== 'gl_lesson') {
    throw new RuntimeException('The disposable lesson was not found.');
  }
  $keys = ['lesson:' . $lesson->id(), 'lesson:' . $lesson->id() . ':last'];
  foreach ($fixture['quiz'] as $quiz) {
    $keys[] = 'quiz:' . (int) $quiz['id'];
  }
  \Drupal::service('user.data')->delete('generic_lesson', (int) $account->id(), $keys);
  print "Reset only the fixture learner's lesson and question records.\n";
  return;
}
\Drupal::configFactory()->getEditable('actools_bs5.settings')
  ->set('sticky_header', $scenario !== 'sticky-off')
  ->set('tokens_brand_color', $scenario === 'pale-large' ? '#ffffcc' : '#0d6efd')
  ->set('tokens_base_font_size', $scenario === 'pale-large' ? '20' : '18')
  ->save();
print 'Applied disposable lesson scenario: ' . $scenario . ".\n";
