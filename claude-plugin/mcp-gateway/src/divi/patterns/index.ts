/**
 * Bibliothèque de patterns Divi 5 paramétrables.
 *
 * Chaque pattern accepte des options haut niveau (textes, images,
 * couleurs gcid-*) et produit un GutenbergBlock prêt à être inclus
 * dans une page Divi 5 via `iawm_divi_page_write` (param `blocks`).
 *
 * Usage :
 *   import { placeholder } from "../builders.js";
 *   import { hero, features3col, ctaBanner } from "./patterns/index.js";
 *
 *   const page = placeholder([
 *     hero({ title, subtitle, ctaText, ctaUrl }),
 *     features3col({ items: [...] }),
 *     ctaBanner({ title, contentHtml, buttonText, buttonUrl }),
 *   ]);
 *
 * Patterns disponibles (Phase 3.4 initiale) :
 *   - hero
 *   - features3col
 *   - ctaBanner
 *   - imageTextSplit
 *
 * À venir une fois les modules avancés documentés (page 29) :
 *   - testimonials (module testimonial)
 *   - faq-accordion (module accordion)
 *   - pricing-3col (button + features list)
 *   - numbers-bar (number-counter)
 *   - team-grid (team module ou blurb personne)
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
