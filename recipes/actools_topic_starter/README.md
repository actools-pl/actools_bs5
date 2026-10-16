# Actools topic starter (optional recipe)

This recipe adds native Drupal fields and display configuration for two reusable
Layout Builder blocks and a Topic/Resource page pattern. Installing or updating
the theme does not apply it automatically. Apply it separately through Drupal's
recipe workflow on a test site before using it on an existing site. The tested
core target is Drupal 11.4.10; the accompanying theme version is also required.

The recipe enables the required core modules and installs the Actools BS5 theme
if needed. It does not select the default or administration theme, change the
front page, grant permissions, create content, or alter existing content types.
Keep the site's existing text formats and use a format that your editor already
has permission to use. No new text format or HTML permission is granted.

## New editing forms

| Type | Fields | Use |
| --- | --- | --- |
| **Actools: Text and image** block | Heading, formatted Body, optional Image and plain-text Caption, Image position | Topic introduction or explanation; image on the left or right on wide screens. |
| **Actools: Resource cards** block | Heading, one or more references to Resources | A reusable selection of existing teaching resources. |
| **Actools resource** content | Title, optional Summary, Resource kind and Study level, formatted Body, Image, Download | A lesson, assignment or teaching resource with its own page. |
| **Actools topic** content | Title, required formatted Body, optional references to Resources | A consistent introduction followed by resource cards. |

Resource fields only reference existing **Actools resource** nodes. Card titles
and destinations come from those nodes, so an editor does not maintain copied
links or titles. Visitors see the resources that Drupal's entity and field access
checks permit them to view. Reference order is card order. Cards display the
resource title, optional kind/level, image and summary; they do not embed the full Body or
Download field. The resource's own full page displays those fields.

Captions remain plain Drupal field render arrays and are shown only with a
displayed image. Number a figure within the caption when useful; numbering is
not generated automatically. Resource kind and study level are optional native
select fields. Empty or denied values leave no metadata badge or separator.
The fields identify resources without changing reference order or access.

Images are optional. When an image is provided, its alternative-text field is
required. Full Resource pages and text/image blocks use core's Responsive Image
formatter with the new `actools_diagram` responsive style. The browser selects
from 480, 960, 1440 and 1920-pixel-wide derivatives; the image fallback is capped
at 960 pixels. All derivatives scale proportionally without cropping or enlarging
small uploads. Clicking the displayed image opens the original, full-resolution
file, so detailed diagram labels remain available without downloading the
original on every page view.

Images use native lazy loading. The `sizes` attribute begins with `auto`, allowing
supporting browsers to account for the image's actual Layout Builder column
width. A contained-page viewport estimate supports older browsers; those
browsers can choose a larger derivative in a narrow column. Core supplies the
image's alternative text, dimensions, derivative URLs and cache dependencies.

Resource-card thumbnails retain the independent `actools_resource_card` style
(fit within 960 by 960 pixels, without cropping or enlargement). The separate
`actools_editor_preview` style fits editing previews within 150 by 150 pixels.
No existing image style is edited. Both block types and both node types create
new revisions by default.

## Public uploads

Images and downloads use Drupal's **public** file system. Downloads accept PDF
and DOCX only, up to 20 MB per file. These are public teaching resources: a direct
file URL can remain accessible even when its parent content is unpublished or
unavailable. Do not upload private student records, restricted assessments,
confidential documents or other material requiring access control. This recipe
does not provide private-file delivery.

## Topic page pattern

The Topic type has Layout Builder enabled for its default display. Its initial
layout is **Actools: One column (contained)**, with the Body field first and
Resources second. The theme supplies the resource-card presentation. The page's
normal page-title block supplies the full-page title; author/date information is
turned off for both new content types.

Each Topic permits an individual layout override. A site administrator who has
appropriate existing layout and custom-block permissions can open its **Layout**
tab, use **Add block -> Create custom block**, select **Actools: Text and image**
or **Actools: Resource cards**, fill in the fields, and save the layout. Blocks
created there belong to that page layout. Reusable blocks may instead be created
in Drupal's Content blocks area and selected in Layout Builder.

Use the shared content-type layout for a consistent page pattern. Enable an
individual override only for a page that needs a deliberate exception: existing
overrides do not automatically inherit later changes to the default layout.

## Configuration scope and conflicts

All supplied bundle names, field names, the view mode and the image style use the
`actools_` prefix. The recipe uses `config.strict: true`: if a supplied
configuration name already exists with different values, Drupal must report a
conflict instead of replacing the site's configuration. Resolve a conflict by
reviewing the existing configuration; do not disable strict matching or force an
import. A recipe is initial feature configuration, not an update or uninstall
migration for a previously customised site.

Core Layout Builder creates its standard `layout_builder__layout` storage and a
field instance on **Actools topic** when per-page overrides are enabled. This
recipe uses core's `allowLayoutOverrides` configuration action for that step;
merely importing an `allow_custom` flag does not create the field on a new site.
The core-managed field intentionally keeps its standard name; it is not a field on
any pre-existing content type. No pre-existing layout is changed.

The feature adds configuration only. No sample nodes, blocks, files, permissions,
menu links or front-page selection are supplied. Review a full site backup and
the Composer platform code before applying the recipe to an existing site.
