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
