#!/usr/bin/env python3
"""Chair-owned oracle for the repo-grounded context pack (slice 1).

This file is the CONTRACT, not the implementation: it is written by the chair and must
FAIL on the unchanged tree (no context_pack module exists yet). An implementer must make
it pass without editing it.

What is frozen here:
  * tools/harpp-bridge/context_pack.py :: build_context_pack(query, workspace, budget_chars)
  * tools/harpp-bridge/harpp_wake.py   :: build_advisor_prompt(...)   (pure, no subprocess)
  * tools/harpp-bridge/chair_consult.py --dry-run        (chair invokes ChatGPT on demand)

Grounding is ON BY DEFAULT: the advisor prompt must carry retrieved repository facts, and
must no longer claim it cannot see the repository.
"""
import json
import os
import re
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

BRIDGE = Path(__file__).resolve().parents[1]
ROOT = BRIDGE.parents[1]
sys.path.insert(0, str(BRIDGE))

import context_pack  # noqa: E402  (does not exist before this slice — import fails = RED)
import harpp_wake  # noqa: E402

CITATION = re.compile(r"([\w./-]+\.(?:md|json|php|py|js|sh|sql|disyl|yml|yaml)):(\d+)")


class ContextPackTest(unittest.TestCase):
    def setUp(self):
        self.workspace = str(ROOT)

    def test_pack_cites_real_paths_that_resolve(self):
        """Every citation is path:line and the path must exist — a pack of invented
        paths is worse than no pack, because the model will reason on fiction."""
        pack = context_pack.build_context_pack("run cancellation state machine",
                                               self.workspace, budget_chars=6000)
        self.assertTrue(pack.strip(), "a matching query must retrieve something")
        cites = CITATION.findall(pack)
        self.assertGreaterEqual(len(cites), 3, f"expected several citations, got {cites}")
        for rel, _line in cites:
            self.assertTrue((Path(self.workspace) / rel).exists(),
                            f"cited path does not exist: {rel}")

    def test_pack_follows_the_query_instead_of_dumping_a_fixed_corpus(self):
        pack = context_pack.build_context_pack("daily-ledger shift reconciliation",
                                               self.workspace, budget_chars=6000)
        self.assertIn("daily-ledger", pack,
                      "retrieval must follow the named subject")
        other = context_pack.build_context_pack("cms builder responsive visibility",
                                                self.workspace, budget_chars=6000)
        self.assertNotEqual(pack, other, "two different intents must not get the same pack")

    def test_pack_is_deterministic(self):
        a = context_pack.build_context_pack("capability authorization registry",
                                            self.workspace, budget_chars=6000)
        b = context_pack.build_context_pack("capability authorization registry",
                                            self.workspace, budget_chars=6000)
        self.assertEqual(a, b, "same query + same tree must give a byte-identical pack")

    def test_pack_respects_the_budget(self):
        small = context_pack.build_context_pack("run cancellation state machine",
                                                self.workspace, budget_chars=1200)
        self.assertLessEqual(len(small), 1200)
        self.assertTrue(small.strip(), "a 1200-char budget must still return the best hits")

    def test_pack_degrades_safely(self):
        self.assertEqual(context_pack.build_context_pack("x", "/nonexistent-workspace-xyz"), "")
        self.assertEqual(context_pack.build_context_pack("", self.workspace), "")
        self.assertEqual(context_pack.build_context_pack(None, self.workspace), "")

    def test_pack_never_carries_secrets(self):
        pack = context_pack.build_context_pack("database config bridge key tenant",
                                               self.workspace, budget_chars=8000)
        for needle in ("HARPP_BRIDGE_KEY", "DB_PASSWORD", "BEGIN PRIVATE KEY"):
            self.assertNotIn(needle, pack, f"pack leaked {needle}")


class AdvisorGroundingTest(unittest.TestCase):
    """Grounding is on by default — not opt-in, not a separate call the owner must make."""

    def test_advisor_prompt_carries_retrieved_repo_facts(self):
        prompt = harpp_wake.build_advisor_prompt(
            plan="Add a stop button that cancels a running harness run.",
            workspace=str(ROOT), decisions="", context="", ledger="")
        cites = CITATION.findall(prompt)
        self.assertTrue(cites, "the advisor prompt must carry repository citations")
        for rel, _line in cites:
            self.assertTrue((ROOT / rel).exists(), f"prompt cites a missing path: {rel}")

    def test_advisor_prompt_no_longer_claims_repo_blindness(self):
        prompt = harpp_wake.build_advisor_prompt(
            plan="Anything.", workspace=str(ROOT), decisions="", context="", ledger="")
        self.assertNotIn("cannot see the repository", prompt.lower())
        self.assertRegex(prompt, r"(?i)retrieved repository facts",
                         "the advisor must be told it has retrieved repository facts")


class ChairConsultTest(unittest.TestCase):
    """The chair must be able to open a discussion with ChatGPT on demand, and the composed
    prompt must already be grounded when it does."""

    def test_dry_run_composes_a_grounded_prompt_without_opening_a_browser(self):
        with tempfile.TemporaryDirectory() as tmp:
            out = Path(tmp) / "prompt.txt"
            proc = subprocess.run(
                [sys.executable, str(BRIDGE / "chair_consult.py"), "--dry-run",
                 "--query", "should we add a stop button for harness runs?",
                 "--workspace", str(ROOT), "--out", str(out)],
                capture_output=True, text=True, timeout=120)
            self.assertEqual(proc.returncode, 0, proc.stderr[-2000:])
            composed = out.read_text(encoding="utf-8")
            self.assertIn("stop button", composed)
            self.assertTrue(CITATION.findall(composed),
                            "a chair consult must carry retrieved repository facts")

    def test_dry_run_emits_machine_readable_json(self):
        proc = subprocess.run(
            [sys.executable, str(BRIDGE / "chair_consult.py"), "--dry-run", "--json",
             "--query", "capability authorization registry", "--workspace", str(ROOT)],
            capture_output=True, text=True, timeout=120)
        self.assertEqual(proc.returncode, 0, proc.stderr[-2000:])
        payload = json.loads(proc.stdout.strip().splitlines()[-1])
        self.assertIn("prompt", payload)
        self.assertGreater(payload.get("citations", 0), 0)


if __name__ == "__main__":
    unittest.main(verbosity=2)
