# Phase 7 — Production hardening · action plan

> Status: **Closed** · Shipped as v1.0.0 on 2026-05-25 · Last updated: 2026-05-25
>
> Goal: take the project from "Phases 0–6 implementé, validable en
> interne" to "publishable open source + déployable en prod par un tiers
> sans réserve". Tag the result as **v1.0.0**.
>
> Historical record kept for reference. The 10 sub-phases below all
> shipped; subsequent phases follow the same structure
> ([Phase 9 action plan](phase-9-action-plan.md)).

## Why this phase

Phases 0–6 delivered every functional capability the project promised:
content, Divi 5, configuration, infrastructure, security (HMAC + scopes
+ agent user + backups + confirmation tokens + multi-key), webmaster
skills. The validation run on 2026-05-25 surfaced two production-blocking
bugs that were fixed on the spot (search-replace gate, theme-options
payload shape), proving that the **last 5%** of polish — the
production-hardening pass — is where real-world risk concentrates.

Phase 7 systematically closes that gap.

## Ten sub-phases

| Sub-phase | Theme | Effort | Status |
|---|---|---|---|
| 7.1 | Network hardening (HTTPS, IP allow-list) | ~2h | pending |
| 7.2 | Lifecycle hardening (rotation, smoke test, self-check) | ~2.5h | pending |
| 7.3 | Divi branding writer (logo/favicon + et_divi) | ~1h | pending |
| 7.4 | Plugin settings page redesign | ~2h | pending |
| 7.5 | i18n plugin admin (gettext + .pot + fr_FR) | ~1.5h | pending |
| 7.6 | PHPUnit tests on critical paths | ~3h | pending |
| 7.7 | Skills assemblies (safe-update, design-system-first, smoke-test, prod-deploy) | ~1.5h | pending |
| 7.8 | Doc pass (README + new prod-deploy + security-model + CHANGELOG + CONTRIBUTING) | ~2.5h | pending |
| 7.9 | Pentest dry-run against the local site | ~1.5h | pending |
| 7.10 | Final integration + **v1.0.0** tag + GitHub release | ~1h | pending |
| **Total** | | **~18h** | |

Ordering rationale: 7.1 → 7.5 each touch the plugin or admin UI and are
independent. 7.6 (tests) goes AFTER the code is final to lock the
contracts. 7.7 (skills) needs the endpoints to be final. 7.8 (doc) goes
near the end so everything written reflects the final shape. 7.9
(pentest) and 7.10 (release) are last.

---

## 7.1 — Network hardening

### What

Close the two most obvious network-level weaknesses that a real
production exposure would amplify.

### Tasks

- [ ] **HTTPS enforcement**:
  - Plugin: read constant `IAWM_REQUIRE_HTTPS` (default `false`).
    When true, `IAWM_Auth::guard()` refuses any request whose
    `$_SERVER['HTTPS']` is empty / off. Returns HTTP 403
    `iawm_https_required`.
  - Gateway: at startup, log a `WARN` to stderr if `baseUrl` starts
    with `http://` and the host is not `localhost` /
    `*.local` / `127.0.0.1`.
