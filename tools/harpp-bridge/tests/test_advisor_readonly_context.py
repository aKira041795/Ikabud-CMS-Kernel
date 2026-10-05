"""Advisor lane: enforced read-only (API backend) and real page-prompt context.

OWNER-CONTROLLED ORACLE. Production code is changed to satisfy these assertions, never the
reverse.

RED on the 2026-10-05 base tree, measured, for two separate reasons:

1. `spawn_agent` builds `["pi", "--model", M, "--mode", "json", (--thinking), "--print", PROMPT]`
   (harpp_wake.py:3975-3978) with NO tool restriction. The advisor's read-only contract lives
   only in the prompt text (wake/task-contract-advisor.md:52-64), so a non-compliant or
   prompt-injected model can use whatever tools pi exposes — `bash`, `edit`, `write` among them.
   The lane's stated constraint ("Read-only (hard)", chatgpt-advisor-mode-contract.md) is
   therefore not enforced.
   Delivery is the reason bash was needed: the contract tells the agent to reply through the
   bridge (`harpp msg send ...`). Enforcing read-only therefore also requires the DAEMON to post
   the opinion, so the deliverable is asserted as a property rather than a mechanism.

2. `_advisor_page_pass` builds the ChatGPT-page prompt as the raw concatenated message bodies
   (harpp_wake.py:4331-4342): `ADVISOR_PAGE_PROMPT + plan`. It ignores the durable decisions,
   the conversation context block and the chair ledger that `task_prompt` already assembles for
   the API lane (harpp_wake.py:3750-3767), so the active backend answers plans from prose alone
   with no system state and no statement of what it cannot see.

Each guard carries its must-allow pair, so a restriction that also swallows the dev lane is red.
"""

import json
import tempfile
import unittest
from pathlib import Path
from unittest import mock

import harpp_client
import harpp_wake

ADVISOR_CFG = {
    "enabled": True,
    "conversation_title": "ChatGPT Advisor",
    "model": "openai-ideation/gpt-5.4",
    "cooldown": 0,
    "max_per_hour": 0,
}

READ_ONLY_TOOLS = {"read", "grep", "find", "ls"}
MUTATING_TOOLS = {"bash", "edit", "write"}

OPINION = "1) What is strong\n2) Gaps and risks\n3) Restructuring suggestion\n4) Recommendation"


