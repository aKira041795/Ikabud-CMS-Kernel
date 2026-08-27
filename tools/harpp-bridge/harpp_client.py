#!/usr/bin/env python3
"""HARPP bridge client core (stdlib only).

Talks to the HARPP module's harness bridge API over HTTPS.

Config resolution (highest wins):
  1. env: HARPP_BASE_URL, HARPP_BRIDGE_KEY, HARPP_TENANT_ID
  2. config file: $HARPP_CONFIG or ~/.config/harpp/config.json

  {"base_url": "https://yourdomain.com", "bridge_key": "...", "tenant_id": 1}

Set HARPP_DRY_RUN=1 to print the request instead of sending.
Set HARPP_INSECURE=1 only for local dev against self-signed HTTPS.
"""
import json
import os
import ssl
import time
import urllib.error
import urllib.request
from pathlib import Path

DEFAULT_CONFIG_PATH = Path("~/.config/harpp/config.json").expanduser()
DEFAULT_WORKFLOW_BUDGETS = {
    "max_total_cycles": 8,
    "max_repairs": 3,
    "max_browser_repairs": 2,
    "max_tool_retries": 2,
    "max_network_retries": 2,
}
DEFAULT_HARPP_AUTHORITY = "L2"
DEFAULT_AUTHORITY_POLICY = {
    "L0": "autonomous",
    "L1": "autonomous",
    "L2": "autonomous",
    "L3": "autonomous",
    "L4": "human_approval",
}
OWNER_MESSAGE_TYPES = {
    "INFO", "PROGRESS", "WARNING", "DECISION_REQUIRED", "BLOCKED", "RELEASE_READY", "FAILED",
}


def _notify_enabled():
    """Owner-facing bridge notifications (messages + decisions) are on by default.

    In testing/quiet mode they are suppressed so test runs never pollute the
    live HARPP with real messages/decisions (the wf-* escalation tests previously
    created live "Escalation required" decisions on the host). Disable via env
    HARPP_NOTIFY=0 / HARPP_TESTING_MODE=1 or config keys harpp_notify:false /
    harpp_testing_mode:true. Suppressed calls return {"ok": True, "suppressed": True}.
    """
    env_notify = os.environ.get("HARPP_NOTIFY", "").strip().lower()
    if env_notify in ("0", "false", "no", "off"):
        return False
    env_testing = os.environ.get("HARPP_TESTING_MODE", "").strip().lower()
    if env_testing in ("1", "true", "yes"):
        return False
    try:
        cfg = governance_config()
    except Exception:  # noqa: BLE001
        cfg = {}
    notify = cfg.get("harpp_notify")
    if isinstance(notify, bool):
        if not notify:
            return False
    elif str(notify or "").strip().lower() in ("0", "false", "no", "off"):
        return False
    if str(cfg.get("harpp_testing_mode", "")).strip().lower() in ("1", "true", "yes"):
        return False
    return True
ACTIONABLE_MESSAGE_TYPES = {"DECISION_REQUIRED", "BLOCKED", "RELEASE_READY"}


class HarppError(RuntimeError):
    """Bridge request failed; carries HTTP status + parsed payload when available."""

    def __init__(self, message, status=None, payload=None):
        super().__init__(message)
        self.status = status
        self.payload = payload


def config_path():
    raw = os.environ.get("HARPP_CONFIG", "")
    return Path(raw).expanduser() if raw else DEFAULT_CONFIG_PATH


def delivery_receipts_path():
    raw = os.environ.get("HARPP_DELIVERY_RECEIPTS", "")
    return Path(raw).expanduser() if raw else config_path().parent / "delivery-receipts.jsonl"


