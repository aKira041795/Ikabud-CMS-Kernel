#!/usr/bin/env python3
"""Chair-owned pre-dispatch criterion for the `harpp-chat-escalations` lane.

The owner's decision (2026-10-04, given in chat because it is director scope):

    "let's discard the decisions lane. harpp becomes better without it.
     as an away tool, linked to my workstation, all i want is a chat lane, aside from the
     deploy and workspaces"

The wake workflow reaches the owner through `harpp_client.harpp_notify()`. That function
ALREADY sends the chat message (harpp_client.py:561-566) and THEN, for an actionable
message type, additionally calls `submit_decision()` (harpp_client.py:567-582).

So the chat lane is already the transport; the decision row is a second, duplicate record
on top of it. This probe asserts the owner's outcome:

    an escalation reaches the owner as a CHAT MESSAGE, and creates NO decision.

exit 0 = message sent, no decision submitted   -> the target
exit 1 = a decision was still submitted        -> the base, measured 2026-10-04
exit 2 = the message was NOT sent              -> the change broke the away channel
exit 3 = the probe captured nothing            -> the probe is broken, not the code

exit 2 and 3 exist so the probe cannot pass vacuously, and cannot pass by silencing the
only channel that reaches the owner away from the workstation.

MUST-ALLOW CONTROL (run by the chair, not by the lane):
    python3 tools/harpp-escalation-probe.py INFO
A non-actionable message type skips the decision path entirely, so the probe MUST exit 0.
If it cannot reach exit 0, its red result on the real needle proves nothing.
"""
import pathlib
import sys

MESSAGE_TYPE = "BLOCKED"
for _arg in sys.argv[1:]:
    if not _arg.startswith("--"):
        MESSAGE_TYPE = _arg.upper()

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parents[1] / "tools" / "harpp-bridge"))

try:
    import harpp_client
except Exception as exc:  # noqa: BLE001
    print(f"PROBE BROKEN: cannot import harpp_client: {exc}", file=sys.stderr)
    sys.exit(3)

calls = []


def _fake_api(method, path, body=None, config=None, **kw):
    calls.append((method, path))
    return {}


harpp_client.api = _fake_api

try:
    harpp_client.harpp_notify(
        conversation_id=1,
        message_type=MESSAGE_TYPE,
        body="probe: escalation body",
        decision={"title": "probe", "what": "w", "why": "y"},
    )
except Exception as exc:  # noqa: BLE001
    print(f"PROBE BROKEN: harpp_notify raised: {type(exc).__name__}: {exc}", file=sys.stderr)
    sys.exit(3)

paths = [p for _m, p in calls]

if not calls:
    print("PROBE BROKEN: harpp_notify issued no request at all", file=sys.stderr)
    sys.exit(3)

if any("/decisions" in p for p in paths):
    print(f"still creates a decision -> {paths}", file=sys.stderr)
    sys.exit(1)

if not any("/messages" in p for p in paths):
    print(f"message no longer sent -> {paths}", file=sys.stderr)
    sys.exit(2)

print(f"OK: escalation sends a message only -> {paths}")
sys.exit(0)
