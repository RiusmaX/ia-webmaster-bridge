---
name: seo-wordpress
description: Optimise the search engine ranking (SEO) of a WordPress site managed through the IA Webmaster Bridge adapter. Use it to analyse, configure and improve the SEO of a page (title, meta description, semantic structure, Open Graph, schema.org, Rank Math / Yoast integration).
---

# WordPress SEO — method

SEO is not a module to bolt on at the end of a page: it is a
**cross-cutting** discipline that shapes the content from the moment it
is written. On sites managed through this adapter, the goal is to
**index every page correctly, make it easy for search engines to read,
and make it attractive in the SERPs**.

This skill assumes you have the `mcp__ia-webmaster__iawm_*` tools and
that one of the reference SEO plugins is active on the site
(**Rank Math** first, **Yoast** second).

## Detecting the active SEO backend

Before any SEO write, determine which plugin drives the metadata:

1. Call `iawm_diagnostics_plugins`.
2. Look in the list, in this order:
   - `seo-by-rank-math/rank-math.php` (Rank Math, **first choice**).
   - `wordpress-seo/wp-seo.php` (Yoast).
3. If neither is active, offer to install Rank Math via
   `iawm_plugins_install` (slug `seo-by-rank-math`, `activate: true`).
4. If both are active at the same time, warn the user — these plugins
   conflict, deactivate one of them.

## Page SEO audit

The audit always follows the same grid (output it as a table or
checklist):

### 1. Base metadata (Rank Math / Yoast)

- **SEO title** (final `<title>`, distinct from the H1): 50–60
  characters, contains the focus keyword, catchy (not merely
  descriptive).
- **Meta description**: 140–160 characters, contains the keyword,
  promises a clear value, **encourages clicks** (action verb).
- **Slug**: short, kebab-case, essential keywords only, no stop words
  (the, of, to, …).
- **Focus keyword** (Rank Math): a single focus keyword, repeated in
  the SEO title, the URL, the H1, the first paragraph.

### 2. Semantic structure of the page

- **A single H1**, matching the page subject (may differ from the SEO
  title).
- **Coherent H2/H3 hierarchy**, no level skipping (no H4 after a H2
  without an H3 in between).
- **Semantic keywords** (co-occurring) present in the H2s.
- **Length** ≥ 300 words for an article (≥ 600 to aim for the top 10).
- **First paragraph**: 50–100 words, contains the focus keyword,
  directly answers the search intent.
- **Lists** (`<ul>`, `<ol>`) for enumerations — Google often promotes
  them as featured snippets.

### 3. Media and accessibility

- **`alt` attribute** on all images (descriptive, not keyword
  stuffing). Check via `iawm_media_get`.
- **Explicit image filename** (not `IMG_1234.jpg`).
- **Size**: optimise beyond 200 kB (suggest compression).
- **Lazy loading** enabled (WP does this by default since 5.5).

### 4. Internal and external linking

- At least **3 internal links** to other pages of the site, with
  descriptive anchors (not "click here").
- **1–2 external links** to authority sources (`rel="noopener"`).
- Check that no link is broken (eventually: dedicated capability).

### 5. Social sharing (Open Graph / Twitter Cards)

- Dedicated OG image (1200×630 px ideal), different from the featured
  image when possible.
- OG title distinct from the SEO title (more emotional).
- Compelling OG description, 100–200 characters.

### 6. Structured data (schema.org)

- Schema type consistent with the content:
  - `Article` for a blog post.
  - `LocalBusiness` for a local business page.
  - `Product` for an e-commerce product.
  - `FAQPage` for a FAQ page.
  - `BreadcrumbList` everywhere.
- Rank Math handles most of it automatically — check it is configured,
  do not reinvent the wheel.

### 7. Performance

- Load `iawm_diagnostics_system` to check PHP version, memory limits,
  cache.
- Eventually: integrate a Core Web Vitals measurement tool.

## Standard page optimisation workflow

1. **Understand the intent**: what is the visitor really looking for?
   Ask the user for the target keyword and the intent type
   (informational / transactional / navigational).
2. **Audit before writing**: apply the grid above if the page already
   exists.
3. **Improvement plan**: present the issues ranked by impact (title /
   meta = maximum impact; alt tags = moderate impact). **Validate**
   before any change.
4. **Writing / rewriting**: apply the changes via `iawm_content_update`
   for the body and via the SEO API (`iawm_seo_page_*`) for the
   metadata.
5. **Re-verification**: re-run the audit after the changes.

## SEO guardrails

- **Never over-optimise** a keyword ("keyword stuffing"). Target
  density: 1 to 2 %.
- **Never produce empty content** just to increase the length. 400
  useful words beat 1200 diluted words.
- **Always keep a human, natural editorial voice**; no robotic
  keyphrases.
- **Maintain semantic consistency**: title, URL, H1, meta description
  and first paragraph must tell the same story.
- **No cloaking, no misleading redirects, no doorway pages.** Black
  hat = loss of ranking over time.

## Expected output

When asked for an SEO audit, return:

```
## SEO audit — [page title]

### Overall score: X/10

### Strengths
- (3 to 5 points)

### Weaknesses (in impact order)
1. (maximum impact) ...
2. (medium impact) ...

### Proposed action plan
- [ ] Action 1 (impact, effort, tool used)
- [ ] Action 2 ...
```

Always ask for validation before applying the changes to the page.
