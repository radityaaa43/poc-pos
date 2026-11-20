"""Fails a PR that changes Standards files until a Standards owner approves its latest commit.

Standards files and owners come from governance.json (`standards.paths`,
`people.standards_owners`). The workflow runs on `pull_request_target`, so it executes this file
and the config as they are on the default branch: a PR cannot weaken the check by editing it. It
never checks out or runs PR code; it reads the PR's file list and reviews through the API.

Env: GITHUB_TOKEN, REPO, PR, AUTHOR, HEAD_SHA.
"""
from __future__ import annotations

import os

import governance_config as gc


def touched(files: list[str], paths: list[str]) -> list[str]:
    return [f for f in files if any(f == p or (p.endswith("/") and f.startswith(p)) for p in paths)]


def approved(reviews: list[dict], owners: list[str], head_sha: str) -> str | None:
    """The owner whose latest non-comment review approves `head_sha`, else None."""
    latest: dict[str, dict] = {}
    for review in reviews:
        login = (review.get("user") or {}).get("login", "")
        if login.lower() in {o.lower() for o in owners} and review.get("state") != "COMMENTED":
            latest[login] = review
    for login, review in latest.items():
        if review.get("state") == "APPROVED" and review.get("commit_id") == head_sha:
            return login
    return None


def main() -> int:
    import gh_api

    cfg = gc.load()
    owners = [o.lstrip("@") for o in cfg["people"].get("standards_owners", [])]
    repo, pr = os.environ["REPO"], os.environ["PR"]
    author, head = os.environ.get("AUTHOR", ""), os.environ["HEAD_SHA"]
    files = [f["filename"] for f in gh_api.paginate(f"repos/{repo}/pulls/{pr}/files")]
    changed = touched(files, cfg["standards"]["paths"])
    if not changed:
        print("No Standards files changed.")
        return 0
    print("Standards files changed:\n  " + "\n  ".join(changed))
    if not owners:
        print("::error::No Standards owner in .github/governance.json → people.standards_owners.")
        return 1
    if author.lower() in {o.lower() for o in owners}:
        print(f"Opened by Standards owner @{author}; no second approval needed.")
        return 0
    who = approved(gh_api.paginate(f"repos/{repo}/pulls/{pr}/reviews"), owners, head)
    if who:
        print(f"Approved by @{who} on the latest commit.")
        return 0
    print("::error::This PR changes Standards files and needs an approval on its latest commit from "
          + ", ".join(f"@{o}" for o in owners)
          + ". After approving, re-run this check. See CONTRIBUTING.md → Standards.")
    return 1


if __name__ == "__main__":
    raise SystemExit(main())
