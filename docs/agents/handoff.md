# Handoff standard: task, delivery, review

How work moves between the Tech Lead and the developers. These are the only three formats. A message
or comment that lacks a required field is sent back, not acted on. Roles and board columns:
[`issue-tracker.md` → Board and roles](issue-tracker.md#board-and-roles).

- [1. Task: Tech Lead → developer](#1-task-tech-lead--developer)
- [2. Delivery: developer → Tech Lead](#2-delivery-developer--tech-lead)
- [3. Review: Tech Lead → PR](#3-review-tech-lead--pr)
- [4. Business track: Business / PM](#4-business-track-business--pm)

## 1. Task: Tech Lead → developer

A task is a **technical plan comment** on the issue, then an **assignment message** to the developer.
The card moves Planning → Todo only after both, and only after the plan has been re-reviewed.

### 1a. Plan comment (on the issue)

Every section is required; write `none — <reason>` when one doesn't apply.

```markdown
## Technical plan (Tech Lead)

**Summary.** 2–4 sentences: what changes and the approach.

**Requirement wording.** The exact UI terms from the REQ / AC that the change must show, quoted
(e.g. AC-01: "Perlu dibuat di dapur", "Target waktu buat (menit)"), or `none — no user-facing text`.

### DB
Tables and columns (type, default, index, FK), migration file name, backfill and its REQ default,
`down()`.

### Backend
Routes and middleware, controller methods, validation, services, permissions (Spatie and Sanctum kept
aligned), audit log. Cite the existing code it follows as `path:line`.

### Frontend
Pages, components, `useForm`, i18n keys (`en.json` and `id.json`), Menu.jsx.

### Tests
One row per Done-when line and per AC:
| Done-when / AC | PHPUnit | Vitest | E2E (`@REQ-<n>-AC-<n>`) or exception with reason |

### Docs
GLOSSARY.md, docs/, README rows the change makes stale.

### Decision record
    Decision (Tech Lead, delegated by the maintainer, YYYY-MM-DD): <what>
    Why: <reason, with the rule or code it rests on>
    Rejected: <alternatives and why>
    Review: rules ✓ code ✓ AC ✓ data ✓ security ✓ parallel ✓ tests ✓

### Size, effort, risks
Counted lines (aim < 300), Effort, hotspot files touched, migration order, overlap with other
in-flight tickets.

**Builder: devN**

_Plan re-review ✓ (YYYY-MM-DD): cited lines still match `origin/main` <sha>; every Done-when line and
AC maps to a test; no file overlap with other in-flight tickets._
```

**Re-review before Todo.** Read the plan again against current `main`. Check that the cited lines
haven't moved, that the requirement wording is quoted, that every Done-when line has a test, and that
parallel tickets don't share files. Then add the re-review line.

### 1b. Assignment message (SendMessage to the developer)

```text
#<n> assigned to devN — start now.
Plan: <plan comment URL>
Board: Todo, ready-for-agent, Effort <level>, Builder devN.
Worktree ../poc-pos-wt-<n>, branch <type>/<n>-<slug> from origin/main, ports <review>/<e2e>.
Cleared hotspots and migrations: <list from the plan, or none>.
Parallel with: #<m> (no shared files) | none.
Report: "#<n> | started" now; "#<n> | ready for review" with the delivery (section 2).
```

- **Several tickets at once:** a developer may hold up to three `low` Effort tickets that touch
  different files. Each one gets its own worktree, branch, PR and subagent (model `sonnet`, effort
  `high`). The developer integrates: reviews each subagent's diff, verifies it, opens the PR.
- **Questions:** a technical question goes to the Tech Lead. A business question goes to the Tech
  Lead, who takes it to Business / PM. Never guess.

## 2. Delivery: developer → Tech Lead

A task is delivered when the PR carries an **Evidence** table and the developer sends a **ready
message**. "Done" without evidence is not done.

### 2a. Evidence (in the PR body, the `## Evidence` section)

**Mandatory evidence** (maintainer, 2026-11-25):

- **Every UI change** has before and after screenshots attached to the PR, showing the REQ wording. A failed upload blocks the merge, and there is no waiver.
- **Every feature or flow change** has e2e tests, including a backend-only flow, which is asserted through the e2e state query.
- `E2E: none — <reason>` is allowed only for docs, governance and pure refactors.

One row per Done-when line, per AC on the `Backlog:` line, and per plan item.

```markdown
| # | Item (Done-when / AC / plan) | Done | Evidence |
|---|---|---|---|
| 1 | [AC-01] <criterion, in the REQ's words> | ✓ | `ProductPreparationTest::test_store_saves_…` pass; e2e `41-products/04-…` `@REQ-003-AC-01` pass |
| 2 | Plan: migration reversible | ✓ | `down()` drops both columns; `test_release_backfill_…` runs down → up |
| 3 | Full suite | ✓ | PHPUnit 831 pass / 0 fail / 1 skip (skips on main too); Vitest 225 pass; `npm run build` ok |
| 4 | CI | ✓ | <link to the green run> |
| 5 | Manual test as <role> | ✓ | <steps> → <what you saw>; screenshot before / after |
```

Valid evidence is one of these:
- a test name and its result;
- a command and its counts;
- an e2e spec and its `@REQ` tag;
- a CI run link;
- a screenshot;
- a manual step and what you saw;
- a `path:line` for a pure code fact.

"Works", "tested" or "done" alone is not evidence. UI text must use the REQ's exact wording. A skip
or exception needs its reason.

### 2b. Ready message (SendMessage to the Tech Lead)

```text
#<n> | ready for review | PR <url> | CI green <run url> | <counted lines> lines
Evidence: <k>/<k> rows ✓ (table in the PR)
Hotspots: <files> | Migrations: <names> | Pre-PR review: /code-review low (sonnet) posted on the PR
```

Before sending: run `/code-review low` in a `sonnet` subagent against the worktree diff (not the
shared checkout), fix or answer each finding, and post the result on the PR. Move the card to In
review.

## 3. Review: technical first, then business

Reviews run in a fixed order, and each step starts only when the one before it passes:

1. **Technical review (Tech Lead).** It covers code, tests, the plan, and the requirement and technical
   AC checklist below.
2. **Business review (Business / PM).** It covers the AC check against *What good looks like*. The
   Tech Lead hands the PR over only after a technical **APPROVE**, with a message to Business / PM:
   `#<n> | PR <url> | technical APPROVE | ready for business review`. In the same step the Tech Lead moves the issue's card from Technical review to Business review, so the hand-over and the board can't drift. This applies to `feat` PRs and
   to any PR whose `Backlog:` line names criteria. Business / PM doesn't review a PR before the
   handover.
3. **Merge (Tech Lead).** Both reviews passed and CI is green. The Tech Lead merges and tells
   Business / PM.
4. **Done (Business / PM).** Business / PM moves the card to Done once every review has passed and
   the PR is merged. The board's *Item closed* workflow does this on its own when the merged PR's
   `Closes #n` closes the issue; Business / PM checks the card landed and moves it by hand only if
   it didn't.

If business review doesn't pass, Business / PM sends its evaluation to the Tech Lead: the criteria
not met and what it saw. The Tech Lead turns it into a fix for the developer, moves the card back to
In progress, and the order starts again at step 1.

The Tech Lead's technical approval is a **Requirement & AC checklist** comment on the PR
([`REVIEW.md`](../../REVIEW.md) → Review against, item 7). The code review itself runs as
`/code-review low` in a `sonnet` subagent; the checklist is done by the Tech Lead.

```markdown
**Tech Lead review: Requirement & AC checklist** (YYYY-MM-DD, REQ-<n> read on <date>)

**Verdict: APPROVE | CHANGES**

| # | Requirement / AC / plan item | Status | Verified at |
|---|---|---|---|
| 1 | [AC-01] <criterion> | ✓ / ✗ | <code line, test, CI run, screenshot> |

**Findings** (REVIEW.md severities): 🔴 … · 🟡 … · 🟣 …
```

- Every Evidence row is re-checked against the REQ text, the plan and the code. It is not copied.
- Any ✗ or unproven row means **CHANGES**, and there is no merge.
- On APPROVE, hand the PR to Business / PM (step 2). Merge only when the checklist is all ✓, CI is
  green and Business / PM's AC check is OK. Then tell Business / PM, who moves the card to Done.

## 4. Business track: Business / PM

Rules the maintainer set on 2026-11-25 for the business side. Business / PM decides under the
delegation in [`issue-tracker.md` → Delegation](issue-tracker.md#delegation).

1. **Requirements are structured, detailed, complete and comprehensive.** Every REQ follows
   [`backlog/TEMPLATE.md`](../../backlog/TEMPLATE.md): every section filled or `n/a — <reason>`, every
   edge case in its checklist covered by a criterion or marked n/a.
2. **Review before deciding.** Check each business decision against:
   - earlier decisions: the REQ, the issue body **and every comment**, ADRs, GLOSSARY
   - the code as it is
   - the impact on the cashier, the owner's money and stock
   - the edge-case checklist
   - per-shop permissions (never a hard-coded role)
   - the general-POS rule (no tenant names)
   - reversibility (if it can't be undone, it goes to the maintainer)

   Record it on the issue:

   ```
   Decision (Business/PM, delegated by the maintainer, YYYY-MM-DD): <what>
   Why: <reason>
   Rejected: <alternatives and why>
   ```
3. **Review again before Planning.** Before moving a card to Planning, re-read the issue against
   rules 1–2, verify its claims in the code, fix any gap in the body, and post a comment starting
   `**Business review before Planning**` that lists what was checked. Only then move the card.
4. **Keep Planning fed.** Each time the Tech Lead takes cards, prepare the next Backlog items under
   rule 3. Target: at least two ready items per developer. A REQ ticket waiting on unmerged work can
   still go to Planning once its REQ is on `main`; the Tech Lead sequences it.
5. **Shift-left AC check.** When the Tech Lead posts a plan (message `#<n> plan posted`), Business /
   PM checks it against the criteria and the business rules, including exact REQ wording for UI
   text, and comments on the issue. It doesn't block: the developer starts at once, and Business /
   PM messages the Tech Lead only about a missed criterion.
6. **Questions found while planning.** The Tech Lead proposes a default for each business question
   and keeps planning with it; Business / PM confirms or corrects it on the issue under rule 2.
7. **Business review of a PR** (step 2 of section 3) is one PR comment:

   ```markdown
   **AC check — Business/PM** (REQ-<n> read on `main` YYYY-MM-DD; issue #<n>)

   **Verdict: AC check: OK | changes requested**

   | # | Requirement / AC | Evidence in the diff | Result |
   |---|---|---|---|
   | AC-01 | <criterion> | <code, test, e2e tag> | ✅ / ❌ |
   ```

   - One row per tagged criterion, Done-when item, recorded decision and business rule or edge case.
   - UI wording must match the REQ terms exactly.
   - Read the full diff. Never pass a PR on its description alone.
   - On ❌, send the evaluation to the Tech Lead (section 3).
8. **After Done.** Update the REQ's *Delivered by* (issues and PRs per criterion). When every
   criterion has shipped, set the REQ to `done`.
