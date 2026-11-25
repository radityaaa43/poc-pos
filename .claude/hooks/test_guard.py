"""Tests for guard.py. Run: python3 -m unittest discover -s .claude/hooks"""
from __future__ import annotations

import os
import sys
import unittest

HERE = os.path.dirname(os.path.abspath(__file__))
os.environ.setdefault("CLAUDE_PROJECT_DIR", os.path.dirname(os.path.dirname(HERE)))
sys.path.insert(0, HERE)

import guard  # noqa: E402


def decision(found):
    return found[0] if found else None


class Edits(unittest.TestCase):
    def test_secrets_denied_templates_allowed(self):
        self.assertEqual(decision(guard.edit_decision(".env")), "deny")
        self.assertEqual(decision(guard.edit_decision("api/.env.local")), "deny")
        self.assertEqual(decision(guard.edit_decision("certs/tls.pem")), "deny")
        self.assertIsNone(guard.edit_decision(".env.example"))
        self.assertIsNone(guard.edit_decision("src/app.ts"))

    def test_read_of_secret_denied(self):
        root = guard.ROOT
        self.assertEqual(decision(guard.decide("Read", {"file_path": os.path.join(root, ".env")})), "deny")
        self.assertIsNone(guard.decide("Read", {"file_path": os.path.join(root, "README.md")}))

    def test_area_crossing_asks(self):
        old = guard.CFG.get("areas")
        guard.CFG["areas"] = [{"name": "qa", "path": "tests/sit/", "owner": "tester"}]
        try:
            self.assertEqual(decision(guard.edit_decision("tests/sit/login.spec.ts")), "ask")
            os.environ[guard.get("area_env_var", "GOVERNANCE_AREA")] = "qa"
            self.assertIsNone(guard.edit_decision("tests/sit/login.spec.ts"))
            self.assertEqual(decision(guard.edit_decision("src/app.ts")), "ask")
        finally:
            os.environ.pop(guard.get("area_env_var", "GOVERNANCE_AREA"), None)
            guard.CFG["areas"] = old or []


class Bash(unittest.TestCase):
    def test_print_secret_denied_touch_asks(self):
        self.assertEqual(decision(guard.bash_decision("cat .env")), "deny")
        self.assertEqual(decision(guard.bash_decision("grep TOKEN api/.env.production")), "deny")
        self.assertEqual(decision(guard.bash_decision("cp .env.example .env")), "ask")
        self.assertIsNone(guard.bash_decision("docker compose --env-file .env up -d"))
        self.assertIsNone(guard.bash_decision("cat .env.example"))

    def test_attribution_denied(self):
        cmd = 'git commit -m "feat: x\n\nCo-Authored-By: Claude <noreply@anthropic.com>"'
        self.assertEqual(decision(guard.attribution_decision(cmd)), "deny")
        self.assertIsNone(guard.attribution_decision('git commit -m "feat(x): y"'))

    def test_push_to_default_denied(self):
        default = guard.get("project.default_branch", "main")
        self.assertEqual(decision(guard.push_decision(f"git push origin HEAD:{default}", lambda: "feat/1-x")), "deny")
        self.assertEqual(decision(guard.push_decision("git push", lambda: default)), "deny")
        self.assertIsNone(guard.push_decision("git push -u origin feat/1-x", lambda: "feat/1-x"))
        self.assertEqual(decision(guard.push_decision("git push --force origin feat/1-x", lambda: "feat/1-x")), "ask")
        self.assertIsNone(guard.push_decision("git push --force-with-lease origin feat/1-x", lambda: "feat/1-x"))

    def test_commit_on_default_asks(self):
        default = guard.get("project.default_branch", "main")
        self.assertEqual(decision(guard.commit_on_default_decision("git commit -m x", lambda: default)), "ask")
        self.assertIsNone(guard.commit_on_default_decision("git commit -m x", lambda: "feat/1-x"))

    def test_destructive_asks(self):
        for cmd in ("git reset --hard origin/main", "docker compose down -v", "terraform destroy",
                    "psql -c 'DROP DATABASE app'"):
            self.assertEqual(decision(guard.destructive_decision(cmd)), "ask", cmd)
        self.assertIsNone(guard.destructive_decision("git status"))

    def test_branch_names(self):
        no_triage = lambda args: {"labels": [{"name": "ready-for-agent"}]}  # noqa: E731
        triage = lambda args: {"labels": [{"name": "needs-triage"}]}  # noqa: E731
        self.assertIsNone(guard.branch_decision("git switch -c feat/12-export-csv", no_triage))
        self.assertEqual(decision(guard.branch_decision("git switch -c feat/12-export-csv", triage)), "ask")
        self.assertEqual(decision(guard.branch_decision("git checkout -b my-work", no_triage)), "ask")
        self.assertIsNone(guard.branch_decision("git branch --show-current", no_triage))

    def test_merge_target(self):
        self.assertEqual(guard.merge_target("gh pr merge 12 --squash"), ("12", None))
        self.assertEqual(guard.merge_target("gh pr merge -R o/r --merge 7"), ("7", "o/r"))
        self.assertIsNone(guard.merge_target("gh pr view 12"))

    def test_large_merge_needs_other_approval(self):
        big = {"number": 5, "author": {"login": "me"}, "baseRefName": "main", "additions": 5000,
               "deletions": 0, "files": [{"path": "src/a.ts", "additions": 5000, "deletions": 0}],
               "latestReviews": []}
        self.assertEqual(decision(guard.merge_decision("5", None, lambda a: big)), "deny")
        big["latestReviews"] = [{"state": "APPROVED", "author": {"login": "other"}}]
        self.assertIsNone(guard.merge_decision("5", None, lambda a: big))
        self.assertEqual(decision(guard.merge_decision("5", None, lambda a: None)), "deny")


class LocalOverride(unittest.TestCase):
    """GOVERNANCE_BASH_MODE=local-override hands "ask" decisions to Claude's own permissions; "deny" always stands."""

    def test_override_drops_ask_but_keeps_deny(self):
        old = guard.local_bash_override_enabled
        guard.local_bash_override_enabled = lambda: True
        try:
            self.assertIsNone(guard.bash_decision("git reset --hard origin/main"))
            self.assertEqual(decision(guard.bash_decision("cat .env")), "deny")
        finally:
            guard.local_bash_override_enabled = old

    def test_without_override_ask_stays(self):
        old = guard.local_bash_override_enabled
        guard.local_bash_override_enabled = lambda: False
        try:
            self.assertEqual(decision(guard.bash_decision("git reset --hard origin/main")), "ask")
        finally:
            guard.local_bash_override_enabled = old


if __name__ == "__main__":
    unittest.main()
