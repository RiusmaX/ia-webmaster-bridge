# Operations runbook

> Status: Living · Last updated: 2026-05-25 (added translation workflow)

Documented procedures for the human operator. Each procedure is
written assuming the plugin and gateway are healthy; if they are not,
the **SSH / WP-CLI fallback** section below covers recovery without
the API.

## Phase 7 features at a glance

Phase 7 (in progress, see [`phase-7-action-plan.md`](phase-7-action-plan.md))
added four operator-facing surfaces that change day-to-day ops:

- **Network hardening** (7.1): HTTPS enforcement via the
  `IAWM_REQUIRE_HTTPS` constant and an IP allow-list option editable
  from the admin Security tab. See
  [`security-model.md`](security-model.md) for the layered model.
- **Lifecycle hardening** (7.2): two daily WP-Cron jobs prune the
  audit log (`iawm_prune_audit_log`, retention
  `iawm_audit_retention_days`, default 90 days) and the backup table
  (`iawm_prune_backups`, retention `iawm_backup_keep_n`, default 50
  rows). Retention sliders live in the admin Cleanup tab.
- **Smoke test workflow**: after any destructive operation, call
  `iawm_diagnostics_smoke` (HTTP probe + fatal scan + state checks)
  and, after a plugin upgrade, `iawm_diagnostics_check_self`
  (invariants). Both are exposed as buttons in the admin Tools tab.
- **Branding writer** (7.3): site logo and favicon are written
  through `iawm_divi_branding_update` (curated allow-list of
  `divi_logo`, `divi_favicon`, `divi_logo_dark`, `divi_logo_mobile`,
  `divi_logo_tablet`, `divi_logo_phone`).

---

## Key rotation

A key rotation invalidates the current HMAC secret and issues a new
one. With multi-key support (since v0.26.0), rotation is non-disruptive:
the operator can create a new key, switch the gateway over, and only
then revoke the old one — zero downtime.

### Recommended procedure (zero-downtime)

1. **WordPress admin → Settings → IA Webmaster Bridge**.
2. **Create a new key**: pick a label (e.g. `Alice — 2026-Q2`), the
   scopes the new key should have (start with the same set the old key
   had if uncertain), and optionally link it to a WP user.
3. The settings page shows the new secret **once** — copy it
   immediately.
4. On the operator's machine, edit `~/.iawm/config.json` to point at
   the new `key_id` + `secret`.
5. Restart Claude Code / the MCP gateway so it picks up the new
   credentials.
6. Validate: call `iawm_status` and confirm the response shows the new
   `key_id` and `key_label`.
7. Back in the admin, **revoke the old key**.

### Emergency rotation (secret leaked)

If you suspect a secret has leaked:

1. Settings → IA Webmaster Bridge → **Revoke ALL keys** (the
   "Danger zone" section at the bottom of the page). Every Claude
   session loses access immediately.
2. Toggle the **Kill switch** on, as a second-layer guarantee.
3. Audit the log (`iawm_audit` once you've reconfigured at least one
   key) for any suspicious calls during the leak window.
4. Re-create keys following the standard procedure.

### Rotation cadence

There is no enforced expiry. Reasonable defaults:

- **Per-key**: rotate when someone leaves the team, when a workstation
  is decommissioned, after a known incident, or every ~12 months.
- **All keys**: only after a confirmed leak or before publishing the
  plugin to a production environment for the first time.

---

## Adding a second operator (multi-key in practice)

Goal: a second human runs their own Claude Code against the same site,
with their own identity in the audit log.

1. Admin → IA Webmaster Bridge → **Create a new key**. Set the label
   to the person's name (`Alice`, `Bob`), tick the scopes you want
   them to have, and select their WordPress user account in the
   **Linked WP user** dropdown.
2. Send them the (`key_id`, `secret`) pair through a secure channel
   (a password manager, a Signal message — never email).
3. They install the Claude Code plugin (`/plugin marketplace add
   RiusmaX/ia-webmaster-bridge`), create their own `~/.iawm/config.json`
   with the secret you sent.
4. From now on, every API call they make is logged under their
   key_id, and the audit log carries their linked user id so you can
   filter on them.

The dedicated agent user (`iawm-agent`) remains the single executor:
WordPress audit / revisions still show "iawm-agent" as the author of
the change. The fine-grained "who triggered this" only lives in the
plugin's audit log — by design, to keep the WP-side capability surface
small.

---

## Safe plugin update workflow

Reference workflow combining the Phase 4 + Phase 5 building blocks.
Use this checklist for every plugin update on production:

