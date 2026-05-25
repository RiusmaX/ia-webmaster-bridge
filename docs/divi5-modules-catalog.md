# Divi 5 modules catalog

> Auto-generated from the local Divi 5 install by `tools/scan-divi-modules.mjs`.
> Do not edit by hand — re-run the script after a Divi update. Last scan: 2026-05-25T12:04:31.924Z.

## Summary

- **105 modules total**
  - 80 native (incl. structure, child and fullwidth variants)
  - 25 WooCommerce

Module families (native):
- 7 structure (section, row, column, etc.)
- 51 regular modules
- 10 fullwidth variants
- 12 child modules (rows inside a parent)

Notation in tables below:
- **name**: the WordPress block name as it appears in saved `post_content` (e.g. `divi/button`).
- **D4 shortcode**: the Divi 4 shortcode the module migrated from (for cross-reference with Divi 4 content / older docs).
- **children**: child block names the module accepts inside its inner blocks.
- **attr groups**: the top-level keys of the module's attribute tree as it appears in saved attributes (each key is itself a tree per breakpoint — see `docs/divi5-format.md`).

## Structure (7)

| Slug | Block name | D4 shortcode | Title | Children | Attr groups |
|------|------------|--------------|-------|----------|-------------|
| `column` | `divi/column` | `et_pb_column` | Column | — | `module` |
| `column-inner` | `divi/column-inner` | `et_pb_column_inner` | Inner Column | — | `module` |
| `global-layout` | `divi/global-layout` | `` | Global Layout | — | — |
| `group` | `divi/group` | `` | Group | — | `module` |
| `row` | `divi/row` | `et_pb_row` | Row | — | `module` |
| `row-inner` | `divi/row-inner` | `et_pb_row_inner` | Inner Row | — | — |
| `section` | `divi/section` | `et_pb_section` | Section | `divi/row` | `module` |

## Native modules (51)

| Slug | Block name | D4 shortcode | Title | Children | Attr groups |
|------|------------|--------------|-------|----------|-------------|
| `accordion` | `divi/accordion` | `et_pb_accordion` | Accordion | `divi/accordion-item` | `closedToggleIcon`, `module`, `title` |
| `audio` | `divi/audio` | `et_pb_audio` | Audio | — | `module`, `title` |
| `before-after-image` | `divi/before-after-image` | `et_pb_before_after_image` | Before/After Image | — | `module`, `slider` |
| `blog` | `divi/blog` | `et_pb_blog` | Blog | — | `blogGrid`, `fullwidth`, `image`, `meta`, `module`, `overlayIcon`, `pagination`, `post`, `readMore`, `title` |
| `blurb` | `divi/blurb` | `et_pb_blurb` | Blurb | — | `imageIcon`, `module`, `title` |
| `breadcrumbs` | `divi/breadcrumbs` | `` | Breadcrumbs | — | `home`, `module`, `separator`, `trail` |
| `button` | `divi/button` | `et_pb_button` | Button | — | `button`, `module` |
| `canvas-portal` | `divi/canvas-portal` | `et_pb_canvas_portal` | Canvas Portal | — | `canvas`, `module` |
| `circle-counter` | `divi/circle-counter` | `et_pb_circle_counter` | Circle Counter | — | `circle`, `module`, `number`, `title` |
| `code` | `divi/code` | `et_pb_code` | Code | — | `module` |
| `comments` | `divi/comments` | `et_pb_comments` | Comments | — | `button`, `commentCount`, `field`, `formTitle`, `image`, `meta`, `module` |
| `contact-form` | `divi/contact-form` | `et_pb_contact_form` | Contact Form | `divi/contact-field` | `button`, `module`, `redirect`, `title` |
| `contact-form-7` | `divi/contact-form-7` | `et_pb_contact_form_7` | Contact Form 7 Styler | — | `module` |
| `countdown-timer` | `divi/countdown-timer` | `et_pb_countdown_timer` | Countdown Timer | — | `content`, `module`, `title` |
| `counters` | `divi/counters` | `et_pb_counters` | Bar Counters | `divi/counter` | `barProgress`, `children`, `module` |
| `cta` | `divi/cta` | `et_pb_cta` | Call To Action | — | `button`, `module`, `title` |
| `divider` | `divi/divider` | `et_pb_divider` | Divider | — | `divider`, `module` |
| `dropdown` | `divi/dropdown` | `et_pb_dropdown` | Dropdown | — | `module` |
| `filterable-portfolio` | `divi/filterable-portfolio` | `et_pb_filterable_portfolio` | Filterable Portfolio | — | `module`, `overlay`, `portfolio`, `portfolioGrid`, `title` |
| `gallery` | `divi/gallery` | `et_pb_gallery` | Gallery | — | `galleryGrid`, `image`, `module`, `pagination`, `title` |
| `group-carousel` | `divi/group-carousel` | `` | Group Carousel | `divi/group` | `arrows`, `dotNav`, `module` |
| `heading` | `divi/heading` | `et_pb_heading` | Heading | — | `module`, `title` |
| `icon` | `divi/icon` | `et_pb_icon` | Icon | — | `icon`, `module` |
| `icon-list` | `divi/icon-list` | `et_pb_icon_list` | Icon List | `divi/icon-list-item` | `icon`, `module` |
| `image` | `divi/image` | `et_pb_image` | Image | — | `image`, `module` |
| `link` | `divi/link` | `et_pb_link` | Link | — | `module` |
| `login` | `divi/login` | `et_pb_login` | Login | — | `button`, `module`, `title` |
| `lottie` | `divi/lottie` | `et_pb_lottie` | Lottie | — | `lottie`, `module` |
| `map` | `divi/map` | `et_pb_map` | Map | `divi/map-pin` | `map`, `module` |
| `menu` | `divi/menu` | `et_pb_menu` | Menu | — | `cartIcon`, `cartQuantity`, `hamburgerMenuIcon`, `logo`, `menu`, `menuDropdown`, `module`, `searchIcon` |
| `number-counter` | `divi/number-counter` | `et_pb_number_counter` | Number Counter | — | `module`, `number`, `title` |
| `portfolio` | `divi/portfolio` | `et_pb_portfolio` | Portfolio | — | `module`, `overlay`, `portfolio`, `portfolioGrid`, `title` |
| `post-content` | `divi/post-content` | `et_pb_post_content` | Post Content | — | — |
| `post-nav` | `divi/post-nav` | `et_pb_post_nav` | Pagination | — | `links`, `module` |
| `post-slider` | `divi/post-slider` | `et_pb_post_slider` | Post Slider | — | `arrows`, `button`, `content`, `image`, `meta`, `module`, `pagination`, `post`, `slideOverlay`, `title` |
| `post-title` | `divi/post-title` | `et_pb_post_title` | Post Title | — | `image`, `meta`, `module`, `textWrapper`, `title` |
| `pricing-tables` | `divi/pricing-tables` | `et_pb_pricing_tables` | Pricing Tables | `divi/pricing-table` | `button`, `content`, `featuredTable`, `module`, `title` |
| `search` | `divi/search` | `et_pb_search` | Search | — | `button`, `module`, `search` |
| `sidebar` | `divi/sidebar` | `et_pb_sidebar` | Sidebar | — | `module`, `sidebar`, `sidebarWidgets` |
| `signup` | `divi/signup` | `et_pb_signup` | Email Optin | — | `button`, `content`, `customFields`, `field`, `module`, `resultMessage`, `success`, `title` |
| `slider` | `divi/slider` | `et_pb_slider` | Slider | `divi/slide` | `arrows`, `button`, `children`, `module`, `pagination`, `title` |
| `social-media-follow` | `divi/social-media-follow` | `et_pb_social_media_follow` | Social Media Follow | `divi/social-media-follow-network` | `icon`, `module`, `socialNetwork` |
| `svg` | `divi/svg` | `et_pb_svg` | SVG | — | `module`, `svg` |
| `tabs` | `divi/tabs` | `et_pb_tabs` | Tabs | `divi/tab` | `module` |
| `team-member` | `divi/team-member` | `et_pb_team_member` | Person | — | `image`, `module`, `name`, `social` |
| `testimonial` | `divi/testimonial` | `et_pb_testimonial` | Testimonial | — | `company`, `module`, `quoteIcon` |
| `text` | `divi/text` | `et_pb_text` | Text | — | `module` |
| `timeline` | `divi/timeline` | `et_pb_timeline` | Timeline | `divi/timeline-item` | `module` |
| `toggle` | `divi/toggle` | `et_pb_toggle` | Toggle | — | `closedToggleIcon`, `module`, `openToggleIcon`, `title` |
| `video` | `divi/video` | `et_pb_video` | Video | — | `module`, `overlay`, `playIcon` |
| `video-slider` | `divi/video-slider` | `et_pb_video_slider` | Video Slider | `divi/video-slider-item` | `module`, `overlay`, `playIcon`, `sliderControls` |

