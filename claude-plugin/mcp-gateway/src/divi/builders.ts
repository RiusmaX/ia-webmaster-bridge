/**
 * Low-level Divi 5 block constructors.
 *
 * All functions produce parse_blocks-compatible `GutenbergBlock` values, ready
 * to be sent to `iawm_divi_page_write` (the `blocks` parameter) or serialized
 * via `serialize_blocks` on the PHP side.
 *
 * Format documented in `docs/divi5-format.md`. Attributes follow the observed
 * structure:
 *   module: { advanced, decoration: { background, spacing, sizing, layout } }
 *   content / title / button / image / imageIcon: per module
 *
 * Multi-breakpoints: for now only "desktop" is set — Divi automatically
 * inherits to the other breakpoints. Phone/tablet overrides should be added
 * for fine-grained responsive design.
 */

import { BUILDER_VERSION, DiviBlock } from "./types.js";
import type {
  ColumnStructure,
  DiviColor,
  GutenbergBlock,
  Spacing,
} from "./types.js";
import { colorToString } from "./globals.js";

/** Builds an empty GutenbergBlock with the right structural fields. */
function makeBlock(name: string, attrs: Record<string, unknown>, innerBlocks: GutenbergBlock[] = []): GutenbergBlock {
  return {
    blockName: name,
    attrs: { ...attrs, builderVersion: BUILDER_VERSION },
    innerBlocks,
    innerHTML: "",
    innerContent: innerBlocks.length === 0 ? [null] : new Array(innerBlocks.length + 1).fill(null),
  };
}

/** Sets a "desktop" value on an attribute (helper). */
function desktopValue<T>(value: T): { desktop: { value: T } } {
  return { desktop: { value } };
}

/* ------------------------------------------------------------------ */
/* Structural: placeholder, section, row, column                      */
/* ------------------------------------------------------------------ */

/**
 * Root `wp:divi/placeholder` wrapper. Required at the top level of a Divi 5
 * page (without it, Divi does not take over the rendering).
 */
export function placeholder(children: GutenbergBlock[]): GutenbergBlock {
  return makeBlock(DiviBlock.Placeholder, {}, children);
}

/**
 * Divi section (full-width horizontal band).
 */
export interface SectionOptions {
  backgroundColor?: DiviColor;
  backgroundImageUrl?: string;
  spacing?: { padding?: Spacing; margin?: Spacing };
}

export function section(options: SectionOptions, rows: GutenbergBlock[]): GutenbergBlock {
  const decoration: Record<string, unknown> = {};

  // Background.
  if (options.backgroundColor || options.backgroundImageUrl) {
    const bgValue: Record<string, unknown> = {};
    if (options.backgroundColor) {
      bgValue.color = colorToString(options.backgroundColor);
    }
    if (options.backgroundImageUrl) {
      bgValue.image = { url: options.backgroundImageUrl };
    }
    decoration.background = desktopValue(bgValue);
  }

  // Spacing.
  if (options.spacing) {
    decoration.spacing = desktopValue(options.spacing);
  }

  const attrs: Record<string, unknown> = {};
  if (Object.keys(decoration).length > 0) {
    attrs.module = { decoration };
  }

  return makeBlock(DiviBlock.Section, attrs, rows);
}

/**
 * Divi row (a row within a section). The column structure (1_3,1_3,1_3 etc.)
 * is required and must match the number of child columns.
 */
export interface RowOptions {
  columnStructure: ColumnStructure;
  flexWrapMobile?: "wrap" | "nowrap";
  spacing?: { padding?: Spacing; margin?: Spacing };
}

export function row(options: RowOptions, columns: GutenbergBlock[]): GutenbergBlock {
  // flexColumnStructure derived from the column count (observed on page 19).
  const colCount = columns.length;
  const flexColumnStructure = `equal-columns_${colCount === 1 ? 1 : colCount}`;

  const moduleAttrs: Record<string, unknown> = {
    advanced: {
      columnStructure: desktopValue(options.columnStructure),
      flexColumnStructure: desktopValue(flexColumnStructure),
    },
    decoration: {
      layout: {
        ...desktopValue({ flexWrap: "nowrap" }),
        ...(options.flexWrapMobile === "wrap"
          ? {
              phone: { value: { flexWrap: "wrap" } },
              phoneWide: { value: { flexWrap: "wrap" } },
            }
          : {}),
      },
    },
  };

  if (options.spacing) {
    (moduleAttrs.decoration as Record<string, unknown>).spacing = desktopValue(options.spacing);
  }

  return makeBlock(DiviBlock.Row, { module: moduleAttrs }, columns);
}

