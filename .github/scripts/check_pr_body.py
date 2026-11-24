"""Fails a pull request whose body skips the checklist in AGENTS.md and the PR template.

Reads PR_BODY, PR_CREATED_AT and PR_TITLE from the environment (plus GITHUB_TOKEN, REPO and
PR_NUMBER to list the changed files of a feature PR). HTML comments are ignored, so the template's
placeholder hints never count as filled in. Which lines are required comes from
.github/governance.json (`pr`, `ai_used`, `backlog`, `e2e_rule`). Each dated rule skips PRs opened
before it started, so adopting the standard doesn't fail old PRs. With `e2e_rule.ac_tags`, every
acceptance criterion on the `Backlog:` line needs a `@<ID>-AC-<n>` tagged test in the e2e folders
(read from the checkout) or a per-AC `E2E: AC-<n> none — <reason>`.
"""
from __future__ import annotations

import os
import re
import sys
from pathlib import Path

import ai_used
import backlog
import governance_config as gc

CLOSES = re.compile(r"(?im)\b(?:closes|fixes|resolves)\s+(?:[\w.-]+/[\w.-]+)?#[0-9]+\b")


def e2e_type(title: str, cfg: dict) -> bool:
    """True when the title's Conventional Commit type (`feat(pos)!: ...`) is in e2e_rule.types."""
    types = "|".join(re.escape(t) for t in cfg["e2e_rule"]["types"])
    return bool(types) and bool(re.match(rf"(?:{types})(?:\([^)]*\))?!?:", title.strip(), re.I))


def e2e_problem(title: str, body: str, files: list[str], cfg: dict) -> str | None:
    """A feature PR must change a file under e2e_rule.paths, or say `E2E: none — <reason>`."""
    rule = cfg["e2e_rule"]
    if not rule.get("enabled") or not e2e_type(title, cfg):
        return None
    if any(gc.match_any(f, rule["paths"]) for f in files):
        return None
    value = gc.line_value(body, "E2E")
    if value is not None and (backlog.NONE.match(value) or ac_exceptions(value, cfg)):
        return None
    return (f"A feature PR brings its own e2e tests: add or update a folder under "
            f"{', '.join(rule['paths'])} (scaffold it with `npm run e2e:new -- <NN-feature>`, see "
            "tests/e2e/README.md), or fill the `E2E:` line with `E2E: none — <reason>`.")


def _acs(text: str, cfg: dict) -> list[tuple[str | None, int]]:
    """(ID, AC number) pairs in reading order: `REQ-002 AC-01, AC-02; REQ-003 AC-03`."""
    b = cfg["backlog"]
    current, out = None, []
    # AC first: a generic id_regex (`[A-Z]+-\d+`) would also match `AC-07`.
    for m in re.finditer(f"(?P<ac>{b['ac_regex']})|(?P<id>{b['id_regex']})", text, re.I):
        if m["ac"]:
            out.append((current, int(re.sub(r"\D", "", m["ac"]))))
        else:
            current = m["id"].upper()
    return out


def ac_exceptions(value: str, cfg: dict) -> list[tuple[str | None, int]]:
    """Criteria excused on the `E2E:` line: `AC-07 none — <reason>; REQ-002 AC-08 none — <reason>`."""
    return [ac for part in value.split(";") if re.search(r"\bnone\W+\w", part, re.I)
            for ac in _acs(re.split(r"\bnone\b", part, maxsplit=1, flags=re.I)[0], cfg)]


# A test or describe head: test[.modifier](`title`, { tag: [...] }. The details object holds no braces.
TEST_HEAD = re.compile(r"\btest((?:\.\w+)*)\(\s*(['\"`])((?:(?!\2).)*)\2\s*(?:,\s*(\{[^{}]*\}))?", re.S)


def e2e_tags(specs: dict[str, str]) -> set[tuple[str, int]]:
    """(ID, AC number) for every `@<ID>-AC-<n>` tag on a test that runs (not fixme/skip)."""
    found = set()
    for text in specs.values():
        for m in TEST_HEAD.finditer(text):
            # shortcut: a test.fixme()/skip() call inside the body isn't seen; parse the JS if that bites.
            if re.search(r"\.(fixme|skip)\b", m[1]):
                continue
            for tag in re.findall(r"@[\w-]+", f"{m[3]} {m[4] or ''}"):
                hit = re.fullmatch(r"@(.+)-AC-(\d+)", tag, re.I)
                if hit:
                    found.add((hit[1].upper(), int(hit[2])))
    return found


