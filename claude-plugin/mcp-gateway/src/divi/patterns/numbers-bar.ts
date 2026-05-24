/**
 * Numbers Bar pattern — key-figures bar (KPIs).
 *
 * Ideal for quickly showing: number of customers, satisfaction rate, years of
 * expertise, etc. Strong social proof.
 *
 * Structure:
 *   Section (bg primary or alternated)
 *     Row 1_3,1_3,1_3 or 1_4,1_4,1_4,1_4
 *       Column × N
 *         Number Counter
 */

import { section, row, column, numberCounter } from "../builders.js";
import type { DiviColor, GutenbergBlock } from "../types.js";

export interface NumberItem {
  label: string;
  /** Value (string to support "1.5k", "10M+", etc.). */
  number: string;
  /** Auto-appends "%"? */
  percent?: boolean;
}

export interface NumbersBarOptions {
  items: NumberItem[];
  backgroundColor?: DiviColor;
}

export function numbersBar(options: NumbersBarOptions): GutenbergBlock {
  const count = options.items.length;
  let structure: string;
  let colType: string;

  if (count === 2) {
    structure = "1_2,1_2";
    colType = "1_2";
  } else if (count === 4) {
    structure = "1_4,1_4,1_4,1_4";
    colType = "1_4";
  } else {
    // Default: 3 columns (ideal for 3 KPIs).
    structure = "1_3,1_3,1_3";
    colType = "1_3";
  }

  return section(
    {
      backgroundColor: options.backgroundColor,
      spacing: {
        padding: { top: "60px", bottom: "60px", syncVertical: "off", syncHorizontal: "off" },
      },
    },
    [
      row(
        { columnStructure: structure, flexWrapMobile: "wrap" },
        options.items.map((item) =>
          column(
            { type: colType },
            [
              numberCounter({
                title: item.label,
                number: item.number,
                percent: item.percent,
              }),
            ],
          ),
        ),
      ),
    ],
  );
}