/**
 * Divi column (a portion of a row). `type` must match the structure
 * (`1_3`, `1_2`, `4_4`, etc.).
 */
export interface ColumnOptions {
  /** `a_b` notation, e.g. "1_3". */
  type: string;
  /** If true, the column becomes full-width on mobile. Default: true. */
  fullWidthOnMobile?: boolean;
}

/** Convert `1_3` → `8_24`, `1_2` → `12_24`, `4_4` → `24_24`. */
function typeToFlexType(type: string): string {
  const [num, denom] = type.split("_").map(Number);
  if (!num || !denom) return "24_24";
  const scaled = Math.round((num / denom) * 24);
  return `${scaled}_24`;
}

export function column(options: ColumnOptions, modules: GutenbergBlock[]): GutenbergBlock {
  const fullWidthOnMobile = options.fullWidthOnMobile !== false;
  const flexType = typeToFlexType(options.type);

  const sizingValue: Record<string, unknown> = {
    desktop: { value: { flexType } },
  };
  if (fullWidthOnMobile && options.type !== "4_4") {
    sizingValue.phone = { value: { flexType: "24_24" } };
    sizingValue.phoneWide = { value: { flexType: "24_24" } };
  }

  return makeBlock(
    DiviBlock.Column,
    {
      module: {
        advanced: { type: desktopValue(options.type) },
        decoration: { sizing: sizingValue },
      },
    },
    modules,
  );
}

/* ------------------------------------------------------------------ */
/* Leaf modules                                                       */
/* ------------------------------------------------------------------ */

/**
 * Text module. The HTML content can include h1-h6, p, span, etc. tags.
 * To style headings (size, color, alignment), use the `headingFont` option.
 */
export interface TextOptions {
  /** HTML content, e.g. "<h1>Welcome</h1>". */
  html: string;
  /** Styles by tag (h1, h2, …). */
  headingFont?: Partial<
    Record<
      "h1" | "h2" | "h3" | "h4" | "h5" | "h6",
      {
        textAlign?: "left" | "center" | "right" | "justify";
        size?: string;
        weight?: string;
        style?: string[];
        color?: DiviColor;
      }
    >
  >;
}

export function text(options: TextOptions): GutenbergBlock {
  const contentAttrs: Record<string, unknown> = {
    innerContent: desktopValue(options.html),
  };

  if (options.headingFont) {
    const fontDecoration: Record<string, unknown> = {};
    for (const [tag, settings] of Object.entries(options.headingFont)) {
      const value: Record<string, unknown> = { ...settings };
      if (settings.color) {
        value.color = colorToString(settings.color);
        delete (value as Record<string, unknown>).color;
        // In reality the heading color belongs in a separate sub-structure —
        // kept simple here, to be refined after populating page 29.
        value.color = colorToString(settings.color);
      }
      fontDecoration[tag] = { font: desktopValue(value) };
    }
    contentAttrs.decoration = { headingFont: fontDecoration };
  }

  return makeBlock(DiviBlock.Text, { content: contentAttrs });
}

/**
 * Blurb module (icon + title + text) — ideal for feature lists.
 */
export interface BlurbOptions {
  title: string;
  contentHtml: string;
  /** Unicode code of a Divi icon, e.g. "&#xe0e1;". */
  iconUnicode?: string;
  /** Image URL as an alternative to the icon. */
  imageUrl?: string;
}

export function blurb(options: BlurbOptions): GutenbergBlock {
  const imageIcon: Record<string, unknown> = {
    innerContent: desktopValue({
      ...(options.imageUrl ? { src: options.imageUrl } : {}),
      ...(options.iconUnicode
        ? {
            useIcon: "on",
            icon: { unicode: options.iconUnicode, type: "divi", weight: "400" },
          }
        : {}),
    }),
  };

  return makeBlock(DiviBlock.Blurb, {
    imageIcon,
    title: { innerContent: desktopValue({ text: options.title }) },
    content: { innerContent: desktopValue(options.contentHtml) },
  });
}

