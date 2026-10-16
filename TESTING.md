# Testing Actools BS5

## Isolated GitHub runtime checks

The `Drupal runtime and browser tests` workflow builds fresh Drupal 11.4.10
projects with core-dev in GitHub-hosted temporary directories. It runs Kernel
and Functional suites on PHP 8.3 and 8.4 with SQLite; the Functional server binds
to loopback only. No production database, BOA credentials or live-site test
helpers are used. Read each job's result and retained reports before accepting
a revision. Source checks are a separate workflow.

LayoutBuilderIntegrationKernelTest exercises all six native sections through
core Section/SectionComponent rendering, serialized settings, preview attributes,
block ordering and cache metadata. UserActionsCacheabilityTest switches viewers
over warm entity render caches and checks both allowed and denied action output.

The browser job installs pinned Playwright 1.64.1 in an isolated temporary
directory and tests the committed CSS in Chromium at 320, 375, 768 and 1440px.
It covers ordinary image fields, captioned text images and Layout Builder-style
columns, including aspect ratios and small authored widths. This is an asset
test against representative HTML, not a claim of live Drupal drag/drop coverage.
The same job reads the real page template's skip-link classes and checks keyboard
visibility and activation using the bundled Bootstrap CSS.

To run the browser fixtures locally with Playwright installed:

```sh
npx playwright test --config tests/browser/playwright.config.cjs
```

The complete future acceptance sequence is in docs/IMPROVEMENT_PLAN.md.

The test pack distinguishes source/asset checks from actual Drupal rendering.
A skipped check is not a pass.

## Optional Topic starter

The runtime workflow also discovers `TopicStarterRecipeKernelTest` and
`TopicStarterEditorFunctionalTest`. They apply the actual optional recipe in
disposable Drupal installations, exercise its real fields/forms and Layout
Builder sections, and check reference access, warm caches, edits and formatted
text. Read the run results; adding a test is not evidence that it passed.

`topic-blocks.spec.cjs` checks the committed component CSS against representative
markup at phone/tablet/desktop sizes and in a narrow column on a wide screen.
It covers container-based stacking, whole-image aspect ratios and card-link
keyboard focus. Kernel/Functional coverage exercises the actual Twig integration;
the CSS fixtures do not simulate authenticated drag/drop editing.

See [PAGE_BUILDING.md](docs/PAGE_BUILDING.md) for manual acceptance, installation
and backup requirements. BOA site Verify and live layout editing remain separate
operator checks.

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

From the Drupal web root, with the test database and base URL configured:

```sh
mkdir -p sites/simpletest/browser_output
../vendor/bin/phpunit -c core/phpunit.xml.dist modules/custom/actools_bs5_test/tests/src/Kernel
../vendor/bin/phpunit -c core/phpunit.xml.dist modules/custom/actools_bs5_test/tests/src/Functional
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
