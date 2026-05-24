/**
 * Divi 5 types — based on the reverse engineering documented in
 * docs/divi5-format.md.
 */

/** Divi 5 breakpoint. */
export type Breakpoint = "desktop" | "tablet" | "phoneWide" | "phone";

/** A value indexed by breakpoint: { desktop: { value: T }, ... }. */
export type Responsive<T> = Partial<Record<Breakpoint, { value: T }>>;

/** Gutenberg block compatible with WP's parse_blocks / serialize_blocks. */
export interface GutenbergBlock {
  blockName: string;
  attrs: Record<string, unknown>;
  innerBlocks: GutenbergBlock[];
  innerHTML: string;
  innerContent: (string | null)[];
}

/** Reference to a Divi global color (gcid-*). */
export type GlobalColorName =
  | "gcid-primary-color"
  | "gcid-secondary-color"
  | "gcid-heading-color"
  | "gcid-body-color"
  | "gcid-link-color"
  | string; // tolerant for custom colors

/** A Divi color: either hex or a global reference. */
export type DiviColor = `#${string}` | { gcid: GlobalColorName };

/** Spacing (padding or margin) with optional sync flags. */
export interface Spacing {
  top?: string;
  right?: string;
  bottom?: string;
  left?: string;
  syncVertical?: "on" | "off";
  syncHorizontal?: "on" | "off";
}

/** Column structure of a Divi row ("a_b,c_d,..." notation). */
export type ColumnStructure =
  | "4_4"               // 1 full-width column
  | "1_2,1_2"           // 2 columns 50/50
  | "1_3,2_3"           // 33/66
  | "2_3,1_3"           // 66/33
  | "1_3,1_3,1_3"       // 3 equal columns
  | "1_4,1_4,1_4,1_4"   // 4 equal columns
  | "1_4,3_4"           // 25/75
  | "3_4,1_4"           // 75/25
  | string;             // tolerant

/** Pinned builder version (to be updated when Divi evolves). */
export const BUILDER_VERSION = "5.5.2";

/** Constants for Divi block names. */
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
  // Phase 3.5 priority modules (reference page #53).
  Divider: "divi/divider",
  Icon: "divi/icon",
  Toggle: "divi/toggle",
  // Warning: convention is tables (plural) + table (singular).
  PricingTables: "divi/pricing-tables",
  PricingTable: "divi/pricing-table",
  IconList: "divi/icon-list",
  IconListItem: "divi/icon-list-item",
  // Warning: convention is -network and not -item.
  SocialMediaFollow: "divi/social-media-follow",
  SocialMediaFollowNetwork: "divi/social-media-follow-network",
  TeamMember: "divi/team-member",
  Signup: "divi/signup",
  Map: "divi/map",
  CircleCounter: "divi/circle-counter",
  // Warning: blockName = divi/counters (not divi/bar-counters).
  Counters: "divi/counters",
  Counter: "divi/counter",
  Audio: "divi/audio",
  // Theme Builder & dynamic content (Phase 3.6).
  Menu: "divi/menu",
  FullwidthMenu: "divi/fullwidth-menu",
  Search: "divi/search",
  Breadcrumbs: "divi/breadcrumbs",
  PostTitle: "divi/post-title",
  PostContent: "divi/post-content",
  PostNavigation: "divi/post-navigation",
  Sidebar: "divi/sidebar",
  Comments: "divi/comments",
} as const;
