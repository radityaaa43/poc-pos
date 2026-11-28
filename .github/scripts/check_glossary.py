"""Fails when GLOSSARY.md repeats a term (the first column of its tables).

Usage: check_glossary.py <path>

GLOSSARY.md merges with `merge=union` (.gitattributes), so parallel PRs can append rows without a
conflict. The price: a row two branches both edited comes back twice instead of conflicting.
"""
from __future__ import annotations

import re
import sys


def _is_separator(line: str) -> bool:
    return re.fullmatch(r"[\s|:-]*-[\s|:-]*", line) is not None


def problems(text: str) -> list[str]:
    lines = text.split("\n")
    seen: dict[str, int] = {}
    found = []
    for number, line in enumerate(lines, 1):
        if not line.lstrip().startswith("|"):
            continue
        below = lines[number] if number < len(lines) else ""
        if _is_separator(line) or _is_separator(below):  # the separator, or the header above it
            continue
        term = re.split(r"(?<!\\)\|", line)[1].strip()
        first = seen.setdefault(term.casefold(), number)
        if first != number:
            found.append(f"line {number} repeats the term '{term}' from line {first}: "
                         "merge the two rows into one.")
    return found


if __name__ == "__main__":
    with open(sys.argv[1], encoding="utf-8") as handle:
        issues = problems(handle.read())
    for issue in issues:
        print(f"::error file={sys.argv[1]}::{issue}")
    if not issues:
        print("Glossary terms are unique.")
    raise SystemExit(1 if issues else 0)
