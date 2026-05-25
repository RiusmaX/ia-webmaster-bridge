/**
 * Divi 5 types — based on the reverse engineering documented in
 * docs/divi5-format.md.
 */

/** Divi 5 breakpoint. */
export type Breakpoint = "desktop" | "tablet" | "phoneWide" | "phone";

/** A value indexed by breakpoint: { desktop: { value: T }, ... }. */
export type Responsive<T> = Partial<Record<Breakpoint, { value: T }>>;

/** Gutenberg block compatible with WP's parse_blocks / serialize_blocks. */
export interface GutenbergBlock {
  blockName: string;
  attrs: Record<string, unknown>;
  innerBlocks: GutenbergBlock[];
  innerHTML: string;
  innerContent: (string | null)[];
}

/** Reference to a Divi global color (gcid-*). */
export type GlobalColorName =
  | "gcid-primary-color"
  | "gcid-secondary-color"
  | "gcid-heading-color"
  | "gcid-body-color"
  | "gcid-link-color"
  | string; // tolerant for custom colors

/** A Divi color: either hex or a global reference. */
export type DiviColor = `#${string}` | { gcid: GlobalColorName };

/** Spacing (padding or margin) with optional sync flags. */
export interface Spacing {
  top?: string;
  right?: string;
  bottom?: string;
  left?: string;
  syncVertical?: "on" | "off";
  syncHorizontal?: "on" | "off";
}

/** Column structure of a Divi row ("a_b,c_d,..." notation). */
export type ColumnStructure =
  | "4_4"               // 1 full-width column
  | "1_2,1_2"           // 2 columns 50/50
  | "1_3,2_3"           // 33/66
  | "2_3,1_3"           // 66/33
  | "1_3,1_3,1_3"       // 3 equal columns
  | "1_4,1_4,1_4,1_4"   // 4 equal columns
  | "1_4,3_4"           // 25/75
  | "3_4,1_4"           // 75/25
  | string;             // tolerant

/** Pinned builder version (to be updated when Divi evolves). */
export const BUILDER_VERSION = "5.5.2";

/**
 * The full catalogue of Divi 5 block names lives in the auto-generated
 * `modules-registry.ts` file (re-run `node tools/scan-divi-modules.mjs`
 * after a Divi upgrade to refresh it). We re-export both the enum and
 * the runtime metadata from there so callers only need one import.
 */
export {
  DiviBlock,
  DIVI_MODULES,
  DIVI_MODULE_BY_NAME,
  type DiviBlockName,
  type DiviModuleCategory,
  type DiviModuleMeta,
} from "./modules-registry.js";
