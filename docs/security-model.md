# Security model — IA Webmaster Bridge

> Status: Living · Last updated: 2026-05-25

This document describes the threat model the plugin defends against,
the eight layers of defence in depth that implement that posture, and
the incident response procedures when a layer fails.

For the operational procedures (key rotation, audit review, WP-CLI
fallback), see [`operations.md`](operations.md). For the day-one
install path, see [`production-deployment.md`](production-deployment.md).

---

## Defence in depth — eight layers

Every incoming HTTP request passes through these gates, in order.
Failure at any gate stops the request before the next gate runs, and
the failure mode is recorded in the audit log.

```
   ┌─────────────────────────────────────────────────────────┐
   │  Request arrives at /wp-json/ia-webmaster/v1/<route>    │
   └─────────────────────────────────────────────────────────┘
                              │
        ┌─────────────────────▼─────────────────────┐
   1.   │  HTTPS enforcement   (IAWM_REQUIRE_HTTPS) │   constant in wp-config.php
        └─────────────────────┬─────────────────────┘
                              │
        ┌─────────────────────▼─────────────────────┐
   2.   │  IP allow-list      (iawm_ip_allowlist)   │   admin UI
        └─────────────────────┬─────────────────────┘
                              │
        ┌─────────────────────▼─────────────────────┐
   3.   │  HMAC signature + nonce + time window     │   per-request
        └─────────────────────┬─────────────────────┘
                              │
        ┌─────────────────────▼─────────────────────┐
   4.   │  Key resolution by key_id  (multi-key)    │   credentials store
        └─────────────────────┬─────────────────────┘
                              │
        ┌─────────────────────▼─────────────────────┐
   5.   │  Scope check         (read / *:write)     │   per-key
        └─────────────────────┬─────────────────────┘
                              │
        ┌─────────────────────▼─────────────────────┐
   6.   │  Kill switch          (iawm_kill_switch)  │   site-wide
        └─────────────────────┬─────────────────────┘
                              │
        ┌─────────────────────▼─────────────────────┐
   7.   │  Audit log (every outcome, success or not)│   wp_iawm_audit_log
        └─────────────────────┬─────────────────────┘
                              │
        ┌─────────────────────▼─────────────────────┐
   8.   │  Auto pre-op backup + confirmation tokens │   wp_iawm_backups
        └─────────────────────┬─────────────────────┘
                              ▼
                       Request handler runs
```

The first six layers gate access. The seventh records every outcome,
allowed or denied. The eighth contains the blast radius of any
destructive operation that did get through — every destructive write
takes a snapshot first and the most dangerous routes require a
single-use, body-bound confirmation token.

### Per-layer details

1. **HTTPS enforcement.** When `IAWM_REQUIRE_HTTPS` is `true` in
   `wp-config.php`, the auth guard refuses any request whose
   `$_SERVER['HTTPS']` is empty or `off`. Returns HTTP 403
   `iawm_https_required`. The constant is in `wp-config.php` (not the
   admin UI) so a compromised admin account cannot disable it. See
   D-022.
2. **IP allow-list.** Option `iawm_ip_allowlist`, edited from the
   admin Security tab. Empty = allow-all (back-compat). Supports
   CIDR and single IPs, IPv4 and IPv6. Loopback always passes (to
   avoid lock-out via WP-CLI). Honoured behind a reverse proxy only
   if `IAWM_TRUST_PROXY_HEADER` is set — otherwise we trust
   `REMOTE_ADDR`. Miss returns HTTP 403 `iawm_ip_not_allowed` with no
   detail leakage.
3. **HMAC signature.** SHA-256 HMAC of `(method | path | body |
   timestamp | nonce)` against the per-key secret. Replay protection
   via a single-use nonce (5 min window) and tight timestamp
   tolerance (5 min). Miss → HTTP 401, audit-logged.
4. **Key resolution.** `X-IAWM-Key` header carries the public key id;
   the plugin looks it up in the credentials map. Unknown key id →
   HTTP 401, `unknown_key`. Each key carries its own secret, scopes,
   label, optional linked WP user (audit attribution).