def _record_delivery_receipt(response: dict, request: dict) -> None:
    """Durably record a successful bridge response without storing message bodies."""
    if not response.get("ok") or response.get("suppressed") or response.get("dry_run"):
        return
    key = request.get("idempotency_key")
    if not key:
        return
    record = {
        "idempotency_key": str(key),
        "conversation_id": int(request.get("conversation_id") or 0),
        "recorded_at": int(time.time()),
    }
    data = response.get("data")
    if isinstance(data, dict) and data.get("message_id") is not None:
        record["message_id"] = data.get("message_id")
    try:
        path = delivery_receipts_path()
        path.parent.mkdir(parents=True, exist_ok=True)
        with path.open("a", encoding="utf-8") as stream:
            stream.write(json.dumps(record, separators=(",", ":")) + "\n")
            stream.flush()
            os.fsync(stream.fileno())
    except OSError as exc:
        # A receipt write must never crash the CLI: the message is already
        # delivered and the server-side idempotency key dedups retries, while the
        # wake daemon's receipt cross-check independently refuses to mark the
        # reply delivered when the receipt is absent.
        print(f"harpp: delivery receipt not persisted: {exc}", flush=True)


def governance_config(config=None):
    cfg = dict(config or {})
    if not cfg:
        p = config_path()
        if p.is_file():
            try:
                cfg = json.loads(p.read_text(encoding="utf-8"))
            except Exception as exc:  # noqa: BLE001
                raise HarppError(f"config file {p} is invalid JSON: {exc}") from exc
    if os.environ.get("HARPP_AUTHORITY"):
        cfg["harpp_authority"] = os.environ["HARPP_AUTHORITY"]
    authority = str(cfg.get("harpp_authority") or DEFAULT_HARPP_AUTHORITY).strip().upper() or DEFAULT_HARPP_AUTHORITY
    cfg["harpp_authority"] = authority
    policy = cfg.get("authority_policy")
    if not isinstance(policy, dict):
        policy = {}
    merged = dict(DEFAULT_AUTHORITY_POLICY)
    merged.update({str(k).upper(): str(v) for k, v in policy.items()})
    cfg["authority_policy"] = merged
    return cfg


def load_config():
    cfg = governance_config()
    for key, env in (
        ("base_url", "HARPP_BASE_URL"),
        ("bridge_key", "HARPP_BRIDGE_KEY"),
        ("tenant_id", "HARPP_TENANT_ID"),
    ):
        if os.environ.get(env):
            cfg[key] = os.environ[env]
    missing = [k for k in ("base_url", "bridge_key", "tenant_id") if not str(cfg.get(k, "")).strip()]
    if missing:
        raise HarppError(
            "HARPP not configured: missing " + ", ".join(missing)
            + ". Run: harpp config set <key> <value>  (base_url, bridge_key, tenant_id)"
        )
    cfg["base_url"] = str(cfg["base_url"]).rstrip("/")
    if not cfg["base_url"].startswith("https://"):
        raise HarppError(
            "HARPP base_url must use https://; refusing to transmit the bridge key over an insecure transport."
        )
    cfg["tenant_id"] = str(cfg["tenant_id"])
    return cfg


def save_config(values):
    p = config_path()
    p.parent.mkdir(parents=True, exist_ok=True)
    cfg = {}
    if p.is_file():
        try:
            cfg = json.loads(p.read_text(encoding="utf-8"))
        except Exception:  # noqa: BLE001
            cfg = {}
    cfg.update(values)
    p.write_text(json.dumps(cfg, indent=2) + "\n", encoding="utf-8")
    os.chmod(p, 0o600)
    return p


def workspace_path(config=None):
    """Absolute path of the active workspace the harness should target, or None.

    Set with: harpp config set workspace /path/to/repo
    The wake agent and job launcher run with this as their working directory so
    file edits land in the currently active workspace regardless of where the
    daemon was started.
    """
    try:
        cfg = config or load_config()
    except HarppError:
        cfg = {}
    raw = str((cfg or {}).get("workspace", "") or "").strip()
    if not raw:
        return None
    p = Path(raw).expanduser()
    return str(p) if p.is_absolute() else None


def _query(params):
    from urllib.parse import urlencode
    # `cursor`/`after` are kept even when 0 so the harness always gets an explicit
    # baseline page from the server (matches the bridge API's id>cursor contract).
    q = {k: str(v) for k, v in params.items() if v not in (None, "", 0) or k in ("after", "cursor")}
    return ("?" + urlencode(q)) if q else ""


