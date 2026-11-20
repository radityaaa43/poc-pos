# Backlog

The source of requirements for this repo (`CONTRIBUTING.md` → Requirements and backlog). Only the
requirement's owner (`@radityaaa43`) changes a backlog item; everyone else reads it and asks.

## Files

- One file per requirement: `backlog/REQ-<n>-<slug>.md`, e.g. `REQ-001-raw-material-purchasing.md`.
- Numbers are never reused. Take the next free number (`ls backlog/REQ-*`).
- Start from [`TEMPLATE.md`](TEMPLATE.md). Content may be in Indonesian; domain terms follow
  [`GLOSSARY.md`](../GLOSSARY.md).
- Acceptance criteria are required and numbered `AC-01`, `AC-02`, ... within the item. Never
  renumber a criterion that an issue or PR already references; add a new one instead.
- No personal data, credentials or copied confidential documents.

## Status

Set `Status:` in the item: `draft` (still being shaped, not ready for issues) → `ready` (criteria
agreed, issues may be filed) → `done` (every criterion shipped; link the PRs) or `dropped` (say why).

## From backlog item to code

1. Item is `ready` → `/to-spec` files an issue with `Backlog: REQ-<n>` and tags each Done-when
   item with its criterion (`- [ ] [AC-01] ...`).
2. Large items are split with `/to-tickets` into thin slices; each ticket names its criteria.
3. Each PR says which criteria it covers: `Backlog: REQ-<n> AC-01, AC-02`.
4. Work not from the backlog says `Backlog: none — <reason>`.
