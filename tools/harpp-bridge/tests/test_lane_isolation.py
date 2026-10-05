"""Dedicated-lane isolation — acceptance probes for the ChatGPT Advisor / CMS Assistant lanes.

OWNER-CONTROLLED ORACLE. These assertions are the contract; production code is changed to
satisfy them, never the other way round.

RED on the 2026-10-05 base tree, for three separate reasons, each measured:

1. `harpp_client.autoprocess()` queues EVERY `kind: message` record as a dev run with
   `required_capabilities=["desktop"]` (harpp_client.py:451-458), and the watch loop calls it
   (harpp:387-390) BEFORE any lane routing (harpp:429-445). Proof from the live harness:
   `~/.config/harpp/autoprocess.log` -> "message 1944 queued state=QUEUED ok=True", where 1944
   is a ChatGPT Advisor message. The run is only cancelled on advisor SUCCESS
   (`_cancel_answered_runs`), so while the advisor fails the run stays claimable by a dev runner.
2. Lane identity is title equality (harpp_wake.py:4268-4273), so a renamed lane conversation
   silently becomes ordinary dev work.
3. An advisor item that exhausts its retries is never reported: `record_failure` only increments
   a counter (harpp_wake.py:861-867) and advisor items are removed from `items` (harpp_wake.py:4735)
   before the terminal-failure branch (harpp_wake.py:4766). The contract's acceptance #5
   ("degrades to stage + notify") is therefore unimplemented for this lane.

Each test states its must-refuse / must-allow pair, so a guard that always fires is as red as one
that never fires.
"""

import json
import tempfile
import unittest
from pathlib import Path

import harpp_client
import harpp_wake

LANE_CFG = {
    "advisor": {
        "enabled": True,
        "conversation_title": "ChatGPT Advisor",
        "model": "openai-ideation/gpt-5.4",
        "cooldown": 0,
        "max_per_hour": 0,
    },
    "cms": {
        "enabled": True,
        "conversation_title": "CMS Draft",
        "model": "deepseek/deepseek-v4-flash",
        "cooldown": 0,
        "max_per_hour": 0,
    },
}

ADVISOR_CONV = 950
ADVISOR_RENAME_CONV = 960


