# IA Webmaster Bridge

> Drive a WordPress + Divi 5 site from Claude. Secure, audited, every
> action signed.

![License](https://img.shields.io/badge/license-GPL--3.0--or--later-blue)
![Plugin](https://img.shields.io/badge/plugin-0.34.0-green)
![Gateway](https://img.shields.io/badge/gateway-0.23.0-green)
![Status](https://img.shields.io/badge/status-v1.0.0--rc-orange)

## Status

Phases 0–6 implemented. **Phase 7 — production hardening — is in
progress**; v1.0.0 target is imminent. Tracking lives in
[`docs/phase-7-action-plan.md`](docs/phase-7-action-plan.md).

Today's state:

- 7.1 network hardening (HTTPS + IP allow-list) — done
- 7.2 lifecycle hardening (rotation cron, smoke test, self-check) — done
- 7.3 Divi branding writer — done
- 7.4 admin UI redesign — done
- 7.5 i18n plugin admin (en + fr_FR) — in progress
- 7.6 PHPUnit tests, 7.7 skills assemblies, 7.8 doc pass (this doc),
  7.9 pentest dry-run, 7.10 v1.0.0 release — pending

Validated end-to-end on a local WordPress 7.0 + Divi 5 site. Test in a
local environment before any production use.

## What it does

Exposes a controlled, signed REST API on a WordPress site, paired with
a Claude Code plugin that mounts the matching MCP tools. From a single
prompt Claude can plan and create a full Divi 5 site (header, footer,
homepage, design tokens, SEO) — every call HMAC-signed, scope-checked,
audit-logged, and protected by automatic pre-op backups and a kill
switch.

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

- [`docs/operations.md`](docs/operations.md) — operator runbook (key
  rotation, multi-operator, safe-update workflow, WP-CLI fallback,
  pentest checklist).
- [`docs/design-system.md`](docs/design-system.md) — Divi design system
  workflow (colors, fonts, variables, branding).
- [`docs/divi5-format.md`](docs/divi5-format.md) — Divi 5 storage
  format reference.
- [`docs/divi5-modules-catalog.md`](docs/divi5-modules-catalog.md) —
  the 105-module registry.
- [`docs/phase-7-action-plan.md`](docs/phase-7-action-plan.md) — the
  current sprint towards v1.0.0.

## Contributing

Code style, commit conventions, test/doc requirements and the
security-disclosure channel are in [`CONTRIBUTING.md`](CONTRIBUTING.md).

## Changelog

Version history in keep-a-changelog format: [`CHANGELOG.md`](CHANGELOG.md).

## License

[GPL-3.0-or-later](LICENSE). The WordPress plugin being a derivative
of WordPress, the entire project is published under the GPL.
