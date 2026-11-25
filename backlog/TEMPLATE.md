# REQ-<n>: <short title>

- **Status:** draft
- **Owner:** @radityaaa43
- **Module:** module:<name>
- **Updated:** YYYY-MM-DD

<!-- Every section is required. A section that truly doesn't apply says "n/a — <reason>".
     A REQ is `ready` only when every section is filled or n/a with a reason (README.md → Status). -->

## User story

As a <role (owner, branch manager, cashier, kitchen staff, central warehouse staff)>, I want
<capability>, so that <outcome>. One story per role that is affected.

## Context

Why this is needed, what happens today, and links to designs, issues or docs.

**Decisions** (each dated, with who decided):

1. <decision> (<who>, YYYY-MM-DD)

## Process

**As-is.** What each role does today, step by step, and where it goes wrong.

**To-be.** What each role does after this ships, step by step.

## Stakeholders

| Party | Role in the flow | What they care about | What they decide |
|---|---|---|---|
| | | | |

## Acceptance criteria

<!-- Given/When/Then, grouped by flow. Never renumber a criterion an issue or PR references. -->

### <flow>

- **AC-01:** Given <situation>, when <action>, then <observable result>.

<!-- Edge cases. Each one is covered by a criterion or marked n/a with a reason:
     - offline (POS queued sale)                - split payment
     - insufficient stock                       - cancellation before payment
     - cancellation or refund after payment     - shift close with open work
     - failed or expired payment                - permission denied (UI and server)
     - two users acting at the same time        - existing data on release (defaults, backfill) -->

## Business rules

- **Money:** when it counts as paid, receivables, cash difference, rounding.
- **Stock:** when it is taken or restored, which warehouse.
- **Permissions:** which steps need which permission (configurable per shop, never a hard-coded role).
- **Settings:** per shop or per outlet, default, who may change it.

## Non-functional requirements

- **Speed:** <e.g. shows within N seconds; report opens for one outlet and one month>.
- **Reliability:** behaviour when the connection drops or a service is down.
- **Security:** server-side permission checks, data visible only to the user's outlet.
- **Audit:** which changes are written to the audit log.
- **Language and accessibility:** Indonesian and English; touch targets and contrast where relevant.

## Data and integrations

- **Data:** where each value comes from, new fields, defaults for existing records.
- **Integrations:** payment gateways, WhatsApp, webhooks, or "none new".

## Success metrics

- **<metric>:** formula, data source, period and time zone, target or baseline.

## Out of scope

- ...

## Risks and assumptions

| Risk | Impact | Mitigation |
|---|---|---|
| | | |

- [assumption] ...

## Open questions

- **Q1:** <question> — decides: <who>. `?` until answered.

## Delivered by

<!-- Issues and PRs, filled in as work ships. -->
