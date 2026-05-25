---
name: design-system-first
description: Configure the brand design system, THEN author pages that reference it. Produces on-brand pages by construction — palette, fonts, variables and branding are set globally before any layout is composed.
---

# Design system first, pages second

## Goal

Make sure the site's **global design system** (palette, fonts, variables,
logo, favicon, accent colour) is configured **before** any page is built —
so every section authored later can reference `gcid-*` colour slots and
`$variable(gvid-...)$` variables instead of hard-coded values. The result:
a palette change on the site propagates to every page in one shot, and
pages are on-brand by construction rather than decorated after the fact.

## When to use it

- A new site or a rebranding, where the user hands you a brand brief
  (logo, primary colour, fonts) before any layout work.
- Right before invoking the `create-divi-page` skill on a site whose
  `iawm_divi_global_data` returns the Divi defaults (`#2ea3f2` and
  friends).
- After a re-read of `docs/design-system.md` reveals the workflow has been
  skipped on a recent site.

## Prerequisites

- API key with `read` and `divi:write` (or equivalent write scope).
- `iawm_divi_status` confirms Divi 5 is active.

## Step-by-step

### 1. Collect the brand brief from the user

Ask, in order:

1. **Primary colour** (CTAs, accents) — hex, brand name, or "match this
   logo".
2. **Secondary colour** (optional, used for highlights).
3. **Heading / body fonts** — Google Fonts names or hosted families.
4. **Logo + favicon** — URL or media library ID.
5. **Brand tone** (formal / warm / premium / casual / technical) — informs
   contrast and font weight choices, even if it does not become a stored
   field.
6. **Any required custom variables** (e.g. `gvid-radius`,
   `gvid-section-padding`).

If anything is missing, **ask** rather than guess. The whole point of the
skill is to anchor downstream pages on a known foundation.

### 2. Read the current design system

```
iawm_divi_global_data()
```

Capture the current `gcid-*` slots, the current fonts, the existing
variables. This lets you produce a clean diff for the user instead of
overwriting blindly.

### 3. Propose a palette + variables + fonts

Output a short proposal block:

```
## Proposed design system

Colours:
- gcid-primary-color   : #003E7E   (CTAs, links)
- gcid-secondary-color : #F4C430   (highlights)
- gcid-heading-color   : #0B1F33   (h1/h2/h3)
- gcid-body-color      : #2A2A2A   (paragraphs)
- gcid-background-color: #FFFFFF   (sections)
+ custom accents (optional): #...  → cgid-accent-1

Fonts:
- Headings: "Inter", sans-serif (weights 600/700)
- Body    : "Inter", sans-serif (weight 400)

Variables:
- gvid-radius          : 8px
- gvid-section-padding : 96px
```

### 4. Validate with the user

Show the proposal, get an explicit "go". Iterate if needed — never write
the design system without confirmation, because every page already
referencing these slots will shift.

### 5. Write the design system

Apply each piece in this order (colours first, so fonts and variables can
inherit / compose if you want):

```
iawm_divi_global_colors_update({ colors: [...] })
iawm_divi_global_fonts_update({ fonts: [...] })
iawm_divi_global_variables_update({ variables: [...] })
iawm_divi_branding_update({ logo_id: <id>, favicon_id: <id> })
iawm_divi_theme_options_update({
  fields: {
    accent_color:       "#003E7E",
    // any other customizer keys (heading defaults, link colour, etc.)
  },
})
```

Run each one as a separate, auditable call. If a write fails, fix the
input and retry — do **not** skip ahead to step 7 with a partial system.

### 6. Verify by reading back

```
iawm_divi_global_data()
iawm_divi_branding_get()
```

Confirm every value lines up with the proposal. Show the user a short
"design system applied" recap.

### 7. Now author pages

From here, **always** reference the design system instead of inlining
values. Inside `iawm_divi_page_compose`, use:

```js
{ module: "section", background: { color: { gcid: "gcid-primary-color" } } }
{ module: "text",    text: { color: { gcid: "gcid-heading-color" } } }
```

For variables (radius, spacing, custom strings), use the
`$variable(gvid-...)$` syntax — see `docs/design-system.md` for the full
syntax and edge cases.

### 8. Document where it came from

Mention in the page recap which `gcid-*` and `gvid-*` slots the layout
touches. That makes future palette work predictable.

## Reference

- `docs/design-system.md` — full workflow, the exact `$variable(...)$`
  serialisation, and migration patterns when an old page still hard-codes
  hex values.
- `iawm_divi_global_data` / `iawm_divi_branding_get` — the canonical
  read-side endpoints.
- `iawm_divi_global_colors_update`, `iawm_divi_global_fonts_update`,
  `iawm_divi_global_variables_update`, `iawm_divi_branding_update`,
  `iawm_divi_theme_options_update` — the write side.

## When NOT to use this skill

- The site already has a stable, validated design system and the user
  only asks for a single page — jump straight to `create-divi-page` and
  reference the existing `gcid-*` slots.
- The user explicitly wants a one-off page with non-brand styling (a
  campaign microsite, a holiday banner). In that case keep the colours
  inline and do **not** mutate the global system.
- The site is not Divi-powered (`iawm_divi_status` returns inactive).
  This skill is Divi 5-specific.
- The user has not given consent to overwrite an existing palette — the
  brand owner must sign off before global colour writes, since they
  cascade everywhere.
