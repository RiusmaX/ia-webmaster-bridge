/**
 * Image + Text side-by-side pattern — 2-column 50/50 section.
 *
 * Structure:
 *   Section
 *     Row (1_2,1_2, mobile wrap)
 *       Column (1_2) - imageOnLeft ? image : text
 *       Column (1_2) - imageOnLeft ? text : image
 */

import { section, row, column, text, image } from "../builders.js";
import type { DiviColor, GutenbergBlock } from "../types.js";

export interface ImageTextSplitOptions {
  /** Image URL. */
  imageUrl: string;
  imageAlt?: string;
  /** Title of the text block (becomes an H2). */
  title: string;
  /** Description (HTML allowed). */
  contentHtml: string;
  /** Image on the left (default) or on the right? */
  imageOnLeft?: boolean;
  /** Background color. */
  backgroundColor?: DiviColor;
}

export function imageTextSplit(options: ImageTextSplitOptions): GutenbergBlock {
  const imageOnLeft = options.imageOnLeft !== false;

  const imageCol = column({ type: "1_2" }, [
    image({ src: options.imageUrl, alt: options.imageAlt }),
  ]);

  const textCol = column({ type: "1_2" }, [
    text({
      html: `<h2>${escapeHtml(options.title)}</h2>${options.contentHtml}`,
      headingFont: {
        h2: { textAlign: "left", size: "32px", weight: "700" },
      },
    }),
  ]);

  return section(
    {
      backgroundColor: options.backgroundColor,
      spacing: {
        padding: { top: "80px", bottom: "80px", syncVertical: "off", syncHorizontal: "off" },
      },
    },
    [
      row(
        { columnStructure: "1_2,1_2", flexWrapMobile: "wrap" },
        imageOnLeft ? [imageCol, textCol] : [textCol, imageCol],
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
