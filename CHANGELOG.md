# Actools standalone hardening — 2026-10-15

Corrected component links, URL/HTML handling, summaries, carousel mapping,
normalized images, navbar focus/control IDs, block headings, capitalization and
print behavior. Added six native Layout Builder sections, self-contained media
schemas, callable retained token cleanup, contrast-aware links and conditional
font preload. Replaced destructive Sass builds with staged, reviewed publishing.
The upstream version/provenance is retained; this is not an upstream rebase.
See FIXES-2026-10-15.md and TESTING.md for checks and limitations.

---

# Actools BS5 (standalone) — Changelog

## Third red-team round + test pack integration

Fixed the remaining review findings and bundled the automated test pack.

- **H-1 (extended):** the dangerous-URL-scheme guard (`javascript:`/`data:`/`vbscript:`
  → `#`) now covers **every** URL-outputting component — added to `badge`,
  `taxonomy`, `list-group`, and `dynamic-responsive-image` (previously only
  `link`/`button`). The raw-HTML rich-text components (`text`/`rich-text`/`html-code`)
  stay `|raw` by design and are trusted-role-only.
- **Undefined tertiary/accent tokens (Medium):** `--bs-tertiary-*` / `--bs-accent-*`
  `bg-subtle`/`text-emphasis`/`border-subtle` were referenced by compiled utilities
  (`.list-group-item-tertiary`, `.alert-accent`, …) but never defined. Added them
  (light + dark) to `tokens.base.scss` and recompiled `tokens.base.css`.
- **`.link-primary:hover` load-order (Medium):** the override now uses a
  `:root:root` prefix so it wins regardless of stylesheet order (it previously had
  the same specificity as Bootstrap's `!important` literal).
- **M-2 dark mode (Medium):** the brand override now emits a
  `:root[data-bs-theme="dark"]` companion that **lightens** link colours (via a new
  tint helper) so a dark brand stays readable in dark mode.
- **Subtheme cron retention (Low):** `hook_cron` now preserves the current token
  file of `actools_bs5` **and every sub-theme built on it**, not just the base.
- **Docs:** removed the stale Animate-on-Scroll sections from the `group`/`section`
  component READMEs (the `aos_*` props were removed earlier).
- **Testing:** the automated test pack now ships under `tests/`, with a root
  `TESTING.md` explaining how to run the static audit (in place) and the PHPUnit
  Kernel/Functional tests (copy the `package: Testing` helper module out). The
  static runner reports 25 passed / 0 hard / 5 advisories (the 5 are the deferred
  carousel prop contract, absolute example paths, and the raw-HTML review flag).

_Validated: PHP lint, YAML/JSON (incl. lockfile), a harness over the token
generator (tint/dark-companion/order-independent hover), tertiary/accent vars now
defined, all 6 URL components guarded, zero dangling includes, known-good CSS
intact, and the bundled static audit passes from inside the theme._

## Second red-team round (post-audit)

A second consolidated review found more real issues (including one my previous
fix got wrong). Verified each against the code and fixed:

**Must-fix (default path):**
- **M-1 — `.link-primary:hover/:focus` now follows the brand colour.** My earlier
  fix emitted a CSS variable, but Bootstrap hard-codes that hover colour as a
  `color:rgba(...)!important` literal that ignores the variable. Now emits real
  `color` + `text-decoration-color` declarations with `!important`.
- **M-3 — token file write is now failure-safe.** `prepareDirectory()` is checked
  and `saveData()` is wrapped in try/catch; on an unwritable `public://` it logs a
  warning and does not attach a stylesheet (was a potential WSOD).
- **L-1 — replaced the deprecated `FileSystemInterface::EXISTS_REPLACE`** with
  `FileExists::Replace` (Drupal-12-ready; removes a per-write deprecation).
- **H-1 — dangerous URL schemes are neutralised** in the `link` and `button`
  components: `javascript:` / `data:` / `vbscript:` hrefs are rewritten to `#`
  (defence-in-depth if these props are ever fed editor input).

**Also fixed:**
- **M-4 — token-file cleanup moved to `hook_cron` with a 24h retention window**
  (was deleting old hashes immediately on save, which could 404 HTML cached by a
  browser/proxy/CDN that still referenced the old file).
- **M-5 — `dynamic-responsive-image` renders the real image** for JS users. It was
  showing an empty-SVG dummy (meant for the removed Drimage JS) with the real
  image only in `<noscript>`; now the visible `<img>`/`<source>` use the real src.
- **L-5 — fixed an unreachable ratio branch** in `image.twig` (the guard required
  `not has_canvas_drimage` on a value only set when `has_canvas_drimage` is true).
- **L-6 — removed the dead `.social-auth-login-hint` CSS** (social-auth was removed).
- **L-2 — dropped the unconditional site-wide Bootstrap-Icons webfont preload.**
- **L-7 — committed `package-lock.json`** so the SASS toolchain is reproducible.

_Deferred (dormant / needs live browser check, documented for a later pass):_
- **M-2** — dark-mode: the `:root:root` brand override can bleed into `[data-bs-theme=dark]`
  and a near-black brand makes dark-mode links unreadable. Dark mode is off by
  default and the fix needs real in-browser verification of body/links/buttons.
- **L-3** — hover/active always shade (Bootstrap tints for light brand colours);
  the contrast-aware text already keeps light brands readable.
- **L-4** — carousel accepts an arbitrary `slide_component` and has no default `id`
  (dormant).
- **L-8** — example `src` paths in 11 `*.component.yml` are absolute
  (`/themes/custom/...`); static YAML examples can't derive the theme path.

_Validated: PHP lint, YAML/JSON parse (incl. the new lockfile), JS syntax, a PHP
harness over the token generator (M-1 output, empty-at-defaults, cron defined),
all library paths resolve, zero dangling includes, known-good CSS intact._

## Housekeeping

- **Design tokens now regenerate the instant settings are saved.** Added a
  settings-form submit handler (`actools_bs5_tokens_settings_submit`) that
  rebuilds the token stylesheet from the submitted values immediately, instead
  of waiting for the next front-end page request, and invalidates the settings
  cache tag. The file-write logic was extracted into
  `_actools_bs5_write_tokens_file()` (shared by the page hook and the submit
  handler), and reverting all tokens to default now removes the generated file.
- **Stripped `//# sourceMappingURL=…` comments from the shipped JS** (27 files).
  The `.map` files aren't shipped, so browsers were 404-ing on them (dev-tools
  only, harmless) — the requests are gone now.

