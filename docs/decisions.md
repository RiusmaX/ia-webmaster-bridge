# Decision log

> Status: Living · Last updated: 2026-05-25

Each decision has an identifier (`D-NNN`), a date, a status
(`Accepted` / `To revisit` / `Superseded`), its context, the decision, and its
consequences. Decisions are never deleted: mark them `Superseded` and add a
new one.

## D-001 — Take inspiration from the official concepts, not external dependencies

- Date: 2026-05-21 · Status: Accepted
- **Context**: WordPress 6.9+ provides the Abilities API (in core) and an
  official MCP Adapter (`WordPress/mcp-adapter`, external, v0.5.0 — pre-1.0,
  subject to breaking changes).
- **Decision**: we take inspiration from these concepts but do not depend on
  the external MCP Adapter. The core Abilities API may eventually serve as an
  internal registry (it's in core, not a third-party dependency) — to be
  decided in Phase 1.
- **Consequences**: full control, no breakage caused by a pre-1.0 component; a
  bit more code to write on our side.

## D-002 — 100% in-house adapter

- Date: 2026-05-21 · Status: Accepted
- **Context**: we want guaranteed compatibility and no dependency on an
  external component.
- **Decision**: we build our own WordPress plugin (custom REST API) and our
  own local MCP bridge (Node.js). The plugin stays plain WordPress REST; the
  MCP bridge isolates the MCP protocol on the user's machine side.
- **Consequences**: two components to maintain, but decoupled and
  independently versionable; no inherited attack surface from a third party.

## D-003 — Priority builder: Divi 5

- Date: 2026-05-21 · Status: Accepted
- **Context**: most target sites run Divi 5, which is less documented than
  Elementor for programmatic generation.
- **Decision**: Divi 5 is the priority target. Elementor is deferred.
- **Consequences**: Phase 3 includes reverse-engineering the Divi 5 format on
  the local site. Stated approach: test and iterate.

## D-004 — Local development first (LocalWP)

- Date: 2026-05-21 · Status: Accepted
- **Context**: iterating on a production site is risky.
- **Decision**: development and tests on a local WordPress 7.0 under LocalWP.
  Progressive rollout: local → small prod → large prod.
- **Consequences**: no direct iteration on a production site; each target is a
  distinct profile in the MCP bridge.

## D-005 — First-class security

- Date: 2026-05-21 · Status: Accepted
- **Context**: an agent with full webmaster powers is a major attack surface;
  plugin ↔ bridge exchanges must not introduce a vulnerability.
- **Decision**: API key authentication + HMAC signature, HTTPS enforced,
  scoped capabilities, audit log, safeguards (dry-run, draft, prior backup),
  kill switch. Details in `specs/02-security.md`.
- **Consequences**: security is built in from Phase 1, not added afterwards.

## D-006 — Infrastructure operations via the plugin

- Date: 2026-05-21 · Status: Accepted
- **Context**: we have SSH access to the servers, but prefer not to expose a
  shell to the agent.
- **Decision**: infrastructure operations go through controlled, logged plugin
  endpoints. SSH/WP-CLI remains a fallback channel (reserved for the human
  operator or cases where the plugin is unavailable).
- **Consequences**: reduced attack surface; some system operations will have
  to be explicitly wrapped on the plugin side.

## D-007 — Plugin name and REST namespace

- Date: 2026-05-21 · Status: Accepted
- **Context**: a provisional name had been proposed for the plugin and its
  API.
- **Decision**: the plugin is called "IA Webmaster Bridge" (slug
  `ia-webmaster-bridge`); the REST API is exposed under the namespace
  `ia-webmaster/v1`.
- **Consequences**: names locked in for the repo and the code to come.

## D-008 — Integration shipped as a Claude Code plugin

- Date: 2026-05-22 · Status: Accepted
- **Context**: we want a clean integration into Claude (plugin, connector or
  extension — whichever fits best).
- **Decision**: ship a **Claude Code plugin** packaging the MCP bridge and the
  skills. This is Claude Code's native format for grouping an MCP server,
  skills, and commands. Ruled out: the "Claude Desktop extension" (wrong
  environment), and the bare `.mcp.json` (no packaging).
- **Consequences**: `mcp-gateway/` and `skills/` will be structured as a
  plugin with a manifest; clean installation and updates.

## D-009 — Access to logs and diagnostics

- Date: 2026-05-22 · Status: Accepted
- **Context**: we want to be able to diagnose and debug the site through the
  agent.
- **Decision**: add a **read-only** diagnostics module: WordPress debug.log,
  Site Health, plugin/theme status and versions, environment versions.
  Read-only is the safeguard; every access is still recorded in the audit
  log.
- **Consequences**: new `IAWM_Diagnostics` module on the plugin side.

## D-011 — Dedicated agent WP user with restricted role

- Date: 2026-05-25 · Status: Accepted
- **Context**: until v0.18.x the adapter borrowed the first administrator's
  identity (`act_as_agent`) to perform writes. Two problems: (1) the WordPress
  audit trail attributes all agent actions to the human operator, blurring
  accountability; (2) a leaked HMAC secret would have granted full
  super-admin powers — the blast radius of a key compromise equalled
  the maximum admin powers on the site.
- **Decision**: introduce a dedicated WordPress user (`iawm-agent`) tied
  to a custom role (`iawm_agent`), created on plugin activation. The role
  is administrator-like but stripped of the highest-risk capabilities
  (`unfiltered_html`, `unfiltered_upload`, `edit_plugins`, `edit_themes`,
  `edit_files`, multisite super-admin). The application layer additionally
  refuses any attempt by the API to modify or delete that user.
- **Consequences**: WordPress audit records clearly attribute every
  write to the agent; a leaked HMAC secret limits the attacker to the
  `iawm_agent` role's surface. Operators can further tighten the role
  via the `iawm_agent_role_caps` filter.

## D-012 — Per-key scopes enforced at the auth layer

- Date: 2026-05-25 · Status: Accepted
- **Context**: a single boolean (authenticated or not) was too coarse:
  any successful HMAC check granted every endpoint, including the most
  destructive ones (plugin install, settings update, user management).
  Spec 02 already called for scoped capabilities — this realises it.
- **Decision**: every API key carries a list of scopes —
  `read`, `content:write`, `divi:write`, `config:write`, `infra:write`.
  The scope required by an incoming request is derived from its HTTP
  method (GET → `read`) and its path prefix (`/divi/*`, `/config/*`,
  `/plugins/*` → `infra:write`, etc.). A mismatch returns HTTP 403
  `iawm_scope_denied`. The check happens **after** the HMAC verification
  to avoid leaking scope information to unauthenticated callers. Legacy
  keys without an explicit scope list remain fully-scoped, so the v0.19
  upgrade does not break existing installs.
- **Consequences**: an HMAC secret tied to e.g. only `read` cannot
  trigger any write; an infra-scoped key cannot leak via a Divi or
  content path. Scope assignment is prefix-based so new routes inherit
  the right scope automatically by their family. The admin UI lets the
  operator generate, retighten or rotate scopes without leaving WP.

## D-013 — Pre-op backups as snapshots, not full dumps

- Date: 2026-05-25 · Status: Accepted
- **Context**: spec 02 calls for a backup before any destructive
  operation, but a full filesystem + database backup is out of the
  plugin's scope (touches files outside `wp-content`, needs server
  access, is heavy). At the same time, operations done via this plugin
  affect a well-bounded slice of state — options, plugin activation
  status, sometimes raw tables.
