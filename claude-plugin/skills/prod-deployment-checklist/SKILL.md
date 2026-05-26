---
name: prod-deployment-checklist
description: Walk through a fresh production install of IA Webmaster Bridge. Covers pre-flight checks, plugin install, key minting, hardening (HTTPS + IP allow-list), local config, and the read-only-first verification path before any write scope is granted.
---

# Production deployment checklist — IA Webmaster Bridge

## Goal

Install and configure the **IA Webmaster Bridge** plugin on a production
WordPress site safely, the first time. The path is intentionally
conservative: hardening before keys, read-only verification before write
scopes, smoke + self-check pass before declaring the site managed.

## When to use it

- First-time deployment on a new client site.
- Re-deployment after a server migration or a major bridge upgrade where
  the trust state needs to be re-established from scratch.

## Pre-checks (do these before touching the plugin)

- **WordPress 7.0+** running (`iawm_diagnostics_system` once the plugin
  is in, but you can confirm via WP admin → Updates beforehand).
- **HTTPS reachable** at the canonical site URL (the bridge will refuse
  to mint keys when `IAWM_REQUIRE_HTTPS` is on and the request is plain
  HTTP — that is the whole point).
- **PHP 7.4+** active (PHP 8.1+ recommended).
- **Daily off-site backup** configured (UpdraftPlus / BlogVault /
  hosting-provider snapshot). The bridge takes per-op snapshots, but
  those are not a replacement for off-site backups.
- An **agent WP user** exists (role `administrator`, dedicated to the
  bridge — not your personal account). If not, create it now.

If any pre-check fails: **stop**. Fix the environment first.

## Install

1. Copy the plugin folder to `wp-content/plugins/ia-webmaster-bridge/`
   (via SFTP, deploy pipeline, or `wp plugin install` from a local zip).
2. Activate:
   - WP admin → Plugins → **Activate** "IA Webmaster Bridge", **OR**
   - `wp plugin activate ia-webmaster-bridge`
3. Visit **Settings → IA Webmaster Bridge** for the first time. Confirm:
   - Status bar reads "Operational".
   - The agent user dropdown shows your dedicated agent account as
     "healthy".
   - No red banners about missing tables / missing secret-key file.

## Mint the first API key

Open the **Users / API keys** tab and create the first key:

- **Label**: human-readable, e.g. `claude-marius-laptop`.
- **Linked WP user**: the human operator account (NOT the agent user —
  the linked user is the principal, audited identity).
- **Scopes**: tick **only `read`** for the first key. Write scopes come
  later, after verification.
- **Expiry** (optional): set if your policy requires it.

Capture the `(key_id, secret)` pair shown **once**. The secret is never
displayed again.

## Hardening (apply BEFORE using the key)

### Force HTTPS

In `wp-config.php`, above the `/* That's all, stop editing! */` line:

```php
define('IAWM_REQUIRE_HTTPS', true);
```

### IP allow-list

In the bridge settings → **Security** tab, add the IP allow-list:

- Your workstation's outbound IP, **or**
- The VPN range that your operators sit behind.

Save. Confirm the page reloads without locking you out.

## Configure the operator machine

On the local machine where Claude Code runs, write `~/.iawm/config.json`
(per the project convention — gitignored):

```json
{
  "sites": {
    "client-xyz": {
      "url": "https://www.example.com",
      "key_id": "kid_xxxxxx",
      "secret": "secret_yyyyyy"
    }
  }
}
```

Do **not** commit this file. Do **not** paste secrets into chat history.

## Read-only verification path

Run, in this exact order, before granting any write scope:

### 1. Connection probe

```
iawm_status()
```

Expect HTTP 200, the `key_label` you set, no kill switch active. If
anything else: stop, fix the credentials or the allow-list.

### 2. Smoke test

```
iawm_diagnostics_smoke()
```

Expect `healthy: true`, every probe `ok`. This proves the site is
operational and the bridge can read system state.

### 3. Self-check

```
iawm_diagnostics_check_self()
```

