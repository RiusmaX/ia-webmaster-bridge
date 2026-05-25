# Spec 03 — Content plan

- **Status**: Implemented
- **Phase**: 2
- **Priority**: High
- **Last updated**: 2026-05-25

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

## Implemented (Phase 2 to date)

- **Content**: `IAWM_Content` (`/content/list`, `/get`, `/create`,
  `/update`). Validation goes through `serialize_blocks()` server-side
  so Gutenberg trees stay schema-correct. Build-mode detection
  (Gutenberg vs Divi) happens before any write: a Divi page is routed
  to `/divi/page/write` instead. Status defaults to `draft`; publishing
  is explicit.
- **Media**: `IAWM_Media` (`/media/list`, `/get`, `/sideload`,
  `/update`). `sideload` accepts a remote URL plus alt/title/caption so
  every upload carries accessibility metadata from creation.
- **Menus**: `IAWM_Menu` (six routes: `list`, `get`, `create`,
  `add_item`, `remove_item`, `assign_location`). Classic menus only;
  block-based navigation (`wp_navigation`) reuses the content routes.
- **Taxonomies**: `IAWM_Taxonomy` (`/list`, `/create`, `/assign`).

## Multi-language

The content language is independent of the codebase language (which is
English — see `CLAUDE.md`). Callers pass an optional `language`
parameter (BCP-47, e.g. `fr-FR`, `en-US`, `pt-BR`) on the
content-generation skills/tools. The plugin stores the produced
content as-is in `post_content`; **no WPML/Polylang integration** is
shipped — translation plugins remain the operator's choice and live
outside the API surface.

## Open questions

- **Revisions management** — expose history and restoration as a
  first-class capability? Today the agent can read the current state
  but cannot restore yesterday's version of a post. Listed in the
  Phase 9 backlog (P1).
- **Custom post types discovery** — implicit today via the `type`
  parameter on `/content/list`; a dedicated `/content/types` endpoint
  would let the agent enumerate available CPTs on a fresh install
  (P2).
- **Bulk operations** — no dedicated bulk endpoint; the agent loops
  client-side. Acceptable today, may revisit if a use case demands it.
- **High-level content abstraction** (titles/paragraphs/images
  decoupled from blocks) — declined: the abstraction lives in the
  gateway-side patterns (`src/divi/patterns/`) for Divi, and Gutenberg
  blocks are already a sufficient lingua franca for the rest.

## Dependencies & risks

- Depends on specs 01 (adapter) and 02 (security).
- Risk: writing Gutenberg content into a Divi-built page (or the other way
  around) would corrupt the page → build-mode detection on
  `/divi/page/read` is the safeguard.
