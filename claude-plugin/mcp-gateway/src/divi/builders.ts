/**
 * Constructeurs bas-niveau de blocs Divi 5.
 *
 * Toutes les fonctions produisent des `GutenbergBlock` parse_blocks-compatibles,
 * prêts à être envoyés à `iawm_divi_page_write` (param `blocks`) ou
 * sérialisés via `serialize_blocks` côté PHP.
 *
 * Format documenté dans `docs/divi5-format.md`. Les attributs suivent la
 * structure observée :
 *   module: { advanced, decoration: { background, spacing, sizing, layout } }
 *   content / title / button / image / imageIcon : par module
 *
 * Multi-breakpoints : pour l'instant on pose seulement "desktop" — Divi
 * hérite automatiquement aux autres breakpoints. Les overrides phone/tablet
 * sont à ajouter pour le responsive fin.
 */

import { BUILDER_VERSION, DiviBlock } from "./types.js";
import type {
  ColumnStructure,
  DiviColor,
  GutenbergBlock,
  Spacing,
} from "./types.js";
import { colorToString } from "./globals.js";

/** Construit un GutenbergBlock vide avec les bons champs structurels. */
function makeBlock(name: string, attrs: Record<string, unknown>, innerBlocks: GutenbergBlock[] = []): GutenbergBlock {
  return {
    blockName: name,
    attrs: { ...attrs, builderVersion: BUILDER_VERSION },
    innerBlocks,
    innerHTML: "",
    innerContent: innerBlocks.length === 0 ? [null] : new Array(innerBlocks.length + 1).fill(null),
  };
}

/** Pose une valeur "desktop" sur un attribut (helper). */
function desktopValue<T>(value: T): { desktop: { value: T } } {
  return { desktop: { value } };
}

/* ------------------------------------------------------------------ */
/* Structurels : placeholder, section, row, column                    */
/* ------------------------------------------------------------------ */

/**
 * Wrapper racine `wp:divi/placeholder`. Obligatoire au top-level d'une
 * page Divi 5 (sans lui, Divi ne prend pas le contrôle du rendu).
 */
export function placeholder(children: GutenbergBlock[]): GutenbergBlock {
  return makeBlock(DiviBlock.Placeholder, {}, children);
}

/**
 * Section Divi (bande horizontale pleine largeur).
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
 * Row Divi (ligne dans une section). La structure de colonnes (1_3,1_3,1_3
 * etc.) est obligatoire et doit correspondre au nombre de columns enfants.
 */
export interface RowOptions {
  columnStructure: ColumnStructure;
  flexWrapMobile?: "wrap" | "nowrap";
  spacing?: { padding?: Spacing; margin?: Spacing };
}

export function row(options: RowOptions, columns: GutenbergBlock[]): GutenbergBlock {
  // flexColumnStructure dérivé du nombre de colonnes (observé sur la page 19).
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
 * Column Divi (une part de row). `type` doit matcher la structure
 * (`1_3`, `1_2`, `4_4`, etc.).
 */
export interface ColumnOptions {
  /** Notation `a_b` ex. "1_3". */
  type: string;
  /** Si true, la colonne passe en pleine largeur sur mobile. Défaut: true. */
  fullWidthOnMobile?: boolean;
}

/** Conversion `1_3` → `8_24`, `1_2` → `12_24`, `4_4` → `24_24`. */
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
/* Modules feuilles                                                   */
/* ------------------------------------------------------------------ */

/**
 * Module Texte. Le contenu HTML peut inclure des balises h1-h6, p, span,
 * etc. Pour styler les titres (taille, couleur, alignement), utiliser
 * l'option `headingFont`.
 */
export interface TextOptions {
  /** HTML du contenu, ex. "<h1>Bienvenue</h1>". */
  html: string;
  /** Styles par tag (h1, h2, …). */
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
        // En réalité la couleur de heading se met dans une sous-structure
        // séparée — gardée simple ici, à raffiner après peuplement de la page 29.
        value.color = colorToString(settings.color);
      }
      fontDecoration[tag] = { font: desktopValue(value) };
    }
    contentAttrs.decoration = { headingFont: fontDecoration };
  }

  return makeBlock(DiviBlock.Text, { content: contentAttrs });
}

/**
 * Module Blurb (icône + titre + texte) — idéal pour les listes de features.
 */
