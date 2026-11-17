#!/usr/bin/env python3
"""Tests for the HARPP experience CLI (Phase 1).

Run: python3 -m unittest tools.tests.test_harpp_experience -v
These assert OUTCOMES a reader can check: a ledger row carries a run id and
component versions, a comparison refuses to claim an improvement it cannot
support, and a trial is immutable pre-registration.
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
TOOL = ROOT / "tools" / "harpp-experience.py"
LEDGER = ROOT / "tools" / "autonomy-ledger.py"


def _run(args: list[str], env: dict | None = None, cwd: Path | None = None):
    merged = dict(os.environ)
    merged.update(env or {})
    return subprocess.run([sys.executable, *args], capture_output=True, text=True,
                          cwd=str(cwd or ROOT), env=merged, check=False)


class HarppExperienceTest(unittest.TestCase):
    def setUp(self) -> None:
        self._tmp = tempfile.TemporaryDirectory()
        self.tmp = Path(self._tmp.name)
        self.ledger_dir = self.tmp / "autonomy"
        self.runs_dir = self.tmp / "runs"
        self.trials = self.tmp / "trials.jsonl"
        self.runs_dir.mkdir(parents=True)
        self.env = {
            "LANE_AUTONOMY_DIR": str(self.ledger_dir),
            "HARPP_EXPERIENCE_RUNS": str(self.runs_dir),
            "HARPP_EXPERIENCE_TRIALS": str(self.trials),
            "HARPP_EXPERIENCE_HINTS": str(self.tmp / "hints.json"),
            # The memory store is git-tracked and therefore real. Without this override a test
            # would write lessons into the repository's own memory.
            "HARPP_MEMORY_DIR": str(self.tmp / "harpp-memory"),
        }

    def tearDown(self) -> None:
        self._tmp.cleanup()

    # ---------------------------------------------------------------- helpers
    def _ledger_row(self, **fields) -> None:
        args = [str(LEDGER), "--dir", str(self.ledger_dir), "record", fields.pop("task")]
        args.extend(f"{k}={v}" for k, v in fields.items())
        result = _run(args, env=self.env)
        self.assertEqual(result.returncode, 0, result.stderr)

    def _landing(self, name: str, **fields) -> None:
        payload = {"name": name, "state": "landed", "reason": "report_present"}
        payload.update(fields)
        (self.runs_dir / f"{name}.landed.json").write_text(json.dumps(payload), encoding="utf-8")

    # ------------------------------------------------------------ components
    def test_component_hash_matches_git_hash_object(self) -> None:
        """A 'version' must be reproducible with the tool the repo already trusts."""
        result = _run([str(TOOL), "stamp", "--task", "any", "--json"], env=self.env)
        self.assertEqual(result.returncode, 0, result.stderr)
        stamps = json.loads(result.stdout)
        for field in ("component_model_chain", "component_context_pack", "component_harness"):
            self.assertIn(field, stamps, f"{field} missing from the stamp")
        expected = subprocess.run(["git", "hash-object", "tools/model-chain.txt"],
                                  cwd=str(ROOT), capture_output=True, text=True, check=False)
        if expected.returncode == 0:
            self.assertEqual(stamps["component_model_chain"], expected.stdout.strip())

    # ------------------------------------------------------------------ join
    def test_join_counts_run_id_from_the_attempt_convention(self) -> None:
        self._ledger_row(task="demo-task", attempts=2, acceptance="PASS")
        self._landing("demo-task-a2", run_id="20260101T000000-000001")
        result = _run([str(TOOL), "join", "--json"], env=self.env)
        self.assertEqual(result.returncode, 0, result.stderr)
        report = json.loads(result.stdout)
        self.assertEqual(report["rows"], 1)
        self.assertEqual(report["with_explicit_run_id"], 0)
        self.assertEqual(report["resolvable_run_id"], 1)

    def test_join_reports_a_task_it_cannot_resolve_rather_than_hiding_it(self) -> None:
        self._ledger_row(task="orphan-task", attempts=1, acceptance="PASS")
        result = _run([str(TOOL), "join", "--json"], env=self.env)
        report = json.loads(result.stdout)
        self.assertEqual(report["resolvable_run_id"], 0)
        self.assertIn("orphan-task", report["unresolvable_tasks"])

    # -------------------------------------------------------------- compare
    def test_compare_refuses_a_verdict_with_a_single_component_version(self) -> None:
        self._ledger_row(task="t1", acceptance="PASS", component_harness="aaa")
        result = _run([str(TOOL), "compare", "--json"], env=self.env)
        self.assertEqual(result.returncode, 3, "must exit 3 when there is nothing to compare")
        self.assertEqual(json.loads(result.stdout)["verdict"], "insufficient evidence")
        self.assertIn("insufficient evidence", result.stdout)

    def test_compare_refuses_below_min_n(self) -> None:
        for index in range(2):
            self._ledger_row(task=f"a{index}", acceptance="PASS", component_harness="aaa")
            self._ledger_row(task=f"b{index}", acceptance="FAIL", component_harness="bbb")
        result = _run([str(TOOL), "compare", "--min-n", "3", "--json"], env=self.env)
        self.assertEqual(result.returncode, 3)
        self.assertEqual(json.loads(result.stdout)["verdict"], "insufficient evidence")

    def test_compare_reports_a_delta_once_evidence_suffices(self) -> None:
        for index in range(3):
            self._ledger_row(task=f"a{index}", acceptance="PASS", component_harness="aaa")
        for index in range(3):
            self._ledger_row(task=f"b{index}", acceptance="FAIL", component_harness="bbb")
        result = _run([str(TOOL), "compare", "--min-n", "3", "--json"], env=self.env)
        self.assertEqual(result.returncode, 0, result.stderr)
        payload = json.loads(result.stdout)
        self.assertEqual(payload["verdict"], "compared")
        self.assertAlmostEqual(payload["delta_pass_rate"], 1.0)

    # ---------------------------------------------------------------- trial
    def test_trial_registration_is_append_only_and_evaluable(self) -> None:
        first = _run([str(TOOL), "trial", "new", "--component", "component_harness",
                      "--hypothesis", "dependency-aware retrieval improves localisation",
                      "--metric", "pass_rate", "--minimum-delta", "0.05", "--min-n", "2",
                      "--baseline", "aaa", "--candidate", "bbb", "--json"], env=self.env)
        self.assertEqual(first.returncode, 0, first.stderr)
        trial = json.loads(first.stdout)
        self.assertEqual(trial["trial_id"], "T-0001")
        self.assertEqual(trial["status"], "registered")
        self.assertTrue(trial["git_head"] or trial["git_head"] is None)

        _run([str(TOOL), "trial", "new", "--component", "component_harness",
              "--hypothesis", "second hypothesis", "--json"], env=self.env)
        rows = [json.loads(line) for line in self.trials.read_text().splitlines() if line.strip()]
        self.assertEqual([r["trial_id"] for r in rows], ["T-0001", "T-0002"])
        self.assertEqual(rows[0]["hypothesis"], "dependency-aware retrieval improves localisation")

    def test_trial_evaluation_refuses_below_min_n(self) -> None:
        _run([str(TOOL), "trial", "new", "--component", "component_harness",
              "--hypothesis", "h", "--min-n", "5", "--baseline", "aaa",
              "--candidate", "bbb", "--json"], env=self.env)
        self._ledger_row(task="a1", acceptance="PASS", component_harness="aaa")
        self._ledger_row(task="b1", acceptance="FAIL", component_harness="bbb")
        result = _run([str(TOOL), "trial", "evaluate", "T-0001", "--json"], env=self.env)
        self.assertEqual(result.returncode, 3)
        self.assertEqual(json.loads(result.stdout)["verdict"], "insufficient evidence")

    def test_trial_evaluation_confirms_a_measured_improvement(self) -> None:
        _run([str(TOOL), "trial", "new", "--component", "component_harness",
              "--hypothesis", "h", "--minimum-delta", "0.05", "--min-n", "2",
              "--baseline", "aaa", "--candidate", "bbb", "--json"], env=self.env)
        for index in range(2):
            self._ledger_row(task=f"a{index}", acceptance="FAIL", component_harness="aaa")
            self._ledger_row(task=f"b{index}", acceptance="PASS", component_harness="bbb")
        result = _run([str(TOOL), "trial", "evaluate", "T-0001", "--json"], env=self.env)
        self.assertEqual(result.returncode, 0, result.stderr)
        payload = json.loads(result.stdout)
        self.assertEqual(payload["verdict"], "improved")
        self.assertAlmostEqual(payload["observed_delta"], 1.0)

    # --------------------------------------------------------------- report
    def test_report_states_cost_is_uncomputable_instead_of_printing_zero(self) -> None:
        self._ledger_row(task="t1", acceptance="PASS")
        result = _run([str(TOOL), "report", "--json"], env=self.env)
        payload = json.loads(result.stdout)
        self.assertIsNone(payload["ledger"]["wasted_cost_share"])
        self.assertEqual(payload["ledger"]["cost_captured_rows"], 0)

    def test_waste_is_measured_by_retained_change_not_by_the_verdict(self) -> None:
        """A FAIL verdict is not waste when the work landed.

        Measured 2026-11-17: a lane recorded FAIL only because the acceptance test itself was
        broken, while the code it wrote shipped. Scoring that as waste reported 87% on a day that
        produced two working features.
        """
        self._ledger_row(task="good", acceptance="PASS", cost_usd=1.0)
        self._ledger_row(task="fail-but-landed", acceptance="FAIL", cost_usd=3.0)
        self._landing("fail-but-landed-a1", changed_files=2)
        payload = json.loads(_run([str(TOOL), "report", "--json"], env=self.env).stdout)
        self.assertAlmostEqual(payload["ledger"]["wasted_cost_share"], 0.0,
                               msg="a failed verdict with retained edits is not waste")

    def test_waste_counts_money_that_bought_nothing(self) -> None:
        self._ledger_row(task="good", acceptance="PASS", cost_usd=1.0)
        self._ledger_row(task="empty-run", acceptance="FAIL", cost_usd=3.0)
        self._landing("empty-run-a1", changed_files=0)
        payload = json.loads(_run([str(TOOL), "report", "--json"], env=self.env).stdout)
        self.assertAlmostEqual(payload["ledger"]["wasted_cost_share"], 0.75,
                               msg="a run that changed no file did buy nothing")

    def test_repeat_failure_rate_counts_a_recurring_signature(self) -> None:
        self._landing("a1", reason="crash", accept=None, acceptance="FAIL",
                      status_line="Status: FAIL 12 tests", landed_at="2026-01-01T00:00:00+00:00")
        self._landing("a2", reason="crash", acceptance="FAIL",
                      status_line="Status: FAIL 13 tests", landed_at="2026-01-02T00:00:00+00:00")
        self._landing("a3", reason="timeout", acceptance="FAIL",
                      status_line="Status: timeout", landed_at="2026-01-03T00:00:00+00:00")
        result = _run([str(TOOL), "report", "--json"], env=self.env)
        payload = json.loads(result.stdout)["landing"]
        self.assertEqual(payload["failure_landings"], 3)
        self.assertEqual(payload["repeated_failures"], 1)
        self.assertAlmostEqual(payload["repeat_failure_rate"], round(1 / 3, 4))

    def test_accepted_work_labelled_no_report_is_not_counted_as_a_failure(self) -> None:
        """A label defect must never inflate the repeat-failure rate."""
        self._landing("ok1", reason="no_report", acceptance="PASS", completion="PASS",
                      status_line="status: PASS", landed_at="2026-01-01T00:00:00+00:00")
        self._landing("ok2", reason="crash", acceptance="PASS",
                      status_line="status: PASS", landed_at="2026-01-02T00:00:00+00:00")
        self._landing("bad1", reason="report_present", acceptance="FAIL", completion="BLOCKED",
                      status_line="status: BLOCKED", landed_at="2026-01-03T00:00:00+00:00")
        payload = json.loads(_run([str(TOOL), "report", "--json"], env=self.env).stdout)["landing"]
        self.assertEqual(payload["failure_landings"], 1)
        self.assertEqual(payload["contradictory_landings"], 2)

    # ------------------------------------------------ production write path
    def test_lane_autonomy_stamps_every_row_it_records(self) -> None:
        """The row lane-autonomy writes must carry the run id and component versions.

        Loaded through importlib because the module filename contains a dash; this
        exercises the real `_stamp_args` the repair loop calls, not a reimplementation.
        """
        import importlib.util
        from unittest import mock

        spec = importlib.util.spec_from_file_location("lane_autonomy", ROOT / "tools" / "lane-autonomy.py")
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        self._landing("stamped-task-a1", run_id="20260101T000000-000009")

        with mock.patch.dict(os.environ, self.env):
            fields = module._stamp_args("stamped-task", 1)
        keys = {field.split("=", 1)[0] for field in fields}
        self.assertEqual(
            keys,
            {"run_id", "git_head", "component_model_chain", "component_context_pack",
             "component_harness"},
            f"lane-autonomy stamped {sorted(keys)}",
        )
        values = dict(field.split("=", 1) for field in fields)
        self.assertEqual(values["run_id"], "20260101T000000-000009")

    def test_lane_autonomy_record_row_keeps_the_stamps(self) -> None:
        """A stamped row survives the ledger writer without losing fields."""
        import importlib.util
        from unittest import mock

        spec = importlib.util.spec_from_file_location("lane_autonomy", ROOT / "tools" / "lane-autonomy.py")
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        self._landing("kept-task-a1", run_id="20260101T000000-000010")

        class Args:
            ledger_dir = str(self.ledger_dir)
            task_class = "routine"

        with mock.patch.dict(os.environ, self.env):
            module._record("kept-task", Args(), 1, "PASS", "stamped", "2026-01-01T00:00:00+00:00")
        rows = [json.loads(line) for line in
                (self.ledger_dir / "ledger.jsonl").read_text().splitlines() if line.strip()]
        self.assertEqual(len(rows), 1)
        self.assertEqual(rows[0]["run_id"], "20260101T000000-000010")
        for field in ("component_model_chain", "component_context_pack", "component_harness"):
            self.assertTrue(rows[0].get(field), f"{field} lost on the way to the ledger")

    # ----------------------------------------------------- signatures and hints
    def test_signatures_collapse_repeated_failures(self) -> None:
        for index in range(3):
            self._landing(f"rep{index}", reason="timeout", acceptance="SKIPPED",
                          status_line="Status: FAIL 4 tests", landed_at=f"2026-01-0{index + 1}T00:00:00+00:00")
        self._landing("once", reason="crash", acceptance="FAIL",
                      status_line="boom", landed_at="2026-01-05T00:00:00+00:00")
        result = _run([str(TOOL), "signatures", "--json"], env=self.env)
        self.assertEqual(result.returncode, 0, result.stderr)
        signatures = json.loads(result.stdout)
        by_reason = {entry["reason"]: entry for entry in signatures}
        self.assertEqual(by_reason["timeout"]["count"], 3)
        self.assertEqual(by_reason["crash"]["count"], 1)

    def test_hint_add_then_block_contains_the_lesson(self) -> None:
        added = _run([str(TOOL), "hint", "add", "--reason", "timeout",
                      "--text", "budget is tight; verify early", "--json"], env=self.env)
        self.assertEqual(added.returncode, 0, added.stderr)
        # Content-addressed, not sequence-numbered: two branches proposing the same lesson agree on
        # the id instead of inventing H-0001 and H-0001 independently.
        self.assertTrue(json.loads(added.stdout)["id"].startswith("L-"), added.stdout)
        block = _run([str(TOOL), "hints"], env=self.env).stdout
        self.assertIn("KNOWN FAILURE MODES", block)
        self.assertIn("budget is tight; verify early", block)

    def test_the_same_lesson_added_twice_is_recorded_once(self) -> None:
        first = _run([str(TOOL), "hint", "add", "--reason", "timeout",
                      "--text", "identical wording", "--json"], env=self.env)
        second = _run([str(TOOL), "hint", "add", "--reason", "timeout",
                       "--text", "identical wording", "--json"], env=self.env)
        self.assertEqual(json.loads(first.stdout)["id"], json.loads(second.stdout)["id"])
        listing = _run([str(TOOL), "memory", "list", "--json"], env=self.env)
        self.assertEqual(len(json.loads(listing.stdout)), 1, "the same fact was stored twice")

    # ------------------------------------------------------------------ memory store
    def _memory_dir(self) -> Path:
        return self.tmp / "harpp-memory"

    def _add(self, text: str) -> str:
        added = _run([str(TOOL), "hint", "add", "--reason", "timeout",
                      "--text", text, "--json"], env=self.env)
        return json.loads(added.stdout)["id"]

    def test_memory_is_immutable_files_in_a_tracked_tree(self) -> None:
        """Experience must survive a clone, so it cannot live only in a gitignored file."""
        self._add("stored as an event")
        events = list((self._memory_dir() / "events").rglob("*.json"))
        self.assertTrue(events, "no event file was written")
        payload = json.loads(events[0].read_text())
        self.assertEqual(payload["schema_version"], 1)
        self.assertIn("propose", {json.loads(p.read_text())["type"] for p in events})
        # Month-partitioned: one flat directory of a thousand lessons stops being browsable.
        self.assertRegex(str(events[0]), r"events/\d{4}-\d{2}/")

    def test_confirmation_raises_and_contradiction_lowers_influence(self) -> None:
        """Reinforcement and inhibition, not a simulated neuron: only outcome events move these."""
        quiet = self._add("quiet lesson")
        proven = self._add("proven lesson")
        for _ in range(3):
            _run([str(TOOL), "memory", "confirm", proven], env=self.env)
        ranked = json.loads(_run([str(TOOL), "memory", "list", "--json"], env=self.env).stdout)
        self.assertEqual(ranked[0]["id"], proven, "a confirmed lesson did not outrank an unconfirmed one")

        for _ in range(4):
            _run([str(TOOL), "memory", "contradict", proven], env=self.env)
        ranked = json.loads(_run([str(TOOL), "memory", "list", "--json"], env=self.env).stdout)
        self.assertEqual(ranked[0]["id"], quiet, "contradictions did not reduce influence")
        # The record is still there: influence falls, evidence is never erased.
        self.assertIn(proven, {row["id"] for row in ranked})

    def test_injection_is_bounded_so_the_store_can_grow_without_bloating_the_prompt(self) -> None:
        for index in range(9):
            self._add(f"bounded-lesson-marker-{index}")
        block = _run([str(TOOL), "hints"], env=self.env).stdout
        injected = sum(1 for index in range(9) if f"bounded-lesson-marker-{index}" in block)
        self.assertGreater(injected, 0, "nothing was injected")
        self.assertLessEqual(injected, 6, f"the whole store was pasted into the prompt: {injected}")

    def test_incompatible_transitions_are_reported_not_silently_resolved(self) -> None:
        lesson = self._add("a lesson that will be retired then approved")
        _run([str(TOOL), "lesson", "retire", lesson], env=self.env)
        _run([str(TOOL), "lesson", "approve", lesson], env=self.env)
        result = _run([str(TOOL), "memory", "verify", "--json"], env=self.env)
        self.assertEqual(result.returncode, 1, result.stdout)
        self.assertTrue(json.loads(result.stdout)["conflicts"], "approve-after-retire was not reported")

    def test_memory_survives_losing_the_repository(self) -> None:
        """Git on one machine is not a backup: the store must be able to leave and come back."""
        self._add("lesson that must survive a lost disk")
        self._add("second lesson that must survive")
        export = self.tmp / "export.jsonl"
        exported = _run([str(TOOL), "memory", "export", "--out", str(export)], env=self.env)
        self.assertEqual(exported.returncode, 0, exported.stderr)
        self.assertTrue(export.is_file())
        header = json.loads(export.read_text().splitlines()[0])
        self.assertEqual(header["kind"], "harpp-memory-export")
        self.assertEqual(header["schema_version"], 1)

        # A brand new machine: no repository memory at all, only the exported file.
        restored_env = dict(self.env, HARPP_MEMORY_DIR=str(self.tmp / "restored"))
        imported = _run([str(TOOL), "memory", "import", "--from", str(export)], env=restored_env)
        self.assertEqual(imported.returncode, 0, imported.stderr)
        listing = json.loads(_run([str(TOOL), "memory", "list", "--json"], env=restored_env).stdout)
        self.assertEqual(len(listing), 2, "lessons did not survive the round trip")
        self.assertTrue(all(row["status"] == "active" for row in listing), listing)

        again = _run([str(TOOL), "memory", "import", "--from", str(export)], env=restored_env)
        self.assertIn("imported 0", again.stdout, "import is not idempotent")

    def test_an_unknown_export_schema_is_refused_not_guessed(self) -> None:
        export = self.tmp / "future.jsonl"
        export.write_text(json.dumps({"kind": "harpp-memory-export", "schema_version": 99}) + "\n"
                          + json.dumps({"event_id": "x" * 16, "lesson_id": "L-x",
                                        "type": "propose", "recorded_at": "2030-01-01T00:00:00.000000Z"}) + "\n")
        result = _run([str(TOOL), "memory", "import", "--from", str(export)], env=self.env)
        self.assertEqual(result.returncode, 2, result.stdout)
        self.assertIn("unsupported export schema", result.stderr)
        self.assertEqual(list((self.tmp / "harpp-memory").rglob("*.json")), [],
                         "a refused import must not write anything")

    # ------------------------------------------------------------------ experience layer
    def test_the_model_definition_is_built_from_approved_lessons_only(self) -> None:
        """The experience layer may only inherit knowledge a human approved."""
        self._add("an APPROVED rule that must reach the model")
        proposed = _run([str(TOOL), "lesson", "propose", "--reason", "timeout",
                         "--text", "an UNAPPROVED proposal that must not reach it"], env=self.env)
        self.assertEqual(proposed.returncode, 0, proposed.stderr)

        rendered = _run([str(TOOL), "llm", "build", "--dry-run"], env=self.env)
        self.assertEqual(rendered.returncode, 0, rendered.stderr)
        self.assertIn("an APPROVED rule that must reach the model", rendered.stdout)
        self.assertNotIn("UNAPPROVED proposal", rendered.stdout,
                         "a proposal leaked into the model's behaviour before approval")
        self.assertIn("FROM ", rendered.stdout, "no base model declared")

    def test_a_model_with_no_approved_lessons_is_refused(self) -> None:
        result = _run([str(TOOL), "llm", "build", "--dry-run"], env=self.env)
        self.assertEqual(result.returncode, 2, result.stdout)
        self.assertIn("no approved lessons", result.stderr)

    def test_corpus_is_the_finetuning_prerequisite_and_carries_provenance(self) -> None:
        lesson = self._add("a rule that becomes a training example")
        corpus = self.tmp / "corpus.jsonl"
        result = _run([str(TOOL), "llm", "corpus", "--out", str(corpus)], env=self.env)
        self.assertEqual(result.returncode, 0, result.stderr)
        rows = [json.loads(line) for line in corpus.read_text().splitlines() if line.strip()]
        self.assertEqual(len(rows), 1)
        self.assertEqual(rows[0]["lesson_id"], lesson)
        self.assertEqual([m["role"] for m in rows[0]["messages"]], ["system", "user", "assistant"])
        self.assertIn("a rule that becomes a training example", rows[0]["messages"][2]["content"])

    # ------------------------------------------------------------------ task-aware retrieval
    def test_the_task_text_decides_which_rules_are_injected(self) -> None:
        """Six slots must follow the brief, not the id order.

        Measured 2026-11-17: a CMS lane received six Daily Ledger UI rules and none of the 104 CMS
        lessons, because selection ignored the task entirely.
        """
        wanted = self._add("variant templates must never alter the capability data contract")
        for index in range(8):
            self._add(f"unrelated filler rule number {index} about scheduling")
        block = _run([str(TOOL), "hints", "--query",
                      "add a new block variant to the theme card"], env=self.env).stdout
        self.assertIn("variant templates must never alter", block)
        first_rule = next(line for line in block.splitlines() if line.startswith("- ["))
        self.assertIn("variant templates", first_rule,
                      f"the relevant rule was not first: {first_rule}")

    def test_the_module_is_inferred_from_the_brief_without_a_flag(self) -> None:
        scoped = _run([str(TOOL), "lesson", "propose", "--reason", "harvested",
                       "--scope", "cms", "--text",
                       "cms-scoped rule: variants are presentation only", "--json"], env=self.env)
        self.assertEqual(scoped.returncode, 0, scoped.stderr)
        lesson_id = json.loads(scoped.stdout)["id"]
        _run([str(TOOL), "lesson", "approve", lesson_id], env=self.env)

        without = _run([str(TOOL), "hints"], env=self.env).stdout
        self.assertNotIn("cms-scoped rule", without,
                         "a module rule leaked into a lane that named no module")
        with_brief = _run([str(TOOL), "hints", "--query",
                           "fix modules/cms theme rendering"], env=self.env).stdout
        self.assertIn("cms-scoped rule", with_brief,
                      "the module named in the brief did not unlock its rules")

    def test_hints_can_be_filtered_by_reason(self) -> None:
        _run([str(TOOL), "hint", "add", "--reason", "timeout", "--text", "T lesson"], env=self.env)
        _run([str(TOOL), "hint", "add", "--reason", "crash", "--text", "C lesson"], env=self.env)
        block = _run([str(TOOL), "hints", "--reason", "crash"], env=self.env).stdout
        self.assertIn("C lesson", block)
        self.assertNotIn("T lesson", block)

    def test_lane_prompt_carries_the_hints_and_the_pass_rule(self) -> None:
        """The measured lesson has to reach the prompt, or nothing changes in practice."""
        import importlib.util
        from unittest import mock

        _run([str(TOOL), "hint", "add", "--reason", "timeout", "--text", "INJECTED-LESSON"], env=self.env)
        spec = importlib.util.spec_from_file_location("lane_autonomy", ROOT / "tools" / "lane-autonomy.py")
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        with mock.patch.dict(os.environ, self.env):
            prompt = module._task_prompt("t", "obj", "bash test.sh", "it prints OK", "", 1)
        self.assertIn("INJECTED-LESSON", prompt, "the hint block never reached the prompt")
        self.assertIn("status: PASS` ONLY if that command just exited 0", prompt.replace("Status", "status"))

    # ------------------------------------------------------------- usage capture
    def test_stamp_reports_cost_captured_from_lane_events(self) -> None:
        self._landing("paid-task-a1", run_id="20260101T000000-000011")
        usage = json.dumps({"totalTokens": 500, "cost_usd": 0.0025})
        (self.runs_dir / "paid-task-a1.events.jsonl").write_text(
            json.dumps({"type": "attempt_finished", "model": "m", "usage": usage}) + "\n"
            + json.dumps({"type": "attempt_finished", "model": "m", "usage": usage}) + "\n",
            encoding="utf-8")
        result = _run([str(TOOL), "stamp", "--task", "paid-task", "--attempts", "1", "--json"],
                      env=self.env)
        stamps = json.loads(result.stdout)
        self.assertAlmostEqual(float(stamps["cost_usd"]), 0.005)
        self.assertEqual(stamps["tokens"], "1000")

    def test_stamp_omits_cost_when_no_usage_was_captured(self) -> None:
        self._landing("free-task-a1", run_id="20260101T000000-000012")
        stamps = json.loads(_run([str(TOOL), "stamp", "--task", "free-task", "--attempts", "1",
                                  "--json"], env=self.env).stdout)
        self.assertNotIn("cost_usd", stamps, "an absent measurement must not become a zero")

    def test_stamp_cost_is_scoped_to_the_current_run(self) -> None:
        """A re-run must not inherit the spend of an earlier dispatch of the same name."""
        self._landing("rerun-task-a1", run_id="20260101T000000-000013")
        old = json.dumps({"totalTokens": 100, "cost_usd": 1.0})
        new = json.dumps({"totalTokens": 10, "cost_usd": 0.1})
        (self.runs_dir / "rerun-task-a1.events.jsonl").write_text(
            json.dumps({"type": "run_started", "ts": "2026-01-01T00:00:00+00:00"}) + "\n"
            + json.dumps({"type": "attempt_finished", "ts": "2026-01-01T00:01:00+00:00", "usage": old}) + "\n"
            + json.dumps({"type": "run_started", "ts": "2026-01-02T00:00:00+00:00"}) + "\n"
            + json.dumps({"type": "attempt_finished", "ts": "2026-01-02T00:01:00+00:00", "usage": new}) + "\n",
            encoding="utf-8")
        stamps = json.loads(_run([str(TOOL), "stamp", "--task", "rerun-task", "--attempts", "1",
                                  "--since", "2026-01-02T00:00:00+00:00", "--json"], env=self.env).stdout)
        self.assertAlmostEqual(float(stamps["cost_usd"]), 0.1)
        self.assertEqual(stamps["tokens"], "10")

    def test_contradicting_verdict_is_not_resolved_silently(self) -> None:
        self._landing("v1", acceptance="PASS", completion="PASS", verdict="NOT_VERIFIED")
        payload = json.loads(_run([str(TOOL), "report", "--json"], env=self.env).stdout)["landing"]
        self.assertEqual(payload["contradictory_landings"], 1)
        self.assertEqual(payload["failure_landings"], 0)

    def test_record_without_any_outcome_field_is_unknown_not_failure(self) -> None:
        self._landing("legacy-one", reason="report_present")
        payload = json.loads(_run([str(TOOL), "report", "--json"], env=self.env).stdout)["landing"]
        self.assertEqual(payload["unknown_landings"], 1)
        self.assertEqual(payload["failure_landings"], 0)

    # -------------------------------------------------------------- experience index
    def test_index_and_search_round_trip(self) -> None:
        """Recorded experience must be FINDABLE, not merely stored."""
        _run([str(TOOL), "hint", "add", "--reason", "any",
              "--text", "effect rows are append-only and must never be deleted"], env=self.env)
        self._landing("idx-task-a1", run_id="20260101T000000-000020",
                      status_line="Status: FAIL duplicate key on effect row")
        db = str(self.tmp / "experience.db")
        built = _run([str(TOOL), "index", "--db", db, "--json"], env=self.env)
        self.assertEqual(built.returncode, 0, built.stderr)
        self.assertEqual(json.loads(built.stdout)["lessons"], 1)

        found = _run([str(TOOL), "search", "append-only", "--db", db, "--json"], env=self.env)
        self.assertEqual(found.returncode, 0, found.stderr)
        hits = json.loads(found.stdout)
        self.assertTrue(any(h["kind"] == "lesson" for h in hits), "lesson not searchable")

        failure = _run([str(TOOL), "search", "duplicate", "--db", db, "--json"], env=self.env)
        self.assertTrue(any(h["kind"] == "landing" for h in json.loads(failure.stdout)),
                        "a past failure is not searchable")

    def test_search_without_an_index_says_so_instead_of_guessing(self) -> None:
        result = _run([str(TOOL), "search", "anything", "--db", str(self.tmp / "missing.db")],
                      env=self.env)
        self.assertEqual(result.returncode, 2)
        self.assertIn("run `index` first", result.stderr)

    def test_search_finds_a_lesson_through_a_different_word_form(self) -> None:
        """Storing experience is not the point; handing it back is.

        Without porter stemming the index held `reversed` and refused to match `reversing`, so the
        lesson existed and stayed invisible — the exact waste the store is meant to prevent.
        """
        _run([str(TOOL), "hint", "add", "--reason", "any",
              "--text", "Never DELETE a reversed effect to force a re-apply"], env=self.env)
        db = str(self.tmp / "experience.db")
        _run([str(TOOL), "index", "--db", db], env=self.env)
        found = _run([str(TOOL), "search", "reversing", "--db", db, "--json"], env=self.env)
        self.assertEqual(found.returncode, 0, found.stderr)
        self.assertTrue(json.loads(found.stdout), "a stored lesson was not found via its word form")


if __name__ == "__main__":
    unittest.main()