5. **Scope check.** Each route declares the scope it needs (`read`,
   `content:write`, `divi:write`, `config:write`, `infra:write`).
   The key's scope list must contain it. Miss → HTTP 403
   `iawm_scope_denied`, with the required scope in the error body.
6. **Kill switch.** Option `iawm_kill_switch`. When on, every write
   route returns HTTP 403 `iawm_kill_switch`. Reads still pass — by
   design, so diagnostics work during an incident.
7. **Audit log.** Every request — allowed or denied — writes a row to
   `wp_iawm_audit_log` with `created_at, route, method, outcome,
   key_id, key_label, linked_user_id, ip, error`. Daily WP-Cron job
   `iawm_prune_audit_log` deletes rows older than
   `iawm_audit_retention_days` (default 90).
8. **Backup + confirmation tokens.** Destructive endpoints
   (`plugins/install/activate/deactivate`, risky settings updates,
   themes write paths, branding write) automatically take an
   `IAWM_Backup` snapshot before applying. The new backup id is
   surfaced as `pre_op_backup_id`. The most destructive routes
   (`/backup/restore`, `/core/update`, `/database/search-replace`)
   additionally require a confirmation token — a single-use,
   body-bound, 5-minute-TTL nonce that the caller must obtain via a
   first dry-run call. See D-015.

---

## What we defend against

| Threat | Layer that catches it |
|---|---|
| **(a) Network sniffing** — passive interception of the secret or payload on the wire | 1 (HTTPS) |
| **(b) Credential leak from the operator's workstation** — `~/.iawm/config.json` exfiltrated | 2 (IP allow-list narrows the abuse), 5 (scope limits blast radius), 6 (kill switch as emergency stop) |
| **(c) Replay** — capture + replay of a previously valid signed request | 3 (single-use nonce + timestamp window) |
| **(d) Cross-key scope escalation** — a `read`-scoped key tries to call a write endpoint | 5 (scope check, before any business logic runs) |
| **(e) Accidental destructive op by the agent** — wrong restore id, wrong search-replace pattern | 8 (auto pre-op backup + confirmation token), 7 (audit forensic) |
| **(f) Malicious or buggy hook on the WP side** — a third-party plugin attempts to elevate the agent | The dedicated `iawm-agent` user has a stripped-down role (D-011): no `unfiltered_html`, no `edit_plugins/themes/files`, no multisite super-admin. Even unrestricted code execution under the agent's identity cannot edit code files via WordPress's own APIs. |
| **(g) Self-destruction** — agent tries to delete the agent user, disable the bridge plugin or self-update it | Hard refusals at the handler level (`iawm_protected_user`, `cannot_disable_self`, `cannot_self_update`); see operations.md → pentest checklist |
| **(h) Brute-force on the HMAC secret** | 256-bit cryptographic secret; no offline attack path without a captured signed request; HTTPS prevents capture (a) |

---

## What we DON'T defend against

Explicit non-goals — these are out of scope and require operator
discipline or different tooling.

- **Compromised WP admin account.** A real super-admin can already
  install plugins, change roles, dump the DB. The plugin doesn't and
  can't prevent that — it's a different threat model. The plugin
  does ensure that a compromised admin cannot *silently* disable the
  HTTPS gate (constant in `wp-config.php`, not in the admin UI).
- **Server-level compromise.** Filesystem access to `wp-content/`
  trivially yields the secrets stored in the WP options table. The
  remedy is server hardening (file permissions, SSH key auth,
  fail2ban, …) — outside this project.
- **Physical access to the operator machine.** Whoever can read
  `~/.iawm/config.json` is, by definition, the operator. The remedy
  is OS-level hardening (full-disk encryption, screen lock,
  separation of work and personal accounts).
- **Side-channel attacks** on the HMAC implementation (timing leaks,
  power analysis). The plugin uses `hash_equals` for the comparison;
  on shared hosting this should be sufficient, but we make no claim
  beyond that.
