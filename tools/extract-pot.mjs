#!/usr/bin/env node
/**
 * extract-pot.mjs
 *
 * Extracts gettext strings from the IA Webmaster Bridge plugin PHP source
 * and writes `plugin/ia-webmaster-bridge/languages/ia-webmaster-bridge.pot`.
 *
 * Why we ship this rather than depend on `wp i18n make-pot`:
 *   - some maintainers may not have WP-CLI on PATH;
 *   - this script's output is reproducible and easy to inspect on a PR.
 *
 * Functions recognised (text domain MUST be `ia-webmaster-bridge`):
 *   __( 'string', 'ia-webmaster-bridge' )
 *   _e( 'string', 'ia-webmaster-bridge' )
 *   esc_html__( 'string', 'ia-webmaster-bridge' )
 *   esc_html_e( 'string', 'ia-webmaster-bridge' )
 *   esc_attr__( 'string', 'ia-webmaster-bridge' )
 *   esc_attr_e( 'string', 'ia-webmaster-bridge' )
 *
 * Usage: `node tools/extract-pot.mjs`
 */

import { readdir, readFile, writeFile, mkdir } from 'node:fs/promises';
import { join, dirname, resolve as resolvePath } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const REPO_ROOT = resolvePath(__dirname, '..');
const PLUGIN_DIR = join(REPO_ROOT, 'plugin', 'ia-webmaster-bridge');
const OUT = join(PLUGIN_DIR, 'languages', 'ia-webmaster-bridge.pot');
const TEXT_DOMAIN = 'ia-webmaster-bridge';

const FN_RE = /(?:__|_e|esc_html__|esc_html_e|esc_attr__|esc_attr_e)\s*\(\s*(['"])((?:\\.|(?!\1).)*)\1\s*,\s*(['"])ia-webmaster-bridge\3\s*\)/g;

async function walk(dir, ext) {
  const out = [];
  const entries = await readdir(dir, { withFileTypes: true });
  for (const e of entries) {
    const p = join(dir, e.name);
    if (e.isDirectory() && !['languages', 'tests', 'vendor', 'node_modules'].includes(e.name)) {
      out.push(...(await walk(p, ext)));
    } else if (e.isFile() && p.endsWith(ext)) {
      out.push(p);
    }
  }
  return out;
}

function escapePo(s) {
  // PO format: backslash, double-quote, newline, tab need escaping.
  return s
    .replace(/\\/g, '\\\\')
    .replace(/"/g, '\\"')
    .replace(/\n/g, '\\n')
    .replace(/\t/g, '\\t');
}

function unescapePhp(s) {
  // PHP single-quoted strings: \' and \\ are the only escapes.
  // PHP double-quoted strings: many escapes (\n, \t, \$, …). We see both.
  // We approximate by handling the common ones; corner cases are rare in our strings.
  return s
    .replace(/\\\\/g, '\\')
    .replace(/\\'/g, "'")
    .replace(/\\"/g, '"')
    .replace(/\\n/g, '\n')
    .replace(/\\t/g, '\t');
}

async function main() {
  const files = await walk(PLUGIN_DIR, '.php');
  /** @type {Map<string, { references: Set<string>, comments: Set<string> }>} */
  const entries = new Map();

  for (const file of files) {
    const src = await readFile(file, 'utf8');
    const rel = file.replace(REPO_ROOT, '').replace(/\\/g, '/').replace(/^\//, '');
    let m;
    while ((m = FN_RE.exec(src)) !== null) {
      const raw = unescapePhp(m[2]);
      const lineNo = src.slice(0, m.index).split('\n').length;
      const ref = `${rel}:${lineNo}`;
      // Look for a `/* translators: ... */` comment immediately before the call.
      const before = src.slice(Math.max(0, m.index - 200), m.index);
      const tx = /\/\*\s*translators:\s*([^*]+)\*\//i.exec(before);
      const entry = entries.get(raw) ?? { references: new Set(), comments: new Set() };
      entry.references.add(ref);
      if (tx) entry.comments.add(tx[1].trim());
      entries.set(raw, entry);
    }
  }

  const now = new Date().toISOString().replace('T', ' ').slice(0, 16) + '+0000';
  const lines = [];
  lines.push(`# Copyright (C) ${new Date().getFullYear()} Marius Sergent`);
  lines.push(`# This file is distributed under the GPL-3.0-or-later license.`);
  lines.push(`msgid ""`);
  lines.push(`msgstr ""`);
  lines.push(`"Project-Id-Version: IA Webmaster Bridge\\n"`);
  lines.push(`"Report-Msgid-Bugs-To: https://github.com/RiusmaX/ia-webmaster-bridge/issues\\n"`);
  lines.push(`"POT-Creation-Date: ${now}\\n"`);
  lines.push(`"MIME-Version: 1.0\\n"`);
  lines.push(`"Content-Type: text/plain; charset=UTF-8\\n"`);
  lines.push(`"Content-Transfer-Encoding: 8bit\\n"`);
  lines.push(`"X-Generator: tools/extract-pot.mjs\\n"`);
  lines.push(`"X-Domain: ${TEXT_DOMAIN}\\n"`);
  lines.push('');

  const sorted = [...entries.entries()].sort((a, b) => a[0].localeCompare(b[0]));
  for (const [msgid, meta] of sorted) {
    for (const ref of [...meta.references].sort()) {
      lines.push(`#: ${ref}`);
    }
    for (const comment of meta.comments) {
      lines.push(`#. translators: ${comment}`);
    }
    lines.push(`msgid "${escapePo(msgid)}"`);
    lines.push(`msgstr ""`);
    lines.push('');
  }

  await mkdir(dirname(OUT), { recursive: true });
  await writeFile(OUT, lines.join('\n'));
  console.log(`Wrote ${OUT}`);
  console.log(`Strings extracted: ${entries.size}`);
}

main().catch((e) => { console.error(e); process.exit(1); });
