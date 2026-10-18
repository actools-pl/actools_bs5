# Resource card

A small presentation component for an existing Drupal Resource node: linked `h3`
title, optional image and summary. It does not create, load or filter entities.

## Render contract

- `heading`: a plain title string, automatically escaped.
- `url`: the node's generated relative or absolute URL; the existing Link atom
  applies its URL allowlist.
- `title_attributes`: optional Drupal `Attribute`, preserving heading attributes.
- `media` and `body` slots: ordinary Drupal field render arrays. Do not extract
  raw formatted text, image paths or downloadable-file URLs in Twig.

`node--actools-resource--actools-resource-card.html.twig` maps the recipe's
`actools_resource_card` view mode to this component. It keeps the node wrapper,
contextual links, node attributes and all other displayed fields. The optional
recipe hides full text and downloads in this card view; those remain on the full
resource page.

`field--field-actools-resources.html.twig` provides a semantic list and attaches
`actools_bs5/resource-card-grid`. Its CSS is separate from the SDC's automatically
attached card CSS, so Topic references and resource-cards blocks use the same
one/two/three-column grid. Named CSS container queries select two columns at
40rem of available field width and three at 64rem. A narrow Layout Builder column
stays a single column even on a wide screen; browsers without container-query
support also retain one column. No JavaScript is required.

Images retain their natural ratio and are not stretched to fill a card. Card
headings have visible keyboard focus; print uses a simple vertical list.

Use on a Topic or another page that already supplies its `h1` and section `h2`
headings. This is a first implementation; check real content, image access and
Layout Builder editing on a separate test site before live deployment.