1. `iawm_diagnostics_plugins()` — identify the plugin to update and its
   current version.
2. `iawm_plugins_update({ file, dry_run: true })` if you want to see
   the available version first. *(Optional: `dry_run` is not
   universally supported on updates yet; safe to skip.)*
3. `iawm_backup_create({ kind: "plugins_state", label: "Manual pre-
   update" })` — extra defensive snapshot beyond the auto pre-op.
4. `iawm_plugins_update({ file })` — the auto pre-op snapshot is also
   taken. Note the returned `pre_op_backup_id`.
5. Smoke test: load the site frontend, hit a few key URLs, verify
   `iawm_diagnostics_logs()` is clean.
6. If broken: `iawm_backup_restore({ id: pre_op_backup_id, dry_run:
   true })`, review, then re-issue with the returned confirmation
   token.

The same shape applies to theme updates (`iawm_themes_update`) and
core updates (`iawm_core_update`).

---

## SSH / WP-CLI fallback channel

Reserved for the human operator, used when:

- the plugin itself is broken (recently introduced bug);
- the kill switch is enabled and the admin UI is unreachable;
- the agent user / role got corrupted and the API refuses to authenticate;
- you need to perform an operation outside the API surface (e.g. drop
  a custom table, manipulate `wp-config.php`, restart cron from outside
  PHP).

By design, the agent **never** has access to this channel.

### Useful WP-CLI commands

The exact path to WP-CLI depends on the hosting environment (LocalWP
gives you a "Site shell" that already has `wp` on PATH).

```bash
# Disable the plugin if the API is locked out.
wp plugin deactivate ia-webmaster-bridge

# Re-enable.
wp plugin activate ia-webmaster-bridge

# Reset the kill switch (true = blocked, false = allowed).
wp option update iawm_kill_switch ''
wp option update iawm_kill_switch ''  # idempotent

# Wipe all API keys (last-resort revocation if you can't reach admin).
wp option delete iawm_credentials

# Force a fresh agent role + user.
wp eval 'IAWM_Agent_User::install();'

# Inspect the latest 20 audit entries.
wp db query "SELECT created_at, route, outcome, key_id, ip FROM wp_iawm_audit_log ORDER BY id DESC LIMIT 20"

# Inspect snapshots.
wp db query "SELECT id, created_at, kind, label, restored_at FROM wp_iawm_backups ORDER BY id DESC LIMIT 20"

# WordPress core: refresh the update transient.
wp cron event run wp_version_check

# Force a core update (if API is down and you really need it).
wp core update
```

### Re-installing the agent user manually

If the `iawm-agent` user got deleted by hand:

```bash
wp eval 'IAWM_Agent_User::install();'
```

Or from the admin: **Settings → IA Webmaster Bridge → Reinstall agent
role & user**.

### Editing `wp-config.php`

Not exposed through the API. If you need to change a WP constant
(e.g. `WP_DEBUG`, `DISALLOW_FILE_EDIT`), use SSH and edit the file by
hand — this is intentional.

---

## Penetration testing checklist

Run this list against a non-production install before each public
release of the plugin. Each item is a known-bad input; the expected
behaviour is in the right column.

### Authentication

| Probe | Expected |
|-------|----------|
| Hit any authenticated endpoint with no `X-IAWM-*` headers | HTTP 401 `iawm_unauthorized` "Missing authentication headers." |
| Hit with a valid `X-IAWM-Key` but an empty signature | HTTP 401 |
| Hit with a signature computed with the **wrong** secret | HTTP 401 "Invalid signature." |
| Hit with a timestamp 10 minutes in the past | HTTP 401 "Request expired or timestamp out of tolerance." |
| Replay the exact same signed request | HTTP 401 "Nonce already used (replay detected)." |
| Hit with `X-IAWM-Key` referring to a revoked key_id | HTTP 401 "Unknown key identifier." |
| Hit `/ping` with NO headers | HTTP 200 (public diagnostic by design) |

### Scopes

| Probe | Expected |
|-------|----------|
| Generate a key with only `read`. Call `iawm_content_create` | HTTP 403 `iawm_scope_denied`, `required_scope: "content:write"` |
| Same key. Call `iawm_plugins_install` | HTTP 403 `iawm_scope_denied`, `required_scope: "infra:write"` |
| Same key. Call any `iawm_*` GET tool | HTTP 200 (read scope sufficient) |

### Self-protection

