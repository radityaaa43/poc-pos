#!/usr/bin/env python3
"""Claude Code PreToolUse hook: enforces AGENTS.md → Never and the workflow rules in the agent.

Reads the tool call as JSON on stdin and answers with a permission decision on stdout. Rules and
paths come from .github/governance.json (see governance_config.py), so the hook itself is generic.

deny  edits to real secret files, `deny_edit` files and migrations already on the default branch;
      shell commands that print a secret file; AI attribution in commits, PRs and issues; pushes
      to the default branch; `gh pr merge` on a PR over `pr_size.warn` lines nobody else approved.
ask   edits across a team area (`areas`); destructive commands; commands that touch a secret file;
      force pushes; commits on the default branch; a branch that breaks `<type>/<issue>-<slug>` or
      starts work on an issue still `needs-triage`.

Only Claude Code runs this; other agents and humans rely on review and CI. If the hook blocks
something legitimate, a human does that step; nobody disables the hook.
"""
from __future__ import annotations

import json
import os
import re
import shlex
import subprocess
import sys

ROOT = os.environ.get("CLAUDE_PROJECT_DIR") or os.getcwd()
sys.path.insert(0, os.path.join(ROOT, ".github", "scripts"))
try:
    import governance_config as gc
except ImportError:  # scripts missing: still guard secrets with built-in patterns
    gc = None

READ_CMD = re.compile(
    r"^\s*(?:sudo\s+)?(?:cat|less|more|head|tail|bat|grep|egrep|fgrep|rg|ag|sed|awk|cut|sort|uniq|"
    r"strings|xxd|od|hexdump|base64|diff|nl|tac|jq|yq|type|Get-Content|gc|python3?\s+-c|node\s+-e|"
    r"vim?|nano|code|notepad|cp\s+[^|;&]*\s/dev/stdout)\b"
)
WRITES_TEXT = re.compile(r"\bgh\s+(?:pr|issue)\s+(?:create|edit|comment|review)\b|\bgit\s+(?:commit|tag)\b")
BODY_FILE = re.compile(r"(?:^|\s)(?:-F|--body-file|--file)[=\s]+(\S+)")
NEW_BRANCH = re.compile(
    r"\bgit\s+(?:switch\s+(?:-c|-C|--create)|checkout\s+(?:-b|-B)|branch(?!\s+-))\s+([^\s;&|]+)"
)
GH_MERGE_VALUE_FLAGS = {"-R", "--repo", "-t", "--subject", "-b", "--body", "-F", "--body-file",
                        "-A", "--author-email", "--match-head-commit"}
FALLBACK_SECRET = re.compile(r"(?:^|/)\.env(?:\.(?!example|sample|template)[\w-]+)*$|\.pem$|\.key$|id_rsa")


def load_cfg() -> dict:
    if gc is None:
        return {}
    return gc.load(ROOT)


CFG = load_cfg()


def get(path: str, default=None):
    node = CFG
    for key in path.split("."):
        if not isinstance(node, dict) or key not in node:
            return default
        node = node[key]
    return node


def is_secret(rel: str) -> bool:
    if gc is None:
        return bool(FALLBACK_SECRET.search(rel))
    return gc.is_secret(rel, CFG)


def rule(name: str) -> str:
    return f"{name}; enforced by .claude/hooks/guard.py"


# ---------- decisions: each returns (decision, reason, rule) or None ----------

def relative(path: str) -> str | None:
    root = os.path.realpath(ROOT)
    full = os.path.realpath(os.path.join(root, path))
    if full != root and not full.startswith(root + os.sep):
        return None
    return os.path.relpath(full, root).replace(os.sep, "/")


def on_default_branch(rel: str) -> bool:
    branch = get("project.default_branch", "main")
    for ref in (f"origin/{branch}", branch):
        try:
            out = subprocess.run(["git", "cat-file", "-e", f"{ref}:{rel}"], cwd=ROOT,
                                 capture_output=True, timeout=5)
        except (OSError, subprocess.SubprocessError):
            return False  # without git, allow rather than block every edit
        if out.returncode == 0:
            return True
    return False


