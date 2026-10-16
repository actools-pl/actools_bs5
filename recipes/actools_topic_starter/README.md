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
| **Actools: Text and image** block | Heading, formatted Body, optional Image, Image position | Topic introduction or explanation; image on the left or right on wide screens. |
| **Actools: Resource cards** block | Heading, one or more references to Resources | A reusable selection of existing teaching resources. |
| **Actools resource** content | Title, optional Summary, formatted Body, Image, Download | A lesson, assignment or teaching resource with its own page. |
| **Actools topic** content | Title, required formatted Body, optional references to Resources | A consistent introduction followed by resource cards. |

Resource fields only reference existing **Actools resource** nodes. Card titles
and destinations come from those nodes, so an editor does not maintain copied
links or titles. Visitors see the resources that Drupal's entity and field access
checks permit them to view. Reference order is card order. Cards display the
resource title, optional image and summary; they do not embed the full Body or
Download field. The resource's own full page displays those fields.

Images are optional. When an image is provided, its alternative-text field is
required. Resource-card thumbnails use the new, independently named
`actools_resource_card` image style (fit within 960 by 960 pixels, with no
cropping or enlargement of smaller images). This preserves diagram labels. The separate `actools_editor_preview` style fits image previews within 150 by
150 pixels, also without cropping or enlargement. No existing image style is
edited. Both block types and both node types create new revisions by
default.

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
core-managed field intentionally keeps its standard name; it is not a field on
any pre-existing content type. No pre-existing layout is changed.

The feature adds configuration only. No sample nodes, blocks, files, permissions,
menu links or front-page selection are supplied. Review a full site backup and
the Composer platform code before applying the recipe to an existing site.
