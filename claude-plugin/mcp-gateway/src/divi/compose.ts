/**
 * Unified Divi 5 composer — accepts 3 description modes in parallel:
 *
 *   1. Pattern (shortcut for standard cases)
 *      { pattern: "hero", options: { title, subtitle, ctaText, ctaUrl } }
 *
 *   2. Free-form section (improvise from base modules)
 *      { section: { background?, padding?, rows: [
 *          { structure: "1_2,1_2", columns: [
 *              [{ module: "text", html: "..." }, { module: "button", ... }],
 *              [{ module: "image", src: "..." }],
 *          ] }
 *      ] } }
 *
 *   3. Raw block (escape hatch — already-composed Divi JSON)
 *      { block: { blockName: "divi/...", attrs: {...}, innerBlocks: [...] } }
 *
 * The 3 modes coexist in the same page. The composer dispatches each section
 * to the right builder and wraps everything in a `divi/placeholder`.
 */

import {
  section, row, column,
  text, blurb, cta, image, button, heading,
  numberCounter, testimonial, gallery, video, code,
  divider, icon, toggle, teamMember, signup, map,
  circleCounter, audio,
  accordion, tabs, slider, contactForm,
  pricingTables, iconList, socialMediaFollow, counters,
  menu, fullwidthMenu, search, breadcrumbs,
  postTitle, postContent, postNavigation, comments,
  placeholder,
  // Phase 9 native builders
  fullwidthHeader, fullwidthImage, fullwidthSlider, fullwidthMap,
  group, groupCarousel, rowInner, columnInner,
  blog, portfolio, filterablePortfolio, postSlider,
  beforeAfter, timeline, lottie, svg, countdown,
  sidebar, login, dropdown, signupCustomField,
  // Phase 9 WooCommerce builders
  wcProductTitle, wcProductPrice, wcProductImages, wcProductAddToCart,
  wcProductDescription, wcProductTabs, wcRelatedProducts,
  wcCartProducts, wcCartTotals, wcCheckoutBilling,
} from "./builders.js";
import type {
  TextOptions, BlurbOptions, CtaOptions, ImageOptions, ButtonOptions,
  HeadingOptions, NumberCounterOptions, TestimonialOptions, GalleryOptions,
  VideoOptions, CodeOptions, DividerOptions, IconOptions, ToggleOptions,
  TeamMemberOptions, SignupOptions, MapOptions, CircleCounterOptions,
  AudioOptions, AccordionItemOptions, TabOptions, SlideOptions,
  ContactFieldOptions, ContactFormOptions, PricingTableOptions,
  IconListItemOptions, SocialNetworkOptions, CounterItemOptions,
  CountersOptions, MenuOptions, SearchOptions, BreadcrumbsOptions,
  PostTitleOptions, PostNavigationOptions,
  SectionOptions, RowOptions,
  // Phase 9 — native module option types
  FullwidthHeaderOptions, FullwidthImageOptions, FullwidthSliderOptions,
  FullwidthMapOptions, GroupOptions, GroupCarouselOptions, ColumnInnerOptions,
  BlogOptions, PortfolioOptions, FilterablePortfolioOptions, PostSliderOptions,
  BeforeAfterOptions, TimelineEntry, LottieOptions, SvgOptions, CountdownOptions,
  SidebarOptions, LoginOptions, DropdownOptions, SignupCustomFieldOptions,
  // Phase 9 — WC option types
  WcProductTitleOptions, WcProductPriceOptions, WcProductImagesOptions,
  WcProductAddToCartOptions, WcProductDescriptionOptions, WcProductTabsOptions,
  WcRelatedProductsOptions, WcCartProductsOptions, WcCartTotalsOptions,
  WcCheckoutBillingOptions,
} from "./builders.js";
import type { GutenbergBlock, DiviColor, Spacing, ColumnStructure } from "./types.js";

