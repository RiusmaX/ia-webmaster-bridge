# Spec 01 — Adapter (plugin + MCP gateway)

- **Status**: Implemented
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
- Registry of discoverable and typed **capabilities**.
- API versioning and management of multiple targets (profiles).

### Excluded (for now)
- The implementation of each functional plan (specs 03 to 06).
- The detail of the security model (spec 02) — referenced here, specified there.

## Technical approach

### "IA Webmaster Bridge" plugin (PHP)
- Standalone WordPress plugin, `plugin/` folder.
- Registers REST routes via `register_rest_route()` under `ia-webmaster/v1`.
- Each **capability** = an endpoint with: validated input schema, permission
  callback, execution, logging. Capabilities are grouped by plan (content,
  Divi, configuration, infrastructure).
- A discovery endpoint (`/capabilities`) lists the available capabilities and
  their schemas — the MCP gateway uses it to generate its tools.
- Target compatibility: WordPress 7.0, modern PHP. Divi 5 required for the
  Divi plan.

### Local MCP gateway (Node.js / TypeScript)
- `mcp-gateway/` folder. MCP server, stdio transport, for Claude Code.
- On startup: queries the plugin's `/capabilities` and exposes each capability
  as a typed **MCP tool**.
- Translates each tool call into a signed HTTPS request (see spec 02).
- **Profiles**: a configuration file (outside the repo) describes each target
  (local, prod A, prod B) with its URL and secret.
- Expected implementation with the official MCP SDK (`@modelcontextprotocol/sdk`).

### Dynamic vs static discovery
The gateway generates its tools from the plugin's `/capabilities`: adding a
capability on the plugin side makes it available without modifying the gateway.

## Open questions

- Use the WordPress core Abilities API as an internal registry, or a custom
  registry? (to be decided in Phase 1, related to D-001).
- MCP gateway: a single multi-profile server, or one server per target?
- Capability granularity: many small capabilities, or broader parameterised
  capabilities?
- API version management (`v1`, `v2`) and backward compatibility.

## Dependencies & risks

- Depends on spec 02 (security) for the transport.
- The official MCP SDK is a gateway dependency — acceptable (standard, our
  choice), but to be pinned to a version.
- Risk: evolution of the MCP protocol — isolated in the gateway, with no
  impact on the plugin.
