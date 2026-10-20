"""Regression tests for tools/autonomy-ledger.py.

Run:
    python3 -m unittest discover -s tools/tests -p 'test_autonomy_ledger.py'
"""

from __future__ import annotations

import json
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
TOOL = ROOT / "tools" / "autonomy-ledger.py"


class AutonomyLedgerTest(unittest.TestCase):
    def setUp(self) -> None:
        self._tmp = tempfile.TemporaryDirectory()
        self.dir = Path(self._tmp.name)

    def tearDown(self) -> None:
        self._tmp.cleanup()

    def run_tool(self, *args: str) -> subprocess.CompletedProcess[str]:
        return subprocess.run(
            [sys.executable, str(TOOL), "--dir", str(self.dir), *args],
            capture_output=True,
            text=True,
            timeout=20,
        )

    def record(self, task: str, **fields: object) -> None:
        args = [f"{key}={value}" for key, value in fields.items()]
        result = self.run_tool("record", task, *args)
        self.assertEqual(result.returncode, 0, result.stderr)

    def test_report_counts_only_routine_tasks_without_interventions(self) -> None:
        self.record("a", acceptance="PASS", gate="PASS", merged="true", attempts="1")
        self.record("b", acceptance="PASS", gate="PASS", merged="true", attempts="2")
        self.record(
            "c", acceptance="PASS", gate="PASS", merged="true", attempts="1",
            owner_interventions="1", intervention_reasons="merge",
        )
        self.record("d", **{
            "class": "high-risk", "acceptance": "PASS", "gate": "PASS", "merged": "true",
            "owner_interventions": "1", "intervention_reasons": "approval",
        })
        result = self.run_tool("report", "--json")
        self.assertEqual(result.returncode, 0, result.stderr)
        report = json.loads(result.stdout)
        self.assertEqual(report["tasks"], 4)
        self.assertEqual(report["routine"], 3)
        self.assertEqual(report["autonomous"], 2)
        self.assertAlmostEqual(report["autonomy_rate"], 66.6666666, places=4)
        self.assertEqual(report["high_risk"], 1)
        self.assertEqual(report["owner_interventions"], 2)
        self.assertEqual(report["interventions_by_reason"], {"approval": 1, "merge": 1})
        self.assertEqual(report["rollbacks"], 0)

    def test_latest_record_wins_and_window_uses_latest_order(self) -> None:
        self.record("a", acceptance="PASS", gate="PASS", merged="true")
        self.record("b", acceptance="PASS", gate="PASS", merged="true")
        # Re-record a as not merged; the latest record must win.
        result = self.run_tool("record", "a", "merged=false", "owner_interventions=1")
        self.assertEqual(result.returncode, 0, result.stderr)
        report = json.loads(self.run_tool("report", "--json").stdout)
        self.assertEqual(report["autonomous"], 1)  # only b
        # With a window of 1, the latest record is a, so the rate is 0.
        windowed = json.loads(self.run_tool("report", "--json", "--window", "1").stdout)
        self.assertEqual(windowed["tasks"], 1)
        self.assertEqual(windowed["autonomous"], 0)

    def test_rollback_removes_autonomy(self) -> None:
        self.record("a", acceptance="PASS", gate="PASS", merged="true", rolled_back="true")
        report = json.loads(self.run_tool("report", "--json").stdout)
        self.assertEqual(report["autonomous"], 0)

    def test_invalid_class_is_refused(self) -> None:
        result = self.run_tool("record", "bad", "class=unknown")
        self.assertEqual(result.returncode, 2)
        self.assertIn("class", result.stderr)

    def test_list_shows_latest(self) -> None:
        self.record("a", acceptance="PASS", merged="true")
        result = self.run_tool("list")
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn("a", result.stdout)
        self.assertIn("accept=PASS", result.stdout)


if __name__ == "__main__":
    unittest.main()
