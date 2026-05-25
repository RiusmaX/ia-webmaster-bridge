# Divi 5 composition DSL

> Quick reference for `iawm_divi_page_compose` and
> `iawm_divi_theme_builder_compose`.

Both MCP tools take an array of **sections** where each section can be
described using **three modes** that can be mixed in the same page:

1. **Pattern** — shortcut for a standard case (1-3 lines).
2. **Free-form** — improvise from the base modules.
3. **Raw block** — escape hatch (already-composed Divi JSON).

## Mode 1 — Pattern

```js
{ pattern: "<name>", options: { ... } }
```

13 available patterns:

| Pattern | Usage | Main options |
|---------|-------|---------------------|
| `hero` | Opening section (H1 + subtitle + CTA) | `title`, `subtitle`, `ctaText`, `ctaUrl`, `backgroundColor?`, `backgroundImageUrl?` |
| `features3col` | 3 benefits with icons | `items: [{title, contentHtml, iconUnicode?, imageUrl?}]`, `sectionTitle?`, `sectionSubtitle?` |
| `ctaBanner` | Call-to-action banner | `title`, `contentHtml`, `buttonText`, `buttonUrl`, `backgroundColor?` |
| `imageTextSplit` | 2 columns image \| text | `imageUrl`, `title`, `contentHtml`, `imageOnLeft?`, `backgroundColor?` |
| `testimonials` | Testimonials, grid or carousel | `items: [{quoteHtml, author, portraitUrl?}]`, `variant?: "grid" \| "carousel"` (default `"grid"`), `sectionTitle?`, `sectionSubtitle?`, `backgroundColor?` |
| `faqAccordion` | FAQ accordion (first open) | `items: [{question, answerHtml}]`, `openFirst?`, `sectionTitle?` |
| `numbersBar` | Animated KPI bar | `items: [{label, number, percent?}]`, `backgroundColor?` |
| `videoSection` | Full-width video | `videoUrl`, `sectionTitle?`, `sectionSubtitle?` |
| `contactSection` | Contact form | `fields?` (defaults to Name/Email/Message), `sectionTitle?` |
| `pricing3col` | 3 pricing plans | `plans: [{title, price, features: [{text, included?}], buttonText?, buttonUrl?}]` |
| `teamGrid` | Member grid | `members: [{name, position, imageUrl?, bioHtml?}]`, `columnsCount?` |
| `headerSimple` | Logo + menu header (2 cols) | `logoUrl?`, `siteName?`, `menuId?`, `backgroundColor?`, `paddingY?` |
| `footerStandard` | Multi-column footer | `columns: [{title, contentHtml?, menuId?, listItems?}]`, `socialNetworks?`, `copyright?`, `backgroundColor?` |

## Mode 2 — Free-form section

```js
{
  section: {
    background?: { color?: <DiviColor>, imageUrl?: <url> },
    spacing?: { padding?: <Spacing>, margin?: <Spacing> },
    rows: [
      {
        structure: "<columnStructure>",   // e.g. "1_3,1_3,1_3"
        wrapMobile?: true,                 // stack columns on mobile (default: true)
        spacing?: { padding?, margin? },
        columns: [
          // Either a direct list of modules:
          [ <ModuleInput>, <ModuleInput>, ... ],
          // Or an enriched object:
          {
            type?: "1_3",                  // structure override (rare)
            fullWidthOnMobile?: true,
            modules: [ <ModuleInput>, ... ],
          },
        ],
      },
      // ... more rows
    ],
  },
}
```

### Utility types

```ts
DiviColor   = "#RRGGBB" | { gcid: "gcid-primary-color" | "gcid-heading-color" | ... }
Spacing     = { top?: "20px", right?, bottom?, left?, syncVertical?: "on"|"off", syncHorizontal?: "on"|"off" }
columnStructure = "4_4" | "1_2,1_2" | "1_3,1_3,1_3" | "1_4,1_4,1_4,1_4" | "1_3,2_3" | "2_3,1_3" | "1_4,3_4" | "3_4,1_4"
```

## Available modules (`ModuleInput`)

Each module = `{ module: "<name>", ...options }`.

### Basic content
```js
{ module: "text",    html: "<h2>...</h2>", headingFont?: { h2: { size, weight, textAlign, ... } } }
{ module: "heading", text: "H1 title" }
{ module: "blurb",   title, contentHtml, iconUnicode?, imageUrl? }
{ module: "cta",     title, contentHtml, buttonText, buttonUrl }
{ module: "image",   src, alt? }
{ module: "button",  text, linkUrl }
```

### Stats and numbers
```js
{ module: "number-counter", title, number: "247", percent?: false }
{ module: "circle-counter", title, number: "85" }
{ module: "counters",       items: [{title, progress: "75"}, ...], showPercentages?: true }
```

### People / testimonials
```js
{ module: "testimonial", quoteHtml: "<p>...</p>", author, portraitUrl? }
{ module: "team-member", name, position, imageUrl?, bioHtml? }
```

### Media
```js
{ module: "gallery", ids: [32,33,34], columns?: 4 }
{ module: "video",   src: "https://youtube.com/..." }
{ module: "audio",   title, artistName?, audioUrl? }
{ module: "code",    html: "<div>...</div>" }    // avoid
```

