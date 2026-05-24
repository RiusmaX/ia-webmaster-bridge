# IA Webmaster Bridge

Drive a WordPress + **Divi 5** site directly from Claude — an AI-assisted
webmaster, secure and auditable, able to **build a complete site from a
prompt**.

IA Webmaster Bridge connects **Claude Code** (and Claude Desktop) to a
**WordPress 7.0** site to manage content, media, menus, taxonomies,
configuration, plugins, SEO and — most importantly — the **generation of
full Divi 5 pages, headers and footers**. Every action is
**authenticated** (HMAC signature), **logged** and protected by
**guardrails**.

> **Status:** Phases 0 to 3 complete. The system can generate a complete
> WordPress + Divi 5 site from a prompt (homepage + header + footer +
> SEO). Validated end-to-end in a local environment. Test in a local
> environment before any production use.

## Architecture

The system has three pieces:

```
   Claude Code
       │  MCP protocol (stdio)
       ▼
   MCP gateway       ── claude-plugin/mcp-gateway/  (Node.js, self-contained bundle)
       │  HTTPS — HMAC-signed requests
       ▼
   WordPress plugin  ── plugin/ia-webmaster-bridge/  (PHP, REST API)
       ▼
   WordPress 7.0
```

1. **WordPress plugin** — exposes a controlled REST API under the
   namespace `ia-webmaster/v1`.
2. **MCP gateway** — translates MCP tool calls from Claude into signed
   requests to the plugin.
3. **Claude Code plugin** — bundles the MCP tools and a set of *skills*
   (webmaster working methods).

## Security

Security is a first-class concern of this project:

- **HMAC-SHA256 authentication** on every request (signature + replay
  nonce + time window).
- **Audit log**: every action is traced (timestamp, route, outcome, IP).
- **Write guardrails**: *dry-run* mode, drafts by default, **kill
  switch** to disable all writes.
- **Allow-list** of mutable settings — no critical option is exposed.
- The shared secret never lives in the repository.

## Features

The adapter exposes **38+ MCP tools** grouped into families:

### WordPress management (Phases 0-2)
- **Content** — list, read, create, update pages and posts (normalised
  Gutenberg blocks). Critical `wp_slash` bug resolved.
- **Media** — media library, sideload from URL, metadata.
- **Taxonomies** — categories, tags, assignment.
- **Menus** — navigation menus and theme locations.
- **Configuration** — site settings, users.
- **Diagnostics** — system, plugins, themes, logs (read-only).

### Divi 5 (Phase 3)
- **Divi pages** — structured tree read (3 modes: tree, flat, raw),
  bit-faithful round-trip writes.
- **41 native modules** implemented (text, blurb, cta, image, button,
  heading, number-counter, testimonial, gallery, video, pricing-tables,
  icon-list, social-media-follow, team-member, signup, map, accordion,
  tabs, slider, contact-form, menu, search, breadcrumbs, post-title…).
- **13 parametric patterns**: hero, features3col, ctaBanner,
  imageTextSplit, testimonials, faqAccordion, numbersBar, videoSection,
  contactSection, pricing3col, teamGrid, headerSimple, footerStandard.
- **Full Theme Builder** — template creation with header / footer /
  dynamic body. `setup-site-defaults` wrapper that lays everything out in
  one call.
- **Divi Library** — local layouts (Save to Library) accessible via the
  API; Divi Cloud is limited (browser-side token).
- **Design system** — fetch the site's global colors and fonts.
- **Full catalogue** of the ~99 native modules is documented in
  (`docs/divi5-modules-catalog.md`).

### SEO (Phase 3)
- **Normalised SEO API** with the **Rank Math** backend (meta_title,
  meta_description, focus_keyword, canonical, Open Graph, Twitter,
  robots).
- Yoast backend prepared (mapping ready, activation pending).
- **Automatic plugin install** from WP.org via `/plugins/install`.

### Claude Code skills (7 methods)
- `webmaster-wordpress` — cross-cutting methods and guardrails.
- `create-wordpress-page` — classic WordPress page workflow.
- `create-divi-page` — full prompt → Divi page workflow (8 steps).
- `audit-wordpress-site` — site health audit.
- `frontend-design-wordpress` — design principles (hierarchy,
  typography, mobile-first).
- `marketing-conversion-wordpress` — AIDA/PAS/FAB frameworks, CTAs,
  social proof.
- `seo-wordpress` — SEO audit grid and best practices.

