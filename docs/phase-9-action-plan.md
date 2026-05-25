# Phase 9 — Polish + long tail · action plan

> Status: Planned · Last updated: 2026-05-25
>
> Goal: close the remaining items from the v1.2.0 audit (deeper Divi
> builders, webhook signing, revisions API, audit-log pseudonymisation),
> resolve the one TODO left in the code (testimonials carousel), and
> tick "validated on a small prod" on the deployment roadmap. Tag the
> result as **v1.3.0**.

## Why this phase

The v1.2.0 audit pass on 2026-05-25 confirmed every spec is now aligned
with the code, every Phase 8 deliverable shipped, and the project is
operationally clean. The remaining items fall into three buckets:

1. **One-line code debt**: a single `TODO` in `patterns/testimonials.ts`
   (carousel variant) and the long tail of 58 Divi modules that the
   auto-discovered registry (D-018) exposes only in free-form mode.
2. **Genuinely-open spec questions**: revisions API (spec 03),
   audit-log pseudonymisation (spec 02), webhook signing for outbound
   notifications (Phase 8 carry-over).
3. **Operational milestone**: validation on a real (small) production
   site — not code to write, but the next real risk gate.

None of these block v1.2.0. v1.2.0 is shipping-ready. Phase 9 is the
polish + long-tail pass before opening the door to bigger production
exposure.

## Eight sub-phases

| Sub-phase | Theme | Effort | Status |
|---|---|---|---|
| 9.0 | Action plan + doc structure (this file + roadmap + CLAUDE.md) | ~30 min | in_progress |
| 9.1 | Testimonials carousel variant (resolve the lone TODO) | ~1.5h | pending |
| 9.2 | Top-20 Divi native module builders (typed + opinionated defaults) | ~7h | pending |
| 9.3 | Top-10 WooCommerce module builders (typed + opinionated defaults) | ~4h | pending |
| 9.4 | Webhook signing for outbound notifications | ~3h | pending |
| 9.5 | Revisions API (read history + restore) | ~3h | pending |
| 9.6 | Audit log pseudonymisation (per-route sensitive-param scheme) | ~2h | pending |
| 9.7 | Production validation on a small prod | variable | pending |
| 9.8 | v1.3.0 release | ~1h | pending |
| **Total** | | **~22h + prod milestone** | |

Ordering rationale: 9.1 is a warmup (small, well-scoped, gets the brain
in Divi land). 9.2 → 9.3 are the bulk of the work and can be split
between sittings. 9.4 → 9.6 are independent quick wins. 9.7 is the
real risk gate. 9.8 is the wrap.

---

## 9.1 — Testimonials carousel variant

### What

`claude-plugin/mcp-gateway/src/divi/patterns/testimonials.ts:5` carries
the only `TODO` in the codebase: a carousel (slider) variant alongside
the existing grid variant.

### How

- Add `variant: "grid" | "carousel"` to the pattern's input schema
  (default `"grid"` for back-compat).
- For `"carousel"`, compose using the Divi 5 slider module
  (`divi/slider` per the registry) with each testimonial as a slide,
  or use a row of toggleable testimonial modules wrapped in a slider —
  pick whichever round-trips cleanly.
- Update `docs/divi5-compose-dsl.md` with the new variant example.
- Smoke test: write a page with the carousel variant, read it back,
  assert bit-faithful round-trip.

### Done when

- The TODO comment is gone.
- `iawm_divi_page_compose` accepts the new variant.
- A round-trip test passes on the local site.

---

## 9.2 — Top-20 Divi native module builders

### What

47 typed builders exist today in
`claude-plugin/mcp-gateway/src/divi/builders.ts`; 58 native modules in
the auto-discovered registry (D-018) are usable only in free-form
mode. The long tail of 58 won't all land in 9.2 — pick the 20 most
useful and ship them with opinionated defaults.

### How

1. Read `docs/divi5-modules-catalog.md` to see the full 105-module
   inventory.
2. Cross-reference with `claude-plugin/mcp-gateway/src/divi/builders.ts`
   to identify which 58 native modules don't yet have a typed builder.
