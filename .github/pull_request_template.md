<!-- One `Closes #n` per line for every issue this PR finishes (`Refs` does not close issues). -->
Closes #

## In plain words

<!-- Two lines for a reader who hasn't seen the issue: what changes for whom, and why. -->

## Summary

<!-- The smallest visual that makes the change clear: a diff-sketch, call tree, file tree, or
Mermaid diagram, plus 1–2 sentences. Use GLOSSARY.md terms. -->

## Manual test

<!-- Required: what you tried by hand (following the issue's "What good looks like"), as which
role, and what you saw. UI changes: a Before | After table of screenshots, attached with
`gh pr create --attach` (skill `github-upload-image-to-pr`). -->


## Checklist

<!-- CI's "PR checklist" checks Closes, Manual test, Docs, E2E on `feat` PRs, an e2e tag or exception per Backlog criterion, and the AI used and Backlog format. See AGENTS.md. -->

- **AI used:** <!-- "<agent>, <model>, effort <low|medium|high|xhigh|max|unknown>" per agent that wrote this PR, separated by ";", e.g. "Claude Code, claude-opus-5-5, effort high", or "none" -->
- **Backlog:** <!-- IDs and acceptance criteria this PR covers, or "none — <reason>" -->
- **Docs:** <!-- files updated (README, GLOSSARY, docs/adr, AGENTS.md, ...), or "n/a — reason" -->
- **Tests added/updated:** <!-- test files, or "none — reason" (e.g. no behavior change) -->
- **E2E:** <!-- `feat` PRs: tests/e2e/features/<NN-feature>/ added or updated (`npm run e2e:new`), or "none — <reason>". Each Backlog criterion needs a test tagged @REQ-<n>-AC-<n>, or "AC-<n> none — <reason>" here (several: separate with ";"). Other PRs: optional -->
- **Hotspot files touched:** <!-- which of the hotspot files in AGENTS.md, or "none" -->
- **CI run:** <!-- link to the green run; unrelated failures linked to their issue -->
