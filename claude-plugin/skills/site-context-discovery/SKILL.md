---
name: site-context-discovery
description: Bootstrap the per-site context on a fresh install. Reads the live design system, plugin list, homepage and a few sample pages, then drafts a brand + content + design + infrastructure context for the operator to confirm and save.
---

## Goal

The first time Claude lands on a site, there's no "how to act here"
brief. This skill assembles a draft `site_context` from observable
signals — design tokens, installed plugins, the homepage's
structure — and presents it to the operator for review before
writing it via `iawm_site_context_update`.

## When to use this skill

- Right after a fresh plugin install on a site you haven't worked on before.
- After a major brand refresh (palette / fonts / voice changed).
- Periodically (every quarter, say) to catch drift between the
  stored context and the live state of the site.

## Prerequisites

The signing key must hold the scopes: `read`, `config:write` (for the
final write). Without `config:write` you can still propose a draft —
just hand it to a human to paste into the admin Context tab.

## Step-by-step

### 1. Check what's already there

```
iawm_site_context_get()
```

- If `populated: true`, **stop and ask the operator** whether they want
  to overwrite. A populated context means someone already curated it.
- If `populated: false`, continue.

### 2. Collect the signals (read-only)

Run these in parallel — they are all GETs:

```
iawm_status()                       # site identity + version
iawm_divi_status()                  # confirms Divi 5
iawm_divi_global_data()             # palette + fonts + variables
iawm_divi_branding_get()            # logo / favicon URLs
iawm_diagnostics_plugins()          # what's installed + active
iawm_diagnostics_themes()           # confirm Divi is the active theme
iawm_divi_theme_builder_list()      # header/footer/body templates
iawm_content_list({ type: "page", per_page: 20 })
```

If a homepage is set as front page, grab its layout:

```
iawm_content_get({ id: <front_page_id> })
iawm_divi_page_read({ post_id: <front_page_id>, mode: "flat" })
```

This tells you which Divi patterns the homepage uses (counter the
distinct block names).

### 3. Draft the context

Synthesise the signals into a draft. Examples below — adapt to what
the site actually exposes:

```json
{
  "brand": {
    "name": "<from blogname>",
    "tagline": "<from blogdescription>",
    "voice": "<TO BE FILLED BY OPERATOR — observable signals can't tell you>",
    "audience": "<TO BE FILLED BY OPERATOR>",
    "do_list": [],
    "dont_list": []
  },
  "content": {
    "default_status": "draft",
    "default_language": "<from site locale>",
    "page_naming_convention": "<TO BE FILLED>",
    "homepage_pattern": "<derived from the front page layout, e.g. 'hero - features3col - testimonials - pricing - cta'>",
    "main_cta": { "label": "<TO BE FILLED>", "url": "<TO BE FILLED>" }
  },
  "design": {
    "palette_summary": "<one-line summary of the 5 gcid-* colours>",
    "fonts_summary": "<heading_font / body_font from global_data>",
    "patterns_used": ["<derived from homepage flat read>"]
  },
  "infrastructure": {
    "plugins_required": ["<every currently-active plugin slug>"],
    "plugins_forbidden": [],
    "environment_note": ""
  },
  "notes": "Auto-drafted by site-context-discovery on <date>. Fields marked TO BE FILLED need the operator's input."
}
```

### 4. Present to the operator

Show the draft in a structured way (table per section). Highlight the
"TO BE FILLED" fields — those need human judgement. Ask the operator:

- Confirm the brand name + tagline.
- Provide the voice / audience / do-don't lists.
- Confirm the homepage pattern interpretation.
- Confirm the plugins_required list (the auto-draft includes
  everything currently active; the operator may want to narrow it to
  the truly mandatory ones).

### 5. Write it

Once the operator confirms:

```
iawm_site_context_update({
  context: { ... the curated draft ... }
})
```

Verify by re-reading:

```
iawm_site_context_get()
```

Expected: `populated: true`, `context.updated_at` is now, every field
the operator filled is back.

## When NOT to use this skill

- On a site where `iawm_site_context_get` already returns
  `populated: true` and the operator hasn't asked for a refresh —
  overwriting curated knowledge with an auto-draft is regression.
- When `read` scope is missing — you cannot even gather the signals.
- As a substitute for talking to the operator about the brand voice —
  observable signals never tell you "we never say 'click here'" or
  "always lead with the customer outcome". Those need a conversation.
