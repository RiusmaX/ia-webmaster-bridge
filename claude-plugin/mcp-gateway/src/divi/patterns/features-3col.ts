/**
 * Pattern Features 3 colonnes — section de bénéfices.
 *
 * Structure :
 *   Section
 *     Row (1_3,1_3,1_3, wrap sur mobile)
 *       Column × 3
 *         Blurb (icône + titre + texte)
 */

import { section, row, column, blurb, text } from "../builders.js";
import { colors } from "../globals.js";
import type { DiviColor, GutenbergBlock } from "../types.js";

export interface FeatureItem {
  /** Titre court (3-6 mots). */
  title: string;
  /** Description (HTML autorisé, idéal 1-2 phrases). */
  contentHtml: string;
  /** Code unicode d'une icône Divi, ex. "&#xe0e1;". */
  iconUnicode?: string;
  /** URL d'image en alternative (16:9 ou carré). */
  imageUrl?: string;
}

export interface Features3ColOptions {
  /** Optionnel : titre de section (devient un H2 au-dessus). */
  sectionTitle?: string;
  /** Sous-titre optionnel. */
  sectionSubtitle?: string;
  /** Les 3 features. */
  items: [FeatureItem, FeatureItem, FeatureItem];
  /** Couleur de fond de la section. Défaut : transparent (hérite). */
  backgroundColor?: DiviColor;
}

export function features3col(options: Features3ColOptions): GutenbergBlock {
  const rows: GutenbergBlock[] = [];

  // Row de titre si fourni.
  if (options.sectionTitle || options.sectionSubtitle) {
    const titleHtml =
      (options.sectionTitle ? `<h2>${escapeHtml(options.sectionTitle)}</h2>` : "") +
      (options.sectionSubtitle ? `<p>${escapeHtml(options.sectionSubtitle)}</p>` : "");

    rows.push(
      row(
        { columnStructure: "4_4" },
        [
          column(
            { type: "4_4" },
            [
              text({
                html: titleHtml,
                headingFont: {
                  h2: { textAlign: "center", size: "36px", weight: "700" },
                },
              }),
            ],
          ),
        ],
      ),
    );
  }

  // Row de features.
  const columns = options.items.map((item) =>
    column({ type: "1_3" }, [
      blurb({
        title: item.title,
        contentHtml: item.contentHtml,
        iconUnicode: item.iconUnicode,
        imageUrl: item.imageUrl,
      }),
    ]),
  );

  rows.push(
    row({ columnStructure: "1_3,1_3,1_3", flexWrapMobile: "wrap" }, columns),
  );

  return section(
    {
      backgroundColor: options.backgroundColor,
      spacing: {
        padding: { top: "80px", bottom: "80px", syncVertical: "off", syncHorizontal: "off" },
      },
    },
    rows,
  );
}

function escapeHtml(s: string): string {
  return s
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;");
}
