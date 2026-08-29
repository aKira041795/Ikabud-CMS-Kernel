#!/usr/bin/env python3
"""Unit tests for the guarded wake scheduler (no network, no model).

Run:  python3 -m unittest tools.harpp-bridge.tests.test_harpp_wake
  or:  harpp self-test
"""
import json
import os
import runpy
import shutil
import subprocess
import sys
import tempfile
import threading
import time
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

import harpp_wake  # noqa: E402


class HarppDesktopRunnerTest(unittest.TestCase):
    def setUp(self):
        self.module = runpy.run_path(str(Path(__file__).resolve().parent.parent / "harpp"))
        self.client = self.module["harpp_client"]
        self.wake = self.module["harpp_wake"]
        self.originals = {name: getattr(self.client, name) for name in (
            "register_runner", "reconcile_runs", "claim_run", "conversation_context",
            "mark_run_running", "renew_run", "complete_run", "fail_run", "cancel_run")}
        self.original_spawn = self.wake.spawn_agent
        self.runner_globals = self.module["_run_desktop_pass"].__globals__
        self.original_interval = self.runner_globals["RUN_LEASE_RENEW_INTERVAL"]

    def tearDown(self):
        for name, value in self.originals.items():
            setattr(self.client, name, value)
        self.wake.spawn_agent = self.original_spawn
        self.runner_globals["RUN_LEASE_RENEW_INTERVAL"] = self.original_interval

    def _start(self):
        return self.module["_start_desktop_pass"](
            workspace="/tmp", wake_command=None, model="model", timeout=10,
            open_terminal=False)

    def _claimed_run(self):
        return {"data": {"claim_token": "token", "run": {
            "id": 41, "conversation_id": 7, "source_message_id": 987654321,
            "task": "Do the work"}}}

    def test_desktop_runner_renews_runner_heartbeat(self):
        started = threading.Event()
        release = threading.Event()
        completed = threading.Event()
        register_calls = []
        self.client.register_runner = lambda **kw: register_calls.append(kw) or {"ok": True}
        self.client.reconcile_runs = lambda *a, **kw: {"ok": True}
        self.client.claim_run = lambda **kw: self._claimed_run()
        self.client.conversation_context = lambda *a, **kw: {"data": {}}
        self.client.mark_run_running = lambda *a, **kw: {"ok": True}
        self.client.renew_run = lambda *a, **kw: {"ok": True}
        self.client.complete_run = lambda *a, **kw: completed.set() or {"ok": True}
        self.client.fail_run = lambda *a, **kw: {"ok": True}
        self.runner_globals["RUN_LEASE_RENEW_INTERVAL"] = 0.05

        def blocking_spawn(*args, **kwargs):
            started.set()
            release.wait(2)
            return True, None

        self.wake.spawn_agent = blocking_spawn
        self.assertTrue(self._start())
        self.assertTrue(started.wait(1))
        deadline = time.monotonic() + 1
        while len(register_calls) <= 1 and time.monotonic() < deadline:
            time.sleep(0.01)
        release.set()
        self.assertGreater(len(register_calls), 1)
        self.assertTrue(completed.wait(1))

    def test_desktop_runner_skips_already_answered_run(self):
        old_receipts = os.environ.get("HARPP_DELIVERY_RECEIPTS")
        with tempfile.TemporaryDirectory() as tmp:
            receipts = Path(tmp) / "receipts.jsonl"
            os.environ["HARPP_DELIVERY_RECEIPTS"] = str(receipts)
            receipts.write_text(json.dumps({"idempotency_key": "wake-message-4321"}) + "\n",
                                encoding="utf-8")
            calls = []
            self.client.register_runner = lambda **kw: {"ok": True}
            self.client.reconcile_runs = lambda *a, **kw: calls.append(("reconcile", a)) or {"ok": True}
            self.client.claim_run = lambda **kw: {"data": {"claim_token": "token", "run": {
                "id": 55, "conversation_id": 7, "source_message_id": 4321,
                "task": "Already answered"}}}
            self.client.complete_run = lambda *a, **kw: calls.append(("complete", a)) or {"ok": True}
            self.client.fail_run = lambda *a, **kw: calls.append(("fail", a)) or {"ok": True}
            self.client.cancel_run = lambda **kw: calls.append(("cancel", kw)) or {"ok": True}
            self.wake.spawn_agent = lambda *a, **kw: calls.append(("spawn", a)) or (True, None)
            try:
                self.module["_run_desktop_pass"](
                    workspace="/tmp", wake_command=None, model="model", timeout=10,
                    open_terminal=False)
            finally:
                if old_receipts is None:
                    os.environ.pop("HARPP_DELIVERY_RECEIPTS", None)
                else:
                    os.environ["HARPP_DELIVERY_RECEIPTS"] = old_receipts
        names = [call[0] for call in calls]
        self.assertNotIn("spawn", names)
        self.assertNotIn("complete", names)
        self.assertNotIn("fail", names)
        # The runner self-cancels with its claim token so the run retires cleanly
        # instead of being reconciled to STALLED.
        self.assertIn(("cancel", {"run_id": 55, "claim_token": "token"}), calls)

    def test_desktop_runner_pass_returns_while_claimed_run_executes(self):
        started = threading.Event()
        release = threading.Event()
        completed = threading.Event()
        self.client.register_runner = lambda **kw: {"ok": True}
        self.client.reconcile_runs = lambda *a, **kw: {"ok": True}
        self.client.claim_run = lambda **kw: self._claimed_run()
        self.client.conversation_context = lambda *a, **kw: {"data": {}}
        self.client.mark_run_running = lambda *a, **kw: {"ok": True}
        self.client.complete_run = lambda *a, **kw: completed.set() or {"ok": True}
        self.client.fail_run = lambda *a, **kw: {"ok": True}

        def blocking_spawn(*args, **kwargs):
            started.set()
            release.wait(2)
            return True, None

        self.wake.spawn_agent = blocking_spawn
        before = time.monotonic()
        self.assertTrue(self._start())
        elapsed = time.monotonic() - before
        self.assertTrue(started.wait(1))
        self.assertLess(elapsed, 0.2)
        self.assertFalse(completed.is_set())
        release.set()
        self.assertTrue(completed.wait(1))

    def test_desktop_runner_pass_is_single_flight(self):
        started = threading.Event()
        release = threading.Event()
        claims = []
        self.client.register_runner = lambda **kw: {"ok": True}
        self.client.reconcile_runs = lambda *a, **kw: {"ok": True}
        self.client.claim_run = lambda **kw: claims.append(kw) or self._claimed_run()
        self.client.conversation_context = lambda *a, **kw: {"data": {}}
        self.client.mark_run_running = lambda *a, **kw: {"ok": True}
        self.client.complete_run = lambda *a, **kw: {"ok": True}
        self.client.fail_run = lambda *a, **kw: {"ok": True}

        def blocking_spawn(*args, **kwargs):
            started.set()
            release.wait(2)
            return True, None

        self.wake.spawn_agent = blocking_spawn
        self.assertTrue(self._start())
        self.assertTrue(started.wait(1))
        self.assertFalse(self._start())
        self.assertEqual(len(claims), 1)
        release.set()
        deadline = time.monotonic() + 1
        lock = self.module["_DESKTOP_RUN_LOCK"]
        while lock.locked() and time.monotonic() < deadline:
            time.sleep(0.01)
        self.assertFalse(lock.locked())

    def test_desktop_runner_pass_contains_claim_and_execute_errors(self):
        for failure_point in ("claim", "execute"):
            with self.subTest(failure_point=failure_point):
                reconciled = threading.Event()
                self.client.register_runner = lambda **kw: {"ok": True}
                self.client.reconcile_runs = lambda *a, **kw: reconciled.set() or {"ok": True}
                self.client.claim_run = (
                    (lambda **kw: (_ for _ in ()).throw(RuntimeError("claim failed")))
                    if failure_point == "claim" else (lambda **kw: self._claimed_run()))
                self.client.conversation_context = lambda *a, **kw: {"data": {}}
                self.client.mark_run_running = lambda *a, **kw: {"ok": True}
                self.client.complete_run = lambda *a, **kw: {"ok": True}
                self.client.fail_run = lambda *a, **kw: {"ok": True}
                self.wake.spawn_agent = lambda *a, **kw: (_ for _ in ()).throw(RuntimeError("execute failed"))
                self.assertTrue(self._start())
                self.assertTrue(reconciled.wait(1))
                deadline = time.monotonic() + 1
                lock = self.module["_DESKTOP_RUN_LOCK"]
                while lock.locked() and time.monotonic() < deadline:
                    time.sleep(0.01)
                self.assertFalse(lock.locked())


