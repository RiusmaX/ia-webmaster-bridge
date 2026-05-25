#!/usr/bin/env node
/**
 * scan-divi-modules.mjs
 *
 * Walks the Divi 5 theme's module-library on disk, inventories every
 * native + WooCommerce module, extracts each module's metadata and the
 * tree of attributes it natively renders, and writes two artefacts:
 *
 *   - docs/divi5-modules-registry.json   structured data, one entry per module
 *   - docs/divi5-modules-catalog.md      human-readable Markdown overview
 *
 * Usage:
 *   node tools/scan-divi-modules.mjs [--divi-path "<absolute path to Divi theme>"]
 *
 * Default path probes (in order):
 *   - $IAWM_DIVI_PATH
 *   - ~/Local Sites/ia-webmaster-bridge/app/public/wp-content/themes/Divi
 *   - %USERPROFILE%/Local Sites/<first-site>/app/public/wp-content/themes/Divi
 *
 * Re-running is safe: artefacts are overwritten in place. The
 * generated files carry an explicit "auto-generated, do not edit"
 * banner.
 */

import { readdir, readFile, writeFile, stat } from 'node:fs/promises';
import { join, dirname, resolve as resolvePath } from 'node:path';
import { fileURLToPath } from 'node:url';
import { homedir } from 'node:os';

const __dirname = dirname(fileURLToPath(import.meta.url));
const REPO_ROOT = resolvePath(__dirname, '..');

const REGISTRY_OUT = join(REPO_ROOT, 'docs', 'divi5-modules-registry.json');
const CATALOG_OUT = join(REPO_ROOT, 'docs', 'divi5-modules-catalog.md');
const TS_OUT = join(REPO_ROOT, 'claude-plugin', 'mcp-gateway', 'src', 'divi', 'modules-registry.ts');

/**
 * Locates the Divi theme directory. Returns the JS-package components
 * folder we walk.
 */
async function resolveComponentsRoot() {
  const argIdx = process.argv.indexOf('--divi-path');
  const candidates = [];
  if (argIdx > -1 && process.argv[argIdx + 1]) candidates.push(process.argv[argIdx + 1]);
  if (process.env.IAWM_DIVI_PATH) candidates.push(process.env.IAWM_DIVI_PATH);
  candidates.push(join(homedir(), 'Local Sites', 'ia-webmaster-bridge', 'app', 'public', 'wp-content', 'themes', 'Divi'));

  for (const c of candidates) {
    try {
      const p = join(c, 'includes', 'builder-5', 'visual-builder', 'packages', 'module-library', 'src', 'components');
      const s = await stat(p);
      if (s.isDirectory()) return p;
    } catch {
      /* not this one */
    }
  }
  throw new Error('Divi components folder not found. Pass --divi-path "C:/Users/<you>/Local Sites/<site>/app/public/wp-content/themes/Divi".');
}

/**
 * Recursively flattens a default-render-attributes tree into dot-paths.
 *
 * The actual values are skipped — we only collect the leaf path so the
 * registry stays compact. A leaf is an object that contains a
 * breakpoint key (`desktop`, `tablet`, `phone`, `phoneWide`) — Divi's
 * universal multi-breakpoint envelope.
 */
const BREAKPOINTS = new Set(['desktop', 'tablet', 'phone', 'phoneWide']);

function flatten(node, prefix = '') {
  const paths = [];
  if (node === null || typeof node !== 'object') return paths;
  for (const [key, val] of Object.entries(node)) {
    if (key === '_comment') continue;
    const here = prefix ? `${prefix}.${key}` : key;
    if (val && typeof val === 'object') {
      // Detect the "breakpoint envelope": at least one key is a breakpoint name.
      const childKeys = Object.keys(val);
      const isLeaf = childKeys.some((k) => BREAKPOINTS.has(k));
      if (isLeaf) {
        paths.push(here);
      } else {
        paths.push(...flatten(val, here));
      }
    } else {
      paths.push(here);
    }
  }
  return paths;
}

/**
 * Reads one module directory.
 *
 * @param {string} root  Components root.
 * @param {string} dir   Module directory name (e.g. "button").
 * @param {string} family "native" or "woocommerce".
 */
