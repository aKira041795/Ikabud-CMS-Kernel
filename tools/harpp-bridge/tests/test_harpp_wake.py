#!/usr/bin/env python3
"""Unit tests for the guarded wake scheduler (no network, no model).

Run:  python3 -m unittest tools.harpp-bridge.tests.test_harpp_wake
  or:  harpp self-test
"""
import json
import sys
import tempfile
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

import harpp_wake  # noqa: E402


class HarppWakeTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        base = Path(self.tmp.name)
        harpp_wake.CONFIG_DIR = base
        harpp_wake.LOCK_FILE = base / "wake.lock"
        harpp_wake.PROCESSED_FILE = base / "wake-processed.json"
        harpp_wake.WAKE_LOG = base / "wake.log"
        self.inbox = base / "inbox.jsonl"
        self.inbox.write_text(
            json.dumps({"kind": "message", "id": 1, "conversation_id": 2, "body": "hi"}) + "\n",
            encoding="utf-8")

    def tearDown(self):
        self.tmp.cleanup()

    def _wake(self, **kw):
        defaults = dict(command="echo dry", cooldown=0, max_per_hour=0, timeout=30, enabled=True)
        defaults.update(kw)
        return harpp_wake.maybe_wake(str(self.inbox), **defaults)

    def test_disabled_returns_false(self):
        self.assertFalse(harpp_wake.maybe_wake(str(self.inbox), enabled=False))

    def test_spawns_and_marks_processed_idempotently(self):
        self.assertTrue(self._wake())
        state = harpp_wake.read_state()
        self.assertIn(1, state["messages"])
        # second run: no unprocessed items left -> no re-run
        self.assertFalse(self._wake())

    def test_cooldown_skips(self):
        state = harpp_wake.read_state()
        state["last_wake"] = harpp_wake._now()
        harpp_wake.save_state(state)
        self.assertFalse(self._wake(cooldown=300))

    def test_hourly_limit_skips(self):
        state = harpp_wake.read_state()
        state["wake_hour"] = [harpp_wake._now()] * 6
        harpp_wake.save_state(state)
        self.assertFalse(self._wake(max_per_hour=6))

    def test_lock_contention_skips(self):
        self.assertTrue(harpp_wake.acquire_lock(30))
        try:
            self.assertFalse(self._wake())
        finally:
            harpp_wake.release_lock()

    def test_stale_lock_recovery_dead_pid(self):
        # A lock whose holder PID is dead must be recovered immediately (not wait for TTL).
        harpp_wake.LOCK_FILE.write_text(f"999999 0", encoding="utf-8")  # implausible/dead PID
        self.assertTrue(self._wake())

    def test_stale_lock_recovery_old_timestamp(self):
        # Old timestamp-only format older than 2x timeout recovers via age TTL.
        harpp_wake.LOCK_FILE.write_text(str(harpp_wake._now() - 100000), encoding="utf-8")
        self.assertTrue(self._wake())

    def test_failed_agent_leaves_staged(self):
        self.assertFalse(self._wake(command="false"))
        self.assertNotIn(1, harpp_wake.read_state()["messages"])

    def test_decision_skipped_by_wake(self):
        # Decisions are handled instantly by the deterministic autoprocess layer,
        # so the wake agent must NOT re-process them (they are filtered out).
        self.inbox.write_text(
            json.dumps({"kind": "decision", "id": 9, "decision": "A"}) + "\n", encoding="utf-8")
        self.assertFalse(self._wake())
        self.assertNotIn(9, harpp_wake.read_state()["decisions"])

    def test_task_prompt_contains_items(self):
        prompt = harpp_wake.task_prompt(str(self.inbox), [{"kind": "message", "id": 1, "conversation_id": 2}])
        self.assertIn("kind", prompt)
        self.assertIn(str(self.inbox), prompt)


if __name__ == "__main__":
    unittest.main()
