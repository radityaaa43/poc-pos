"""Minimal GitHub REST client on urllib, so the scripts run on any runner (no `gh`, no pip).

Reads GITHUB_TOKEN (or GH_TOKEN) and GITHUB_API_URL (GitHub Enterprise) from the environment.
"""
from __future__ import annotations

import json
import os
import urllib.error
import urllib.request

API = os.environ.get("GITHUB_API_URL", "https://api.github.com").rstrip("/")


def _token() -> str:
    return os.environ.get("GITHUB_TOKEN") or os.environ.get("GH_TOKEN") or ""


def request(method: str, path: str, body: dict | None = None):
    data = json.dumps(body).encode() if body is not None else None
    req = urllib.request.Request(f"{API}/{path.lstrip('/')}", data=data, method=method)
    req.add_header("Accept", "application/vnd.github+json")
    req.add_header("X-GitHub-Api-Version", "2022-11-28")
    if _token():
        req.add_header("Authorization", f"Bearer {_token()}")
    if data is not None:
        req.add_header("Content-Type", "application/json")
    with urllib.request.urlopen(req, timeout=30) as resp:
        raw = resp.read()
    return json.loads(raw) if raw else None


def get(path: str):
    return request("GET", path)


def paginate(path: str, limit_pages: int = 30) -> list:
    sep = "&" if "?" in path else "?"
    items: list = []
    for page in range(1, limit_pages + 1):
        batch = get(f"{path}{sep}per_page=100&page={page}") or []
        items.extend(batch)
        if len(batch) < 100:
            break
    return items


def comment_once(repo: str, number: str, marker: str, body: str) -> bool:
    """Post `body` on an issue/PR unless a comment with `marker` exists. False if not allowed."""
    try:
        comments = paginate(f"repos/{repo}/issues/{number}/comments")
        if any(marker in (c.get("body") or "") for c in comments):
            return True
        request("POST", f"repos/{repo}/issues/{number}/comments", {"body": f"{marker}\n{body}"})
        return True
    except urllib.error.HTTPError as err:
        # Fork PRs get a read-only token: report in the log instead of failing the job.
        print(f"::warning::Could not comment on #{number}: HTTP {err.code}")
        return False
