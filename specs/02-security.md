# Spec 02 — Security & guardrails

- **Status**: Implemented (Phase 5.1 + 5.2 + 5.3 + 5.4 shipped; ops runbook documented; audit pseudonymisation added in Phase 9.6)
- **Phase**: Cross-cutting (built from Phase 1, hardened in Phase 5, audit hardening in Phase 9)
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
- **Explicit confirmation** — *implemented since v0.24.0 (Phase 5.3)*.
  The most destructive endpoints (`/backup/restore`, `/core/update`,
  `/database/search-replace`) require a **two-step** pattern: the first
  non-`dry_run` call returns `requires_confirmation: true` with a
  fresh, single-use token (5-minute TTL) and a summary of what would
  happen; the SAME body re-issued with `confirmation_token: <token>`
  applies the action. Tokens are bound to the (route, key id, body
  hash) tuple so a token cannot be replayed against a different call.
  `dry_run` requests are exempt (a preview never mutates state).
- **Kill switch**: a plugin setting instantly disables all write
  capabilities.

### Secrets management
- Secrets (keys, URLs, credentials) live in the gateway config, **outside
  the repo** (Git-ignored file / environment variables).
- Key rotation planned; revocation on the plugin side.
- Optional: allow-list of IPs on the plugin side for production.

## Open questions

The original list of open questions is mostly settled by decisions
D-013 → D-022. The remaining items:

- **Automated key expiry**. Rotation procedure is documented in
  `operations.md`; the **policy** (frequency, procedure) is the
  operator's call. The plugin does not currently enforce a TTL on a
  key — a manual rotation cycle is the convention. Adding an optional
  `expires_at` per key is a reasonable P2 enhancement.
- **Pentest against real production**. `docs/pentest-2026-05-25.md`
  documents the dry-run on local; "small prod" and "large prod"
  validations remain decoched on the roadmap. Not a code item but a
  ship-blocking checkpoint for any operator publishing the bridge
  outside their own dev box.

Settled and removed from this list (see `docs/decisions.md` for
context): audit-log storage (custom table — D-013 era), risky-action
classification (per-route allow-list, D-015 + D-022), confirmation
token mechanism (D-015: single-use, 5-min TTL, body-bound),
sensitive-parameter pseudonymisation in the audit log (D-031: opt-in,
dot-path-declared, SHA-256 short-prefix sentinel).

## Dependencies & risks

- Cross-cutting: all other specs rely on this model.
- Risk: a too-strict guardrail slows the agent down; a too-lax one creates a
  hazard. To be calibrated through experience, target by target.
