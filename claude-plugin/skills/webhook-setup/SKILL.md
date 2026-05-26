---
name: webhook-setup
description: Register and verify an outbound webhook destination for IA Webmaster Bridge events (smoke-test failures, audit alerts, key-rotation reminders). Walks through choosing the channel, generating the signing secret, sending a test ping, and confirming the receiver verifies the HMAC signature correctly.
---

# Webhook setup — outbound notifications

## Goal

Wire the site to push HMAC-signed JSON events to an operator-chosen
receiver. Today that means Slack incoming webhooks, a generic JSON
endpoint, or PagerDuty Events v2 — any HTTPS POST receiver that can
verify a signature header. After this skill, the operator has at
least one channel registered, tested end-to-end, and the receiver
side has the recipe to validate signatures.

## When to use it

- The operator asks to "get an alert when the smoke test fails", "send
  audit alerts to Slack", "page me on auth bursts".
- After enabling the Phase 9.6 audit pseudonymisation, when a third
  party will read the audit stream.
- During a fresh prod install (the
  `prod-deployment-checklist` skill should mention configuring one
  smoke-failed webhook as a recommended step).

## Prerequisites

- API key with at least the `config:write` scope.
- `iawm_status` returns OK and the kill switch is OFF.
- The receiver URL is **HTTPS**. Plaintext HTTP destinations are
  rejected on principle (signatures are pointless if the body can
  be intercepted).
- For Slack: the operator has created an incoming webhook in the
  target Slack workspace and has the URL.

## Step-by-step

### 1. Read the site context

```
iawm_site_context_get()
```