3. Pick the **top 20** that an opinionated webmaster would reach for
   first: tabs, accordion, image-with-text-overlay, gallery
   (lightbox), counter (animated number), pricing-table-with-bullets,
   testimonial-with-image, post-grid-with-thumbnail, etc. (final list
   to be decided when this sub-phase starts; the registry holds the
   exact names.)
4. For each, write the builder following the existing style: typed
   Zod input, sensible defaults wired to the active design tokens
   (colors / fonts / spacing pulled from `iawm_divi_global_data`),
   round-trip test.
5. If any module attribute isn't yet documented in
   `docs/divi5-format.md`, append a note.

### Done when

- 67 typed builders in `builders.ts` (47 + 20).
- Each new builder has a one-line example in
  `docs/divi5-compose-dsl.md`.
- Long-tail catalogue size noted in roadmap (58 → 38 free-form-only).

---

## 9.3 — Top-10 WooCommerce module builders

### What

Same shape as 9.2 but for the 25 WooCommerce modules in the registry.
The four canonical Theme Builder contexts (shop, single-product,
cart, checkout — see `docs/woocommerce-integration.md`) suggest a
module list each; pick the 10 most useful across those contexts and
ship typed builders.

### How

1. Re-read `docs/woocommerce-integration.md` for the suggested module
   per context.
2. Pick the 10 highest-leverage modules — typically:
   `woocommerce-cart-products`, `woocommerce-checkout-billing`,
   `woocommerce-checkout-payment`, `woocommerce-product-title`,
   `woocommerce-product-price`, `woocommerce-product-images`,
   `woocommerce-product-add-to-cart`, `woocommerce-product-tabs`,
   `woocommerce-related-products`, `woocommerce-upsell-products`.
   (Adjust to actual registry names when the sub-phase starts.)
3. Write the builders; respect the `use_on` Theme Builder assignment
   semantics from the existing helper.
4. End-to-end smoke test: compose a full single-product page via
   `iawm_divi_theme_builder_compose` using only typed builders (no
   free-form), assert the Theme Builder UI renders it identically.

### Done when

- 10 new builders in `builders.ts`.
- `docs/woocommerce-integration.md` gains a "Typed builders" section.
- An E2E "build a single-product template" runs in one prompt without
  any free-form attribute dump.

---

## 9.4 — Webhook signing for outbound notifications

### What

Today the plugin can notify the operator about a smoke-test failure
or an audit alert only by being polled. Sending a signed POST to a
configured endpoint (Slack incoming webhook, generic JSON receiver,
PagerDuty event) closes that gap. The signing scheme is symmetric to
the inbound HMAC model (D-005, spec 02).

### How

- New module `IAWM_Webhook` with per-channel registration
  (`/config/webhooks/list,create,update,delete`). Each entry stores
  a label, a destination URL, a signing secret, and a list of events
  to fire on (e.g. `smoke.failed`, `audit.alert`,
  `key_rotation.reminder`).
- Outbound payload signed with HMAC-SHA256 over `(timestamp + nonce
  + body)` and sent as headers `X-IAWM-Webhook-Timestamp`,
  `X-IAWM-Webhook-Nonce`, `X-IAWM-Webhook-Signature`. Receivers
  validate the signature with the shared secret to defeat replay.
- Outbox table `wp_iawm_webhook_outbox` with retry policy (3 retries
  with exponential backoff, then dead-letter). WP-Cron job drains it.
