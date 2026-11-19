#!/usr/bin/env python3
"""Tests for experience injection in the lane runner (tools/lane-model.sh).

Run: python3 -m unittest tools.tests.test_lane_experience -v

WHY THIS FILE EXISTS
Measured 2026-11-19: `harpp-experience.py status` reported "0 run(s) given rules" while the hint
block had in fact reached every lane. `lane_model_run` passed `--record "$LANE_EVENT_RUN"` only when
the caller had already set that variable, but the fallback that sets it was exported AFTER the
injection. Rules were therefore injected and never credited to the run, so no rule could ever earn
or lose precision - the loop could not close at all.

The assertions here are about OUTCOMES a reader can check: a run that receives rules is a run the
store can attribute them to. They are deliberately end-to-end (source the script, run it with a stub
model) because the defect was an ordering bug inside the script, not a bad value in an argument.
"""
from __future__ import annotations

import json
import os
import subprocess
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
LANE_MODEL = ROOT / "tools" / "lane-model.sh"
EXPERIENCE = ROOT / "tools" / "harpp-experience.py"

# One rule that matches any query: the test is about attribution, not about ranking quality.
HINT_FIXTURE = [
    {
        "id": "H-TEST-0001",
        "reason": "test",
        "status_match": "",
        "text": "A test fixture rule used to prove that an injected rule is recorded against the run.",
        "added_at": "2026-11-19T00:00:00+00:00",
        "evidence": ["test-lane-experience"],
    }
]


class LaneExperienceAttributionTest(unittest.TestCase):
    def setUp(self) -> None:
        self._tmp = tempfile.TemporaryDirectory()
        self.tmp = Path(self._tmp.name)
        self.memory_dir = self.tmp / "memory"
        self.memory_dir.mkdir(parents=True)
        self.hints = self.tmp / "hints.json"
        self.hints.write_text(json.dumps(HINT_FIXTURE), encoding="utf-8")

    def tearDown(self) -> None:
        self._tmp.cleanup()

    def _env(self) -> dict:
        env = dict(os.environ)
        for key in ("HARPP_MEMORY_DIR", "HARPP_EXPERIENCE_HINTS",
                    "LANE_EVENT_RUN", "LANE_SCOPE", "LANE_MODEL_CHAIN"):
            env.pop(key, None)
        env.update({
            "HARPP_MEMORY_DIR": str(self.memory_dir),
            "HARPP_EXPERIENCE_HINTS": str(self.hints),
            # A stub model: exercises the whole path without spending a token.
            "LANE_MODEL_CMD": "true",
        })
        return env

    def _status(self) -> str:
        result = subprocess.run(
            ["python3", str(EXPERIENCE), "status"],
            capture_output=True, text=True, cwd=str(ROOT), env=self._env(), check=False,
        )
        return result.stdout

    def _runs_given_rules(self, status: str) -> int:
        for line in status.splitlines():
            if "run(s) given rules" in line:
                # e.g. "  used:     1 run(s) given rules · 0 application(s) · ..."
                token = line.split("used:")[-1].strip().split()[0]
                return int(token)
        self.fail("status did not report 'run(s) given rules':\n" + status)

    def test_a_lane_run_is_credited_with_the_rules_it_was_given(self) -> None:
        script = (
            "source tools/lane-model.sh\n"
            'lane_model_run "deepseek-v4-flash" "Refactor the daily-ledger dispatch allowlist" '
            '"/tmp/lane-experience-attribution-probe" >/dev/null 2>&1\n'
            "exit $?\n"
        )
        run = subprocess.run(
            ["bash", "-c", script],
            capture_output=True, text=True, cwd=str(ROOT), env=self._env(), check=False,
        )
        self.assertEqual(run.returncode, 0, f"lane_model_run failed: {run.stderr}")

        # THE OUTCOME: the store can name the run that received the rules. Before the fix this was 0
        # on every run, because --record was evaluated before LANE_EVENT_RUN had a value.
        self.assertGreaterEqual(
            self._runs_given_rules(self._status()), 1,
            "a lane run received rules that the store cannot attribute to any run - the loop "
            "cannot close and every lesson is unmeasurable",
        )

    def test_the_run_identity_is_set_before_injection_not_after(self) -> None:
        # A guard against re-introducing the ordering bug by "tidying" the assignment back down the
        # function. Both must exist, and the assignment must precede the hints call.
        source = LANE_MODEL.read_text(encoding="utf-8")
        assign = source.find('export LANE_EVENT_RUN="${LANE_EVENT_RUN:-$(basename "$prefix")}"')
        hints = source.find('harpp-experience.py" hints')
        self.assertGreaterEqual(assign, 0, "LANE_EVENT_RUN fallback assignment is missing")
        self.assertGreaterEqual(hints, 0, "the hints call is missing")
        self.assertLess(
            assign, hints,
            "LANE_EVENT_RUN is assigned after the hints call, so the run is never credited with the "
            "rules it received (measured 2026-11-19: status reported 0 runs given rules forever)",
        )


if __name__ == "__main__":
    unittest.main()