## Fullwidth modules (10)

| Slug | Block name | D4 shortcode | Title | Children | Attr groups |
|------|------------|--------------|-------|----------|-------------|
| `fullwidth-code` | `divi/fullwidth-code` | `et_pb_fullwidth_code` | Fullwidth Code | — | `module` |
| `fullwidth-header` | `divi/fullwidth-header` | `et_pb_fullwidth_header` | Hero | — | `buttonOne`, `buttonTwo`, `content`, `image`, `module`, `scrollDown`, `title` |
| `fullwidth-image` | `divi/fullwidth-image` | `et_pb_fullwidth_image` | Fullwidth Image | — | `image`, `module` |
| `fullwidth-map` | `divi/fullwidth-map` | `et_pb_fullwidth_map` | Fullwidth Map | `divi/map-pin` | `map`, `module` |
| `fullwidth-menu` | `divi/fullwidth-menu` | `et_pb_fullwidth_menu` | Fullwidth Menu | — | `cartIcon`, `cartQuantity`, `hamburgerMenuIcon`, `logo`, `menu`, `menuDropdown`, `module`, `searchIcon` |
| `fullwidth-portfolio` | `divi/fullwidth-portfolio` | `et_pb_fullwidth_portfolio` | Post Carousel | — | `module`, `overlay`, `portfolio`, `portfolioGrid`, `title` |
| `fullwidth-post-content` | `divi/fullwidth-post-content` | `et_pb_fullwidth_post_content` | Fullwidth Post Content | — | — |
| `fullwidth-post-slider` | `divi/fullwidth-post-slider` | `et_pb_fullwidth_post_slider` | Fullwidth Post Slider | — | `arrows`, `button`, `content`, `image`, `meta`, `module`, `pagination`, `post`, `slideOverlay`, `title` |
| `fullwidth-post-title` | `divi/fullwidth-post-title` | `et_pb_fullwidth_post_title` | Fullwidth Post Title | — | `featuredImage`, `meta`, `module`, `textWrapper`, `title` |
| `fullwidth-slider` | `divi/fullwidth-slider` | `et_pb_fullwidth_slider` | Fullwidth Slider | `divi/slide` | `arrows`, `button`, `children`, `module`, `pagination`, `title` |

