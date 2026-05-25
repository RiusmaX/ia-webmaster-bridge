# Spec 06 — Infrastructure plan

- **Status**: Implemented (plugins, themes, core update, DB tools, cron, backups, smoke test, link checker, 404 tracker, WooCommerce helper, multisite-tolerant; ops runbook)
- **Phase**: 4 + Phase 8 add-ons
- **Priority**: Medium
- **Last updated**: 2026-05-25

## Goal

Enable Claude to carry out the site's infrastructure operations:
plugins, themes, database, backups, scheduled tasks, updates.

## Scope

### Included
- Plugins: installation, activation/deactivation, update.
- Themes: installation, activation, update.
- Database: export, controlled queries, `search-replace`.
- Backups and restoration.
- Scheduled tasks (WordPress cron).
- WordPress core update.

### Excluded (for now)
- System administration beyond WordPress (server configuration, DNS, etc.).
- Modification of `wp-config.php` beyond settings explicitly exposed.

## Technical approach

- In line with decision **D-006**, these operations go through
  **controlled plugin endpoints**, not through a shell open to the agent.
- The plugin, running in PHP with WordPress rights, can perform most of
  these operations (plugin install, options, cron). For what goes beyond
  PHP, the plugin may wrap precise, validated WP-CLI calls (never an
  arbitrary command).
- **Backup SSH/WP-CLI channel**: reserved for the human operator, or for
  situations where the plugin is unavailable (e.g. plugin itself broken).
  Documented, not exposed to the agent by default.
- **All these operations are classified "at risk"**: prior backup,
  explicit confirmation, dry-run where possible (spec 02).
- Plugin/theme sources: restrict to trusted sources (official repository,
  validated archives) — no install from an arbitrary URL.

## Implemented (Phase 4 to date)

- **Plugins**: install / activate / deactivate / **update** / info, via
  `IAWM_Plugins`. WP.org-only source; the bridge plugin itself cannot
  be deactivated or self-updated via the API.
- **Themes**: install / activate / update / info / list, via
  `IAWM_Themes`. WP.org-only source; strict slug validation; deletion
  intentionally not exposed.
- **WordPress core**: `/core/info` + `/core/update`, via `IAWM_Core`.
  PHP version pre-flight, plugin-state snapshot pre-op, dry_run preview,
  confirmation token gate (Phase 5.3) for the real apply.
- **Database**:
  - `/database/info`: tables + sizes (read-only).
  - `/database/export`: SQL dump of named tables into a backup record.
  - `/database/query`: SELECT-only, validated (no `;`, no `INTO OUTFILE`,
    no `BENCHMARK`/`SLEEP()`/`LOAD_FILE`, forced LIMIT cap).
  - `/database/search-replace`: serialization-safe walker against an
    explicit allow-list of (table, column) pairs; mandatory `dry_run`
    + confirmation token for a real apply.
- **Backups**: snapshot + restore, via `IAWM_Backup` (`options`,
  `plugins_state`, `tables` kinds). Auto-triggered before
  `plugins/install`, `plugins/activate`, `plugins/deactivate`,
  `plugins/update`, `themes/install`, `themes/activate`, `themes/update`,
  `core/update` and risky settings updates. Restore supports `dry_run`
  and is gated by the Phase 5.3 confirmation token.
- **Site hygiene smoke test** (Phase 7.2): `/diagnostics/smoke` runs a
  composite HTTP probe (homepage + admin reachability + fatal-error
  scan in `debug.log` + WP-Cron state) and returns `healthy: true|false`
  with per-probe detail. `/diagnostics/check-self` validates the plugin's
  install invariants (tables present, role installed, agent user
  reachable). The two answer the open question "how does the agent
  verify a site is healthy after an operation" — every safe-update
  workflow ends on a `/diagnostics/smoke` call.

## Phase 8 add-ons (shipped v1.2.0)

- **Broken-links scanner** (`IAWM_LinkChecker`, decision D-028).
  Per-site table `wp_iawm_link_issues`. Four routes:
  `/diagnostics/links/scan` (walks published posts/pages/CPTs,
  HEAD-then-GET probe with a 100 ms throttle, in-scan + table dedup,
  classifies failures into `404 / 410 / timeout / dns / ssl / other`),
  `/diagnostics/links/list`, `/diagnostics/links/resolve`,
  `/diagnostics/links/delete`. Scope: published content only; drafts,
  revisions, attachments and comment links are excluded. The scanner
  is one of the building blocks of the `broken-links-audit` workflow
  skill.
- **404 tracker** (`IAWM_FourOhFour`, decision D-026). Per-site table
  `wp_iawm_404_log` with a `(url, IP)` transient dedup at insert time
  (60 s TTL) that keeps the table bounded under scanner load while
  still counting hits from distinct IPs on the same URL. Optional
  sampling denominator for very high-traffic sites. Four routes:
  `/diagnostics/404/{list,stats,delete,clear}`. `clear` is gated by
  the confirmation token. Daily prune via WP-Cron at 03:30, offset
  from the audit prune (03:00) and the backup prune (03:15).
- **WooCommerce Theme Builder helper** (`IAWM_WooCommerce`). Read-only
  helpers: `/woocommerce/status` reports plugin activity, version,
  product count, currency, key page ids (shop/cart/checkout/myaccount)
  and which template contexts already have a Theme Builder layout;
  `/woocommerce/contexts` returns the four canonical contexts
  (shop, single-product, cart, checkout), each with a suggested
  module list and the matching Theme Builder `use_on` assignment
  expression. No new parametric pattern — the 25 Woo modules in the
  auto-discovered Divi registry (D-018) compose cleanly via
  `iawm_divi_theme_builder_compose`. Doc: `docs/woocommerce-integration.md`.
- **Multisite tolerance** (decision D-027). Network-activation walks
  every sub-site under `switch_to_blog()` and installs the per-site
  pieces (role, tables) under that sub-site's `$wpdb->prefix`; the
  dedicated agent WordPress user is provisioned once globally for the
  network. New sub-sites are auto-provisioned via `wp_initialize_site`
  (`wpmu_new_blog` registered as a legacy fallback). New endpoint
  `POST /status/network` exposes topology; new admin module
  `IAWM_Network_Admin` adds a Network Admin → Settings page listing
  every sub-site with key count, kill-switch state, last audit row
  and next cron timestamp. Doc: `docs/multisite.md`.

## Open questions

- **Filesystem-level backup** (uploads, plugins on disk, themes on
  disk): out of scope (D-013). The snapshot-of-state approach covers
  every operation we expose through the API; a true filesystem backup
  is the operator's job (off-site, scheduled, outside the agent's
  blast radius).
- **WP-CLI wrappers**: never landed. Every infrastructure operation is
  served by native PHP / WP APIs, which is the cleaner path. The
  reference to "wrapping precise WP-CLI calls" in the original spec
  is now obsolete; the SSH/WP-CLI fallback is the **human operator's**
  channel, documented in `docs/operations.md`.
- **Pre-production environment** for testing updates before
  production: still no in-plugin tooling for it. The
  `prod-deployment-checklist` skill documents the manual rehearsal
  flow; out of scope to automate further until a clear use case
  emerges.

## Dependencies & risks

- Depends on specs 01 (adapter) and 02 (security).
- **High risk**: this plan contains the most destructive operations in the
  project (updates, database). Non-negotiable guardrails; production
  rollout only after strong stability on local.
- Risk: an update breaks the site → prior backup + restoration procedure
  tested before any production use.
