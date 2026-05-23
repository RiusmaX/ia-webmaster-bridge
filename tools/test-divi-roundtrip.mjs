#!/usr/bin/env node
/**
 * Test round-trip Divi 5 :
 *   1. Lit le post_content brut de la page 19 (source).
 *   2. L'écrit dans la page 24 (cible) via /divi/page/write.
 *   3. Relit les deux pages en mode "tree" et compare le résumé.
 */

import { createHash, createHmac, randomBytes } from "node:crypto";
import { readFileSync } from "node:fs";
import { homedir } from "node:os";
import { join } from "node:path";

const cfg = JSON.parse(readFileSync(join(homedir(), ".iawm", "config.json"), "utf8"));

async function call(route, body) {
  const restRoute = `/ia-webmaster/v1${route}`;
  const httpPath = `/wp-json${restRoute}`;
  const method = body === null ? "GET" : "POST";
  const bodyStr = body === null ? "" : JSON.stringify(body);
  const ts = Math.floor(Date.now() / 1000).toString();
  const nonce = randomBytes(16).toString("hex");
  const bodyHash = createHash("sha256").update(bodyStr, "utf8").digest("hex");
  const message = ["IAWM-HMAC-SHA256", method, restRoute, "", ts, nonce, bodyHash].join("\n");
  const sig = createHmac("sha256", cfg.secret).update(message).digest("hex");
  const res = await fetch(`${cfg.baseUrl}${httpPath}`, {
    method,
    headers: {
      "X-IAWM-Key": cfg.keyId,
      "X-IAWM-Timestamp": ts,
      "X-IAWM-Nonce": nonce,
      "X-IAWM-Signature": sig,
      ...(bodyStr ? { "Content-Type": "application/json" } : {}),
    },
    body: bodyStr || undefined,
  });
  const text = await res.text();
  let data;
  try { data = JSON.parse(text); } catch { data = text; }
  if (!res.ok) {
    console.error(`✗ ${method} ${restRoute} → HTTP ${res.status}`);
    console.error(JSON.stringify(data, null, 2));
    process.exit(1);
  }
  return data;
}

const SOURCE_ID = 19;
const TARGET_ID = 24;

console.log(`=== Round-trip Divi 5 : page ${SOURCE_ID} → page ${TARGET_ID} ===\n`);

// 1. Lire le contenu brut de la source.
console.log("1. Lecture source (page 19, mode=raw)...");
const sourceRaw = await call("/divi/page/read", { post_id: SOURCE_ID, mode: "raw" });
console.log(`   ✓ ${sourceRaw.stats.total_blocks} blocs lus, ${sourceRaw.stats.section_count} sections`);

// 2. Récupérer le post_content brut via content/get (on copie tel quel).
console.log("\n2. Récupération du post_content via content/get...");
const sourceGet = await call("/content/get", { id: SOURCE_ID });
const sourceContent = sourceGet.item.content;
console.log(`   ✓ ${sourceContent.length} octets de contenu source`);

// 3. Écriture dans la cible via /divi/page/write (format content).
console.log("\n3. Écriture sur la page cible (mode content)...");
const writeRes = await call("/divi/page/write", {
  post_id: TARGET_ID,
  content: sourceContent,
});
console.log(`   ✓ written=${writeRes.written}, total_blocks=${writeRes.preview.total_blocks}, content_length=${writeRes.preview.content_length}`);

// 4. Relire la cible.
console.log("\n4. Relecture de la cible (mode=tree)...");
const targetRead = await call("/divi/page/read", { post_id: TARGET_ID, mode: "tree" });
console.log(`   ✓ ${targetRead.stats.total_blocks} blocs relus, ${targetRead.stats.section_count} sections`);

// 5. Comparer.
console.log("\n5. Comparaison source vs cible :");
const sourceTree = await call("/divi/page/read", { post_id: SOURCE_ID, mode: "tree" });
const sameStats = JSON.stringify(sourceTree.stats.block_counts) === JSON.stringify(targetRead.stats.block_counts);
const sameLayoutString = JSON.stringify(sourceTree.layout) === JSON.stringify(targetRead.layout);
console.log(`   stats identiques : ${sameStats ? "✓" : "✗"}`);
console.log(`   arbre identique  : ${sameLayoutString ? "✓" : "✗"}`);

// 6. Test d'idempotence côté octets (content/get sur la cible doit matcher la source).
console.log("\n6. Idempotence octets :");
const targetGet = await call("/content/get", { id: TARGET_ID });
const sameContent = targetGet.item.content === sourceContent;
console.log(`   post_content identique au bit près : ${sameContent ? "✓" : "✗"}`);
if (!sameContent) {
  console.log(`   source : ${sourceContent.length} octets`);
  console.log(`   cible  : ${targetGet.item.content.length} octets`);
}

// 7. Vérifier les meta Divi.
console.log("\n7. Meta Divi sur la cible : builder=" + targetGet.item.builder);

console.log("\n=== Round-trip ===");
console.log(sameStats && sameLayoutString && sameContent ? "✓ SUCCÈS" : "✗ DIVERGENCE DÉTECTÉE");
process.exit((sameStats && sameLayoutString && sameContent) ? 0 : 1);