## Child modules (12)

| Slug | Block name | D4 shortcode | Title | Children | Attr groups |
|------|------------|--------------|-------|----------|-------------|
| `accordion-item` | `divi/accordion-item` | `et_pb_accordion_item` | Accordion Item | — | `closedToggleIcon`, `module` |
| `contact-field` | `divi/contact-field` | `et_pb_contact_field` | Field | — | `fieldItem`, `module` |
| `counter` | `divi/counter` | `et_pb_counter` | Bar Counter | — | `barProgress`, `module` |
| `icon-list-item` | `divi/icon-list-item` | `et_pb_icon_list_item` | Icon List Item | — | `content`, `icon`, `module` |
| `map-pin` | `divi/map-pin` | `et_pb_map_pin` | Map Pin | — | `pin` |
| `pricing-table` | `divi/pricing-table` | `et_pb_pricing_table` | Pricing Table | — | `module` |
| `signup-custom-field` | `divi/signup-custom-field` | `et_pb_signup_custom_field` | Custom Field | — | `fieldItem`, `module` |
| `slide` | `divi/slide` | `et_pb_slide` | Slide | — | `button`, `contentOverlay`, `image`, `module` |
| `social-media-follow-item` | `divi/social-media-follow-network` | `et_pb_social_media_follow_network` | Social Network | — | `icon`, `module`, `socialNetwork` |
| `tab` | `divi/tab` | `et_pb_tab` | Tab | — | — |
| `timeline-item` | `divi/timeline-item` | `et_pb_timeline_item` | Timeline Item | — | `module` |
| `video-slider-item` | `divi/video-slider-item` | `et_pb_video_slider_item` | Video Slider Item | — | `module`, `overlay`, `playIcon`, `sliderControls` |

## WooCommerce modules (25)

| Slug | Block name | D4 shortcode | Title | Children | Attr groups |
|------|------------|--------------|-------|----------|-------------|
| `breadcrumb` | `divi/woocommerce-breadcrumb` | `et_pb_wc_breadcrumb` | Woo Breadcrumbs | — | `content`, `module` |
| `cart-notice` | `divi/woocommerce-cart-notice` | `et_pb_wc_cart_notice` | Woo Notice | — | `button`, `content`, `field`, `module` |
| `cart-products` | `divi/woocommerce-cart-products` | `et_pb_wc_cart_products` | Woo Cart Products | — | `button`, `elements`, `layout`, `module`, `table` |
| `cart-totals` | `divi/woocommerce-cart-totals` | `et_pb_wc_cart_totals` | Woo Cart Totals | — | `button`, `module`, `table` |
| `checkout-billing` | `divi/woocommerce-checkout-billing` | `et_pb_wc_checkout_billing` | Woo Checkout Billing | — | `layout`, `module` |
| `checkout-information` | `divi/woocommerce-checkout-additional-info` | `et_pb_wc_checkout_additional_info` | Woo Checkout Information | — | `elements`, `module` |
| `checkout-order-details` | `divi/woocommerce-checkout-order-details` | `et_pb_wc_checkout_order_details` | Woo Checkout Details | — | `module`, `table` |
| `checkout-payment-info` | `divi/woocommerce-checkout-payment-info` | `et_pb_wc_checkout_payment_info` | Woo Checkout Payment | — | `button`, `module` |
| `checkout-shipping` | `divi/woocommerce-checkout-shipping` | `et_pb_wc_checkout_shipping` | Woo Checkout Shipping | — | `layout`, `module` |
| `cross-sells` | `divi/woocommerce-cross-sells` | `et_pb_wc_cross_sells` | Woo Cross Sells | — | `module` |
| `product-add-to-cart` | `divi/woocommerce-product-add-to-cart` | `et_pb_wc_add_to_cart` | Woo Product Add To Cart | — | `button`, `content`, `elements`, `fieldLabels`, `module` |
| `product-additional-info` | `divi/woocommerce-product-additional-info` | `et_pb_wc_additional_info` | Woo Product Information | — | `content`, `elements`, `module` |
| `product-description` | `divi/woocommerce-product-description` | `et_pb_wc_description` | Woo Product Description | — | `content`, `module` |
| `product-gallery` | `divi/woocommerce-product-gallery` | `et_pb_wc_gallery` | Woo Product Gallery | — | `content`, `galleryGrid`, `image`, `layout`, `module`, `overlay`, `overlayIcon`, `title` |
| `product-images` | `divi/woocommerce-product-images` | `et_pb_wc_images` | Woo Product Images | — | `content`, `elements`, `galleryGrid`, `module` |
| `product-meta` | `divi/woocommerce-product-meta` | `et_pb_wc_meta` | Woo Product Meta | — | `content`, `elements`, `layout`, `module` |
| `product-price` | `divi/woocommerce-product-price` | `et_pb_wc_price` | Woo Product Price | — | `content`, `module` |
| `product-rating` | `divi/woocommerce-product-rating` | `et_pb_wc_rating` | Woo Product Rating | — | `content`, `elements`, `layout`, `module` |
| `product-reviews` | `divi/woocommerce-product-reviews` | `et_pb_wc_reviews` | Woo Product Reviews | — | `button`, `content`, `elements`, `module`, `reviewCount` |
| `product-stock` | `divi/woocommerce-product-stock` | `et_pb_wc_stock` | Woo Product Stock | — | `content`, `module` |
| `product-tabs` | `divi/woocommerce-product-tabs` | `et_pb_wc_tabs` | Woo Product Tabs | — | `content`, `module` |
| `product-title` | `divi/woocommerce-product-title` | `et_pb_wc_title` | Woo Product Title | — | `content`, `module`, `title` |
| `product-upsell` | `divi/woocommerce-product-upsell` | `et_pb_wc_upsells` | Woo Product Upsell | — | `content`, `elements`, `module` |
| `products` | `divi/shop` | `et_pb_shop` | Woo Products | — | `content`, `elements`, `module` |
| `related-products` | `divi/woocommerce-related-products` | `et_pb_wc_related_products` | Woo Related Products | — | `content`, `elements`, `module` |