/**
 * Call To Action module (title + text + button).
 */
export interface CtaOptions {
  title: string;
  contentHtml: string;
  buttonText: string;
  buttonUrl: string;
}

export function cta(options: CtaOptions): GutenbergBlock {
  return makeBlock(DiviBlock.Cta, {
    module: {
      advanced: {
        link: desktopValue({ url: "" }),
      },
    },
    title: { innerContent: desktopValue(options.title) },
    content: { innerContent: desktopValue(options.contentHtml) },
    button: {
      innerContent: desktopValue({
        text: options.buttonText,
        linkUrl: options.buttonUrl,
      }),
    },
  });
}

/**
 * Image module.
 */
export interface ImageOptions {
  src: string;
  alt?: string;
}

export function image(options: ImageOptions): GutenbergBlock {
  return makeBlock(DiviBlock.Image, {
    image: {
      innerContent: desktopValue({
        src: options.src,
        ...(options.alt ? { alt: options.alt } : {}),
      }),
    },
  });
}

/**
 * Heading module (dedicated heading, alternative to text with h1).
 */
export interface HeadingOptions {
  text: string;
}

export function heading(options: HeadingOptions): GutenbergBlock {
  return makeBlock(DiviBlock.Heading, {
    title: { innerContent: desktopValue(options.text) },
  });
}

/**
 * Button module (standalone button). linkUrl can be a Divi variable
 * (e.g. `$variable({"type":"content","value":{"name":"home_url",…}})$`).
 */
export interface ButtonOptions {
  text: string;
  linkUrl: string;
}

export function button(options: ButtonOptions): GutenbergBlock {
  return makeBlock(DiviBlock.Button, {
    button: {
      innerContent: desktopValue({
        text: options.text,
        linkUrl: options.linkUrl,
      }),
    },
  });
}

/**
 * Number Counter module (number animated on scroll, ideal for KPIs).
 */
export interface NumberCounterOptions {
  title: string;
  /** Value to display (string to support "1.5k" if needed). */
  number: string;
  /** Auto-appends "%". Default: false. */
  percent?: boolean;
}

export function numberCounter(options: NumberCounterOptions): GutenbergBlock {
  return makeBlock(DiviBlock.NumberCounter, {
    title: { innerContent: desktopValue(options.title) },
    number: {
      innerContent: desktopValue(options.number),
      advanced: {
        enablePercentSign: desktopValue(options.percent ? "on" : "off"),
      },
    },
  });
}

/**
 * Testimonial module (customer quote with photo).
 */
export interface TestimonialOptions {
  quoteHtml: string;
  author: string;
  /** Photo URL (optional). */
  portraitUrl?: string;
}

export function testimonial(options: TestimonialOptions): GutenbergBlock {
  return makeBlock(DiviBlock.Testimonial, {
    content: { innerContent: desktopValue(options.quoteHtml) },
    author: { innerContent: desktopValue(options.author) },
    ...(options.portraitUrl
      ? {
          portrait: {
            innerContent: desktopValue({ url: options.portraitUrl }),
          },
        }
      : {}),
  });
}

/**
 * Gallery module (list of WP media IDs).
 */
export interface GalleryOptions {
  /** WP attachment IDs. */
  ids: number[];
  /** Number of columns on desktop. Default: 4. */
  columns?: number;
}

export function gallery(options: GalleryOptions): GutenbergBlock {
  return makeBlock(DiviBlock.Gallery, {
    image: {
      advanced: {
        galleryIds: desktopValue(options.ids.join(",")),
      },
    },
    galleryGrid: {
      decoration: {
        layout: {
          desktop: { value: { gridColumnCount: String(options.columns ?? 4) } },
          tablet: { value: { gridColumnCount: "3" } },
          phone: { value: { gridColumnCount: "1" } },
        },
      },
    },
  });
}

/**
 * Video module (YouTube/Vimeo/mp4 URL).
 */
export interface VideoOptions {
  src: string;
}

export function video(options: VideoOptions): GutenbergBlock {
  return makeBlock(DiviBlock.Video, {
    video: {
      innerContent: desktopValue({ src: options.src }),
    },
  });
}

