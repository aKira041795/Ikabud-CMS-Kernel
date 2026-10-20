"""Regression tests for tools/lane-events.py.

Run:
    python3 -m unittest discover -s tools/tests -p 'test_lane_events.py'
    tools/lane.sh events-selftest
"""

from __future__ import annotations

import json
import os
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
TOOL = ROOT / "tools" / "lane-events.py"


class LaneEventsTest(unittest.TestCase):
    def setUp(self) -> None:
        self._tmp = tempfile.TemporaryDirectory()
        self.runs = Path(self._tmp.name)
        self.model_log = self.runs / "model.log"
        self.model_log.write_text("model output\n", encoding="utf-8")
        self.env = dict(os.environ, LANE_RUNS_DIR=str(self.runs))

    def tearDown(self) -> None:
        self._tmp.cleanup()

    def append(self, run: str, kind: str, **fields: object) -> None:
        args = [sys.executable, str(TOOL), "append", run, kind]
        args += [f"{key}={value}" for key, value in fields.items()]
        subprocess.run(args, env=self.env, check=True, capture_output=True, text=True)

    def tool(self, *args: str) -> subprocess.CompletedProcess[str]:
        return subprocess.run(
            [sys.executable, str(TOOL), *args],
            env=self.env,
            capture_output=True,
            text=True,
        )

    def clean_run(self, run: str, reason: str = "report_present") -> None:
        self.append(run, "run_started", role="implement")
        self.append(
            run,
            "attempt_finished",
            model="deepseek-v4-flash",
            exit=0,
            log=str(self.model_log),
            bytes=13,
        )
        self.append(
            run,
            "model_completed",
            model="deepseek-v4-flash",
            log=str(self.model_log),
            bytes=13,
        )
        self.append(run, "run_finished", state="landed", reason=reason, exit_code=0)

    def test_clean_run_verifies_and_replays(self) -> None:
        self.clean_run("clean")
        result = self.tool("verify", "clean")
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)

        replay = self.tool("replay", "clean", "--json")
        self.assertEqual(replay.returncode, 0)
        summary = json.loads(replay.stdout)
        self.assertEqual(summary["state"], "landed")
        self.assertEqual(summary["model"], "deepseek-v4-flash")
        self.assertEqual(summary["model_log_bytes"], 13)

    def test_no_report_with_completed_output_is_a_contradiction(self) -> None:
        self.clean_run("contradiction", reason="no_report")
        result = self.tool("verify", "contradiction")
        self.assertEqual(result.returncode, 1)
        self.assertIn("no_report", result.stdout)
        self.assertIn("model log", result.stdout)

    def test_missing_model_log_is_a_contradiction(self) -> None:
        self.append("missing-log", "run_started")
        self.append(
            "missing-log",
            "attempt_finished",
            model="x",
            exit=0,
            log=str(self.runs / "does-not-exist.log"),
            bytes=13,
        )
        self.append("missing-log", "model_completed", model="x")
        self.append("missing-log", "run_finished", state="landed", reason="report_present")
        result = self.tool("verify", "missing-log")
        self.assertEqual(result.returncode, 1)
        self.assertIn("missing on disk", result.stdout)

    def test_policy_violation_with_zero_exit_is_a_contradiction(self) -> None:
        self.clean_run("policy-zero")
        self.append("policy-zero", "policy_violation", role="review", detail="edited a file")
        result = self.tool("verify", "policy-zero")
        self.assertEqual(result.returncode, 1)
        self.assertIn("policy_violation", result.stdout)

    def test_policy_violation_with_nonzero_exit_is_consistent(self) -> None:
        self.append("policy-nonzero", "run_started", role="review")
        self.append("policy-nonzero", "run_finished", state="landed", reason="crash", exit_code=97)
        self.append("policy-nonzero", "policy_violation", role="review", detail="edited a file")
        result = self.tool("verify", "policy-nonzero")
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)


if __name__ == "__main__":
    unittest.main()
