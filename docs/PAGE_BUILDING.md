# Topic pages and editable blocks

This optional starter adds **Actools text and image** and **Actools resource
cards** to Drupal's native content blocks. It also supplies **Actools topic**
and **Actools resource** content types. Installing or updating the theme alone
does not apply this setup.

## What the first version provides

| Item | Editor controls |
| --- | --- |
| Text and image block | Heading, formatted text, optional uploaded image with alternative text and caption, image on the left or right |
| Resource cards block | Heading and an ordered selection of existing Actools resources |
| Resource | Title, short plain-text summary, optional resource kind and study level, formatted body, optional image and public PDF/DOCX download |
| Topic | Introduction and an ordered resource selection, with a shared Layout Builder default |

Cards reference resources rather than copying their titles and summaries.
Updating a resource updates its cards. Drupal's entity-reference formatter
controls access to referenced resources. Card summaries use plain text; longer
formatted explanations belong in the resource body.

Cards put their metadata, title and summary first. An optional diagram follows
the text, so cards with and without pictures share a clear reading order. A
card without an image has no reserved thumbnail area.

Resource kind and study level are optional native select fields. Kind offers
Lesson, Notes, Worked examples and Assignment; level offers Foundation,
JEE Advanced and Olympiad. A resource without these values remains a normal
card, with no empty badge or separator. Cards and full resource pages use the
same field values and labels. These selections identify material; they do not
automatically sort resources, create filters or grant access.

The optional image caption is plain text and appears below the image as a
semantic figure caption. For example, "Figure 1. Capillary rise in a narrow
glass tube." Figure numbering is editorial, not automatic. Alternative text
still describes the image for readers who cannot see it. A caption without a
displayed image is retained for editing but is not printed on the page.

The figure and caption align together within the available column. Small
diagrams keep their natural size while their captions retain a readable width;
larger diagrams scale down without cropping. For dense labels, use a wider
section and keep the full-resolution original available through the image link.

For a Resource download, enter a useful **Description** in the file widget, such
as “Capillary rise: worked examples”. This becomes the download link text. The
file type and size appear beside the link automatically, for example
**PDF · 420 KB**. Empty descriptions use “Download PDF” or “Download DOCX”.
These details come from the stored file; editors do not need to type its size.

The supplied image widget is a standard core image upload with required
alternative text, not a Media Library selector. Existing site text formats
remain in control of formatted text; this recipe does not grant Full HTML or
create new text formats. Hero and FAQ editing blocks are later additions.
The site must already have an enabled text format that the intended editor may
use (for example, core Plain text or an existing restricted HTML format).

Full Resource pages and text/image blocks use core responsive image derivatives
at 480, 960, 1440 and 1920 pixel widths. Scaling preserves the whole diagram and
never enlarges a smaller original. Displayed images link to the original file
for full-resolution inspection. Lazy loading and responsive size selection
reduce initial downloads; the original upload is fetched when its link is opened.

## Apply on a test site first

Use the same reviewed code revision for both the theme and its recipe. Keep a
full site/database/files backup and a separate copy of the platform code and
Composer lock file before changing an existing site.

1. Put the reviewed theme revision on a new test platform/site. Confirm its
   normal pages work and make Actools BS5 the default front-end theme through
   Appearance if it is not already selected. Keep the existing admin theme.
2. Apply `recipes/actools_topic_starter` once using the project's local Drush
   13 or core recipe command. Use the **absolute** recipe path; a Composer
   installation usually places the theme in `themes/contrib`, while manual
   uploads normally use `themes/custom`.
3. Review permissions for the intended editor account. Recipe installation
   grants no permissions. Only trusted site builders should change default
   layouts; ordinary editors need the relevant content/block editing access.
4. Clear caches, then perform the acceptance checks below.

For an ordinary local test project, from its Drupal web root:

```sh
../vendor/bin/drush --uri=https://example.test recipe /absolute/path/to/actools_bs5/recipes/actools_topic_starter
../vendor/bin/drush --uri=https://example.test cache:rebuild
```

Replace the example URL and path for the test site. On BOA, use the correct
site alias and platform-local Drush as the site owner in a guided session.
The limited `o1.ftp` shell does not allow arbitrary PHP commands or chained
commands. Do not run Composer as root or modify a shared live platform to try
the recipe.

The recipe creates new, prefixed configuration. It does not select a new home
page, change the site's default/admin themes, alter existing content types,
grant roles, or create demonstration content. Strict configuration matching
stops on conflicting existing starter configuration. Recipes are applied to
site configuration; deleting the recipe directory is not an uninstall or a
rollback. Restore the pre-change site backup to undo an unwanted application.

If an earlier PR revision of this starter was already applied on a test site,
updating theme files alone does not create its new caption or metadata fields.
Use a fresh disposable site for acceptance, or review and deploy those changes
through the site's normal configuration-management process. Do not force a
recipe reapplication over an existing customised display.

## Build the first Topic page

