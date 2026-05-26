# Skills catalog

> Status: Living · Last updated: 2026-05-25 (v1.4.0)

The IA Webmaster Bridge ships **17 Claude Code skills** — reusable
workflows that compose the underlying MCP tools into recognisable
operator procedures. Each skill is a markdown file with YAML
frontmatter under `claude-plugin/skills/<slug>/SKILL.md`; Claude
Code surfaces them automatically once the plugin is installed.

A skill is the "verb-shaped" way to use the toolset. Where
[`capabilities.md`](capabilities.md) lists what the API can do, this
doc lists how to ask Claude to do it. You don't trigger skills
explicitly — Claude picks the right one from your prompt, but
knowing what's available helps you ask precisely.

## At a glance

| Skill | One-liner | Scopes needed |
|---|---|---|
| [`webmaster-wordpress`](#webmaster-wordpress) | Cross-cutting method + guardrails for any WordPress task | read + ad-hoc |
| [`create-wordpress-page`](#create-wordpress-page) | Build a classic Gutenberg page from a brief | read + content:write |
| [`create-divi-page`](#create-divi-page) | Build a full Divi 5 page from a prompt | read + content:write + divi:write |
| [`audit-wordpress-site`](#audit-wordpress-site) | Inspect a site's health and produce a report | read |
| [`frontend-design-wordpress`](#frontend-design-wordpress) | Design principles applied to WP pages (hierarchy, typography, mobile-first) | read + divi:write |
| [`marketing-conversion-wordpress`](#marketing-conversion-wordpress) | Apply AIDA / PAS / FAB frameworks; CTA structure; social proof | read + content:write |
| [`seo-wordpress`](#seo-wordpress) | SEO audit + on-page tuning via Rank Math | read + content:write |
| [`safe-plugin-update`](#safe-plugin-update) | Update plugins safely (backup → update → smoke test → rollback) | read + infra:write |
| [`design-system-first`](#design-system-first) | Configure brand palette/fonts/tokens, then author pages | read + divi:write |
| [`site-smoke-test`](#site-smoke-test) | Health check post-op (HTTP + fatal scan + state) | read |
| [`prod-deployment-checklist`](#prod-deployment-checklist) | Fresh production install walkthrough | read + admin |
| [`site-context-discovery`](#site-context-discovery) | Bootstrap the per-site context from observable signals | read + config:write |
| [`site-status-report`](#site-status-report) | Weekly compiled health + content + audit + updates report | read |
| [`scheduled-routines`](#scheduled-routines) | Program periodic site checks via WP-Cron | read + infra:write |
| [`broken-links-audit`](#broken-links-audit) | Find broken outgoing links + triage incoming 404s, with concrete fix actions | read + infra:write + content:write |
| [`webhook-setup`](#webhook-setup) | Register and verify an HMAC-signed outbound webhook destination | read + config:write |
| [`content-rollback`](#content-rollback) | Restore an earlier revision of a post / page, with confirmation token + symmetric undo | read + content:write |

---

## Method skills — foundation

These skills don't perform a single workflow; they define the *way*
Claude behaves on this codebase.

### webmaster-wordpress

The cross-cutting method. Loaded implicitly for any WordPress task,
this skill imprints:

- Read before write.
- Draft by default.
- Pre-op backups for destructive ops.
- Confirmation tokens for restore / core / search-replace.
- Use the per-site context as the brand brief.
- Smoke test after destructive ops.

**When to use it**: implicitly — every other skill builds on it.

**Anti-patterns**: not a substitute for the specific skills below
when their workflow applies.

---

## Authoring skills — content + design

### create-wordpress-page

Build a classic Gutenberg page from a brief. Five steps: clarify the
brief → propose a structure → write as draft → review with the user
→ ask before publishing.

**When to use it**: for classic WP content. Use `create-divi-page`
instead when the site uses Divi 5 and you want layout control.

**Prompt example**:
> *"Create a page 'Mentions légales' that meets the French legal
> requirements. Get our company info from the site context."*

### create-divi-page

Build a complete Divi 5 page from a prompt. Eight steps: structured
brief → page plan → draft creation → block composition → Theme
Builder setup (optional) → SEO → validation read → ask before
publishing.

**When to use it**: when the site is Divi 5 (most cases on this
project) and you want pixel-level layout control.

**Prompt example**:
> *"Build the homepage for my BJJ club. Hero, services, team grid,
> testimonials, pricing, FAQ, contact. Use the design system colors
> and Inter font."*

### frontend-design-wordpress

Applies visual-hierarchy / typography / colour / spacing /
mobile-first / accessibility principles to the page being composed.
Loaded as a method overlay during page authoring.

**When to use it**: implicitly, as part of any page-creation
workflow. Or explicitly: *"Audit the visual design of my homepage
and propose 5 concrete improvements."*

### marketing-conversion-wordpress

Adds copywriting + conversion frameworks (AIDA, PAS, FAB), CTA
hierarchy, social-proof patterns. Used by the page-creation skills
when the brief calls for marketing pages.

**Prompt example**:
> *"My homepage hero says 'Welcome to MokaTeam'. Rewrite it to
> follow PAS — pain, agitate, solution — for parents looking for
> a kids' martial arts class."*

### seo-wordpress

SEO audit grid + on-page tuning via the Rank Math backend
(`iawm_seo_*` tools). Identifies missing meta_titles, missing
descriptions, focus_keyword consistency, canonical issues, OG
completeness.

**Prompt example**:
> *"Audit the SEO of every published page. Flag pages with: meta
> title >70 chars, no meta_description, no focus_keyword. Output
> the list as a table."*

---

## Infrastructure + ops skills

### safe-plugin-update

Update plugins safely. Standard procedure: identify outdated →
manual extra backup → per-plugin update (each takes its own
pre_op_backup_id) → smoke test → if broken, restore from the
appropriate backup id.

**Prompt example**:
> *"Update every plugin that has an available update. Use the
> safe-plugin-update procedure."*

### design-system-first

Configure the design system *before* authoring pages, so every page
inherits the brand by construction. Sequence: read → propose →
confirm → write tokens → author pages referencing them.

**Prompt example**:
> *"Set up our brand: primary blue #0a6ef5, Inter, brand-radius
> 12px, logo at /uploads/logo.svg. Then build the homepage referencing
> these tokens."*

### site-smoke-test

Run after any destructive operation to confirm the site is still
healthy. Steps: `iawm_diagnostics_smoke` → interpret per-probe
results → tail `iawm_diagnostics_logs` if fatal_errors > 0 →
escalate or roll back.

**Prompt example**:
> *"I just updated 5 plugins. Run a smoke test and tell me if the
> site is healthy."*

### prod-deployment-checklist

Walk the operator through a fresh production install:
pre-checks → install → key creation → IP allow-list → first probe →
go/no-go.

**Prompt example**:
> *"I'm about to install this plugin on my production WordPress.
> Walk me through the production deployment checklist."*

---

## Knowledge + governance skills

### audit-wordpress-site

Inspect a connected WP site's state and produce a structured report:
versions, active theme, plugins, recent posts, configuration,
visible issues. Read-only.

**Prompt example**:
> *"Audit this site. I want to know what's installed, what's
> active, what's broken."*

### site-context-discovery

Bootstrap the per-site context on a fresh install. Reads observable
signals (design system, plugins, homepage layout), drafts a context,
asks the operator to confirm the values that require judgement
(voice, audience, do-don'ts).

**Prompt example**:
> *"This is a fresh install — bootstrap the site context from what
> you can see, then ask me what you can't infer."*

### site-status-report

Compile a complete site health report once a week: smoke test,
self-check, pending updates per component, audit log summary,
content stats, SEO completeness sample, backups, cron schedule.

**Prompt example**:
> *"Produce the weekly site status report."*

### scheduled-routines

Use the cron module to set up periodic site checks. Standard
routines: weekly status report, monthly key-rotation reminder,
quarterly brand-drift check.

**Prompt example**:
> *"Set up the weekly site status report every Monday at 9 AM and
> a quarterly reminder to rotate my API keys."*

### webhook-setup

Register an outbound webhook destination end-to-end: pick channel
(Slack / generic JSON / PagerDuty), generate a strong signing secret,
register via `iawm_webhooks_create` with the desired event list
(`smoke.failed`, `audit.alert`, `key_rotation.reminder`, `test.ping`,
or the `"*"` wildcard), send a `test.ping`, and verify the receiver
validates the HMAC signature correctly using the recipe in
`docs/operations.md`. Handles the "show the plaintext secret once,
then it's gone" UX.

**Prompt example**:
> *"Wire a Slack alert for smoke-test failures — channel is
> #ops-alerts in our workspace, here's the incoming webhook URL."*

### content-rollback

Restore an earlier revision of a post or page. Lists revisions
(`iawm_content_revisions_list`), inspects candidates
(`iawm_content_revisions_get`, with build-mode detection so Divi vs
Gutenberg pages take the right write path), dry-runs the restore to
surface the diff + the confirmation token, applies with the token,
and **captures the auto-created revision id** as the symmetric undo
handle (the Phase 9.5 mechanism: `wp_restore_post_revision` itself
creates a fresh revision capturing pre-restore state). Smoke-tests
after the restore.

**Prompt example**:
> *"The homepage edit from yesterday broke something — restore the
> version from before."*

### broken-links-audit

Find broken **outgoing** links via the link checker (`iawm_links_scan`,
walks every published post type with HEAD→GET probes) **and** triage
**incoming** 404s via the 404 tracker (`iawm_404_stats`,
popularity-weighted by `hit_count`). Produces three buckets: fix in
content (internal targets the operator can rewrite), redirect candidates
(external referers worth catching with a redirect), and ignore/parked
(scanner noise + known-broken-by-design). Applies approved content
rewrites via `iawm_content_update` / `iawm_divi_page_write`, marks
issues as resolved, smoke-tests, optionally clears the 404 log.

**Prompt example**:
> *"Audit broken links on the site and propose fixes — group by what I
> can fix in content vs what needs a redirect."*

---

## How a skill is invoked

You **don't call skills by name**. You describe the outcome you
want. Claude reads the prompt, picks the skill (or skills) whose
goal matches, and executes the workflow.

If you want to force a specific procedure, mention it explicitly:

> *"Use the safe-plugin-update skill to update Akismet."*

If you want to KNOW which skill applies before triggering it, ask:

> *"Which skill would you use to set up a fresh brand kit on this
> site?"* → answer: `design-system-first`.

## Adding your own skill

Drop a new directory under `claude-plugin/skills/<slug>/` with a
`SKILL.md` file. Required frontmatter:

```yaml
---
name: <kebab-case-slug>          # must match the dir name
description: <≤ 2 sentences>     # used by Claude to match prompts
---
```

Body conventions (see existing skills for examples):

1. `## Goal` — one paragraph describing the outcome.
2. `## When to use this skill` — triggers and signals.
3. `## Prerequisites` — required scopes / state.
4. `## Step-by-step` — numbered actions with concrete tool calls.
5. `## When NOT to use this skill` — explicit anti-patterns.

Submit via the contribution flow in [`CONTRIBUTING.md`](../CONTRIBUTING.md).
