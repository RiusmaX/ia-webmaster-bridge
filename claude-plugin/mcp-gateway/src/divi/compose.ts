/**
 * Composeur Divi 5 unifié — accepte 3 modes de description en parallèle :
 *
 *   1. Pattern (raccourci pour cas standards)
 *      { pattern: "hero", options: { title, subtitle, ctaText, ctaUrl } }
 *
 *   2. Section free-form (improvisation à partir des modules de base)
 *      { section: { background?, padding?, rows: [
 *          { structure: "1_2,1_2", columns: [
 *              [{ module: "text", html: "..." }, { module: "button", ... }],
 *              [{ module: "image", src: "..." }],
 *          ] }
 *      ] } }
 *
 *   3. Block brut (escape hatch — JSON Divi déjà composé)
 *      { block: { blockName: "divi/...", attrs: {...}, innerBlocks: [...] } }
 *
 * Les 3 modes cohabitent dans la même page. Le composeur dispatche chaque
 * section vers le bon builder et wrap le tout dans un `divi/placeholder`.
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
/* Types d'entrée du composeur                                        */
/* ------------------------------------------------------------------ */

/** Un module feuille / composé décrit déclarativement. */
export type ModuleInput =
  // Modules de contenu de base
  | ({ module: "text" } & TextOptions)
  | ({ module: "blurb" } & BlurbOptions)
  | ({ module: "cta" } & CtaOptions)
  | ({ module: "image" } & ImageOptions)
  | ({ module: "button" } & ButtonOptions)
  | ({ module: "heading" } & HeadingOptions)
  // Statistiques et chiffres
  | ({ module: "number-counter" } & NumberCounterOptions)
  | ({ module: "circle-counter" } & CircleCounterOptions)
  // Personne, témoignage
  | ({ module: "testimonial" } & TestimonialOptions)
  | ({ module: "team-member" } & TeamMemberOptions)
  // Médias
  | ({ module: "gallery" } & GalleryOptions)
  | ({ module: "video" } & VideoOptions)
  | ({ module: "audio" } & AudioOptions)
  | ({ module: "code" } & CodeOptions)
  // Mise en page
  | ({ module: "divider" } & DividerOptions)
  | ({ module: "icon" } & IconOptions)
  | ({ module: "toggle" } & ToggleOptions)
  // Forms / inscription
  | ({ module: "signup" } & SignupOptions)
  | ({ module: "map" } & MapOptions)
  // Navigation et theme builder
  | ({ module: "menu" } & MenuOptions)
  | ({ module: "fullwidth-menu" } & MenuOptions)
  | ({ module: "search" } & SearchOptions)
  | ({ module: "breadcrumbs" } & BreadcrumbsOptions)
  | ({ module: "post-title" } & PostTitleOptions)
  | { module: "post-content" }
  | ({ module: "post-navigation" } & PostNavigationOptions)
  | { module: "comments" }
  // Composés (nested)
  | { module: "accordion"; items: AccordionItemOptions[] }
  | { module: "tabs"; items: TabOptions[] }
  | { module: "slider"; items: SlideOptions[] }
  | ({ module: "contact-form" } & ContactFormOptions)
  | { module: "pricing-tables"; items: PricingTableOptions[] }
  | { module: "icon-list"; items: IconListItemOptions[] }
  | { module: "social-media-follow"; networks: SocialNetworkOptions[] }
  | ({ module: "counters" } & CountersOptions)
  // Escape : bloc Gutenberg brut
  | { module: "block"; block: GutenbergBlock };

/** Une colonne en mode free-form : soit liste directe de modules, soit objet enrichi. */
export type ColumnInput =
  | ModuleInput[]
  | {
      /** Notation `a_b` (ex. "1_3"). Si omis, déduit du parent row. */
      type?: string;
      /** Plein largeur sur mobile (défaut true sauf 4_4). */
      fullWidthOnMobile?: boolean;
      modules: ModuleInput[];
    };

