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

/* ================================================================== */
/* Phase 9 — extended module builders                                 */
/* ================================================================== */
/*
 * The 22 builders below cover the most operationally useful Divi
 * native modules that previously lived only in the free-form
 * registry. Each accepts a small, opinionated interface and emits
 * sane defaults so a webmaster can compose pages without dropping to
 * raw attribute hashes.
 *
 * Conventions inherited from the rest of this file:
 *  - All values pass through desktopValue() so Divi inherits to
 *    other breakpoints unless overridden.
 *  - Optional fields are conditionally added — empty attribute keys
 *    are avoided to keep the round-trip readable.
 *  - Colors flow through colorToString() so DiviColor (hex or
 *    gcid-*) is handled uniformly.
 */

/* ------------------------------------------------------------------ */
/* Fullwidth band / hero family (4)                                   */
/* ------------------------------------------------------------------ */

/**
 * Fullwidth Header — large hero band with title, subtitle, body,
 * optional dual CTA, optional foreground image and optional
 * background image. Used as the opening section of a page where
 * the standalone `hero` pattern is not flexible enough.
 *
 * Sensible defaults: vertical padding 120 / 80 px, centred layout,
 * heading typography hooked to the design system.
 */
export interface FullwidthHeaderOptions {
  title: string;
  subhead?: string;
  contentHtml?: string;
  primaryButton?: { text: string; linkUrl: string };
  secondaryButton?: { text: string; linkUrl: string };
  /** URL of a foreground image (rendered next to the text). */
  imageUrl?: string;
  /** URL of a background image (fills the band). */
  backgroundImageUrl?: string;
  backgroundColor?: DiviColor;
  /** Where the text sits horizontally. Default: "center". */
  textAlign?: "left" | "center" | "right";
  /**
   * Height preset. "screen" sets min-height to 100vh, "large" to
   * 80vh, "medium" to 60vh. Default: "large".
   */
  height?: "screen" | "large" | "medium";
}

export function fullwidthHeader(options: FullwidthHeaderOptions): GutenbergBlock {
  const heightMap = { screen: "100vh", large: "80vh", medium: "60vh" };
  const minHeight = heightMap[options.height ?? "large"];
  const textAlign = options.textAlign ?? "center";

  const innerContent: Record<string, unknown> = {
    title: options.title,
    textAlign,
  };
  if (options.subhead) innerContent.subhead = options.subhead;
  if (options.contentHtml) innerContent.content = options.contentHtml;

  const attrs: Record<string, unknown> = {
    header: { innerContent: desktopValue(innerContent) },
    module: {
      decoration: {
        sizing: desktopValue({ minHeight }),
        spacing: desktopValue({
          padding: { top: "120px", bottom: "80px" },
        }),
      },
    },
  };

  if (options.imageUrl) {
    attrs.image = {
      innerContent: desktopValue({ url: options.imageUrl, alt: options.title }),
    };
  }

  if (options.backgroundColor || options.backgroundImageUrl) {
    const bgValue: Record<string, unknown> = {};
    if (options.backgroundColor) bgValue.color = colorToString(options.backgroundColor);
    if (options.backgroundImageUrl) bgValue.image = { url: options.backgroundImageUrl };
    (attrs.module as Record<string, Record<string, unknown>>).decoration.background = desktopValue(bgValue);
  }

  if (options.primaryButton) {
    attrs.buttonOne = {
      innerContent: desktopValue({
        text: options.primaryButton.text,
        linkUrl: options.primaryButton.linkUrl,
      }),
    };
  }
  if (options.secondaryButton) {
    attrs.buttonTwo = {
      innerContent: desktopValue({
        text: options.secondaryButton.text,
        linkUrl: options.secondaryButton.linkUrl,
      }),
    };
  }

  return makeBlock(DiviBlock.FullwidthHeader, attrs);
}

/**
 * Fullwidth Image — image spanning the full width of the section.
 * Useful as a decorative band between content sections or as a
 * standalone hero image. Accepts an optional link and a colour
 * overlay (typical pattern: dark overlay at 30 % under text).
 */
export interface FullwidthImageOptions {
  imageUrl: string;
  alt?: string;
  linkUrl?: string;
  /** Hex/gcid overlay colour. Combined with `overlayOpacity`. */
  overlayColor?: DiviColor;
  /** 0..1; default 0.3 when overlayColor is set. */
  overlayOpacity?: number;
  /** Parallax effect. Default: false. */
  parallax?: boolean;
}

