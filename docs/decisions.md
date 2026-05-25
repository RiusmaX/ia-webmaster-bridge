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
