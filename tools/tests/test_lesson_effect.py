#!/usr/bin/env python3
"""Attribution tests: can the store say whether a RULE ever helped?

Run: python3 -m unittest tools.tests.test_lesson_effect -v

WHY THIS FILE EXISTS
Measured 2026-11-19: 806 lessons proposed, 62 active, 0 runs ever credited, 0 retired. The injection
record had existed since 2026-11-17 and nothing scored it, so approval and retirement could only be
opinion. These assertions pin the properties that make the score trustworthy rather than merely
present:

  - a rule is SCORED only with enough decided runs (a rate over two runs invites a decision the
    evidence cannot support);
  - a rule that appeared in N decided runs and passed in NONE is a retirement CANDIDATE, and is
    still only a candidate - `sweep` reports unless asked to apply;
  - an injection with no landing is UNATTRIBUTED, never counted as a pass ("we told it something"
    is not evidence of anything);
  - the tag->landing join survives the model suffix lane-model.sh appends to the log prefix.
"""
from __future__ import annotations

import importlib.util
import json
import os
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
TOOL = ROOT / "tools" / "harpp-experience.py"

_EXPERIENCE = None


def _experience():
    """The shipped module itself, for the pure helpers the CLI wraps."""
    global _EXPERIENCE
    if _EXPERIENCE is None:
        spec = importlib.util.spec_from_file_location("harpp_experience_under_test", TOOL)
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        _EXPERIENCE = module
    return _EXPERIENCE


def _run(args: list[str], env: dict) -> subprocess.CompletedProcess:
    return subprocess.run([sys.executable, str(TOOL), *args],
                          capture_output=True, text=True, cwd=str(ROOT), env=env, check=False)