| Probe | Expected |
|-------|----------|
| `iawm_config_users_update({ id: <agent user id> })` | HTTP 403 `iawm_protected_user` |
| `iawm_plugins_deactivate({ file: "ia-webmaster-bridge/ia-webmaster-bridge.php" })` | HTTP 403 `cannot_disable_self` |
| `iawm_plugins_update({ file: "ia-webmaster-bridge/ia-webmaster-bridge.php" })` | HTTP 403 `cannot_self_update` |

### Confirmation tokens

| Probe | Expected |
|-------|----------|
| Call `iawm_backup_restore({ id: 1 })` (no token, not dry_run) | HTTP 202 `requires_confirmation: true` + token |
| Re-issue with token, same body | HTTP 200, restore applied |
| Re-issue with token AGAIN | HTTP 400 `iawm_invalid_confirmation` (single-use) |
| Get a token for `{ id: 1 }`, submit it for `{ id: 2 }` | HTTP 400 `iawm_invalid_confirmation` (body hash mismatch) |
| Use a token 6 minutes after issue | HTTP 400 `iawm_invalid_confirmation` (TTL expired) |

### Database tools

| Probe | Expected |
|-------|----------|
| `iawm_database_query({ sql: "DROP TABLE wp_users" })` | HTTP 400 `iawm_invalid_query` |
| `iawm_database_query({ sql: "SELECT 1; DROP TABLE wp_users" })` | HTTP 400 (semicolon refused) |
| `iawm_database_query({ sql: "SELECT * FROM wp_options INTO OUTFILE '/tmp/x'" })` | HTTP 400 |
| `iawm_database_query({ sql: "SELECT BENCHMARK(10000000, MD5('a'))" })` | HTTP 400 |
| `iawm_database_search_replace({ search: 'x', replace: 'y', targets: [["wp_users", "user_pass"]] })` | HTTP 403 `iawm_target_not_allowed` |

### Kill switch

| Probe | Expected |
|-------|----------|
| Turn on the kill switch. Call any write tool | HTTP 403 `iawm_kill_switch` |
| Call any read tool | HTTP 200 |

### Audit log

| Probe | Expected |
|-------|----------|
| Run every probe above | Each failed call shows up in `iawm_audit` with the right `outcome` (`denied`, `error`) |

If any row in the table above behaves differently, file an issue
before publishing the release.

---

## Translation workflow

The plugin's user-visible strings are wrapped in WordPress gettext calls
(`__`, `esc_html__`, `esc_attr__`, `esc_html_e`, `_n`, …) with the
text-domain `ia-webmaster-bridge`. The `Text Domain` and `Domain Path`
plugin headers point WordPress at the `languages/` folder, and a
`plugins_loaded` callback in `ia-webmaster-bridge.php` registers the
domain via `load_plugin_textdomain()`.

### Source of truth

- `plugin/ia-webmaster-bridge/languages/ia-webmaster-bridge.pot` — the
  canonical list of English source strings, regenerated from the PHP
  source by `tools/extract-pot.mjs`.
- `plugin/ia-webmaster-bridge/languages/ia-webmaster-bridge-fr_FR.po`
  — the French translation (paired `.mo` shipped alongside).

### Regenerate the `.pot`

```bash
node tools/extract-pot.mjs
```

The script walks every `.php` file in the plugin, extracts every call
whose text domain is `ia-webmaster-bridge`, preserves the
`/* translators: ... */` comments that document placeholder usage,
and writes a sorted POT file. Plurals (`_n`) are emitted as
`msgid` / `msgid_plural` pairs.

### Update an existing locale

1. Re-run `node tools/extract-pot.mjs` to refresh the POT.
2. Open `ia-webmaster-bridge-<locale>.po` in a translation editor
   (Poedit, GTranslator, or any text editor) and synchronise the
   entries against the new POT — add any missing strings, drop any
   that disappeared.
3. Recompile the binary `.mo`:

   ```bash
   node tools/compile-mo.mjs plugin/ia-webmaster-bridge/languages/ia-webmaster-bridge-<locale>.po
   ```

   That script is a pure-Node implementation of the GNU gettext MO
   format; no `msgfmt` or wp-cli required. If you do have `msgfmt`
   handy, `msgfmt path/to/file.po -o path/to/file.mo` is equivalent.

### Add a new locale

1. Pick the locale slug (BCP-47 with a region, e.g. `es_ES`, `de_DE`).
2. Copy the POT to `ia-webmaster-bridge-<locale>.po`.
3. Fill in the PO header (`Language:`, `Plural-Forms:`, …) and
   translate the `msgstr` values.
4. Compile the `.mo` (see above).
5. Commit both the `.po` and the `.mo`.

WordPress loads the `.mo` matching the site's active locale; if no
matching file is found, the original English strings are displayed —
no fallback is required.