## Red-team fixes (post-audit)

An independent adversarial audit found a critical bug plus several real issues.
All verified against the code and fixed:

- **[Critical] The design-token override never ran.** It was implemented as
  `hook_page_attachments()`, which Drupal fires for **modules only** — themes
  must use the `_alter` form. Renamed to `actools_bs5_page_attachments_alter()`
  (same signature/body); this is why `hook_library_info_alter` already worked.
  The configurable brand colour / font / size / radius now actually apply.
- **[Default path] `no-container` rendered an empty `<div class="no-container">`.**
  The header/footer/content wrappers now skip the wrapper when the container
  setting is `no-container` (page + navbar templates). No more meaningless div on
  every page.
- **[Safety] The "Plain Text" component could emit raw HTML** (`{{ output|raw }}`).
  Now always escaped (`|e`).
- **Three hardcoded `themes/contrib/actools_bs5` fallback image paths** remained
  in `card-impressed` / `card-featured` / `card-overlay` (the earlier fix missed
  `.twig` files) — now derived from `active_theme_path()`.
- **progress-bar schema** used `min`/`max` (JSON Schema wants `minimum`/`maximum`)
  and wasn't range-checked; fixed the keys and clamped `percent` to 0–100 server-side.
- **`hook_library_info_alter`** could TypeError on `sitewide_alert` if that
  library omitted `dependencies`; guarded with `?? []`.
- **Design-token completeness:** the generator now emits contrast-aware button
  text (a light brand colour gets dark text instead of unreadable white),
  `--bs-btn-focus-shadow-rgb` for primary + outline-primary (focus rings match
  the brand), and a `.link-primary` hover/focus override.
- **Build:** `tokens.base.scss` is now included in `npm run build` (it was loaded
  but not built, so the palette could drift); the two utility dev-deps are pinned.
- **Housekeeping:** superseded `tokens-*.css` files are garbage-collected on
  change; `card-testimonial` example asset path corrected; removed the empty
  `images/` directory; README corrected (package.json is present; the icon pack /
  `icons.yml` were removed in Pass 3; the build warning now names the canonical CSS).

_Deferred (dormant, low): `.alert-tertiary`/`.alert-accent`/`.list-group-item-tertiary`
reference `--bs-*-bg-subtle`/`-text-emphasis` vars the compiled `:root` doesn't
define — these variants aren't exposed in any component schema. Noted for a future
SASS-map + `tokens.base.scss` recompile._

