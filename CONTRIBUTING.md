# Contributing to IA Webmaster Bridge

Thanks for the interest. This document describes how to make a change
that lands cleanly.

## Repository layout

Quick orientation — the directories you'll touch most:

| Path | Role |
|---|---|
| `plugin/ia-webmaster-bridge/` | WordPress plugin (PHP, REST namespace `ia-webmaster/v1`) |
| `claude-plugin/mcp-gateway/` | MCP gateway (TypeScript, bundled) |
| `claude-plugin/skills/<slug>/SKILL.md` | Claude Code skills |
| `docs/` | Architecture, ops runbook, decisions log, specs |
| `specs/` | Per-feature specs (auto-loaded by the project's process rules) |

The full layout is in [`CLAUDE.md`](CLAUDE.md).

---

## Branching and PR flow

- Fork → branch per logical unit → PR back into `main`. One PR =
  one reviewable concern.
- Keep PRs small. If a change spans plugin + gateway + docs, that's
  fine in one PR (they ship together); two unrelated features should
  not.
- Rebase on `main` before opening the PR. Resolve conflicts in your
  fork.
- Every PR includes a short description of **why** (not just what),
  and a checklist that the docs and tests are updated.

---

## Code style

### PHP (plugin)

- Follow the
  [WordPress PHP coding standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/php/).
- 4-space indentation (per WPCS).
- Yoda conditions, escape on output, sanitise on input, slash for
  storage. The plugin's `IAWM_Support` helper centralises common
  patterns — reuse it.
- Use `__()` / `esc_html__()` / `esc_attr__()` for every
  user-visible string with text domain `ia-webmaster-bridge`.
  (Phase 7.5 is the active i18n pass — until it lands, add new
  strings i18n-ready even if the surrounding code isn't yet.)
- Each class lives in its own file `class-iawm-<slug>.php` under
  `includes/`. Top-level `IAWM_<Module>::init()` registers REST
  routes and hooks.

### TypeScript (gateway)

- Strict TypeScript. `tsc --noEmit` must pass.
- Module file names kebab-case; one tool registration per file when
  practical.
- Every MCP tool has a meaningful `description` and `inputSchema`
  (Zod). The description is what Claude reads to decide whether to
  call the tool — treat it as user-facing documentation.

### Skills

- One skill per directory under `claude-plugin/skills/<slug>/`.
- Mandatory `SKILL.md` with the standard frontmatter (`name`,
  `description`, `tools` if scoped). Description is short, ends with
  a `Use when…` phrase that lists the trigger conditions.
- English only (per the project's i18n decision in commit
  `829868d`). Skill names are kebab-case.

---

## Commit messages

Present tense, conventional commits style:

```
feat(divi): add branding writer endpoints
fix(security): close /database/search-replace confirmation gap
docs(operations): add weekly audit review SQL example
chore(deps): bump esbuild to 0.28.x
```

Common prefixes: `feat:`, `fix:`, `docs:`, `chore:`, `refactor:`,
`test:`, `perf:`, `style:`.

For substantial commits, include a body that explains the **why** and
calls out any consequences (especially in security-related changes).
The existing log is a good reference.

---

## Tests required for new endpoints

Phase 7.6 (in progress) introduces PHPUnit + the `yoast/wp-test-utils`
scaffold. Once landed:

- Every new REST endpoint comes with at least one happy-path test and
  one rejection test (auth, scope, validation).
- Tests live under `plugin/ia-webmaster-bridge/tests/test-<module>.php`.
- Run locally with `composer test` from `plugin/ia-webmaster-bridge/`.

Until 7.6 lands, a new endpoint PR is expected to be accompanied by a
manual validation walk in [`docs/validation-checklist.md`](docs/validation-checklist.md)
documenting the cases you exercised.

---

## Docs required for new endpoints

For every new endpoint **and** every new MCP tool:

- The MCP tool's `description` (in the gateway) explains what it does,
  what it returns, what guarantees it gives (dry-run? confirmation
  token? auto-backup?).
- At least a mention in either the README's feature list or in a
  relevant `docs/*.md`.
- If the endpoint changes the security posture, a new entry in
  [`docs/decisions.md`](docs/decisions.md) (D-NNN, see existing
  examples).
- If the endpoint changes operator-facing behaviour, a paragraph in
  [`docs/operations.md`](docs/operations.md).
- If a spec is touched, refresh its `Status` and date headers.

---

## Security disclosure

**Do not** open public issues for security vulnerabilities. Use the
private channel described in [`docs/security-model.md`](docs/security-model.md)
→ "Reporting a vulnerability".

We will acknowledge within 5 business days. High-severity issues aim
for a fix or mitigation within 30 days. Credit is given (with
permission) in the changelog and release notes.

---

## Local development

```bash
# Plugin: copy folder + activate.
wp plugin install /path/to/plugin/ia-webmaster-bridge --activate

# Gateway: install deps and build the bundle.
npm install --prefix claude-plugin/mcp-gateway
npm run build --prefix claude-plugin/mcp-gateway

# Operator-machine config (gitignored, lives at ~/.iawm/config.json).
# Template at claude-plugin/mcp-gateway/config.example.json.
```

After a gateway rebuild, **restart Claude Code** so the MCP bundle
reloads. If you installed via `/plugin install`, also copy
`dist/index.js` into `~/.iawm/gateway/index.js` (or re-run
`/plugin install`) — see
[`docs/validation-checklist.md`](docs/validation-checklist.md) for
the gateway-bundle-vs-repo gotcha.

Develop against a local WordPress site (LocalWP), never directly
against production. See [`docs/production-deployment.md`](docs/production-deployment.md)
for the production install path.

---

## License

By contributing to this project you agree your changes are licensed
under [GPL-3.0-or-later](LICENSE), the project's license.
