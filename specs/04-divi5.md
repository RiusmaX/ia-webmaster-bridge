# Spec 04 — Divi 5 plan (priority)

- **Status**: Implemented (Phase 3.1 through 3.6 complete; unified composer shipped; full module registry — 105 modules, native + WooCommerce — autonomously scanned; design system writes — global colors, fonts, variables, theme options — shipped)
- **Phase**: 3
- **Priority**: Top
- **Last updated**: 2026-05-25

## Goal

Enable Claude to **read, create and modify Divi 5 layouts**: the core
value of the project, since the majority of targeted sites run on Divi 5
(decision D-003).

## Context

Divi 5 (released February 26, 2026) rewrote its architecture: React builder,
content stored in **JSON / serialised blocks format** (close to the Gutenberg
format), hierarchical multi-breakpoint and multi-state attributes. Divi 4
layouts are migrated to this format.

**Acknowledged difficulty**: there is **no documented public API** to
generate a complete Divi 5 layout programmatically. Available resources:
- the "portability" import/export `.json` format (official, but constrained
  by the export context);
- the new Divi 5 Builder API (oriented towards custom modules — scope to be
  explored);
- unofficial community documentation (to be verified, never assumed
  accurate).

**Full mapping of `divi/v1` (2026-05-23)** — 102 unique routes, 29
groups. Detailed map in `docs/divi5-api-index.md` (raw source:
`docs/divi5-api-map.json`). The most structuring routes for our usage:

- **`page-manager/`** (9 routes) — create, update, duplicate, trash, search, show.
  High-level way to manage Divi pages without going through the VB.
- **`sync-to-server`** — POST that the VB uses to persist content:
  `post_id`, `content`, `pageSettingsByLayout`, `off_canvas_data`,
  `layout_post_ids`, `mainLoopType`, `mainLoopSettingsData`. **This is the
  canonical write channel for Divi 5.**
- **`outside-vb/posts/set-layout`** — applies a layout (by content or by
  source post) to a target post, without the VB.
- **`outside-vb/export-layout`** — exports the layout of a post (read).
- **`portability/export`** and **`portability/import`** — official JSON
  format, used for layout migration.
- **`divi-library/`** (12 routes) — Divi library: list, item, load,
  create-item, save, split-item, convert-item, upload-image, item-location;
  **`cloud-token`** for Divi Cloud.
- **`global-data/`** (4 routes) — global colors, fonts, variables,
  presets (design system). **Critical** to drive site-wide style.
- **`module-render`** — HTML rendering of a module from its JSON
  (programmatic preview).
- **`dynamic-content/options`** — dynamic content options available
  for a post (title, excerpt, custom fields…).
- **`breakpoints/update`** — responsive settings (mobile, tablet,
  desktop, custom).
- **`outside-vb/theme-builder/*`** (5 routes) — header/footer templates,
  custom templates.
- **`menu-manager/`** (8 routes) — Divi menu manager (distinct from
  the standard WP menu system).
- **`module-data/`** (21 routes) — per-module utility endpoints
  (gallery, video, audio, blog/posts, breadcrumbs, sidebar, shortcode…).
- **`loop/`** (5 routes) — query types for dynamic loops.
- **`ai_layout_save_defaults`** — Divi already has its own "AI layout
  defaults" (fonts, primary/secondary colors, site description).

All these routes are **protected by the Visual Builder nonce**: a pure
server-to-server call (without an admin cookie) gets a 401/403. Three
ways to exploit them:
1. **Internal PHP call** from our plugin (`rest_do_request` after
   `act_as_agent`): we inherit admin rights and avoid the nonce.
2. **Direct call to Divi's PHP functions**: some routes are only a thin
   wrapper around a reusable function (to be confirmed case by case).
3. **Work at the storage level** (`post_content`, post meta) without going
   through Divi's routes — rawer but more stable. To be preferred for
   reading; to be avoided for writing (triggers/cache).

Consequence: the first building block remains **reverse engineering the
actual format** on the local site (creating a reference page in the VB,
extracting `post_content` and meta), to anchor everything else.

## Scope

### Included
- Reading an existing Divi 5 layout (structure, modules, settings).
- Programmatic Divi 5 layout generation.
- Modification of existing layouts.
- Generation library on the Claude side (`lib/divi/`).

### Excluded (for now)
- Divi 4 (sites still on Divi 4 — to handle later if needed).
- Elementor (postponed, decision D-003).
- Creation of custom Divi modules (Builder API) — outside initial scope.

## Technical approach (iterative)

1. **Observe the actual format.** On the local site: create several reference
   layouts in the Divi 5 builder (simple section, columns, common modules),
   then extract and analyse their exact storage (`post_content` and/or post
   meta). This is the source of truth — not the community documentation.
2. **Read before writing.** A read capability that returns a Divi 5 layout
   in a structure usable by Claude.
3. **Round-trip.** Verify that a layout read and then rewritten as-is
   remains identical and opens without error in the builder. Reliability
   criterion.
4. **Incremental generation.** Build `lib/divi/`: helpers that produce
   valid Divi 5 JSON from a high-level intent (section → rows → columns →
   modules). Start with a reduced subset of modules, broaden gradually.
5. **Controlled import.** Plugin capability that applies a generated layout
   to a page, respecting the context constraints of the "portability" format.

## Open questions

Most original questions are answered (see `docs/divi5-format.md`,
`docs/divi5-modules-catalog.md`, `docs/divi5-compose-dsl.md`, and
decisions D-018 + D-019). Remaining items:

- **Divi cache/assets purge after a write** (analogous to Elementor's
  CSS cache). Not currently called from our handlers. Worth verifying
  on a production site whether `/divi/page/write` requires a Divi
  cache rebuild for the changes to render correctly outside the
  builder iframe — if yes, the plugin should invoke Divi's purge
  routine after every write. P1 to verify under prod traffic.
- **Multi-breakpoint / multi-state attribute model**: read/write
  round-trip is bit-faithful, but no high-level helper yet for "set
  this module's mobile variant to X". The full multi-state map lives
  in `docs/divi5-format.md`; opinionated helpers can be added on
  demand.
- **Deeper Divi module builders** (Phase 9 + long tail). **79
  builders typed today** (47 → 79 in Phase 9: 22 native + 10
  WooCommerce). About 26 modules remain in free-form-only via the
  auto-discovered registry — split between ~11 natives (of which
  most are child-only modules or fullwidth variants) and ~16
  WooCommerce. Decision D-018 documents the registry; the next
  prioritisation pass is the small "shop + 4 checkout" lot if a
  target site needs a 100 % typed checkout template.
- **Third-party Divi modules** (Divi Supreme, etc.). The
  auto-discovery scanner picks them up if they register cleanly, but
  no opinionated patterns are shipped. Out of scope until a target
  site demands it.

Settled (removed from this list): testimonials carousel variant —
**shipped in v1.3.0 (Phase 9.1)**, the `testimonials` pattern now
accepts `variant: "grid" | "carousel"`.

Settled (removed from the list): native storage shape — confirmed
post_content + Divi 5 module markup, no dedicated table; "portability"
format — declined, we write the native storage directly; Builder API
entry point — not needed, REST + post update suffices.

## Dependencies & risks

- Depends on specs 01 (adapter) and 02 (security).
- **Main risk**: undocumented format, liable to evolve between minor
  versions of Divi 5 → hence direct observation, pinning the tested
  Divi version, and round-trip testing as a guardrail.
- Risk: an invalid generated layout can break a page's display → everything
  goes through draft and dry-run (spec 02) before publication.
