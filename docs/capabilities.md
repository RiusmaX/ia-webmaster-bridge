# Capabilities — what you can ask Claude to do

> Status: Living · Last updated: 2026-05-25 (v1.1.0 + multisite support)

This is the **operator-facing tour** of the IA Webmaster Bridge
surface: every family of capabilities, the MCP tools that back them,
and example prompts that take Claude from a one-line ask to an
executed change on the site.

The prompts are illustrative — Claude figures out the tool
sequencing. You don't need to call the tools yourself; you describe
the outcome and Claude composes the right MCP calls under the
[skills](skills.md) it has at hand.

## Quick reference

| You can ask Claude to… | …and it uses |
|---|---|
| Generate or edit a complete WordPress page or post | `iawm_content_*`, `iawm_media_*`, `iawm_taxonomy_*` |
| Compose a full Divi 5 page (sections / rows / modules) from a brief | `iawm_divi_page_compose`, `iawm_divi_page_write`, the 105-module registry |
| Build a Divi Theme Builder header + footer + dynamic body | `iawm_divi_theme_builder_*` |
| Configure the site-wide brand (palette, fonts, design tokens, logo, favicon) | `iawm_divi_global_*`, `iawm_divi_branding_*`, `iawm_divi_theme_options_*` |
| Update site settings (title, tagline, permalinks…) | `iawm_config_settings_*` |
| Manage users (create / edit / role change) | `iawm_config_users_*` |
| Install / activate / update / deactivate plugins from WP.org | `iawm_plugins_*` |
| Install / activate / update themes | `iawm_themes_*` |
| Read and update SEO metadata (Rank Math) | `iawm_seo_*` |
| Take and restore backups (options / plugin state / SQL tables) | `iawm_backup_*` |
| Run a controlled SELECT or a serialization-safe search/replace | `iawm_database_*` |
| Update WordPress core itself | `iawm_core_*` |
| Inspect and program WP-Cron events | `iawm_cron_*` |
| Run an operational health check after a destructive op | `iawm_diagnostics_smoke`, `iawm_diagnostics_check_self` |
| Investigate 404s actually hit by visitors (broken inbound URLs) | `iawm_404_list`, `iawm_404_stats`, `iawm_404_delete`, `iawm_404_clear` |
| Proactively scan your own content for broken `<a href>` links | `iawm_links_scan`, `iawm_links_list`, `iawm_links_resolve`, `iawm_links_delete` |
| Read / write the per-site brand context | `iawm_site_context_*` |
| List or look up Divi 5 modules and their attributes | `iawm_divi_modules_catalog`, `iawm_divi_module_info` |
| Audit who did what on the site | `iawm_audit` |
| Discover whether the site is a multisite (and which sub-site is targeted) | `iawm_status_network` |

---

## Capability families

### 1. Content — pages, posts, media, menus, taxonomies

The classic editorial surface. Everything writes a **draft by
default**; publishing is always an explicit step.

#### Tools

`iawm_content_list`, `iawm_content_get`, `iawm_content_create`,
`iawm_content_update`, `iawm_media_list`, `iawm_media_get`,
`iawm_media_sideload`, `iawm_media_update`, `iawm_taxonomy_list`,
`iawm_taxonomy_create`, `iawm_taxonomy_assign`, `iawm_menu_*` (6
endpoints).

#### Example prompts

> *"Create a draft page titled 'About us' with a hero paragraph
> introducing my agency and a 3-column list of our specialities."*

> *"Sideload this image URL into the media library and set its alt
> text to 'team photo at our 2026 retreat'."*

> *"Add the 'About us' page to the primary navigation menu, second
> position after Home."*

> *"List every draft post older than 30 days so I can decide what to
> publish or delete."*

> *"Update post 42 — change the focus keyword to 'Brazilian
> Jiu-Jitsu Bordeaux' and refresh the meta description."*

#### Guardrails