export function fullwidthImage(options: FullwidthImageOptions): GutenbergBlock {
  const innerContent: Record<string, unknown> = {
    url: options.imageUrl,
    alt: options.alt ?? "",
  };
  if (options.linkUrl) innerContent.linkUrl = options.linkUrl;

  const attrs: Record<string, unknown> = {
    image: { innerContent: desktopValue(innerContent) },
  };

  const moduleDecoration: Record<string, unknown> = {};
  if (options.overlayColor) {
    const opacity = options.overlayOpacity ?? 0.3;
    moduleDecoration.background = desktopValue({
      color: colorToString(options.overlayColor),
      blend: "multiply",
      opacity: String(opacity),
    });
  }
  if (options.parallax) {
    moduleDecoration.advanced = desktopValue({ parallax: "on" });
  }
  if (Object.keys(moduleDecoration).length > 0) {
    attrs.module = { decoration: moduleDecoration };
  }

  return makeBlock(DiviBlock.FullwidthImage, attrs);
}

/**
 * Fullwidth Slider — full-width hero slider containing slides. Each
 * slide reuses the existing `slide()` builder so the API surface
 * stays small. Sensible defaults: 7 s autoplay, navigation arrows
 * + dots, viewport-height slides.
 */
export interface FullwidthSliderOptions {
  /** Autoplay rotation. Default: true. */
  autoplay?: boolean;
  /** Autoplay delay in ms. Default: 7000. */
  autoplaySpeed?: number;
  /** Show prev/next arrows. Default: true. */
  showArrows?: boolean;
  /** Show pagination dots. Default: true. */
  showPagination?: boolean;
}

export function fullwidthSlider(
  options: FullwidthSliderOptions,
  slides: GutenbergBlock[],
): GutenbergBlock {
  const sliderAttrs: Record<string, unknown> = {
    autoplay: options.autoplay === false ? "off" : "on",
    autoplaySpeed: String(options.autoplaySpeed ?? 7000),
    showArrows: options.showArrows === false ? "off" : "on",
    showPagination: options.showPagination === false ? "off" : "on",
  };
  return makeBlock(
    DiviBlock.FullwidthSlider,
    { slider: { innerContent: desktopValue(sliderAttrs) } },
    slides,
  );
}

/**
 * Fullwidth Map — Google Map spanning the full width. Useful on
 * "Contact" / "Find us" pages. Optional pins. Sensible defaults:
 * grayscale on (sober rendering), mouse-wheel zoom off (prevents
 * scroll trap), 500 px height.
 */
export interface FullwidthMapPin {
  lat: number;
  lng: number;
  title?: string;
  contentHtml?: string;
}

export interface FullwidthMapOptions {
  centerLat: number;
  centerLng: number;
  zoom?: number;
  pins?: FullwidthMapPin[];
  /** Grayscale rendering. Default: true. */
  grayscale?: boolean;
  /** Enable mouse-wheel zoom. Default: false (avoids scroll trap). */
  mouseWheel?: boolean;
  /** Height (CSS). Default: "500px". */
  height?: string;
}

export function fullwidthMap(options: FullwidthMapOptions): GutenbergBlock {
  const mapInner: Record<string, unknown> = {
    addressLat: String(options.centerLat),
    addressLng: String(options.centerLng),
    zoomLevel: String(options.zoom ?? 14),
    grayscale: (options.grayscale ?? true) ? "on" : "off",
    mouseWheel: (options.mouseWheel ?? false) ? "on" : "off",
  };

  const innerBlocks: GutenbergBlock[] = (options.pins ?? []).map((pin) =>
    makeBlock(DiviBlock.MapPin, {
      pin: {
        innerContent: desktopValue({
          title: pin.title ?? "",
          content: pin.contentHtml ?? "",
          pinAddressLat: String(pin.lat),
          pinAddressLng: String(pin.lng),
        }),
      },
    }),
  );

  return makeBlock(
    DiviBlock.FullwidthMap,
    {
      map: { innerContent: desktopValue(mapInner) },
      module: {
        decoration: {
          sizing: desktopValue({ minHeight: options.height ?? "500px" }),
        },
      },
    },
    innerBlocks,
  );
}

/* ------------------------------------------------------------------ */
/* Group containers (2 + 2 inner)                                     */
/* ------------------------------------------------------------------ */

/**
 * Group module — generic container that wraps a set of modules as
 * one stylable / animable unit. Use it to build "card"-style
 * clusters with shared background, padding, radius and shadow.
 *
 * Default look: 30 px padding, radius 12 px, subtle elevation
 * shadow, optional translateY on hover for interactive cards.
 */
export interface GroupOptions {
  backgroundColor?: DiviColor;
  /** Border-radius, e.g. "12px" or "1rem". Default: "12px". */
  borderRadius?: string;
  /** Padding in CSS. Default: "30px". */
  padding?: string;
  /** Drop shadow. Default: true. */
  shadow?: boolean;
  /** Translate up on hover. Default: false. */
  hoverElevation?: boolean;
}