## Full attribute paths

For each module, the flat list of attribute dot-paths that Divi sets by default. Use these as keys under the per-breakpoint envelope (`desktop` / `tablet` / `phone` / `phoneWide`).

### `divi/accordion` — Accordion

- `closedToggleIcon.decoration.icon`
- `module.meta.adminLabel`
- `title.decoration.font.font`

### `divi/accordion-item` — Accordion Item

- `closedToggleIcon.decoration.icon`
- `module.advanced.open`

### `divi/audio` — Audio

- `module.advanced.text.text`
- `module.decoration.background`
- `module.meta.adminLabel`
- `title.decoration.font.font`

### `divi/before-after-image` — Before/After Image

- `module.decoration.spacing`
- `module.meta.adminLabel`
- `slider.advanced.orientation`

### `divi/blog` — Blog

- `blogGrid.decoration.layout`
- `fullwidth.advanced.enable`
- `image.advanced.enable`
- `meta.advanced.showAuthor`
- `meta.advanced.showCategories`
- `meta.advanced.showComments`
- `meta.advanced.showDate`
- `module.advanced.text.text`
- `module.meta.adminLabel`
- `overlayIcon.decoration.icon`
- `pagination.advanced.enable`
- `post.advanced.dateFormat`
- `post.advanced.excerptContent`
- `post.advanced.excerptLength`
- `post.advanced.excerptManual`
- `post.advanced.number`
- `post.advanced.offset`
- `post.advanced.showExcerpt`
- `post.advanced.type`
- `readMore.advanced.enable`
- `title.decoration.font.font`

### `divi/blurb` — Blurb

- `imageIcon.advanced.color`
- `imageIcon.advanced.placement`
- `imageIcon.innerContent`
- `module.advanced.text.text`
- `module.meta.adminLabel`
- `title.decoration.font.font`

### `divi/breadcrumbs` — Breadcrumbs

- `home.innerContent`
- `module.decoration.spacing`
- `module.meta.adminLabel`
- `separator.innerContent`
- `trail.advanced.htmlTag`

### `divi/button` — Button

- `button.decoration.button`
- `module.advanced.html`
- `module.advanced.text.text`
- `module.meta.adminLabel`

### `divi/canvas-portal` — Canvas Portal

- `canvas.advanced.canvasId`
- `module.meta.adminLabel`

### `divi/circle-counter` — Circle Counter

- `circle.advanced.background`
- `circle.advanced.color`
- `module.advanced.text.text`
- `module.decoration.spacing`
- `module.meta.adminLabel`
- `number.advanced.percentSign`
- `number.innerContent`
- `title.decoration.font.font`

### `divi/code` — Code

- `module.decoration.spacing`
- `module.meta.adminLabel`

### `divi/column` — Column

- `module.decoration.sizing`
- `module.meta.adminLabel`

### `divi/column-inner` — Inner Column

- `module.meta.adminLabel`

### `divi/comments` — Comments

- `button.decoration.button`
- `commentCount.advanced.showCount`
- `commentCount.decoration.font.font`
- `field.advanced.focus.border`
- `field.advanced.focusUseBorder`
- `field.decoration.border`
- `formTitle.decoration.font.font`
- `image.advanced.showAvatar`
- `meta.advanced.showMeta`
- `module.advanced.showReply`
- `module.advanced.text.text`
- `module.meta.adminLabel`

### `divi/contact-field` — Field

- `fieldItem.advanced.id`
- `fieldItem.advanced.required`
- `fieldItem.advanced.type`
- `fieldItem.innerContent`
- `module.decoration.sizing`

### `divi/contact-form` — Contact Form

- `button.decoration.button`
- `button.decoration.sizing`
- `button.innerContent`
- `module.advanced.spamProtection`
- `module.meta.adminLabel`
- `redirect.advanced.useRedirect`
- `title.decoration.font.font`

### `divi/contact-form-7` — Contact Form 7 Styler

- `module.decoration.spacing`
- `module.meta.adminLabel`

### `divi/countdown-timer` — Countdown Timer

- `content.advanced.dateTime`
- `module.advanced.text.text`
- `module.decoration.background`
- `module.meta.adminLabel`
- `title.decoration.font.font`

### `divi/counter` — Bar Counter

- `barProgress.innerContent`
- `module.advanced.html`
- `module.meta.adminLabel`

### `divi/counters` — Bar Counters

- `barProgress.advanced.usePercentages`
- `children.barProgress.decoration.background`
- `module.advanced.html`
- `module.advanced.text.text`
- `module.meta.adminLabel`

### `divi/cta` — Call To Action

