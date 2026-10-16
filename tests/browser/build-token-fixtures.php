<?php

declare(strict_types=1);

/**
 * Generates browser fixtures with the real theme token builder, without Drupal.
 *
 * Usage: php tests/browser/build-token-fixtures.php /tmp/actools-tokens.json
 */
require dirname(__DIR__, 2) . '/actools_bs5.theme';

if ($argc !== 2) {
  fwrite(STDERR, "Pass the output JSON path.\n");
  exit(2);
}
$fixtures = [];
foreach ([
  'default' => '#0d6efd',
  'pale-yellow' => '#ffffcc',
  'pale-blue' => '#ddeeff',
  'white' => '#ffffff',
  'dark-blue' => '#0b1020',
] as $name => $brand) {
  $fixtures[] = [
    'name' => $name,
    'brand' => $brand,
    'css' => _actools_bs5_build_tokens_css(['tokens_brand_color' => $brand]),
  ];
}
if (file_put_contents($argv[1], json_encode($fixtures, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)) === FALSE) {
  throw new RuntimeException('Could not write token fixtures.');
}
fwrite(STDOUT, 'Generated ' . count($fixtures) . " actual theme-token variants.\n");
