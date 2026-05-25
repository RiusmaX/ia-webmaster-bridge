# Design system first — building ultra-personalised pages

> Status: Living · Last updated: 2026-05-25

The Divi 5 design system is **the** primary lever for cohesive,
brand-consistent pages. Before authoring any page, the agent should
configure or read the site's design tokens — colours, typography,
spacing, reusable variables — then **reference** them from every
module.

This guide explains:

- what the design system actually contains;
- how to read it (`iawm_divi_global_data`, `iawm_divi_theme_options_get`);
- how to write it (the four `iawm_divi_global_*_update` tools shipped in
  v0.28.0);
- how to reference the tokens from a generated page so every module
  inherits the brand palette.

---

## What the Divi design system contains

There are **three layers** of global style state on a Divi 5 site,
all manipulable through the API now:

### 1. Global colors palette — `iawm_divi_global_data` → `global_colors`

The classic 5-slot palette (and unlimited custom slots). Each entry is
keyed by a stable `gcid-<id>`:

```json
{
  "gcid-primary-color":   { "color": "#0a6ef5", "label": "Primary",   "status": "active" },
  "gcid-secondary-color": { "color": "#10b981", "label": "Secondary", "status": "active" },
  "gcid-heading-color":   { "color": "#0f172a", "label": "Heading",   "status": "active" },
  "gcid-body-color":      { "color": "#475569", "label": "Body",      "status": "active" },
  "gcid-link-color":      { "color": "#0a6ef5", "label": "Link",      "status": "active" },
  "gcid-accent-amber":    { "color": "#f59e0b", "label": "Amber",     "status": "active" }
}
```

The 5 default `gcid-*-color` slots are special: Divi binds them to its
own customizer so the entire site frontend reacts to changes.

Every module attribute that takes a color (text color, button bg,
border, shadow…) accepts EITHER a hex literal OR a reference to a
`gcid-*` id. Always prefer the reference — that's the single
source of truth.

### 2. Global fonts — `iawm_divi_global_data` → `global_fonts`

Two slots: heading family + body family. Saved in the ePanel options:

```json
{ "heading_font": "Inter", "body_font": "Inter" }
```

Values are Google Fonts family names ("Inter", "Roboto",
"Playfair Display", "Source Sans 3"…) or system fonts ("Arial",
"Georgia"). The browser falls back gracefully.

### 3. Global variables (design tokens) — `iawm_divi_global_data` → `global_variables`

Divi 5's typed token system. Six buckets, each a map keyed by
`gvid-<uuid>` → `{ label, value, order, status }`:

| Bucket | Typical use |
|---|---|
| `numbers` | radii, sizes (e.g. `--brand-radius` = `12px`, `--card-pad` = `24px`) |
| `strings` | reusable copy fragments (CTA labels, footer credits) |
| `images` | recurring assets (logo URL, hero bg) |
| `links` | external URLs (newsletter, app store) |
| `colors` | non-palette accents kept outside the `gcid-*` set |
| `fonts` | additional font families beyond heading/body |

Within modules, reference a global variable via the `$variable(<id>)$`
escape syntax in any value position (e.g. a border radius can be
`$variable(gvid-card-radius)$` instead of `"12px"`).

---

## The recommended authoring workflow

```
   ┌───────────────────────────────────────────────────────┐
   │ 1. READ the existing design system                    │
   │    iawm_divi_global_data                              │
   │    iawm_divi_theme_options_get  (logo, favicon, etc.) │
   └────────────────────┬──────────────────────────────────┘
                        ▼
   ┌───────────────────────────────────────────────────────┐
   │ 2. NORMALISE: decide the brand palette + fonts.       │
   │    Pick stable gcid ids. Pick families. Pick the few  │
   │    design tokens you'll reuse (radii, spacings).      │
   └────────────────────┬──────────────────────────────────┘
                        ▼
   ┌───────────────────────────────────────────────────────┐
   │ 3. WRITE the design system FIRST                      │
   │    iawm_divi_global_colors_update                     │
   │    iawm_divi_global_fonts_update                      │
   │    iawm_divi_global_variables_update                  │
   │    iawm_divi_theme_options_update  (logo, favicon)    │
   └────────────────────┬──────────────────────────────────┘
                        ▼
   ┌───────────────────────────────────────────────────────┐
   │ 4. AUTHOR pages and theme-builder templates that      │
   │    REFERENCE the tokens (never hard-code colors/      │
   │    fonts you've made globals).                        │
   │    iawm_divi_theme_builder_compose                    │
   │    iawm_divi_page_compose                             │
   └───────────────────────────────────────────────────────┘
```

