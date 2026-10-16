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
if (!in_array($scenario, ['default', 'sticky-off', 'pale-large', 'menu-rename', 'menu-restore'], TRUE)) {
  throw new RuntimeException('Unknown navigation fixture scenario.');
}
if (str_starts_with($scenario, 'menu-')) {
  $fixture = json_decode(file_get_contents(getenv('ACTOOLS_NAV_FIXTURE')), TRUE, flags: JSON_THROW_ON_ERROR);
  $link = \Drupal\menu_link_content\Entity\MenuLinkContent::load($fixture['parentMenuLinkId']);
  if (!$link || $link->getMenuName() !== 'main') {
    throw new RuntimeException('The disposable parent menu fixture was not found.');
  }
  $link->set('title', $fixture[$scenario === 'menu-rename' ? 'renamedParentTitle' : 'parentTitle'])->save();
  // Native entity save must invalidate the warm header/footer output itself.
  // Deliberately do not clear caches or touch theme settings in this scenario.
  print 'Saved disposable native menu change: ' . $scenario . ".\n";
  return;
}
\Drupal::configFactory()->getEditable('actools_bs5.settings')
  ->set('sticky_header', $scenario !== 'sticky-off')
  ->set('tokens_brand_color', $scenario === 'pale-large' ? '#ffffcc' : '#0d6efd')
  ->set('tokens_base_font_size', $scenario === 'pale-large' ? '20' : '18')
  ->save();
print 'Applied disposable navigation scenario: ' . $scenario . ".\n";
