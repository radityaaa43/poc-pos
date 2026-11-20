"""Counts merged PRs per agent, model and effort level from their `AI used:` line.

Usage: ai_used_report.py [--limit N] [--since YYYY-MM-DD]   (needs `gh`, run locally)
"""
from __future__ import annotations

import argparse
import collections
import json
import subprocess

import ai_used


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--limit", type=int, default=200)
    ap.add_argument("--since", default="")
    args = ap.parse_args()
    out = subprocess.run(
        ["gh", "pr", "list", "--state", "merged", "--limit", str(args.limit),
         "--json", "number,mergedAt,body"], capture_output=True, text=True, check=True,
    )
    counts: collections.Counter = collections.Counter()
    unparsed = []
    for pr in json.loads(out.stdout):
        if args.since and (pr.get("mergedAt") or "")[:10] < args.since:
            continue
        entries = ai_used.parse(pr.get("body") or "")
        if entries is None:
            unparsed.append(pr["number"])
        elif not entries:
            counts[("none", "", "")] += 1
        for entry in entries or []:
            counts[entry] += 1
    print("| Agent | Model | Effort | PRs |\n|---|---|---|---|")
    for (agent, model, effort), n in counts.most_common():
        print(f"| {agent} | {model} | {effort} | {n} |")
    if unparsed:
        print(f"\nNo valid AI used line: {', '.join(f'#{n}' for n in unparsed)}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