/**
 * Code module (raw HTML — to be used sparingly).
 */
export interface CodeOptions {
  html: string;
}

export function code(options: CodeOptions): GutenbergBlock {
  return makeBlock(DiviBlock.Code, {
    content: { innerContent: desktopValue(options.html) },
  });
}

/* ------------------------------------------------------------------ */
/* Composite modules (nested)                                         */
/* ------------------------------------------------------------------ */

/**
 * Accordion item.
 */
export interface AccordionItemOptions {
  title: string;
  contentHtml: string;
  /** Open by default? */
  open?: boolean;
}

export function accordionItem(options: AccordionItemOptions): GutenbergBlock {
  const attrs: Record<string, unknown> = {
    title: { innerContent: desktopValue(options.title) },
    content: { innerContent: desktopValue(options.contentHtml) },
  };
  if (options.open) {
    attrs.module = { advanced: { open: desktopValue("on") } };
  }
  return makeBlock(DiviBlock.AccordionItem, attrs);
}

/**
 * Accordion module (contains accordion-item).
 */
export function accordion(items: AccordionItemOptions[]): GutenbergBlock {
  return makeBlock(
    DiviBlock.Accordion,
    {},
    items.map((item) => accordionItem(item)),
  );
}

/**
 * Tab of a Tabs module.
 */
export interface TabOptions {
  title: string;
  contentHtml: string;
}

export function tab(options: TabOptions): GutenbergBlock {
  return makeBlock(DiviBlock.Tab, {
    title: { innerContent: desktopValue(options.title) },
    content: { innerContent: desktopValue(options.contentHtml) },
  });
}

/**
 * Tabs module (contains tab).
 */
export function tabs(items: TabOptions[]): GutenbergBlock {
  return makeBlock(
    DiviBlock.Tabs,
    {},
    items.map((item) => tab(item)),
  );
}

/**
 * Slide of a Slider module.
 */
export interface SlideOptions {
  title: string;
  contentHtml: string;
  buttonText?: string;
  buttonUrl?: string;
}

export function slide(options: SlideOptions): GutenbergBlock {
  const attrs: Record<string, unknown> = {
    title: { innerContent: desktopValue(options.title) },
    content: { innerContent: desktopValue(options.contentHtml) },
  };
  if (options.buttonText) {
    attrs.button = {
      innerContent: desktopValue({
        text: options.buttonText,
        linkUrl: options.buttonUrl ?? "#",
      }),
    };
  }
  return makeBlock(DiviBlock.Slide, attrs);
}

/**
 * Slider module (contains slide).
 */
export function slider(items: SlideOptions[]): GutenbergBlock {
  return makeBlock(
    DiviBlock.Slider,
    {},
    items.map((item) => slide(item)),
  );
}

/**
 * Field of a contact form.
 */
export interface ContactFieldOptions {
  /** Internal identifier (no spaces: "name", "email", "message"). */
  id: string;
  /** Visible label. */
  label: string;
  /** Type. */
  type?: "input" | "email" | "text" | "phone" | "select" | "checkbox" | "radio";
  /** Full-width field? Default: false (except "text"). */
  fullwidth?: boolean;
}

export function contactField(options: ContactFieldOptions): GutenbergBlock {
  const type = options.type ?? "input";
  const fullwidth = options.fullwidth ?? type === "text";

  return makeBlock(DiviBlock.ContactField, {
    fieldItem: {
      advanced: {
        fullwidth: desktopValue(fullwidth ? "on" : "off"),
        id: desktopValue(options.id),
        type: desktopValue(type),
      },
      innerContent: desktopValue(options.label),
    },
    module: {
      decoration: {
        sizing: desktopValue({ flexType: fullwidth ? "24_24" : "12_24" }),
      },
    },
  });
}

/**
 * Contact Form module (contains contact-field).
 *
 * The uniqueId is generated automatically (UUID-like) — used by Divi to
 * identify submissions in email notifications.
 */
export interface ContactFormOptions {
  fields: ContactFieldOptions[];
  /** Custom UUID (otherwise generated). */
  uniqueId?: string;
}

