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
import re
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


# Safe HARPP workspace_key form (must mirror the PHP service validation so a key
# can never be smuggled into a filesystem path). Lowercase start; [a-z0-9_-]{1,63}.
_WORKSPACE_KEY_RE = re.compile(r"^[a-z][a-z0-9_-]{1,63}$")


def projects_base(config=None):
    """Absolute base directory where per-workspace folders are created.

    Set with: harpp config set projects_base /path/to/base
    Defaults to the parent of the configured `workspace` (e.g. /var/www/html when
    workspace is /var/www/html/applicationostest). Returns None when unavailable.
    """
    try:
        cfg = config or load_config()
    except HarppError:
        cfg = {}
    raw = str((cfg or {}).get("projects_base", "") or "").strip()
    if raw:
        p = Path(raw).expanduser()
        return str(p) if p.is_absolute() else None
    ws = workspace_path(config=cfg)
    if ws:
        return str(Path(ws).parent)
    return None


def workspace_dir_for(workspace_key, config=None):
    """Local folder path for a HARPP workspace: <projects_base>/<workspace_key>.

    Returns None when the base or a valid key is unavailable. The folder is NOT
    created here — the daemon's ensure_workspace_dir() does that (it needs the
    existence check + fs access).
    """
    if not workspace_key or not _WORKSPACE_KEY_RE.match(str(workspace_key)):
        return None
    base = projects_base(config=config)
    if not base:
        return None
    return str(Path(base) / str(workspace_key))