## Installation

**Prerequisites:** WordPress 7.0+, Node.js 18+, Claude Code.

### 1. WordPress side

1. Copy the `plugin/ia-webmaster-bridge/` folder into the site's
   `wp-content/plugins/`.
2. Activate the "IA Webmaster Bridge" plugin.
3. Under **Settings → IA Webmaster Bridge**, generate the secret. Note
   the site URL, the key id and the secret.

### 2. Claude Code side (CLI)

```
/plugin marketplace add RiusmaX/ia-webmaster-bridge
/plugin install ia-webmaster@ia-webmaster-bridge
```

If `/plugin` is not available in your environment, install at user
scope:

```
claude mcp add ia-webmaster --scope user -- node /path/to/.iawm/gateway/index.js
```

### 2bis. Claude Desktop side

Settings → Connectors → **Add custom connector**:
- Command: `node`
- Args: `C:\Users\<you>\.iawm\gateway\index.js`

OR manually edit `%APPDATA%\Claude\claude_desktop_config.json`:

```json
{
  "mcpServers": {
    "ia-webmaster": {
      "command": "node",
      "args": ["C:\\Users\\<you>\\.iawm\\gateway\\index.js"]
    }
  }
}
```

### 3. Configuration

Create **`~/.iawm/config.json`** (template:
[`config.example.json`](claude-plugin/mcp-gateway/config.example.json)):

```json
{
  "baseUrl": "https://example.com",
  "keyId": "iawm_xxxxxxxxxxxx",
  "secret": "the-secret-generated-in-admin"
}
```

Restart Claude Code / Claude Desktop: the `iawm_*` tools are available.

## Usage examples

Once connected, you can ask Claude things like:

> *"Build the homepage for my site. Industry: [your business]. Audience:
> [persona]. Primary goal: [expected action, e.g. quote request,
> signup, purchase]. Include a header (logo + menu + CTA), 8 to 10
> sections (hero, intro, key numbers, services, testimonials, pricing,
> FAQ, contact) and a multi-column footer."*

The `create-divi-page` skill will orchestrate:
1. Structured brief (audience, tone, sections).
2. Page plan validated.
3. Draft creation (never direct publish).
4. Divi 5 block generation with parametric patterns.
5. Theme Builder setup (header + footer).
6. Rank Math SEO.
7. Validation read.
8. Explicit ask before publish.

### Content language

Generation tools (`iawm_content_create`, `iawm_content_update`,
`iawm_divi_page_compose`, `iawm_divi_theme_builder_compose`) accept an
optional `language` parameter (BCP-47 tag, e.g. `en-US`, `fr-FR`,
`es-ES`, `de-DE`, `pt-BR`, `ja-JP`…) that hints Claude on which language
to use when generating texts. Defaults to the WordPress site locale.
Tooling, comments and documentation stay in English; only the produced
content is localised.

## Repository structure

| Path | Role |
|------|------|
| `plugin/ia-webmaster-bridge/` | WordPress plugin (REST API) |
| `claude-plugin/` | Claude Code plugin (MCP tools + skills) |
| `claude-plugin/mcp-gateway/` | MCP gateway (TypeScript, self-contained bundle) |
| `.claude-plugin/marketplace.json` | Marketplace catalogue |
| `docs/` | Architecture, roadmap, decisions log, glossary |
| `specs/` | Spec per feature |

## Development

Rebuild the MCP gateway with:

```
npm install --prefix claude-plugin/mcp-gateway
npm run build --prefix claude-plugin/mcp-gateway
```

Develop against a local WordPress site (LocalWP), never directly
against production. The workflow is documented in `docs/`.

## Roadmap

- **Phases 0–3 (done)** — secure connection, content plan, full Divi 5
  (41 modules, 13 patterns, Theme Builder), Rank Math SEO, first
  successful end-to-end test on a real site.
- **Phase 4** — infrastructure: themes (install/activation), database
  (export, controlled queries), backup and restore.
- **Phase 5** — security hardening (authorisation model, pre-destructive
  backups, penetration testing).
- **Phase 6** — advanced webmaster layer (per-site context file,
  documented workflows, scheduled routines).
- **Backlog**: WooCommerce modules (25 inventoried), Yoast SEO backend,
  Theme Builder patterns for single post / archive.

## License

[GPL-3.0-or-later](LICENSE). The WordPress plugin being a derivative of
WordPress, the entire project is published under the GPL.
