# Changelog

All notable changes to **IA Webmaster Bridge** are documented in this
file. Format: [keep a changelog](https://keepachangelog.com/en/1.1.0/);
versioning follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Two components are versioned independently and called out per release
where they moved together:

- **plugin** — `plugin/ia-webmaster-bridge/` (WordPress side).
- **gateway** — `claude-plugin/mcp-gateway/` (Node.js MCP bridge).

## [Unreleased]

## [1.4.0] — 2026-05-25 — plugin 1.4.0, gateway 1.4.0

Phase 10 closure — operator polish. Resolves the three D-030
trade-offs explicitly deferred from v1.3.0 (webhook secret encryption,
admin tab UI, audit.alert event firing) and lands two new Claude Code
skills that orchestrate the v1.3.0 APIs (webhooks + revisions). Phase
9.7 — small-prod validation — remains the next deployment milestone.

### Added

- **`webhook-setup` skill** (10.1). Walks the operator from "I want
  a Slack alert when smoke tests fail" to "the receiver is
  verifying signatures" — channel choice (Slack / generic JSON /
  PagerDuty), secret generation, registration, test ping, HMAC
  verification on the receiver side.
- **`content-rollback` skill** (10.2). Restore an earlier post or
  page revision via the Phase 9.5 API. Captures the auto-created
  revision id (the symmetric-undo handle) from the restore response
  so the rollback chain is fully recoverable.
- **`prod-deployment-checklist` skill** gains an "Observability"
  section (10.3) covering smoke-failed webhook wiring,
  audit-pseudonymisation toggle, and the audit-alert rule set.
- **Webhook signing-secret encryption at rest** (10.4, D-032). New
  `IAWM_Crypto` helper (AES-256-CBC, key derived from `AUTH_KEY`,
  versioned `iawm-enc:v1:` envelope). `wp_iawm_webhooks.signing_secret`
  is encrypted on INSERT/UPDATE and decrypted lazily by the cron
  drainer. Backward-compatible: legacy plaintext rows sniff through
  the envelope check and are migrated in-place on the first call
  after the version bump.
- **Webhooks admin tab** (10.5). New tab in the plugin settings
  page lists configured webhooks (label, URL, events, enabled, last
  drain), with add/edit/delete/test/rotate-secret actions. The
  signing secret is shown ONCE at create or rotate time.
- **`audit.alert` event firing** (10.6). New WP-Cron job
  `iawm_audit_tail_watch` (every 5 minutes via the `iawm_5min`
  schedule, offset 90 s from the webhook drainer) scans new audit
  rows since a watermark and evaluates three rules:
  `scope_denied_burst` (5+ scope-denied from one key in 60 s),
  `kill_switch_toggled` (once per toggle), `auth_failure_burst`
  (10+ HMAC failures from one IP in 60 s). Each match fires
  `audit.alert` with rule id, summary, trigger audit id, window,
  details. Operator toggle in the admin Cleanup tab.

### Changed

- `IAWM_Webhook::create/update` now encrypt the supplied
  `signing_secret` before writing; `IAWM_Webhook::deliver_row()` and
  the `test_webhook` handler decrypt before computing the HMAC.
- `IAWM_Webhook` REST handlers refactored into reusable internal
  helpers so the admin tab and the MCP path share the same writes.
- `docs/operations.md` gains: receiver-verification reminder about
  the now-encrypted-at-rest secret (`AUTH_KEY` rotation =
  re-register affected webhooks), Audit-alerting section
  documenting the three rules.

### Decisions

- **D-030** updated to mark its three v1.4-deferred trade-offs as
  resolved (plaintext secret → encrypted via D-032; no admin tab →
  shipped; no `audit.alert` firing → shipped with three rules).
- **D-032** — Webhook signing-secret encryption at rest. AES-256-CBC
  with key derived from `AUTH_KEY` (single key-management surface),
  versioned `iawm-enc:v1:` envelope for forward compat, backward
  compatibility via envelope sniffing on read. Trade-offs noted:
  `AUTH_KEY` rotation invalidates stored secrets (operator
  re-registers webhooks), encryption failure returns blank rather
  than silently falling back to plaintext.

### Notes

Phase 9.7 (production validation on a small prod) remains
operator-gated. v1.4.0 ships with every code-side deliverable
complete; 9.7 is the next deployment milestone.

## [1.3.0] — 2026-05-25 — plugin 1.3.0, gateway 1.3.0

Phase 9 closure — polish + long tail. Resolves every code-side item
flagged by the v1.2.0 audit pass (testimonials carousel TODO, deeper
Divi module builders) and closes the three genuinely-open spec
questions (revisions API, audit-log pseudonymisation, webhook
signing). Phase 9.7 — small-prod validation — remains an
operator-gated deployment milestone for the next sprint.

### Added

