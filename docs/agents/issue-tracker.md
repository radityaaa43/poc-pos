# Issue tracker: GitHub

Issues and specs for this repo live as GitHub issues in `radityaaa43/poc-pos`. Use the `gh` CLI for all
operations. Every change starts from an issue (`AGENTS.md`).

## Conventions

- **Create an issue**: `gh issue create --title "..." --body-file <file>` (use the templates in
  `.github/ISSUE_TEMPLATE/`; a heredoc or file for multi-line bodies).
- **Read an issue**: `gh issue view <number> --json number,title,body,labels,assignees,comments`.
- **List issues**: `gh issue list --state open --label <label> --json number,title,labels,assignees`.
- **Sub-issues**: `gh issue create --parent <parent> ...`, or `gh issue edit <parent> --add-sub-issue <child>`
  (newer `gh`). Without sub-issues, put `Part of #<parent>` at the top of the child body.
- **Order**: `Blocked by #<n>` in the body of the issue that waits.
- **Comment**: `gh issue comment <number> --body "..."`
- **Labels**: `gh issue edit <number> --add-label "..."` / `--remove-label "..."`
- **Claim**: `gh issue edit <number> --add-assignee @me`
- **Close**: through the PR (`Closes #<n>`), or `gh issue close <number> --comment "..."`.

Infer the repo from `git remote -v`; `gh` does this automatically inside a clone.

## Pull requests as a triage surface

**PRs as a request surface: no.** _(Set to `yes` if this repo treats external PRs as feature requests; `/triage` reads this flag.)_

## Board and roles

All open issues live on the project board
[poc-pos](https://github.com/users/radityaaa43/projects/1), linked to this repo. Each card has a
**Status** and an **Effort**. Four roles move the cards, and a role can be a person or an agent
session. Until a role is staffed, the maintainer names who covers it.

### Delegation

Since 2026-11-25 the maintainer delegates day-to-day decisions by track:

- **Business / PM decides the business track**: scope, behaviour, edge cases, acceptance criteria,
  priorities and backlog item status. It acts as the requester for *What good looks like*, records
  each decision with its date and "Business/PM, delegated by the maintainer", and merges `docs` PRs
  that only touch `backlog/`.
- **The Tech Lead decides the technical track**: architecture, database, implementation, skills and
  tooling, and code review and merge.
- **Still the maintainer's call**: irreversible or outward-facing actions outside the repo
  (production data, deploys, paid services, credentials, legal), and weakening a CI gate or a Never
  rule. The maintainer can overrule any decision.

When a question spans both tracks, Business / PM and the Tech Lead agree it between them and record
it on the issue.

| Role | Does |
|---|---|
| **Maintainer** (`@radityaaa43`) | Makes business decisions (through Business/PM), is the Standards owner, can overrule any role or card |
| **Business / PM** (also scrum master) | Analyses requests and interviews the maintainer, writes `backlog/REQ-*`, the PRD issue and its tickets as sub-issues, keeps the board current, checks feat PRs against their criteria, fills in *Delivered by* |
| **Tech Lead** | Writes the technical plan (architecture, database and migrations, backend, frontend, risks), sets Effort, picks the developer, keeps parallel work from colliding, reviews and merges PRs |
| **Developer** | Builds a planned ticket test-first, verifies it, opens the PR and fixes review findings |

| Status | Owner | A card moves on when | Moved by |
|---|---|---|---|
| **Backlog** | Business / PM | It meets the business Definition of Ready (below) | Business / PM → Planning |
| **Planning** | Tech Lead | The plan is a comment on the issue, Effort is set, the plan names the developer, and `ready-for-agent` (or `ready-for-human`) is set | Tech Lead → Todo |
| **Todo** | Developer | The developer starts on a branch | Developer → In progress |
| **In progress** | Developer | A PR with `Closes #<n>` is open and CI is green | Developer → In review |
| **In review** | Tech Lead, then Business / PM | The Tech Lead's technical review approved, then (for a `feat` PR or any PR with criteria) Business / PM's review passed, and CI is green | Tech Lead merges; Business / PM → Done |
| **Done** | Business / PM | — | Business / PM, once every review passed and the PR is merged ([`handoff.md`](handoff.md) → 3) |

New issues land in Backlog. Dependencies stay as `Blocked by #<n>` and sub-issues; there is no
Blocked column.

**Business Definition of Ready.** *What good looks like* holds the requester's answers with no `?`
left; exactly one `module:*` label; every Done-when item is tagged with its criterion (or
`Backlog: none — <reason>`); the REQ item is on `main` with Status `ready`.

**Technical plan.** A comment on the issue before coding, sized to the ticket: what changes in the
database, backend and frontend, the files and hotspot files touched, migration order, risks, and the
tests per criterion, ending with `Builder: <developer>` (e.g. `Builder: dev1`). Large decisions also
get an ADR in `docs/adr/`. The exact formats for the plan and the assignment message, the
developer's delivery with evidence, and the Tech Lead's review checklist are in
[`handoff.md`](handoff.md).

**One GitHub login.** While every session uses the same account, the assignee can't tell developers
apart and GitHub refuses a self-requested review. So the `Builder:` line in the plan names who builds
it (the assignee still marks the issue as claimed), review is requested by message, and the Tech
Lead's `/code-review` result posted with `gh pr comment` is its approval (`CONTRIBUTING.md` → Single
maintainer). Drop this once each role has its own account.

**Worktrees.** A developer works in their own git worktree with its own `npm ci`. Run PHP for a
worktree as `tests/e2e/README.md` → *Running compose from a git worktree* describes: `docker compose
exec -T app` from a worktree runs against the main checkout's code, not the worktree's.

**Effort.** The Tech Lead sets the Effort field (`low`, `medium`, `high`, `xhigh`, `max`, the same
scale as the `AI used` line). A developer works on one ticket at a time, except that tickets whose
Effort is `low` may run in parallel, up to three at once, as long as they don't touch the same
files.

**Moving a card**: `gh project item-list 1 --owner radityaaa43 --format json` gives the item IDs, then
`gh project item-edit --project-id PVT_kwHOA9U6R84BtLzZ --id <item> --field-id
PVTSSF_lAHOA9U6R84BtLzZzhrWdvw --single-select-option-id <option>`. Status options: Backlog
`92c46c55`, Planning `a8b028cf`, Todo `bcf512e5`, In progress `beac7de7`, In review `d3a2e75a`,
Done `ba2a9002`. Effort is field `PVTSSF_lAHOA9U6R84BtLzZzhrWmx4`, options low `925e9424`, medium
`d05ec75c`, high `6ccd014c`, xhigh `1cfdc60d`, max `98931aeb`. `gh` needs the `project` scope
(`gh auth refresh -s project`).
