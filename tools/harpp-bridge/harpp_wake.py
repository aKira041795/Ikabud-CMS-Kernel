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
import subprocess
import time
from pathlib import Path

CONFIG_DIR = Path(os.environ.get("XDG_CONFIG_HOME", str(Path.home() / ".config"))) / "harpp"
LOCK_FILE = CONFIG_DIR / "wake.lock"
PROCESSED_FILE = CONFIG_DIR / "wake-processed.json"
WAKE_LOG = CONFIG_DIR / "wake.log"

DEFAULT_MODEL = "deepseek/deepseek-v4-pro"
DEFAULT_COOLDOWN = 300
DEFAULT_MAX_PER_HOUR = 6
DEFAULT_TIMEOUT = 900


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
        path.write_text(json.dumps(obj), encoding="utf-8")
    except Exception:  # noqa: BLE001
        pass


def read_state() -> dict:
    state = _load_json(PROCESSED_FILE, {})
    state.setdefault("messages", [])
    state.setdefault("decisions", [])
    state.setdefault("last_wake", 0)
    state.setdefault("wake_hour", [])
    return state


def save_state(state: dict) -> None:
    _save_json(PROCESSED_FILE, state)


def _stale_lock(timeout: int) -> bool:
    try:
        return _now() - int(LOCK_FILE.read_text().strip()) > 2 * timeout
    except Exception:  # noqa: BLE001
        return False


def acquire_lock(timeout: int) -> bool:
    """Single-flight acquire with stale-lock recovery. Returns True if this process owns it."""
    LOCK_FILE.parent.mkdir(parents=True, exist_ok=True)
    try:
        fd = os.open(LOCK_FILE, os.O_CREAT | os.O_EXCL | os.O_WRONLY)
        os.write(fd, str(_now()).encode())
        os.close(fd)
        return True
    except FileExistsError:
        if _stale_lock(timeout):
            try:
                LOCK_FILE.unlink()
            except OSError:
                return False
            log("recovered stale wake lock")
            return acquire_lock(timeout)
        return False


def release_lock() -> None:
    try:
        LOCK_FILE.unlink()
    except OSError:
        pass


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


def mark_processed(records: list) -> None:
    state = read_state()
    for r in records or []:
        rid = int(r.get("id", 0))
        if r.get("kind") == "message" and rid not in state["messages"]:
            state["messages"].append(rid)
        elif r.get("kind") == "decision" and rid not in state["decisions"]:
            state["decisions"].append(rid)
    state["wake_hour"] = [t for t in state.get("wake_hour", []) if t > _now() - 3600]
    state["wake_hour"].append(_now())
    state["last_wake"] = _now()
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


def spawn_agent(prompt: str, *, command: str | None, model: str, timeout: int) -> bool:
    """Run the headless Pi agent once. Returns True on exit 0. Kills on timeout."""
    if command:
        cmd = command.replace("{model}", model).replace("{prompt}", prompt)
        proc = subprocess.Popen(cmd, shell=True, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True)
    else:
        proc = subprocess.Popen(
            ["pi", "--model", model, "--mode", "json", "--print", prompt],
            stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True,
        )
    try:
        out, _ = proc.communicate(timeout=timeout)
    except subprocess.TimeoutExpired:
        proc.kill()
        proc.communicate()
        log(f"wake agent timed out after {timeout}s; killed")
        return False
    log(f"wake agent exit={proc.returncode} output_tail={out[-200:]!r}")
    return proc.returncode == 0


def maybe_wake(inbox: str, *, enabled: bool = True, command: str | None = None,
               model: str = DEFAULT_MODEL, cooldown: int = DEFAULT_COOLDOWN,
               max_per_hour: int = DEFAULT_MAX_PER_HOUR, timeout: int = DEFAULT_TIMEOUT) -> bool:
    """Attempt one guarded wake for unprocessed inbox items. Returns True if an agent ran."""
    if not enabled:
        return False
    items = unprocessed_items(inbox)
    if not items:
        return False
    state = read_state()
    if in_cooldown(state, cooldown):
        log(f"cooldown skip ({cooldown}s); {len(items)} item(s) remain staged")
        return False
    if over_hourly_limit(state, max_per_hour):
        log(f"max-per-hour ({max_per_hour}) reached; {len(items)} item(s) remain staged")
        return False
    if not acquire_lock(timeout):
        log(f"single-flight lock held; {len(items)} item(s) remain staged")
        return False
    try:
        prompt = task_prompt(inbox, items)
        ok = spawn_agent(prompt, command=command, model=model, timeout=timeout)
        if ok:
            mark_processed(items)
            log(f"wake complete; processed {len(items)} item(s)")
        else:
            log(f"wake agent failed (exit != 0); items remain staged for retry")
        return ok
    except Exception as e:  # noqa: BLE001
        log(f"wake failed gracefully: {e}")
        return False
    finally:
        release_lock()
