#!/usr/bin/env python3
"""HARPP bridge MCP server (zero-dependency, stdlib only).

Exposes the HARPP harness bridge as MCP tools over stdio (newline-delimited
JSON-RPC), so any MCP client (VS Code Copilot Chat, Pi, Claude, etc.) can call:

  harpp_submit_decision      - raise a decision-request to the owner
  harpp_list_decisions       - poll pending/any decisions (state/workbench filter)
  harpp_acknowledge_decision - harness received the owner decision
  harpp_apply_decision       - harness applied the decision (closes it)
  harpp_send_message         - send a message into a HARPP conversation
  harpp_poll_messages        - poll for new owner messages (cursor)
  harpp_post_status          - post a harness status/heartbeat update

Run:  python3 tools/harpp-bridge/harpp_mcp.py
Config: see harpp_client.load_config (env or ~/.config/harpp/config.json).
Diagnostics go to stderr; stdout is reserved for the MCP protocol.
"""
import json
import sys

from harpp_client import (
    HarppError,
    acknowledge_decision,
    apply_decision,
    list_decisions,
    load_config,
    poll_messages,
    post_status,
    send_message,
    submit_decision,
)

SERVER_NAME = "harpp-bridge"
SERVER_VERSION = "1.0.0"
PROTOCOL_VERSION = "2024-11-05"

TOOLS = [
    {
        "name": "harpp_submit_decision",
        "description": "Raise a decision-request to the HARPP owner (creates a decision + notification + push). "
                       "Use when the harness needs a human decision, e.g. BLOCKED/ARCHITECTURE_DECISION_REQUIRED.",
        "inputSchema": {
            "type": "object",
            "properties": {
                "title": {"type": "string", "description": "Short decision title"},
                "body": {"type": "string", "description": "Full request body"},
                "context": {"type": "string", "description": "Optional context/situation"},
                "requested_decision": {"type": "string", "description": "What decision is requested"},
                "priority": {"type": "string", "enum": ["low", "normal", "high", "critical"], "default": "normal"},
                "source": {"type": "string", "default": "harness"},
                "workbench_state": {"type": "string", "default": "ARCHITECTURE_DECISION_REQUIRED"},
                "decision_key": {"type": "string", "description": "Optional idempotency key"},
            },
            "required": ["title", "body"],
        },
    },
    {
        "name": "harpp_list_decisions",
        "description": "List decisions (e.g. poll for owner decisions).",
        "inputSchema": {
            "type": "object",
            "properties": {
                "state": {"type": "string", "description": "Filter by lifecycle state, e.g. PENDING, DECIDED"},
                "priority": {"type": "string", "enum": ["low", "normal", "high", "critical"]},
                "workbench_state": {"type": "string", "description": "e.g. ARCHITECTURE_DECISION_REQUIRED"},
                "limit": {"type": "integer", "description": "1..100, default 25"},
            },
        },
    },
    {
        "name": "harpp_acknowledge_decision",
        "description": "Mark a decision as acknowledged by the harness (after reading the owner's decision).",
        "inputSchema": {
            "type": "object",
            "properties": {
                "id": {"type": "integer", "description": "Decision id"},
                "rationale": {"type": "string"},
            },
            "required": ["id"],
        },
    },
    {
        "name": "harpp_apply_decision",
        "description": "Report that the harness applied the decision (ACKNOWLEDGED -> APPLIED -> CLOSED).",
        "inputSchema": {
            "type": "object",
            "properties": {
                "id": {"type": "integer", "description": "Decision id"},
                "rationale": {"type": "string"},
            },
            "required": ["id"],
        },
    },
    {
        "name": "harpp_send_message",
        "description": "Send a message into a HARPP conversation (owner sees it in the HARPP messenger).",
        "inputSchema": {
            "type": "object",
            "properties": {
                "body": {"type": "string", "description": "Message text"},
                "conversation_id": {"type": "integer", "description": "Existing conversation id (optional; auto-creates)"},
                "title": {"type": "string", "description": "Conversation title when auto-creating"},
                "harness_session_id": {"type": "string"},
            },
            "required": ["body"],
        },
    },
    {
        "name": "harpp_poll_messages",
        "description": "Poll for new owner messages since a cursor (id).",
        "inputSchema": {
            "type": "object",
            "properties": {
                "conversation_id": {"type": "integer"},
                "after": {"type": "integer", "description": "Message id cursor (default 0)"},
                "limit": {"type": "integer", "default": 25},
            },
        },
    },
    {
        "name": "harpp_post_status",
        "description": "Post a harness status/heartbeat update (creates a notification for the owner).",
        "inputSchema": {
            "type": "object",
            "properties": {
                "message": {"type": "string", "description": "Status message"},
                "status": {"type": "string", "description": "e.g. running, blocked, done"},
                "workbench_state": {"type": "string"},
                "harness_session_id": {"type": "string"},
            },
            "required": ["message"],
        },
    },
]

