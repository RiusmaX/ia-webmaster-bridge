/**
 * Features 3-column pattern — benefits section.
 *
 * Structure:
 *   Section
 *     Row (1_3,1_3,1_3, wrap on mobile)
 *       Column × 3
 *         Blurb (icon + title + text)
 */

import { section, row, column, blurb, text } from "../builders.js";
import { colors } from "../globals.js";
import type { DiviColor, GutenbergBlock } from "../types.js";

export interface FeatureItem {
  /** Short title (3-6 words). */
  title: string;
  /** Description (HTML allowed, ideally 1-2 sentences). */
  contentHtml: string;
  /** Unicode code of a Divi icon, e.g. "&#xe0e1;". */
  iconUnicode?: string;
  /** Alternative image URL (16:9 or square). */
  imageUrl?: string;
}

export interface Features3ColOptions {
  /** Optional: section title (becomes an H2 on top). */
  sectionTitle?: string;
  /** Optional subtitle. */
  sectionSubtitle?: string;
  /** The 3 features. */
  items: [FeatureItem, FeatureItem, FeatureItem];
  /** Section background color. Default: transparent (inherits). */
  backgroundColor?: DiviColor;
}

export function features3col(options: Features3ColOptions): GutenbergBlock {
  const rows: GutenbergBlock[] = [];

  // Title row if provided.
  if (options.sectionTitle || options.sectionSubtitle) {
    const titleHtml =
      (options.sectionTitle ? `<h2>${escapeHtml(options.sectionTitle)}</h2>` : "") +
      (options.sectionSubtitle ? `<p>${escapeHtml(options.sectionSubtitle)}</p>` : "");

    rows.push(
      row(
        { columnStructure: "4_4" },
        [
          column(
            { type: "4_4" },
            [
              text({
                html: titleHtml,
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

  // Features row.
  const columns = options.items.map((item) =>
    column({ type: "1_3" }, [
      blurb({
        title: item.title,
        contentHtml: item.contentHtml,
        iconUnicode: item.iconUnicode,
        imageUrl: item.imageUrl,
      }),
    ]),
  );

  rows.push(
    row({ columnStructure: "1_3,1_3,1_3", flexWrapMobile: "wrap" }, columns),
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
