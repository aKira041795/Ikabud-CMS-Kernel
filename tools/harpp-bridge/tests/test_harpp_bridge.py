#!/usr/bin/env python3
"""Local tests for the HARPP bridge client + MCP server (no network).

Run:  python3 -m unittest tools.harpp-bridge.tests.test_harpp_bridge
  or:  harpp self-test
"""
import json
import os
import runpy
import sys
import subprocess
import tempfile
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

import harpp_client  # noqa: E402
import harpp_mcp  # noqa: E402


class HarppClientTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.cfg_path = Path(self.tmp.name) / "config.json"
        self.cfg_path.write_text(json.dumps({
            "base_url": "https://harpp.example.com", "bridge_key": "k-test", "tenant_id": "7",
        }))
        self._old = {k: os.environ.get(k) for k in ("HARPP_CONFIG", "HARPP_BASE_URL", "HARPP_BRIDGE_KEY", "HARPP_TENANT_ID", "HARPP_DRY_RUN")}
        os.environ["HARPP_CONFIG"] = str(self.cfg_path)
        os.environ["HARPP_DRY_RUN"] = "1"

    def tearDown(self):
        for k, v in self._old.items():
            if v is None:
                os.environ.pop(k, None)
            else:
                os.environ[k] = v
        self.tmp.cleanup()

    def test_autoprocess_routes_owner_input(self):
        calls = []

        def fake_api(method, url, body=None, **kw):
            calls.append((method, url, body))
            return {"ok": True}

        original = harpp_client.api
        harpp_client.api = fake_api
        try:
            notes = harpp_client.autoprocess([
                {"kind": "message", "id": 5, "conversation_id": 2, "body": "hi"},
                {"kind": "decision", "id": 9, "decision": "Option A", "lifecycle_state": "DECIDED"},
            ])
        finally:
            harpp_client.api = original
        urls = [c[1] for c in calls]
        # Each owner message creates/resolves one durable run, and the DECIDED
        # decision triggers acknowledge + applied.
        self.assertEqual(len(calls), 3, notes)
        self.assertTrue(any(u.endswith("/bridge/runs") for u in urls), urls)
        self.assertTrue(any(u.endswith("/acknowledge") for u in urls), urls)
        self.assertTrue(any(u.endswith("/applied") for u in urls), urls)
        self.assertEqual(notes[0], "message 5 queued state=unknown ok=True", notes)
        self.assertEqual(notes[1], "decision 9 ack=True apply=True", notes)

    def test_successful_idempotent_message_writes_delivery_receipt(self):
        original = harpp_client.api
        harpp_client.api = lambda *args, **kwargs: {"ok": True, "data": {"message_id": 77}}
        try:
            result = harpp_client.send_message(
                body="secret body is not persisted", conversation_id=2,
                idempotency_key="wake-message-55")
        finally:
            harpp_client.api = original
        self.assertTrue(result["ok"])
        records = [json.loads(line) for line in harpp_client.delivery_receipts_path().read_text().splitlines()]
        self.assertEqual(records[-1]["idempotency_key"], "wake-message-55")
        self.assertEqual(records[-1]["message_id"], 77)
        self.assertNotIn("body", records[-1])

    def test_emit_reports_failure_instead_of_claiming_staging(self):
        module = runpy.run_path(str(Path(__file__).resolve().parent.parent / "harpp"))
        record = {"kind": "message", "id": 8, "conversation_id": 2}
        inbox = Path(self.tmp.name) / "inbox.jsonl"
        self.assertTrue(module["_emit"](record, str(inbox)))
        self.assertEqual(json.loads(inbox.read_text()), record)
        self.assertFalse(module["_emit"](record, self.tmp.name))

    def test_desktop_runner_claims_executes_and_completes(self):
        module = runpy.run_path(str(Path(__file__).resolve().parent.parent / "harpp"))
        client = module["harpp_client"]
        wake = module["harpp_wake"]
        calls = []
        originals = {name: getattr(client, name) for name in (
            "register_runner", "reconcile_runs", "claim_run", "conversation_context",
            "mark_run_running", "renew_run", "complete_run", "fail_run")}
        original_spawn = wake.spawn_agent
        runner_globals = module["_run_desktop_pass"].__globals__
        original_interval = runner_globals["RUN_LEASE_RENEW_INTERVAL"]
        try:
            client.register_runner = lambda **kw: calls.append(("register", kw)) or {"ok": True}
            client.reconcile_runs = lambda healthy, key: calls.append(("reconcile", healthy, key)) or {"ok": True}
            client.claim_run = lambda **kw: {"data": {"claim_token": "token", "run": {
                "id": 41, "conversation_id": 7, "task": "Do the work"}}}
            client.conversation_context = lambda *a, **kw: {"data": {"summary": "bounded"}}
            client.mark_run_running = lambda *a, **kw: calls.append(("running", a)) or {"ok": True}
            client.complete_run = lambda *a, **kw: calls.append(("complete", a, kw)) or {"ok": True}
            client.fail_run = lambda *a, **kw: calls.append(("fail", a, kw)) or {"ok": True}
            client.renew_run = lambda *a, **kw: calls.append(("renew", a, kw)) or {"ok": True}
            runner_globals["RUN_LEASE_RENEW_INTERVAL"] = 0.01
            def fake_spawn(prompt, **kw):
                calls.append(("spawn", prompt, kw))
                import time
                time.sleep(0.03)
                return True, None
            wake.spawn_agent = fake_spawn
            module["_run_desktop_pass"](workspace=self.tmp.name, wake_command=None,
                                         model="model", timeout=10, open_terminal=False)
        finally:
            for name, value in originals.items():
                setattr(client, name, value)
            wake.spawn_agent = original_spawn
            runner_globals["RUN_LEASE_RENEW_INTERVAL"] = original_interval
        names = [call[0] for call in calls]
        self.assertEqual(names[:4], ["register", "reconcile", "running", "spawn"])
        self.assertIn("renew", names)
        self.assertEqual(names[-2:], ["complete", "reconcile"])
        self.assertIn("Do the work", calls[3][1])

    def test_desktop_runner_contains_errors_and_fails_claim(self):
        module = runpy.run_path(str(Path(__file__).resolve().parent.parent / "harpp"))
        client = module["harpp_client"]
        calls = []
        originals = {name: getattr(client, name) for name in (
            "register_runner", "reconcile_runs", "claim_run", "conversation_context", "fail_run")}
        try:
            client.register_runner = lambda **kw: {"ok": True}
            client.reconcile_runs = lambda healthy, key: calls.append(("reconcile", healthy)) or {"ok": True}
            client.claim_run = lambda **kw: {"data": {"claim_token": "token", "run": {
                "id": 42, "conversation_id": 8}}}
            client.conversation_context = lambda *a, **kw: (_ for _ in ()).throw(RuntimeError("context down"))
            client.fail_run = lambda *a, **kw: calls.append(("fail", a, kw)) or {"ok": True}
            module["_run_desktop_pass"](workspace=self.tmp.name, wake_command=None,
                                         model="model", timeout=10, open_terminal=False)
        finally:
            for name, value in originals.items():
                setattr(client, name, value)
        self.assertEqual(calls[1][0], "fail")
        self.assertEqual(calls[-1][0], "reconcile")

    def test_autoprocess_gates_decisions_by_lifecycle(self):
        calls = []

        def fake_api(method, url, body=None, **kw):
            calls.append(url)
            return {"ok": True}

        original = harpp_client.api
        harpp_client.api = fake_api
        outcomes = []
        try:
            notes = harpp_client.autoprocess([
                {"kind": "decision", "id": 10, "lifecycle_state": "NOTIFIED"},
                {"kind": "decision", "id": 11, "lifecycle_state": "DECIDED"},
                {"kind": "decision", "id": 12, "lifecycle_state": "ACKNOWLEDGED"},
                {"kind": "decision", "id": 13, "lifecycle_state": "CLOSED"},
            ], outcomes=outcomes)
        finally:
            harpp_client.api = original

        self.assertFalse(any("/decisions/10/" in url for url in calls), calls)
        self.assertEqual(sum("/decisions/11/acknowledge" in url for url in calls), 1, calls)
        self.assertEqual(sum("/decisions/11/applied" in url for url in calls), 1, calls)
        self.assertFalse(any("/decisions/12/acknowledge" in url for url in calls), calls)
        self.assertEqual(sum("/decisions/12/applied" in url for url in calls), 1, calls)
        self.assertFalse(any("/decisions/13/" in url for url in calls), calls)
        self.assertIn("verify HARPP migrations and ADR creation", notes[0])
        self.assertEqual(notes[-1], "decision 13 already closed; no action")
        self.assertEqual([ok for _, ok in outcomes], [False, True, True, True])

    def test_config_load(self):
        cfg = harpp_client.load_config()
        self.assertEqual(cfg["base_url"], "https://harpp.example.com")
        self.assertEqual(cfg["tenant_id"], "7")
        self.assertEqual(cfg["harpp_authority"], "L2")
        self.assertEqual(cfg["authority_policy"]["L4"], "human_approval")

    def test_config_env_override(self):
        os.environ["HARPP_BASE_URL"] = "https://env.example.com"
        self.assertEqual(harpp_client.load_config()["base_url"], "https://env.example.com")

    def test_config_missing_raises(self):
        os.environ.pop("HARPP_CONFIG", None)
        with tempfile.TemporaryDirectory() as d:
            os.environ["HARPP_CONFIG"] = str(Path(d) / "nope.json")
            with self.assertRaises(harpp_client.HarppError):
                harpp_client.load_config()

    def test_dry_run_request(self):
        req = harpp_client.submit_decision(title="T", body="B", priority="high", decision_key="DK-1")
        self.assertTrue(req["dry_run"])
        self.assertEqual(req["method"], "POST")
        self.assertIn("/api/v1/harpp/bridge/decisions", req["url"])
        # The dry-run payload must redact the bridge key, never echo it.
        self.assertNotEqual(req["headers"]["X-HARPP-BRIDGE-KEY"], "k-test")
        self.assertNotIn("k-test", json.dumps(req))
        self.assertIn("redacted", req["headers"]["X-HARPP-BRIDGE-KEY"])
        self.assertEqual(req["headers"]["X-HARPP-TENANT-ID"], "7")
        self.assertEqual(req["body"]["title"], "T")
        self.assertEqual(req["body"]["priority"], "high")
        self.assertEqual(req["body"]["workbench_state"], "ARCHITECTURE_DECISION_REQUIRED")

    def test_rejects_http_base_url(self):
        with self.assertRaises(harpp_client.HarppError):
            harpp_client.api("GET", "/api/v1/harpp/bridge/decisions", config={"base_url": "http://x", "bridge_key": "k", "tenant_id": "1"})

    def test_dry_run_list_query(self):
        req = harpp_client.list_decisions(state="PENDING", limit=5)
        self.assertIn("state=PENDING", req["url"])
        self.assertIn("limit=5", req["url"])

    def test_dry_run_poll_messages_sends_cursor(self):
        # Regression: the bridge API keys owner-message polls by id>cursor. The
        # client must send `cursor` (not `after`); otherwise the watcher only ever
        # receives the oldest page and silently misses newer owner messages.
        req = harpp_client.poll_messages(after=473, limit=100)
        self.assertIn("cursor=473", req["url"])
        self.assertNotIn("after=", req["url"])
        self.assertIn("limit=100", req["url"])
        # A default poll must still send an explicit cursor=0 baseline page.
        req0 = harpp_client.poll_messages()
        self.assertIn("cursor=0", req0["url"])

    def test_dry_run_ack_apply(self):
        a = harpp_client.acknowledge_decision(3, rationale="ok")
        self.assertIn("/decisions/3/acknowledge", a["url"])
        ap = harpp_client.apply_decision(3)
        self.assertIn("/decisions/3/applied", ap["url"])

    def test_dry_run_renew_run_posts_claim_token(self):
        req = harpp_client.renew_run(12, "lease-token", lease_seconds=180)
        self.assertTrue(req["url"].endswith("/api/v1/harpp/bridge/runs/12/renew"))
        self.assertEqual(req["body"]["claim_token"], "lease-token")
        self.assertEqual(req["body"]["lease_seconds"], 180)

    def test_dry_run_cancel_run_posts_run_and_message_ids(self):
        req = harpp_client.cancel_run(run_id=12, message_id=34)
        self.assertTrue(req["url"].endswith("/api/v1/harpp/bridge/runs/cancel"))
        self.assertEqual(req["body"]["run_id"], 12)
        self.assertEqual(req["body"]["message_id"], 34)
        self.assertEqual(req["body"]["claim_token"], "")

    def test_dry_run_cancel_run_sends_claim_token(self):
        req = harpp_client.cancel_run(run_id=12, claim_token="tok-abc")
        self.assertEqual(req["body"]["run_id"], 12)
        self.assertEqual(req["body"]["claim_token"], "tok-abc")
        self.assertEqual(req["body"]["message_id"], 0)

    def test_dry_run_message_and_status(self):
        m = harpp_client.send_message(body="hi", conversation_id=9)
        self.assertEqual(m["body"]["conversation_id"], 9)
        s = harpp_client.post_status(message="running", workbench_state="IMPLEMENTING")
        self.assertEqual(s["body"]["workbench_state"], "IMPLEMENTING")

    def test_dry_run_report_daemon_status(self):
        req = harpp_client.report_daemon_status(
            runner_key="desktop-x", workflow_counts={"done": 1},
            recent_workflows=[{"id": "a", "title": "t", "status": "done", "updated_at": ""}])
        self.assertTrue(req["url"].endswith("/api/v1/harpp/bridge/status/report"))
        self.assertEqual(req["body"]["runner_key"], "desktop-x")
        self.assertEqual(req["body"]["workflow_counts"], {"done": 1})
        self.assertEqual(len(req["body"]["recent_workflows"]), 1)
        self.assertIn("daemon_version", req["body"])


