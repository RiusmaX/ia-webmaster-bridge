---
name: webmaster-wordpress
description: Method and guardrails to manage a WordPress site through the IA Webmaster Bridge adapter. Use it for any content, media, menu, configuration or diagnostics task on a WordPress site connected via the mcp__ia-webmaster__iawm_* tools.
---

# WordPress webmaster — method

The WordPress site is driven through the **IA Webmaster Bridge** adapter. Every
action goes through the MCP tools `mcp__ia-webmaster__iawm_*` — each call is
authenticated (HMAC signature) and logged.

## First thing first

Start with `iawm_status`: confirm that the connection is valid and that the
**kill switch** is not active. If it is, writes have been intentionally turned
off — warn the user instead of trying to write.

## Tool families

- **Diagnostics** (`iawm_diagnostics_*`) — system, plugins, themes, logs.
- **Content** (`iawm_content_*`) — pages and posts: list, get, create, update.
- **Media** (`iawm_media_*`) — media library: list, get, sideload, update.
- **Taxonomies** (`iawm_taxonomy_*`) — categories, tags.
- **Menus** (`iawm_menu_*`) — navigation menus.
- **Configuration** (`iawm_config_*`) — site settings, users.
- **Audit** (`iawm_audit`) — log of every action performed.

## Guardrails — to follow systematically

1. **Read before writing.** Before modifying a content item, read it (`get`)
   to know its state and its `builder` (gutenberg / divi / classic).
2. **Dry-run first.** For any non-trivial write, call the tool with
   `dry_run: true`, show the user what would happen, and apply only after
   their agreement.
3. **Draft by default.** Content is created as a draft. Only publish
   (`status: publish`) on explicit request.
4. **Verify after writing.** After a creation or update, read the result back
   to confirm.
5. **Never bypass the guardrails** and never suggest disabling them.

## Troubleshooting

- Check `iawm_diagnostics_logs` (WordPress errors) and
  `iawm_diagnostics_system`.
- Check `iawm_audit` to trace the recent actions.
- A 403 "kill switch" status on a write: warn the user that writes have been
  turned off on the site side.

## Pages built with Divi

The `builder` field of `iawm_content_get` returns `divi`, `gutenberg` or
`classic`. **Do not write Gutenberg content into a Divi page** (or vice
versa): it would corrupt the page. Fine-grained Divi support is in place
(Phase 3 of the project) — use the `create-divi-page` skill for those.

## Content language

When the user asks Claude to produce text (page bodies, headings, button
copy…), respect the **target language**. By default, follow the WordPress
site locale. The content-generation tools accept an explicit `language`
parameter (BCP-47, e.g. `fr-FR`, `en-US`, `es-ES`, `de-DE`) — pass it
through whenever the user states a language preference.
