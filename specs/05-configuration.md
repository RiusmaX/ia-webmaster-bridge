# Spec 05 — Configuration plan

- **Status**: Draft
- **Phase**: 2
- **Priority**: Medium
- **Last updated**: 2026-05-21

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

## Open questions

- Exact scope of the exposed "theme options" (varies by theme).
- Should there be a backup/restore capability for a settings set
  (configuration snapshot)?
- Multisite settings management if the case arises.
- Which settings should be outright forbidden to the agent (blocklist)?

## Dependencies & risks

- Depends on specs 01 (adapter) and 02 (security).
- Risk: a permalinks or language change can disrupt the site →
  "at risk" classification and prior backup mandatory.
- Risk: privilege escalation via user management → strict guardrails,
  the agent cannot self-elevate or touch its own account.
