#!/usr/bin/env node
/**
 * Test bout-en-bout du Theme Builder :
 *   1. Status Divi (vérification).
 *   2. List templates (devrait être vide).
 *   3. setup-site-defaults avec header + footer simples.
 *   4. List à nouveau : doit voir le nouveau template par défaut avec
 *      header/footer assignés.
 *   5. Read le layout header pour vérifier le contenu.
 */

import { createHash, createHmac, randomBytes } from "node:crypto";
import { readFileSync } from "node:fs";
import { homedir } from "node:os";
import { join } from "node:path";

const cfg = JSON.parse(readFileSync(join(homedir(), ".iawm", "config.json"), "utf8"));

async function call(route, body) {
  const restRoute = `/ia-webmaster/v1${route}`;
  const httpPath = `/wp-json${restRoute}`;
  const bodyStr = body === null ? "" : JSON.stringify(body);
  const ts = Math.floor(Date.now() / 1000).toString();
  const nonce = randomBytes(16).toString("hex");
  const bodyHash = createHash("sha256").update(bodyStr, "utf8").digest("hex");
  const message = ["IAWM-HMAC-SHA256", body === null ? "GET" : "POST", restRoute, "", ts, nonce, bodyHash].join("\n");
  const sig = createHmac("sha256", cfg.secret).update(message).digest("hex");
  const res = await fetch(`${cfg.baseUrl}${httpPath}`, {
    method: body === null ? "GET" : "POST",
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
    console.error(`✗ ${restRoute} → HTTP ${res.status}`);
    console.error(JSON.stringify(data, null, 2));
    process.exit(1);
  }
  return data;
}

console.log("=== Theme Builder E2E ===\n");

// 1. Status.
const status = await call("/divi/status", {});
console.log(`1. Divi v${status.divi_version} actif sur le site\n`);

// 2. List (devrait être vide).
const before = await call("/divi/theme-builder/list", { live: true });
console.log(`2. Templates existants : ${before.templates?.length ?? 0}\n`);

// 3. Construire des blocs Divi minimaux pour header et footer.
const BV = "5.5.2";
const mk = (blockName, attrs, innerBlocks = []) => ({
  blockName,
  attrs: { ...attrs, builderVersion: BV },
  innerBlocks,
  innerHTML: "",
  innerContent: innerBlocks.length === 0 ? [null] : new Array(innerBlocks.length + 1).fill(null),
});
const dv = (value) => ({ desktop: { value } });
const gcid = (name) =>
  `$variable(${JSON.stringify({ type: "color", value: { name, settings: {} } })})$`;

// Header = section avec text "Mon site" + menu
const headerBlocks = [
  mk("divi/placeholder", {}, [
    mk("divi/section", {
      module: {
        decoration: {
          background: dv({ color: gcid("gcid-body-color") }),
          spacing: dv({
            padding: { top: "20px", bottom: "20px", syncVertical: "on", syncHorizontal: "off" },
          }),
        },
      },
    }, [
      mk("divi/row", {
        module: {
          advanced: {
            columnStructure: dv("1_2,1_2"),
            flexColumnStructure: dv("equal-columns_2"),
          },
          decoration: {
            layout: {
              desktop: { value: { flexWrap: "nowrap" } },
              phone: { value: { flexWrap: "wrap" } },
            },
          },
        },
      }, [
        mk("divi/column", {
          module: {
            advanced: { type: dv("1_2") },
            decoration: {
              sizing: {
                desktop: { value: { flexType: "12_24" } },
                phone: { value: { flexType: "24_24" } },
              },
            },
          },
        }, [
          mk("divi/text", {
            content: {
              innerContent: dv("<h1>Mon Site IAWM</h1>"),
              decoration: {
                headingFont: {
                  h1: { font: dv({ size: "28px", weight: "700" }) },
                },
              },
            },
          }),
        ]),
        mk("divi/column", {
          module: {
            advanced: { type: dv("1_2") },
            decoration: {
              sizing: {
                desktop: { value: { flexType: "12_24" } },
                phone: { value: { flexType: "24_24" } },
              },
            },
          },
        }, [
          mk("divi/menu", {}),
        ]),
      ]),
    ]),
  ]),
];

// Footer = section sombre avec texte
const footerBlocks = [
  mk("divi/placeholder", {}, [
    mk("divi/section", {
      module: {
        decoration: {
          background: dv({ color: gcid("gcid-heading-color") }),
          spacing: dv({
            padding: { top: "40px", bottom: "20px", syncVertical: "off", syncHorizontal: "off" },
          }),
        },
      },
    }, [
      mk("divi/row", {
        module: {
          advanced: {
            columnStructure: dv("4_4"),
            flexColumnStructure: dv("equal-columns_1"),
          },
          decoration: { layout: dv({ flexWrap: "nowrap" }) },
        },
      }, [
        mk("divi/column", {
          module: {
            advanced: { type: dv("4_4") },
            decoration: { sizing: { desktop: { value: { flexType: "24_24" } } } },
          },
        }, [
          mk("divi/text", {
            content: { innerContent: dv("<p style=\"text-align:center;\">© 2026 Mon Site IAWM. Footer de test.</p>") },
          }),
        ]),
      ]),
    ]),
  ]),
];

// 4. setup-site-defaults
console.log("3. setup-site-defaults avec header + footer...");
const setup = await call("/divi/theme-builder/setup-site-defaults", {
  title: "IAWM Test Default Template",
  header: { title: "Header IAWM", blocks: headerBlocks },
  footer: { title: "Footer IAWM", blocks: footerBlocks },
  assign_default: true,
  replace_existing: true,
});
console.log(`   ✓ template_id=${setup.template_id}`);
console.log(`   ✓ layouts: ${JSON.stringify(setup.layouts)}`);
console.log(`   ✓ assigned_default=${setup.assigned_default}\n`);

// 5. List à nouveau
const after = await call("/divi/theme-builder/list", { live: true });
console.log(`4. Templates après setup : ${after.templates?.length ?? 0}`);
if (after.templates && after.templates[0]) {
  const t = after.templates[0];
  console.log(`   - id=${t.id} "${t.title}" default=${t.default} enabled=${t.enabled}`);
  console.log(`   - layouts:`);
  for (const [zone, layout] of Object.entries(t.layouts ?? {})) {
    console.log(`       ${zone}: id=${layout.id} title="${layout.title ?? ""}" has_content=${layout.has_content}`);
  }
}

// 6. Read header layout
if (setup.layouts?.header) {
  const hd = await call("/divi/theme-builder/layout/read", { post_id: setup.layouts.header, mode: "tree" });
  console.log(`\n5. Lecture du header :`);
  console.log(`   ${hd.stats.total_blocks} blocs, ${hd.stats.section_count} sections`);
  console.log(`   block_counts: ${JSON.stringify(hd.stats.block_counts)}`);
}

console.log(`\n6. URL frontend pour valider (n'importe quelle page du site) :`);
console.log(`   http://site-local.example/`);
console.log(`   http://site-local.example/iawm-divi-reference/`);

console.log("\n=== Test E2E réussi ===");