export function group(options: GroupOptions, modules: GutenbergBlock[]): GutenbergBlock {
  const decoration: Record<string, unknown> = {
    spacing: desktopValue({ padding: { top: options.padding ?? "30px", right: options.padding ?? "30px", bottom: options.padding ?? "30px", left: options.padding ?? "30px" } }),
    border: desktopValue({ radius: { topLeft: options.borderRadius ?? "12px", topRight: options.borderRadius ?? "12px", bottomRight: options.borderRadius ?? "12px", bottomLeft: options.borderRadius ?? "12px" } }),
  };
  if (options.backgroundColor) {
    decoration.background = desktopValue({ color: colorToString(options.backgroundColor) });
  }
  if (options.shadow !== false) {
    decoration.boxShadow = desktopValue({
      style: "preset3",
      horizontal: "0px",
      vertical: "4px",
      blur: "12px",
      spread: "0px",
      color: "rgba(0,0,0,0.08)",
    });
  }
  if (options.hoverElevation) {
    decoration.transform = {
      desktop: { hover: { translate: { y: "-4px" } } },
    };
  }

  return makeBlock(DiviBlock.Group, { module: { decoration } }, modules);
}

/**
 * Group Carousel — carousel where each slide is a full `group()`
 * cluster (vs the slider+slide pair which is one-quote-per-slide).
 * Used for features carousels, case-study cards, testimonial cards
 * that don't fit in the simpler testimonials pattern.
 */
export interface GroupCarouselOptions {
  /** Visible items on desktop. Default: 3. */
  visibleItems?: number;
  /** Autoplay rotation. Default: false. */
  autoplay?: boolean;
  /** Autoplay delay in ms. Default: 5000. */
  autoplaySpeed?: number;
  /** Show navigation arrows. Default: true. */
  showArrows?: boolean;
  /** Show pagination dots. Default: true. */
  showDots?: boolean;
  /** Gap between items in px. Default: 24. */
  gap?: number;
}

export function groupCarousel(
  options: GroupCarouselOptions,
  groups: GutenbergBlock[],
): GutenbergBlock {
  const carouselAttrs: Record<string, unknown> = {
    visibleItems: String(options.visibleItems ?? 3),
    autoplay: options.autoplay ? "on" : "off",
    autoplaySpeed: String(options.autoplaySpeed ?? 5000),
    showArrows: options.showArrows === false ? "off" : "on",
    showPagination: options.showDots === false ? "off" : "on",
    gap: String(options.gap ?? 24) + "px",
  };
  return makeBlock(
    DiviBlock.GroupCarousel,
    { carousel: { innerContent: desktopValue(carouselAttrs) } },
    groups,
  );
}

/**
 * Inner Row — a row nested inside a column (sub-grid). Mirrors the
 * regular `row` builder; same options minus the constraint that a
 * row-inner can only live inside a column.
 */
export function rowInner(options: RowOptions, columns: GutenbergBlock[]): GutenbergBlock {
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
  return makeBlock(DiviBlock.RowInner, { module: moduleAttrs }, columns);
}

/**
 * Inner Column — a column inside a row-inner. Mirrors the regular
 * `column` builder.
 */
export interface ColumnInnerOptions {
  type: ColumnStructure | string;
  fullWidthOnMobile?: boolean;
}

export function columnInner(options: ColumnInnerOptions, modules: GutenbergBlock[]): GutenbergBlock {
  const attrs: Record<string, unknown> = {
    module: {
      advanced: { type: desktopValue(options.type) },
    },
  };
  if (options.fullWidthOnMobile) {
    (attrs.module as Record<string, Record<string, unknown>>).advanced.sizing = {
      phone: { value: { width: "100%" } },
      phoneWide: { value: { width: "100%" } },
    };
  }
  return makeBlock(DiviBlock.ColumnInner, attrs, modules);
}

/* ------------------------------------------------------------------ */
/* Post-loop family (4) — shared helper                               */
/* ------------------------------------------------------------------ */

/**
 * Shared options for post-loop modules (blog, portfolio,
 * filterable-portfolio, post-slider). Each loop's builder picks the
 * relevant subset and adds its own specifics.
 */
interface PostLoopOptions {
  /** Number of posts to display. Default: 10. */
  postsNumber?: number;
  /** Category slugs to include. */
  categories?: string[];
  /** Custom post type. Default: implicit per module. */
  postType?: string;
  /** Order. Default: "DESC" by date. */
  order?: "ASC" | "DESC";
  /** Orderby. Default: "date". */
  orderby?: "date" | "title" | "menu_order" | "rand";
}

function postLoopInner(options: PostLoopOptions, extra: Record<string, unknown>): Record<string, unknown> {
  const inner: Record<string, unknown> = {
    postsNumber: String(options.postsNumber ?? 10),
    order: options.order ?? "DESC",
    orderBy: options.orderby ?? "date",
    ...extra,
  };
  if (options.categories && options.categories.length > 0) {
    inner.includeCategories = options.categories.join(",");
  }
  if (options.postType) {
    inner.postType = options.postType;
  }
  return inner;
}

