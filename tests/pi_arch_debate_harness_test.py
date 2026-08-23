#!/usr/bin/env python3

import os
import pathlib
import subprocess
import tempfile
import unittest


ROOT = pathlib.Path(__file__).resolve().parents[1]
HARNESS = ROOT / "tools" / "pi-arch-debate.py"
LONG_INTENT = (
    "Design a bounded architecture proof that checks module boundaries, tenant context, "
    "capability authorization, deterministic verification, rollback behavior, and explicit risks."
)
SENTINEL = "# Existing task\n\nThis contract must survive failed or unapproved debates.\n"


FAKE_PI = r'''#!/usr/bin/env python3
import json
import os
import sys

mode = os.environ.get("FAKE_PI_MODE", "approved")
prompt = sys.argv[-1] if len(sys.argv) > 1 else ""

if mode == "failure":
    print("fake provider authentication failed")
    raise SystemExit(3)
if mode == "empty":
    raise SystemExit(0)

if "senior architect and peer reviewer" in prompt:
    verdict = "REVISIONS" if mode == "revisions" else "APPROVED"
    text = f"VERDICT: {verdict}\nThe contract is deterministic, bounded, and safe for this isolated harness test."
else:
    text = """task: Fake architecture debate contract
objective: Prove that approved output is the only output allowed to replace the task file.
scope:
  allowed: isolated harness verification
  prohibited: production mutations
constraints: preserve the prior task on every failure or revisions verdict
acceptance: failed, empty, and unapproved output cannot replace the task
verification: run the isolated fake provider tests
risk: accidental task truncation
status: READY_FOR_IMPLEMENTATION"""

print(json.dumps({
    "type": "message_update",
    "assistantMessageEvent": {"type": "text_delta", "delta": text},
}))
'''


class DebateHarnessTest(unittest.TestCase):
    def setUp(self) -> None:
        self.tmp = tempfile.TemporaryDirectory(prefix="pi-debate-test-")
        self.root = pathlib.Path(self.tmp.name)
        self.bin_dir = self.root / "bin"
        self.work_dir = self.root / "debate"
        self.task_file = self.root / "current-task.md"
        self.bin_dir.mkdir()
        fake_pi = self.bin_dir / "pi"
        fake_pi.write_text(FAKE_PI)
        fake_pi.chmod(0o755)
        self.task_file.write_text(SENTINEL)

    def tearDown(self) -> None:
        self.tmp.cleanup()

    def run_harness(
        self,
        mode: str,
        *extra: str,
        env_overrides: dict[str, str] | None = None,
    ) -> subprocess.CompletedProcess[str]:
        env = os.environ.copy()
        env.update({
            "PATH": str(self.bin_dir) + os.pathsep + env.get("PATH", ""),
            "FAKE_PI_MODE": mode,
            "DEBATE_WORK_DIR": str(self.work_dir),
            "DEBATE_TASK_FILE": str(self.task_file),
            "DEBATE_MAX_ROUNDS": "1",
            "PI_MODEL_TIMEOUT": "5",
        })
        env.update(env_overrides or {})
        return subprocess.run(
            ["python3", str(HARNESS), "--quiet", "--fast", "--first", "codex", *extra, LONG_INTENT],
            cwd=ROOT,
            env=env,
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            timeout=15,
            check=False,
        )

    def assert_preserved(self) -> None:
        self.assertEqual(SENTINEL, self.task_file.read_text())

    def test_nonzero_provider_exit_preserves_task(self) -> None:
        result = self.run_harness("failure")
        self.assertEqual(2, result.returncode, result.stdout + result.stderr)
        self.assertIn("DEBATE_FAILED", result.stderr)
        self.assert_preserved()
        self.assertEqual("failed\n", (self.work_dir / "approved.txt").read_text())

    def test_zero_text_provider_exit_preserves_task(self) -> None:
        result = self.run_harness("empty")
        self.assertEqual(2, result.returncode, result.stdout + result.stderr)
        self.assertIn("returned no text", result.stderr)
        self.assert_preserved()

    def test_unqualified_model_is_rejected_before_provider_start(self) -> None:
        result = self.run_harness(
            "approved",
            env_overrides={"DEBATE_CODEX_MODEL": "ambiguous-model-alias"},
        )
        self.assertEqual(2, result.returncode, result.stdout + result.stderr)
        self.assertIn("must be provider-qualified", result.stderr)
        self.assert_preserved()

    def test_revisions_verdict_preserves_task_and_saves_draft(self) -> None:
        result = self.run_harness("revisions")
        self.assertEqual(2, result.returncode, result.stdout + result.stderr)
        self.assert_preserved()
        self.assertEqual("revisions\n", (self.work_dir / "approved.txt").read_text())
        self.assertIn("status: READY_FOR_IMPLEMENTATION", (self.work_dir / "round-1-draft.txt").read_text())

    def test_approved_verdict_atomically_replaces_task(self) -> None:
        result = self.run_harness("approved")
        self.assertEqual(0, result.returncode, result.stdout + result.stderr)
        updated = self.task_file.read_text()
        self.assertNotEqual(SENTINEL, updated)
        self.assertIn("task: Fake architecture debate contract", updated)
        self.assertTrue(updated.endswith("\n"))
        self.assertEqual("approved\n", (self.work_dir / "approved.txt").read_text())


if __name__ == "__main__":
    unittest.main()
