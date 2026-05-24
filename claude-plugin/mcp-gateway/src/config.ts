/**
 * Chargement et validation de la configuration du pont MCP.
 *
 * La configuration (URL du site + secret HMAC) est cherchée, dans l'ordre :
 *   1. ~/.iawm/config.json  — emplacement utilisateur stable, recommandé : il
 *                             survit aux mises à jour du plugin.
 *   2. <pont>/config.json   — à côté du pont, pratique en développement.
 *
 * Ce fichier contient le secret partagé : il ne doit jamais être versionné.
 * Voir config.example.json.
 */

import { existsSync, readFileSync } from "node:fs";
import { homedir } from "node:os";
import { fileURLToPath } from "node:url";
import { dirname, join } from "node:path";

export interface GatewayConfig {
  /** Racine du site WordPress, ex. https://votre-site.example */
  baseUrl: string;
  /** Identifiant de clé d'API (en-tête X-IAWM-Key). */
  keyId: string;
  /** Secret partagé HMAC. */
  secret: string;
}

/**
 * Emplacements où chercher le fichier de configuration, par ordre de priorité.
 *
 * @returns Liste de chemins absolus candidats.
 */
function configCandidates(): string[] {
  const here = dirname(fileURLToPath(import.meta.url));

  return [
    join(homedir(), ".iawm", "config.json"),
    join(here, "..", "config.json"),
  ];
}

/**
 * Charge la configuration depuis le premier emplacement candidat trouvé.
 *
 * @throws Si aucun fichier n'est trouvé, ou s'il est illisible / incomplet.
 */
export function loadConfig(): GatewayConfig {
  const candidates = configCandidates();
  const configPath = candidates.find((path) => existsSync(path));

  if (!configPath) {
    throw new Error(
      "Configuration introuvable. Créez le fichier " +
        `${candidates[0]} à partir du modèle config.example.json.`,
    );
  }

  let parsed: Partial<GatewayConfig>;
  try {
    parsed = JSON.parse(readFileSync(configPath, "utf8")) as Partial<GatewayConfig>;
  } catch {
    throw new Error(`Configuration illisible : ${configPath} n'est pas un JSON valide.`);
  }

  if (!parsed.baseUrl || !parsed.keyId || !parsed.secret) {
    throw new Error(
      `Configuration incomplète dans ${configPath} : ` +
        "les champs baseUrl, keyId et secret sont requis.",
    );
  }

  return {
    baseUrl: parsed.baseUrl.replace(/\/+$/, ""),
    keyId: parsed.keyId,
    secret: parsed.secret,
  };
}
