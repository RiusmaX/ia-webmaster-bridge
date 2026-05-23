/**
 * Pattern Footer Standard — footer de site multi-colonnes.
 *
 * Structure :
 *   Section (bg sombre, padding 60px)
 *     Row N colonnes (par défaut 4 : about, links, contact, social)
 *       Column × N
 *         Modules variés
 *     Row 4_4 (copyright + mentions légales)
 *       Column 4_4
 *         Text H6 centré
 */

import {
  section, row, column, text, menu, iconList, socialMediaFollow,
} from "../builders.js";
import type {
  IconListItemOptions, SocialNetworkOptions,
} from "../builders.js";
import { colors } from "../globals.js";
import type { DiviColor, GutenbergBlock } from "../types.js";

export interface FooterColumn {
  /** Titre H4 de la colonne. */
  title: string;
  /** Contenu HTML libre (peut être complété par menuId ou items). */
  contentHtml?: string;
  /** ID d'un menu WP à afficher dans cette colonne. */
  menuId?: number;
  /** Items de liste (alternative au menu/contentHtml). */
  listItems?: IconListItemOptions[];
}

export interface FooterStandardOptions {
  /** Colonnes du footer (2 à 4 idéal). */
  columns?: FooterColumn[];
  /** Réseaux sociaux à afficher dans la dernière colonne ou en bas. */
  socialNetworks?: SocialNetworkOptions[];
  /** Texte de copyright (ex. "© 2026 Mon Site. Tous droits réservés."). */
  copyright?: string;
  /** Couleur de fond. Défaut : couleur "heading" (sombre). */
  backgroundColor?: DiviColor;
  /** Couleur du texte. */
  textColor?: DiviColor;
}

export function footerStandard(options: FooterStandardOptions = {}): GutenbergBlock {
  const rows: GutenbergBlock[] = [];
  const columns = options.columns ?? [];
  const colCount = columns.length;

  // Row principale (colonnes thématiques).
  if (colCount > 0) {
    let structure: string;
    let colType: string;
    if (colCount === 2) {
      structure = "1_2,1_2";
      colType = "1_2";
    } else if (colCount === 3) {
      structure = "1_3,1_3,1_3";
      colType = "1_3";
    } else {
      structure = "1_4,1_4,1_4,1_4";
      colType = "1_4";
    }

    const cols = columns.map((col) => {
      const modules: GutenbergBlock[] = [];

      // Titre de colonne.
      modules.push(
        text({
          html: `<h4>${escapeHtml(col.title)}</h4>`,
          headingFont: { h4: { size: "18px", weight: "700" } },
        }),
      );

      // Contenu : priorité à listItems, sinon menu, sinon contentHtml.
      if (col.listItems && col.listItems.length > 0) {
        modules.push(iconList(col.listItems));
      } else if (col.menuId !== undefined) {
        modules.push(menu({ menuId: col.menuId }));
      } else if (col.contentHtml) {
        modules.push(text({ html: col.contentHtml }));
      }

      return column({ type: colType }, modules);
    });

    rows.push(row({ columnStructure: structure, flexWrapMobile: "wrap" }, cols));
  }

  // Row réseaux sociaux + copyright.
  const bottomModules: GutenbergBlock[] = [];
  if (options.socialNetworks && options.socialNetworks.length > 0) {
    bottomModules.push(socialMediaFollow(options.socialNetworks));
  }
  if (options.copyright) {
    bottomModules.push(
      text({
        html: `<p style="text-align:center;">${escapeHtml(options.copyright)}</p>`,
      }),
    );
  }

  if (bottomModules.length > 0) {
    rows.push(
      row(
        { columnStructure: "4_4" },
        [column({ type: "4_4" }, bottomModules)],
      ),
    );
  }

  return section(
    {
      backgroundColor: options.backgroundColor ?? colors.heading,
      spacing: {
        padding: { top: "60px", bottom: "30px", syncVertical: "off", syncHorizontal: "off" },
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
