# Technical architecture

> Status: Under design · Last updated: 2026-05-21

## Overview

The system connects **Claude Code** (the agent's brain and runtime, on the
user's machine) to a **WordPress 7.0 site** (the target). Claude Code does not
need a separate orchestrator: it IS the agent.

```
   Claude Code
        |
        |  MCP protocol (stdio)
        v
   Local MCP bridge          <-- our code (Node.js / TypeScript)
        |
        |  HTTPS — custom REST API + signed requests (HMAC)
        v
   "IA Webmaster Bridge" plugin   <-- our code (PHP, installed on the site)
        |
        +-- Content plane         (pages, posts, media, menus, blocks)
        +-- Divi 5 plane          (layout generation)
        +-- Configuration plane   (settings, theme, users)
        +-- Infrastructure plane  (plugins, database, backups)
        |
        v
   WordPress 7.0 + Divi 5
```

## Components to build

### 1. "IA Webmaster Bridge" plugin (PHP)

Installed on the target site. Exposes a **custom REST API** under the
`ia-webmaster/v1` namespace. Responsibilities:

- Expose **capabilities** grouped by functional plane.
- Enforce **authentication** and **audit logging**.
- Implement **safeguards** (dry-run, draft, prior backup).
- Encapsulate infrastructure operations to avoid opening a shell.

This is plain WordPress REST: easy to secure, test and version.

### 2. Local MCP bridge (Node.js / TypeScript)

Runs on the user's machine (folder `mcp-gateway/`). Responsibilities:

- Present Claude Code with an **MCP server** (stdio transport) exposing clean,
  well-typed tools.
- Translate each MCP tool call into a signed HTTPS request to the plugin.
- Hold the **secrets** (API key, site URLs) — never stored in the repo, never
  exposed to the site.
- Manage multiple targets (local, small prod, large prod) via profiles.

### 3. Webmaster layer (skills + context)

Claude Code skills and per-site context files, giving Claude its working
methods (create a landing page, audit SEO, safely update plugins, etc.). See
`specs/07-webmaster-layer.md`.

## Why a custom adapter

The official MCP Adapter (`WordPress/mcp-adapter`) is pre-1.0 (v0.5.0):
breaking changes expected, and it has no native understanding of Divi.
Building our own adapter guarantees full control, no breakage caused by a
third-party component, and a scope tailored to our needs (Divi 5 first). We
can take inspiration from the official concepts (the WordPress core Abilities
API) without depending on them. See `docs/decisions.md` (D-001, D-002).

## The three functional planes

| Plane | Scope | Spec |
|------|-----------|------|
| Content | Pages, posts, media, menus, taxonomies, Gutenberg blocks | `03-content.md` |
| Divi 5 | Generation and editing of Divi 5 layouts | `04-divi5.md` |
| Configuration | Site settings, theme, users, roles | `05-configuration.md` |
| Infrastructure | Plugins, themes, database, backups, cron | `06-infrastructure.md` |

**Security** (`02-security.md`) is cross-cutting across all planes.

## Why MCP rather than a direct REST call

Claude Code natively consumes **MCP tools**: it's the most reliable agent
ergonomics (typed tools, discoverable, validated input schemas). The local
MCP bridge lets us own both ends while keeping the plugin as a plain
WordPress REST API. Alternative ruled out for now: implementing the MCP
protocol directly in PHP inside the plugin (more work, coupled to the
evolution of the protocol).

## Deployment cycle

`Local (LocalWP)` → `small prod` → `large prod`. Each target is a profile in
the MCP bridge. We only promote to a higher target after stability on the
previous one. See `docs/roadmap.md`.
