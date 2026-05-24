# Divi 5 format — reverse engineering

> Source: reference pages #19 and #29 on the local site, populated in
> the Divi 5.5.2 Visual Builder then read via `iawm_content_get`.
> **Complete catalog of native modules** in
> [`divi5-modules-catalog.md`](divi5-modules-catalog.md) — ~99 native
> modules inventoried (21 implemented on the TS builders side).
> Last updated: 2026-05-23.

This doc records what we **directly observed**, not what community
documentation assumes. After each major Divi release, re-run a round-trip
on this page.

## TL;DR

A Divi 5 page is stored in `post_content` as **Divi Gutenberg blocks**,
with a five-level hierarchy:

```
wp:divi/placeholder              ← root wrapper (1 per Divi page)
└── wp:divi/section              ← full-width "band" block
    └── wp:divi/row              ← row within the section
        └── wp:divi/column       ← column (1, 2, 3 or 4 within the row)
            └── modules          ← text, blurb, cta, image, button…
```

Each Gutenberg block contains a **JSON attribute payload** (JSON-escaped
`"` → `"`) with two recurring roots:

- **`module`** — structural and style parameters (decoration, advanced, spacing, sizing…).
- **`content`** (text modules) / **`title`** / **`imageIcon`** / **`image`** / **`button`** — module-specific content and data.

Plus a `builderVersion` at the root (e.g. `"5.5.2"`).

## Root wrapper `wp:divi/placeholder`

Every Divi 5 page starts with:

```html
<!-- wp:divi/placeholder -->
…sections…
<!-- /wp:divi/placeholder -->
```

This wrapper is what tells Divi to take over rendering of the page (in
place of the default WordPress theme). Without it, Divi blocks may not
render correctly.

> ⚠️ The meta `_et_pb_use_builder = 'on'` is also still required for
> Divi to recognize the page as "built". The wrapper alone is not enough.

## Mandatory hierarchy

The tree **must** follow this exact order:

| Level | Block                     | Constraints |
|--------|---------------------------|-------------|
| 1      | `wp:divi/placeholder`     | 1 only, root |
| 2      | `wp:divi/section`         | N sections in sequence |
| 3      | `wp:divi/row`             | 1 or N rows inside a section |
| 4      | `wp:divi/column`          | Depends on the row's `columnStructure` |
| 5      | Modules (`wp:divi/text`, …) | N modules per column |

Breaking this order = page not rendered, or visible errors in the VB.

## Attribute format

The attribute JSON is encoded with **`"` instead of `"`** in values
(standard Gutenberg escaping). When parsed on the PHP side with
`parse_blocks()`, we get native associative structures.

### Generic per-block structure

```json
{
  "module": {
    "advanced": { /* type, columnStructure, link, position… */ },
    "decoration": { /* background, spacing, layout, sizing, font… */ }
  },
  "content": { "innerContent": { /* per breakpoint */ } },
  "title":   { "innerContent": { /* per breakpoint */ } },
  "builderVersion": "5.5.2"
}
```

**Structural** blocks (section/row/column) mainly carry `module`.
**Content** blocks (text/blurb/cta/image/button) add one or more
specific fields (`content`, `title`, `image`, `imageIcon`, `button`).

### Multi-breakpoints

All styleable properties follow the pattern:

```json
{
  "decoration": {
    "background": {
      "desktop": { "value": { "color": "#fff" } },
      "phone":   { "value": { "color": "#000" } }
    }
  }
}
```

**Observed breakpoints**: `desktop`, `tablet`, `phoneWide`, `phone`.
(Divi 5 also supports custom breakpoints — not encountered here.)

If only `desktop` is defined, Divi inherits it for all others.

### Global variables (design system)

A value can reference a **global variable** instead of a constant:

```
$variable({"type":"color","value":{"name":"gcid-heading-color","settings":{}}})$
```

Format: `$variable( <JSON> )$` where the JSON contains:
- `type`: `color` (seen), probably also `font`, etc.
- `value.name`: variable identifier (`gcid-*` = global color id).
- `value.settings`: optional overrides.

