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
import importlib.util
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


class GroundingWiringTest(unittest.TestCase):
    """Every place the chair discusses or hands off work must be grounded, not just the
    advisor lane — otherwise the discussion still argues from memory."""

    def test_chair_handoff_brief_is_grounded(self):
        sent, queued = [], []
        originals = (harpp_wake.harpp_client.harpp_notify, harpp_wake.harpp_client.poll_messages,
                     harpp_wake.harpp_client.queue_run, harpp_wake.conversation_workspace_dir)
        harpp_wake.harpp_client.harpp_notify = lambda **kw: sent.append(kw) or {"ok": True}
        harpp_wake.harpp_client.poll_messages = lambda **kw: {"ok": True, "data": {"messages": [
            {"id": 1, "harness_session_id": "chatgpt-advisor",
             "body": "Add a stop button; runs already have a CANCELLED state."}]}}
        harpp_wake.harpp_client.queue_run = lambda **kw: queued.append(kw) or {
            "ok": True, "data": {"run": {"state": "QUEUED"}}}
        harpp_wake.conversation_workspace_dir = lambda conv: str(ROOT)
        try:
            reply = harpp_wake._exec_chair_handoff(
                {"kind": "message", "id": 950, "conversation_id": 187}, 187,
                "implement the stop button")
            # The return value is the owner-facing reply; the GROUNDED brief is what gets posted.
            posted = [str(kw.get("body") or "") for kw in sent
                      if int(kw.get("conversation_id") or 0) == 187]
            self.assertTrue(posted, "the handoff must post a brief for the chair")
            self.assertTrue(any("# RETRIEVED REPOSITORY FACTS" in body for body in posted),
                            "the posted brief must carry a retrieved-facts section")
            self.assertTrue(any(CITATION.findall(body) for body in posted),
                            "the posted brief must carry repository citations")
            self.assertIn("queued", reply.lower())
            self.assertEqual(len(queued), 1, "the run must still be queued")
        finally:
            (harpp_wake.harpp_client.harpp_notify, harpp_wake.harpp_client.poll_messages,
             harpp_wake.harpp_client.queue_run,
             harpp_wake.conversation_workspace_dir) = originals

    def test_debate_prompts_are_grounded(self):
        spec = importlib.util.spec_from_file_location(
            "pi_arch_debate_under_test", str(ROOT / "tools/pi-arch-debate.py"))
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        block = module.repo_context("stop button to cancel a running harness run")
        self.assertIn("# RETRIEVED REPOSITORY FACTS", block)
        cites = CITATION.findall(block)
        self.assertTrue(cites, "the debate must argue from retrieved facts")
        for rel, _line in cites:
            self.assertTrue((ROOT / rel).exists(), f"debate cites a missing path: {rel}")
        # A missing query or an unreachable pack must degrade, never break the debate.
        self.assertEqual(module.repo_context(""), "")

    def test_retrieval_workspace_requires_a_repository(self):
        # Measured 2026-10-06: conversation 187 was bound to /var/www/html/legacy, which holds
        # only tools/ and no .git, so every pack came back empty while the code looked correct.
        with tempfile.TemporaryDirectory() as tmp:
            legacy = Path(tmp) / "legacy"
            (legacy / "tools").mkdir(parents=True)
            self.assertEqual(harpp_wake.repo_retrieval_workspace(str(legacy), str(ROOT)), str(ROOT),
                             "a non-repository workspace must never be used for retrieval")
            self.assertEqual(harpp_wake.repo_retrieval_workspace(None, ""), str(ROOT),
                             "with no usable candidate it must fall through to the harness root")
            self.assertTrue(harpp_wake.repo_retrieval_workspace(str(ROOT)))

    def test_handoff_stays_grounded_when_the_conversation_workspace_is_not_a_repo(self):
        with tempfile.TemporaryDirectory() as tmp:
            legacy = Path(tmp) / "legacy"
            (legacy / "tools").mkdir(parents=True)
            sent = []
            originals = (harpp_wake.harpp_client.harpp_notify, harpp_wake.harpp_client.poll_messages,
                         harpp_wake.harpp_client.queue_run, harpp_wake.conversation_workspace_dir)
            harpp_wake.harpp_client.harpp_notify = lambda **kw: sent.append(kw) or {"ok": True}
            harpp_wake.harpp_client.poll_messages = lambda **kw: {"ok": True, "data": {"messages": [
                {"id": 1, "harness_session_id": "chatgpt-advisor",
                 "body": "Runs already have a CANCELLED state."}]}}
            harpp_wake.harpp_client.queue_run = lambda **kw: {
                "ok": True, "data": {"run": {"state": "QUEUED"}}}
            harpp_wake.conversation_workspace_dir = lambda conv: str(legacy)
            try:
                harpp_wake._exec_chair_handoff(
                    {"kind": "message", "id": 951, "conversation_id": 187}, 187,
                    "implement the stop button")
            finally:
                (harpp_wake.harpp_client.harpp_notify, harpp_wake.harpp_client.poll_messages,
                 harpp_wake.harpp_client.queue_run,
                 harpp_wake.conversation_workspace_dir) = originals
            posted = [str(kw.get("body") or "") for kw in sent]
            self.assertTrue(any(CITATION.findall(body) for body in posted),
                            "the handoff must stay grounded even when the conversation's "
                            "workspace is not a repository")

    def test_consult_session_continues_the_discussion(self):
        with tempfile.TemporaryDirectory() as tmp:
            session = Path(tmp) / "session.md"
            for query in ("should we add a stop button?", "what about the 409 on cancel?"):
                proc = subprocess.run(
                    [sys.executable, str(BRIDGE / "chair_consult.py"), "--dry-run",
                     "--session", "demo", "--out", str(session), "--query", query,
                     "--workspace", str(ROOT)],
                    capture_output=True, text=True, timeout=180)
                self.assertEqual(proc.returncode, 0, proc.stderr[-2000:])
            body = session.read_text(encoding="utf-8")
            self.assertIn("should we add a stop button?", body,
                          "a continuing discussion must carry the earlier turn")
            self.assertIn("SO FAR", body)
            # Each turn is grounded for its OWN question, and the carried history must not
            # re-feed the previous pack (that would spend the budget twice).
            self.assertEqual(body.count("# RETRIEVED REPOSITORY FACTS"), 2)