- `button.decoration.button`
- `module.advanced.text.text`
- `module.decoration.background`
- `module.meta.adminLabel`
- `title.decoration.font.font`

### `divi/divider` — Divider

- `divider.advanced.line`
- `module.meta.adminLabel`

### `divi/dropdown` — Dropdown

- `module.advanced.dropdown`
- `module.meta.adminLabel`

### `divi/filterable-portfolio` — Filterable Portfolio

- `module.advanced.text.text`
- `module.meta.adminLabel`
- `overlay.decoration.icon`
- `portfolio.advanced.layout`
- `portfolio.advanced.postsNumber`
- `portfolio.advanced.showCategories`
- `portfolio.advanced.showPagination`
- `portfolio.advanced.showTitle`
- `portfolioGrid.advanced.flexType`
- `portfolioGrid.decoration.layout`
- `title.decoration.font.font`

### `divi/fullwidth-code` — Fullwidth Code

- `module.meta.adminLabel`

### `divi/fullwidth-header` — Hero

- `buttonOne.decoration.button`
- `buttonTwo.decoration.button`
- `content.advanced.maxWidth`
- `content.advanced.orientation`
- `image.advanced.orientation`
- `module.advanced.headerFullscreen`
- `module.advanced.html`
- `module.advanced.text.text`
- `module.decoration.background`
- `module.meta.adminLabel`
- `scrollDown.decoration.icon`
- `title.decoration.font.font`

### `divi/fullwidth-image` — Fullwidth Image

- `image.advanced.lightbox`
- `image.advanced.overlay`
- `image.innerContent`
- `module.meta.adminLabel`

### `divi/fullwidth-map` — Fullwidth Map

- `map.advanced.mobileDragging`
- `map.advanced.mouseWheel`
- `map.innerContent`
- `module.decoration.sizing`
- `module.meta.adminLabel`

### `divi/fullwidth-menu` — Fullwidth Menu

- `cartIcon.advanced.show`
- `cartIcon.decoration.font.font`
- `cartQuantity.advanced.show`
- `hamburgerMenuIcon.decoration.font.font`
- `logo.innerContent`
- `menu.advanced.style`
- `menuDropdown.advanced.animation`
- `menuDropdown.advanced.direction`
- `module.advanced.text.text`
- `module.decoration.background`
- `module.meta.adminLabel`
- `searchIcon.advanced.show`
- `searchIcon.decoration.font.font`

### `divi/fullwidth-portfolio` — Post Carousel

- `module.advanced.autoRotate`
- `module.advanced.autoRotateSpeed`
- `module.advanced.text.text`
- `module.meta.adminLabel`
- `overlay.decoration.icon`
- `portfolio.advanced.layout`
- `portfolio.advanced.showDate`
- `portfolio.advanced.showTitle`
- `portfolio.decoration.font.font`
- `portfolio.innerContent`
- `portfolioGrid.advanced.flexType`
- `title.decoration.font.font`

### `divi/fullwidth-post-slider` — Fullwidth Post Slider

- `arrows.advanced.enable`
- `button.advanced.enable`
- `button.advanced.showOnMobile`
- `button.decoration.button`
- `button.innerContent`
- `content.advanced.showOnMobile`
- `image.advanced.enable`
- `image.advanced.placement`
- `meta.advanced.enable`
- `module.advanced.autoSpeed`
- `module.advanced.text.text`
- `module.decoration.background`
- `module.meta.adminLabel`
- `pagination.advanced.enable`
- `post.advanced.contentSource`
- `post.advanced.excerptLength`
- `post.advanced.excerptManual`
- `post.advanced.number`
- `post.advanced.offset`
- `post.advanced.orderby`
- `slideOverlay.advanced.use`
- `title.decoration.font.font`

### `divi/fullwidth-post-title` — Fullwidth Post Title

- `featuredImage.advanced.enabled`
- `featuredImage.advanced.forceFullwidth`
- `featuredImage.advanced.placement`
- `featuredImage.decoration.sizing`
- `meta.advanced.dateFormat`
- `meta.advanced.showAuthor`
- `meta.advanced.showCategories`
- `meta.advanced.showCommentsCount`
- `meta.advanced.showDate`
- `meta.advanced.showMeta`
- `module.advanced.text.text`
- `module.meta.adminLabel`
- `textWrapper.advanced.useBackground`
- `textWrapper.decoration.background`
- `title.advanced.showTitle`
- `title.decoration.font.font`

### `divi/fullwidth-slider` — Fullwidth Slider

- `arrows.advanced.show`
- `button.decoration.button`
- `children.button.advanced.showOnMobile`
- `children.content.advanced.showOnMobile`
- `module.advanced.autoSpeed`
- `module.meta.adminLabel`
- `pagination.advanced.show`
- `title.decoration.font.font`

### `divi/gallery` — Gallery

- `galleryGrid.decoration.layout`
- `image.advanced.galleryOrderby`
- `image.advanced.orientation`
- `module.advanced.autoSpeed`
- `module.advanced.fullwidth`
- `module.advanced.postsNumber`
- `module.advanced.showTitleAndCaption`
- `module.advanced.text.text`
- `module.meta.adminLabel`
- `pagination.advanced.showPagination`
- `title.decoration.font.font`

### `divi/group` — Group

- `module.meta.adminLabel`

### `divi/group-carousel` — Group Carousel

