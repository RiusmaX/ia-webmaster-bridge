# Decision log

> Status: Living · Last updated: 2026-05-21

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