function generateUniqueId(): string {
  // Minimalist UUID-like format, sufficient for Divi.
  const hex = () => Math.floor((1 + Math.random()) * 0x10000).toString(16).slice(1);
  return `${hex()}${hex()}-${hex()}-${hex()}-${hex()}-${hex()}${hex()}${hex()}`;
}

export function contactForm(options: ContactFormOptions): GutenbergBlock {
  return makeBlock(
    DiviBlock.ContactForm,
    {
      module: {
        advanced: {
          uniqueId: desktopValue(options.uniqueId ?? generateUniqueId()),
        },
      },
    },
    options.fields.map((f) => contactField(f)),
  );
}

/* ------------------------------------------------------------------ */
/* Phase 3.5 priority modules (reference page #53)                    */
/* ------------------------------------------------------------------ */

/**
 * Divider module — visual separator.
 */
export interface DividerOptions {
  /** Line color. */
  color?: DiviColor;
  /** Height in pixels. */
  height?: string;
}

export function divider(options: DividerOptions = {}): GutenbergBlock {
  const attrs: Record<string, unknown> = {};
  if (options.color || options.height) {
    const dividerValue: Record<string, unknown> = {};
    if (options.color) dividerValue.color = colorToString(options.color);
    if (options.height) dividerValue.height = options.height;
    attrs.module = {
      decoration: {
        divider: desktopValue(dividerValue),
      },
    };
  }
  return makeBlock(DiviBlock.Divider, attrs);
}

/**
 * Icon module — standalone icon (Divi font icon).
 */
export interface IconOptions {
  /** Unicode code of the Divi icon, e.g. "&#xe0e1;". */
  unicode: string;
  /** Icon color. */
  color?: DiviColor;
  /** Size (e.g. "48px"). */
  size?: string;
}

export function icon(options: IconOptions): GutenbergBlock {
  const value: Record<string, unknown> = {
    unicode: options.unicode,
    type: "divi",
    weight: "400",
  };
  if (options.color) value.color = colorToString(options.color);

  const attrs: Record<string, unknown> = {
    icon: { innerContent: desktopValue(value) },
  };
  if (options.size) {
    attrs.module = {
      decoration: {
        font: desktopValue({ size: options.size }),
      },
    };
  }
  return makeBlock(DiviBlock.Icon, attrs);
}

/**
 * Toggle module — revealable block (equivalent to a 1-item accordion).
 */
export interface ToggleOptions {
  title: string;
  contentHtml: string;
}

export function toggle(options: ToggleOptions): GutenbergBlock {
  return makeBlock(DiviBlock.Toggle, {
    title: { innerContent: desktopValue(options.title) },
    content: { innerContent: desktopValue(options.contentHtml) },
  });
}

/**
 * Item of a pricing grid.
 */
export interface PricingTableOptions {
  title: string;
  subtitle?: string;
  /** Currency symbol ("$", "€", "£"…). */
  currency?: string;
  price: string;
  /** Frequency ("month", "year"…). */
  frequency?: string;
  /** Feature list: starts with `+` (included) or `-` (not included). */
  features: Array<{ text: string; included?: boolean }>;
  /** Button text (optional). */
  buttonText?: string;
  buttonUrl?: string;
}

export function pricingTable(options: PricingTableOptions): GutenbergBlock {
  // content format: "+ feature\n+ feature\n- feature"
  const contentValue = options.features
    .map((f) => `${f.included !== false ? "+" : "-"} ${f.text}`)
    .join("\n");

  const currencyFreqValue: Record<string, unknown> = {
    currency: options.currency ?? "$",
  };
  if (options.frequency) currencyFreqValue.frequency = options.frequency;

  const attrs: Record<string, unknown> = {
    title: { innerContent: desktopValue(options.title) },
    price: { innerContent: desktopValue(options.price) },
    currencyFrequency: { innerContent: desktopValue(currencyFreqValue) },
    content: { innerContent: desktopValue(contentValue) },
  };
  if (options.subtitle) {
    attrs.subtitle = { innerContent: desktopValue(options.subtitle) };
  }
  if (options.buttonText) {
    attrs.button = {
      innerContent: desktopValue({
        text: options.buttonText,
        linkUrl: options.buttonUrl ?? "#",
      }),
    };
  }
  return makeBlock(DiviBlock.PricingTable, attrs);
}