These variables are defined via `divi/v1/global-data/global-colors` /
`global-fonts` / `global-variables`. **Always prefer the variable** over
a hardcoded value: changing the global palette propagates the change.

### Divi icons

`blurb`-type modules can display a Divi icon:

```json
"icon": {
  "unicode": "&#x5a;",
  "type":    "divi",
  "weight":  "400"
}
```

Where `unicode` is the icon code-point in the Divi icon font
(format `&#xXXXX;`).

### Colors and gradients

Solid color:
```json
"background": { "desktop": { "value": { "color": "#2B87DA" } } }
```

Gradient:
```json
"background": {
  "desktop": {
    "value": {
      "color": "...",
      "gradient": {
        "enabled": "on",
        "stops": [
          {"position": "0",   "color": "#2B87DA"},
          {"position": "100", "color": "#29C4A9"}
        ]
      }
    }
  }
}
```

### Background image

```json
"background": {
  "desktop": {
    "value": {
      "image": { "url": "http://.../image.jpg" }
    }
  }
}
```

### Spacing (padding/margin)

```json
"spacing": {
  "desktop": {
    "value": {
      "padding": {
        "top":            "150px",
        "bottom":         "150px",
        "syncVertical":   "on",
        "syncHorizontal": "off"
      },
      "margin": {
        "left":           "334px",
        "syncVertical":   "off",
        "syncHorizontal": "off"
      }
    }
  }
}
```

`syncVertical` / `syncHorizontal` link top↔bottom / left↔right for
synced editing in the VB.

## Structural modules

### `wp:divi/section`

```json
{
  "module": {
    "decoration": {
      "background": { "desktop": { "value": { "color": "..." } } },
      "spacing":    { /* padding, margin */ }
    }
  },
  "builderVersion": "5.5.2"
}
```

A section = a full-width horizontal band.

### `wp:divi/row`

```json
{
  "module": {
    "advanced": {
      "columnStructure":     { "desktop": { "value": "1_3,1_3,1_3" } },
      "flexColumnStructure": { "desktop": { "value": "equal-columns_3" } }
    },
    "decoration": {
      "layout": {
        "desktop": { "value": { "flexWrap": "nowrap" } },
        "phone":   { "value": { "flexWrap": "wrap"   } }
      }
    }
  }
}
```

**`columnStructure`**: describes the columns in `a_b` notation where
`a/b` is the fraction occupied. Observed combinations:
- `"4_4"` — 1 full-width column
- `"1_2,1_2"` — 2 equal columns
- `"1_3,1_3,1_3"` — 3 equal columns
- (probably `"1_4,1_4,1_4,1_4"`, `"1_2,1_4,1_4"`, etc.)

**`flexColumnStructure`**: redundancy for the flex engine (Divi 5 uses
flex internally).

### `wp:divi/column`

```json
{
  "module": {
    "advanced": {
      "type": { "desktop": { "value": "1_3" } }
    },
    "decoration": {
      "sizing": {
        "desktop": { "value": { "flexType": "8_24" } },
        "phone":   { "value": { "flexType": "24_24" } }
      }
    }
  }
}
```

`type` matches the share in `columnStructure`. `flexType` is the same
thing as a fraction over 24 (e.g. `8_24` = 1/3, `24_24` = full width).
On mobile (`phone`), columns switch to full width via `flexType: 24_24`.

## Content modules

### `wp:divi/text`

```json
{
  "content": {
    "innerContent": {
      "desktop": {
        "value": "<h1><span>Welcome to IAWM Reference</span></h1>"
      }
    },
    "decoration": {
      "headingFont": {
        "h1": {
          "font": {
            "desktop": {
              "value": {
                "textAlign": "center",
                "style":     ["uppercase"],
                "size":      "28px",
                "weight":    "800"
              }
            }
          }
        }
      }
    }
  }
}
```

The actual content is **inline HTML** in `innerContent.{bp}.value`.
H1–H6 typography is set via `decoration.headingFont.{tag}.font`.

### `wp:divi/blurb`