- **Testimonials carousel variant** (9.1, D-029-era). The
  `testimonials` pattern accepts `variant: "grid" | "carousel"`
  (default `"grid"`). Carousel wraps items in a single Divi `slider`
  with one slide per testimonial; portrait composed inline with a
  `.iawm-testimonial-portrait` CSS hook so sites can override
  styling. Resolves the last `TODO` in the codebase.
- **22 typed Divi native module builders** (9.2). Cover the 33
  native modules previously exposed only in free-form via the
  registry, picked by webmaster utility:
  fullwidth-header / fullwidth-image / fullwidth-slider /
  fullwidth-map; group / group-carousel / row-inner / column-inner;
  blog / portfolio / filterable-portfolio / post-slider;
  before-after / timeline (+ timelineItem) / lottie / svg /
  countdown; sidebar / login / dropdown / signup-custom-field. Each
  ships opinionated defaults (typography, spacing, colour hooks to
  the design system). Net: builders 47 → 69.
- **10 typed WooCommerce module builders** (9.3). `wc-` prefix to
  disambiguate from post-aware builders. Cover the highest-leverage
  modules across the four Theme Builder contexts:
  wc-product-title / wc-product-price / wc-product-images /
  wc-product-add-to-cart / wc-product-description / wc-product-tabs /
  wc-related-products / wc-cart-products / wc-cart-totals /
  wc-checkout-billing. Documented in `docs/woocommerce-integration.md`.
  Net: builders 69 → 79.
- **Webhook signing for outbound notifications** (9.4, D-030).
  New `IAWM_Webhook` module with per-site tables `wp_iawm_webhooks`
  (configuration) + `wp_iawm_webhook_outbox` (queue). 5 endpoints:
  `/config/webhooks/{list,create,update,delete,test}`. HMAC-SHA256
  signing over `timestamp + "\n" + nonce + "\n" + body`, headers
  `X-IAWM-Webhook-{Timestamp,Nonce,Signature}`. Retry policy: 3
  attempts with 1m / 5m / 30m backoff, then dead-lettered. WP-Cron
  job `iawm_webhook_drain` runs every 5 minutes via a new
  `iawm_5min` schedule. Smoke-test failures auto-fire
  `smoke.failed` events. Receiver verification recipe (Node +
  Python) added to `docs/operations.md`. MCP tools:
  `iawm_webhooks_{list,create,update,delete,test}`.
- **Revisions API** (9.5). Three new routes on `IAWM_Content`:
  `/content/revisions/{list,get,restore}`. `list` returns compact
  revision records (id, author, date, title, excerpt byte size);
  `get` returns full revision content + build_mode of the current
  parent; `restore` is gated by the Phase 5.3 confirmation token
  (added to `REQUIRES_CONFIRMATION`) and leverages WordPress's
  native revision creation as the pre-op snapshot
  (`pre_op_backup_id: "revision:<id>"` — rollback is symmetric).
  MCP tools: `iawm_content_revisions_{list,get,restore}`. Resolves
  the spec 03 open question.
- **Audit log pseudonymisation** (9.6, D-031). `IAWM_Audit::write()`
  is a new public method that handlers call with a list of
  dot-notation sensitive paths (`*` wildcard supported);
  `IAWM_Audit::pseudonymise()` replaces values with
  `<redacted:sha256:abc123def456>` (12-hex short prefix —
  correlatable without leaking the value). Gated by the option
  `iawm_audit_pseudonymise` (default off for back-compat; admin
  toggle ships under the cleanup tab). Per-module
  `SENSITIVE_PARAMS` maps declare `config/users/{create,update}` →
  `password`, `config/webhooks/{create,update}` →
  `signing_secret`, etc. Legacy audit rows untouched. Resolves the
  spec 02 open question.

### Changed

- `claude-plugin/mcp-gateway/src/divi/compose.ts` `ModuleInput`
  union and `composeModule` dispatcher extended to cover the 32 new
  builders so they are reachable from `iawm_divi_page_compose` /
  `iawm_divi_theme_builder_compose`.
- `claude-plugin/mcp-gateway/src/divi/patterns/testimonials.ts`
  refactored: grid path extracted to `testimonialsGrid()`, new
  `testimonialsCarousel()` path, shared section header + section
  wrapper helpers.
- `claude-plugin/mcp-gateway/src/tools.ts` adds 8 MCP tools
  (5 webhook + 3 revision). Net: tools 100 → 108.
- `plugin/ia-webmaster-bridge/ia-webmaster-bridge.php` requires
  and inits `IAWM_Webhook`, calls its `maybe_upgrade()` inside the
  per-site installer (multisite-tolerant per D-027).
- `class-iawm-diagnostics.php` fires `smoke.failed` to the webhook
  outbox when a smoke run reports `healthy: false`.
- `docs/decisions.md` log grows by three entries (D-029, D-030,
  D-031). Counter at the README / CLAUDE.md updated.
- `docs/woocommerce-integration.md`, `docs/divi5-compose-dsl.md`,
  `docs/operations.md` updated for the new surface.