### Layout / decoration
```js
{ module: "divider", color?, height? }
{ module: "icon",    unicode: "&#xe0e1;", color?, size? }
{ module: "toggle",  title, contentHtml }
```

### Forms
```js
{ module: "signup", title, contentHtml }
{ module: "map",    address?, zoom? }
{ module: "contact-form",
  fields: [
    { id: "Name",    label: "Name",          type: "input" },
    { id: "Email",   label: "Email address", type: "email" },
    { id: "Message", label: "Your message",  type: "text", fullwidth: true },
  ],
}
```

### Navigation / theme builder
```js
{ module: "menu",            menuId?: 5, logoUrl?, dropdownDirection? }
{ module: "fullwidth-menu",  ... }                                  // same options
{ module: "search",          placeholder?, buttonText? }
{ module: "breadcrumbs",     homeText?, separator?, htmlTag? }
{ module: "post-title",      includeMeta?, includeFeaturedImage? }   // theme builder
{ module: "post-content" }                                           // injects the current content
{ module: "post-navigation", prevText?, nextText?, sameTerm? }
{ module: "comments" }
```

### Composed (nested)
```js
{ module: "accordion", items: [
    { title: "Q1", contentHtml: "<p>A1</p>", open: true },
    { title: "Q2", contentHtml: "<p>A2</p>" },
] }

{ module: "tabs", items: [
    { title: "Tab 1", contentHtml: "<p>...</p>" },
    { title: "Tab 2", contentHtml: "<p>...</p>" },
] }

{ module: "slider", items: [
    { title, contentHtml, buttonText?, buttonUrl? },
] }

{ module: "pricing-tables", items: [
    {
      title: "Plan A", subtitle?, price: "29", currency?: "$", frequency?: "month",
      features: [
        { text: "Included 1" },                // included: true (default)
        { text: "Not included", included: false },
      ],
      buttonText?, buttonUrl?,
    },
] }

{ module: "icon-list", items: [
    { text: "Line 1", iconUnicode?: "&#x21;", url?, newTab? },
] }

{ module: "social-media-follow", networks: [
    { network: "facebook",  label?: "Facebook" },
    { network: "instagram", label?: "Instagram" },
] }
```

## Mode 3 — Raw block (escape)

```js
{ block: <raw Gutenberg block JSON> }
```

For exotic cases (custom modules, very fine-grained attributes, special
composition). Use **sparingly**.

## Full example — homepage mixing patterns + free-form

```js
iawm_divi_page_compose({
  post_id: 82,
  sections: [
    // Standard hero pattern
    { pattern: "hero", options: {
      title: "My Service",
      subtitle: "Short description",
      ctaText: "Learn more",
      ctaUrl: "#services",
    } },

    // Custom free-form section
    {
      section: {
        background: { color: { gcid: "gcid-body-color" } },
        spacing: { padding: { top: "80px", bottom: "80px", syncVertical: "on", syncHorizontal: "off" } },
        rows: [
          {
            structure: "1_2,1_2",
            wrapMobile: true,
            columns: [
              [
                { module: "text", html: "<h2>Our approach</h2><p>...</p>" },
                { module: "icon-list", items: [
                  { text: "Benefit 1" },
                  { text: "Benefit 2" },
                  { text: "Benefit 3" },
                ] },
                { module: "button", text: "Discover", linkUrl: "/services" },
              ],
              [
                { module: "image", src: "https://...", alt: "..." },
              ],
            ],
          },
        ],
      },
    },

    // 3 KPIs via pattern
    { pattern: "numbersBar", options: {
      items: [
        { label: "Clients", number: "247" },
        { label: "Projects", number: "1.2k" },
        { label: "Satisfaction", number: "98", percent: true },
      ],
    } },

    // FAQ
    { pattern: "faqAccordion", options: {
      items: [
        { question: "Q1?", answerHtml: "<p>A1</p>" },
        { question: "Q2?", answerHtml: "<p>A2</p>" },
      ],
    } },

    // Final CTA
    { pattern: "ctaBanner", options: {
      title: "Ready to get started?",
      contentHtml: "<p>First meeting is free.</p>",
      buttonText: "Book now",
      buttonUrl: "/contact",
    } },
  ],
})
```

## For the Theme Builder

```js
iawm_divi_theme_builder_compose({
  title: "Default Site Template",
  header_sections: [
    // Free-form for a tailor-made header:
    {
      section: {
        background: { color: "#003366" },
        spacing: { padding: { top: "15px", bottom: "15px", syncVertical: "on", syncHorizontal: "off" } },
        rows: [
          {
            structure: "1_4,1_2,1_4",
            columns: [
              [{ module: "text", html: "<h1>Logo</h1>" }],
              [{ module: "menu" }],
              [{ module: "button", text: "Contact", linkUrl: "/contact" }],
            ],
          },
        ],
      },
    },
  ],
  footer_sections: [
    { pattern: "footerStandard", options: {
      columns: [
        { title: "About", contentHtml: "<p>...</p>" },
        { title: "Navigation", menuId: 2 },
        { title: "Contact", contentHtml: "<p>...</p>" },
      ],
      socialNetworks: [
        { network: "facebook" },
        { network: "instagram" },
      ],
      copyright: "© 2026 My Site",
    } },
  ],
  // body_sections: omit = Divi renders the native post_content (recommended for the default).
  replace_existing: true,
})
```
