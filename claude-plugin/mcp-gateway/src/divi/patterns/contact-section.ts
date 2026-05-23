/**
 * Pattern Contact Section — section de contact avec formulaire.
 *
 * Structure (variante 1, simple) :
 *   Section
 *     Row 4_4 (titre + sous-titre)
 *     Row 4_4
 *       Column 4_4
 *         Contact Form (Nom, Email, Message par défaut)
 *
 * Variante 2 (à venir) : split image | formulaire.
 */

import { section, row, column, text, contactForm } from "../builders.js";
import type { ContactFieldOptions } from "../builders.js";
import type { DiviColor, GutenbergBlock } from "../types.js";

export interface ContactSectionOptions {
  sectionTitle?: string;
  sectionSubtitle?: string;
  /** Si omis, utilise les 3 champs standards (Nom, Email, Message). */
  fields?: ContactFieldOptions[];
  backgroundColor?: DiviColor;
}

const DEFAULT_FIELDS: ContactFieldOptions[] = [
  { id: "Name", label: "Nom", type: "input", fullwidth: false },
  { id: "Email", label: "Adresse email", type: "email", fullwidth: false },
  { id: "Message", label: "Votre message", type: "text", fullwidth: true },
];

export function contactSection(options: ContactSectionOptions): GutenbergBlock {
  const rows: GutenbergBlock[] = [];

  if (options.sectionTitle || options.sectionSubtitle) {
    rows.push(
      row(
        { columnStructure: "4_4" },
        [
          column(
            { type: "4_4" },
            [
              text({
                html:
                  (options.sectionTitle ? `<h2>${escapeHtml(options.sectionTitle)}</h2>` : "") +
                  (options.sectionSubtitle ? `<p>${escapeHtml(options.sectionSubtitle)}</p>` : ""),
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

  rows.push(
    row(
      { columnStructure: "4_4" },
      [
        column(
          { type: "4_4" },
          [contactForm({ fields: options.fields ?? DEFAULT_FIELDS })],
        ),
      ],
    ),
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
