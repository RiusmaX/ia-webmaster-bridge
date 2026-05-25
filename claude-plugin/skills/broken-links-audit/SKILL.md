---
name: broken-links-audit
description: Find and triage broken links on the site — both outgoing dead URLs in published content (via the link checker scan) and 404s the outside world is hitting (via the 404 tracker). Produces a prioritised fix list with concrete actions per category (fix in content, redirect, delete row).
---

# Broken-links + 404 audit

## Goal

Give the operator a clear "what's broken and what to fix first" picture
covering two complementary failure modes:

- **Outgoing broken links** — anchors and images on the site's published
  pages that point to dead targets. Detected by the **link checker**
  (`iawm_links_scan`), which crawls published posts/pages/CPTs.
- **Incoming 404 traffic** — URLs the **outside world** keeps requesting
  that don't exist on the site. Observed by the **404 tracker**
  (`iawm_404_*`), which hooks `template_redirect` and records each
  unmatched request.

The two views together let you fix what visitors actually hit (404
tracker — popularity-weighted) and what visitors *would* hit if they
clicked the wrong link (link checker — preventive).

## When to use it

- The operator asks to "find broken links", "audit 404s", "clean up
  dead links", "see what's missing on the site".
- After a content migration, domain change, or `search-replace` op —
  to catch the URLs that didn't get rewritten.
- As part of a quarterly site hygiene routine (also see
  `scheduled-routines`).

## Prerequisites

- API key with at least `read` + `infra:write` (the scan call writes to
  the `wp_iawm_link_issues` table).
- `iawm_status` returns OK and the kill switch is OFF.
- The 404 tracker has been running long enough to gather signal
  (≥ a few days). If you just installed the plugin, the link-checker
  half of this audit is still useful on day one.

## Step-by-step

### 1. Read the site context

```
iawm_site_context_get()
```