class LessonEffectTest(unittest.TestCase):
    def setUp(self) -> None:
        self._tmp = tempfile.TemporaryDirectory()
        self.tmp = Path(self._tmp.name)
        self.runs_dir = self.tmp / "runs"
        self.memory_dir = self.tmp / "harpp-memory"
        self.hints = self.tmp / "hints.json"
        self.runs_dir.mkdir(parents=True)
        self.memory_dir.mkdir(parents=True)
        self.hints.write_text("[]", encoding="utf-8")
        self.env = dict(os.environ)
        for key in ("LANE_AUTONOMY_DIR", "HARPP_EXPERIENCE_RUNS", "HARPP_MEMORY_DIR",
                    "HARPP_EXPERIENCE_HINTS", "HARPP_EXPERIENCE_DB", "HARPP_EXPERIENCE_TRIALS"):
            self.env.pop(key, None)
        self.env.update({
            "LANE_AUTONOMY_DIR": str(self.tmp / "autonomy"),
            "HARPP_EXPERIENCE_RUNS": str(self.runs_dir),
            "HARPP_MEMORY_DIR": str(self.memory_dir),
            "HARPP_EXPERIENCE_HINTS": str(self.hints),
            "HARPP_EXPERIENCE_TRIALS": str(self.tmp / "trials.jsonl"),
        })

    def tearDown(self) -> None:
        self._tmp.cleanup()

    # ---------------------------------------------------------------- helpers
    def _landing(self, name: str, *, passed: bool) -> None:
        """A landing with an unambiguous outcome; `_landing_class` decides from these fields."""
        payload = {
            "name": name,
            "state": "landed",
            "completion": "PASS" if passed else "FAIL",
            "acceptance": "PASS" if passed else "FAIL",
            "verdict": "VERIFIED" if passed else "NOT_VERIFIED",
        }
        (self.runs_dir / f"{name}.landed.json").write_text(json.dumps(payload), encoding="utf-8")

    def _injection(self, tag: str, lesson_ids: list[str]) -> None:
        directory = self.memory_dir / "injected"
        directory.mkdir(parents=True, exist_ok=True)
        (directory / f"{tag}.json").write_text(json.dumps({
            "tag": tag, "recorded_at": "2026-11-19T00:00:00+00:00",
            "lessons": lesson_ids, "block_chars": 100,
        }), encoding="utf-8")

    def _effect(self, *extra: str) -> dict:
        result = _run(["lesson", "effect", "--json", *extra], self.env)
        self.assertEqual(result.returncode, 0, result.stderr)
        return json.loads(result.stdout)

    def _rules(self, payload: dict) -> dict[str, dict]:
        return {entry["id"]: entry for entry in payload["rules"]}

    # ------------------------------------------------------------ attribution
    def test_a_rule_that_never_passed_is_a_retirement_candidate(self) -> None:
        # L-DEAD sat in six decided runs, none of which passed. L-GOOD sat in four passes.
        for index in range(6):
            name = f"run-dead-{index}"
            self._landing(name, passed=False)
            self._injection(name, ["L-DEAD"])
        for index in range(4):
            name = f"run-good-{index}"
            self._landing(name, passed=True)
            self._injection(name, ["L-GOOD"])

        payload = self._effect("--min-n", "5")
        rules = self._rules(payload)

        self.assertEqual(payload["retire_candidates"], ["L-DEAD"],
                         "a rule present in six failed runs and no passing run must be flagged")
        self.assertEqual(rules["L-DEAD"]["verdict"], "suspect")
        self.assertEqual(rules["L-GOOD"]["verdict"], "insufficient")
        self.assertFalse(rules["L-GOOD"]["retire_candidate"],
                         "four passing runs is not evidence of anything at min-n 5 - and certainly "
                         "not of a rule that should be retired")

    def test_a_rule_below_min_n_is_refused_rather_than_scored(self) -> None:
        # Three failed runs: enough to look alarming on a rate, not enough to act on.
        for index in range(3):
            name = f"run-thin-{index}"
            self._landing(name, passed=False)
            self._injection(name, ["L-THIN"])

        payload = self._effect("--min-n", "5")
        entry = self._rules(payload)["L-THIN"]

        self.assertEqual(entry["verdict"], "insufficient")
        self.assertFalse(entry["retire_candidate"])
        self.assertNotIn("L-THIN", payload["retire_candidates"])
        self.assertEqual(payload["scored"], 0,
                         "nothing is scored below min-n; the refusal must be visible, not silent")

    def test_an_injection_without_a_landing_is_unattributed_not_a_pass(self) -> None:
        # The tag recorded an injection but the run never landed. Counting that as a pass is the
        # "we told it something" trap this whole section exists to avoid.
        self._injection("run-never-landed", ["L-ORPHAN"])
        for index in range(5):
            name = f"run-ok-{index}"
            self._landing(name, passed=True)
            self._injection(name, ["L-OK"])

        payload = self._effect("--min-n", "5")
        rules = self._rules(payload)

        self.assertEqual(payload["unattributed_runs"], 1)
        self.assertEqual(rules["L-ORPHAN"]["passes"], 0)
        self.assertEqual(rules["L-ORPHAN"]["unresolved"], 1)
        self.assertEqual(rules["L-ORPHAN"]["decided"], 0,
                         "an unattributed injection has no decided outcome")
        self.assertEqual(payload["attributed_runs"], 5)

    def test_the_join_survives_the_model_suffix_on_the_log_prefix(self) -> None:
        # lane-model.sh tags injections with the log prefix, which is `<lane>-<model>`; the landing
        # is named after the lane. An exact-match-only join would silently lose every run.
        for index in range(5):
            lane = f"lane-alpha-{index}"
            self._landing(lane, passed=True)
            self._injection(f"{lane}-openai-codex_gpt-5.6-sol", ["L-SCOPED"])

        payload = self._effect("--min-n", "5")
        entry = self._rules(payload)["L-SCOPED"]

        self.assertEqual(entry["decided"], 5, "the tag->landing join must tolerate the model suffix")
        self.assertEqual(entry["verdict"], "holding")

    # ---------------------------------------------------------------- sweep
    def test_sweep_reports_without_retiring_until_asked(self) -> None:
        for index in range(6):
            name = f"run-sweep-{index}"
            self._landing(name, passed=False)
            self._injection(name, ["L-SWEEP"])

        report = _run(["lesson", "sweep", "--min-n", "5"], self.env)
        self.assertEqual(report.returncode, 0, report.stderr)
        self.assertIn("report only", report.stdout)
        self.assertEqual((self.memory_dir / "events").exists(), False,
                         "sweep must not write a retirement event when it was not asked to")

        applied = _run(["lesson", "sweep", "--min-n", "5", "--apply"], self.env)
        self.assertEqual(applied.returncode, 0, applied.stderr)
        # The lesson does not exist in an empty store, so _set_lesson_status exits 2 per id; what
        # matters here is that --apply is what attempts the write at all.
        self.assertIn("L-SWEEP", applied.stdout + applied.stderr)

    # --------------------------------------------------------------- funnel
    def _propose(self, text: str, scope: str) -> str:
        result = _run(["lesson", "propose", "--text", text, "--scope", scope, "--json"], self.env)
        self.assertEqual(result.returncode, 0, result.stderr)
        return str(json.loads(result.stdout)["id"])

    def _approve(self, lesson_id: str) -> None:
        result = _run(["lesson", "approve", lesson_id], self.env)
        self.assertEqual(result.returncode, 0, result.stderr)

    def _funnel(self, scope: str) -> dict:
        result = _run(["lesson", "funnel", "--scope", scope, "--json"], self.env)
        self.assertEqual(result.returncode, 0, result.stderr)
        return json.loads(result.stdout)

    def test_funnel_names_the_stage_losing_the_knowledge(self) -> None:
        # Two CMS rules, one approved; one universal rule, approved.
        self._approve(self._propose("CMS rule that was approved", "cms"))
        self._propose("CMS rule left as a proposal", "cms")
        self._approve(self._propose("Universal rule that applies everywhere", "any"))

        payload = self._funnel("cms")

        # UNIVERSAL RULES COUNT FOR A MODULE. A cms lane draws on scope=any too, so the pool is
        # 2 cms + 1 any - not 2. Measured 2026-11-19: cms reported 104 scoped lessons but a pool of
        # 575, and the difference (471) is exactly the universal set.
        self.assertEqual(payload["captured"], 3)
        self.assertEqual(payload["active"], 2)
        self.assertEqual(payload["proposed"], 1)
        self.assertEqual(payload["would_inject"], 2)

    def test_funnel_shows_that_retrieval_cannot_reach_what_was_never_approved(self) -> None:
        for index in range(4):
            self._propose(f"Unapproved CMS observation number {index}", "cms")

        payload = self._funnel("cms")

        self.assertEqual(payload["proposed"], 4)
        self.assertEqual(payload["active"], 0)
        self.assertEqual(payload["would_inject"], 0,
                         "an unapproved rule must never reach a lane - that is the funnel's point")
        self.assertEqual(payload["attributed_runs"], 0)

    # ---------------------------------------------------------------- audit
    def test_a_manifest_reference_is_not_read_as_a_javascript_file(self) -> None:
        # THE BUG THIS PINS: with the extensions ordered `ts|js|json`, `modules/ecommerce/module.json`
        # matched as `module.js`, so every manifest reference was reported as a MISSING javascript
        # file and landed in the stale bucket. Caught by the cms audit 2026-11-19.
        paths = _experience().lesson_paths("see `modules/ecommerce/module.json` here")
        self.assertEqual(paths, ["modules/ecommerce/module.json"],
                         "a manifest path must never be truncated to its .js prefix")

    def test_example_paths_are_not_treated_as_claims_about_this_repo(self) -> None:
        self._propose(
            "Run it:\n```bash\nphp tests/import_contract_test.php\n```\n"
            "and put it at modules/my-module/services/Workflow.php",
            "any",
        )
        payload = _run(["lesson", "audit", "--scope", "any", "--json"], self.env)
        self.assertEqual(payload.returncode, 0, payload.stderr)
        audit = json.loads(payload.stdout)
        self.assertEqual(audit["counts"]["stale"], 0,
                         "a CLI usage sample and a `my-module` placeholder are examples, not "
                         "claims; reporting them as stale buries the real ones")
        self.assertEqual(audit["counts"]["prose"], 1)

    def test_audit_separates_a_real_claim_from_a_vanished_one(self) -> None:
        self._propose("`public/index.php` owns the entrypoint", "any")
        self._propose("`src/http/dispatch.php` orchestrates dispatch", "any")

        result = _run(["lesson", "audit", "--scope", "any", "--json"], self.env)
        self.assertEqual(result.returncode, 0, result.stderr)
        audit = json.loads(result.stdout)

        anchored = [e["text"] for e in audit["buckets"]["anchored"]]
        stale = [e["text"] for e in audit["buckets"]["stale"]]

        self.assertTrue(any("public/index.php" in text for text in anchored),
                        "a lesson naming a file that exists is anchored")
        self.assertTrue(any("dispatch.php" in text for text in stale),
                        "a lesson naming a file that is gone is stale - the claim may be obsolete")
        self.assertEqual(audit["counts"]["stale"], 1)


if __name__ == "__main__":
    unittest.main()
