"""Fails when the code reads a feature flag that the flag table doesn't list.

Flags pile up unless each is planned from the day it is added: the PR that adds one adds its row
(kind, default, owner module, issue, removal condition) to the table in
governance.json → feature_flags.doc, and opens its removal issue.
"""
from __future__ import annotations

import os
import re

import governance_config as gc

SKIP_DIRS = {".git", "node_modules", "vendor", "dist", "build", ".venv", "__pycache__", "target"}


def flags_in_code(root: str, paths: list[str], pattern: re.Pattern) -> dict[str, str]:
    found: dict[str, str] = {}
    for base in paths:
        for dirpath, dirnames, filenames in os.walk(os.path.join(root, base)):
            dirnames[:] = [d for d in dirnames if d not in SKIP_DIRS]
            for name in filenames:
                full = os.path.join(dirpath, name)
                try:
                    with open(full, encoding="utf-8", errors="ignore") as fh:
                        text = fh.read()
                except OSError:
                    continue
                for flag in pattern.findall(text):
                    found.setdefault(flag, os.path.relpath(full, root).replace(os.sep, "/"))
    return found


def problems(root: str, cfg: dict | None = None) -> list[str]:
    cfg = cfg or gc.load(root)
    ff = cfg["feature_flags"]
    if not ff.get("enabled"):
        return []
    pattern = re.compile(ff["regex"])
    try:
        with open(os.path.join(root, ff["doc"]), encoding="utf-8") as fh:
            listed = set(pattern.findall(fh.read()))
    except OSError:
        listed = set()
    code = flags_in_code(root, ff.get("scan_paths") or ["."], pattern)
    return [f"{flag} (read in {where}) is missing from {ff['doc']}: add its row and removal issue."
            for flag, where in sorted(code.items()) if flag not in listed]


if __name__ == "__main__":
    issues = problems(gc.repo_root())
    for issue in issues:
        print(f"::error::{issue}")
    if not issues:
        print("Every feature flag is listed.")
    raise SystemExit(1 if issues else 0)
