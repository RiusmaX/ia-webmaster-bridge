/**
 * Helpers pour référencer les variables globales Divi (design system).
 *
 * Au lieu de coder en dur "#2ea3f2" pour la couleur primaire, on
 * référence `gcid-primary-color`. Une modification de la palette globale
 * du site se propage automatiquement à toutes les pages générées.
 *
 * Voir docs/divi5-format.md § "Variables globales" pour le format brut.
 */

import type { DiviColor, GlobalColorName } from "./types.js";

/**
 * Sérialise une couleur Divi en chaîne JSON Divi.
 *
 * - Si c'est un hex (`#xxx` ou `#xxxxxx`), retourne tel quel.
 * - Si c'est une référence globale `{ gcid: "gcid-primary-color" }`,
 *   construit la chaîne `$variable({...})$` que Divi interprète.
 */
export function colorToString(color: DiviColor): string {
  if (typeof color === "string") {
    return color;
  }
  // Format observé sur la page de référence n°19 :
  // $variable({"type":"color","value":{"name":"gcid-XXX","settings":{}}})$
  const json = JSON.stringify({
    type: "color",
    value: { name: color.gcid, settings: {} },
  });
  return `$variable(${json})$`;
}

/** Raccourci : référence à une couleur globale par nom court. */
export function gcid(name: GlobalColorName): DiviColor {
  return { gcid: name };
}

/** Couleurs globales standard Divi (raccourcis pratiques). */
export const colors = {
  primary: gcid("gcid-primary-color"),
  secondary: gcid("gcid-secondary-color"),
  heading: gcid("gcid-heading-color"),
  body: gcid("gcid-body-color"),
  link: gcid("gcid-link-color"),
};
