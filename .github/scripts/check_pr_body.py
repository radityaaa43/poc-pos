"""Fails a pull request whose body skips the checklist in AGENTS.md and the PR template.

Reads PR_BODY and PR_CREATED_AT from the environment. HTML comments are ignored, so the template's
placeholder hints never count as filled in. Which lines are required comes from
.github/governance.json (`pr`, `ai_used`, `backlog`). Each dated rule skips PRs opened before it
started, so adopting the standard doesn't fail old PRs.
"""
from __future__ import annotations

import os
import re
import sys

import ai_used
import backlog
import governance_config as gc

CLOSES = re.compile(r"(?im)\b(?:closes|fixes|resolves)\s+(?:[\w.-]+/[\w.-]+)?#[0-9]+\b")


def problems(body: str, created_at: str = "9999", cfg: dict | None = None) -> list[str]:
    cfg = cfg or gc.load()
    clean = gc.strip_comments(body)
    day = (created_at or "9999")[:10]
    found = []
    if cfg["pr"].get("require_closes") and not CLOSES.search(clean):
        found.append("Add `Closes #<issue>` for every issue this PR finishes, one per line "
                     "(`Refs #<n>` closes nothing).")
    if cfg["pr"].get("require_manual_test") and not (gc.section(body, "Manual test") or "").strip():
        found.append("Say under '## Manual test' what you tested by hand, as which role, and what you saw.")
    if cfg["pr"].get("require_docs_line") and gc.line_value(body, "Docs") is None:
        found.append("Fill the `Docs:` line with the docs you updated, or `n/a — <reason>`.")
    if cfg["ai_used"].get("required") and day >= cfg["ai_used"]["rule_start"]:
        msg = ai_used.problem(body, "wrote this PR")
        if msg:
            found.append(msg)
    if day >= cfg["backlog"]["rule_start"]:
        msg = backlog.pr_problem(body, cfg)
        if msg:
            found.append(msg)
    if gc.has_attribution(clean):
        found.append("Remove the AI attribution footer or Co-Authored-By trailer; "
                     "the `AI used:` line says which agent worked on it.")
    return found


if __name__ == "__main__":
    issues = problems(os.environ.get("PR_BODY", ""), os.environ.get("PR_CREATED_AT", ""))
    for issue in issues:
        print(f"::error::{issue}")
    if issues:
        print("See AGENTS.md and .github/pull_request_template.md.", file=sys.stderr)
        raise SystemExit(1)
    print("PR checklist OK.")
