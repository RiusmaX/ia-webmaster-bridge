/**
 * Types Divi 5 — basés sur la rétro-ingénierie documentée dans
 * docs/divi5-format.md.
 */

/** Breakpoint Divi 5. */
export type Breakpoint = "desktop" | "tablet" | "phoneWide" | "phone";

/** Une valeur indexée par breakpoint : { desktop: { value: T }, ... }. */
export type Responsive<T> = Partial<Record<Breakpoint, { value: T }>>;

/** Bloc Gutenberg compatible avec parse_blocks / serialize_blocks de WP. */
export interface GutenbergBlock {
  blockName: string;
  attrs: Record<string, unknown>;
  innerBlocks: GutenbergBlock[];
  innerHTML: string;
  innerContent: (string | null)[];
}

/** Référence à une couleur globale Divi (gcid-*). */
export type GlobalColorName =
  | "gcid-primary-color"
  | "gcid-secondary-color"
  | "gcid-heading-color"
  | "gcid-body-color"
  | "gcid-link-color"
  | string; // tolérant pour les couleurs custom

/** Une couleur Divi : soit hex, soit référence globale. */
export type DiviColor = `#${string}` | { gcid: GlobalColorName };

/** Espacement (padding ou margin) avec sync optionnels. */
export interface Spacing {
  top?: string;
  right?: string;
  bottom?: string;
  left?: string;
  syncVertical?: "on" | "off";
  syncHorizontal?: "on" | "off";
}

/** Structure de colonnes d'une row Divi (notation "a_b,c_d,..."). */
export type ColumnStructure =
  | "4_4"               // 1 colonne pleine largeur
  | "1_2,1_2"           // 2 colonnes 50/50
  | "1_3,2_3"           // 33/66
  | "2_3,1_3"           // 66/33
  | "1_3,1_3,1_3"       // 3 colonnes égales
  | "1_4,1_4,1_4,1_4"   // 4 colonnes égales
  | "1_4,3_4"           // 25/75
  | "3_4,1_4"           // 75/25
  | string;             // tolérant

/** Builder version épinglé (à mettre à jour si Divi évolue). */
export const BUILDER_VERSION = "5.5.2";

/** Constantes des noms de blocs Divi. */
export const DiviBlock = {
  Placeholder: "divi/placeholder",
  Section: "divi/section",
  Row: "divi/row",
  Column: "divi/column",
  Text: "divi/text",
  Blurb: "divi/blurb",
  Cta: "divi/cta",
  Image: "divi/image",
  Button: "divi/button",
  Heading: "divi/heading",
  NumberCounter: "divi/number-counter",
  Testimonial: "divi/testimonial",
  Gallery: "divi/gallery",
  Video: "divi/video",
  Code: "divi/code",
  Accordion: "divi/accordion",
  AccordionItem: "divi/accordion-item",
  Tabs: "divi/tabs",
  Tab: "divi/tab",
  Slider: "divi/slider",
  Slide: "divi/slide",
  ContactForm: "divi/contact-form",
  ContactField: "divi/contact-field",
  // Modules prioritaires Phase 3.5 (page de référence n°53).
  Divider: "divi/divider",
  Icon: "divi/icon",
  Toggle: "divi/toggle",
  // ⚠️ Convention : tables (pluriel) + table (singulier).
  PricingTables: "divi/pricing-tables",
  PricingTable: "divi/pricing-table",
  IconList: "divi/icon-list",
  IconListItem: "divi/icon-list-item",
  // ⚠️ Convention : -network et pas -item.
  SocialMediaFollow: "divi/social-media-follow",
  SocialMediaFollowNetwork: "divi/social-media-follow-network",
  TeamMember: "divi/team-member",
  Signup: "divi/signup",
  Map: "divi/map",
  CircleCounter: "divi/circle-counter",
  // ⚠️ blockName = divi/counters (pas divi/bar-counters).
  Counters: "divi/counters",
  Counter: "divi/counter",
  Audio: "divi/audio",
} as const;
