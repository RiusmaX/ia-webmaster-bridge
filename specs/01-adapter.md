# Spec 01 — Adapter (plugin + MCP gateway)

- **Status**: Implemented (plugin v1.3.0, gateway v1.3.0, 108 MCP tools mapped to ~108 REST routes)
- **Phase**: 1–2
- **Priority**: High
- **Last updated**: 2026-05-25

## Goal

Provide the communication channel between Claude Code and the WordPress site:
our own adapter, with no external dependency (decisions D-001, D-002). Two
components: the **WordPress plugin** and the **local MCP gateway**.

## Scope

### Included
- WordPress plugin exposing a custom REST API `ia-webmaster/v1`.
- Local MCP gateway (Node.js) presenting an MCP server to Claude Code.
- Statically typed, Zod-validated catalogue of **capabilities** (MCP tools)
  declared in the gateway, one-to-one with the REST routes the plugin
  exposes.
- Management of multiple targets (profiles) via the gateway config file.

### Excluded (for now)
- The implementation of each functional plan (specs 03 to 06).
- The detail of the security model (spec 02) — referenced here, specified there.

## Technical approach

### "IA Webmaster Bridge" plugin (PHP)
- Standalone WordPress plugin, `plugin/ia-webmaster-bridge/` folder.
- Registers REST routes via `register_rest_route()` under `ia-webmaster/v1`.
- Each **capability** = an endpoint with: validated input schema, permission
  callback (HMAC + scopes), execution, audit logging. Capabilities are
  grouped by plan (content, Divi, configuration, infrastructure,
  webmaster-layer).
- Target compatibility: WordPress 7.0+, PHP 7.4+. Divi 5 required for the
  Divi plan. Multisite-tolerant since v1.2.0 (D-027).

### Local MCP gateway (Node.js / TypeScript)
- `claude-plugin/mcp-gateway/` folder. MCP server, stdio transport, for
  Claude Code. Implementation uses the official MCP SDK
  (`@modelcontextprotocol/sdk`).
- **Tool catalogue is declared statically** in `src/tools.ts`: every MCP
  tool has a Zod schema for its inputs and a typed handler that translates
  the call into a signed HTTPS request (see spec 02). 108 tools in v1.3.0.
- **Profiles**: a configuration file outside the repo
  (`~/.iawm/config.json`) describes each target (local, prod A, prod B) with
  its `baseUrl`, `keyId` and `secret`. One gateway process per profile;
  Claude Code picks the active one at session start.

### Static vs dynamic discovery (decision D-029)

The gateway declares its tools statically rather than pulling them from a
plugin-side `/capabilities` endpoint at runtime. Rationale and trade-offs
are recorded in **D-029**: type safety (Zod at compile time), no
runtime-discovery surface to exploit, simpler audit. Cost: adding a
capability requires a coordinated edit on both sides (plugin route +
gateway tool) and a gateway rebuild. The bundled gateway distribution
makes that ergonomic (`npm run build` + copy `dist/index.js`).

## Open questions

- **API versioning** (`v1` → `v2`) and backward compatibility: nothing
  has shipped yet that would require a v2; a deprecation policy will be
  written the first time a breaking change is needed (P2 backlog).
- **Capability granularity**: settled in practice on ~100 narrow
  capabilities; the cost of one extra tool is low because of the static
  catalogue.

## Dependencies & risks

- Depends on spec 02 (security) for the transport.
- The official MCP SDK is a gateway dependency — acceptable (standard, our
  choice), pinned via `package.json`.
- Risk: evolution of the MCP protocol — isolated in the gateway, with no
  impact on the plugin (the REST API stays stable).
- Risk: catalogue drift between plugin routes and gateway tools — caught
  by an integration smoke test that calls `iawm_ping` + `iawm_status`
  before any production rollout.
