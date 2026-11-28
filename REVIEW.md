# REVIEW.md

How PRs are reviewed, by bots, `/code-review`, other agents and humans alike. The point is the same
first-pass review on every PR, so the human reviewer can spend their time on judgment calls.

## Review against

1. **The issue**: does the diff do what *What good looks like* and Done-when ask, no more and no
   less? Something requested but missing is a finding. So is unrequested scope.
2. **The backlog**, when the PR's `Backlog:` line names IDs: re-read those items in
   the backlog/ folder in this repo: one file per requirement, backlog/REQ-<n>-<slug>.md, with AC-<n> acceptance criteria and name the date you read them. A criterion listed but not met is 🔴. A
   criterion that disagrees with the issue, the code or the design is not fixed in review: ask the
   requester to clarify. Each criterion's `@REQ-<n>-AC-<n>` e2e test must really prove it (CI only
   sees the tag); a per-criterion `E2E: ... none` exception needs a reason that holds.
3. [`CODING_STANDARDS.md`](CODING_STANDARDS.md) and the skills it links.
4. [`AGENTS.md`](AGENTS.md): tests for changed behavior, docs in the same PR, Never rules.
5. [`GLOSSARY.md`](GLOSSARY.md) for names. Decisions already in `docs/adr/` or settled in the issue
   are not re-litigated in review.

6. **Business acceptance** (`feat` PRs, and any PR whose `Backlog:` line names criteria): in the
   Review column, before the merge, the Verifier checks that each criterion on the `Backlog:` line
   behaves as the issue's *What good looks like* says, or names the criteria that don't. It is part
   of the verdict; business acceptance stays with the Verifier (owner rule 2026-11-28).
7. **Evidence and the Requirement & AC checklist**: the PR's `## Evidence` table has a row with
   proof for every Done-when line, every `Backlog:` criterion and every plan item. The Verifier's
   verdict ticks the owner's list (`[TC-n]`) against the Evidence, or, on tickets that predate the
   lists, re-checks each row against the REQ text, the plan and the code (format:
   [`docs/agents/handoff.md`](docs/agents/handoff.md) → 3). A missing row, a row
   without proof, or UI text that differs from the REQ wording is 🔴.

Also check:

- **Size:** over 3000 counted lines, ask for a split instead of reviewing it, unless the
  Owner agreed to review it whole and added `large-pr`.
- **New dependencies** (CI's *Dependency check* lists them): each is real, maintained, the intended
  package (not a look-alike name) and needed by the issue.

## Severity

Rank findings most severe first. Each finding gives `file:line`, a concrete failure scenario
(input or state → wrong result), and a suggested fix.

| Level | Meaning | Examples |
|---|---|---|
| 🔴 **Important** | Must be fixed before merge | Bug or crash on a realistic path; security issue; secret or personal data in code, logs, fixtures; data loss; an edited applied migration or one without a rollback; missing permission check; Done-when not met; changed behavior without a test; a bug fix that edits its own failing test; stale docs |
| 🟡 **Nit** | Optional; the author decides | Naming, a clearer structure, a simpler expression, a missing *why* comment |
| 🟣 **Pre-existing** | Not caused by this PR; not blocking | A bug the diff sits next to. Suggest an issue rather than widening the PR |

- **At most 5 nits per review.** Pick the most useful and say how many more you skipped.
- When unsure whether something is Important, explain the scenario and let the human decide.
  Don't inflate a nit.

## Skip

- Lockfiles and generated files.
- Anything CI already enforces: lint/format, type and build errors, test results, the PR checklist
  fields, Standards approval.
- Style preferences that aren't in `CODING_STANDARDS.md`.
- Secret files and real personal data never appear in a diff. If one does, that's Important.

## Recurring findings

A finding that keeps coming back across PRs belongs in the rules, not in another comment. Propose
the rule for `CODING_STANDARDS.md`, `AGENTS.md` or this file in an issue (AGENTS.md →
Documentation).
