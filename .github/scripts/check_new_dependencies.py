#!/usr/bin/env python3
"""Lists new direct dependencies a PR adds, for the reviewer to confirm (warn-only).

Usage: check_new_dependencies.py <base-sha> <head-sha>

AI-written code can name packages that don't exist or were squatted under a look-alike name, so
every new direct dependency gets a warning and a job-summary row with its registry link. Removals
and version bumps are not reported. Manifests come from governance.json → dependencies.manifests.
Supported: package.json, go.mod, requirements*.txt, pyproject.toml, Cargo.toml, composer.json,
Gemfile, pom.xml.
"""
from __future__ import annotations

import json
import os
import re
import subprocess
import sys

import governance_config as gc

NPM_SECTIONS = ("dependencies", "devDependencies", "peerDependencies", "optionalDependencies")


def show(ref: str, path: str) -> str:
    out = subprocess.run(["git", "show", f"{ref}:{path}"], capture_output=True, text=True,
                         encoding="utf-8", errors="replace")
    return out.stdout if out.returncode == 0 else ""


def files_at(ref: str) -> list[str]:
    out = subprocess.run(["git", "ls-tree", "-r", "--name-only", ref], capture_output=True, text=True)
    return out.stdout.split() if out.returncode == 0 else []


def _pep508_name(spec: str) -> str | None:
    m = re.match(r"\s*([A-Za-z0-9][A-Za-z0-9._-]*)", spec)
    return m.group(1).lower().replace("_", "-") if m else None


def parse(path: str, text: str) -> set[str]:
    name = path.rsplit("/", 1)[-1]
    if not text.strip():
        return set()
    if name in ("package.json", "composer.json"):
        try:
            data = json.loads(text)
        except ValueError:
            return set()
        sections = NPM_SECTIONS if name == "package.json" else ("require", "require-dev")
        found = {k for s in sections for k in (data.get(s) or {})}
        return {k for k in found if not (name == "composer.json" and (k == "php" or k.startswith("ext-")))}
    if name == "go.mod":
        mods, block = set(), False
        for raw in text.splitlines():
            line = raw.strip()
            if line.startswith("require ("):
                block = True
                continue
            if block and line == ")":
                block = False
                continue
            if line.startswith("require "):
                line = line[len("require "):]
            elif not block:
                continue
            if "// indirect" in line:
                continue
            m = re.match(r"(\S+)\s+v\S+", line)
            if m:
                mods.add(m.group(1))
        return mods
    if name.startswith("requirements") and name.endswith(".txt"):
        out = set()
        for line in text.splitlines():
            line = line.split("#", 1)[0].strip()
            if line and not line.startswith(("-", "git+", "http")):
                n = _pep508_name(line)
                if n:
                    out.add(n)
        return out
    if name in ("pyproject.toml", "Cargo.toml"):
        return _toml_deps(name, text)
    if name == "Gemfile":
        return set(re.findall(r"""^\s*gem\s+['"]([^'"]+)['"]""", text, re.M))
    if name == "pom.xml":
        deps = re.findall(r"<dependency>.*?<groupId>([^<]+)</groupId>.*?<artifactId>([^<]+)</artifactId>",
                          text, re.S)
        return {f"{g.strip()}:{a.strip()}" for g, a in deps}
    return set()


def _toml_deps(name: str, text: str) -> set[str]:
    try:
        import tomllib  # Python 3.11+
        data = tomllib.loads(text)
    except Exception:  # older Python or invalid TOML: skip rather than guess
        return set()
    out: set[str] = set()
    if name == "Cargo.toml":
        for key in ("dependencies", "dev-dependencies", "build-dependencies"):
            out |= set((data.get(key) or {}).keys())
        return out
    project = data.get("project") or {}
    for spec in project.get("dependencies") or []:
        n = _pep508_name(spec)
        if n:
            out.add(n)
    for group in (project.get("optional-dependencies") or {}).values():
        out |= {n for n in (_pep508_name(s) for s in group) if n}
    poetry = ((data.get("tool") or {}).get("poetry") or {})
    for key in ("dependencies", "dev-dependencies"):
        out |= {k.lower() for k in (poetry.get(key) or {}) if k.lower() != "python"}
    return out


def registry(path: str, pkg: str) -> str:
    name = path.rsplit("/", 1)[-1]
    if name == "package.json":
        return f"https://www.npmjs.com/package/{pkg}"
    if name == "go.mod":
        return f"https://pkg.go.dev/{pkg}"
    if name in ("pyproject.toml",) or name.startswith("requirements"):
        return f"https://pypi.org/project/{pkg}/"
    if name == "Cargo.toml":
        return f"https://crates.io/crates/{pkg}"
    if name == "composer.json":
        return f"https://packagist.org/packages/{pkg}"
    if name == "Gemfile":
        return f"https://rubygems.org/gems/{pkg}"
    if name == "pom.xml":
        return f"https://central.sonatype.com/search?q={pkg.split(':')[-1]}"
    return ""


def new_dependencies(base: str, head: str, cfg: dict | None = None) -> list[tuple[str, str, str]]:
    cfg = cfg or gc.load()
    globs = cfg["dependencies"]["manifests"]
    paths = sorted({p for p in files_at(head) if gc.match_any(p, globs)}
                   - {p for p in files_at(head) if gc.match_any(p, cfg["pr_size"]["exclude"])})
    found = []
    for path in paths:
        for pkg in sorted(parse(path, show(head, path)) - parse(path, show(base, path))):
            found.append((path, pkg, registry(path, pkg)))
    return found


def main() -> int:
    base, head = sys.argv[1], sys.argv[2]
    found = new_dependencies(base, head)
    if not found:
        print("No new direct dependencies.")
        return 0
    lines = [
        "## New direct dependencies", "",
        "Reviewer: confirm each package is the intended one (not a look-alike name), maintained, "
        "and approved for this issue (AGENTS.md → Never).", "",
        "| File | Package |", "|---|---|",
    ]
    for path, pkg, url in found:
        print(f"::warning file={path}::New dependency {pkg}: confirm it is real, maintained and approved")
        lines.append(f"| `{path}` | [{pkg}]({url}) |" if url else f"| `{path}` | {pkg} |")
    summary = os.environ.get("GITHUB_STEP_SUMMARY")
    if summary:
        with open(summary, "a", encoding="utf-8") as fh:
            fh.write("\n".join(lines) + "\n")
    print("\n".join(lines))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