- **Decision**: implement three snapshot kinds in `IAWM_Backup`:
  `options` (JSON map of WP option keys), `plugins_state` (a derived
  options snapshot of `active_plugins` + `recently_activated` plus the
  installed plugin list) and `tables` (SQL dump of named tables).
  Snapshots live in a dedicated `wp_iawm_backups` table; restore is
  done by the same plugin, with `dry_run` first. A full filesystem
  backup is left to dedicated tooling and is out of scope.
- **Consequences**: the plugin's auto-backup before destructive ops is
  fast (low-byte JSON snapshot in most cases), restorable in-band, and
  bounded to what the API can plausibly affect. For heavy database
  operations (`search-replace`, raw SQL) the caller asks for `tables`
  explicitly — keeping snapshot cost intentional.

## D-014 — Pre-op snapshot is automatic and surfaced as `pre_op_backup_id`

- Date: 2026-05-25 · Status: Accepted
- **Context**: we want safety by default, not safety by remembering to
  ask for it. But there are legitimate cases (chained retries, dry-run
  composition flows) where the caller does not want an extra snapshot.
- **Decision**: destructive endpoints (`plugins/install`,
  `plugins/activate`, `plugins/deactivate`, risky settings updates)
  automatically call `IAWM_Backup::snapshot_*()` before applying the
  change. The new backup id is surfaced in the response as
  `pre_op_backup_id`. Callers can opt out per request with
  `skip_backup: true`, which is intended for dry-runs and re-tries
  where the previous snapshot is still valid.
- **Consequences**: the agent does not need to think about backups for
  the common path; the operator gets a free safety net; advanced
  callers retain control. Backup table needs periodic pruning, exposed
  via `/backup/prune`.

## D-015 — Confirmation tokens, single-use, body-bound

- Date: 2026-05-25 · Status: Accepted
- **Context**: backups + scopes drastically reduce risk, but the most
  destructive actions (restoring a snapshot, updating WP core, running
  a real search-replace across the DB) still mutate the site in ways
  that are hard or impossible to undo. We want explicit, parameterised
  consent for those — not a flag in a config file.
- **Decision**: introduce `IAWM_Confirmation`. The endpoints in
  `REQUIRES_CONFIRMATION` (currently `/backup/restore`, `/core/update`,
  `/database/search-replace`) require a two-step pattern: the first
  call (no token, no `dry_run`) returns
  `requires_confirmation: true` with a fresh 32-byte hex token and a
  summary; the same call re-issued with `confirmation_token: <token>`
  in the body actually applies the action. Tokens are single-use,
  expire after 5 minutes, and are bound to a sha256 of the
  (route, key id, normalised body) tuple. `dry_run` requests are
  exempt (a preview never mutates state).
- **Consequences**: the agent has to perform a deliberate two-step
  flow for destructive actions, which is the entire point. Token
  storage uses WordPress transients keyed by sha256(token), so the
  raw token never appears in the DB. Combined with HMAC, scope check,
  audit log and pre-op backups, this gives the final gate before a
  destructive write.

## D-016 — Database operations: explicit allow-list, never raw shell

- Date: 2026-05-25 · Status: Accepted
- **Context**: WP-CLI's `search-replace` is the canonical "post-domain-
  move" tool, and a SQL console is the canonical debugging tool — but
  exposing either as a raw passthrough to an AI agent would be an
  enormous attack surface, even with HMAC and audit. We need a
  shaped, opinionated surface that covers the legitimate use cases.
