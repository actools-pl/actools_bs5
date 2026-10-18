# Generic Lesson browser integration

This suite exercises actual Drupal rendering, core Toolbar, native contextual
editing controls, Paragraphs authoring and Layout Builder with Generic Lesson
2.1.2. Use a disposable Drupal site: the seed changes its theme/configuration,
creates content and creates test accounts. It is not a production installation
script.

## Prepare the fixture

Start with a disposable Drupal 11 site with this theme available, Generic Lesson
2.1.2 enabled and its Paragraphs dependencies installed. Place the complete
MathJax 3.2.2 `es5` distribution at `web/libraries/mathjax/es5` (including fonts
and `ui/safe.js`). The suite intentionally uses local math assets.

Set these environment variables in the shell that will run Drush and Playwright:

```bash
export ACTOOLS_GL_THEME_FIXTURE=1
export ACTOOLS_GL_BASE_URL=http://127.0.0.1:8894
export ACTOOLS_GL_PROJECT=/absolute/path/to/disposable-drupal-project
export ACTOOLS_GL_DRUSH="$ACTOOLS_GL_PROJECT/vendor/bin/drush"
export ACTOOLS_GL_FIXTURE=/absolute/path/to/test-output/fixture.json
export ACTOOLS_GL_BROWSER_OUTPUT=/absolute/path/to/test-output/browser
export ACTOOLS_GL_EDITOR_PASSWORD="$(openssl rand -hex 24)"
export ACTOOLS_GL_ADMIN_PASSWORD="$(openssl rand -hex 24)"
```

Create the output directory, then run the seed from the Drupal project directory:

```bash
"$ACTOOLS_GL_DRUSH" --uri="$ACTOOLS_GL_BASE_URL" php:script \
  web/themes/custom/actools_bs5/tests/browser/generic-lesson-ui/seed-lesson-site.php
"$ACTOOLS_GL_DRUSH" --uri="$ACTOOLS_GL_BASE_URL" cache:rebuild
```

The seed writes fixture IDs and public test markers to JSON. Passwords remain in
the environment. It refuses to run twice on an already seeded site. Restore a
fresh disposable database to reseed.

## Run

Serve the disposable Drupal web root on the loopback URL above, for example
`php -S 127.0.0.1:8894 .ht.router.php` from its `web` directory. The server and
browser must share a network namespace; container sandboxes may require starting
both from the same parent process.

Install Playwright in the test toolchain (verified with Playwright 1.62.1) and its
Chromium browser, then run from the theme directory:

```bash
npx playwright test --config=tests/browser/generic-lesson-ui/playwright.config.cjs
```

Set `ACTOOLS_GL_CHROMIUM` to an existing Chromium executable when using a managed
browser runtime. The config rejects non-loopback or non-HTTP URLs.

The guarded configuration helper restores the default theme scenario after
presentation tests. Before the submission test it clears only the fixture
learner's records for the fixture lesson and its questions, so a second test run
can repeat submissions. It does not clear other users' progress or attempts.

## Coverage

Ten browser tests cover library attachment and ordinary pages; 320/390/768/1440px
viewports; explicit light/dark theme versus opposite OS preference; pale branding,
20px theme text and 200% phone text; measured text/control contrast; keyboard and
native hash navigation; sticky headers with real administrator bars; contextual
editing controls; MCQ/numeric/matrix submission and restoration; progress reset;
wide images and keyboard-scrollable tables; local MathJax rendering; continuous
reading; no-JavaScript content; print disclosures and quiz-key privacy; and real
Paragraphs and Layout Builder editing controls.

Screenshots and an A4 print PDF are written to `ACTOOLS_GL_BROWSER_OUTPUT`.
Failure traces and DOM snapshots use the same directory. The suite does not
change or test a live website, other browsers, or the production database engine.