- Generated Gutenberg block markup is normalised through
  `parse_blocks` + `serialize_blocks` before being saved.
- Draft is the default `status`. Publishing requires an explicit
  `status: "publish"`.
- The `wp_slash` bug is handled (Divi content survives writes
  bit-faithfully).
- Optional `language` parameter (BCP-47) on every content-generating
  tool tells Claude which language to produce — defaults to the
  WordPress site locale.

---

### 2. Divi 5 — full layout authoring

The flagship capability. Read existing layouts, compose new ones
from patterns or free-form blocks, write them back bit-faithful.

#### Tools

- **Read**: `iawm_divi_status`, `iawm_divi_page_read` (modes: tree /
  flat / raw), `iawm_divi_library_list`, `iawm_divi_library_local`,
  `iawm_divi_modules_catalog`, `iawm_divi_module_info`.
- **Write**: `iawm_divi_page_compose` (unified composer — 3 modes:
  pattern, free-form, raw block), `iawm_divi_page_write`,
  `iawm_divi_theme_builder_compose`, `iawm_divi_theme_builder_*` (8
  endpoints).
- **Discovery**: 105 modules registry (native + 25 WooCommerce
  modules), 13 parametric patterns (hero, features-3col, pricing,
  testimonials, FAQ, team, headerSimple, footerStandard, …).

#### Example prompts

> *"Build the homepage for my Brazilian Jiu-Jitsu club. Audience:
> beginners and parents looking for a friendly local club. Sections:
> hero with a class-finder CTA, features3col with our pillars (kids,
> adults, competition), team grid (4 coaches), testimonials,
> pricing3col, FAQ, contact. Use the existing brand colours."*

> *"Add a 'Numbers' counters section between the hero and features
> on page 53 — 4 counters: 250+ students, 12 black belts, 5 medals,
> 8 years. Animate them on scroll."*

> *"Read the current Theme Builder setup. Then create a clean
> header + footer template and assign it to the default site
> template."*

> *"Look up the `divi/woocommerce-product-gallery` module — what
> attribute groups does it accept?"*

#### Highlights

- **Bit-faithful round-trip** — reading a layout, re-writing it,
  reading again gives identical bytes (validated end-to-end).
- **Unified composer** — pick patterns, drop free-form sections, or
  hand-author raw `wp:divi/*` blocks. Mix and match in the same
  page.
- **105 modules** auto-extracted from the Divi installation
  (`tools/scan-divi-modules.mjs`) — including every WooCommerce
  module.
- **Theme Builder** — full surface: list, create, update, delete
  templates; assign by condition; the `setup-site-defaults` wrapper
  installs header / footer / default body in one call.

See [`docs/divi5-compose-dsl.md`](divi5-compose-dsl.md) for the
composer reference and [`docs/divi5-modules-catalog.md`](divi5-modules-catalog.md)
for the full module list with attribute paths.

#### WooCommerce

The 25 WooCommerce Divi 5 modules ship with full read/write support.
They're meant to live inside Divi **Theme Builder templates** — Shop
archive, Single product, Cart, Checkout — rather than inside
standalone pages.

- **Detection**: `iawm_woocommerce_status` — `is_active`, version,
  products count, currency, page ids (shop/cart/checkout/myaccount),
  and `has_template_for.{shop, single_product, cart, checkout}`.
- **Catalog**: `iawm_woocommerce_contexts` — canonical mapping of WC
  context → suggested modules + Divi `use_on` expression for
  template assignment.

> *"Build a single-product Theme Builder template using the standard
> WooCommerce module stack."*

> *"Audit the WooCommerce setup of this site: is it active, how many
> products, what's already templated."*

See [`docs/woocommerce-integration.md`](woocommerce-integration.md)
for the full walkthrough, the four context module lists, and when to
prefer Theme Builder templates over standalone pages for WC modules.

---

### 3. Design system — colors, fonts, variables, logo