class HarppCliDecisionLedgerTest(unittest.TestCase):
    def test_local_decision_record_and_list_cli(self):
        with tempfile.TemporaryDirectory() as d:
            env = os.environ.copy()
            env["XDG_CONFIG_HOME"] = d
            script = str(Path(__file__).resolve().parent.parent / "harpp")
            rec = subprocess.run(
                [sys.executable, script, "decision", "record", "--task", "workflow:x",
                 "--decision", "Use stdlib only", "--constraint", "Never print secrets",
                 "--applied-to", "stage:test"],
                check=True, text=True, stdout=subprocess.PIPE, env=env)
            listed = subprocess.run(
                [sys.executable, script, "decision", "list"],
                check=True, text=True, stdout=subprocess.PIPE, env=env)
            self.assertIn("DEC-0001", rec.stdout)
            self.assertIn("Use stdlib only", listed.stdout)
            self.assertIn("Never print secrets", listed.stdout)


class HarppMcpTest(unittest.TestCase):
    def _call(self, method, params=None, msg_id=1):
        msg = {"jsonrpc": "2.0", "id": msg_id, "method": method}
        if params is not None:
            msg["params"] = params
        return harpp_mcp.handle_message(msg)

    def test_initialize(self):
        resp = self._call("initialize", {"protocolVersion": "2024-11-05", "capabilities": {}, "clientInfo": {}})
        self.assertEqual(resp["result"]["serverInfo"]["name"], "harpp-bridge")
        self.assertIn("tools", resp["result"]["capabilities"])

    def test_initialized_notification_returns_none(self):
        self.assertIsNone(harpp_mcp.handle_message({"jsonrpc": "2.0", "method": "notifications/initialized"}))

    def test_tools_list(self):
        resp = self._call("tools/list")
        names = [t["name"] for t in resp["result"]["tools"]]
        self.assertIn("harpp_submit_decision", names)
        self.assertIn("harpp_poll_messages", names)
        for spine in ("harpp_get_run", "harpp_list_runners", "harpp_get_artifact_bundle", "harpp_get_decision", "harpp_memory_search", "harpp_approve_run", "harpp_reject_run"):
            self.assertIn(spine, names)
        self.assertEqual(len(names), 14)

    def test_unknown_tool(self):
        resp = self._call("tools/call", {"name": "nope", "arguments": {}})
        self.assertIn("error", resp)

    def test_call_missing_config_is_error(self):
        # No config in a temp env -> HarppError surfaced as isError result.
        with tempfile.TemporaryDirectory() as d:
            os.environ["HARPP_CONFIG"] = str(Path(d) / "nope.json")
            os.environ.pop("HARPP_BASE_URL", None)
            os.environ.pop("HARPP_BRIDGE_KEY", None)
            os.environ.pop("HARPP_TENANT_ID", None)
            resp = self._call("tools/call", {"name": "harpp_post_status", "arguments": {"message": "x"}})
            self.assertTrue(resp["result"]["isError"])