async function readModule(root, dir, family) {
  const moduleJsonPath = join(root, dir, 'module.json');
  const defaultsPath = join(root, dir, 'module-default-render-attributes.json');

  let meta;
  try {
    meta = JSON.parse(await readFile(moduleJsonPath, 'utf8'));
  } catch {
    return null;
  }

  let defaults = null;
  try {
    defaults = JSON.parse(await readFile(defaultsPath, 'utf8'));
  } catch {
    /* some modules ship no defaults file */
  }

  const attributePaths = defaults ? flatten(defaults).sort() : [];
  const topLevelGroups = Array.from(new Set(attributePaths.map((p) => p.split('.')[0]))).sort();

  return {
    slug: dir,
    family,
    name: meta.name ?? null,
    d4Shortcode: meta.d4Shortcode ?? null,
    title: meta.title ?? null,
    titles: meta.titles ?? null,
    category: meta.category ?? null,
    childrenName: Array.isArray(meta.childrenName) ? meta.childrenName : [],
    videoCount: Array.isArray(meta.videos) ? meta.videos.length : 0,
    topLevelGroups,
    attributePaths,
  };
}

async function main() {
  const root = await resolveComponentsRoot();
  const wooRoot = join(root, 'woocommerce');

  const entries = await readdir(root, { withFileTypes: true });
  const dirs = entries.filter((e) => e.isDirectory() && e.name !== 'woocommerce').map((e) => e.name).sort();
  const wooEntries = await readdir(wooRoot, { withFileTypes: true });
  const wooDirs = wooEntries.filter((e) => e.isDirectory()).map((e) => e.name).sort();

  const modules = [];
  for (const d of dirs) {
    const m = await readModule(root, d, 'native');
    if (m) modules.push(m);
  }
  for (const d of wooDirs) {
    const m = await readModule(wooRoot, d, 'woocommerce');
    if (m) modules.push(m);
  }

  // Write the structured registry.
  const registry = {
    _comment: 'Auto-generated by tools/scan-divi-modules.mjs — do not edit by hand.',
    scanned_at: new Date().toISOString(),
    divi_components_root: root,
    counts: {
      total: modules.length,
      native: modules.filter((m) => m.family === 'native').length,
      woocommerce: modules.filter((m) => m.family === 'woocommerce').length,
      structure: modules.filter((m) => m.category === 'structure').length,
      childModules: modules.filter((m) => m.category === 'child-module').length,
      fullwidth: modules.filter((m) => m.category === 'fullwidth-module').length,
    },
    modules,
  };
  await writeFile(REGISTRY_OUT, JSON.stringify(registry, null, 2));

  // Write the human-readable catalog.
  const md = renderMarkdown(registry);
  await writeFile(CATALOG_OUT, md);

  // Write the TypeScript registry for the gateway. Keeps the
  // DiviBlock enum and runtime module list in sync with Divi.
  const ts = renderTypeScript(registry);
  await writeFile(TS_OUT, ts);

  console.log(`Wrote ${REGISTRY_OUT}`);
  console.log(`Wrote ${CATALOG_OUT}`);
  console.log(`Wrote ${TS_OUT}`);
  console.log(`Native:      ${registry.counts.native}`);
  console.log(`WooCommerce: ${registry.counts.woocommerce}`);
  console.log(`Total:       ${registry.counts.total}`);
}

