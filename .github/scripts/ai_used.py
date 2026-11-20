"""The `AI used:` line on PRs and issues: `none`, or `<agent>, <model>, effort <level>` per agent.

One fixed format, so `ai_used_report.py` can count which agent, model and effort level did the
work. It replaces AI attribution footers and Co-Authored-By trailers, which this standard forbids.
CI checks the format only; it cannot prove what actually ran.
"""
from __future__ import annotations

import re

import governance_config as gc

EFFORTS = ("low", "medium", "high", "xhigh", "max", "unknown")
FORMAT_HINT = (
    "`none`, or `<agent>, <model>, effort <low|medium|high|xhigh|max|unknown>`, one entry per agent "
    "separated by `;` (e.g. `Claude Code, claude-opus-5-5, effort high`)"
)
ENTRY = re.compile(rf"^([^,;]*\S)\s*,\s*([^,;]*\S)\s*,\s*effort\s+({'|'.join(EFFORTS)})$", re.I)


def parse(body: str) -> list[tuple[str, str, str]] | None:
    """(agent, model, effort) per entry; [] for `none`; None when missing or malformed."""
    value = gc.line_value(body, "AI used")
    if value is None:
        return None
    value = value.strip().strip("`*").strip()
    if value.lower() == "none":
        return []
    entries = []
    for part in value.split(";"):
        entry = ENTRY.match(part.strip().strip("`"))
        if not entry:
            return None
        agent, model, effort = entry.groups()
        entries.append((agent.strip(), model.strip(), effort.lower()))
    return entries


def problem(body: str, what: str) -> str | None:
    """The message to show when the line is missing or malformed, else None."""
    if parse(body) is None:
        return f"Fill the `AI used:` line for the agent(s) that {what}: {FORMAT_HINT}."
    return None
