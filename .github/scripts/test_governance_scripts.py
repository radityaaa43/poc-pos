"""Tests for the governance scripts. Run: python3 -m unittest discover -s .github/scripts -p 'test_*.py'"""
from __future__ import annotations

import copy
import os
import sys
import unittest

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

import ai_used  # noqa: E402
import backlog  # noqa: E402
import check_issue_body  # noqa: E402
import check_new_dependencies as deps  # noqa: E402
import check_pr_body  # noqa: E402
import check_standards_approval as standards  # noqa: E402
import governance_config as gc  # noqa: E402
import pr_size  # noqa: E402


def cfg(**overrides):
    out = copy.deepcopy(gc.DEFAULTS)
    for dotted, value in overrides.items():
        node = out
        *parents, last = dotted.split("__")
        for key in parents:
            node = node[key]
        node[last] = value
    return out


GOOD_PR = """Closes #12

## Manual test
Logged in as admin, opened the page, saw the new column.

## Checklist
- **AI used:** Claude Code, claude-opus-5-5, effort high
- **Docs:** README.md
"""


class Config(unittest.TestCase):
    def test_match(self):
        self.assertTrue(gc.match("a/b/key.pem", "*.pem"))
        self.assertTrue(gc.match("db/migrations/001.sql", "db/migrations/"))
        self.assertFalse(gc.match("x/db/migrations/001.sql", "db/migrations/"))
        self.assertTrue(gc.match("x/fixtures/a.csv", "**/fixtures/"))
        self.assertTrue(gc.match("vendor/x.go", "**/vendor/**"))
        self.assertTrue(gc.match("./.env", ".env"))

    def test_secrets(self):
        c = cfg()
        for path in (".env", "be/.env.local", "certs/server.pem", "config/secrets/db.yml", "id_rsa"):
            self.assertTrue(gc.is_secret(path, c), path)
        for path in (".env.example", "be/.env.be.example", "README.md", "src/env.ts"):
            self.assertFalse(gc.is_secret(path, c), path)

    def test_merge_keeps_defaults(self):
        merged = gc._merge(gc.DEFAULTS, {"pr_size": {"warn": 500}})
        self.assertEqual(merged["pr_size"]["warn"], 500)
        self.assertEqual(merged["pr_size"]["fail"], 3000)

    def test_attribution(self):
        self.assertTrue(gc.has_attribution("x\n\nCo-Authored-By: Claude <noreply@anthropic.com>"))
        self.assertTrue(gc.has_attribution("🤖 Generated with [Claude Code](https://claude.com)"))
        self.assertFalse(gc.has_attribution("Co-Authored-By: Jane <jane@example.com>"))


class AiUsed(unittest.TestCase):
    def test_parse(self):
        self.assertEqual(ai_used.parse("AI used: none"), [])
        self.assertEqual(ai_used.parse("- **AI used:** Codex, gpt-x, effort low; Claude Code, m, effort max"),
                         [("Codex", "gpt-x", "low"), ("Claude Code", "m", "max")])
        self.assertIsNone(ai_used.parse("AI used: Claude"))
        self.assertIsNone(ai_used.parse("<!-- AI used: none -->"))


class PrBody(unittest.TestCase):
    def test_good(self):
        self.assertEqual(check_pr_body.problems(GOOD_PR, cfg=cfg()), [])

    def test_missing_everything(self):
        found = check_pr_body.problems("## Manual test\n<!-- hint -->\n", cfg=cfg())
        self.assertEqual(len(found), 4)

    def test_refs_does_not_close(self):
        self.assertTrue(check_pr_body.problems(GOOD_PR.replace("Closes", "Refs"), cfg=cfg()))

    def test_attribution_fails(self):
        body = GOOD_PR + "\n🤖 Generated with [Claude Code](https://claude.com/claude-code)\n"
        self.assertTrue(any("attribution" in p for p in check_pr_body.problems(body, cfg=cfg())))

    def test_ai_used_rule_start(self):
        body = GOOD_PR.replace("Claude Code, claude-opus-5-5, effort high", "x")
        c = cfg(ai_used__rule_start="2030-01-01")
        self.assertEqual(check_pr_body.problems(body, "2029-12-31T00:00:00Z", cfg=c), [])
        self.assertTrue(check_pr_body.problems(body, "2030-01-02T00:00:00Z", cfg=c))

    def test_backlog(self):
        c = cfg(backlog__enabled=True, backlog__require_ac=True)
        self.assertTrue(check_pr_body.problems(GOOD_PR, cfg=c))
        self.assertEqual(check_pr_body.problems(GOOD_PR + "- Backlog: PROJ-12 AC-01\n", cfg=c), [])
        self.assertEqual(check_pr_body.problems(GOOD_PR + "- Backlog: none — CI fix\n", cfg=c), [])
        self.assertIsNone(backlog.pr_problem("Backlog: PROJ-1", cfg(backlog__enabled=True)))


