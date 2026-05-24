/**
 * Header Simple pattern — classic site header.
 *
 * Structure:
 *   Section (no excessive vertical padding, bg primary or body)
 *     Row 1_2,1_2 (logo on the left, menu on the right)
 *       Column 1_2: Image (logo)
 *       Column 1_2: Menu
 *
 * 3-column variation with a CTA if ctaText is provided.
 */

import { section, row, column, image, menu, text } from "../builders.js";
import { colors } from "../globals.js";
import type { DiviColor, GutenbergBlock } from "../types.js";

export interface HeaderSimpleOptions {
  /** Logo URL (recommended: SVG or transparent PNG, 200x60 px). */
  logoUrl?: string;
  /** Fallback text if no logo. Becomes a styled H1. */
  siteName?: string;
  /** ID of the WP menu to display. */
  menuId?: number;
  /** Background color. Default: body (light). */
  backgroundColor?: DiviColor;
  /** Vertical padding. Default: "20px". */
  paddingY?: string;
}

export function headerSimple(options: HeaderSimpleOptions = {}): GutenbergBlock {
  const paddingY = options.paddingY ?? "20px";

  // Column 1: logo (image) or site text
  const logoModule = options.logoUrl
    ? image({ src: options.logoUrl, alt: options.siteName ?? "Logo" })
    : text({
        html: `<h1>${escapeHtml(options.siteName ?? "Site")}</h1>`,
        headingFont: { h1: { size: "28px", weight: "700" } },
      });

  // Column 2: menu
  const menuModule = menu({ menuId: options.menuId });

  return section(
    {
      backgroundColor: options.backgroundColor ?? colors.body,
      spacing: {
        padding: { top: paddingY, bottom: paddingY, syncVertical: "on", syncHorizontal: "off" },
      },
    },
    [
      row(
        { columnStructure: "1_2,1_2", flexWrapMobile: "wrap" },
        [
          column({ type: "1_2" }, [logoModule]),
          column({ type: "1_2" }, [menuModule]),
        ],
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
