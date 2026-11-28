"""Loads `.github/governance.json`, the one project-specific file of the governance standard.

Every governance script and the Claude Code guard hook read their settings from here, so adapting
the standard to a codebase means editing the JSON, never the scripts. Missing keys fall back to
DEFAULTS; lists in the file replace the default list, dicts are merged key by key.
Standard library only, Python >= 3.9.
"""
from __future__ import annotations

import copy
import fnmatch
import json
import os
import re
import subprocess
import sys

for _stream in (sys.stdout, sys.stderr):  # Windows consoles default to a legacy code page
    try:
        _stream.reconfigure(encoding="utf-8", errors="replace")
    except (AttributeError, ValueError):
        pass

CONFIG_PATH = ".github/governance.json"

DEFAULTS: dict = {
    "version": "0.0.0",
    "tier": "standard",
    "project": {
        "name": "",
        "repo": "",
        "host": "github",
        "default_branch": "main",
        "docs_language": "English",
    },
    "people": {"standards_owners": []},
    "modules": [],
    "branches": {
        "types": ["feat", "fix", "refactor", "docs", "test", "chore"],
        "exploratory": "",
    },
    "merge": {"self_merge_types": ["chore", "docs", "ci", "test"]},
    "standards": {
        "paths": [
            "AGENTS.md", "CLAUDE.md", "CODING_STANDARDS.md", "REVIEW.md", "CONTRIBUTING.md",
            ".agents/skills/", ".claude/", ".github/",
        ],
    },
    "protected": {
        # Real secret files. Matched against the basename when the glob has no "/".
        "secret_globs": [
            ".env", ".env.*", "*.env", "*.env.local", "*.pem", "*.key", "*.p12", "*.pfx",
            "*.keystore", "*.jks", "id_rsa*", "id_ed25519*", "**/secrets/**", "**/.secrets/**",
            "credentials.json", "service-account*.json", ".pypirc", ".netrc",
        ],
        # A secret-looking file with one of these suffixes is a committed template, not a secret.
        "secret_allow_suffixes": [".example", ".sample", ".template", ".dist", ".tpl"],
        # Other files agents must never edit: [{"glob": "...", "reason": "..."}].
        "deny_edit": [],
        # Migration folders: a migration already on the default branch is immutable.
        "migrations": [],
        # Shell commands that must ask first (regexes, searched in each command).
        "destructive_commands": [
            r"\bgit\s+reset\s+--hard\b",
            r"\bgit\s+clean\s+-[a-z]*f",
            r"\brm\s+-[a-z]*r[a-z]*f?\s+(?:/|~|\*|\.)(?:\s|$)",
            r"\bdocker[- ]compose\b[^|;&]*\bdown\b[^|;&]*(?:\s-v\b|--volumes)",
            r"\bdocker\s+(?:volume\s+(?:rm|prune)|system\s+prune)\b",
            r"\b(?:DROP\s+(?:DATABASE|SCHEMA|TABLE)|TRUNCATE\s+TABLE)\b",
            r"\bterraform\s+(?:destroy|apply\s+[^|;&]*-auto-approve)\b",
            r"\bkubectl\s+delete\s+(?:ns|namespace|pvc|pv)\b",
            r"\bhelm\s+uninstall\b",
        ],
    },
    # Folders owned by another team: [{"name": "qa", "path": "tests/sit/", "owner": "handle"}].
    "areas": [],
    "area_env_var": "GOVERNANCE_AREA",
    "hotspots": [],
    "pr_size": {
        "target": 300,
        "warn": 2000,
        "fail": 3000,
        "large_label": "large-pr",
        "exclude": [
            "package-lock.json", "yarn.lock", "pnpm-lock.yaml", "bun.lockb", "bun.lock",
            "go.sum", "Cargo.lock", "poetry.lock", "uv.lock", "Pipfile.lock", "composer.lock",
            "Gemfile.lock", "*.min.js", "*.min.css", "*.snap", "*.gen.*", "**/generated/**",
            "**/vendor/**", "**/__generated__/**",
        ],
    },
    "file_length": {
        "max": 1000,
        "extensions": [
            ".py", ".js", ".jsx", ".ts", ".tsx", ".vue", ".svelte", ".go", ".rs", ".java",
            ".kt", ".cs", ".php", ".rb", ".swift", ".scala", ".dart",
        ],
        "exempt": [
            "**/test_*", "**/*_test.*", "**/*.test.*", "**/*.spec.*", "**/tests/**",
            "**/e2e/**", "**/__tests__/**", "*.gen.*", "**/generated/**", "**/migrations/**",
        ],
        "ratchet": [],  # files that may only shrink, whatever their size
    },
    "pr": {
        "require_closes": True,
        "require_manual_test": True,
        "require_docs_line": True,
    },
    "issue": {"required_sections": ["Done when"], "title_types": [], "title_rule_start": "9999-12-31"},
    "ai_used": {"required": True, "rule_start": "1970-01-01"},
    "backlog": {
        "enabled": False,
        "source": "",
        "rule_start": "1970-01-01",
        "id_regex": r"\b[A-Z][A-Z0-9]+-\d+\b",
        "require_ac": False,
        "ac_regex": r"\bAC-\d+\b",
        "see": "CONTRIBUTING.md → Requirements and backlog",
    },
    # A PR whose title type is in `types` must change a file under `paths`, or say `E2E: none — <reason>`.
    # `ac_tags`: each criterion on the Backlog line needs a `@<ID>-AC-<n>` tagged test under `paths`
    # or `E2E: AC-<n> none — <reason>`.
    "e2e_rule": {"enabled": False, "types": ["feat"], "paths": [], "ac_tags": False,
                 "rule_start": "1970-01-01"},
    "dependencies": {
        "manifests": [
            "package.json", "go.mod", "requirements*.txt", "pyproject.toml", "Cargo.toml",
            "composer.json", "Gemfile", "pom.xml",
        ],
    },
    "feature_flags": {
        "enabled": False,
        "doc": "docs/feature-flags.md",
        "regex": r"\b[A-Z][A-Z0-9_]*_ENABLED\b",
        "scan_paths": [],
    },
    "verify": {"areas": [], "e2e": "", "e2e_in_ci": True},
    "ui": {"enabled": False, "screenshots_dir": "", "design_plugin": False},
    "ci": {"runner": "ubuntu-latest", "python": "3.12"},
    "triage_labels": {
        "needs-triage": "needs-triage",
        "needs-info": "needs-info",
        "ready-for-agent": "ready-for-agent",
        "ready-for-human": "ready-for-human",
        "wontfix": "wontfix",
    },
}

