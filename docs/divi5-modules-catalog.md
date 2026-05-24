# Divi 5 (native) modules catalog

> Source of truth: `wp-content/themes/Divi/includes/builder-5/server/Packages/ModuleLibrary/`
> on the local site. Cross-referenced with the official Elegant Themes docs.
> Last updated: 2026-05-23.

## Block naming pattern

Divi 5 modules are registered as Gutenberg blocks under the `divi/*`
namespace. The `blockName` is the **kebab-case** of the PascalCase class
name:

| PHP class | blockName |
|-----------|-----------|
| `NumberCounter` | `divi/number-counter` |
| `AccordionItem` | `divi/accordion-item` |
| `BeforeAfterImage` | `divi/before-after-image` |
| `WooCommerceBreadcrumb` | `divi/woocommerce-breadcrumb` (to confirm) |

## Legend

- ✅ **Documented + TS builder implemented** in `lib/divi/builders.ts`
- 🟢 **Documented** (format observed on a reference page)
- 🟡 **Inventoried** (blockName inferred, format to validate)
- ⚠️ **To confirm** (populate a reference page)

---

## 1. Structural (3)

The entire skeleton of a Divi 5 page. Mandatory hierarchy:
`placeholder > section > row > column > modules`.

| blockName | Status | Note |
|-----------|--------|------|
| `divi/placeholder` | ✅ | Mandatory root wrapper |
| `divi/section` | ✅ | Horizontal band |
| `divi/row` | ✅ | With `columnStructure` (notation `a_b,c_d,...`) |
| `divi/column` | ✅ | `type` (e.g. `1_3`), auto `flexType` (`8_24`) |
| `divi/row-inner` | 🟡 | Variant of row for nested modules |
| `divi/column-inner` | 🟡 | Variant of column for nested modules |

---

## 2. Basic content modules (12)

The builder's fundamental bricks. **All documented or implemented.**

| blockName | Status | TS builder | Pattern |
|-----------|--------|------------|---------|
| `divi/text` | ✅ | `text({ html, headingFont })` | hero / features3col |
| `divi/heading` | ✅ | `heading({ text })` | — |
| `divi/blurb` | ✅ | `blurb({ title, contentHtml, iconUnicode? })` | features3col |
| `divi/cta` | ✅ | `cta({ title, contentHtml, buttonText, buttonUrl })` | ctaBanner |
| `divi/button` | ✅ | `button({ text, linkUrl })` | — |
| `divi/image` | ✅ | `image({ src, alt? })` | imageTextSplit |
| `divi/video` | ✅ | `video({ src })` | videoSection |
| `divi/code` | ✅ | `code({ html })` | (avoid) |
| `divi/divider` | ✅ | `divider({ color?, height? })` | useful for visual separation |
| `divi/icon` | ✅ | `icon({ unicode, color?, size? })` | Divi unicode code |
| `divi/gallery` | ✅ | `gallery({ ids[], columns? })` | — |
| `divi/audio` | ✅ | `audio({ title, artistName?, audioUrl? })` | HTML5 audio player |

---

## 3. Composed (nested) modules (8 groups)

Modules that contain other Divi blocks as `innerBlocks`.

| Container | Child item | Status | TS builder |
|-----------|-------------|--------|------------|
| `divi/accordion` | `divi/accordion-item` | ✅ | `accordion([items])` |
| `divi/tabs` | `divi/tab` | ✅ | `tabs([items])` |
| `divi/slider` | `divi/slide` | ✅ | `slider([items])` |
| `divi/contact-form` | `divi/contact-field` | ✅ | `contactForm({ fields })` |
| `divi/toggle` | — (simple) | 🟡 | To add (accordion but a single item) |
| `divi/pricing-tables` | `divi/pricing-table` ⚠️ | ✅ | **singular** (not -item nor -s) |
| `divi/icon-list` | `divi/icon-list-item` | ✅ | |
| `divi/social-media-follow` | `divi/social-media-follow-network` ⚠️ | ✅ | **`-network`** (not `-item`) |
| `divi/timeline` | `divi/timeline-item` | 🟡 | To add — company history |
| `divi/map` | `divi/map-item` | ✅ | (item to add for markers) |
| `divi/video-slider` | `divi/video-slider-item` | 🟡 | To add |
| `divi/counters` ⚠️ | `divi/counter` ⚠️ | ✅ | **`counters`**, not `bar-counters` |

