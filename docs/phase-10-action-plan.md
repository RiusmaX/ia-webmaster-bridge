# Phase 10 — Operator polish · action plan

> Status: In progress · Last updated: 2026-05-25
>
> Goal: close the three D-030 trade-offs explicitly deferred to v1.4
> (webhook secret encryption, admin tab UI, audit.alert event firing)
> and add three operator skills that orchestrate the v1.3.0 APIs
> (webhooks, revisions, pseudonymisation). Tag the result as **v1.4.0**.

## Why this phase

v1.3.0 shipped everything code-side from Phase 9 but left three known
gaps in the webhook module and three new MCP API families without a
workflow skill:

1. **D-030 trade-offs** flagged for v1.4 from the day they shipped:
   `signing_secret` stored plaintext, no admin tab UI, no
   `audit.alert` event firing.
2. **v1.3.0 API orphans**: the three new MCP families
   (`iawm_webhooks_*`, `iawm_content_revisions_*`,
   `iawm_audit_pseudonymise`) work but no Claude Code skill
   composes them into recognisable operator procedures.

Phase 10 closes both buckets in one coherent operator-polish sprint
before the next deployment milestone (small-prod validation, 9.7).

## Eight sub-phases

| Sub-phase | Theme | Effort | Status |
|---|---|---|---|
| 10.0 | Action plan + doc refresh (4 stale figures) | ~30 min | in_progress |
| 10.1 | Skill `webhook-setup` | ~2h | pending |
| 10.2 | Skill `content-rollback` | ~2h | pending |
| 10.3 | Pseudonymisation step in `prod-deployment-checklist` | ~30 min | pending |
| 10.4 | Encrypt webhook `signing_secret` at rest (D-030 trade-off) | ~3h | pending |
| 10.5 | Webhooks admin tab in wp-admin (D-030 trade-off) | ~4h | pending |
| 10.6 | `audit.alert` event firing via tail watcher (D-030 trade-off) | ~4h | pending |
| 10.7 | v1.4.0 release | ~1h | pending |
| **Total** | | **~17h** | |

Ordering rationale: 10.0 first (doc refresh is a quick win, unblocks
nothing else). 10.1 → 10.3 are skill files (operator-facing,
independent). 10.4 → 10.6 are backend modules (PHP, can run in
parallel sub-agents). 10.7 wraps.

---

## 10.0 — Doc refresh

Four stale figures spotted in the post-v1.3.0 audit:

- `specs/01-adapter.md:3,46` — "v1.2.0 / 100 tools" → "v1.3.0 / 108 tools"
- `docs/decisions.md:626` (D-029) — "100 tools in v1.2.0" → "108 tools in v1.3.0"
- `specs/04-divi5.md:128-131` — "47 builders / 58 free-form" → "79 / ~26"
- `specs/04-divi5.md:136-138` — testimonials carousel listed as open
  question, but resolved in 9.1. Move to "Settled" block.

Plus this action plan + a roadmap section pointing at it.

## 10.1 — Skill `webhook-setup`

### What

A workflow skill that walks the operator from "I want a Slack alert
when smoke tests fail" to "the receiver is verifying signatures".

### How

`claude-plugin/skills/webhook-setup/SKILL.md`, ≤ 200 lines. Sections:
1. Goal + when to use.
2. Prerequisites (`config:write`, kill switch off).
3. Pick channel (Slack incoming webhook vs generic JSON receiver).
4. Generate the signing secret (the receiver needs the plaintext).
5. Register via `iawm_webhooks_create` with the right event list.
6. Send a `test.ping` via `iawm_webhooks_test`; confirm 2xx.
7. Verify the receiver's signature check (point to the recipe in
   `docs/operations.md`).
8. Pitfalls + when NOT to use.

## 10.2 — Skill `content-rollback`

### What

A workflow skill for "restore yesterday's version of the homepage"
via the v1.3.0 revisions API. Handles confirmation token + dry-run +
post-restore smoke test.

### How

