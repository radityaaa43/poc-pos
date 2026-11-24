---
name: review-open-prs
description: Review open PRs in this repo before they merge — use when asked to review or go through open PRs, one person's PRs, or a PR number.
---

# Review open PRs

You draft verdicts and review comments; the user decides what to post and what merges. Reading is
free; writing to GitHub waits for the user's go-ahead.

1. **State first.** List the PRs (`gh pr list --state open`, or the numbers given). For each run
   `gh pr view <n> --json state,mergedAt,isDraft,mergeable,reviewDecision,statusCheckRollup,closingIssuesReferences,files,author,labels`.
   A merged or closed PR gets one line in the report and nothing else. Drafts are skipped unless
   asked. **Done when** every PR has a current state.
2. **Checklist** for each open, non-draft PR. Mark every item pass or fail, with evidence:
   - **Issue:** `Closes #<n>` is present, and the diff does what the issue's Done-when asks, nothing
     beyond it.
   - **Backlog:** the IDs on the `Backlog:` line match the issue, and each listed criterion is met
     (re-read the backlog/ folder in this repo: one file per requirement, backlog/REQ-<n>-<slug>.md, with AC-<n> acceptance criteria; name the date).
   - **Domain:** names and rules match `GLOSSARY.md` and the ADRs. Mark an assumption *Unconfirmed*
     and name who decides (the module Owner).
   - **Tests:** changed behavior has a test; a bug fix leaves its failing test untouched.
   - **Body:** *In plain words*, Manual test with role and result, Docs line, AI used line,
     screenshots for UI changes, CI run link.
   - **CI:** checks green; *Standards approval* present when Standards files changed.
   - **Size:** over 3000 counted lines without `large-pr` → ask for a split.
   - **Mergeable:** clean against `main`; any migration is newer than the default branch's latest.
   - **`REVIEW.md`:** each finding with its severity.

   **Done when** every item is marked for every PR.
3. **Report** one card per PR: the full PR URL, author, verdict (*Ready*, *Needs changes*, or
   *Blocked on <person>*), the 🔴 findings, who may merge under `AGENTS.md` → PRs, and a draft
   review comment in plain language. **Done when** every open, non-draft PR has a verdict.
4. **Act on request.** Post a review (`gh pr review <n> --comment` or `--request-changes`), request
   a reviewer, or merge: re-run step 1 for that PR, then `gh pr merge <n> --merge --delete-branch`.
