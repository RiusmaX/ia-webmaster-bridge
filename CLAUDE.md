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
| `CHANGELOG.md` | keep-a-changelog version history (since v0.18.3) |
| `CONTRIBUTING.md` | Contribution rules (code style, commits, tests, disclosure) |
| `LICENSE` | GPL-3.0-or-later |
| `docs/` | `architecture.md`, `roadmap.md`, `decisions.md`, `glossary.md`, `operations.md`, `production-deployment.md`, `security-model.md`, `capabilities.md`, `skills.md`, `design-system.md`, `phase-7-action-plan.md`, `phase-9-action-plan.md`, `divi5-format.md`, `divi5-modules-catalog.md`, `divi5-compose-dsl.md`, `multisite.md`, `woocommerce-integration.md`, `pentest-*.md`, `validation-checklist.md` |
| `specs/` | One spec per feature (`01` to `07`) |
| `plugin/ia-webmaster-bridge/` | WordPress plugin (REST API `ia-webmaster/v1`) |
| `claude-plugin/` | Claude Code plugin (MCP tools + skills + MCP gateway) |
| `claude-plugin/mcp-gateway/` | MCP gateway (TypeScript, bundled) |
| `.claude-plugin/marketplace.json` | Claude Code marketplace catalogue |
| `tools/` | Development tools (see `tools/README.md`) |

## Project status

Phases 0–8 closed. **Current release: v1.2.0** (plugin + gateway both
1.2.0, 100 MCP tools, 15 Claude Code skills, 28 decisions on file).
**Phase 9 — polish + long tail — is the next sprint**, targeting
**v1.3.0**. The sub-phase tracker lives in
[`docs/phase-9-action-plan.md`](docs/phase-9-action-plan.md); the
historical roadmap (Phases 0 → 8) is in
[`docs/roadmap.md`](docs/roadmap.md); Phase 7's tracker is preserved
as a closed reference at
[`docs/phase-7-action-plan.md`](docs/phase-7-action-plan.md).

Current high-level capabilities:

- Full WordPress management (content, media, taxonomies, menus,
  configuration, diagnostics, plugins, themes, core, database, cron,
  backups, broken-links scanner, 404 tracker).
- Divi 5: 105 auto-discovered modules (incl. 25 WooCommerce), 47
  typed builders, 13 parametric patterns, full Theme Builder,
  bit-faithful round-trip, unified declarative composer
  (`iawm_divi_page_compose`), design system writes (colors / fonts /
  variables / theme options / branding).
- **Two SEO backends auto-detected**: Rank Math and Yoast (same
  normalized API on both sides).
- **WooCommerce Theme Builder helper**: four canonical contexts
  (shop / single-product / cart / checkout) with suggested module
  lists and `use_on` expressions.
- **Multisite-tolerant**: global agent user, per-site role + tables,
  auto-provisioning of new sub-sites via `wp_initialize_site`. Network
  Admin Settings page surfaces every sub-site's state.
- Multi-key auth, scoped per request, with audit log, automatic
  pre-op backups, confirmation tokens, kill switch, HTTPS
  enforcement, IP allow-list. Eight-layer defence in depth — see
  [`docs/security-model.md`](docs/security-model.md).
- **15 Claude Code skills** covering method, content authoring (incl.
  Divi), design / marketing / SEO, infra ops (safe-update,
  design-system-first, smoke-test, prod-deployment), governance
  (audit, context discovery, status report, scheduled routines,
  broken-links audit). Catalogue: [`docs/skills.md`](docs/skills.md).

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