Divi 5's site-wide style state. Configure ONCE; every generated page
references the tokens and stays on-brand.

#### Tools

`iawm_divi_global_data` (read), `iawm_divi_global_colors_update`,
`iawm_divi_global_fonts_update`, `iawm_divi_global_variables_update`,
`iawm_divi_theme_options_get`, `iawm_divi_theme_options_update`,
`iawm_divi_branding_get`, `iawm_divi_branding_update`.

#### Example prompts

> *"Set up the brand design system: primary `#0a6ef5`, secondary
> `#10b981`, dark heading `#0f172a`, slate body `#334155`, accent
> amber for CTAs. Fonts: Inter for both heading and body. Set a
> brand-radius variable to 12px and a card padding to 32px."*

> *"Replace the site logo with this URL and update the favicon to
> the matching square asset."*

> *"What's currently in the design system? I want to see the
> palette, the fonts and any global variables."*

> *"The brand is shifting to a darker primary. Change `gcid-primary-color`
> to `#0b3d91` and re-read everything that references it."*

#### Workflow

The recommended sequence is **design system first**, then pages —
see [`docs/design-system.md`](design-system.md) for the full guide.
Every Divi attribute that takes a color accepts EITHER a hex literal
OR a `gcid-*` reference — always prefer the reference so a future
brand change cascades through the whole site.

---

### 4. Configuration — site settings, users

The WordPress settings surface, gated to an allow-list of mutable
options.

#### Tools

`iawm_config_settings_get`, `iawm_config_settings_update`,
`iawm_config_users_list`, `iawm_config_users_create`,
`iawm_config_users_update`.

#### Example prompts

> *"Change the site title to 'MokaTeam 443 — Brazilian Jiu-Jitsu' and
> the tagline to 'Friendly, technical, since 2018'."*

> *"Set the homepage to display page 19 (we picked the new homepage
> in draft)."*

> *"Create an editor account for Bob (bob@example.com) and send me
> back the generated password."*

> *"List every user with the administrator role."*

#### Guardrails

- **Allow-list**: only 16 settings are writable (`blogname`,
  `blogdescription`, timezone, date/time format, permalink_structure
  (marked risky), reading defaults, comment status, default role,
  …). Anything outside is rejected.
- **Risky settings** (e.g. `permalink_structure`) trigger an
  automatic pre-op options snapshot — restorable via `iawm_backup_restore`.
- **The dedicated agent user is self-protected** — calls to
  `iawm_config_users_update` targeting the agent itself return 403.
- **No privilege escalation** — the agent can create / edit users
  within roles ≤ administrator, but cannot touch the agent's own
  account.

---

### 5. Infrastructure — plugins, themes, core, database, cron, backups

The Phase 4 surface. Every destructive op is preceded by an
automatic snapshot and many require a two-step confirmation token.

#### Tools

- **Plugins**: `iawm_plugins_info`, `iawm_plugins_install`,
  `iawm_plugins_activate`, `iawm_plugins_deactivate`,
  `iawm_plugins_update`.
- **Themes**: `iawm_themes_list`, `iawm_themes_info`,
  `iawm_themes_install`, `iawm_themes_activate`, `iawm_themes_update`.
- **Core**: `iawm_core_info`, `iawm_core_update`.
- **Database**: `iawm_database_info`, `iawm_database_export`,
  `iawm_database_query`, `iawm_database_search_replace`.
- **Cron**: `iawm_cron_list`, `iawm_cron_schedules`,
  `iawm_cron_run`, `iawm_cron_schedule`, `iawm_cron_unschedule`.
- **Backups**: `iawm_backup_list`, `iawm_backup_get`,
  `iawm_backup_create`, `iawm_backup_restore`, `iawm_backup_delete`,
  `iawm_backup_prune`.

#### Example prompts

> *"Install and activate Rank Math SEO from WordPress.org."*

