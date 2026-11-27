"""PR size and file length signals (governance.json → `pr_size`, `file_length`).

Reviews find fewer defects past a few hundred changed lines, and long files cost agents context
on every turn. Usage:

  pr_size.py size     needs GITHUB_TOKEN, REPO, PR_NUMBER, LABELS (JSON list). Above `warn` the
                      PR gets one comment (it needs a second person's approval; the guard hook
                      refuses `gh pr merge` until then); above `fail` the job fails unless the
                      `large_label` label is on the PR.
  pr_size.py length   needs BASE_SHA. Fails when a hand-written code file grows past `max` lines.
                      Files already over the limit may shrink or stay, never grow, and a file
                      in `ratchet` may not grow at any size.

Not counted: lockfiles, generated and vendored files, and the `exclude` globs. The guard hook
imports `counted_lines` and `WARN_LINES` from here.
"""
from __future__ import annotations

import json
import os
import subprocess
import sys

import governance_config as gc

CFG = gc.load()
WARN_LINES = int(CFG["pr_size"]["warn"])
FAIL_LINES = int(CFG["pr_size"]["fail"])
MARKER = "<!-- governance:pr-size -->"


def counted(path: str, cfg: dict = CFG) -> bool:
    return not gc.match_any(path, cfg["pr_size"]["exclude"]) and not gc.match_any(
        path, cfg["protected"].get("migrations", [])
    )


def counted_lines(files: list, cfg: dict = CFG) -> int:
    """Changed lines over files from the REST API (`filename`) or `gh pr view` (`path`)."""
    total = 0
    for f in files:
        path = f.get("filename") or f.get("path") or ""
        if counted(path, cfg):
            total += int(f.get("additions", 0)) + int(f.get("deletions", 0))
    return total


def verdict(lines: int, labels: list, cfg: dict = CFG) -> tuple[str, str]:
    """('ok'|'warn'|'fail', message)."""
    ps = cfg["pr_size"]
    if lines > int(ps["fail"]) and ps["large_label"] not in labels:
        return "fail", (f"{lines} counted changed lines is over {ps['fail']}. Split the PR "
                        f"(`/to-tickets`), or a module Owner adds `{ps['large_label']}` after "
                        "agreeing to review it whole.")
    if lines > int(ps["warn"]):
        return "warn", (f"{lines} counted changed lines is over {ps['warn']}: this PR needs an "
                        "approving review from someone other than its author before it merges. "
                        f"Aim for under {ps['target']}.")
    return "ok", f"{lines} counted changed lines."


def run_size() -> int:
    import gh_api

    repo, pr = os.environ["REPO"], os.environ["PR_NUMBER"]
    labels = json.loads(os.environ.get("LABELS") or "[]")
    files = gh_api.paginate(f"repos/{repo}/pulls/{pr}/files")
    state, message = verdict(counted_lines(files), labels)
    print(message)
    if state != "ok":
        gh_api.comment_once(repo, pr, MARKER, f"**PR size:** {message} See CONTRIBUTING.md → PR size.")
    if state == "fail":
        print(f"::error::{message}")
        return 1
    return 0


def _lines_at(ref: str | None, path: str) -> int:
    if ref is None:
        try:
            with open(path, encoding="utf-8", errors="replace") as fh:
                return sum(1 for _ in fh)
        except OSError:
            return 0
    out = subprocess.run(["git", "show", f"{ref}:{path}"], capture_output=True, text=True,
                         encoding="utf-8", errors="replace")
    return out.stdout.count("\n") if out.returncode == 0 else 0


def length_problems(base: str, cfg: dict = CFG) -> list[str]:
    fl = cfg["file_length"]
    diff = subprocess.run(["git", "diff", "--name-only", "--diff-filter=AM", f"{base}...HEAD"],
                          capture_output=True, text=True, check=True).stdout.split()
    found = []
    for path in diff:
        if not any(path.endswith(ext) for ext in fl["extensions"]):
            continue
        if gc.match_any(path, fl["exempt"]) or not counted(path, cfg):
            continue
        new, old = _lines_at(None, path), _lines_at(base, path)
        if path in fl.get("ratchet", []):
            if new > old:
                found.append(f"{path}: {old} → {new} lines. This file may only shrink; move code out "
                             "(see AGENTS.md → Working in parallel).")
            continue
        if new > int(fl["max"]) and new > old:
            found.append(f"{path}: {old} → {new} lines (limit {fl['max']}). Split it before it grows.")
    return found


def run_length() -> int:
    found = length_problems(os.environ["BASE_SHA"])
    for item in found:
        print(f"::error file={item.split(':', 1)[0]}::{item}")
    if not found:
        print("No hand-written file grew past the limit.")
    return 1 if found else 0


if __name__ == "__main__":
    mode = sys.argv[1] if len(sys.argv) > 1 else ""
    if mode == "size":
        raise SystemExit(run_size())
    if mode == "length":
        raise SystemExit(run_length())
    print(__doc__)
    raise SystemExit(2)
