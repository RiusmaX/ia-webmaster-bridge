---
name: site-smoke-test
description: Verify the site is healthy after any destructive operation. Runs the standard probe sequence (smoke → logs → self-check → backup restore if needed) and produces a short health report.
---

# Site smoke test — after a destructive operation

## Goal

Confirm a connected WordPress site is still alive and functional after a
write that could plausibly break it (plugin/theme/core update, theme
activation, mass content write, configuration change). Returns a clear
"healthy yes/no + which probe failed + next step" report, with a
documented rollback hook if anything is red.

## When to run it

- Right after `iawm_plugins_update`, `iawm_themes_update`,
  `iawm_core_update`, `iawm_themes_activate`.
- After a large content batch (10+ pages or posts written in one session).
- After `iawm_divi_global_*_update`, `iawm_divi_theme_options_update` —
  globals propagate site-wide, so a smoke pass is cheap insurance.
- Any time you have a `pre_op_backup_id` in hand and want to know whether
  you need to use it.

## Step-by-step

### 1. Run the smoke probe

```
iawm_diagnostics_smoke()
```

Interpret each probe in the response:

| Probe | What it checks | Failure means |
|-------|----------------|---------------|
| `http_home` | GET `/` returns 2xx/3xx | Front-end is down |
| `http_admin` | GET `/wp-admin/` reachable | Back-office down |
| `php_fatal` | No fatal in the last N seconds | Code path broken |
| `wp_cron` | WP-Cron heartbeat fresh | Scheduled jobs stuck |
| `db_query` | Trivial `SELECT 1` works | DB connection lost |

If every probe is `ok` and `healthy: true` → **report green and stop**.

### 2. If `fatal_errors.count > 0` — read the log

```
iawm_diagnostics_logs({ lines: 100 })
```

Show the user the last fatals: file, line, message. The probe gives the
count, the log gives the context. Do **not** try to "fix forward" without
a clear root cause — restoring is usually faster.

### 3. If `http_home` is not 2xx/3xx — escalate

A non-2xx/3xx home means anonymous visitors are seeing an error. This is
the hardest signal: stop, surface the status code and any body snippet
the probe captured, and recommend an immediate rollback (step 5).

### 4. If anything is at `error` level — run self-check

```
iawm_diagnostics_check_self()
```

This validates the bridge plugin itself: file integrity, signing keys,
audit-log write path, expected hooks. If `check_self` returns red, the
adapter may be the broken party (not the site). Surface that distinction
to the user — it changes the fix.

### 5. Restore if needed

If the report is red and a fix-forward is not obvious, restore from the
most recent `pre_op_backup_id` of the operation under investigation:

```
iawm_backup_restore({ id: "<pre_op_backup_id>", dry_run: true })  # inspect
iawm_backup_restore({ id: "<pre_op_backup_id>" })                 # apply
```

Re-run `iawm_diagnostics_smoke()` to confirm green after restore.

## Report template

Output a short markdown block, regardless of outcome:

```
## Smoke test — <site name>

Time: 2026-05-25 14:32 UTC
Healthy: yes / no
Probes:
- http_home  : ok (200)
- http_admin : ok (302)
- php_fatal  : ok (0 fatals in last 60s)
- wp_cron    : ok (heartbeat 12s ago)
- db_query   : ok

Next step: none required.
```

Failure variant:

```
## Smoke test — <site name>

Time: 2026-05-25 14:35 UTC
Healthy: NO
Failing probe: php_fatal (3 fatals in last 60s)
Root cause: Call to undefined function in wp-content/plugins/foo/foo.php:42
Suggested next step:
  iawm_backup_restore({ id: "bk_2026_05_25_002", dry_run: true })
```

## When NOT to use this skill

- Read-only sessions where nothing destructive happened — running the
  probe is cheap, but you do not need a full report for "I listed five
  pages".
- During an outage that you already know is in progress — switch to
  incident-response mode instead of running probes in a loop.
- When `iawm_status` shows the kill switch is ON or the connection is
  broken — fix the connection first; smoke probes will all fail in a
  misleading way.
- As a substitute for staging. The smoke test catches obvious breaks; it
  does not catch subtle regressions (broken CSS on one breakpoint,
  silently-failing payment hook). For high-risk changes, rehearse on
  staging first.
