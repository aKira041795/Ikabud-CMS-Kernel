#!/usr/bin/env python3
"""Local tests for the HARPP bridge client + MCP server (no network).

Run:  python3 -m unittest tools.harpp-bridge.tests.test_harpp_bridge
  or:  harpp self-test
"""
import json
import os
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
                {"kind": "decision", "id": 9, "decision": "Option A"},
            ])
        finally:
            harpp_client.api = original
        urls = [c[1] for c in calls]
        self.assertEqual(len(calls), 3, notes)
        self.assertTrue(any(u.endswith("/bridge/messages") for u in urls), urls)
        self.assertTrue(any(u.endswith("/acknowledge") for u in urls), urls)
        self.assertTrue(any(u.endswith("/applied") for u in urls), urls)
        self.assertEqual(notes[0], "message 5 ack ok=True", notes)
        self.assertEqual(notes[1], "decision 9 ack=True apply=True", notes)

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

    def test_dry_run_ack_apply(self):
        a = harpp_client.acknowledge_decision(3, rationale="ok")
        self.assertIn("/decisions/3/acknowledge", a["url"])
        ap = harpp_client.apply_decision(3)
        self.assertIn("/decisions/3/applied", ap["url"])

    def test_dry_run_message_and_status(self):
        m = harpp_client.send_message(body="hi", conversation_id=9)
        self.assertEqual(m["body"]["conversation_id"], 9)
        s = harpp_client.post_status(message="running", workbench_state="IMPLEMENTING")
        self.assertEqual(s["body"]["workbench_state"], "IMPLEMENTING")


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
        self.assertEqual(len(names), 7)

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


if __name__ == "__main__":
    unittest.main(verbosity=2)