Step 4 makes the whole thing pay off: when the client says "actually
the primary should be teal, not blue", you update **one** `gcid-*`
entry and every page on the site re-renders with the new colour.

---

## Concrete example: minimum-viable brand kit

```js
// Step 1 — read what's there.
const ds = await iawm_divi_global_data();

// Step 2 — design.
const palette = {
  "gcid-primary-color":   { color: "#0a6ef5", status: "active" },  // Brand blue
  "gcid-secondary-color": { color: "#10b981", status: "active" },  // Success green
  "gcid-heading-color":   { color: "#0f172a", status: "active" },  // Near-black headings
  "gcid-body-color":      { color: "#334155", status: "active" },  // Slate body
  "gcid-link-color":      { color: "#0a6ef5", status: "active" },  // Match primary
  "gcid-accent-amber":    { color: "#f59e0b", status: "active" },  // Custom CTA highlight
  "gcid-surface-card":    { color: "#f8fafc", status: "active" },  // Card background
};

// Step 3 — write.
await iawm_divi_global_colors_update({ global_colors: palette });
await iawm_divi_global_fonts_update({ heading_font: "Inter", body_font: "Inter" });
await iawm_divi_global_variables_update({
  global_variables: {
    numbers: {
      "gvid-brand-radius": { label: "Brand radius", value: "12px", order: 1, status: "active" },
      "gvid-card-pad":     { label: "Card padding", value: "32px", order: 2, status: "active" },
    },
    strings: {
      "gvid-cta-label":    { label: "Primary CTA", value: "Demander un devis", order: 1, status: "active" },
    },
  },
});
await iawm_divi_theme_options_update({
  options: {
    "divi_logo":    "https://example.com/wp-content/uploads/logo.svg",
    "divi_favicon": "https://example.com/wp-content/uploads/favicon.png",
  },
});

// Step 4 — author. From now on, every page/template references
// these tokens — see docs/divi5-format.md for the JSON shape.
```

---

## Reading the result back

After writing, `iawm_divi_global_data` reflects the changes
immediately. Pages already on the site that reference the same
`gcid-*` / `gvid-*` ids will pick up the new values on next render —
Divi caches generated CSS, so on a heavy site you may want to flush
the et-cache (look under `wp-content/et-cache/`) after big design-
system changes.

---

## Pre-configured layouts (Divi's 2000+ layouts library)

A frequent question: "can the agent pick from Divi's 2000+ premade
layouts directly?" Short answer: **not server-side**. The premade
catalog is served by the Visual Builder from elegantthemes.com, not
through a REST route on the site. Three workflows:

1. **Hybrid (recommended)** — the human operator opens the Visual
   Builder, browses the premade collection, clicks "Save to library"
   on one or more layouts they want to start from. Those then become
   visible to the API via `iawm_divi_library_local` (and the
   `iawm_divi_library_list` "local + cloud" view) and can be
   referenced as starting points by the composer.

2. **Divi Cloud sync** — if the user connects their elegantthemes
   account AND the `cloudToken` is present in the site DB (set by the
   Visual Builder once authenticated), library items are accessible
   over the API. Inspect via `iawm_divi_cloud_status`. We do not
   write the cloudToken from the API — it has to come from the VB.

3. **Direct elegantthemes API** — possible but unofficial: hit
   elegantthemes.com directly from the gateway with the user's
   license. Not implemented here; doing so reliably would require
   tracking changes to a non-public API.

In practice, the design-system-first workflow above is more powerful
than picking a premade layout: you build pages that are exactly
on-brand by construction, with the `iawm_divi_page_compose` patterns
+ free-form mix. The premade layouts become a source of inspiration
(structure ideas, copy direction) more than a literal starting point.

---

## Where to go next

- [`docs/divi5-format.md`](divi5-format.md) — exact JSON shape of
  saved Divi 5 attributes (breakpoint envelope, color reference
  format, variable reference format).
- [`docs/divi5-modules-catalog.md`](divi5-modules-catalog.md) —
  auto-generated catalog of every module's accepted attribute paths.
- [`docs/divi5-compose-dsl.md`](divi5-compose-dsl.md) — the
  composer DSL the agent uses to assemble pages from patterns,
  free-form sections, or raw blocks.
