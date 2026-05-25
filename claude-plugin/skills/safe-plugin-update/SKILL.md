---
name: safe-plugin-update
description: Update one or more plugins safely on this site. Coordinates the pre-op backup, per-plugin update calls, and a post-update smoke test, with a documented rollback path if anything goes wrong.
---

# Safely update WordPress plugins

## Goal

Apply the available plugin updates on a connected WordPress site without
breaking it. Each update creates a `pre_op_backup_id` automatically; this
skill makes sure those IDs are captured, that the site is probed after the
batch, and that a clean rollback is one tool call away if a probe fails.

## When to use it

- The operator asks to update plugins, "patch the site", or "bring plugins
  up to date".
- After an `audit-wordpress-site` run that surfaced outdated plugins.

## Prerequisites

- API key with at least the `read` and `infra:write` scopes.
- `iawm_status` returns OK and the kill switch is OFF.
- The site has at least one off-site backup configured (recommended, in
  addition to the pre-op snapshot the plugin takes per write).

## Step-by-step

### 1. List outdated plugins

```
iawm_diagnostics_plugins()
```

Filter on entries where `update_available: true`. Show the user the list
(slug, current version, target version) and ask for confirmation before
touching anything.

### 2. Update each plugin, one by one

For each plugin to update:

```
iawm_plugins_update({ file: "<plugin-folder>/<main-file>.php" })
```

**Capture the returned `pre_op_backup_id`** in a small table — you will
need it if the smoke test fails. Example mental model:

| Plugin | Pre-op backup ID |
|--------|------------------|
| akismet/akismet.php | bk_2026_05_25_001 |
| wordpress-seo/wp-seo.php | bk_2026_05_25_002 |

Do **not** batch all updates in a single MCP call — running them one by one
keeps the backup chain readable and lets you stop early if one fails.

### 3. Smoke-test the site

After **all** updates have run:

```
iawm_diagnostics_smoke()
```

Confirm the response contains `healthy: true`. Inspect each probe
(HTTP home, admin reachability, fatal-error log, WP cron). The smoke test
is the single source of truth for "did the site survive".

### 4. Optional — verify via audit log

```
iawm_audit({ limit: 20 })
```

Walk the last 20 entries to confirm every `plugins_update` action landed
with status `success` and matches the list from step 1.

### 5. If `healthy: false` — rollback path

1. Identify the **first** update whose `pre_op_backup_id` precedes the
   failure (usually the most recent one, but check the audit log if
   several updates ran).
2. Inspect what the restore would touch:
   ```
   iawm_backup_restore({ id: "<pre_op_backup_id>", dry_run: true })
   ```
3. Show the user the plan, get explicit confirmation, then apply:
   ```
   iawm_backup_restore({ id: "<pre_op_backup_id>" })
   ```
4. Re-run `iawm_diagnostics_smoke()` to confirm health is back.

## Pitfalls

- **The bridge plugin refuses to self-update** via this endpoint — that is
  by design. Update `ia-webmaster-bridge` manually via WP admin or
  `wp plugin update`.
- A plugin can return `success` from `iawm_plugins_update` and still break
  the front-end (fatal in a hook fired later). Always run the smoke test —
  never trust the update call alone.
- If two plugins depend on each other (e.g. WooCommerce + an extension),
  update the core first, then the extension. Reverse order can dump a
  fatal until both versions match.
- `pre_op_backup_id` is **per-call**: each `iawm_plugins_update` takes its
  own snapshot. Don't assume the first one covers later updates.

## When NOT to use this skill

- The site does not yet have the bridge plugin in v0.18+ — the smoke and
  backup endpoints may be missing. Update the bridge first.
- The operator only has the `read` scope. Stop and ask for an
  `infra:write` key rather than attempting the call (it will 403).
- Major-version plugin updates with known breaking changes (e.g.
  WooCommerce 8 → 9). Those deserve a staging-site rehearsal first; do
  not run this skill straight on production.
- A new core or theme update is also pending — bundle the work via the
  full deployment checklist instead of doing one slice in isolation.
