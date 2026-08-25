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
# Machine-global defaults so monitoring survives workspace switches (any VS Code
# window reads the same inbox/log). Explicit --inbox/--log still override.
INBOX_FILE = CONFIG_DIR / "inbox.jsonl"
AUTOPROCESS_LOG = CONFIG_DIR / "autoprocess.log"
JOB_HISTORY_LIMIT = 100
JOB_VERIFY_TIMEOUT = 600
JOB_REPORT_STALE_AFTER = JOB_VERIFY_TIMEOUT + 300

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


def track_job(*, pid: int, model: str, task: str, conversation_id: int | None = None,
              log_path: str | None = None, verify: str | None = None,
              marker: str | None = None, repo: str | None = None,
              commit: bool = False, timeout: int = 0) -> str:
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
               cwd: str | None = None, open_terminal: bool = True):
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
                           commit=commit, timeout=timeout)
    except Exception:
        try:
            os.killpg(proc.pid, signal.SIGKILL)
        except OSError:
            proc.kill()
        raise
    if not quiet:
        try:
            harpp_client.send_message(
                conversation_id=int(conversation_id),
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
        evidence.append(f"verify exit={'OK' if vok else 'FAIL'}\n{vout}")
    ok = bool(checks) and all(checks)
    if not checks:
        evidence.append("no marker or verification command configured")
    git = _git_status(job.get("repo"))
    commit = ""
    if ok and job.get("commit"):
        commit = _commit_job(job)
    status = "DONE" if ok else "FAILED"
    lines = [f"[job:{job_id}] {job.get('task')} ({job.get('model')})", f"status: {status}",
             "evidence: " + "\n".join(evidence)]
    if tail and not marker:
        lines.append("log tail:\n" + tail[-600:])
    if git:
        lines.append(f"git: {git}")
    if commit:
        lines.append(f"commit: {commit}")
    lines.append("— auto-reported by the harness job monitor; no action needed unless noted.")
    harpp_client.send_message(conversation_id=int(conv), body="\n".join(lines))
    return status


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
                job["finished_at"] = job.get("finished_at") or time.strftime("%Y-%m-%d %H:%M:%S")
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
        return state


def save_workflows_state(state: dict) -> None:
    with _jobs_lock():
        _save_json(WORKFLOWS_FILE, state)


def list_workflows() -> list:
    return sorted(workflows_state().get("workflows", {}).values(),
                  key=lambda w: w.get("created_at", ""))


def get_workflow(wid: str) -> dict | None:
    return workflows_state().get("workflows", {}).get(wid)


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


def _notify_workflow(wf: dict, status: str, stage: dict | None = None) -> None:
    try:
        if status == "DONE":
            body = (f"✅ Workflow complete: {wf.get('title')} — all {len(wf.get('stages', []))} stages passed.\n"
                    "Stages: " + " → ".join(s.get('name', '?') for s in wf.get('stages', [])) + ".")
        else:
            body = (f"❌ Workflow stopped: {wf.get('title')} failed at stage "
                    f"'{stage.get('name') if stage else '?'}' "
                    f"(job {stage.get('job_id') if stage else '?'}). "
                    f"See the job report above; fix and retry, or ask the harness.")
        harpp_client.send_message(conversation_id=int(wf.get("conversation_id") or 0), body=body)
    except Exception as e:  # noqa: BLE001
        log(f"workflow notify failed for {wf.get('id')}: {e}")


def _launch_stage(wid: str, stage: dict, index: int, workflow: dict) -> None:
    """Launch a workflow stage as a tracked job and record its job id on the stage."""
    prompt = _stage_prompt(stage)
    prompt = (prompt.replace("{{WORKSPACE}}", workflow.get("workspace") or "(no workspace)")
                   .replace("{{TITLE}}", workflow.get("title") or ""))
    if index > 0:
        prev = workflow.get("stages", [])[index - 1]
        prompt = prompt.replace("{{PREV_OUTPUT}}", str(prev.get("output_path") or prev.get("job_id") or ""))
    task = f"workflow {wid} stage {index + 1}/{len(workflow.get('stages', []))}: {stage.get('name')}"
    cmd = ["pi", "--model", str(stage.get("model")), "--mode", "json", "--print", prompt]
    log_path = str(CONFIG_DIR / f"wf-{wid}-s{index}.log")
    jid, _proc = launch_job(
        model=str(stage.get("model")), task=task, conversation_id=int(workflow["conversation_id"]),
        command=cmd, log_path=log_path, verify=stage.get("verify"), marker=stage.get("marker"),
        repo=workflow.get("workspace"), commit=bool(stage.get("commit")),
        timeout=int(stage.get("timeout") or 1800), cwd=workflow.get("workspace"),
        open_terminal=True)
    stage["job_id"] = jid
    stage["log_path"] = log_path
    stage["status"] = "running"
    log(f"workflow {wid} launched stage {index + 1} '{stage.get('name')}' as job {jid}")


def start_workflow(*, title: str, conversation_id: int, stages: list,
                  workspace: str | None = None) -> str:
    """Register a multi-stage workflow and launch its first stage. Returns workflow id."""
    if not str(title).strip() or int(conversation_id) < 1 or not stages:
        raise ValueError("title, conversation_id and at least one stage are required")
    wid = uuid.uuid4().hex[:12]
    wf = {
        "id": wid, "title": str(title).strip(), "conversation_id": int(conversation_id),
        "workspace": workspace or default_workspace(),
        "stages": [dict(s) for s in stages],
        "current_index": 0, "status": "running",
        "created_at": time.strftime("%Y-%m-%d %H:%M:%S"),
        "updated_at": time.strftime("%Y-%m-%d %H:%M:%S"),
    }
    for i, st in enumerate(wf["stages"]):
        st.setdefault("job_id", None)
        st.setdefault("status", "pending")
        st.setdefault("timeout", 1800)
        st.setdefault("marker", None)
        st.setdefault("verify", None)
        st.setdefault("commit", False)
        st.setdefault("prompt_file", None)
        st.setdefault("prompt", None)
        st.setdefault("model", "deepseek/deepseek-v4-pro")
    with _jobs_lock():
        state = _load_json(WORKFLOWS_FILE, {})
        if not isinstance(state, dict):
            state = {}
        state.setdefault("workflows", {})
        state["workflows"][wid] = wf
        _save_json(WORKFLOWS_FILE, state)
    try:
        _launch_stage(wid, wf["stages"][0], 0, wf)
    except Exception as e:  # noqa: BLE001
        wf["status"] = "failed"
        wf["stages"][0]["status"] = "failed"
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


def advance_workflows() -> int:
    """Advance running workflows whose current stage job finished. Returns count advanced/finalized."""
    try:
        with _jobs_lock():
            wstate = _load_json(WORKFLOWS_FILE, {})
            jstate = _jobs_state_unlocked()
        wf_map = wstate.get("workflows", {}) if isinstance(wstate, dict) else {}
        jobs = jstate.get("jobs", {})
        advanced = 0
        for wid, wf in list(wf_map.items()):
            if wf.get("status") != "running":
                continue
            stages = wf.get("stages", [])
            idx = int(wf.get("current_index", 0))
            if idx >= len(stages):
                wf["status"] = "done"
                advanced += 1
                continue
            stage = stages[idx]
            job = jobs.get(stage.get("job_id")) if stage.get("job_id") else None
            if not job or job.get("status") != "finished":
                continue  # stage still running
            outcome = job.get("outcome") or "FAILED"
            if outcome == "DONE":
                stage["status"] = "done"
                if idx >= len(stages) - 1:
                    wf["status"] = "done"
                    _notify_workflow(wf, "DONE")
                else:
                    nxt = idx + 1
                    wf["current_index"] = nxt
                    _launch_stage(wid, stages[nxt], nxt, wf)
                advanced += 1
            else:
                stage["status"] = "failed"
                wf["status"] = "failed"
                _notify_workflow(wf, "FAILED", stage)
                advanced += 1
            wf["updated_at"] = time.strftime("%Y-%m-%d %H:%M:%S")
        if advanced:
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
        rest = re.sub(r"--workspace\s+\S+", "", rest)
        rest = re.sub(r"--title\s+.+", "", rest)
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
        wid = start_workflow(title=title, conversation_id=conv, stages=stages, workspace=ws)
        names = ", ".join(s.get("name", "?") for s in stages)
        first = stages[0].get("name", "?")
        return (f"🔁 Workflow started: {wid}\ntitle: {title}\nstages ({len(stages)}): {names}\n"
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
                harpp_client.send_message(conversation_id=conv, body=body)
            log(f"routed workflow command '{cmd[0]}' for message {r.get('id')}")
        except Exception as e:  # noqa: BLE001
            log(f"workflow command failed for message {r.get('id')}: {e}")
            try:
                if conv:
                    harpp_client.send_message(conversation_id=conv,
                                              body=f"⚠️ Workflow command could not be processed: {e}")
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

    threading.Thread(target=_read, daemon=True).start()
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
            tee = (CONFIG_DIR / "wake-agent.log").open("a", encoding="utf-8", buffering=1)
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
        prompt = task_prompt(inbox, items, workspace=workspace)
        # Prompt-driven model routing + graceful fallback: try the requested model first;
        # if it fails (incl. usage/balance exhaustion), fall back to the configured default.
        chosen = pick_model(items, model)
        chain = [chosen] if chosen == model else [chosen, model]
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
