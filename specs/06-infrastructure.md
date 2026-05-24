# Spec 06 — Infrastructure plan

- **Status**: Draft
- **Phase**: 4
- **Priority**: Medium
- **Last updated**: 2026-05-21

## Goal

Enable Claude to carry out the site's infrastructure operations:
plugins, themes, database, backups, scheduled tasks, updates.

## Scope

### Included
- Plugins: installation, activation/deactivation, update.
- Themes: installation, activation, update.
- Database: export, controlled queries, `search-replace`.
- Backups and restoration.
- Scheduled tasks (WordPress cron).
- WordPress core update.

### Excluded (for now)
- System administration beyond WordPress (server configuration, DNS, etc.).
- Modification of `wp-config.php` beyond settings explicitly exposed.

## Technical approach

- In line with decision **D-006**, these operations go through
  **controlled plugin endpoints**, not through a shell open to the agent.
- The plugin, running in PHP with WordPress rights, can perform most of
  these operations (plugin install, options, cron). For what goes beyond
  PHP, the plugin may wrap precise, validated WP-CLI calls (never an
  arbitrary command).
- **Backup SSH/WP-CLI channel**: reserved for the human operator, or for
  situations where the plugin is unavailable (e.g. plugin itself broken).
  Documented, not exposed to the agent by default.
- **All these operations are classified "at risk"**: prior backup,
  explicit confirmation, dry-run where possible (spec 02).
- Plugin/theme sources: restrict to trusted sources (official repository,
  validated archives) — no install from an arbitrary URL.

## Open questions

- Backup mechanism: rely on an existing backup plugin, or implement a
  minimal backup in the plugin?
- Which WP-CLI commands to wrap, and which to leave strictly to the
  human operator?
- `search-replace` is powerful and dangerous (serialisation) → specific
  guardrails, mandatory dry-run.
- Updates: do we need a pre-production environment to test an update
  before production?
- How does the agent verify that a site is healthy after an operation
  (smoke test)?

## Dependencies & risks

- Depends on specs 01 (adapter) and 02 (security).
- **High risk**: this plan contains the most destructive operations in the
  project (updates, database). Non-negotiable guardrails; production
  rollout only after strong stability on local.
- Risk: an update breaks the site → prior backup + restoration procedure
  tested before any production use.
