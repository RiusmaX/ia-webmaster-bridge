# Specs by feature

This folder contains one spec per feature of the system. Specs are
**living documents**: at this stage of the project (design, test and iterate),
they frame the scope and approach, not the line-by-line implementation.

## Index

| # | Spec | Subject | Phase | Priority |
|---|------|---------|-------|----------|
| 01 | [`01-adapter.md`](01-adapter.md) | WordPress plugin + local MCP gateway | 1–2 | High |
| 02 | [`02-security.md`](02-security.md) | Authentication, audit, guardrails | Cross-cutting | High |
| 03 | [`03-content.md`](03-content.md) | Pages, posts, media, menus, blocks | 2 | High |
| 04 | [`04-divi5.md`](04-divi5.md) | Divi 5 layout generation | 3 | **Top** |
| 05 | [`05-configuration.md`](05-configuration.md) | Settings, theme, users | 2 | Medium |
| 06 | [`06-infrastructure.md`](06-infrastructure.md) | Plugins, database, backups | 4 | Medium |
| 07 | [`07-webmaster-layer.md`](07-webmaster-layer.md) | Webmaster skills and workflows | 6 | Medium |

## Status convention

Every spec carries a status in its header:

- **Draft** — initial framing, many open questions.
- **In design** — approach being stabilised.
- **Validated** — approach settled, ready to implement.
- **In implementation** — code in progress.
- **Implemented** — delivered and verified.

## Maintenance rule

Any change to a spec must update its "Status" field and its date.
Any structuring decision is also recorded in `../docs/decisions.md`.
