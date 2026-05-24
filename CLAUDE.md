# IA Webmaster Bridge — Project context

> Context file loaded automatically by Claude Code in every session
> opened against this repository. States the project's purpose, its
> layout and the rules to follow on any change.

## In one sentence

A system that lets a Claude agent (Claude Code or Claude Desktop) act
as a **full-stack WordPress webmaster** on **WordPress 7.0 + Divi 5**
sites: content, configuration, page and theme template generation, SEO,
infrastructure operations — every action authenticated, logged and
fenced by guardrails.

## Key decisions

See [`docs/decisions.md`](docs/decisions.md). In short:

- **100 % in-house adapter.** Our own WordPress plugin + MCP gateway.
  No dependency on a pre-1.0 external adapter.
- **Primary builder: Divi 5.** Elementor deferred.
- **Local-first development** (LocalWP recommended). Never iterate
  directly against production.
- **First-class security** (see [`specs/02-security.md`](specs/02-security.md)):
  HMAC, audit log, guardrails, kill switch.
- **Infrastructure ops via the plugin** rather than raw SSH whenever
  possible.

## Repository layout

| Path | Role |
|------|------|
| `CLAUDE.md` | This file (shared project context, loaded each session) |
| `README.md` | Public project description and install |
| `LICENSE` | GPL-3.0-or-later |
| `docs/` | `architecture.md`, `roadmap.md`, `decisions.md`, `glossary.md`, `divi5-format.md`, `divi5-modules-catalog.md`, `divi5-compose-dsl.md` |
| `specs/` | One spec per feature (`01` to `07`) |
| `plugin/ia-webmaster-bridge/` | WordPress plugin (REST API `ia-webmaster/v1`) |
| `claude-plugin/` | Claude Code plugin (MCP tools + skills + MCP gateway) |
| `claude-plugin/mcp-gateway/` | MCP gateway (TypeScript, bundled) |
| `.claude-plugin/marketplace.json` | Claude Code marketplace catalogue |
| `tools/` | Development tools (see `tools/README.md`) |

## Project status

Phases 0 to 3 complete — see [`docs/roadmap.md`](docs/roadmap.md) for
the detailed sub-milestones reached and remaining.

Current high-level capabilities:

- Full WordPress management (content, media, taxonomies, menus,
  configuration, diagnostics, plugins).
- Divi 5: 41 native modules, 13 parametric patterns, full Theme
  Builder, bit-faithful round-trip, unified declarative composer
  (`iawm_divi_page_compose`).
- Rank Math SEO (Yoast planned).
- 7 Claude Code skills for method and workflow.

## Collaboration rules (to be followed by the agent)

- **Step-by-step co-design.** Move in testable increments; validate
  important commitments with the user before coding.
- **Never run a destructive operation on production without explicit
  confirmation AND a prior backup.**
- **Draft by default.** Any content creation starts in `draft`;
  publishing must be explicit.
- **No intermediate scripts to generate Divi content.** Use the MCP
  tools `iawm_divi_page_compose` and `iawm_divi_theme_builder_compose`
  directly (see `docs/divi5-compose-dsl.md`). Per-project (client)
  scripts live in a folder **outside the repository**.
- **Keep `docs/decisions.md` and `docs/roadmap.md` up to date** for
  every decision or milestone reached.
- **Any modified spec**: refresh its "Status" field and date.
- **No private data leaks** in the repository: no secrets, no personal
  paths, no client-site-specific content. All sensitive configuration
  stays in `~/.iawm/config.json` (gitignored).
- **Codebase language is English.** Code, file names, comments,
  identifiers, documentation, error messages and tool descriptions are
  all in English so the project is usable by anyone worldwide. The
  language of generated website content is independent — callers pass
  an optional `language` parameter (BCP-47) to content-generation
  tools.

## Personal preferences

The current maintainer's personal preferences (language, communication
style, dev environment) live in `CLAUDE.local.md` — gitignored — when
that file exists. If you are new to this repository, you can create
one to record your own preferences without polluting the public repo.