import {
  hero, features3col, ctaBanner, imageTextSplit,
  testimonials, faqAccordion, numbersBar, videoSection, contactSection,
  pricing3col, teamGrid, headerSimple, footerStandard,
} from "./patterns/index.js";
import type {
  HeroOptions, Features3ColOptions, CtaBannerOptions, ImageTextSplitOptions,
  TestimonialsOptions, FaqAccordionOptions, NumbersBarOptions,
  VideoSectionOptions, ContactSectionOptions, Pricing3ColOptions,
  TeamGridOptions, HeaderSimpleOptions, FooterStandardOptions,
} from "./patterns/index.js";

/* ------------------------------------------------------------------ */
/* Composer input types                                               */
/* ------------------------------------------------------------------ */

/** A leaf / composite module described declaratively. */
export type ModuleInput =
  // Base content modules
  | ({ module: "text" } & TextOptions)
  | ({ module: "blurb" } & BlurbOptions)
  | ({ module: "cta" } & CtaOptions)
  | ({ module: "image" } & ImageOptions)
  | ({ module: "button" } & ButtonOptions)
  | ({ module: "heading" } & HeadingOptions)
  // Stats and numbers
  | ({ module: "number-counter" } & NumberCounterOptions)
  | ({ module: "circle-counter" } & CircleCounterOptions)
  // Person, testimonial
  | ({ module: "testimonial" } & TestimonialOptions)
  | ({ module: "team-member" } & TeamMemberOptions)
  // Media
  | ({ module: "gallery" } & GalleryOptions)
  | ({ module: "video" } & VideoOptions)
  | ({ module: "audio" } & AudioOptions)
  | ({ module: "code" } & CodeOptions)
  // Layout
  | ({ module: "divider" } & DividerOptions)
  | ({ module: "icon" } & IconOptions)
  | ({ module: "toggle" } & ToggleOptions)
  // Forms / signup
  | ({ module: "signup" } & SignupOptions)
  | ({ module: "map" } & MapOptions)
  // Navigation and theme builder
  | ({ module: "menu" } & MenuOptions)
  | ({ module: "fullwidth-menu" } & MenuOptions)
  | ({ module: "search" } & SearchOptions)
  | ({ module: "breadcrumbs" } & BreadcrumbsOptions)
  | ({ module: "post-title" } & PostTitleOptions)
  | { module: "post-content" }
  | ({ module: "post-navigation" } & PostNavigationOptions)
  | { module: "comments" }
  // Composite (nested)
  | { module: "accordion"; items: AccordionItemOptions[] }
  | { module: "tabs"; items: TabOptions[] }
  | { module: "slider"; items: SlideOptions[] }
  | ({ module: "contact-form" } & ContactFormOptions)
  | { module: "pricing-tables"; items: PricingTableOptions[] }
  | { module: "icon-list"; items: IconListItemOptions[] }
  | { module: "social-media-follow"; networks: SocialNetworkOptions[] }
  | ({ module: "counters" } & CountersOptions)
  // Phase 9 — native modules
  | ({ module: "fullwidth-header" } & FullwidthHeaderOptions)
  | ({ module: "fullwidth-image" } & FullwidthImageOptions)
  | ({ module: "fullwidth-slider" } & FullwidthSliderOptions & { slides: SlideOptions[] })
  | ({ module: "fullwidth-map" } & FullwidthMapOptions)
  | ({ module: "group" } & GroupOptions & { modules: ModuleInput[] })
  | ({ module: "group-carousel" } & GroupCarouselOptions & { groups: ({ module: "group" } & GroupOptions & { modules: ModuleInput[] })[] })
  | ({ module: "row-inner"; columns: ({ type?: string; modules: ModuleInput[] })[] } & RowOptions)
  | ({ module: "column-inner" } & ColumnInnerOptions & { modules: ModuleInput[] })
  | ({ module: "blog" } & BlogOptions)
  | ({ module: "portfolio" } & PortfolioOptions)
  | ({ module: "filterable-portfolio" } & FilterablePortfolioOptions)
  | ({ module: "post-slider" } & PostSliderOptions)
  | ({ module: "before-after" } & BeforeAfterOptions)
  | { module: "timeline"; items: TimelineEntry[] }
  | ({ module: "lottie" } & LottieOptions)
  | ({ module: "svg" } & SvgOptions)
  | ({ module: "countdown" } & CountdownOptions)
  | ({ module: "sidebar" } & SidebarOptions)
  | ({ module: "login" } & LoginOptions)
  | ({ module: "dropdown" } & DropdownOptions)
  | ({ module: "signup-custom-field" } & SignupCustomFieldOptions)
  // Phase 9 — WooCommerce modules
  | ({ module: "wc-product-title" } & WcProductTitleOptions)
  | ({ module: "wc-product-price" } & WcProductPriceOptions)
  | ({ module: "wc-product-images" } & WcProductImagesOptions)
  | ({ module: "wc-product-add-to-cart" } & WcProductAddToCartOptions)
  | ({ module: "wc-product-description" } & WcProductDescriptionOptions)
  | ({ module: "wc-product-tabs" } & WcProductTabsOptions)
  | ({ module: "wc-related-products" } & WcRelatedProductsOptions)
  | ({ module: "wc-cart-products" } & WcCartProductsOptions)
  | ({ module: "wc-cart-totals" } & WcCartTotalsOptions)
  | ({ module: "wc-checkout-billing" } & WcCheckoutBillingOptions)
  // Escape: raw Gutenberg block
  | { module: "block"; block: GutenbergBlock };

