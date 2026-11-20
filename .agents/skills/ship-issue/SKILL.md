---
name: ship-issue
description: Ship an issue in this repo end to end — use when asked to work on, pick up, implement or fix issue #<n>, or to take the next `ready-for-agent` issue.
---

# Ship an issue

Runs `AGENTS.md` → Workflow steps 3–7 in order, so every issue takes the same path. Each step ends
on its **Done when**; start the next step only once it holds.

1. **Ready.** List your human's open issues first (`gh issue list --assignee @me`) and finish those
   before claiming more. Then `gh issue view <n>`: it carries `ready-for-agent`, no other assignee,
   and filled *What good looks like* and *Done when*. A `?`, `needs-info` or missing section means
   the requirement is not settled: interview the requester with `/grill-with-docs` instead of
   guessing. An issue with IDs on its `Backlog:` line is checked against the backlog/ folder in this repo: one file per requirement, backlog/REQ-<n>-<slug>.md, with AC-<n> acceptance criteria
   first; a mismatch goes to your human to clarify, not into the code. Claim it:
   `gh issue edit <n> --add-assignee @me`. **Done when** the issue is ready and assigned.
2. **Branch.** `git fetch origin main && git switch -c <type>/<n>-<slug> origin/main`.
   **Done when** the branch starts at the current `origin/main`.
3. **Build** with `/tdd`, one Done-when item at a time: the test goes red first, then green. A bug
   starts with a failing reproduction (`/diagnosing-bugs`) that the fix leaves untouched.
   **Done when** every Done-when item has a passing test, or a written reason it needs none.
4. **Verify.** Run the checks in `AGENTS.md` → Verify for each area you changed:

   ```bash
   # laravel
   docker compose exec -T app vendor/bin/pint --test --diff=main
   docker compose exec -T app php artisan test --compact
   # frontend
   npm test
   npm run build
   ```

   Then test by hand as the affected role. UI changes get before/after screenshots
   (`github-upload-image-to-pr`). **Done when** the checks pass and you can write what you tried and saw.
5. **Docs.** Update every doc the change makes stale, in this branch (`AGENTS.md` → Documentation).
   **Done when** the PR's Docs line can name the files, or `n/a — <reason>`.
6. **Review** with `/code-review` against the issue, `CODING_STANDARDS.md` and `REVIEW.md`. Fix every
   🔴. **Done when** no 🔴 remains.
7. **PR** with `/pr` and `.github/pull_request_template.md`: *In plain words*, `Closes #<n>`, Manual
   test, Docs, AI used, Backlog, hotspot files. Commit with Conventional Commits ending
   `Refs #<n>`, no AI attribution. Request review from the module Owner (`CONTRIBUTING.md` →
   Ownership) and paste the PR link in chat. **Done when** the PR is open with a reviewer requested.
8. **CI.** Watch `gh pr checks <n> --watch` in the background and report the result unasked, with
   the run link added to the PR. An unrelated failure follows `AGENTS.md` → Verify. Merging follows
   `AGENTS.md` → PRs. **Done when** CI is green and linked.
