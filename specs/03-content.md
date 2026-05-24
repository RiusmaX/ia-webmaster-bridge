# Spec 03 — Content plan

- **Status**: Draft
- **Phase**: 2
- **Priority**: High
- **Last updated**: 2026-05-21

## Goal

Enable Claude to manage the site's editorial content: pages, posts, media,
menus, taxonomies, and content as Gutenberg blocks.

## Scope

### Included
- CRUD of pages and posts (and exposed custom post types).
- Media library management (upload, metadata, alt text).
- Navigation menus.
- Taxonomies (categories, tags, custom taxonomies).
- Generation of valid **Gutenberg block** content.
- Bulk operations (e.g. grouped update).

### Excluded (for now)
- Divi 5 layout generation → spec `04-divi5.md`.
- Site-wide settings → spec `05-configuration.md`.

## Technical approach

- Capabilities exposed by the plugin under `ia-webmaster/v1`, grouped under
  "content". Unless a custom need arises, they rely on native WordPress
  functions and the `wp/v2` REST API on the server side.
- **Gutenberg blocks**: a page's content is block markup in
  `post_content`. Never write it by hand as a string: build a block tree and
  serialise it via `serialize_blocks()` (PHP) on the plugin side. Invalid
  markup triggers "block recovery" in the editor.
- **Media**: upload via a dedicated capability; always fill in the alt text
  and the title (quality + accessibility + SEO).
- **Menus**: handle classic menus and block-based navigation
  (`wp_navigation`) depending on the theme.
- **Guardrails** (spec 02): draft by default, dry-run available,
  explicit publication.

## Open questions

- A Divi 5 page is not managed like a Gutenberg block page: the boundary
  between this spec and spec 04 must be clear (detect the page's build mode
  before any write).
- Should there be a "high-level content" abstraction layer (titles,
  paragraphs, images) that the agent manipulates, then translated to blocks?
- Revisions management: expose history and restoration?
- Custom post types: automatic discovery or explicit declaration?

## Dependencies & risks

- Depends on specs 01 (adapter) and 02 (security).
- Risk: writing Gutenberg content into a Divi-built page (or the other way
  around) would corrupt the page → detecting the page mode is critical.