- **Denial of service.** The plugin doesn't ship rate limiting. The
  IP allow-list is a hard barrier for the API surface, but other WP
  surfaces remain exposed to the network. Use a CDN / WAF at the
  edge if your site is internet-facing.

---

## Incident response

For each scenario, the playbook is: (1) contain, (2) rotate, (3)
audit, (4) verify, (5) document.

### Secret leak (suspected or confirmed)

The bootstrap key, or any operator's secret, may have escaped:
committed to git, sent over Slack, lost with a laptop.

1. **Admin → Settings → IA Webmaster Bridge → Tools tab → Revoke ALL
   keys**. Immediate; every gateway loses access at the next call.
2. Toggle the **kill switch** on as a second guarantee (writes
   refused even if a hypothetical key survives).
3. Open the audit log filtered by `outcome=success` over the
   suspected leak window. Look for calls you don't recognise (wrong
   IP, wrong key label, unexpected scopes used).
4. Investigate any suspicious entry — what was the body, what changed
   in WP. Use `iawm_backup_list` + `iawm_backup_get` to see the
   pre-op snapshots taken near those calls; restore from them if a
   write needs to be undone.
5. Once the audit is clean: regenerate fresh keys following
   [`operations.md`](operations.md) → "Key rotation", reset the kill
   switch, and write a short incident note in the project's
   ops journal.

### WP admin account breach

A WordPress administrator account has been compromised through a
WordPress-side weakness (weak password, plugin vuln, supply-chain).

1. Take the site offline at the edge (CDN/WAF, or DNS) or with the
   plugin's kill switch if you have it.
2. **Re-install the plugin from clean source** (the binary that lives
   in WP may have been tampered with by the attacker).
3. **Regenerate everything**: API keys, `iawm-agent` password
   (admin Tools → Reinstall agent role & user), every WP admin
   password.
4. Force a fresh `IAWM_Agent_User::install()` to recreate the
   dedicated user / role from canonical caps.
5. Audit the WP user table (`wp_users`, `wp_usermeta`) for unknown
   accounts.
6. Restore from a known-good off-site backup if any code under
   `wp-content/` looks tampered with.

### Agent rogue behaviour

The agent did something unexpected — wrong restore, wrong page
overwritten, mass content change.

1. **Kill switch on** (Settings → IA Webmaster Bridge → Security → Kill
   switch). Writes stop immediately; reads keep working so you can
   investigate.
2. Open the audit log filtered by the time window and the key that
   was active. Every audit row carries the route, method, outcome
   and `pre_op_backup_id` if any.
3. Use `iawm_backup_get({ id })` to inspect each snapshot taken.
4. Restore selectively with `iawm_backup_restore({ id, dry_run:
   true })` first, then re-issue with the confirmation token.
5. Kill switch off only once you have understood what happened. If
   the agent's instructions were the root cause, refine the skill
   (`claude-plugin/skills/<slug>/SKILL.md`) and document the
   regression in [`operations.md`](operations.md).

---

## Reporting a vulnerability

If you find a security weakness in this codebase, please **do not**
open a public issue.

- Open a **private security advisory** on the GitHub repo (Security
  tab → Report a vulnerability) if available.
- Otherwise, contact the maintainer through the email listed in the
  plugin header (`Author` field) — clear the subject with
  `[IAWM SECURITY]` so it gets prioritised.

We will acknowledge receipt within 5 business days, and aim to ship a
fix or mitigation within 30 days for high-severity issues. Credit will
be given (with permission) in the changelog and the release notes.

---

## Related documents

- [`production-deployment.md`](production-deployment.md) — install
  path with pre-flight checklist.
- [`operations.md`](operations.md) — day-to-day procedures and pentest
  checklist.
- [`decisions.md`](decisions.md) — D-005, D-011, D-012, D-013, D-014,
  D-015, D-016, D-017, D-022 are the security-relevant decisions.
- [`../specs/02-security.md`](../specs/02-security.md) — the formal
  spec.
