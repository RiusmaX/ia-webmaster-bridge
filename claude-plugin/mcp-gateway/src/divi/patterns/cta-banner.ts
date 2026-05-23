/**
 * Pattern CTA Banner — bandeau d'appel à l'action en fin de page.
 *
 * Structure :
 *   Section (bg color = primary, padding généreux)
 *     Row (4_4)
 *       Column (4_4)
 *         CTA (titre + texte + bouton)
 */

import { section, row, column, cta } from "../builders.js";
import { colors } from "../globals.js";
import type { DiviColor, GutenbergBlock } from "../types.js";

export interface CtaBannerOptions {
  /** Titre fort (idéal 5-10 mots). */
  title: string;
  /** Texte secondaire (1-2 phrases). */
  contentHtml: string;
  /** Texte du bouton (verbe d'action + bénéfice). */
  buttonText: string;
  /** URL du bouton. */
  buttonUrl: string;
  /** Couleur de fond. Défaut : couleur primaire du site. */
  backgroundColor?: DiviColor;
}

export function ctaBanner(options: CtaBannerOptions): GutenbergBlock {
  return section(
    {
      backgroundColor: options.backgroundColor ?? colors.primary,
      spacing: {
        padding: { top: "80px", bottom: "80px", syncVertical: "off", syncHorizontal: "off" },
      },
    },
    [
      row(
        { columnStructure: "4_4" },
        [
          column(
            { type: "4_4" },
            [
              cta({
                title: options.title,
                contentHtml: options.contentHtml,
                buttonText: options.buttonText,
                buttonUrl: options.buttonUrl,
              }),
            ],
          ),
        ],
      ),
    ],
  );
}
