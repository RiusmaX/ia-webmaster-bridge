# Operations runbook

> Status: Living · Last updated: 2026-05-25 (multisite section)

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

## Multisite

The plugin is multisite-tolerant. Most operators run single-site
installs and can ignore this section, but the headline rules for a
WordPress multisite network are:

- **Network-activate the plugin** from Network Admin → Plugins if every
  sub-site of the network should be reachable via the bridge. The
  activation walks every existing sub-site and provisions its tables,
  role and role assignment. New sub-sites are auto-provisioned at
  creation time.
- **Per-site activate** instead if only one or two blogs need the
  bridge.
- **One key per sub-site.** Keys are per-site, the kill switch is
  per-site, the audit log is per-site. Each blog you operate gets its
  own profile in `~/.iawm/config.json`.
- The dedicated agent user (`iawm-agent`) is **global** — one user
  across the whole network — but its `iawm_agent` role is granted
  **per sub-site** (never network-wide super-admin).
- A read-only network admin overview is added at **Network Admin →
  Settings → IA Webmaster Bridge**: one row per sub-site with key
  count, kill switch state, last audit timestamp and next cron run.
- From Claude, call the `iawm_status_network` MCP tool to discover
  whether the connection is on a multisite and which blog it targets.

Full details, design rationale and known limitations live in
[`multisite.md`](multisite.md).

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

## Broken links

The plugin ships a proactive scanner that walks every published post +
page, extracts each `<a href>` from `post_content`, probes the target
with `wp_remote_head` (falling back to `wp_remote_get` when the remote
refuses HEAD), and records non-OK outcomes in `wp_iawm_link_issues`.
It complements the reactive 404 tracker: the scanner finds broken
links **before** a visitor hits one, the tracker reports what visitors
have **already** failed to load.

### Scan workflow

1. `iawm_links_scan({ dry_run: true })` — preview the next scan
   without writing rows. Useful to size the run on a new site (the
   response surfaces `scanned_links`, `issues_found` and
   `duration_ms`).
2. `iawm_links_scan()` — full scan. Note the returned
   `issues_new` (rows actually inserted, ignoring duplicates of
   still-unresolved findings) — that's the number to triage.
3. `iawm_links_list({ outcome: "404" })` — review by bucket. Buckets:
   `404`, `410`, `timeout`, `dns`, `ssl`, `other`.
4. Fix the link in the source post, then either:
   - `iawm_links_resolve({ issue_id })` to close it (keeps an audit
     row, pruned after the retention window), or
   - `iawm_links_delete({ issue_id })` to remove it entirely.

### Sizing and cadence

A scan is **synchronous** and capped at 500 URLs by default
(`iawm_link_checker_max_per_scan`). Most of the wall time is remote
HTTP latency — budget several seconds per ~50 links. For sites that
exceed the cap, do NOT auto-schedule the scan from the plugin: drive
it from a cron job set up with the `scheduled-routines` skill so the
operator owns the cadence (weekly is a sensible default for medium
sites).

To bound the blast radius on a big site:

```
iawm_links_scan({ post_ids: [12, 34, 56], include_external: true })
```

Scoping to a list of post IDs keeps the run predictable and avoids
hitting unrelated remote hosts.

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

## 404 monitoring

The plugin records every 404 hit it receives so the operator (and the
agent) can investigate broken inbound URLs over time. This is the
**reactive** counterpart to the broken-links scanner: it tells you what
the outside world is actually requesting and failing to fetch, whereas
the scanner audits your own content proactively.

### What gets logged

Every front-end 404 fires `template_redirect` at priority 999 (so any
redirect plugin that might rescue a 404 has had its chance first). At
that point the tracker checks:

- The request is genuinely a 404 (`is_404()` true).
- The path is NOT in the always-skipped list:
  `/wp-admin`, `/wp-login.php`, `/wp-cron.php`, `/xmlrpc.php`, `/feed`
  (or any `*/feed` variant). These are infrastructure paths whose 404s
  are noise.
