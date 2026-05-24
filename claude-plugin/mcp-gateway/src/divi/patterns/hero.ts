/**
 * Hero pattern — opening section of a landing page.
 *
 * Structure:
 *   Section (bg color + generous padding)
 *     Row (4_4, 1 column)
 *       Column (4_4)
 *         Text (H1 + subtitle)
 *         CTA (secondary title+button) OR standalone button + image
 *
 * Possible future variations: hero 2-col (text | image), hero with full-width
 * image background, hero with video background.
 */

import { section, row, column, text, cta } from "../builders.js";
import { colors } from "../globals.js";
import type { DiviColor, GutenbergBlock } from "../types.js";

export interface HeroOptions {
  /** Main title (becomes an H1). */
  title: string;
  /** Subtitle / secondary tagline (becomes a paragraph). */
  subtitle: string;
  /** Primary action button text. */
  ctaText: string;
  /** Button URL. */
  ctaUrl: string;
  /** Background color. Default: site "body" color. */
  backgroundColor?: DiviColor;
  /** Optional background image URL (takes precedence over backgroundColor). */
  backgroundImageUrl?: string;
}

export function hero(options: HeroOptions): GutenbergBlock {
  const heroText = text({
    html: `<h1>${escapeHtml(options.title)}</h1><p>${escapeHtml(options.subtitle)}</p>`,
    headingFont: {
      h1: { textAlign: "center", size: "48px", weight: "800" },
    },
  });

  const heroCta = cta({
    title: "",
    contentHtml: "",
    buttonText: options.ctaText,
    buttonUrl: options.ctaUrl,
  });

  return section(
    {
      backgroundColor: options.backgroundColor ?? colors.body,
      backgroundImageUrl: options.backgroundImageUrl,
      spacing: {
        padding: { top: "120px", bottom: "120px", syncVertical: "off", syncHorizontal: "off" },
      },
    },
    [
      row(
        { columnStructure: "4_4" },
        [column({ type: "4_4" }, [heroText, heroCta])],
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