---

## 4. Statistics and numbers (4)

| blockName | Status | TS builder | Note |
|-----------|--------|------------|------|
| `divi/number-counter` | ✅ | `numberCounter({ title, number, percent? })` | Animation on scroll |
| `divi/circle-counter` | ✅ | `circleCounter({ title, number })` | Circular variant |
| `divi/counters` ⚠️ (composed) | ✅ | `counters({ items, showPercentages? })` | Horizontal bars (NOT `bar-counters`) |
| `divi/countdown-timer` | 🟡 | To add | Real countdown |

---

## 5. People and testimonials (2)

| blockName | Status | TS builder | Note |
|-----------|--------|------------|------|
| `divi/testimonial` | ✅ | `testimonial({ quoteHtml, author, portraitUrl? })` | Quote + photo |
| `divi/team-member` | ✅ | `teamMember({ name, position, imageUrl?, bioHtml? })` | Photo + bio + social networks |

---

## 6. Portfolio and galleries (5)

| blockName | Status | Note |
|-----------|--------|------|
| `divi/portfolio` | 🟡 | WP project grid (post_type=project) |
| `divi/filterable-portfolio` | 🟡 | With category filters |
| `divi/fullwidth-portfolio` | 🟡 | Full-width variant |
| `divi/gallery` | ✅ | Simple image gallery |
| `divi/before-after-image` | 🟡 | Comparison slider |

---

## 7. WordPress dynamic content (theme builder) (6)

Modules fed from the current post. Mainly useful in the Theme Builder
(dynamic page templates).

| blockName | Status | Note |
|-----------|--------|------|
| `divi/post-title` | 🟡 | Dynamic post title |
| `divi/post-content` | 🟡 | Post content |
| `divi/post-navigation` | 🟡 | Previous / next links |
| `divi/post-slider` | 🟡 | Posts as a slider |
| `divi/blog` | 🟡 | Paginated post list |
| `divi/comments` | 🟡 | WP comments |
| `divi/breadcrumbs` | 🟡 | Breadcrumb (useful for SEO) |

Full-width variants (`fullwidth-*`):
- `divi/fullwidth-header`
- `divi/fullwidth-image`
- `divi/fullwidth-map`
- `divi/fullwidth-menu`
- `divi/fullwidth-portfolio`
- `divi/fullwidth-post-content`
- `divi/fullwidth-post-slider`
- `divi/fullwidth-post-title`
- `divi/fullwidth-slider`
- `divi/fullwidth-code`

---

## 8. Navigation and menus (3)

| blockName | Status | Note |
|-----------|--------|------|
| `divi/menu` | 🟡 | WP menu |
| `divi/search` | 🟡 | Search bar |
| `divi/sidebar` | 🟡 | Displays a WP sidebar |

---

## 9. Forms and users (4)

| blockName | Status | TS builder | Note |
|-----------|--------|------------|------|
| `divi/contact-form` (composed) | ✅ | `contactForm({ fields })` | Native Divi form |
| `divi/contact-form-7` | 🟡 | — | CF7 integration |
| `divi/signup` | ✅ | `signup({ title, contentHtml })` | Email opt-in (newsletter) |
| `divi/signup-custom-field` | 🟡 | — | Child of signup |
| `divi/login` | 🟡 | To add | WP login form |

---

## 10. Media and visual richness (4)

| blockName | Status | Note |
|-----------|--------|------|
| `divi/lottie` | 🟡 | Lottie animations (JSON) |
| `divi/svg` | 🟡 | Inline SVG |
| `divi/icon` | 🟡 | Divi icon (unicode + color + size) |
| `divi/link` | 🟡 | Styled button/link |

---

## 11. Advanced layout (3)

