/**
 * Loading and validation of the MCP gateway configuration.
 *
 * The configuration (site URL + HMAC secret) is looked up in this order:
 *   1. ~/.iawm/config.json  — stable user-level location, recommended: it
 *                             survives plugin updates.
 *   2. <gateway>/config.json — next to the gateway, convenient in development.
 *
 * This file contains the shared secret: it must never be versioned.
 * See config.example.json.
 */

import { existsSync, readFileSync } from "node:fs";
import { homedir } from "node:os";
import { fileURLToPath } from "node:url";
import { dirname, join } from "node:path";

export interface GatewayConfig {
  /** WordPress site root, e.g. https://your-site.example */
  baseUrl: string;
  /** API key identifier (X-IAWM-Key header). */
  keyId: string;
  /** Shared HMAC secret. */
  secret: string;
}

/**
 * Locations where the configuration file is looked up, in priority order.
 *
 * @returns List of candidate absolute paths.
 */
function configCandidates(): string[] {
  const here = dirname(fileURLToPath(import.meta.url));

  return [
    join(homedir(), ".iawm", "config.json"),
    join(here, "..", "config.json"),
  ];
}

/**
 * Loads the configuration from the first candidate location that exists.
 *
 * @throws If no file is found, or if it is unreadable / incomplete.
 */
export function loadConfig(): GatewayConfig {
  const candidates = configCandidates();
  const configPath = candidates.find((path) => existsSync(path));

  if (!configPath) {
    throw new Error(
      "Configuration not found. Create the file " +
        `${candidates[0]} from the config.example.json template.`,
    );
  }

  let parsed: Partial<GatewayConfig>;
  try {
    parsed = JSON.parse(readFileSync(configPath, "utf8")) as Partial<GatewayConfig>;
  } catch {
    throw new Error(`Unreadable configuration: ${configPath} is not valid JSON.`);
  }

  if (!parsed.baseUrl || !parsed.keyId || !parsed.secret) {
    throw new Error(
      `Incomplete configuration in ${configPath}: ` +
        "the baseUrl, keyId and secret fields are required.",
    );
  }

  return {
    baseUrl: parsed.baseUrl.replace(/\/+$/, ""),
    keyId: parsed.keyId,
    secret: parsed.secret,
  };
}
