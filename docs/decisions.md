# Decision log

> Status: Living · Last updated: 2026-05-25

Each decision has an identifier (`D-NNN`), a date, a status
(`Accepted` / `To revisit` / `Superseded`), its context, the decision, and its
consequences. Decisions are never deleted: mark them `Superseded` and add a
new one.

## D-001 — Take inspiration from the official concepts, not external dependencies

- Date: 2026-05-21 · Status: Accepted
- **Context**: WordPress 6.9+ provides the Abilities API (in core) and an
  official MCP Adapter (`WordPress/mcp-adapter`, external, v0.5.0 — pre-1.0,
  subject to breaking changes).
- **Decision**: we take inspiration from these concepts but do not depend on
  the external MCP Adapter. The core Abilities API may eventually serve as an
  internal registry (it's in core, not a third-party dependency) — to be
  decided in Phase 1.
- **Consequences**: full control, no breakage caused by a pre-1.0 component; a
  bit more code to write on our side.

## D-002 — 100% in-house adapter

- Date: 2026-05-21 · Status: Accepted
- **Context**: we want guaranteed compatibility and no dependency on an
  external component.
- **Decision**: we build our own WordPress plugin (custom REST API) and our
  own local MCP bridge (Node.js). The plugin stays plain WordPress REST; the
  MCP bridge isolates the MCP protocol on the user's machine side.
- **Consequences**: two components to maintain, but decoupled and
  independently versionable; no inherited attack surface from a third party.

## D-003 — Priority builder: Divi 5

- Date: 2026-05-21 · Status: Accepted
- **Context**: most target sites run Divi 5, which is less documented than
  Elementor for programmatic generation.
- **Decision**: Divi 5 is the priority target. Elementor is deferred.
- **Consequences**: Phase 3 includes reverse-engineering the Divi 5 format on
  the local site. Stated approach: test and iterate.

## D-004 — Local development first (LocalWP)

- Date: 2026-05-21 · Status: Accepted
- **Context**: iterating on a production site is risky.
- **Decision**: development and tests on a local WordPress 7.0 under LocalWP.
  Progressive rollout: local → small prod → large prod.
- **Consequences**: no direct iteration on a production site; each target is a
  distinct profile in the MCP bridge.

## D-005 — First-class security

- Date: 2026-05-21 · Status: Accepted
- **Context**: an agent with full webmaster powers is a major attack surface;
  plugin ↔ bridge exchanges must not introduce a vulnerability.
- **Decision**: API key authentication + HMAC signature, HTTPS enforced,
  scoped capabilities, audit log, safeguards (dry-run, draft, prior backup),
  kill switch. Details in `specs/02-security.md`.
- **Consequences**: security is built in from Phase 1, not added afterwards.

## D-006 — Infrastructure operations via the plugin

- Date: 2026-05-21 · Status: Accepted
- **Context**: we have SSH access to the servers, but prefer not to expose a
  shell to the agent.
- **Decision**: infrastructure operations go through controlled, logged plugin
  endpoints. SSH/WP-CLI remains a fallback channel (reserved for the human
  operator or cases where the plugin is unavailable).
- **Consequences**: reduced attack surface; some system operations will have
  to be explicitly wrapped on the plugin side.

## D-007 — Plugin name and REST namespace

- Date: 2026-05-21 · Status: Accepted
- **Context**: a provisional name had been proposed for the plugin and its
  API.
- **Decision**: the plugin is called "IA Webmaster Bridge" (slug
  `ia-webmaster-bridge`); the REST API is exposed under the namespace
  `ia-webmaster/v1`.
- **Consequences**: names locked in for the repo and the code to come.

## D-008 — Integration shipped as a Claude Code plugin

- Date: 2026-05-22 · Status: Accepted
- **Context**: we want a clean integration into Claude (plugin, connector or
  extension — whichever fits best).
- **Decision**: ship a **Claude Code plugin** packaging the MCP bridge and the
  skills. This is Claude Code's native format for grouping an MCP server,
  skills, and commands. Ruled out: the "Claude Desktop extension" (wrong
  environment), and the bare `.mcp.json` (no packaging).
- **Consequences**: `mcp-gateway/` and `skills/` will be structured as a
  plugin with a manifest; clean installation and updates.

## D-009 — Access to logs and diagnostics

- Date: 2026-05-22 · Status: Accepted
- **Context**: we want to be able to diagnose and debug the site through the
  agent.
- **Decision**: add a **read-only** diagnostics module: WordPress debug.log,
  Site Health, plugin/theme status and versions, environment versions.
  Read-only is the safeguard; every access is still recorded in the audit
  log.
- **Consequences**: new `IAWM_Diagnostics` module on the plugin side.

## D-011 — Dedicated agent WP user with restricted role

- Date: 2026-05-25 · Status: Accepted
- **Context**: until v0.18.x the adapter borrowed the first administrator's
  identity (`act_as_agent`) to perform writes. Two problems: (1) the WordPress
  audit trail attributes all agent actions to the human operator, blurring
  accountability; (2) a leaked HMAC secret would have granted full
  super-admin powers — the blast radius of a key compromise equalled
  the maximum admin powers on the site.
- **Decision**: introduce a dedicated WordPress user (`iawm-agent`) tied
  to a custom role (`iawm_agent`), created on plugin activation. The role
  is administrator-like but stripped of the highest-risk capabilities
  (`unfiltered_html`, `unfiltered_upload`, `edit_plugins`, `edit_themes`,
  `edit_files`, multisite super-admin). The application layer additionally
  refuses any attempt by the API to modify or delete that user.
- **Consequences**: WordPress audit records clearly attribute every
  write to the agent; a leaked HMAC secret limits the attacker to the
  `iawm_agent` role's surface. Operators can further tighten the role
  via the `iawm_agent_role_caps` filter.

## D-012 — Per-key scopes enforced at the auth layer

- Date: 2026-05-25 · Status: Accepted
- **Context**: a single boolean (authenticated or not) was too coarse:
  any successful HMAC check granted every endpoint, including the most
  destructive ones (plugin install, settings update, user management).
  Spec 02 already called for scoped capabilities — this realises it.
- **Decision**: every API key carries a list of scopes —
  `read`, `content:write`, `divi:write`, `config:write`, `infra:write`.
  The scope required by an incoming request is derived from its HTTP
  method (GET → `read`) and its path prefix (`/divi/*`, `/config/*`,
  `/plugins/*` → `infra:write`, etc.). A mismatch returns HTTP 403
  `iawm_scope_denied`. The check happens **after** the HMAC verification
  to avoid leaking scope information to unauthenticated callers. Legacy
  keys without an explicit scope list remain fully-scoped, so the v0.19
  upgrade does not break existing installs.
- **Consequences**: an HMAC secret tied to e.g. only `read` cannot
  trigger any write; an infra-scoped key cannot leak via a Divi or
  content path. Scope assignment is prefix-based so new routes inherit
  the right scope automatically by their family. The admin UI lets the
  operator generate, retighten or rotate scopes without leaving WP.

## D-013 — Pre-op backups as snapshots, not full dumps

- Date: 2026-05-25 · Status: Accepted
- **Context**: spec 02 calls for a backup before any destructive
  operation, but a full filesystem + database backup is out of the
  plugin's scope (touches files outside `wp-content`, needs server
  access, is heavy). At the same time, operations done via this plugin
  affect a well-bounded slice of state — options, plugin activation
  status, sometimes raw tables.