Expect verdict `ok` — file integrity, signing-key roundtrip, audit-log
write path, hook coverage all healthy.

### 4. Audit baseline

```
iawm_audit({ limit: 5 })
```

You should see the read calls you just made. **Note the most recent
audit ID** — that is your "site is now managed" timestamp.

## Promote to write scopes

Only **after** the three checks above are green:

- Edit the API key in the bridge settings, tick the scopes the operator
  actually needs (`content:write`, `divi:write`, `infra:write`, etc.).
- Re-export the key if your gateway caches scope locally — do **not**
  rotate the secret unless you are also re-deploying the local config.
- Run one harmless write (e.g. create a draft page titled "deployment
  smoke") and confirm it appears in `iawm_audit`. Delete the draft after.

## Configure observability (optional but recommended)

### Webhook for smoke-test failures

If the operator wants to be paged when the site goes unhealthy, wire
at least one outbound webhook subscribed to `smoke.failed` before
opening the door. Reach for the `webhook-setup` skill — it walks
through Slack vs generic JSON receiver vs PagerDuty, generates the
signing secret, and verifies HMAC validation on the receiver side.

Quick decision aid:
- **No alerting infra yet?** Slack incoming webhook — fastest, no
  signature verification on Slack's side but useful for visibility.
- **Pager rotation in place?** Route through a verifying receiver
  (PagerDuty Events v2 or similar) so leaked URLs can't inject
  fake events.

### Audit-log pseudonymisation

If a **third party can read the audit log** — most commonly a hosting
log shipper, a SIEM agent, a monitoring sidecar, or a read-only
monitoring key — enable pseudonymisation so sensitive parameters
(user passwords on creation, webhook signing secrets) are stored as
SHA-256 short-prefix sentinels (`<redacted:sha256:abc123def456>`)
instead of plaintext.

Toggle lives in **WP admin → IA Webmaster Bridge → Cleanup**
(`iawm_audit_pseudonymise` option, default off for v1.3.0+
back-compat). Existing audit rows stay readable as-is; only new
writes apply the masking.

Confirm it's working after enabling:

```
iawm_audit({ limit: 1 })   # after a config/users/create call
```

The `params.password` field should show `<redacted:sha256:...>` and
NOT the cleartext value. If it still shows cleartext, double-check
the toggle persisted (the option is per-site on multisite).

Decision rationale: D-031.

### Audit-alert webhooks (v1.4+)

If the audit pseudonymisation is on AND a webhook is registered for
`audit.alert`, the audit tail watcher (every 5 min cron) will fire
when one of the rules trips:

- `scope_denied_burst` — 5+ scope-denied responses from the same key
  in 60 s
- `kill_switch_toggled` — fires once per toggle
- `auth_failure_burst` — 10+ HMAC verification failures from the
  same IP in 60 s

Operator toggles in the same Cleanup tab. Decision D-030.

## Document the baseline

Record, in the project notes (outside this repo):

- Site URL, agent WP user, key label, scopes granted, allow-listed IPs.
- The "site is now managed" audit ID from the verification step.
- Backup provider + restore-test date.

That gives the next operator (or future you) a clean starting state to
diff against if anything looks off.

## When NOT to use this skill

- You are re-installing on an environment that **already has** the bridge
  in trust state. Use the `safe-plugin-update` or upgrade flow instead —
  do not mint new keys and re-set hardening, you will just leak secrets.
- Local development against a LocalWP site over plain HTTP. The hardening
  steps (HTTPS, allow-list) do not fit, and the read-only verification
  loop adds friction without security gain. Use a local-only key with
  `IAWM_REQUIRE_HTTPS` off, and skip the IP allow-list.
- The site is not yet on WordPress 7.0+ or PHP 7.4+. The bridge will
  refuse to activate cleanly — upgrade the platform first.
- Staging or QA where the operator is fine accepting a wider scope from
  day one. This checklist optimises for **production** safety; on
  ephemeral environments the friction is wasted.
