# IA Webmaster Bridge

> Drive a WordPress + Divi 5 site from Claude. Secure, audited, every
> action signed.

![License](https://img.shields.io/badge/license-GPL--3.0--or--later-blue)
![Plugin](https://img.shields.io/badge/plugin-1.3.0-brightgreen)
![Gateway](https://img.shields.io/badge/gateway-1.3.0-brightgreen)
![Status](https://img.shields.io/badge/status-v1.3.0-brightgreen)
![Tools](https://img.shields.io/badge/MCP%20tools-108-blue)
![Skills](https://img.shields.io/badge/skills-15-blue)
![Divi modules](https://img.shields.io/badge/Divi%205%20modules-105-blue)

## Status

**v1.3.0 — production ready, polish complete.** Phases 0-9 closed
code-side (9.7 small-prod validation is the next deployment
milestone); specs 01-07 all Implemented. Numbers at a glance:

- **108 MCP tools** exposed to Claude (≈108 signed REST routes on the
  WordPress side, grouped in 25 modules).
- **79 typed Divi 5 builders** (47 → 79 in Phase 9) covering native
  modules + 10 WooCommerce modules with opinionated defaults.
- **15 Claude Code skills** covering content, design, marketing, SEO,
  Divi authoring, safe plugin updates, design-system-first builds,
  smoke testing, production deployment, per-site context discovery,
  scheduled routines, status reporting, audits.
- **105 Divi 5 modules** auto-discovered (incl. 25 WooCommerce) + 13
  parametric patterns + full Theme Builder.
- **Two SEO backends** auto-detected: Rank Math and Yoast.
- **8-layer security model** + rotation + smoke test + pentest record.
- **Multisite-tolerant**: global agent user, per-site role + tables,
  auto-provisioning on new sub-sites.
- **Site hygiene loop**: broken-links scanner + 404 tracker
  (with dedup + sampling) shipped in v1.2.0.
- **Per-site context module** so multi-operator setups share a curated
  brand brief.
- French localisation complete.

History: [`CHANGELOG.md`](CHANGELOG.md);
pentest: [`docs/pentest-2026-05-25.md`](docs/pentest-2026-05-25.md);
phase tracker: [`docs/roadmap.md`](docs/roadmap.md);
next sprint: [`docs/phase-9-action-plan.md`](docs/phase-9-action-plan.md).

Validated end-to-end on a local WordPress 7.0 + Divi 5 site. Run the
[production deployment checklist](docs/production-deployment.md)
before any go-live.

## What it does

Exposes a controlled, signed REST API on a WordPress site, paired with
a Claude Code plugin that mounts the matching MCP tools. From a single
prompt Claude can plan and create a full Divi 5 site (header, footer,
homepage, design tokens, SEO) — every call HMAC-signed, scope-checked,
audit-logged, and protected by automatic pre-op backups and a kill
switch.

## What you can ask Claude to do

A condensed tour. The full catalogue of the **108 MCP tools** with
example prompts is in [`docs/capabilities.md`](docs/capabilities.md);
the **15 Claude Code skills** that compose those tools into
recognisable workflows are documented in
[`docs/skills.md`](docs/skills.md).

### Build pages

> *"Build the homepage of my Brazilian Jiu-Jitsu club — hero with a
> trial-class CTA, our pillars in 3 columns (kids/adults/competition),
> testimonials, pricing in 3 tiers, FAQ, contact. Use the brand
> palette."*

> *"Add a 'numbers we're proud of' section between the hero and
> features on page 53 — 4 counters animated on scroll."*

### Configure the brand design system

> *"Set up the brand: primary `#0a6ef5`, secondary `#10b981`, Inter
> font, 12px brand-radius. Use the logo at /uploads/logo.svg."*

> *"What's currently in the design system? I want to see palette,
> fonts and global variables before I propose changes."*

### Manage infrastructure safely

> *"Install Rank Math SEO and activate it."*

> *"Update every plugin that has an available update. Use the safe-
> plugin-update procedure (backup → update → smoke test → rollback
> if anything breaks)."*

> *"A WordPress core update is pending — apply it. Run the full
> two-step confirmation flow."*

> *"Run a serialization-safe search-replace from `old-domain.com` to
> `new-domain.com` — dry-run first."*

### Author SEO

> *"Set the SEO of page 19 — focus keyword 'Brazilian Jiu-Jitsu
> Bordeaux', meta title under 60 chars, meta description with the
> primary CTA in it."*

> *"Audit every published page and tell me which ones have no meta
> description or no focus keyword."*

### Operate and monitor

> *"Run a smoke test — is the site healthy?"*

> *"Produce the weekly site status report."*

> *"Schedule a quarterly reminder to rotate my API keys."*

> *"Bootstrap the per-site context from what you can see on the
> live site, then ask me what you can't infer."*

### What it intentionally does **not** do

No raw shell access. No arbitrary URL installs (WP.org only). No
self-deactivation or self-update of the bridge plugin. No agent-user
modification. No raw INSERT/UPDATE/DELETE. No publication by default
— content always starts as `draft`. Full anti-pattern list in
[`docs/capabilities.md`](docs/capabilities.md) §"Limitations".

## Architecture

```
   Claude Code              (your machine — agent runtime)
        │  MCP protocol (stdio)
        ▼
   MCP gateway              (claude-plugin/mcp-gateway, Node.js bundle)
        │  HTTPS — HMAC-signed REST calls
        ▼
   WordPress plugin         (plugin/ia-webmaster-bridge, PHP)
        ▼
   WordPress 7.0 + Divi 5   (the site)
```

## Quick start

```bash
# 1. Install the WP plugin (copy folder + activate via WP admin or wp-cli).
wp plugin install /path/to/ia-webmaster-bridge --activate

# 2. In WP admin → Settings → IA Webmaster Bridge, create your first key.
#    Copy (key_id, secret); start with scope=read.

# 3. Install the Claude Code plugin (loads the gateway).
/plugin marketplace add RiusmaX/ia-webmaster-bridge
/plugin install ia-webmaster@ia-webmaster-bridge

# 4. Write ~/.iawm/config.json (template at claude-plugin/mcp-gateway/config.example.json).
#    { "baseUrl": "https://your-site.tld", "keyId": "...", "secret": "..." }

# 5. Restart Claude Code, then in a session: "Run iawm_status."
```

If `iawm_status` returns `{ "ok": true }`, you're live.

## Production deployment

A real install on a public site has a few more constraints (HTTPS
enforcement, IP allow-list, wp-cron strategy, monitoring). See
[`docs/production-deployment.md`](docs/production-deployment.md) — it
walks the full pre-flight checklist, install steps, smoke test
sequence and go/no-go criteria for opening writes.

## Security model

Eight layers of defence in depth: HTTPS → IP allow-list → HMAC
signature → key resolution → scope check → kill switch → audit log →
backup + confirmation tokens. Threat model and incident response are
documented in [`docs/security-model.md`](docs/security-model.md).

## Documentation

Operator-facing:

- [`docs/capabilities.md`](docs/capabilities.md) — comprehensive
  tour of the 108 capabilities with example prompts.
- [`docs/skills.md`](docs/skills.md) — the 15 Claude Code skills
  that compose the tools into operator workflows.
- [`docs/operations.md`](docs/operations.md) — operator runbook (key
  rotation, multi-operator, safe-update workflow, WP-CLI fallback,
  pentest checklist).
- [`docs/production-deployment.md`](docs/production-deployment.md) —
  fresh production install walkthrough.
- [`docs/security-model.md`](docs/security-model.md) — 8-layer
  defence in depth + threat model + incident response.
- [`docs/design-system.md`](docs/design-system.md) — Divi design
  system workflow (colors, fonts, variables, branding).

Reference:

- [`docs/divi5-format.md`](docs/divi5-format.md) — Divi 5 storage
  format reference.
- [`docs/divi5-modules-catalog.md`](docs/divi5-modules-catalog.md) —
  the 105-module registry (auto-generated).
- [`docs/divi5-compose-dsl.md`](docs/divi5-compose-dsl.md) — page
  composer DSL.
- [`docs/architecture.md`](docs/architecture.md) — three-component
  architecture.
- [`docs/decisions.md`](docs/decisions.md) — 31 structuring
  decisions (D-001 → D-031).
- [`docs/roadmap.md`](docs/roadmap.md) — phase-by-phase history,
  Phases 0-9 closed in v1.3.0; small-prod validation (9.7) pending.
- [`docs/glossary.md`](docs/glossary.md) — vocabulary primer.

## Contributing

Code style, commit conventions, test/doc requirements and the
security-disclosure channel are in [`CONTRIBUTING.md`](CONTRIBUTING.md).

## Changelog

Version history in keep-a-changelog format: [`CHANGELOG.md`](CHANGELOG.md).

## License

[GPL-3.0-or-later](LICENSE). The WordPress plugin being a derivative
of WordPress, the entire project is published under the GPL.
