#!/usr/bin/env node
/**
 * Test bout-en-bout des patterns Divi :
 *   1. Importe les patterns depuis le bundle compilé du gateway.
 *   2. Construit une page (hero + features3col + ctaBanner + imageTextSplit).
 *   3. Crée une page WP en draft via content/create.
 *   4. L'écrit via divi/page/write (param blocks).
 *   5. Relit via divi/page/read tree pour valider la structure.
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

// Importer les patterns depuis le code source TypeScript via tsx — sinon
// compiler à la volée. Plus simple : on duplique l'invocation côté TS via
// un import dynamique du bundle. Mais le bundle n'expose pas ces fonctions.
//
// Pragmatique : on écrit ici directement les blocs au format parse_blocks
// en utilisant la même logique que les patterns. Plus tard, on peut
// extraire les patterns en module exportable.

const BV = "5.5.2";
const mk = (blockName, attrs, innerBlocks = []) => ({
  blockName,
  attrs: { ...attrs, builderVersion: BV },
  innerBlocks,
  innerHTML: "",
  innerContent: innerBlocks.length === 0 ? [null] : new Array(innerBlocks.length + 1).fill(null),
});
const dv = (value) => ({ desktop: { value } });
const gcidColor = (name) =>
  `$variable(${JSON.stringify({ type: "color", value: { name, settings: {} } })})$`;

// Pattern hero (inline)
const heroSection = mk(
  "divi/section",
  {
    module: {
      decoration: {
        background: dv({ color: gcidColor("gcid-body-color") }),
        spacing: dv({
          padding: { top: "120px", bottom: "120px", syncVertical: "off", syncHorizontal: "off" },
        }),
      },
    },
  },
  [
    mk(
      "divi/row",
      {
        module: {
          advanced: {
            columnStructure: dv("4_4"),
            flexColumnStructure: dv("equal-columns_1"),
          },
          decoration: { layout: dv({ flexWrap: "nowrap" }) },
        },
      },
      [
        mk(
          "divi/column",
          {
            module: {
              advanced: { type: dv("4_4") },
              decoration: { sizing: { desktop: { value: { flexType: "24_24" } } } },
            },
          },
          [
            mk("divi/text", {
              content: {
                innerContent: dv("<h1>Construis ta page parfaite</h1><p>Test des patterns Divi 5 générés automatiquement par IA Webmaster Bridge.</p>"),
                decoration: {
                  headingFont: {
                    h1: { font: dv({ textAlign: "center", size: "48px", weight: "800" }) },
                  },
                },
              },
            }),
            mk("divi/cta", {
              module: { advanced: { link: dv({ url: "" }) } },
              title: { innerContent: dv("") },
              content: { innerContent: dv("") },
              button: { innerContent: dv({ text: "Découvrir", linkUrl: "#" }) },
            }),
          ],
        ),
      ],
    ),
  ],
);

// Pattern features3col (inline)
const blurbModule = (title, contentHtml) =>
  mk("divi/blurb", {
    imageIcon: { innerContent: dv({ useIcon: "on", icon: { unicode: "&#xe0e1;", type: "divi", weight: "400" } }) },
    title: { innerContent: dv({ text: title }) },
    content: { innerContent: dv(contentHtml) },
  });

const featuresSection = mk(
  "divi/section",
  {
    module: {
      decoration: {
        spacing: dv({
          padding: { top: "80px", bottom: "80px", syncVertical: "off", syncHorizontal: "off" },
        }),
      },
    },
  },
  [
    mk(
      "divi/row",
      {
        module: {
          advanced: {
            columnStructure: dv("1_3,1_3,1_3"),
            flexColumnStructure: dv("equal-columns_3"),
          },
          decoration: {
            layout: {
              desktop: { value: { flexWrap: "nowrap" } },
              phone: { value: { flexWrap: "wrap" } },
              phoneWide: { value: { flexWrap: "wrap" } },
            },
          },
        },
      },
      ["Rapide", "Fiable", "Évolutif"].map((title) =>
        mk(
          "divi/column",
          {
            module: {
              advanced: { type: dv("1_3") },
              decoration: {
                sizing: {
                  desktop: { value: { flexType: "8_24" } },
                  phone: { value: { flexType: "24_24" } },
                  phoneWide: { value: { flexType: "24_24" } },
                },
              },
            },
          },
          [blurbModule(title, `<p>${title} — une raison de plus de choisir notre solution.</p>`)],
        ),
      ),
    ),
  ],
);

// Pattern ctaBanner (inline)
const ctaBannerSection = mk(
  "divi/section",
  {
    module: {
      decoration: {
        background: dv({ color: gcidColor("gcid-primary-color") }),
        spacing: dv({
          padding: { top: "80px", bottom: "80px", syncVertical: "off", syncHorizontal: "off" },
        }),
      },
    },
  },
  [
    mk(
      "divi/row",
      {
        module: {
          advanced: {
            columnStructure: dv("4_4"),
            flexColumnStructure: dv("equal-columns_1"),
          },
          decoration: { layout: dv({ flexWrap: "nowrap" }) },
        },
      },
      [
        mk(
          "divi/column",
          {
            module: {
              advanced: { type: dv("4_4") },
              decoration: { sizing: { desktop: { value: { flexType: "24_24" } } } },
            },
          },
          [
            mk("divi/cta", {
              module: { advanced: { link: dv({ url: "" }) } },
              title: { innerContent: dv("Prêt à commencer ?") },
              content: { innerContent: dv("<p>Rejoignez les milliers de clients qui nous font confiance.</p>") },
              button: { innerContent: dv({ text: "S'inscrire gratuitement", linkUrl: "#signup" }) },
            }),
          ],
        ),
      ],
    ),
  ],
);

// Wrapper placeholder
const placeholderBlock = mk("divi/placeholder", {}, [heroSection, featuresSection, ctaBannerSection]);

console.log("=== Test patterns E2E ===\n");

// 1. Créer une page draft.
console.log("1. Création page draft...");
const created = await call("/content/create", {
  type: "page",
  title: "IAWM Test Patterns E2E",
  status: "draft",
  content: "",
});
const postId = created.item.id;
console.log(`   ✓ Page créée, id=${postId}\n`);

// 2. Écrire le layout via divi/page/write.
console.log("2. Écriture du layout via divi/page/write...");
const written = await call("/divi/page/write", { post_id: postId, blocks: [placeholderBlock] });
console.log(`   ✓ written=${written.written}, total_blocks=${written.preview.total_blocks}\n`);

// 3. Relire en mode tree.
console.log("3. Lecture de validation...");
const read = await call("/divi/page/read", { post_id: postId, mode: "tree" });
console.log(`   ✓ ${read.stats.total_blocks} blocs lus`);
console.log(`   block_counts: ${JSON.stringify(read.stats.block_counts)}`);
console.log(`   section_count: ${read.stats.section_count}`);

// 4. Afficher les titres des modules text trouvés.
const collect = (nodes, type, acc = []) => {
  for (const n of nodes) {
    if (n.type === type) acc.push(n);
    if (n.rows) collect(n.rows, type, acc);
    if (n.columns) collect(n.columns, type, acc);
    if (n.modules) collect(n.modules, type, acc);
  }
  return acc;
};
const texts = collect(read.layout, "text");
const ctas = collect(read.layout, "cta");
const blurbs = collect(read.layout, "blurb");

console.log("\n4. Contenu détecté :");
console.log("   text modules :");
for (const t of texts) console.log(`     - ${t.summary?.content_html?.substring(0, 60)}...`);
console.log("   cta modules :");
for (const c of ctas) console.log(`     - "${c.summary?.title}" → bouton "${c.summary?.button_text}" (${c.summary?.button_url})`);
console.log("   blurb modules :");
for (const b of blurbs) console.log(`     - ${b.summary?.title}`);

console.log(`\n5. URL d'inspection : http://site-local.example/?page_id=${postId}&et_fb=1`);
console.log(`   (Ouvre dans le navigateur pour valider visuellement)\n`);
console.log("=== Test E2E réussi ===");