class AdvisorReadOnlyTest(unittest.TestCase):
    """The API-backend advisor must be unable to modify anything, and must still deliver."""

    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        base = Path(self.tmp.name)
        harpp_wake.CONFIG_DIR = base
        harpp_wake.LOCK_FILE = base / "wake.lock"
        harpp_wake.QUICK_LOCK_FILE = base / "wake-quick.lock"
        harpp_wake.PROCESSED_FILE = base / "wake-processed.json"
        harpp_wake.WAKE_LOG = base / "wake.log"
        harpp_wake.JOBS_FILE = base / "jobs.json"
        harpp_wake.WORKFLOWS_FILE = base / "workflows.json"
        harpp_wake.DECISIONS_FILE = base / "decisions.json"
        harpp_wake._LAST_DAEMON_REPORT_TS = 0.0
        self.inbox = base / "inbox.jsonl"
        self.sent = []
        self._orig = {
            "load_config": harpp_client.load_config,
            "send_message": harpp_client.send_message,
            "harpp_notify": harpp_client.harpp_notify,
            "cancel_run": harpp_client.cancel_run,
            "receipts_path": harpp_client.delivery_receipts_path,
            "ws_dir": harpp_wake.conversation_workspace_dir,
            "stream": harpp_wake._stream_agent_output,
        }
        harpp_client.load_config = lambda config=None: {"advisor": dict(ADVISOR_CFG)}
        harpp_client.send_message = lambda **kw: (self.sent.append(kw) or {"ok": True})
        harpp_client.harpp_notify = lambda **kw: {"ok": True}
        harpp_client.cancel_run = lambda **kw: {"ok": True}
        harpp_client.delivery_receipts_path = lambda: base / "delivery-receipts.jsonl"
        harpp_wake.conversation_workspace_dir = lambda *a, **kw: None

    def tearDown(self):
        harpp_client.load_config = self._orig["load_config"]
        harpp_client.send_message = self._orig["send_message"]
        harpp_client.harpp_notify = self._orig["harpp_notify"]
        harpp_client.cancel_run = self._orig["cancel_run"]
        harpp_client.delivery_receipts_path = self._orig["receipts_path"]
        harpp_wake.conversation_workspace_dir = self._orig["ws_dir"]
        harpp_wake._stream_agent_output = self._orig["stream"]
        self.tmp.cleanup()

    def _item(self, mid=7001, conv=700):
        return {"kind": "message", "id": mid, "conversation_id": conv,
                "conversation_title": "ChatGPT Advisor", "sender_type": "user",
                "body": "Plan: deploy the lane in phases."}

    def _write_inbox(self, records):
        with self.inbox.open("w", encoding="utf-8") as f:
            for r in records:
                f.write(json.dumps(r) + "\n")

    def _run_advisor_capturing_argv(self):
        """Run one advisor pass with the child process faked, returning the pi argv used."""
        calls = []

        class FakeProc:
            pid = 4242
            returncode = 0
            stdout = None

            def wait(self, timeout=None):
                return 0

        def fake_popen(cmd, *a, **kw):
            calls.append(cmd)
            return FakeProc()

        harpp_wake._stream_agent_output = lambda proc, timeout, tee: (OPINION, False)
        with mock.patch.object(harpp_wake.subprocess, "Popen", side_effect=fake_popen):
            items = [self._item()]
            self._write_inbox(items)
            harpp_wake.maybe_wake_advisor(str(self.inbox), items, harpp_wake.advisor_config(
                {"advisor": dict(ADVISOR_CFG)}), max_retries=3)
        return calls[0] if calls else None

    # -- must-refuse: no mutating tool may be granted ----------------------

    def test_advisor_api_spawn_grants_read_only_tools_only(self):
        argv = self._run_advisor_capturing_argv()
        self.assertIsNotNone(argv, "the advisor lane did not spawn at all")
        self.assertIn("--tools", argv,
                      "the advisor agent was spawned with no tool allowlist, so its read-only "
                      "contract is prose only; a model that ignores it can edit the repo")

        allowed = set()
        for flag in ("--tools", "-t"):
            if flag in argv:
                allowed |= {t.strip() for t in str(argv[argv.index(flag) + 1]).split(",") if t.strip()}
        self.assertTrue(allowed, "--tools was passed with an empty allowlist")
        self.assertTrue(allowed <= READ_ONLY_TOOLS,
                        f"advisor was granted non-read-only tools: {sorted(allowed - READ_ONLY_TOOLS)}")
        self.assertFalse(allowed & MUTATING_TOOLS,
                         f"advisor was granted mutating tools: {sorted(allowed & MUTATING_TOOLS)}")

    # -- must-work: the opinion must still reach the owner without a shell -

    def test_advisor_opinion_is_delivered_by_the_daemon(self):
        argv = self._run_advisor_capturing_argv()
        self.assertIsNotNone(argv, "the advisor lane did not spawn at all")
        self.assertTrue(self.sent,
                        "the opinion was not delivered: with the shell/agent-side `harpp msg "
                        "send` removed, the daemon must post the agent's output itself")
        bodies = " ".join(str(c.get("body") or "") for c in self.sent)
        self.assertIn("Restructuring suggestion", bodies,
                      f"the delivered body does not carry the agent's opinion: {bodies[:200]!r}")
        self.assertTrue(any(str(c.get("idempotency_key") or "") == "wake-message-7001"
                            for c in self.sent),
                        "delivery must reuse the stable per-message idempotency key")

    # -- must-allow: the restriction is lane-specific ----------------------

    def test_dev_lane_spawn_is_not_restricted(self):
        """A read-only allowlist must not leak into the ordinary dev/wake agent."""
        calls = []

        class FakeProc:
            pid = 4243
            returncode = 0
            stdout = None

            def wait(self, timeout=None):
                return 0

        harpp_wake._stream_agent_output = lambda proc, timeout, tee: ("", False)
        with mock.patch.object(harpp_wake.subprocess, "Popen",
                               side_effect=lambda cmd, *a, **kw: (calls.append(cmd), FakeProc())[1]):
            harpp_wake.spawn_agent("do some work", command=None, model="deepseek-v4-flash",
                                   timeout=5)
        self.assertTrue(calls, "the dev agent did not spawn")
        self.assertNotIn("--tools", calls[0],
                         "the dev lane lost its tools; the advisor restriction must be "
                         "lane-specific, not global")