def list_workspaces(config=None):
    """Return active HARPP workspaces as [{id, workspace_key, name, status}].

    Fetched from the bridge so the local daemon can provision a folder for every
    workspace the owner creates. Returns [] on any failure (never raises).
    """
    try:
        response = api("GET", "/api/v1/harpp/bridge/workspaces", config=config)
        data = response.get("data") if isinstance(response, dict) else None
        workspaces = data.get("workspaces") if isinstance(data, dict) else None
        return workspaces if isinstance(workspaces, list) else []
    except Exception:  # noqa: BLE001 - provisioning must never break the wake loop
        return []


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
    # Bust host-level proxy/edge caches on every GET. Shared hosts (e.g.
    # Bluehost LiteSpeed/nginx proxy) serve stale cached bodies for identical
    # URLs — a fixed-URL bridge GET can silently keep returning an old page
    # and hide new messages/workspaces/runs from the daemon. A unique query
    # param is the only signal those caches reliably honor; no-store headers
    # below are defense-in-depth for cooperative caches.
    if method == "GET":
        url += ("&" if "?" in url else "?") + f"_hb={int(time.time() * 1000)}"
    data = json.dumps(body).encode("utf-8") if body is not None else None
    headers = {
        "X-HARPP-BRIDGE-KEY": config["bridge_key"],
        "X-HARPP-TENANT-ID": config["tenant_id"],
        "Accept": "application/json",
        "User-Agent": "harpp-bridge-client/1.0",
        "Cache-Control": "no-store, no-cache, must-revalidate",
        "Pragma": "no-cache",
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
    """Deterministically queue messages and close decisions; optionally report success."""
    notes = []
    for rec in records or []:
        ok = False
        try:
            if rec.get("kind") == "message":
                conv = rec.get("conversation_id")
                if not conv:
                    raise HarppError("message has no conversation_id")
                r = queue_run(
                    message_id=int(rec.get("id", 0)),
                    required_capabilities=rec.get("required_capabilities") or ["desktop"],
                )
                ok = bool(r.get("ok"))
                run = (r.get("data") or {}).get("run") or {}
                notes.append(f"message {rec.get('id')} queued state={run.get('state', 'unknown')} ok={ok}")
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


def queue_run(config=None, **kw):
    return api("POST", "/api/v1/harpp/bridge/runs", {
        "message_id": int(kw.get("message_id") or 0),
        "required_capabilities": kw.get("required_capabilities") or ["desktop"],
    }, config=config)


def register_runner(config=None, **kw):
    return api("POST", "/api/v1/harpp/bridge/runners", {
        "runner_key": kw.get("runner_key", ""),
        "display_name": kw.get("display_name", kw.get("runner_key", "")),
        "capabilities": kw.get("capabilities") or ["desktop"],
    }, config=config)


def claim_runner_wake(runner_key, config=None):
    """Claim the oldest pending wake request for a runner (relay/daemon side)."""
    return api("POST", "/api/v1/harpp/bridge/runner-wakes/claim",
               {"runner_key": str(runner_key or "")}, config=config)


def deliver_runner_wake(request_id, claim_token, config=None):
    """Acknowledge a claimed wake request as delivered (machine came online / WoL sent)."""
    return api("POST", f"/api/v1/harpp/bridge/runner-wakes/{int(request_id)}/delivered",
               {"claim_token": str(claim_token or "")}, config=config)


def fail_runner_wake(request_id, claim_token, error="", config=None):
    """Acknowledge a claimed wake request as failed with an inspectable error."""
    return api("POST", f"/api/v1/harpp/bridge/runner-wakes/{int(request_id)}/failed",
               {"claim_token": str(claim_token or ""), "error": str(error or "")[:2000]},
               config=config)


def report_daemon_status(runner_key, daemon_version="", workflow_counts=None, recent_workflows=None, config=None):
    """Report daemon liveness + workflow summary to the server (status page).
    Best-effort call site; the daemon treats any failure as non-fatal."""
    return api("POST", "/api/v1/harpp/bridge/status/report", {
        "runner_key": str(runner_key or ""),
        "daemon_version": str(daemon_version or "")[:64],
        "workflow_counts": dict(workflow_counts or {}),
        "recent_workflows": [dict(x) for x in (recent_workflows or [])][:10],
    }, config=config)


def claim_run(config=None, **kw):
    return api("POST", "/api/v1/harpp/bridge/runs/claim", {
        "runner_key": kw.get("runner_key", ""),
        "lease_seconds": kw.get("lease_seconds", 300),
    }, config=config)


def mark_run_running(run_id, claim_token, config=None, status="Running."):
    return api("POST", f"/api/v1/harpp/bridge/runs/{int(run_id)}/running", {
        "claim_token": claim_token,
        "status": status,
    }, config=config)


def renew_run(run_id, claim_token, config=None, lease_seconds=300):
    return api("POST", f"/api/v1/harpp/bridge/runs/{int(run_id)}/renew", {
        "claim_token": claim_token,
        "lease_seconds": lease_seconds,
    }, config=config)


def complete_run(run_id, claim_token, config=None, status="Complete.", result=None):
    body = {"claim_token": claim_token, "status": status}
    if isinstance(result, dict):
        body["result"] = result
    return api("POST", f"/api/v1/harpp/bridge/runs/{int(run_id)}/complete", body, config=config)


def approve_run(run_id, approval_token, config=None, rationale="Approved."):
    """Owner approves a risk-gated run using the approval_token from completion."""
    return api("POST", f"/api/v1/harpp/bridge/runs/{int(run_id)}/approve",
               {"approval_token": approval_token, "rationale": rationale}, config=config)


def reject_run(run_id, config=None, rationale="Rejected."):
    """Owner rejects a risk-gated run, revoking it to CANCELLED."""
    return api("POST", f"/api/v1/harpp/bridge/runs/{int(run_id)}/reject",
               {"rationale": rationale}, config=config)


def fail_run(run_id, claim_token, config=None, status="Failed.", result=None):
    body = {"claim_token": claim_token, "status": status}
    if isinstance(result, dict):
        body["result"] = result
    return api("POST", f"/api/v1/harpp/bridge/runs/{int(run_id)}/fail", body, config=config)


def stall_run(run_id, claim_token, config=None, status="Stalled."):
    return api("POST", f"/api/v1/harpp/bridge/runs/{int(run_id)}/stall",
               {"claim_token": claim_token, "status": status}, config=config)


def cancel_run(config=None, run_id=0, message_id=0, claim_token=""):
    """Cancel a run by run id or source message id. Claimable/stalled runs cancel
    unconditionally; a runner may also retire its own CLAIMED/RUNNING run by passing
    the matching claim_token. Idempotent on terminal runs; 409 run_in_progress for
    an in-progress run without the matching token. Used by the wake agent to retire
    the run-queue entry for a message it has already answered, so the runner never
    re-executes already-answered work."""
    return api("POST", "/api/v1/harpp/bridge/runs/cancel",
               {"run_id": int(run_id or 0), "message_id": int(message_id or 0),
                "claim_token": str(claim_token or "")},
               config=config)


def reconcile_runs(healthy, runner_key, config=None):
    """Report the set of run ids the runner is actively supervising."""
    return api("POST", "/api/v1/harpp/bridge/runs/reconcile",
               {"runner_key": runner_key, "healthy": [int(i) for i in healthy]}, config=config)


def mark_run_report_delivered(run_id, config=None):
    return api("POST", f"/api/v1/harpp/bridge/runs/{int(run_id)}/report/delivered", {}, config=config)


def mark_run_report_dead_letter(run_id, error="", config=None):
    return api("POST", f"/api/v1/harpp/bridge/runs/{int(run_id)}/report/dead-letter",
               {"error": error}, config=config)


def conversation_context(conversation_id, config=None, limit=20):
    return api("GET", f"/api/v1/harpp/bridge/conversations/{int(conversation_id)}/context" + _query({"limit": limit}), config=config)


# ── S1 MCP spine: read-only run/runner/artifact/decision access ──────────────
# Exposes approved decision artifact bundles (ADR + decision + files) and live
# run/runner status so an MCP client can verify governance end-to-end.

def run_status(run_id, config=None):
    return api("GET", f"/api/v1/harpp/bridge/runs/{int(run_id)}", config=config)


def list_runners(config=None):
    return api("GET", "/api/v1/harpp/bridge/runners", config=config)


def artifact_bundle_for_decision(decision_id, config=None):
    return api("GET", f"/api/v1/harpp/bridge/artifacts/bundles/decision/{int(decision_id)}", config=config)


def get_decision(decision_id, config=None):
    return api("GET", f"/api/v1/harpp/bridge/decisions/{int(decision_id)}", config=config)


# ── S2 approved-memory retrieval ────────────────────────────────────────────
# Search HARPP's approved memory (ADRs + approved decisions + artifact bundles)
# to cite prior approved work — never raw conversation messages.

def memory_search(q, config=None, limit=5, include_historical=False, budget_limit=None):
    params = {"q": q, "limit": limit, "include_historical": include_historical}
    if budget_limit is not None:
        params["budget_limit"] = budget_limit
    return api("GET", "/api/v1/harpp/bridge/memory/search" + _query(params), config=config)


# ── Derived client context cache ────────────────────────────────────────────
# A bounded, invalidatable local cache of the server-authoritative conversation
# context envelope. Keyed by tenant + conversation, stored with mode 0600, a
# schema version, a size cap, atomic writes, and version-based invalidation.
# Missing / stale / corrupt / schema-incompatible / oversized entries are
# refetched safely from the bridge. Bridge credentials and auth tokens never
# enter the cache.

CONTEXT_CACHE_SCHEMA_VERSION = 1
CONTEXT_CACHE_MAX_BYTES = 256 * 1024  # 256 KiB per conversation envelope


def context_cache_dir():
    raw = os.environ.get("HARPP_CONTEXT_CACHE", "")
    if raw:
        return Path(raw).expanduser()
    return config_path().parent / "context-cache"


def _context_cache_path(tenant_id, conversation_id):
    return context_cache_dir() / f"tenant-{int(tenant_id)}-conv-{int(conversation_id)}.json"


def _redact_context(envelope):
    """Defensively strip credential-shaped fields and message payload blobs."""
    redacted = dict(envelope or {})
    messages = redacted.get("messages")
    if isinstance(messages, list):
        clean = []
        for msg in messages:
            if isinstance(msg, dict):
                msg = {k: v for k, v in msg.items() if k != "payload"}
            clean.append(msg)
        redacted["messages"] = clean
    for key in list(redacted.keys()):
        low = str(key).lower()
        if any(tok in low for tok in ("secret", "password", "token", "key", "credential", "bridge_key")):
            redacted[key] = "[REDACTED]"
    return redacted


def _context_cache_valid(path, tenant_id, conversation_id, expected_version=None):
    """Return (envelope, True) if the cache entry is usable, else (None, False)."""
    try:
        st = path.stat()
        if st.st_mode & 0o077:  # not owner-only permissions -> unsafe, refetch
            return None, False
        if st.st_size > CONTEXT_CACHE_MAX_BYTES:
            return None, False
        payload = json.loads(path.read_text(encoding="utf-8"))
    except Exception:  # noqa: BLE001 - corrupt/oversized/unreadable -> refetch
        return None, False
    if not isinstance(payload, dict):
        return None, False
    meta = payload.get("_meta")
    if not isinstance(meta, dict):
        return None, False
    if int(meta.get("schema_version") or 0) != CONTEXT_CACHE_SCHEMA_VERSION:
        return None, False
    if int(meta.get("tenant_id") or 0) != int(tenant_id):
        return None, False
    if int(meta.get("conversation_id") or 0) != int(conversation_id):
        return None, False
    if expected_version is not None and int((payload.get("data") or {}).get("cache", {}).get("version") or 0) < int(expected_version):
        return None, False
    data = payload.get("data")
    return (data, True) if isinstance(data, dict) else (None, False)


def store_cached_context(tenant_id, conversation_id, envelope):
    """Atomically write a context envelope with mode 0600. Never stores secrets."""
    path = _context_cache_path(tenant_id, conversation_id)
    path.parent.mkdir(parents=True, exist_ok=True)
    payload = {
        "_meta": {
            "schema_version": CONTEXT_CACHE_SCHEMA_VERSION,
            "tenant_id": int(tenant_id),
            "conversation_id": int(conversation_id),
            "saved_at": int(time.time()),
        },
        "data": _redact_context(envelope),
    }
    tmp = path.with_name(f".{path.name}.{os.getpid()}.tmp")
    tmp.write_text(json.dumps(payload, separators=(",", ":")), encoding="utf-8")
    os.chmod(tmp, 0o600)
    os.replace(tmp, path)


def get_cached_context(tenant_id, conversation_id, expected_version=None):
    """Return a validated cached context envelope, or None (invalidate + refetch)."""
    path = _context_cache_path(tenant_id, conversation_id)
    if not path.is_file():
        return None
    envelope, ok = _context_cache_valid(path, tenant_id, conversation_id, expected_version)
    if not ok:
        try:
            path.unlink()
        except OSError:  # noqa: BLE001
            pass
        return None
    return envelope


def invalidate_context_cache(tenant_id, conversation_id):
    try:
        _context_cache_path(tenant_id, conversation_id).unlink()
        return True
    except FileNotFoundError:
        return False
    except OSError:  # noqa: BLE001
        return False


def context_for_conversation(conversation_id, config=None, limit=20, expected_version=None, use_cache=True):
    """Return a context envelope, using the bounded local cache when valid.

    Missing / stale / corrupt / oversized / schema-incompatible / wrong-key
    entries trigger a safe server refetch. Never returns bridge credentials,
    passwords, or cross-tenant/conversation content.
    """
    config = config or load_config()
    tenant_id = str(config["tenant_id"])
    if use_cache:
        cached = get_cached_context(tenant_id, conversation_id, expected_version)
        if cached is not None:
            return cached
    response = conversation_context(conversation_id, config=config, limit=limit)
    if not response.get("ok") or not isinstance(response.get("data"), dict):
        raise HarppError(
            "context refetch failed",
            status=response.get("status") if isinstance(response, dict) else None,
            payload=response if isinstance(response, dict) else None,
        )
    envelope = response["data"]
    if use_cache:
        store_cached_context(tenant_id, conversation_id, envelope)
    return _redact_context(envelope)


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
