#!/usr/bin/env python3
"""Guarded headless-Pi agent wake for HARPP (mechanism E).

Implements .ai/current-task.harpp-wake.md: when new owner input is staged in the watch
inbox, spawn exactly one headless Pi agent (provider-qualified model) with a bounded,
single-pass task contract. Guardrails:

- Single-flight: atomic O_CREAT|O_EXCL lock with stale TTL (2x timeout) recovery.
- Cooldown + max-per-hour rate limits.
- Hard timeout kill on the agent.
- Processed-id ledger (~/.config/harpp/wake-processed.json) for idempotent dedup.
- Graceful fallback on any failure (missing pi, model error, lock, cooldown) — the
  caller keeps staging + notify-send; nothing is dropped or crashed.

Python stdlib only; no DB/PHP/route changes. This is purely the local client wake layer.
"""
from __future__ import annotations

import json
import os
import re
import shlex
import shutil
import signal
import subprocess
import threading
import time
import uuid
from contextlib import contextmanager
from pathlib import Path

import fcntl

import harpp_client

CONFIG_DIR = Path(os.environ.get("XDG_CONFIG_HOME", str(Path.home() / ".config"))) / "harpp"
LOCK_FILE = CONFIG_DIR / "wake.lock"
PROCESSED_FILE = CONFIG_DIR / "wake-processed.json"
WAKE_LOG = CONFIG_DIR / "wake.log"
JOBS_FILE = CONFIG_DIR / "jobs.json"
WORKFLOWS_FILE = CONFIG_DIR / "workflows.json"
DECISIONS_FILE = CONFIG_DIR / "decisions.json"
# Machine-global defaults so monitoring survives workspace switches (any VS Code
# window reads the same inbox/log). Explicit --inbox/--log still override.
INBOX_FILE = CONFIG_DIR / "inbox.jsonl"
AUTOPROCESS_LOG = CONFIG_DIR / "autoprocess.log"
# Cap each diagnostic log so unbounded tee/append growth can never fill disk.
# wake-agent.log (agent raw-output tee) reached ~280 MiB before capping was added.
LOG_MAX_BYTES = 20 * 1024 * 1024  # 20 MiB per file
JOB_HISTORY_LIMIT = 100
DECISION_LEDGER_LIMIT = 200
DECISION_PROMPT_LIMIT = 8
JOB_VERIFY_TIMEOUT = 600
JOB_REPORT_STALE_AFTER = JOB_VERIFY_TIMEOUT + 300

DEFAULT_MODEL = "deepseek/deepseek-v4-pro"
# Production-grade wake limits: max ~1 run/3min (20/hr) keeps replies responsive while
# bounded; cooldown 60s is the min gap between runs (new-message bypass covers bursts).
DEFAULT_COOLDOWN = 60
DEFAULT_MAX_PER_HOUR = 20
DEFAULT_TIMEOUT = 900
OUTPUT_DRAIN_TIMEOUT = 10

# Prompt-driven model routing: the owner can ask for a specific model in their message
# (e.g. "use gpt sol", "use flash") and the wake router honors it. If the requested
# model is unavailable / its usage is exhausted, maybe_wake falls back to the default.
MODEL_ALIASES = {
    "openai-codex/gpt-5.6-sol": ["gpt sol", "got sol", "codex sol", "openai codex", "sol", "gpt-5.6"],
    "openai-codex/gpt-5.4": ["gpt 5.4", "gpt-5.4", "5.4"],
    "deepseek/deepseek-v4-pro": ["deepseek pro", "v4 pro"],
    "deepseek/deepseek-v4-flash": ["flash"],
}
# Ordered delegation chain when a model's token/usage/quota/balance is exhausted: the
# engine retries with the next model in this list instead of burning bounded auto-repair
# rounds on a model that cannot run. The stage's own model is always tried first.
MODEL_FALLBACK_ORDER = [
    "openai-codex/gpt-5.6-sol",
    "openai-codex/gpt-5.4",
    "deepseek/deepseek-v4-pro",
    "deepseek/deepseek-v4-flash",
]
AUTHORITY_ORDER = {"L0": 0, "L1": 1, "L2": 2, "L3": 3, "L4": 4}
ESCALATION_FLAGS = {
    "architecture_change": "change architecture/contract",
    "contract_break": "break a contract",
    "data_loss_risk": "risk data loss",
    "security_exception": "require a security exception",
    "scope_expansion": "expand scope beyond the contract",
}


def pick_model(items, default: str) -> str:
    """Return the model the owner asked for in the staged message bodies, else the default."""
    text = " ".join(str(i.get("body") or "") for i in (items or [])).lower()
    for model_id, keywords in MODEL_ALIASES.items():
        for kw in keywords:
            if re.search(r"\b" + re.escape(kw) + r"\b", text):
                return model_id
    return default
DEFAULT_MAX_RETRIES = 3
_LOCK_TOKEN = None


def _now() -> int:
    return int(time.time())


def default_workspace() -> str | None:
    """Absolute path of the configured active workspace, or None."""
    try:
        return harpp_client.workspace_path()
    except Exception:  # noqa: BLE001
        return None


# Terminal emulators, in preference order, with the arg to run a command after.
TERMINAL_EMULATORS = [
    ("gnome-terminal", ["--"]),
    ("konsole", ["-e"]),
    ("xfce4-terminal", ["-e"]),
    ("mate-terminal", ["-e"]),
    ("x-terminal-emulator", ["-e"]),
    ("xterm", ["-e"]),
]


def _detect_terminal() -> tuple[str | None, list | None]:
    for name, args in TERMINAL_EMULATORS:
        if shutil.which(name):
            return name, args
    return None, None


