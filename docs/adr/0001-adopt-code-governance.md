# 0001. Adopt the code governance standard

- **Status:** accepted
- **Date:** 2026-11-20
- **Issue:** none — adopted before the issue tracker workflow existed (`gh` not yet set up); the adoption PR is the record

## Context

Several people and AI agents change this repo in parallel. Without shared rules, requirements are
guessed, PRs grow large, secrets and personal data can leak into prompts and commits, and review
quality depends on who happens to review.

## Decision

Adopt the code governance standard (tier `full`, version 1.0.0):

- Every change goes issue → branch → test → review → PR, with the workflow skills from
  mattpocock/skills (`AGENTS.md` → Workflow).
- Written rules in `AGENTS.md` (one page, read by every agent; `CLAUDE.md` imports it),
  `CONTRIBUTING.md`, `REVIEW.md` and `CODING_STANDARDS.md`.
- Enforcement in two places: a Claude Code guard hook (`.claude/hooks/guard.py`) and CI checks
  (`.github/workflows/governance-*.yml`). All project-specific settings live in
  `.github/governance.json`.
- Standards files change only with a Standards owner's approval.

### Project decisions (2026-11-20)

- **Branching:** `main` is the only long-lived branch; feature branches open PRs into `main`. The
  upstream Git Flow (`development`, `release/*`) in the old `CONTRIBUTING.md` and `AGENTS.md` is
  dropped, and releases are tags on `main`. No exploratory branch.
- **Requirements:** the `backlog/` folder in this repo, one file per requirement
  (`AKAR-<n>-<slug>.md`), with required `AC-<n>` acceptance criteria. Backlog files may be in
  Indonesian; everything else is in English.
- **Workflow skills:** the mattpocock/skills workflow is the required path. The previous
  `AGENTS.md` rule to load `superpowers:brainstorming`, `superpowers:writing-plans` and
  `ponytail-review` is kept as optional helpers inside those steps; `ponytail` stays the default
  style. The personal Obsidian session export is no longer a repo rule.
- **Ownership:** seven modules, all owned by `@radityaaa43`, who is also the Standards owner. While
  there is a single maintainer, an agent `/code-review` recorded in the PR stands in for a second
  reviewer on `feat`, `fix` and `refactor` PRs.
- **Code style:** Pint gates only changed files (`--diff=origin/main`), so upstream files stay
  byte-identical for future syncs. Three oversized React pages are exempt from the file-length
  check until split (`CODING_STANDARDS.md`).
- **Hosting:** public repository on GitHub Free. Branch rulesets can be enforced; until one is
  applied, the guard hook and these documents carry the rules.
- **Project detail** from the old `AGENTS.md` moved unchanged to `docs/agents/project-guide.md`.

## Consequences

- Each developer installs the workflow plugin once (`README.md` → Agent setup).
- PRs need `Closes #`, a manual test, a Docs line and an AI used line; CI fails without them.
- Rules that turn out wrong are changed through a Standards issue and PR, not ignored.
