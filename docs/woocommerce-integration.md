# WooCommerce integration

> Status: Living · Last updated: 2026-05-25

The IA Webmaster Bridge ships full read/write coverage of the 25
WooCommerce Divi 5 modules. They are designed to live **inside Divi
Theme Builder templates** — Shop archive, Single product, Cart,
Checkout — rather than inside standalone pages. This doc explains how
the AI detects WooCommerce, what contexts it knows about, and how it
combines `iawm_woocommerce_*` with `iawm_divi_theme_builder_*` to
build idiomatic WooCommerce templates.

## Detecting WooCommerce — `iawm_woocommerce_status`

Read-only audit of the site's WooCommerce setup. Returns:

| Field | Meaning |
|-------|---------|
| `is_active` | `true` when the `woocommerce` plugin is active OR the `WooCommerce` PHP class is loaded. |
| `version` | `WC()->version` when reachable, otherwise `null`. |
| `products_count` | Number of `product` posts with status `publish` (via `wp_count_posts`). |
| `currency` | `woocommerce_currency` option (e.g. `EUR`, `USD`) or `null`. |
| `pages.shop` / `pages.cart` / `pages.checkout` / `pages.myaccount` | Page ids resolved from the standard WC options (`woocommerce_shop_page_id`, etc.) — `null` if the page isn't set. |
| `has_template_for.{shop, single_product, cart, checkout}` | `true` when an existing Theme Builder template already targets the corresponding `use_on` condition. |
| `templates` | Compact list of every Theme Builder template currently defined (`id`, `title`, `use_on`) — useful to inspect what's there before creating or replacing. |

Use it as the **first** call of any WooCommerce-related task: it tells
the agent whether the plugin is installed (if not, suggest
`iawm_plugins_install woocommerce`) and what's already templated.

## The four template contexts — `iawm_woocommerce_contexts`

Returns the canonical mapping below. Each context lists the modules
typically placed in its body layout, and the Divi `use_on`
expression(s) that should be passed to `iawm_divi_theme_builder_template_assign`.

The modules listed are cross-checked against
[`docs/divi5-modules-registry.json`](divi5-modules-registry.json) —
all are canonical Divi 5 module names.

### `shop` — Shop archive

The main product listing page.

- `use_on`: `archive:product`
- Suggested modules:
  - `divi/woocommerce-breadcrumb`
  - `divi/shop` *(the product grid — the registry exposes it as `divi/shop`)*

### `single-product` — Single product

The product detail page.

- `use_on`: `singular:product`
- Suggested modules:
  - `divi/woocommerce-breadcrumb`
  - `divi/woocommerce-product-images`
  - `divi/woocommerce-product-gallery`
  - `divi/woocommerce-product-title`
  - `divi/woocommerce-product-rating`
  - `divi/woocommerce-product-price`
  - `divi/woocommerce-product-stock`
  - `divi/woocommerce-product-description`
  - `divi/woocommerce-product-add-to-cart`
  - `divi/woocommerce-product-meta`
  - `divi/woocommerce-product-additional-info`
  - `divi/woocommerce-product-tabs`
  - `divi/woocommerce-product-reviews`
  - `divi/woocommerce-related-products`
  - `divi/woocommerce-product-upsell`

### `cart` — Shopping cart

- `use_on`: `page:cart` (the endpoint also reports the explicit page
  id when the WC cart page is configured, e.g. `page:42`)
- Suggested modules:
  - `divi/woocommerce-cart-notice`
  - `divi/woocommerce-cart-products`
  - `divi/woocommerce-cart-totals`
  - `divi/woocommerce-cross-sells`

### `checkout` — Checkout flow

- `use_on`: `page:checkout` (plus the explicit page id when set)
- Suggested modules:
  - `divi/woocommerce-checkout-billing`
  - `divi/woocommerce-checkout-shipping`
  - `divi/woocommerce-checkout-additional-info`
  - `divi/woocommerce-checkout-order-details`
  - `divi/woocommerce-checkout-payment-info`

> The full 25-module roster also includes
> `divi/woocommerce-product-reviews`,
> `divi/woocommerce-product-stock`,
> `divi/woocommerce-product-additional-info`,
> `divi/woocommerce-product-gallery` — already listed under
> single-product above — for a total of 25 across all contexts.

## Walkthrough — "Build me a single-product template"

The agent's workflow when the user asks *"I have WooCommerce installed,
build me a single-product Theme Builder template"*:

1. **Probe** — call `iawm_woocommerce_status`. Read
   `is_active`, `has_template_for.single_product`, `products_count`.
   - If `is_active=false`, suggest installing WooCommerce first
     (`iawm_plugins_install` with `slug: "woocommerce", activate: true`).
   - If `has_template_for.single_product=true`, ask the user whether
     to replace the existing template or update it.
2. **Catalog** — call `iawm_woocommerce_contexts`, pick the
   `single-product` entry to get the canonical module list and the
   `use_on` expression.
