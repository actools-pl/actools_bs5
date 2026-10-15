# Theme improvement and testing plan — 16 October 2026

Current baseline: e29a3c6719d518efa228276cc1094211567dafe8 on main. Preserve the
BOA-verified section choices, compact/full profile heading corrections and
operator settings. Repository code and live site configuration are separate.

## First focused change

1. Fit normal Drupal field/CKEditor diagrams to their content column. Preserve
   aspect ratios and smaller authored widths; do not enlarge all images.
2. Bubble update-access cacheability on full user profiles, including denied
   output. Keep compact author pictures free of private actions and unnecessary
   viewer-specific cache variation.
3. Run the existing component/token suites in disposable Drupal installations,
   add core Layout Builder section rendering/preview tests and warm profile-cache
   regressions, and test content media with the committed CSS in Chromium.

The runtime workflow uses temporary Drupal 11.4.10 projects on PHP 8.3/8.4. It
does not deploy changes or read the public site's database. Browser media
fixtures cover four widths and ordinary, captioned and column-contained images.
Actions reports are evidence for the exact tested commit, not for other builds.

## Public-site observations

The anonymous homepage loaded successfully with one h1, loaded sample images,
the Actools theme's aggregated styles and no desktop horizontal overflow.
The ordinary image field's image had no maximum width constraint. This supports
the preventive responsive-image change; it is not evidence of an existing
overflow on the small sample image. Current content remains demonstration text.
Cloud-browser screenshots could not be captured, and a live phone viewport and
authenticated Layout Builder editing were not tested in this review.

## Next acceptance after automated checks

Use a disposable/staging site with the intended core/modules/theme revision:

- Save and reload each of the six section variants; add/reorder/remove blocks;
  verify desktop, tablet and phone output and keyboard editing.
- Check owner, unrelated user and administrator profiles, login/logout/edit
  actions and author pictures with caches warm.
- Check images with captions, responsive derivatives, SVG/PNG physics diagrams,
  long equations/tables and printing of hidden answers.
- Perform BOA site Verify and keep the established full site/platform recovery
  process before production adoption. CI does not mutate the live platform.

## Professional site presentation

After functional acceptance, build a small design/content system: Home, Topic,
Lesson and Resource Listing templates; consistent resource cards and downloads;
readable lesson columns; clear branding and useful navigation/footer content.
Connect structured Hero/Text-and-image/Resource/FAQ blocks to Layout Builder.
Keep search/filtering and mathematical rendering in the appropriate Drupal
configuration/modules. Full upstream Sass/PostCSS parity remains separate work;
continue using staged CSS builds until reconciled.