/**
 * Pricing Tables module (contains pricing-table).
 */
export function pricingTables(items: PricingTableOptions[]): GutenbergBlock {
  return makeBlock(
    DiviBlock.PricingTables,
    {},
    items.map((i) => pricingTable(i)),
  );
}

/**
 * Item of an icon-list.
 */
export interface IconListItemOptions {
  text: string;
  /** Unicode code of the Divi icon. */
  iconUnicode?: string;
  /** Optional link. */
  url?: string;
  /** Open in a new tab. */
  newTab?: boolean;
}

export function iconListItem(options: IconListItemOptions): GutenbergBlock {
  return makeBlock(DiviBlock.IconListItem, {
    content: { innerContent: desktopValue(options.text) },
    icon: {
      innerContent: desktopValue({
        unicode: options.iconUnicode ?? "&#x21;",
        type: "divi",
        weight: "400",
        target: options.newTab ? "on" : "off",
        ...(options.url ? { url: options.url } : {}),
      }),
    },
  });
}

/**
 * Icon List module (contains icon-list-item).
 */
export function iconList(items: IconListItemOptions[]): GutenbergBlock {
  return makeBlock(
    DiviBlock.IconList,
    {},
    items.map((i) => iconListItem(i)),
  );
}

/**
 * Social network for Social Media Follow.
 */
export interface SocialNetworkOptions {
  /** Identifier: "facebook", "twitter", "instagram", "linkedin", "youtube", "tiktok"… */
  network: string;
  /** Displayed label (useful for accessibility). */
  label?: string;
  /** Profile URL (otherwise Divi falls back to a default URL). */
  url?: string;
}

/** Default brand colors. */
const NETWORK_COLORS: Record<string, string> = {
  facebook: "#3b5998",
  twitter: "#1da1f2",
  instagram: "#e1306c",
  linkedin: "#0077b5",
  youtube: "#ff0000",
  tiktok: "#000000",
  pinterest: "#bd081c",
  github: "#181717",
};

export function socialMediaFollowNetwork(options: SocialNetworkOptions): GutenbergBlock {
  const bgColor = NETWORK_COLORS[options.network.toLowerCase()] ?? "#666666";
  return makeBlock(DiviBlock.SocialMediaFollowNetwork, {
    socialNetwork: {
      innerContent: desktopValue({
        title: options.network.toLowerCase(),
        label: options.label ?? capitalize(options.network),
        ...(options.url ? { url: options.url } : {}),
      }),
    },
    module: {
      decoration: {
        background: desktopValue({ color: bgColor }),
      },
    },
  });
}

/**
 * Social Media Follow module (contains social-media-follow-network).
 */
export function socialMediaFollow(networks: SocialNetworkOptions[]): GutenbergBlock {
  return makeBlock(
    DiviBlock.SocialMediaFollow,
    {},
    networks.map((n) => socialMediaFollowNetwork(n)),
  );
}

function capitalize(s: string): string {
  return s.charAt(0).toUpperCase() + s.slice(1).toLowerCase();
}

/**
 * Team Member module — team member.
 */
export interface TeamMemberOptions {
  name: string;
  position: string;
  /** Photo URL. */
  imageUrl?: string;
  /** Bio in HTML. */
  bioHtml?: string;
}

export function teamMember(options: TeamMemberOptions): GutenbergBlock {
  const attrs: Record<string, unknown> = {
    name: { innerContent: desktopValue(options.name) },
    position: { innerContent: desktopValue(options.position) },
  };
  if (options.imageUrl) {
    attrs.image = { innerContent: desktopValue({ url: options.imageUrl }) };
  }
  if (options.bioHtml) {
    attrs.content = { innerContent: desktopValue(options.bioHtml) };
  }
  return makeBlock(DiviBlock.TeamMember, attrs);
}

/**
 * Signup module — email opt-in (newsletter).
 */
export interface SignupOptions {
  title: string;
  contentHtml: string;
}

export function signup(options: SignupOptions): GutenbergBlock {
  return makeBlock(DiviBlock.Signup, {
    title: { innerContent: desktopValue(options.title) },
    content: { innerContent: desktopValue(options.contentHtml) },
  });
}

