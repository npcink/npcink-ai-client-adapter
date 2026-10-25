#!/usr/bin/env python3
"""Offline end-to-end gate tests; every GitHub call is mocked and logged."""
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
HEAD = "a" * 40

GH = r'''#!/usr/bin/env python3
import json, os, sys
from pathlib import Path
root = Path(os.environ["GATE_FIXTURE"])
args = sys.argv[1:]
with (root / "calls").open("a") as f:
    f.write(json.dumps(args) + "\n")
c = json.loads((root / "config").read_text())
def emit(value):
    print(json.dumps(value))
def die():
    sys.exit(1)
if args[:2] == ["repo", "view"]:
    print("example/toolbox")
elif args[:2] == ["pr", "view"]:
    if "headRefOid" in args:
        n = int((root / "heads").read_text()) if (root / "heads").exists() else 0
        (root / "heads").write_text(str(n + 1))
        if c.get("head_read_fail"): die()
        print("b" * 40 if c.get("drift") and n >= c.get("drift_at", 1) else "a" * 40)
    else:
        if c.get("body_read_fail") or (c.get("readback_fail") and (root / "edited").exists()): die()
        print((root / "body").read_text())
elif args[:2] == ["pr", "edit"]:
    if c.get("write_fail"): die()
    body = sys.stdin.read()
    (root / "edited").touch()
    if not c.get("missing_line"):
        (root / "body").write_text(body)
elif args[:2] == ["run", "rerun"]:
    (root / "rerun").touch()
elif args and args[0] == "api":
    endpoint = next(a for a in args[1:] if a.startswith("repos/"))
    if "actions/runs?" in endpoint:
        n = int((root / "discovery").read_text()) if (root / "discovery").exists() else 0
        (root / "discovery").write_text(str(n + 1))
        if c.get("discovery_fail"): die()
        if c.get("empty_discovery"): sys.exit(0)
        if c.get("malformed_discovery"):
            print("{}")
        else:
            runs = [] if c["state"] == "absent" or (c.get("discover_later") and n == 0) else [{"id": 42, "run_number": 7, "path": ".github/workflows/ocr-review.yml", "event": "pull_request_target"}]
            emit({"workflow_runs": runs})
    elif "actions/runs/42" in endpoint:
        if c.get("state_read_fail"): die()
        if c.get("partial_state_error"):
            emit({"status": "queued", "conclusion": None, "attempt": 1, "created_at": "2026-10-25T00:00:00Z"})
            die()
        if c.get("malformed_state"):
            print("{}")
        else:
            n = int((root / "states").read_text()) if (root / "states").exists() else 0
            (root / "states").write_text(str(n + 1))
            rerun = (root / "rerun").exists()
            state = "success" if rerun or (c.get("complete_later") and n >= 2) else c["state"]
            emit({"status": state if state in ["queued", "in_progress", "waiting"] else "completed", "conclusion": state if state not in ["queued", "in_progress", "waiting"] else None, "attempt": 2 if rerun else 1, "created_at": "2026-10-25T00:00:00Z"})
    elif "/pulls/" in endpoint:
        if c.get("comments_fail"): die()
        attempt = 2 if (root / "rerun").exists() else 1
        emit([{"user": {"login": "github-actions[bot]"}, "body": f"<!-- ocr-42-{attempt}-abc123 --> [P2] finding", "path": "file.php", "line": 1}] if c.get("finding") else [])
    elif "/issues/" in endpoint:
        body = "Review complete: 1 finding" if c.get("finding") else "Review skipped: no items were selected"
        emit([{"user": {"login": "github-actions[bot]"}, "updated_at": "2026-10-25T00:01:00Z", "body": "<!-- ocr-summary --> " + c.get("summary", body)}])
    else:
        raise RuntimeError(args)
else:
    raise RuntimeError(args)
'''


