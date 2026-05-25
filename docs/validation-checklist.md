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

- _none yet_
