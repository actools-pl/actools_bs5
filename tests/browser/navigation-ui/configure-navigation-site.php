<?php

/**
 * @file
 * Restricted settings scenarios for the isolated navigation browser fixture.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli' || getenv('ACTOOLS_NAV_UI_FIXTURE') !== '1') {
  throw new RuntimeException('Only the disposable navigation CI site may use these scenarios.');
}
$scenario = getenv('ACTOOLS_NAV_SCENARIO');
if (!in_array($scenario, ['default', 'sticky-off', 'pale-large'], TRUE)) {
  throw new RuntimeException('Unknown navigation fixture scenario.');
}
\Drupal::configFactory()->getEditable('actools_bs5.settings')
  ->set('sticky_header', $scenario !== 'sticky-off')
  ->set('tokens_brand_color', $scenario === 'pale-large' ? '#ffffcc' : '#0d6efd')
  ->set('tokens_base_font_size', $scenario === 'pale-large' ? '20' : '18')
  ->save();
print 'Applied disposable navigation scenario: ' . $scenario . ".\n";
