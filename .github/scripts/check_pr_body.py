"""Fails a pull request whose body skips the checklist in AGENTS.md and the PR template.

Reads PR_BODY, PR_CREATED_AT and PR_TITLE from the environment (plus GITHUB_TOKEN, REPO and
PR_NUMBER to list the changed files of a feature PR). HTML comments are ignored, so the template's
placeholder hints never count as filled in. Which lines are required comes from
.github/governance.json (`pr`, `ai_used`, `backlog`, `e2e_rule`). Each dated rule skips PRs opened
before it started, so adopting the standard doesn't fail old PRs.
"""
from __future__ import annotations

import os
import re
import sys

import ai_used
import backlog
import governance_config as gc

CLOSES = re.compile(r"(?im)\b(?:closes|fixes|resolves)\s+(?:[\w.-]+/[\w.-]+)?#[0-9]+\b")


def e2e_type(title: str, cfg: dict) -> bool:
    """True when the title's Conventional Commit type (`feat(pos)!: ...`) is in e2e_rule.types."""
    types = "|".join(re.escape(t) for t in cfg["e2e_rule"]["types"])
    return bool(types) and bool(re.match(rf"(?:{types})(?:\([^)]*\))?!?:", title.strip(), re.I))


def e2e_problem(title: str, body: str, files: list[str], cfg: dict) -> str | None:
    """A feature PR must change a file under e2e_rule.paths, or say `E2E: none — <reason>`."""
    rule = cfg["e2e_rule"]
    if not rule.get("enabled") or not e2e_type(title, cfg):
        return None
    if any(gc.match_any(f, rule["paths"]) for f in files):
        return None
    value = gc.line_value(body, "E2E")
    if value is not None and backlog.NONE.match(value):
        return None
    return (f"A feature PR brings its own e2e tests: add or update a folder under "
            f"{', '.join(rule['paths'])} (scaffold it with `npm run e2e:new -- <NN-feature>`, see "
            "tests/e2e/README.md), or fill the `E2E:` line with `E2E: none — <reason>`.")


def problems(body: str, created_at: str = "9999", cfg: dict | None = None, title: str = "",
             files: list[str] | None = None) -> list[str]:
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
    if day >= cfg["e2e_rule"]["rule_start"]:
        msg = e2e_problem(title, body, files or [], cfg)
        if msg:
            found.append(msg)
    if gc.has_attribution(clean):
        found.append("Remove the AI attribution footer or Co-Authored-By trailer; "
                     "the `AI used:` line says which agent worked on it.")
    return found


if __name__ == "__main__":
    title = os.environ.get("PR_TITLE", "")
    files = []
    if e2e_type(title, gc.load()):  # one API call, and only for the PR types the rule covers
        import gh_api

        files = [f["filename"] for f in
                 gh_api.paginate(f"repos/{os.environ['REPO']}/pulls/{os.environ['PR_NUMBER']}/files")]
    issues = problems(os.environ.get("PR_BODY", ""), os.environ.get("PR_CREATED_AT", ""),
                      title=title, files=files)
    for issue in issues:
        print(f"::error::{issue}")
    if issues:
        print("See AGENTS.md and .github/pull_request_template.md.", file=sys.stderr)
        raise SystemExit(1)
    print("PR checklist OK.")