class HarppWakeTest(unittest.TestCase):
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
        self.inbox.write_text(
            json.dumps({"kind": "message", "id": 1, "conversation_id": 2, "body": "hi"}) + "\n",
            encoding="utf-8")

    def tearDown(self):
        harpp_wake._LAST_DAEMON_REPORT_TS = 0.0
        self.tmp.cleanup()

    def _wake(self, **kw):
        defaults = dict(command="echo 'HARPP_WAKE_RESULT replies_sent=1 items_processed=1 delivered_ids=1'",
                        verify_delivery_receipts=False,
                        cooldown=0, max_per_hour=0, timeout=30, enabled=True)
        defaults.update(kw)
        return harpp_wake.maybe_wake(str(self.inbox), **defaults)

    def test_disabled_returns_false(self):
        self.assertFalse(harpp_wake.maybe_wake(str(self.inbox), enabled=False))

    def test_report_daemon_status_posts_summary(self):
        harpp_wake.WORKFLOWS_FILE.write_text(json.dumps({"workflows": {
            "wf-1": {"id": "wf-1", "title": "One", "status": "done", "updated_at": "2026-08-29"},
            "wf-2": {"id": "wf-2", "title": "Two", "status": "running", "updated_at": "2026-08-29"},
            "wf-3": {"id": "wf-3", "title": "Three", "status": "done", "updated_at": "2026-08-29"},
        }}), encoding="utf-8")
        calls = []
        original = harpp_wake.harpp_client.report_daemon_status
        harpp_wake.harpp_client.report_daemon_status = lambda **kw: calls.append(kw) or {"ok": True}
        try:
            self.assertIsNone(harpp_wake.report_daemon_status("desktop-test", workspace="/tmp"))
        finally:
            harpp_wake.harpp_client.report_daemon_status = original
        self.assertEqual(calls[0]["runner_key"], "desktop-test")
        self.assertEqual(calls[0]["workflow_counts"], {"done": 2, "running": 1})
        self.assertLessEqual(len(calls[0]["recent_workflows"]), 5)

    def test_report_daemon_status_throttles(self):
        calls = []
        original = harpp_wake.harpp_client.report_daemon_status
        harpp_wake.harpp_client.report_daemon_status = lambda **kw: calls.append(kw) or {"ok": True}
        try:
            harpp_wake.report_daemon_status("desktop-test")
            harpp_wake.report_daemon_status("desktop-test")
        finally:
            harpp_wake.harpp_client.report_daemon_status = original
        self.assertEqual(len(calls), 1)

    def test_report_daemon_status_tolerates_client_error(self):
        original = harpp_wake.harpp_client.report_daemon_status
        harpp_wake.harpp_client.report_daemon_status = lambda **kw: (_ for _ in ()).throw(RuntimeError("offline"))
        try:
            self.assertIsNone(harpp_wake.report_daemon_status("desktop-test"))
        finally:
            harpp_wake.harpp_client.report_daemon_status = original

    def test_spawns_and_marks_processed_idempotently(self):
        self.assertTrue(self._wake())
        state = harpp_wake.read_state()
        self.assertIn(1, state["messages"])
        # second run: no unprocessed items left -> no re-run
        self.assertFalse(self._wake())

    def test_duplicate_inbox_message_is_processed_once(self):
        duplicate = {"kind": "message", "id": 1, "conversation_id": 2, "body": "updated"}
        with self.inbox.open("a", encoding="utf-8") as stream:
            stream.write(json.dumps(duplicate) + "\n")

        items = harpp_wake.unprocessed_items(str(self.inbox))
        self.assertEqual(len(items), 1)
        self.assertEqual(items[0]["body"], "updated")
        self.assertTrue(self._wake())
        self.assertEqual(harpp_wake.read_state()["messages"].count(1), 1)

    def test_is_simple_message_classification(self):
        # conversational → fast tier
        self.assertTrue(harpp_wake._is_simple_message("hi"))
        self.assertTrue(harpp_wake._is_simple_message("thanks"))
        self.assertTrue(harpp_wake._is_simple_message("what does HARPP do?"))
        self.assertTrue(harpp_wake._is_simple_message("what is the error?"))
        self.assertTrue(harpp_wake._is_simple_message("explain push notifications"))
        self.assertTrue(harpp_wake._is_simple_message("how does the workflow work?"))
        # work / coding → agent tier
        self.assertFalse(harpp_wake._is_simple_message("fix the login"))
        self.assertFalse(harpp_wake._is_simple_message("can you fix the login"))
        self.assertFalse(harpp_wake._is_simple_message("use gpt sol to fix the login"))
        self.assertFalse(harpp_wake._is_simple_message("implement the workflow"))
        self.assertFalse(harpp_wake._is_simple_message("check the error in the code"))
        self.assertFalse(harpp_wake._is_simple_message("push the changes"))
        self.assertFalse(harpp_wake._is_simple_message("review my approach"))
        self.assertFalse(harpp_wake._is_simple_message(""))

    def test_maybe_wake_quick_reply_path(self):
        # Real mode (command=None): a simple message is answered by the fast tier,
        # not the agent, and is marked processed.
        calls = []
        original = harpp_wake._quick_reply
        harpp_wake._quick_reply = lambda item: (calls.append(item["id"]) or True)
        try:
            ok = harpp_wake.maybe_wake(
                str(self.inbox), enabled=True, command=None,
                cooldown=0, max_per_hour=0, timeout=30)
        finally:
            harpp_wake._quick_reply = original
        self.assertTrue(ok)
        self.assertEqual(calls, [1])
        self.assertIn(1, harpp_wake.read_state()["messages"])

    def test_maybe_wake_quick_reply_cancels_answered_run(self):
        self.inbox.write_text(json.dumps({
            "kind": "message", "id": 2, "conversation_id": 2, "body": "hi", "run_id": 72,
        }) + "\n", encoding="utf-8")
        cancelled = []
        original_quick = harpp_wake._quick_reply
        original_cancel = harpp_wake.harpp_client.cancel_run
        harpp_wake._quick_reply = lambda item: True
        harpp_wake.harpp_client.cancel_run = lambda **kw: cancelled.append(kw) or {"ok": True}
        try:
            ok = harpp_wake.maybe_wake(
                str(self.inbox), enabled=True, command=None,
                cooldown=0, max_per_hour=0, timeout=30)
        finally:
            harpp_wake._quick_reply = original_quick
            harpp_wake.harpp_client.cancel_run = original_cancel
        self.assertTrue(ok)
        self.assertEqual(cancelled, [{"run_id": 72}])
        self.assertIn(2, harpp_wake.read_state()["messages"])

    def test_cancel_answered_runs(self):
        calls = []
        original = harpp_wake.harpp_client.cancel_run

        def cancel_run(**kw):
            calls.append(kw)
            if kw["run_id"] == 81:
                raise RuntimeError("client unavailable")
            return {"ok": True}

        harpp_wake.harpp_client.cancel_run = cancel_run
        try:
            harpp_wake._cancel_answered_runs([
                {"run_id": 80}, {}, {"run_id": 81}, {"run_id": 0},
            ])
        finally:
            harpp_wake.harpp_client.cancel_run = original
        self.assertEqual(calls, [{"run_id": 80}, {"run_id": 81}])

    def test_maybe_wake_quick_reply_falls_back_to_agent(self):
        # If the fast-tier reply fails, the simple item falls through to the agent.
        original_quick = harpp_wake._quick_reply
        original_spawn = harpp_wake.spawn_agent
        harpp_wake._quick_reply = lambda item: False
        harpp_wake.spawn_agent = lambda *a, **k: (True, None)
        try:
            ok = harpp_wake.maybe_wake(
                str(self.inbox), enabled=True, command=None,
                cooldown=0, max_per_hour=0, timeout=30)
        finally:
            harpp_wake._quick_reply = original_quick
            harpp_wake.spawn_agent = original_spawn
        self.assertTrue(ok)
        self.assertIn(1, harpp_wake.read_state()["messages"])

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

    # --- job monitor ---

    def _dead_pid(self) -> int:
        p = subprocess.Popen(["true"])
        p.wait()
        return p.pid  # reaped -> os.kill(pid, 0) raises ProcessLookupError

    def _patch_send(self):
        sent = []
        original = harpp_wake.harpp_client.send_message
        harpp_wake.harpp_client.send_message = lambda **kw: sent.append(kw) or {"ok": True}
        return sent, original

    def _patch_notify(self):
        sent, original_send = self._patch_send()
        decisions = []
        original_submit = harpp_wake.harpp_client.submit_decision
        harpp_wake.harpp_client.submit_decision = lambda **kw: decisions.append(kw) or {"ok": True}
        return sent, decisions, original_send, original_submit

    def test_track_list_untrack_job(self):
        jid = harpp_wake.track_job(pid=self._dead_pid(), model="openai-codex/gpt-5.6-sol",
                                   task="test job", conversation_id=7)
        jobs = harpp_wake.list_jobs()
        self.assertEqual(len(jobs), 1)
        self.assertEqual(jobs[0]["id"], jid)
        self.assertEqual(jobs[0]["status"], "running")
        self.assertEqual(jobs[0]["conversation_id"], 7)
        self.assertTrue(harpp_wake.untrack_job(jid))
        self.assertEqual(harpp_wake.list_jobs(), [])

    def test_monitor_reports_success_and_is_idempotent(self):
        logp = str(Path(self.tmp.name) / "job.log")
        Path(logp).write_text("work in progress\n", encoding="utf-8")
        jid = harpp_wake.track_job(pid=self._dead_pid(), model="deepseek/deepseek-v4-pro",
                                   task="run the suite", conversation_id=9, log_path=logp,
                                   marker="ALL HARPP CHECKS PASS")
        with Path(logp).open("a", encoding="utf-8") as stream:
            stream.write("all harpp checks pass\n")  # matching is case-insensitive
        sent, original = self._patch_send()
        try:
            self.assertEqual(harpp_wake.monitor_jobs(), 1)
            self.assertEqual(len(sent), 1)
            self.assertIn("VERIFIED", sent[0]["body"])
            self.assertIn("ALL HARPP CHECKS PASS", sent[0]["body"])
            self.assertEqual(sent[0]["conversation_id"], 9)
            # idempotent: second pass does not re-report
            self.assertEqual(harpp_wake.monitor_jobs(), 0)
            self.assertEqual(len(sent), 1)
            self.assertIn(jid, harpp_wake.jobs_state()["reported"])
        finally:
            harpp_wake.harpp_client.send_message = original

    def test_monitor_reports_failure_when_marker_missing(self):
        logp = str(Path(self.tmp.name) / "bad.log")
        Path(logp).write_text("something broke\n", encoding="utf-8")
        harpp_wake.track_job(pid=self._dead_pid(), model="openai-codex/gpt-5.6-sol",
                             task="fix the form", conversation_id=3, log_path=logp,
                             marker="ALL CHECKS PASS")
        sent, original = self._patch_send()
        try:
            self.assertEqual(harpp_wake.monitor_jobs(), 1)
            self.assertEqual(len(sent), 1)
            self.assertIn("FAILED", sent[0]["body"])
            self.assertIn("did not include its required completion marker", sent[0]["body"])
            self.assertIn("Details:", sent[0]["body"])
        finally:
            harpp_wake.harpp_client.send_message = original

    def test_humanize_job_failure_interprets_known_failures_and_keeps_details(self):
        marker = harpp_wake._humanize_job_failure(
            "marker NOT FOUND: 'verdict: APPROVED'", None, "/tmp/job.log")
        missing = harpp_wake._humanize_job_failure(
            "[Errno 2] No such file or directory: 'pi'", "SOL_TASK status=PASS", "/tmp/job.log")
        exited = harpp_wake._humanize_job_failure("process exited with code 7", None, "/tmp/job.log")
        verification = harpp_wake._humanize_job_failure(
            "success marker 'verdict:' confirmed in the agent's output; "
            "verification command 'python3 -c ...' failed (verdict: REVISIONS)",
            "verdict:", "/tmp/job.log")

        self.assertIn("required completion marker 'verdict: APPROVED'", marker)
        self.assertIn("missing executable 'pi'", missing)
        self.assertIn("ensure the tool is installed/on PATH", missing)
        self.assertIn("exited with code 7; remedy: inspect /tmp/job.log", exited)
        self.assertIn("verification step failed", verification)
        self.assertNotIn("did not include its required completion marker", verification)
        for message in [marker, missing, exited, verification]:
            self.assertIn("Details:", message)

    def test_monitor_skips_alive_pid(self):
        harpp_wake.track_job(pid=os.getpid(), model="deepseek/deepseek-v4-pro",
                             task="still working", conversation_id=1)
        self.assertEqual(harpp_wake.monitor_jobs(), 0)
        self.assertEqual(harpp_wake.list_jobs()[0]["status"], "running")

    def test_monitor_runs_verify_command(self):
        harpp_wake.track_job(pid=self._dead_pid(), model="deepseek/deepseek-v4-flash",
                             task="verify work", conversation_id=4,
                             verify="echo 'verify-ok'")
        sent, original = self._patch_send()
        try:
            self.assertEqual(harpp_wake.monitor_jobs(), 1)
            self.assertIn("VERIFIED", sent[0]["body"])
            self.assertIn("verification passed", sent[0]["body"])
            self.assertIn("Next: workflow monitor will advance automatically", sent[0]["body"])
        finally:
            harpp_wake.harpp_client.send_message = original

    def test_monitor_retries_when_delivery_fails(self):
        harpp_wake.track_job(pid=self._dead_pid(), model="deepseek/deepseek-v4-pro",
                             task="flaky network", conversation_id=5, marker="ok")
        original = harpp_wake.harpp_client.send_message
        harpp_wake.harpp_client.send_message = lambda **kw: (_ for _ in ()).throw(RuntimeError("net down"))
        try:
            # delivery failed -> no report recorded, job stays running for retry
            self.assertEqual(harpp_wake.monitor_jobs(), 0)
            job = harpp_wake.list_jobs()[0]
            self.assertEqual(job["status"], "running")
            self.assertNotIn(job["id"], harpp_wake.jobs_state()["reported"])
            sent = []
            harpp_wake.harpp_client.send_message = lambda **kw: sent.append(kw) or {"ok": True}
            self.assertEqual(harpp_wake.monitor_jobs(), 1)
            self.assertEqual(len(sent), 1)
        finally:
            harpp_wake.harpp_client.send_message = original

    def test_monitor_retries_when_delivery_receipt_is_not_ok(self):
        harpp_wake.track_job(pid=self._dead_pid(), model="model", task="non-ok",
                             conversation_id=5, verify="true")
        original = harpp_wake.harpp_client.send_message
        harpp_wake.harpp_client.send_message = lambda **kw: {"ok": False, "error": "rejected"}
        try:
            self.assertEqual(harpp_wake.monitor_jobs(), 0)
            job = harpp_wake.list_jobs()[0]
            self.assertEqual(job["status"], "running")
            self.assertNotIn(job["id"], harpp_wake.jobs_state()["reported"])
        finally:
            harpp_wake.harpp_client.send_message = original

    # --- job launch (one-step spawn + track) ---

    def test_launch_job_spawns_tracks_and_confirms(self):
        sent, original = self._patch_send()
        try:
            jid, proc = harpp_wake.launch_job(
                model="deepseek/deepseek-v4-pro", task="quick test", conversation_id=7,
                command=["echo", "hello"])
            proc.wait(timeout=10)
            job = next(j for j in harpp_wake.list_jobs() if j["id"] == jid)
            self.assertEqual(job["pid"], proc.pid)
            self.assertEqual(job["conversation_id"], 7)
            self.assertEqual(job["status"], "running")
            self.assertGreater(len(sent), 0)
            self.assertEqual(sent[0]["conversation_id"], 7)
            self.assertTrue(sent[0]["body"].startswith("[PROGRESS]"))
            self.assertIn("monitoring started", sent[0]["body"])
            self.assertIn(jid, sent[0]["body"])
        finally:
            harpp_wake.harpp_client.send_message = original

    def test_launch_job_captures_pid_identity(self):
        _, proc = harpp_wake.launch_job(
            model="deepseek/deepseek-v4-pro", task="identity test", conversation_id=7,
            command=["true"], quiet=True)
        try:
            job = harpp_wake.list_jobs()[-1]
            self.assertEqual(job["pid"], proc.pid)
            self.assertEqual(job["pid_identity"], harpp_wake._pid_identity(proc.pid))
        finally:
            try:
                proc.wait(timeout=10)
            except Exception:
                pass

    def test_launch_job_timeout_kills_and_reports(self):
        sent, original = self._patch_send()
        proc = None
        try:
            jid, proc = harpp_wake.launch_job(
                model="deepseek/deepseek-v4-pro", task="timeout test", conversation_id=7,
                command=["sleep", "60"], quiet=True, timeout=1)
            time.sleep(1.3)
            for _ in range(6):
                harpp_wake.monitor_jobs()
                time.sleep(0.15)
                if any(j["id"] == jid and j["status"] == "finished" for j in harpp_wake.list_jobs()):
                    break
            self.assertFalse(harpp_wake._pid_alive(proc.pid, None))
            self.assertIn(jid, harpp_wake.jobs_state()["reported"])
            self.assertGreater(len(sent), 0)
            self.assertIn("FAILED", sent[0]["body"])  # killed -> no marker -> FAILED
        finally:
            harpp_wake.harpp_client.send_message = original
            if proc and harpp_wake._pid_alive(proc.pid, None):
                try:
                    os.killpg(proc.pid, 9)
                except OSError:
                    proc.kill()

    # --- marker accuracy (last occurrence wins) ---

    def _job_with_log(self, content, marker="JOB status=PASS"):
        p = Path(self.tmp.name) / "m.log"
        p.write_text(content, encoding="utf-8")
        st = p.stat()
        return {"marker": marker, "log_path": str(p), "log_offset": 0,
                "log_identity": f"{st.st_dev}:{st.st_ino}"}

    def test_marker_uses_last_occurrence(self):
        # intermediate PASS then final FAIL -> expected PASS not satisfied
        self.assertFalse(harpp_wake._marker_found(
            self._job_with_log("JOB status=PASS\nmore\nJOB status=FAIL\n")))
        # intermediate FAIL then final PASS -> satisfied
        self.assertTrue(harpp_wake._marker_found(
            self._job_with_log("JOB status=FAIL\nmore\nJOB status=PASS\n")))
        # final FAIL with expected FAIL -> satisfied
        self.assertTrue(harpp_wake._marker_found(
            self._job_with_log("JOB status=PASS\nJOB status=FAIL\n", marker="JOB status=FAIL")))
        # plain marker without a status value -> presence suffices
        self.assertTrue(harpp_wake._marker_found(
            self._job_with_log("some output\nDONE\n", marker="DONE")))
        self.assertFalse(harpp_wake._marker_found(
            self._job_with_log("no marker here\n", marker="DONE")))

    def test_marker_reassembles_split_pi_json_text_deltas_after_offset(self):
        p = Path(self.tmp.name) / "pi.log"
        old = json.dumps({"type": "message_update", "assistantMessageEvent": {
            "type": "text_delta", "delta": "JOB status=FAIL"}}) + "\n"
        current = "".join(json.dumps({"type": "message_update", "assistantMessageEvent": {
            "type": "text_delta", "delta": delta}}) + "\n" for delta in (
                "work complete\nJOB status=", "PASS"))
        p.write_text(old + current, encoding="utf-8")
        st = p.stat()
        job = {"marker": "JOB status=PASS", "log_path": str(p),
               "log_offset": len(old.encode("utf-8")),
               "log_identity": f"{st.st_dev}:{st.st_ino}"}
        self.assertTrue(harpp_wake._marker_found(job))
        self.assertNotIn("status=FAIL", harpp_wake._job_output_text(job))

    def test_remediation_extracts_full_assistant_text_without_protocol_noise(self):
        p = Path(self.tmp.name) / "review.jsonl"
        remediation = "BLOCKER-FIRST\n" + ("detail " * 700) + "\nSOL_REVIEW status=FAIL"
        records = [
            {"type": "toolcall_start", "name": "shell", "arguments": "secret protocol noise"},
            {"type": "message_update", "assistantMessageEvent": {
                "type": "text_delta", "delta": remediation}},
        ]
        p.write_text("\n".join(json.dumps(record) for record in records) + "\n", encoding="utf-8")
        st = p.stat()
        job = {"log_path": str(p), "log_offset": 0,
               "log_identity": f"{st.st_dev}:{st.st_ino}"}
        extracted = harpp_wake._remediation_from({"log_path": str(p)}, job)
        self.assertIn("BLOCKER-FIRST", extracted)
        self.assertIn("SOL_REVIEW status=FAIL", extracted)
        self.assertNotIn("secret protocol noise", extracted)

    # --- wake terminal (visual feedback) ---

    def test_open_agent_terminal_opens_when_emulator_present(self):
        called = []
        original_detect = harpp_wake._detect_terminal
        original_popen = harpp_wake.subprocess.Popen
        harpp_wake._detect_terminal = lambda: ("xterm", ["-e"])
        harpp_wake.subprocess.Popen = lambda *a, **k: called.append(a)
        try:
            ok = harpp_wake.open_agent_terminal(12345, "/tmp/some.log")
            self.assertTrue(ok)
            flat = " ".join(str(x) for x in called[0])
            self.assertIn("xterm", flat)
            self.assertIn("--pid=12345", flat)
            self.assertIn("some.log", flat)
        finally:
            harpp_wake._detect_terminal = original_detect
            harpp_wake.subprocess.Popen = original_popen

    def test_open_agent_terminal_no_emulator_returns_false(self):
        original_detect = harpp_wake._detect_terminal
        harpp_wake._detect_terminal = lambda: (None, None)
        try:
            self.assertFalse(harpp_wake.open_agent_terminal(12345, "/tmp/x.log"))
        finally:
            harpp_wake._detect_terminal = original_detect

    def test_spawn_agent_tees_output_for_terminal(self):
        calls = []
        original = harpp_wake.open_agent_terminal
        harpp_wake.open_agent_terminal = lambda *a, **k: calls.append(a)
        try:
            ok = harpp_wake.spawn_agent(
            "prompt", command="echo 'HARPP_WAKE_RESULT replies_sent=1 items_processed=1 delivered_ids=1'",
                model="deepseek/deepseek-v4-pro", timeout=30, expected_replies=1,
                open_terminal=True)
            self.assertTrue(ok)
            self.assertEqual(len(calls), 1)
            tee_log = Path(harpp_wake.CONFIG_DIR) / "wake-agent.log"
            self.assertIn("HARPP_WAKE_RESULT", tee_log.read_text(encoding="utf-8"))
        finally:
            harpp_wake.open_agent_terminal = original

    def test_open_capped_log_truncates_oversized_file(self):
        p = Path(harpp_wake.CONFIG_DIR) / "cap.log"
        p.write_text("x" * 1024, encoding="utf-8")
        with harpp_wake._open_capped_log(p, max_bytes=512) as f:
            f.write("tail\n")
        self.assertLess(p.stat().st_size, 128)
        self.assertTrue(p.read_text(encoding="utf-8").endswith("tail\n"))

    def test_open_capped_log_keeps_small_file(self):
        p = Path(harpp_wake.CONFIG_DIR) / "small.log"
        p.write_text("keep\n", encoding="utf-8")
        with harpp_wake._open_capped_log(p, max_bytes=512) as f:
            f.write("more\n")
        self.assertEqual(p.read_text(encoding="utf-8"), "keep\nmore\n")

    def test_spawn_agent_caps_oversized_tee_log(self):
        original = harpp_wake.open_agent_terminal
        original_cap = harpp_wake.LOG_MAX_BYTES
        harpp_wake.open_agent_terminal = lambda *a, **k: None
        harpp_wake.LOG_MAX_BYTES = 1024
        tee_log = Path(harpp_wake.CONFIG_DIR) / "wake-agent.log"
        tee_log.write_text("junk" * 4096, encoding="utf-8")  # > capped threshold
        try:
            ok = harpp_wake.spawn_agent(
            "prompt", command="echo 'HARPP_WAKE_RESULT replies_sent=1 items_processed=1 delivered_ids=1'",
                model="deepseek/deepseek-v4-pro", timeout=30, expected_replies=1,
                open_terminal=True)
            self.assertTrue(ok)
            self.assertLess(tee_log.stat().st_size, 4096)
            self.assertIn("HARPP_WAKE_RESULT", tee_log.read_text(encoding="utf-8"))
        finally:
            harpp_wake.open_agent_terminal = original
            harpp_wake.LOG_MAX_BYTES = original_cap

    def test_stream_agent_output_waits_for_reader_after_fast_exit(self):
        """A completed child is not complete until its stdout reader is drained."""
        original_thread = harpp_wake.threading.Thread
        joined = []

        class DelayedReader:
            def __init__(self, *, target, daemon):
                self.target = target
                self.running = False

            def start(self):
                self.running = True

            def join(self, timeout=None):
                joined.append(timeout)
                if self.running:
                    self.target()
                    self.running = False

            def is_alive(self):
                return self.running

        proc = subprocess.Popen(
                ["sh", "-c", "printf 'HARPP_WAKE_RESULT replies_sent=1 items_processed=1 delivered_ids=1\\n'"],
            stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True,
            start_new_session=True)
        harpp_wake.threading.Thread = DelayedReader
        try:
            out, timed_out = harpp_wake._stream_agent_output(proc, timeout=30, tee=None)
            self.assertFalse(timed_out)
            self.assertEqual(proc.returncode, 0)
            self.assertIn("HARPP_WAKE_RESULT replies_sent=1", out)
            self.assertEqual(joined, [harpp_wake.OUTPUT_DRAIN_TIMEOUT])
        finally:
            harpp_wake.threading.Thread = original_thread
            if proc.poll() is None:
                proc.kill()
                proc.wait(timeout=10)

    # --- governed multi-stage workflows ---

    def test_default_governed_manifest_uses_strong_separation_and_real_checks(self):
        workflows = Path(harpp_wake.__file__).resolve().parent / "workflows"
        path = workflows / "governed-loop.json"
        manifest = json.loads(path.read_text(encoding="utf-8"))
        stages = {stage["name"]: stage for stage in manifest["stages"]}
        self.assertEqual(stages["architect"]["model"], "openai-codex/gpt-5.6-sol")
        self.assertEqual(stages["implement"]["model"], "deepseek/deepseek-v4-pro")
        self.assertEqual(stages["review"]["model"], "openai-codex/gpt-5.6-sol")
        self.assertEqual(stages["release-gate"]["model"], "openai-codex/gpt-5.6-sol")
        self.assertTrue(all(stage.get("verify") not in (None, "", "true", ":")
                            for stage in manifest["stages"]))
        for manifest_path in workflows.glob("*.json"):
            candidate = json.loads(manifest_path.read_text(encoding="utf-8"))
            self.assertTrue(all(stage.get("verify") not in (None, "", "true", ":")
                                for stage in candidate["stages"]), manifest_path.name)

    def _write_jobs(self, jobs):
        with harpp_wake._jobs_lock():
            jstate = harpp_wake._jobs_state_unlocked()
            jstate["jobs"] = jobs
            harpp_wake._save_jobs_state_unlocked(jstate)

    def _stage(self, name, job_id=None, status="pending"):
        return {"name": name, "model": "m", "job_id": job_id, "status": status,
                "timeout": 10, "marker": None, "verify": None, "commit": False,
                "prompt_file": None, "prompt": "p"}

    def _seed_workflow(self, wid, stages, index=0, max_repairs=0, **extra):
        wf = {"id": wid, "title": "t", "conversation_id": 7, "workspace": None,
              "stages": stages, "current_index": index, "status": "running",
              "max_repairs": max_repairs, "repair_count": 0,
              "advancing": False, "advancing_ts": 0,
              "created_at": "2026-08-25 00:00:00", "updated_at": "2026-08-25 00:00:00"}
        wf.update(extra)
        harpp_wake.save_workflows_state({"workflows": {wid: wf}})
        return wf

    def test_start_workflow_launches_first_stage(self):
        launched = []
        original_launch = harpp_wake.launch_job

        def fake_launch(**kw):
            launched.append(kw)
            return "job-0", None

        harpp_wake.launch_job = fake_launch
        try:
            wid = harpp_wake.start_workflow(
                title="test wf", conversation_id=7,
                stages=[self._stage("s1")])
            self.assertEqual(len(launched), 1)
            wf = harpp_wake.get_workflow(wid)
            self.assertEqual(wf["status"], "running")
            self.assertEqual(wf["stages"][0]["status"], "running")
            self.assertEqual(wf["stages"][0]["job_id"], "job-0")
        finally:
            harpp_wake.launch_job = original_launch

    def test_start_workflow_applies_temporary_model_without_mutating_input(self):
        launched = []
        original_launch = harpp_wake.launch_job
        stages = [self._stage("implement"), self._stage("review")]
        stages[0]["model"] = "deepseek/deepseek-v4-pro"
        stages[1]["model"] = "openai-codex/gpt-5.6-sol"
        original_models = [stage["model"] for stage in stages]
        harpp_wake.launch_job = lambda **kw: (launched.append(kw) or ("job-pref", None))
        try:
            wid = harpp_wake.start_workflow(
                title="temporary preference", conversation_id=7, stages=stages,
                preferred_model="deepseek/deepseek-v4-flash")
            wf = harpp_wake.get_workflow(wid)
            self.assertEqual([stage["model"] for stage in stages], original_models)
            self.assertEqual(wf["preferred_model"], "deepseek/deepseek-v4-flash")
            self.assertEqual(wf["model_selection"], "conversation_once")
            self.assertTrue(all(stage["model"] == "deepseek/deepseek-v4-flash"
                                for stage in wf["stages"]))
            self.assertEqual(wf["stages"][0]["configured_model"], "deepseek/deepseek-v4-pro")
            self.assertEqual(wf["stages"][1]["configured_model"], "openai-codex/gpt-5.6-sol")
            self.assertEqual(launched[0]["model"], "deepseek/deepseek-v4-flash")
        finally:
            harpp_wake.launch_job = original_launch

    def test_workflow_requires_valid_args(self):
        with self.assertRaises(ValueError):
            harpp_wake.start_workflow(title="x", conversation_id=7, stages=[])
        with self.assertRaises(ValueError):
            harpp_wake.start_workflow(title="", conversation_id=0, stages=[self._stage("s")])

    def test_workflow_advances_on_stage_pass_then_done(self):
        wid = "wf-adv"
        stages = [self._stage("s1", job_id="job-1", status="running"), self._stage("s2")]
        self._seed_workflow(wid, stages)
        self._write_jobs({"job-1": {"id": "job-1", "status": "finished", "outcome": "DONE", "conversation_id": 7}})
        launched = []
        original_launch = harpp_wake.launch_job

        def fake_launch(**kw):
            launched.append(kw)
            return "job-2", None

        harpp_wake.launch_job = fake_launch
        try:
            self.assertEqual(harpp_wake.advance_workflows(), 1)
            wf = harpp_wake.get_workflow(wid)
            self.assertEqual(wf["current_index"], 1)
            self.assertEqual(wf["stages"][0]["status"], "done")
            self.assertEqual(wf["stages"][1]["job_id"], "job-2")
            self.assertEqual(len(launched), 1)
        finally:
            harpp_wake.launch_job = original_launch
        # stage 2 finishes DONE -> workflow done + final notify
        self._write_jobs({"job-2": {"id": "job-2", "status": "finished", "outcome": "DONE", "conversation_id": 7}})
        sent, original_send = self._patch_send()
        try:
            self.assertEqual(harpp_wake.advance_workflows(), 1)
            wf2 = harpp_wake.get_workflow(wid)
            self.assertEqual(wf2["status"], "done")
            self.assertEqual(wf2["stages"][1]["status"], "done")
            self.assertGreater(len(sent), 0)
            self.assertIn("WORKFLOW COMPLETE", sent[0]["body"])
            self.assertIn("Next: done — safe to move forward", sent[0]["body"])
        finally:
            harpp_wake.harpp_client.send_message = original_send

    def test_workflow_stops_on_stage_fail(self):
        wid = "wf-fail"
        stages = [self._stage("s1", job_id="job-f", status="running")]
        self._seed_workflow(wid, stages)
        self._write_jobs({"job-f": {"id": "job-f", "status": "finished", "outcome": "FAILED", "conversation_id": 7}})
        sent, original = self._patch_send()
        try:
            self.assertEqual(harpp_wake.advance_workflows(), 1)
            wf = harpp_wake.get_workflow(wid)
            self.assertEqual(wf["status"], "failed")
            self.assertEqual(wf["stages"][0]["status"], "failed")
            self.assertGreater(len(sent), 0)
            self.assertIn("FAILED", sent[0]["body"])
            self.assertIn("Next: remediation required", sent[0]["body"])
        finally:
            harpp_wake.harpp_client.send_message = original

    def _fake_launch_seq(self, launched, counter):
        def fake_launch(**kw):
            launched.append(kw.get("task"))
            counter["n"] += 1
            return f"job-{counter['n']}", None
        return fake_launch

    def test_workflow_auto_repairs_review_then_succeeds(self):
        wid = "wf-repair"
        stages = [self._stage("implement", "job-1", "running"),
                  self._stage("review"), self._stage("release-gate")]
        self._seed_workflow(wid, stages, max_repairs=2, authority_level="L3")
        launched, counter = [], {"n": 1}
        original_launch = harpp_wake.launch_job
        harpp_wake.launch_job = self._fake_launch_seq(launched, counter)
        try:
            self._write_jobs({"job-1": {"id": "job-1", "status": "finished", "outcome": "DONE", "conversation_id": 7}})
            self.assertEqual(harpp_wake.advance_workflows(), 1)  # -> review job-2
            self._write_jobs({"job-2": {"id": "job-2", "status": "finished", "outcome": "FAILED", "conversation_id": 7}})
            self.assertEqual(harpp_wake.advance_workflows(), 1)  # -> auto-repair implement
            wf = harpp_wake.get_workflow(wid)
            self.assertEqual(wf["status"], "running")
            self.assertEqual(wf["repair_count"], 1)
            self.assertEqual(wf["current_index"], 0)
            self.assertIn("REPAIR 1/2", launched[-1])
            self._write_jobs({"job-3": {"id": "job-3", "status": "finished", "outcome": "DONE", "conversation_id": 7}})
            self.assertEqual(harpp_wake.advance_workflows(), 1)  # -> review job-4
            self.assertEqual(harpp_wake.get_workflow(wid)["current_index"], 1)
            self._write_jobs({"job-4": {"id": "job-4", "status": "finished", "outcome": "DONE", "conversation_id": 7}})
            self.assertEqual(harpp_wake.advance_workflows(), 1)  # -> gate job-5
            self.assertEqual(harpp_wake.get_workflow(wid)["current_index"], 2)
            self._write_jobs({"job-5": {"id": "job-5", "status": "finished", "outcome": "DONE", "conversation_id": 7}})
            sent, original_send = self._patch_send()
            try:
                self.assertEqual(harpp_wake.advance_workflows(), 1)
                self.assertEqual(harpp_wake.get_workflow(wid)["status"], "done")
                self.assertTrue(any("[RELEASE_READY]" in s["body"] for s in sent))
            finally:
                harpp_wake.harpp_client.send_message = original_send
        finally:
            harpp_wake.launch_job = original_launch

    def test_workflow_stops_after_max_repairs(self):
        wid = "wf-max"
        stages = [self._stage("implement", "job-1", "running"), self._stage("review")]
        self._seed_workflow(wid, stages, max_repairs=1)
        launched, counter = [], {"n": 1}
        original_launch = harpp_wake.launch_job
        harpp_wake.launch_job = self._fake_launch_seq(launched, counter)
        sent, original_send = self._patch_send()
        try:
            self._write_jobs({"job-1": {"id": "job-1", "status": "finished", "outcome": "DONE", "conversation_id": 7}})
            harpp_wake.advance_workflows()  # -> review job-2
            self._write_jobs({"job-2": {"id": "job-2", "status": "finished", "outcome": "FAILED", "conversation_id": 7}})
            harpp_wake.advance_workflows()  # repair round 1 -> implement job-3
            wf = harpp_wake.get_workflow(wid)
            self.assertEqual(wf["status"], "running")
            self.assertEqual(wf["repair_count"], 1)
            self.assertIn("REPAIR 1/1", launched[-1])
            self._write_jobs({"job-3": {"id": "job-3", "status": "finished", "outcome": "DONE", "conversation_id": 7}})
            harpp_wake.advance_workflows()  # -> review job-4
            self._write_jobs({"job-4": {"id": "job-4", "status": "finished", "outcome": "FAILED", "conversation_id": 7}})
            harpp_wake.advance_workflows()  # repairs exhausted -> BLOCKED (terminates)
            wf2 = harpp_wake.get_workflow(wid)
            self.assertEqual(wf2["status"], "blocked")
            self.assertEqual(wf2["repair_count"], 1)
            self.assertFalse(any("REPAIR 2" in t for t in launched))
            self.assertTrue(any("what: unblock workflow" in s["body"] for s in sent))
        finally:
            harpp_wake.launch_job = original_launch
            harpp_wake.harpp_client.send_message = original_send

    def test_workflow_delegates_to_other_model_on_token_exhaustion(self):
        wid = "wf-delegate"
        stages = [self._stage("implement", job_id="job-1", status="running")]
        self._seed_workflow(wid, stages, max_repairs=2, authority_level="L3")
        logp = Path(self.tmp.name) / "exhausted.log"
        logp.write_text("Error: token usage exhausted for this model\n", encoding="utf-8")
        self._write_jobs({"job-1": {"id": "job-1", "status": "finished", "outcome": "FAILED",
                                     "conversation_id": 7, "log_path": str(logp)}})
        launched, counter = [], {"n": 1}
        original_launch = harpp_wake.launch_job
        harpp_wake.launch_job = self._fake_launch_seq(launched, counter)
        sent, original_send = self._patch_send()
        try:
            self.assertEqual(harpp_wake.advance_workflows(), 1)
            wf = harpp_wake.get_workflow(wid)
            self.assertEqual(wf["status"], "running")
            self.assertEqual(wf["model_fallbacks"], 1)
            self.assertEqual(wf["repair_count"], 0)  # delegation does not burn a repair round
            stage0 = wf["stages"][0]
            self.assertEqual(stage0["model"], "openai-codex/gpt-5.6-sol")
            self.assertIn("openai-codex/gpt-5.6-sol", stage0["tried_models"])
            self.assertEqual(len(launched), 1)
            self.assertIn("DELEGATED", launched[-1])
            self.assertTrue(any("MODEL DELEGATION" in s["body"] for s in sent))
        finally:
            harpp_wake.launch_job = original_launch
            harpp_wake.harpp_client.send_message = original_send

    def test_workflow_blocks_when_all_models_exhausted(self):
        wid = "wf-delegate-block"
        stage = self._stage("implement", job_id="job-1", status="running")
        stage["tried_models"] = list(harpp_wake.MODEL_FALLBACK_ORDER)
        self._seed_workflow(wid, [stage], max_repairs=2)
        logp = Path(self.tmp.name) / "balance.log"
        logp.write_text("insufficient balance\n", encoding="utf-8")
        self._write_jobs({"job-1": {"id": "job-1", "status": "finished", "outcome": "FAILED",
                                     "conversation_id": 7, "log_path": str(logp)}})
        launched = []
        original_launch = harpp_wake.launch_job
        harpp_wake.launch_job = lambda **kw: (launched.append(kw.get("task")) or ("x", None))
        sent, original_send = self._patch_send()
        try:
            self.assertEqual(harpp_wake.advance_workflows(), 1)
            wf = harpp_wake.get_workflow(wid)
            self.assertEqual(wf["status"], "blocked")
            self.assertIn("all available models exhausted", wf["blocked_reason"])
            self.assertEqual(len(launched), 0)
            self.assertTrue(any("what: unblock workflow" in s["body"] for s in sent))
        finally:
            harpp_wake.launch_job = original_launch
            harpp_wake.harpp_client.send_message = original_send

    def test_workflow_max_repairs_zero_disables_repair(self):
        wid = "wf-zero"
        stages = [self._stage("implement", "job-1", "running"), self._stage("review")]
        self._seed_workflow(wid, stages, max_repairs=0)
        launched = []
        original_launch = harpp_wake.launch_job
        harpp_wake.launch_job = lambda **kw: (launched.append(kw.get("task")) or ("x", None))
        sent, original_send = self._patch_send()
        try:
            self._write_jobs({"job-1": {"id": "job-1", "status": "finished", "outcome": "DONE", "conversation_id": 7}})
            harpp_wake.advance_workflows()  # -> review launched ("x")
            self._write_jobs({"x": {"id": "x", "status": "finished", "outcome": "FAILED", "conversation_id": 7}})
            harpp_wake.advance_workflows()  # max_repairs=0 -> immediate stop, no repair
            wf = harpp_wake.get_workflow(wid)
            self.assertEqual(wf["status"], "failed")
            self.assertEqual(wf["repair_count"], 0)
            self.assertEqual(len(launched), 1)
            self.assertTrue(any("Next: remediation required" in s["body"] for s in sent))
        finally:
            harpp_wake.launch_job = original_launch
            harpp_wake.harpp_client.send_message = original_send

    # --- deterministic workflow commands from the messenger ---

    def test_parse_workflow_command_list_show(self):
        self.assertEqual(harpp_wake.parse_workflow_command("workflow list")[0], "list")
        self.assertEqual(harpp_wake.parse_workflow_command("Workflow status")[0], "list")
        self.assertEqual(harpp_wake.parse_workflow_command("workflow show abc123"),
                         ("show", {"id": "abc123"}))
        self.assertIsNone(harpp_wake.parse_workflow_command("hello there"))
        self.assertIsNone(harpp_wake.parse_workflow_command(""))

    def test_parse_workflow_command_start(self):
        c = harpp_wake.parse_workflow_command("workflow start standalone harpp loop")
        self.assertEqual(c[0], "start")
        self.assertEqual(c[1]["name"], "standalone harpp loop")
        c2 = harpp_wake.parse_workflow_command("workflow start --workspace /tmp/x standalone")
        self.assertEqual(c2[1]["workspace"], "/tmp/x")
        c3 = harpp_wake.parse_workflow_command("run the governed loop")
        self.assertEqual(c3[0], "start")
        self.assertEqual(c3[1]["name"], "governed loop")
        c4 = harpp_wake.parse_workflow_command(
            "workflow start governed-loop --model gpt-sol --max-repairs 1")
        self.assertEqual(c4[1]["name"], "governed-loop")
        self.assertEqual(c4[1]["model"], "openai-codex/gpt-5.6-sol")
        self.assertEqual(c4[1]["max_repairs"], 1)
        c5 = harpp_wake.parse_workflow_command("run the governed loop using deepseek flash")
        self.assertEqual(c5[1]["model"], "deepseek/deepseek-v4-flash")
        with self.assertRaises(ValueError):
            harpp_wake.parse_workflow_command("workflow start governed-loop --model mystery")

    def test_route_workflow_commands_list_replies_and_marks_processed(self):
        sent, original = self._patch_send()
        try:
            n = harpp_wake.route_workflow_commands([
                {"kind": "message", "id": 900, "conversation_id": 7, "sender_type": "user",
                 "body": "workflow list"}])
            self.assertEqual(n, 1)
            self.assertEqual(len(sent), 1)
            self.assertIn("No workflows", sent[0]["body"])
            self.assertIn(900, harpp_wake.read_state()["messages"])
        finally:
            harpp_wake.harpp_client.send_message = original

    def test_route_workflow_commands_starts_workflow(self):
        started = []
        original_start = harpp_wake.start_workflow
        harpp_wake.start_workflow = lambda **kw: started.append(kw) or "wf-x"
        sent, original_send = self._patch_send()
        try:
            n = harpp_wake.route_workflow_commands([
                {"kind": "message", "id": 901, "conversation_id": 8, "sender_type": "user",
                 "body": "workflow start standalone harpp loop using gpt sol"}])
            self.assertEqual(n, 1)
            self.assertEqual(len(started), 1)
            self.assertEqual(started[0]["conversation_id"], 8)
            self.assertEqual(started[0]["workspace"], "/var/www/html/harpp")
            self.assertEqual(started[0]["preferred_model"], "openai-codex/gpt-5.6-sol")
            self.assertEqual(len(sent), 1)
            self.assertIn("Workflow started", sent[0]["body"])
            self.assertIn(901, harpp_wake.read_state()["messages"])
        finally:
            harpp_wake.start_workflow = original_start
            harpp_wake.harpp_client.send_message = original_send

    def test_route_workflow_retries_delivery_without_reexecuting(self):
        calls = []
        original_exec = harpp_wake._exec_workflow_command
        original_notify = harpp_wake.harpp_client.harpp_notify
        harpp_wake._exec_workflow_command = lambda cmd, conv: calls.append((cmd, conv)) or "saved result"
        harpp_wake.harpp_client.harpp_notify = lambda **kw: {"ok": False}
        record = {"kind": "message", "id": 902, "conversation_id": 8,
                  "sender_type": "user", "body": "workflow list"}
        try:
            self.assertEqual(harpp_wake.route_workflow_commands([record]), 0)
            self.assertNotIn(902, harpp_wake.read_state()["messages"])
            self.assertIn("902", harpp_wake.read_state()["routing_results"])
            harpp_wake.harpp_client.harpp_notify = lambda **kw: {"ok": True}
            self.assertEqual(harpp_wake.route_workflow_commands([record]), 1)
            self.assertEqual(len(calls), 1)
            self.assertIn(902, harpp_wake.read_state()["messages"])
        finally:
            harpp_wake._exec_workflow_command = original_exec
            harpp_wake.harpp_client.harpp_notify = original_notify

    def test_parse_plan_command_recognizes_plan_execution_requests(self):
        for body in ["Start T1, T2 has option B selected. Then T3, T4",
                     "Restart the guidance tasks created and implement",
                     "re-implement the approved tasks T1 to T4. T2, I selected option B",
                     "implement the approved tasks",
                     "proceed with the workflow",
                     "resume the failed tasks",
                     "/implement"]:
            self.assertTrue(harpp_wake.parse_plan_command(body), f"should detect: {body}")
        # Non-execution messages must NOT be claimed as plan-execution requests.
        for body in ["How does HARPP resolve this routing fault?",
                     "Assessment received and logged; nothing implemented",
                     "Approved. T2, use option B",
                     "It will be good to show time of HARPP responses. Implement",
                      "Start inspect logs. Find the issues and fix. Create the plan when finished",
                     "workflow list", "Start debate on the topic"]:
            self.assertFalse(harpp_wake.parse_plan_command(body), f"should ignore: {body}")

    def test_parse_debate_command_rejects_compound_debate_word(self):
        self.assertIsNone(harpp_wake.parse_debate_command("Start inspect-debate-logs"))
        self.assertEqual(harpp_wake.parse_debate_command("start debate: logging failures")["intent"],
                         "logging failures")

    def test_parse_debate_command_singular_max_round(self):
        # "max round 5" (singular) must be honored AND stripped from the intent
        # (previously only "max rounds"/"rounds"/"depth" matched, so singular fell
        # back to the 3-round default and leaked into the intent).
        cmd = harpp_wake.parse_debate_command(
            "Start a debate, max round 5. Resolve: Must HARPP adopt Workspaces like VSCode?")
        self.assertEqual(cmd["rounds"], 5)
        self.assertEqual(cmd["intent"], "Resolve: Must HARPP adopt Workspaces like VSCode?")

    def test_parse_debate_approve_command(self):
        self.assertIsNotNone(harpp_wake.parse_debate_approve_command("Approve debate"))
        self.assertIsNotNone(harpp_wake.parse_debate_approve_command("chair approve the debate"))
        self.assertIsNotNone(harpp_wake.parse_debate_approve_command("accept the debate verdict"))
        self.assertIsNone(harpp_wake.parse_debate_approve_command("Start a debate"))
        self.assertIsNone(harpp_wake.parse_debate_approve_command("Restart debate"))
        self.assertIsNone(harpp_wake.parse_debate_approve_command("workflow list"))

    def test_exec_debate_approve_runs_and_reports(self):
        import types
        original_run = harpp_wake.subprocess.run
        original_workspace = harpp_wake.default_workspace
        harpp_wake.default_workspace = lambda: self.tmp.name
        decision_dir = Path(self.tmp.name) / ".ai"
        decision_dir.mkdir(parents=True, exist_ok=True)
        (decision_dir / "current-task.md").write_text(
            "task: Accept the workspace decision\nstatus: RESOLVED\n", encoding="utf-8")
        calls = []

        def fake_run(cmd, **kw):
            calls.append(cmd)
            return types.SimpleNamespace(returncode=0,
                                         stdout="APPROVED (chair): wrote .ai/current-task.md",
                                         stderr="")
        harpp_wake.subprocess.run = fake_run
        try:
            out = harpp_wake._exec_debate_approve(9)
        finally:
            harpp_wake.subprocess.run = original_run
            harpp_wake.default_workspace = original_workspace
        self.assertIn("APPROVED", out)
        self.assertIn("Accept the workspace decision", out)
        self.assertIn("--approve", " ".join(calls[0]))

    def test_route_debate_approve_command(self):
        approved = []
        notified = []
        original_approve = harpp_wake._exec_debate_approve
        original_notify = harpp_wake.harpp_client.harpp_notify
        harpp_wake._exec_debate_approve = lambda conv: approved.append(conv) or "✅ Debate chair-approved (APPROVED)."
        harpp_wake.harpp_client.harpp_notify = lambda **kw: notified.append(kw) or {"ok": True}
        try:
            n = harpp_wake.route_debate_commands([
                {"kind": "message", "id": 955, "conversation_id": 8, "sender_type": "user",
                 "body": "Approve debate"}])
            self.assertEqual(n, 1)
            self.assertEqual(approved, [8])
            self.assertEqual(len(notified), 1)
            self.assertIn("APPROVED", notified[0]["body"])
        finally:
            harpp_wake._exec_debate_approve = original_approve
            harpp_wake.harpp_client.harpp_notify = original_notify

    def test_debate_job_uses_verdict_presence_and_value_verification(self):
        captured = []
        original_launch = harpp_wake.launch_job
        original_workspace = harpp_wake.default_workspace
        harpp_wake.default_workspace = lambda: self.tmp.name
        harpp_wake.launch_job = lambda **kw: captured.append(kw) or ("debate-1", object())
        try:
            harpp_wake._exec_debate_command({"intent": "Improve boundaries", "rounds": 5}, 9)
        finally:
            harpp_wake.launch_job = original_launch
            harpp_wake.default_workspace = original_workspace
        self.assertEqual(captured[0]["marker"], "verdict:")
        self.assertIn("approved.txt", captured[0]["verify"])
        self.assertIn("DEBATE_MAX_ROUNDS=5", captured[0]["verify"])

    def test_debate_verifier_reports_approved_and_revisions_honestly(self):
        verdict_file = Path(self.tmp.name) / ".ai" / "debate" / "approved.txt"
        verdict_file.parent.mkdir(parents=True)
        original_workspace = harpp_wake.default_workspace
        original_launch = harpp_wake.launch_job
        captured = []
        harpp_wake.default_workspace = lambda: self.tmp.name
        harpp_wake.launch_job = lambda **kw: captured.append(kw) or ("debate-2", object())
        try:
            harpp_wake._exec_debate_command({"intent": "Test outcomes"}, 9)
            verdict_file.write_text("approved\n")
            self.assertTrue(harpp_wake._run_verify(captured[0]["verify"], None)[0])
            verdict_file.write_text("revisions\n")
            ok, output = harpp_wake._run_verify(captured[0]["verify"], None)
            self.assertFalse(ok)
            self.assertIn("critic requested revisions", output)
            self.assertIn("verdict: REVISIONS", output)
        finally:
            harpp_wake.launch_job = original_launch
            harpp_wake.default_workspace = original_workspace

    def test_parse_plan_tasks_extracts_task_lines(self):
        tasks = harpp_wake._parse_plan_tasks(
            "Proposed plan:\nT1 - Fix doc drift (docs only). Assign: GPT Sol\n"
            "T2 - Refactor handlers. Assign: Codex\n")
        self.assertEqual(len(tasks), 2)
        self.assertEqual(tasks[0]["num"], 1)
        self.assertIn("Fix doc drift", tasks[0]["desc"])
        self.assertEqual(tasks[1]["num"], 2)

    def test_parse_plan_tasks_accepts_natural_numbering(self):
        tasks = harpp_wake._parse_plan_tasks(
            "(1) Fix parser (2) Add tests\n3. Verify behavior\n4) Report results")
        self.assertEqual([task["num"] for task in tasks], [1, 2, 3, 4])
        self.assertEqual([task["desc"] for task in tasks],
                         ["Fix parser", "Add tests", "Verify behavior", "Report results"])

    def test_route_plan_commands_records_plan_message(self):
        sent = []
        original_notify = harpp_wake.harpp_client.harpp_notify
        harpp_wake.harpp_client.harpp_notify = lambda **kw: sent.append(kw) or {"ok": True}
        try:
            record = {"kind": "message", "id": 910, "conversation_id": 7,
                      "sender_type": "user",
                      "body": "Proposed plan:\nT1 - Fix doc drift. Assign: GPT Sol\nT2 - Add tests"}
            n = harpp_wake.route_plan_commands([record])
            self.assertEqual(n, 1)
            self.assertIn(910, harpp_wake.read_state()["messages"])
            self.assertIn("Plan recorded", sent[0]["body"])
            self.assertIn("T1 - Fix doc drift", harpp_wake._plan_storage().get("7"))
        finally:
            harpp_wake.harpp_client.harpp_notify = original_notify

    def test_route_plan_commands_execution_starts_workflow(self):
        started = []
        original_start = harpp_wake.start_workflow
        original_ws = harpp_wake.default_workspace
        harpp_wake.start_workflow = lambda **kw: started.append(kw) or "wf-plan"
        harpp_wake.default_workspace = lambda: str(self.tmp.name)
        sent = []
        original_notify = harpp_wake.harpp_client.harpp_notify
        harpp_wake.harpp_client.harpp_notify = lambda **kw: sent.append(kw) or {"ok": True}
        try:
            harpp_wake._store_plan(8, "T1 - Fix doc drift. Assign: GPT Sol\nT2 - Add tests")
            record = {"kind": "message", "id": 911, "conversation_id": 8,
                      "sender_type": "user", "body": "Start T1, T2"}
            n = harpp_wake.route_plan_commands([record])
            self.assertEqual(n, 1)
            self.assertEqual(len(started), 1)
            self.assertEqual(started[0]["conversation_id"], 8)
            self.assertEqual(len(started[0]["stages"]), 2)
            self.assertEqual(started[0]["stages"][0]["name"], "T1")
            self.assertEqual(started[0]["stages"][0]["model"], "openai-codex/gpt-5.6-sol")
            self.assertEqual(started[0]["stages"][1]["name"], "T2")
            self.assertIn("Plan workflow started", sent[0]["body"])
            self.assertIn(911, harpp_wake.read_state()["messages"])
        finally:
            harpp_wake.start_workflow = original_start
            harpp_wake.default_workspace = original_ws
            harpp_wake.harpp_client.harpp_notify = original_notify

    def test_exec_plan_command_start_t4_builds_only_stage_t4(self):
        started = []
        original_start = harpp_wake.start_workflow
        original_ws = harpp_wake.default_workspace
        harpp_wake.start_workflow = lambda **kw: started.append(kw) or "wf-t4"
        harpp_wake.default_workspace = lambda: str(self.tmp.name)
        try:
            harpp_wake._store_plan(12, "T1 - One\nT2 - Two\nT3 - Three\nT4 - Four")
            result = harpp_wake._exec_plan_command(
                {"body": "Start T4"}, 12, "deepseek/deepseek-v4-pro")
            self.assertEqual([stage["name"] for stage in started[0]["stages"]], ["T4"])
            self.assertIn("stages (1): T4", result)
        finally:
            harpp_wake.start_workflow = original_start
            harpp_wake.default_workspace = original_ws

    def test_spawn_agent_signal_kill_not_usage_exhausted(self):
        # A process terminated by a signal (negative rc) must NOT be classified
        # usage_exhausted even if its partial output mentions rate-limit words.
        ok, reason = harpp_wake.spawn_agent(
            "prompt", command="echo 'rate limit 429'; kill -TERM $$",
            model="deepseek/deepseek-v4-pro", timeout=30, return_reason=True)
        self.assertFalse(ok)
        self.assertNotEqual(reason, "usage_exhausted")
        self.assertEqual(reason, "exit_error")

    def test_spawn_agent_shell_reported_signal_exit_not_usage_exhausted(self):
        # A shell-reported signal exit (128+signal) is also not exhaustion.
        ok, reason = harpp_wake.spawn_agent(
            "prompt", command="sh -c 'echo rate limit 429; kill -TERM $$'",
            model="deepseek/deepseek-v4-pro", timeout=30, return_reason=True)
        self.assertFalse(ok)
        self.assertNotEqual(reason, "usage_exhausted")

    def test_spawn_agent_genuine_error_exit_is_usage_exhausted(self):
        # A genuine normal non-zero exit with exhaustion text IS usage_exhausted.
        ok, reason = harpp_wake.spawn_agent(
            "prompt", command="echo 'rate limit 429'; exit 1",
            model="deepseek/deepseek-v4-pro", timeout=30, return_reason=True)
        self.assertFalse(ok)
        self.assertEqual(reason, "usage_exhausted")

    def test_missing_log_is_reported_as_failure(self):
        logp = Path(self.tmp.name) / "deleted.log"
        logp.write_text("starting\n")
        harpp_wake.track_job(pid=self._dead_pid(), model="model", task="lost log",
                             conversation_id=5, log_path=str(logp), marker="done")
        logp.unlink()
        sent, original = self._patch_send()
        try:
            self.assertEqual(harpp_wake.monitor_jobs(), 1)
            self.assertIn("FAILED", sent[0]["body"])
            self.assertIn("did not finish with its required success marker", sent[0]["body"])
        finally:
            harpp_wake.harpp_client.send_message = original

    def test_marker_ignores_success_text_present_before_tracking(self):
        logp = Path(self.tmp.name) / "reused.log"
        logp.write_text("SUCCESS MARKER\nold attempt failed later\n", encoding="utf-8")
        harpp_wake.track_job(pid=self._dead_pid(), model="model", task="new attempt",
                             conversation_id=5, log_path=str(logp), marker="success marker")
        logp.write_text(logp.read_text() + "new attempt failed\n", encoding="utf-8")
        sent, original = self._patch_send()
        try:
            self.assertEqual(harpp_wake.monitor_jobs(), 1)
            self.assertIn("FAILED", sent[0]["body"])
            self.assertIn("did not finish with its required success marker", sent[0]["body"])
        finally:
            harpp_wake.harpp_client.send_message = original

    def test_verify_honors_shell_quoting_cwd_and_nonzero_exit(self):
        repo = Path(self.tmp.name) / "verify cwd"
        repo.mkdir()
        ok, output = harpp_wake._run_verify(
            f"printf '%s' \"$PWD\"; test -f 'file with spaces'", str(repo))
        self.assertFalse(ok)
        self.assertIn(str(repo), output)
        (repo / "file with spaces").write_text("x")
        ok, _ = harpp_wake._run_verify("test -f 'file with spaces'", str(repo))
        self.assertTrue(ok)

    def test_verify_timeout_is_failure(self):
        original_timeout = harpp_wake.JOB_VERIFY_TIMEOUT
        harpp_wake.JOB_VERIFY_TIMEOUT = 0.01
        try:
            ok, output = harpp_wake._run_verify(
                f"{sys.executable} -c 'import time; time.sleep(1)'", None)
            self.assertFalse(ok)
            self.assertIn("timed out", output)
        finally:
            harpp_wake.JOB_VERIFY_TIMEOUT = original_timeout

    def test_pid_identity_detects_reuse(self):
        identity = harpp_wake._pid_identity(os.getpid())
        if identity is None:
            self.skipTest("/proc process identity is unavailable")
        self.assertTrue(harpp_wake._pid_alive(os.getpid(), identity))
        self.assertFalse(harpp_wake._pid_alive(os.getpid(), identity + "-reused"))

    def test_concurrent_monitors_deliver_only_once(self):
        harpp_wake.track_job(pid=self._dead_pid(), model="model", task="race",
                             conversation_id=8, verify="true")
        sent = []
        original = harpp_wake.harpp_client.send_message

        def slow_send(**kw):
            time.sleep(0.05)
            sent.append(kw)
            return {"ok": True}

        harpp_wake.harpp_client.send_message = slow_send
        try:
            results = []
            threads = [threading.Thread(target=lambda: results.append(harpp_wake.monitor_jobs()))
                       for _ in range(2)]
            for thread in threads:
                thread.start()
            for thread in threads:
                thread.join()
            self.assertEqual(sum(results), 1)
            self.assertEqual(len(sent), 1)
        finally:
            harpp_wake.harpp_client.send_message = original

    def test_stale_reporting_claim_is_recovered_after_restart(self):
        jid = harpp_wake.track_job(pid=self._dead_pid(), model="model", task="restart",
                                   conversation_id=8, verify="true")
        state = harpp_wake.jobs_state()
        state["jobs"][jid].update({
            "status": "reporting", "reporter_pid": 999999,
            "report_started_ts": harpp_wake._now(), "report_token": "dead-monitor",
        })
        harpp_wake.save_jobs_state(state)
        sent, original = self._patch_send()
        try:
            self.assertEqual(harpp_wake.monitor_jobs(), 1)
            self.assertEqual(len(sent), 1)
            self.assertEqual(harpp_wake.jobs_state()["jobs"][jid]["status"], "finished")
        finally:
            harpp_wake.harpp_client.send_message = original

    def test_reported_history_is_bounded_but_running_jobs_are_retained(self):
        original_limit = harpp_wake.JOB_HISTORY_LIMIT
        harpp_wake.JOB_HISTORY_LIMIT = 2
        try:
            jobs = {
                str(i): {"id": str(i), "status": "reported", "reported_at": f"2025-01-0{i}"}
                for i in range(1, 4)
            }
            jobs["active"] = {"id": "active", "status": "running"}
            state = {"jobs": jobs, "reported": ["1", "2", "3"]}
            harpp_wake._prune_jobs_state(state)
            self.assertEqual(set(state["reported"]), {"2", "3"})
            self.assertIn("active", state["jobs"])
            self.assertNotIn("1", state["jobs"])
        finally:
            harpp_wake.JOB_HISTORY_LIMIT = original_limit

    def test_track_requires_delivery_target_and_commit_repo(self):
        with self.assertRaises(ValueError):
            harpp_wake.track_job(pid=1, model="model", task="task")
        with self.assertRaises(ValueError):
            harpp_wake.track_job(pid=1, model="model", task="task", conversation_id=1,
                                 commit=True)

    def test_commit_scopes_out_preexisting_dirty_paths(self):
        if not shutil.which("git"):
            self.skipTest("git unavailable")
        repo = Path(self.tmp.name) / "repo"
        remote = Path(self.tmp.name) / "remote.git"
        subprocess.run(["git", "init", "-q", "--bare", str(remote)], check=True)
        subprocess.run(["git", "init", "-q", str(repo)], check=True)
        subprocess.run(["git", "-C", str(repo), "config", "user.email", "test@example.invalid"], check=True)
        subprocess.run(["git", "-C", str(repo), "config", "user.name", "Test"], check=True)
        (repo / "old.txt").write_text("base\n")
        (repo / "job.txt").write_text("base\n")
        subprocess.run(["git", "-C", str(repo), "add", "."], check=True)
        subprocess.run(["git", "-C", str(repo), "commit", "-qm", "base"], check=True)
        subprocess.run(["git", "-C", str(repo), "remote", "add", "origin", str(remote)], check=True)
        subprocess.run(["git", "-C", str(repo), "push", "-qu", "origin", "HEAD"], check=True)
        (repo / "old.txt").write_text("preexisting\n")
        job = {"repo": str(repo), "git_baseline": harpp_wake._git_changed_paths(str(repo)),
               "model": "model", "task": "scoped work"}
        (repo / "job.txt").write_text("job change\n")
        summary = harpp_wake._commit_job(job)
        self.assertIn("committed + pushed", summary)
        status = subprocess.run(["git", "-C", str(repo), "status", "--porcelain"],
                                check=True, text=True, stdout=subprocess.PIPE).stdout
        self.assertIn("old.txt", status)
        self.assertNotIn("job.txt", status)

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
        self.assertEqual(harpp_wake.read_state()["failures"]["1"], 1)

    def test_unverifiable_agent_output_is_retried(self):
        self.assertFalse(self._wake(command="echo garbage"))
        self.assertNotIn(1, harpp_wake.read_state()["messages"])

    def test_agent_marker_without_daemon_delivery_receipt_is_rejected(self):
        old_receipts = os.environ.get("HARPP_DELIVERY_RECEIPTS")
        os.environ["HARPP_DELIVERY_RECEIPTS"] = str(Path(self.tmp.name) / "receipts.jsonl")
        original_notify_enabled = harpp_wake.harpp_client._notify_enabled
        harpp_wake.harpp_client._notify_enabled = lambda: True
        try:
            self.assertFalse(harpp_wake.spawn_agent(
                "prompt",
                command="echo 'HARPP_WAKE_RESULT replies_sent=1 items_processed=1 delivered_ids=1'",
                model="model", timeout=30, expected_replies=1, expected_source_ids=[1]))
        finally:
            harpp_wake.harpp_client._notify_enabled = original_notify_enabled
            if old_receipts is None:
                os.environ.pop("HARPP_DELIVERY_RECEIPTS", None)
            else:
                os.environ["HARPP_DELIVERY_RECEIPTS"] = old_receipts

    def test_agent_receipt_verification_overrides_bad_marker_counts(self):
        original_notify_enabled = harpp_wake.harpp_client._notify_enabled
        original_delivery_keys_since = harpp_wake._delivery_keys_since
        harpp_wake.harpp_client._notify_enabled = lambda: True
        harpp_wake._delivery_keys_since = lambda offset: {"wake-message-1"}
        try:
            self.assertTrue(harpp_wake.spawn_agent(
                "prompt",
                command="echo 'HARPP_WAKE_RESULT replies_sent=0 items_processed=0 delivered_ids=1'",
                model="model", timeout=30, expected_replies=1, expected_source_ids=[1]))
        finally:
            harpp_wake.harpp_client._notify_enabled = original_notify_enabled
            harpp_wake._delivery_keys_since = original_delivery_keys_since

    def test_already_delivered_message_is_marked_processed_not_retried(self):
        """A message whose wake-message-<id> receipt exists is answered; the wake
        loop must mark it processed instead of re-running the agent (which would
        409-conflict on the locked idempotency key and burn toward a FAILED reply)."""
        old_receipts = os.environ.get("HARPP_DELIVERY_RECEIPTS")
        receipts = Path(self.tmp.name) / "receipts.jsonl"
        os.environ["HARPP_DELIVERY_RECEIPTS"] = str(receipts)
        try:
            # A delivered reply already exists for wake-message-1.
            receipts.write_text(
                json.dumps({"idempotency_key": "wake-message-1", "conversation_id": 2}) + "\n",
                encoding="utf-8")
            marker = Path(self.tmp.name) / "agent-ran"
            ok = harpp_wake.maybe_wake(
                str(self.inbox), enabled=True, command=f"touch {marker}",
                cooldown=0, max_per_hour=0, timeout=30)
            # Guard short-circuits before the agent: no invocation, marked processed.
            self.assertFalse(ok)
            self.assertFalse(marker.exists(), "agent should not run for an already-delivered message")
            state = harpp_wake.read_state()
            self.assertIn(1, state["messages"])
            self.assertNotIn("1", state["failures"])
        finally:
            if old_receipts is None:
                os.environ.pop("HARPP_DELIVERY_RECEIPTS", None)
            else:
                os.environ["HARPP_DELIVERY_RECEIPTS"] = old_receipts

    def test_decision_skipped_by_wake(self):
        # Decisions are handled instantly by the deterministic autoprocess layer,
        # so the wake agent must NOT re-process them (they are filtered out).
        self.inbox.write_text(
            json.dumps({"kind": "decision", "id": 9, "decision": "A"}) + "\n", encoding="utf-8")
        self.assertFalse(self._wake())
        self.assertNotIn(9, harpp_wake.read_state()["decisions"])

    def test_pick_model_routing(self):
        items = [{"kind": "message", "id": 1, "conversation_id": 2, "body": "use gpt sol to fix the login"}]
        self.assertEqual(harpp_wake.pick_model(items, "deepseek/deepseek-v4-pro"), "openai-codex/gpt-5.6-sol")
        items2 = [{"kind": "message", "id": 1, "conversation_id": 2, "body": "check this quickly"}]
        self.assertEqual(harpp_wake.pick_model(items2, "deepseek/deepseek-v4-pro"), "deepseek/deepseek-v4-pro")
        items3 = [{"kind": "message", "id": 1, "conversation_id": 2, "body": "use got sol and update"}]
        self.assertEqual(harpp_wake.pick_model(items3, "deepseek/deepseek-v4-pro"), "openai-codex/gpt-5.6-sol")
        items4 = [{"kind": "message", "id": 1, "conversation_id": 2, "body": "run with flash"}]
        self.assertEqual(harpp_wake.pick_model(items4, "deepseek/deepseek-v4-pro"), "deepseek/deepseek-v4-flash")

    def test_model_batches_never_mix_conversations(self):
        items = [
            {"id": 1, "conversation_id": 10, "body": "use gpt sol"},
            {"id": 2, "conversation_id": 11, "body": "use gpt sol"},
        ]
        batches = harpp_wake._temporary_model_batches(items, "deepseek/deepseek-v4-pro")
        self.assertEqual(len(batches), 2)
        self.assertEqual([{row["conversation_id"] for row in batch} for _, batch in batches], [{10}, {11}])

    def test_processed_ledger_concurrent_updates_do_not_lose_ids(self):
        threads = [threading.Thread(
            target=harpp_wake.mark_processed,
            args=([{"kind": "message", "id": rid}],)) for rid in range(100, 120)]
        for thread in threads:
            thread.start()
        for thread in threads:
            thread.join()
        self.assertTrue(set(range(100, 120)).issubset(set(harpp_wake.read_state()["messages"])))

    def test_wake_falls_back_when_requested_model_fails(self):
        calls = []
        original = harpp_wake.spawn_agent
        original_notify = harpp_wake.harpp_client.harpp_notify

        def fake_spawn(prompt, **kw):
            calls.append(kw.get("model"))
            # Requested model (gpt sol) fails; the configured default (deepseek pro) succeeds.
            if kw.get("model") == "deepseek/deepseek-v4-pro":
                return (True, None)
            return (False, "usage_exhausted")

        harpp_wake.spawn_agent = fake_spawn
        harpp_wake.harpp_client.harpp_notify = lambda **kw: {"ok": True}
        try:
            self.inbox.write_text(
                json.dumps({"kind": "message", "id": 1, "conversation_id": 2, "body": "use gpt sol"}) + "\n",
                encoding="utf-8")
            ok = harpp_wake.maybe_wake(str(self.inbox), enabled=True, command="echo dry",
                                       cooldown=0, max_per_hour=0, timeout=30,
                                       model="deepseek/deepseek-v4-pro")
            self.assertTrue(ok)
            self.assertEqual(calls, ["openai-codex/gpt-5.6-sol", "deepseek/deepseek-v4-pro"])
        finally:
            harpp_wake.spawn_agent = original
            harpp_wake.harpp_client.harpp_notify = original_notify

    def test_wake_falls_back_through_all_models_when_default_exhausted(self):
        calls = []
        original = harpp_wake.spawn_agent
        original_notify = harpp_wake.harpp_client.harpp_notify

        def fake_spawn(prompt, **kw):
            calls.append(kw.get("model"))
            if kw.get("model") == "deepseek/deepseek-v4-flash":
                return (True, None)
            return (False, "usage_exhausted")

        harpp_wake.spawn_agent = fake_spawn
        harpp_wake.harpp_client.harpp_notify = lambda **kw: {"ok": True}
        try:
            self.inbox.write_text(
                json.dumps({"kind": "message", "id": 1, "conversation_id": 2, "body": "status please"}) + "\n",
                encoding="utf-8")
            ok = harpp_wake.maybe_wake(str(self.inbox), enabled=True, command="echo dry",
                                       cooldown=0, max_per_hour=0, timeout=30,
                                       model="deepseek/deepseek-v4-pro")
            self.assertTrue(ok)
            self.assertEqual(calls, [
                "deepseek/deepseek-v4-pro",
                "openai-codex/gpt-5.6-sol",
                "openai-codex/gpt-5.4",
                "deepseek/deepseek-v4-flash",
            ])
        finally:
            harpp_wake.spawn_agent = original
            harpp_wake.harpp_client.harpp_notify = original_notify

    def test_wake_switches_model_for_invalid_result_without_delivery(self):
        calls = []
        original = harpp_wake.spawn_agent
        original_notify = harpp_wake.harpp_client.harpp_notify

        def fake_spawn(prompt, **kw):
            calls.append(kw.get("model"))
            if kw.get("model") == "deepseek/deepseek-v4-pro":
                return (True, None)
            return (False, "invalid_result")

        harpp_wake.spawn_agent = fake_spawn
        harpp_wake.harpp_client.harpp_notify = lambda **kw: {"ok": True}
        try:
            self.inbox.write_text(
                json.dumps({"kind": "message", "id": 1, "conversation_id": 2,
                            "body": "use gpt sol"}) + "\n", encoding="utf-8")
            self.assertTrue(harpp_wake.maybe_wake(
                str(self.inbox), enabled=True, command="echo dry", cooldown=0,
                max_per_hour=0, timeout=30, model="deepseek/deepseek-v4-pro"))
            self.assertEqual(calls, [
                "openai-codex/gpt-5.6-sol", "deepseek/deepseek-v4-pro"])
        finally:
            harpp_wake.spawn_agent = original
            harpp_wake.harpp_client.harpp_notify = original_notify

    def test_wake_batches_mixed_conversation_preferences_temporarily(self):
        calls = []
        original = harpp_wake.spawn_agent

        def fake_spawn(prompt, **kw):
            calls.append((kw.get("model"), kw.get("expected_replies")))
            return (True, None)

        harpp_wake.spawn_agent = fake_spawn
        try:
            records = [
                {"kind": "message", "id": 1, "conversation_id": 2, "body": "use gpt sol for this"},
                {"kind": "message", "id": 2, "conversation_id": 3, "body": "use flash for this"},
                {"kind": "message", "id": 3, "conversation_id": 4, "body": "default is fine"},
            ]
            self.inbox.write_text("".join(json.dumps(r) + "\n" for r in records), encoding="utf-8")
            self.assertTrue(harpp_wake.maybe_wake(
                str(self.inbox), enabled=True, command="echo dry", cooldown=0,
                max_per_hour=0, timeout=30, model="deepseek/deepseek-v4-pro"))
            self.assertEqual(calls, [
                ("openai-codex/gpt-5.6-sol", 1),
                ("deepseek/deepseek-v4-flash", 1),
                ("deepseek/deepseek-v4-pro", 1),
            ])
            self.assertEqual(harpp_wake.read_state()["messages"], [1, 2, 3])
        finally:
            harpp_wake.spawn_agent = original

    def test_spawn_agent_classifies_usage_exhaustion_for_fallback(self):
        ok, reason = harpp_wake.spawn_agent(
            "prompt", command="echo 'token usage limit exceeded'; exit 1",
            model="deepseek/deepseek-v4-pro", timeout=30, return_reason=True)
        self.assertFalse(ok)
        self.assertEqual(reason, "usage_exhausted")

    def test_model_exhausted_detects_usage_signals(self):
        logp = Path(self.tmp.name) / "tok.log"
        logp.write_text("request rejected: token usage exhausted\n", encoding="utf-8")
        self.assertTrue(harpp_wake._model_exhausted({"log_path": str(logp)}))
        logp.write_text("the implementation logic is wrong\n", encoding="utf-8")
        self.assertFalse(harpp_wake._model_exhausted({"log_path": str(logp)}))

    def test_delegate_stage_model_walks_fallback_order(self):
        stage = {"name": "implement", "model": "deepseek/deepseek-v4-pro"}
        self.assertEqual(harpp_wake._delegate_stage_model(stage), "openai-codex/gpt-5.6-sol")
        self.assertEqual(stage["model"], "openai-codex/gpt-5.6-sol")
        self.assertEqual(harpp_wake._delegate_stage_model(stage), "openai-codex/gpt-5.4")
        self.assertEqual(harpp_wake._delegate_stage_model(stage), "deepseek/deepseek-v4-flash")
        self.assertIsNone(harpp_wake._delegate_stage_model(stage))

    def test_delegate_stage_model_tries_manifest_model_after_temporary_preference(self):
        stage = {"name": "implement", "model": "deepseek/deepseek-v4-flash",
                 "configured_model": "deepseek/deepseek-v4-pro"}
        self.assertEqual(harpp_wake._delegate_stage_model(stage), "deepseek/deepseek-v4-pro")
        self.assertEqual(stage["tried_models"], [
            "deepseek/deepseek-v4-flash", "deepseek/deepseek-v4-pro"])

    def test_task_prompt_contains_items(self):
        harpp_wake.record_local_decision(task="conversation:2", decision="Keep scope narrow",
                                         constraints=["Never print secrets"], applied_to="stage:implement")
        harpp_wake.record_local_decision(task="conversation:3", decision="Do not leak this",
                                         applied_to="stage:implement")
        prompt = harpp_wake.task_prompt(str(self.inbox), [{"kind": "message", "id": 1, "conversation_id": 2}])
        self.assertIn("kind", prompt)
        self.assertIn(str(self.inbox), prompt)
        self.assertIn("DEC-0001", prompt)
        self.assertIn("Keep scope narrow", prompt)
        self.assertNotIn("Do not leak this", prompt)

    def test_run_record_fields_present_on_start_and_job_track(self):
        launched = []
        original_launch = harpp_wake.launch_job

        def fake_launch(**kw):
            launched.append(kw)
            return "job-0", None

        harpp_wake.launch_job = fake_launch
        try:
            wid = harpp_wake.start_workflow(title="implement feature", conversation_id=7,
                                            stages=[self._stage("implement")])
            wf = harpp_wake.get_workflow(wid)
            self.assertRegex(wf["run_id"], r"^HARPP-\d{8}-\d{5}$")
            self.assertEqual(wf["task_id"], "implement_feature")
            self.assertEqual(wf["contract_revision"], 0)
            self.assertEqual(wf["authority_level"], "L2")
            self.assertEqual(wf["authority_policy"]["L4"], "human_approval")
            self.assertIn("base_sha", wf)
            self.assertIn("current_sha", wf)
            self.assertEqual(wf["human_decisions"], [])
            self.assertEqual(wf["total_cycles"], 1)
            self.assertEqual(wf["stages"][0]["attempt_count"], 1)
            self.assertTrue(wf["stages"][0]["attempt_statuses"])
        finally:
            harpp_wake.launch_job = original_launch
        jid = harpp_wake.track_job(pid=self._dead_pid(), model="model", task="do task", conversation_id=9)
        job = next(j for j in harpp_wake.list_jobs() if j["id"] == jid)
        self.assertRegex(job["run_id"], r"^HARPP-\d{8}-\d{5}$")
        self.assertEqual(job["task_id"], "do_task")
        self.assertEqual(job["contract_revision"], 0)
        self.assertEqual(job["authority_level"], "L2")
        self.assertIn("base_sha", job)
        self.assertIn("current_sha", job)
        self.assertEqual(job["human_decisions"], [])

    def test_budget_exhaustion_blocks_never_loops(self):
        wid = "wf-budget"
        stages = [self._stage("implement", "job-1", "running"), self._stage("review")]
        self._seed_workflow(wid, stages, max_repairs=3, max_total_cycles=1, total_cycles=1)
        self._write_jobs({"job-1": {"id": "job-1", "status": "finished", "outcome": "DONE", "conversation_id": 7}})
        sent, original_send = self._patch_send()
        try:
            self.assertEqual(harpp_wake.advance_workflows(), 1)
            wf = harpp_wake.get_workflow(wid)
            self.assertEqual(wf["status"], "blocked")
            self.assertIn("BLOCKED_BUDGET_EXHAUSTED", wf["blocked_reason"])
            self.assertTrue(any("blocked" in s["body"].lower() for s in sent))
        finally:
            harpp_wake.harpp_client.send_message = original_send

    def test_resume_reports_state_and_relands_unknown_job(self):
        original_launch = harpp_wake.launch_job
        calls = []

        def fake_launch(**kw):
            calls.append(kw)
            self._write_jobs({"job-resume": {"id": "job-resume", "status": "running", "conversation_id": 7}})
            return "job-resume", None

        harpp_wake.launch_job = fake_launch
        try:
            self._seed_workflow("wf-resume", [self._stage("implement", None, "running")])
            info = harpp_wake.resume_workflow("wf-resume")
            self.assertEqual(info["workflow_id"], "wf-resume")
            self.assertTrue(info["relanded"])
            self.assertEqual(info["current_stage"], "implement")
            self.assertEqual(harpp_wake.get_workflow("wf-resume")["stages"][0]["job_id"], "job-resume")
            info2 = harpp_wake.resume_workflow("wf-resume")
            self.assertFalse(info2["relanded"])
            self.assertEqual(len(calls), 1)
        finally:
            harpp_wake.launch_job = original_launch

    def test_restart_survival_relands_after_state_reload_without_double_launch(self):
        original_launch = harpp_wake.launch_job
        calls = []

        def fake_launch(**kw):
            calls.append(kw)
            self._write_jobs({"job-restart": {"id": "job-restart", "status": "running", "conversation_id": 7}})
            return "job-restart", None

        harpp_wake.launch_job = fake_launch
        try:
            self._seed_workflow("wf-restart", [self._stage("implement", None, "running")])
            self.assertEqual(harpp_wake.advance_workflows(), 1)
            self.assertEqual(harpp_wake.advance_workflows(), 0)
            self.assertEqual(len(calls), 1)
            wf = harpp_wake.get_workflow("wf-restart")
            self.assertEqual(wf["stages"][0]["job_id"], "job-restart")
        finally:
            harpp_wake.launch_job = original_launch

    def test_authority_gating_escalates_delivery_stage(self):
        sent, decisions, original_send, original_submit = self._patch_notify()
        try:
            wid = harpp_wake.start_workflow(
                title="delivery wf", conversation_id=7,
                stages=[self._stage("release-gate")], authority_level="L2")
            wf = harpp_wake.get_workflow(wid)
            self.assertEqual(wf["status"], "escalated")
            self.assertEqual(wf["state"], "ESCALATED")
            self.assertIn("required authority L3 exceeds configured authority L2", wf["escalation_reason"])
            self.assertEqual(wf["stages"][0]["status"], "escalated")
            self.assertEqual(len(sent), 1)
            self.assertTrue(sent[0]["body"].startswith("[DECISION_REQUIRED]"))
            self.assertEqual(len(decisions), 1)
            self.assertIn("what:", decisions[0]["body"])
            self.assertIn("why:", decisions[0]["body"])
            self.assertIn("options:", decisions[0]["body"])
            self.assertIn("recommendation:", decisions[0]["body"])
            self.assertIn("risk:", decisions[0]["body"])
        finally:
            harpp_wake.harpp_client.send_message = original_send
            harpp_wake.harpp_client.submit_decision = original_submit

    def test_escalation_required_outcome_never_auto_repairs(self):
        wid = "wf-escalation-code"
        stages = [self._stage("implement", "job-1", "running"), self._stage("review")]
        self._seed_workflow(wid, stages, max_repairs=2)
        self._write_jobs({"job-1": {"id": "job-1", "status": "finished", "outcome": "FAILED",
                                     "code": "escalation_required", "reason": "contract would be broken",
                                     "conversation_id": 7}})
        sent, decisions, original_send, original_submit = self._patch_notify()
        try:
            self.assertEqual(harpp_wake.advance_workflows(), 1)
            wf = harpp_wake.get_workflow(wid)
            self.assertEqual(wf["status"], "escalated")
            self.assertEqual(wf["repair_count"], 0)
            self.assertEqual(wf["stages"][0]["status"], "escalated")
            self.assertTrue(any("DECISION_REQUIRED" in s["body"] for s in sent))
            self.assertEqual(len(decisions), 1)  # decision captured, never sent live
        finally:
            harpp_wake.harpp_client.send_message = original_send
            harpp_wake.harpp_client.submit_decision = original_submit

    def test_each_override_condition_escalates_without_auto_repair(self):
        for key in ("architecture_change", "contract_break", "data_loss_risk", "security_exception", "scope_expansion"):
            with self.subTest(key=key):
                sent, decisions, original_send, original_submit = self._patch_notify()
                try:
                    stage = self._stage("implement")
                    stage[key] = True
                    wid = harpp_wake.start_workflow(title=f"wf-{key}", conversation_id=7,
                                                    stages=[stage], max_repairs=2)
                    wf = harpp_wake.get_workflow(wid)
                    self.assertEqual(wf["status"], "escalated")
                    self.assertEqual(wf["repair_count"], 0)
                    self.assertEqual(wf["stages"][0]["attempt_count"], 0)
                    self.assertEqual(wf["stages"][0]["status"], "escalated")
                    self.assertIn("DECISION_REQUIRED", sent[0]["body"])
                    self.assertEqual(len(decisions), 1)  # decision captured, never sent live
                finally:
                    harpp_wake.harpp_client.send_message = original_send
                    harpp_wake.harpp_client.submit_decision = original_submit

    def test_escalation_notifications_suppressed_in_testing_mode(self):
        # In testing mode (HARPP_TESTING_MODE=1) the workflow still escalates
        # locally, but no live message or decision is sent — so test workflows
        # cannot pollute live HARPP with "Escalation required" decisions.
        sent, decisions, original_send, original_submit = self._patch_notify()
        old = os.environ.get("HARPP_TESTING_MODE")
        os.environ["HARPP_TESTING_MODE"] = "1"
        try:
            stage = self._stage("implement")
            stage["scope_expansion"] = True
            wid = harpp_wake.start_workflow(title="wf-testing", conversation_id=7,
                                            stages=[stage], max_repairs=2)
            wf = harpp_wake.get_workflow(wid)
            self.assertEqual(wf["status"], "escalated")
            self.assertEqual(wf["stages"][0]["status"], "escalated")
            self.assertEqual(sent, [])
            self.assertEqual(decisions, [])
        finally:
            if old is None:
                os.environ.pop("HARPP_TESTING_MODE", None)
            else:
                os.environ["HARPP_TESTING_MODE"] = old
            harpp_wake.harpp_client.send_message = original_send
            harpp_wake.harpp_client.submit_decision = original_submit

    def test_l4_policy_always_escalates_for_human_approval(self):
        sent, decisions, original_send, original_submit = self._patch_notify()
        try:
            wid = harpp_wake.start_workflow(
                title="release wf", conversation_id=7,
                stages=[self._stage("release")], authority_level="L4")
            wf = harpp_wake.get_workflow(wid)
            self.assertEqual(wf["status"], "escalated")
            self.assertIn("requires human approval by policy", wf["escalation_reason"])
            self.assertIn("configured=L4 required=L4", sent[0]["body"])
            self.assertEqual(decisions[0]["workbench_state"], "DECISION_REQUIRED")
        finally:
            harpp_wake.harpp_client.send_message = original_send
            harpp_wake.harpp_client.submit_decision = original_submit

    def test_harpp_notify_prefixes_and_only_actionable_create_decisions(self):
        sent, decisions, original_send, original_submit = self._patch_notify()
        try:
            harpp_wake.harpp_client.harpp_notify(conversation_id=7, message_type="INFO", body="hello")
            harpp_wake.harpp_client.harpp_notify(conversation_id=7, message_type="PROGRESS", body="working")
            harpp_wake.harpp_client.harpp_notify(
                conversation_id=7, message_type="BLOCKED", body="stop",
                decision={"title": "Blocked", "what": "w", "why": "y", "options": ["A", "B"],
                          "recommendation": "A", "risk": "r"})
            self.assertEqual([m["body"] for m in sent[:2]], ["[HARPP] hello", "[PROGRESS] working"])
            self.assertEqual(sent[2]["body"], "[BLOCKED] stop")
            self.assertEqual(len(decisions), 1)
            self.assertEqual(decisions[0]["title"], "Blocked")
        finally:
            harpp_wake.harpp_client.send_message = original_send
            harpp_wake.harpp_client.submit_decision = original_submit

    def test_workflow_done_release_gate_becomes_release_ready(self):
        wid = "wf-release"
        stages = [self._stage("release-gate", job_id="job-r", status="running")]
        self._seed_workflow(wid, stages)
        self._write_jobs({"job-r": {"id": "job-r", "status": "finished", "outcome": "DONE", "conversation_id": 7}})
        sent, decisions, original_send, original_submit = self._patch_notify()
        try:
            self.assertEqual(harpp_wake.advance_workflows(), 1)
            self.assertEqual(harpp_wake.get_workflow(wid)["status"], "done")
            self.assertTrue(sent[0]["body"].startswith("[RELEASE_READY]"))
            self.assertEqual(decisions[0]["workbench_state"], "RELEASE_READY")
        finally:
            harpp_wake.harpp_client.send_message = original_send
            harpp_wake.harpp_client.submit_decision = original_submit

    def test_workflow_blocked_and_failed_are_classified(self):
        sent, decisions, original_send, original_submit = self._patch_notify()
        try:
            wf = {"id": "wf1", "title": "wf", "conversation_id": 7, "stages": [self._stage("implement")],
                  "blocked_reason": "BLOCKED_BUDGET_EXHAUSTED: x", "repair_count": 0}
            harpp_wake._notify_workflow(wf, "BLOCKED", wf["stages"][0], reason=wf["blocked_reason"])
            harpp_wake._notify_workflow(wf, "FAILED", wf["stages"][0], reason="boom")
            self.assertTrue(sent[0]["body"].startswith("[BLOCKED]"))
            self.assertTrue(sent[1]["body"].startswith("[FAILED]"))
            self.assertEqual(len(decisions), 1)
            self.assertEqual(decisions[0]["workbench_state"], "BLOCKED")
        finally:
            harpp_wake.harpp_client.send_message = original_send
            harpp_wake.harpp_client.submit_decision = original_submit

    def test_record_and_list_local_decisions(self):
        rec = harpp_wake.record_local_decision(
            task="workflow:abc", decision="Use stdlib only",
            constraints=["Never print secrets"], additional_requirements=["Keep scope to tools/harpp-bridge"],
            applied_to="stage:slice4")
        self.assertEqual(rec["id"], "DEC-0001")
        listed = harpp_wake.load_decisions()
        self.assertEqual(len(listed), 1)
        self.assertEqual(listed[0]["decision"], "Use stdlib only")
        self.assertEqual(listed[0]["constraints"], ["Never print secrets"])

    def test_auto_record_directive_is_idempotent_by_message_id(self):
        message = {"kind": "message", "id": 42, "conversation_id": 9, "sender_type": "owner",
                   "body": "Use gpt sol. Keep scope to tools/harpp-bridge only. Never print secrets.",
                   "created_at": "2026-08-25 00:00:00"}
        self.assertEqual(harpp_wake.auto_record_directives([message]), 1)
        self.assertEqual(harpp_wake.auto_record_directives([message]), 0)
        records = harpp_wake.load_decisions()
        self.assertEqual(len(records), 1)
        self.assertEqual(records[0]["source_message_id"], 42)
        self.assertIn("Keep scope to tools/harpp-bridge only.", records[0]["constraints"])
        self.assertIn("Never print secrets.", records[0]["constraints"])

    def test_status_and_question_messages_are_not_recorded(self):
        records = [
            {"kind": "message", "id": 50, "conversation_id": 9, "sender_type": "owner", "body": "status?"},
            {"kind": "message", "id": 51, "conversation_id": 9, "sender_type": "owner", "body": "thanks"},
            {"kind": "message", "id": 52, "conversation_id": 9, "sender_type": "owner", "body": "workflow list"},
        ]
        self.assertEqual(harpp_wake.auto_record_directives(records), 0)
        self.assertEqual(harpp_wake.load_decisions(), [])

    def test_decision_ledger_is_bounded(self):
        original_limit = harpp_wake.DECISION_LEDGER_LIMIT
        harpp_wake.DECISION_LEDGER_LIMIT = 3
        try:
            for i in range(5):
                harpp_wake.record_local_decision(task=f"t{i}", decision=f"d{i}")
            records = harpp_wake.load_decisions()
            self.assertEqual([r["id"] for r in records], ["DEC-0003", "DEC-0004", "DEC-0005"])
        finally:
            harpp_wake.DECISION_LEDGER_LIMIT = original_limit

    def test_summarize_formats_counts_and_next(self):
        summary = harpp_wake.summarize({
            "task": "Slice 5",
            "state": "IMPLEMENTATION COMPLETE",
            "changed_files": 3,
            "unit_passed": 5,
            "unit_total": 5,
            "integration_passed": 2,
            "integration_total": 2,
            "playwright_passed": 1,
            "playwright_total": 1,
            "repairs_done": 1,
            "repairs_max": 3,
            "scope": True,
            "review": "PENDING",
            "next": "release gate / safe to move forward",
            "details": "short detail",
        })
        self.assertIn("Slice 5 — IMPLEMENTATION COMPLETE", summary)
        self.assertIn("Changed      3 files", summary)
        self.assertIn("Unit         5/5 ✓", summary)
        self.assertIn("Integration  2/2 ✓", summary)
        self.assertIn("Playwright   1/1 ✓", summary)
        self.assertIn("Repairs      1/3", summary)
        self.assertIn("Scope        ✓", summary)
        self.assertIn("Review       PENDING", summary)
        self.assertIn("Next: release gate / safe to move forward", summary)

    def test_summarize_omits_unknowns_and_redacts_secrets(self):
        summary = harpp_wake.summarize({
            "task": "Task",
            "state": "RUNNING",
            "next": "awaiting decision",
            "details": "bridge_key=abc123 token=secret-value",
        })
        self.assertNotIn("Changed", summary)
        self.assertNotIn("Unit", summary)
        self.assertNotIn("abc123", summary)
        self.assertNotIn("secret-value", summary)
        self.assertIn("<redacted>", summary)

    def test_phone_summary_used_for_info_progress_and_headline_kept_for_actionable(self):
        sent, decisions, original_send, original_submit = self._patch_notify()
        try:
            wf = {"id": "wf-sum", "title": "summary wf", "conversation_id": 7,
                  "workspace": None, "repair_count": 0, "max_repairs": 2,
                  "stages": [self._stage("implement"), self._stage("review", status="pending")]}
            harpp_wake._notify_workflow(wf, "DONE", wf["stages"][0])
            harpp_wake._notify_workflow(wf, "REPAIR", wf["stages"][1], round_no=1, max_repairs=2)
            harpp_wake._notify_workflow(wf, "BLOCKED", wf["stages"][1], reason="budget")
            self.assertIn("WORKFLOW COMPLETE", sent[0]["body"])
            self.assertIn("Next: done — safe to move forward", sent[0]["body"])
            self.assertIn("REVIEW REMEDIATION", sent[1]["body"])
            self.assertIn("Next: auto-repair round 1/2 in progress", sent[1]["body"])
            self.assertIn("summary wf / review — BLOCKED", sent[2]["body"])
            self.assertIn("what: unblock workflow", sent[2]["body"])
            self.assertEqual(decisions[0]["workbench_state"], "BLOCKED")
        finally:
            harpp_wake.harpp_client.send_message = original_send
            harpp_wake.harpp_client.submit_decision = original_submit

    def test_secret_like_values_are_redacted_before_recording(self):
        rec = harpp_wake.record_local_decision(task="workflow:x",
                                               decision="bridge_key=abc123 token=secret-value use flash")
        self.assertNotIn("abc123", rec["decision"])
        self.assertNotIn("secret-value", rec["decision"])
        self.assertIn("<redacted>", rec["decision"])

    def test_old_records_without_new_fields_still_load(self):
        harpp_wake.save_jobs_state({"jobs": {"j1": {"id": "j1", "pid": 1, "model": "m", "task": "t",
                                                       "conversation_id": 7, "status": "running"}}, "reported": []})
        harpp_wake.save_workflows_state({"workflows": {"w1": {"id": "w1", "title": "old", "conversation_id": 7,
                                                                  "stages": [self._stage("implement")],
                                                                  "current_index": 0, "status": "running"}}})
        job = harpp_wake.jobs_state()["jobs"]["j1"]
        wf = harpp_wake.get_workflow("w1")
        self.assertIn("run_id", job)
        self.assertIn("task_id", job)
        self.assertIn("authority_level", job)
        self.assertIn("run_id", wf)
        self.assertIn("max_total_cycles", wf)
        self.assertIn("human_decisions", wf)
        self.assertIn("authority_level", wf)


