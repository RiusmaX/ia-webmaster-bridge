---
name: content-rollback
description: Restore an earlier revision of a post or page after a content edit went wrong. Walks through listing revisions, inspecting candidates, dry-running the restore, applying with the confirmation token, smoke-testing, and the symmetric rollback path if the restore itself was a mistake.
---

# Content rollback — restore a post revision

## Goal

Recover the previous state of a post or page when a recent edit broke
the layout, published the wrong copy, or otherwise made the operator
unhappy. Uses the Phase 9.5 revisions API
(`iawm_content_revisions_{list,get,restore}`) end-to-end, with the
confirmation-token gate and the elegant symmetric-rollback mechanism:
the restore itself creates a fresh revision that captures the
pre-restore state, so undoing the rollback is one more call against
that auto-created revision id.

## When to use it

- The operator says "I just saved a bad edit, can you revert?", "the
  homepage looks broken since yesterday", "restore the version from
  before the migration".
- After a `search-replace` op that touched more content than expected.
- After a content batch update that the operator wants to walk back.

## Prerequisites

- API key with at least the `content:write` scope (restore is a
  write).
- `iawm_status` returns OK and the kill switch is OFF.
- The post in question exists and has WordPress revisions enabled
  (default on; a few hardened setups disable them in `wp-config.php`
  — if so, this skill won't help and you should reach for the
  `iawm_backup_restore` flow instead).

## Step-by-step

### 1. Identify the target post

If the operator hasn't given a post id, find it:

```
iawm_content_list({ search: "<title or slug>" })
```

Confirm with the operator before proceeding. Note the post id.

### 2. List revisions

```
iawm_content_revisions_list({ post_id: <id>, limit: 20 })
```

The response is compact: revision id, author, date_gmt, title,
excerpt, byte_size. Walk it with the operator. Most of the time the
target is "the revision right before the bad one" — the second item
in the list when sorted DESC by date.

### 3. Inspect a candidate

Before restoring, fetch the full revision content to confirm it's
what the operator wants:

```
iawm_content_revisions_get({ revision_id: <id> })
```

The response includes:
- `title`, `content`, `excerpt` — the revision's payload.
- `build_mode` — `"divi"` / `"gutenberg"` / `"classic"`, computed on
  the **current** parent (not the revision). Tells you which write
  path the restore will use.
- `status` — the parent's current status (revisions don't carry
  publish state independently).

Show the operator a summary (title diff vs current; byte_size delta;
excerpt). If the operator approves, move on.

### 4. Dry-run the restore

```
iawm_content_revisions_restore({
  revision_id: <id>,
  dry_run: true
})
```

This returns:
- `requires_confirmation: true`
- `confirmation_token: "<5-min single-use token>"`
- `changes: { title_before, title_after, bytes_before, bytes_after }`
- `pre_op_backup_id: null` (no snapshot taken yet — see step 5 for
  why it's null at the dry-run stage)

Display the diff to the operator and ask for explicit confirmation.

### 5. Apply with the token

```
iawm_content_revisions_restore({
  revision_id: <id>,
  confirmation_token: "<token from step 4>"
})
```

The response surfaces:
- `restored_revision_id`
- `parent_post_id`
- `pre_op_backup_id: "revision:<id>"` — the **fresh revision** the
  restore created automatically, capturing the state *before* the
  rollback. This is the symmetric-undo handle (see step 7).
- `build_mode`
- `applied_at`

**Capture the `pre_op_backup_id`**. Show it to the operator and say
"if this rollback was a mistake, run me again with this id to undo
the undo".

### 6. Smoke-test

```
iawm_diagnostics_smoke()
```

Confirm `healthy: true`. A revision restore should never break the
site — they pass through WP's native `wp_restore_post_revision`,
which is well-tested — but the smoke test is cheap and catches the
rare case where a Divi page's cached CSS is stale after the swap.

If the site uses Divi and the change is visually wrong despite
`healthy: true`, see "Divi cache" in Pitfalls below.

### 7. Symmetric undo path (rare)

If the restore was itself a mistake, run the skill again targeting
the `pre_op_backup_id` (which is `revision:<id>` — strip the prefix
to get the raw revision id):

```
iawm_content_revisions_restore({
  revision_id: <the id captured in step 5>,
  dry_run: true
})
```

…and proceed through steps 4-6. The rollback chain is fully
recoverable — every restore creates its own snapshot revision.

### 8. Update the operator + close

Summarise:
- Post id, revision id restored, date of the restored revision.
- Pre-op `pre_op_backup_id` (so the operator can re-undo if needed).
- Smoke-test result.
- Suggest the operator inspect the live page in a browser (the API
  cannot replace human visual review).

## Pitfalls

- **Confirmation tokens are body-bound.** The token returned by
  step 4's dry-run is bound to `(route, key_id, body_hash)`. If you
  change the `revision_id` between the dry-run and the apply, the
  token is rejected. Don't "let me try a different revision" — go
  back to step 4 with the new id.
- **Divi cache.** A restored Divi page may need a Divi cache rebuild
  to render correctly outside the builder iframe. This is the spec
  04 P1 open question. If the operator reports visual oddities
  despite `healthy: true`, suggest visiting the page in the Divi
  visual builder (which forces a regen) as a workaround until the
  cache hook lands.
- **Build-mode mismatch.** Revisions store `post_content` as-is.
  Restoring a Divi-built revision onto a current Gutenberg page (or
  vice versa) is **legal** but probably wrong — Gutenberg won't
  render Divi shortcodes correctly. The `build_mode` field on the
  `get` response is the safeguard; if it changes between the
  current parent and the revision the operator wants, stop and ask.
- **Autosaves are not in this list.** `iawm_content_revisions_list`
  returns user-saved revisions only, not the per-keystroke
  autosaves Gutenberg writes. If the operator wants the autosave,
  they have to use the WP admin UI.
- **Multisite.** Revisions live per-sub-site under `wp_posts`. Make
  sure you're operating on the right sub-site (`iawm_status_network`
  to confirm `current_blog_id`).

## When NOT to use this skill

- The operator only has the `read` scope. Stop and ask for a
  `content:write` key.
- The post has no revisions (some hosts disable revisions in
  `wp-config.php` with `define('WP_POST_REVISIONS', false)`). The
  `iawm_content_revisions_list` call returns an empty list. Reach
  for `iawm_backup_restore` against an `options`-kind snapshot, or
  rebuild manually.
- The change the operator wants undone is **not** a content edit —
  e.g. a theme switch, a plugin activation, a setting change. Use
  the relevant `iawm_backup_restore` path instead (each of those
  ops takes its own snapshot).
- The site is part of an active editing session by another user and
  you'd overwrite in-flight work. Confirm with the operator first.