class AdvisorPageContextTest(unittest.TestCase):
    """The page backend must answer with the same system state the API lane gets."""

    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        base = Path(self.tmp.name)
        harpp_wake.CONFIG_DIR = base
        harpp_wake.PROCESSED_FILE = base / "wake-processed.json"
        harpp_wake.WAKE_LOG = base / "wake.log"
        harpp_wake.LOCK_FILE = base / "wake.lock"
        harpp_wake.JOBS_FILE = base / "jobs.json"
        harpp_wake.WORKFLOWS_FILE = base / "workflows.json"
        harpp_wake.DECISIONS_FILE = base / "decisions.json"
        harpp_wake._LAST_DAEMON_REPORT_TS = 0.0
        self.prompts = []
        self._orig = {
            "decisions": harpp_wake.recent_decisions_text,
            "context": harpp_wake.conversation_context_block,
        }
        harpp_wake.recent_decisions_text = lambda **kw: "DEC-0042: keep the lane read-only"
        harpp_wake.conversation_context_block = lambda *a, **kw: "earlier: phase 1 internal only"
        self._orig_lock = harpp_wake.acquire_lock
        self._orig_release = harpp_wake.release_lock

    def tearDown(self):
        harpp_wake.recent_decisions_text = self._orig["decisions"]
        harpp_wake.conversation_context_block = self._orig["context"]
        harpp_wake.acquire_lock = self._orig_lock
        harpp_wake.release_lock = self._orig_release
        self.tmp.cleanup()

    def test_page_prompt_carries_decisions_and_context(self):
        item = {"kind": "message", "id": 7100, "conversation_id": 710,
                "conversation_title": "ChatGPT Advisor", "sender_type": "user",
                "body": "Plan: roll out in phases."}
        captured = {}

        class FakeProc:
            pid = 4244
            returncode = 0

            def communicate(self, timeout=None):
                return (json.dumps({"ok": True, "text": "opinion"}), "")

            def kill(self):
                pass

        def fake_popen(cmd, *a, **kw):
            # --prompt <file>; read what the backend would paste into ChatGPT.
            path = cmd[cmd.index("--prompt") + 1]
            captured["prompt"] = Path(path).read_text(encoding="utf-8")
            return FakeProc()

        harpp_wake.acquire_lock = lambda *a, **kw: True
        harpp_wake.release_lock = lambda: None
        try:
            with mock.patch.object(harpp_wake.subprocess, "Popen", side_effect=fake_popen):
                harpp_wake._advisor_page_pass(str(Path(self.tmp.name) / "inbox.jsonl"), [item],
                                              {"backend": "page", "timeout": 5}, workspace=None)
        finally:
            harpp_wake.acquire_lock = self._orig_lock
            harpp_wake.release_lock = self._orig_release

        prompt = captured.get("prompt", "")
        self.assertIn("DEC-0042", prompt,
                      "the page backend ignored the durable decisions the API lane receives")
        self.assertIn("phase 1 internal only", prompt,
                      "the page backend ignored the conversation context the API lane receives")
        self.assertIn("Plan: roll out in phases.", prompt, "the owner's plan must survive")


if __name__ == "__main__":
    unittest.main()
