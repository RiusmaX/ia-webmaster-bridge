#!/usr/bin/env node
/**
 * Client de test : appelle un endpoint du plugin IA Webmaster Bridge
 * en signant la requête HMAC comme le fait le gateway MCP.
 *
 * Utilisation :
 *   node tools/call-api.mjs /status
 *   node tools/call-api.mjs /plugins/info '{"slug":"rank-math-seo"}'
 *   node tools/call-api.mjs /plugins/install '{"slug":"rank-math-seo","activate":true}'
 */

import { createHash, createHmac, randomBytes } from "node:crypto";
import { readFileSync } from "node:fs";
import { homedir } from "node:os";
import { join } from "node:path";

const cfgPath = join(homedir(), ".iawm", "config.json");
const cfg = JSON.parse(readFileSync(cfgPath, "utf8"));

const route = process.argv[2];
const bodyJson = process.argv[3] ?? "";
if (!route) {
  console.error("Usage : node tools/call-api.mjs <route> [json-body]");
  process.exit(1);
}

// Normaliser route (commence par /).
const path = route.startsWith("/") ? route : `/${route}`;
// La route REST (utilisée dans la signature) = ce que WP voit après /wp-json/.
const restRoute = `/ia-webmaster/v1${path}`;
// L'URL HTTP réelle inclut /wp-json/.
const httpPath = `/wp-json${restRoute}`;
const method = bodyJson === "" ? "GET" : "POST";
const body = bodyJson === "" ? "" : bodyJson;

// Construction du message canonique HMAC (7 lignes, identique au plugin) :
const timestamp = Math.floor(Date.now() / 1000).toString();
const nonce = randomBytes(16).toString("hex");
const bodyHash = createHash("sha256").update(body, "utf8").digest("hex");
const message = [
  "IAWM-HMAC-SHA256",
  method,
  restRoute,
  "",
  timestamp,
  nonce,
  bodyHash,
].join("\n");
const signature = createHmac("sha256", cfg.secret).update(message).digest("hex");

const url = `${cfg.baseUrl}${httpPath}`;
const headers = {
  "X-IAWM-Key": cfg.keyId,
  "X-IAWM-Timestamp": timestamp,
  "X-IAWM-Nonce": nonce,
  "X-IAWM-Signature": signature,
};
if (body) {
  headers["Content-Type"] = "application/json";
}

const t0 = Date.now();
const res = await fetch(url, {
  method,
  headers,
  body: body || undefined,
});
const elapsed = Date.now() - t0;
const text = await res.text();
let parsed;
try {
  parsed = JSON.parse(text);
} catch {
  parsed = text;
}

console.log(`${method} ${restRoute} → HTTP ${res.status} (${elapsed}ms)`);
console.log(JSON.stringify(parsed, null, 2));
process.exit(res.ok ? 0 : 1);