`claude-plugin/skills/content-rollback/SKILL.md`, ≤ 200 lines.
Sections:
1. Goal + when to use ("a recent content edit broke the layout / the
   wrong copy went live").
2. Prerequisites (`content:write`).
3. List revisions for the target post (`iawm_content_revisions_list`).
4. Inspect candidates (`iawm_content_revisions_get`) — show
   build_mode so the operator picks the right one.
5. Dry-run restore — captures the confirmation token + the
   `pre_op_backup_id` preview (the "fresh revision that captures
   pre-restore state" mechanism from 9.5).
6. Apply with token.
7. Smoke-test (`iawm_diagnostics_smoke`).
8. Rollback path if restore broke the site: same skill, but target
   the auto-created revision id from the original restore.

## 10.3 — Pseudonymisation step in `prod-deployment-checklist`

### What

The existing `prod-deployment-checklist` skill (or
`docs/production-deployment.md`) should mention when to flip
`iawm_audit_pseudonymise`: typically when a third party (hosting log
shipper, monitoring sidecar) can read the audit log.

### How

Add a short section "If a third party reads the audit log, enable
pseudonymisation" with: where the toggle lives (admin Cleanup tab),
which params get redacted (passwords on user creation, signing
secrets on webhook creation), and a one-line test
(`iawm_audit({limit:1})` after a `users/create` should show
`<redacted:sha256:...>`).

## 10.4 — Encrypt webhook `signing_secret` at rest

D-030 trade-off explicitly deferred to v1.4. Wrap the column behind
`IAWM_Crypto::encrypt/decrypt` using AES-256-CBC with a key derived
from `AUTH_KEY`. Versioned envelope `iawm-enc:v1:` for forward
compat. Backward-compat: legacy plaintext rows decrypted via envelope
sniff. One-time migration helper runs from `IAWM_Webhook::init()`
behind a version-bump option.

Decision D-032 records the scheme.

## 10.5 — Webhooks admin tab

D-030 trade-off explicitly deferred to v1.4. New "Webhooks" tab in
the existing `IAWM_Admin` settings page:
- Table of configured webhooks (label, URL, events, enabled, last
  drain status).
- Add / edit form (label, https URL, events checkboxes, signing
  secret shown ONCE at create or rotate).
- Test button → fires `test.ping` and shows receiver status.
- Toggle / Delete / Rotate-secret actions via `admin_post`
  post-redirect-get.

Refactor `IAWM_Webhook` so REST handlers and admin handlers call the
same internal helpers.

## 10.6 — `audit.alert` event firing

D-030 trade-off explicitly deferred to v1.4. A WP-Cron-driven
watcher (`iawm_audit_tail_watch`, every 5 minutes via the existing
`iawm_5min` schedule) scans new audit rows since a watermark and
evaluates three rules:

- `scope_denied_burst` — 5+ `iawm_scope_denied` from the same key in
  a 60 s window.
- `kill_switch_toggled` — fire once per toggle.
- `auth_failure_burst` — 10+ HMAC verification failures from the
  same IP in 60 s.

Each match fires `IAWM_Webhook::fire('audit.alert', $payload)` with
rule id, summary, trigger audit id, window start/end, details.
Operator toggles `iawm_audit_alert_enabled` + selects active rules
via the admin Cleanup tab.

## 10.7 — v1.4.0 release

Mirror v1.3.0 release sequence:

1. Bump plugin / gateway / claude-plugin manifest to `1.4.0`.
2. `npm run build --prefix claude-plugin/mcp-gateway`.
3. Copy `dist/index.js` to `~/.iawm/gateway/index.js`.
4. Smoke-load bundle.
5. CHANGELOG `[1.4.0]` entry covering 10.1 → 10.6.
6. Decisions D-032 (encryption) appended. D-030 updated to mark the
   three trade-offs as resolved.
7. Roadmap: tick Phase 10 sub-items + add v1.4.0 next to the
   deployment-milestones forward references.
8. `git commit` + `git tag -a v1.4.0` + `git push origin main v1.4.0`.
