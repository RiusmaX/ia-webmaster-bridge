---
name: scheduled-routines
description: Set up periodic routines that run via WP-Cron — weekly site status report, monthly key rotation reminder, daily backup pruning. Uses the iawm_cron_* endpoints to manage the schedule.
---

## Goal

Convert "I should check the site weekly" into a programmed routine
that the site itself triggers. The agent doesn't need to remember —
WP-Cron fires the hook and the agent (or the operator) reacts to its
output.

## When to use this skill

- At install time on a production site, to set up the default
  monitoring cadence.
- When the operator wants to add a custom recurring check.
- During a tooling review (every 6 months), to inspect scheduled
  events and prune obsolete ones.

## Prerequisites

Scope: `read` + `infra:write` (the writes go through `/cron/*`).

The hooks scheduled by this skill **must already have a PHP listener
on the site**. By default WordPress fires the action but nothing acts
on it. Three options for actually reacting:

1. **Polling pattern** (simplest): the routine is a calendar marker.
   The hook fires (or doesn't); the operator runs the related
   workflow manually when they next open Claude. Useful purely as a
   reminder, e.g. "rotate keys this quarter".
2. **Logger pattern**: write a tiny mu-plugin that listens to the
   hook, calls the relevant `iawm_*` endpoint over WP REST, stores
   the result in a transient or option. The agent reads the transient
   on next session.
3. **Webhook pattern** (future): listen to the hook, POST the result
   to an external collector. Not implemented today — flagged in
   `docs/roadmap.md` Phase 8.

This skill uses option 1 by default and documents 2 + 3 for reference.

## Step-by-step

### 1. Inventory current scheduled events

```
iawm_cron_list()
iawm_cron_schedules()
```

Tells you what's already scheduled and what recurrence slugs are
available. Standard slugs on a default WordPress: `hourly`,
`twicedaily`, `daily`, `weekly`. Custom slugs may be present.

### 2. Pick the routine + cadence

Common routines to set up:

| Routine | Cadence | Hook name | Reaction |
|---------|---------|-----------|----------|
| Site status report | weekly | `iawm_routine_weekly_status` | Operator reviews next time they open Claude |
| Backup pruning | daily | `iawm_prune_backups` (already auto-set) | Pure cleanup |
| Audit pruning | daily | `iawm_prune_audit_log` (already auto-set) | Pure cleanup |
| Key rotation reminder | quarterly | `iawm_routine_key_rotation_reminder` | Operator runs the `key rotation` ops procedure |
| Brand drift check | monthly | `iawm_routine_brand_drift` | Operator runs site-context-discovery + diff |

The two "prune" rows are scheduled automatically by the plugin (see
Phase 7.2). The others are operator-defined.

### 3. Schedule the chosen routine

```
iawm_cron_schedule({
  hook: "iawm_routine_weekly_status",
  schedule: "weekly",
  timestamp: <next Monday 09:00 site time>,
  args: []
})
```

If `schedule` is omitted, the event is a one-off and won't recur.

### 4. Verify

```
iawm_cron_list({ hook: "iawm_routine_weekly_status" })
```

Expected: one entry with the right `timestamp` and `schedule: "weekly"`.

### 5. Document for the operator

Tell the operator clearly:

- WHAT was scheduled (hook name, cadence, next fire timestamp).
- WHEN it next fires (in their local timezone).
- WHAT happens when it fires (under option 1: nothing — the calendar
  is just a marker; under option 2/3: link to the mu-plugin / webhook
  that listens).
- HOW to unschedule it (`iawm_cron_unschedule({ hook: ... })`).

### 6. Periodic maintenance

Once a quarter, run:

```
iawm_cron_list()
```

Look for:

- Routines whose timestamp is far in the past (WP-Cron is real-page
  triggered, so on low-traffic sites events DRIFT).
- Routines that are obsolete (the operator no longer cares).

Drop them via `iawm_cron_unschedule`.

## Implementation tip for option 2 (listener mu-plugin)

For sites where the operator wants the routine to ACT (not just
remind), drop a tiny mu-plugin at `wp-content/mu-plugins/iawm-
routines.php`:

```php
<?php
add_action( 'iawm_routine_weekly_status', function () {
    // Trigger an internal REST call that exercises smoke + status.
    $req = new WP_REST_Request( 'POST', '/' . IAWM_REST_NAMESPACE . '/diagnostics/smoke' );
    $res = rest_do_request( $req );
    set_transient( 'iawm_last_weekly_status', $res->get_data(), DAY_IN_SECONDS * 9 );
} );
```

Then on the next Claude session, read the transient:

```
iawm_database_query({ sql: "SELECT option_value FROM wp_options WHERE option_name = '_transient_iawm_last_weekly_status' LIMIT 1" })
```

This is intentionally a manual integration — automating the agent's
side of routines is Phase 8 territory (webhook signing).

## When NOT to use this skill

- When the site has no `infra:write` scope on the signing key — the
  cron writes are gated.
- When the operator doesn't actually need a routine — schedules they
  ignore become noise.
- As a workflow to PERFORM the routine — schedule it, then USE the
  related skill (`site-status-report`, key rotation procedure, etc.)
  when the cadence fires.
