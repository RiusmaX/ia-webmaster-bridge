# Production deployment — IA Webmaster Bridge

> Status: Living · Last updated: 2026-05-25

This document is the operator's path from a fresh WordPress install to
a hardened, monitored IA Webmaster Bridge deployment with the agent
running in read-only mode and a documented Go/No-Go for opening writes.

For day-to-day operations after install, see
[`operations.md`](operations.md). For the layered security model and
threat analysis, see [`security-model.md`](security-model.md).

---

## Pre-install checklist

Tick every item before touching the production site.

- [ ] **WordPress 7.0+** running and reachable.
- [ ] **PHP 7.4+** (PHP 8.1+ recommended).
- [ ] **MySQL 5.7+** or MariaDB 10.3+.
- [ ] **HTTPS reachable** on the target domain (valid certificate). The
      gateway refuses to talk to plain HTTP in production posture, and
      the plugin will refuse all requests when `IAWM_REQUIRE_HTTPS` is
      set (see below).
- [ ] **WP-Cron strategy**. The plugin's audit/backup rotation runs via
      WP-Cron. On a low-traffic site WP's built-in pseudo-cron is
      unreliable. **Strongly recommended** for production: disable
      internal cron and trigger it from system cron.

      In `wp-config.php`:

      ```php
      define( 'DISABLE_WP_CRON', true );
      ```

      In your server's crontab:

      ```
      * * * * * curl -s https://example.com/wp-cron.php?doing_wp_cron > /dev/null
      ```

- [ ] **Off-site backup of files + DB**, daily, retained ≥ 14 days. The
      plugin's `wp_iawm_backups` table is for snapshots of specific
      WP state before destructive ops (options, plugin activation,
      individual SQL tables). It is **not** a substitute for a real
      backup of the filesystem and full database.
- [ ] **Local validation passed**. Walk
      [`validation-checklist.md`](validation-checklist.md) on a local
      clone before going to production.

---

## Install steps

1. **Upload the plugin folder** to `wp-content/plugins/`:

   ```bash
   rsync -av plugin/ia-webmaster-bridge/ \
     user@host:/var/www/example.com/wp-content/plugins/ia-webmaster-bridge/
   ```

2. **Activate the plugin** (admin → Plugins, or wp-cli):

   ```bash
   wp plugin activate ia-webmaster-bridge
   ```

   Activation creates the `iawm-agent` WP user, the `iawm_agent`
   custom role, the `wp_iawm_audit_log` and `wp_iawm_backups` tables,
   and registers the daily rotation cron jobs.

3. **Harden `wp-config.php`** (see next section).

4. **Visit Settings → IA Webmaster Bridge**. Check the top status bar
   reads **Operational** and the agent user health is green.

5. **Configure the IP allow-list** (Settings → IA Webmaster Bridge →
   Security tab). One entry per line, CIDR or plain IP, IPv4 or IPv6:

   ```
   203.0.113.42
   2001:db8::/48
   ```

   Add only your operator workstation's static IP and/or your VPN
   range. Loopback is always allowed.

6. **Generate the first API key** (API Keys tab → Create a new key):

   - Label: `Operator — bootstrap`.
   - Scopes: **`read` only** for the first key.
   - Linked WP user: your own admin account (audit attribution only).
   - Copy the secret immediately; it is shown once.

7. **Configure the operator machine**. On the workstation that runs
   Claude Code, create `~/.iawm/config.json`:

   ```json
   {
     "baseUrl": "https://example.com",
     "keyId": "iawm_xxxxxxxxxxxx",
     "secret": "the-secret-shown-once-in-admin"
   }
   ```

8. **Install the Claude Code plugin**:

   ```
   /plugin marketplace add RiusmaX/ia-webmaster-bridge
   /plugin install ia-webmaster@ia-webmaster-bridge
   ```

   Restart Claude Code so the gateway loads the new credentials.

9. **Run the smoke test sequence** (next section). If every step is
   green, you have a working read-only install.

---

## `wp-config.php` hardening

Two project-specific constants are placed in `wp-config.php`, both
above the `/* That's all, stop editing! */` line:

```php
// Plugin refuses any non-HTTPS request. Mandatory in production.
// Set on the file system, not in the admin UI: even a compromised
// WP admin cannot disable HTTPS enforcement.
define( 'IAWM_REQUIRE_HTTPS', true );

// Only if your site sits behind a reverse proxy / load balancer that
// rewrites the client IP into X-Forwarded-For. Leave commented if
// the site is exposed directly — trusting the header in that case
// would let an attacker spoof their source IP.
// define( 'IAWM_TRUST_PROXY_HEADER', true );
```

The "why HTTPS as a constant rather than an admin toggle" reasoning
is documented in [`security-model.md`](security-model.md) and
[`decisions.md`](decisions.md) (D-022).

