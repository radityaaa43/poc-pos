# AGENTS.md

Rules for everyone changing this repo: developers and AI agents (Claude Code, Codex, Cursor, ...).
Where an agent's defaults conflict with this file, this file wins. Kept to about one page: the why,
the ownership table and the full parallel-work rules are in [`CONTRIBUTING.md`](CONTRIBUTING.md).

These gates are firm: issue → branch → PR, CI green, a manual test, and docs that match the code.
Everything else is a default. When a change redesigns a flow, rewrite or delete the tests, glossary
terms and docs it makes obsolete in the same PR.

- Run the app and set up the environment: [`README.md`](README.md) · project detail (inventory
  model, gotchas, test conventions, API abilities): [`docs/agents/project-guide.md`](docs/agents/project-guide.md)
- How code is written: [`CODING_STANDARDS.md`](CODING_STANDARDS.md) · how PRs are reviewed: [`REVIEW.md`](REVIEW.md)
- Domain vocabulary: [`GLOSSARY.md`](GLOSSARY.md) · decisions: [`docs/adr/`](docs/adr/)

## Workflow: issue → branch → test → review → PR

Nothing goes directly to `main`. Workflow skills come from
[mattpocock/skills](https://github.com/mattpocock/skills) (setup: `README.md` → Agent setup).

| Step | Do | Skill |
|---|---|---|
| 1. Align | Business / PM: get the requirement unambiguous before writing anything | `/grill-with-docs` |
| 2. Issue | Business / PM: publish the spec (PRD) as an issue; split it into tickets | `/to-spec`, `/to-tickets` |
| 2b. Plan | Tech Lead: technical plan on the issue, Effort, `Builder:`, then `ready-for-*` | `superpowers:writing-plans` |
| 3. Branch | `git switch main && git pull && git switch -c <type>/<issue>-<slug>` | — |
| 4. Build | Test-first, in thin vertical slices | `/implement` → `/tdd` |
| 5. Verify | Static checks and tests locally, then test the change by hand | — |
| 6. Review | Review the diff against the issue, the standards and `REVIEW.md` | `/code-review` |
| 7. PR | A PR that closes the issue, with manual test, docs, and green CI | `/pr` |

For a `ready-for-agent` issue, `/ship-issue <n>` runs steps 3–7 in order.

Four roles carry an issue across the project board (Backlog → Planning → Todo → In progress → In
review → Done): **Business / PM** (steps 1–2 and the criteria check), **Tech Lead** (step 2b, review
and merge), **Developer** (steps 3–7) and the **maintainer** (decides, owns the Standards, overrules).
Columns, Definition of Ready and Effort: [`docs/agents/issue-tracker.md` → Board and roles](docs/agents/issue-tracker.md#board-and-roles).

Optional helpers inside a step (they never replace one): `superpowers:brainstorming` in step 1 for
large features, `superpowers:writing-plans` in step 4 for multi-step issues, `ponytail-review` in
step 6 after `/code-review`. Default style is `ponytail`: the smallest change that solves the
issue, stdlib and native features before new libraries.

### Issues

- Title: `<type>(<scope>): <summary>`, the same shape as commits; the type becomes the branch type
  ([`docs/agents/issue-tracker.md`](docs/agents/issue-tracker.md) → Conventions).
- Sections: Background, **What good looks like**, Scope (checklist), Done-when (checkable, incl. the
  tests that must pass). One issue = one independently reviewable change. Exactly one
  `module:*` label (modules and owners: [`CONTRIBUTING.md` → Ownership](CONTRIBUTING.md#ownership)).
- Requirements come from the backlog/ folder in this repo: one file per requirement, backlog/REQ-<n>-<slug>.md, with AC-<n> acceptance criteria. Issues and PRs carry a `Backlog:` line (IDs and
  acceptance-criteria tags, or `none — <reason>`; CI checks the format). When the backlog
  disagrees with the code, the design or the issue, stop and ask the requester; never pick a side.
- New issues start `needs-triage`; developers only pick up `ready-for-agent`. Only the Tech Lead (when
  the plan is on the issue) or the maintainer sets `ready-for-*`; no other agent does it on its own.
- **Humans steer, agents ask.** An agent never fills in *What good looks like* itself: it interviews
  the requester (`/grill-with-docs`, one question at a time) and records the answers to: (1) who uses
  it, and when; (2) what they do and see when it works; (3) the source of truth and who agreed it;
  (4) unusual cases and errors, and what the user sees; (5) where the data comes from. Anything
  unanswered stays `?` and the issue gets `needs-info`. Done-when is derived from the answers.
  While the maintainer delegates ([`docs/agents/issue-tracker.md` → Delegation](docs/agents/issue-tracker.md#delegation)),
  Business / PM is the requester for business questions and the Tech Lead for technical ones.

### Branches, commits, PRs

- Branch `<type>/<issue>-<slug>`, `<type>` ∈ `feat fix refactor docs test chore`. One branch per issue.
- **Bugs**: reproduce first with a failing test (`/diagnosing-bugs`), then fix. The fix must not
  edit or weaken that test; if the test itself is wrong, say why in the PR.
- **UI work**: after the manual test, take before/after screenshots (dummy data, as the affected
  role) and attach them to the PR (`gh pr create --attach`, skill `github-upload-image-to-pr`).
  Never commit them.
- **Commits**: [Conventional Commits](https://www.conventionalcommits.org/) (`feat(scope): ...`),
  body explains *why*, ends with `Refs #<issue>`. No AI attribution: no `Co-Authored-By` trailer for
  an agent, no "Generated with ..." footer. Instead, issues and PRs say on their **AI used** line
  which agent, model and effort level did the work: `<agent>, <model>, effort <level>` per agent,
  joined by `;` (level `low`…`max`, or `unknown`), or `none`. CI checks the format.
- **PRs** follow `.github/pull_request_template.md`: `Closes #<n>` per finished issue, one per line
  (`Refs` closes nothing), the manual test, and the docs updated. CI's *PR checklist* enforces it.
  The Tech Lead reviews and merges; a `feat` PR also needs Business / PM's comment that its criteria
  are met. While every session shares one GitHub login, ask for review by message, and the Tech
  Lead's `/code-review` result posted as a PR comment is its approval. An Owner may merge their own `chore`, `docs`, `ci`, `test` PR
  once CI is green; every other PR needs another reviewer's approval.
  Never push to `main` or force-push a reviewed branch. After any
  post-review change, wait for green CI again.
- **E2E**: a `feat` PR adds or updates its feature's e2e folder (`npm run e2e:new -- <NN-feature>`;
  one folder per feature, one file per step; see `tests/e2e/README.md`) or says
  `E2E: none — <reason>`. Every PR whose `Backlog:` line names criteria has, per criterion, an e2e
  test tagged `@REQ-<n>-AC-<n>` or `E2E: AC-<n> none — <reason>`. CI's *PR checklist* enforces both.
- **Standards files** (`AGENTS.md`, `CLAUDE.md`, `CODING_STANDARDS.md`, `REVIEW.md`,
  `CONTRIBUTING.md`, `.agents/skills/`, `.claude/`, `.github/`) need a Standards owner's
  (`@radityaaa43`) approval on the latest commit; CI's *Standards approval* enforces it.
  Change a standard in its own issue, never inside an unrelated PR.

### Working in parallel

- Finish or hand back your assigned issues before claiming more (`gh issue list --assignee @me`;
  agents check their human's). Exception: tickets the Tech Lead rated Effort `low` may run in
  parallel, up to three, when they touch different files. Claim before starting (`gh issue edit <n> --add-assignee @me`); never
  start on someone else's issue.
- Small, short-lived PRs: aim under 300 counted lines; over 2000 needs
  another person's approval, over 3000 fails CI unless an Owner adds
  `large-pr`. Code files stay under 1000 lines. No drive-by
  refactors, renames or reformatting outside the issue.
- Sync with `main` daily and before the PR: `git fetch && git rebase origin/main`
  on your own unreviewed branch (`--force-with-lease` is fine); after review, merge it in instead.
- Hotspot files (`routes/web.php`, `routes/api.php`, `resources/js/Utils/Menu.jsx`, `database/seeders/PermissionSeeder.php`, `database/seeders/RoleSeeder.php`, `resources/js/i18n/locales/en.json`, `resources/js/i18n/locales/id.json`, `lang/`, `ARCHITECTURE.md`): append rather than reorder, and name them in the PR.
- Migrations: if `main` has a newer one than yours, rename yours to a fresh
  timestamp before merging. Never rename or edit a merged migration.
- Lockfiles: never hand-edit conflicts; take the default branch's, re-run the package manager.
- Needs an unmerged PR? Write `Blocked by #<n>` in the issue and branch after it merges.

## Verify: run the checks, test by hand

```bash
# laravel
docker compose exec -T app vendor/bin/pint --test --diff=main
docker compose exec -T app php artisan test --compact
# CI also runs the suite on MySQL 8.4 (Backend job)
# frontend
npm test
npm run build
# e2e (Playwright; setup and adding a feature: tests/e2e/README.md)
npm run e2e:up && npm run test:e2e
```

- **CI is the gate.** Merge only with CI green; link the run in the PR.
- **Test it by hand** as the affected role(s) before the PR, and write what you tried and saw under
  *Manual test*.
- **Tests for what the change touches**, derived from Done-when: add tests for new behavior, update
  them for changed behavior, delete them for removed behavior. No behavior change → no new tests,
  and the PR says so.
- **Unrelated failure**: open the log, re-run once; if it fails again, check the default branch. If it
  fails there too, link or open an issue and note it in the PR. Never a quiet skip; every skip
  needs a reason.
- Stop any servers you started for testing.

## Documentation: leave the repo workable for the next person

Anyone must be able to pick up any area from the repo alone, without a chat log or agent memory.

- Update in the **same PR** every doc the change makes stale (`README.md`, `GLOSSARY.md`,
  `docs/adr/`, the Standards files, ...). The PR's **Docs** line names them, or `n/a — <reason>`.
- New env var, script, service or dependency → document setup where the next person looks
  (usually `README.md`) and add it to the `*.example` env file.
- Non-obvious code (a workaround, an external constraint) gets a short *why* comment.
- **Same mistake twice → write it down.** When an agent repeats a mistake or a review finding keeps
  coming back, add the rule here, to `CODING_STANDARDS.md`, or to `REVIEW.md` (a Standards change).

## Never

In Claude Code, `.claude/hooks/guard.py` enforces these (it blocks the edit or command, or asks
first) and `.claude/settings.json` denies reads of secret files. Other agents and humans aren't
covered by the hook; review and CI still apply.

- Commit secrets: `.env*` (except `*.example`), keys and certificates (`*.pem`, `*.key`), credentials files,
  database dumps (`*.sql`, `*.sql.gz`, `*.dump`).
- Edit `storage/`: Runtime logs and uploads; may hold customer data. Never edit or commit by hand.
- Edit `public/build/`: Vite build output; regenerate with npm run build, never edit.
- Edit a migration that is already on the default branch (add a new one instead): `database/migrations/`.
- Put secrets or personal data in issues, PRs, commits, logs, test fixtures, or prompts to
  external services.
- Run destructive operations (database resets, deleting volumes or cloud resources) anywhere but
  your own local environment.
- Add dependencies or change infrastructure outside the issue's scope without approval.

## Project rules

Detail and the reasons: [`docs/agents/project-guide.md`](docs/agents/project-guide.md).

- Local PHP and MySQL run in Docker: prefix `php`/`composer` with `docker compose exec -T app`.
- Stock: `product_warehouse.stock` is operational; update `products.stock` in the same
  `DB::transaction`, with `lockForUpdate()` on the affected rows.
- Never run the demo seeder (`DemoSeeder`, `seed:demo`) against production; it rebuilds demo data.
- npm only, with the committed `package-lock.json`; no other lockfiles.
- Tests run on in-memory SQLite: no MySQL-only features; set `tax_rate=0` on test products.
- Keep Sanctum abilities and Spatie permissions aligned when changing API authorization; API tests
  need explicit token abilities (`Sanctum::actingAs($user, ['*'])`).

## Language

Everything in the repo or on the issue tracker (code, comments, docs, issues, PRs, commits) is in
English, except requirement files in `backlog/`, which may be in Indonesian. Domain terms stay as
defined in `GLOSSARY.md`.

## Agent skills

### Issue tracker

GitHub Issues on `radityaaa43/poc-pos` via `gh`. See `docs/agents/issue-tracker.md`.

### Triage labels

`needs-triage`, `needs-info`, `ready-for-agent`, `ready-for-human`, `wontfix`. See `docs/agents/triage-labels.md`.

### Domain docs

Single context: `GLOSSARY.md` and `docs/adr/`. See `docs/agents/domain.md`.

### Repo skills

In-repo skills live in `.agents/skills/` (list: `.agents/skills/README.md`). They run inside a
workflow step and never replace one.