/**
 * Blog module — paginated list of posts (grid or fullwidth).
 * Defaults: 3-column grid, thumbnail on top, 270-char excerpt,
 * date + author meta, pagination on.
 */
export interface BlogOptions extends PostLoopOptions {
  /** Full-width single-column layout. Default: false (3-col grid). */
  fullwidth?: boolean;
  /** Masonry grid. Default: false. */
  masonry?: boolean;
  showThumbnail?: boolean;
  showExcerpt?: boolean;
  showAuthor?: boolean;
  showDate?: boolean;
  showCategories?: boolean;
  showReadMore?: boolean;
  showPagination?: boolean;
}

export function blog(options: BlogOptions = {}): GutenbergBlock {
  const inner = postLoopInner(options, {
    fullwidth: options.fullwidth ? "on" : "off",
    useMasonryGrid: options.masonry ? "on" : "off",
    showThumbnail: options.showThumbnail === false ? "off" : "on",
    showContent: options.showExcerpt === false ? "off" : "on",
    showAuthor: options.showAuthor === false ? "off" : "on",
    showDate: options.showDate === false ? "off" : "on",
    showCategories: options.showCategories === false ? "off" : "on",
    showReadMore: options.showReadMore === false ? "off" : "on",
    showPagination: options.showPagination === false ? "off" : "on",
  });
  return makeBlock(DiviBlock.Blog, { post: { innerContent: desktopValue(inner) } });
}

/**
 * Portfolio module — grid of "project" CPT entries. Defaults: 3
 * columns, ratio 4:3 covers, overlay-on-hover with magnifier icon,
 * title H4 centred below.
 */
export interface PortfolioOptions extends PostLoopOptions {
  /** Number of columns. Default: 3. */
  columns?: 2 | 3 | 4;
  /** Full-width single-column. Default: false. */
  fullwidth?: boolean;
  showTitle?: boolean;
  showCategories?: boolean;
}

export function portfolio(options: PortfolioOptions = {}): GutenbergBlock {
  const inner = postLoopInner(options, {
    fullwidth: options.fullwidth ? "on" : "off",
    showTitle: options.showTitle === false ? "off" : "on",
    showCategories: options.showCategories === false ? "off" : "on",
  });
  if (options.columns) {
    inner.columnsNumber = String(options.columns);
  }
  return makeBlock(DiviBlock.Portfolio, { post: { innerContent: desktopValue(inner) } });
}

/**
 * Filterable Portfolio — same as portfolio with a horizontal
 * category-filter bar above. Defaults: 3 columns, isotope animation.
 */
export interface FilterablePortfolioOptions extends PortfolioOptions {}

export function filterablePortfolio(options: FilterablePortfolioOptions = {}): GutenbergBlock {
  const block = portfolio(options);
  block.blockName = DiviBlock.FilterablePortfolio;
  return block;
}

/**
 * Post Slider — slider of posts (editorial hero / featured posts
 * carousel). Defaults: 5 latest posts, autoplay 6 s, full-bleed
 * background image with dark overlay, "Read article" button.
 */
export interface PostSliderOptions extends PostLoopOptions {
  showImage?: boolean;
  showMeta?: boolean;
  showButton?: boolean;
  buttonText?: string;
  /** Slide background variant. Default: "dark". */
  backgroundLayout?: "light" | "dark";
}

export function postSlider(options: PostSliderOptions = {}): GutenbergBlock {
  const inner = postLoopInner(
    { postsNumber: 5, ...options },
    {
      showImage: options.showImage === false ? "off" : "on",
      showMeta: options.showMeta === false ? "off" : "on",
      showMoreButton: options.showButton === false ? "off" : "on",
      moreText: options.buttonText ?? "Read article",
      backgroundLayout: options.backgroundLayout ?? "dark",
    },
  );
  return makeBlock(DiviBlock.PostSlider, { post: { innerContent: desktopValue(inner) } });
}

/* ------------------------------------------------------------------ */
/* Specialty content (5)                                              */
/* ------------------------------------------------------------------ */

/**
 * Before/After Image — comparison slider. Used for retouching,
 * renovation, transformations, esthetics. Default position 50 %.
 */
export interface BeforeAfterOptions {
  beforeImageUrl: string;
  afterImageUrl: string;
  beforeLabel?: string;
  afterLabel?: string;
  /** Slider handle colour. */
  sliderColor?: DiviColor;
  /** Initial position 0-100. Default: 50. */
  startPosition?: number;
}

export function beforeAfter(options: BeforeAfterOptions): GutenbergBlock {
  const inner: Record<string, unknown> = {
    beforeImage: { url: options.beforeImageUrl },
    afterImage: { url: options.afterImageUrl },
    beforeLabel: options.beforeLabel ?? "Before",
    afterLabel: options.afterLabel ?? "After",
    startPosition: String(options.startPosition ?? 50),
  };
  if (options.sliderColor) {
    inner.sliderColor = colorToString(options.sliderColor);
  }
  return makeBlock(DiviBlock.BeforeAfterImage, {
    image: { innerContent: desktopValue(inner) },
  });
}

