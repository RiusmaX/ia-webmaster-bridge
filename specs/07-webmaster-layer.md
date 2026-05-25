# Spec 07 — Webmaster layer (skills & workflows)

- **Status**: Implemented (11 skills shipped; per-site context module + 3 workflow skills + scheduled-routines workflow shipped in v1.1.0)
- **Phase**: 6
- **Priority**: Medium
- **Last updated**: 2026-05-25

## Goal

Turn a set of technical capabilities into genuine **webmaster know-how**:
give Claude working methods, per-site context, and ready-to-use workflows.

## Scope

### Included
- **Claude Code skills**: reusable workflows (create a landing page,
  SEO audit, safe plugin updates, etc.).
- **Per-site context file**: describes the site (brand guidelines,
  conventions, sitemap, key plugins, Divi specifics).
- Verification procedures (review own work, smoke test after operation).

### Excluded (for now)
- The technical capabilities themselves → specs 03 to 06.
- Scheduled routines (possible, to be evaluated once the base is stable).

## Technical approach

- The capabilities (specs 03–06) are **low-level building blocks**. This
  layer assembles them into **high-level procedures** that Claude follows
  as an experienced webmaster would.
- **Skills**: each recurring workflow becomes a documented skill — steps,
  guardrails, success criteria. `skills/` folder.
- **Per-site context**: each target has a context file (brand guidelines,
  editorial tone, page structure, naming conventions, installed plugins).
  Claude reads it before acting on that site.
- **Verification loop**: every workflow ends with a review of the
  result (the agent verifies its own work) and, if relevant, a smoke test.

## Expected workflows — current status

| Workflow | Skill | Status |
|---|---|---|
| Create a Divi 5 landing page from a brief | `create-divi-page` | ✅ Shipped |
| Audit the SEO of a set of pages and propose fixes | `seo-wordpress` | ✅ Shipped |
| Update plugins safely (backup → update → smoke test → report) | `safe-plugin-update` | ✅ Shipped |
| Detect and fix broken links | — | ⏳ Phase 8 backlog |
| Produce a site status report (content, performance, to-do) | `site-status-report` | ✅ Shipped |
| Configure the brand design system, then author pages | `design-system-first` | ✅ Shipped |
| Site health smoke test post-op | `site-smoke-test` | ✅ Shipped |
| Walk a fresh production install | `prod-deployment-checklist` | ✅ Shipped |
| Bootstrap the per-site context | `site-context-discovery` | ✅ Shipped (v1.1.0) |
| Set up scheduled routines | `scheduled-routines` | ✅ Shipped (v1.1.0) |

## Per-site context (shipped v1.1.0)

The `IAWM_Context` module stores site-specific knowledge in the
`iawm_site_context` WP option — single source of truth that every
operator and every Claude session sees. Schema (versioned, mergeable):

- **brand**: name, tagline, voice, audience, do_list, dont_list.
- **content**: default_status, default_language, page_naming_convention,
  homepage_pattern, main_cta { label, url }.
- **design**: palette_summary, fonts_summary, patterns_used.
- **infrastructure**: plugins_required, plugins_forbidden,
  environment_note.
- **notes**: free-form markdown.

Endpoints: `/site-context/get`, `/site-context/update` (`config:write`),
`/site-context/clear` (`config:write`). Admin UI: a 7th tab "Context"
in the settings page lets the operator edit the structure with
sections and textareas. MCP tools: `iawm_site_context_get`,
`iawm_site_context_update`, `iawm_site_context_clear`.

Reading this context is the FIRST step every workflow skill performs.

## Open questions

- Format of the per-site context file (a Markdown? a dedicated skill?).
- Which workflows to prioritise once Phases 1–5 are stable?
- Are scheduled routines needed (weekly audit, etc.)? To be evaluated.
- How to capitalise: should a successful workflow on a site enrich a
  pool of methods shared across all sites?

## Dependencies & risks

- Depends on all other specs: this is the final assembly layer.
- Risk: over-industrialising too early. This layer only makes sense
  once the basic capabilities are reliable — hence its position in Phase 6.