- Admin tab "Webhooks" — list, create, test (send a synthetic event
  and show the receiver's response), delete.
- Decision D-030 records the signing scheme and the trust model.

### Done when

- Both ends of a smoke-test failure → Slack flow work on the local
  site (Slack channel receives a signed message).
- Receiver-side validation example is in `docs/operations.md` ("How
  to consume an IA Webmaster webhook").

---

## 9.5 — Revisions API

### What

Spec 03 open question. Today the agent can read the current state of
a post but cannot enumerate or restore yesterday's version. WordPress
already stores revisions natively — exposing them is mostly a
plumbing job.

### How

- New routes:
  - `GET /content/revisions/list?post_id=N&limit=20` — paginated
    list of revisions (id, author, date, excerpt, byte size).
  - `GET /content/revisions/get?revision_id=N` — single revision
    content (post_content + post_title + meta of interest).
  - `POST /content/revisions/restore` — apply a revision as the
    current state. Gated by `content:write` AND by a confirmation
    token (re-uses the Phase 5.3 mechanism); auto-snapshot via
    `IAWM_Backup` before applying.
- New MCP tools mirroring the routes.
- Build-mode detection still applies: restoring a revision into a
  Divi-built page goes through `IAWM_Divi` write path so the cache
  semantics stay consistent.
- Update spec 03 to mark the open question as settled, link to the
  endpoints.

### Done when

- The three routes are live and audit-logged.
- An end-to-end "show me yesterday's homepage and restore it" prompt
  works on the local site.

---

## 9.6 — Audit log pseudonymisation

### What

Spec 02 open question. `wp_iawm_audit_log.parameters_summary` stores
the request body as plain JSON. For a setup where a third party can
read the audit log (e.g. a read-only monitoring sidecar key), values
marked sensitive (user passwords on `/config/users/create`, webhook
secrets on `/config/webhooks/create`, etc.) should be hashed or
redacted before storage.

### How

- Per-route declaration of "sensitive parameter paths" — a small map
  at the top of each module, e.g.
  `IAWM_Config::SENSITIVE_PARAMS = ['users/create' => ['password']]`.
- Audit writer walks the body before storage, replacing each
  declared path with either `"<redacted>"` or a stable hash like
  `"sha256:abc123…"` so logs stay correlatable without leaking the
  value.
- Setting `iawm_audit_pseudonymise` (default `off` for v1.3.0
  back-compat) gates the masking globally. Operators turn it on
  when they're ready.
- Backward-compatible: legacy rows stay readable as-is; only new
  writes see the masking.
- Decision D-031 records the scheme.

### Done when

- A new `users/create` audit row stores `"password": "<redacted>"`
  when the toggle is on.
- Spec 02 marks the question as settled.

---

## 9.7 — Production validation on a small prod

### What

Roadmap "Deployment milestones" still has "Validated on a small prod"
unchecked. This is the next real risk gate.

### How

1. Pick a client site (Marius's choice — ideally one of the smaller
   ones with a few weeks of acceptable downtime tolerance just in
   case).
2. Walk through `docs/production-deployment.md` end-to-end. Every
   step that fails or is ambiguous becomes a PR.
3. Run the `docs/operations.md` pentest checklist for real (not the
   dry-run on local). Log every finding in
   `docs/pentest-2026-MM-DD.md`.
4. Let the bridge run on the site for at least one full week of
   normal traffic. Monitor the audit log, the 404 tracker, the
   smoke-test cron, the backup cron.
5. Tick "Validated on a small prod" in `docs/roadmap.md`.

### Done when

- `docs/roadmap.md` deployment milestones has "small prod" checked.
- The pentest doc records any real-world finding (positive or
  negative).

---

## 9.8 — v1.3.0 release

### What

Bundle 9.1 → 9.7 into v1.3.0.

### How

Mirror the v1.2.0 release sequence (see commit `a79cd45`):

1. Bump `plugin/ia-webmaster-bridge/ia-webmaster-bridge.php` (header
   + `IAWM_VERSION`).
2. Bump `claude-plugin/mcp-gateway/package.json`.
3. Bump `claude-plugin/.claude-plugin/plugin.json`.
4. `npm run build --prefix claude-plugin/mcp-gateway` → copy
   `dist/index.js` to `~/.iawm/gateway/index.js`.
5. Smoke test the bundle (`node -e import(...)`).
6. CHANGELOG `[1.3.0]` entry summarising 9.1 → 9.7.
7. Decisions D-030 (webhook signing) + D-031 (audit
   pseudonymisation) appended.
8. Roadmap: tick the Phase 9 items + add v1.3.0 to deployment
   milestones if "small prod" landed.
9. `git commit` + `git tag -a v1.3.0` + `git push origin main
   v1.3.0`.

### Done when

- `git tag v1.3.0` exists.
- The GitHub release page shows v1.3.0 with the CHANGELOG body.
