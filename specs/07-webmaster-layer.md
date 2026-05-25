# Spec 07 — Webmaster layer (skills & workflows)

- **Status**: In progress (7 skills shipped; per-site context + documented workflows pending)
- **Phase**: 6
- **Priority**: Medium
- **Last updated**: 2026-05-25

## Goal

Turn a set of technical capabilities into genuine **webmaster know-how**:
give Claude working methods, per-site context, and ready-to-use workflows.

## Scope

### Included
- **Claude Code skills**: reusable workflows (create a landing page,
  SEO audit, safe plugin updates, etc.).
- **Per-site context file**: describes the site (brand guidelines,
  conventions, sitemap, key plugins, Divi specifics).
- Verification procedures (review own work, smoke test after operation).

### Excluded (for now)
- The technical capabilities themselves → specs 03 to 06.
- Scheduled routines (possible, to be evaluated once the base is stable).

## Technical approach

- The capabilities (specs 03–06) are **low-level building blocks**. This
  layer assembles them into **high-level procedures** that Claude follows
  as an experienced webmaster would.
- **Skills**: each recurring workflow becomes a documented skill — steps,
  guardrails, success criteria. `skills/` folder.
- **Per-site context**: each target has a context file (brand guidelines,
  editorial tone, page structure, naming conventions, installed plugins).
  Claude reads it before acting on that site.
- **Verification loop**: every workflow ends with a review of the
  result (the agent verifies its own work) and, if relevant, a smoke test.

## Expected workflows (to be specified)

- Create a Divi 5 landing page from a brief.
- Audit the SEO of a set of pages and propose fixes.
- Update plugins safely (backup → update → smoke test → report).
- Detect and fix broken links.
- Produce a site status report (content, performance, to-do).

## Open questions

- Format of the per-site context file (a Markdown? a dedicated skill?).
- Which workflows to prioritise once Phases 1–5 are stable?
- Are scheduled routines needed (weekly audit, etc.)? To be evaluated.
- How to capitalise: should a successful workflow on a site enrich a
  pool of methods shared across all sites?

## Dependencies & risks

- Depends on all other specs: this is the final assembly layer.
- Risk: over-industrialising too early. This layer only makes sense
  once the basic capabilities are reliable — hence its position in Phase 6.