class HarppMcpSpineTest(unittest.TestCase):
    """S1 MCP spine: run/runner/artifact/decision read-only tools."""

    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.cfg_path = Path(self.tmp.name) / "config.json"
        self.cfg_path.write_text(json.dumps({
            "base_url": "https://harpp.example.com", "bridge_key": "k-spine", "tenant_id": "7",
        }))
        self._old = {k: os.environ.get(k) for k in (
            "HARPP_CONFIG", "HARPP_BASE_URL", "HARPP_BRIDGE_KEY", "HARPP_TENANT_ID", "HARPP_DRY_RUN")}
        os.environ["HARPP_CONFIG"] = str(self.cfg_path)
        os.environ["HARPP_DRY_RUN"] = "1"

    def tearDown(self):
        for k, v in self._old.items():
            if v is None:
                os.environ.pop(k, None)
            else:
                os.environ[k] = v
        self.tmp.cleanup()

    def test_tools_list_includes_spine_tools(self):
        resp = harpp_mcp.handle_message({"jsonrpc": "2.0", "id": 1, "method": "tools/list"})
        names = [t["name"] for t in resp["result"]["tools"]]
        for spine in ("harpp_get_run", "harpp_list_runners", "harpp_get_artifact_bundle", "harpp_get_decision", "harpp_memory_search", "harpp_approve_run", "harpp_reject_run"):
            self.assertIn(spine, names)
        self.assertEqual(len(names), 14)

    def test_client_functions_route_to_correct_urls_and_methods(self):
        calls = []

        def fake_api(method, url, body=None, **kw):
            calls.append((method, url))
            return {"ok": True}

        original = harpp_client.api
        harpp_client.api = fake_api
        try:
            harpp_client.run_status(7)
            harpp_client.list_runners()
            harpp_client.artifact_bundle_for_decision(9)
            harpp_client.get_decision(4)
            harpp_client.memory_search("cache invalidation", limit=3)
            harpp_client.approve_run(12, "tok-123", rationale="Looks good.")
            harpp_client.reject_run(13, rationale="Not approved.")
        finally:
            harpp_client.api = original
        self.assertEqual(calls, [
            ("GET", "/api/v1/harpp/bridge/runs/7"),
            ("GET", "/api/v1/harpp/bridge/runners"),
            ("GET", "/api/v1/harpp/bridge/artifacts/bundles/decision/9"),
            ("GET", "/api/v1/harpp/bridge/decisions/4"),
            ("GET", "/api/v1/harpp/bridge/memory/search?q=cache+invalidation&limit=3"),
            ("POST", "/api/v1/harpp/bridge/runs/12/approve"),
            ("POST", "/api/v1/harpp/bridge/runs/13/reject"),
        ])

    def _dispatch(self, name, args):
        calls = []

        def fake_api(method, url, body=None, **kw):
            calls.append((method, url))
            return {"ok": True}

        original = harpp_client.api
        harpp_client.api = fake_api
        try:
            resp = harpp_mcp.handle_message({
                "jsonrpc": "2.0", "id": 1, "method": "tools/call",
                "params": {"name": name, "arguments": args},
            })
        finally:
            harpp_client.api = original
        self.assertNotIn("error", resp, resp)
        self.assertTrue(resp["result"]["content"], resp)
        return calls

    def test_get_artifact_bundle_tool_dispatches(self):
        calls = self._dispatch("harpp_get_artifact_bundle", {"decision_id": 7})
        self.assertEqual(calls[0], ("GET", "/api/v1/harpp/bridge/artifacts/bundles/decision/7"))

    def test_get_run_tool_dispatches(self):
        calls = self._dispatch("harpp_get_run", {"run_id": 12})
        self.assertEqual(calls[0], ("GET", "/api/v1/harpp/bridge/runs/12"))

    def test_list_runners_tool_dispatches(self):
        calls = self._dispatch("harpp_list_runners", {})
        self.assertEqual(calls[0], ("GET", "/api/v1/harpp/bridge/runners"))

    def test_get_decision_tool_dispatches(self):
        calls = self._dispatch("harpp_get_decision", {"id": 3})
        self.assertEqual(calls[0], ("GET", "/api/v1/harpp/bridge/decisions/3"))

    def test_memory_search_tool_dispatches(self):
        calls = self._dispatch("harpp_memory_search", {"q": "approved layout", "limit": 4})
        self.assertEqual(calls[0], ("GET", "/api/v1/harpp/bridge/memory/search?q=approved+layout&limit=4"))

    def test_memory_search_sends_fail_closed_params(self):
        # Slice B: the client must forward include_historical + budget_limit so
        # the server's fail-closed retrieval params reach the bridge unchanged.
        calls = []

        def fake_api(method, url, body=None, **kw):
            calls.append((method, url))
            return {"ok": True}

        original = harpp_client.api
        harpp_client.api = fake_api
        try:
            harpp_client.memory_search("x", limit=3, include_historical=True, budget_limit=5000)
        finally:
            harpp_client.api = original
        method, url = calls[0]
        self.assertEqual(method, "GET")
        self.assertIn("memory/search", url)
        self.assertIn("q=x", url)
        self.assertIn("limit=3", url)
        self.assertIn("include_historical=True", url)
        self.assertIn("budget_limit=5000", url)

    def test_memory_search_tool_dispatches_with_fail_closed_params(self):
        # The MCP impl must thread include_historical + budget_limit through and
        # not fall back to "unknown tool" when they are supplied.
        calls = self._dispatch("harpp_memory_search", {
            "q": "cache", "limit": 3, "include_historical": True, "budget_limit": 5000})
        self.assertEqual(calls[0], ("GET", "/api/v1/harpp/bridge/memory/search?q=cache&limit=3&include_historical=True&budget_limit=5000"))

    def test_memory_search_tool_schema_exposes_fail_closed_params(self):
        resp = harpp_mcp.handle_message({"jsonrpc": "2.0", "id": 1, "method": "tools/list"})
        tools = {t["name"]: t for t in resp["result"]["tools"]}
        self.assertIn("harpp_memory_search", tools)
        props = tools["harpp_memory_search"]["inputSchema"]["properties"]
        self.assertIn("include_historical", props)
        self.assertIn("budget_limit", props)
        # No new tool added: still 14.
        self.assertEqual(len(tools), 14)

    def test_approve_run_tool_dispatches(self):
        calls = self._dispatch("harpp_approve_run", {"run_id": 12, "approval_token": "tok-123"})
        self.assertEqual(calls[0], ("POST", "/api/v1/harpp/bridge/runs/12/approve"))

    def test_reject_run_tool_dispatches(self):
        calls = self._dispatch("harpp_reject_run", {"run_id": 13, "rationale": "Not approved."})
        self.assertEqual(calls[0], ("POST", "/api/v1/harpp/bridge/runs/13/reject"))


class HarppContextCacheTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.cfg_path = Path(self.tmp.name) / "config.json"
        self.cfg_path.write_text(json.dumps({
            "base_url": "https://harpp.example.com", "bridge_key": "k-secret", "tenant_id": "7",
        }))
        self._old = {k: os.environ.get(k) for k in (
            "HARPP_CONFIG", "HARPP_CONTEXT_CACHE", "HARPP_BASE_URL",
            "HARPP_BRIDGE_KEY", "HARPP_TENANT_ID", "HARPP_DRY_RUN")}
        os.environ["HARPP_CONFIG"] = str(self.cfg_path)
        os.environ["HARPP_CONTEXT_CACHE"] = str(Path(self.tmp.name) / "ctx-cache")
        for k in ("HARPP_BASE_URL", "HARPP_BRIDGE_KEY", "HARPP_TENANT_ID", "HARPP_DRY_RUN"):
            os.environ.pop(k, None)

    def tearDown(self):
        for k, v in self._old.items():
            if v is None:
                os.environ.pop(k, None)
            else:
                os.environ[k] = v
        self.tmp.cleanup()

    def _envelope(self, version=3):
        return {
            "conversation": {"id": 2, "title": "Cache conv", "harness_session_id": "s1",
                             "version": version, "status": "active", "updated_at": "2026-01-01"},
            "messages": [{"id": 1, "conversation_id": 2, "aggregate_sequence": 1,
                          "sender_type": "owner", "sender_user_id": 1, "body": "hi",
                          "payload": {"secret": "x"}, "created_at": "2026-01-01"}],
            "runs": [],
            "cache": {"version": version, "message_limit": 20},
        }

    def _patch_api(self, calls, version=3):
        original = harpp_client.api

        def fake(method, url, body=None, **kw):
            calls.append(url)
            return {"ok": True, "data": self._envelope(version=version)}
        harpp_client.api = fake
        return original

    def test_cache_write_is_0600_and_excludes_secrets(self):
        harpp_client.store_cached_context("7", 2, self._envelope())
        path = harpp_client._context_cache_path("7", 2)
        self.assertTrue(path.is_file())
        self.assertEqual(oct(path.stat().st_mode & 0o777), "0o600")
        raw = json.loads(path.read_text(encoding="utf-8"))
        self.assertEqual(raw["_meta"]["schema_version"], harpp_client.CONTEXT_CACHE_SCHEMA_VERSION)
        self.assertEqual(raw["_meta"]["tenant_id"], 7)
        self.assertEqual(raw["_meta"]["conversation_id"], 2)
        # bridge credentials never enter the cache
        self.assertNotIn("k-secret", json.dumps(raw))
        self.assertNotIn("bridge_key", json.dumps(raw))
        # message payload blobs are stripped from the cached envelope
        self.assertNotIn("payload", raw["data"]["messages"][0])

    def test_cache_hit_avoids_api(self):
        calls = []
        original = self._patch_api(calls)
        try:
            first = harpp_client.context_for_conversation(2)
            second = harpp_client.context_for_conversation(2)
        finally:
            harpp_client.api = original
        self.assertEqual(len(calls), 1, calls)  # second read served from cache
        self.assertEqual(first["cache"]["version"], 3)
        self.assertEqual(second["cache"]["version"], 3)

    def test_version_invalidation_refetches(self):
        calls = []
        original = self._patch_api(calls, version=4)
        try:
            harpp_client.context_for_conversation(2)          # fetch + cache (version 4)
            harpp_client.context_for_conversation(2)          # cache hit
            harpp_client.context_for_conversation(2, expected_version=10)  # stale -> refetch
        finally:
            harpp_client.api = original
        self.assertEqual(len(calls), 2, calls)

    def test_corrupt_cache_refetches(self):
        path = harpp_client._context_cache_path("7", 2)
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text("{not valid json", encoding="utf-8")
        calls = []
        original = self._patch_api(calls)
        try:
            env = harpp_client.context_for_conversation(2)
        finally:
            harpp_client.api = original
        self.assertEqual(len(calls), 1)
        self.assertEqual(env["conversation"]["id"], 2)

    def test_wrong_tenant_cache_is_not_reused(self):
        # cached under tenant 7/conversation 2; reading as tenant 9 must not reuse
        # it and must refetch, proving no cross-tenant cache reuse.
        harpp_client.store_cached_context("7", 2, self._envelope())
        self.assertIsNone(harpp_client.get_cached_context("9", 2))
        # switch the active config to tenant 9
        cfg9 = Path(self.tmp.name) / "config9.json"
        cfg9.write_text(json.dumps({
            "base_url": "https://harpp.example.com", "bridge_key": "k9", "tenant_id": "9",
        }))
        os.environ["HARPP_CONFIG"] = str(cfg9)
        calls = []
        original = self._patch_api(calls)
        try:
            env = harpp_client.context_for_conversation(2)
        finally:
            harpp_client.api = original
        self.assertEqual(len(calls), 1, calls)
        self.assertEqual(env["conversation"]["id"], 2)

    def test_invalidate_deletes_cache(self):
        harpp_client.store_cached_context("7", 2, self._envelope())
        self.assertTrue(harpp_client.invalidate_context_cache("7", 2))
        self.assertFalse(harpp_client.invalidate_context_cache("7", 2))
        self.assertFalse(harpp_client._context_cache_path("7", 2).exists())

    def test_oversized_cache_refetches(self):
        path = harpp_client._context_cache_path("7", 2)
        path.parent.mkdir(parents=True, exist_ok=True)
        big = {"_meta": {"schema_version": harpp_client.CONTEXT_CACHE_SCHEMA_VERSION,
                         "tenant_id": 7, "conversation_id": 2, "saved_at": 0},
               "data": {"cache": {"version": 3}, "pad": "x" * (harpp_client.CONTEXT_CACHE_MAX_BYTES + 1)}}
        path.write_text(json.dumps(big), encoding="utf-8")
        calls = []
        original = self._patch_api(calls)
        try:
            env = harpp_client.context_for_conversation(2)
        finally:
            harpp_client.api = original
        self.assertEqual(len(calls), 1, calls)
        self.assertEqual(env["conversation"]["id"], 2)


if __name__ == "__main__":
    unittest.main(verbosity=2)
