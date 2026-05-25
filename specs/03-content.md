# Spec 03 — Content plan

- **Status**: Implemented
- **Phase**: 2 (revisions API added in Phase 9.5)
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

### Revisions API (Phase 9.5)

Three routes expose WordPress's native revision history so the agent
can audit and roll back content changes without leaving the bridge:

- `POST /content/revisions/list` (`read`) — paginated list of
  revisions for a given `post_id`. Each entry carries the revision
  id, author, GMT timestamp, title, a 200-char text excerpt and the
  byte size of `post_content`. Build mode is irrelevant — revisions
  snapshot `post_content` regardless of Gutenberg vs Divi.
- `POST /content/revisions/get` (`read`) — full content of one
  revision (title, `post_content`, excerpt, author, GMT date) plus
  the parent post's current status and detected `build_mode`. The
  build mode is reported off the *current parent*, not the revision
  itself, so the caller knows which write path a restore would land
  on.
- `POST /content/revisions/restore` (`content:write`) — restores a
  revision onto its parent. Gated by the **two-step confirmation
  token** (added to `IAWM_Confirmation::REQUIRES_CONFIRMATION`): the
  first call returns a token and a `changes` summary
  (`title_before` / `title_after`, `bytes_before` / `bytes_after`);
  the second call applies. `dry_run=true` is an alternative
  non-mutating preview. WordPress itself inserts a fresh revision
  capturing the pre-restore state of the parent during
  `wp_restore_post_revision()`; its id is surfaced as
  `pre_op_backup_id` in the form `revision:<id>` so rollback is one
  more `revisions/restore` call.

Out of scope for v1.3.0: attachment revisions, autosave entries, and
a dedicated diff endpoint (the caller can fetch two revisions and
diff client-side).

## Multi-language

The content language is independent of the codebase language (which is
English — see `CLAUDE.md`). Callers pass an optional `language`
parameter (BCP-47, e.g. `fr-FR`, `en-US`, `pt-BR`) on the
content-generation skills/tools. The plugin stores the produced
content as-is in `post_content`; **no WPML/Polylang integration** is
shipped — translation plugins remain the operator's choice and live
outside the API surface.

## Open questions

- ~~**Revisions management** — expose history and restoration as a
  first-class capability? Today the agent can read the current state
  but cannot restore yesterday's version of a post.~~ **Resolved in
  Phase 9.5** — see "Revisions API" above. The three new routes
  (`/content/revisions/list`, `/get`, `/restore`) cover history
  enumeration and one-call rollback, with a confirmation-token gate
  on the restore and a native pre-restore revision surfaced as
  `pre_op_backup_id`.
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