/**
 * Map module — Google Maps.
 *
 * Options: address, zoom, markers (to be enriched as needs evolve).
 */
export interface MapOptions {
  /** Address (e.g. "1600 Amphitheatre Parkway, Mountain View"). */
  address?: string;
  /** Zoom level (1-22). */
  zoom?: number;
}

export function map(options: MapOptions = {}): GutenbergBlock {
  const attrs: Record<string, unknown> = {};
  if (options.address || options.zoom) {
    attrs.map = {
      innerContent: desktopValue({
        ...(options.address ? { address: options.address } : {}),
        ...(options.zoom !== undefined ? { zoom: String(options.zoom) } : {}),
      }),
    };
  }
  return makeBlock(DiviBlock.Map, attrs);
}

/**
 * Circle Counter module — animated percentage circle.
 */
export interface CircleCounterOptions {
  title: string;
  number: string;
}

export function circleCounter(options: CircleCounterOptions): GutenbergBlock {
  return makeBlock(DiviBlock.CircleCounter, {
    title: { innerContent: desktopValue(options.title) },
    number: { innerContent: desktopValue(options.number) },
  });
}

/**
 * Item of a Counters module (bar counters / skill bars).
 */
export interface CounterItemOptions {
  title: string;
  /** Percentage (0-100). */
  progress: string;
}

export function counter(options: CounterItemOptions): GutenbergBlock {
  return makeBlock(DiviBlock.Counter, {
    title: { innerContent: desktopValue(options.title) },
    barProgress: { innerContent: desktopValue(options.progress) },
  });
}

/**
 * Counters module (animated skill/progress bars).
 *
 * The blockName is `divi/counters` (NOT `divi/bar-counters`).
 */
export interface CountersOptions {
  items: CounterItemOptions[];
  /** Show the percentage on the right? Default: true. */
  showPercentages?: boolean;
}

export function counters(options: CountersOptions): GutenbergBlock {
  return makeBlock(
    DiviBlock.Counters,
    {
      barProgress: {
        advanced: {
          usePercentages: desktopValue(options.showPercentages !== false ? "on" : "off"),
        },
      },
    },
    options.items.map((i) => counter(i)),
  );
}

/**
 * Audio module — HTML5 audio player.
 */
export interface AudioOptions {
  title: string;
  artistName?: string;
  /** URL of the audio file. */
  audioUrl?: string;
}

export function audio(options: AudioOptions): GutenbergBlock {
  const attrs: Record<string, unknown> = {
    title: { innerContent: desktopValue(options.title) },
  };
  if (options.artistName) {
    attrs.artistName = { innerContent: desktopValue(options.artistName) };
  }
  if (options.audioUrl) {
    attrs.audio = { innerContent: desktopValue({ url: options.audioUrl }) };
  }
  return makeBlock(DiviBlock.Audio, attrs);
}

/* ------------------------------------------------------------------ */
/* Theme Builder & dynamic content (Phase 3.6)                        */
/* ------------------------------------------------------------------ */

/**
 * Menu module — navigation bar (WP menu).
 *
 * Displays a WordPress menu by its ID. Ideal inside a header.
 */
export interface MenuOptions {
  /** WP menu ID. If omitted, Divi displays the default menu. */
  menuId?: number;
  /** Logo on the left of the menu (URL). */
  logoUrl?: string;
  /** Desktop sub-menu direction. "downwards" (default) | "upwards". */
  dropdownDirection?: "downwards" | "upwards";
}

export function menu(options: MenuOptions = {}): GutenbergBlock {
  const attrs: Record<string, unknown> = {};
  const menuAttrs: Record<string, unknown> = {};
  if (options.menuId !== undefined) {
    menuAttrs.menuId = desktopValue(String(options.menuId));
  }
  if (options.dropdownDirection) {
    menuAttrs.menuDropdownDirection = desktopValue(options.dropdownDirection);
  }
  if (Object.keys(menuAttrs).length > 0) {
    attrs.menu = menuAttrs;
  }
  if (options.logoUrl) {
    attrs.logo = { innerContent: desktopValue({ url: options.logoUrl }) };
  }
  return makeBlock(DiviBlock.Menu, attrs);
}

