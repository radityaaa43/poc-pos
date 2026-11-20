"""Flags an issue whose required sections, `AI used:` line or `Backlog:` line are missing.

Reads ISSUE_BODY and ISSUE_CREATED_AT. Issues can't be blocked like PRs, so the workflow turns a
failure into one comment showing the expected format. Dated rules skip older issues, so editing an
old issue draws no comment.
"""
from __future__ import annotations

import os

import ai_used
import backlog
import governance_config as gc


def problems(body: str, created_at: str = "9999", cfg: dict | None = None) -> list[str]:
    cfg = cfg or gc.load()
    day = (created_at or "9999")[:10]
    found = []
    for heading in cfg["issue"].get("required_sections", []):
        text = gc.section(body, heading)
        if text is None or not text.strip(" \n-[]"):
            found.append(f"Fill the `## {heading}` section.")
    if cfg["ai_used"].get("required") and day >= cfg["ai_used"]["rule_start"]:
        found.append(ai_used.problem(body, "drafted this issue"))
    if day >= cfg["backlog"]["rule_start"]:
        found.append(backlog.issue_problem(body, cfg))
    return [f for f in found if f]


if __name__ == "__main__":
    issues = problems(os.environ.get("ISSUE_BODY", ""), os.environ.get("ISSUE_CREATED_AT", ""))
    if issues:
        print(" ".join(issues))
        raise SystemExit(1)
    print("Issue checklist OK (or the issue predates the rules).")