export interface BlurbOptions {
  title: string;
  contentHtml: string;
  /** Code unicode d'une icône Divi, ex. "&#xe0e1;". */
  iconUnicode?: string;
  /** URL d'image en alternative à l'icône. */
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
 * Module Call To Action (titre + texte + bouton).
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
 * Module Image.
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
 * Module Heading (titre dédié, alternative à text avec h1).
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
 * Module Button (bouton seul). linkUrl peut être une variable Divi
 * (ex. `$variable({"type":"content","value":{"name":"home_url",…}})$`).
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
 * Module Number Counter (chiffre animé au scroll, idéal pour KPIs).
 */
export interface NumberCounterOptions {
  title: string;
  /** Valeur à afficher (string pour supporter "1.5k" si besoin). */
  number: string;
  /** Ajoute un "%" auto. Défaut : false. */
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
 * Module Testimonial (citation client avec photo).
 */
export interface TestimonialOptions {
  quoteHtml: string;
  author: string;
  /** URL de la photo (optionnel). */
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
 * Module Gallery (liste d'IDs media WP).
 */
export interface GalleryOptions {
  /** IDs d'attachments WP. */
  ids: number[];
  /** Nb de colonnes en desktop. Défaut : 4. */
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
 * Module Video (URL YouTube/Vimeo/mp4).
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
 * Module Code (HTML brut — à utiliser avec parcimonie).
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
/* Modules composés (nested)                                          */
/* ------------------------------------------------------------------ */

/**
 * Item d'accordion.
 */
export interface AccordionItemOptions {
  title: string;
  contentHtml: string;
  /** Ouvert par défaut ? */
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
 * Module Accordion (contient des accordion-item).
 */
export function accordion(items: AccordionItemOptions[]): GutenbergBlock {
  return makeBlock(
    DiviBlock.Accordion,
    {},
    items.map((item) => accordionItem(item)),
  );
}

/**
 * Tab d'un module Tabs.
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
 * Module Tabs (contient des tab).
 */
export function tabs(items: TabOptions[]): GutenbergBlock {
  return makeBlock(
    DiviBlock.Tabs,
    {},
    items.map((item) => tab(item)),
  );
}

/**
 * Slide d'un module Slider.
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
 * Module Slider (contient des slide).
 */
export function slider(items: SlideOptions[]): GutenbergBlock {
  return makeBlock(
    DiviBlock.Slider,
    {},
    items.map((item) => slide(item)),
  );
}

/**
 * Champ d'un formulaire de contact.
 */
export interface ContactFieldOptions {
  /** Identifiant interne (sans espaces : "name", "email", "message"). */
  id: string;
  /** Libellé visible. */
  label: string;
  /** Type. */
  type?: "input" | "email" | "text" | "phone" | "select" | "checkbox" | "radio";
  /** Champ pleine largeur ? Défaut : false (sauf "text"). */
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
 * Module Contact Form (contient des contact-field).
 *
 * Le uniqueId est généré automatiquement (UUID-like) — utilisé par Divi
 * pour identifier les soumissions dans les notifications email.
 */
export interface ContactFormOptions {
  fields: ContactFieldOptions[];
  /** UUID custom (sinon généré). */
  uniqueId?: string;
}

function generateUniqueId(): string {
  // Format UUID-like minimaliste suffisant pour Divi.
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
/* Modules prioritaires Phase 3.5 (page de référence n°53)            */
/* ------------------------------------------------------------------ */

/**
 * Module Divider — séparateur visuel.
 */
export interface DividerOptions {
  /** Couleur de la ligne. */
  color?: DiviColor;
  /** Hauteur en pixels. */
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
 * Module Icon — icône standalone (Divi font icon).
 */
export interface IconOptions {
  /** Code unicode de l'icône Divi, ex. "&#xe0e1;". */
  unicode: string;
  /** Couleur de l'icône. */
  color?: DiviColor;
  /** Taille (ex. "48px"). */
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
 * Module Toggle — bloc révélable (équivalent accordion 1 item).
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
 * Item d'une grille de pricing.
 */
export interface PricingTableOptions {
  title: string;
  subtitle?: string;
  /** Symbole monétaire ("$", "€", "£"…). */
  currency?: string;
  price: string;
  /** Fréquence ("month", "year"…). */
  frequency?: string;
  /** Liste de features : commence par `+` (incluse) ou `-` (non incluse). */
  features: Array<{ text: string; included?: boolean }>;
  /** Texte du bouton (optionnel). */
  buttonText?: string;
  buttonUrl?: string;
}

export function pricingTable(options: PricingTableOptions): GutenbergBlock {
  // Format content : "+ feature\n+ feature\n- feature"
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
 * Module Pricing Tables (contient des pricing-table).
 */
export function pricingTables(items: PricingTableOptions[]): GutenbergBlock {
  return makeBlock(
    DiviBlock.PricingTables,
    {},
    items.map((i) => pricingTable(i)),
  );
}

/**
 * Item d'une icon-list.
 */
export interface IconListItemOptions {
  text: string;
  /** Code unicode de l'icône Divi. */
  iconUnicode?: string;
  /** Lien optionnel. */
  url?: string;
  /** Ouvrir dans un nouvel onglet. */
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
 * Module Icon List (contient des icon-list-item).
 */
export function iconList(items: IconListItemOptions[]): GutenbergBlock {
  return makeBlock(
    DiviBlock.IconList,
    {},
    items.map((i) => iconListItem(i)),
  );
}

/**
 * Réseau social pour Social Media Follow.
 */
export interface SocialNetworkOptions {
  /** Identifiant : "facebook", "twitter", "instagram", "linkedin", "youtube", "tiktok"… */
  network: string;
  /** Label affiché (utile pour l'accessibilité). */
  label?: string;
  /** URL du profil (sinon Divi propose une URL par défaut). */
  url?: string;
}

/** Couleurs de marque par défaut. */
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
 * Module Social Media Follow (contient des social-media-follow-network).
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
 * Module Team Member — membre d'équipe.
 */
export interface TeamMemberOptions {
  name: string;
  position: string;
  /** URL de la photo. */
  imageUrl?: string;
  /** Bio en HTML. */
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
 * Module Signup — email opt-in (newsletter).
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
 * Module Map — Google Maps.
 *
 * Options : adresse, zoom, marqueurs (à enrichir en fonction des besoins).
 */
export interface MapOptions {
  /** Adresse (ex. "1 rue de la Paix, Paris"). */
  address?: string;
  /** Niveau de zoom (1-22). */
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
 * Module Circle Counter — pourcentage en cercle animé.
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
 * Item d'un Counters (bar counters / skill bars).
 */
export interface CounterItemOptions {
  title: string;
  /** Pourcentage (0-100). */
  progress: string;
}

export function counter(options: CounterItemOptions): GutenbergBlock {
  return makeBlock(DiviBlock.Counter, {
    title: { innerContent: desktopValue(options.title) },
    barProgress: { innerContent: desktopValue(options.progress) },
  });
}

/**
 * Module Counters (barres animées de compétences/progression).
 *
 * Le blockName est `divi/counters` (et NON `divi/bar-counters`).
 */
export interface CountersOptions {
  items: CounterItemOptions[];
  /** Afficher le pourcentage à droite ? Défaut : true. */
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
 * Module Audio — lecteur audio HTML5.
 */
export interface AudioOptions {
  title: string;
  artistName?: string;
  /** URL du fichier audio. */
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
/* Theme Builder & contenu dynamique (Phase 3.6)                      */
/* ------------------------------------------------------------------ */

/**
 * Module Menu — barre de navigation (menu WP).
 *
 * Affiche un menu WordPress par son ID. Idéal dans un header.
 */
export interface MenuOptions {
  /** ID du menu WP. Si omis, Divi affiche le menu par défaut. */
  menuId?: number;
  /** Logo à gauche du menu (URL). */
  logoUrl?: string;
  /** Direction des sous-menus desktop. "downwards" (défaut) | "upwards". */
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
 * Module Fullwidth Menu — version pleine largeur du Menu.
 */
export function fullwidthMenu(options: MenuOptions = {}): GutenbergBlock {
  // Mêmes attributs que Menu, juste le blockName diffère.
  const block = menu(options);
  block.blockName = DiviBlock.FullwidthMenu;
  return block;
}

/**
 * Module Search — barre de recherche WordPress.
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
 * Module Breadcrumbs — fil d'Ariane (utile SEO + nav).
 */
export interface BreadcrumbsOptions {
  /** Texte du lien Accueil. Défaut : "Home". */
  homeText?: string;
  /** Séparateur entre items. Défaut : "/". */
  separator?: string;
  /** Balise HTML wrapper. Défaut : "nav". */
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
 * Module Post Title — titre dynamique du post courant (Theme Builder).
 *
 * À utiliser dans les templates de single post / page pour afficher
 * automatiquement le titre du post visité.
 */
export interface PostTitleOptions {
  /** Inclut la meta (date, auteur). Défaut : false. */
  includeMeta?: boolean;
  /** Inclut l'image vedette en arrière-plan. Défaut : false. */
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
 * Module Post Content — contenu dynamique du post courant (Theme Builder).
 */
export function postContent(): GutenbergBlock {
  return makeBlock(DiviBlock.PostContent, {});
}

/**
 * Module Post Navigation — liens précédent / suivant.
 */
export interface PostNavigationOptions {
  prevText?: string;
  nextText?: string;
  /** Limiter aux articles de la même catégorie. */
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
  return makeBlock(DiviBlock.PostNavigation, attrs);
}

/**
 * Module Comments — commentaires WP.
 */
export function comments(): GutenbergBlock {
  return makeBlock(DiviBlock.Comments, {});
}
