@AGENTS.md

# CLAUDE.md

Behavioral guidelines to reduce common LLM coding mistakes. Merge with project-specific instructions as needed.

**Tradeoff:** These guidelines bias toward caution over speed. For trivial tasks, use judgment.

## 1. Think Before Coding

**Don't assume. Don't hide confusion. Surface tradeoffs.**

Before implementing:
- State your assumptions explicitly. If uncertain, ask.
- If multiple interpretations exist, present them - don't pick silently.
- If a simpler approach exists, say so. Push back when warranted.
- If something is unclear, stop. Name what's confusing. Ask.

## 2. Simplicity First

**Minimum code that solves the problem. Nothing speculative.**

- No features beyond what was asked.
- No abstractions for single-use code.
- No "flexibility" or "configurability" that wasn't requested.
- No error handling for impossible scenarios.
- If you write 200 lines and it could be 50, rewrite it.

Ask yourself: "Would a senior engineer say this is overcomplicated?" If yes, simplify.

## 3. Surgical Changes

**Touch only what you must. Clean up only your own mess.**

When editing existing code:
- Don't "improve" adjacent code, comments, or formatting.
- Don't refactor things that aren't broken.
- Match existing style, even if you'd do it differently.
- If you notice unrelated dead code, mention it - don't delete it.

When your changes create orphans:
- Remove imports/variables/functions that YOUR changes made unused.
- Don't remove pre-existing dead code unless asked.

The test: Every changed line should trace directly to the user's request.

## 4. Goal-Driven Execution

**Define success criteria. Loop until verified.**

Transform tasks into verifiable goals:
- "Add validation" → "Write tests for invalid inputs, then make them pass"
- "Fix the bug" → "Write a test that reproduces it, then make it pass"
- "Refactor X" → "Ensure tests pass before and after"

For multi-step tasks, state a brief plan:
```
1. [Step] → verify: [check]
2. [Step] → verify: [check]
3. [Step] → verify: [check]
```

Strong success criteria let you loop independently. Weak criteria ("make it work") require constant clarification.

## 5. Use the Project Skills

**Load the skill for the area before you write or review code there.** Project skills live in
`.claude/skills/` (mirrored in `.agents/skills/`, versions pinned in `skills-lock.json`). They run
inside an AGENTS.md workflow step and never replace one.

| When you touch | Load |
|---|---|
| Controllers, models, services, Form Requests, jobs, Eloquent queries | `laravel-best-practices`, `laravel-specialist` |
| Auth, Sanctum tokens, input handling, file uploads, webhooks | `laravel-security` |
| Roles, permissions, policies (Spatie) | `laravel-permission-development` |
| PHP tests (`tests/`) | `laravel-tdd` |
| Migrations, indexes, slow queries, stock locking | `mysql` |
| Inertia pages, `useForm`, shared props, layouts | `laravel-inertia-react` |
| React components and hooks | `vercel-react-best-practices` |
| Frontend tests (`*.test.js`, `*.test.jsx`) | `vitest` |
| E2E tests (`tests/e2e/`, Playwright) | `playwright-best-practices` |
| An ADR in `docs/adr/`, or the why of a design choice | `documentation-and-adrs` |
| New or reshaped UI: layout, colour, type, spacing | `ui-ux-pro-max`, `frontend-design` |
| Reviewing a UI change before the PR | `web-design-guidelines`, `accessibility` |
| `docker-compose.yml` | `docker-compose-patterns` |
| `.github/workflows/` | `github-actions-hardening` |

- Repo docs beat skills. Where a skill disagrees with `CODING_STANDARDS.md`, `docs/agents/project-guide.md`
  or the code, follow the repo. The stack is Laravel 13 + Inertia React (no Livewire, no Pest, no
  Horizon: queue is `database`), Tailwind **v3** (`tailwind.config.js`, not v4 `@theme`), and tests on
  in-memory SQLite (no MySQL-only features).
- Load only the skills the change touches, not the whole table.
- Personal plugins (`superpowers`, `impeccable`, `ponytail`, mattpocock skills) are optional helpers
  for whoever has them installed; the project skills above must be enough on their own.

---

**These guidelines are working if:** fewer unnecessary changes in diffs, fewer rewrites due to overcomplication, and clarifying questions come before implementation rather than after mistakes.