/** A column in free-form mode: either a direct module list, or an enriched object. */
export type ColumnInput =
  | ModuleInput[]
  | {
      /** `a_b` notation (e.g. "1_3"). If omitted, inferred from the parent row. */
      type?: string;
      /** Full-width on mobile (default true except 4_4). */
      fullWidthOnMobile?: boolean;
      modules: ModuleInput[];
    };

/** A free-form row. */
export interface RowInput {
  /** Column structure (e.g. "1_3,1_3,1_3"). */
  structure: ColumnStructure;
  /** Stack columns on mobile (default true). */
  wrapMobile?: boolean;
  /** Row spacing. */
  spacing?: { padding?: Spacing; margin?: Spacing };
  /** Column list (as many as the structure). */
  columns: ColumnInput[];
}

/** A free-form section. */
export interface FreeFormSection {
  /** Background color (hex or global reference). */
  background?: { color?: DiviColor; imageUrl?: string };
  /** Section spacing. */
  spacing?: { padding?: Spacing; margin?: Spacing };
  /** Section rows. */
  rows: RowInput[];
}

/** A section item for `compose` — 3 possible modes. */
export type SectionInput =
  // Mode 1: ready-made pattern
  | { pattern: "hero"; options: HeroOptions }
  | { pattern: "features3col"; options: Features3ColOptions }
  | { pattern: "ctaBanner"; options: CtaBannerOptions }
  | { pattern: "imageTextSplit"; options: ImageTextSplitOptions }
  | { pattern: "testimonials"; options: TestimonialsOptions }
  | { pattern: "faqAccordion"; options: FaqAccordionOptions }
  | { pattern: "numbersBar"; options: NumbersBarOptions }
  | { pattern: "videoSection"; options: VideoSectionOptions }
  | { pattern: "contactSection"; options: ContactSectionOptions }
  | { pattern: "pricing3col"; options: Pricing3ColOptions }
  | { pattern: "teamGrid"; options: TeamGridOptions }
  | { pattern: "headerSimple"; options: HeaderSimpleOptions }
  | { pattern: "footerStandard"; options: FooterStandardOptions }
  // Mode 2: free-form
  | { section: FreeFormSection }
  // Mode 3: escape hatch
  | { block: GutenbergBlock };

/* ------------------------------------------------------------------ */
/* Dispatcher: module → builder                                       */
/* ------------------------------------------------------------------ */

