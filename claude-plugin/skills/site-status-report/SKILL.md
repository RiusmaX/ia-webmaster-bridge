---
name: site-status-report
description: Produce a full site health report — combines smoke test, self-check, audit summary, pending updates, SEO completeness and content stats into a single operator-ready markdown block.
---

## Goal

A weekly (or on-demand) check-up of the site. Tells the operator at a
glance whether the site is healthy, what's pending, and what needs a
human decision — all in one pass.

## When to use this skill

- Weekly cadence (can be triggered by a scheduled routine — see
  `scheduled-routines` skill).
- After any major operation that landed without a full smoke test at
  the time.
- As the pre-check before promoting the site to a new environment.
- When the operator asks "How's the site doing?".

## Prerequisites

Scope: `read` (the report is entirely read-side). With `read` only,
the agent reports the state — it does not fix anything. Operator
decides what to act on after reading.

## Step-by-step

### 1. Establish the baseline (always)

```
iawm_status()
iawm_diagnostics_check_self()
iawm_diagnostics_smoke()
```

- `status` confirms key + agent + kill switch.
- `check_self` confirms invariants (agent user, tables, cron jobs).
- `smoke` confirms the live site is up + no recent fatal errors.

If any of these reports `verdict: error` or `healthy: false`, **stop
and flag immediately** — the report header should make this the headline.

### 2. Pull operational signals (parallel)

```
iawm_diagnostics_system()      # versions
iawm_diagnostics_plugins()     # versions + update flags
iawm_diagnostics_themes()      # active + update flags
iawm_core_info()               # WP version + update flag
iawm_audit({ limit: 50 })      # recent activity
iawm_backup_list({ limit: 20 })
iawm_cron_list()
```

### 3. Pull content signals (parallel)

```
iawm_content_list({ type: "page", per_page: 50, status: "publish,draft" })
iawm_content_list({ type: "post", per_page: 50, status: "publish,draft" })
```

For each page, optionally fetch SEO via `iawm_seo_page_get` if you want
SEO completeness in the report. Cap the per-page calls to ~10 to keep
the report fast — sample the most-visited or most-recent.

### 4. Compose the report

Structure (markdown):

```markdown
# Site status — <site name> — <ISO date>

## TL;DR

- Health: ✅ healthy / ⚠️ warn / ❌ unhealthy
- Pending updates: N plugins, N themes, N core
- Pending decisions: <bullet list, e.g. "Hello Dolly is inactive — keep or remove?">

## 1. Health

| Probe | Result |
|-------|--------|
| Front page HTTP | <status code, time> |
| Fatal errors (10 min) | <count> |
| Agent user | <healthy / missing> |
| Kill switch | <on / off> |
| Divi active | <version> |

## 2. Updates available

| Component | Current | New | Risk |
|-----------|---------|-----|------|
| WP core | 7.0 | <version or "-"> | <low / medium / high> |
| Theme: <name> | <v> | <v> | ... |
| Plugin: <name> | <v> | <v> | ... |

## 3. Recent activity (audit summary)

- Total calls last 50: <N>
- Denied: <N> (any `iawm_*` errors worth investigating?)
- Notable writes: <list of recent destructive ops with timestamps>

## 4. Content

- Pages: <total> (publish: N, draft: N)
- Posts: <total>
- Last publish: <date>
- Stale drafts (> 30 days untouched): <count>

## 5. SEO (sample)

- Pages with no meta_description: <list>
- Pages with no focus_keyword: <list>
- Pages with no canonical: <list>

## 6. Backups

- Total: <N>, oldest <date>, latest <date>
- Restored since last report: <N>

## 7. Cron

- Scheduled events: <N>
- Next iawm_prune_audit_log: <timestamp>
- Next iawm_prune_backups: <timestamp>

## Recommendations

- <Specific, actionable bullets in priority order>
```

### 5. Hand it to the operator

Render the markdown block in chat. Do NOT save it to a file or
publish it as a post — this is for human consumption.

## When NOT to use this skill

- As a substitute for the safe-plugin-update skill — the report
  surfaces pending updates but doesn't apply them.
- When the operator wants to dig into a specific area (e.g. just SEO
  audit) — use the dedicated `seo-wordpress` or `audit-wordpress-site`
  skills for depth.
- Without `read` scope — the report can't be assembled.