---

## First settings page visit

After activating + hardening, open **Settings → IA Webmaster Bridge**
and verify:

- **Status bar (top)** reads **Operational**, the border is blue (not
  red).
- **Agent user health** is green: `iawm-agent` exists, role
  `iawm_agent`, capabilities OK.
- **Key count** is 1 (your bootstrap key).
- **Kill switch** is OFF.
- **HTTPS state** shows "Enforced (constant set)".
- **IP allow-list** shows your workstation's IP.
- **Cleanup tab**: audit retention 90 days, backup retention 50, next
  cron runs visible and dated in the future.

---

## Smoke test sequence

In Claude Code, run these three calls in order. All three must return
`ok: true` before moving on.

```
iawm_status                       → basic ping + identity check
iawm_diagnostics_check_self       → invariants (agent user, tables,
                                    cron jobs, credentials, HTTPS)
iawm_diagnostics_smoke            → live health probe (HTTP front
                                    page, debug.log fatals, Divi)
```

Expected:

- `iawm_status` returns `key_label` matching your bootstrap key.
- `iawm_diagnostics_check_self` returns every check `ok` (or with an
  explained `warn` — never `error`).
- `iawm_diagnostics_smoke` returns `healthy: true`.

If any of these fails, **do not proceed** to writes. Diagnose with
[`operations.md`](operations.md) → "SSH / WP-CLI fallback channel".

---

## Monitoring — what to watch

Three log surfaces in production:

| Surface | What to watch | How |
|---|---|---|
| Plugin audit log | `outcome=denied` entries (clusters of denials = probing) | `iawm_audit({ outcome: "denied", limit: 50 })` or the Audit tab in admin |
| WP `debug.log` | Any new fatal entry near the time of a destructive op | `iawm_diagnostics_logs({ lines: 200 })` |
| `wp_iawm_backups` table size | Drift > 50 rows (rotation broken?) | `iawm_diagnostics_check_self` reports the row count |

Suggested SQL for a weekly review (over WP-CLI or `iawm_database_query`):

```sql
SELECT outcome, COUNT(*) AS n
FROM wp_iawm_audit_log
WHERE created_at >= NOW() - INTERVAL 7 DAY
GROUP BY outcome;
```

`denied` or `error` counts > 0 deserve investigation.

---

## Go / No-Go: opening writes

Once the read-only install is stable and the smoke test sequence is
green for at least 24 hours, you can expand the bootstrap key's scopes
(or, preferred, generate a second key with the writes you want and
keep the read-only key for diagnostics).

Tick all of these before you open any `*:write` scope:

- [ ] Smoke test green twice in a row, 12 h apart.
- [ ] `iawm_audit` shows zero `denied` entries from your IP in the
      last 24 h (denials = config drift).
- [ ] You have an off-site filesystem + DB backup from the last 24 h.
- [ ] WP-Cron is firing (audit log retention cron has run at least
      once — check `iawm_cron_list({ hook: "iawm_prune_audit_log" })`).
- [ ] You have read [`operations.md`](operations.md) → "Safe plugin
      update workflow" and [`security-model.md`](security-model.md) →
      "Incident response".
- [ ] The kill switch is documented in your team's runbook — every
      operator knows where to flip it in case of trouble.

Open scopes incrementally, smallest to largest:

1. `content:write` first (low blast radius — drafts by default).
2. `divi:write` next (page composition; still draft-by-default).
3. `config:write` only when needed (settings, users).
4. `infra:write` last and only behind a fresh secondary key — that
   key alone can install plugins, switch themes, update core, run
   `search-replace`. Every action through that scope is also gated
   by confirmation tokens and pre-op backups, but it remains the
   most dangerous scope to issue.

---

## Operational rituals

Three cadences keep the install healthy long-term:

- **Weekly** — review `outcome=denied` entries in the audit log. Most
  will be legitimate (an agent attempt that hit a guardrail). Any
  pattern that isn't legitimate is a probe — investigate and, if
  needed, tighten the IP allow-list.
- **Monthly** — rotate at least one secret. With multi-key support
  this is zero-downtime: create a new key, switch the gateway over,
  revoke the old one. See [`operations.md`](operations.md) → "Key
  rotation".
- **Quarterly** — walk the
  [`operations.md`](operations.md) → "Penetration testing checklist"
  against a staging clone. Any deviation from expected behaviour is a
  bug.

---

## Related documents

- [`security-model.md`](security-model.md) — eight defence layers,
  threat model, incident response.
- [`operations.md`](operations.md) — operator runbook (rotation,
  multi-operator, WP-CLI fallback, pentest checklist).
- [`phase-7-action-plan.md`](phase-7-action-plan.md) — current sprint
  to v1.0.0.
- [`../CHANGELOG.md`](../CHANGELOG.md) — version history.
