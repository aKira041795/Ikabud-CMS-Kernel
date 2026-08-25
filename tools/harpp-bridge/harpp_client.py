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
import urllib.error
import urllib.request
from pathlib import Path

DEFAULT_CONFIG_PATH = Path("~/.config/harpp/config.json").expanduser()


class HarppError(RuntimeError):
    """Bridge request failed; carries HTTP status + parsed payload when available."""

    def __init__(self, message, status=None, payload=None):
        super().__init__(message)
        self.status = status
        self.payload = payload


def config_path():
    raw = os.environ.get("HARPP_CONFIG", "")
    return Path(raw).expanduser() if raw else DEFAULT_CONFIG_PATH


def load_config():
    cfg = {}
    p = config_path()
    if p.is_file():
        try:
            cfg = json.loads(p.read_text(encoding="utf-8"))
        except Exception as exc:  # noqa: BLE001
            raise HarppError(f"config file {p} is invalid JSON: {exc}") from exc
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


def _query(params):
    from urllib.parse import urlencode
    q = {k: str(v) for k, v in params.items() if v not in (None, "", 0) or k == "after"}
    return ("?" + urlencode(q)) if q else ""


def api(method, path, body=None, config=None, timeout=30, dry_run=None):
    config = config or load_config()
    dry = (os.environ.get("HARPP_DRY_RUN") == "1") if dry_run is None else dry_run
    url = config["base_url"] + path
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
        print(json.dumps({"dry_run": True, "method": method, "url": url, "headers": headers, "body": body}, indent=2))
        return {"dry_run": True, "url": url, "method": method, "headers": headers, "body": body}
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
         "workbench_state": kw.get("workbench_state"), "limit": kw.get("limit")}), config=config)


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
                r = send_message(body="✅ Received — the harness is working on it.",
                                 conversation_id=int(conv))
                ok = bool(r.get("ok"))
                notes.append(f"message {rec.get('id')} ack ok={ok}")
            elif rec.get("kind") == "decision":
                did = int(rec.get("id"))
                ack_ok = False
                try:
                    a = acknowledge_decision(did, rationale="Harness auto-acknowledged the owner decision.")
                    ack_ok = bool(a.get("ok"))
                except Exception:  # Applying an already-acknowledged retry may still succeed.
                    pass
                ap = apply_decision(did, rationale="Harness auto-applied the owner decision (standard watch behavior).")
                ok = bool(ap.get("ok"))
                notes.append(f"decision {did} ack={ack_ok} apply={ok}")
        except Exception as e:  # noqa: BLE001
            notes.append(f"{rec.get('kind')} {rec.get('id')} failed: {e}")
        if outcomes is not None:
            outcomes.append((rec, ok))
    return notes


def send_message(config=None, **kw):
    import socket
    body = {"body": _nl(kw.get("body", ""))}
    if kw.get("conversation_id"):
        body["conversation_id"] = int(kw["conversation_id"])
    if kw.get("title"):
        body["title"] = kw["title"]
    # The bridge auto-creates a conversation only when title + harness_session_id
    # are both provided; default the session id to the hostname for convenience.
    session = kw.get("harness_session_id")
    if not session and not kw.get("conversation_id"):
        session = socket.gethostname()
    if session:
        body["harness_session_id"] = session
    return api("POST", "/api/v1/harpp/bridge/messages", body, config=config)


def poll_messages(config=None, **kw):
    return api("GET", "/api/v1/harpp/bridge/messages" + _query(
        {"conversation_id": kw.get("conversation_id"), "after": kw.get("after", 0),
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