Look for `infrastructure.environment_note` and any `notes` mentioning
known broken external links the operator has explicitly decided to keep
(e.g. "the press archive at example.com is 404 but we leave the link
for historical accuracy"). Keep that list at hand to **not** flag those.

### 2. Run a fresh link-checker scan

```
iawm_links_scan({ post_types: ["post", "page"] })
```

Pass any custom post type you care about in `post_types` (default is
`["post", "page"]`). The scan walks every **published** post in those
types, extracts every `<a href>` and `<img src>`, then probes each
unique target with HEAD → GET fallback. Throttle is 100 ms; a typical
200-post site finishes in 1–3 min.

Capture the response — the `summary` field tells you `urls_probed`,
`new_issues`, `still_broken`, `resolved`. **`still_broken`** are URLs
that were already in the table from a previous scan and are still
broken; **`new_issues`** are first-time detections.

### 3. List the open issues, grouped

```
iawm_links_list({ resolved: false, limit: 500 })
```

Group the rows by `outcome`:

| Outcome | Meaning | Default action |
|---|---|---|
| `404` | HTTP 404, target genuinely missing | Fix in content, or remove the link, or redirect |
| `410` | HTTP 410 Gone, server says permanently removed | Remove the link |
| `timeout` | Server didn't answer in time | Re-scan once before flagging — might be a flake |
| `dns` | Hostname doesn't resolve | Domain is dead; remove the link |
| `ssl` | TLS handshake failed | Server has cert issues; flag for the operator, don't auto-remove |
| `other` | Other HTTP error (4xx/5xx not 404/410, or unclassified `WP_Error`) | Investigate case by case |

Inside each group, separate `is_internal: true` (URLs pointing to **this
site**) from `is_internal: false` (outbound). Internal 404s are
**always** worth fixing (the operator can rewrite the link). External
404s may or may not be — depends on the operator's stance.

### 4. Pull the 404 tracker view

```
iawm_404_stats({ window: "30d" })
```

This returns top URLs by `hit_count`, top referers, and total unique
URLs / total hits over the window. The interesting buckets:

- **High-hit 404s with internal referers** → the site is linking to
  itself wrongly somewhere. Cross-reference with the link checker
  output above; the URL should appear in both.
- **High-hit 404s with external referers** → other sites or search
  engines have stale links to this site. Best fix is a **redirect**
  (via the operator's redirect plugin, or `.htaccess`, or a server
  config). Surface these as "redirect candidates" — Claude does not
  install redirects automatically.
- **High-hit 404s with no referer + suspicious paths** (`wp-login.php`
  variants, `.env`, `.git/config`, …) → scanner noise. Surface in a
  separate "ignore" bucket so the operator confirms.

### 5. Compose the triage report

For the operator, produce a single message with **three sections**:

**A. Fix in content (link checker, internal targets)**
List each issue as: `[<post title> — <permalink>] → <broken target>
(<outcome>)`. For each, suggest the concrete fix (rewrite to the new
URL if obvious, remove the link, or replace with a working anchor).
Do **not** modify content yet — wait for the operator's go.

**B. Redirect candidates (404 tracker, high-hit external referers)**
List each as: `<requested_url> — <hit_count> hits — referer:
<top_referer>`. Suggest the destination URL. Flag the redirect plugin
the site uses (Rank Math has one built-in, Yoast Premium too, or a
dedicated redirect plugin). Claude does not install or configure
redirects — that's the operator's call.

**C. Ignore / parked**
Anything in the operator's known-broken list from step 1, plus the
scanner-noise bucket from step 4. Keep these visible so nothing
silently slips out of view.

### 6. Apply the fixes the operator approved

For each approved "fix in content" item from section A:

```
iawm_content_get({ id: <post_id> })
```

Identify the link in `post_content`, then:

```
iawm_content_update({
  id: <post_id>,
  content: "<post content with the link rewritten or removed>"
})
```

Then mark the link-checker row as resolved so it doesn't re-appear:

```
iawm_links_resolve({ id: <issue_id> })
```

For Divi pages, the same pattern uses `iawm_divi_page_read` then
`iawm_divi_page_write` instead — the link checker tells you the post id,
the build mode is in the read response.

### 7. Smoke-test

After a batch of content rewrites:

```
iawm_diagnostics_smoke()
```

Confirm `healthy: true`. The smoke test catches any regression a content
edit might have introduced (rare with link fixes, but it costs nothing).

### 8. Optional — clean the 404 log

If the operator wants to start fresh after applying the fixes (e.g. they
just deployed a batch of redirects and want to track only **new** 404s
from now on):

```
iawm_404_clear()
```

This requires a confirmation token (the route is in
`REQUIRES_CONFIRMATION`). First call returns a `requires_confirmation`
flag plus a token; re-issue with the token to actually clear.

## Pitfalls

- **The scan can miss links inside Divi shortcodes** if Divi hasn't
  rendered them by the time the extractor runs. The scanner does its
  best with DOMDocument + a regex fallback, but a few module-specific
  link patterns (e.g. button modules with the URL in a JSON attribute)
  may slip through. Cross-check against the 404 tracker for high-hit
  URLs that don't show up in `iawm_links_list`.
- **`is_internal` is decided by hostname match**, so a link to
  `www.example.com` is "external" even on `example.com` unless the
  site is configured to canonicalise. Flag mismatches when they
  appear.
- **Don't auto-redirect from this skill.** Installing or configuring a
  redirect plugin is an operator decision; this skill produces the
  *candidate* list.
- **The 404 tracker's 60 s dedup transient** means very fast retries
  on the same `(URL, IP)` pair only count once. Real hit volume on a
  popular missing URL is therefore a **lower bound**, not an exact
  count.
- **External 404s on link-rot links** (e.g. an article from 2018
  linking to a blog post that's gone) may not be worth fixing — keep
  a "parked" bucket and don't churn through fixes the operator won't
  approve.

## When NOT to use this skill

- The operator only has the `read` scope. Stop and ask for an
  `infra:write` key rather than attempting the scan (it will 403).
- The bridge plugin is below v1.2.0 — the link checker and 404 tracker
  endpoints don't exist yet. Update the bridge first.
- The site is so fresh the 404 tracker has < 24 h of data **and** the
  site has no content yet — there is nothing to audit. Wait for
  content to land first.
