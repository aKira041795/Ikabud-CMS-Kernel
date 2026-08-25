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
import signal
import subprocess
import time
import uuid
from pathlib import Path

import harpp_client

CONFIG_DIR = Path(os.environ.get("XDG_CONFIG_HOME", str(Path.home() / ".config"))) / "harpp"
LOCK_FILE = CONFIG_DIR / "wake.lock"
PROCESSED_FILE = CONFIG_DIR / "wake-processed.json"
WAKE_LOG = CONFIG_DIR / "wake.log"

DEFAULT_MODEL = "deepseek/deepseek-v4-pro"
# Production-grade wake limits: max ~1 run/3min (20/hr) keeps replies responsive while
# bounded; cooldown 60s is the min gap between runs (new-message bypass covers bursts).
DEFAULT_COOLDOWN = 60
DEFAULT_MAX_PER_HOUR = 20
DEFAULT_TIMEOUT = 900

# Prompt-driven model routing: the owner can ask for a specific model in their message
# (e.g. "use gpt sol", "use flash") and the wake router honors it. If the requested
# model is unavailable / its usage is exhausted, maybe_wake falls back to the default.
MODEL_ALIASES = {
    "openai-codex/gpt-5.6-sol": ["gpt sol", "got sol", "codex sol", "openai codex", "sol"],
    "deepseek/deepseek-v4-pro": ["deepseek pro", "v4 pro"],
    "deepseek/deepseek-v4-flash": ["flash"],
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


def log(msg: str) -> None:
    line = f"{time.strftime('%Y-%m-%d %H:%M:%S')} {msg}"
    try:
        WAKE_LOG.parent.mkdir(parents=True, exist_ok=True)
        with WAKE_LOG.open("a", encoding="utf-8") as f:
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
    new = []
    for r in records:
        rid = int(r.get("id", 0))
        if r.get("kind") == "message" and rid not in messages:
            new.append(r)
    return new


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


def task_prompt(inbox: str, items: list, template: str | None = None) -> str:
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
    return text.replace("{{INBOX}}", inbox).replace("{{ITEMS}}", items_json)


def spawn_agent(prompt: str, *, command: str | None, model: str, timeout: int,
                expected_replies: int | None = None) -> bool:
    """Run the headless Pi agent once. Returns True on exit 0. Kills on timeout."""
    if command:
        cmd = command.replace("{model}", model).replace("{prompt}", prompt)
        proc = subprocess.Popen(cmd, shell=True, stdout=subprocess.PIPE, stderr=subprocess.STDOUT,
                                text=True, start_new_session=True)
    else:
        proc = subprocess.Popen(
            ["pi", "--model", model, "--mode", "json", "--print", prompt],
            stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True, start_new_session=True,
        )
    try:
        out, _ = proc.communicate(timeout=timeout)
    except subprocess.TimeoutExpired:
        try:
            os.killpg(proc.pid, signal.SIGKILL)
        except OSError:
            proc.kill()
        proc.communicate()
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
               max_retries: int = DEFAULT_MAX_RETRIES) -> bool:
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
                    harpp_client.send_message(
                        conversation_id=int(r["conversation_id"]),
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
        prompt = task_prompt(inbox, items)
        # Prompt-driven model routing + graceful fallback: try the requested model first;
        # if it fails (incl. usage/balance exhaustion), fall back to the configured default.
        chosen = pick_model(items, model)
        chain = [chosen] if chosen == model else [chosen, model]
        ok = False
        for attempt_model in chain:
            log(f"spawning wake agent with model {attempt_model}")
            if spawn_agent(prompt, command=command, model=attempt_model, timeout=timeout,
                           expected_replies=len(items)):
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