class ExceptionBehavior(unittest.TestCase):
    def run_gate(self, state="absent", reason="independent local review", body="", **config):
        with tempfile.TemporaryDirectory(prefix="review-exception-") as tmp:
            root = Path(tmp)
            (root / "config").write_text(json.dumps(dict(state=state, exception=reason is not None, **config)))
            (root / "body").write_text(body)
            (root / "gh").write_text(GH)
            (root / "gh").chmod(0o755)
            (root / "sleep").write_text("#!/usr/bin/env python3\nimport json, os\nfrom pathlib import Path\nr = Path(os.environ['GATE_FIXTURE'])\nwith (r / 'calls').open('a') as f: f.write('[\"sleep\"]\\n')\nraise SystemExit(88 if json.loads((r / 'config').read_text())['exception'] else 0)\n")
            (root / "sleep").chmod(0o755)
            args = ["bash", "scripts/verify-ai-review.sh", "--pr", "123", "--head-sha", HEAD]
            if reason is not None:
                args += ["--no-review-because", reason]
            result = subprocess.run(args, cwd=ROOT, env=dict(os.environ, PATH=tmp + os.pathsep + os.environ["PATH"], GATE_FIXTURE=tmp), capture_output=True, text=True, timeout=25)
            calls = [json.loads(line) for line in (root / "calls").read_text().splitlines()] if (root / "calls").exists() else []
            self.last_output = result.stdout + result.stderr
            return result.returncode, calls, (root / "body").read_text()

    def assert_no_review_actions(self, calls):
        self.assertFalse(any(c[0] == "sleep" or c[:2] == ["run", "rerun"] or c[:2] == ["pr", "comment"] or c[:2] == ["workflow", "enable"] for c in calls), calls)
        self.assertFalse(any(c[:2] == ["pr", "merge"] for c in calls), calls)

    def test_undelivered_exception_is_immediate_and_read_back(self):
        for state in ["absent", "queued", "in_progress", "waiting", "failure", "cancelled", "timed_out", "skipped", "action_required"]:
            with self.subTest(state=state):
                code, calls, body = self.run_gate(state)
                self.assertEqual(code, 0, self.last_output)
                self.assert_no_review_actions(calls)
                self.assertEqual(sum("actions/runs?" in " ".join(c) for c in calls), 1)
                self.assertIn("independent local review", body)
                edit = next(i for i, c in enumerate(calls) if c[:2] == ["pr", "edit"])
                self.assertTrue(any(c[:2] == ["pr", "view"] and "body" in c for c in calls[edit + 1:]))

    def test_exception_does_not_bypass_delivered_findings(self):
        for triaged, expected in [(False, 2), (True, 0)]:
            with self.subTest(triaged=triaged):
                code, calls, body = self.run_gate("success", finding=True, body="## AI Review Triage\n- abc123 accept: verified\n" if triaged else "")
                self.assertEqual(code, expected, self.last_output)
                self.assert_no_review_actions(calls)
                self.assertFalse(any(c[:2] == ["pr", "edit"] for c in calls))
        code, calls, _ = self.run_gate("success")
        self.assertEqual(code, 0, self.last_output)
        self.assert_no_review_actions(calls)

    def test_exception_fails_closed_on_reads_writes_and_head_drift(self):
        for config in [dict(discovery_fail=True), dict(empty_discovery=True), dict(malformed_discovery=True), dict(state_read_fail=True), dict(partial_state_error=True), dict(malformed_state=True), dict(body_read_fail=True), dict(write_fail=True), dict(readback_fail=True), dict(missing_line=True), dict(head_read_fail=True), dict(drift=True), dict(drift=True, drift_at=0)]:
            with self.subTest(config=config):
                code, calls, _ = self.run_gate("queued", **config)
                self.assertNotEqual(code, 0, self.last_output)
                self.assert_no_review_actions(calls)

    def test_delivery_contract_errors_are_not_exceptions(self):
        for config in [dict(comments_fail=True), dict(summary="Review partially complete"), dict(summary="unknown producer format"), dict(summary="Review complete: 1 finding")]:
            with self.subTest(config=config):
                code, calls, _ = self.run_gate("success", **config)
                self.assertNotEqual(code, 0, self.last_output)
                self.assert_no_review_actions(calls)
                self.assertFalse(any(c[:2] == ["pr", "edit"] for c in calls))

    def test_invalid_reason_is_rejected_before_network(self):
        for reason in ["", " \t", "two\nlines", "two\rlines"]:
            with self.subTest(reason=reason):
                code, calls, _ = self.run_gate(reason=reason)
                self.assertNotEqual(code, 0, self.last_output)
                self.assertEqual(calls, [])

    def test_normal_wait_retry_and_triage_remain(self):
        for state, config in [("queued", dict(complete_later=True)), ("success", dict(discover_later=True)), ("failure", {}), ("cancelled", {})]:
            with self.subTest(state=state, config=config):
                code, calls, _ = self.run_gate(state, reason=None, **config)
                self.assertEqual(code, 0, self.last_output)
                if state in ["failure", "cancelled"]:
                    reruns = [c for c in calls if c[:2] == ["run", "rerun"]]
                    self.assertEqual(len(reruns), 1)
                    self.assertEqual("--failed" in reruns[0], state == "failure")
                else:
                    self.assertIn(["sleep"], calls)
        code, _, _ = self.run_gate("success", reason=None, finding=True)
        self.assertEqual(code, 2, self.last_output)
        code, _, _ = self.run_gate("absent", reason=None)
        self.assertNotEqual(code, 0, self.last_output)


if __name__ == "__main__":
    unittest.main(verbosity=2)
