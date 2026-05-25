# Multisite support

> Status: Living · Last updated: 2026-05-25 (initial implementation)

The IA Webmaster Bridge plugin is **multisite-tolerant**: it can be
installed on a WordPress multisite network, either network-activated
or activated per sub-site. This document explains what is shared
across the network and what is scoped to each sub-site, the
recommended install path, and the known limitations.

## TL;DR

- The plugin works on multisite. It does **not** require multisite —
  single-site is still the primary supported topology.
- The dedicated agent **user** is **global** (one across the whole
  network); the agent **role** is **per-site** (each sub-site grants
  the role independently).
- All credentials, kill-switch flags, audit rows and backup snapshots
  are **per-site**. There is no network-wide key.
- For most networks: **network-activate** the plugin, then provision
  each sub-site with its own key from that sub-site's settings page.

## What lives where

| Surface                | Scope     | Stored in                                |
|------------------------|-----------|-------------------------------------------|
| Agent user (`iawm-agent`) | **Global**  | `wp_users` (network-wide)                 |
| Agent role (`iawm_agent`) | Per-site  | Per-blog `wp_<prefix>options.wp_user_roles` |
| Agent role assignment  | Per-site  | `wp_<prefix>usermeta.wp_<prefix>capabilities`     |
| API credentials (HMAC keys) | Per-site  | Option `iawm_credentials` on the sub-site |
| Kill switch            | Per-site  | Option `iawm_kill_switch` on the sub-site |
| IP allow-list          | Per-site  | Option `iawm_ip_allowlist` on the sub-site |
| Audit log              | Per-site  | Table `wp_<prefix>iawm_audit_log`         |
| Backups                | Per-site  | Table `wp_<prefix>iawm_backups`           |
| 404 log                | Per-site  | Table `wp_<prefix>iawm_404_log`           |
| Cron jobs              | Per-site  | Per-blog `cron` option                    |
| Settings (Phase 7+ options) | Per-site | Per-blog options                          |

Each sub-site has its own copy of every table and every option (via
`$wpdb->prefix`). When you call an endpoint, the per-site row is the
one that lives on the blog whose URL signed the request.

## Install topologies

### A. Network-activated (recommended for managed networks)

When a Super Admin network-activates the plugin from
**Network Admin → Plugins**, the activation hook walks **every
existing sub-site**, switches into it, and provisions the tables, the
role and the role assignment.

The plugin also registers a `wp_initialize_site` listener. **New
sub-sites** created after network activation are provisioned
automatically on creation — the operator does not need to revisit
them.

Each sub-site still needs its own API key, created from its own
**Settings → IA Webmaster Bridge** page. There is no shared key.

### B. Per-site activation

A sub-site admin can activate the plugin on just their own blog (from
the sub-site's Plugins page). The activation hook provisions tables,
role and role assignment for **that sub-site only**. Other sub-sites
remain untouched.

This is the right choice when only one or two blogs of a large
network use the plugin.

### C. Standalone (no multisite)

Same as before this change — the activation hook calls the per-site
install path once, and no sub-site loops happen. `is_multisite()`
returns false so all multisite-specific code paths are short-circuited.

## How activation works under the hood

The plugin's main file defines:

- `iawm_install_for_current_site()` — runs every per-site install
  step (audit table, backup table, 404 table, role install + role
  assignment).
- `iawm_on_activate( $network_wide )` — activation entry point.
  When `$network_wide` is true (network activation), it ensures the
  global agent user exists, then loops over `get_sites()` with
  `switch_to_blog()` to install everywhere. Otherwise, it provisions
  just the current site.
- `iawm_on_new_site( $site )` — wired to `wp_initialize_site` (and
  the legacy `wpmu_new_blog` fallback) so newly-created sub-sites get
  provisioned automatically when the plugin is network-active.

`IAWM_Agent_User::install()` is now multisite-aware:

- `ensure_global_user()` creates the agent user once across the whole
  network and returns its id.
- `assign_role_on_current_site( $user_id )` adds the user to the
  current sub-site (if multisite) and grants the `iawm_agent` role on
  that sub-site only. **Never** super-admin or any network-wide
  capability.

## Discovering the topology from Claude

A small, authenticated endpoint reports the topology to the agent:

```text
POST /status/network
```

Returns:

```json
{
  "ok": true,
  "is_multisite": true,
  "is_main_site": false,
  "current_blog_id": 7,
  "network_id": 1,
  "sites_in_network": 12,
  "sites": null,
  "plugin_network_active": true
}
```

The MCP tool `iawm_status_network` wraps this endpoint. On the main
site only, the response additionally includes a `sites` array
(id / URL / name / active flag, capped at 100 rows) so Claude can
enumerate sub-sites if needed.

## Network admin overview page

On a multisite install, a read-only page is added at
**Network Admin → Settings → IA Webmaster Bridge**. It walks the
sub-sites of the network and shows for each:

- blog id and URL (linked to that sub-site's own settings page),
- number of API keys configured,
- whether the per-site kill switch is engaged,
- timestamp of the most recent audit row (or `—` if empty),
- the next scheduled `iawm_prune_audit_log` cron run.

The page is intentionally read-only. Every key creation, scope edit
and kill-switch toggle still happens **on the sub-site's own**
settings page so the audit trail lines up with the correct blog id.

## Known limitations

- **No network-wide key.** Each sub-site needs its own HMAC key. If
  one human operates several blogs, they will hold several
  `key_id` / `secret` pairs in their `~/.iawm/config.json` — one
  profile per blog.
- **No network-wide kill switch.** Toggling the kill switch on the
  main site only affects the main site's API. To freeze the whole
  network, toggle it on each blog (a future enhancement could add a
  bulk toggle to the network admin page).
- **No network-wide audit view.** The network overview page shows
  the latest audit timestamp per blog; full-row inspection still
  goes via each sub-site's Audit tab.
- **The IP allow-list is per-site.** If you want the same allow-list
  everywhere, set it on each blog. Or pin HTTPS network-wide via the
  `IAWM_REQUIRE_HTTPS` constant in `wp-config.php` (that one is
  network-wide because it lives in `wp-config.php`).
- **`wpmu_new_blog` is deprecated** but still registered as a
  fallback for pre-WP-5.1 installs. Modern installs use
  `wp_initialize_site`.
- **Sub-site URL handling.** Each MCP profile must point at the
  exact sub-site URL the key was created on. If `https://example.com`
  has key `iawm_aaaa` and `https://example.com/blog/`
  has key `iawm_bbbb`, the gateway must route to each URL separately.

## Future work (out of scope for the initial implementation)

- A network-wide kill switch with a single toggle.
- A bulk "rotate all keys" action across the network.
- An aggregated audit view rolling up rows from every sub-site.
- Network-level scopes that grant a key access to a defined subset of
  sub-sites (today a key is fully bound to its creation blog).

See [`roadmap.md`](roadmap.md) for the broader backlog.