```json
{
  "imageIcon": {
    "innerContent": {
      "desktop": {
        "value": {
          "src":     "data:image/svg+xml;base64,...",
          "useIcon": "on",
          "icon":    { "unicode": "&#x5a;", "type": "divi", "weight": "400" }
        }
      }
    }
  },
  "title":   { "innerContent": { "desktop": { "value": { "text": "Your Title" } } } },
  "content": { "innerContent": { "desktop": { "value": "<p>...</p>" } } }
}
```

The blurb has three fields: `imageIcon` (top visual), `title`, `content`.
`useIcon: "on"` switches the image to a Divi icon.

### `wp:divi/cta`

```json
{
  "module": {
    "advanced": {
      "link": { "desktop": { "value": { "url": "" } } }
    }
  },
  "title":   { "innerContent": { "desktop": { "value": "Your Title" } } },
  "content": { "innerContent": { "desktop": { "value": "<p>...</p>" } } },
  "button":  {
    "innerContent": {
      "desktop": {
        "value": { "text": "CHECK THIS", "linkUrl": "#" }
      }
    }
  }
}
```

CTA = title + text + button, optionally linked.

### `wp:divi/image`

```json
{
  "image": {
    "innerContent": {
      "desktop": {
        "value": { "src": "data:image/svg+xml;base64,..." }
      }
    }
  }
}
```

The image module is minimal — URL/source in `image.innerContent`.
For a real WP asset, the URL points to `wp-content/uploads/...`.

### `wp:divi/button` (assumed, not encountered here)

Seen via `wp:divi/cta` which embeds a button, we assume the same
`innerContent.desktop.value.{text, linkUrl}` structure.

## Identified pitfalls

1. **Deep, redundant JSON.** `module.decoration.background.desktop.value.color`
   for a simple background color. Any programmatic manipulation must go
   through a dedicated constructor, not manual concatenation.

2. **`"` escaping.** If you write Divi JSON by hand and re-inject it
   into `post_content`, do **not** escape `"` to `"` — that's what
   `serialize_block()` does on the WP side.

3. **Mandatory root placeholder.** A standalone section without a
   `wp:divi/placeholder` wrapper will not render correctly (or will
   break the VB on open).

4. **Module + Row + Section = inseparable trio.** No module directly
   inside a row without a column; no column directly inside a section
   without a row. Otherwise the builder refuses to load.

5. **Global variables > hardcoded values.** Systematically reference
   global colors (`gcid-heading-color`, `gcid-body-color`, etc.)
   instead of hardcoded values (`#000`). Otherwise a site palette
   change won't propagate.

6. **Versioning.** Every block carries a `builderVersion`. After each
   major Divi 5 update, verify format compatibility. Always test via
   round-trip.

7. **Gutenberg builds.** `parse_blocks()` + `serialize_blocks()` on the
   WP side normalize the output. A Divi → parse → serialize round-trip
   may **reorder the keys** of the attribute JSON (not a bug, an artifact
   of `json_encode`). The builder accepts both orders. Observed in
   practice on the reference page: **bit-identical round-trip** (11438
   bytes, 24 blocks).

8. **`wp_unslash` pitfall (critical).** `wp_insert_post` and
   `wp_update_post` internally apply `wp_unslash()` on text fields —
   they assume the data comes slashed from `$_POST`. If you pass them
   an already-clean Divi `post_content`, **all backslashes vanish
   silently**. And Divi 5 stores its attributes with Unicode escapes
   `"`, `<`, `>`, etc. — each lost backslash corrupts an
   attribute. **Always call `wp_slash()` on the content before
   `wp_insert_post` / `wp_update_post`.** That's the fix that makes the
   round-trip bit-faithful.

## Attack plan (Phase 3.2)

1. **PHP constructors on the plugin side** (`lib/divi/` on the TS gateway,
   or `includes/divi/` on the plugin):
   - `make_section($attrs, $rows)`
   - `make_row($column_structure, $columns)`
   - `make_column($type, $modules)`
   - `make_text($html, $style)`
   - `make_blurb($title, $text, $icon)`
   - `make_cta($title, $text, $button_text, $button_link)`
   - `make_image($src, $alt)`
