# Header, navigation and optional resource discovery

The standalone page shell renders the Header region through a compact, wrapping
layout. Place Drupal's Site branding and Main navigation blocks in that region.
Branding links to Drupal's configured front page, including its language prefix.
Menu labels, ordering, destinations and access remain Drupal configuration.
The theme does not create Topics, Assignments or About links automatically.

## Existing installations

1. Update the theme code and clear Drupal's caches normally.
2. In the theme's Containers settings, select the same container for Header and
   Content (normally **Container**) to align their margins. Existing saved
   settings are retained; the new default applies only to fresh installations.
3. In Block layout, confirm Site branding and Main navigation are in Header.
   Keep the menu block's meaningful administrative label: it names the
   navigation landmark even when the visible block heading is disabled.
4. In the menu block configuration, choose the depth and expansion settings for
   the branches visitors should be able to open. The theme can only disclose
   descendants Drupal actually loads. For a short teaching menu, expand all
   links and use the required depth; existing settings are not overwritten.
5. Edit links through Drupal's menu interface. Parent landing pages remain
   ordinary links; a separate, labelled button opens their children. Section
   names that are not destinations should use Drupal's native non-link option.

At narrow widths, the visible **Menu** control opens a scrolling, non-modal
panel. Submenu buttons expose branches independently. Escape closes the
relevant branch or panel and returns focus to its control. Changing viewport
width resets visibility and keeps focus reachable. Before successful JavaScript
initialization, the navigation is a visible nested list with no inactive toggle
controls. The footer displays its menu as an ordinary visible nested list.

The theme uses Drupal's exact-page link handling and active-trail information;
it does not infer the current page by comparing raw URL strings. Main, footer
and account navigation should have distinct descriptive block labels.

## Sticky header

The existing **Sticky header** theme setting remains authoritative. CSS provides
sticky positioning; JavaScript measures changing header/admin geometry for
scroll clearance and the scrolled shadow. The header does not register itself
as another Drupal admin displacement element. Sticky mode can be disabled
without removing navigation functionality. Canvas preview and print exceptions
remain in place. The header can grow for larger fonts and branding instead of
forcing a fixed height or hiding while reading.

## Optional resource finder

The separate `recipes/actools_resource_discovery` package uses the existing
Topic starter's Resource fields. Apply it only when those fields are present
and its `/resources` path and View identifier do not conflict with site
configuration. Updating the theme alone does not apply either recipe or add
menu links. See that recipe's README for prerequisites and installation.

The finder searches **titles and summaries**, with Resource kind and Study level
filters. It is not an indexed search of full lessons, diagrams or attachments.
Only add a Search/Find resources menu entry after verifying that the configured
finder destination works for anonymous visitors. A site's existing working
search route can be used instead.

## Breadcrumbs and future content decisions

Use Drupal's Breadcrumbs block where its genuine trail is meaningful. Resources
can currently be referenced by several Topics; there is no primary Topic field.
This change does not invent a parent or alter the content model. A canonical
Topic relationship, syllabus hierarchy and editorial navigation can be decided
separately when the teaching content structure is agreed.

## Acceptance on a test copy

Check branding and real menus at 320, 390, 768, 1024 and 1440px, near the collapse
breakpoint, and with larger text. Exercise parent links, deep branches, keyboard
focus, Escape, duplicate header/footer menu instances, sticky on/off, long-page
hash links, browser history, no JavaScript, reduced motion and print. Review a
closed/open phone menu and a desktop header visually. Check the actual enabled
administration interface and Layout Builder dialogs as an authenticated editor.

When discovery is enabled, also check combined filters, clear/reset, empty
results, pagination, shareable URLs and publication/access/language changes over
warm caches. Keep existing Topic/card/caption/image/download acceptance intact.
No live installation or BOA Verify is implied by repository tests.
