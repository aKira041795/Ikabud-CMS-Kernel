#!/usr/bin/env python3
"""Unit tests for the guarded wake scheduler (no network, no model).

Run:  python3 -m unittest tools.harpp-bridge.tests.test_harpp_wake
  or:  harpp self-test
"""
import json
import os
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


class HarppWakeTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        base = Path(self.tmp.name)
        harpp_wake.CONFIG_DIR = base
        harpp_wake.LOCK_FILE = base / "wake.lock"
        harpp_wake.PROCESSED_FILE = base / "wake-processed.json"
        harpp_wake.WAKE_LOG = base / "wake.log"
        harpp_wake.JOBS_FILE = base / "jobs.json"
        self.inbox = base / "inbox.jsonl"
        self.inbox.write_text(
            json.dumps({"kind": "message", "id": 1, "conversation_id": 2, "body": "hi"}) + "\n",
            encoding="utf-8")

    def tearDown(self):
        self.tmp.cleanup()

    def _wake(self, **kw):
        defaults = dict(command="echo 'HARPP_WAKE_RESULT replies_sent=1 items_processed=1'",
                        cooldown=0, max_per_hour=0, timeout=30, enabled=True)
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

    # --- job monitor ---

    def _dead_pid(self) -> int:
        p = subprocess.Popen(["true"])
        p.wait()
        return p.pid  # reaped -> os.kill(pid, 0) raises ProcessLookupError

    def _patch_send(self):
        sent = []
        original = harpp_wake.harpp_client.send_message
        harpp_wake.harpp_client.send_message = lambda **kw: sent.append(kw)
        return sent, original

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
            self.assertIn("DONE", sent[0]["body"])
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
            self.assertIn("marker NOT FOUND", sent[0]["body"])
        finally:
            harpp_wake.harpp_client.send_message = original

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
            self.assertIn("DONE", sent[0]["body"])
            self.assertIn("verify exit=OK", sent[0]["body"])
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
            harpp_wake.harpp_client.send_message = lambda **kw: sent.append(kw)
            self.assertEqual(harpp_wake.monitor_jobs(), 1)
            self.assertEqual(len(sent), 1)
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
                "prompt", command="echo 'HARPP_WAKE_RESULT replies_sent=1 items_processed=1'",
                model="deepseek/deepseek-v4-pro", timeout=30, expected_replies=1,
                open_terminal=True)
            self.assertTrue(ok)
            self.assertEqual(len(calls), 1)
            tee_log = Path(harpp_wake.CONFIG_DIR) / "wake-agent.log"
            self.assertIn("HARPP_WAKE_RESULT", tee_log.read_text(encoding="utf-8"))
        finally:
            harpp_wake.open_agent_terminal = original

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
            self.assertIn("marker NOT FOUND", sent[0]["body"])
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
            self.assertIn("marker NOT FOUND", sent[0]["body"])
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

    def test_wake_falls_back_when_requested_model_fails(self):
        calls = []
        original = harpp_wake.spawn_agent

        def fake_spawn(prompt, **kw):
            calls.append(kw.get("model"))
            # Requested model (gpt sol) fails; the configured default (deepseek pro) succeeds.
            return kw.get("model") == "deepseek/deepseek-v4-pro"

        harpp_wake.spawn_agent = fake_spawn
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

    def test_task_prompt_contains_items(self):
        prompt = harpp_wake.task_prompt(str(self.inbox), [{"kind": "message", "id": 1, "conversation_id": 2}])
        self.assertIn("kind", prompt)
        self.assertIn(str(self.inbox), prompt)


if __name__ == "__main__":
    unittest.main()
