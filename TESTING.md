# Testing Actools BS5

The test pack distinguishes source/asset checks from actual Drupal rendering.
A skipped check is not a pass.

## Checks without Drupal

In a Git checkout, run the packaging/static audit against an exported tree so
the repository's own .git directory is not mistaken for shipped release content:

```sh
theme_test_export="$(mktemp -d)"
git archive HEAD | tar -x -C "$theme_test_export"
python3 tests/tools/actools_bs5_static_audit_tests.py "$theme_test_export"
```

For an extracted release archive with no .git directory, use the direct command
below. The packaging gate deliberately continues to reject .git in releases.

```sh
python3 tests/tools/actools_bs5_static_audit_tests.py .
python3 tests/tools/test_layout_regressions.py
node --test scripts/css-compat.test.mjs
```

The static runner requires Python 3 and PyYAML; Node is used for JavaScript
syntax checks. Missing PHP is reported as an explicit skip. A release gate can
require PHP and promote advisories:

```sh
python3 tests/tools/actools_bs5_static_audit_tests.py . --require-php --strict-advisory
```

Demo image examples assume the documented `themes/custom/actools_bs5` location;
the path advisory does not refer to production field images. Examples may need
adjustment for other installations.

## Drupal Kernel and Functional tests

Use a disposable Drupal 11 test site with core-dev/PHPUnit and a configured test
database. Install the theme at a normal theme path. Copy the test-only helper:

```sh
cp -a tests/drupal-module/actools_bs5_test /path/to/drupal/web/modules/custom/
```

From the Drupal project root:

```sh
vendor/bin/phpunit web/modules/custom/actools_bs5_test/tests/src/Kernel
vendor/bin/phpunit web/modules/custom/actools_bs5_test/tests/src/Functional
```

ComponentRenderingRegressionTest renders real component templates for hostile
and legacy input and uses SDC smoke paths for ordinary metadata-valid input.
It covers URL output, filtered rich HTML, safe summaries, native image sources,
card links, navbar targets, block labels and carousel content/IDs. The token
lifecycle suite invokes the actual writer to test expired/recent/current files.

A convenient component-test wrapper is also included:

```sh
python3 tests/tools/test_component_regressions.py --drupal-root /path/to/drupal/web
```

See that script's `--help` for accepted project/phpunit arguments. It reports a
skip when a PHP/Drupal test environment is absent.

## Manual acceptance

On a test copy: enable the theme, check ordinary nodes, login/forms, menus with
multiple levels, block titles and card links; change brand/font settings; verify
cache clearing; add/edit/reorder blocks in each of the six Layout Builder
sections; check phone/tablet/desktop widths, keyboard navigation and printing
of hidden answers. Full-width sections respect the outer Content container.

The shipped tests are not a substitute for this browser/Drupal acceptance.
The test helper is never auto-enabled and is not required in production.
