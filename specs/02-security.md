# Spec 02 — Security & guardrails

- **Status**: In implementation (Phase 5.1 + 5.2 shipped)
- **Phase**: Cross-cutting (built from Phase 1, hardened in Phase 5)
- **Priority**: High
- **Last updated**: 2026-05-25

## Goal

Ensure that an agent with full webmaster powers does not become a
vulnerability. Covers authentication of exchanges, authorisation, traceability
and guardrails against dangerous operations (decision D-005).

## Scope

### Included
- Authentication and integrity of gateway ↔ plugin exchanges.
- Authorisation model (scoped capabilities).
- Audit log of all actions.
- Guardrails on destructive operations.
- Secrets management and kill switch.

### Excluded (for now)
- General security of the WordPress server (hosting hardening) — falls under
  the hosting provider and operator, outside the software scope of the project.

## Technical approach

### Authentication & integrity
- **HTTPS enforced** on production; tolerated over HTTP on local (documented).
- **API key** dedicated to the agent, distinct per target.
- **HMAC signature** on every request: the gateway signs (method + path + body
  + timestamp + nonce) with a shared secret; the plugin verifies. Protects
  against tampering and replay.
- **Short time window** on the timestamp to limit replay.
- No Application Password in simple Basic Auth: insufficient (no expiry, no
  body integrity).

### Authorisation
- **Dedicated WordPress user** for the agent (`iawm-agent`, role
  `iawm_agent`) created on plugin activation. *Implemented since v0.19.0.*
  Loosely modelled on the administrator role but stripped of the
  highest-risk capabilities: `unfiltered_html`, `unfiltered_upload`,
  `edit_plugins`, `edit_themes`, `edit_files`, multisite super-admin
  capabilities. The application layer additionally refuses any attempt
  by the API to modify or delete that user.
- **Scoped API keys**: every key carries a list of scopes — `read`,
  `content:write`, `divi:write`, `config:write`, `infra:write`. A
  request whose route family is not covered by the key's scopes is
  denied with `403 iawm_scope_denied`. The scope required by a route is
  derived from its HTTP method (GET → `read`) and its path prefix
  (`/divi/*`, `/config/*`, `/plugins/*` → `infra:write`, etc.).
  *Implemented since v0.19.0.* Legacy keys without an explicit scope
  list remain fully-scoped (backward compatibility); the admin UI lets
  the operator tighten an existing key without rotating its secret.
- Caution profiles: on a new target, start read-only, then broaden.

### Audit log
- The plugin logs **every capability call**: date, capability, parameters
  (summarised), result, key identity. Queryable storage.
- Log readable by Claude (dedicated read capability) for self-verification.

### Guardrails
- **Dry-run mode**: any write capability can be called in simulation
  (returns what it would do, without applying it).
- **Draft before publish**: created content is created as a draft by
  default; publishing is an explicit step.
- **Backup before destructive** — *implemented since v0.20.0*. Any
  destructive or risky operation triggers a prior snapshot:
    - plugin install/activate/deactivate → `plugins_state` snapshot;
    - settings update on a `risky` option (e.g. `permalink_structure`)
      → `options` snapshot of the affected keys.
  The operation response surfaces a `pre_op_backup_id` the operator can
  feed to `/backup/restore` (with `dry_run` first) to roll back. The
  same `/backup/*` routes also expose manual snapshots and a SQL-level
  `tables` kind for future Phase 4 database operations.
- **Explicit confirmation** — to be implemented in Phase 5.3. Actions
  classified "at risk" will require a confirmation token distinct from
  the initial call.
- **Kill switch**: a plugin setting instantly disables all write
  capabilities.

### Secrets management
- Secrets (keys, URLs, credentials) live in the gateway config, **outside
  the repo** (Git-ignored file / environment variables).
- Key rotation planned; revocation on the plugin side.
- Optional: allow-list of IPs on the plugin side for production.

## Open questions

- Audit log storage: custom table, file, or both?
- Precise classification of "risky action" → list to be established per plan.
- Exact mechanism for the confirmation token (TTL, single use).
- Should sensitive parameters be encrypted in the log?
- Key rotation policy (frequency, procedure).

## Dependencies & risks

- Cross-cutting: all other specs rely on this model.
- Risk: a too-strict guardrail slows the agent down; a too-lax one creates a
  hazard. To be calibrated through experience, target by target.