/**
 * Timeline — vertical chronological track. Use the wrapper to
 * declare the full list of milestones in one call. Each item
 * becomes a `timeline-item` child. Defaults: alternating
 * left/right, primary-colour central line, round icons.
 */
export interface TimelineEntry {
  title: string;
  date: string;
  contentHtml?: string;
  imageUrl?: string;
  /** Icon unicode (Divi font). */
  iconUnicode?: string;
  /** Force a side ("left" / "right"). Default: auto (alternating). */
  side?: "left" | "right" | "auto";
}

export function timeline(items: TimelineEntry[]): GutenbergBlock {
  const innerBlocks = items.map((item, idx) => timelineItem(item, idx % 2 === 0 ? "left" : "right"));
  return makeBlock(DiviBlock.Timeline, {}, innerBlocks);
}

/**
 * Timeline Item — one entry of a timeline. Use directly only when
 * `timeline()` doesn't fit (e.g. you want to mix custom inner
 * modules between standard items).
 */
export function timelineItem(item: TimelineEntry, defaultSide: "left" | "right" = "left"): GutenbergBlock {
  const side = item.side === "auto" ? defaultSide : item.side ?? defaultSide;
  const inner: Record<string, unknown> = {
    title: item.title,
    date: item.date,
    contentHtml: item.contentHtml ?? "",
    side,
  };
  if (item.imageUrl) inner.image = { url: item.imageUrl };
  if (item.iconUnicode) inner.iconUnicode = item.iconUnicode;
  return makeBlock(DiviBlock.TimelineItem, {
    item: { innerContent: desktopValue(inner) },
  });
}

/**
 * Lottie — vector animation from a Lottie/JSON file. Defaults:
 * autoplay, infinite loop, triggered on load, 100 % width,
 * centred.
 */
export interface LottieOptions {
  /** URL to a .json or .lottie file. */
  animationUrl: string;
  loop?: boolean;
  autoplay?: boolean;
  /** Playback speed multiplier. Default: 1. */
  speed?: number;
  /** What triggers playback. Default: "load". */
  trigger?: "load" | "hover" | "scroll";
  /** Render width (CSS). Default: "100%". */
  width?: string;
  /** Horizontal alignment. Default: "center". */
  alignment?: "left" | "center" | "right";
}

export function lottie(options: LottieOptions): GutenbergBlock {
  const inner: Record<string, unknown> = {
    src: options.animationUrl,
    loop: options.loop !== false ? "on" : "off",
    autoplay: options.autoplay !== false ? "on" : "off",
    speed: String(options.speed ?? 1),
    trigger: options.trigger ?? "load",
  };
  return makeBlock(DiviBlock.Lottie, {
    animation: { innerContent: desktopValue(inner) },
    module: {
      decoration: {
        sizing: desktopValue({ width: options.width ?? "100%" }),
        alignment: desktopValue({ horizontal: options.alignment ?? "center" }),
      },
    },
  });
}

/**
 * SVG — inline SVG block. Lighter than `image` for vector
 * illustrations. Supports a hover colour transition. Default
 * width 120 px, centred.
 */
export interface SvgOptions {
  /** Raw SVG markup (xml string). */
  svgCode: string;
  width?: string;
  height?: string;
  color?: DiviColor;
  hoverColor?: DiviColor;
  alignment?: "left" | "center" | "right";
}

export function svg(options: SvgOptions): GutenbergBlock {
  const inner: Record<string, unknown> = {
    code: options.svgCode,
  };
  if (options.color) inner.color = colorToString(options.color);
  if (options.hoverColor) inner.hoverColor = colorToString(options.hoverColor);

  return makeBlock(DiviBlock.Svg, {
    svg: { innerContent: desktopValue(inner) },
    module: {
      decoration: {
        sizing: desktopValue({
          width: options.width ?? "120px",
          ...(options.height ? { height: options.height } : {}),
        }),
        alignment: desktopValue({ horizontal: options.alignment ?? "center" }),
      },
    },
  });
}

/**
 * Countdown Timer — counts down to a target date. Defaults: card
 * style, separators ":", English labels (override via `labels`).
 */
export interface CountdownOptions {
  /** Target end date (ISO 8601). */
  endDate: string;
  title?: string;
  labels?: { days?: string; hours?: string; minutes?: string; seconds?: string };
  backgroundColor?: DiviColor;
  textColor?: DiviColor;
}

