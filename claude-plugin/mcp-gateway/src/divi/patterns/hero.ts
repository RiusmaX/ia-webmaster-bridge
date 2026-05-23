/**
 * Pattern Hero — section d'ouverture d'une landing page.
 *
 * Structure :
 *   Section (bg color + padding généreux)
 *     Row (4_4, 1 colonne)
 *       Column (4_4)
 *         Text (H1 + sous-titre)
 *         CTA (titre+bouton secondaires) OU bouton seul + image
 *
 * Variations futures possibles : hero 2-col (texte | image), hero
 * full-width image background, hero video background.
 */

import { section, row, column, text, cta } from "../builders.js";
import { colors } from "../globals.js";
import type { DiviColor, GutenbergBlock } from "../types.js";

export interface HeroOptions {
  /** Titre principal (devient un H1). */
  title: string;
  /** Sous-titre / accroche secondaire (devient un paragraphe). */
  subtitle: string;
  /** Texte du bouton d'action principal. */
  ctaText: string;
  /** URL du bouton. */
  ctaUrl: string;
  /** Couleur de fond. Défaut : couleur "body" du site. */
  backgroundColor?: DiviColor;
  /** URL d'une image de fond optionnelle (prime sur backgroundColor). */
  backgroundImageUrl?: string;
}

export function hero(options: HeroOptions): GutenbergBlock {
  const heroText = text({
    html: `<h1>${escapeHtml(options.title)}</h1><p>${escapeHtml(options.subtitle)}</p>`,
    headingFont: {
      h1: { textAlign: "center", size: "48px", weight: "800" },
    },
  });

  const heroCta = cta({
    title: "",
    contentHtml: "",
    buttonText: options.ctaText,
    buttonUrl: options.ctaUrl,
  });

  return section(
    {
      backgroundColor: options.backgroundColor ?? colors.body,
      backgroundImageUrl: options.backgroundImageUrl,
      spacing: {
        padding: { top: "120px", bottom: "120px", syncVertical: "off", syncHorizontal: "off" },
      },
    },
    [
      row(
        { columnStructure: "4_4" },
        [column({ type: "4_4" }, [heroText, heroCta])],
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
