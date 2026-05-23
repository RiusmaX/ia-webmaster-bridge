/**
 * Pattern Pricing 3 colonnes — section de tarifs (3 plans).
 *
 * Structure :
 *   Section
 *     Row 4_4 (titre + sous-titre)
 *     Row 4_4
 *       Column 4_4
 *         PricingTables
 *           PricingTable × 3
 */

import { section, row, column, text, pricingTables } from "../builders.js";
import type { PricingTableOptions } from "../builders.js";
import type { DiviColor, GutenbergBlock } from "../types.js";

export interface PricingPlan extends PricingTableOptions {}

export interface Pricing3ColOptions {
  sectionTitle?: string;
  sectionSubtitle?: string;
  plans: [PricingPlan, PricingPlan, PricingPlan];
  backgroundColor?: DiviColor;
}

export function pricing3col(options: Pricing3ColOptions): GutenbergBlock {
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
      [column({ type: "4_4" }, [pricingTables(options.plans)])],
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