export function countdown(options: CountdownOptions): GutenbergBlock {
  const inner: Record<string, unknown> = {
    endDate: options.endDate,
  };
  if (options.title) inner.title = options.title;
  if (options.labels) {
    if (options.labels.days) inner.daysText = options.labels.days;
    if (options.labels.hours) inner.hoursText = options.labels.hours;
    if (options.labels.minutes) inner.minutesText = options.labels.minutes;
    if (options.labels.seconds) inner.secondsText = options.labels.seconds;
  }

  const decoration: Record<string, unknown> = {
    spacing: desktopValue({ padding: { top: "60px", right: "60px", bottom: "60px", left: "60px" } }),
  };
  if (options.backgroundColor) {
    decoration.background = desktopValue({ color: colorToString(options.backgroundColor) });
  }
  if (options.textColor) {
    decoration.font = desktopValue({ color: colorToString(options.textColor) });
  }

  return makeBlock(DiviBlock.CountdownTimer, {
    timer: { innerContent: desktopValue(inner) },
    module: { decoration },
  });
}

/* ------------------------------------------------------------------ */
/* Layout + form widgets (4)                                          */
/* ------------------------------------------------------------------ */

/**
 * Sidebar — render a WordPress sidebar (widget area). Useful in
 * blog 2-column layouts. Default sidebar id "sidebar-1" matches
 * the WP convention.
 */
export interface SidebarOptions {
  /** WP sidebar id. Default: "sidebar-1". */
  areaId?: string;
  /** Visual orientation hint (left padding when "right"). */
  orientation?: "left" | "right";
  /** Show subtle border. Default: false. */
  showBorder?: boolean;
}

export function sidebar(options: SidebarOptions = {}): GutenbergBlock {
  const inner: Record<string, unknown> = {
    sidebarId: options.areaId ?? "sidebar-1",
    orientation: options.orientation ?? "left",
    showBorder: options.showBorder ? "on" : "off",
  };
  return makeBlock(DiviBlock.Sidebar, {
    sidebar: { innerContent: desktopValue(inner) },
  });
}

/**
 * Login — front-end WordPress login form. Defaults: redirect to
 * current page after login, max-width 480 px centred.
 */
export interface LoginOptions {
  title?: string;
  /** Redirect to the current page after login. Default: true. */
  currentPageRedirect?: boolean;
  backgroundColor?: DiviColor;
  buttonText?: string;
}

export function login(options: LoginOptions = {}): GutenbergBlock {
  const inner: Record<string, unknown> = {
    currentPageRedirect: options.currentPageRedirect !== false ? "on" : "off",
  };
  if (options.title) inner.title = options.title;
  if (options.buttonText) inner.buttonText = options.buttonText;

  const decoration: Record<string, unknown> = {
    sizing: desktopValue({ maxWidth: "480px" }),
    spacing: desktopValue({ padding: { top: "40px", right: "40px", bottom: "40px", left: "40px" } }),
    alignment: desktopValue({ horizontal: "center" }),
  };
  if (options.backgroundColor) {
    decoration.background = desktopValue({ color: colorToString(options.backgroundColor) });
  }

  return makeBlock(DiviBlock.Login, {
    login: { innerContent: desktopValue(inner) },
    module: { decoration },
  });
}

/**
 * Dropdown — standalone dropdown selector. Two behaviours:
 *  - "navigate": pick an option → window.location to its `url`
 *  - "emit": pick an option → emit a JS custom event (consumed by
 *    a page-level filter script the operator provides)
 */
export interface DropdownOption {
  value: string;
  label: string;
  /** Required if behavior === "navigate". */
  url?: string;
}

export interface DropdownOptions {
  label?: string;
  options: DropdownOption[];
  defaultValue?: string;
  /** Default: "navigate". */
  behavior?: "navigate" | "emit";
}

export function dropdown(options: DropdownOptions): GutenbergBlock {
  const inner: Record<string, unknown> = {
    options: options.options.map((o) => ({
      value: o.value,
      label: o.label,
      ...(o.url ? { url: o.url } : {}),
    })),
    behavior: options.behavior ?? "navigate",
  };
  if (options.label) inner.label = options.label;
  if (options.defaultValue) inner.defaultValue = options.defaultValue;

  return makeBlock(DiviBlock.Dropdown, {
    dropdown: { innerContent: desktopValue(inner) },
  });
}

/**
 * Signup Custom Field — extra field for the email-signup module.
 * The standalone `signup()` builder takes the canonical email-only
 * form; pass a list of these as `innerBlocks` to it (or compose
 * manually) to qualify the lead with extra inputs.
 */
export interface SignupCustomFieldOptions {
  fieldId: string;
  label: string;
  type?: "input" | "email" | "text" | "checkbox" | "radio" | "select";
  required?: boolean;
  /** For select/radio/checkbox. */
  options?: string[];
}

export function signupCustomField(options: SignupCustomFieldOptions): GutenbergBlock {
  const inner: Record<string, unknown> = {
    fieldId: options.fieldId,
    label: options.label,
    type: options.type ?? "input",
    required: options.required ? "on" : "off",
  };
  if (options.options && options.options.length > 0) {
    inner.options = options.options;
  }
  return makeBlock(DiviBlock.SignupCustomField, {
    field: { innerContent: desktopValue(inner) },
  });
}