3. **Compose** — call `iawm_divi_theme_builder_compose` with a body
   that follows the suggested module ordering. A minimal section
   would be:

   ```jsonc
   {
     "title": "Single product",
     "body": {
       "sections": [
         {
           "pattern": "free",
           "rows": [
             {
               "columns": [
                 [
                   { "module": "divi/woocommerce-breadcrumb" },
                   { "module": "divi/woocommerce-product-images" },
                   { "module": "divi/woocommerce-product-title" },
                   { "module": "divi/woocommerce-product-rating" },
                   { "module": "divi/woocommerce-product-price" },
                   { "module": "divi/woocommerce-product-description" },
                   { "module": "divi/woocommerce-product-add-to-cart" },
                   { "module": "divi/woocommerce-product-meta" },
                   { "module": "divi/woocommerce-product-tabs" }
                 ]
               ]
             },
             {
               "columns": [
                 [
                   { "module": "divi/woocommerce-related-products" },
                   { "module": "divi/woocommerce-product-upsell" }
                 ]
               ]
             }
           ]
         }
       ]
     },
     "assign": { "use_on": ["singular:product"] }
   }
   ```

4. **Verify** — `iawm_divi_theme_builder_list` (re-listed by the
   composer's return) should now show a template with
   `use_on: ["singular:product"]`. Visit any product in the
   front-end to confirm the layout takes effect.

## Typed builders (Phase 9.3)

10 of the 25 WooCommerce modules now have **typed builders** in the
gateway, accessible via `iawm_divi_page_compose` /
`iawm_divi_theme_builder_compose` under the `wc-*` module names
(prefix avoids collision with the post-aware builders, e.g.
`post-title` vs `wc-product-title`).

| Builder | Registry name | Typical context | Key options |
|---|---|---|---|
| `wc-product-title` | `divi/woocommerce-product-title` | single-product | `headingLevel?: "h1"\|"h2"\|"h3"` |
| `wc-product-price` | `divi/woocommerce-product-price` | single-product, archive | `alignment?` |
| `wc-product-images` | `divi/woocommerce-product-images` | single-product | `showProductImage?`, `showProductGallery?`, `showSaleBadge?`, `lightbox?` |
| `wc-product-add-to-cart` | `divi/woocommerce-product-add-to-cart` | single-product | `buttonText?`, `showQuantity?`, `showStock?` |
| `wc-product-description` | `divi/woocommerce-product-description` | single-product | `descriptionType?: "short"\|"long"` |
| `wc-product-tabs` | `divi/woocommerce-product-tabs` | single-product | `activeTab?`, `includeTabs?` |
| `wc-related-products` | `divi/woocommerce-related-products` | single-product | `postsNumber?`, `columns?`, `orderby?` |
| `wc-cart-products` | `divi/woocommerce-cart-products` | cart | `showThumbnail?`, `showQuantity?` |
| `wc-cart-totals` | `divi/woocommerce-cart-totals` | cart | `showProceedButton?` |
| `wc-checkout-billing` | `divi/woocommerce-checkout-billing` | checkout | `showTitle?`, `title?` |

The remaining 15 WooCommerce modules (cross-sells, checkout-shipping,
product-meta, product-stock, product-rating, etc.) stay reachable
free-form via `{ module: "block", block: ... }` or directly via the
registry. They are valuable but their option surface is small enough
that typed defaults add little.

**Example** — minimal single-product template body with only typed
builders, no free-form attribute dumps:

```jsonc
{
  "title": "Single product",
  "body": {
    "sections": [
      {
        "section": {
          "rows": [
            {
              "structure": "4_4",
              "columns": [
                [
                  { "module": "wc-product-images" },
                  { "module": "wc-product-title" },
                  { "module": "wc-product-price" },
                  { "module": "wc-product-description", "descriptionType": "short" },
                  { "module": "wc-product-add-to-cart", "showQuantity": true }
                ]
              ]
            },
            {
              "structure": "4_4",
              "columns": [
                [
                  { "module": "wc-product-tabs", "activeTab": "description" }
                ]
              ]
            },
            {
              "structure": "4_4",
              "columns": [
                [
                  { "module": "wc-related-products", "columns": 4, "postsNumber": 4 }
                ]
              ]
            }
          ]
        }
      }
    ]
  },
  "assign": { "use_on": ["singular:product"] }
}
```

## Composing WC modules in standalone pages

The 25 WooCommerce modules also work inside standalone pages via
`iawm_divi_page_compose`. This is occasionally useful — e.g. a
landing page that embeds a single product with `divi/woocommerce-product-price`
and `divi/woocommerce-product-add-to-cart` — but the **idiomatic**
place for them is a Theme Builder template. A standalone-page usage
will only render correctly when the page itself is being viewed in
the right product context (the modules expect the global WC product
or cart loop to be set up).

Rule of thumb:

- **Want it to apply to every product/cart/checkout/shop page?** → Theme
  Builder template assigned to the matching `use_on` condition.
- **Want it on one specific page only?** → standalone page via
  `iawm_divi_page_compose`, accepting that some modules will fall back
  to empty markup when the WC context isn't available.

## Related tools

- `iawm_divi_theme_builder_list` — inspect existing templates.
- `iawm_divi_theme_builder_template_create` — create a fresh template
  shell.
- `iawm_divi_theme_builder_layout_create` — populate one of the three
  layout zones (header/body/footer) with Divi 5 blocks.
- `iawm_divi_theme_builder_template_assign` — assign the template to
  one or more Divi conditions (`singular:product`, `archive:product`,
  `page:cart`, …).
- `iawm_divi_theme_builder_compose` — high-level composer that wraps
  the four primitives above.
- `iawm_divi_modules_catalog` / `iawm_divi_module_info` — full
  attribute reference per module.