/**
 * Builds a GutenbergBlock from a declarative ModuleInput.
 *
 * @param input  Module description.
 * @returns      Gutenberg block ready to include.
 */
export function composeModule(input: ModuleInput): GutenbergBlock {
  switch (input.module) {
    case "text":            return text(input);
    case "blurb":           return blurb(input);
    case "cta":             return cta(input);
    case "image":           return image(input);
    case "button":          return button(input);
    case "heading":         return heading(input);
    case "number-counter":  return numberCounter(input);
    case "circle-counter":  return circleCounter(input);
    case "testimonial":     return testimonial(input);
    case "team-member":     return teamMember(input);
    case "gallery":         return gallery(input);
    case "video":           return video(input);
    case "audio":           return audio(input);
    case "code":            return code(input);
    case "divider":         return divider(input);
    case "icon":            return icon(input);
    case "toggle":          return toggle(input);
    case "signup":          return signup(input);
    case "map":             return map(input);
    case "menu":            return menu(input);
    case "fullwidth-menu":  return fullwidthMenu(input);
    case "search":          return search(input);
    case "breadcrumbs":     return breadcrumbs(input);
    case "post-title":      return postTitle(input);
    case "post-content":    return postContent();
    case "post-navigation": return postNavigation(input);
    case "comments":        return comments();
    case "accordion":       return accordion(input.items);
    case "tabs":            return tabs(input.items);
    case "slider":          return slider(input.items);
    case "contact-form":    return contactForm(input);
    case "pricing-tables":  return pricingTables(input.items);
    case "icon-list":       return iconList(input.items);
    case "social-media-follow": return socialMediaFollow(input.networks);
    case "counters":        return counters(input);
    // Phase 9 — native modules
    case "fullwidth-header":     return fullwidthHeader(input);
    case "fullwidth-image":      return fullwidthImage(input);
    case "fullwidth-slider":     return fullwidthSlider(input, input.slides.map((s) => slider([s]).innerBlocks[0]));
    case "fullwidth-map":        return fullwidthMap(input);
    case "group":                return group(input, input.modules.map(composeModule));
    case "group-carousel":       return groupCarousel(input, input.groups.map((g) => group(g, g.modules.map(composeModule))));
    case "row-inner":            return rowInner(input, input.columns.map((c) => columnInner({ type: c.type ?? "4_4" }, c.modules.map(composeModule))));
    case "column-inner":         return columnInner(input, input.modules.map(composeModule));
    case "blog":                 return blog(input);
    case "portfolio":            return portfolio(input);
    case "filterable-portfolio": return filterablePortfolio(input);
    case "post-slider":          return postSlider(input);
    case "before-after":         return beforeAfter(input);
    case "timeline":             return timeline(input.items);
    case "lottie":               return lottie(input);
    case "svg":                  return svg(input);
    case "countdown":            return countdown(input);
    case "sidebar":              return sidebar(input);
    case "login":                return login(input);
    case "dropdown":             return dropdown(input);
    case "signup-custom-field":  return signupCustomField(input);
    // Phase 9 — WooCommerce modules
    case "wc-product-title":        return wcProductTitle(input);
    case "wc-product-price":        return wcProductPrice(input);
    case "wc-product-images":       return wcProductImages(input);
    case "wc-product-add-to-cart":  return wcProductAddToCart(input);
    case "wc-product-description":  return wcProductDescription(input);
    case "wc-product-tabs":         return wcProductTabs(input);
    case "wc-related-products":     return wcRelatedProducts(input);
    case "wc-cart-products":        return wcCartProducts(input);
    case "wc-cart-totals":          return wcCartTotals(input);
    case "wc-checkout-billing":     return wcCheckoutBilling(input);
    case "block":           return input.block;
    default: {
      // Exhaustive check — TS detects unhandled cases.
      const _exhaust: never = input;
      throw new Error(`Unknown module: ${JSON.stringify(_exhaust)}`);
    }
  }
}

/* ------------------------------------------------------------------ */
/* Free-form section composition                                      */
/* ------------------------------------------------------------------ */