- `arrows.advanced.leftIcon`
- `arrows.advanced.rightIcon`
- `arrows.advanced.showArrows`
- `dotNav.advanced.showDots`
- `module.advanced.auto`
- `module.advanced.centerMode`
- `module.advanced.pauseOnHover`
- `module.advanced.slidesToScroll`
- `module.advanced.slidesToShow`
- `module.meta.adminLabel`

### `divi/heading` — Heading

- `module.meta.adminLabel`
- `title.decoration.font.font`

### `divi/icon` — Icon

- `icon.advanced.align`
- `icon.advanced.color`
- `icon.advanced.size`
- `icon.innerContent`
- `module.meta.adminLabel`

### `divi/icon-list` — Icon List

- `icon.advanced.color`
- `icon.advanced.size`
- `module.advanced.html`
- `module.meta.adminLabel`

### `divi/icon-list-item` — Icon List Item

- `content.innerContent`
- `icon.innerContent`
- `module.advanced.html`
- `module.meta.adminLabel`

### `divi/image` — Image

- `image.advanced.lightbox`
- `image.advanced.overlay`
- `image.innerContent`
- `module.advanced.forceFullwidth`
- `module.advanced.spacing`
- `module.meta.adminLabel`

### `divi/link` — Link

- `module.advanced.html`
- `module.decoration.layout`
- `module.decoration.spacing`
- `module.meta.adminLabel`

### `divi/login` — Login

- `button.decoration.button`
- `button.innerContent`
- `module.advanced.currentPageRedirect`
- `module.advanced.text.text`
- `module.decoration.background`
- `module.meta.adminLabel`
- `title.decoration.font.font`

### `divi/lottie` — Lottie

- `lottie.innerContent`
- `module.meta.adminLabel`

### `divi/map` — Map

- `map.advanced.grayscaleFilter`
- `map.advanced.mobileDragging`
- `map.advanced.mouseWheel`
- `map.innerContent`
- `module.decoration.sizing`
- `module.meta.adminLabel`

### `divi/map-pin` — Map Pin

- `pin.innerContent`

### `divi/menu` — Menu

- `cartIcon.advanced.show`
- `cartIcon.decoration.font.font`
- `cartQuantity.advanced.show`
- `hamburgerMenuIcon.decoration.font.font`
- `logo.innerContent`
- `menu.advanced.style`
- `menuDropdown.advanced.animation`
- `menuDropdown.advanced.direction`
- `module.advanced.text.text`
- `module.decoration.background`
- `module.meta.adminLabel`
- `searchIcon.advanced.show`
- `searchIcon.decoration.font.font`

### `divi/number-counter` — Number Counter

- `module.advanced.text.text`
- `module.meta.adminLabel`
- `number.advanced.enablePercentSign`
- `number.decoration.font.font`
- `number.innerContent`
- `title.decoration.font.font`

### `divi/portfolio` — Portfolio

- `module.advanced.text.text`
- `module.meta.adminLabel`
- `overlay.advanced.hoverIcon`
- `portfolio.advanced.layout`
- `portfolio.advanced.showCategories`
- `portfolio.advanced.showPagination`
- `portfolio.advanced.showTitle`
- `portfolio.innerContent`
- `portfolioGrid.advanced.flexType`
- `portfolioGrid.decoration.layout`
- `title.decoration.font.font`

### `divi/post-nav` — Pagination

- `links.advanced.showNext`
- `links.advanced.showPrev`
- `module.advanced.inSameTerm`
- `module.meta.adminLabel`

### `divi/post-slider` — Post Slider

- `arrows.advanced.enable`
- `button.advanced.enable`
- `button.advanced.showOnMobile`
- `button.decoration.button`
- `button.innerContent`
- `content.advanced.showOnMobile`
- `image.advanced.enable`
- `image.advanced.placement`
- `meta.advanced.enable`
- `module.advanced.autoSpeed`
- `module.advanced.text.text`
- `module.decoration.background`
- `module.meta.adminLabel`
- `pagination.advanced.enable`
- `post.advanced.contentSource`
- `post.advanced.excerptLength`
- `post.advanced.excerptManual`
- `post.advanced.number`
- `post.advanced.offset`
- `post.advanced.orderby`
- `slideOverlay.advanced.use`
- `title.decoration.font.font`

### `divi/post-title` — Post Title

- `image.advanced.enabled`
- `image.advanced.placement`
- `meta.advanced.dateFormat`
- `meta.advanced.showAuthor`
- `meta.advanced.showCategories`
- `meta.advanced.showCommentsCount`
- `meta.advanced.showDate`
- `meta.advanced.showMeta`
- `module.advanced.text.text`
- `module.meta.adminLabel`
- `textWrapper.advanced.useBackground`
- `textWrapper.decoration.background`
- `title.advanced.showTitle`
- `title.decoration.font.font`

### `divi/pricing-table` — Pricing Table

- `module.advanced.featured`
- `module.decoration.sizing`

### `divi/pricing-tables` — Pricing Tables

- `button.decoration.button`
- `content.advanced.showBullet`
- `featuredTable.advanced.showDropShadow`
- `module.meta.adminLabel`
- `title.decoration.font.font`

### `divi/row` — Row

- `module.meta.adminLabel`

### `divi/search` — Search

- `button.decoration.font.font`
- `module.advanced.text.text`
- `module.decoration.spacing`
- `module.meta.adminLabel`
- `search.advanced.excludePages`
- `search.advanced.excludePosts`
- `search.advanced.showButton`

### `divi/section` — Section

- `module.advanced.innerShadow`
- `module.meta.adminLabel`