> *"Update every plugin that has an available update. Use the
> `safe-plugin-update` skill — backup, update, smoke test."*

> *"Check if a WordPress core update is available and tell me what
> it requires (PHP version, MySQL version)."*

> *"Run a serialization-safe search-replace from `old-domain.com` to
> `new-domain.com` across `wp_options`, `wp_posts.post_content` and
> `wp_postmeta.meta_value`. Show me a dry-run preview first."*

> *"Schedule a weekly site status report — every Monday at 9 AM
> site time."*

> *"List my last 10 backups; restore the one taken before yesterday's
> theme switch."*

#### Guardrails

- Plugin / theme installs are restricted to **WordPress.org** —
  never an arbitrary URL.
- The plugin itself cannot be self-deactivated, self-updated or
  deleted through the API.
- Every destructive op takes a **pre-op backup**
  (`pre_op_backup_id`) restorable via `iawm_backup_restore`.
- The **most destructive endpoints** require a two-step confirmation:
  `iawm_backup_restore`, `iawm_core_update`,
  `iawm_database_search_replace`. First call returns a token + a
  preview; re-issue with the token to apply. See
  [`docs/security-model.md`](security-model.md) §"Confirmation tokens".
- `iawm_database_query` is **SELECT-only** — no `;`, no `INTO
  OUTFILE`, no `BENCHMARK`, forced row cap.
- `iawm_database_search_replace` only operates on a fixed allow-list
  of (table, column) pairs — `options.option_value`,
  `posts.post_content/excerpt/title`, `*meta.meta_value`,
  `comments.comment_content`.

See [`docs/operations.md`](operations.md) for the safe-update
workflow and the WP-CLI fallback channel.

---

### 6. SEO — Rank Math today, Yoast planned

A normalized SEO API. Same field names regardless of the backend
plugin.

#### Tools

`iawm_seo_status`, `iawm_seo_page_get`, `iawm_seo_page_update`.

#### Fields

`meta_title`, `meta_description`, `focus_keyword`, `canonical_url`,
`robots_noindex`, `robots_nofollow`, `og_title`, `og_description`,
`og_image_id`, `twitter_title`, `twitter_description`,
`twitter_image_id`.

#### Example prompts

> *"Set the SEO of page 19: focus keyword 'Brazilian Jiu-Jitsu
> Bordeaux', meta title under 60 chars, meta description with the
> primary CTA in it, open-graph image id 142."*

> *"Audit the SEO of every published page and tell me which ones
> have no meta_description, no focus_keyword or a meta_title longer
> than 70 chars."*

> *"Set `robots_noindex` on the staging pages so they don't get
> indexed."*

The `seo-wordpress` skill orchestrates SEO audits, focus-keyword
selection and on-page tuning.

---

### 7. Diagnostics — system, plugins, themes, logs, smoke, self-check, 404 tracker, broken-links scanner

Read-only operational visibility, plus two complementary link-health
tools:

- **Reactive 404 tracker** (`iawm_404_*`): every front-end 404 a
  visitor hits is recorded (with dedup so a retrying crawler folds
  into a single row) and exposed for the agent to investigate.
- **Proactive broken-links scanner** (`iawm_links_*`): walks every
  published post + page, extracts `<a href>` targets and probes each
  one with HEAD (falling back to GET). Findings land in
  `wp_iawm_link_issues` and get triaged by outcome bucket (`404`,
  `410`, `timeout`, `dns`, `ssl`, `other`). Synchronous and capped at
  500 URLs per scan — operators that need a recurring run schedule it
  themselves via the `scheduled-routines` skill.

#### Tools

`iawm_diagnostics_system`, `iawm_diagnostics_plugins`,
`iawm_diagnostics_themes`, `iawm_diagnostics_logs`,
`iawm_diagnostics_smoke`, `iawm_diagnostics_check_self`,
`iawm_404_list`, `iawm_404_stats`, `iawm_404_delete`, `iawm_404_clear`,
`iawm_links_scan`, `iawm_links_list`, `iawm_links_resolve`,
`iawm_links_delete`.

