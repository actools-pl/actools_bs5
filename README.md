# Actools BS5 — standalone Drupal 11 theme

Standalone hardening build: **2026-10-16**. Based on the supplied Vartheme-derived
5.0.0-beta1 fork. This is a custom Actools build, not upstream Vartheme 5.0.6 and
not a claim of stable certification.

The theme keeps its machine name **actools_bs5**. It has no required Varbase,
Canvas, CVA, Barrio or other contributed-module dependency. Bootstrap 5.3.8,
compiled CSS, JavaScript and local fonts are included; production use does not
require Node or a build step.

- Drupal constraint: `^11.3` (includes Drupal 11.4; excludes Drupal 12).
- PHP: follow the requirements of the installed Drupal core release (8.3+ for
  the targeted Drupal 11 releases).
- 99 Single Directory Components, 73 standard Drupal Twig overrides and six
  registered Layout Discovery sections.
- English/LTR styling. Complete RTL styling is not supplied or certified.

## Install or update

**Optional editable Topic pages:** [PAGE_BUILDING.md](docs/PAGE_BUILDING.md)
describes the separate recipe for resource cards, text/image blocks, and shared
Topic layouts, with optional figure captions and resource kind/level labels.
Theme installation alone does not apply it or create content
types. Use a test site before adopting this new feature on BOA.

**GitHub/Composer installation:** see [INSTALL.md](INSTALL.md) for the package
repository, exact-lock/commit workflow and BOA platform instructions. The theme
code is at this repository's root. Main is a test candidate with no tagged
release yet; the source version 5.0.0-beta1 is inherited from the supplied fork.

CI runs Composer validation, PHP syntax checks on 8.3/8.4, the static asset/YAML/JS
audit, three layout source regressions and nine CSS publication-guard tests.
The separate **Drupal runtime and browser tests** workflow builds disposable
Drupal 11.4.10 projects on PHP 8.3/8.4 for Kernel/Functional coverage. Its Chromium
job checks large and small content diagrams at four viewport widths using the
shipped CSS. These jobs never connect to or alter the BOA installation. Consult
the specific commit's Actions results; source CI alone is not runtime acceptance.

Extract the ZIP so the folder is named `actools_bs5` in your Drupal platform's
custom themes directory, commonly `web/themes/custom/actools_bs5`. On BOA, use
the directory belonging to the intended Drupal platform.

For an existing installation, replace the theme code with this complete folder,
then clear Drupal caches. Keep a copy of any local code customizations before
replacing the folder. Theme settings and content are stored in Drupal, outside
this ZIP.

```sh
drush cr
```

For a first installation:

```sh
drush theme:enable actools_bs5
drush config:set system.theme default actools_bs5 -y
drush cr
```

Default block placements in `config/optional/` are imported on initial theme
installation only. Existing sites should check **Structure → Block layout**:
branding and navigation belong in Header; breadcrumbs, page title, tabs,
actions, messages and help precede Main page content in Content.

## Native Layout Builder sections

This build registers the following choices under **Actools sections**:

- One column, contained.
- One column, full available width.
- Two columns: 50/50, 33/67 or 67/33.
- Three equal columns.

Columns stack below 768px. Region attributes and render arrays are retained for
core editing controls and cache metadata. Enable core Layout Builder if wanted:

```sh
drush en layout_builder -y
drush cr
```

Then enable **Use Layout Builder** under a content type's **Manage display**.
Choose **Manage layout → Add section → Actools sections**. Individual page
overrides remain an optional content-type setting.

Full available width respects the surrounding page container. For full-width
page sections, set **Appearance → Actools BS5 → Settings → Containers → Content**
to **No Container** and use contained sections for narrow content.

Installing the theme supplies these section layouts. The optional Topic starter
recipe supplies its documented native block and content types when explicitly
applied. Hero/FAQ editing blocks and a general SDC component picker are separate
integrations.
No Varbase distribution or Canvas installation is needed for the six layouts.

## Appearance settings

**Appearance → Actools BS5 → Settings** provides:

- Container selection for Header, Content and Footer.
- Sticky-header toggle.
- Brand colour, body font, body text size (16–20px), and corner radius.

Body text size does not resize the entire rem-based interface. Link colours are
adjusted for at least 4.5:1 contrast on the standard white/light and dark page
surfaces. Selected primary fills remain unchanged. Custom backgrounds, opacity
utilities and additional component combinations still require visual review.
Heading capitalization follows the authored text.