class WorkflowManifestValidationTest(unittest.TestCase):
    """P1-3: versioned manifest schema/preflight and structured stage results."""

    def _valid_manifest(self):
        return {
            "schema_version": harpp_wake.WORKFLOW_MANIFEST_SCHEMA_VERSION,
            "title": "Loop",
            "stages": [
                {"name": "architect", "model": "openai-codex/gpt-5.6-sol",
                 "prompt": "You are the architect.\n", "marker": "SOL_ARCH status=PASS",
                 "verify": "test -f ARCHITECTURE.md", "timeout": 1800},
                {"name": "implement", "model": "deepseek/deepseek-v4-pro",
                 "prompt": "You are the implementer.\n", "marker": "SOL_IMPL status=PASS",
                 "verify": "git diff --check", "timeout": 2400},
            ],
        }

    def test_valid_manifest_passes(self):
        self.assertEqual(harpp_wake.validate_workflow_manifest(self._valid_manifest()), [])

    def test_missing_stages_rejected(self):
        errors = harpp_wake.validate_workflow_manifest({"title": "x"})
        self.assertTrue(any("stages" in e for e in errors), errors)

    def test_unsupported_model_identifies_field(self):
        m = self._valid_manifest()
        m["stages"][0]["model"] = "nope/nope"
        errors = harpp_wake.validate_workflow_manifest(m)
        self.assertTrue(any("stages[0].model" in e for e in errors), errors)

    def test_missing_prompt_identifies_field(self):
        m = self._valid_manifest()
        m["stages"][0].pop("prompt")
        m["stages"][0].pop("prompt_file", None)
        errors = harpp_wake.validate_workflow_manifest(m)
        self.assertTrue(any("stages[0].prompt" in e for e in errors), errors)

    def test_missing_prompt_file_rejected(self):
        m = self._valid_manifest()
        m["stages"][0]["prompt_file"] = "stages/does-not-exist.md"
        errors = harpp_wake.validate_workflow_manifest(m)
        self.assertTrue(any("stages[0].prompt_file" in e for e in errors), errors)

    def test_invalid_budget_identifies_field(self):
        m = self._valid_manifest()
        m["max_repairs"] = -1
        errors = harpp_wake.validate_workflow_manifest(m)
        self.assertTrue(any("max_repairs" in e for e in errors), errors)

    def test_unsafe_authority_rejected(self):
        m = self._valid_manifest()
        m["stages"][1]["required_authority"] = "L9"
        errors = harpp_wake.validate_workflow_manifest(m)
        self.assertTrue(any("required_authority" in e for e in errors), errors)

    def test_duplicate_stage_name_rejected(self):
        m = self._valid_manifest()
        m["stages"][1]["name"] = "Architect"
        errors = harpp_wake.validate_workflow_manifest(m)
        self.assertTrue(any("duplicate stage name" in e for e in errors), errors)

    def test_owner_text_interpolation_verify_rejected(self):
        m = self._valid_manifest()
        m["stages"][0]["verify"] = "echo {{body}} > out.txt"
        errors = harpp_wake.validate_workflow_manifest(m)
        self.assertTrue(any("verify" in e and "interpolate owner text" in e for e in errors), errors)

    def test_destructive_verify_rejected(self):
        m = self._valid_manifest()
        m["stages"][0]["verify"] = "rm -rf /"
        errors = harpp_wake.validate_workflow_manifest(m)
        self.assertTrue(any("verify" in e and "destructive" in e for e in errors), errors)

    def test_admin_shell_substitution_verify_is_allowed(self):
        # Legitimate admin-authored `$(...)` in a manifest verify is not flagged.
        m = self._valid_manifest()
        m["stages"][0]["verify"] = 'test -s ARCHITECTURE.md && test "$(tail -n 1 ARCHITECTURE.md)" = "ok"'
        self.assertEqual(harpp_wake.validate_workflow_manifest(m), [])

    def test_preflight_raises_with_exact_field(self):
        m = self._valid_manifest()
        m["stages"][0]["model"] = "unknown/model"
        with self.assertRaises(ValueError) as ctx:
            harpp_wake.preflight_workflow_manifest(m)
        self.assertIn("stages[0].model", str(ctx.exception))

    def test_preflight_returns_manifest_when_valid(self):
        m = self._valid_manifest()
        self.assertIs(harpp_wake.preflight_workflow_manifest(m), m)

    def test_structured_stage_result_identity(self):
        wf = {"id": "wf1", "run_id": "run-1"}
        stage = {"name": "architect"}
        job = {"id": "job1", "marker": None, "verify": None, "model": "m"}
        result = harpp_wake._build_stage_result(wf, stage, job, "DONE")
        self.assertEqual(result["schema_version"], harpp_wake.WORKFLOW_MANIFEST_SCHEMA_VERSION)
        self.assertEqual(result["workflow_id"], "wf1")
        self.assertEqual(result["run_id"], "run-1")
        self.assertEqual(result["stage_name"], "architect")
        self.assertEqual(result["outcome"], "DONE")
        self.assertTrue(harpp_wake._stage_result_matches(wf, stage, result))

    def test_structured_result_identity_mismatch_rejected(self):
        wf = {"id": "wf1", "run_id": "run-1"}
        stage = {"name": "architect"}
        result = {"schema_version": harpp_wake.WORKFLOW_MANIFEST_SCHEMA_VERSION,
                  "workflow_id": "wf-OTHER", "run_id": "run-9", "stage_name": "architect",
                  "outcome": "DONE", "evidence": ["marker:FOUND"]}
        self.assertFalse(harpp_wake._stage_result_matches(wf, stage, result))
        # wrong stage
        wrong_stage = dict(result, workflow_id="wf1", run_id="run-1", stage_name="review")
        self.assertFalse(harpp_wake._stage_result_matches(wf, stage, wrong_stage))
        # non-DONE outcome
        failed = dict(result, workflow_id="wf1", run_id="run-1", outcome="FAILED")
        self.assertFalse(harpp_wake._stage_result_matches(wf, stage, failed))
        # DONE without evidence (marker-only with no verification evidence) rejected
        no_evidence = dict(result, workflow_id="wf1", run_id="run-1", evidence=[])
        self.assertFalse(harpp_wake._stage_result_matches(wf, stage, no_evidence))


