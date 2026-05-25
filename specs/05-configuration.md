# Spec 05 — Configuration plan

- **Status**: Implemented (general settings, users, theme options via the builder; multisite-tolerant since v1.2.0)
- **Phase**: 2
- **Priority**: Medium
- **Last updated**: 2026-05-25

## Goal

Enable Claude to manage the site's configuration: general settings, active
theme options, users and roles.

## Scope

### Included
- General WordPress settings (title, tagline, timezone, permalinks, language…).
- Active theme options and customisation.
- User management (creation, roles, profiles).
- Discussion, reading and media settings.

### Excluded (for now)
- Installation / activation of plugins and themes → spec `06-infrastructure.md`.
- Divi-specific settings → covered with spec `04-divi5.md`.

## Technical approach

- Capabilities exposed by the plugin, grouped under "configuration".
- **Exhaustive read first**: a capability that reports the site's
  configuration state, so Claude decides with full knowledge.
- **Targeted and explicit writes**: no global bulk modification; each
  modified setting is named, and the dry-run shows the before/after.
- **Users**: sensitive capability. User creation/modification classified
  "at risk" → explicit confirmation (spec 02). Forbidden to modify or
  delete the user dedicated to the agent itself.
- Some settings (permalinks, language) have significant side effects → classify
  them as "at risk" and back up beforehand.

## Implemented (Phase 2 to date)

- **General settings**: `IAWM_Config` (`/config/settings/get`,
  `/config/settings/update`). Covers `general`, `discussion`, `reading`
  and `media` setting groups. Risky settings (e.g. `permalink_structure`)
  trigger an auto-snapshot via `IAWM_Backup` before applying.
- **Users**: `IAWM_Config` (`/config/users/list`, `/config/users/create`,
  `/config/users/update`). User creation is gated by `config:write`; the
  agent's own dedicated user is **protected from modification and
  deletion** (self-protection from the agent layer).
- **Theme options**: live with each builder. For Divi, the writable
  surface is the 17-key Customizer allow-list via
  `/divi/theme-options/{get,update}` plus the branding writer
  (`/divi/branding/*`, see D-020).
- **Per-site context**: spec 07's `IAWM_Context` adds the editorial /
  brand / infra brief that every workflow reads before acting.

## Multisite (since v1.2.0, decision D-027)

On a multisite install:

- The **dedicated agent WordPress user is global** (one record at the
  network level, `IAWM_Agent_User::ensure_global_user`).
- The **`iawm_agent` role and all per-feature tables** (audit, backups,
  link issues, 404 log) are installed **per sub-site** under that
  sub-site's `$wpdb->prefix`. Sub-site creation triggers automatic
  provisioning via `wp_initialize_site`.
- **Credentials and the kill switch are per-site**: each sub-site
  issues its own keys and can be opted out of the agent independently.
  This matches the natural blast-radius boundary of multisite and
  lets operators delegate sub-sites to different teams.
- A `POST /status/network` endpoint exposes topology (multisite flag,
  current blog id, sites count, plugin network-activation status) so
  Claude can detect a multisite at session start.
- A **Network Admin → Settings page** (`IAWM_Network_Admin`) lists
  every sub-site with its key count, kill-switch state, last audit row
  and next cron timestamp.

## Open questions

- **Settings blocklist** — formal allow-list of which `general` /
  `discussion` / `reading` / `media` keys the agent can write,
  rather than the implicit "anything with `config:write`". Carried as
  P2 nice-to-have.
- **Settings snapshot/restore as a first-class capability** — today
  configuration is captured by `IAWM_Backup`'s `options` kind on a
  per-write basis; no high-level "snapshot the whole config" tool.
- **Theme options scope outside Divi** — only Divi has an explicit
  writable surface; non-Divi themes have none. Out of scope until a
  non-Divi target is in play.

## Dependencies & risks

- Depends on specs 01 (adapter) and 02 (security).
- Risk: a permalinks or language change can disrupt the site →
  "at risk" classification and prior backup mandatory.
- Risk: privilege escalation via user management → strict guardrails,
  the agent cannot self-elevate or touch its own account.
