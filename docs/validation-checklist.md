# Validation checklist — pending end-to-end tests

> Living document. Lists the manual end-to-end checks waiting to be run
> against a local WordPress site after a feature lands. Tick boxes as
> they pass; reset when a new feature lands that supersedes them.
>
> Last updated: 2026-05-25

## How to use

1. Restart Claude Code (or the MCP gateway) so the new bundle is loaded
   and new `iawm_*` tools are exposed.
2. From top to bottom, run each `iawm_*` command in a Claude Code
   session against the local site. Confirm the **expected outcome**
   matches. Tick the box when green.
3. At the end of each section there's a **Cleanup** step — run it so
   the test artefacts don't pile up.
4. If anything fails, note the deviation under the section so it can be
   fixed before promoting the feature to a real site.

## Prerequisites

- [ ] LocalWP site `ia-webmaster-bridge.local` is up.
- [ ] Claude Code MCP gateway has been restarted after the last build
  (so it serves the current `dist/index.js` bundle).
- [ ] `iawm_status` returns the latest plugin `version` (cross-check
  with `plugin/ia-webmaster-bridge/ia-webmaster-bridge.php` header).

---

## Phase 5.1 — Dedicated agent user + scopes (plugin v0.19.0)

### Already validated in-session ✓

- [x] `iawm_status` returns `agent_user: {id, login: "iawm-agent", role: "iawm_agent"}` and `scopes: "*"` (legacy key).
- [x] `iawm_content_create` returns `author: 3` (i.e. the agent user, not the human admin).

### Remaining (admin UI + scope enforcement)

- [ ] **Admin UI** — go to `Settings → IA Webmaster Bridge`.
  - [ ] The "Scopes granted to this key" section shows the 5 checkboxes (`read`, `content:write`, `divi:write`, `config:write`, `infra:write`).
  - [ ] Legacy key shows the notice *"Legacy key without an explicit scope list — full access"*.
  - [ ] The "Dedicated agent user" panel shows `iawm-agent` (ID 3) with role `iawm_agent`.

- [ ] **Tighten scopes without rotating** — untick `infra:write`, click *Save scopes*.
  - [ ] Notice: *"Scopes updated. The secret was not rotated."*
  - [ ] `iawm_status` now returns `scopes: ["read","content:write","divi:write","config:write"]`.