class IssueBody(unittest.TestCase):
    def test_sections_and_ai_used(self):
        body = "AI used: none\n\n## Done when\n- [ ] the export button downloads a CSV\n"
        self.assertEqual(check_issue_body.problems(body, cfg=cfg()), [])
        empty = "AI used: none\n\n## Done when\n- [ ]\n"
        self.assertEqual(len(check_issue_body.problems(empty, cfg=cfg())), 1)

    def test_backlog_ac_tags(self):
        c = cfg(backlog__enabled=True, backlog__require_ac=True)
        body = "AI used: none\nBacklog: PROJ-7\n\n## Done when\n- [ ] works\n"
        self.assertEqual(len(check_issue_body.problems(body, cfg=c)), 1)
        self.assertEqual(check_issue_body.problems(body.replace("- [ ] works", "- [ ] [AC-01] works"), cfg=c), [])


class PrSize(unittest.TestCase):
    def test_counted_lines_skips_lockfiles_and_migrations(self):
        c = cfg(protected__migrations=["db/migrations/"])
        files = [{"filename": "src/a.ts", "additions": 10, "deletions": 5},
                 {"filename": "yarn.lock", "additions": 900, "deletions": 0},
                 {"path": "db/migrations/001.sql", "additions": 50, "deletions": 0}]
        self.assertEqual(pr_size.counted_lines(files, c), 15)

    def test_verdict(self):
        c = cfg()
        self.assertEqual(pr_size.verdict(100, [], c)[0], "ok")
        self.assertEqual(pr_size.verdict(2500, [], c)[0], "warn")
        self.assertEqual(pr_size.verdict(3500, [], c)[0], "fail")
        self.assertEqual(pr_size.verdict(3500, ["large-pr"], c)[0], "warn")


class Standards(unittest.TestCase):
    def test_touched(self):
        paths = gc.DEFAULTS["standards"]["paths"]
        self.assertEqual(standards.touched(["AGENTS.md", "src/x.ts", ".github/workflows/ci.yml"], paths),
                         ["AGENTS.md", ".github/workflows/ci.yml"])

    def test_latest_review_must_approve_head(self):
        reviews = [{"user": {"login": "ken"}, "state": "APPROVED", "commit_id": "old"},
                   {"user": {"login": "ken"}, "state": "COMMENTED", "commit_id": "new"}]
        self.assertIsNone(standards.approved(reviews, ["ken"], "new"))
        reviews.append({"user": {"login": "Ken"}, "state": "APPROVED", "commit_id": "new"})
        self.assertEqual(standards.approved(reviews, ["ken"], "new"), "Ken")
        reviews.append({"user": {"login": "Ken"}, "state": "CHANGES_REQUESTED", "commit_id": "new"})
        self.assertIsNone(standards.approved(reviews, ["ken"], "new"))


class Dependencies(unittest.TestCase):
    def test_parsers(self):
        self.assertEqual(deps.parse("package.json", '{"dependencies": {"a": "1"}, "devDependencies": {"b": "2"}}'),
                         {"a", "b"})
        gomod = "module x\nrequire (\n\tgithub.com/a/b v1.0.0\n\tgithub.com/c/d v1.0.0 // indirect\n)\n"
        self.assertEqual(deps.parse("go.mod", gomod), {"github.com/a/b"})
        self.assertEqual(deps.parse("requirements.txt", "Django>=4\n# c\n-r base.txt\nrequests_oauth==1\n"),
                         {"django", "requests-oauth"})
        self.assertEqual(deps.parse("composer.json", '{"require": {"php": ">=8", "laravel/framework": "^11"}}'),
                         {"laravel/framework"})
        self.assertEqual(deps.parse("Gemfile", "gem 'rails', '~> 7'\n"), {"rails"})


if __name__ == "__main__":
    unittest.main()