/**
 * Builds a section GutenbergBlock from a FreeFormSection.
 */
function composeFreeFormSection(input: FreeFormSection): GutenbergBlock {
  // Section options.
  const sectionOpts: SectionOptions = {};
  if (input.background?.color) sectionOpts.backgroundColor = input.background.color;
  if (input.background?.imageUrl) sectionOpts.backgroundImageUrl = input.background.imageUrl;
  if (input.spacing) sectionOpts.spacing = input.spacing;

  // Rows.
  const rows: GutenbergBlock[] = input.rows.map((rowInput) => {
    // Derive column types from the structure.
    const colTypes = rowInput.structure.split(",");
    const cols: GutenbergBlock[] = rowInput.columns.map((colInput, i) => {
      // Normalize: module list → {modules: list} object.
      const norm = Array.isArray(colInput) ? { modules: colInput } : colInput;
      const colType = norm.type ?? colTypes[i] ?? "4_4";
      const modules: GutenbergBlock[] = norm.modules.map((m) => composeModule(m));
      return column(
        { type: colType, fullWidthOnMobile: norm.fullWidthOnMobile },
        modules,
      );
    });

    const rowOpts: RowOptions = {
      columnStructure: rowInput.structure,
      flexWrapMobile: rowInput.wrapMobile === false ? "nowrap" : "wrap",
    };
    if (rowInput.spacing) rowOpts.spacing = rowInput.spacing;
    return row(rowOpts, cols);
  });

  return section(sectionOpts, rows);
}

/* ------------------------------------------------------------------ */
/* Section composition (3 modes)                                      */
/* ------------------------------------------------------------------ */

/**
 * Dispatches a SectionInput to the right builder according to its mode.
 *
 * @param input  Section description (pattern / section / block).
 * @returns      Section Gutenberg block ready to include.
 */
export function composeSection(input: SectionInput): GutenbergBlock {
  // Mode 3: raw block.
  if ("block" in input) {
    return input.block;
  }
  // Mode 2: free-form.
  if ("section" in input) {
    return composeFreeFormSection(input.section);
  }
  // Mode 1: pattern.
  switch (input.pattern) {
    case "hero":            return hero(input.options);
    case "features3col":    return features3col(input.options);
    case "ctaBanner":       return ctaBanner(input.options);
    case "imageTextSplit":  return imageTextSplit(input.options);
    case "testimonials":    return testimonials(input.options);
    case "faqAccordion":    return faqAccordion(input.options);
    case "numbersBar":      return numbersBar(input.options);
    case "videoSection":    return videoSection(input.options);
    case "contactSection":  return contactSection(input.options);
    case "pricing3col":     return pricing3col(input.options);
    case "teamGrid":        return teamGrid(input.options);
    case "headerSimple":    return headerSimple(input.options);
    case "footerStandard":  return footerStandard(input.options);
    default: {
      const _exhaust: never = input;
      throw new Error(`Unknown pattern: ${JSON.stringify(_exhaust)}`);
    }
  }
}

/* ------------------------------------------------------------------ */
/* Full page composition                                              */
/* ------------------------------------------------------------------ */

/**
 * Composes a full Divi 5 page from a list of sections.
 *
 * Each section can be a pattern, free-form, or a raw block. All sections are
 * assembled under a root `divi/placeholder` wrapper.
 *
 * @param sections  Mixed list of SectionInput.
 * @returns         Root Gutenberg block ready to be written via
 *                  iawm_divi_page_write.
 */
export function composePage(sections: SectionInput[]): GutenbergBlock {
  const sectionBlocks = sections.map((s) => composeSection(s));
  return placeholder(sectionBlocks);
}

/**
 * Composes a Theme Builder layout (header / footer / body) from a list of
 * sections. Same grammar as composePage: root placeholder wrapper.
 */
export function composeThemeZone(sections: SectionInput[]): GutenbergBlock {
  return composePage(sections);
}
