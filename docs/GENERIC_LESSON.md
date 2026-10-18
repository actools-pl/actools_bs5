# Generic Lesson with Actools BS5

The theme can present **Generic Lesson 2.1.2** using the site's existing fonts,
colours and navigation. The lesson module remains responsible for content,
Paragraphs, reading modes, quizzes, progress and permissions.

## Installation and updates

1. Install or update Generic Lesson using its own installation instructions.
2. Install this complete theme version on a test copy of the site.
3. Clear Drupal caches with `drush cr`.
4. Open an existing lesson and check it as a guest and a learner.

The integration loads automatically when Drupal renders the module's
`generic_lesson/lesson` library under Actools BS5. It uses Drupal's
`libraries-extend` mechanism, including cached output and AJAX attachments.
The theme still works when Generic Lesson is absent. Ordinary pages do not
load the lesson stylesheet just because the module is installed.

No extra recipe is needed for lessons. The separate Topic starter and Resource
finder recipes create their own content/configuration and are not applied by
this integration. Updating theme files does not migrate authored content,
change permissions, select fields or rearrange Layout Builder placements.

## Presentation

The integration is scoped to the module's lesson markup. It keeps the module's
templates, data attributes, native form controls and server access decisions.
There is no replacement quiz JavaScript or extra answer-checking endpoint.

- Lesson surfaces and text follow Bootstrap's active light/dark palette.
  An operating-system dark preference no longer gives the lesson a different
  palette from the surrounding explicitly light page.
- Lesson accents use the theme's contrast-adjusted link colour. This also keeps
  controls readable when the site's chosen brand colour is very pale.
- Fonts, body text size and corner radius follow the theme settings.
- Quiz controls, feedback, progress, diagrams and wide content fit the lesson
  column. Wide matching grids and content tables can scroll locally on phones.
- Lesson destinations share the theme's measured sticky-header clearance,
  including the visible Drupal administration bar, without counting the header
  twice.
- Print styling keeps the reading content available and removes navigation
  controls. Quiz answer visibility continues to follow the module's rules;
  printing does not unlock an unresolved quiz.

The theme supports the active Bootstrap palette; it does not add a new colour
mode switch. Administration forms continue to use the site's chosen admin
theme. Keep that theme configured normally for Paragraphs and Layout Builder
editing.

## Before deploying to BOA

Use the intended Drupal platform's theme directory or its Composer workflow
from [INSTALL.md](../INSTALL.md). Do not uninstall the lesson module to update
the theme. After `drush cr`, check:

- A lesson in continuous and guided reading modes, including an in-text anchor.
- A numeric answer, choice question and matching grid, then restore saved
  answers and reading progress after reloading.
- The actual administration toolbar, narrow phone width and keyboard focus.
- A lesson arranged with Layout Builder and its Paragraphs editing form.
- Print preview with all steps and worked-example/practice disclosures.

This integration does not apply the theme or recipes to a live server. The
site's BOA/Percona deployment and any additional Gin or Canvas interface need
their normal staging acceptance.

## Development

The stylesheet is `scss/theme/generic-lesson.theme.scss`, with committed output
in `css/theme/generic-lesson.theme.css`. Build only this maintained entry:

```sh
npm ci --ignore-scripts
npm run build:css -- --entry=theme/generic-lesson.theme
npm run check:css -- --entry=theme/generic-lesson.theme
# After inspecting and testing the candidate:
npm run publish:css -- --entry=theme/generic-lesson.theme
```

The scoped build preserves the inherited Bootstrap bundles. See
[TESTING.md](../TESTING.md) for the automated checks and the separate real-module
browser fixture.

## Integration validation

The integration was checked on 18 October 2026 using Drupal 11.4.10, PHP 8.3,
SQLite, the actual Generic Lesson 2.1.2 package and local MathJax 3.2.2.

- All 10 real-module Chromium scenarios passed. The colour/size scenario
  includes 16 combinations of light/dark mode, default/pale brand settings and
  320, 390, 768 and 1440-pixel widths, plus enlarged text.
- The five optional-library Kernel tests passed with 32 assertions, covering
  absence of the module, asset order, cached rendering, denied content and AJAX.
- Source audit: 28 checks passed, with no failures or skipped checks. The
  existing preview-path advisory remains. PHP syntax, three layout regressions,
  eleven CSS build-tool tests and exact compiled-CSS comparisons also passed.

Browser checks exercised live answer submissions/restoration, reading progress,
both reading modes, keyboard navigation, core Toolbar and contextual editing,
figures/tables, MathJax, Layout Builder, Paragraphs editing, print privacy and
the JavaScript-disabled fallback. The fixture includes all seven quiz formats;
submission/restoration checks specifically cover single-choice, numerical and
matrix questions. This is not a claim of live BOA/Percona, Gin, Canvas, all-browser
or screen-reader acceptance.
