"""Tests for the pre-dispatch task contract (tools/autonomy-contract.py)."""
from __future__ import annotations

import json
import os
import shutil
import subprocess
import sys
import tempfile
import time
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
CONTRACT = ROOT / "tools" / "autonomy-contract.py"
LOOP = ROOT / "tools" / "lane-autonomy.py"


class AutonomyContractTest(unittest.TestCase):
    def setUp(self) -> None:
        self.temp = Path(tempfile.mkdtemp(prefix="autonomy-contract-it-"))
        self.task = f"contract-it-{os.getpid()}-{int(time.time())}"
        self.flag = self.temp / "flag"
        self.contracts = self.temp / "contracts"

    def tearDown(self) -> None:
        shutil.rmtree(self.temp, ignore_errors=True)

    def _run(self, *extra: str) -> subprocess.CompletedProcess:
        cmd = [
            sys.executable, str(CONTRACT),
            "--task", self.task,
            "--objective", "Reject duplicate import rows with a clear error.",
            "--acceptance", f"test -f {self.flag}",
            "--pass-looks-like", "the flag exists and the command exits 0",
            "--touches", "modules/import/",
            "--contract-dir", str(self.contracts),
            *extra,
        ]
        return subprocess.run(cmd, cwd=ROOT, capture_output=True, text=True, timeout=120)

    def _contract(self) -> dict:
        return json.loads((self.contracts / f"{self.task}.json").read_text(encoding="utf-8"))

    def test_valid_contract_records_the_failing_criterion(self) -> None:
        proc = self._run()
        self.assertEqual(proc.returncode, 0, proc.stderr + proc.stdout)
        contract = self._contract()
        self.assertEqual(contract["task"], self.task)
        self.assertEqual(contract["class"], "routine")
        self.assertTrue(contract["acceptance_fails_now"])
        self.assertNotEqual(contract["probe_exit"], 0)
        self.assertEqual(contract["touches"], ["modules/import"])

    def test_tautology_is_refused(self) -> None:
        proc = self._run("--acceptance", "true")
        self.assertEqual(proc.returncode, 2)
        self.assertIn("cannot fail", proc.stderr)
        self.assertFalse(self.contracts.exists() and (self.contracts / f"{self.task}.json").exists())

    def test_already_passing_criterion_is_refused(self) -> None:
        self.flag.touch()
        proc = self._run()
        self.assertEqual(proc.returncode, 2)
        self.assertIn("already passes", proc.stderr)

    def test_high_risk_path_cannot_be_declared_routine(self) -> None:
        proc = self._run("--touches", "kernel/App.php", "--class", "routine")
        self.assertEqual(proc.returncode, 2)
        self.assertIn("high-risk", proc.stderr)

    def test_high_risk_class_is_accepted(self) -> None:
        proc = self._run("--touches", "kernel/App.php", "--class", "high-risk")
        self.assertEqual(proc.returncode, 0, proc.stderr + proc.stdout)
        self.assertEqual(self._contract()["class"], "high-risk")

    def test_loop_consumes_the_contract(self) -> None:
        proc = self._run()
        self.assertEqual(proc.returncode, 0, proc.stderr + proc.stdout)
        work = self.temp / "work"
        ledger = self.temp / "ledger"
        result = subprocess.run(
            [
                sys.executable, str(LOOP),
                "--contract", str(self.contracts / f"{self.task}.json"),
                "--dry-run",
                "--work-dir", str(work),
                "--ledger-dir", str(ledger),
            ],
            cwd=ROOT, capture_output=True, text=True, timeout=120,
        )
        self.assertEqual(result.returncode, 0, result.stderr + result.stdout)
        prompt = (work / "attempt-1.prompt.md").read_text(encoding="utf-8")
        self.assertIn("Reject duplicate import rows", prompt)
        self.assertFalse((ledger / "ledger.jsonl").exists())


if __name__ == "__main__":
    unittest.main()
