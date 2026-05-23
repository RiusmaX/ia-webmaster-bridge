/**
 * Pattern FAQ Accordion — section de questions/réponses.
 *
 * Structure :
 *   Section
 *     Row 4_4 (titre + sous-titre optionnels)
 *     Row 4_4 (accordion avec items)
 *
 * Bonus SEO : ajouter le schema FAQPage via le module SEO (Rank Math).
 */

import { section, row, column, text, accordion } from "../builders.js";
import type { DiviColor, GutenbergBlock } from "../types.js";

export interface FaqItem {
  question: string;
  answerHtml: string;
}

export interface FaqAccordionOptions {
  sectionTitle?: string;
  sectionSubtitle?: string;
  items: FaqItem[];
  /** Ouvrir le premier item par défaut ? Défaut : true. */
  openFirst?: boolean;
  backgroundColor?: DiviColor;
}

export function faqAccordion(options: FaqAccordionOptions): GutenbergBlock {
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

  const openFirst = options.openFirst !== false;
  const accordionItems = options.items.map((it, i) => ({
    title: it.question,
    contentHtml: it.answerHtml,
    open: i === 0 && openFirst,
  }));

  rows.push(
    row(
      { columnStructure: "4_4" },
      [column({ type: "4_4" }, [accordion(accordionItems)])],
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
