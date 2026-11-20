"""The `Backlog:` line on issues and PRs, when the project tracks requirements outside the issues.

Enabled by `backlog.enabled` in .github/governance.json. Issue: `Backlog: <IDs>`; with
`backlog.require_ac`, each Done-when item is tagged with the acceptance criterion it covers
(`- [ ] [AC-01] ...`). PR: `Backlog: <ID> AC-01, AC-02; <ID> AC-03`. Work not from the backlog
says `none — <reason>`. CI checks the format only; whether the criteria are met is the reviewer's
job (REVIEW.md), because CI cannot read the external tracker.
"""
from __future__ import annotations

import re

import governance_config as gc

NONE = re.compile(r"^none\W+\w", re.I)


def _cfg(cfg: dict | None) -> dict:
    return (cfg or gc.load())["backlog"]


def issue_problem(body: str, cfg: dict | None = None) -> str | None:
    b = _cfg(cfg)
    if not b.get("enabled"):
        return None
    ids, acs = re.compile(b["id_regex"]), re.compile(b["ac_regex"], re.I)
    value = gc.line_value(body, "Backlog")
    hint = "`Backlog: <IDs>` or `Backlog: none — <reason>`"
    if value is None or not (NONE.match(value) or ids.search(value)):
        return f"Fill the Backlog line: {hint}. See {b['see']}."
    if NONE.match(value) or not b.get("require_ac"):
        return None
    done = gc.section(body, "Done when") or ""
    if not re.search(r"\[[^\]]*" + b["ac_regex"] + r"[^\]]*\]", done, re.I) and not acs.search(done):
        return (f"Backlog names {value}: tag each Done-when item with the acceptance criterion it "
                f"covers, e.g. `- [ ] [AC-01] ...`. See {b['see']}.")
    return None


def pr_problem(body: str, cfg: dict | None = None) -> str | None:
    b = _cfg(cfg)
    if not b.get("enabled"):
        return None
    ids, acs = re.compile(b["id_regex"]), re.compile(b["ac_regex"], re.I)
    value = gc.line_value(body, "Backlog")
    if value is not None and NONE.match(value):
        return None
    if value is None or not ids.search(value) or (b.get("require_ac") and not acs.search(value)):
        what = "the IDs and acceptance criteria" if b.get("require_ac") else "the backlog IDs"
        return (f"Fill the Backlog line with {what} this PR covers, or `Backlog: none — <reason>`. "
                f"See {b['see']}.")
    return None
