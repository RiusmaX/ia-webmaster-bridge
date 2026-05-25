# Roadmap

> Status: Phases 0-6 complete; Phase 7 (production hardening) in progress, v1.0.0 target imminent · Last updated: 2026-05-25

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
- [x] SSH/WP-CLI fallback channel documented in `docs/operations.md`
- [x] Cron capabilities via `/cron/list`, `/cron/schedules`, `/cron/run`, `/cron/schedule`, `/cron/unschedule`

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
- [x] **5.4** — Multi-key support: each operator gets their own labelled key, scopes and optional linked WP user (audit only). Zero-downtime rotation via co-existing keys
- [x] Kill switch (Phase 1)
- [x] Key rotation procedure documented (`docs/operations.md`)
- [x] Penetration test checklist documented (`docs/operations.md`); execution still owed before next public release

## Phase 6 — Webmaster layer *(complete — v1.1.0)*

- [x] Base Claude Code skills (webmaster, create page, audit)
- [x] Packaged as a Claude Code plugin + open-source release (GitHub, GPL-3.0)
- [x] `frontend-design-wordpress` skill (hierarchy, typography, colors, spacing, mobile-first)
- [x] `marketing-conversion-wordpress` skill (AIDA/PAS/FAB, social proof, CTA hierarchy)
- [x] `seo-wordpress` skill (SEO audit, Rank Math, semantic structure)
- [x] `create-divi-page` skill (prompt → full Divi page workflow)
- [x] Normalized SEO API with Rank Math backend
- [ ] Yoast SEO backend as an alternative (#33 — deferred to Phase 8)
- [x] **Per-site context module** (`IAWM_Context`, `/site-context/*`, admin "Context" tab) — v1.1.0
- [x] 4 workflow skills: `safe-plugin-update`, `design-system-first`, `site-smoke-test`, `prod-deployment-checklist` — v1.0.0
- [x] `site-context-discovery` skill (bootstraps the context from observable signals) — v1.1.0
- [x] `site-status-report` skill (compiled health + content + audit + updates report) — v1.1.0
- [x] `scheduled-routines` skill (programs periodic checks via the cron module) — v1.1.0
- [ ] Broken-links scanner — deferred to Phase 8

## Phase 7 — Production hardening (in progress, target v1.0.0)

Master tracker: [`phase-7-action-plan.md`](phase-7-action-plan.md).

- [x] **7.1** — Network hardening: HTTPS enforcement via
  `IAWM_REQUIRE_HTTPS` constant, IP allow-list option
  (`iawm_ip_allowlist`) supporting CIDR + single IPs, IPv4 + IPv6;
  both checks happen before credentials resolution so probes from
  unauthorised IPs leak no key information
- [x] **7.2** — Lifecycle hardening: daily WP-Cron jobs
  `iawm_prune_audit_log` (default 90 days) and `iawm_prune_backups`
  (default 50 records); `/diagnostics/smoke` endpoint (HTTP probe +
  debug.log fatal scan + state checks); `/diagnostics/check-self`
  endpoint (install invariants)
- [x] **7.3** — Divi branding writer: `/divi/branding/get` and
  `/divi/branding/update` cover the `et_divi` keys not in the
  17-key customizer allow-list (`divi_logo`, `divi_favicon`, dark /
  mobile / tablet / phone variants)
- [x] **7.4** — Plugin settings page redesign: six-tab card layout
  with status bar, kill-switch toggle, IP allow-list editor,
  retention sliders, audit viewer, smoke-test buttons; danger zone
  visually separated; mobile responsive
- [ ] **7.5** — i18n plugin admin: wrap user-visible strings in
  `__()`, generate `.pot`, ship `fr_FR` translation
- [ ] **7.6** — PHPUnit tests on critical paths (auth, scopes,
  backups, confirmation tokens, self-protection)
- [ ] **7.7** — Skills assemblies: `safe-plugin-update`,
  `design-system-first`, `site-smoke-test`,
  `prod-deployment-checklist`
- [x] **7.8** — Doc pass (this pass): README + new
  `production-deployment.md`, `security-model.md`, `CHANGELOG.md`,
  `CONTRIBUTING.md`; refresh of `CLAUDE.md`, `operations.md`,
  `decisions.md` (D-020 through D-023), this roadmap
- [ ] **7.9** — Pentest dry-run against the local site
- [ ] **7.10** — Final integration + **v1.0.0** tag + GitHub release

## Phase 8 — Backlog after v1.0.0

Nice-to-haves to ship under the 1.x line. None of these block v1.0.0;
all are tracked here so they don't fall through the cracks.

- [ ] **Yoast SEO backend** as an alternative to Rank Math (#33).
  Adapter mapping is already in place, only the toggle + tests
  remain.
- [ ] **WordPress multisite** support. Today the plugin assumes a
  single-site install; the dedicated agent user lives on the main
  site only, the audit table is per-site, and the IP allow-list is
  network-shared at best. A clean multisite pass means deciding
  per-site vs network-wide for each of those.
- [ ] **WooCommerce reference page**. The 25 Woo modules are already
  in the auto-discovered registry (D-018) but no parametric pattern
  composes a "complete WooCommerce product page" yet. One reference
  pattern (single-product layout + cart + cross-sell) would unlock
  e-commerce site generation in one prompt.
- [ ] **Deeper Divi modules**: build typed builders + opinionated
  defaults for the long tail of native modules currently only
  exposed through the auto-discovered registry (the 105-element
  catalog) — today 41 have explicit builders, the rest are
  free-form-only.
- [ ] **Per-site context file** (Phase 6 carry-over). A
  `iawm-context.md` checked in on the operator side that records
  brand vocabulary, off-limits content, preferred patterns.
- [ ] **Scheduled routines** (Phase 6 carry-over). The agent could
  run a weekly "site health" routine without operator prompting,
  posting the report to a configured channel.
- [ ] **Webhook signing** for outbound notifications (smoke test
  failure → Slack, audit alert → email).

## Deployment milestones

- [x] Stable on the local site (validated end-to-end 2026-05-25)
- [ ] Validated on a small prod
- [ ] Validated on a large prod