def edit_decision(rel: str):
    if is_secret(rel):
        return "deny", f"{rel} holds real secrets; edit the committed *.example template instead", rule("AGENTS.md → Never")
    for item in get("protected.deny_edit", []) or []:
        if gc and gc.match(rel, item.get("glob", "")):
            return "deny", f"{rel}: {item.get('reason', 'protected file')}", rule("AGENTS.md → Never")
    for folder in get("protected.migrations", []) or []:
        if gc and gc.match(rel, folder if folder.endswith("/") else folder + "/") and on_default_branch(rel):
            return "deny", (f"{rel} is already on the default branch and may be applied somewhere; "
                            "add a new migration instead"), rule("AGENTS.md → Never")
    areas = get("areas", []) or []
    mine = os.environ.get(get("area_env_var", "GOVERNANCE_AREA") or "GOVERNANCE_AREA", "")
    for area in areas:
        inside = rel.startswith(area["path"])
        if mine == area.get("name") and not inside:
            return "ask", (f"{rel} is outside {area['path']}, the {area['name']} area you work in; "
                           "open an issue for its owners instead"), rule("CONTRIBUTING.md → Ownership")
        if mine != area.get("name") and inside:
            who = f" (`@{area['owner']}`)" if area.get("owner") else ""
            return "ask", (f"{rel} is in {area['path']}, the {area['name']} area{who}; report the "
                           "problem to its owners instead of changing it"), rule("CONTRIBUTING.md → Ownership")
    return None


def gh_json(args: list[str]):
    try:
        out = subprocess.run(["gh", *args], cwd=ROOT, capture_output=True, text=True, timeout=20)
    except (OSError, subprocess.SubprocessError):
        return None
    if out.returncode != 0:
        return None
    try:
        return json.loads(out.stdout)
    except ValueError:
        return None


def merge_target(segment: str):
    """(pr, repo) for a `gh pr merge` segment, else None. pr may be '' (current branch)."""
    try:
        words = shlex.split(segment)
    except ValueError:
        return None
    for i in range(len(words) - 2):
        if words[i:i + 3] == ["gh", "pr", "merge"]:
            break
    else:
        return None
    pr, repo, rest = "", None, iter(words[i + 3:])
    for word in rest:
        flag, _, inline = word.partition("=")
        if flag in GH_MERGE_VALUE_FLAGS:
            value = inline if inline else next(rest, "")
            if flag in ("-R", "--repo"):
                repo = value
        elif not word.startswith("-") and not pr:
            pr = word
    return pr, repo


def merge_decision(pr: str, repo: str | None, view=gh_json):
    try:
        from pr_size import WARN_LINES, counted_lines
    except ImportError:
        return None
    args = ["pr", "view", *([pr] if pr else []), *(["--repo", repo] if repo else []),
            "--json", "number,author,baseRefName,additions,deletions,files,latestReviews"]
    data = view(args)
    if data is None:
        return "deny", "Couldn't read the PR to check its size and reviews; merge once gh works", rule("AGENTS.md → PRs")
    exploratory = get("branches.exploratory", "")
    if exploratory and data.get("baseRefName") == exploratory:
        return None
    files = data.get("files") or []
    lines = counted_lines(files) if len(files) < 100 else data["additions"] + data["deletions"]
    if lines <= WARN_LINES:
        return None
    author = (data.get("author") or {}).get("login")
    if any(r.get("state") == "APPROVED" and (r.get("author") or {}).get("login") != author
           for r in data.get("latestReviews") or []):
        return None
    return "deny", (f"PR #{data['number']} has {lines} counted changed lines (over {WARN_LINES}) and no "
                    "approving review from someone other than its author"), rule("AGENTS.md → PRs")


def attribution_decision(command: str):
    if not WRITES_TEXT.search(command):
        return None
    texts = [command]
    for path in BODY_FILE.findall(command):
        try:
            with open(os.path.join(ROOT, path.strip("'\"")), encoding="utf-8") as fh:
                texts.append(fh.read())
        except OSError:
            pass
    check = gc.has_attribution if gc else (lambda t: "Co-Authored-By: Claude" in t)
    if any(check(t) for t in texts):
        return "deny", ("Remove the AI attribution footer or Co-Authored-By trailer; the issue/PR "
                        "`AI used:` line records the agent"), rule("AGENTS.md → Commits")
    return None


def current_branch() -> str:
    try:
        out = subprocess.run(["git", "rev-parse", "--abbrev-ref", "HEAD"], cwd=ROOT,
                             capture_output=True, text=True, timeout=5)
        return out.stdout.strip()
    except (OSError, subprocess.SubprocessError):
        return ""


def push_decision(segment: str, branch_now=current_branch):
    if not re.search(r"\bgit\s+push\b", segment):
        return None
    default = get("project.default_branch", "main")
    words = segment.split()
    refspecs = [w for w in words[words.index("push") + 1:] if not w.startswith("-")][1:] if "push" in words else []
    targets = {r.split(":")[-1].removeprefix("refs/heads/").lstrip("+") for r in refspecs}
    if default in targets or (not refspecs and branch_now() == default):
        return "deny", f"Never push to {default}; open a PR from a branch", rule("AGENTS.md → Branches")
    if re.search(r"(?:\s--force(?!-with-lease)\b|\s-f\b)", segment):
        return "ask", ("Force push: only on your own unreviewed branch, and prefer --force-with-lease; "
                       "after review, merge the default branch in instead"), rule("AGENTS.md → Working in parallel")
    return None