- The sampling roll fires. Sampling rate is the denominator of a 1/N
  probability, configured via the `iawm_404_sampling_rate` option:
  `1` (default) records everything, `10` records 1 in 10, `100`
  records 1 in 100. The default is full capture — only crank it up if
  the table grows unmanageable on a high-traffic site.

When the request passes those checks, a row is either inserted or its
counter is bumped:

- **Dedup key**: `sha1(requested_url + '|' + ip)`, stored as a transient
  with a 60-second TTL. On a hit, we increment the most recent row's
  `hit_count` + `last_seen` — no new row. On a miss, we set the
  transient and INSERT a fresh row with `hit_count = 1`. The intent: a
  single retrying crawler folds into one row, while three different
  visitors hitting the same broken URL each get their own (the
  distinction is useful when investigating).

Schema (`wp_iawm_404_log`):

| Column          | Type           | Notes |
|-----------------|----------------|-------|
| `id`            | BIGINT PK      | Auto-increment row id |
| `created_at`    | DATETIME (GMT) | First time this URL+IP was seen |
| `requested_url` | VARCHAR(2048)  | Path + query, indexed on a 191-char prefix |
| `referer`       | VARCHAR(2048)  | `HTTP_REFERER`, may be NULL |
| `user_agent`    | VARCHAR(512)   | Truncated to 512 chars |
| `ip`            | VARCHAR(45)    | IPv4 or IPv6 |
| `hit_count`     | INT UNSIGNED   | Burst counter, ≥ 1 |
| `last_seen`     | DATETIME (GMT) | Updated on every dedup-bump |

### Retention

The cron job `iawm_prune_404_log` fires daily at 03:30 site time and
deletes rows older than `iawm_404_retention_days` (default **30 days**,
clamped to [1, 365]). The three rotation jobs are offset so they don't
all hit wpdb at the same minute: audit at 03:00, backup at 03:15, 404 at
03:30.

### How to investigate

The agent has four MCP tools for this:

- `iawm_404_list` — paginated list of recent rows. Default window is the
  last 7 days; pass `since` (ISO 8601) to widen it.
- `iawm_404_stats` — aggregate counters, top 10 missing URLs by total
  hits, top 5 referers. The fast first read when you don't know where
  to start.
- `iawm_404_delete` — remove a single row once you've resolved the
  underlying broken link (or installed a redirect).
- `iawm_404_clear` — empty the whole table. Two-step destructive
  operation: the first call returns a confirmation token + a summary
  showing how many rows will be deleted; the second call applies it.

Typical workflow:

1. *"Show me the top 404s of the last 30 days."* — Claude calls
   `iawm_404_stats`, sorts the surface by hit count.
2. For each high-volume URL, decide:
   - **Stale internal link** → fix it in the source page.
   - **Removed page, search engine still indexes it** → add a 301
     redirect (or restore the page if it should still exist).
   - **Bot probing for vulnerabilities** (`/wp-config.php~`,
     `/.env`, …) → ignore; the WAF/firewall layer is the right place
     to block these.
3. Delete the row once handled, or leave it and rely on the 30-day
   retention to age it out.

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

---

## Consuming an IA Webmaster webhook

When you register an outbound webhook via `iawm_webhooks_create`
(Phase 9.4), the plugin POSTs signed JSON envelopes to your
`destination_url` whenever any of the subscribed `events` fire (today
that's just `smoke.failed`; future versions will add `audit.alert`
and key-rotation reminders). Receivers verify the signature to make
sure the request really came from this site.