2. **Serializer**: takes a high-level structure (Page→Sections→…→Modules)
   and produces valid `post_content`, wrapped in `wp:divi/placeholder`.
3. **`/divi/page/read` endpoint**: `parse_blocks` + projection to a
   simplified tree (without the `desktop.value` noise).
4. **Round-trip**: read page 19, rewrite it elsewhere, compare.

## Advanced modules (reference page #29)

Documented from a 2nd reference page populated in the Divi 5.5.2
builder.

### `wp:divi/heading`

Dedicated module for titles (alternative to the `<h1>` in `wp:divi/text`).

```json
{
  "title": { "innerContent": { "desktop": { "value": "Your Title" } } }
}
```

Simpler than `wp:divi/text` when you only want a heading. The HTML tag
produced (`<h1>`, `<h2>`…) is set via `module.advanced.headingLevel`.

### `wp:divi/button`

Standalone button (distinct from the button embedded in CTA / slide).

```json
{
  "button": {
    "innerContent": {
      "desktop": {
        "value": { "text": "Click Here", "linkUrl": "..." }
      }
    }
  }
}
```

**Tip**: `linkUrl` can be a **Divi content variable**:

```
$variable({"type":"content","value":{"name":"home_url","settings":{}}})$
```

Known content variables: `home_url`, and likely `page_url`, `site_url`,
etc. Useful to avoid hardcoding URLs.

### `wp:divi/number-counter`

Number animated on scroll (ideal for KPIs).

```json
{
  "title":  { "innerContent": { "desktop": { "value": "Clients" } } },
  "number": {
    "innerContent": { "desktop": { "value": "247" } },
    "advanced":     { "enablePercentSign": { "desktop": { "value": "off" } } }
  }
}
```

`enablePercentSign` automatically adds a `%` (useful for rates).

### `wp:divi/testimonial`

Quote with photo + name.

```json
{
  "content":  { "innerContent": { "desktop": { "value": "<p>Quote…</p>" } } },
  "author":   { "innerContent": { "desktop": { "value": "Name" } } },
  "portrait": {
    "innerContent": {
      "desktop": { "value": { "url": "..." } }
    }
  }
}
```

`job_title` field (position) likely also available — to confirm.

### `wp:divi/gallery`

Image gallery, **CSV list of media IDs**.

```json
{
  "image": {
    "advanced": {
      "galleryIds": { "desktop": { "value": "32,33,34,35,36,37,38" } }
    }
  },
  "galleryGrid": {
    "decoration": {
      "layout": {
        "tablet": { "value": { "gridColumnCount": "3" } },
        "phone":  { "value": { "gridColumnCount": "1" } }
      }
    }
  }
}
```

IDs point to WP attachments (post_type=attachment). To generate, first
upload the images via `iawm_media_sideload`, then retrieve their IDs.

### `wp:divi/video`

Video, **YouTube/Vimeo URL auto-detected**.

```json
{
  "video": {
    "innerContent": {
      "desktop": { "value": { "src": "https://www.youtube.com/watch?v=…" } }
    }
  }
}
```

For a self-hosted video, provide the `.mp4` URL directly.

### `wp:divi/code`

Raw HTML block. **Avoid unless necessary** — bypasses the builder's
logic and complicates maintenance.

```json
{
  "content": { "innerContent": { "desktop": { "value": "<div>...</div>" } } }
}
```

### Composed (nested) modules

Four modules contain children: `accordion`, `tabs`, `slider`,
`contact-form`. Their children are first-class Divi blocks in
`innerBlocks`.

#### `wp:divi/accordion` + `wp:divi/accordion-item`

```
wp:divi/accordion
└── wp:divi/accordion-item × N
```

Item:
```json
{
  "title":   { "innerContent": { "desktop": { "value": "Question?" } } },
  "content": { "innerContent": { "desktop": { "value": "<p>Answer…</p>" } } },
  "module": {
    "advanced": { "open": { "desktop": { "value": "on" } } }  // optional
  }
}
```