def api(method, path, body=None, config=None, timeout=30, dry_run=None):
    config = config or load_config()
    dry = (os.environ.get("HARPP_DRY_RUN") == "1") if dry_run is None else dry_run
    url = config["base_url"] + path
    if not config["base_url"].startswith("https://"):
        raise HarppError(
            "HARPP base_url must use https://; refusing to transmit the bridge key over an insecure transport."
        )
    data = json.dumps(body).encode("utf-8") if body is not None else None
    headers = {
        "X-HARPP-BRIDGE-KEY": config["bridge_key"],
        "X-HARPP-TENANT-ID": config["tenant_id"],
        "Accept": "application/json",
        "User-Agent": "harpp-bridge-client/1.0",
    }
    if data is not None:
        headers["Content-Type"] = "application/json"
    if dry:
        redacted = dict(headers)
        if "X-HARPP-BRIDGE-KEY" in redacted:
            redacted["X-HARPP-BRIDGE-KEY"] = "harpp_br_***redacted***"
        print(json.dumps({"dry_run": True, "method": method, "url": url, "headers": redacted, "body": body}, indent=2))
        return {"dry_run": True, "url": url, "method": method, "headers": redacted, "body": body}
    req = urllib.request.Request(url, data=data, headers=headers, method=method)
    ctx = ssl.create_default_context()
    if os.environ.get("HARPP_INSECURE") == "1":
        ctx.check_hostname = False
        ctx.verify_mode = ssl.CERT_NONE
    try:
        with urllib.request.urlopen(req, timeout=timeout, context=ctx) as resp:
            raw = resp.read().decode("utf-8", "replace")
            return json.loads(raw) if raw.strip() else {}
    except urllib.error.HTTPError as exc:
        raw = exc.read().decode("utf-8", "replace") if exc.fp else ""
        try:
            payload = json.loads(raw) if raw else None
        except Exception:  # noqa: BLE001
            payload = raw
        raise HarppError(f"HARPP bridge error {exc.code}: {exc.reason}", status=exc.code, payload=payload) from exc
    except urllib.error.URLError as exc:
        raise HarppError(f"cannot reach HARPP bridge: {exc.reason}") from exc


# ── Bridge operations ─────────────────────────────────────────────────────────

def _nl(value):
    """Convert literal '\\n' escapes (common from shell double-quoted args) to real newlines."""
    return value.replace("\\n", "\n") if isinstance(value, str) else value


def submit_decision(config=None, **kw):
    if not _notify_enabled():
        return {"ok": True, "suppressed": True, "reason": "testing/quiet mode"}
    body = {
        "title": kw.get("title", ""),
        "body": _nl(kw.get("body", "")),
        "context": _nl(kw.get("context", "")),
        "requested_decision": _nl(kw.get("requested_decision", "")),
        "priority": kw.get("priority", "normal"),
        "source": kw.get("source", "harness"),
        "workbench_state": kw.get("workbench_state", "ARCHITECTURE_DECISION_REQUIRED"),
    }
    if kw.get("decision_key"):
        body["decision_key"] = kw["decision_key"]
    return api("POST", "/api/v1/harpp/bridge/decisions", body, config=config)


def list_decisions(config=None, **kw):
    return api("GET", "/api/v1/harpp/bridge/decisions" + _query(
        {"state": kw.get("state"), "priority": kw.get("priority"),
         "workbench_state": kw.get("workbench_state"), "limit": kw.get("limit"),
         "cursor_created_at": kw.get("cursor_created_at"), "cursor_id": kw.get("cursor_id")}), config=config)


def view_decision(decision_id, config=None, rationale="Owner viewed the decision via messenger."):
    return api("POST", f"/api/v1/harpp/bridge/decisions/{int(decision_id)}/view",
               {"rationale": rationale}, config=config)


def record_decision(decision_id, decision, config=None, rationale="Owner decision recorded via harness."):
    """Record the owner's decision (DECIDED) on the owner's behalf via the bridge."""
    return api("POST", f"/api/v1/harpp/bridge/decisions/{int(decision_id)}/decide",
               {"decision": decision, "rationale": _nl(rationale)}, config=config)


