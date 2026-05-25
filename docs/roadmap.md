# Roadmap

> Status: Phases 0-3 complete; Phase 4 (plugins/themes/core/database) shipped; Phase 5.1 + 5.2 + 5.3 shipped · Last updated: 2026-05-25

Phased action plan. Boxes reflect real progress. Security
(`specs/02-security.md`) is cross-cutting: built in from Phase 1 and hardened
continuously, with Phase 5 being the formal hardening pass.

## Phase 0 — Scoping & environment

- [x] Research (WordPress 7.0, AI ecosystem, Divi 5)
- [x] Architecture validated (3 components, in-house adapter)
- [x] Project context, memory and specs written
- [x] Local WordPress 7.0 site installed under LocalWP
- [x] Local site access provided to Claude
- [x] Divi 5 installed and active on the local site (Divi 5.5.2)
- [x] Repo structure initialized (`plugin/`, `mcp-gateway/`)

## Phase 1 — Basic connection

- [x] Minimal plugin: REST namespace, test capability (`ping`, read-only)
- [x] Authentication: API key + HMAC signature
- [x] Minimal local MCP bridge connected to Claude Code
- [x] End-to-end validation: 3 MCP tools tested (real content read → Phase 2)
- [x] Audit log in place from this phase

## Phase 2 — Content & configuration plane

- [x] Content capabilities: CRUD pages/posts, media, menus, taxonomies
- [x] Generation of valid Gutenberg blocks (normalization in content/create)
- [x] Configuration capabilities: site settings, users (theme options → Phase 3)
- [x] Diagnostics capabilities & log access (debug.log, Site Health, plugins)
- [x] Safeguards: dry-run mode, draft before publication, kill switch

## Phase 3 — Divi 5 plane *(complete)*

- [x] **3.1** — Full mapping of the `divi/v1` API (102 routes documented in `docs/divi5-api-index.md`)
- [x] **3.1** — Reverse-engineering of the Divi 5 format (3 reference pages populated: id 19, 29, 53)
- [x] **3.1** — Exhaustive catalog of the ~99 native modules (`docs/divi5-modules-catalog.md`)
- [x] **3.2** — Endpoint `/divi/page/read` (tree-based read: tree / flat / raw)
- [x] **3.2** — Endpoint `/divi/page/write` (content + blocks write)
- [x] **3.2** — Bit-faithful round-trip (`wp_slash` bug fixed)
- [x] **3.3** — Endpoints `/divi/library/list` + `/library/item` + `/library/local`
- [x] **3.3** — Endpoints `/divi/cloud/status` + `/divi/global-data` (design system)
- [x] **3.3** — Divi Cloud hybrid workflow documented (Save to Library on the builder side)
- [x] **3.4** — `lib/divi/` library (builders + 11 parameterizable patterns)
- [x] **3.4** — `creer-page-divi-wordpress` skill (prompt → page workflow)
- [x] **3.5** — 41 modules implemented (advanced, structural, dynamic modules)
- [x] **3.5** — 13 patterns: hero, features3col, ctaBanner, imageTextSplit, testimonials, faqAccordion, numbersBar, videoSection, contactSection, pricing3col, teamGrid, headerSimple, footerStandard
- [x] **3.6** — Theme Builder: `/divi/theme-builder/*` endpoints (8 routes)
- [x] **3.6** — `setup-site-defaults` wrapper (header + footer + default template in 1 call)
- [x] **3.6** — Divi override logic decoded (`enabled=true + id=0` for native rendering)
- [x] **Real E2E test**: full homepage (header + footer + 10 sections + Rank Math SEO) generated as draft on the test site from a brief — validation of the prompt → site workflow

## Phase 4 — Infrastructure plane

- [x] Plugin capabilities (install, activate, deactivate) — advanced in Phase 3 to integrate Rank Math
- [x] Theme capabilities: `/themes/info`, `/themes/list`, `/themes/install`, `/themes/activate`, `/themes/update`. WP.org-only source, slug validation, auto-snapshot of theme-related options before any write
- [x] Plugin updates via `/plugins/update`. Self-update of the bridge plugin refused. Auto-snapshot of plugin state pre-op
- [x] WordPress core update via `/core/info` + `/core/update`. PHP version pre-flight, plugin-state snapshot pre-op, dry_run preview, confirmation token mandatory for the real apply
- [x] Controlled database capabilities: `/database/info` (read), `/database/export` (SQL dump as a backup record), `/database/query` (SELECT-only with strict validation + row cap), `/database/search-replace` (serialization-safe, allow-listed (table,column) pairs, mandatory dry_run + confirmation token)
- [x] Backups and restore — shipped in Phase 5.2 (`/backup/*`)
- [ ] SSH/WP-CLI fallback channel documented
- [ ] Cron capabilities (read/program scheduled tasks)

## Phase 5 — Security & safeguards (hardening)

- [x] **5.1** — Dedicated WordPress user (`iawm-agent`, role `iawm_agent`) created on plugin activation; writes no longer borrow the first admin's identity
- [x] **5.1** — Restricted role: administrator-like minus `unfiltered_html`, `unfiltered_upload`, `edit_files`, `edit_plugins`, `edit_themes`, multisite caps
- [x] **5.1** — Per-key scopes (`read`, `content:write`, `divi:write`, `config:write`, `infra:write`) enforced by the auth guard; HTTP 403 `iawm_scope_denied` on miss
- [x] **5.1** — Admin UI: pick scopes when generating a key, retighten an existing key without rotating the secret, reinstall agent role/user
- [x] **5.1** — Agent's own user is protected from modification or deletion by the API
- [x] **5.2** — `IAWM_Backup` module: snapshot/restore for WP options, plugin activation state and SQL tables; `wp_iawm_backups` table + `/backup/*` routes (list/get/create/restore/delete/prune)
- [x] **5.2** — Automatic pre-op snapshot before `plugins/install`, `plugins/activate`, `plugins/deactivate` and risky settings updates (e.g. `permalink_structure`); `pre_op_backup_id` surfaced in the response
- [x] **5.2** — Restore supports `dry_run` so the operator previews the diff before applying
- [x] **5.3** — Confirmation token gate for the most destructive endpoints (`/backup/restore`, `/core/update`, `/database/search-replace`). Two-step: first call returns `requires_confirmation` + a single-use token (TTL 5 min, bound to route + key + body hash); re-issue with the token to apply
- [x] Kill switch (Phase 1)
- [ ] Key rotation policy (documented procedure)
- [ ] Light penetration test of the plugin

## Phase 6 — Webmaster layer

- [x] Base Claude Code skills (webmaster, create page, audit)
- [x] Packaged as a Claude Code plugin + open-source release (GitHub, GPL-3.0)
- [x] `design-frontend-wordpress` skill (hierarchy, typography, colors, spacing, mobile-first)
- [x] `marketing-conversion-wordpress` skill (AIDA/PAS/FAB, social proof, CTA hierarchy)
- [x] `seo-wordpress` skill (SEO audit, Rank Math, semantic structure)
- [x] `creer-page-divi-wordpress` skill (prompt → full Divi page workflow)
- [x] Normalized SEO API with Rank Math backend
- [ ] Yoast SEO backend as an alternative (#33)
- [ ] Per-site context file
- [ ] Documented webmaster workflows
- [ ] Possible scheduled routines

## Deployment milestones

- [ ] Stable on the local site
- [ ] Validated on a small prod
- [ ] Validated on a large prod
