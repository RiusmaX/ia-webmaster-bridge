---
name: audit-wordpress-site
description: Audit a connected WordPress site — technical health, plugins and themes, content, configuration, logs — and produce a structured report.
---

# Audit a WordPress site

Workflow to produce a complete health check of a site through the adapter.

## 1. Technical health

- `iawm_diagnostics_system` — versions (WordPress, PHP, MySQL), active theme,
  PHP limits, debug mode.
- `iawm_diagnostics_logs` — recent errors in debug.log.

## 2. Plugins and themes

- `iawm_diagnostics_plugins` — installed plugins, active/inactive, and most
  importantly the **available updates**.
- `iawm_diagnostics_themes` — installed themes.

## 3. Content inventory

- `iawm_content_list` (pages, then posts) — volume, pending drafts.
- `iawm_media_list` — media library.
- `iawm_menu_list` — menus and assigned locations.

## 4. Configuration

- `iawm_config_settings_get` — key settings: indexing (`blog_public`), home
  page, permalink structure.
- `iawm_config_users_list` — accounts and roles; spot unexpected accounts.

## 5. Recent activity

- `iawm_audit` — recent actions performed through the adapter.

## Report

Produce a clear report, grouped by topic — **Technical**, **Plugins**,
**Content**, **Configuration**, **Security** — with for each point: the
finding, a priority level, and a concrete recommendation. Highlight
pending updates and any risky setting.