- **Decision**: ship four narrow endpoints — `/database/info`,
  `/database/export`, `/database/query`, `/database/search-replace`.
  Reads (info, query) are gated by `infra:write` (query because it can
  still be a load DoS, and to stay aligned with WP-CLI semantics).
  `/database/query` is restricted to SELECT/WITH; semicolons,
  `INTO OUTFILE`, `LOAD_FILE`, `BENCHMARK`, `SLEEP()` are refused; a
  LIMIT is forcibly appended. `/database/search-replace` only operates
  on an allow-list of (table, column) pairs that are known to need it
  (options, posts, *meta, comments) and walks PHP-serialised payloads
  recursively to keep length counters valid. Real applies are gated by
  D-015's confirmation token.
- **Consequences**: ad-hoc INSERTs / UPDATEs / DELETEs go through their
  business endpoints (`content/*`, `config/*`, `themes/*`, etc.) where
  rules and audit are tighter. The agent has enough to perform a domain
  move or a structured data audit, but cannot drop a table or write
  raw SQL.

## D-017 — Multi-key with linked WP users (audit-only association)

- Date: 2026-05-25 · Status: Accepted
- **Context**: a single shared HMAC secret per site does not scale to
  teams. We want each human operator to use their own Claude with
  their own `~/.iawm/config.json`, and the audit log to reflect WHO
  triggered each call — not just "the API key did it".
- **Decision**: refactor the credentials storage to a map keyed by
  key_id. Each record carries label, secret, scopes, optional
  `linked_user_id` (a WP user id), `created_at` and `last_used_at`.
  The HMAC executor remains the dedicated agent user
  (`iawm-agent`) regardless of which key signs — that keeps the
  WP-side capability surface tight. The `linked_user_id` only flows
  into the audit log so the operator can see, e.g., "Alice's Claude
  triggered this restore". Legacy single-record installs are
  migrated transparently on first read; the existing key keeps
  working with a synthetic label `"Legacy key"`.
- **Consequences**: zero-downtime key rotation becomes natural —
  create a new key, switch the gateway, revoke the old one. Multi-
  team setups become viable. The admin UI grows from one form to a
  table of keys with per-row scope, label, linked user, last-used
  display. No security regression: each key still goes through the
  same HMAC + scope + audit + backup pipeline, just with finer
  attribution.

## D-018 — Auto-generated module registry from the Divi install

- Date: 2026-05-25 · Status: Accepted
- **Context**: hand-curating the list of Divi 5 modules (and their
  WooCommerce variants) is brittle. Each Divi update can rename a
  block, change a default attribute path, add a new module. We had
  manually catalogued ~48 of the 105 blocks Divi ships, with at least
  one wrong block name (`divi/post-navigation` was a typo for the real
  `divi/post-nav`). We want a single source of truth that survives
  Divi upgrades.