/* ================================================================== */
/* Phase 9 — WooCommerce module builders                              */
/* ================================================================== */
/*
 * The 10 builders below cover the WooCommerce Divi 5 modules that an
 * opinionated webmaster reaches for most often when composing a
 * Theme Builder template (shop / single-product / cart / checkout).
 *
 * Most WC modules are dynamic — they pull from the current WC
 * context (product, cart, order) at render time — so the builders
 * are deliberately thin: they expose only the layout/visibility
 * toggles operators actually tune. For finer-grained customisation
 * the operator drops to the free-form catalogue
 * (`iawm_divi_module_info`).
 *
 * Naming convention: `wc<Module>` prefix to disambiguate from the
 * post-aware builders (`postTitle` vs `wcProductTitle`).
 *
 * Composition site: prefer Theme Builder layouts (via
 * `iawm_divi_theme_builder_compose`). Standalone pages work but the
 * modules only render meaningfully when the page itself is being
 * viewed in the right WC context — see docs/woocommerce-integration.md.
 */

/* ------------------------------------------------------------------ */
/* Single-product context (7)                                         */
/* ------------------------------------------------------------------ */

/**
 * WooCommerce Product Title — dynamic title of the current product
 * (Theme Builder single-product templates). Defaults: H1, hooked to
 * the design system heading typography.
 */
export interface WcProductTitleOptions {
  /** Heading level. Default: "h1". */
  headingLevel?: "h1" | "h2" | "h3";
}

export function wcProductTitle(options: WcProductTitleOptions = {}): GutenbergBlock {
  const inner: Record<string, unknown> = {};
  if (options.headingLevel) inner.headerLevel = options.headingLevel;
  const attrs: Record<string, unknown> = {};
  if (Object.keys(inner).length > 0) {
    attrs.title = { innerContent: desktopValue(inner) };
  }
  return makeBlock(DiviBlock.WoocommerceProductTitle, attrs);
}

/**
 * WooCommerce Product Price — current/sale price. Defaults: stock
 * Divi rendering with the strikethrough for sale prices.
 */
export interface WcProductPriceOptions {
  /** Optional CSS alignment override. */
  alignment?: "left" | "center" | "right";
}

export function wcProductPrice(options: WcProductPriceOptions = {}): GutenbergBlock {
  const attrs: Record<string, unknown> = {};
  if (options.alignment) {
    attrs.module = {
      decoration: { alignment: desktopValue({ horizontal: options.alignment }) },
    };
  }
  return makeBlock(DiviBlock.WoocommerceProductPrice, attrs);
}

/**
 * WooCommerce Product Images — main gallery + lightbox. Defaults:
 * lightbox on, sale-flash on, square thumbnails.
 */
export interface WcProductImagesOptions {
  /** Show the main image. Default: true. */
  showProductImage?: boolean;
  /** Show the gallery strip. Default: true. */
  showProductGallery?: boolean;
  /** Show the sale flash. Default: true. */
  showSaleBadge?: boolean;
  /** Open in lightbox. Default: true. */
  lightbox?: boolean;
}

export function wcProductImages(options: WcProductImagesOptions = {}): GutenbergBlock {
  const inner: Record<string, unknown> = {
    showProductImage: options.showProductImage === false ? "off" : "on",
    showProductGallery: options.showProductGallery === false ? "off" : "on",
    showSaleBadge: options.showSaleBadge === false ? "off" : "on",
    lightbox: options.lightbox === false ? "off" : "on",
  };
  return makeBlock(DiviBlock.WoocommerceProductImages, {
    image: { innerContent: desktopValue(inner) },
  });
}

/**
 * WooCommerce Add-to-Cart — the buy-now button + quantity selector.
 * Defaults: alignment left, button text uses the WC default
 * ("Add to cart" / "Read more" depending on product type).
 */
export interface WcProductAddToCartOptions {
  /** Override the button text. */
  buttonText?: string;
  /** Show the quantity input. Default: true (in-stock simple products). */
  showQuantity?: boolean;
  /** Show stock availability under the button. Default: true. */
  showStock?: boolean;
}

export function wcProductAddToCart(options: WcProductAddToCartOptions = {}): GutenbergBlock {
  const inner: Record<string, unknown> = {
    showQuantity: options.showQuantity === false ? "off" : "on",
    showStock: options.showStock === false ? "off" : "on",
  };
  if (options.buttonText) inner.buttonText = options.buttonText;
  return makeBlock(DiviBlock.WoocommerceProductAddToCart, {
    button: { innerContent: desktopValue(inner) },
  });
}

/**
 * WooCommerce Product Description — short or long description block.
 * Default: short description on the cart-side, full description in
 * the tabs section. Operators pin which one with `descriptionType`.
 */