_Validated: PHP lint, YAML/JSON parse (111 files), JS syntax, a PHP harness over
the token generator (contrast math, focus rings, empty-at-defaults, light/dark
brand output), all library paths resolve, zero dangling includes. Known-good
minified base CSS restored after the accidental full rebuild._

## Extending work — Pass 4 (part 2: design tokens)

### Restored the theme's missing Bootstrap tokens (visible correction)

The upstream build disabled Bootstrap's `root` partial, so the `:root { --bs-* }`
custom-property block was never emitted — meaning `--bs-body-font-family`,
`--bs-primary`, `--bs-primary-rgb`, `--bs-border-radius` (and more) were
**undefined**. In practice the body font fell back to the browser default
(serif) instead of Figtree, and `.text-primary` / `.bg-primary` and
component border-radius didn't work.

- **Added `css/base/tokens.base.css`** — Bootstrap's `:root` token block compiled
  against the theme's own SASS variables (Figtree, 1.125rem, `#0d6efd`, 0.5rem
  radius, the full palette). Loaded with the framework, it restores the intended
  design. Additive — it does **not** recompile or replace `bootstrap.base.css`.

### Configurable design tokens (Appearance → Actools BS5 → Design tokens)

- New controls: **Brand colour**, **Body font** (Figtree / system / serif),
  **Base font size** (16–20px), **Corner radius** (sharp / subtle / default /
  rounded).
- Only tokens changed from the default are emitted, layered over the base
  tokens, to an **external, content-hashed stylesheet** (`public://actools_bs5/
  tokens-*.css`) — never inlined, so it stays CSP-compatible. Rebuilds via the
  `config:actools_bs5.settings` cache tag.
- Brand colour drives `--bs-primary`/`-rgb`, links, and the primary buttons
  (`.btn-primary` / `.btn-outline-primary`), with hover/active shades computed to
  match Bootstrap's `shade-color()` (15/20/25%).
- New config keys (schema-backed, seeded in install config): `tokens_brand_color`,
  `tokens_body_font`, `tokens_base_font_size`, `tokens_corner_radius`.

_Validated: PHP lint, a PHP harness over the token generation (shade math,
empty-at-defaults, brand/font/size/radius output), YAML/JSON parse, and a clean
`tokens.base.css` compile against Bootstrap 5.3.8._

> **Deploy note:** because this restores the intended fonts/colours, the site
> will look different (proper Figtree body text, working primary-colour
> utilities). Deploy to staging and review before production.

## Extending work — Pass 4 (part 1: infrastructure)

- **Migrated `theme_get_setting()`** (deprecated in Drupal 11.3, removed in 12.0)
  to the `Drupal\Core\Extension\ThemeSettingsProvider` service across
  `actools_bs5.theme` and `theme-settings.php` — resolves the remaining
  deprecation notices. Uses the active theme in preprocess and the configured
  theme key in the settings form, so it stays correct for sub-themes.
- **Added a SASS build pipeline** (`package.json` + `npm run build`, pinned
  `bootstrap@5.3.8` + the two utility packages) so the styles can be recompiled;
  `node_modules/` is git-ignored. Verified it compiles cleanly in a clean
  environment. See the README "Building from source" note about the committed
  CSS vs. a clean rebuild (they have drifted upstream).
- **Minified all loaded CSS** in place (rule-count verified identical, so no
  visual change): `bootstrap.base.css` 324 KB → 268 KB, plus `actools.base`,
  `ckeditor5.bootstrap.base`, and the `theme/` + `layout/` stylesheets.

_Still to come in Pass 4: Bootstrap design tokens via `theme-settings.php`
(CSP-safe `--bs-*`), and core `responsive_image` guidance/config._

## Streamlining work — Pass 3 (removals + optimisation)

Applies the agreed streamlining decisions (not using Canvas right now; no Icon API,
no RTL). Theme dropped from ~16 MB to ~7 MB uncompressed; component count 114 → 97.

- **Removed the Bootstrap Icons SVG pack** (`images/icons/`, ~2,092 SVGs) and its
  two Icon-API packs (deleted `actools_bs5.icons.yml`). The theme's own icons
  render from the Bootstrap Icons **web font**, which is unchanged; this only
  removes the Drupal Icon-API data source (icon fields / CKEditor icon plugin).