TOOL_IMPLS = {
    "harpp_submit_decision": lambda c, a: submit_decision(config=c, **a),
    "harpp_list_decisions": lambda c, a: list_decisions(config=c, **a),
    "harpp_acknowledge_decision": lambda c, a: acknowledge_decision(a["id"], config=c, rationale=a.get("rationale", "")),
    "harpp_apply_decision": lambda c, a: apply_decision(a["id"], config=c, rationale=a.get("rationale", "")),
    "harpp_send_message": lambda c, a: send_message(config=c, **a),
    "harpp_poll_messages": lambda c, a: poll_messages(config=c, **a),
    "harpp_post_status": lambda c, a: post_status(config=c, **a),
}


def _text(content, is_error=False):
    return {
        "content": [{"type": "text", "text": json.dumps(content, indent=2) if not isinstance(content, str) else content}],
        "isError": is_error,
    }


def handle_message(message):
    """Handle one JSON-RPC message. Returns a response dict or None for notifications."""
    if not isinstance(message, dict):
        return None
    method = message.get("method")
    msg_id = message.get("id")
    params = message.get("params") or {}
    if method == "initialize":
        return {
            "jsonrpc": "2.0", "id": msg_id,
            "result": {
                "protocolVersion": PROTOCOL_VERSION,
                "capabilities": {"tools": {}},
                "serverInfo": {"name": SERVER_NAME, "version": SERVER_VERSION},
            },
        }
    if method in ("notifications/initialized", "initialized"):
        return None  # notification
    if method == "ping":
        return {"jsonrpc": "2.0", "id": msg_id, "result": {}}
    if method == "tools/list":
        return {"jsonrpc": "2.0", "id": msg_id, "result": {"tools": TOOLS}}
    if method == "tools/call":
        name = params.get("name")
        args = params.get("arguments") or {}
        if name not in TOOL_IMPLS:
            return {"jsonrpc": "2.0", "id": msg_id, "error": {"code": -32602, "message": f"unknown tool: {name}"}}
        try:
            result = TOOL_IMPLS[name](load_config(), args)
            return {"jsonrpc": "2.0", "id": msg_id, "result": _text(result)}
        except HarppError as exc:
            return {"jsonrpc": "2.0", "id": msg_id, "result": _text(
                {"error": str(exc), "status": exc.status, "payload": exc.payload}, is_error=True)}
        except Exception as exc:  # noqa: BLE001
            return {"jsonrpc": "2.0", "id": msg_id, "result": _text({"error": f"{type(exc).__name__}: {exc}"}, is_error=True)}
    return {"jsonrpc": "2.0", "id": msg_id, "error": {"code": -32601, "message": f"method not found: {method}"}}


def main():
    for line in sys.stdin:
        line = line.strip()
        if not line:
            continue
        try:
            message = json.loads(line)
        except Exception as exc:  # noqa: BLE001
            sys.stderr.write(f"bad json: {exc}\n")
            sys.stderr.flush()
            continue
        response = handle_message(message)
        if response is not None:
            sys.stdout.write(json.dumps(response) + "\n")
            sys.stdout.flush()


if __name__ == "__main__":
    main()
