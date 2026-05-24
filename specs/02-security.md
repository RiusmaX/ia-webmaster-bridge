# Spec 02 — Security & guardrails

- **Status**: Draft
- **Phase**: Cross-cutting (built from Phase 1, hardened in Phase 5)
- **Priority**: High
- **Last updated**: 2026-05-21

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
- A **dedicated WordPress user** for the agent, with a least-privilege role.
- Capabilities are **scoped**: the API key carries a list of scopes
  (e.g. `content:read`, `content:write`, `divi:write`, `infra:*`). A capability
  outside its scope is denied.
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
- **Backup before destructive**: any destructive or risky operation
  (deletion, plugin update, database operation) triggers a prior backup.
- **Explicit confirmation**: actions classified "at risk" require a
  confirmation token distinct from the initial call.
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
