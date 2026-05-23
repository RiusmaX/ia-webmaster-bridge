/**
 * Pattern Testimonials — preuve sociale.
 *
 * Variante 1 : grille (3 colonnes).
 * Variante 2 : carrousel (slider) — TODO.
 */

import { section, row, column, text, testimonial } from "../builders.js";
import { colors } from "../globals.js";
import type { DiviColor, GutenbergBlock } from "../types.js";

export interface TestimonialItem {
  quoteHtml: string;
  author: string;
  portraitUrl?: string;
}

export interface TestimonialsOptions {
  sectionTitle?: string;
  sectionSubtitle?: string;
  items: TestimonialItem[];
  backgroundColor?: DiviColor;
}

export function testimonials(options: TestimonialsOptions): GutenbergBlock {
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

  // Layout adaptatif : 1, 2 ou 3 colonnes selon le nombre d'items.
  const count = options.items.length;
  const structure = count === 1 ? "4_4" : count === 2 ? "1_2,1_2" : "1_3,1_3,1_3";
  const colType = count === 1 ? "4_4" : count === 2 ? "1_2" : "1_3";

  // Si > 3, on regroupe par 3.
  const groups: TestimonialItem[][] = [];
  if (count <= 3) {
    groups.push(options.items);
  } else {
    for (let i = 0; i < count; i += 3) {
      groups.push(options.items.slice(i, i + 3));
    }
  }

  for (const group of groups) {
    rows.push(
      row(
        { columnStructure: structure, flexWrapMobile: "wrap" },
        group.map((item) => column({ type: colType }, [testimonial(item)])),
      ),
    );
  }

  return section(
    {
      backgroundColor: options.backgroundColor ?? colors.body,
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
