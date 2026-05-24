# Glossary

> Status: Living · Last updated: 2026-05-21

**WordPress 7.0** — Major version of WordPress released on May 20, 2026.
New features mainly on the editor side. The project's target.

**WordPress 6.9** — December 2025 release that introduced the "AI agent"
infrastructure (Abilities API) in core.

**Abilities API** — WordPress core API (since 6.9): a registry of "actions"
(capabilities) with JSON Schema-validated inputs/outputs and permission
callbacks. We may take inspiration from it / use it as an internal registry,
without depending on it as an external component.

**MCP (Model Context Protocol)** — Standard protocol by which an AI agent
(here Claude Code) discovers and calls tools. Our local bridge speaks MCP.

**MCP Adapter** (`WordPress/mcp-adapter`) — Official plugin that exposes
Abilities as an MCP server. External, pre-1.0 (v0.5.0). **Not used** in this
project (see decision D-002).

**Local MCP bridge** (MCP Gateway) — Our Node.js component, running on the
user's machine. Presents an MCP server to Claude Code and translates it
into HTTPS calls to the plugin. Folder `mcp-gateway/`.

**"IA Webmaster Bridge" plugin** — Our WordPress plugin (PHP), installed on
the target site. Exposes the custom REST API `ia-webmaster/v1`. Folder
`plugin/`.

**Capability** — A unit operation exposed by the plugin (e.g. "create a
page", "upload a media item"), with its input schema, its permission, and
its log entry.

**Divi 5** — Version of the Divi page builder (Elegant Themes), released on
February 26, 2026. React-based architecture, content stored as JSON /
serialized blocks. The project's priority builder.

**Divi Builder API** — Developer API introduced with Divi 5 for custom
modules and features. Exact scope to be explored.

**Divi "portability" format** — `.json` import/export format for Divi
layouts. Constraint: a layout must be imported into the same context as
the one it was exported from (page / library / theme builder).

**Gutenberg / blocks** — WordPress's block editor. Content is stored in
`post_content` as HTML markup annotated with comments (`<!-- wp:... -->`).

**FSE (Full Site Editing)** — Full site editing: templates and template
parts managed as blocks.

**post_content / post meta** — `post_content`: the body of a piece of
content. `post meta`: key/value pairs attached to a content item (builders
often store their data there).

**HMAC** — Cryptographic signature of a request using a shared secret, to
guarantee authenticity and integrity. Used to secure bridge ↔ plugin
exchanges.

**WP-CLI** — WordPress command-line interface. Fallback channel for system
operations (see decision D-006).

**LocalWP** — Tool for creating local WordPress sites. Used as the
development and testing environment for the project.

**Claude Code** — The environment in which Claude runs; here, the brain and
runtime of the webmaster agent.

**Skill** — Reusable competency module for Claude Code (a documented
workflow). See `specs/07-webmaster-layer.md`.