- [ ] **IP allow-list**:
  - New option `iawm_ip_allowlist` (array of CIDR or plain IPs).
    Empty / unset = allow-all (today's behaviour).
  - `IAWM_Auth::guard()` checks the caller IP **before** HMAC. Miss
    returns HTTP 403 `iawm_ip_not_allowed` with no detail leakage.
  - Admin UI: textarea (one entry per line) with format help.
    Validation: parse + validate each entry, refuse invalid lines.
  - Audit log entries gain `caller_ip` already; verify still works.
- [ ] **Kill-switch logging**: every blocked attempt while
  `iawm_kill_switch=true` logs to audit with `outcome=denied,
  error=iawm_kill_switch`. (Today they probably do — verify and
  document.)

### Decisions to lock

- IP allow-list format: support CIDR (`192.168.1.0/24`) AND single
  IPs (`192.168.1.10`) AND IPv6.
- HTTPS enforcement: hard constant (operator must set in
  `wp-config.php`) rather than admin UI toggle — harder to
  accidentally disable.

### Acceptance

- Setting `IAWM_REQUIRE_HTTPS=true` then hitting via HTTP returns 403.
- Adding `192.168.99.0/24` to the allow-list then hitting from
  `127.0.0.1` returns 403.
- Adding `127.0.0.1` to the allow-list then hitting from `127.0.0.1`
  passes.
- Audit log records both denials.

---

## 7.2 — Lifecycle hardening

### What

Operations that grow indefinitely (audit log, backups) and operations
that need a "did it work?" check (smoke test) are missing today.

### Tasks

- [ ] **Audit log rotation**:
  - New option `iawm_audit_retention_days` (default 90).
  - WP-Cron job `iawm_prune_audit_log` runs daily at 03:00.
  - Deletes rows older than N days.
  - Registered on plugin activation, scheduled via
    `wp_schedule_event`.
- [ ] **Backup rotation**:
  - New option `iawm_backup_keep_n` (default 50).
  - WP-Cron job `iawm_prune_backups` runs daily at 03:15.
  - Calls existing `IAWM_Backup::prune_old(keep_n)`.
- [ ] **Smoke test endpoint** `/diagnostics/smoke`:
  - HTTP GET on `home_url()` (HEAD-like, follow redirects). Records
    the status code and final URL.
  - Reads `debug.log` and reports any fatal lines from the last 10
    minutes.
  - Reports active theme, agent user state, kill switch state,
    Divi version.
  - Returns `{ ok: true, healthy: true/false, checks: { http_home,
    fatal_errors, agent_user, kill_switch, divi, plugin_versions } }`.
- [ ] **Self-check endpoint** `/diagnostics/check-self`:
  - Verifies: agent user exists + role is `iawm_agent`.
  - Tables `wp_iawm_audit_log` + `wp_iawm_backups` exist + schema OK.
  - At least one credentials record present.
  - Rotation cron jobs registered.
  - Returns a list with status `ok` / `warn` / `error` per check.

### Decisions to lock

- Retention defaults 90 days / 50 backups — reasonable for a single
  busy site. Configurable via the admin UI in 7.4.
- Smoke test does NOT alert (no notification); it's a read endpoint
  the agent calls after any destructive op.

### Acceptance

- After plugin activation, `wp_get_scheduled_event('iawm_prune_audit_log')`
  returns a real timestamp.
- After insert of 100 audit rows backdated 100 days, the cron job
  cleans them.
- `iawm_diagnostics_smoke()` returns `healthy: true` on a clean site
  and `false` after artificially crashing a plugin.

---

## 7.3 — Divi branding writer

### What

The Divi customizer-allowlist for `/divi/theme-options/update` is 17
keys — `divi_logo` and `divi_favicon` aren't in it. Logo + favicon are
the most common branding settings.

### Tasks

- [ ] New endpoint `/divi/branding/get` (read):
  - Returns the brand-related keys from the `et_divi` option:
    `divi_logo`, `divi_favicon`, plus a curated subset (header style
    accent, etc.).
- [ ] New endpoint `/divi/branding/update` (write, scope `divi:write`):
  - Body `{ branding: { divi_logo, divi_favicon, ... }, dry_run? }`.
  - Reads current `et_divi`, merges, writes back.
  - Auto-backup of the `et_divi` option before the change.
- [ ] Gateway: 2 new MCP tools `iawm_divi_branding_get` /
  `iawm_divi_branding_update`.
- [ ] Doc: update `docs/design-system.md` example to use the new
  branding tool for logo/favicon (instead of the never-worked
  `theme-options` example).

### Decisions to lock

- Branding scope: which keys are exposed? Start with
  `divi_logo`, `divi_favicon`, `divi_logo_dark`, `divi_logo_alt`,
  `divi_favicon_apple`, `divi_color_palette` — to be reviewed during
  implementation.

### Acceptance

- `iawm_divi_branding_update({ branding: { divi_logo: "<URL>" } })`
  applies; the frontend logo updates after a page reload.

---

## 7.4 — Plugin settings page redesign

### What

The current admin UI works but is "long form, no hierarchy, prone to
errors". A polished UI is the difference between "experimental" and
"production-ready" for an operator.

### Tasks

- [ ] **Layout**:
  - Top status bar: kill switch state, agent user health, key
    count, last activity.
  - Tabs or sections: API Keys / Agent User / Security / Cleanup /
    Audit / Tools.
  - Cards (WordPress postbox style).
  - Danger zone visually separated (red border, confirmation prompts).
- [ ] **API keys**:
  - Better empty state: "No key yet. Create one to start." + CTA.
  - Each key in a card. Status colour-coded (active=green,
    last_used > 30d=yellow, never_used=grey).
  - Inline scope badges (not just text).
  - "Copy secret" button (only on row that just got a fresh secret).
- [ ] **Security**:
  - HTTPS state shown (refusal / accepted).
  - IP allow-list editor (textarea + parse preview).
  - Kill switch big-and-obvious toggle.
- [ ] **Cleanup**:
  - Retention sliders for audit + backups (read 7.2 options).
  - "Prune now" buttons.
  - Counters showing current row counts.
- [ ] **Audit**:
  - Recent 20 audit entries with key_label / linked_user_id resolved.
  - Filter by outcome (success / denied / error).
- [ ] **Tools**:
  - "Reinstall agent role & user" (existing).
  - "Run smoke test" (new — calls /diagnostics/smoke).
  - "Run self-check" (new — calls /diagnostics/check-self).
- [ ] **First-run wizard**:
  - On plugin activation, set a transient. On first admin visit,
    show a 3-step wizard: (1) generate first key (2) pick scopes (3)
    configure IP allow-list (skippable).

### Decisions to lock

- Tabs vs single-page-with-anchors: tabs (cleaner, scales).
- Use vanilla CSS + WP admin styles (no React or build step).

### Acceptance

- The redesigned page is visibly more polished, organised, with
  hierarchy.
- All existing actions still work (keys CRUD, kill switch, reinstall
  agent).
- Wizard appears on first activation of a fresh install.

---

## 7.5 — i18n plugin admin

### What

The plugin declares Text Domain but never wraps strings in `__()`. A
French operator sees English notices. Bloquant pour le public au-delà
de l'anglophone.

### Tasks

- [ ] Wrap every user-visible string in `__()` / `_e()` /
  `esc_html__()` / `esc_attr__()` with text domain
  `ia-webmaster-bridge`:
  - All admin UI strings (`class-iawm-admin.php`).
  - All `WP_Error` messages across modules.
  - The wizard strings (from 7.4).
- [ ] Set up `languages/` directory.
- [ ] Generate `ia-webmaster-bridge.pot` via `wp i18n make-pot` (or
  Loco Translate, or manually).
- [ ] Translate to French: `ia-webmaster-bridge-fr_FR.po` + `.mo`.
- [ ] Verify in WP admin set to French: strings show in French.
- [ ] Document the i18n workflow in `docs/operations.md`.

### Decisions to lock

- Languages shipped: English (source) + French (Marius's language).
  Others welcome via PR.

### Acceptance

- With `WPLANG=fr_FR` (or admin user language = Français), the
  settings page shows French.
- The `.pot` file is up-to-date and committed.

---

## 7.6 — Automated tests

### What

Zero tests today. For an outage-causing-capable tool, ridiculous.

### Tasks

- [ ] **PHPUnit setup**:
  - `composer.json` in `plugin/ia-webmaster-bridge/` with phpunit
    + WP test scaffold (`yoast/wp-test-utils` or
    `phpunit-polyfills`).
  - `tests/bootstrap.php` that loads WP minimally.
  - `phpunit.xml.dist`.
- [ ] **Critical-path tests** (target: 18 cases):
  - `tests/test-auth.php`:
    - HMAC sign/verify round-trip.
    - Wrong secret → fail.
    - Replayed nonce → fail.
    - Timestamp out of window → fail.
    - Missing headers → fail.
    - Unknown key_id → fail.
  - `tests/test-scope.php`:
    - Read scope rejects content:write.
    - infra:write rejected without scope.
    - Legacy key (no scope list) accepts everything.
  - `tests/test-backup.php`:
    - Snapshot options → restore → identical.
    - Snapshot plugins_state → restore → diff applied.
    - Delete consumes the row.
  - `tests/test-confirmation.php`:
    - Issue → consume → ok.
    - Issue → consume → consume again → fail.
    - Issue → mismatched body → fail.
  - `tests/test-self-protection.php`:
    - Update agent user → 403.
    - Deactivate bridge plugin → 403.
    - Self-update bridge plugin → 403.
- [ ] Optional: GitHub Actions CI workflow.

### Decisions to lock

- WP test framework: `yoast/wp-test-utils` (mature, less ceremony than
  the official wp-cli scaffold).
- CI: GitHub Actions, run on push to main + PRs. Optional in this
  phase; defer to 7.10 if time-constrained.

### Acceptance

- `composer install && composer test` runs green from scratch.
- The 18 tests cover ALL the auth + scope + backup + confirmation +
  self-protection paths exercised in `docs/validation-checklist.md`.

---

## 7.7 — Skills assemblies

### What

Skills are the high-level workflows the agent uses. Phase 4+5+7 produced
many new building blocks; assemble them into 4 ready-to-use workflows.

### Tasks

- [ ] **`safe-plugin-update`** skill:
  - Verb: "Update one or more plugins safely on this site."
  - Steps: list installed → identify outdated → for each (manual
    backup → update → smoke test → diff audit log → if broken, restore).
- [ ] **`design-system-first`** skill:
  - Verb: "Configure the brand design system, then author the
    homepage."
  - Steps: read brief → propose palette + fonts + tokens →
    confirm with user → write design system → author pages
    referencing the tokens.
- [ ] **`site-smoke-test`** skill:
  - Verb: "Verify the site is healthy after an operation."
  - Steps: call `/diagnostics/smoke` → tail audit log →
    interpret + report.
- [ ] **`prod-deployment-checklist`** skill:
  - Verb: "Walk through a fresh install of IAWM on a production
    site."
  - Steps: check prerequisites (HTTPS, WP version) → install plugin
    → configure agent user → generate first key → set IP allow-list
    → run smoke test → record audit baseline → switch on monitoring.

### Decisions to lock

- All skills in English (per project convention since the
  internationalisation pass).
- Names are kebab-case, listed in the marketplace plugin.json.

### Acceptance

- The 4 skills appear in `/plugin` after install.
- A dry run of each in Claude Code completes without error.

---

## 7.8 — Doc pass

### What

Reader-side doc is fragmented: install, ops, design system are each
in their own file but no master "production deployment" path. Also
missing CHANGELOG, CONTRIBUTING.

### Tasks

- [ ] **README.md** rewrite:
  - Honest status section (Phase 7 in progress; v1.0.0 target).
  - Quickstart in 3 commands + 1 screenshot of the settings page.
  - "Production deployment" → link to new doc.
  - "Security model" → link to new doc.
  - Badges (license, plugin version, gateway version).
- [ ] New `docs/production-deployment.md`:
  - Pre-install checklist (HTTPS, WP-Cron strategy, backups).
  - Install steps (plugin upload, configuration, generate key with
    minimal scopes, IP allow-list).
  - First smoke test.
  - Monitoring (audit log queries, debug.log).
  - "Go / No-Go" criteria for switching the agent to write mode.
- [ ] New `docs/security-model.md`:
  - Layered model: HMAC → IP allow-list → scope → agent role →
    audit → backup → confirmation → kill switch.
  - Threat model (what we defend against, what we don't).
  - Incident response: secret leak / WP admin breach / plugin
    compromise.
- [ ] Refresh `docs/operations.md` with rotation + new self-check.
- [ ] `CHANGELOG.md` from scratch — every commit since v0.18.3.
- [ ] `CONTRIBUTING.md` — how to contribute, code style, tests.
- [ ] Refresh `CLAUDE.md` — mention v1.0.0 production-readiness model.
- [ ] Decisions log: D-020 (rotation), D-021 (smoke test),
  D-022 (HTTPS + IP), D-023 (i18n + tests).

### Decisions to lock

- CHANGELOG style: keep-a-changelog format.
- CONTRIBUTING: PR via fork, test required, doc required for new
  endpoint.

### Acceptance

- A new contributor can install + configure + run a first end-to-end
  test in <15 minutes reading only `README.md` + `docs/production-
  deployment.md`.

---

## 7.9 — Pentest dry-run

### What

The pentest checklist (`docs/operations.md`) was written but never
executed. Walk it.

### Tasks

- [ ] Run every probe in the "Penetration testing checklist" section.
  Tick each.
- [ ] For any probe whose result deviates from expected: open an
  issue in this doc, fix in code, re-run.
- [ ] Record the run in a new `docs/pentest-2026-05-25.md` (or the
  date of the actual run). Keep as a reference for next iterations.

### Acceptance

- Every row in the pentest table behaves as documented.

---

## 7.10 — Final integration + v1.0.0 release

### What

Tag the result. The version jump from `0.x` to `1.0.0` signals stable
public API + production-deployable.

### Tasks

- [ ] Re-run the full validation checklist one more time.
- [ ] Bump versions: plugin **`1.0.0`**, gateway **`1.0.0`**.
- [ ] Tag git: `git tag -a v1.0.0 -m "..."` + push.
- [ ] Create GitHub Release from tag with CHANGELOG excerpt.
- [ ] Update `README.md` "Project status" to "Production ready".
- [ ] Update `docs/roadmap.md` — Phase 7 ticked, link to v1.0.0.

### Acceptance

- A fresh clone of the repo at tag `v1.0.0`, walked through the
  production-deployment doc, results in a working install on a
  fresh WP site.

---

## Tracking

This document is the master tracker for Phase 7. Each sub-phase will
be ticked off as it completes. Deviations or scope changes will be
recorded under each sub-phase as bullet points dated "YYYY-MM-DD".