/** Une row free-form. */
export interface RowInput {
  /** Structure de colonnes (ex. "1_3,1_3,1_3"). */
  structure: ColumnStructure;
  /** Wrap des colonnes en pile sur mobile (défaut true). */
  wrapMobile?: boolean;
  /** Espacement de la row. */
  spacing?: { padding?: Spacing; margin?: Spacing };
  /** Liste des colonnes (autant que la structure). */
  columns: ColumnInput[];
}

/** Une section free-form. */
export interface FreeFormSection {
  /** Couleur de fond (hex ou référence globale). */
  background?: { color?: DiviColor; imageUrl?: string };
  /** Espacement de la section. */
  spacing?: { padding?: Spacing; margin?: Spacing };
  /** Rows de la section. */
  rows: RowInput[];
}

/** Un item de section pour `compose` — 3 modes possibles. */
export type SectionInput =
  // Mode 1 : pattern prêt
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
  // Mode 2 : free-form
  | { section: FreeFormSection }
  // Mode 3 : escape hatch
  | { block: GutenbergBlock };

/* ------------------------------------------------------------------ */
/* Dispatcher : module → builder                                      */
/* ------------------------------------------------------------------ */

/**
 * Construit un GutenbergBlock à partir d'un ModuleInput déclaratif.
 *
 * @param input  Description du module.
 * @returns      Bloc Gutenberg prêt à inclure.
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
    case "block":           return input.block;
    default: {
      // Vérification exhaustive — TS détecte les cas non gérés.
      const _exhaust: never = input;
      throw new Error(`Module inconnu : ${JSON.stringify(_exhaust)}`);
    }
  }
}

/* ------------------------------------------------------------------ */
/* Composition d'une section free-form                                */
/* ------------------------------------------------------------------ */

/**
 * Construit un GutenbergBlock section à partir d'une FreeFormSection.
 */
function composeFreeFormSection(input: FreeFormSection): GutenbergBlock {
  // Options de section.
  const sectionOpts: SectionOptions = {};
  if (input.background?.color) sectionOpts.backgroundColor = input.background.color;
  if (input.background?.imageUrl) sectionOpts.backgroundImageUrl = input.background.imageUrl;
  if (input.spacing) sectionOpts.spacing = input.spacing;

  // Rows.
  const rows: GutenbergBlock[] = input.rows.map((rowInput) => {
    // Déduire les types des colonnes depuis la structure.
    const colTypes = rowInput.structure.split(",");
    const cols: GutenbergBlock[] = rowInput.columns.map((colInput, i) => {
      // Normaliser : list de modules → objet {modules: list}.
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
/* Composition d'une section (3 modes)                                */
/* ------------------------------------------------------------------ */

/**
 * Dispatche une SectionInput vers le bon builder selon son mode.
 *
 * @param input  Description de la section (pattern / section / block).
 * @returns      Bloc Gutenberg section prêt à inclure.
 */
export function composeSection(input: SectionInput): GutenbergBlock {
  // Mode 3 : block brut.
  if ("block" in input) {
    return input.block;
  }
  // Mode 2 : free-form.
  if ("section" in input) {
    return composeFreeFormSection(input.section);
  }
  // Mode 1 : pattern.
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
      throw new Error(`Pattern inconnu : ${JSON.stringify(_exhaust)}`);
    }
  }
}

/* ------------------------------------------------------------------ */
/* Composition de page complète                                       */
/* ------------------------------------------------------------------ */

/**
 * Compose une page Divi 5 complète à partir d'une liste de sections.
 *
 * Chaque section peut être un pattern, du free-form, ou un block brut.
 * Toutes les sections sont assemblées sous un wrapper racine
 * `divi/placeholder`.
 *
 * @param sections  Liste mixte de SectionInput.
 * @returns         Bloc Gutenberg racine prêt à être écrit via
 *                  iawm_divi_page_write.
 */
export function composePage(sections: SectionInput[]): GutenbergBlock {
  const sectionBlocks = sections.map((s) => composeSection(s));
  return placeholder(sectionBlocks);
}

/**
 * Compose un layout Theme Builder (header / footer / body) à partir
 * d'une liste de sections. Même grammaire que composePage : wrapper
 * placeholder racine.
 */
export function composeThemeZone(sections: SectionInput[]): GutenbergBlock {
  return composePage(sections);
}