### Decisions

- **D-029** — Static gateway catalogue (vs runtime `/capabilities`
  discovery). Ratified as the long-term shape; recorded the
  rationale and trade-offs.
- **D-030** — Outbound webhook signing scheme: HMAC-SHA256 over
  `timestamp + "\n" + nonce + "\n" + body`, ±5 minute replay
  window, opt-in subscription per event, 3-attempt retry with
  dead-lettering.
- **D-031** — Audit-log pseudonymisation: opt-in,
  dot-notation-with-wildcards path scheme, irreversible
  SHA-256-prefix sentinel format. Trade-off accepted: redacted
  values cannot be recovered from the log alone (by design).

### Notes

Phase 9.7 (production validation on a small prod) remains
operator-gated: it requires Marius to pick a real client site,
walk through `docs/production-deployment.md` end-to-end, and run
the pentest checklist for real. v1.3.0 ships with every code-side
deliverable complete; 9.7 is the next deployment milestone, not a
release blocker.

## [1.2.0] — 2026-05-25 — plugin 1.2.0, gateway 1.2.0

Phase 8 closure — Yoast SEO backend, WooCommerce/Theme Builder helper,
broken-links scanner, 404 tracker, and multisite tolerance ship together.

### Added

- **Yoast SEO backend** (#33). `IAWM_Seo` now auto-detects Yoast
  alongside Rank Math and reads/writes the per-postmeta keys Yoast
  uses (`_yoast_wpseo_title`, `_yoast_wpseo_metadesc`,
  `_yoast_wpseo_focuskw`, `_yoast_wpseo_canonical`, OpenGraph + Twitter
  pairs, and the noindex/nofollow flag pair stored as separate
  `'1'` / `''` postmeta). The MCP surface (`iawm_seo_status`,
  `iawm_seo_page_get`, `iawm_seo_page_update`) is unchanged; only the
  backend dispatcher learned a new branch. (Decision D-025.)
- **WooCommerce Theme Builder helper** (`IAWM_WooCommerce`). Two
  read-only endpoints: `/woocommerce/status` (reports plugin activity,
  version, products_count, currency, shop/cart/checkout/myaccount
  page ids, and which template contexts already have a Theme Builder
  layout) and `/woocommerce/contexts` (returns the four canonical
  contexts — shop, single-product, cart, checkout — each with a
  pre-built list of `suggested_modules` and the matching Divi
  Theme-Builder `use_on` assignment expression). MCP tools:
  `iawm_woocommerce_status`, `iawm_woocommerce_contexts`. No new
  parametric pattern: the 25 Woo modules are already in the
  auto-discovered registry (D-018), so callers compose pages via
  `iawm_divi_theme_builder_compose` using the suggested module list.
  New doc: `docs/woocommerce-integration.md`.
- **Broken-links scanner** (`IAWM_LinkChecker`). New per-site table
  `wp_iawm_link_issues` (id, found_at, source_post_id, target_url,
  status_code, outcome ∈ {404, 410, timeout, dns, ssl, other},
  redirect_to, is_internal, resolved_at). Scan walks the published
  content set (posts + pages + custom post types), extracts links via
  DOMDocument with a regex fallback, deduplicates inside the scan
  and against the existing table, then probes each target with HEAD
  → GET fallback (servers that reject HEAD with 400/403/405/501 get
  retried) and a 100 ms throttle. Endpoints: `/diagnostics/links/scan`
  (infra:write, returns a scan summary), `/diagnostics/links/list`
  (filter by outcome and resolved state), `/diagnostics/links/resolve`
  (mark fixed), `/diagnostics/links/delete`. MCP tools:
  `iawm_links_scan`, `iawm_links_list`, `iawm_links_resolve`,
  `iawm_links_delete`. (Decision D-028.)
- **404 tracker** (`IAWM_FourOhFour`). New per-site table
  `wp_iawm_404_log` (id, created_at, requested_url, referer,
  user_agent, ip, hit_count, last_seen). Hooks `template_redirect`
  at priority 999 and bails on wp-admin/wp-login/wp-cron/xmlrpc/feed.
  Dedup transient `iawm_404_dedup_{sha1(url|ip)}` with a 60 s TTL
  suppresses crawler log-spam at the row-insert level; distinct IPs
  still increment `hit_count` on the existing row. Sampling rate
  configurable via `iawm_404_sampling_rate` (default 1 — record every
  hit). Endpoints: `/diagnostics/404/list` (paginated), `/stats`
  (top URLs + totals + outcome histogram), `/delete` (content:write,
  per-row), `/clear` (infra:write + confirmation token).
  Cron prune at 03:30 (offset from audit 03:00, backups 03:15).
  MCP tools: `iawm_404_list`, `iawm_404_stats`, `iawm_404_delete`,
  `iawm_404_clear`. (Decision D-026.)
- **Multisite tolerance**. Plugin install logic refactored: the agent
  WordPress user is provisioned **once globally** on the network
  (`IAWM_Agent_User::ensure_global_user`), but the `iawm_agent` role
  and the per-site tables (audit, backups, 404 log, link issues) are
  installed **per sub-site** under that sub-site's `$wpdb->prefix`.
  Network-activation walks `get_sites()` inside `switch_to_blog()`
  loops; newly-created sub-sites are provisioned automatically via
  the `wp_initialize_site` hook (legacy `wpmu_new_blog` registered
  for pre-WP-5.1). New `POST /status/network` endpoint returns
  topology (`is_multisite`, `is_main_site`, `current_blog_id`,
  `network_id`, `sites_in_network`, `sites[]` from the main site,
  `plugin_network_active`). New `IAWM_Network_Admin` module adds a
  Network Admin → Settings page listing every sub-site with its key
  count, kill-switch state, last audit row and next cron timestamp
  (paginated, 50/page, read-only). MCP tool: `iawm_status_network`.
  New doc: `docs/multisite.md`. (Decision D-027.)

### Changed

- `IAWM_REST::register_routes` registers four new namespaces:
  `/woocommerce/*`, `/diagnostics/links/*`, `/diagnostics/404/*` and
  `/status/network`. Auth rules updated: `diagnostics/links/scan` and
  `diagnostics/404/clear` require `infra:write`; `diagnostics/404/clear`
  is added to `IAWM_Confirmation::REQUIRES_CONFIRMATION`.
- Roadmap: Phase 8 marked complete except the two carry-overs kept
  intentionally open (deeper Divi module builders, webhook signing).
- Spec 06 (infrastructure): broken-links scanner and 404 tracker
  documented as part of the infrastructure plane.

### Fixed

- `IAWM_Seo` no longer rejects requests when only Yoast is active —
  previously returned `yoast_not_implemented` even though the adapter
  was implementation-ready.

### Decisions

- **D-025** — Yoast SEO unlocked as a first-class backend (postmeta
  shape mapped 1:1 with the normalized SEO payload, separate
  noindex/nofollow flags stored as `'1'` / `''` postmeta).
- **D-026** — 404 tracker uses a URL+IP dedup transient (60 s TTL) to
  suppress log-spam at the row-insert level while still counting hits
  from distinct IPs; optional sampling for very high-traffic sites.
- **D-027** — Multisite tolerance: the agent WordPress user is global,
  the agent role and the per-feature tables are per-site. New
  sub-sites get provisioned automatically via `wp_initialize_site`
  when the plugin is network-activated.
- **D-028** — Broken-links scanner scope: walks published content
  only, HEAD-first with GET fallback for HEAD-hostile servers,
  classifies WP_Error by message substring (timeout / dns / ssl /
  other), 100 ms inter-request throttle, dedup inside the scan and
  against the existing issues table.

## [1.1.0] — 2026-05-25 — plugin 1.1.0, gateway 1.1.0

Phase 6 (Webmaster layer) closure. Spec 07 is now Implemented.

### Added

- **Per-site context module** (`IAWM_Context`). Single WP option
  `iawm_site_context` stores brand voice, audience, do/don't lists,
  editorial defaults (status, language, naming, main CTA), design
  notes (palette, fonts, patterns used), infrastructure preferences
  (plugins required / forbidden, env notes), and free-form notes.
  Schema-versioned, mergeable. (Decision D-024.)
- **Endpoints**: `/site-context/get` (read), `/site-context/update`
  (config:write, dry_run supported), `/site-context/clear`
  (config:write).
- **MCP tools**: `iawm_site_context_get`, `iawm_site_context_update`,
  `iawm_site_context_clear`.
- **Admin UI**: 7th tab "Context" with sections (Brand / Content /
  Design / Infrastructure / Notes), structured form, save in one
  click.
- **Skills (3 new, 14 total)**:
  - `site-context-discovery` — bootstraps the context from
    observable signals on a fresh install (design system + plugins
    + homepage layout).
  - `site-status-report` — compiles a full health + content + audit
    + updates + SEO + cron report.
  - `scheduled-routines` — uses the cron endpoints to set up
    periodic site checks; documents the polling / mu-plugin /
    webhook patterns for actually reacting to fired hooks.

### Changed

- Spec 07 status: In progress → Implemented.
- Roadmap Phase 6 marked complete.

### Decisions

- **D-024** — Per-site context lives in a single WP option, not a
  per-operator file, so multi-operator setups share the curated
  brief automatically.

## [1.0.1] — 2026-05-25 — plugin 1.0.1, gateway 1.0.1

Patch release completing the French localisation that v1.0.0 only
partially shipped.

### Added

- Complete French translation: 271 strings (263 singular + 8 plural)
  in `languages/ia-webmaster-bridge-fr_FR.po` + compiled `.mo`.
- `tools/compile-mo.mjs` — pure-Node `.po`-to-`.mo` compiler (no
  `msgfmt` / `wp-cli` dependency, runs on Windows out of the box).
- `tools/extract-pot.mjs` now picks up `_n()` plural calls and emits
  a `Plural-Forms` header.
- `docs/operations.md` "Translation workflow" section.

### Changed

- Additional gettext wrapping in modules the 1.0.0 i18n pass missed:
  agent-user, confirmation, core, divi, plugins, seo, settings,
  themes. Total wrapped calls: ~315 across the plugin.

## [1.0.0] — 2026-05-25 — plugin 1.0.0, gateway 1.0.0

**v1.0.0 — production ready.** Closes Phase 7 of the action plan
([`docs/phase-7-action-plan.md`](docs/phase-7-action-plan.md)).

### Added

- **PHPUnit test scaffold** (Phase 7.6). 18 critical-path tests
  covering HMAC sign/verify round-trip, scope enforcement, backup
  snapshot+restore, confirmation token issue/consume/mismatch/replay
  and network CIDR matching. No full WordPress install required —
  uses in-memory stubs (`tests/wp-stub/*`).
- **Four assembly skills** (Phase 7.7):
  - `safe-plugin-update` — backup → update → smoke test → rollback.
  - `design-system-first` — read DS → write tokens → author pages.
  - `site-smoke-test` — interpret /diagnostics/smoke probes.
  - `prod-deployment-checklist` — fresh production install walkthrough.
- **Production-readiness docs** (Phase 7.8):
  - `docs/production-deployment.md` — end-to-end install runbook.
  - `docs/security-model.md` — 8-layer defence-in-depth + threat
    model + incident response.
  - `CHANGELOG.md` (this file) — keep-a-changelog format.
  - `CONTRIBUTING.md` — code style, commit conventions, security
    disclosure.
- **Pentest dry-run record** (Phase 7.9):
  `docs/pentest-2026-05-25.md` — 28 probes documented, 0 active
  vulnerabilities, 2 historical deviations fixed during the run.
- **i18n infrastructure** (Phase 7.5):
  - 185 strings wrapped with the `ia-webmaster-bridge` text domain.
  - `load_plugin_textdomain` wired on `plugins_loaded`.
  - `tools/extract-pot.mjs` — Node script generating
    `languages/ia-webmaster-bridge.pot`.
  - French translation stub.

### Changed

- Plugin version: 0.34.0 → 1.0.0 — stable contract signal.
- Gateway version: 0.23.0 → 1.0.0.
- Claude Code plugin manifest: 0.2.0 → 1.0.0.
- README rewritten with v1.0.0 status, badges, quick start,
  doc index.
- `docs/decisions.md`: D-020 (rotation policies), D-021 (smoke
  test approach), D-022 (HTTPS + IP allow-list), D-023 (i18n
  strategy).

## [0.33.0] — 2026-05-25 — plugin 0.33.0, gateway 0.23.0

### Added

- **Divi branding writer** (Phase 7.3). Site logo and favicon live in
  the `et_divi` option behind Divi's Customizer; they are not in the
  17-key theme-options allow-list. Two new endpoints:
  - `POST /divi/branding/get` — returns the branding subset of
    `et_divi`.
  - `POST /divi/branding/update` — writes a curated allow-list of keys
    (`divi_logo`, `divi_favicon`, `divi_logo_dark`,
    `divi_logo_mobile`, `divi_logo_tablet`, `divi_logo_phone`). URLs
    sanitised via `esc_url_raw`. Auto-snapshot of the full `et_divi`
    option before writing.
- Two new MCP tools: `iawm_divi_branding_get`,
  `iawm_divi_branding_update`.

### Changed

- **Plugin settings page redesigned** (Phase 7.4). Single long form
  replaced by a six-tab card layout:
  - Status bar with kill-switch state, key count, agent health, audit
    and backup counts.
  - Tab 1 API Keys: expandable rows with status dots, colour-coded
    scope badges, inline label/scope editing.
  - Tab 2 Agent: dedicated user status + reinstall button.
  - Tab 3 Security: kill switch, HTTPS state, IP allow-list editor.
  - Tab 4 Cleanup: retention sliders, next-run timestamps, "Prune now"
    buttons.
  - Tab 5 Audit log: last 30 entries with colour-coded outcomes.
  - Tab 6 Tools: doc links, danger zone visually separated.
  - Mobile responsive, vanilla CSS, no build step.

## [0.31.0] — 2026-05-25 — plugin 0.31.0, gateway 0.22.0

### Added

- **Network hardening** (Phase 7.1, `IAWM_Network`).
  - Constant `IAWM_REQUIRE_HTTPS` in `wp-config.php`. When `true`,
    non-HTTPS requests are refused with HTTP 403 `iawm_https_required`
    before any signature work.
  - Option `iawm_ip_allowlist` (admin UI). Supports CIDR and single
    IPs, IPv4 + IPv6. Empty = allow-all (back-compat). Loopback always
    permitted. `IAWM_TRUST_PROXY_HEADER` constant honours
    `X-Forwarded-For` behind a reverse proxy.
  - Both checks happen in `IAWM_Auth::guard()` before credentials
    resolution — an attacker probing from an unauthorised IP cannot
    learn which key ids exist.
- **Lifecycle hardening** (Phase 7.2).
  - Option `iawm_audit_retention_days` (default 90). Daily WP-Cron job
    `iawm_prune_audit_log` at 03:00.
  - Option `iawm_backup_keep_n` (default 50). Daily WP-Cron job
    `iawm_prune_backups` at 03:15.
  - New endpoint `/diagnostics/smoke` — HTTP probe on `home_url()`,
    debug.log fatal scan over the last 10 minutes, agent user /
    kill-switch / Divi state, plugin version summary, aggregate
    `healthy: true|false`.
  - New endpoint `/diagnostics/check-self` — verifies agent user +
    role, audit + backup tables, ≥ 1 credentials record, rotation cron
    jobs, HTTPS state.
- Two new MCP tools: `iawm_diagnostics_smoke`,
  `iawm_diagnostics_check_self`.

### Security

- HTTPS enforcement constant is intentionally placed in `wp-config.php`
  rather than the admin UI: a compromised WP admin account cannot
  silently disable it.

## [0.29.0] — 2026-05-25 — plugin 0.29.0, gateway 0.21.0

### Fixed

- `/database/search-replace` is now correctly confirmation-gated. The
  handler called `IAWM_Confirmation::guard()` but the route was missing
  from `REQUIRES_CONFIRMATION`, so non-dry-run calls applied directly.
- `/divi/theme-options/update` payload shape now matches Divi's
  upstream contract. Divi expects a single `{key, value}` per call
  with strict allow-list of 17 customizer keys. The handler now loops
  over the input map, calls Divi once per key, coerces values to
  string, collects per-key outcomes in `applied` / `rejected`. Returns
  HTTP 200 if all applied, 207 Multi-Status if some failed.

### Changed

- Documentation: design-system.md now spells out the 17-key
  theme-options allow-list and that `divi_logo` / `divi_favicon` are
  **not** in it (they require the new branding writer in 0.33).
- `docs/validation-checklist.md` carries a full-run log of the 35+
  validation checks plus a record of the two deviations and their
  fixes.

## [0.28.0] — 2026-05-25 — plugin 0.28.0, gateway 0.20.0

### Added

- **Design system writes** (D-019). The agent can now own the Divi
  design system end-to-end:
  - `POST /divi/global-data/colors/update` — replaces the `gcid-*`
    palette (full-replace semantics).
  - `POST /divi/global-data/fonts/update` — sets heading and/or body
    font (two-field merge).
  - `POST /divi/global-data/variables/update` — replaces the typed
    `gvid-*` variable map (6 buckets).
  - `POST /divi/theme-options/get` / `update` — wraps Divi's
    `outside-vb/theme-options/*` with merge semantics.
- `/divi/global-data` (read) now also returns `global_fonts`
  alongside `global_colors` and `global_variables`.
- Five new MCP tools (`iawm_divi_global_colors_update`,
  `iawm_divi_global_fonts_update`,
  `iawm_divi_global_variables_update`, `iawm_divi_theme_options_get`,
  `iawm_divi_theme_options_update`).
- New `docs/design-system.md` documenting the four-step authoring
  workflow.

## [0.27.0] — 2026-05-25 — plugin 0.27.0, gateway 0.18.0

### Added

- **Multi-key support** (D-017, Phase 5.4). Credentials storage
  refactored to a map keyed by `key_id`. Each record carries label,
  secret, scopes, optional `linked_user_id` (WP user, audit-only
  attribution), `created_at`, `last_used_at`. Zero-downtime rotation
  becomes natural: create new, switch the gateway, revoke the old.
- Admin UI rebuilt around a table of keys: per-row scope editing,
  rotate secret, revoke. Separate "Create a new key" form. Danger-zone
  "Revoke ALL".
- Audit log entries gain `key_label` and `linked_user_id`.
- **Cron module** (`IAWM_Cron`, Phase 4). Five endpoints —
  `/cron/list`, `/cron/schedules`, `/cron/run`, `/cron/schedule`,
  `/cron/unschedule`. Wraps WP-Cron without exposing a shell.
- New `docs/operations.md` runbook (key rotation, multi-operator
  setup, safe-update workflow, SSH/WP-CLI fallback, pentest
  checklist).

### Changed

- `IAWM_Auth` resolves credentials by `X-IAWM-Key` header (was:
  compared against the single stored record).
- Legacy single-record installs are transparently migrated on first
  read; the existing key keeps working with a synthetic
  `"Legacy key"` label.

## [0.26.0] — 2026-05-25

### Added

- **Auto-generated Divi module registry** (D-018). Hand-curated list
  of 48 modules replaced by 105 auto-discovered from the Divi install
  itself.
  - `tools/scan-divi-modules.mjs` walks
    `visual-builder/packages/module-library/src/components/`, parses
    each `module.json` + `module-default-render-attributes.json`, and
    writes three artefacts:
    - `docs/divi5-modules-registry.json` — structured registry.
    - `docs/divi5-modules-catalog.md` — human catalog.
    - `claude-plugin/mcp-gateway/src/divi/modules-registry.ts` —
      `DiviBlock` enum + `DIVI_MODULES` runtime array.
- Two new MCP tools: `iawm_divi_modules_catalog`,
  `iawm_divi_module_info`.

### Fixed

- `divi/post-navigation` was a typo for the real block name
  `divi/post-nav`, breaking any `postNavigation()` builder call.

## [0.25.0] — 2026-05-25 — plugin 0.25.0, gateway 0.17.0

### Added

- **Database tools** (Phase 4, `IAWM_Database`). Four narrow endpoints:
  - `/database/info` — tables, sizes, row counts.
  - `/database/export` — SQL dump into a backup record.
  - `/database/query` — SELECT-only with strict validation (no `;`,
    no `INTO OUTFILE`, no `LOAD_FILE`, no `BENCHMARK`, no `SLEEP()`,
    forced `LIMIT` cap of 200).
  - `/database/search-replace` — serialisation-safe walker against an
    explicit (table, column) allow-list. Mandatory
    dry-run-then-confirm flow.

### Security

- Confirmation token now gates `/database/search-replace` in addition
  to `/backup/restore` and `/core/update`.

## [0.24.0] — 2026-05-25

### Added

- **Confirmation tokens** (Phase 5.3, `IAWM_Confirmation`, D-015).
  Two-step pattern for the most destructive endpoints. First
  non-dry-run call returns HTTP 202 + `requires_confirmation: true` +
  single-use 64-hex token (5-min TTL, body-bound). Re-issue with the
  token applies. Tokens stored as sha256 in transients — the raw
  token never hits the DB.
- Currently gating: `/backup/restore`, `/core/update`,
  `/database/search-replace`.

## [0.23.0] — 2026-05-25

### Added

- **WordPress core update** (Phase 4, `IAWM_Core`). `/core/info`
  (read) + `/core/update` (infra:write) with PHP version pre-flight,
  `dry_run` preview, plugin-state snapshot, confirmation token
  mandatory for the real apply.

## [0.22.0] — 2026-05-25 — plugin 0.22.0, gateway 0.16.0

### Added

- **Single-plugin update** via `/plugins/update`. Mirrors the
  `/themes/update` path. Refuses to self-update the bridge plugin
  (HTTP 403 `cannot_self_update`). Forces `wp_update_plugins()` to
  refresh the transient before deciding. Snapshots plugin activation
  state pre-op; surfaces `pre_op_backup_id`. `skip_backup: true` opts
  out for retries.
- New MCP tool: `iawm_plugins_update`.

## [0.21.0] — 2026-05-25 — plugin 0.21.0, gateway 0.15.0

### Added

- **Themes module** (Phase 4). Five endpoints, all WP.org-only and
  strict-slug-validated:
  - `/themes/info`, `/themes/list`, `/themes/install`,
    `/themes/activate`, `/themes/update`.
- Automatic pre-op `options` snapshot of theme-related options
  (`template`, `stylesheet`, `current_theme`, `theme_switched`,
  `theme_mods_{slug}`) for both the active and the target theme.
- Five new MCP tools (`iawm_themes_info` / `list` / `install` /
  `activate` / `update`).

### Decisions

- Theme deletion intentionally not exposed (matches the
  `IAWM_Plugins` policy).

## [0.20.0] — 2026-05-25 — plugin 0.20.0, gateway 0.14.0

### Added

- **Backup module** (`IAWM_Backup`, Phase 5.2, D-013/D-014). Three
  snapshot kinds: `options` (JSON map of WP option values),
  `plugins_state` (active + recently-activated + installed list),
  `tables` (raw SQL dump of named tables).
- New `wp_iawm_backups` table.
- Six REST routes under `/backup/*` (list / get / create / restore /
  delete / prune). `read` for list/get, `infra:write` for the rest.
- Restore supports `dry_run` so the operator previews the diff.
- **Automatic pre-op snapshots** wired into `plugins/install`,
  `plugins/activate`, `plugins/deactivate` and risky settings
  updates. Surfaced as `pre_op_backup_id`. `skip_backup: true` opts
  out per-request.
- Six new MCP tools under `registerBackup()`
  (`iawm_backup_list/get/create/restore/delete/prune`).

### Changed

- `IAWM_Auth::required_scope` now uses the permission callback
  (`guard_read` vs `guard_write`) to pick read vs write-by-prefix
  rather than the HTTP method.

## [0.19.0] — 2026-05-25

### Added

- **Dedicated agent WordPress user** (`iawm-agent`, role
  `iawm_agent`, Phase 5.1, D-011). Created on plugin activation.
  Administrator-like minus `unfiltered_html`, `unfiltered_upload`,
  `edit_files`, `edit_plugins`, `edit_themes`, multisite super-admin.
  The application refuses any API attempt to modify or delete this
  user. WP audit records now attribute every write to the agent, not
  to a human admin.
- **Per-key scopes** (D-012): `read`, `content:write`, `divi:write`,
  `config:write`, `infra:write`. Required scope derived from the
  route. Miss returns HTTP 403 `iawm_scope_denied`. Check happens
  after HMAC verification so scope info never leaks to
  unauthenticated callers.
- Admin UI: pick scopes when generating a key, retighten an existing
  key's scopes without rotating the secret, reinstall the agent
  role/user.

### Security

- Backward compatibility: legacy keys without an explicit scope list
  remain fully-scoped; existing installs aren't broken on upgrade.

## [0.18.x] — 2026-05-24

### Changed

- **Full i18n pass on the codebase** (commits `829868d`, `a6b8f53`).
  All documentation, code comments, error messages and MCP tool
  descriptions translated to English so the project is usable
  worldwide. The maintainer's personal preferences stay in the
  gitignored `CLAUDE.local.md`.
- 4 skills renamed (`audit-wordpress-site`,
  `create-wordpress-page`, `create-divi-page`,
  `frontend-design-wordpress`).
- 4 specs renamed (`01-adapter`, `02-security`, `03-content`,
  `07-webmaster-layer`).
- `docs/glossaire.md` → `docs/glossary.md`.
- New `language` parameter (BCP-47) on content-generation tools
  (`iawm_content_create`, `iawm_content_update`,
  `iawm_divi_page_compose`, `iawm_divi_theme_builder_compose`) to
  hint the agent on which language to write site content in.

### Added

- Repo cleanup pass (commits `e264800`, `6933914`): removed hardcoded
  personal paths and nominal references, generic public `CLAUDE.md`
  with maintainer preferences split into the gitignored
  `CLAUDE.local.md`.

## [0.18.3] and earlier

- **Phase 3 — Divi 5 plane** (commits `4fcf34a` … `1b2022d`).
  Unified declarative `iawm_divi_page_compose` (pattern / free-form /
  block). Theme Builder endpoints + `setup-site-defaults` wrapper.
  Bit-faithful round-trip writes. 41 native modules, 13 parametric
  patterns. Library + design system reads. Full E2E test passed
  (header + footer + 10-section homepage + Rank Math SEO generated
  as draft from a brief).
- **Phase 2 — content + configuration plane** (commit `d3dfba3` and
  earlier).
  Pages, posts, media, menus, taxonomies, site settings, users,
  diagnostics. Rank Math SEO. Kill switch. Dry-run + draft-by-default
  guardrails. `wp_slash` round-trip bug fixed.
- **Phase 1 — basic connection** (commit `58d178e`). Minimal plugin
  with REST namespace + HMAC signature + audit log. Local MCP
  gateway connected to Claude Code.

[Unreleased]: https://github.com/RiusmaX/ia-webmaster-bridge/compare/v0.34.0...HEAD
[0.34.0]: https://github.com/RiusmaX/ia-webmaster-bridge/compare/v0.33.0...v0.34.0
[0.33.0]: https://github.com/RiusmaX/ia-webmaster-bridge/compare/v0.31.0...v0.33.0
[0.31.0]: https://github.com/RiusmaX/ia-webmaster-bridge/compare/v0.29.0...v0.31.0
[0.29.0]: https://github.com/RiusmaX/ia-webmaster-bridge/compare/v0.28.0...v0.29.0
[0.28.0]: https://github.com/RiusmaX/ia-webmaster-bridge/compare/v0.27.0...v0.28.0
[0.27.0]: https://github.com/RiusmaX/ia-webmaster-bridge/compare/v0.26.0...v0.27.0
[0.26.0]: https://github.com/RiusmaX/ia-webmaster-bridge/compare/v0.25.0...v0.26.0
[0.25.0]: https://github.com/RiusmaX/ia-webmaster-bridge/compare/v0.24.0...v0.25.0
[0.24.0]: https://github.com/RiusmaX/ia-webmaster-bridge/compare/v0.23.0...v0.24.0
[0.23.0]: https://github.com/RiusmaX/ia-webmaster-bridge/compare/v0.22.0...v0.23.0
[0.22.0]: https://github.com/RiusmaX/ia-webmaster-bridge/compare/v0.21.0...v0.22.0
[0.21.0]: https://github.com/RiusmaX/ia-webmaster-bridge/compare/v0.20.0...v0.21.0
[0.20.0]: https://github.com/RiusmaX/ia-webmaster-bridge/compare/v0.19.0...v0.20.0
[0.19.0]: https://github.com/RiusmaX/ia-webmaster-bridge/compare/v0.18.3...v0.19.0