See [`D-030`](decisions.md#d-030--outbound-webhooks-hmac-sha256-over-ts--n-nonce--n-body-5-min-drainer-3-attempt-dead-letter)
for the full design rationale and trade-offs.

### Envelope

The body is `application/json` and looks like:

```json
{
  "event": "smoke.failed",
  "site_url": "https://example.com",
  "fired_at": "2026-05-25T14:32:18Z",
  "payload": {
    "ok": true,
    "healthy": false,
    "checks": { "...": "..." },
    "time": "2026-05-25T14:32:17+00:00"
  }
}
```

### Headers

Every POST carries three custom headers in addition to the usual
`Content-Type: application/json`:

| Header | Value |
|--------|-------|
| `X-IAWM-Webhook-Timestamp` | Unix epoch seconds at signing time |
| `X-IAWM-Webhook-Nonce`     | 16 random bytes, hex-encoded (32 chars) |
| `X-IAWM-Webhook-Signature` | `sha256=` + hex HMAC-SHA256 of the canonical string (see below), using the `signing_secret` you set at create time |

### Canonical signing string

The string fed into HMAC-SHA256 is exactly three lines, joined with
literal `\n` (LF) newlines, in this order:

```
<timestamp>
<nonce>
<raw request body, byte-for-byte>
```

There is no leading or trailing newline. The body is the raw bytes
you receive — do not re-serialize the parsed JSON before hashing or
the signature will not match.

### Verification (Node example)

```js
import crypto from "node:crypto";
import express from "express";

const app = express();
// Important: capture the raw body so the HMAC is computed over
// exactly what we received, byte for byte.
app.use(
  express.raw({ type: "application/json", limit: "1mb" }),
);

const SECRET = process.env.IAWM_WEBHOOK_SECRET; // same value you gave to iawm_webhooks_create
const MAX_SKEW_SECONDS = 5 * 60;

app.post("/iawm-hook", (req, res) => {
  const ts        = String(req.header("X-IAWM-Webhook-Timestamp") || "");
  const nonce     = String(req.header("X-IAWM-Webhook-Nonce") || "");
  const signature = String(req.header("X-IAWM-Webhook-Signature") || "");
  const body      = req.body instanceof Buffer ? req.body : Buffer.from("");

  // 1. Timestamp must be within ±5 minutes of now.
  const skew = Math.abs(Math.floor(Date.now() / 1000) - Number(ts));
  if (!ts || Number.isNaN(skew) || skew > MAX_SKEW_SECONDS) {
    return res.status(401).send("stale or missing timestamp");
  }

  // 2. Recompute the signature.
  const message = `${ts}\n${nonce}\n${body.toString("utf8")}`;
  const expected = "sha256=" + crypto
    .createHmac("sha256", SECRET)
    .update(message)
    .digest("hex");

  // 3. Constant-time compare.
  const a = Buffer.from(expected);
  const b = Buffer.from(signature);
  if (a.length !== b.length || !crypto.timingSafeEqual(a, b)) {
    return res.status(401).send("bad signature");
  }

  // 4. (Optional) Cache the nonce for at least 10 minutes to harden
  //    against replay. The timestamp check alone is enough for most
  //    use cases — see D-030.

  const envelope = JSON.parse(body.toString("utf8"));
  console.log("verified", envelope.event, envelope.fired_at);
  res.status(200).end();
});

app.listen(3000);
```

### Verification (Python pseudo-code)

```python
import hmac, hashlib, time

def verify(headers, raw_body: bytes, secret: str) -> bool:
    ts    = headers.get("X-IAWM-Webhook-Timestamp", "")
    nonce = headers.get("X-IAWM-Webhook-Nonce", "")
    sig   = headers.get("X-IAWM-Webhook-Signature", "")

    if not ts or abs(int(time.time()) - int(ts)) > 5 * 60:
        return False

    msg = f"{ts}\n{nonce}\n".encode("utf-8") + raw_body
    expected = "sha256=" + hmac.new(
        secret.encode("utf-8"), msg, hashlib.sha256
    ).hexdigest()
    return hmac.compare_digest(expected, sig)
```

### Operational notes

- A receiver that returns any non-2xx status is treated as a delivery
  failure. The plugin retries with exponential backoff (1m, 5m, 30m)
  for up to 3 attempts before dead-lettering the row.
- Receivers must respond within the 10-second HTTP timeout. Long
  processing belongs in a queue on your side, not in the
  request handler.
- Test a freshly-registered webhook with `iawm_webhooks_test` — it
  sends a synthetic `test.ping` envelope immediately (bypassing the
  outbox) and returns the receiver's HTTP status + a short body
  excerpt.
- To rotate the signing secret without losing history, call
  `iawm_webhooks_update` with a fresh `signing_secret`. Update the
  receiver to accept either the old or the new secret for the
  duration of the rotation window, then drop the old one.
