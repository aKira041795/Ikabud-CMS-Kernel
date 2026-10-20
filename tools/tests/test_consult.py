"""Regression tests for tools/harpp-bridge/consult.py.

Run:
    python3 -m unittest discover -s tools/tests -p 'test_consult.py'
"""

from __future__ import annotations

import json
import os
import shutil
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
TOOL = ROOT / "tools" / "harpp-bridge" / "consult.py"


class ConsultTest(unittest.TestCase):
    def setUp(self) -> None:
        self._tmp = tempfile.TemporaryDirectory()
        self.workspace = Path(self._tmp.name)
        self.env = dict(os.environ)
        self.env.pop("ANTHROPIC_API_KEY", None)
        self.env["CONSULT_CLAUDE_BACKEND"] = "cli"

    def tearDown(self) -> None:
        self._tmp.cleanup()

    def run_consult(self, *args: str, env: dict | None = None) -> subprocess.CompletedProcess[str]:
        return subprocess.run(
            [sys.executable, str(TOOL), "--workspace", str(self.workspace), *args],
            env=env or self.env,
            capture_output=True,
            text=True,
            timeout=30,
        )

    def cli_stub(self, reply: str = "STUB CLAUDE REPLY") -> str:
        stub = self.workspace / "claude-stub.sh"
        stub.write_text(
            "#!/usr/bin/env bash\ncat >/dev/null\nprintf '%s\\n' " + json.dumps(reply) + "\n",
            encoding="utf-8",
        )
        stub.chmod(0o755)
        return f"bash {stub}"

    def test_dry_run_chair_prompt(self) -> None:
        result = self.run_consult(
            "--provider", "claude", "--query", "should the harness add a second discussant?",
            "--dry-run", "--json", "--budget", "1000",
        )
        self.assertEqual(result.returncode, 0, result.stderr)
        payload = json.loads(result.stdout)
        self.assertTrue(payload["ok"])
        self.assertEqual(payload["provider"], "claude")
        self.assertIn("read-only architecture advisor", payload["prompt"])
        self.assertIn("# RETRIEVED REPOSITORY FACTS", payload["prompt"])
        self.assertTrue(Path(payload["turn"]).is_file())
        self.assertFalse(Path(payload["out"]).exists(), "dry-run must not append to the transcript")

    def test_red_team_requires_a_target(self) -> None:
        missing = self.run_consult(
            "--provider", "claude", "--mode", "red-team", "--query", "attack it",
            "--dry-run", "--json",
        )
        self.assertEqual(missing.returncode, 2)
        self.assertIn("red-team", missing.stdout)

        result = self.run_consult(
            "--provider", "claude", "--mode", "red-team", "--query", "attack it",
            "--target", "Make the second discussant mandatory for every patch.",
            "--dry-run", "--json", "--budget", "1000",
        )
        self.assertEqual(result.returncode, 0, result.stderr)
        prompt = json.loads(result.stdout)["prompt"]
        self.assertIn("# RED TEAM REVIEW", prompt)
        self.assertIn("## RECOMMENDATION UNDER REVIEW", prompt)
        self.assertIn("mandatory for every patch", prompt)

    def test_red_team_reads_last_reply_from_a_transcript(self) -> None:
        transcript = self.workspace / ".ai" / "consult" / "chair-prior.md"
        transcript.parent.mkdir(parents=True)
        transcript.write_text(
            "# CHAIR CONSULTATION\n\nYou are ChatGPT...\n\n# CHATGPT REPLY\nPRIOR CHAIR REPLY\n",
            encoding="utf-8",
        )
        result = self.run_consult(
            "--provider", "claude", "--mode", "red-team", "--query", "attack the prior reply",
            "--target-file", str(transcript), "--dry-run", "--json", "--budget", "1000",
        )
        self.assertEqual(result.returncode, 0, result.stderr)
        prompt = json.loads(result.stdout)["prompt"]
        self.assertIn("## RECOMMENDATION UNDER REVIEW\nPRIOR CHAIR REPLY", prompt)
        self.assertNotIn("## RECOMMENDATION UNDER REVIEW\n# CHAIR CONSULTATION", prompt)

    def test_compare_dry_run_writes_both_prompts(self) -> None:
        result = self.run_consult(
            "--compare", "--query", "compare the two plans",
            "--dry-run", "--json", "--budget", "1000",
        )
        self.assertEqual(result.returncode, 0, result.stderr)
        payload = json.loads(result.stdout)
        self.assertTrue(payload["ok"])
        self.assertEqual(set(payload["providers"]), {"chatgpt", "claude"})
        self.assertTrue(Path(payload["out"]).is_file())
        for provider in ("chatgpt", "claude"):
            self.assertIn("# CHAIR CONSULTATION", payload["providers"][provider]["prompt"])
            self.assertTrue(Path(payload["providers"][provider]["turn"]).is_file())

    def test_claude_cli_stub_saves_reply(self) -> None:
        env = dict(self.env, CONSULT_CLAUDE_CMD=self.cli_stub("STUB CLAUDE REPLY"))
        result = self.run_consult(
            "--provider", "claude", "--query", "stub transport",
            "--json", "--budget", "1000", env=env,
        )
        self.assertEqual(result.returncode, 0, result.stderr)
        payload = json.loads(result.stdout)
        self.assertTrue(payload["ok"])
        self.assertEqual(payload["reply"], "STUB CLAUDE REPLY")
        transcript = Path(payload["out"]).read_text(encoding="utf-8")
        self.assertIn("# CLAUDE REPLY", transcript)
        self.assertIn("STUB CLAUDE REPLY", transcript)

    def test_claude_api_without_key_fails_clearly(self) -> None:
        env = dict(self.env, CONSULT_CLAUDE_BACKEND="api")
        result = self.run_consult(
            "--provider", "claude", "--query", "api transport", "--json", env=env,
        )
        self.assertEqual(result.returncode, 1)
        payload = json.loads(result.stdout)
        self.assertFalse(payload["ok"])
        self.assertIn("ANTHROPIC_API_KEY", payload["error"])

    def test_claude_api_transport(self) -> None:
        import http.server
        import threading

        captured: dict[str, object] = {}

        class Handler(http.server.BaseHTTPRequestHandler):
            def do_POST(self) -> None:  # noqa: N802 - stdlib callback name
                length = int(self.headers.get("Content-Length", "0"))
                captured["body"] = json.loads(self.rfile.read(length))
                captured["headers"] = {key.lower(): value for key, value in self.headers.items()}
                payload = json.dumps({"content": [{"type": "text", "text": "API REPLY"}]}).encode()
                self.send_response(200)
                self.send_header("Content-Type", "application/json")
                self.send_header("Content-Length", str(len(payload)))
                self.end_headers()
                self.wfile.write(payload)

            def log_message(self, *_args: object) -> None:
                return

        server = http.server.ThreadingHTTPServer(("127.0.0.1", 0), Handler)
        thread = threading.Thread(target=server.serve_forever, daemon=True)
        thread.start()
        try:
            port = server.server_address[1]
            env = dict(
                self.env,
                ANTHROPIC_API_KEY="test-key",
                CONSULT_CLAUDE_BACKEND="api",
                CONSULT_CLAUDE_MODEL="test-model",
                CONSULT_ANTHROPIC_URL=f"http://127.0.0.1:{port}/v1/messages",
                NO_PROXY="127.0.0.1,localhost",
            )
            for key in ("HTTP_PROXY", "HTTPS_PROXY", "http_proxy", "https_proxy"):
                env.pop(key, None)
            result = self.run_consult(
                "--provider", "claude", "--query", "api transport",
                "--json", "--budget", "1000", env=env,
            )
            self.assertEqual(result.returncode, 0, result.stderr)
            payload = json.loads(result.stdout)
            self.assertEqual(payload["reply"], "API REPLY")
            body = captured.get("body") or {}
            self.assertEqual(body.get("model"), "test-model")
            self.assertEqual(body.get("messages", [{}])[0].get("role"), "user")
            headers = captured.get("headers") or {}
            self.assertEqual(headers.get("x-api-key"), "test-key")
            self.assertIn("anthropic-version", headers)
        finally:
            server.shutdown()
            server.server_close()

    def test_chatgpt_transport_with_stub_adapter(self) -> None:
        if shutil.which("node") is None:
            self.skipTest("node is not installed")
        adapter = self.workspace / "chatgpt-stub.js"
        adapter.write_text(
            'console.log(JSON.stringify({ok: true, text: "STUB CHATGPT REPLY"}));\n',
            encoding="utf-8",
        )
        env = dict(self.env, CONSULT_CHATGPT_ADAPTER=str(adapter))
        result = self.run_consult(
            "--provider", "chatgpt", "--query", "chatgpt transport",
            "--json", "--budget", "1000", env=env,
        )
        self.assertEqual(result.returncode, 0, result.stderr)
        payload = json.loads(result.stdout)
        self.assertTrue(payload["ok"])
        self.assertEqual(payload["reply"], "STUB CHATGPT REPLY")
        transcript = Path(payload["out"]).read_text(encoding="utf-8")
        self.assertIn("# CHATGPT REPLY", transcript)
        self.assertIn("STUB CHATGPT REPLY", transcript)

    def test_compare_end_to_end_with_stubs(self) -> None:
        if shutil.which("node") is None:
            self.skipTest("node is not installed")
        adapter = self.workspace / "chatgpt-stub.js"
        adapter.write_text(
            'console.log(JSON.stringify({ok: true, text: "CHATGPT STUB REPLY"}));\n',
            encoding="utf-8",
        )
        env = dict(
            self.env,
            CONSULT_CHATGPT_ADAPTER=str(adapter),
            CONSULT_CLAUDE_CMD=self.cli_stub("CLAUDE STUB REPLY"),
        )
        result = self.run_consult(
            "--compare", "--query", "compare the stubs",
            "--json", "--budget", "1000", env=env,
        )
        self.assertEqual(result.returncode, 0, result.stderr)
        payload = json.loads(result.stdout)
        self.assertTrue(payload["ok"])
        self.assertEqual(payload["providers"]["chatgpt"]["reply"], "CHATGPT STUB REPLY")
        self.assertEqual(payload["providers"]["claude"]["reply"], "CLAUDE STUB REPLY")
        comparison = Path(payload["out"]).read_text(encoding="utf-8")
        self.assertIn("CHATGPT STUB REPLY", comparison)
        self.assertIn("CLAUDE STUB REPLY", comparison)

    def test_compare_session_appends_turns(self) -> None:
        if shutil.which("node") is None:
            self.skipTest("node is not installed")
        adapter = self.workspace / "chatgpt-stub.js"
        adapter.write_text(
            'console.log(JSON.stringify({ok: true, text: "CHATGPT STUB REPLY"}));\n',
            encoding="utf-8",
        )
        env = dict(
            self.env,
            CONSULT_CHATGPT_ADAPTER=str(adapter),
            CONSULT_CLAUDE_CMD=self.cli_stub("CLAUDE STUB REPLY"),
        )
        first = self.run_consult(
            "--compare", "--session", "compare-session", "--query", "first compare",
            "--json", "--budget", "1000", env=env,
        )
        self.assertEqual(first.returncode, 0, first.stderr)
        second = self.run_consult(
            "--compare", "--session", "compare-session", "--query", "second compare",
            "--json", "--budget", "1000", env=env,
        )
        self.assertEqual(second.returncode, 0, second.stderr)
        payload = json.loads(second.stdout)
        self.assertIn("## SO FAR", payload["providers"]["chatgpt"]["prompt"])
        self.assertIn("first compare", payload["providers"]["chatgpt"]["prompt"])
        chatgpt_transcript = Path(payload["providers"]["chatgpt"]["out"]).read_text(encoding="utf-8")
        claude_transcript = Path(payload["providers"]["claude"]["out"]).read_text(encoding="utf-8")
        self.assertEqual(chatgpt_transcript.count("# CHATGPT REPLY"), 2)
        self.assertEqual(claude_transcript.count("# CLAUDE REPLY"), 2)

    def test_claude_page_transport_and_limit(self) -> None:
        if shutil.which("node") is None:
            self.skipTest("node is not installed")
        adapter = self.workspace / "claude-page-stub.js"
        adapter.write_text(
            'console.log(JSON.stringify({ok: true, text: "CLAUDE PAGE STUB"}));\n',
            encoding="utf-8",
        )
        env = dict(self.env, CONSULT_CLAUDE_BACKEND="page", CONSULT_CLAUDE_PAGE_ADAPTER=str(adapter))
        result = self.run_consult(
            "--provider", "claude", "--query", "page transport",
            "--json", "--budget", "1000", env=env,
        )
        self.assertEqual(result.returncode, 0, result.stderr)
        payload = json.loads(result.stdout)
        self.assertEqual(payload["reply"], "CLAUDE PAGE STUB")
        self.assertIn("# CLAUDE REPLY", Path(payload["out"]).read_text(encoding="utf-8"))

        adapter.write_text(
            'console.log(JSON.stringify({ok: false, error: "usage limit reached", limit: true}));\n',
            encoding="utf-8",
        )
        limited = self.run_consult(
            "--provider", "claude", "--query", "page limit",
            "--json", "--budget", "1000", env=env,
        )
        self.assertEqual(limited.returncode, 1)
        limited_payload = json.loads(limited.stdout)
        self.assertIn("usage limit", limited_payload["error"])
        self.assertIn("free account", limited_payload["error"])

    def test_session_history_is_clean_and_does_not_nest(self) -> None:
        env = dict(self.env, CONSULT_CLAUDE_CMD=self.cli_stub())
        first = self.run_consult(
            "--provider", "claude", "--session", "clean-session",
            "--query", "first question", "--json", "--budget", "1000", env=env,
        )
        self.assertEqual(first.returncode, 0, first.stderr)
        second = self.run_consult(
            "--provider", "claude", "--session", "clean-session",
            "--query", "second question", "--json", "--budget", "1000", env=env,
        )
        self.assertEqual(second.returncode, 0, second.stderr)
        payload = json.loads(second.stdout)
        prompt = payload["prompt"]
        self.assertIn("## SO FAR", prompt)
        self.assertIn("### TURN 1", prompt)
        self.assertIn("first question", prompt)
        self.assertEqual(prompt.count("# CHAIR CONSULTATION"), 1)
        transcript = Path(payload["out"]).read_text(encoding="utf-8")
        self.assertEqual(transcript.count("# CLAUDE REPLY"), 2)


if __name__ == "__main__":
    unittest.main()
