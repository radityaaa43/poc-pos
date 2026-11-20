# Contributing

The rules every change follows are in [`AGENTS.md`](AGENTS.md), kept to about a page so agents
follow them reliably. This file holds the detail behind them: who owns what, how Standards changes
work, the full parallel-work rules, and why some rules exist.

## Why the issue questions

The five *What good looks like* questions (AGENTS.md → Issues) exist because the usual cause of
rework is not bad code but a requirement nobody pinned down: a button that looks dead, a flow
rebuilt after someone shows the real process, an edge case found only in manual testing, two
similar domain terms mixed up in data. Asking first is cheaper than rebuilding. In this repo the
classic case is stock: whether a quantity is per warehouse (`product_warehouse.stock`) or global
(`products.stock`), and whether a recipe product deducts its own stock or its raw materials'.
Add this project's own examples (with issue links) as they happen.

## Requirements and backlog

Requirements come from **the backlog/ folder in this repo: one file per requirement, backlog/AKAR-<n>-<slug>.md, with AC-<n> acceptance criteria**. It may change, so read it fresh each time and
name the date you read it.

1. **Read the item.** Take its ID, user story, notes and acceptance criteria (`AC-nn`).
2. **Check what exists.** Compare the item with the default branch (and the design, if any): Built,
   Partial, Differs or Missing. Only the gap becomes work; a difference between the code and the
   design is a question for the requester, not an automatic fix.
3. **Ask, don't guess.** Interview the requester (`/grill-with-docs`) for whatever the item leaves
   open. Unclear or empty criteria mean `needs-info` until answered.
4. **File the issue** (`/to-spec`). Put the ID(s) on the `Backlog:` line and tag each
   Done-when item with the criterion it covers (`- [ ] [AC-01] ...`). Only IDs, numbers and short
   names go into the tracker: no copied confidential text, no personal data.
5. **Split** (`/to-tickets`) into thin end-to-end slices (all layers plus tests for one behavior)
   that merge independently, not into a backend ticket and a frontend ticket. Mark order with
   `Blocked by #<n>`.
6. **Triage, then build.** Exactly one `module:*` label per ticket; `ready-for-agent` tickets go through `/ship-issue <n>`.

**The backlog disagrees with the code, the design or the issue?** The backlog is the source of
truth and only its owner changes it. Whoever finds the mismatch stops and tells the requester the
ID, the criterion and what differs, with evidence. The issue carries `needs-info` until then.

**CI checks the format only**: an issue or PR without a `Backlog:` line is told the expected
format. Work not from the backlog writes `Backlog: none — <reason>`. Whether the criteria are met is
the reviewer's check (`REVIEW.md`).

## Getting started