#### Example prompts

> *"Run a smoke test — is the site healthy?"*

> *"Read the last 50 lines of debug.log. Are there any fatal
> errors?"*

> *"What PHP version and MySQL version is this site running?
> Compare with the requirements of the latest WordPress release."*

> *"Run the self-check — confirm the agent user, the audit table,
> the backup table and the rotation cron jobs are all in place."*

> *"Show me the top 404s of the last 30 days — which dead URLs are
> being hit hardest? Suggest redirects for the top 5."*

> *"List 404s where the referer is google.com — these are search
> results pointing at removed pages."*

> *"Scan the site for broken outbound links — give me a triage list
> grouped by outcome (404, timeout, DNS, SSL)."*

> *"Scan only pages 12, 34 and 56 for broken links and tell me which
> ones changed since last week."*

---

### 8. Per-site context — brand brief that follows the site

The Phase 6 primitive. A single WP option stores brand voice,
audience, do/don't lists, editorial defaults, design notes,
infrastructure preferences and free-form notes.

#### Tools

`iawm_site_context_get`, `iawm_site_context_update`,
`iawm_site_context_clear`.

#### Example prompts

> *"Bootstrap the site context — discover what you can from the
> live site and propose a draft I can complete."* (`site-context-discovery` skill)

> *"Read the site context. Then create an 'About us' page that
> matches the brand voice and follows the do/don't lists."*

> *"Update the context: add 'Never use 'click here' as a link
> label' to the dont_list, and set the main CTA label to 'Demander
> un cours d'essai gratuit'."*

#### Why it matters

Multi-operator setups share the brief automatically (decision
D-024). Adding a new operator = handing them a key + scope; their
Claude inherits the curated brand brief on first read. No more
"each laptop has its own .md file that drifts".

---

### 9. Security, audit, governance

The cross-cutting layer.

#### Tools

`iawm_status`, `iawm_audit`.

#### Example prompts

> *"Show me the last 50 audit entries. Highlight any 403 / 500
> responses."*

> *"List every API call from the last 24 hours grouped by key_id.
> Anything suspicious?"*

> *"What is the current state of the kill switch?"*

#### Admin-side governance

Done through the WordPress admin (no MCP tools — these are operator
actions):

- Create / rotate / revoke API keys.
- Manage scopes per key.
- Set up IP allow-list.
- Toggle the kill switch.
- Configure retention (audit + backups).
- Reinstall the agent role + user.
- Edit the per-site context.

See [`docs/security-model.md`](security-model.md) for the layered
defence model and [`docs/operations.md`](operations.md) for the
operator runbook (rotation, emergency response, WP-CLI fallback,
pentest checklist).

---

## End-to-end: build a complete site from a prompt

The flagship demo. One brief, one Claude session, a finished site
ready for review.

### Prompt

> *"Build the homepage of the BJJ club MokaTeam 443. Audience:
> beginners + parents looking for a local, friendly club. Tone:
> warm and concrete. Primary CTA: 'Book a free trial class'.
> Sections: hero with the CTA, our pillars (3 columns: kids,
> adults, competition), numbers we're proud of, testimonials, team
> grid (4 coaches), pricing in 3 tiers, FAQ, contact. Include the
> header with the logo + main menu, the footer with multi-column
> info. Use Inter font, primary blue #0a6ef5. Set up the SEO."*

### What Claude does, step by step

1. **Read the site context** — `iawm_site_context_get`. If
   `populated: true`, use it. If false, ask if a discovery pass is
   warranted.
2. **Verify Divi is active** — `iawm_divi_status`.
3. **Read the current design system** — `iawm_divi_global_data`,
   `iawm_divi_branding_get`. Confirm whether to update or keep.