Check `infrastructure.environment_note` and `infrastructure.plugins_required`
for any existing notification convention (e.g. "all sites in this
org use the `#ops-alerts` Slack channel"). Honour it.

### 2. Check what's already registered

```
iawm_webhooks_list()
```

Avoid duplicates. If a webhook with the same destination URL already
exists, prefer `iawm_webhooks_update` to extend its event list rather
than creating a parallel one.

### 3. Pick the channel

| Channel | Pros | Notes |
|---|---|---|
| **Slack incoming webhook** | Fastest setup, decent default UI | Slack doesn't verify HMAC — it ignores the signature header. Useful for visibility, not for secrets. |
| **Generic JSON receiver** | Full HMAC verification on your side, integrates with anything | Need to write the verifier (recipe in `docs/operations.md`). |
| **PagerDuty Events v2** | Pager-rotation aware, escalation policies | Use a generic receiver that re-shapes the payload into PagerDuty's schema, signature-verified. |

For each channel pick, confirm what *events* the operator wants:

| Event | When it fires | Recommended channels |
|---|---|---|
| `smoke.failed` | After an unhealthy `iawm_diagnostics_smoke` run (auto-fired by the diagnostics module) | Slack + pager |
| `audit.alert` | When the audit tail watcher trips a rule (scope_denied_burst, kill_switch_toggled, auth_failure_burst) | Pager + audit-mailbox |
| `key_rotation.reminder` | Scheduled by the `scheduled-routines` skill | Email channel |
| `test.ping` | Only fired by `iawm_webhooks_test` | All channels, transient |

A wildcard `"*"` subscribes to every event — convenient for a debug
endpoint but **noisy in prod**.

### 4. Generate the signing secret

The signing secret is the shared HMAC key. The receiver needs the
**plaintext** value (the plugin stores it encrypted at rest since
v1.4 — D-032 — but the operator and the receiver both use the
plaintext). Generate a strong one:

> Suggested format: 32 random bytes hex-encoded — e.g.
> `bin2hex(random_bytes(32))` in PHP, `crypto.randomBytes(32).toString("hex")`
> in Node, or `openssl rand -hex 32` on the shell. 64 hex chars total.

Show this value to the operator **once**, ask them to paste it into
the receiver's configuration, then proceed. Do **not** echo the
secret again in subsequent messages.

### 5. Register the webhook

```
iawm_webhooks_create({
  label: "<short human-readable>",
  destination_url: "https://...",
  events: ["smoke.failed"],     // or larger list
  signing_secret: "<the value from step 4>",
  enabled: true
})
```

Capture the returned `webhook_id`. The response strips the secret —
that's intentional.

### 6. Send a test ping

```
iawm_webhooks_test({ webhook_id: <id> })
```

The plugin synthesises a `test.ping` event, signs it with the stored
secret, and POSTs it to the destination. The response surfaces the
receiver's HTTP status code + body excerpt. **2xx is required.** Any
3xx/4xx/5xx → debug before moving on (most common: wrong URL,
receiver blocks HEAD/POST without a custom UA).

### 7. Verify HMAC verification on the receiver side

This is the part operators routinely skip. Confirm explicitly.

Point the operator at the recipe in [`docs/operations.md`](../../../docs/operations.md)
("Consuming an IA Webmaster webhook"). The canonical signing string
is:

```
timestamp + "\n" + nonce + "\n" + raw_body
```

Three headers carry the metadata:

- `X-IAWM-Webhook-Timestamp` (unix epoch seconds)
- `X-IAWM-Webhook-Nonce` (16 random hex bytes)
- `X-IAWM-Webhook-Signature` (`sha256=` + hex HMAC-SHA256)

A receiver MUST:
1. Reject if the timestamp is more than ±5 minutes from "now".
2. Recompute the HMAC and compare in constant-time.
3. Reject if either fails.

Ask the operator to confirm the receiver does this — either by
inspecting their code or by sending a deliberately-bad signature
through a test fixture and confirming it's rejected.

### 8. Trigger a real event to validate

If the registered events include `smoke.failed`, trigger a controlled
failure to confirm end-to-end:

```
iawm_diagnostics_smoke()
```

If the site is currently healthy and you don't want to break
anything to force a smoke failure, simply rely on the `test.ping` from
step 6 — it's the same code path minus the trigger logic.

### 9. Tell the user it's done

Summarise:
- Webhook id, label, destination, event list, enabled state.
- Receiver verified signatures correctly (yes/no).
- Suggest scheduling the `key_rotation.reminder` event via
  `scheduled-routines` if the operator hasn't already.

## Pitfalls

- **Slack ignores signatures.** Slack receivers consume the body as-is
  and don't verify your HMAC header. That's fine for visibility but
  means a leaked URL is enough to inject fake events. Either accept
  it (most operators do) or front Slack with a verifying proxy.
- **The secret cannot be re-read.** Once created, the plugin returns
  `signing_secret: "[hidden]"` on every read. If the operator loses
  it, they have to rotate (via the admin UI or by recreating the
  webhook). Encryption at rest (D-032) protects from DB leaks but
  doesn't help recover the value.
- **The 5-minute replay window assumes synced clocks.** A receiver
  with a clock skew > 5 minutes will reject all events. Document NTP
  as a prerequisite when troubleshooting "every event is rejected".
- **The outbox is per-site on multisite.** Each sub-site has its own
  `wp_iawm_webhooks` + `wp_iawm_webhook_outbox` tables. Configure the
  webhook on the sub-site whose events you care about — there is no
  network-wide fan-out by design.
- **Dead-lettered events stay in the outbox.** Look at the admin
  Webhooks tab's "Last drain" column; rows stuck at `status='dead'`
  after 3 failed attempts indicate the receiver is permanently down
  or the URL changed. Either fix the receiver or rotate the URL.

## When NOT to use this skill

- The operator only has the `read` scope. Stop and ask for a
  `config:write` key.
- The destination URL is HTTP (not HTTPS). Refuse and ask for HTTPS.
- The site has no smoke-test history. Wire a smoke schedule first
  (`scheduled-routines` skill) before subscribing to `smoke.failed`,
  otherwise the webhook will never fire.
- The operator wants to subscribe to events that don't exist (e.g.
  `content.published` is not implemented in v1.4). Show the supported
  event list and confirm.