function renderMarkdown(reg) {
  const buckets = {
    structure: reg.modules.filter((m) => m.category === 'structure'),
    module: reg.modules.filter((m) => m.category === 'module' && m.family === 'native'),
    'fullwidth-module': reg.modules.filter((m) => m.category === 'fullwidth-module'),
    'child-module': reg.modules.filter((m) => m.category === 'child-module'),
    woocommerce: reg.modules.filter((m) => m.family === 'woocommerce'),
  };

  const lines = [];
  lines.push('# Divi 5 modules catalog');
  lines.push('');
  lines.push('> Auto-generated from the local Divi 5 install by `tools/scan-divi-modules.mjs`.');
  lines.push(`> Do not edit by hand — re-run the script after a Divi update. Last scan: ${reg.scanned_at}.`);
  lines.push('');
  lines.push('## Summary');
  lines.push('');
  lines.push(`- **${reg.counts.total} modules total**`);
  lines.push(`  - ${reg.counts.native} native (incl. structure, child and fullwidth variants)`);
  lines.push(`  - ${reg.counts.woocommerce} WooCommerce`);
  lines.push('');
  lines.push(`Module families (native):`);
  lines.push(`- ${buckets.structure.length} structure (section, row, column, etc.)`);
  lines.push(`- ${buckets.module.length} regular modules`);
  lines.push(`- ${buckets['fullwidth-module'].length} fullwidth variants`);
  lines.push(`- ${buckets['child-module'].length} child modules (rows inside a parent)`);
  lines.push('');
  lines.push('Notation in tables below:');
  lines.push('- **name**: the WordPress block name as it appears in saved `post_content` (e.g. `divi/button`).');
  lines.push('- **D4 shortcode**: the Divi 4 shortcode the module migrated from (for cross-reference with Divi 4 content / older docs).');
  lines.push('- **children**: child block names the module accepts inside its inner blocks.');
  lines.push('- **attr groups**: the top-level keys of the module\'s attribute tree as it appears in saved attributes (each key is itself a tree per breakpoint — see `docs/divi5-format.md`).');
  lines.push('');

  for (const [bucket, list] of Object.entries(buckets)) {
    if (list.length === 0) continue;
    lines.push(`## ${bucketTitle(bucket)} (${list.length})`);
    lines.push('');
    lines.push('| Slug | Block name | D4 shortcode | Title | Children | Attr groups |');
    lines.push('|------|------------|--------------|-------|----------|-------------|');
    for (const m of list) {
      const children = m.childrenName.length ? '`' + m.childrenName.join('`, `') + '`' : '—';
      const groups = m.topLevelGroups.length ? '`' + m.topLevelGroups.join('`, `') + '`' : '—';
      lines.push(`| \`${m.slug}\` | \`${m.name ?? ''}\` | \`${m.d4Shortcode ?? ''}\` | ${m.title ?? ''} | ${children} | ${groups} |`);
    }
    lines.push('');
  }

  lines.push('## Full attribute paths');
  lines.push('');
  lines.push('For each module, the flat list of attribute dot-paths that Divi sets by default. Use these as keys under the per-breakpoint envelope (`desktop` / `tablet` / `phone` / `phoneWide`).');
  lines.push('');
  for (const m of reg.modules) {
    if (m.attributePaths.length === 0) continue;
    lines.push(`### \`${m.name}\` — ${m.title}`);
    lines.push('');
    for (const p of m.attributePaths) {
      lines.push(`- \`${p}\``);
    }
    lines.push('');
  }

  return lines.join('\n');
}

/**
 * PascalCase key for a Divi block name. A few names are intentionally
 * overridden to match historical conventions in the gateway.
 */
const ENUM_KEY_OVERRIDES = {
  'divi/cta': 'Cta',
};