class RagCompletionTest(unittest.TestCase):
    """RAG must COMPOUND: what the chair and ChatGPT concluded before has to be retrievable
    next time, and the harness's own decision memory must reach the prompt."""

    def _seed_repo(self, tmp: str) -> Path:
        ws = Path(tmp) / "ws"
        (ws / "docs").mkdir(parents=True)
        (ws / "docs" / "runs.md").write_text(
            "# Run lifecycle\nRuns are QUEUED, CLAIMED, RUNNING, CANCELLED.\n", encoding="utf-8")
        (ws / ".ai" / "consult").mkdir(parents=True)
        (ws / ".ai" / "consult" / "stop-button.md").write_text(
            "# CHAIR CONSULTATION\nVerdict: cooperative cancellation is required before a stop "
            "button ships.\n", encoding="utf-8")
        (ws / ".ai" / "debate").mkdir(parents=True)
        (ws / ".ai" / "debate" / "plan-1.md").write_text(
            "# Approved plan\nAdd a cancellation handshake the runner polls.\n", encoding="utf-8")
        subprocess.run(["git", "init", "-q"], cwd=ws, check=True)
        subprocess.run(["git", "add", "-A"], cwd=ws, check=True)
        subprocess.run(["git", "-c", "user.email=t@t", "-c", "user.name=t", "commit", "-qm", "seed"],
                       cwd=ws, check=True)
        return ws

    def test_pack_makes_past_consultations_and_plans_retrievable(self):
        with tempfile.TemporaryDirectory() as tmp:
            ws = self._seed_repo(tmp)
            pack = context_pack.build_context_pack(
                "stop button cooperative cancellation handshake", str(ws), budget_chars=4000)
            self.assertIn(".ai/consult/stop-button.md", pack,
                          "a previous consultation on this topic must be retrievable")
            self.assertIn("cooperative cancellation", pack)
            self.assertIn(".ai/debate/plan-1.md", pack,
                          "an approved debate plan must be retrievable")
            # ...and the repository itself is still retrieved.
            self.assertIn("docs/runs.md", pack)
            # Ordering matters: the chair's own prior verdict is the most valuable context.
            self.assertLess(pack.index(".ai/consult/stop-button.md"), pack.index("docs/runs.md"))

    def test_pack_accepts_prefetched_durable_memory(self):
        """Harness memory (approved ADRs/decisions) is fetched by the caller and injected, so
        the pack stays deterministic and offline."""
        with tempfile.TemporaryDirectory() as tmp:
            ws = self._seed_repo(tmp)
            pack = context_pack.build_context_pack(
                "stop button", str(ws), budget_chars=4000,
                extra_facts="DECISION memory: ADR-042 owner-only cancellation requires an audit row")
            self.assertIn("ADR-042 owner-only cancellation requires an audit row", pack,
                          "prefetched durable memory must reach the pack")
            # The injected block is bounded by the same budget as everything else.
            self.assertLessEqual(len(pack), 4000)

    def test_advisor_prompt_includes_harness_memory_when_available(self):
        original = harpp_wake.harpp_client.memory_search
        harpp_wake.harpp_client.memory_search = lambda *a, **kw: {
            "ok": True, "data": {"results": [{"title": "ADR-777 stop control",
                                              "body": "Cancellation must be cooperative."}]}}
        try:
            prompt = harpp_wake.build_advisor_prompt(
                plan="stop button to cancel a run", workspace=str(ROOT))
        finally:
            harpp_wake.harpp_client.memory_search = original
        self.assertIn("ADR-777", prompt,
                      "the advisor must receive the harness's durable decision memory")
        # A failing memory search must never break the prompt.
        harpp_wake.harpp_client.memory_search = lambda *a, **kw: (_ for _ in ()).throw(RuntimeError("down"))
        try:
            degraded = harpp_wake.build_advisor_prompt(plan="anything", workspace=str(ROOT))
        finally:
            harpp_wake.harpp_client.memory_search = original
        self.assertIn("RETRIEVED REPOSITORY FACTS", degraded)


if __name__ == "__main__":
    unittest.main(verbosity=2)
