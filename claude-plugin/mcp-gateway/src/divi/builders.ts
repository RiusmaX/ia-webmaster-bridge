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