export interface WcProductDescriptionOptions {
  /** Which description body to render. Default: "short". */
  descriptionType?: "short" | "long";
}

export function wcProductDescription(options: WcProductDescriptionOptions = {}): GutenbergBlock {
  const inner: Record<string, unknown> = {
    descriptionType: options.descriptionType ?? "short",
  };
  return makeBlock(DiviBlock.WoocommerceProductDescription, {
    description: { innerContent: desktopValue(inner) },
  });
}

/**
 * WooCommerce Product Tabs — the bottom-of-page tabs (description,
 * additional information, reviews). Lets the operator pick the
 * initial tab.
 */
export interface WcProductTabsOptions {
  /** Default open tab. */
  activeTab?: "description" | "additional_information" | "reviews";
  /** List of tab keys to display (in order). Default: all three. */
  includeTabs?: ("description" | "additional_information" | "reviews")[];
}

export function wcProductTabs(options: WcProductTabsOptions = {}): GutenbergBlock {
  const inner: Record<string, unknown> = {};
  if (options.activeTab) inner.activeTab = options.activeTab;
  if (options.includeTabs && options.includeTabs.length > 0) {
    inner.includeTabs = options.includeTabs.join(",");
  }
  const attrs: Record<string, unknown> = {};
  if (Object.keys(inner).length > 0) {
    attrs.tabs = { innerContent: desktopValue(inner) };
  }
  return makeBlock(DiviBlock.WoocommerceProductTabs, attrs);
}

/**
 * WooCommerce Related Products — grid of related products at the
 * bottom of a single-product template. Defaults: 4 columns, 4 items,
 * order by relevance.
 */
export interface WcRelatedProductsOptions {
  /** Maximum items to show. Default: 4. */
  postsNumber?: number;
  /** Columns on desktop. Default: 4. */
  columns?: 2 | 3 | 4 | 5;
  /** Order. Default: random (WC default). */
  orderby?: "rand" | "date" | "title" | "popularity" | "rating";
}

export function wcRelatedProducts(options: WcRelatedProductsOptions = {}): GutenbergBlock {
  const inner: Record<string, unknown> = {
    postsNumber: String(options.postsNumber ?? 4),
    columnsNumber: String(options.columns ?? 4),
    orderBy: options.orderby ?? "rand",
  };
  return makeBlock(DiviBlock.WoocommerceRelatedProducts, {
    related: { innerContent: desktopValue(inner) },
  });
}

/* ------------------------------------------------------------------ */
/* Cart context (2)                                                   */
/* ------------------------------------------------------------------ */

/**
 * WooCommerce Cart Products — line items + thumbnails + quantity
 * editors. Defaults: with thumbnails, allow quantity edits.
 */
export interface WcCartProductsOptions {
  /** Show product thumbnails. Default: true. */
  showThumbnail?: boolean;
  /** Allow quantity editing in the cart. Default: true. */
  showQuantity?: boolean;
}

export function wcCartProducts(options: WcCartProductsOptions = {}): GutenbergBlock {
  const inner: Record<string, unknown> = {
    showThumbnail: options.showThumbnail === false ? "off" : "on",
    showQuantity: options.showQuantity === false ? "off" : "on",
  };
  return makeBlock(DiviBlock.WoocommerceCartProducts, {
    cart: { innerContent: desktopValue(inner) },
  });
}

/**
 * WooCommerce Cart Totals — subtotal / shipping / tax / total
 * summary box with proceed-to-checkout button. Few options — the
 * module pulls everything from the WC totals context.
 */
export interface WcCartTotalsOptions {
  /** Show the proceed-to-checkout button. Default: true. */
  showProceedButton?: boolean;
}

export function wcCartTotals(options: WcCartTotalsOptions = {}): GutenbergBlock {
  const inner: Record<string, unknown> = {
    showProceedButton: options.showProceedButton === false ? "off" : "on",
  };
  return makeBlock(DiviBlock.WoocommerceCartTotals, {
    totals: { innerContent: desktopValue(inner) },
  });
}

/* ------------------------------------------------------------------ */
/* Checkout context (1)                                               */
/* ------------------------------------------------------------------ */

/**
 * WooCommerce Checkout Billing — billing fields block (name, email,
 * address, phone). Field set itself comes from the WC checkout
 * config; this builder controls the label/layout knobs.
 */
export interface WcCheckoutBillingOptions {
  /** Show the section title above the fields. Default: true. */
  showTitle?: boolean;
  /** Section title override. */
  title?: string;
}

export function wcCheckoutBilling(options: WcCheckoutBillingOptions = {}): GutenbergBlock {
  const inner: Record<string, unknown> = {
    showTitle: options.showTitle === false ? "off" : "on",
  };
  if (options.title) inner.title = options.title;
  return makeBlock(DiviBlock.WoocommerceCheckoutBilling, {
    billing: { innerContent: desktopValue(inner) },
  });
}