Setup (Docker or plain PHP), the first-install `/setup` wizard and demo data:
[`docs/agents/project-guide.md`](docs/agents/project-guide.md) → Quick start. Before a PR, run the
commands in [`AGENTS.md` → Verify](AGENTS.md#verify-run-the-checks-test-by-hand).

## Branches

`main` is the only long-lived branch and every push to it deploys to the VPS, so nothing is pushed
to it directly. Branch from `main` as `<type>/<issue>-<slug>` and open the PR back into `main`. The
upstream Git Flow (`development`, `release/*`) is not used here (ADR 0001). Releases are tags on
`main`: [`docs/agents/project-guide.md` → Release process](docs/agents/project-guide.md#release-process).

## Ownership

Every area of the product has an Owner and backups. The Owner decides scope questions in their
module and reviews its PRs. When the Owner is away or wrote the PR, ask the Backup.

| Label | Module | Scope | Paths | Owner | Backup |
|---|---|---|---|---|---|
| `module:platform` | Platform | Auth, users/roles/permissions, outlets, settings, setup wizard, audit log, reports, API, layouts, shared components, migrations and config | `app/`, `resources/`, `routes/`, `database/`, `tests/`, `config/`, `bootstrap/`, `lang/`, `public/`, `docker/`, `docs/`, `backlog/` | `@radityaaa43` | — |
| `module:sales` | Sales / POS | Cashier POS, cart, checkout, transactions, sales returns, cashier shifts, tenders/payments, receipts, receivables | `**/*Checkout*`, `**/*Transaction*`, `**/*Sale*`, `**/*CashierShift*`, `**/*Cart*`, `**/*ThermalPrint*`, `**/*Tender*`, `**/*Payments*`, `**/*PaymentSetting*`, `**/*BankAccount*`, `**/*Receivable*`, `**/*Aging*`, `**/*Pos*`, `**/*POS*`, `**/*Receipt*` | `@radityaaa43` | — |
| `module:dine-in` | Dine-in | Dine menu, table orders, dine areas and tables | `**/*Dine*` | `@radityaaa43` | — |
| `module:inventory` | Inventory | Products, recipes/composite products, units and conversions, categories, warehouses, stock mutations/opname/transfers, batches, reorder | `**/*Product*`, `**/*Stock*`, `**/*Batch*`, `**/*UnitConversion*`, `**/*UnitController*`, `**/Units/`, `**/*Categor*`, `**/*Warehouse*`, `**/*Recipe*`, `**/*Reorder*`, `**/*Barcode*` | `@radityaaa43` | — |
| `module:purchasing` | Purchasing | Suppliers, purchase orders, goods receiving, supplier returns, payables | `**/*Supplier*`, `**/*PurchaseOrder*`, `**/*GoodsReceiving*`, `**/*Payable*` | `@radityaaa43` | — |
| `module:pricing-crm` | Pricing & CRM | Price lists, pricing rules, discounts and approvals, tax, customers, members, vouchers, loyalty, CRM campaigns/reminders/segments | `**/*Pric*`, `**/*Crm*`, `**/*Customer*`, `**/*Loyalty*`, `**/*Member*`, `**/*Voucher*`, `**/*Discount*`, `**/*Tax*` | `@radityaaa43` | — |
| `module:whatsapp-service` | WhatsApp service | Node WhatsApp gateway and the Laravel WhatsApp integration | `whatsapp-service/`, `**/*WhatsApp*` | `@radityaaa43` | — |

- **Every issue gets exactly one `module:*` label** at triage: the module whose behavior its
  Done-when changes. Work that spans modules takes the main one and names the others in Background.
- **Ask the module's Owner for review** (`gh pr edit <n> --add-reviewer <handle>`). A change that also
  touches another module's code mentions that module's Owner in the PR.
- Anyone may work on any module; claiming an issue still applies. Ownership is about who decides
  and reviews, not who is allowed to code.
- Changes to this table are Standards changes (below).

### Standards

The repo's basic standards are owned by `@radityaaa43`:

- **Standards files**: `AGENTS.md`, `CLAUDE.md`, `CODING_STANDARDS.md`, `REVIEW.md`,
  `CONTRIBUTING.md`, `.agents/skills/`, everything under `.claude/` and everything under `.github/`
  (templates, `CODEOWNERS`, `governance.json`, workflows and their scripts).
- A PR that changes any of them needs a Standards owner's approval on its latest commit before it
  merges, even when the module Owner has reviewed it. A Standards owner's own Standards PRs need no
  second approval.
- CI's *Standards approval* check fails such a PR until then. After approving, re-run the failed
  check. Never merge over it.
- To change a standard, open an issue and a PR like any other change. Don't weaken a rule inside an
  unrelated PR.

### Single maintainer

While `@radityaaa43` is the only maintainer, the "another reviewer's approval" for `feat`, `fix` and
`refactor` PRs is an agent review: run `/code-review` (and optionally `ponytail-review`) on the
final diff, resolve or answer every finding, and link or paste the result in the PR before merging.
Add a human reviewer as soon as a second person joins.

### Enforcement on GitHub

The repository is public on GitHub Free, so a branch ruleset on `main` can enforce what this file
asks: PR required, required checks green, no force-push or deletion. It is created with
`setup_github.py --apply-ruleset` (code-governance skill) after the maintainer agrees. Until the ruleset exists, nothing on the server stops a
direct push to `main`: the Claude Code guard hook blocks it for agents, and for humans it rests on
this file.

## Working in parallel

Several people and agents work at the same time. These rules prevent duplicate work and painful
merges:

- **Check what's assigned to you first** (`gh issue list --assignee @me`) and finish or hand those
  back before claiming more. Agents check the assignee of the human they work for.
- **Claim before you start** (`gh issue edit <n> --add-assignee @me`). Never start on an issue
  someone else is assigned to; pick another or ask.
- **Prefer small, short-lived PRs**, merged within days. A large redesign PR is fine when
  splitting it would be artificial; say so in the PR.
- **Sync daily and right before opening the PR**: `git fetch && git rebase origin/main`.
  `git push --force-with-lease` is fine on your *own* unreviewed branch; after review, merge the
  default branch in instead. A resolved conflict is a code change: let CI re-run.
- **Don't mix in unrelated changes**: no drive-by refactors, renames or reformatting outside the
  issue. Do them as their own issue.
- **Hotspot files** (`routes/web.php`, `routes/api.php`, `resources/js/Utils/Menu.jsx`, `database/seeders/PermissionSeeder.php`, `database/seeders/RoleSeeder.php`, `resources/js/i18n/locales/en.json`, `resources/js/i18n/locales/id.json`, `lang/`, `ARCHITECTURE.md`): prefer appending over reordering, and mention the edit in the
  PR so reviewers merge in a sensible order.
- **Migrations** (`database/migrations/`): when the default branch has a newer migration than yours,
  rename yours to a fresh timestamp before merging, so files sort in apply order. CI's *Migration
  order* check turns red when you need to. Never rename or edit a migration after it has merged.
- **Lockfiles**: never hand-edit conflict markers. Take the default branch's version, re-run the
  package manager and commit the result.
- **Dependent work**: if your issue needs another unmerged PR, write `Blocked by #<n>` in the issue
  and branch after it merges. Don't stack on someone's branch.

### PR size and file length

Reviews find fewer defects past a few hundred lines at a time, and long files cost agents context
on every turn.

- **PR size**, counted changed lines: aim under 300. Above 1000 the PR gets
  a comment and needs an approving review from someone other than its author; above
  2000 the check fails unless a module Owner adds `large-pr` after
  agreeing to review it whole. In Claude Code, the guard hook refuses `gh pr merge` on a PR over
  1000 lines without that approval.
- **Not counted:** lockfiles, generated and vendored files, migrations (`.github/governance.json` → `pr_size.exclude`).
- **File length:** a hand-written code file may not grow past 1000 lines; files
  already over it may shrink. Tests and generated files are exempt.
- **Start small:** an issue expected over ~1000 lines is split into tickets before anyone builds.

## CI

| Check | Blocks? | What it checks |
|---|---|---|
| CI/CD Pipeline → CI (Build) (`deploy.yml`) | yes | `composer validate`, Pint on changed PHP files (`--diff=origin/main`), `npm run build`, `php artisan test` on SQLite; a push to `main` then deploys to the VPS |
| PR checklist | yes | `Closes #`, Manual test, Docs, AI used, Backlog lines; no AI attribution |
| Issue checklist | comments | Required sections and lines on new issues |
| PR size / File length | above the fail limit | Counted changed lines; code files growing past the limit |
| Standards approval | yes | A Standards owner approved the latest commit when Standards files changed |
| Dependency check | no (warns) | Lists new direct dependencies for the reviewer |
| Migration order | yes | New migrations sort after the default branch's |
| Script and hook tests | yes | The governance scripts and the guard hook still work |

All limits and lists live in `.github/governance.json` (a Standards file).

## Development tips

- Set `tax_rate=0` on `Product::create` in tests so tax doesn't change `grand_total`.
- Run `php artisan migrate` when a newer module has no tables yet.
- More gotchas and test conventions: [`docs/agents/project-guide.md`](docs/agents/project-guide.md).

## Reporting bugs and requesting features

Open an issue with the **Bug** or **Feature** template. Features start from a requirement file in
`backlog/` ([`backlog/README.md`](backlog/README.md)).