- [ ] **Scope denial** — call `iawm_plugins_info(slug: "hello-dolly")`.
  - Expected: still works (it's read-only, mapped to `read`).
- [ ] Call `iawm_plugins_install(slug: "akismet")`.
  - Expected: **HTTP 403 `iawm_scope_denied`**, with `required_scope: "infra:write"` in the error data.

- [ ] **Restore full access** — re-tick `infra:write`, *Save scopes*.

- [ ] **Users panel in WP admin** — go to `Users → All Users`. Confirm
  `iawm-agent` is listed with role *IA Webmaster Agent*.

- [ ] **Self-protection** — call
  `iawm_config_users_update(id: 3, display_name: "hacked")`.
  - Expected: **HTTP 403 `iawm_protected_user`**.

---

## Phase 5.2 — Auto backup before destructive (plugin v0.20.0)

### Already validated in-session ✓

- [x] `iawm_plugins_install({slug: "hello-dolly", activate: true})` returned `pre_op_backup_id: 1`.

### Remaining (round-trip through the new backup tools)

- [ ] **List backups** — `iawm_backup_list()`.
  - Expected: at least one entry with `kind: "plugins_state"` and
    `label: "Before plugin install: hello-dolly (+activate)"`.

- [ ] **Read the snapshot payload** — `iawm_backup_get({id: 1, include_payload: true})`.
  - Expected: `payload.active_plugins` does NOT contain
    `hello-dolly/hello.php` (it was captured BEFORE the install).
  - Expected: `payload.installed` map covers IA Webmaster Bridge + Rank Math.

- [ ] **Dry-run restore** — `iawm_backup_restore({id: 1, dry_run: true})`.
  - Expected: `to_deactivate: ["hello-dolly/hello.php"]`, `to_activate: []`, `dry_run: true`.

- [ ] **Real restore** — `iawm_backup_restore({id: 1})`.
  - Expected: `result.deactivated: ["hello-dolly/hello.php"]`, `dry_run: false`.
  - Then `iawm_diagnostics_plugins()` shows Hello Dolly as `active: false`.

- [ ] **Manual options snapshot** — `iawm_backup_create({kind: "options", option_names: ["blogname", "blogdescription"], label: "Manual smoke test"})`.
  - Expected: `created: true, backup_id: N`.

- [ ] **Manual options restore (dry-run)** — `iawm_backup_restore({id: N, dry_run: true})`.
  - Expected: `diff: {}` (nothing changed since the snapshot).

- [ ] **Delete a backup** — `iawm_backup_delete({id: <one of the test ids>})`.
  - Expected: `deleted: true`.

- [ ] **Cleanup** — delete remaining test backup records via `iawm_backup_delete`. Optionally `iawm_backup_prune({keep: 20})` to trim the history.

---

## Design system writes (plugin v0.28.0, gateway v0.20.0)

### Untested as of this writing

- [ ] **Read** — `iawm_divi_global_data()`.
  - Expected: `global_colors` (palette), `global_variables` (6 buckets), `global_fonts` (heading + body). The new `global_fonts` field is the marker of v0.28.0.

- [ ] **Update colors** — `iawm_divi_global_colors_update({ global_colors: { "gcid-primary-color": { color: "#0a6ef5", status: "active" } } })`.
  - Expected: `updated: true`, palette returned with the new value.
  - Re-call `iawm_divi_global_data` and confirm the new color is persisted.

- [ ] **Update fonts** — `iawm_divi_global_fonts_update({ heading_font: "Inter", body_font: "Inter" })`.
  - Expected: `updated: true`. Re-call `iawm_divi_global_data` → `global_fonts.heading_font === "Inter"`.

- [ ] **Partial fonts update** — `iawm_divi_global_fonts_update({ heading_font: "Playfair Display" })` (no body).
  - Expected: body font preserved, only heading changed.

- [ ] **Variables** — `iawm_divi_global_variables_update({ global_variables: { numbers: { "gvid-brand-radius": { label: "Brand radius", value: "12px", order: 1, status: "active" } } } })`.
  - Expected: `updated: true`, the new entry is in subsequent `iawm_divi_global_data` calls.

- [ ] **Theme options get** — `iawm_divi_theme_options_get()`.
  - Expected: full `et_divi` option array (logo, favicon, integration HTML, performance switches, layout settings, etc.).

- [ ] **Theme options update (dry-run)** — `iawm_divi_theme_options_update({ options: { divi_logo: "https://example.com/logo.svg" }, dry_run: true })`.
  - Expected: `dry_run: true`, `would_change.divi_logo.from` = current value, `to` = new URL.

- [ ] **Theme options update (real)** — same call without `dry_run`.
  - Expected: `updated: true, changed_keys: ["divi_logo"]`. Confirm via re-reading.

## Divi modules registry (gateway v0.19.0)

### Untested as of this writing

- [ ] **Catalog tool** — `iawm_divi_modules_catalog({ family: "woocommerce" })`.
  - Expected: 25 modules returned, every `name` starting with `divi/woocommerce-` or `divi/shop`.
- [ ] **Catalog filter by category** — `iawm_divi_modules_catalog({ category: "fullwidth-module" })`.
  - Expected: 10 entries (hero / fullwidth-image / fullwidth-slider / etc.).
- [ ] **Catalog text search** — `iawm_divi_modules_catalog({ q: "post" })`.
  - Expected: blog, post-content, post-nav, post-slider, post-title, plus fullwidth-post-* variants.
- [ ] **Module info** — `iawm_divi_module_info({ name: "divi/woocommerce-product-add-to-cart" })`.
  - Expected: `ok: true`, module with `family: "woocommerce"`, `d4Shortcode: "et_pb_wc_add_to_cart"`, title "Woo Product Add To Cart".
- [ ] **Unknown module** — `iawm_divi_module_info({ name: "divi/post-navigation" })` (the old typo).
  - Expected: `ok: false, error: "not_found"` — confirms the typo is now caught.

## Multi-key + Cron (plugin v0.27.0)

### Multi-key

- [x] **Legacy migration** — already validated in session: `iawm_status` returns `key_label: "Legacy key"` and `total_keys: 1`.
- [ ] **Admin UI** — Settings → IA Webmaster Bridge:
  - [ ] Existing key shown in the table with the synthetic label "Legacy key".
  - [ ] **Create a new key** form: pick a label, scopes, optional linked WP user. Submit.
  - [ ] After submit: new row appears, secret displayed once (in highlighted yellow row).
- [ ] **Per-key scope tightening** — open the new key's "Manage" → "Scopes" section, untick `infra:write`, save.
  - [ ] `iawm_status` (signed with that key) returns scopes without `infra:write`.
  - [ ] `iawm_plugins_install({slug: "akismet"})` returns HTTP 403 `iawm_scope_denied`.
- [ ] **Linked user audit** — call any iawm tool with the new key, then `iawm_audit`. Expected: the entry's `detail` contains `key_label` and `linked_user_id`.
- [ ] **Rotate secret** — "Manage" → "Rotate secret" on a key. Expected: new secret shown once, MCP gateway with old secret now fails 401.
- [ ] **Revoke single key** — "Manage" → "Revoke this key". Expected: that key returns 401 on next call; other keys keep working.
- [ ] **Revoke all** — Danger zone → "Revoke ALL keys". Expected: every key dies. (Be ready to re-create your own before testing this!)

### Cron

- [ ] **List events** — `iawm_cron_list()`. Expected: queued events with `wp_version_check`, plugin-specific hooks, etc.
- [ ] **List schedules** — `iawm_cron_schedules()`. Expected: at least `hourly`, `twicedaily`, `daily`.
- [ ] **Run an event now** — pick a recurring event from the list, call `iawm_cron_run({hook: "wp_version_check"})`. Expected: hook fires; subsequent `iawm_cron_list` shows a new occurrence ~12h away.
- [ ] **Schedule a one-off event** — `iawm_cron_schedule({hook: "my_test_hook", timestamp: <now+300>})`. Expected: `scheduled: true`. List shows it.
- [ ] **Unschedule by hook** — `iawm_cron_unschedule({hook: "my_test_hook"})`. Expected: `unscheduled_all: true, removed: 1`.
- [ ] **Unknown schedule slug** — `iawm_cron_schedule({hook: "x", schedule: "nope"})`. Expected: HTTP 400 `iawm_unknown_schedule`.

## Phase 4 — Database tools (plugin v0.25.0)

### Untested as of this writing

- [ ] **Database info** — `iawm_database_info()`.
  - Expected: list of tables with engine, rows, sizes; `db_prefix` returned.

- [ ] **Export selected tables** — `iawm_database_export({tables: ["wp_options"], label: "Smoke export"})`.
  - Expected: `exported: true, backup_id: N`.
  - Verify the backup payload via `iawm_backup_get({id: N, include_payload: true})` — should show SQL containing `DROP TABLE IF EXISTS \`wp_options\`` and INSERTs.

- [ ] **Valid SELECT** — `iawm_database_query({sql: "SELECT option_name, option_value FROM wp_options WHERE option_name = 'siteurl'"})`.
  - Expected: `row_count: 1`, the row in `rows`.

- [ ] **Invalid query (UPDATE)** — `iawm_database_query({sql: "UPDATE wp_options SET option_value = 'x'"})`.
  - Expected: **HTTP 400 `iawm_invalid_query`** "Only SELECT (or WITH ... SELECT)…".

- [ ] **Invalid query (semicolon)** — `iawm_database_query({sql: "SELECT 1; DROP TABLE wp_users"})`.
  - Expected: **HTTP 400 `iawm_invalid_query`** "Multiple statements are not allowed".

- [ ] **Search-replace dry-run** — `iawm_database_search_replace({search: "Hello world", replace: "Salut le monde", dry_run: true})`.
  - Expected: `dry_run: true, total_changed: 0` (assuming "Hello world" is not in any post/option). For a real test, install hello-dolly first and search for "Quel and his band of Dixieland 7".

- [ ] **Search-replace confirmation gate** — same call without `dry_run` and without `confirmation_token`.
  - Expected: HTTP 202, `requires_confirmation: true`, `confirmation_token: "..."` (64 hex chars), `summary.targets` listing the (table, column) pairs that will be scanned.
  - Then re-issue the EXACT same body with `confirmation_token: <token>`.
  - Expected: real apply, `dry_run: false`, total_changed reported.

- [ ] **Token replay refusal** — re-issue the same body with the SAME token again.
  - Expected: **HTTP 400 `iawm_invalid_confirmation`** — token is single-use.

- [ ] **Cleanup** — `iawm_backup_delete({id: N})` for the export, then optionally `iawm_database_search_replace` again to reverse a real change.

## Phase 5.3 — Confirmation tokens (plugin v0.24.0)

### Untested as of this writing

- [ ] **First call without token returns 202 + token** —
  `iawm_backup_restore({id: <some backup>})` (non-dry_run, no token).
  - Expected: HTTP 202, `requires_confirmation: true`, `confirmation_token: "..."`, `summary.preview` describing what the restore would do.

- [ ] **Second call with token applies** — re-issue with the same body + token.
  - Expected: HTTP 200, restore applied.

- [ ] **Mismatched body refuses token** — first call with `{id: 1}` gets token T; then submit `{id: 2, confirmation_token: T}`.
  - Expected: **HTTP 400 `iawm_invalid_confirmation`** (body hash mismatch).

- [ ] **Dry-run bypasses token** — `iawm_backup_restore({id: 1, dry_run: true})`.
  - Expected: HTTP 200, normal dry-run response, no token needed.

- [ ] **Token expiration** — issue a token, wait 6 minutes, try to use it.
  - Expected: **HTTP 400 `iawm_invalid_confirmation`** (transient expired).

## Phase 4 — Core WordPress update (plugin v0.23.0)

### Untested as of this writing

- [ ] **Core info** — `iawm_core_info()`.
  - Expected: `current_version: "7.0"`, `php_version: "8.2.29"`, `available: {...}` or `null`, `has_update: false` (or true).

- [ ] **Dry-run when no update available** — `iawm_core_update({dry_run: true})`.
  - Expected: `no_update: true` if site is on the latest version. No token issued.

- [ ] **Confirmation flow if an update IS available** —
  - First call: `iawm_core_update({})`. Expected: HTTP 202, `requires_confirmation: true`, summary with current_version + would_update_to + php_required.
  - Second call with token: applies the update. Expected: `updated: true, previous_version, new_version, pre_op_backup_id`.
  - ⚠️ Only run this on a non-prod site you don't mind testing on.

## Phase 4 — Plugin updates (plugin v0.22.0)

### Untested as of this writing

- [ ] **No-update path** — `iawm_plugins_update({file: "hello-dolly/hello.php"})`.
  - Expected: `no_update: true, version: "1.7.2"` (Hello Dolly is already latest).
  - No `pre_op_backup_id` (snapshot is only taken when there IS an update to apply).

- [ ] **Self-update refusal** — `iawm_plugins_update({file: "ia-webmaster-bridge/ia-webmaster-bridge.php"})`.
  - Expected: **HTTP 403 `cannot_self_update`** with a clear message pointing to WP admin.

- [ ] **Real update path** — only possible when a plugin on the site has an update available.
  - Trigger: install an older plugin version manually (or wait for a real WP.org update to land on something we have installed), then call `iawm_plugins_update({file: "..."})`.
  - Expected: `updated: true`, `previous_version`, `new_version`, `pre_op_backup_id: M`.
  - Cleanup: optionally `iawm_backup_restore({id: M})` would re-mark the previous active_plugins state, but it does NOT downgrade the on-disk files (we only snapshot state, not bytes).

- [ ] **Invalid file** — `iawm_plugins_update({file: "../etc/passwd"})`.
  - Expected: **HTTP 400 `invalid_file`**.

## Phase 4 — Themes module (plugin v0.21.0)

### Untested as of this writing

- [ ] **List installed themes** — `iawm_themes_list()`.
  - Expected: at least Divi (active) and the default Twenty* themes.
    `active_stylesheet` is `Divi` (or your variant).

- [ ] **Metadata** — `iawm_themes_info(slug: "twentytwentyfour")`.
  - Expected: version, screenshot_url, author "wordpressdotorg", description.

- [ ] **Install without activate** — `iawm_themes_install({slug: "twentytwentyfour", activate: false})`.
  - Expected: `installed: true, activated: false, pre_op_backup_id: M`.
  - ⚠️ **Do NOT pass `activate: true`** unless you want to swap away from Divi.
  - [ ] `iawm_themes_list()` now lists `twentytwentyfour` with `is_active: false`.

- [ ] **Inspect the auto-backup** —
  `iawm_backup_get({id: M, include_payload: true})`.
  - Expected: `kind: "options"`, payload contains `template`, `stylesheet`, `current_theme`, etc. with the values that were active before the install (still Divi).

- [ ] **(Optional, advanced) Round-trip an activation** — only if you
  are comfortable temporarily losing Divi as the active theme:
  - [ ] `iawm_themes_activate({stylesheet: "twentytwentyfour"})`. Expected: `activated: true, pre_op_backup_id: P`.
  - [ ] Visually verify the frontend is now twentytwentyfour.
  - [ ] `iawm_backup_restore({id: P})`. Expected: theme options rolled back; Divi is active again.

- [ ] **Cleanup** — either keep `twentytwentyfour` (harmless, ~150 KB)
  or delete it manually via `Appearance → Themes` (API deletion is
  intentionally not exposed).

---

## Notes on deviations

> Append findings here as you tick the boxes. Examples: an endpoint
> returns the wrong field name, a UI element is missing, the auto-backup
> didn't trigger when it should have, etc.

### 2026-05-25 — Partial validation run

**Operating note**: when Claude Code is "restarted", it relaunches its
processes but the **MCP gateway bundle is read from its installed
location** (`~/.iawm/gateway/index.js` on Windows). That location is
**not** automatically synced with the repo's `claude-plugin/
mcp-gateway/dist/index.js`. To run the full validation after a build,
copy the fresh bundle over and restart Claude Code a second time:

```powershell
Copy-Item F:\Dev\wordpress\ia\claude-plugin\mcp-gateway\dist\index.js `
          $env:USERPROFILE\.iawm\gateway\index.js -Force
```

(Or run `/plugin install ia-webmaster@ia-webmaster-bridge` again from
Claude Code, which performs the same copy via the marketplace.)

**Validated this run (plugin v0.28.0 live, old gateway still loaded):**

- [x] Phase 5.1 — self-protection — `iawm_config_users_update(id:3, display_name:"hacked")` → HTTP 403 `iawm_protected_user` ✓
- [x] Phase 5.4 audit enrichment — entry #175 in `iawm_audit` shows `detail.key_label: "Legacy key"` ✓
- [x] Design system read (`iawm_divi_global_data`) returns the new `global_fonts` field ✓ (v0.28.0 marker present)
- [x] Plugin install path from previous session shows in audit log with `pre_op_backup_id` workflow intact (#164 hello-dolly install → still active in diagnostics)
- [x] Divi 5.5.2 active, Theme = `Divi`, plugin v0.28.0 live

**Blocked on gateway restart with the fresh bundle** (all tools listed below resolve as
"No such tool available" in the current session because the installed
gateway bundle pre-dates Phase 5.2):

- All `iawm_backup_*` (list/get/create/restore/delete/prune) — Phase 5.2
- All `iawm_themes_*` (list/info/install/activate/update) — Phase 4
- All `iawm_cron_*` (list/schedules/run/schedule/unschedule) — Phase 4
- All `iawm_core_*` (info/update) — Phase 4
- All `iawm_database_*` (info/export/query/search_replace) — Phase 4
- `iawm_divi_modules_catalog`, `iawm_divi_module_info` — Phase 3 registry
- `iawm_divi_global_colors_update`, `iawm_divi_global_fonts_update`, `iawm_divi_global_variables_update` — Phase 6 design system
- `iawm_divi_theme_options_get`, `iawm_divi_theme_options_update` — Phase 6 design system
- `iawm_plugins_update` — Phase 4

Once Claude Code is relaunched with the freshly-copied bundle, re-run
the checklist from top to bottom — all the items above should green
through.