def branch_decision(command: str, view=gh_json):
    found = NEW_BRANCH.search(command)
    if not found:
        return None
    name = found.group(1)
    types = get("branches.types", ["feat", "fix", "refactor", "docs", "test", "chore"])
    ok = re.match(rf"^(?:{'|'.join(map(re.escape, types))})/(\d+)-[a-z0-9][a-z0-9._-]*$", name)
    if not ok:
        if name.startswith(("exploratory/", "release/", "hotfix/")) or name == get("branches.exploratory", ""):
            return None
        return "ask", (f"Branch `{name}` breaks `<type>/<issue>-<slug>` ({' '.join(types)}); every change "
                       "starts from an issue"), rule("AGENTS.md → Branches")
    labels = view(["issue", "view", ok.group(1), "--json", "labels"])
    triage = get("triage_labels.needs-triage", "needs-triage")
    if labels and any(l["name"] == triage for l in labels.get("labels", [])):
        return "ask", (f"Issue #{ok.group(1)} is still {triage}; its module Owner moves it to "
                       "ready-for-* before work starts"), rule("AGENTS.md → Issues")
    return None


def commit_on_default_decision(segment: str, branch_now=current_branch):
    if re.search(r"\bgit\s+commit\b", segment) and branch_now() == get("project.default_branch", "main"):
        return "ask", ("You are on the default branch; create `<type>/<issue>-<slug>` first"), rule("AGENTS.md → Branches")
    return None


def tokens(segment: str) -> list[str]:
    try:
        words = shlex.split(segment, posix=True)
    except ValueError:
        words = segment.split()
    out = []
    for w in words:
        w = w.lstrip("<>").strip("'\"")
        if w and not w.startswith("-"):
            out.append(w.replace("\\", "/"))
    return out


def secret_decision(command: str):
    cleaned = re.sub(r"--env-file[=\s]+\S+", "", command)  # compose reads it without printing it
    touched = False
    for segment in re.split(r"[|;&\n]|\$\(", cleaned):
        hits = [t for t in tokens(segment) if "/" in t or "." in t]
        hits = [t for t in hits if is_secret(t.lstrip("./") if t.startswith("./") else t)]
        if not hits:
            continue
        if READ_CMD.search(segment):
            return "deny", "Printing a real secret file would put its values in the transcript", rule("AGENTS.md → Never")
        touched = True
    if touched:
        return "ask", "Command touches a real secret file; secrets must not reach prompts or logs", rule("AGENTS.md → Never")
    return None


def destructive_decision(command: str):
    for pattern in get("protected.destructive_commands", []) or []:
        if re.search(pattern, command, re.I):
            return "ask", "Destructive command; run it only on your own local environment", rule("AGENTS.md → Never")
    return None


def bash_decision(command: str):
    found = attribution_decision(command)
    if found:
        return found
    for segment in re.split(r"&&|\|\||[;\n]", command):
        target = merge_target(segment)
        if target:
            found = merge_decision(*target)
            if found:
                return found
        found = push_decision(segment) or commit_on_default_decision(segment)
        if found:
            return found
    return (secret_decision(command) or destructive_decision(command) or branch_decision(command))


def decide(tool: str, tool_input: dict):
    if tool in ("Edit", "Write", "MultiEdit", "NotebookEdit"):
        path = tool_input.get("file_path") or tool_input.get("notebook_path")
        rel = relative(path) if path else None
        return edit_decision(rel) if rel else None
    if tool == "Bash":
        return bash_decision(tool_input.get("command", ""))
    if tool == "Read":
        path = tool_input.get("file_path")
        rel = relative(path) if path else None
        if rel and is_secret(rel):
            return "deny", f"{rel} holds real secrets; read the *.example template instead", rule("AGENTS.md → Never")
    return None


def main() -> None:
    try:
        event = json.load(sys.stdin)
    except ValueError:
        sys.exit(0)
    found = decide(event.get("tool_name", ""), event.get("tool_input") or {})
    if found:
        decision, reason, why = found
        print(json.dumps({"hookSpecificOutput": {
            "hookEventName": "PreToolUse",
            "permissionDecision": decision,
            "permissionDecisionReason": f"{reason} ({why})",
        }}))
    sys.exit(0)


if __name__ == "__main__":
    main()