- **Decision**: ship `tools/scan-divi-modules.mjs` — a Node script
  that walks the Divi theme's `visual-builder/packages/module-library/
  src/components/` directory, parses every `module.json` and
  `module-default-render-attributes.json`, and writes three artefacts:
    - `docs/divi5-modules-registry.json` — structured registry
      (one record per module with its block name, slug, family,
      category, accepted children, default attribute paths);
    - `docs/divi5-modules-catalog.md` — human-readable catalog with
      one section per family + the full flat attribute path list
      per module;
    - `claude-plugin/mcp-gateway/src/divi/modules-registry.ts` —
      the TypeScript `DiviBlock` enum and `DIVI_MODULES` runtime
      array used by the gateway. `types.ts` re-exports these.
- **Consequences**: the gateway now knows every Divi 5 module (105
  vs. the previous 48). The MCP catalog tools (`iawm_divi_modules_
  catalog`, `iawm_divi_module_info`) let Claude introspect the
  registry at runtime — useful for free-form composition without
  having a typed builder. Bug fix in passing: the spurious
  `divi/post-navigation` block name is corrected to `divi/post-nav`.
  Regenerating after a Divi upgrade is one command:
  `node tools/scan-divi-modules.mjs`.

## D-019 — Design system writes (global colors, fonts, variables, theme options)

- Date: 2026-05-25 · Status: Accepted
- **Context**: until v0.27.x the API could READ the Divi design
  system (global colors, fonts, variables) but not write it. That
  meant every generated page had to either hard-code colours and
  fonts or assume a pre-configured palette — pages were
  surface-decorated rather than truly on-brand. To produce
  "ultra-personalised pages with reusable components and centralised
  variables", the agent needs to OWN the design system.
- **Decision**: wrap Divi's four write endpoints under
  `/divi/global-data/{colors,fonts,variables}/update` and
  `/divi/theme-options/{get,update}`. Three of them have
  full-replace semantics (colours, variables); fonts is a two-field
  set; theme-options is a merge (read current, patch, write). All
  go through the standard pipeline — `infra:write`-equivalent scope
  via `divi:write`, audit logged, kill-switch respected, `dry_run`
  supported. The dedicated agent user has `edit_posts`, which is
  what Divi requires for these endpoints.
- **Consequences**: the agent can now run a "design-system first"
  workflow (`docs/design-system.md`): read → normalise the brand
  palette → write the tokens → author pages that reference the
  tokens. Changing one `gcid-*` value cascades through every page
  that referenced it. The hand-off to the human via the admin UI
  still works exactly the same: this just complements the manual
  flow with a programmatic one.

## D-020 — Lifecycle rotation policies (90 days audit / 50 backups)

- Date: 2026-05-25 · Status: Accepted
- **Context**: the audit log and the backup table grow indefinitely if
  left alone. On a busy site, both reach hundreds of thousands of rows
  within a year, causing slow admin pages, expensive `wp_iawm_*`
  queries, and an opaque incident response (you can no longer skim
  the audit log). At the same time, throwing data away too early
  destroys the security signal the audit log is supposed to carry,
  and recent backups should outlive any plausible roll-back window.
- **Decision**: ship two WP-Cron jobs registered on activation,
  `iawm_prune_audit_log` (daily at 03:00) and `iawm_prune_backups`
  (daily at 03:15). Retention windows are options
  `iawm_audit_retention_days` (default **90 days**) and
  `iawm_backup_keep_n` (default **50 records**), both editable from
  the admin Cleanup tab.
  - 90 days for audit is long enough to investigate a leak that
    surfaced 2–3 months after the fact (typical lag for a stolen
    secret to be used), short enough to keep query cost flat.
  - 50 backups is roughly 1–2 months of pre-op snapshots on an
    actively-managed site; an operator who runs more than that per
    week probably needs a custom retention anyway.
- **Consequences**: the two surfaces stay self-pruning by default,
  but the operator can dial them up (e.g. 365 days audit for
  compliance) or down (e.g. 14 backups on a high-volume site) from
  the admin UI without code changes. The same rotation principle
  will apply to any future log-like surface — register a cron + an
  option, default sane.

## D-021 — Smoke test approach (HTTP probe + debug.log scan + state checks)

- Date: 2026-05-25 · Status: Accepted
- **Context**: until Phase 7.2 there was no programmatic way for the
  agent to confirm "did the destructive operation I just ran break
  anything?". The agent could read `debug.log` via diagnostics, but
  parsing it for relevant fatal entries, then cross-checking with
  the site's HTTP status, the kill-switch state, the agent user, the
  active Divi version, was repetitive boilerplate that the agent had
  to re-derive every time. A clear "post-op health probe" was needed.
- **Decision**: ship `/diagnostics/smoke` as the single
  "did-it-survive" endpoint. It runs four independent checks:
  - **HTTP probe** of `home_url()` over `wp_remote_get` (follows
    redirects), records final status and final URL.
  - **debug.log scan** for any `PHP Fatal error` line whose
    timestamp is within the last 10 minutes.
  - **State checks**: kill switch state, agent user existence + role
    integrity, Divi activity, summary of plugin versions.
  - Aggregates into a top-level `healthy: true|false`.
- The endpoint is a **read endpoint** and does not alert. The agent
  is expected to call it after any destructive operation and the
  operator can pin its result to a dashboard.
- A companion `/diagnostics/check-self` covers the install
  invariants (tables, cron jobs, credentials presence, HTTPS state)
  — useful after a plugin upgrade and to gate the production go/no-
  go check in [`production-deployment.md`](production-deployment.md).
- **Consequences**: agent skills (and the Phase 7.7
  `site-smoke-test` skill) have a single call to make. The endpoint
  is cheap (one HTTP request + a file tail); it's safe to call
  liberally. The 10-minute fatal window is a deliberate trade-off:
  long enough to catch most slow-failing ops, short enough not to
  return ancient unrelated errors.

## D-022 — HTTPS enforcement via constant, IP allow-list as second line

- Date: 2026-05-25 · Status: Accepted
- **Context**: Phase 5 closed the application-level threat model
  (HMAC, scopes, audit, backups, confirmation). Two network-level
  weaknesses remained: a request could in theory be served over HTTP
  (leaking the signed payload + token to anyone on the path), and
  the bridge accepted requests from any source IP — making a leaked
  secret usable from any internet host. Both are textbook hardenings
  but had been left for Phase 7 to keep the application-level work
  shippable.
- **Decision**: enforce HTTPS via a **`wp-config.php` constant**
  `IAWM_REQUIRE_HTTPS` (not an admin-UI toggle), and add an IP
  allow-list as an admin-editable option `iawm_ip_allowlist`. Both
  checks happen in `IAWM_Auth::guard()` before credentials
  resolution. Rationale:
  - The HTTPS check belongs in `wp-config.php` because that file is
    typically owned by the deployment process, not the WP admin
    UI. A compromised WP admin account (different threat model —
    see [`security-model.md`](security-model.md)) cannot silently
    disable HTTPS enforcement. Toggling it on/off requires
    filesystem access.
  - The IP allow-list belongs in the admin UI because it changes
    often (operator on a train, new VPN range, new team member)
    and is non-destructive when wrong (worst case: the operator
    locks themselves out, and loopback / WP-CLI recovery still
    work).
  - Both run **before** key resolution so an attacker probing the
    namespace from an unauthorised IP cannot learn which key ids
    exist (no detail leakage in the 403).
  - Both **default to off** for back-compat (HTTPS constant absent
    = no enforcement; empty allow-list = allow-all). The operator
    must opt in. The
    [`production-deployment.md`](production-deployment.md)
    checklist makes opting in mandatory for any non-local install.
- **Consequences**: a production install with both features enabled
  reduces the attack surface from "anyone with the secret" to
  "anyone with the secret AND on the allow-list AND via HTTPS".
  Operators behind a reverse proxy honour `X-Forwarded-For` only
  when `IAWM_TRUST_PROXY_HEADER` is also set in `wp-config.php` —
  matching the constant pattern for security-critical toggles. Both
  features are now part of the pentest checklist in
  [`operations.md`](operations.md).

## D-023 — i18n strategy (English source, .pot generated, fr_FR first locale)

- Date: 2026-05-25 · Status: Accepted
- **Context**: until Phase 7.4 the plugin shipped English-only
  user-visible strings, despite declaring a `Text Domain` header.
  The codebase i18n pass (commit `829868d`) had translated source
  comments and docstrings to English but had not wrapped admin UI
  strings in `__()` / `esc_html__()`. A French operator still saw
  English notices. Beyond the maintainer's locale, this is a
  blocker for adoption by any operator who isn't comfortable in
  English.
- **Decision**: codebase source language stays English (per the
  internationalisation pass) but every user-visible string gets
  wrapped in the WordPress i18n functions
  (`__`, `_e`, `esc_html__`, `esc_attr__`) with text domain
  `ia-webmaster-bridge`. The plugin loads its translations via
  `load_plugin_textdomain` on `plugins_loaded`. The translation
  workflow:
  - `languages/ia-webmaster-bridge.pot` is generated by
    `wp i18n make-pot` (or equivalent — Loco Translate, manual
    `xgettext`) and committed.
  - First locale shipped: French (`ia-webmaster-bridge-fr_FR.po` +
    `.mo`). This is the maintainer's locale and the second-most-
    likely operator language.
  - Additional locales welcome via PR — see
    [`CONTRIBUTING.md`](../CONTRIBUTING.md).
- **Consequences**: a French operator sees the plugin in French if
  their WP `Site Language` is set accordingly; a German operator
  who provides a `de_DE.po` gets German; the source-of-truth strings
  remain English, matching the project's worldwide-adoption goal.
  The `.pot` file becomes part of the release artefacts; PRs that
  add user-visible strings must update the `.pot` or at minimum
  flag the strings as added so the next release can regenerate it.

## D-024 — Per-site context as a server-side WP option, not a per-operator file

- Date: 2026-05-25 · Status: Accepted
- **Context**: spec 07 calls for a "per-site context file" that
  describes the site so Claude can act as a competent webmaster on
  it. Two natural homes were considered: (a) a markdown file on each
  operator's machine (e.g. under `~/.iawm/site-context/<site>.md`),
  or (b) a single WP option that every operator and every Claude
  session sees.
- **Decision**: option (b). Store the structured context in
  `iawm_site_context` — a single WP option, accessed by the new
  `IAWM_Context` module via `/site-context/{get,update,clear}`. The
  context follows the SITE, not the operator's workstation.
- **Consequences**:
  - Multi-operator setups share the same brand brief without
    coordinating .md files. Adding a second operator (per multi-key
    workflow) means they inherit the curated context immediately.
  - The context is editable from the admin (`Settings → IA
    Webmaster Bridge → Context` tab) so non-Claude users can also
    curate it.
  - Backup: the context option is captured automatically by the
    options-snapshot machinery when relevant settings get touched
    — no separate backup path needed.
  - Trade-off: the operator loses the ability to keep
    site-specific notes "off the server". For sensitive notes,
    the operator can still keep a private file outside the API
    surface — the API context is for things the team agrees on.
- **Implementation**: `IAWM_Context` module + `iawm_site_context_*`
  MCP tools + admin tab + `site-context-discovery` skill that
  bootstraps the context from observable signals on a fresh install.

## D-025 — Yoast SEO as a first-class backend alongside Rank Math

- Date: 2026-05-25 · Status: Accepted
- **Context**: until v1.1.0 `IAWM_Seo` exposed a normalized API but
  only had a Rank Math backend; a Yoast branch existed but bailed out
  with `yoast_not_implemented`. Several user sites run Yoast and need
  the same MCP surface (`iawm_seo_page_get` / `iawm_seo_page_update`)
  without a backend swap.
- **Decision**: implement the Yoast branch fully and auto-detect
  which plugin is active. The normalized payload (title, description,
  focus keyword, canonical, OpenGraph triplet, Twitter pair,
  noindex/nofollow) maps 1:1 to Yoast postmeta. The only structural
  difference vs Rank Math: Yoast stores noindex/nofollow as two
  separate postmeta entries (`_yoast_wpseo_meta-robots-noindex` and
  `_yoast_wpseo_meta-robots-nofollow`) with values `'1'` / `''`,
  while Rank Math serializes them in a single list under
  `rank_math_robots`. The dispatcher branches at the get/set level so
  the API stays identical.
- **Consequences**:
  - Sites can switch between Rank Math and Yoast without touching
    Claude's prompts or workflows.
  - If both plugins are active (rare but possible during a migration),
    the dispatcher picks Rank Math first as the historical default;
    operators can force Yoast by deactivating Rank Math.
  - The skill `seo-wordpress` documents both backends as
    interchangeable.

## D-026 — 404 tracker uses URL+IP transient dedup, optional sampling

- Date: 2026-05-25 · Status: Accepted
- **Context**: a per-request log of every 404 on a high-traffic site
  would balloon the `wp_iawm_404_log` table within hours: a single
  vulnerability scanner hits hundreds of paths from one IP, and good
  search engines hit the same broken URL repeatedly. We want signal,
  not row count.
- **Decision**: insert at most one row per `(url, IP)` pair per 60 s
  via a transient `iawm_404_dedup_{sha1(url|ip)}`. When the dedup key
  exists, we **update** the existing row (bump `hit_count`, refresh
  `last_seen`) instead of inserting a new one. Distinct IPs still
  create distinct rows the first time they hit a URL — useful to
  tell "one scanner" from "many users hitting a broken link". Add
  an optional sampling denominator (`iawm_404_sampling_rate`,
  default 1 = record everything) so very high-traffic sites can
  trade resolution for table size.
- **Consequences**:
  - Table size stays bounded (~tens of thousands of rows on a normal
    site; scanner spikes don't explode it).
  - Distinct-IP rollup gives a real popularity signal without storing
    one row per hit.
  - The 60 s transient TTL is short enough that real visitors are not
    silently dropped if they share a NATed IP; it's long enough to
    absorb scanner bursts.
  - Daily prune at 03:30 (offset from audit 03:00 and backup 03:15)
    keeps cron load distributed.

## D-027 — Multisite tolerance: global user, per-site role and tables

- Date: 2026-05-25 · Status: Accepted
- **Context**: until v1.1.0 the plugin assumed a single-site install.
  The agent WordPress user was created on whatever site happened to
  run activation; the audit, backup, link-issues and 404 tables were
  installed per `$wpdb->prefix` (so per-site by accident) but only on
  the activation site; the role assignment lived on a single site
  too. Network-activating the plugin left every sub-site half-broken.
- **Decision**:
  - The dedicated `iawm-agent` WordPress user is created **once
    globally** for the network (`IAWM_Agent_User::ensure_global_user`).
    The user record is shared; only the per-site capability mapping
    is local.
  - The `iawm_agent` **role** and every per-feature table (audit,
    backups, link issues, 404 log) are installed **per sub-site**
    inside a `switch_to_blog()` loop on network activation, and
    automatically on new sub-sites via `wp_initialize_site`
    (`wpmu_new_blog` registered as a legacy fallback for pre-WP-5.1).
  - The auth, scopes and credentials options stay per-site: each
    sub-site has its own keys and kill switch. This matches the
    natural blast-radius boundary of multisite — operators routinely
    delegate sub-sites to separate teams.
  - A new `IAWM_Network_Admin` adds a Network Admin → Settings page
    listing every sub-site (blog id, URL, key count, kill switch
    state, last audit row, next cron timestamp).
  - A new `/status/network` endpoint exposes topology so Claude can
    detect a multisite at session start without paying the cost of
    enumerating sites.
- **Consequences**:
  - Single-site installs are unaffected; the new code paths are
    guarded by `is_multisite()`.
  - Operators can audit network-wide health from one place without
    having to switch_to_blog manually.
  - Credentials sharing across sub-sites is **not** automatic on
    purpose — each sub-site issues its own keys, which keeps blast
    radius local.

## D-028 — Broken-links scanner scope: published content, HEAD→GET, throttled

- Date: 2026-05-25 · Status: Accepted
- **Context**: a broken-link audit could mean many things — only the
  homepage, every published page, every revision, every comment,
  external links only, etc. We need a deterministic scope that runs
  in reasonable time on a real site and produces actionable output.
- **Decision**:
  - Scope: every **published** post in every public post type
    (`posts`, `pages`, plus any custom post type registered as
    public). Drafts, trash, attachments, revisions are skipped.
  - Extraction: `DOMDocument::loadHTML` of the rendered post content,
    pulling every `<a href>` and `<img src>`. Regex fallback if
    DOMDocument errors. Skip `#anchor`, `mailto:`, `tel:`,
    `javascript:`, `data:`.
  - Probe: HEAD request first (fast, cheap); on 400/403/405/501
    or other "HEAD-hostile" responses, retry with GET. 100 ms
    throttle between requests to avoid hammering the host.
  - Classification: HTTP status code if available; otherwise classify
    `WP_Error` by message substring (`timeout` / `dns` / `ssl` /
    `other`). Redirects (3xx) are followed and the final URL is
    recorded as `redirect_to`.
  - Dedup: in-scan (don't probe the same URL twice in one run) **and**
    against the existing `wp_iawm_link_issues` table (don't re-record
    a known-unresolved issue, just refresh `found_at`).
- **Consequences**:
  - One scan of a 200-post site finishes in 1–3 minutes on a typical
    server; the throttle keeps it polite.
  - Drafts are not scanned because they're not visible to users; this
    matches the "find what your visitors see broken" use-case.
  - Internal vs external is recorded so operators can fix internal
    issues directly and choose whether external dead links matter.

## D-029 — Static gateway catalogue, not runtime `/capabilities` discovery

- Date: 2026-05-25 · Status: Accepted
- **Context**: spec 01 originally described a gateway that would query
  the plugin's `/capabilities` endpoint at startup and generate its
  MCP tools from the returned schemas. Two years later, the catalogue
  is **declared statically** in `claude-plugin/mcp-gateway/src/tools.ts`
  (100 tools in v1.2.0), each with a Zod input schema and a typed
  handler. No `/capabilities` endpoint exists on the plugin side.
- **Decision**: ratify the static approach as the long-term shape.
  Adding a capability requires a coordinated edit on both sides
  (plugin route + gateway tool + bundle rebuild) — the bundled
  distribution makes that ergonomic.
- **Rationale**:
  - **Type safety**: Zod at compile time catches drift between the
    gateway tool's input schema and what the route actually accepts.
    A dynamic catalogue would be JSON-schema at runtime, with no
    TypeScript checking on call sites.
  - **Smaller attack surface**: no runtime-introspection endpoint to
    exploit (no path traversal via crafted capability names, no
    information leak via inspectable schemas).
  - **Simpler audit**: a code reviewer can read `tools.ts` and see
    every tool the gateway exposes in one file. With dynamic
    discovery, the live tool set would depend on plugin version and
    feature flags.
  - **Cheaper smoke test**: `iawm_ping` + `iawm_status` is enough to
    detect catalogue drift in CI; no separate "schema diff" job
    needed.
- **Trade-offs**:
  - Third parties can't extend the gateway dynamically. Acceptable —
    this project is not designed to host third-party capabilities,
    and the bundled distribution is opinionated.
  - Adding a feature requires touching both repos in lockstep. Lived
    with in practice; the cycle plugin route → gateway tool → bundle
    rebuild → copy `dist/index.js` is a single commit.
- **Implementation**: spec 01 rewritten to describe the static
  catalogue; the "Dynamic vs static discovery" section now explains
  why the static path was kept.

## D-030 — Outbound webhooks: HMAC-SHA256 over (ts \n nonce \n body), 5-min drainer, 3-attempt dead-letter

- Date: 2026-05-25 · Status: Accepted
- **Context**: Phase 9.4 asked for outbound notifications so external
  receivers (Slack incoming webhooks, generic JSON endpoints, internal
  relays) learn about interesting events on the site (smoke-test
  failure today; audit alert, key-rotation reminder later). Three
  axes had to be decided: the signing scheme, the trust model around
  the per-hook shared secret, and the retry policy when a receiver is
  briefly unavailable.
- **Decision**:
  - **Signing scheme**. Every POST carries three headers:
    `X-IAWM-Webhook-Timestamp` (unix epoch seconds),
    `X-IAWM-Webhook-Nonce` (16 random hex bytes), and
    `X-IAWM-Webhook-Signature` (`sha256=` + hex HMAC-SHA256 over
    `timestamp + "\n" + nonce + "\n" + body`). Receivers verify by
    checking the timestamp is within ±5 minutes of now and recomputing
    the HMAC with constant-time compare (`hash_equals` / `crypto.timingSafeEqual`).
    Body is the canonical JSON envelope `{event, site_url, fired_at, payload}`.
  - **Trust model**. The `signing_secret` is a 16+ character shared
    secret, generated and provided by the operator at create time and
    **never** read back through the API (list/update responses redact
    it). To rotate, the operator issues an update with a fresh value.
    Outbound signing protects authenticity and integrity of the
    delivery — it does NOT authorize the receiver to do anything on
    the WP site (the inbound HMAC auth model is a separate trust
    plane). The secret is stored as plaintext in `wp_iawm_webhooks`
    for v1.3.0 — see trade-offs below.
  - **Retry policy**. `IAWM_Webhook::fire()` enqueues a row per
    matching webhook in `wp_iawm_webhook_outbox` with `status='pending'`.
    A WP-Cron job `iawm_webhook_drain` runs every 5 minutes on a
    custom `iawm_5min` schedule, draining a small batch each tick.
    On HTTP 2xx the row flips to `sent`. Any other outcome
    (non-2xx, transport error, timeout) increments `attempts` and
    stores `last_error`. Backoff between attempts: 1 minute, then
    5 minutes, then 30 minutes. After 3 failed attempts the row is
    dead-lettered (`status='dead'`) and never retried — it surfaces
    via the list endpoint but no longer consumes drainer cycles.
- **Rationale**:
  - HMAC-SHA256 over `timestamp + nonce + body` mirrors the inbound
    auth scheme (`IAWM_Auth::build_message`), giving operators a
    single mental model for "how the bridge signs things". The
    nonce + timestamp pair makes replay attacks ineffective at the
    receiver side without requiring nonce storage on our end.
  - Out-of-band delivery (outbox + cron) keeps the firing path
    cheap — a request that triggers a smoke run still returns in
    milliseconds even if the receiver is slow or down.
  - Bounded retries (3 attempts, capped at ~36 minutes total) match
    the "interesting events" use-case: a 36-minute outage is
    usually long enough for the operator to notice through another
    channel anyway.
- **Trade-offs**:
  - **Plaintext secret at rest**. Encrypting the secret with a key
    derived from `wp-config.php`'s `AUTH_KEY` would be nice but it
    only really helps against an attacker who can dump the DB
    without also having `wp-config.php`. In practice both sit on
    the same filesystem, so the marginal benefit is small. v1.4 may
    add it once the admin UI lands; for v1.3 we ship plaintext and
    document it loudly here so operators rotate secrets if their DB
    is ever exposed.
  - **No admin UI in v1.3**. Endpoints + MCP tools + cron are
    enough for the Claude-driven workflow we ship; humans without
    Claude can call the endpoints via wp-cli or `curl`. A future
    v1.4 will add an admin tab so plain wp-admin operators can
    manage webhooks without leaving the UI.
  - **One drain schedule shared with future modules**. The custom
    `iawm_5min` schedule is registered by `IAWM_Webhook` but other
    modules can re-use it; this keeps cron clean even as the
    operations module grows.
- **Implementation**: `IAWM_Webhook` (`includes/class-iawm-webhook.php`)
  with two per-site tables (`wp_iawm_webhooks`, `wp_iawm_webhook_outbox`),
  five `config/webhooks/*` REST routes, five mirrored MCP tools, a
  cron-registered drainer, and a `class_exists`-wrapped hook call
  from the diagnostics smoke handler for the first concrete event
  (`smoke.failed`). Audit-alert event firing is deferred to v1.4
  once an audit-tail watcher exists.

## D-031 — Audit-log pseudonymisation: opt-in, dot-path-declared, SHA-256 short-prefix sentinel

- Date: 2026-05-25 · Status: Accepted
- **Context**: until now, when a handler enriched its audit row with
  request params (planned for `config/users/create`, webhook secrets,
  rotated API secrets, etc.), the params were stored verbatim in the
  `detail` JSON column of `wp_iawm_audit_log`. Most setups have a
  single full-trust operator reading the audit log, but the v1.2.0
  scope grew to include read-only monitoring keys and hosting-side
  log shippers — both of which could now see passwords or signing
  secrets in cleartext. Spec 02 listed this as the last unresolved
  open question.
- **Decision**:
  - **Opt-in, default off** via the `iawm_audit_pseudonymise` option.
    Operators upgrading from v1.2.0 see exactly the previous
    behaviour until they flip the toggle in the cleanup tab of the
    plugin's settings page. No surprise on upgrade.
  - **Per-handler declaration** of sensitive paths via a
    `SENSITIVE_PARAMS` class constant mapping route suffix to a list
    of dot-notation paths (e.g. `config/users/create => ['password']`,
    `config/webhooks/create => ['signing_secret']`). The handler
    forwards the resolved list to `IAWM_Audit::write()` as its
    fourth argument.
  - **Dot-notation with `*` wildcards** for nested or list-shaped
    bodies: `users.*.password` masks the leaf on every entry of a
    `users` list; `fields.signing_secret` walks a single nested
    object. Unresolved paths are no-ops (the body comes back
    unchanged for that branch).
  - **Sentinel format** `<redacted:sha256:abc123def456>`. The first
    12 hex of SHA-256 over the (JSON-encoded if non-scalar) value.
    The short prefix is correlatable — two occurrences of the same
    value yield the same sentinel, useful for an operator who wants
    to ask "is this the same secret as on line 42?" without seeing
    either secret — and not reversible (12 hex characters offer no
    practical path back to a meaningful value space, and the
    operator never sees the rest of the digest).
  - **Backward-compatible reads**: legacy rows written before the
    toggle was flipped are stored verbatim and left untouched. The
    sentinel is human-readable so an operator scanning a mixed log
    doesn't get confused. The audit row gains a
    `detail.pseudonymised_paths` list when the toggle is on, so a
    log reader can tell what was masked at the time of writing
    (vs. what was simply absent).
- **Rationale**:
  - **Default off** preserves the v1.2.0 behaviour for existing
    operators; flipping it on is a conscious operator choice when
    they add a read-only watcher.
  - **Per-handler `SENSITIVE_PARAMS`** keeps the declaration colocated
    with the code that knows what the route accepts — no central
    "schema of sensitive fields" to drift out of sync.
  - **Dot-notation + wildcards** gracefully handle current bodies
    (`password` as a top-level field) and future ones
    (`users.*.password` if a bulk endpoint lands).
  - **Short-prefix SHA-256** is the smallest sentinel that still
    correlates: 48 bits of entropy is plenty to collide within an
    audit log's lifetime, and the value isn't recoverable from 12
    hex characters of an unsalted hash unless the attacker already
    knows the value (in which case they didn't need the log).
- **Trade-offs**:
  - **Pseudonymisation is irreversible from the log alone** — no
    "decrypt with operator passphrase" path. Intentional: an operator
    who needs to see a secret has access to where the secret
    originated (the original request, the credentials option). Adding
    a reversible path would defeat the purpose against the read-only
    watcher we're protecting against.
  - **Per-request stash via static** in `IAWM_Audit::write()`: the
    handler stashes params, the `rest_post_dispatch` filter picks
    them up and inserts the row. A future move to a queue-based audit
    pipeline would have to revisit this — flagged in the class
    docblock.
- **Implementation**:
  - `IAWM_Audit::pseudonymise()` static helper + `IAWM_Audit::write()`
    public entry + `IAWM_Audit::is_pseudonymise_on()` reader.
  - `IAWM_Config::SENSITIVE_PARAMS` wired into `handle_users_create`
    and `handle_users_update`.
  - `IAWM_Settings::SENSITIVE_PARAMS` declared for future
    `config/keys/*` routes (today key management is purely admin-UI
    server-side, so nothing currently calls `write()` with it).
  - Webhook-side wiring (D-030 / task 9.4) declares
    `config/webhooks/create` and `config/webhooks/update` in its own
    module and calls `IAWM_Audit::write()` with the resolved list.
  - Spec 02 open question marked resolved.

## D-010 — Public open source distribution

- Date: 2026-05-22 · Status: Accepted
- **Context**: we want to release the project as open source.
- **Decision**: public release on GitHub (`RiusmaX/ia-webmaster-bridge`)
  under **GPL-3.0-or-later**. The repo also serves as a Claude Code
  marketplace: the `ia-webmaster` plugin installs via `/plugin marketplace
  add` then `/plugin install`.
- **Consequences**: the HMAC secret and any sensitive data are excluded from
  the repo (`.gitignore`); a security review precedes each publication. The
  bridge is distributed as a standalone bundle; its configuration lives
  outside the repo (`~/.iawm/config.json`).
