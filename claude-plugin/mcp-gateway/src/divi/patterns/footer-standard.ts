/**
 * Footer Standard pattern — multi-column site footer.
 *
 * Structure:
 *   Section (dark bg, 60px padding)
 *     Row N columns (4 by default: about, links, contact, social)
 *       Column × N
 *         Mixed modules
 *     Row 4_4 (copyright + legal notice)
 *       Column 4_4
 *         Centered H6 text
 */

import {
  section, row, column, text, menu, iconList, socialMediaFollow,
} from "../builders.js";
import type {
  IconListItemOptions, SocialNetworkOptions,
} from "../builders.js";
import { colors } from "../globals.js";
import type { DiviColor, GutenbergBlock } from "../types.js";

export interface FooterColumn {
  /** Column H4 title. */
  title: string;
  /** Free HTML content (may be supplemented by menuId or items). */
  contentHtml?: string;
  /** ID of a WP menu to display in this column. */
  menuId?: number;
  /** List items (alternative to menu/contentHtml). */
  listItems?: IconListItemOptions[];
}

export interface FooterStandardOptions {
  /** Footer columns (2 to 4 is ideal). */
  columns?: FooterColumn[];
  /** Social networks to display in the last column or at the bottom. */
  socialNetworks?: SocialNetworkOptions[];
  /** Copyright text (e.g. "© 2026 My Site. All rights reserved."). */
  copyright?: string;
  /** Background color. Default: "heading" color (dark). */
  backgroundColor?: DiviColor;
  /** Text color. */
  textColor?: DiviColor;
}

export function footerStandard(options: FooterStandardOptions = {}): GutenbergBlock {
  const rows: GutenbergBlock[] = [];
  const columns = options.columns ?? [];
  const colCount = columns.length;

  // Main row (thematic columns).
  if (colCount > 0) {
    let structure: string;
    let colType: string;
    if (colCount === 2) {
      structure = "1_2,1_2";
      colType = "1_2";
    } else if (colCount === 3) {
      structure = "1_3,1_3,1_3";
      colType = "1_3";
    } else {
      structure = "1_4,1_4,1_4,1_4";
      colType = "1_4";
    }

    const cols = columns.map((col) => {
      const modules: GutenbergBlock[] = [];

      // Column title.
      modules.push(
        text({
          html: `<h4>${escapeHtml(col.title)}</h4>`,
          headingFont: { h4: { size: "18px", weight: "700" } },
        }),
      );

      // Content: priority to listItems, otherwise menu, otherwise contentHtml.
      if (col.listItems && col.listItems.length > 0) {
        modules.push(iconList(col.listItems));
      } else if (col.menuId !== undefined) {
        modules.push(menu({ menuId: col.menuId }));
      } else if (col.contentHtml) {
        modules.push(text({ html: col.contentHtml }));
      }

      return column({ type: colType }, modules);
    });

    rows.push(row({ columnStructure: structure, flexWrapMobile: "wrap" }, cols));
  }

  // Social networks + copyright row.
  const bottomModules: GutenbergBlock[] = [];
  if (options.socialNetworks && options.socialNetworks.length > 0) {
    bottomModules.push(socialMediaFollow(options.socialNetworks));
  }
  if (options.copyright) {
    bottomModules.push(
      text({
        html: `<p style="text-align:center;">${escapeHtml(options.copyright)}</p>`,
      }),
    );
  }

  if (bottomModules.length > 0) {
    rows.push(
      row(
        { columnStructure: "4_4" },
        [column({ type: "4_4" }, bottomModules)],
      ),
    );
  }

  return section(
    {
      backgroundColor: options.backgroundColor ?? colors.heading,
      spacing: {
        padding: { top: "60px", bottom: "30px", syncVertical: "off", syncHorizontal: "off" },
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
