# Agent skills

Skills that live in this repo. Each one is a folder with a `SKILL.md`. Claude Code reads the
pointer of the same name in `.claude/skills/`; other agents read this folder directly. The workflow
skills (`/grill-with-docs`, `/to-spec`, `/to-tickets`, `/implement`, `/tdd`, `/diagnosing-bugs`,
`/code-review`, `/pr`, ...) are not here: they come from the mattpocock/skills plugin
(`README.md` → Agent setup).

## In-house

| Skill | Use for |
|---|---|
| `ship-issue` | Take a `ready-for-agent` issue through `AGENTS.md` workflow steps 3–7 in order: claim, branch, `/tdd`, verify, docs, `/code-review`, `/pr`, CI |
| `review-open-prs` | Review open PRs against their issue, `GLOSSARY.md`, tests, the PR body and CI; drafts a verdict and comment per PR |
| `github-upload-image-to-pr` | Attach before/after screenshots or recordings to a PR or issue with `gh --attach` |

## Adding a skill

1. Open a Standards issue (skills are Standards files).
2. Write `.agents/skills/<name>/SKILL.md` (frontmatter `name`, `description` saying *when* to use
   it) and a pointer `.claude/skills/<name>/SKILL.md` with the same frontmatter and one line:
   "Full content lives at `.agents/skills/<name>/SKILL.md`; read that file now."
3. Add a row above. A skill helps inside a workflow step; it never replaces one.

## Vendoring third-party skills

Copy a third-party skill only when it adds something the plugins don't. Then:

- Pin it: record the source repo, commit SHA, version and date here, and keep its license file.
- Read every `SKILL.md` and reference first. Reject anything that ships a script, an install step,
  a network call or its own workflow router that would compete with `AGENTS.md`.
- Mark it `disable-model-invocation: true` unless it should load on its own.
- Keep a "Not vendored, and why" table, so the next person doesn't re-evaluate the same skills.