1. In **Content → Add content → Actools resource**, create two real teaching
   resources. Give each a short useful summary and, when useful, select its kind
   and level. For example, “Surface tension:
   concepts and diagrams” and “Capillarity: solved problems”. Publish only
   material that should be publicly readable.
2. In **Content → Add content → Actools topic**, enter “Surface tension and
   capillarity”, write its introduction, and select the two resources in their
   intended order. Save and view the page.
3. The shared Topic default places its introduction before its resource cards.
   Change the default for every Topic through **Structure → Content types →
   Actools topic → Manage display → Manage layout**.
4. For a deliberately different page, open its **Layout** tab. Choose **Add
   block → Create custom block → Actools text and image**. Enter its heading,
   text and image, add an optional caption, choose left/right, add it to the
   section and save the layout.
5. Add an **Actools resource cards** custom block the same way when a separate
   curated resource group is useful. Choose the resources rather than typing
   links. The block's heading is its visible heading; its administrative label
   identifies it in the editor.

Keep the Topic's page title as its single h1. Section/block headings use h2 and
resource-card titles h3. Use a short introduction and clear labels before
adding decorative content. Individual layout overrides stop following changes
to the shared default; use them intentionally.

## Publishing appearance and a complete Topic

The publishing styles load with the starter's Topic pages and resource metadata.
They keep the site's configured fonts and accessible link colours, use a
restrained spacing scale, and give Topic introductions a readable line length.
Cards retain their full titles, summaries and uncropped diagrams. Cards without
images or optional metadata have an intentional compact presentation. These
styles are scoped to the starter's content, not a replacement for the site's
header, navigation or footer configuration.

For a complete "Surface tension and capillarity" Topic, use this editorial order:

1. A short introduction explaining what the student will study.
2. A text/image block with an explanatory diagram and caption.
3. A resource-cards group headed **Concepts and notes**.
4. A resource-cards group headed **Worked examples**.
5. A resource-cards group headed **Practice assignments**.

Build each group with a normal resource-cards block and select its resources in
the intended order. The default single resource field remains available for
simpler Topics. Shared layouts are preferable when several Topics use this
pattern; a deliberately separate page can use an individual override. No sample
content or grouping is silently added to an existing site by this theme.

The repository includes a static visual specimen at
[preview/topic.html](preview/topic.html), using the committed publishing and
component styles. Open it from a checkout with its relative assets intact.
It is an appearance reference rather than a substitute for a Drupal page.
The disposable browser test also builds a real Topic and retains desktop and
phone screenshots in its `layout-builder-editor-browser` Actions artifact.

## Acceptance before live use

- Create, edit and reload both block types through Drupal's forms; confirm the
  saved content and chosen order survive a layout save/reload.
- Check the shared Topic default and one page override. Check contextual edit
  links and moving/removing blocks in Layout Builder.
- Check narrow phone, tablet and desktop widths: cards should form a readable
  grid, text/images should stack, and diagrams should not overflow or enlarge
  unnecessarily. Check keyboard focus and heading order.
- Edit a referenced resource title/summary and verify its cards update with
  caches warm. As an anonymous viewer, confirm unpublished/inaccessible
  resources are absent. Check anonymous file access separately.
- Check formatted text uses its configured filter, image alternative text is
  retained, and PDF/DOCX links and printing work.
- Check a Resource with a described PDF and another with a DOCX download. Their
  labels, file types and sizes must remain readable on phones and after edits;
  confirm the links open the intended files. Empty or access-denied downloads
  must leave no download label, information row or filename behind.
- Edit a caption and both metadata selections through their native forms. Check
  the saved caption, kind and level on a warm public page and after reload.
  Empty or access-denied optional fields must leave no heading, badge or
  separator behind.
- Review one complete Topic with real long titles, resources with and without
  images, a captioned diagram and all three resource groups at desktop and
  phone widths and in print. The page should have one h1 and clear h2/h3 order.
- Check card-title contrast in light/dark mode and with the selected brand
  colour. Titles use the theme's readable link colours, including hover/focus.
- Check image `srcset` selects derivatives rather than loading the original by
  default, and that opening the image still reaches the complete original.
- On BOA, run site **Verify**, confirm the original content/image still work,
  and take a fresh full site backup after acceptance.

Downloads in this first version use **public files**. They are appropriate for
public teaching material only: an unpublished node does not protect a known
public file URL. Restricted assignments/downloads need a separately designed
private-file access workflow.

## Portability and maintenance

The recipe exports definitions, forms, displays and default layouts. Actual
nodes, custom block content, uploaded files, UUID references and individual
layout overrides live in the site database/files and must be backed up or
migrated with them. GitHub and the recipe are not a content backup.

Content remains normal Drupal content when changing themes. Actools-specific
layouts and presentation depend on Actools BS5; change those layouts before
uninstalling the theme. Existing sites are not silently reconfigured by future
theme updates. Review changes to already-applied recipe configuration as site
configuration changes.

Automated coverage and its limits are described in [TESTING.md](../TESTING.md).
Authenticated production editing and BOA acceptance remain operator checks.