class WakeAdapterTest(unittest.TestCase):
    """P1-5: optional Wake-on-LAN adapter behind config + fake-adapter tests."""

    def setUp(self):
        self.original_claim = harpp_wake.harpp_client.claim_runner_wake
        self.original_deliver = harpp_wake.harpp_client.deliver_runner_wake
        self.original_fail = harpp_wake.harpp_client.fail_runner_wake
        self.original_wake_runner = harpp_wake.wake_runner

    def tearDown(self):
        harpp_wake.harpp_client.claim_runner_wake = self.original_claim
        harpp_wake.harpp_client.deliver_runner_wake = self.original_deliver
        harpp_wake.harpp_client.fail_runner_wake = self.original_fail
        harpp_wake.wake_runner = self.original_wake_runner

    def test_wake_relay_pass_self_ack(self):
        delivered = []
        wake_calls = []
        harpp_wake.harpp_client.claim_runner_wake = lambda **kw: {
            "data": {"request": {"id": 1}, "claim_token": "tok"}}
        harpp_wake.harpp_client.deliver_runner_wake = lambda rid, token, **kw: delivered.append((rid, token))
        harpp_wake.wake_runner = lambda *args, **kw: wake_calls.append(args) or (True, "sent")

        harpp_wake.wake_relay_pass(own_runner_key="desktop-test", config={})

        self.assertEqual(delivered, [(1, "tok")])
        self.assertEqual(wake_calls, [])

    def test_wake_relay_pass_wakes_other(self):
        delivered = []
        wake_calls = []
        harpp_wake.harpp_client.claim_runner_wake = lambda runner_key, **kw: (
            {"data": {"request": {"id": 2}, "claim_token": "tok"}}
            if runner_key == "other" else {"data": {"request": None}})
        harpp_wake.harpp_client.deliver_runner_wake = lambda rid, token, **kw: delivered.append((rid, token))
        harpp_wake.wake_runner = lambda key, **kw: wake_calls.append(key) or (True, "sent")

        harpp_wake.wake_relay_pass(
            own_runner_key="desktop-test", config={"wake_on_lan": {"runners": {"other": {}}}})

        self.assertEqual(wake_calls, ["other"])
        self.assertEqual(delivered, [(2, "tok")])

    def test_wake_relay_pass_fails_other(self):
        failed = []
        harpp_wake.harpp_client.claim_runner_wake = lambda runner_key, **kw: (
            {"data": {"request": {"id": 3}, "claim_token": "tok"}}
            if runner_key == "other" else {"data": {"request": None}})
        harpp_wake.harpp_client.fail_runner_wake = lambda rid, token, error="", **kw: failed.append(
            (rid, token, error))
        harpp_wake.wake_runner = lambda *args, **kw: (False, "no mac")

        harpp_wake.wake_relay_pass(
            own_runner_key="desktop-test", config={"wake_on_lan": {"runners": {"other": {}}}})

        self.assertEqual(failed, [(3, "tok", "no mac")])

    def test_wake_relay_pass_tolerates_client_error(self):
        claims = []
        delivered = []

        def claim(runner_key, **kw):
            claims.append(runner_key)
            if runner_key == "desktop-test":
                raise RuntimeError("offline")
            return {"data": {"request": {"id": 4}, "claim_token": "tok"}}

        harpp_wake.harpp_client.claim_runner_wake = claim
        harpp_wake.harpp_client.deliver_runner_wake = lambda rid, token, **kw: delivered.append((rid, token))
        harpp_wake.wake_runner = lambda *args, **kw: (True, "sent")

        harpp_wake.wake_relay_pass(
            own_runner_key="desktop-test", config={"wake_on_lan": {"runners": {"other": {}}}})

        self.assertEqual(claims, ["desktop-test", "other"])
        self.assertEqual(delivered, [(4, "tok")])

    def test_wake_relay_pass_no_request(self):
        calls = []
        harpp_wake.harpp_client.claim_runner_wake = lambda **kw: {"data": {"request": None}}
        harpp_wake.harpp_client.deliver_runner_wake = lambda *args, **kw: calls.append("deliver")
        harpp_wake.harpp_client.fail_runner_wake = lambda *args, **kw: calls.append("fail")
        harpp_wake.wake_runner = lambda *args, **kw: calls.append("wake") or (True, "sent")

        harpp_wake.wake_relay_pass(own_runner_key="desktop-test", config={})

        self.assertEqual(calls, [])

    def test_no_adapter_when_not_configured(self):
        self.assertIsNone(harpp_wake.get_wake_adapter({}))
        self.assertIsNone(harpp_wake.get_wake_adapter({"wake_on_lan": {"enabled": False}}))
        ok, note = harpp_wake.wake_runner("desktop", {"wake_on_lan": {}})
        self.assertFalse(ok)
        self.assertIn("stays queued", note)

    def test_fake_adapter_records_and_reports(self):
        calls = []
        fake = harpp_wake.FakeWakeAdapter(ok=True, calls=calls)
        ok, note = harpp_wake.wake_runner("desktop", {"wake_on_lan": {"enabled": True}}, adapter=fake)
        self.assertTrue(ok)
        self.assertEqual(len(calls), 1)
        self.assertEqual(calls[0]["runner_key"], "desktop")

    def test_fake_adapter_failure_leaves_queued(self):
        fake = harpp_wake.FakeWakeAdapter(ok=False, note="wake rejected by network")
        ok, note = harpp_wake.wake_runner("desktop", {}, adapter=fake)
        self.assertFalse(ok)
        self.assertEqual(note, "wake rejected by network")

    def test_wol_requires_configured_mac(self):
        adapter = harpp_wake.WakeOnLanAdapter({"enabled": True})
        ok, note = adapter.wake("desktop")
        self.assertFalse(ok)
        self.assertIn("no MAC configured", note)

    def test_wol_rejects_invalid_mac_network_restriction(self):
        adapter = harpp_wake.WakeOnLanAdapter({"enabled": True, "mac": "not-a-mac"})
        ok, note = adapter.wake("desktop")
        self.assertFalse(ok)
        self.assertIn("invalid MAC", note)

    def test_wol_is_rate_limited(self):
        adapter = harpp_wake.WakeOnLanAdapter({"enabled": True, "mac": "00:11:22:33:44:55", "min_interval": 60})
        adapter._last = time.time()  # pretend a packet was just sent
        ok, note = adapter.wake("desktop")
        self.assertFalse(ok)
        self.assertIn("rate limited", note)

    def test_magic_packet_shape(self):
        packet = harpp_wake._magic_packet("00:11:22:33:44:55")
        self.assertEqual(len(packet), 102)  # 6 ff + 16 * 6 bytes
        self.assertEqual(packet[:6], b"\xff" * 6)
        self.assertEqual(packet[6:12], bytes.fromhex("001122334455"))


