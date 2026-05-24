/**
 * Helpers for referencing Divi global variables (design system).
 *
 * Instead of hard-coding "#2ea3f2" for the primary color, we reference
 * `gcid-primary-color`. A change to the site's global palette is then
 * automatically propagated to every generated page.
 *
 * See docs/divi5-format.md § "Global variables" for the raw format.
 */

import type { DiviColor, GlobalColorName } from "./types.js";

/**
 * Serializes a Divi color to a Divi JSON string.
 *
 * - If it is a hex value (`#xxx` or `#xxxxxx`), returns it as-is.
 * - If it is a global reference `{ gcid: "gcid-primary-color" }`,
 *   builds the `$variable({...})$` string that Divi interprets.
 */
export function colorToString(color: DiviColor): string {
  if (typeof color === "string") {
    return color;
  }
  // Format observed on reference page #19:
  // $variable({"type":"color","value":{"name":"gcid-XXX","settings":{}}})$
  const json = JSON.stringify({
    type: "color",
    value: { name: color.gcid, settings: {} },
  });
  return `$variable(${json})$`;
}

/** Shortcut: reference a global color by its short name. */
export function gcid(name: GlobalColorName): DiviColor {
  return { gcid: name };
}

/** Standard Divi global colors (convenient shortcuts). */
export const colors = {
  primary: gcid("gcid-primary-color"),
  secondary: gcid("gcid-secondary-color"),
  heading: gcid("gcid-heading-color"),
  body: gcid("gcid-body-color"),
  link: gcid("gcid-link-color"),
};