4. **Write the design system** — `iawm_divi_global_colors_update`
   (palette), `iawm_divi_global_fonts_update` (Inter / Inter),
   `iawm_divi_global_variables_update` (any tokens), set CTA label
   in `gvid-cta-label`.
5. **Set up Theme Builder** — `iawm_divi_theme_builder_compose`
   with `setup-site-defaults`: header with `divi/menu` + logo,
   footer with multi-column info, default body using post-content.
6. **Create the homepage** — `iawm_content_create({ type: "page",
   title: "MokaTeam 443 — Brazilian Jiu-Jitsu Bordeaux", status:
   "draft" })`.
7. **Compose the layout** — `iawm_divi_page_compose` with 8
   sections mixing patterns (`hero`, `features-3col`,
   `numbers-bar`, `testimonials`, `team-grid`, `pricing-3col`,
   `faq-accordion`, `contact-section`).
8. **Set the SEO** — `iawm_seo_page_update` with the agreed focus
   keyword, meta description with the CTA, OG image.
9. **Smoke test** — `iawm_diagnostics_smoke`. Confirm `healthy:
   true`.
10. **Hand off** — point the operator at the draft URL.
    Publication is **always** an explicit step the operator takes.

This sequence is what the `create-divi-page` skill orchestrates
automatically. See [`docs/skills.md`](skills.md) for the skill
catalogue.

---

## Limitations — what it intentionally does NOT do

- **No raw shell access.** Every infra op goes through a controlled
  endpoint. SSH/WP-CLI is a human-only fallback channel.
- **No arbitrary URL install.** Plugins and themes come from
  WordPress.org only.
- **No deletion of plugins or themes via the API.** Intentional —
  too easy to remove the wrong one. The human operator does that via
  WP admin.
- **No self-modification.** The IA Webmaster Bridge plugin cannot
  deactivate, update or delete itself through its own API.
- **No agent user modification.** The dedicated agent user is
  refused as a target of `iawm_config_users_update`.
- **No raw INSERT / UPDATE / DELETE.** `iawm_database_query` is
  SELECT-only. Mutations go through purpose-built endpoints.
- **No core downgrade.** `iawm_core_update` calls the upstream
  Core_Upgrader which only moves forward.
- **No publication by default.** Every content create starts in
  `draft`.
- **No raw HTML injection.** The agent role lacks `unfiltered_html`;
  content flows through Gutenberg / Divi normalisation.
- **No 2000+ premade Divi layouts catalogue.** Divi's premade layouts
  are served by elegantthemes.com to the Visual Builder, not via REST
  on the local site. See [`docs/design-system.md`](design-system.md)
  §"Pre-configured layouts" for the hybrid workflow.
- **No network-wide key on multisite.** Each sub-site holds its own
  HMAC keys, kill switch and audit log. The plugin is multisite-
  tolerant (both network-activation and per-site activation work, and
  new sub-sites are auto-provisioned), but there is no single key that
  spans the whole network. See [`docs/multisite.md`](multisite.md) for
  the full model.

---

## See also

- [`docs/skills.md`](skills.md) — the 15 skills in detail.
- [`docs/operations.md`](operations.md) — operator runbook.
- [`docs/production-deployment.md`](production-deployment.md) — production install checklist.
- [`docs/security-model.md`](security-model.md) — 8-layer defence in depth.
- [`docs/design-system.md`](design-system.md) — design-system-first workflow.
- [`docs/divi5-format.md`](divi5-format.md) — Divi 5 storage format reference.
- [`docs/divi5-modules-catalog.md`](divi5-modules-catalog.md) — the 105-module registry.
- [`docs/divi5-compose-dsl.md`](divi5-compose-dsl.md) — page composer DSL.
- [`docs/architecture.md`](architecture.md) — three-component architecture.
- [`docs/multisite.md`](multisite.md) — multisite support: what is per-site, what is global, install topologies.