class ContextBlockTest(unittest.TestCase):
    """Slice A/B: conversation context block into worker + quick-reply prompts."""

    def _envelope(self):
        return {
            "conversation": {"id": 2, "title": "Memory conversation"},
            "summary": {
                "version": 7, "title": "Memory conversation",
                "recent": [
                    {"sender_type": "user", "body": "Please run the task."},
                    {"sender_type": "harness", "body": "Will do."},
                ],
                "active_run": {"id": 5, "state": "SUCCEEDED", "report_state": "DELIVERED",
                               "last_status": "Done."},
                "decisions": [{"decision_key": "DEC-REUSE-1", "decision": "Use option B",
                               "title": "Reuse decision"}],
            },
        }

    def test_context_block_includes_title_run_and_decisions(self):
        original = harpp_wake.harpp_client.context_for_conversation
        harpp_wake.harpp_client.context_for_conversation = lambda *a, **k: self._envelope()
        try:
            block = harpp_wake.conversation_context_block(2)
        finally:
            harpp_wake.harpp_client.context_for_conversation = original
        self.assertIn("Memory conversation", block)
        self.assertIn("state=SUCCEEDED", block)
        self.assertIn("DEC-REUSE-1", block)
        self.assertIn("Use option B", block)
        self.assertIn("owner:", block)

    def test_context_block_includes_only_current_approved_memory(self):
        envelope = self._envelope()
        envelope["summary"]["memory"] = [
            {"decision_key": "ADR-CURRENT-1", "title": "Current architecture",
             "decision": "Use the approved event boundary", "lifecycle_state": "APPLIED",
             "kind": "adr", "authority": "adr_current", "status": "current", "revision": 2},
            {"decision_key": "DEC-HISTORICAL-1", "title": "Old architecture",
             "decision": "Use the retired direct path", "lifecycle_state": "CLOSED",
             "kind": "decision", "authority": "decision_historical",
             "status": "historical", "revision": 1},
        ]
        original = harpp_wake.harpp_client.context_for_conversation
        harpp_wake.harpp_client.context_for_conversation = lambda *a, **k: envelope
        try:
            block = harpp_wake.conversation_context_block(2)
        finally:
            harpp_wake.harpp_client.context_for_conversation = original
        self.assertIn("Approved memory (RAG):", block)
        self.assertIn("ADR-CURRENT-1: Current architecture — Use the approved event boundary", block)
        self.assertNotIn("DEC-HISTORICAL-1", block)
        self.assertNotIn("retired direct path", block)

    def test_context_block_includes_recent_debate_verdict(self):
        import tempfile
        original_jobs = harpp_wake.JOBS_FILE
        original_client = harpp_wake.harpp_client.context_for_conversation
        block = ""
        with tempfile.TemporaryDirectory() as td:
            log = Path(td) / "debate-job.log"
            log.write_text("...\nverdict: APPROVED after 2 round(s)\n", encoding="utf-8")
            jobs = Path(td) / "jobs.json"
            jobs.write_text(json.dumps({"jobs": [{
                "id": "deb-1", "status": "finished", "conversation_id": 2,
                "model": "arch-debate", "task": "Architecture debate: workspaces",
                "log_path": str(log), "finished_at": "2026-08-29 09:15:04",
            }]}), encoding="utf-8")
            harpp_wake.JOBS_FILE = jobs
            harpp_wake.harpp_client.context_for_conversation = lambda *a, **k: self._envelope()
            try:
                block = harpp_wake.conversation_context_block(2)
            finally:
                harpp_wake.JOBS_FILE = original_jobs
                harpp_wake.harpp_client.context_for_conversation = original_client
        self.assertIn("Recent debate activity:", block)
        self.assertIn("deb-1: finished — verdict: APPROVED after 2 round(s)", block)

    def test_context_block_graceful_on_miss(self):
        original = harpp_wake.harpp_client.context_for_conversation

        def boom(*a, **k):
            raise RuntimeError("no config")
        harpp_wake.harpp_client.context_for_conversation = boom
        try:
            block = harpp_wake.conversation_context_block(2)
        finally:
            harpp_wake.harpp_client.context_for_conversation = original
        self.assertEqual(block, "")

    def test_task_prompt_appends_context_block(self):
        original = harpp_wake.conversation_context_block
        harpp_wake.conversation_context_block = lambda cid, **k: "Conversation: X\nActive run: #1 state=RUNNING"
        try:
            prompt = harpp_wake.task_prompt("inbox.jsonl", [{"id": 1, "conversation_id": 2, "body": "status?"}],
                                           template="T: {{ITEMS}} {{CONTEXT}}")
        finally:
            harpp_wake.conversation_context_block = original
        self.assertIn("Active run: #1 state=RUNNING", prompt)
        self.assertIn("# Conversation context", prompt)

    def test_task_prompt_appends_approved_memory_for_single_conversation(self):
        original = harpp_wake.conversation_context_block
        harpp_wake.conversation_context_block = lambda cid, **k: "Approved memory (RAG):\n- ADR-1: Keep the boundary"
        try:
            prompt = harpp_wake.task_prompt(
                "inbox.jsonl",
                [{"id": 1, "conversation_id": 2, "body": "start"}],
                template="T: {{ITEMS}} {{CONTEXT}}",
            )
        finally:
            harpp_wake.conversation_context_block = original
        self.assertIn("Approved memory (RAG):", prompt)

    def test_quick_reply_is_conversation_grounded(self):
        original_block = harpp_wake.conversation_context_block
        harpp_wake.conversation_context_block = lambda cid, **k: "Conversation: Memory conversation\nstate=SUCCEEDED"
        original_completion = harpp_wake._quick_completion
        prompts = []
        harpp_wake._quick_completion = lambda *a, **k: prompts.append(a[0]) or "The task completed."
        sent = {}
        original_notify = harpp_wake.harpp_client.harpp_notify
        harpp_wake.harpp_client.harpp_notify = lambda **kw: sent.update(kw) or {"ok": True}
        try:
            ok = harpp_wake._quick_reply({"id": 9, "conversation_id": 2, "body": "what changed?"})
        finally:
            harpp_wake.conversation_context_block = original_block
            harpp_wake._quick_completion = original_completion
            harpp_wake.harpp_client.harpp_notify = original_notify
        self.assertTrue(ok)
        self.assertTrue(prompts)
        self.assertIn("Relevant conversation context", prompts[0])
        self.assertIn("Memory conversation", prompts[0])
        self.assertIn("state=SUCCEEDED", prompts[0])


if __name__ == "__main__":
    unittest.main()
