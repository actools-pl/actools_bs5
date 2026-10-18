# Text and image

A fielded introduction for a Layout Builder custom block. The content appears
first in the document and in narrow blocks. When the block has at least 40rem of
available width, `position` selects the image's left or right column. Named CSS
container queries use the actual Layout Builder column width, not the viewport;
browsers without container-query support retain the readable single column. Images retain their natural
ratio, and the text column has a readable maximum width.

## Render contract

- `heading`: an escaped plain string rendered as `h2`; omit it when another
  heading already labels the block.
- `position`: `left` or `right`; unrecognised values fall back to `right`.
- `has_media`: a real boolean, set only after the image field passes access and
  emptiness checks and is present in the rendered display.
- `title_attributes`: optional Drupal `Attribute` for the heading.
- `body` and `media` slots: field render arrays. Drupal retains responsibility
  for formatted-text filtering, image alt text, cache metadata and attachments.

The integration is the core-supported outer block template
`block--block-content--type--actools-text-image.html.twig`. Drupal does not supply
a separately themed `block_content` entity inside this wrapper. Theme preprocess
sets the access-checked scalar heading and position values, and the image flag.
The wrapper retains block attributes, contextual links, title prefix/suffix,
content attributes and additional displayed fields.

The block's dedicated Heading field supplies its visible title. A configured
outer block label appears only when that field cannot supply a heading; this
avoids duplicate headings in Layout Builder. Position is an editing control,
not a separate visible field.

The component's matching CSS file is attached by SDC. No JavaScript, Sass rebuild
or permissive HTML text format is required. Do not render fields into strings
just to determine whether an image exists.