ATTRIBUTION_RE = re.compile(
    r"Generated with \[?Claude Code|Co-Authored-By:\s*(?:Claude|Cursor|Copilot|Codex|Gemini|Devin|"
    r"[^\n<]*<[^>]*noreply@anthropic\.com>)|🤖 Generated with",
    re.I,
)


def _merge(base, override):
    if isinstance(base, dict) and isinstance(override, dict):
        out = dict(base)
        for key, value in override.items():
            out[key] = _merge(base.get(key), value) if key in base else value
        return out
    return copy.deepcopy(override)


def repo_root(start: str | None = None) -> str:
    """The repository root: GOVERNANCE_ROOT, CLAUDE_PROJECT_DIR, GITHUB_WORKSPACE, git, or cwd."""
    for var in ("GOVERNANCE_ROOT", "CLAUDE_PROJECT_DIR", "GITHUB_WORKSPACE"):
        if os.environ.get(var):
            return os.environ[var]
    try:
        out = subprocess.run(
            ["git", "rev-parse", "--show-toplevel"], cwd=start or os.getcwd(),
            capture_output=True, text=True, timeout=5,
        )
        if out.returncode == 0 and out.stdout.strip():
            return out.stdout.strip()
    except (OSError, subprocess.SubprocessError):
        pass
    return start or os.getcwd()


def load(root: str | None = None) -> dict:
    """DEFAULTS merged with the repo's governance.json (DEFAULTS alone when the file is missing)."""
    path = os.path.join(root or repo_root(), CONFIG_PATH)
    try:
        with open(path, encoding="utf-8") as fh:
            data = json.load(fh)
    except FileNotFoundError:
        data = {}
    return _merge(DEFAULTS, data)


def match(rel: str, pattern: str) -> bool:
    """Glob match on a repo-relative POSIX path.

    A pattern without "/" matches the basename anywhere ("*.pem"). A pattern ending in "/" is a
    folder prefix from the root ("db/migrations/"), or anywhere with "**/" ("**/fixtures/"). Otherwise fnmatch on the whole path, where "**/" may also
    match nothing ("**/vendor/**" matches "vendor/x.go").
    """
    rel = rel.replace("\\", "/")
    while rel.startswith("./"):
        rel = rel[2:]
    if pattern.endswith("/"):
        if pattern.startswith("**/"):
            return ("/" + pattern[3:]) in ("/" + rel)
        return rel.startswith(pattern)
    if "/" not in pattern:
        return fnmatch.fnmatchcase(rel.rsplit("/", 1)[-1], pattern)
    if fnmatch.fnmatchcase(rel, pattern):
        return True
    while pattern.startswith("**/"):
        pattern = pattern[3:]
        if fnmatch.fnmatchcase(rel, pattern):
            return True
    return False


def match_any(rel: str, patterns) -> bool:
    return any(match(rel, p) for p in patterns or [])


def is_secret(rel: str, cfg: dict) -> bool:
    """True for a real secret file; committed templates (*.example, ...) are not secrets."""
    prot = cfg["protected"]
    name = rel.replace("\\", "/").rsplit("/", 1)[-1]
    if any(name.endswith(sfx) for sfx in prot.get("secret_allow_suffixes", [])):
        return False
    return match_any(rel, prot.get("secret_globs", []))


def strip_comments(text: str) -> str:
    """Text without HTML comments, so template hints never count as filled in."""
    return re.sub(r"<!--.*?-->", "", text or "", flags=re.S)


def section(body: str, heading: str) -> str | None:
    """The text under a Markdown heading (any level), or None when the heading is missing."""
    found = re.search(
        rf"(?ims)^#+\s*{re.escape(heading)}\s*$(.*?)(?=^#+\s|\Z)", strip_comments(body)
    )
    return found.group(1) if found else None


def line_value(body: str, label: str) -> str | None:
    """The value after `<label>:` on its own line, ignoring list dashes and ** markers."""
    found = re.search(
        rf"^[^\w\n]*{re.escape(label)}:[^\w\n]*(\w.*)$", strip_comments(body), re.I | re.M
    )
    return found.group(1).strip() if found else None


def has_attribution(text: str) -> bool:
    return bool(ATTRIBUTION_RE.search(text or ""))
