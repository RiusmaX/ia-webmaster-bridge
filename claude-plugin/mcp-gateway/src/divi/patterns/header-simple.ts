/**
 * Pattern Header Simple — header de site classique.
 *
 * Structure :
 *   Section (sans padding vertical excessif, bg primary ou body)
 *     Row 1_2,1_2 (logo à gauche, menu à droite)
 *       Column 1_2 : Image (logo)
 *       Column 1_2 : Menu
 *
 * Variation avec CTA (3 colonnes) si ctaText fourni.
 */

import { section, row, column, image, menu, text } from "../builders.js";
import { colors } from "../globals.js";
import type { DiviColor, GutenbergBlock } from "../types.js";

export interface HeaderSimpleOptions {
  /** URL du logo (recommandé : SVG ou PNG transparent, 200x60 px). */
  logoUrl?: string;
  /** Texte de remplacement si pas de logo. Devient un H1 stylé. */
  siteName?: string;
  /** ID du menu WP à afficher. */
  menuId?: number;
  /** Couleur de fond. Défaut : body (clair). */
  backgroundColor?: DiviColor;
  /** Padding vertical. Défaut : "20px". */
  paddingY?: string;
}

export function headerSimple(options: HeaderSimpleOptions = {}): GutenbergBlock {
  const paddingY = options.paddingY ?? "20px";

  // Colonne 1 : logo (image) ou texte du site
  const logoModule = options.logoUrl
    ? image({ src: options.logoUrl, alt: options.siteName ?? "Logo" })
    : text({
        html: `<h1>${escapeHtml(options.siteName ?? "Site")}</h1>`,
        headingFont: { h1: { size: "28px", weight: "700" } },
      });

  // Colonne 2 : menu
  const menuModule = menu({ menuId: options.menuId });

  return section(
    {
      backgroundColor: options.backgroundColor ?? colors.body,
      spacing: {
        padding: { top: paddingY, bottom: paddingY, syncVertical: "on", syncHorizontal: "off" },
      },
    },
    [
      row(
        { columnStructure: "1_2,1_2", flexWrapMobile: "wrap" },
        [
          column({ type: "1_2" }, [logoModule]),
          column({ type: "1_2" }, [menuModule]),
        ],
      ),
    ],
  );
}

function escapeHtml(s: string): string {
  return s
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;");
}
