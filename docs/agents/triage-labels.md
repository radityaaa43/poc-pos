# Triage labels

The skills speak in terms of five canonical triage roles. This file maps them to the label strings
used in this repo's tracker.

| Label in mattpocock/skills | Label in our tracker | Meaning |
|---|---|---|
| `needs-triage` | `needs-triage` | A module Owner needs to evaluate this issue |
| `needs-info` | `needs-info` | Waiting on the requester for more information |
| `ready-for-agent` | `ready-for-agent` | Fully specified, ready for an agent |
| `ready-for-human` | `ready-for-human` | Requires human implementation |
| `wontfix` | `wontfix` | Will not be actioned |

When a skill mentions a role (e.g. "apply the AFK-ready triage label"), use the label from this table.

Only a module Owner or a Standards owner moves an issue to `ready-for-*` (`AGENTS.md` → Issues).
## Module labels

Besides its triage role, every issue carries exactly one `module:*` label (`module:platform`, `module:sales`, `module:dine-in`, `module:inventory`, `module:purchasing`, `module:pricing-crm`, `module:whatsapp-service`;
scope and owners in `CONTRIBUTING.md` → Ownership), e.g. `gh issue edit <n> --add-label module:<name>`.
An issue isn't `ready-for-*` until it has one and its **What good looks like** section holds the
requester's answers (no `?` left); otherwise it is `needs-info`.