`open: "on"` opens the item by default (often set on the first one).

#### `wp:divi/tabs` + `wp:divi/tab`

```
wp:divi/tabs
└── wp:divi/tab × N
```

Tab:
```json
{
  "title":   { "innerContent": { "desktop": { "value": "Tab Title" } } },
  "content": { "innerContent": { "desktop": { "value": "<p>…</p>" } } }
}
```

#### `wp:divi/slider` + `wp:divi/slide`

```
wp:divi/slider
└── wp:divi/slide × N
```

Slide:
```json
{
  "title":   { "innerContent": { "desktop": { "value": "Slide Title" } } },
  "content": { "innerContent": { "desktop": { "value": "<p>…</p>" } } },
  "button":  {
    "innerContent": {
      "desktop": { "value": { "text": "Click Here", "linkUrl": "#" } }
    }
  }
}
```

#### `wp:divi/contact-form` + `wp:divi/contact-field`

```
wp:divi/contact-form
└── wp:divi/contact-field × N
```

The form has a **uniqueId** UUID (auto-generated by the builder, to be
regenerated on our side via `wp_generate_uuid4()` or equivalent JS):

```json
{
  "module": {
    "advanced": {
      "uniqueId": { "desktop": { "value": "aa2b25f7-44fc-41da-af32-b665cedb10d0" } }
    }
  }
}
```

Field:
```json
{
  "fieldItem": {
    "advanced": {
      "fullwidth": { "desktop": { "value": "on" } },
      "id":        { "desktop": { "value": "Name" } },
      "type":      { "desktop": { "value": "input" } }
    },
    "innerContent": { "desktop": { "value": "Name" } }
  },
  "module": {
    "decoration": {
      "sizing": { "desktop": { "value": { "flexType": "12_24" } } }
    }
  }
}
```

**Field types**: `input` (short text), `email`, `text` (textarea),
probably also `select`, `checkbox`, `radio`, `phone`. `flexType`
controls width (12_24 = half, 24_24 = full).

`id` is the field's internal identifier (used in the email
notifications received by the recipient). Use no spaces.

## Priority native modules (reference page #53)

Documented from a 3rd reference page populated in the builder.

### `wp:divi/divider`

Visual separator. With defaults, attributes are minimal. Style options
(color, height, alignment) live in `module.decoration.divider`.

```json
{ "builderVersion": "5.5.2" }
```

### `wp:divi/icon`

Standalone Divi icon. By default, attributes are minimal. The
configuration (unicode + color + size) is in `icon.innerContent.desktop.value`.

```json
{
  "icon": {
    "innerContent": {
      "desktop": { "value": { "unicode": "&#x21;", "type": "divi", "weight": "400" } }
    }
  }
}
```

### `wp:divi/toggle`

Reveal block (equivalent to a 1-item accordion).

```json
{
  "title":   { "innerContent": { "desktop": { "value": "Title" } } },
  "content": { "innerContent": { "desktop": { "value": "<p>…</p>" } } }
}
```

### `wp:divi/pricing-tables` + `wp:divi/pricing-table` ⚠️

**Specific naming convention**: the container is *tables* (plural)
and the child is *table* (singular), not *table-item*.

```
wp:divi/pricing-tables
└── wp:divi/pricing-table × N
```

Item:
```json
{
  "title":    { "innerContent": { "desktop": { "value": "Table Title" } } },
  "subtitle": { "innerContent": { "desktop": { "value": "Subtitle" } } },
  "currencyFrequency": {
    "innerContent": {
      "desktop": { "value": { "currency": "$" } }
    }
  },
  "price":   { "innerContent": { "desktop": { "value": "50" } } },
  "content": {
    "innerContent": {
      "desktop": {
        "value": "+ Included 1\n+ Included 2\n- Not included"
      }
    }
  }
}
```

`content` is a multi-line text where each line starts with:
- `+` for an **included** feature
- `-` for a **non-included** feature

### `wp:divi/icon-list` + `wp:divi/icon-list-item`

