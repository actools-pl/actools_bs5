# Actools resource discovery (optional)

Apply `actools_topic_starter` first, then apply this separate recipe on a test
site. It enables core Views and creates **Find resources** at `/resources`.
Installing/updating the theme does not apply either recipe automatically.

The finder lists published Actools resources that the visitor may view. Its
GET form filters titles and short summaries together, Resource kind and Study
level. It does not search full bodies, PDF/DOCX contents, equations or attachments;
it provides no relevance ranking. No search index, cron indexing, Search API or
external search service is required. Empty metadata is allowed and remains
discoverable when its filter is set to Any.

The View uses native core field handlers and a Rendered entity field with the
existing Resource card view mode. Results have a heading above the cards, a
responsive grid, 12 items per page and stable title/node-ID ordering. Core node
access SQL rewriting remains enabled for both results and pagination. No query
or search logic is added to the theme.

On a multilingual site the query uses the negotiated **content language** and
renders that result's translation. Resources without that language translation
are omitted; there is no implicit fallback to another language. A monolingual
site does not apply a language constraint. This recipe does not enable Language
or Content Translation, create languages or change negotiation settings.

This recipe creates no content, permissions, roles, menu links, block placements,
Topic relationships, aliases or front-page setting. Add the finder to an
existing menu through normal site configuration when appropriate. A Resource
can be selected in several Topics, so this recipe does not invent a Topic parent
or breadcrumb for it. Uploaded public files keep their existing public access.

Strict matching stops on a conflicting existing `views.view.actools_resources`.
The starter is deliberately not reapplied by this recipe, preserving separately
customised forms/displays. Existing starter fields and its card view mode are
prerequisites. Applying a recipe is a site-configuration operation; deleting its
directory is not an uninstall or rollback. Keep the pre-change site backup,
platform code and Composer lock file for recovery.
