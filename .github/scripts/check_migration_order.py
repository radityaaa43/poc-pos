"""Fails when a PR adds a migration that sorts before one already on the base branch.

Usage: check_migration_order.py <base-sha> <head-sha>

Parallel branches each add a timestamped migration; whichever merges second may carry an older
timestamp than one already applied. Most tools then skip or reorder it silently. Rename yours to a
fresh timestamp before merging; never rename or edit a migration once it has merged.
Folders come from governance.json → protected.migrations.
"""
from __future__ import annotations

import subprocess
import sys

import governance_config as gc


def _git(*args: str) -> list[str]:
    out = subprocess.run(["git", *args], capture_output=True, text=True)
    return out.stdout.split("\n") if out.returncode == 0 else []


def problems(base: str, head: str, cfg: dict | None = None) -> list[str]:
    cfg = cfg or gc.load()
    found = []
    for folder in cfg["protected"].get("migrations", []):
        folder = folder if folder.endswith("/") else folder + "/"
        on_base = [p for p in _git("ls-tree", "-r", "--name-only", base, "--", folder) if p]
        added = [p for p in _git("diff", "--name-only", "--diff-filter=A", base, head, "--", folder) if p]
        if not on_base:
            continue
        newest = max(p[len(folder):] for p in on_base)
        for path in added:
            name = path[len(folder):]
            if name < newest:
                found.append(f"{path} sorts before {folder}{newest}, already on the base branch: "
                             "rename it to a fresh timestamp.")
    return found


if __name__ == "__main__":
    issues = problems(sys.argv[1], sys.argv[2])
    for issue in issues:
        print(f"::error::{issue}")
    if not issues:
        print("Migration order OK.")
    raise SystemExit(1 if issues else 0)