- **Removed RTL support:** the `framework-rtl` / `bootstrap-styles-rtl` /
  `global-styles-rtl` libraries, the `*-rtl.base.css` files and their SCSS
  sources, `fonts/droid/`, the `dir=rtl` attach in `html.html.twig`, and the RTL
  font preload block in `preload.twig`.
- **Re-encoded oversized placeholder images** (`hero-1`, `hero-2`, `teaser-1`)
  from 24-bit PNG to optimised JPEG: ~2.3 MB → ~0.18 MB. References in the
  component `examples:` were updated to `.jpg`.
- **Fixed the hardcoded `/themes/contrib/actools_bs5` paths** in component
  `examples:` to `/themes/custom/actools_bs5` (the install location).
- **Removed 7 `foundation` style-guide components** (colors, icons, images,
  interaction-guideline, logos, spacing-and-grids, typography) — the
  `foundation/images/assets/` placeholder images are kept, since other components
  reference them.
- **Removed 9 confirmed-leftover components** with no template/reference:
  `input-color` / `-date` / `-email` / `-password`, `views-view-grid` / `-list` /
  `-summary` / `-table`, and `hero-slide` (superseded by the bundled carousel).
- **Added `.brand-logo-image { height: 40px; width: auto; }`** (compiled CSS +
  SCSS source) so an arbitrarily-sized uploaded logo sits neatly in the header
  for classic (non-navbar) use.
- Tidied the stale accordion comment in `scss/base/bootstrap.base.scss`.

_Validated statically: PHP lint, YAML/JSON parse (111 files), JS syntax (29 files),
all library asset paths resolve, and zero dangling SDC includes._

## Streamlining work — Pass 1 & 2 (default-path + dormant correctness)

### Pass 1 — Default render-path fixes
- **Fixed broken core image rendering.** Removed `templates/system/image.html.twig`,
  which routed the core `image` hook through the SDC image component without the
  `media` prop it requires, producing an empty `<figure>` for standard Image
  fields, user pictures, and image-style renders. Core now emits a proper `<img>`.
  The SDC `image` component is unchanged and still used where `media` is supplied.
- **Added a default Main navigation block** (`config/optional/…mainnavigation.yml`)
  in the `header` region, so a fresh install has site navigation (activates the
  existing `menu--main.html.twig`).
- **Added `sticky_header` to the config schema** (`config/schema/…schema.yml`) —
  clears the config-inspector schema warning.
- **De-duplicated the Bootstrap Icons font preload** in `templates/includes/preload.twig`
  (kept `.woff2`, dropped the redundant `.woff`).
- Corrected the stale region list in the `page.html.twig` doc comment.

### Pass 2 — Dormant-component correctness + safe cleanup
- Removed the dangling `actools_bs5:accordion` include (deleted the unused
  `views-bootstrap-accordion.html.twig`); **zero dangling SDC includes remain**.
- Removed the undefined `actools_bs5/accordion-styles` library dep from
  `accordion-block` (its co-located CSS auto-attaches).
- Removed the orphaned `accordion-scripts` library + `js/components/accordion-init.js`
  (Bootstrap's global collapse data-API drives accordions).
- Removed the contrib `drimage_improved/drimage_improved` library dependency from
  `carousel` and `dynamic-responsive-image` (would throw on placement without the
  contrib module).
- Added the missing `cover_fill` prop to the `image` component schema.
- Removed the dead `aos_*` props (schema + twig) from `group` and `section`.
- Gated the `menu-item--expanded` class in `nav-menu` on `item.below`.
- Removed redundant `bootstrap-icons.{css,json,scss}` (only `min.css` is loaded)
  and the orphan `images/required.svg`.

_Validated statically: PHP lint, YAML/JSON parse (128 files), JS syntax (29 files),
all library asset paths resolve, and zero dangling SDC includes. Live-render
confirmation (esp. the image formatter and the nav block) still owed on staging._

### Still to come
- **Pass 3 (streamlining):** remove the Bootstrap Icons SVG pack + its Icon-API
  packs, drop RTL libraries + `fonts/droid`, re-encode the oversized placeholder
  images, fix the hardcoded `/themes/contrib/actools_bs5` paths, remove the 7
  `foundation` style-guide components.
- **Pass 4 (extend):** slim Sass/Vite build + minification, Bootstrap design
  tokens via `theme-settings.php` (CSP-safe `--bs-*`), migrate `theme_get_setting()`,
  core `responsive_image` wiring.