def acknowledge_decision(decision_id, config=None, rationale="Harness acknowledged the owner decision."):
    return api("POST", f"/api/v1/harpp/bridge/decisions/{int(decision_id)}/acknowledge",
               {"rationale": rationale}, config=config)


def cancel_decision(decision_id, rationale="Owner cancelled the decision via harness.", config=None):
    return api("POST", f"/api/v1/harpp/bridge/decisions/{int(decision_id)}/cancel",
               {"rationale": rationale}, config=config)


def apply_decision(decision_id, config=None, rationale="Harness applied the owner decision."):
    return api("POST", f"/api/v1/harpp/bridge/decisions/{int(decision_id)}/applied",
               {"rationale": rationale}, config=config)


def autoprocess(records, outcomes=None):
    """Deterministically receipt messages and close decisions; optionally report success."""
    notes = []
    for rec in records or []:
        ok = False
        try:
            if rec.get("kind") == "message":
                conv = rec.get("conversation_id")
                if not conv:
                    raise HarppError("message has no conversation_id")
                # One idempotent "Received" confirmation per owner message. The
                # idempotency key makes a retry a no-op server-side, so the owner
                # gets exactly one confirmation (the earlier unbounded auto-reply
                # flood came from retrying without an idempotency key).
                r = harpp_notify(
                    body="✅ Received — the harness is working on it.",
                    conversation_id=int(conv), message_type="INFO",
                    idempotency_key=f"ack-{int(rec.get('id', 0))}")
                ok = bool(r.get("ok"))
                notes.append(f"message {rec.get('id')} ack ok={ok}")
            elif rec.get("kind") == "decision":
                did = int(rec.get("id"))
                state = str(rec.get("lifecycle_state") or "").upper()
                if state in ("CLOSED", "EXPIRED", "SUPERSEDED", "CANCELLED"):
                    ok = True
                    notes.append(f"decision {did} already {state.lower()}; no action")
                elif state not in ("DECIDED", "ACKNOWLEDGED", "APPLIED"):
                    raise HarppError(
                        f"decision {did} is {state or 'UNKNOWN'}, not eligible for apply; "
                        "verify HARPP migrations and ADR creation before retrying"
                    )
                else:
                    ack_ok = False
                    if state == "DECIDED":
                        a = acknowledge_decision(did, rationale="Harness auto-acknowledged the owner decision.")
                        ack_ok = bool(a.get("ok"))
                        if not ack_ok:
                            raise HarppError(f"decision {did} acknowledge failed; apply was not attempted")
                    else:
                        ack_ok = state in ("ACKNOWLEDGED", "APPLIED")
                    ap = apply_decision(did, rationale="Harness auto-applied the owner decision (standard watch behavior).")
                    ok = bool(ap.get("ok"))
                    notes.append(f"decision {did} ack={ack_ok} apply={ok}")
        except Exception as e:  # noqa: BLE001
            notes.append(f"{rec.get('kind')} {rec.get('id')} failed: {e}")
        if outcomes is not None:
            outcomes.append((rec, ok))
    return notes


def send_message(config=None, **kw):
    if not _notify_enabled():
        return {"ok": True, "suppressed": True, "reason": "testing/quiet mode"}
    conversation_id = kw.get("conversation_id")
    if not conversation_id:
        # Only the owner can start conversations; the harness must reply within an
        # existing conversation. Sending without one used to autocreate useless
        # conversations on the server (now rejected there as well).
        return {"ok": False,
                "error": "conversation_id is required; only the owner can start new conversations.",
                "status": 422, "code": "conversation_required"}
    body = {"body": _nl(kw.get("body", "")), "conversation_id": int(conversation_id)}
    if kw.get("title"):
        body["title"] = kw["title"]
    if kw.get("harness_session_id"):
        body["harness_session_id"] = kw["harness_session_id"]
    if kw.get("idempotency_key"):
        body["idempotency_key"] = str(kw["idempotency_key"])
    # Structured message type (INFO/PROGRESS/WARNING/BLOCKED/DECISION_REQUIRED/
    # RELEASE_READY/FAILED) so the server can gate push importance without
    # parsing the body prefix.
    message_type = str(kw.get("message_type") or "INFO").strip().upper()
    if message_type:
        body["message_type"] = message_type
    response = api("POST", "/api/v1/harpp/bridge/messages", body, config=config)
    _record_delivery_receipt(response, body)
    return response


