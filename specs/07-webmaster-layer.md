# Spec 07 — Webmaster layer (skills & workflows)

- **Status**: Implemented (17 skills shipped; per-site context module since v1.1.0; broken-links-audit workflow added in v1.2.0; webhook-setup + content-rollback added in v1.4.0)
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

17 skills in v1.4.0:

| Workflow | Skill | Status |
|---|---|---|
| Webmaster method (when to use what) | `webmaster-wordpress` | ✅ Shipped |
| Create a standard WordPress page | `create-wordpress-page` | ✅ Shipped |
| Create a Divi 5 landing page from a brief | `create-divi-page` | ✅ Shipped |
| Front-end design fundamentals on WP | `frontend-design-wordpress` | ✅ Shipped |
| Marketing & conversion patterns | `marketing-conversion-wordpress` | ✅ Shipped |
| Audit the SEO of a set of pages and propose fixes | `seo-wordpress` | ✅ Shipped |
| Site audit (content + infra + SEO) | `audit-wordpress-site` | ✅ Shipped |
| Update plugins safely (backup → update → smoke test → report) | `safe-plugin-update` | ✅ Shipped (v1.0.0) |
| Configure the brand design system, then author pages | `design-system-first` | ✅ Shipped (v1.0.0) |
| Site health smoke test post-op | `site-smoke-test` | ✅ Shipped (v1.0.0) |
| Walk a fresh production install | `prod-deployment-checklist` | ✅ Shipped (v1.0.0) |
| Bootstrap the per-site context | `site-context-discovery` | ✅ Shipped (v1.1.0) |
| Produce a site status report (content, performance, to-do) | `site-status-report` | ✅ Shipped (v1.1.0) |
| Set up scheduled routines | `scheduled-routines` | ✅ Shipped (v1.1.0) |
| Detect and fix broken links + audit 404 traffic | `broken-links-audit` | ✅ Shipped (v1.2.0) |
| Register and verify an outbound webhook (HMAC-signed) | `webhook-setup` | ✅ Shipped (v1.4.0) |
| Restore an earlier post revision with confirmation token | `content-rollback` | ✅ Shipped (v1.4.0) |

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

- **Cross-site capitalisation**: should a successful workflow on a site
  enrich a pool of methods shared across all sites? Today every site
  has its own `IAWM_Context` and there's no fan-out. Listed as P2
  nice-to-have — the simplest first step is probably exporting the
  context option as a portable file the operator can seed elsewhere.
- ~~Format of the per-site context file (Markdown? dedicated skill?)~~
  → tranched by D-024 (single WP option, schema-versioned).
- ~~Scheduled routines~~ → shipped via the `scheduled-routines` skill
  + the cron module (v1.1.0).

## Dependencies & risks

- Depends on all other specs: this is the final assembly layer.
- Risk: over-industrialising too early. This layer only makes sense
  once the basic capabilities are reliable — hence its position in Phase 6.
