---
name: create-divi-page
description: Build a Divi 5-powered WordPress page from a prompt — from a structured brief to a published layout, orchestrating design, marketing/conversion, SEO and the native Divi 5 format. Use it when the user asks "create page X" (landing, services, about, contact, FAQ, portfolio…) on a Divi site.
---

# Create a Divi 5 page — method

You are the **conductor** of a workflow that turns a prompt into a **real
published Divi 5 page**. You don't decorate a Word doc — you build a valid
`wp:divi/placeholder > sections > rows > columns > modules` tree, referenced
on the **site's design system** (gcid-*), engineered to **convert**, optimised
for SEO, and **mobile-first**.

You **mobilise** the skills:
- `frontend-design-wordpress` (hierarchy, typography, spacing, mobile-first)
- `marketing-conversion-wordpress` (AIDA/PAS frameworks, CTA, social proof)
- `seo-wordpress` (metadata, semantic structure, schema)

You use the **MCP tools** `mcp__ia-webmaster__iawm_*` and **NEVER** write Divi
HTML/JSON by hand when a tool can do it for you.

## Prerequisites to verify first

1. `iawm_status` — adapter live, kill switch OFF.
2. `iawm_divi_status` — Divi 5 active on the site, version known.
3. `iawm_divi_global_data` — **fetch the design system**: global colors
   `gcid-*`, global fonts. **Everything you produce** will use these
   variables, never hard-coded values. If the site has no design system
   (Divi default colours like #2ea3f2), offer to define one before or
   together with the first page.
4. `iawm_seo_status` — SEO backend active (Rank Math expected). If absent,
   offer `iawm_plugins_install` with slug `seo-by-rank-math`.

## Step 1 — Structured brief (DO NOT SKIP)

Before writing the first section, ask the user for a **short but complete
brief** (use AskUserQuestion / open questions depending on context):

1. **Page type** (landing, services, about, FAQ, portfolio, contact,
   product page, team page…).
2. **Target audience** (1-2 sentences — persona).
3. **Primary goal** = expected action (a single, top-priority one:
   signup, purchase, booking, quote request…).
4. **Unique promise / argument** (what makes the offer different).
5. **Available social proof** (testimonials, numbers, client logos).
6. **Tone** (formal / warm / premium / casual / technical).
7. **Constraints** (length, mandatory sections, things to avoid).
8. **Visuals** (Unsplash/Pexels URLs provided, or to choose together).
9. **Content language** if it differs from the site default.

→ If the brief is incomplete, **ask** rather than guess.

## Step 2 — Propose a page plan

Output a **section structure** to validate with the user **before coding**.
Format:

```
## Page plan — [title]

Type: [landing | services | about | …]
Goal: [action]
Copywriting framework: [AIDA | PAS | FAB | other]

### Proposed sections
1. Hero — hook + subtitle + primary CTA + visual
2. Felt problem — PAS applied
3. Solution / Offer — text + image block
4. Benefits (3 columns) — 3 blurbs: title + text + icon
5. Social proof — testimonials + key numbers (number-counter)
6. FAQ — 5-8 questions (accordion)
7. Final CTA — full-width banner
```

**Have the structure validated.** Iterate as needed. **Never** ship to
production without explicit validation.

## Step 3 — Design the visuals

Decide, based on what `iawm_divi_global_data` revealed about the site:
- Primary colour (CTA, accents) → `var:gcid-primary-color`
- Light / dark background alternated per section
- Heading font, body font (inherited from globals)
- Vertical padding: 80px desktop / 40px mobile for standard sections.
  120-150px for the hero.
- Line length: Divi handles this automatically — don't touch unless needed.

**Reference the global variables** instead of hard-coded values in the Divi
JSON: for example for a background colour:

```json
"background": {
  "desktop": {
    "value": {
      "color": "$variable({\"type\":\"color\",\"value\":{\"name\":\"gcid-heading-color\",\"settings\":{}}})$"
    }
  }
}
```

## Step 4 — Create the page as a draft

```
iawm_content_create({
  type: "page",
  title: "[brief title]",
  status: "draft",
  content: ""    // will be rewritten in Step 5
})
```

Capture the returned id. **Always start as a draft.**

## Step 5 — Generate and write the Divi 5 layout

### 🚨 Golden rule: `iawm_divi_page_compose` direct, NEVER a script

**FORBIDDEN**: create an intermediate `.mjs`/`.ts` file that builds and posts
the page. It's verbose, non-reusable, and pollutes the public repo with
client-specific files.

**MANDATORY**: call `iawm_divi_page_compose` directly from the conversation,
passing the sections as arguments. The gateway-side composer handles the
assembly, the `placeholder` wrapping, and the write.

### Three composition modes available within the same call

**Mode 1 — PATTERN** (1-3 lines, ideal for standard cases):

```js
iawm_divi_page_compose({
  post_id: <id>,
  sections: [
    { pattern: "hero", options: { title, subtitle, ctaText, ctaUrl, backgroundColor } },
    { pattern: "features3col", options: { items: [...] } },
    { pattern: "pricing3col", options: { plans: [...] } },
    { pattern: "ctaBanner", options: { title, contentHtml, buttonText, buttonUrl } },
  ],
})
```

13 patterns available: `hero`, `features3col`, `ctaBanner`,
`imageTextSplit`, `testimonials`, `faqAccordion`, `numbersBar`,
`videoSection`, `contactSection`, `pricing3col`, `teamGrid`,
`headerSimple`, `footerStandard`.

**Mode 2 — FREE-FORM** (improvising from the 41 base modules):

```js
{
  section: {
    background: { color: "#003366" },     // or { color: { gcid: "gcid-primary-color" } }
    spacing: { padding: { top: "120px", bottom: "120px", syncVertical: "on", syncHorizontal: "off" } },
    rows: [
      {
        structure: "1_2,1_2",
        wrapMobile: true,
        columns: [
          [
            { module: "text", html: "<h2>Our approach</h2><p>...</p>" },
            { module: "button", text: "Discover", linkUrl: "/services" },
          ],
          [
            { module: "image", src: "https://...", alt: "Description" },
          ],
        ],
      },
    ],
  },
}
```

Supported modules: `text`, `blurb`, `cta`, `image`, `button`,
`heading`, `number-counter`, `circle-counter`, `testimonial`,
`team-member`, `gallery`, `video`, `audio`, `code`, `divider`, `icon`,
`toggle`, `signup`, `map`, `menu`, `fullwidth-menu`, `search`,
`breadcrumbs`, `post-title`, `post-content`, `post-navigation`,
`comments`, `accordion`, `tabs`, `slider`, `contact-form`,
`pricing-tables`, `icon-list`, `social-media-follow`, `counters`.

**Mode 3 — RAW BLOCK** (ultimate escape hatch):

```js
{ block: { blockName: "divi/xxx", attrs: {...}, innerBlocks: [...] } }
```

For exotic cases where no module / pattern fits. Very rare.

### Mix the 3 modes in the same page

```js
iawm_divi_page_compose({
  post_id: <id>,
  language: "en-US",                                 // optional language hint
  sections: [
    { pattern: "hero", options: {...} },              // pattern
    { section: { rows: [...] } },                     // free-form
    { pattern: "pricing3col", options: {...} },       // pattern
    { section: { background: {...}, rows: [...] } },  // free-form
    { pattern: "ctaBanner", options: {...} },         // pattern
  ],
})
```

### For the Theme Builder (header / footer)

```js
iawm_divi_theme_builder_compose({
  title: "Default Site Template",
  header_sections: [
    { pattern: "headerSimple", options: { logoUrl, siteName, menuId } },
  ],
  footer_sections: [
    { pattern: "footerStandard", options: { columns: [...], copyright, socialNetworks: [...] } },
  ],
  // body_sections optional — if omitted, Divi renders the native post_content.
  replace_existing: true,
})
```

### Anti-patterns STRICTLY FORBIDDEN

- ❌ Writing a `.mjs` script that calls the signed HTTP API by hand
- ❌ Building Divi JSON by string concatenation
- ❌ Duplicating the logic of `placeholder()`, `section()`, etc. on the
  Claude side (that's the gateway's job)
- ❌ Saving client-specific content in the public repo

### Reference: global variables

To reference the colours of the site's design system:

```js
{ module: "section", background: { color: { gcid: "gcid-primary-color" } } }
```

The composer translates this to `$variable({...})$` automatically.

### Inspiration from Divi Cloud (hybrid workflow)

If the user has saved a Cloud layout to their local library (via "Save to
Library" in the builder), you can take inspiration from it:

```js
iawm_divi_library_local()             // list the saved layouts
iawm_divi_library_item({ id: <id> })  // inspect the structure
```

→ Take inspiration from the sections / columns / modules you see, then
**rebuild** with your own content via `iawm_divi_page_compose` (do not copy
& paste the placeholder content).

### Low-level (advanced case)

`iawm_divi_page_write({ post_id, blocks })` is still available if you
already have a complete Divi tree (e.g. round-trip from another page via
`iawm_divi_page_read` mode raw). To generate a page **from scratch**, always
use `iawm_divi_page_compose`.

## Step 6 — Read-back validation

```
iawm_divi_page_read({ post_id: <id>, mode: "tree" })
```

Verify:
- The number of sections produced matches the plan.
- The `summary.title` / `summary.content_html` carry the right content
  (no forgotten "Your Title Goes Here" placeholder).
- The CTAs point to the correct URL.

## Step 7 — SEO

```
iawm_seo_page_update({
  post_id: <id>,
  fields: {
    meta_title:       "[55-60 characters, focus keyword + benefit]",
    meta_description: "[140-160 characters, action verb]",
    focus_keyword:    "[main keyword]",
    og_title:         "[more emotional]",
    og_description:   "[100-200 characters]",
  },
})
```

See the `seo-wordpress` skill for the full grid.

## Step 8 — Recap to the user

Present:
- The preview URL: `<site>/?page_id=<id>` (in draft, accessible via admin)
- The plan of the sections produced
- The SEO set
- **Ask for confirmation** before switching the page to `publish`.

```
iawm_content_update({ id: <id>, status: "publish" })
```

## Absolute guardrails

1. **No publish without explicit agreement.** Always draft first.
2. **No hard-coded colours** when a gcid-* exists — otherwise a palette
   change on the site won't propagate.
3. **One primary action per page** (one primary CTA). If the user asks
   for several, challenge them.
4. **Verify contrast**: if you place dark text on a dark background (or
   light on light), that's a fail — fix it immediately.
5. **Think mobile.** For each row: ensure there is a
   `phone.value.flexWrap: "wrap"` variation or similar so the columns
   stack.
6. **No `wp:divi/code` modules** unless absolutely necessary.
7. **Final audit**: run the `seo-wordpress` grid BEFORE publish.

## Anti-patterns to refuse

- "Make me a page" with no brief → ask first, don't guess.
- "Generate 10 pages" → make **one** good page, then iterate.
- "Copy this page" → prefer a clean import via the local library +
  rebuild with the content of the new brief.
- "Use all the loud colours" → respect the design system, explain why.

## Trigger prompt example

> "Create a landing page for an online Yoga course. Audience: beginners.
> Goal: free trial week signup. Warm tone."

Expected workflow:
1. Verify prerequisites (status, divi, global-data, seo).
2. Refine the brief (session duration, post-trial pricing, available
   testimonials, site palette).
3. Propose a plan: emotional hero → 3-col benefits → testimonials →
   3-step "how it works" → FAQ → CTA banner.
4. Have it validated.
5. Create the draft page, generate the layout via patterns, write.
6. Set the SEO (focus: "online yoga for beginners", description with an
   action verb).
7. Present the preview URL + ask for publish agreement.