class LaneIsolationTest(unittest.TestCase):
    """A dedicated lane owns its conversation; the dev pool must never be handed its messages."""

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

        self.queued = []
        self.notices = []
        self.spawns = []

        self._orig = {
            "queue_run": harpp_client.queue_run,
            "harpp_notify": harpp_client.harpp_notify,
            "load_config": harpp_client.load_config,
            "spawn_agent": harpp_wake.spawn_agent,
            "cancel_run": harpp_client.cancel_run,
            "ws_dir": harpp_wake.conversation_workspace_dir,
            "simple": harpp_wake._is_simple_message,
            "receipts_path": harpp_client.delivery_receipts_path,
        }
        # Hermetic: never read the desktop's real receipt ledger (it is large, and a receipt
        # for a test message id would silently mark the item processed).
        harpp_client.delivery_receipts_path = lambda: base / "delivery-receipts.jsonl"
        harpp_client.queue_run = lambda **kw: (
            self.queued.append(kw) or {"ok": True, "data": {"run": {"state": "QUEUED"}}})
        harpp_client.harpp_notify = lambda **kw: (self.notices.append(kw) or {"ok": True})
        harpp_client.load_config = lambda config=None: LANE_CFG
        harpp_wake.spawn_agent = self._fake_spawn
        harpp_client.cancel_run = lambda **kw: {"ok": True}
        harpp_wake.conversation_workspace_dir = lambda *a, **kw: None
        # Keep the fast/conversational tier out of the way: these tests are about lane routing.
        harpp_wake._is_simple_message = lambda *a, **kw: False

    def tearDown(self):
        harpp_client.queue_run = self._orig["queue_run"]
        harpp_client.harpp_notify = self._orig["harpp_notify"]
        harpp_client.load_config = self._orig["load_config"]
        harpp_wake.spawn_agent = self._orig["spawn_agent"]
        harpp_client.cancel_run = self._orig["cancel_run"]
        harpp_wake.conversation_workspace_dir = self._orig["ws_dir"]
        harpp_wake._is_simple_message = self._orig["simple"]
        harpp_client.delivery_receipts_path = self._orig["receipts_path"]
        self.tmp.cleanup()

    # -- helpers ----------------------------------------------------------

    def _fake_spawn(self, prompt, *, command=None, model, timeout, expected_replies=None,
                    cwd=None, expected_source_ids=None, verify_delivery_receipts=True,
                    open_terminal=False, thinking=None, return_reason=False, require_marker=True):
        self.spawns.append({"model": model})
        return (True, None)

    def _msg(self, mid, title, conv, body="A long enough plan body that is not a simple question.\n"
                                           "Phase 1: internal. Phase 2: pilot. Phase 3: rollout."):
        return {"kind": "message", "id": mid, "conversation_id": conv,
                "conversation_title": title, "sender_type": "user", "body": body}

    def _write_inbox(self, records):
        with self.inbox.open("w", encoding="utf-8") as f:
            for r in records:
                f.write(json.dumps(r) + "\n")

    # -- must-refuse ------------------------------------------------------

    def test_advisor_message_is_not_queued_as_a_dev_run(self):
        rec = self._msg(9001, "ChatGPT Advisor", ADVISOR_CONV)
        self._write_inbox([rec])
        harpp_client.autoprocess([rec])
        self.assertEqual(
            self.queued, [],
            "a ChatGPT Advisor message was queued as a dev run; a plan can be executed as work")

    def test_cms_message_is_not_queued_as_a_dev_run(self):
        rec = self._msg(9002, "CMS Draft", 902, body="Create a draft post about our launch")
        self._write_inbox([rec])
        harpp_client.autoprocess([rec])
        self.assertEqual(
            self.queued, [],
            "a CMS Assistant message was queued as a dev run; a draft request can be executed as work")

    def test_advisor_session_marker_is_not_queued_as_a_dev_run(self):
        # The Messenger advisor toggle marks the conversation; the marker is what routes it.
        # The title deliberately looks ordinary, so the marker alone must decide.
        rec = dict(self._msg(9030, "Design chat", 970))
        rec["harness_session_id"] = "chatgpt-advisor"
        self._write_inbox([rec])
        harpp_client.autoprocess([rec])
        self.assertEqual(
            self.queued, [],
            "a conversation marked chatgpt-advisor was queued as dev work")

    def test_ordinary_session_is_still_queued(self):
        # must-allow pair: an ordinary Messenger session must keep reaching the dev lane.
        rec = dict(self._msg(9031, "Design chat", 971))
        rec["harness_session_id"] = "operator-1234567890"
        self.queued.clear()
        harpp_client.autoprocess([rec])
        self.assertEqual(len(self.queued), 1, "an ordinary session stopped reaching the dev lane")

    def test_lane_message_is_not_queued_when_the_lane_is_disabled(self):
        # Disabling the advisor must not convert its plans into dev work. The lane stays
        # lane-owned; it simply has no worker, so the message stages and warns.
        disabled = {"advisor": dict(LANE_CFG["advisor"], enabled=False)}
        harpp_client.load_config = lambda config=None: disabled
        rec = self._msg(9005, "ChatGPT Advisor", ADVISOR_CONV)
        self._write_inbox([rec])
        harpp_client.autoprocess([rec])
        self.assertEqual(
            self.queued, [],
            "disabling the advisor lane turned its messages into dev work")

    def test_renamed_lane_conversation_is_not_queued_as_a_dev_run(self):
        item = self._msg(9010, "ChatGPT Advisor", ADVISOR_RENAME_CONV)
        self._write_inbox([item])
        items = harpp_wake.unprocessed_items(str(self.inbox))
        harpp_wake.maybe_wake_advisor(str(self.inbox), items, harpp_wake.advisor_config(LANE_CFG))
        self.assertTrue(self.spawns, "precondition: the lane pass did not run, so nothing was bound")

        # The owner renames the conversation in the messenger; the lane identity must not follow
        # the title. Same conversation, different title.
        self.queued.clear()
        harpp_client.autoprocess([self._msg(9011, "Renamed by owner", ADVISOR_RENAME_CONV)])
        self.assertEqual(
            self.queued, [],
            "a bound lane conversation fell through to dev routing after the title changed")

    # -- must-allow -------------------------------------------------------

    def test_ordinary_dev_message_is_still_queued(self):
        self.queued.clear()
        harpp_client.autoprocess([self._msg(9003, "Dev thread", 901, body="fix the login page")])
        self.assertEqual(
            len(self.queued), 1,
            "the isolation guard must not swallow ordinary dev work too")

    def test_unknown_conversation_is_still_queued(self):
        # Fail-closed must mean "the lane's own conversation", not "anything unfamiliar".
        # A real conversation id with no title (id 0 is rejected upstream before this guard).
        self.queued.clear()
        harpp_client.autoprocess([self._msg(9004, "", 903, body="general question")])
        self.assertEqual(len(self.queued), 1, "an untitled conversation must still be dev work")

    # -- bounded retry + notify -------------------------------------------

    def test_advisor_failure_past_max_retries_notifies_the_owner_once(self):
        harpp_wake.spawn_agent = lambda *a, **kw: (False, "page_launch_failed")
        self._write_inbox([self._msg(9020, "ChatGPT Advisor", ADVISOR_CONV, body="Plan: X")])
        for _ in range(5):
            harpp_wake.maybe_wake(str(self.inbox), enabled=True, command=None, cooldown=0,
                                  max_per_hour=0, timeout=5, max_retries=3)
        notices = [n for n in self.notices
                   if int(n.get("conversation_id") or 0) == ADVISOR_CONV]
        self.assertEqual(
            len(notices), 1,
            f"an advisor request that exhausted its retries must reach the owner exactly once "
            f"(got {len(notices)} notices); silently retrying forever is the defect")
