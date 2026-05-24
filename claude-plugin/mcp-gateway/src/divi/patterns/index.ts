/**
 * Library of parameterized Divi 5 patterns.
 *
 * Each pattern accepts high-level options (texts, images, gcid-* colors) and
 * produces a GutenbergBlock ready to be included in a Divi 5 page via
 * `iawm_divi_page_write` (the `blocks` parameter).
 *
 * Usage:
 *   import { placeholder } from "../builders.js";
 *   import { hero, features3col, ctaBanner } from "./patterns/index.js";
 *
 *   const page = placeholder([
 *     hero({ title, subtitle, ctaText, ctaUrl }),
 *     features3col({ items: [...] }),
 *     ctaBanner({ title, contentHtml, buttonText, buttonUrl }),
 *   ]);
 *
 * Patterns available (initial Phase 3.4):
 *   - hero
 *   - features3col
 *   - ctaBanner
 *   - imageTextSplit
 *
 * Coming once advanced modules are documented (page 29):
 *   - testimonials (testimonial module)
 *   - faq-accordion (accordion module)
 *   - pricing-3col (button + features list)
 *   - numbers-bar (number-counter)
 *   - team-grid (team module or person blurb)
 *   - gallery-grid (gallery)
 *   - video-section (video)
 *   - contact-form (form)
 */

export { hero } from "./hero.js";
export type { HeroOptions } from "./hero.js";

export { features3col } from "./features-3col.js";
export type { Features3ColOptions, FeatureItem } from "./features-3col.js";

export { ctaBanner } from "./cta-banner.js";
export type { CtaBannerOptions } from "./cta-banner.js";

export { imageTextSplit } from "./image-text-split.js";
export type { ImageTextSplitOptions } from "./image-text-split.js";

export { testimonials } from "./testimonials.js";
export type { TestimonialsOptions, TestimonialItem } from "./testimonials.js";

export { faqAccordion } from "./faq-accordion.js";
export type { FaqAccordionOptions, FaqItem } from "./faq-accordion.js";

export { numbersBar } from "./numbers-bar.js";
export type { NumbersBarOptions, NumberItem } from "./numbers-bar.js";

export { videoSection } from "./video-section.js";
export type { VideoSectionOptions } from "./video-section.js";

export { contactSection } from "./contact-section.js";
export type { ContactSectionOptions } from "./contact-section.js";

export { pricing3col } from "./pricing-3col.js";
export type { Pricing3ColOptions, PricingPlan } from "./pricing-3col.js";

export { teamGrid } from "./team-grid.js";
export type { TeamGridOptions } from "./team-grid.js";

export { headerSimple } from "./header-simple.js";
export type { HeaderSimpleOptions } from "./header-simple.js";

export { footerStandard } from "./footer-standard.js";
export type { FooterStandardOptions, FooterColumn } from "./footer-standard.js";