def open_agent_terminal(pid: int, tail_path: str, title: str = "HARPP") -> bool:
    """Open a visible terminal tailing `tail_path`, closing shortly after pid dies.

    Gives a human user live visual feedback that HARPP is woken/working. The window
    lingers HARPP_WAKE_TERMINAL_LINGER seconds (default 30) after the agent exits so
    the output can be read before it closes. Never raises into the wake loop.
    """
    try:
        name, args = _detect_terminal()
        if not name or not tail_path:
            return False
        linger = max(0, int(os.environ.get("HARPP_WAKE_TERMINAL_LINGER", "30")))
        inner = (f"tail -n 20 -f --pid={int(pid)} -- {shlex.quote(str(tail_path))}; "
                 f"echo; echo '--- HARPP agent finished (window closes in {linger}s) ---'; "
                 f"sleep {linger}")
        subprocess.Popen([name, *args, "bash", "-lc", inner],
                         start_new_session=True,
                         stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        log(f"opened {name} terminal tailing {tail_path} for pid {pid}")
        return True
    except Exception as e:  # noqa: BLE001
        log(f"could not open wake terminal: {e}")
        return False


def _open_capped_log(path: Path, max_bytes: int | None = None):
    """Open a log in append mode, truncating in place if it exceeds max_bytes.

    Truncate-in-place (not rename) preserves the inode so `tail -f` and already-open
    append FDs keep following the same file; O_APPEND writers always land at EOF.
    """
    max_bytes = LOG_MAX_BYTES if max_bytes is None else max_bytes
    try:
        if path.exists() and path.stat().st_size > max_bytes:
            path.open("w", encoding="utf-8").close()
    except Exception:  # noqa: BLE001 - never let logging break the watch loop
        pass
    return path.open("a", encoding="utf-8", buffering=1)


def log(msg: str) -> None:
    line = f"{time.strftime('%Y-%m-%d %H:%M:%S')} {msg}"
    try:
        WAKE_LOG.parent.mkdir(parents=True, exist_ok=True)
        with _open_capped_log(WAKE_LOG) as f:
            f.write(line + "\n")
    except Exception:  # noqa: BLE001 - logging must never break the watch loop
        pass
    print("harpp wake: " + line, flush=True)


def _load_json(path: Path, default):
    try:
        if path.exists():
            return json.loads(path.read_text(encoding="utf-8"))
    except Exception:  # noqa: BLE001
        pass
    return default


def _save_json(path: Path, obj) -> None:
    try:
        path.parent.mkdir(parents=True, exist_ok=True)
        tmp = path.with_name(f".{path.name}.{os.getpid()}.tmp")
        tmp.write_text(json.dumps(obj), encoding="utf-8")
        os.replace(tmp, path)
    except Exception as e:  # noqa: BLE001
        try:
            tmp.unlink()
        except Exception:  # noqa: BLE001
            pass
        print(f"harpp wake: state save failed for {path}: {e}", flush=True)


def read_state() -> dict:
    state = _load_json(PROCESSED_FILE, {})
    state.setdefault("messages", [])
    state.setdefault("decisions", [])
    state.setdefault("last_wake", 0)
    state.setdefault("wake_hour", [])
    state.setdefault("last_attempt_messages", [])
    state.setdefault("failures", {})
    return state


def decisions_path() -> Path:
    return DECISIONS_FILE


def load_decisions() -> list:
    data = _load_json(decisions_path(), [])
    return data if isinstance(data, list) else []


def save_decisions(records: list) -> None:
    _save_json(decisions_path(), list(records)[-DECISION_LEDGER_LIMIT:])


def _next_decision_id(records: list) -> str:
    seq = 0
    for rec in records:
        try:
            seq = max(seq, int(str(rec.get("id") or "").split("-", 1)[1]))
        except Exception:  # noqa: BLE001
            pass
    return f"DEC-{seq + 1:04d}"


_SECRET_PATTERNS = [
    re.compile(r"(?i)\b(x-harpp-bridge-key|bridge[_ -]?key|api[_ -]?key|token|secret|password|passwd|authorization)\b\s*[:=]\s*([^\s,;]+)"),
    re.compile(r"(?i)\b(bearer)\s+[a-z0-9._\-+/=]+"),
]


def sanitize_decision_text(value: str | None) -> str:
    text = str(value or "")
    for pattern in _SECRET_PATTERNS:
        text = pattern.sub(lambda m: f"{m.group(1)}=<redacted>", text)
    text = re.sub(r"(?i)(https?://[^\s]+)([?&](?:token|key|secret|password)=[^\s&]+)", r"\1\2=<redacted>", text)
    return " ".join(text.split())


def _summary_value(value) -> str:
    text = sanitize_decision_text(value)
    return text.strip()


def _summary_detail(value: str | None, limit: int = 600) -> str:
    text = _summary_value(value)
    if len(text) <= limit:
        return text
    return text[:max(0, limit - 1)].rstrip() + "…"


def _summary_count_line(label: str, passed, total) -> str | None:
    if passed is None or total is None:
        return None
    try:
        passed = int(passed)
        total = int(total)
    except Exception:  # noqa: BLE001
        return None
    mark = " ✓" if total >= 0 and passed == total else ""
    return f"{label:<13}{passed}/{total}{mark}"


def summarize(stage_report: dict | None) -> str:
    report = dict(stage_report or {})
    task = _summary_value(report.get("task") or report.get("title") or report.get("stage") or "HARPP")
    state = _summary_value(report.get("state") or report.get("status") or "STATUS")
    lines = [f"{task} — {state}"]
    changed = report.get("changed_files")
    if changed is not None:
        try:
            changed = int(changed)
            lines.append(f"{'Changed':<13}{changed} file" + ("s" if changed != 1 else ""))
        except Exception:  # noqa: BLE001
            pass
    for label, prefix in (("Unit", "unit"), ("Integration", "integration"), ("Playwright", "playwright")):
        line = _summary_count_line(label, report.get(f"{prefix}_passed"), report.get(f"{prefix}_total"))
        if line:
            lines.append(line)
    repairs_done = report.get("repairs_done")
    repairs_max = report.get("repairs_max")
    if repairs_done is not None or repairs_max is not None:
        left = "?" if repairs_done is None else str(int(repairs_done))
        right = "?" if repairs_max is None else str(int(repairs_max))
        lines.append(f"{'Repairs':<13}{left}/{right}")
    scope = report.get("scope")
    if scope is True:
        lines.append(f"{'Scope':<13}✓")
    elif scope is False:
        lines.append(f"{'Scope':<13}remediation…")
    elif scope:
        lines.append(f"{'Scope':<13}{_summary_value(scope)}")
    review = report.get("review")
    if review is True:
        lines.append(f"{'Review':<13}✓")
    elif review is False:
        lines.append(f"{'Review':<13}remediation…")
    elif review is not None and str(review).strip():
        lines.append(f"{'Review':<13}{_summary_value(review)}")
    next_step = _summary_value(report.get("next"))
    if next_step:
        lines.append(f"Next: {next_step}")
    details = _summary_detail(report.get("details"))
    if details:
        lines.append(f"[Details] {details}")
    return "\n".join(lines)


def _summary_headline(stage_report: dict | None) -> str:
    return summarize(stage_report).splitlines()[0]


def _review_status(wf: dict, current_stage: dict | None = None, status: str | None = None):
    review_stage = None
    for candidate in wf.get("stages", []) or []:
        if str(candidate.get("name") or "").strip().lower() == "review":
            review_stage = candidate
            break
    if not review_stage:
        return None
    if str((current_stage or {}).get("name") or "").strip().lower() == "review":
        if status == "DONE":
            return True
        if status in ("FAILED", "REPAIR", "BLOCKED"):
            return False
    review_status = str(review_stage.get("status") or "").lower()
    if review_status == "done":
        return True
    if review_status in ("failed", "blocked", "escalated"):
        return False
    return "PENDING"


def _summary_changed_files(repo: str | None) -> int | None:
    if not repo:
        return None
    try:
        return len(_git_changed_paths(repo))
    except Exception:  # noqa: BLE001
        return None


def _summary_stage_task(wf: dict, stage: dict | None) -> str:
    title = _summary_value(wf.get("title") or wf.get("id") or "HARPP workflow")
    stage_name = _summary_value((stage or {}).get("name"))
    return f"{title} / {stage_name}" if stage_name else title


def _workflow_summary_report(wf: dict, status: str, stage: dict | None = None,
                             round_no: int = 0, max_repairs: int = 0,
                             reason: str | None = None) -> dict:
    stage_name = _summary_value((stage or {}).get("name") or "workflow")
    final_stage = stage_name.lower()
    state_map = {
        "DONE": "RELEASE READY" if final_stage in ("release-gate", "release", "production-release") else "WORKFLOW COMPLETE",
        "REPAIR": f"{stage_name.upper()} REMEDIATION",
        "DELEGATED": f"{stage_name.upper()} MODEL DELEGATION",
        "BLOCKED": "BLOCKED",
        "ESCALATED": "DECISION REQUIRED",
        "FAILED": f"{stage_name.upper()} FAILED",
    }
    details = reason or wf.get("blocked_reason") or wf.get("escalation_reason")
    next_map = {
        "DONE": "awaiting decision — approve/hold/reject release" if final_stage in ("release-gate", "release", "production-release") else "done — safe to move forward",
        "REPAIR": f"auto-repair round {round_no}/{max_repairs} in progress — no owner action",
        "DELEGATED": f"model usage exhausted — delegated to {_summary_value(reason or 'another model')} — no owner action",
        "BLOCKED": f"blocked({_summary_value(reason or wf.get('blocked_reason') or 'owner action required')})",
        "ESCALATED": "awaiting decision — approve revised authority/scope or stop",
        "FAILED": "remediation required — not safe to proceed",
    }
    report = {
        "task": _summary_stage_task(wf, stage),
        "state": state_map.get(status, _summary_value(status)),
        "changed_files": _summary_changed_files(wf.get("workspace")),
        "repairs_done": wf.get("repair_count"),
        "repairs_max": wf.get("max_repairs"),
        "review": _review_status(wf, stage, status),
        "next": next_map.get(status, "status unknown"),
        "details": details,
    }
    if status == "DONE" and final_stage not in ("release-gate", "release", "production-release"):
        report["scope"] = True
    return report


def _split_sentences(text: str) -> list[str]:
    parts = re.split(r"(?<=[.!?])\s+|\n+", str(text or "").strip())
    return [p.strip(" -\t") for p in parts if p and p.strip(" -\t")]


def _is_ack_message(body: str) -> bool:
    low = " ".join(str(body or "").lower().split())
    return low in {
        "ok", "okay", "thanks", "thank you", "got it", "sounds good", "sgtm",
        "roger", "ack", "acknowledged", "cool", "great", "yep", "yes",
    }


def _is_status_or_question(body: str) -> bool:
    low = " ".join(str(body or "").lower().split())
    if not low:
        return True
    if "?" in low:
        return True
    return any(low.startswith(prefix) for prefix in (
        "status", "what is", "what's", "when will", "when can", "how is", "how's",
        "did you", "have you", "is it", "are we", "where are", "progress", "eta",
    ))


def is_directive_message(body: str) -> bool:
    if _is_ack_message(body) or _is_status_or_question(body) or parse_workflow_command(body):
        return False
    low = " ".join(str(body or "").lower().split())
    directive_markers = (
        "please ", "use ", "keep ", "ensure ", "make ", "implement ", "fix ",
        "update ", "change ", "add ", "remove ", "avoid ", "strip ", "preserve ",
        "limit ", "only ", "must ", "should ", "need to ", "do not ", "don't ",
        "never ", "always ", "without ",
    )
    return low.startswith(directive_markers) or any(f" {marker}" in low for marker in directive_markers)


def _directive_parts(body: str) -> tuple[str, list[str], list[str]]:
    decision = ""
    constraints = []
    additional = []
    for sentence in _split_sentences(sanitize_decision_text(body)):
        low = sentence.lower()
        if any(token in low for token in ("do not", "don't", "never", "only", "without", "must not", "keep scope", "stdlib only", "no secrets")):
            constraints.append(sentence)
        elif not decision:
            decision = sentence
        else:
            additional.append(sentence)
    return decision or sanitize_decision_text(body), constraints, additional


def record_local_decision(*, task: str, decision: str, constraints=None, additional_requirements=None,
                          source: str = "human", applied_to: str = "", created_at: str | None = None,
                          source_message_id: int | None = None) -> dict:
    records = load_decisions()
    if source_message_id is not None:
        for rec in records:
            if int(rec.get("source_message_id") or 0) == int(source_message_id):
                return rec
    record = {
        "id": _next_decision_id(records),
        "task": sanitize_decision_text(task),
        "decision": sanitize_decision_text(decision),
        "constraints": [sanitize_decision_text(v) for v in (constraints or []) if str(v or "").strip()],
        "additional_requirements": [sanitize_decision_text(v) for v in (additional_requirements or []) if str(v or "").strip()],
        "source": sanitize_decision_text(source or "human") or "human",
        "applied_to": sanitize_decision_text(applied_to),
        "created_at": created_at or time.strftime("%Y-%m-%d %H:%M:%S"),
    }
    if source_message_id is not None:
        record["source_message_id"] = int(source_message_id)
    records.append(record)
    save_decisions(records)
    return record


def auto_record_directives(records) -> int:
    added = 0
    for rec in records or []:
        if rec.get("kind") != "message":
            continue
        if str(rec.get("sender_type") or "owner").lower() not in ("owner", "user"):
            continue
        body = str(rec.get("body") or "")
        if not is_directive_message(body):
            continue
        decision, constraints, additional = _directive_parts(body)
        before = len(load_decisions())
        record_local_decision(
            task=f"conversation:{int(rec.get('conversation_id') or 0)}",
            decision=decision,
            constraints=constraints,
            additional_requirements=additional,
            source="human",
            applied_to="stage:owner-message",
            created_at=str(rec.get("created_at") or time.strftime("%Y-%m-%d %H:%M:%S")),
            source_message_id=int(rec.get("id") or 0),
        )
        if len(load_decisions()) > before:
            added += 1
    return added


def recent_decisions_text(limit: int = DECISION_PROMPT_LIMIT) -> str:
    records = load_decisions()[-max(0, int(limit)):]
    if not records:
        return "- none"
    lines = []
    for rec in records:
        lines.append(f"- {rec.get('id')}: task={rec.get('task')} | decision={rec.get('decision')}")
        if rec.get("constraints"):
            lines.append(f"  constraints: {'; '.join(rec['constraints'])}")
        if rec.get("additional_requirements"):
            lines.append(f"  additional: {'; '.join(rec['additional_requirements'])}")
        if rec.get("applied_to"):
            lines.append(f"  applied_to: {rec.get('applied_to')}")
    return "\n".join(lines)


def save_state(state: dict) -> None:
    _save_json(PROCESSED_FILE, state)


def _stale_lock(timeout: int) -> bool:
    """Recover when the lock holder is dead, or the lock is older than 2x timeout."""
    try:
        raw = LOCK_FILE.read_text().strip()
        parts = raw.split()
        pid = int(parts[0]) if parts else 0
        if 0 < pid < 4194304:  # plausible Linux PID range -> liveness check
            # Recover an orphaned lock even when another thread in this PID once owned it.
            if len(parts) > 1 and _now() - int(parts[1]) > 2 * timeout:
                log(f"lock for pid {pid} exceeded stale TTL; recovering")
                return True
            try:
                os.kill(pid, 0)  # signal 0 = existence probe
                return False  # holder alive -> lock legitimately held
            except ProcessLookupError:
                log(f"lock holder pid {pid} is dead; recovering")
                return True
            except PermissionError:
                return False
        # Old timestamp-only format (or unparseable): compare the stored timestamp value.
        try:
            return _now() - int(raw) > 2 * timeout
        except ValueError:
            return _now() - LOCK_FILE.stat().st_mtime > 2 * timeout
    except Exception:  # noqa: BLE001
        try:
            return _now() - LOCK_FILE.stat().st_mtime > 2 * timeout
        except OSError:
            return False


def acquire_lock(timeout: int) -> bool:
    """Single-flight acquire with PID-liveness recovery. Returns True if this process owns it."""
    global _LOCK_TOKEN
    LOCK_FILE.parent.mkdir(parents=True, exist_ok=True)
    token = f"{os.getpid()} {_now()} {uuid.uuid4().hex}"
    try:
        fd = os.open(LOCK_FILE, os.O_CREAT | os.O_EXCL | os.O_WRONLY)
        try:
            os.write(fd, token.encode())
        finally:
            os.close(fd)
        _LOCK_TOKEN = token
        return True
    except FileExistsError:
        try:
            stale_inode = LOCK_FILE.stat().st_ino
        except OSError:
            stale_inode = None
        if _stale_lock(timeout):
            try:
                # Do not unlink a replacement lock created during stale inspection.
                if stale_inode is None or LOCK_FILE.stat().st_ino != stale_inode:
                    return False
                LOCK_FILE.unlink()
            except OSError:
                return False
            log("recovered stale wake lock")
            return acquire_lock(timeout)
        return False


def release_lock() -> None:
    """Release only the lock instance acquired by this process."""
    global _LOCK_TOKEN
    try:
        if _LOCK_TOKEN and LOCK_FILE.read_text().strip() == _LOCK_TOKEN:
            LOCK_FILE.unlink()
    except OSError:
        pass
    finally:
        _LOCK_TOKEN = None


def in_cooldown(state: dict, cooldown: int) -> bool:
    return cooldown > 0 and _now() - int(state.get("last_wake", 0)) < cooldown


def over_hourly_limit(state: dict, max_per_hour: int) -> bool:
    if max_per_hour <= 0:
        return False
    hour_ago = _now() - 3600
    recent = [t for t in state.get("wake_hour", []) if t > hour_ago]
    return len(recent) >= max_per_hour


def unprocessed_items(inbox: str) -> list:
    """Return inbox MESSAGE records whose ids are not yet in the processed ledger.

    Decisions are handled instantly by the deterministic autoprocess layer (ack/apply),
    so the wake agent only needs to pick up unprocessed MESSAGES for substantive replies.
    """
    state = read_state()
    records = []
    try:
        p = Path(inbox)
        if p.exists():
            for line in p.read_text(encoding="utf-8").splitlines():
                line = line.strip()
                if not line:
                    continue
                try:
                    records.append(json.loads(line))
                except Exception:  # noqa: BLE001
                    continue
    except Exception:  # noqa: BLE001
        return []
    messages = set(state.get("messages", []))
    # The watch bridge can append the same durable message more than once (for
    # example after reconnecting). Treat the message id as the inbox identity so
    # one owner request produces one expected reply and one retry increment.
    new = []
    seen = set()
    for r in reversed(records):
        rid = int(r.get("id", 0))
        if r.get("kind") == "message" and rid not in messages and rid not in seen:
            seen.add(rid)
            new.append(r)
    return list(reversed(new))


def record_attempt(records: list) -> None:
    state = read_state()
    now = _now()
    state["wake_hour"] = [t for t in state.get("wake_hour", []) if t > now - 3600]
    state["wake_hour"].append(now)
    state["last_wake"] = now
    state["last_attempt_messages"] = [int(r.get("id", 0)) for r in records]
    save_state(state)


def mark_processed(records: list) -> None:
    state = read_state()
    for r in records or []:
        rid = int(r.get("id", 0))
        if r.get("kind") == "message" and rid not in state["messages"]:
            state["messages"].append(rid)
        elif r.get("kind") == "decision" and rid not in state["decisions"]:
            state["decisions"].append(rid)
        state["failures"].pop(str(rid), None)
    save_state(state)


def record_failure(records: list) -> None:
    state = read_state()
    for r in records:
        key = str(int(r.get("id", 0)))
        state["failures"][key] = int(state["failures"].get(key, 0)) + 1
    save_state(state)


# ---------------------------------------------------------------------------
# Job monitor — close the "did the model finish?" loop so the owner never has
# to remind the harness. Delegated model processes (e.g. a long GPT-Sol run)
# are registered via `harpp job track`; the watch daemon then detects when the
# pid exits, verifies the outcome (marker in log and/or a verify command),
# optionally commits the repo, and auto-reports the result back to the owner
# conversation through the bridge — no reminder required.
# ---------------------------------------------------------------------------

@contextmanager
def _jobs_lock():
    """Serialize registry read/modify/write operations across watch processes/threads."""
    lock_path = Path(str(JOBS_FILE) + ".lock")
    lock_path.parent.mkdir(parents=True, exist_ok=True)
    with lock_path.open("a+") as lock_file:
        fcntl.flock(lock_file.fileno(), fcntl.LOCK_EX)
        try:
            yield
        finally:
            fcntl.flock(lock_file.fileno(), fcntl.LOCK_UN)


def _jobs_state_unlocked() -> dict:
    state = _load_json(JOBS_FILE, {})
    if not isinstance(state, dict):
        state = {}
    if not isinstance(state.get("jobs"), dict):
        state["jobs"] = {}
    if not isinstance(state.get("reported"), list):
        state["reported"] = []
    state["jobs"] = {jid: _normalize_job(job) for jid, job in state["jobs"].items() if isinstance(job, dict)}
    return state


def _save_jobs_state_unlocked(state: dict) -> None:
    """Atomically persist registry state, raising so callers never assume it was saved."""
    JOBS_FILE.parent.mkdir(parents=True, exist_ok=True)
    tmp = JOBS_FILE.with_name(f".{JOBS_FILE.name}.{os.getpid()}.{uuid.uuid4().hex}.tmp")
    try:
        tmp.write_text(json.dumps(state), encoding="utf-8")
        os.replace(tmp, JOBS_FILE)
    except Exception:
        try:
            tmp.unlink()
        except OSError:
            pass
        raise


def _prune_jobs_state(state: dict) -> None:
    """Bound completed history while retaining every active or delivery-pending job."""
    jobs = state.get("jobs", {})
    completed = [j for j in jobs.values() if j.get("id") in set(state.get("reported", []))]
    completed.sort(key=lambda j: (j.get("reported_at") or "", j.get("finished_at") or ""), reverse=True)
    keep = {j.get("id") for j in completed[:JOB_HISTORY_LIMIT]}
    for jid in list(jobs):
        if jid in state.get("reported", []) and jid not in keep:
            del jobs[jid]
    state["reported"] = [jid for jid in state.get("reported", []) if jid in keep]


def jobs_state() -> dict:
    with _jobs_lock():
        return _jobs_state_unlocked()


def save_jobs_state(state: dict) -> None:
    with _jobs_lock():
        _save_jobs_state_unlocked(state)


def _pid_identity(pid: int) -> str | None:
    """Return Linux process start ticks, which distinguish a reused PID."""
    try:
        raw = Path(f"/proc/{pid}/stat").read_text(encoding="utf-8")
        after_comm = raw[raw.rfind(")") + 2:].split()
        return after_comm[19]  # field 22 (starttime); field 3 starts this list
    except (OSError, IndexError):
        return None


def _log_identity(path: str | None) -> tuple[int | None, str | None]:
    if not path:
        return None, None
    try:
        stat = Path(path).stat()
        return stat.st_size, f"{stat.st_dev}:{stat.st_ino}"
    except OSError:
        return None, None


def _git_changed_paths(repo: str | None) -> list[str]:
    if not repo:
        return []
    try:
        proc = subprocess.run(
            ["git", "-C", repo, "status", "--porcelain=v1", "-z", "--untracked-files=all"],
            stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, timeout=30, check=True)
        records = (proc.stdout or b"").split(b"\0")
        paths = set()
        i = 0
        while i < len(records) and records[i]:
            rec = records[i]
            status = rec[:2]
            paths.add(rec[3:].decode("utf-8", errors="surrogateescape"))
            if b"R" in status or b"C" in status:
                i += 1
                if i < len(records) and records[i]:
                    paths.add(records[i].decode("utf-8", errors="surrogateescape"))
            i += 1
        return sorted(paths)
    except Exception:  # noqa: BLE001
        return []


def _git_sha(repo: str | None) -> str | None:
    if not repo:
        return None
    try:
        proc = subprocess.run(["git", "-C", repo, "rev-parse", "HEAD"], stdout=subprocess.PIPE,
                              stderr=subprocess.DEVNULL, text=True, timeout=30, check=True)
        sha = (proc.stdout or "").strip()
        return sha or None
    except Exception:  # noqa: BLE001
        return None


def _task_id(value: str | None) -> str:
    text = re.sub(r"[^A-Za-z0-9]+", "_", str(value or "").strip()).strip("_")
    return text[:120]


def _next_run_id() -> str:
    today = time.strftime("%Y%m%d")
    prefix = f"HARPP-{today}-"
    seq = 0
    try:
        for path, key in ((JOBS_FILE, "jobs"), (WORKFLOWS_FILE, "workflows")):
            state = _load_json(path, {})
            values = (state.get(key, {}) or {}).values() if isinstance(state, dict) else []
            for rec in values:
                rid = str((rec or {}).get("run_id") or "")
                if rid.startswith(prefix):
                    try:
                        seq = max(seq, int(rid.rsplit("-", 1)[1]))
                    except Exception:  # noqa: BLE001
                        pass
    except Exception:  # noqa: BLE001
        pass
    return f"{prefix}{seq + 1:05d}"


def _job_state_label(job: dict) -> str:
    status = str(job.get("status") or "running").lower()
    outcome = str(job.get("outcome") or "").upper()
    if outcome in ("DONE", "FAILED"):
        return outcome
    return "RUNNING" if status in ("running", "reporting") else status.upper()


def _normalize_authority_level(value: str | None, default: str | None = None) -> str:
    level = str(value or default or harpp_client.DEFAULT_HARPP_AUTHORITY).strip().upper()
    return level if level in AUTHORITY_ORDER else str(default or harpp_client.DEFAULT_HARPP_AUTHORITY)


def _authority_policy_map(value=None) -> dict:
    policy = dict(harpp_client.DEFAULT_AUTHORITY_POLICY)
    if isinstance(value, dict):
        policy.update({str(k).upper(): str(v) for k, v in value.items()})
    return policy


def _governance_defaults() -> dict:
    try:
        cfg = harpp_client.governance_config()
    except Exception:  # noqa: BLE001
        cfg = {}
    return {
        "harpp_authority": _normalize_authority_level(cfg.get("harpp_authority"), harpp_client.DEFAULT_HARPP_AUTHORITY),
        "authority_policy": _authority_policy_map(cfg.get("authority_policy")),
    }


def _stage_required_authority(stage: dict | None) -> str:
    if isinstance(stage, dict) and stage.get("required_authority"):
        return _normalize_authority_level(stage.get("required_authority"))
    low = str((stage or {}).get("name") or "").strip().lower()
    if low in ("release", "deploy", "production-release"):
        return "L4"
    if low in ("release-gate", "delivery", "deliver"):
        return "L3"
    return "L2"


def _authority_requires_human(required: str, policy: dict) -> bool:
    return str(policy.get(required, "autonomous")).strip().lower() in ("human_approval", "human-approval", "human", "approval_required")


def _authority_escalation_reason(workflow: dict, stage: dict | None) -> str | None:
    required = _stage_required_authority(stage)
    configured = _normalize_authority_level(workflow.get("authority_level"), harpp_client.DEFAULT_HARPP_AUTHORITY)
    policy = _authority_policy_map(workflow.get("authority_policy"))
    if AUTHORITY_ORDER.get(required, 0) > AUTHORITY_ORDER.get(configured, 0):
        return f"required authority {required} exceeds configured authority {configured}"
    if _authority_requires_human(required, policy):
        return f"required authority {required} requires human approval by policy"
    return None


def _override_escalation_reason(source: dict | None) -> str | None:
    if not isinstance(source, dict):
        return None
    for key, label in ESCALATION_FLAGS.items():
        if source.get(key):
            return label
    return None


def _workflow_stage_state(name: str | None, workflow_status: str | None = None, repairing: bool = False) -> str:
    status = str(workflow_status or "").lower()
    if status == "escalated":
        return "ESCALATED"
    if status == "blocked":
        return "BLOCKED"
    if status == "done":
        return "DONE"
    if status == "failed":
        return "FAILED"
    if repairing:
        return "REPAIRING"
    low = str(name or "").strip().lower()
    if low == "implement":
        return "IMPLEMENTING"
    if low == "review":
        return "REVIEWING"
    if low == "architect":
        return "ARCHITECTING"
    if low == "release-gate":
        return "RELEASE_GATE"
    return (re.sub(r"[^A-Za-z0-9]+", "_", low).strip("_") or "RUNNING").upper()


def _normalize_job(job: dict) -> dict:
    rec = dict(job or {})
    rec.setdefault("run_id", _next_run_id())
    rec.setdefault("task_id", _task_id(rec.get("task")))
    rec.setdefault("contract_revision", 0)
    rec.setdefault("authority_level", _normalize_authority_level(rec.get("authority_level")))
    rec["state"] = _job_state_label(rec)
    rec.setdefault("stage_attempts", {})
    rec.setdefault("base_sha", _git_sha(rec.get("repo")))
    rec.setdefault("current_sha", _git_sha(rec.get("repo")))
    rec.setdefault("human_decisions", [])
    return rec


def _workflow_budget_defaults(max_repairs: int | None = None) -> dict:
    defaults = dict(harpp_client.DEFAULT_WORKFLOW_BUDGETS)
    if max_repairs is not None:
        defaults["max_repairs"] = max(0, int(max_repairs))
    return defaults


def _normalize_stage(stage: dict, index: int) -> dict:
    rec = dict(stage or {})
    rec.setdefault("job_id", None)
    rec.setdefault("status", "pending")
    rec.setdefault("timeout", 1800)
    rec.setdefault("marker", None)
    rec.setdefault("verify", None)
    rec.setdefault("commit", False)
    rec.setdefault("prompt_file", None)
    rec.setdefault("prompt", None)
    rec.setdefault("model", "deepseek/deepseek-v4-pro")
    rec.setdefault("required_authority", _stage_required_authority(rec))
    rec.setdefault("attempt_count", 0)
    if not isinstance(rec.get("attempt_statuses"), list):
        rec["attempt_statuses"] = []
    rec.setdefault("last_run_id", None)
    rec.setdefault("last_started_at", None)
    rec.setdefault("last_finished_at", None)
    return rec


def _normalize_workflow(wf: dict) -> dict:
    rec = dict(wf or {})
    governance = _governance_defaults()
    rec.setdefault("id", uuid.uuid4().hex[:12])
    rec.setdefault("title", "")
    rec.setdefault("conversation_id", 0)
    rec.setdefault("workspace", None)
    stages = rec.get("stages") if isinstance(rec.get("stages"), list) else []
    rec["stages"] = [_normalize_stage(stage, i) for i, stage in enumerate(stages)]
    rec.setdefault("current_index", 0)
    rec.setdefault("status", "running")
    budgets = _workflow_budget_defaults(rec.get("max_repairs"))
    for key, value in budgets.items():
        rec.setdefault(key, value)
    rec.setdefault("repair_count", 0)
    rec.setdefault("total_cycles", 0)
    rec.setdefault("browser_repairs", 0)
    rec.setdefault("tool_retries", 0)
    rec.setdefault("network_retries", 0)
    rec.setdefault("model_fallbacks", 0)
    rec.setdefault("advancing", False)
    rec.setdefault("advancing_ts", 0)
    rec.setdefault("created_at", time.strftime("%Y-%m-%d %H:%M:%S"))
    rec.setdefault("updated_at", rec.get("created_at"))
    rec.setdefault("run_id", _next_run_id())
    rec.setdefault("task_id", _task_id(rec.get("title")))
    rec.setdefault("contract_revision", 0)
    rec.setdefault("authority_level", governance["harpp_authority"])
    rec.setdefault("authority_policy", governance["authority_policy"])
    rec.setdefault("base_sha", _git_sha(rec.get("workspace")))
    rec.setdefault("current_sha", _git_sha(rec.get("workspace")))
    rec.setdefault("human_decisions", [])
    rec.setdefault("blocked_reason", None)
    rec.setdefault("escalation_reason", None)
    rec["state"] = _workflow_stage_state(
        rec["stages"][min(max(int(rec.get("current_index", 0)), 0), max(len(rec["stages"]) - 1, 0))].get("name")
        if rec["stages"] else None,
        rec.get("status"),
        False,
    )
    return rec


def track_job(*, pid: int, model: str, task: str, conversation_id: int | None = None,
              log_path: str | None = None, verify: str | None = None,
              marker: str | None = None, repo: str | None = None,
              commit: bool = False, timeout: int = 0, run_id: str | None = None,
              task_id: str | None = None, contract_revision: int = 0,
              state: str | None = None, human_decisions: list | None = None,
              authority_level: str | None = None) -> str:
    """Register an in-flight delegated model job for completion monitoring."""
    pid = int(pid)
    if pid < 1:
        raise ValueError("pid must be a positive integer")
    if conversation_id is None or int(conversation_id) < 1:
        raise ValueError("conversation_id must be a positive integer")
    if not str(model).strip() or not str(task).strip():
        raise ValueError("model and task must not be empty")
    if commit and not repo:
        raise ValueError("--commit requires --repo")
    log_offset, log_identity = _log_identity(log_path)
    job_id = uuid.uuid4().hex[:12]
    job = {
        "id": job_id, "pid": pid, "pid_identity": _pid_identity(pid),
        "model": model, "task": task, "conversation_id": int(conversation_id),
        "log_path": log_path, "log_offset": log_offset, "log_identity": log_identity,
        "verify": verify, "marker": marker, "repo": repo, "commit": bool(commit),
        "git_baseline": _git_changed_paths(repo),
        "deadline": (_now() + int(timeout)) if int(timeout) > 0 else None,
        "status": "running", "started_at": time.strftime("%Y-%m-%d %H:%M:%S"),
        "finished_at": None, "reported_at": None,
        "run_id": run_id or _next_run_id(),
        "task_id": task_id if task_id is not None else _task_id(task),
        "contract_revision": int(contract_revision or 0),
        "authority_level": _normalize_authority_level(authority_level),
        "state": state or "RUNNING",
        "stage_attempts": {},
        "base_sha": _git_sha(repo),
        "current_sha": _git_sha(repo),
        "human_decisions": list(human_decisions or []),
    }
    with _jobs_lock():
        state = _jobs_state_unlocked()
        _prune_jobs_state(state)
        state["jobs"][job_id] = job
        _save_jobs_state_unlocked(state)
    log(f"job {job_id} tracked: pid={pid} model={model} task={task!r}")
    return job_id


def _drain(proc: subprocess.Popen) -> None:
    """Drain a child's stdout so it never blocks on a full pipe when no log is set."""
    try:
        while True:
            chunk = proc.stdout.read(65536) if proc.stdout else None
            if not chunk:
                break
    except Exception:  # noqa: BLE001
        pass


def launch_job(*, model: str, task: str, conversation_id: int, command,
               log_path: str | None = None, verify: str | None = None,
               marker: str | None = None, repo: str | None = None,
               commit: bool = False, quiet: bool = False, timeout: int = 0,
               cwd: str | None = None, open_terminal: bool = True,
               run_id: str | None = None, task_id: str | None = None,
               contract_revision: int = 0, state: str | None = None,
               human_decisions: list | None = None, authority_level: str | None = None):
    """Spawn a delegated model command AND track it in one atomic step.

    The pid is captured directly from the spawned process (never pgrep), and its
    start-time identity is recorded so the monitor can distinguish real completion
    from PID reuse. A start confirmation is sent to the owner conversation so they
    know the delegation was received and is being monitored. Returns (job_id, proc).
    """
    logf = None
    if log_path:
        Path(log_path).parent.mkdir(parents=True, exist_ok=True)
        logf = open(log_path, "a", encoding="utf-8", buffering=1)  # line-buffered
    shell = isinstance(command, str)
    proc = subprocess.Popen(command if shell else list(command), shell=shell,
                            stdout=logf or subprocess.PIPE, stderr=subprocess.STDOUT,
                            text=True, start_new_session=True, cwd=cwd)
    if logf is None:
        threading.Thread(target=_drain, args=(proc,), daemon=True).start()
    try:
        job_id = track_job(pid=proc.pid, model=model, task=task,
                           conversation_id=conversation_id, log_path=log_path,
                           verify=verify, marker=marker, repo=repo,
                           commit=commit, timeout=timeout, run_id=run_id,
                           task_id=task_id, contract_revision=contract_revision,
                           state=state, human_decisions=human_decisions,
                           authority_level=authority_level)
    except Exception:
        try:
            os.killpg(proc.pid, signal.SIGKILL)
        except OSError:
            proc.kill()
        raise
    if not quiet:
        try:
            harpp_client.harpp_notify(
                conversation_id=int(conversation_id), message_type="PROGRESS",
                body=f"🔧 Delegated to {model}: {task} — monitoring started (job {job_id}). "
                     f"You'll be auto-notified when it's done.")
            log(f"job {job_id} start confirmation sent to conversation {conversation_id}")
        except Exception as e:  # noqa: BLE001
            log(f"job {job_id} start confirmation failed: {e}")
    if open_terminal and log_path:
        open_agent_terminal(proc.pid, log_path, f"HARPP job ({model})")
    return job_id, proc


def list_jobs() -> list:
    return sorted(jobs_state().get("jobs", {}).values(), key=lambda j: j.get("started_at", ""))


def untrack_job(job_id: str) -> bool:
    with _jobs_lock():
        state = _jobs_state_unlocked()
        if job_id not in state.get("jobs", {}):
            return False
        del state["jobs"][job_id]
        state["reported"] = [jid for jid in state["reported"] if jid != job_id]
        _save_jobs_state_unlocked(state)
    log(f"job {job_id} untracked")
    return True


def _pid_zombie(pid: int) -> bool:
    """True if the pid is a zombie (finished but not yet reaped). A zombie is effectively dead."""
    try:
        raw = Path(f"/proc/{pid}/stat").read_text(encoding="utf-8")
        after_comm = raw[raw.rfind(")") + 2:].split()
        return bool(after_comm) and after_comm[0] == "Z"
    except (OSError, IndexError):
        return False


def _pid_alive(pid: int, identity: str | None = None) -> bool:
    if not pid or pid < 1:
        return False
    try:
        os.kill(pid, 0)
        if _pid_zombie(pid):
            return False  # finished, awaiting reap — treat as dead for monitoring
        if identity is not None:
            current = _pid_identity(pid)
            return current is not None and current == identity
        return True
    except ProcessLookupError:
        return False
    except PermissionError:
        return True  # exists but owned by another user -> still working
    except (OverflowError, ValueError):
        return False


def _log_tail(path: str | None, limit: int = 40) -> str:
    if not path:
        return ""
    try:
        p = Path(path)
        if not p.exists():
            return "(log file missing)"
        lines = p.read_text(encoding="utf-8", errors="replace").splitlines()
        return "\n".join(lines[-limit:])
    except Exception:  # noqa: BLE001
        return "(log unreadable)"


def _marker_found(job: dict) -> bool:
    """Accurate marker check over post-tracking log output.

    Agents routinely mention their marker (both PASS and FAIL) in intermediate
    thinking/tool output, so presence alone is misleading. For markers of the form
    `PREFIX status=STATUS`, the LAST occurrence is the agent's final stated status
    and is the only one that counts. Plain markers (no status value) fall back to
    presence after tracking.
    """
    marker = job.get("marker")
    path = job.get("log_path")
    if not marker or not path:
        return False
    m = re.match(r"^(?P<prefix>.+?)\s+status=(?P<expected>[A-Za-z0-9_]+)\s*$", marker)
    try:
        p = Path(path)
        stat = p.stat()
        identity = f"{stat.st_dev}:{stat.st_ino}"
        offset = job.get("log_offset")
        if offset is None or identity != job.get("log_identity") or stat.st_size < int(offset):
            offset = 0  # legacy state, rotation, or truncation: inspect the replacement log
        with p.open("rb") as stream:
            stream.seek(int(offset))
            data = stream.read().decode("utf-8", errors="replace")
        if m is None:
            return str(marker).casefold() in data.casefold()
        found = re.findall(re.escape(m.group("prefix")) + r"\s+status=([A-Za-z0-9_]+)", data)
        return bool(found) and found[-1] == m.group("expected")
    except Exception:  # noqa: BLE001
        return False


def _run_verify(verify: str, repo: str | None) -> tuple[bool, str]:
    """Run a trusted admin-supplied shell command. Returns (ok, output tail)."""
    try:
        proc = subprocess.run(verify, shell=True, cwd=repo or None,
                              stdout=subprocess.PIPE, stderr=subprocess.STDOUT,
                              text=True, timeout=JOB_VERIFY_TIMEOUT)
        tail = (proc.stdout or "")[-500:]
        return proc.returncode == 0, tail
    except subprocess.TimeoutExpired:
        return False, f"(verification timed out after {JOB_VERIFY_TIMEOUT}s)"
    except Exception as e:  # noqa: BLE001
        return False, f"(verification failed: {e})"


def _git_status(repo: str | None) -> str:
    if not repo:
        return ""
    paths = _git_changed_paths(repo)
    return f"{len(paths)} changed file(s) uncommitted in {repo}" if paths else "no uncommitted changes"


def _commit_job(job: dict) -> str:
    """Commit only paths that were clean when tracking began, then push."""
    repo = job.get("repo")
    if not repo:
        return "no repo configured for auto-commit"
    baseline = set(job.get("git_baseline") or [])
    paths = [path for path in _git_changed_paths(repo) if path not in baseline]
    if not paths:
        return "no job-isolated changes to commit (pre-existing changes left untouched)"
    try:
        subprocess.run(["git", "-C", repo, "add", "--", *paths], check=True, timeout=60)
        subprocess.run(["git", "-C", repo, "commit", "-q", "--only",
                        "-m", f"harpp-job({job.get('model', 'model')}): {job.get('task', 'work')}",
                        "--", *paths], check=True, timeout=60)
        subprocess.run(["git", "-C", repo, "push"], check=True, timeout=120)
        return f"committed + pushed {len(paths)} job-isolated path(s)"
    except subprocess.CalledProcessError as e:
        return f"commit attempt failed (exit {e.returncode}); pre-existing paths were not staged"
    except Exception as e:  # noqa: BLE001
        return f"commit attempt failed: {e}"


def _report_job(job_id: str, job: dict) -> str:
    """Build + deliver the completion report for a finished job. Returns the outcome."""
    conv = job.get("conversation_id")
    if not conv:
        raise ValueError("job has no conversation_id")
    tail = _log_tail(job.get("log_path"))
    marker = job.get("marker")
    checks = []
    evidence = []
    if marker:
        found = _marker_found(job)
        checks.append(found)
        evidence.append(f"marker {'FOUND' if found else 'NOT FOUND'}: {marker!r}")
    if job.get("verify"):
        vok, vout = _run_verify(job["verify"], job.get("repo"))
        checks.append(vok)
        evidence.append(f"verify exit={'OK' if vok else 'FAIL'} {sanitize_decision_text(vout)}")
    ok = bool(checks) and all(checks)
    if not checks:
        evidence.append("no marker or verification command configured")
    git = _git_status(job.get("repo"))
    commit = ""
    if ok and job.get("commit"):
        commit = _commit_job(job)
    status = "DONE" if ok else "FAILED"
    message_type = "PROGRESS" if ok else "FAILED"
    details = "; ".join(evidence)
    if commit:
        details = f"{details}; {commit}" if details else commit
    elif git:
        details = f"{details}; {git}" if details else git
    elif tail and not marker:
        details = f"{details}; log tail: {tail[-300:]}" if details else f"log tail: {tail[-300:]}"
    report = {
        "task": f"{job.get('task')} ({job.get('model')})",
        "state": "VERIFIED" if ok else "FAILED",
        "changed_files": _summary_changed_files(job.get("repo")),
        "next": "workflow monitor will advance automatically — safe to move forward" if ok else "inspect logs / remediate before proceeding",
        "details": details,
    }
    harpp_client.harpp_notify(conversation_id=int(conv), message_type=message_type,
                              body=summarize(report))
    return status


def sync_deploys() -> int:
    """Publish the deploy inventory and, if any deploy is queued, start the deploy
    worker once (on demand). The FTP worker process runs only when there is work, so
    the local machine is not continuously connected to the deploy host. Returns the
    number of pending deploys handed to the worker (0 when idle/absent)."""
    try:
        import harpp_deploy_worker as _dw
    except ImportError:
        return 0  # deploy tooling is not vendored into this distribution
    try:
        _dw.publish_inventory()
    except Exception as e:  # noqa: BLE001 - watch loop must never break
        log(f"deploy inventory publish failed: {e}")
    try:
        pending = harpp_client.api("GET", "/api/v1/harpp/bridge/deploys/pending?limit=10")
        jobs = pending.get("data", {}).get("deploys", []) if pending.get("ok") else []
    except Exception as e:  # noqa: BLE001
        log(f"deploy pending check failed: {e}")
        return 0
    if not jobs:
        return 0
    try:
        import sys
        worker = Path(__file__).resolve().parent / "harpp_deploy_worker.py"
        if not worker.is_file():
            log("deploy worker script missing; cannot run on-demand deploy")
            return 0
        subprocess.Popen([sys.executable, str(worker), "--once",
                          "--log", str(CONFIG_DIR / "deploy-worker.log")],
                         stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
                         start_new_session=True)
        log(f"started deploy worker for {len(jobs)} pending deploy(s)")
        return len(jobs)
    except Exception as e:  # noqa: BLE001
        log(f"could not start deploy worker: {e}")
        return 0


def monitor_jobs() -> int:
    """Claim dead jobs, verify and report once; return successful delivery count.

    Claims serialize concurrent watch threads/processes. Failed delivery is reset to running
    for retry. A stale claim from a crashed monitor is recovered on a later poll.
    """
    claimed = []
    claim_token = f"{os.getpid()}:{uuid.uuid4().hex}"
    try:
        with _jobs_lock():
            state = _jobs_state_unlocked()
            reported = set(state.get("reported", []))
            now = _now()
            for jid, job in state.get("jobs", {}).items():
                if jid in reported:
                    continue
                if job.get("status") == "reporting":
                    owner = int(job.get("reporter_pid") or 0)
                    age = now - int(job.get("report_started_ts") or 0)
                    if _pid_alive(owner) and age <= JOB_REPORT_STALE_AFTER:
                        continue
                    job["status"] = "running"
                if job.get("status") != "running":
                    continue
                deadline = job.get("deadline")
                if deadline and now > int(deadline):
                    try:
                        os.killpg(int(job.get("pid", 0)), signal.SIGKILL)
                        log(f"job {jid} exceeded its deadline; process group killed")
                    except (OSError, ProcessLookupError, ValueError):
                        try:
                            os.kill(int(job.get("pid", 0)), signal.SIGKILL)
                        except (OSError, ProcessLookupError, ValueError):
                            pass
                    # pid will be claimed as finished this or the next pass.
                if _pid_alive(int(job.get("pid", 0)), job.get("pid_identity")):
                    continue
                job["status"] = "reporting"
                job["state"] = "REPORTING"
                job["finished_at"] = job.get("finished_at") or time.strftime("%Y-%m-%d %H:%M:%S")
                job["current_sha"] = _git_sha(job.get("repo"))
                job["report_token"] = claim_token
                job["reporter_pid"] = os.getpid()
                job["report_started_ts"] = now
                claimed.append((jid, dict(job)))
            if claimed:
                _save_jobs_state_unlocked(state)
    except Exception as e:  # noqa: BLE001
        log(f"job monitor registry claim failed: {e}")
        return 0

    reported_count = 0
    for jid, job in claimed:
        try:
            outcome = _report_job(jid, job)
            with _jobs_lock():
                state = _jobs_state_unlocked()
                current = state["jobs"].get(jid)
                if not current or current.get("report_token") != claim_token:
                    raise RuntimeError("job claim changed before report could be recorded")
                current["status"] = "finished"
                current["outcome"] = outcome  # DONE / FAILED — lets workflows advance on real results
                current["state"] = outcome
                current["current_sha"] = _git_sha(current.get("repo"))
                current["reported_at"] = time.strftime("%Y-%m-%d %H:%M:%S")
                current.pop("report_token", None)
                current.pop("reporter_pid", None)
                current.pop("report_started_ts", None)
                if jid not in state["reported"]:
                    state["reported"].append(jid)
                _prune_jobs_state(state)
                _save_jobs_state_unlocked(state)
            reported_count += 1
            log(f"job {jid} reported to conversation {job.get('conversation_id')} ({outcome})")
        except Exception as e:  # noqa: BLE001
            log(f"job {jid} report delivery failed: {e}; will retry next pass")
            try:
                with _jobs_lock():
                    state = _jobs_state_unlocked()
                    current = state["jobs"].get(jid)
                    if current and current.get("report_token") == claim_token:
                        current["status"] = "running"
                        current["state"] = "RUNNING"
                        current["current_sha"] = _git_sha(current.get("repo"))
                        current.pop("report_token", None)
                        current.pop("reporter_pid", None)
                        current.pop("report_started_ts", None)
                        _save_jobs_state_unlocked(state)
            except Exception as reset_error:  # noqa: BLE001
                log(f"job {jid} retry-state save failed: {reset_error}")
    return reported_count


# ---------------------------------------------------------------------------
# Governed multi-stage workflows — run /architect -> /implement -> /review ->
# /release-gate as sequential tracked jobs. Each stage is its own `harpp job`
# (with start confirmation, live terminal, auto-verify + report); the engine
# advances to the next stage only when the previous one actually PASSED, and
# stops + notifies on FAIL. State persists in ~/.config/harpp/workflows.json.
# ---------------------------------------------------------------------------

def workflows_state() -> dict:
    with _jobs_lock():
        state = _load_json(WORKFLOWS_FILE, {})
        if not isinstance(state, dict):
            state = {}
        state.setdefault("workflows", {})
        state["workflows"] = {wid: _normalize_workflow(wf) for wid, wf in state["workflows"].items() if isinstance(wf, dict)}
        return state


def save_workflows_state(state: dict) -> None:
    with _jobs_lock():
        _save_json(WORKFLOWS_FILE, state)


def list_workflows() -> list:
    return sorted(workflows_state().get("workflows", {}).values(),
                  key=lambda w: w.get("created_at", ""))


def get_workflow(wid: str) -> dict | None:
    return workflows_state().get("workflows", {}).get(wid)


def resume_workflow(wid: str) -> dict | None:
    """Deterministically reconstruct a workflow from persisted state and re-land if needed."""
    with _jobs_lock():
        raw = _load_json(WORKFLOWS_FILE, {})
        if not isinstance(raw, dict):
            raw = {}
        raw.setdefault("workflows", {})
        wf = _normalize_workflow(raw["workflows"].get(wid)) if raw["workflows"].get(wid) else None
        if not wf:
            return None
        jobs = _jobs_state_unlocked().get("jobs", {})
        idx = int(wf.get("current_index", 0))
        stages = wf.get("stages", [])
        stage = stages[idx] if 0 <= idx < len(stages) else None
        reason = _budget_exhausted_reason(wf)
        needs_reland = False
        if stage and wf.get("status") == "running":
            job = jobs.get(stage.get("job_id")) if stage.get("job_id") else None
            if job and job.get("status") == "finished":
                stage["status"] = "done" if (job.get("outcome") == "DONE") else "failed"
            elif stage.get("status") in ("pending", "running") and (not stage.get("job_id") or job is None):
                wf["advancing"] = False
                wf["advancing_ts"] = 0
                needs_reland = not reason
                if reason:
                    _block_workflow(wf, stage, reason)
        _update_workflow_state(wf, stage_name=stage.get("name") if stage else None)
        raw["workflows"][wid] = wf
        _save_json(WORKFLOWS_FILE, raw)
    relaunched = _launch_stage_with_claim(wid, wf, stage, idx) if needs_reland and stage else False
    if relaunched:
        wf = get_workflow(wid) or wf
        idx = int(wf.get("current_index", 0))
        stage = wf.get("stages", [])[idx] if 0 <= idx < len(wf.get("stages", [])) else None
    return {
        "workflow_id": wid,
        "run_id": wf.get("run_id"),
        "task_id": wf.get("task_id"),
        "contract_revision": int(wf.get("contract_revision") or 0),
        "status": wf.get("status"),
        "state": wf.get("state"),
        "blocked_reason": wf.get("blocked_reason"),
        "current_stage": stage.get("name") if stage else None,
        "current_index": int(wf.get("current_index", 0)),
        "job_id": stage.get("job_id") if stage else None,
        "repair_count": int(wf.get("repair_count", 0)),
        "total_cycles": int(wf.get("total_cycles", 0)),
        "browser_repairs": int(wf.get("browser_repairs", 0)),
        "tool_retries": int(wf.get("tool_retries", 0)),
        "network_retries": int(wf.get("network_retries", 0)),
        "base_sha": wf.get("base_sha"),
        "current_sha": wf.get("current_sha"),
        "relanded": bool(relaunched),
        "stage_attempts": {s.get("name") or str(i): {"attempt_count": int(s.get("attempt_count", 0)),
                                                       "statuses": list(s.get("attempt_statuses") or [])}
                           for i, s in enumerate(wf.get("stages", []))},
    }


def _stage_prompt(stage: dict) -> str:
    if stage.get("prompt_file"):
        p = Path(__file__).resolve().parent / "workflows" / str(stage["prompt_file"])
        try:
            return p.read_text(encoding="utf-8")
        except Exception:  # noqa: BLE001
            pass
    return (stage.get("prompt")
            or f"You are stage '{stage.get('name')}' of a governed HARPP workflow. "
               f"Produce the required output and end with your stage marker.\n")


def _notify_enabled() -> bool:
    """Owner workflow notifications (messages + decisions) are on by default and
    are suppressed in testing/quiet mode so test workflows do not pollute live
    HARPP (the wf-* escalation test launches previously created real
    "Escalation required" decisions on the live host). The canonical toggle lives
    in harpp_client._notify_enabled (env HARPP_NOTIFY / HARPP_TESTING_MODE, or
    config harpp_notify / harpp_testing_mode). Workflow state still advances
    locally (escalated/blocked etc.); only the live message + decision are
    skipped and logged instead.
    """
    return harpp_client._notify_enabled()


def _notify_workflow(wf: dict, status: str, stage: dict | None = None,
                     round_no: int = 0, max_repairs: int = 0,
                     reason: str | None = None) -> None:
    try:
        if not _notify_enabled():
            log(f"workflow notify suppressed for {wf.get('id')} ({status}): testing/quiet mode")
            return
        conversation_id = int(wf.get("conversation_id") or 0)
        workflow_title = wf.get("title")
        workflow_id = wf.get("id")
        stage_name = stage.get("name") if stage else '?'
        job_id = stage.get("job_id") if stage else '?'
        report = _workflow_summary_report(wf, status, stage, round_no=round_no,
                                          max_repairs=max_repairs, reason=reason)
        headline = _summary_headline(report)
        if status == "DONE":
            final_stage = str(stage_name or "").strip().lower()
            if final_stage in ("release-gate", "release", "production-release"):
                body = (headline + "\n"
                        f"workflow: {workflow_title} ({workflow_id})\n"
                        f"stage: {stage_name}\n"
                        "what: decide whether to release to L4/production\n"
                        "why: the final release gate passed\n"
                        "options:\n"
                        "- approve release now\n"
                        "- hold release pending more validation\n"
                        "- reject release and request changes\n"
                        "recommendation: approve only if the release evidence is sufficient\n"
                        "risk: releasing without owner confirmation could ship incorrect or unsafe changes")
                decision = {
                    "title": f"Release ready: {workflow_title}",
                    "what": f"Decide whether to release workflow {workflow_id} to L4/production.",
                    "why": f"Final gate stage '{stage_name}' passed and the workflow is awaiting owner release approval.",
                    "options": [
                        "Approve release now.",
                        "Hold release pending more validation.",
                        "Reject release and request changes.",
                    ],
                    "recommendation": "Approve only if the release evidence is sufficient for production.",
                    "risk": "Releasing without owner confirmation could ship incorrect or unsafe changes.",
                    "context": f"workflow={workflow_id}\nstage={stage_name}\njob_id={job_id}",
                    "requested_decision": "Choose release: approve, hold, or reject.",
                    "priority": "high",
                    "workbench_state": "RELEASE_READY",
                    "decision_key": f"release-ready:{workflow_id}:{stage_name}",
                }
                harpp_client.harpp_notify(conversation_id=conversation_id, message_type="RELEASE_READY",
                                          body=body, decision=decision)
                return
            harpp_client.harpp_notify(conversation_id=conversation_id, message_type="INFO",
                                      body=summarize(report))
            return
        if status == "REPAIR":
            harpp_client.harpp_notify(conversation_id=conversation_id, message_type="PROGRESS",
                                      body=summarize(report))
            return
        if status == "DELEGATED":
            harpp_client.harpp_notify(conversation_id=conversation_id, message_type="PROGRESS",
                                      body=summarize(report))
            return
        if status == "BLOCKED":
            blocked_reason = reason or wf.get('blocked_reason') or 'budget exhausted'
            body = (headline + "\n"
                    f"workflow: {workflow_title} ({workflow_id})\n"
                    f"stage: {stage_name}\n"
                    f"what: unblock workflow at job {job_id}\n"
                    f"why: {blocked_reason}\n"
                    "options:\n"
                    "- adjust the budget/authority and restart\n"
                    "- reduce scope and retry within current limits\n"
                    "- stop the workflow permanently\n"
                    "recommendation: do not resume until the blocking reason is explicitly addressed\n"
                    "risk: continuing without intervention could loop, exceed limits, or violate governance")
            decision = {
                "title": f"Workflow blocked: {workflow_title}",
                "what": f"Unblock workflow {workflow_id} at stage '{stage_name}'.",
                "why": blocked_reason,
                "options": [
                    "Adjust the budget/authority and restart.",
                    "Reduce scope and retry within current limits.",
                    "Stop the workflow permanently.",
                ],
                "recommendation": "Do not resume until the blocking reason is explicitly addressed.",
                "risk": "Continuing without intervention could loop, exceed limits, or violate governance.",
                "context": f"workflow={workflow_id}\nstage={stage_name}\njob_id={job_id}",
                "requested_decision": "Choose how to unblock or stop the workflow.",
                "priority": "high",
                "workbench_state": "BLOCKED",
                "decision_key": f"blocked:{workflow_id}:{stage_name}:{blocked_reason}",
            }
            harpp_client.harpp_notify(conversation_id=conversation_id, message_type="BLOCKED",
                                      body=body, decision=decision)
            return
        if status == "ESCALATED":
            required = _stage_required_authority(stage)
            escalation_reason = reason or wf.get('escalation_reason') or 'escalation required'
            body = (headline + "\n"
                    f"workflow: {workflow_title} ({workflow_id})\n"
                    f"stage: {stage_name}\n"
                    f"authority: configured={wf.get('authority_level')} required={required}\n"
                    "what: review and approve an escalation before this workflow can continue\n"
                    f"why: {escalation_reason}\n"
                    "options:\n"
                    "- approve a revised contract/authority and restart\n"
                    "- narrow scope to stay within the current contract\n"
                    "- stop the workflow\n"
                    "recommendation: require explicit owner approval before any further action\n"
                    "risk: architecture/contract, safety, security, or scope governance could be violated")
            decision = {
                "title": f"Escalation required: {workflow_title}",
                "what": f"Approve or reject workflow escalation for {workflow_id} at stage '{stage_name}'.",
                "why": escalation_reason,
                "options": [
                    "Approve a revised contract/authority and restart.",
                    "Narrow scope to stay within the current contract.",
                    "Stop the workflow.",
                ],
                "recommendation": "Require explicit owner approval before any further action.",
                "risk": "Architecture/contract, safety, security, or scope governance could be violated.",
                "context": f"workflow={workflow_id}\nstage={stage_name}\nauthority_configured={wf.get('authority_level')}\nauthority_required={required}\njob_id={job_id}",
                "requested_decision": "Choose approval, scope reduction, or stop.",
                "priority": "high",
                "workbench_state": "DECISION_REQUIRED",
                "decision_key": f"escalation:{workflow_id}:{stage_name}",
            }
            harpp_client.harpp_notify(conversation_id=conversation_id, message_type="DECISION_REQUIRED",
                                      body=body, decision=decision)
            return
        harpp_client.harpp_notify(conversation_id=conversation_id, message_type="FAILED",
                                  body=summarize(report))
    except Exception as e:  # noqa: BLE001
        log(f"workflow notify failed for {wf.get('id')}: {e}")


def _update_workflow_state(wf: dict, *, stage_name: str | None = None, repairing: bool = False) -> None:
    wf["current_sha"] = _git_sha(wf.get("workspace"))
    wf["updated_at"] = time.strftime("%Y-%m-%d %H:%M:%S")
    wf["state"] = _workflow_stage_state(stage_name, wf.get("status"), repairing)


def _record_stage_attempt(stage: dict, status: str, *, job_id: str | None = None, run_id: str | None = None) -> None:
    stage.setdefault("attempt_count", 0)
    if status == "running":
        stage["attempt_count"] += 1
        stage["last_started_at"] = time.strftime("%Y-%m-%d %H:%M:%S")
    if status in ("done", "failed", "blocked", "escalated"):
        stage["last_finished_at"] = time.strftime("%Y-%m-%d %H:%M:%S")
    stage["last_run_id"] = run_id or stage.get("last_run_id")
    stage.setdefault("attempt_statuses", [])
    stage["attempt_statuses"].append({
        "attempt": int(stage.get("attempt_count") or 0),
        "status": status,
        "job_id": job_id,
        "run_id": run_id,
        "at": time.strftime("%Y-%m-%d %H:%M:%S"),
    })


def _block_workflow(wf: dict, stage: dict | None, reason: str) -> None:
    wf["status"] = "blocked"
    wf["blocked_reason"] = f"BLOCKED_BUDGET_EXHAUSTED: {reason}"
    if stage:
        stage["status"] = "blocked"
        _record_stage_attempt(stage, "blocked", job_id=stage.get("job_id"), run_id=stage.get("last_run_id"))
    _update_workflow_state(wf, stage_name=stage.get("name") if stage else None)
    _notify_workflow(wf, "BLOCKED", stage, reason=wf["blocked_reason"])


def _escalate_workflow(wf: dict, stage: dict | None, reason: str, *, required_authority: str | None = None) -> None:
    wf["status"] = "escalated"
    wf["blocked_reason"] = None
    wf["escalation_reason"] = reason
    if stage:
        stage["status"] = "escalated"
        _record_stage_attempt(stage, "escalated", job_id=stage.get("job_id"), run_id=stage.get("last_run_id"))
    _update_workflow_state(wf, stage_name=stage.get("name") if stage else None)
    _notify_workflow(wf, "ESCALATED", stage, reason=reason or wf.get("escalation_reason"))


def _budget_exhausted_reason(wf: dict) -> str | None:
    checks = (
        ("total_cycles", "max_total_cycles", int(wf.get("total_cycles", 0)) >= int(wf.get("max_total_cycles", 0))),
        ("browser_repairs", "max_browser_repairs", int(wf.get("browser_repairs", 0)) >= int(wf.get("max_browser_repairs", 0))),
        ("tool_retries", "max_tool_retries", int(wf.get("tool_retries", 0)) >= int(wf.get("max_tool_retries", 0))),
        ("network_retries", "max_network_retries", int(wf.get("network_retries", 0)) >= int(wf.get("max_network_retries", 0))),
    )
    for used_key, max_key, exhausted in checks:
        if exhausted:
            return f"{used_key}={int(wf.get(used_key, 0))} reached {max_key}={int(wf.get(max_key, 0))}"
    return None


def _launch_stage(wid: str, stage: dict, index: int, workflow: dict,
                  remediation: str | None = None, repair_round: int = 0,
                  max_repairs: int = 0,
                  delegation_note: str | None = None) -> None:
    """Launch a workflow stage as a tracked job and record its job id on the stage."""
    reason = _budget_exhausted_reason(workflow)
    if reason:
        raise RuntimeError(reason)
    prompt = _stage_prompt(stage)
    prompt = (prompt.replace("{{WORKSPACE}}", workflow.get("workspace") or "(no workspace)")
                   .replace("{{TITLE}}", workflow.get("title") or ""))
    if index > 0:
        prev = workflow.get("stages", [])[index - 1]
        prompt = prompt.replace("{{PREV_OUTPUT}}", str(prev.get("output_path") or prev.get("job_id") or ""))
    if remediation:
        prompt += ("\n\n# AUTO-REPAIR CONTEXT (round {}/{})\n"
                   "This is an auto-repair run: the previous stage reported a failure. "
                   "Fix the issues below and re-verify; do NOT redo unrelated work. "
                   "If the issues cannot be resolved, say so explicitly and end with the FAIL marker.\n"
                   "Previous stage log (last lines):\n```\n{}\n```\n").format(
                       repair_round, max_repairs, (remediation or "")[-1500:])
    if delegation_note:
        prompt += ("\n\n# MODEL DELEGATION CONTEXT\n"
                   f"{delegation_note}\n"
                   "This is a delegation run: perform the SAME stage task as before. "
                   "If it cannot be completed, end with the stage's FAIL marker as usual.\n")
    label = stage.get('name')
    if delegation_note:
        label = f"{label} (DELEGATED {stage.get('model')})"
    elif repair_round:
        label = f"{label} (REPAIR {repair_round}/{max_repairs})"
    task = f"workflow {wid} stage {index + 1}/{len(workflow.get('stages', []))}: {label}"
    cmd = ["pi", "--model", str(stage.get("model")), "--mode", "json", "--print", prompt]
    log_path = str(CONFIG_DIR / f"wf-{wid}-s{index}.log")
    jid, _proc = launch_job(
        model=str(stage.get("model")), task=task, conversation_id=int(workflow["conversation_id"]),
        command=cmd, log_path=log_path, verify=stage.get("verify"), marker=stage.get("marker"),
        repo=workflow.get("workspace"), commit=bool(stage.get("commit")),
        timeout=int(stage.get("timeout") or 1800), cwd=workflow.get("workspace"),
        open_terminal=True, task_id=workflow.get("task_id"), contract_revision=int(workflow.get("contract_revision") or 0),
        state=_workflow_stage_state(stage.get("name"), workflow.get("status"), bool(repair_round)),
        human_decisions=list(workflow.get("human_decisions") or []),
        authority_level=_stage_required_authority(stage))
    stage["job_id"] = jid
    stage["log_path"] = log_path
    stage["status"] = "running"
    stage["last_run_id"] = jid
    workflow["total_cycles"] = int(workflow.get("total_cycles", 0)) + 1
    _record_stage_attempt(stage, "running", job_id=jid, run_id=jid)
    _update_workflow_state(workflow, stage_name=stage.get("name"), repairing=bool(repair_round))
    log(f"workflow {wid} launched stage {index + 1} '{label}' as job {jid}")


def start_workflow(*, title: str, conversation_id: int, stages: list,
                  workspace: str | None = None, max_repairs: int = 3,
                  max_total_cycles: int | None = None,
                  max_browser_repairs: int | None = None,
                  max_tool_retries: int | None = None,
                  max_network_retries: int | None = None,
                  contract_revision: int = 0,
                  authority_level: str | None = None,
                  authority_policy: dict | None = None) -> str:
    """Register a multi-stage workflow and launch its first stage. Returns workflow id.

    max_repairs bounds the auto-repair loop (review FAIL -> re-run implement -> review)
    so a failing pipeline always terminates: after max_repairs rounds it stops as FAILED.
    Set 0 to disable auto-repair (fail-closed on the first failure).
    """
    if not str(title).strip() or int(conversation_id) < 1 or not stages:
        raise ValueError("title, conversation_id and at least one stage are required")
    wid = uuid.uuid4().hex[:12]
    budgets = _workflow_budget_defaults(max_repairs)
    if max_total_cycles is not None:
        budgets["max_total_cycles"] = max(0, int(max_total_cycles))
    if max_browser_repairs is not None:
        budgets["max_browser_repairs"] = max(0, int(max_browser_repairs))
    if max_tool_retries is not None:
        budgets["max_tool_retries"] = max(0, int(max_tool_retries))
    if max_network_retries is not None:
        budgets["max_network_retries"] = max(0, int(max_network_retries))
    wf = _normalize_workflow({
        "id": wid, "title": str(title).strip(), "conversation_id": int(conversation_id),
        "workspace": workspace or default_workspace(),
        "stages": [dict(s) for s in stages],
        "current_index": 0, "status": "running",
        "repair_count": 0, "total_cycles": 0, "browser_repairs": 0,
        "tool_retries": 0, "network_retries": 0, "model_fallbacks": 0,
        "advancing": False, "advancing_ts": 0,
        "created_at": time.strftime("%Y-%m-%d %H:%M:%S"),
        "updated_at": time.strftime("%Y-%m-%d %H:%M:%S"),
        "run_id": _next_run_id(),
        "task_id": _task_id(title),
        "contract_revision": int(contract_revision or 0),
        "base_sha": _git_sha(workspace or default_workspace()),
        "current_sha": _git_sha(workspace or default_workspace()),
        "human_decisions": [],
        **({"authority_level": authority_level} if authority_level is not None else {}),
        **({"authority_policy": authority_policy} if authority_policy is not None else {}),
        **budgets,
    })
    with _jobs_lock():
        state = _load_json(WORKFLOWS_FILE, {})
        if not isinstance(state, dict):
            state = {}
        state.setdefault("workflows", {})
        state["workflows"][wid] = wf
        _save_json(WORKFLOWS_FILE, state)
    first_stage = wf["stages"][0]
    prelaunch_reason = _override_escalation_reason(first_stage) or _authority_escalation_reason(wf, first_stage)
    if prelaunch_reason:
        _escalate_workflow(wf, first_stage, prelaunch_reason)
        with _jobs_lock():
            state = _load_json(WORKFLOWS_FILE, {})
            state["workflows"][wid] = wf
            _save_json(WORKFLOWS_FILE, state)
        log(f"workflow {wid} escalated before first stage launch: {prelaunch_reason}")
        return wid
    try:
        _launch_stage(wid, first_stage, 0, wf)
    except Exception as e:  # noqa: BLE001
        wf["tool_retries"] = int(wf.get("tool_retries", 0)) + 1
        reason = _budget_exhausted_reason(wf)
        if reason:
            _block_workflow(wf, wf["stages"][0], reason)
        else:
            wf["status"] = "failed"
            wf["stages"][0]["status"] = "failed"
            _record_stage_attempt(wf["stages"][0], "failed", job_id=wf["stages"][0].get("job_id"))
            _update_workflow_state(wf, stage_name=wf["stages"][0].get("name"))
            _notify_workflow(wf, "FAILED", wf["stages"][0], reason=str(e))
        with _jobs_lock():
            state = _load_json(WORKFLOWS_FILE, {})
            state["workflows"][wid] = wf
            _save_json(WORKFLOWS_FILE, state)
        raise RuntimeError(f"workflow first stage failed to launch: {e}") from e
    # Persist the launched first-stage job id + status so the monitor can advance it.
    with _jobs_lock():
        state = _load_json(WORKFLOWS_FILE, {})
        state["workflows"][wid] = wf
        _save_json(WORKFLOWS_FILE, state)
    log(f"workflow {wid} started: {wf['title']} ({len(wf['stages'])} stages)")
    return wid


def _repair_target_index(wf: dict, failed_idx: int) -> int:
    """Index of the stage to re-run on failure — the 'implement' producer if present, else the stage itself."""
    for i, s in enumerate(wf.get("stages", [])):
        if str(s.get("name", "")).lower() == "implement":
            return i
    return failed_idx


def _remediation_from(stage: dict) -> str:
    """Tail of a failed stage's log — carries the reviewer's FAIL rationale/remediation."""
    p = stage.get("log_path")
    if not p:
        return ""
    try:
        pp = Path(p)
        return pp.read_text(encoding="utf-8", errors="replace")[-1500:] if pp.exists() else ""
    except Exception:  # noqa: BLE001
        return ""


_MODEL_EXHAUSTION_PATTERNS = (
    re.compile(r"(token|usage).{0,40}(exhaust|exceed|limit|quota|deplet|insufficient|ceiling)"),
    re.compile(r"(exhaust|exceed|limit|quota|deplet|insufficient).{0,40}(token|usage)"),
    re.compile(r"(quota|rate\s*limit).{0,40}(exceed|reached|hit|limit)"),
    re.compile(r"(insufficient|low|empty|exhausted|out of|zero).{0,20}balance"),
    re.compile(r"balance.{0,20}(insufficient|low|empty|exhausted|zero)"),
    re.compile(r"rate\s*limit|too\s+many\s+requests|\b429\b|\b402\b"),
    re.compile(r"context\s+length\s+exceeded"),
)


def _job_log_text(job: dict, limit: int = 20000) -> str:
    path = job.get("log_path")
    if not path:
        return ""
    try:
        return Path(path).read_text(encoding="utf-8", errors="replace")[-limit:]
    except Exception:  # noqa: BLE001
        return ""


def _model_exhausted(job: dict) -> bool:
    """True when a failed job looks like the model's token/usage/quota/balance was exhausted.

    This is the signal the workflow engine uses to delegate the stage to a different
    model rather than re-running the same exhausted model in an auto-repair round.
    """
    text = " ".join([
        str(job.get("reason") or ""),
        str(job.get("message") or ""),
        str(job.get("code") or ""),
        _job_log_text(job),
    ]).lower()
    if not text:
        return False
    return any(p.search(text) for p in _MODEL_EXHAUSTION_PATTERNS)


def _delegate_stage_model(stage: dict) -> str | None:
    """Move a failed stage to the next untried fallback model.

    Returns the newly delegated model, or None when every available model has already
    been tried (the caller must then block/fail instead of looping). Mutates the stage
    so the persisted workflow records exactly which models were attempted.
    """
    tried = [str(m) for m in (stage.get("tried_models") or [])]
    current = str(stage.get("model") or "")
    if current and current not in tried:
        tried.append(current)
    for model in MODEL_FALLBACK_ORDER:
        if model not in tried:
            tried.append(model)
            stage["tried_models"] = tried
            stage["model"] = model
            stage["job_id"] = None
            stage["status"] = "pending"
            return model
    stage["tried_models"] = tried
    return None


def _launch_stage_with_claim(wid: str, wf: dict, stage: dict, index: int, *,
                             remediation: str | None = None, repair_round: int = 0,
                             max_repairs: int = 0,
                             delegation_note: str | None = None) -> bool:
    """Launch a stage under a persisted 'advancing' claim so concurrent passes can't double-launch."""
    escalation = _override_escalation_reason(stage) or _authority_escalation_reason(wf, stage)
    if escalation:
        _escalate_workflow(wf, stage, escalation)
        return False
    now = _now()
    with _jobs_lock():
        state = _load_json(WORKFLOWS_FILE, {})
        cur = state.get("workflows", {}).get(wid)
        if not cur or cur.get("status") != "running":
            return False
        cur["advancing"] = True
        cur["advancing_ts"] = now
        _save_json(WORKFLOWS_FILE, state)
    ok = False
    try:
        _launch_stage(wid, stage, index, wf, remediation=remediation,
                      repair_round=repair_round, max_repairs=max_repairs,
                      delegation_note=delegation_note)
        ok = True
        return True
    except Exception as exc:
        wf["tool_retries"] = int(wf.get("tool_retries", 0)) + 1
        reason = _budget_exhausted_reason(wf)
        if reason:
            _block_workflow(wf, stage, reason)
        else:
            wf["status"] = "failed"
            stage["status"] = "failed"
            _record_stage_attempt(stage, "failed", job_id=stage.get("job_id"), run_id=stage.get("last_run_id"))
            _update_workflow_state(wf, stage_name=stage.get("name"), repairing=bool(repair_round))
            _notify_workflow(wf, "FAILED", stage, reason=str(exc))
        return False
    finally:
        with _jobs_lock():
            state = _load_json(WORKFLOWS_FILE, {})
            c2 = _normalize_workflow(state.get("workflows", {}).get(wid) or wf)
            if c2:
                c2.update(wf)
                c2["advancing"] = False
                c2["advancing_ts"] = 0
                # Persist the just-launched job id atomically (closes the double-launch window).
                try:
                    c2["stages"][index] = stage
                    c2["current_index"] = index
                except Exception:  # noqa: BLE001
                    pass
                state.setdefault("workflows", {})
                state["workflows"][wid] = c2
                _save_json(WORKFLOWS_FILE, state)


def advance_workflows() -> int:
    """Advance running workflows whose current stage job finished. Returns count advanced/finalized.

    Failures trigger a BOUNDED auto-repair: the implement stage is re-run with the failed
    stage's remediation (e.g. review FAIL -> repair implement -> review again) up to
    max_repairs rounds; past that the workflow terminates as BLOCKED_BUDGET_EXHAUSTED.
    A persisted advancing claim prevents concurrent passes from double-launching.
    """
    try:
        with _jobs_lock():
            raw_wstate = _load_json(WORKFLOWS_FILE, {})
            if not isinstance(raw_wstate, dict):
                raw_wstate = {}
            raw_wstate.setdefault("workflows", {})
            raw_wstate["workflows"] = {wid: _normalize_workflow(wf) for wid, wf in raw_wstate["workflows"].items() if isinstance(wf, dict)}
            wstate = raw_wstate
            jstate = _jobs_state_unlocked()
        wf_map = wstate.get("workflows", {}) if isinstance(wstate, dict) else {}
        jobs = jstate.get("jobs", {})
        advanced = 0
        now = _now()
        dirty = False
        for wid, wf in list(wf_map.items()):
            if wf.get("status") != "running":
                continue
            stages = wf.get("stages", [])
            idx = int(wf.get("current_index", 0))
            stage = stages[idx] if 0 <= idx < len(stages) else None
            reason = _budget_exhausted_reason(wf)
            if reason:
                _block_workflow(wf, stage, reason)
                advanced += 1
                dirty = True
                continue
            if wf.get("advancing"):
                if now - int(wf.get("advancing_ts") or 0) < 60:
                    continue  # another pass is mid-launch
                wf["advancing"] = False  # stale claim reclaim
                dirty = True
            if idx >= len(stages):
                wf["status"] = "done"
                _update_workflow_state(wf)
                advanced += 1
                dirty = True
                continue
            stage = stages[idx]
            job = jobs.get(stage.get("job_id")) if stage.get("job_id") else None
            if stage.get("status") in ("pending", "running") and (not stage.get("job_id") or job is None):
                if _launch_stage_with_claim(wid, wf, stage, idx):
                    advanced += 1
                dirty = True
                continue
            if not job or job.get("status") != "finished":
                continue  # stage still running
            outcome = job.get("outcome") or "FAILED"
            stage["job_id"] = job.get("id")
            stage["last_run_id"] = job.get("run_id") or stage.get("last_run_id")
            escalation_reason = None
            if str(job.get("code") or "") == "escalation_required":
                escalation_reason = str(job.get("reason") or job.get("message") or "stage reported escalation_required")
            else:
                escalation_reason = _override_escalation_reason(job) or _override_escalation_reason(stage)
            if escalation_reason:
                _escalate_workflow(wf, stage, escalation_reason)
                advanced += 1
            elif outcome == "DONE":
                stage["status"] = "done"
                _record_stage_attempt(stage, "done", job_id=job.get("id"), run_id=job.get("run_id"))
                if idx >= len(stages) - 1:
                    wf["status"] = "done"
                    _update_workflow_state(wf, stage_name=stage.get("name"))
                    _notify_workflow(wf, "DONE", stage)
                    advanced += 1
                else:
                    nxt = idx + 1
                    wf["current_index"] = nxt
                    _update_workflow_state(wf, stage_name=stages[nxt].get("name"))
                    _launch_stage_with_claim(wid, wf, stages[nxt], nxt)
                    advanced += 1
            else:
                stage["status"] = "failed"
                _record_stage_attempt(stage, "failed", job_id=job.get("id"), run_id=job.get("run_id"))
                repairs = int(wf.get("repair_count", 0))
                max_repairs = int(wf.get("max_repairs", 0))
                if _model_exhausted(job):
                    # Token/usage/quota/balance exhausted: delegate the SAME stage to a
                    # different model instead of burning an auto-repair round on a model
                    # that cannot run. Still bounded: at most MODEL_FALLBACK_ORDER models
                    # and the global max_total_cycles budget.
                    reason = _budget_exhausted_reason(wf)
                    delegated = None if reason else _delegate_stage_model(stage)
                    if delegated:
                        wf["model_fallbacks"] = int(wf.get("model_fallbacks", 0)) + 1
                        wf["current_index"] = idx
                        note = (f"The previous run of stage '{stage.get('name')}' failed because the "
                                "configured model's token/usage/quota was exhausted. This run is "
                                f"delegated to a different model ({delegated}).")
                        _launch_stage_with_claim(wid, wf, stage, idx, delegation_note=note)
                        _notify_workflow(wf, "DELEGATED", stage, reason=f"delegated to {delegated}")
                    else:
                        _block_workflow(wf, stage, reason or f"all available models exhausted for stage '{stage.get('name')}'")
                    advanced += 1
                elif max_repairs > 0 and repairs < max_repairs:
                    wf["repair_count"] = repairs + 1
                    repair_idx = _repair_target_index(wf, idx)
                    target = stages[repair_idx]
                    target["status"] = "pending"
                    target["job_id"] = None
                    if "browser" in str(stage.get("name") or "").lower() or "browser" in str(target.get("name") or "").lower():
                        wf["browser_repairs"] = int(wf.get("browser_repairs", 0)) + 1
                    reason = _budget_exhausted_reason(wf)
                    if reason:
                        _block_workflow(wf, stage, reason)
                    else:
                        wf["current_index"] = repair_idx
                        remediation = _remediation_from(stage)
                        _launch_stage_with_claim(wid, wf, target, repair_idx,
                                                 remediation=remediation, repair_round=repairs + 1,
                                                 max_repairs=max_repairs)
                        _notify_workflow(wf, "REPAIR", stage, round_no=repairs + 1, max_repairs=max_repairs)
                    advanced += 1
                elif max_repairs > 0:
                    _block_workflow(wf, stage, f"repair_count={repairs} reached max_repairs={max_repairs}")
                    advanced += 1
                else:
                    wf["status"] = "failed"
                    _update_workflow_state(wf, stage_name=stage.get("name"))
                    _notify_workflow(wf, "FAILED", stage)
                    advanced += 1
            if wf.get("status") != "escalated":
                _update_workflow_state(wf, stage_name=stage.get("name"))
            dirty = True
        if dirty:
            with _jobs_lock():
                _save_json(WORKFLOWS_FILE, wstate)
        return advanced
    except Exception as e:  # noqa: BLE001
        log(f"workflow advance failed: {e}")
        return 0


# ---------------------------------------------------------------------------
# Deterministic workflow commands from the messenger — the owner drives
# multi-stage work directly from HARPP (no VS Code, no LLM needed). The watch
# daemon routes owner messages that are workflow commands through here.
# ---------------------------------------------------------------------------
# natural alias -> (manifest file under tools/harpp-bridge/workflows/, default stage workspace)
WORKFLOW_COMMANDS = {
    "governed-loop": ("governed-loop.json", None),
    "governed loop": ("governed-loop.json", None),
    "the loop": ("governed-loop.json", None),
    "default": ("governed-loop.json", None),
    "standalone-harpp-loop": ("standalone-harpp-loop.json", "/var/www/html/harpp"),
    "standalone harpp loop": ("standalone-harpp-loop.json", "/var/www/html/harpp"),
    "standalone": ("standalone-harpp-loop.json", "/var/www/html/harpp"),
    "harpp loop": ("standalone-harpp-loop.json", "/var/www/html/harpp"),
}


def _workflow_manifest_path(name: str) -> Path:
    return Path(__file__).resolve().parent / "workflows" / name


def parse_workflow_command(body) -> tuple | None:
    """Parse an owner message into a workflow command: ('list'|'show'|'start', args)."""
    if not body or not isinstance(body, str):
        return None
    low = " ".join(body.split()).lower().strip()
    if low.startswith("workflow list") or low.startswith("workflow status"):
        return ("list", {})
    m = re.match(r"^workflow show\s+([a-z0-9_\-]+)\s*$", low)
    if m:
        return ("show", {"id": m.group(1)})
    m = re.match(r"^workflow start\b(.*)$", low)
    if m:
        rest = m.group(1).strip()
        args = {}
        ws = re.search(r"--workspace\s+(\S+)", rest)
        if ws:
            args["workspace"] = ws.group(1)
        ti = re.search(r"--title\s+(.+)", rest)
        if ti:
            args["title"] = ti.group(1).strip()
        mr = re.search(r"--max-repairs\s+(\d+)", rest)
        if mr:
            args["max_repairs"] = int(mr.group(1))
        rest = re.sub(r"--workspace\s+\S+", "", rest)
        rest = re.sub(r"--title\s+.+", "", rest)
        rest = re.sub(r"--max-repairs\s+\d+", "", rest)
        args["name"] = " ".join(rest.split()).strip() or "governed-loop"
        return ("start", args)
    for alias in WORKFLOW_COMMANDS:
        if alias in low and any(w in low for w in ("run", "start")):
            return ("start", {"name": alias})
    return None


def _exec_workflow_command(cmd, conv: int) -> str:
    """Execute a parsed workflow command and return the owner-facing reply body."""
    kind = cmd[0]
    if kind in ("list", "status"):
        wfs = list_workflows()
        if not wfs:
            return "📋 No workflows running."
        lines = [f"📋 Workflows ({len(wfs)}):"]
        for w in wfs:
            total = len(w.get("stages", []))
            idx = int(w.get("current_index", 0))
            cur = w.get("stages", [])[idx].get("name", "?") if total else "?"
            lines.append(f"- {w.get('id')}: {w.get('status')} — stage {idx + 1}/{total} ({cur}) — {w.get('title')}")
        return "\n".join(lines)
    if kind == "show":
        w = get_workflow(cmd[1]["id"])
        if not w:
            return f"❓ Workflow {cmd[1]['id']} not found."
        return (f"🔍 Workflow {w['id']}: {w.get('title')}\nstatus: {w.get('status')}\n"
                + "\n".join(f"- {i + 1}. {s.get('name')} [{s.get('status')}] job {s.get('job_id')}"
                            for i, s in enumerate(w.get('stages', []))))
    if kind == "start":
        args = cmd[1]
        name = args.get("name", "governed-loop").lower()
        if name in WORKFLOW_COMMANDS:
            manifest_file, default_ws = WORKFLOW_COMMANDS[name]
        else:
            manifest_file = name if name.endswith(".json") else f"{name}.json"
            default_ws = None
        mpath = _workflow_manifest_path(manifest_file)
        try:
            manifest = json.loads(mpath.read_text(encoding="utf-8"))
        except Exception as exc:  # noqa: BLE001
            raise RuntimeError(f"workflow manifest not found or invalid ({mpath}): {exc}") from exc
        stages = manifest.get("stages") if isinstance(manifest, dict) else None
        if not isinstance(stages, list) or not stages:
            raise RuntimeError("workflow manifest has no stages")
        title = args.get("title") or (manifest.get("title") if isinstance(manifest, dict) else "") or name
        ws = args.get("workspace") or default_ws or default_workspace()
        max_repairs = args.get("max_repairs")
        if max_repairs is None and isinstance(manifest, dict):
            max_repairs = manifest.get("max_repairs")
        if max_repairs is None:
            max_repairs = 2
        wid = start_workflow(title=title, conversation_id=conv, stages=stages, workspace=ws,
                             max_repairs=int(max_repairs))
        names = ", ".join(s.get("name", "?") for s in stages)
        first = stages[0].get("name", "?")
        return (f"🔁 Workflow started: {wid}\ntitle: {title}\nstages ({len(stages)}): {names}\n"
                f"auto-repair: {max_repairs} round(s)\n"
                f"stage 1 ({first}) launched — each stage result will be auto-reported here.")
    return "❓ Unrecognized workflow command."


def route_workflow_commands(records) -> int:
    """Deterministically handle owner workflow commands in staged messages.

    Recognized commands are executed + replied to via the bridge and marked
    processed so the single-pass wake agent never double-handles them.
    Returns the number of commands handled. Never raises into the watch loop.
    """
    handled = 0
    for r in records or []:
        if r.get("kind") != "message":
            continue
        if str(r.get("sender_type") or "owner").lower() not in ("owner", "user"):
            continue
        cmd = parse_workflow_command(r.get("body"))
        if not cmd:
            continue
        conv = int(r.get("conversation_id") or 0)
        try:
            body = _exec_workflow_command(cmd, conv)
            if conv:
                harpp_client.harpp_notify(conversation_id=conv, message_type="INFO", body=body)
            log(f"routed workflow command '{cmd[0]}' for message {r.get('id')}")
        except Exception as e:  # noqa: BLE001
            log(f"workflow command failed for message {r.get('id')}: {e}")
            try:
                if conv:
                    harpp_client.harpp_notify(conversation_id=conv, message_type="WARNING",
                                              body=f"⚠️ Workflow command could not be processed: {e}")
            except Exception:  # noqa: BLE001
                pass
        mark_processed([{"kind": "message", "id": int(r.get("id", 0))}])
        handled += 1
    return handled


# ---------------------------------------------------------------------------
# Deterministic debate commands — the owner can start an architecture debate
# directly from HARPP ("Start debate …"). The daemon launches the debate as a
# tracked background job (tools/pi-arch-debate.py) and auto-reports the verdict;
# the single-pass wake agent never runs the debate inline.
# ---------------------------------------------------------------------------

def parse_debate_command(body) -> dict | None:
    """Parse an owner message into a debate launch request, or return None."""
    if not body or not isinstance(body, str):
        return None
    low = " ".join(body.split()).lower().strip()
    if "debate" not in low:
        return None
    if not re.search(r"\b(start|run|begin|launch|kick\s*off)\b", low):
        return None

    # Intent: prefer an explicit "Objective:" clause, else the remainder after "debate".
    intent = ""
    m = re.search(r"\bobjective\s*[:：]\s*(.+)", body, flags=re.IGNORECASE | re.DOTALL)
    if m:
        intent = m.group(1).strip()
    else:
        m = re.search(r"\bdebate\b(.*)", body, flags=re.IGNORECASE | re.DOTALL)
        if m:
            intent = m.group(1).strip()
    intent = re.sub(r"^[,:;.\s]+", "", intent)
    intent = re.sub(r"\b(?:max\s*depth|max\s*rounds|rounds|depth)\s*[:=]?\s*\d+\b", "",
                    intent, flags=re.IGNORECASE)
    intent = " ".join(intent.split())
    if not intent:
        return None

    rounds = None
    m = re.search(r"\b(?:max\s*depth|max\s*rounds|rounds|depth)\s*[:=]?\s*(\d+)", low)
    if m:
        rounds = max(1, min(int(m.group(1)), 10))

    # Opener: which model drafts first (the other critiques). Default AUTO (chair decides).
    first = "auto"
    if re.search(r"\b(?:gpt|codex|sol)\b[^.;]*\b(?:start|open|draft|begin|first)", low):
        first = "codex"
    elif re.search(r"\bdeepseek\b[^.;]*\b(?:start|open|draft|begin|first)", low):
        first = "deepseek"

    return {"intent": intent, "rounds": rounds, "first": first}


def _exec_debate_command(cmd: dict, conv: int) -> str:
    """Launch an architecture debate as a tracked background job and return the reply."""
    intent = str(cmd.get("intent") or "").strip()
    rounds = cmd.get("rounds")
    first = str(cmd.get("first") or "auto").lower()
    if first not in ("codex", "deepseek"):
        first = "auto"
    workspace = default_workspace()
    log_path = Path(workspace) / ".ai" / "debate" / f"debate-job-{int(time.time())}.log"
    argv = ["python3", "tools/pi-arch-debate.py", "--quiet"]
    if first != "auto":
        argv += ["--first", first]
    argv.append(intent)
    command = " ".join(shlex.quote(a) for a in argv)
    if rounds:
        command = f"DEBATE_MAX_ROUNDS={int(rounds)} {command}"
    jid, _proc = launch_job(
        model="arch-debate" + (f"/{first}" if first != "auto" else ""),
        task=f"Architecture debate: {intent[:90]}",
        conversation_id=conv,
        command=command,
        log_path=str(log_path),
        marker="verdict: APPROVED",
        cwd=workspace,
        open_terminal=False,
    )
    return (f"🧠 Architecture debate started (job {jid}).\n"
            f"intent: {intent}\n"
            f"rounds: {rounds or 'default (3)'}\n"
            f"opener: {first}\n"
            "It runs as a tracked job; I'll auto-report the verdict when it finishes.")


def route_debate_commands(records) -> int:
    """Deterministically handle owner debate requests in staged messages.

    Recognized requests launch tools/pi-arch-debate.py as a tracked job, are
    replied to via the bridge, and are marked processed so the single-pass wake
    agent never double-handles them. Returns the number handled.
    """
    handled = 0
    for r in records or []:
        if r.get("kind") != "message":
            continue
        if str(r.get("sender_type") or "owner").lower() not in ("owner", "user"):
            continue
        cmd = parse_debate_command(r.get("body"))
        if not cmd:
            continue
        conv = int(r.get("conversation_id") or 0)
        try:
            body = _exec_debate_command(cmd, conv)
            if conv:
                harpp_client.harpp_notify(conversation_id=conv, message_type="INFO", body=body)
            log(f"routed debate command for message {r.get('id')}")
        except Exception as e:  # noqa: BLE001
            log(f"debate command failed for message {r.get('id')}: {e}")
            try:
                if conv:
                    harpp_client.harpp_notify(conversation_id=conv, message_type="WARNING",
                                              body=f"⚠️ Debate request could not be processed: {e}")
            except Exception:  # noqa: BLE001
                pass
        mark_processed([{"kind": "message", "id": int(r.get("id", 0))}])
        handled += 1
    return handled


def task_prompt(inbox: str, items: list, template: str | None = None, workspace: str | None = None) -> str:
    """Build the single-pass agent prompt from the task-contract template + staged items."""
    default = Path(__file__).resolve().parent / "wake" / "task-contract.md"
    try:
        text = (template or default.read_text(encoding="utf-8"))
    except Exception:  # noqa: BLE001
        text = (
            "You are the HARPP wake agent. Process the staged owner input below by replying "
            "through the harness bridge. Then EXIT. Do not edit code or run tests unless asked.\n"
        )
    items_json = "\n".join(json.dumps(i, ensure_ascii=False) for i in items)
    return (text.replace("{{INBOX}}", inbox)
                .replace("{{ITEMS}}", items_json)
                .replace("{{DECISIONS}}", recent_decisions_text())
                .replace("{{WORKSPACE}}", workspace or "(no workspace configured)"))


def _stream_agent_output(proc, timeout: int, tee):
    """Stream a child's stdout, teeing live to `tee` (optional). Kills on timeout.

    Returns (output, timed_out). The reader thread keeps the terminal (and tee file)
    updated in real time while the main thread enforces the hard timeout.
    """
    chunks = []

    def _read():
        try:
            while True:
                chunk = proc.stdout.read(4096)
                if not chunk:
                    break
                chunks.append(chunk)
                if tee is not None:
                    try:
                        tee.write(chunk)
                        tee.flush()
                    except Exception:  # noqa: BLE001
                        pass
        except Exception:  # noqa: BLE001
            pass

    reader = threading.Thread(target=_read, daemon=True)
    reader.start()
    timed_out = False
    try:
        proc.wait(timeout=timeout)
    except subprocess.TimeoutExpired:
        timed_out = True
        try:
            os.killpg(proc.pid, signal.SIGKILL)
        except OSError:
            try:
                proc.kill()
            except OSError:
                pass
        try:
            proc.wait(timeout=10)
        except Exception:  # noqa: BLE001
            pass
    # A fast child can exit before the reader thread is scheduled. Always wait for
    # the pipe to drain before inspecting output; otherwise a valid completion
    # marker can be lost and the successful run is reported as failed.
    reader.join(timeout=OUTPUT_DRAIN_TIMEOUT)
    if reader.is_alive():
        log(f"wake agent output did not drain within {OUTPUT_DRAIN_TIMEOUT}s; closing pipe")
        try:
            proc.stdout.close()
        except Exception:  # noqa: BLE001
            pass
        reader.join(timeout=1)
    else:
        try:
            proc.stdout.close()
        except Exception:  # noqa: BLE001
            pass
    return "".join(chunks), timed_out


def spawn_agent(prompt: str, *, command: str | None, model: str, timeout: int,
                expected_replies: int | None = None, cwd: str | None = None,
                open_terminal: bool = False) -> bool:
    """Run the headless Pi agent once. Returns True on exit 0. Kills on timeout."""
    if command:
        cmd = command.replace("{model}", model).replace("{prompt}", prompt)
        proc = subprocess.Popen(cmd, shell=True, stdout=subprocess.PIPE, stderr=subprocess.STDOUT,
                                text=True, start_new_session=True, cwd=cwd)
    else:
        proc = subprocess.Popen(
            ["pi", "--model", model, "--mode", "json", "--print", prompt],
            stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True, start_new_session=True,
            cwd=cwd,
        )
    tee = None
    if open_terminal:
        try:
            CONFIG_DIR.mkdir(parents=True, exist_ok=True)
            tee = _open_capped_log(CONFIG_DIR / "wake-agent.log")
        except Exception as e:  # noqa: BLE001
            log(f"could not open wake-agent log for terminal tee: {e}")
        open_agent_terminal(proc.pid, str(CONFIG_DIR / "wake-agent.log"), f"HARPP wake ({model})")
    try:
        out, timed_out = _stream_agent_output(proc, timeout, tee)
    finally:
        if tee is not None:
            try:
                tee.close()
            except Exception:  # noqa: BLE001
                pass
    if timed_out:
        log(f"wake agent timed out after {timeout}s; killed")
        return False
    log(f"wake agent exit={proc.returncode} output_tail={out[-200:]!r}")
    if proc.returncode == 0 and not out.strip():
        log("wake agent returned empty output; treating run as failed")
        return False
    result = re.search(r"HARPP_WAKE_RESULT replies_sent=(\d+)", out)
    if proc.returncode == 0 and not result:
        log("wake agent output lacked a valid HARPP_WAKE_RESULT marker; treating delivery as failed")
        return False
    if proc.returncode == 0 and expected_replies is not None and int(result.group(1)) != expected_replies:
        log(f"wake agent reported {result.group(1)} replies; expected {expected_replies}; treating delivery as failed")
        return False
    return proc.returncode == 0


def maybe_wake(inbox: str, *, enabled: bool = True, command: str | None = None,
               model: str = DEFAULT_MODEL, cooldown: int = DEFAULT_COOLDOWN,
               max_per_hour: int = DEFAULT_MAX_PER_HOUR, timeout: int = DEFAULT_TIMEOUT,
               max_retries: int = DEFAULT_MAX_RETRIES, workspace: str | None = None,
               open_terminal: bool = False) -> bool:
    """Attempt one guarded wake for unprocessed inbox items. Returns True if an agent ran."""
    if not enabled:
        return False
    items = unprocessed_items(inbox)
    if not items:
        return False
    state = read_state()
    exhausted = [r for r in items if int(state["failures"].get(str(r.get("id")), 0)) >= max_retries]
    if exhausted:
        if not acquire_lock(timeout):
            log(f"single-flight lock held; {len(exhausted)} failure reply/replies remain pending")
            return False
        delivered = []
        try:
            # Recheck under the lock to avoid duplicate fallback replies from watch threads.
            state = read_state()
            pending_ids = {int(r.get("id", 0)) for r in unprocessed_items(inbox)}
            exhausted = [r for r in exhausted if int(r.get("id", 0)) in pending_ids
                         and int(state["failures"].get(str(r.get("id")), 0)) >= max_retries]
            for r in exhausted:
                try:
                    harpp_client.harpp_notify(
                        conversation_id=int(r["conversation_id"]), message_type="FAILED",
                        body="I’m sorry — the automated worker could not complete this request after multiple attempts. Please retry or ask the harness to handle it interactively.")
                    delivered.append(r)
                    log(f"sent bounded-retry failure reply for message {r.get('id')}")
                except Exception as e:  # noqa: BLE001
                    log(f"failure reply for message {r.get('id')} could not be delivered: {e}")
            if delivered:
                mark_processed(delivered)
        finally:
            release_lock()
        items = unprocessed_items(inbox)
        if not items:
            return bool(delivered)
        state = read_state()
    attempted = set(state.get("last_attempt_messages", []))
    has_new_item = bool(attempted) and any(int(r.get("id", 0)) not in attempted for r in items)
    if in_cooldown(state, cooldown) and not has_new_item:
        log(f"cooldown skip ({cooldown}s); {len(items)} item(s) remain staged")
        return False
    if over_hourly_limit(state, max_per_hour):
        log(f"max-per-hour ({max_per_hour}) reached; {len(items)} item(s) remain staged")
        return False
    if not acquire_lock(timeout):
        log(f"single-flight lock held; {len(items)} item(s) remain staged")
        return False
    try:
        # Count every invocation, including failures, so retries remain rate-bounded.
        record_attempt(items)
        prompt = task_prompt(inbox, items, workspace=workspace)
        # Prompt-driven model routing + graceful fallback: try the requested model first,
        # then the configured default, then every other known model. If a model fails
        # (incl. token/usage/quota/balance exhaustion) the wake delegates to the next one.
        chosen = pick_model(items, model)
        chain = []
        for candidate in (chosen, model, *MODEL_FALLBACK_ORDER):
            if candidate and candidate not in chain:
                chain.append(candidate)
        ok = False
        for attempt_model in chain:
            log(f"spawning wake agent with model {attempt_model}")
            if spawn_agent(prompt, command=command, model=attempt_model, timeout=timeout,
                           expected_replies=len(items), cwd=workspace,
                           open_terminal=open_terminal):
                ok = True
                log(f"wake complete with {attempt_model}; processed {len(items)} item(s)")
                break
            log(f"model {attempt_model} failed; " + ("trying fallback model" if attempt_model != chain[-1] else "giving up this cycle"))
        if ok:
            mark_processed(items)
        else:
            record_failure(items)
            log("wake agent failed; items remain staged for bounded retry")
        return ok
    except Exception as e:  # noqa: BLE001
        record_failure(items)
        log(f"wake failed gracefully: {e}; items remain staged for bounded retry")
        return False
    finally:
        release_lock()