/**
 * Fullwidth Menu module — full-width version of Menu.
 */
export function fullwidthMenu(options: MenuOptions = {}): GutenbergBlock {
  // Same attributes as Menu, only the blockName differs.
  const block = menu(options);
  block.blockName = DiviBlock.FullwidthMenu;
  return block;
}

/**
 * Search module — WordPress search bar.
 */
export interface SearchOptions {
  placeholder?: string;
  buttonText?: string;
}

export function search(options: SearchOptions = {}): GutenbergBlock {
  const attrs: Record<string, unknown> = {};
  const innerAttrs: Record<string, unknown> = {};
  if (options.placeholder) innerAttrs.placeholder = options.placeholder;
  if (options.buttonText) innerAttrs.buttonText = options.buttonText;
  if (Object.keys(innerAttrs).length > 0) {
    attrs.search = { innerContent: desktopValue(innerAttrs) };
  }
  return makeBlock(DiviBlock.Search, attrs);
}

/**
 * Breadcrumbs module — breadcrumb trail (useful for SEO + navigation).
 */
export interface BreadcrumbsOptions {
  /** Text of the Home link. Default: "Home". */
  homeText?: string;
  /** Separator between items. Default: "/". */
  separator?: string;
  /** Wrapper HTML tag. Default: "nav". */
  htmlTag?: string;
}

export function breadcrumbs(options: BreadcrumbsOptions = {}): GutenbergBlock {
  const attrs: Record<string, unknown> = {};
  const inner: Record<string, unknown> = {};
  if (options.homeText) inner.homeText = options.homeText;
  if (options.separator) inner.separator = options.separator;
  if (options.htmlTag) inner.htmlTag = options.htmlTag;
  if (Object.keys(inner).length > 0) {
    attrs.breadcrumbs = { innerContent: desktopValue(inner) };
  }
  return makeBlock(DiviBlock.Breadcrumbs, attrs);
}

/**
 * Post Title module — dynamic title of the current post (Theme Builder).
 *
 * Use inside single post / page templates to automatically display the title
 * of the post being viewed.
 */
export interface PostTitleOptions {
  /** Include meta (date, author). Default: false. */
  includeMeta?: boolean;
  /** Include the featured image as background. Default: false. */
  includeFeaturedImage?: boolean;
}

export function postTitle(options: PostTitleOptions = {}): GutenbergBlock {
  const attrs: Record<string, unknown> = {};
  const inner: Record<string, unknown> = {};
  if (options.includeMeta !== undefined) {
    inner.includeMeta = options.includeMeta ? "on" : "off";
  }
  if (options.includeFeaturedImage !== undefined) {
    inner.includeFeaturedImage = options.includeFeaturedImage ? "on" : "off";
  }
  if (Object.keys(inner).length > 0) {
    attrs.title = { innerContent: desktopValue(inner) };
  }
  return makeBlock(DiviBlock.PostTitle, attrs);
}

/**
 * Post Content module — dynamic content of the current post (Theme Builder).
 */
export function postContent(): GutenbergBlock {
  return makeBlock(DiviBlock.PostContent, {});
}

/**
 * Post Navigation module — previous / next links.
 */
export interface PostNavigationOptions {
  prevText?: string;
  nextText?: string;
  /** Restrict to posts in the same category. */
  sameTerm?: boolean;
}

export function postNavigation(options: PostNavigationOptions = {}): GutenbergBlock {
  const attrs: Record<string, unknown> = {};
  const inner: Record<string, unknown> = {};
  if (options.prevText) inner.prevText = options.prevText;
  if (options.nextText) inner.nextText = options.nextText;
  if (options.sameTerm !== undefined) inner.inSameTerm = options.sameTerm ? "on" : "off";
  if (Object.keys(inner).length > 0) {
    attrs.navigation = { innerContent: desktopValue(inner) };
  }
  // Note: Divi 5 ships this as `divi/post-nav` (the registry name is
  // PostNav, not PostNavigation — the old constant referenced a non-existent
  // block name; the auto-registry fixed it).
  return makeBlock(DiviBlock.PostNav, attrs);
}

/**
 * Comments module — WP comments.
 */
export function comments(): GutenbergBlock {
  return makeBlock(DiviBlock.Comments, {});
}