### `divi/sidebar` — Sidebar

- `module.advanced.text.text`
- `module.meta.adminLabel`
- `sidebar.advanced.layout`
- `sidebar.innerContent`
- `sidebarWidgets.advanced.flexType`

### `divi/signup` — Email Optin

- `button.decoration.button`
- `button.innerContent`
- `content.decoration.bodyFont.body.font`
- `content.decoration.bodyFont.link.font`
- `content.decoration.bodyFont.ol.font`
- `content.decoration.bodyFont.quote.font`
- `content.decoration.bodyFont.ul.font`
- `customFields.advanced.enable`
- `field.advanced.emailFullwidth`
- `field.advanced.firstNameField`
- `field.advanced.firstNameFullwidth`
- `field.advanced.focusUseBorder`
- `field.advanced.ipAddress`
- `field.advanced.lastNameField`
- `field.advanced.lastNameFullwidth`
- `field.advanced.nameField`
- `field.advanced.nameFieldOnly`
- `field.advanced.nameFullwidth`
- `module.advanced.spamProtection`
- `module.advanced.text.text`
- `module.decoration.background`
- `module.meta.adminLabel`
- `resultMessage.decoration.font.font`
- `success.advanced.action`
- `success.advanced.message`
- `title.decoration.font.font`

### `divi/signup-custom-field` — Custom Field

- `fieldItem.advanced.fullwidth`
- `fieldItem.advanced.id`
- `fieldItem.advanced.required`
- `fieldItem.advanced.type`
- `fieldItem.innerContent`
- `module.advanced.html`

### `divi/slide` — Slide

- `button.decoration.button`
- `contentOverlay.decoration.border`
- `image.advanced.alignment`
- `module.advanced.text.text`
- `module.decoration.background`
- `module.meta.adminLabel`

### `divi/slider` — Slider

- `arrows.advanced.show`
- `button.decoration.button`
- `children.button.advanced.showOnMobile`
- `children.content.advanced.showOnMobile`
- `module.advanced.autoSpeed`
- `module.meta.adminLabel`
- `pagination.advanced.show`
- `title.decoration.font.font`

### `divi/social-media-follow` — Social Media Follow

- `icon.advanced.size`
- `module.advanced.html`
- `module.advanced.text.text`
- `module.decoration.background`
- `module.meta.adminLabel`
- `socialNetwork.advanced.followButton`

### `divi/social-media-follow-network` — Social Network

- `icon.advanced.size`
- `module.advanced.html`
- `socialNetwork.innerContent`

### `divi/svg` — SVG

- `module.meta.adminLabel`
- `svg.innerContent`

### `divi/tabs` — Tabs

- `module.meta.adminLabel`

### `divi/team-member` — Person

- `image.innerContent`
- `module.advanced.text.text`
- `module.meta.adminLabel`
- `name.decoration.font.font`
- `social.decoration.icon`

### `divi/testimonial` — Testimonial

- `company.innerContent`
- `module.advanced.text.text`
- `module.decoration.background`
- `module.meta.adminLabel`
- `quoteIcon.decoration.icon`

### `divi/text` — Text

- `module.advanced.text.text`

### `divi/timeline` — Timeline

- `module.advanced.timeline`
- `module.meta.adminLabel`

### `divi/timeline-item` — Timeline Item

- `module.meta.adminLabel`

### `divi/toggle` — Toggle

- `closedToggleIcon.decoration.icon`
- `module.advanced.open`
- `module.meta.adminLabel`
- `openToggleIcon.decoration.icon`
- `title.decoration.font.font`

### `divi/video` — Video

- `module.meta.adminLabel`
- `overlay.decoration.background`
- `playIcon.decoration.icon`

### `divi/video-slider` — Video Slider

- `module.meta.adminLabel`
- `overlay.advanced`
- `overlay.decoration.background`
- `playIcon.decoration.icon`
- `sliderControls.advanced`

### `divi/video-slider-item` — Video Slider Item

- `module.meta.adminLabel`
- `overlay.advanced`
- `playIcon.decoration.icon`
- `sliderControls.advanced`

### `divi/woocommerce-breadcrumb` — Woo Breadcrumbs

- `content.advanced.homeText`
- `content.advanced.homeUrl`
- `content.advanced.product`
- `content.advanced.separator`
- `module.meta.adminLabel`

### `divi/woocommerce-cart-notice` — Woo Notice

- `button.decoration.button`
- `content.advanced.pageType`
- `content.advanced.product`
- `field.advanced.width`
- `module.decoration.spacing`
- `module.meta.adminLabel`

### `divi/woocommerce-cart-products` — Woo Cart Products

- `button.decoration.button`
- `elements.advanced.showCouponCode`
- `elements.advanced.showProductImage`
- `elements.advanced.showRemoveItemIcon`
- `elements.advanced.showUpdateCartButton`
- `layout.advanced.rowLayout`
- `module.advanced.text.text`
- `module.meta.adminLabel`
- `table.advanced.collapseTableGuttersBorders`
- `table.advanced.horizontalGutterWidth`
- `table.advanced.verticalGutterWidth`

### `divi/woocommerce-cart-totals` — Woo Cart Totals

- `button.decoration.button`
- `module.meta.adminLabel`
- `table.advanced.collapseTableGuttersBorders`
- `table.advanced.horizontalGutterWidth`
- `table.advanced.verticalGutterWidth`

### `divi/woocommerce-checkout-billing` — Woo Checkout Billing