| blockName | Status | Note |
|-----------|--------|------|
| `divi/group` | 🟡 | Block grouping |
| `divi/group-carousel` | 🟡 | Group as a carousel |
| `divi/canvas-portal` | 🟡 | Portal to off-canvas |
| `divi/dropdown` | 🟡 | Dropdown menu |
| `divi/common` | 🟡 | Utility module (rare in direct usage) |

---

## 12. WooCommerce (25 modules) ⚠️

For e-commerce sites. **All blockNames to confirm** — the pattern is
likely `divi/woocommerce-{slug}` but needs validation by populating a
reference WooCommerce page.

| Module | Probable blockName |
|--------|---------------------|
| Breadcrumb | `divi/woocommerce-breadcrumb` |
| CartNotice | `divi/woocommerce-cart-notice` |
| CartProducts | `divi/woocommerce-cart-products` |
| CartTotals | `divi/woocommerce-cart-totals` |
| CheckoutBilling | `divi/woocommerce-checkout-billing` |
| CheckoutInformation | `divi/woocommerce-checkout-information` |
| CheckoutOrderDetails | `divi/woocommerce-checkout-order-details` |
| CheckoutPaymentInfo | `divi/woocommerce-checkout-payment-info` |
| CheckoutShipping | `divi/woocommerce-checkout-shipping` |
| CrossSells | `divi/woocommerce-cross-sells` |
| ProductAdditionalInfo | `divi/woocommerce-product-additional-info` |
| ProductAddToCart | `divi/woocommerce-product-add-to-cart` |
| ProductDescription | `divi/woocommerce-product-description` |
| ProductGallery | `divi/woocommerce-product-gallery` |
| ProductImages | `divi/woocommerce-product-images` |
| ProductMeta | `divi/woocommerce-product-meta` |
| ProductPrice | `divi/woocommerce-product-price` |
| ProductRating | `divi/woocommerce-product-rating` |
| ProductReviews | `divi/woocommerce-product-reviews` |
| Products | `divi/woocommerce-products` |
| ProductStock | `divi/woocommerce-product-stock` |
| ProductTabs | `divi/woocommerce-product-tabs` |
| ProductTitle | `divi/woocommerce-product-title` |
| ProductUpsell | `divi/woocommerce-product-upsell` |
| RelatedProducts | `divi/woocommerce-related-products` |

→ **Later phase**: create a reference WooCommerce product page to
validate all these blockNames and their attribute structure.

---

## Coverage summary

| Category | Total | ✅ Covered | 🟡 Inventoried | ⚠️ Woo |
|-----------|-------|-----------|---------------|--------|
| Structural | 6 | 4 | 2 | — |
| Basic content | 12 | 9 | 3 | — |
| Composed | 12 | 4 | 8 | — |
| Numbers | 4 | 1 | 3 | — |
| People/testimonials | 2 | 1 | 1 | — |
| Portfolio | 5 | 1 | 4 | — |
| Theme Builder | 6 + 10 fullwidth | 0 | 16 | — |
| Navigation | 3 | 0 | 3 | — |
| Forms/users | 5 | 1 | 4 | — |
| Media | 4 | 0 | 4 | — |
| Layout | 5 | 0 | 5 | — |
| WooCommerce | 25 | 0 | — | 25 |
| **NATIVE TOTAL** | **~99** | **21** | **53** | **25** |

→ **21 operational modules** on the TS builder side (covers all classic
marketing pages: landing, services, about, contact, FAQ).
→ **~30 modules** to add at medium priority (theme builder, portfolio,
icon-list, social-follow, pricing-tables, team, signup).
→ **WooCommerce**: dedicated phase to come.

---

## `lib/divi/builders.ts` extension priorities

Top 10 modules to implement first (impact / usage frequency):

1. **`divider`** — visual separation (very common)
2. **`icon`** — standalone icon
3. **`pricing-tables`** + `pricing-tables-item` — pricing pages
4. **`icon-list`** + `icon-list-item` — bullet lists
5. **`social-media-follow`** + `social-media-follow-item` — footer/header
6. **`team-member`** — team page
7. **`signup`** — newsletter email capture
8. **`map`** — contact page
9. **`circle-counter`** + **`bar-counters`** — KPI variations
10. **`toggle`** — content reveal (mini-accordion)