function toEnumKey(name) {
  if (ENUM_KEY_OVERRIDES[name]) return ENUM_KEY_OVERRIDES[name];
  const slug = name.replace(/^divi\//, '');
  return slug
    .split('-')
    .map((s) => s.charAt(0).toUpperCase() + s.slice(1))
    .join('');
}

/**
 * Renders the TypeScript registry consumed by the gateway: the full
 * `DiviBlock` enum plus a runtime `DIVI_MODULES` array carrying the
 * metadata Claude needs to pick the right block name + accepted
 * children in free-form mode.
 */
function renderTypeScript(reg) {
  const sorted = [...reg.modules].sort((a, b) => toEnumKey(a.name).localeCompare(toEnumKey(b.name)));

  const lines = [];
  lines.push('/**');
  lines.push(' * Divi 5 modules registry — AUTO-GENERATED.');
  lines.push(' *');
  lines.push(' * Regenerate with `node tools/scan-divi-modules.mjs` after a Divi update.');
  lines.push(` * Source: ${reg.divi_components_root}`);
  lines.push(` * Scan timestamp: ${reg.scanned_at}`);
  lines.push(' *');
  lines.push(' * Two exports:');
  lines.push(' *   - DiviBlock: an object of every Divi 5 block name (DiviBlock.Button = "divi/button", …).');
  lines.push(' *   - DIVI_MODULES: a runtime array of metadata records for catalog/discovery use cases.');
  lines.push(' */');
  lines.push('');
  lines.push('export const DiviBlock = {');
  lines.push('  Placeholder: "divi/placeholder",');
  for (const m of sorted) {
    lines.push(`  ${toEnumKey(m.name)}: ${JSON.stringify(m.name)},`);
  }
  lines.push('} as const;');
  lines.push('');
  lines.push('/** Divi 5 block name (one of the DiviBlock enum values). */');
  lines.push('export type DiviBlockName = (typeof DiviBlock)[keyof typeof DiviBlock];');
  lines.push('');
  lines.push('/**');
  lines.push(' * Categorisation of a module:');
  lines.push(' *   - "structure"        : section, row, column, etc. (layout containers).');
  lines.push(' *   - "module"           : a leaf module inside a column.');
  lines.push(' *   - "fullwidth-module" : a leaf module that takes the full section width.');
  lines.push(' *   - "child-module"     : a row inside a parent (e.g. an accordion item).');
  lines.push(' */');
  lines.push('export type DiviModuleCategory =');
  lines.push('  | "structure"');
  lines.push('  | "module"');
  lines.push('  | "fullwidth-module"');
  lines.push('  | "child-module";');
  lines.push('');
  lines.push('export interface DiviModuleMeta {');
  lines.push('  /** Block name, e.g. `divi/button`. */');
  lines.push('  name: string;');
  lines.push('  /** Slug (kebab-case of the trailing path segment). */');
  lines.push('  slug: string;');
  lines.push('  /** Divi 4 shortcode, when one exists. */');
  lines.push('  d4Shortcode: string | null;');
  lines.push('  /** Human-readable title. */');
  lines.push('  title: string | null;');
  lines.push('  /** Module family. */');
  lines.push('  family: "native" | "woocommerce";');
  lines.push('  /** Category among structure / module / fullwidth-module / child-module. */');
  lines.push('  category: DiviModuleCategory | null;');
  lines.push('  /** Child block names the module accepts in inner blocks. */');
  lines.push('  childrenName: string[];');
  lines.push('  /** Top-level attribute groups the module sets by default. */');
  lines.push('  topLevelGroups: string[];');
  lines.push('}');
  lines.push('');
  lines.push('export const DIVI_MODULES: DiviModuleMeta[] = [');
  for (const m of sorted) {
    lines.push('  {');
    lines.push(`    name: ${JSON.stringify(m.name)},`);
    lines.push(`    slug: ${JSON.stringify(m.slug)},`);
    lines.push(`    d4Shortcode: ${m.d4Shortcode ? JSON.stringify(m.d4Shortcode) : 'null'},`);
    lines.push(`    title: ${m.title ? JSON.stringify(m.title) : 'null'},`);
    lines.push(`    family: ${JSON.stringify(m.family)},`);
    lines.push(`    category: ${m.category ? JSON.stringify(m.category) : 'null'},`);
    lines.push(`    childrenName: ${JSON.stringify(m.childrenName)},`);
    lines.push(`    topLevelGroups: ${JSON.stringify(m.topLevelGroups)},`);
    lines.push('  },');
  }
  lines.push('];');
  lines.push('');
  lines.push('/** Quick lookup: block name -> module metadata. */');
  lines.push('export const DIVI_MODULE_BY_NAME: Record<string, DiviModuleMeta> = Object.fromEntries(');
  lines.push('  DIVI_MODULES.map((m) => [m.name, m]),');
  lines.push(');');
  lines.push('');
  return lines.join('\n');
}

function bucketTitle(bucket) {
  switch (bucket) {
    case 'structure':
      return 'Structure';
    case 'module':
      return 'Native modules';
    case 'fullwidth-module':
      return 'Fullwidth modules';
    case 'child-module':
      return 'Child modules';
    case 'woocommerce':
      return 'WooCommerce modules';
    default:
      return bucket;
  }
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
