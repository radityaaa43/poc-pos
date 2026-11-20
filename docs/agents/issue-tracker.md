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