def ac_problem(body: str, specs: dict[str, str], cfg: dict) -> str | None:
    """Every criterion on the Backlog line has a tagged e2e test or a per-AC exception."""
    value = gc.line_value(body, "Backlog")
    if not cfg["e2e_rule"].get("ac_tags") or value is None or backlog.NONE.match(value):
        return None
    excused = ac_exceptions(gc.line_value(body, "E2E") or "", cfg)
    tags = e2e_tags(specs)
    missing = [f"{rid} AC-{n:02d}" for rid, n in dict.fromkeys(_acs(value, cfg))
               if (rid, n) not in tags and (rid, n) not in excused and (None, n) not in excused]
    if not missing:
        return None
    return (f"No e2e test proves {', '.join(missing)}: tag a test under "
            f"{', '.join(cfg['e2e_rule']['paths'])} with the criterion it proves "
            "(`test('...', { tag: '@REQ-002-AC-05' }, ...)`), or excuse it on the `E2E:` line with "
            "`E2E: AC-05 none — <reason>`. See tests/e2e/README.md.")


def problems(body: str, created_at: str = "9999", cfg: dict | None = None, title: str = "",
             files: list[str] | None = None, specs: dict[str, str] | None = None) -> list[str]:
    cfg = cfg or gc.load()
    clean = gc.strip_comments(body)
    day = (created_at or "9999")[:10]
    found = []
    if cfg["pr"].get("require_closes") and not CLOSES.search(clean):
        found.append("Add `Closes #<issue>` for every issue this PR finishes, one per line "
                     "(`Refs #<n>` closes nothing).")
    if cfg["pr"].get("require_manual_test") and not (gc.section(body, "Manual test") or "").strip():
        found.append("Say under '## Manual test' what you tested by hand, as which role, and what you saw.")
    if cfg["pr"].get("require_docs_line") and gc.line_value(body, "Docs") is None:
        found.append("Fill the `Docs:` line with the docs you updated, or `n/a — <reason>`.")
    if cfg["ai_used"].get("required") and day >= cfg["ai_used"]["rule_start"]:
        msg = ai_used.problem(body, "wrote this PR")
        if msg:
            found.append(msg)
    if day >= cfg["backlog"]["rule_start"]:
        msg = backlog.pr_problem(body, cfg)
        if msg:
            found.append(msg)
    if day >= cfg["e2e_rule"]["rule_start"]:
        msg = e2e_problem(title, body, files or [], cfg)
        if msg:
            found.append(msg)
        msg = ac_problem(body, specs or {}, cfg)
        if msg:
            found.append(msg)
    if gc.has_attribution(clean):
        found.append("Remove the AI attribution footer or Co-Authored-By trailer; "
                     "the `AI used:` line says which agent worked on it.")
    return found


if __name__ == "__main__":
    title = os.environ.get("PR_TITLE", "")
    files = []
    if e2e_type(title, gc.load()):  # one API call, and only for the PR types the rule covers
        import gh_api

        files = [f["filename"] for f in
                 gh_api.paginate(f"repos/{os.environ['REPO']}/pulls/{os.environ['PR_NUMBER']}/files")]
    # The job checks out the PR's e2e folders, so tags from earlier PRs count too.
    specs = {str(p): p.read_text(encoding="utf-8") for d in gc.load()["e2e_rule"]["paths"]
             for p in Path(d).rglob("*.spec.js")}
    issues = problems(os.environ.get("PR_BODY", ""), os.environ.get("PR_CREATED_AT", ""),
                      title=title, files=files, specs=specs)
    for issue in issues:
        print(f"::error::{issue}")
    if issues:
        print("See AGENTS.md and .github/pull_request_template.md.", file=sys.stderr)
        raise SystemExit(1)
    print("PR checklist OK.")