- `layout.advanced.fieldsWidth`
- `module.meta.adminLabel`

### `divi/woocommerce-checkout-additional-info` — Woo Checkout Information

- `elements.advanced.showTitle`
- `module.meta.adminLabel`

### `divi/woocommerce-checkout-order-details` — Woo Checkout Details

- `module.meta.adminLabel`
- `table.advanced.collapseTableGuttersBorders`
- `table.advanced.horizontalGutterWidth`
- `table.advanced.verticalGutterWidth`

### `divi/woocommerce-checkout-payment-info` — Woo Checkout Payment

- `button.decoration.button`
- `module.decoration.background`
- `module.decoration.spacing`
- `module.meta.adminLabel`

### `divi/woocommerce-checkout-shipping` — Woo Checkout Shipping

- `layout.advanced.fieldsWidth`
- `module.meta.adminLabel`

### `divi/woocommerce-cross-sells` — Woo Cross Sells

- `module.meta.adminLabel`

### `divi/woocommerce-product-add-to-cart` — Woo Product Add To Cart

- `button.decoration.button`
- `content.advanced.product`
- `elements.advanced.showQuantity`
- `elements.advanced.showStock`
- `fieldLabels.advanced.fieldLabelPosition`
- `module.advanced.text.text`
- `module.meta.adminLabel`

### `divi/woocommerce-product-additional-info` — Woo Product Information

- `content.advanced.product`
- `elements.advanced.showTitle`
- `module.meta.adminLabel`

### `divi/woocommerce-product-description` — Woo Product Description

- `content.advanced.descriptionType`
- `content.advanced.product`
- `module.meta.adminLabel`

### `divi/woocommerce-product-gallery` — Woo Product Gallery

- `content.advanced.postsNumber`
- `content.advanced.product`
- `content.advanced.showPagination`
- `content.advanced.showTitleAndCaption`
- `galleryGrid.decoration.layout`
- `image.advanced.galleryOrderby`
- `layout.advanced.fullwidth`
- `layout.advanced.orientation`
- `module.meta.adminLabel`
- `overlay.decoration.background`
- `overlayIcon.decoration.icon`
- `title.decoration.font.font`

### `divi/woocommerce-product-images` — Woo Product Images

- `content.advanced.product`
- `elements.advanced.showProductGallery`
- `elements.advanced.showProductImage`
- `elements.advanced.showSaleBadge`
- `galleryGrid.decoration.layout`
- `module.meta.adminLabel`

### `divi/woocommerce-product-meta` — Woo Product Meta

- `content.advanced.product`
- `content.advanced.separator`
- `elements.advanced.showCategories`
- `elements.advanced.showSku`
- `elements.advanced.showTags`
- `layout.advanced.metaLayout`
- `module.meta.adminLabel`

### `divi/woocommerce-product-price` — Woo Product Price

- `content.advanced.product`
- `module.meta.adminLabel`

### `divi/woocommerce-product-rating` — Woo Product Rating

- `content.advanced.product`
- `elements.advanced.showRating`
- `elements.advanced.showReviewsLink`
- `layout.advanced.layout`
- `module.meta.adminLabel`

### `divi/woocommerce-product-reviews` — Woo Product Reviews

- `button.decoration.button`
- `content.advanced.product`
- `elements.advanced.showAvatar`
- `elements.advanced.showCount`
- `elements.advanced.showMeta`
- `elements.advanced.showRating`
- `elements.advanced.showReply`
- `module.advanced.text.text`
- `module.meta.adminLabel`
- `reviewCount.decoration.font.font`

### `divi/woocommerce-product-stock` — Woo Product Stock

- `content.advanced.product`
- `module.meta.adminLabel`

### `divi/woocommerce-product-tabs` — Woo Product Tabs

- `content.advanced.includeTabs`
- `content.advanced.product`
- `module.meta.adminLabel`

### `divi/woocommerce-product-title` — Woo Product Title

- `content.advanced.product`
- `module.advanced.text.text`
- `module.meta.adminLabel`
- `title.decoration.font.font`

### `divi/woocommerce-product-upsell` — Woo Product Upsell

- `content.advanced.columnsNumber`
- `content.advanced.offsetNumber`
- `content.advanced.orderby`
- `content.advanced.postsNumber`
- `content.advanced.product`
- `elements.advanced.showImage`
- `elements.advanced.showName`
- `elements.advanced.showPrice`
- `elements.advanced.showRating`
- `elements.advanced.showSaleBadge`
- `module.meta.adminLabel`

### `divi/shop` — Woo Products

- `content.advanced.columnsNumber`
- `content.advanced.offsetNumber`
- `content.advanced.orderby`
- `content.advanced.postsNumber`
- `content.advanced.type`
- `content.advanced.useCurrentLoop`
- `elements.advanced.showImage`
- `elements.advanced.showName`
- `elements.advanced.showPagination`
- `elements.advanced.showPrice`
- `elements.advanced.showRating`
- `elements.advanced.showSaleBadge`
- `module.meta.adminLabel`

### `divi/woocommerce-related-products` — Woo Related Products

- `content.advanced.columnsNumber`
- `content.advanced.offsetNumber`
- `content.advanced.orderby`
- `content.advanced.postsNumber`
- `content.advanced.product`
- `elements.advanced.showImage`
- `elements.advanced.showName`
- `elements.advanced.showPrice`
- `elements.advanced.showRating`
- `elements.advanced.showSaleBadge`
- `module.meta.adminLabel`