Token overrides are external, content-hashed stylesheets under
`public://actools_bs5/`; they do not require inline styles. Cleanup runs through
the actual writer on uncached theme renders, at most once per day, protected by
a lock. It preserves current/subtheme files and retains superseded hashes for
seven days. Sites caching HTML for longer should extend the retention policy.
The removed theme cron hook was never invoked by Drupal core.

Only the primary Latin Figtree font is preloaded, and only when Figtree is the
selected body font. Other font subsets and DM Mono load when CSS requires them.

## Components and safe content

- Cards supplied with a URL now have a linked title when no button is requested.
- Reusable navigation uses unique control IDs and visible keyboard focus.
- Block titles use the current Heading component contract.
- Component URL strings accept HTTP, HTTPS, mailto, tel and ordinary relative
  paths. Controls, backslashes, protocol-relative and unsupported schemes are
  rejected. Attribute output is escaped once.
- Plain HTML strings use Drupal's core render-array XSS filtering. Already
  trusted Drupal render output keeps its normal rendering semantics. The HTML
  component accepts safe markup; JavaScript belongs in a Drupal library/module.
- Rich-text summaries remove tags before trimming. Full rich text remains
  formatted through core filtering. The legacy `strip_html` setting is retained
  for saved data; trimming always produces a plain-text summary.
- Carousel slide components are restricted to the supported Hero Card contract;
  IDs are generated uniquely and actual slide text/media are mapped correctly.
- Image and video metadata schemas are self-contained and no longer reference
  Canvas-only schema definitions. Canvas-specific media-picker autodetection is
  not supplied. Canvas integration would need its own adapter and verification.

## Images and print

For regular Drupal image fields, use core **Responsive Image** and configure
responsive image styles against `actools_bs5.breakpoints.yml`. Field formatters
retain normal Drupal picture/srcset output. Native SDC image fallbacks use the
normalized source; they do not automatically create responsive derivatives.
Ordinary image fields and formatted-text images also fit their content column
without forcing small diagrams to full width. Captioned figures respect the
same column boundary.

Print styles hide site navigation/footer and editing controls, show Bootstrap
tab/accordion answers, and discourage splits inside figures and tables. Article
headers and attribution remain visible. This is CSS print support, not a PDF
export system.

## Safe CSS development

The inherited compiled CSS contains output from upstream processing, including
RTL companion selectors, that the slim Sass sources/tooling do not reproduce.
**Full Sass-to-shipped-CSS parity is still unresolved.** The build workflow now
protects the working CSS from accidental replacement.

Use Node 20.19+ and the exact lockfile:

```sh
npm ci
npm run test:build-tools
npm run build
npm run check:css
```

Builds stage ten base/layout/theme CSS files under `.build/css/`. They never
silently overwrite `css/`. A report in `.build/css-build-report.json` records
hashes and selector differences. A build/check exits nonzero if selectors would
be lost. This is expected for the inherited source drift until the pipeline is
fully reconciled.

After reviewing staged output and testing its appearance, publish explicitly:

```sh
npm run publish:css
```

Publishing rejects stale sources and edited candidate files. Intentional selector
removal requires an explicit reviewed override:

```sh
npm run publish:css -- --allow-selector-removal
```

Selector preservation does not prove visual equivalence. Review responsive,
keyboard, print and any RTL use before deployment. `node_modules/` and `.build/`
are development output and must not be included in the deployed package.

## Verification

See `TESTING.md`, `FIXES-2026-10-15.md` and `FIXES-2026-10-16.md` for checks,
changes and environment limits. The BOA smoke test verified the six section
choices and the 50/50 layout on desktop and narrow screens, including reloads.
The compact-author correction was deployed and visually confirmed on a node:
the duplicate author heading disappeared while the submission line and columns
remained visible. The operator confirmed the System sans-serif setting changes
the font and showed profile controls still rendering. The latest cleanup removes
the duplicate heading on the profile itself, relying on the normal page-title
block; the operator confirmed that only one profile heading remains. A fresh
full site-files/classic-mysqldump backup was also reported green. Standalone
site Verify after the final theme changes has not yet been reported.
The full PHP/Drupal rendering suites and the other layout variants remain
outside the completed smoke test.
Further test work is described in [docs/IMPROVEMENT_PLAN.md](docs/IMPROVEMENT_PLAN.md).
New automated coverage does not retroactively certify the deployed revision.

This custom theme has no Drupal.org project release feed. Its metadata omits
`project` and the inherited packaging timestamp. Track updates and security
reviews through the maintained theme package/repository; absence from the core
Available updates report is not a security assessment.

## Licence

GPL-2.0-or-later. Original Vardot/Varbase attribution is retained. See LICENSE.txt.