Styled bullet list (icon + text per item).

Item:
```json
{
  "content": { "innerContent": { "desktop": { "value": "List item text" } } },
  "icon": {
    "innerContent": {
      "desktop": {
        "value": {
          "unicode": "&#x21;",
          "type":    "divi",
          "weight":  "400",
          "target":  "off"
        }
      }
    }
  }
}
```

`target: "on"` opens the link in a new tab (if the item is linked).

### `wp:divi/social-media-follow` + `wp:divi/social-media-follow-network` ⚠️

**Convention**: the child is named `*-network` (not `*-item`).

Item:
```json
{
  "socialNetwork": {
    "innerContent": {
      "desktop": {
        "value": { "title": "facebook", "label": "Facebook" }
      }
    }
  },
  "module": {
    "decoration": {
      "background": {
        "desktop": { "value": { "color": "#3b5998" } }
      }
    }
  }
}
```

`title` = the network's internal identifier (`facebook`, `twitter`,
`instagram`, `linkedin`, `youtube`, `tiktok`, etc.). `label` = displayed
text. The background color is typically the brand's color.

### `wp:divi/team-member`

Team member (photo + name + position + bio).

```json
{
  "name":     { "innerContent": { "desktop": { "value": "Name" } } },
  "position": { "innerContent": { "desktop": { "value": "Position" } } },
  "image": {
    "innerContent": {
      "desktop": { "value": { "url": "https://..." } }
    }
  },
  "content": { "innerContent": { "desktop": { "value": "<p>Bio…</p>" } } }
}
```

⚠️ Note: the image uses `url` (not `src` like in `wp:divi/image`).
The module also handles the member's social profiles via advanced
attributes (facebook, twitter, …).

### `wp:divi/signup`

Email opt-in (newsletter capture).

```json
{
  "title":   { "innerContent": { "desktop": { "value": "Title" } } },
  "content": { "innerContent": { "desktop": { "value": "<p>…</p>" } } }
}
```

The provider (Mailchimp, ConvertKit, etc.) and the destination list
are configured in advanced attributes not observed here.

### `wp:divi/map`

Google Maps. With defaults, attributes are minimal. The address, zoom
and markers (child `wp:divi/map-item` modules) are set via advanced
attributes.

```json
{ "builderVersion": "5.5.2" }
```

### `wp:divi/circle-counter`

Circular variation of the number counter (animated percentage circle).

```json
{
  "title":  { "innerContent": { "desktop": { "value": "Title" } } },
  "number": { "innerContent": { "desktop": { "value": "50" } } }
}
```

Displayed as a percentage by default (from 0 to `number`).

### `wp:divi/counters` + `wp:divi/counter` ⚠️

**Bar counters**: the blockName is **`divi/counters`** (not
`divi/bar-counters` as suggested by the class name `BarCounters`).
Item = `divi/counter` (singular).

Container:
```json
{
  "barProgress": {
    "advanced": {
      "usePercentages": { "desktop": { "value": "on" } }
    }
  }
}
```

Item:
```json
{
  "title":      { "innerContent": { "desktop": { "value": "Skill" } } },
  "barProgress":{ "innerContent": { "desktop": { "value": "50" } } }
}
```

### `wp:divi/audio`

HTML5 audio player.

```json
{
  "title":      { "innerContent": { "desktop": { "value": "Track Title" } } },
  "artistName": { "innerContent": { "desktop": { "value": "Artist" } } }
}
```

The audio file URL lives in an `audio` or similar attribute (to
confirm by populating the URL in the builder).

## Divi variables (beyond colors)

We've seen `$variable({"type":"color",...})$` for global colors.
**The same mechanism** is used for other types:

| `type`     | Usage | Observed `name` examples |
|-----------|-------------|------------------------------|
| `color`   | Global color | `gcid-primary-color`, `gcid-heading-color`, … |
| `content` | Dynamic content | `home_url`, likely `page_url`, `post_title`, … |

Other possible types (to confirm): `font`, `text`, `image`, `number`,
`link`.
