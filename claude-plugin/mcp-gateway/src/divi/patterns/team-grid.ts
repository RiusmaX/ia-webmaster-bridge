/**
 * Pattern Team Grid — grille de membres d'équipe.
 *
 * Structure :
 *   Section
 *     Row 4_4 (titre + sous-titre)
 *     Row (1/2/3/4 colonnes selon nb d'items)
 *       Column × N
 *         TeamMember
 */

import { section, row, column, text, teamMember } from "../builders.js";
import type { TeamMemberOptions } from "../builders.js";
import type { DiviColor, GutenbergBlock } from "../types.js";

export interface TeamGridOptions {
  sectionTitle?: string;
  sectionSubtitle?: string;
  members: TeamMemberOptions[];
  /** Nombre de colonnes (1, 2, 3 ou 4). Défaut : auto selon members.length. */
  columnsCount?: 1 | 2 | 3 | 4;
  backgroundColor?: DiviColor;
}

const COLUMN_STRUCTURES: Record<number, { structure: string; colType: string }> = {
  1: { structure: "4_4", colType: "4_4" },
  2: { structure: "1_2,1_2", colType: "1_2" },
  3: { structure: "1_3,1_3,1_3", colType: "1_3" },
  4: { structure: "1_4,1_4,1_4,1_4", colType: "1_4" },
};

export function teamGrid(options: TeamGridOptions): GutenbergBlock {
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

  // Auto columns : 1 → 1col, 2 → 2col, 3 → 3col, 4+ → 4col (avec wrap).
  const explicitCount = options.columnsCount;
  const count = explicitCount ?? (Math.min(4, Math.max(1, options.members.length)) as 1 | 2 | 3 | 4);
  const { structure, colType } = COLUMN_STRUCTURES[count];

  // Découpage en groupes de `count` items par row.
  for (let i = 0; i < options.members.length; i += count) {
    const group = options.members.slice(i, i + count);
    rows.push(
      row(
        { columnStructure: structure, flexWrapMobile: "wrap" },
        group.map((m) => column({ type: colType }, [teamMember(m)])),
      ),
    );
  }

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
