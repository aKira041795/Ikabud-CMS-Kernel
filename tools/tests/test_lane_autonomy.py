"""Integration tests for the bounded repair loop (tools/lane-autonomy.py)."""
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
ORCH = ROOT / "tools" / "lane-autonomy.py"
RUNS = ROOT / ".ai" / "runs"


class LaneAutonomyTest(unittest.TestCase):
    def setUp(self) -> None:
        self.temp = Path(tempfile.mkdtemp(prefix="lane-autonomy-it-"))
        self.task = f"autonomy-it-{os.getpid()}-{int(time.time())}"
        self.flag = self.temp / "flag"
        self.stub = self.temp / "stub.sh"
        self.work = self.temp / "work"
        self.ledger = self.temp / "ledger"

    def tearDown(self) -> None:
        for path in RUNS.glob(f"{self.task}-a*"):
            if path.is_dir():
                shutil.rmtree(path, ignore_errors=True)
            else:
                try:
                    path.unlink()
                except OSError:
                    pass
        for path in Path("/tmp").glob(f"{self.task}-a*"):
            if path.is_dir():
                shutil.rmtree(path, ignore_errors=True)
            else:
                try:
                    path.unlink()
                except OSError:
                    pass
        shutil.rmtree(self.temp, ignore_errors=True)

    def _write_stub(self, body: str) -> None:
        self.stub.write_text("#!/usr/bin/env bash\nset -u\n" + body, encoding="utf-8")
        self.stub.chmod(0o755)

    def _run(self, *extra: str) -> subprocess.CompletedProcess:
        cmd = [
            sys.executable, str(ORCH), "run",
            "--task", self.task,
            "--objective", "create the acceptance flag",
            "--acceptance", f"echo flag-missing; test -f {self.flag}",
            "--pass-looks-like", "the flag exists",
            "--lane-script", str(self.stub),
            "--timeout", "60",
            "--work-dir", str(self.work),
            "--ledger-dir", str(self.ledger),
            *extra,
        ]
        env = dict(os.environ)
        env["AUTONOMY_FLAG"] = str(self.flag)
        return subprocess.run(cmd, cwd=ROOT, capture_output=True, text=True, env=env, timeout=300)

    def _records(self) -> list[dict]:
        path = self.ledger / "ledger.jsonl"
        if not path.is_file():
            return []
        return [json.loads(line) for line in path.read_text(encoding="utf-8").splitlines() if line.strip()]

    def test_repairs_after_one_failed_attempt(self) -> None:
        self._write_stub(
            "if [ \"${LANE_AUTONOMY_ATTEMPT:-0}\" = \"2\" ]; then\n"
            "  touch \"${AUTONOMY_FLAG:?}\"\n"
            "fi\n"
            "echo 'status: PASS'\n"
            "exit 0\n"
        )
        proc = self._run("--max-attempts", "2")
        self.assertEqual(proc.returncode, 0, proc.stderr + proc.stdout)
        self.assertIn("PASS (attempt 2)", proc.stdout)
        records = self._records()
        self.assertEqual(len(records), 1)
        self.assertEqual(records[0]["attempts"], 2)
        self.assertEqual(records[0]["acceptance"], "PASS")
        self.assertEqual(records[0]["owner_interventions"], 0)

    def test_identical_failure_blocks_instead_of_looping(self) -> None:
        self._write_stub("echo 'status: PASS'\nexit 0\n")
        proc = self._run("--max-attempts", "4")
        self.assertEqual(proc.returncode, 3, proc.stderr + proc.stdout)
        self.assertIn("identical failure repeated", proc.stderr)
        records = self._records()
        self.assertEqual(len(records), 1)
        self.assertEqual(records[0]["attempts"], 2)
        self.assertEqual(records[0]["acceptance"], "FAIL")
        self.assertIn("identical failure", records[0]["notes"])

    def test_dry_run_does_not_record(self) -> None:
        self._write_stub("echo 'status: PASS'\nexit 0\n")
        proc = self._run("--max-attempts", "1", "--dry-run")
        self.assertEqual(proc.returncode, 0, proc.stderr + proc.stdout)
        self.assertIn("dry-run wrote", proc.stdout)
        self.assertEqual(self._records(), [])
        self.assertTrue(list(self.work.glob("attempt-1.prompt.md")))


if __name__ == "__main__":
    unittest.main()
