---
name: frontend-design-wordpress
description: Produce visually attractive, readable and responsive WordPress pages. Use it to design or improve the design of a page (visual hierarchy, typography, colours, spacing, responsive, accessibility). Useful in addition to create-wordpress-page and especially for Divi 5 pages.
---

# WordPress frontend design — method

Design is not a final coat of paint: it is what **makes a page readable**.
A well-designed page is scanned in a clear order, ranks information, and
remains usable on mobile and desktop alike. This skill gathers the
principles you should apply systematically when producing or improving a
WordPress page (Divi 5 in particular — see also `create-wordpress-page`).

## The seven core principles

### 1. Visual hierarchy

The visitor scans before reading. After looking at the page for two
seconds, they must understand:
- **The subject** (main heading, large size, strong contrast).
- **The promise** (subtitle / sub-headline).
- **The expected action** (CTA visible without scrolling — above the
  fold).

Tools: noticeably differentiated font sizes (at least a 1.4× gap between
H1 and body), font weight, colour, spacing.

### 2. Contrast (readability + accessibility)

- **WCAG AA contrast ratio at minimum**: 4.5:1 for body text, 3:1 for
  text ≥ 18 pt or bold.
- **No pale grey on white** for content: pretty in a mockup, illegible
  in practice.
- **Link colours**: must stand out from the body text (beyond mere
  underlining).
- Test with a contrast tool (WebAIM Contrast Checker mentally or via
  Rank Math / browser devtools).

### 3. Typography

- **At most 2 type families** per page (often: 1 sans-serif for
  headings + 1 serif or sans-serif for body).
- **Sizes**: 16 px minimum for body on desktop, 18 px ideal for reading
  comfort.
- **Line-height**: 1.4 to 1.6 × the font size for body. Tighter
  (1.1–1.2) for headings.
- **Line length**: 60 to 80 characters. Beyond that, the eye gets lost
  — use a `max-width` on text blocks.
- **Alignment**: ragged-right (left-aligned). Justified text produces
  ugly "rivers" without proper automatic hyphenation.

### 4. Spacing (the real hero)

- **Section padding**: generous — 80 to 120 px vertical on desktop, 40
  to 60 px on mobile.
- **Space between blocks**: let it breathe. A page packed at 100 %
  exhausts the reader.
- **Rule of 8**: use a spacing system in multiples of 8 px (8, 16, 24,
  32, 48, 64, 96…) for consistency.
- **White space ≠ empty**: it's what gives value to the elements that
  remain.

### 5. Mobile-first

- **Test at 360 px wide**: that's the most common smartphone. If the
  page works at 360, it works everywhere.
- **Test scrolling**: on mobile, you scroll. Optimise for verticality
  (stacked cards rather than side by side).
- **Thumb-reachable CTAs**: positioned in the bottom area of the
  screen or full-width, 44 px minimum height (touch target).
- **No hover-only** to reveal information: impossible on touch.
- **Responsive images**: `srcset`, modern formats (WebP, AVIF), no
  image > 1500 px wide without a good reason.

### 6. Consistency (design system)

On a Divi site: use the **global colors**, **global fonts**, **presets**
of Divi 5 (routes `divi/v1/global-data/*`). If the user has no design
system, **propose one** from the first page:

- **Colours**: 1 primary, 1 secondary (accent), 1 dark neutral (text),
  1 light neutral (background). 4 to 5 colours maximum across the
  whole site.
- **Type scale**: 5 to 6 sizes (e.g. 12, 14, 16, 20, 28, 40, 64 px),
  consistent site-wide.
- **Reusable components**: buttons, cards, badges, forms — all in the
  same style.

### 7. Animations and interactions (with restraint)

- **Scroll reveals**: yes, but gentle (200–400 ms, opacity + slight
  translateY). No "show off" effects.
- **Hover**: subtle effect (elevation, tint) to signal
  interactivity.
- **No video auto-play with sound** — ever.
- **Honour `prefers-reduced-motion`**: respect the user's setting.

## Divi 5 specifics

When you build with Divi 5:

1. **Think in sections > rows > modules.** A page = 4 to 8 sections.
   Each section has ONE goal (introduce, convince, reassure, act).
2. **Use the global colors** instead of hard-coded colours: a brand
   change propagates automatically.
3. **Multi-breakpoint**: Divi 5 exposes mobile/tablet/desktop (plus
   custom breakpoints). Always **check** all three — the same margin
   on desktop is too large on mobile.
4. **Most useful modules**:
   - `Text` — rich text
   - `Image` — image with link and alt
   - `Blurb` — icon + title + text (perfect for features)
   - `Call To Action` — ready-made CTA block
   - `Button` — reusable button
   - `Number Counter` — key numbers (with animation)
   - `Testimonial` — client quote
   - `Gallery` — image grid
5. **Avoid the Code module** unless absolutely necessary: it
   short-circuits the builder logic and complicates maintenance.

## Design workflow

When asked to create or improve a page:

1. **Understand the audience and the goal** (before thinking colours
   or fonts). Ask the user:
   - Who is the target visitor?
   - What action is expected after reading?
   - Which tone (formal, warm, premium, …)?
2. **Propose a structure** as a tree of sections before touching
   visuals. Have it validated.
3. **Define the design system** if missing (colours, fonts, scale).
4. **Build section by section**, testing each step on desktop **and**
   mobile (the user can monitor along the way).
5. **Final audit**: run the checklist of the 7 principles, have it
   validated.

## Anti-patterns to flag

When you spot these mistakes on an existing page, **offer to fix
them** (do not change without agreement):

- Pale grey text on white background.
- More than 3 different fonts.
- CTA visually blended with the rest (no differentiation).
- Sections touching each other (no whitespace).
- Stretched or compressed images.
- Auto-play carousels with no pause.
- Pop-ups appearing on load.
- Missing or duplicated H1.
- Mobile not tested (text overflowing, illegible buttons).
- "All in one" page with no hierarchy (a wall of text).