- **Decision**: implement three snapshot kinds in `IAWM_Backup`:
  `options` (JSON map of WP option keys), `plugins_state` (a derived
  options snapshot of `active_plugins` + `recently_activated` plus the
  installed plugin list) and `tables` (SQL dump of named tables).
  Snapshots live in a dedicated `wp_iawm_backups` table; restore is
  done by the same plugin, with `dry_run` first. A full filesystem
  backup is left to dedicated tooling and is out of scope.
- **Consequences**: the plugin's auto-backup before destructive ops is
  fast (low-byte JSON snapshot in most cases), restorable in-band, and
  bounded to what the API can plausibly affect. For heavy database
  operations (`search-replace`, raw SQL) the caller asks for `tables`
  explicitly — keeping snapshot cost intentional.

## D-014 — Pre-op snapshot is automatic and surfaced as `pre_op_backup_id`

- Date: 2026-05-25 · Status: Accepted
- **Context**: we want safety by default, not safety by remembering to
  ask for it. But there are legitimate cases (chained retries, dry-run
  composition flows) where the caller does not want an extra snapshot.
- **Decision**: destructive endpoints (`plugins/install`,
  `plugins/activate`, `plugins/deactivate`, risky settings updates)
  automatically call `IAWM_Backup::snapshot_*()` before applying the
  change. The new backup id is surfaced in the response as
  `pre_op_backup_id`. Callers can opt out per request with
  `skip_backup: true`, which is intended for dry-runs and re-tries
  where the previous snapshot is still valid.
- **Consequences**: the agent does not need to think about backups for
  the common path; the operator gets a free safety net; advanced
  callers retain control. Backup table needs periodic pruning, exposed
  via `/backup/prune`.

## D-010 — Public open source distribution

- Date: 2026-05-22 · Status: Accepted
- **Context**: we want to release the project as open source.
- **Decision**: public release on GitHub (`RiusmaX/ia-webmaster-bridge`)
  under **GPL-3.0-or-later**. The repo also serves as a Claude Code
  marketplace: the `ia-webmaster` plugin installs via `/plugin marketplace
  add` then `/plugin install`.
- **Consequences**: the HMAC secret and any sensitive data are excluded from
  the repo (`.gitignore`); a security review precedes each publication. The
  bridge is distributed as a standalone bundle; its configuration lives
  outside the repo (`~/.iawm/config.json`).