def _prefix_message(message_type, body):
    kind = str(message_type or "INFO").strip().upper() or "INFO"
    if kind not in OWNER_MESSAGE_TYPES:
        raise ValueError(f"unsupported HARPP owner message type: {kind}")
    text = _nl(body or "")
    # Generic conversational replies are branded [HARPP] instead of [INFO];
    # semantic types (WARNING, BLOCKED, FAILED, ...) keep their own tag so the
    # owner can still triage by severity.
    display = "HARPP" if kind == "INFO" else kind
    prefix = f"[{display}]"
    stripped = text.lstrip()
    return text if stripped.startswith(prefix) else (prefix if not text else f"{prefix} {text}")


def _decision_lines(payload=None):
    payload = payload or {}
    options = payload.get("options") or []
    if isinstance(options, str):
        options = [options]
    lines = [
        f"what: {_nl(str(payload.get('what') or ''))}".rstrip(),
        f"why: {_nl(str(payload.get('why') or ''))}".rstrip(),
        "options:",
    ]
    if options:
        for opt in options:
            lines.append(f"- {_nl(str(opt))}".rstrip())
    else:
        lines.append("- owner direction required")
    lines.extend([
        f"recommendation: {_nl(str(payload.get('recommendation') or ''))}".rstrip(),
        f"risk: {_nl(str(payload.get('risk') or ''))}".rstrip(),
    ])
    return "\n".join(lines)


def harpp_notify(*, conversation_id, message_type, body, title=None, harness_session_id=None,
                 idempotency_key=None, decision=None, config=None):
    message_type = str(message_type or "INFO").strip().upper() or "INFO"
    response = send_message(config=config, conversation_id=conversation_id, title=title,
                             harness_session_id=harness_session_id,
                             idempotency_key=idempotency_key,
                             message_type=message_type,
                            body=_prefix_message(message_type, body))
    if message_type in ACTIONABLE_MESSAGE_TYPES:
        decision = dict(decision or {})
        if not decision.get("title"):
            raise ValueError(f"{message_type} notifications require decision metadata.title")
        decision_body = _decision_lines(decision)
        submit_decision(
            config=config,
            title=decision["title"],
            body=decision_body,
            context=_nl(decision.get("context", "")),
            requested_decision=_nl(decision.get("requested_decision", "")),
            priority=decision.get("priority", "high" if message_type != "RELEASE_READY" else "normal"),
            source=decision.get("source", "harness"),
            workbench_state=decision.get("workbench_state", message_type),
            decision_key=decision.get("decision_key", ""),
        )
    return response


def poll_messages(config=None, **kw):
    # The bridge API keys owner-message polls by `id > cursor` and caps each page
    # at `limit`. Sending the server's `cursor` param (not `after`) is required so
    # newer pages are returned instead of always the oldest N messages.
    return api("GET", "/api/v1/harpp/bridge/messages" + _query(
        {"conversation_id": kw.get("conversation_id"), "cursor": kw.get("after", 0),
         "limit": kw.get("limit")}), config=config)


def post_status(config=None, **kw):
    body = {"message": kw.get("message", "")}
    if kw.get("status"):
        body["status"] = kw["status"]
    if kw.get("workbench_state"):
        body["workbench_state"] = kw["workbench_state"]
    if kw.get("harness_session_id"):
        body["harness_session_id"] = kw["harness_session_id"]
    return api("POST", "/api/v1/harpp/bridge/status", body, config=config)


def release_idempotency(config=None, **kw):
    """Delete a stuck idempotency key so its scope can be re-claimed (owner/admin)."""
    body = {
        "scope": kw.get("scope", "harpp_message"),
        "idempotency_key": kw.get("idempotency_key", ""),
    }
    return api("POST", "/api/v1/harpp/bridge/idempotency/release", body, config=config)
