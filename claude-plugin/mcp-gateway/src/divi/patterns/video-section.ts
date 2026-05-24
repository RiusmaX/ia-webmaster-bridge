/**
 * Video Section pattern — full-width demo/presentation video.
 *
 * Structure:
 *   Section
 *     Row 4_4 (optional title + subtitle)
 *     Row 4_4
 *       Column 4_4
 *         Video
 */

import { section, row, column, text, video } from "../builders.js";
import type { DiviColor, GutenbergBlock } from "../types.js";

export interface VideoSectionOptions {
  videoUrl: string;
  sectionTitle?: string;
  sectionSubtitle?: string;
  backgroundColor?: DiviColor;
}

export function videoSection(options: VideoSectionOptions): GutenbergBlock {
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
      [column({ type: "4_4" }, [video({ src: options.videoUrl })])],
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
