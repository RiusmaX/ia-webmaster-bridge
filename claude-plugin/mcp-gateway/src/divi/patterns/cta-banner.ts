/**
 * CTA Banner pattern — call-to-action band at the end of a page.
 *
 * Structure:
 *   Section (bg color = primary, generous padding)
 *     Row (4_4)
 *       Column (4_4)
 *         CTA (title + text + button)
 */

import { section, row, column, cta } from "../builders.js";
import { colors } from "../globals.js";
import type { DiviColor, GutenbergBlock } from "../types.js";

export interface CtaBannerOptions {
  /** Strong title (ideally 5-10 words). */
  title: string;
  /** Secondary text (1-2 sentences). */
  contentHtml: string;
  /** Button text (action verb + benefit). */
  buttonText: string;
  /** Button URL. */
  buttonUrl: string;
  /** Background color. Default: site primary color. */
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
