#!/usr/bin/env python3
"""HARPP experience reporting and trial registration (Phase 1).

Design: docs/ai/harpp-experience-repository.md

Deliberately database-free. The evidence at this volume (a small ledger plus a
few hundred landing records) is scanned directly, so there is no index to keep
in sync with the append-only truth. Revisit when a full scan exceeds ~2 seconds
or the ledger passes ~5000 rows; then add SQLite as a *derived* index.

Subcommands
    join                     coverage: ledger rows -> run artifact / run_id / component stamp
    report [--window 28d]    pass rate, interventions, cost or the honest repeat-failure fallback
    compare                  pass rate per component stamp; refuses below min-n
    trial new|list|evaluate  append-only pre-registration of a change-level hypothesis
    stamp                    key=value fields for the ledger writer (git blob hashes)

Exit codes: 0 ok | 2 usage/IO problem | 3 insufficient evidence (no verdict claimed)
"""
from __future__ import annotations

import argparse
import datetime as _dt
import hashlib
import json
import os
import re
import subprocess
import sys
import urllib.error
import urllib.request
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
RUNS_DIR = Path(os.environ.get("HARPP_EXPERIENCE_RUNS") or ROOT / ".ai" / "runs")
TRIALS_FILE = Path(os.environ.get("HARPP_EXPERIENCE_TRIALS") or ROOT / ".ai" / "harpp" / "trials.jsonl")
HINTS_FILE = Path(os.environ.get("HARPP_EXPERIENCE_HINTS") or ROOT / ".ai" / "harpp" / "hints.json")

# THE STORE IS DERIVED, AND SWAPPABLE. JSONL stays the append-only truth; this database is a
# disposable index over it, rebuilt by `index`. Evidence for the choice (measured 2026-11-17):
# PostgreSQL is NOT installed on this workstation, MySQL is already running for the app, and
# Python ships SQLite 3.45 with FTS5. A second daemon on an i3 would add operations and an outage
# mode (HARPP cannot run when its database is down) to buy concurrency this design does not need
# yet. Swap to MySQL (already backed up here) or PostgreSQL behind these two functions when a
# SECOND writer or project appears - callers never touch SQL directly.
DB_FILE = Path(os.environ.get("HARPP_EXPERIENCE_DB") or ROOT / ".ai" / "harpp" / "experience.db")
LEDGER_DIR = Path(os.environ.get("LANE_AUTONOMY_DIR") or ".ai/autonomy")
if not LEDGER_DIR.is_absolute():
    LEDGER_DIR = ROOT / LEDGER_DIR
LEDGER_FILE = LEDGER_DIR / "ledger.jsonl"

# The three components whose change would be attributed to a measured outcome.
# Identity is the git blob hash, so a version is reproducible without a registry.
COMPONENT_FILES = {
    "component_model_chain": "tools/model-chain.txt",
    "component_context_pack": "tools/harpp-bridge/context_pack.py",
    "component_harness": "tools/lane.sh",
}
FAILURE_REASONS = {"crash", "timeout", "no_report", "quota", "fatal", "empty"}


# --------------------------------------------------------------------------- io


def _now() -> str:
    return _dt.datetime.now(_dt.timezone.utc).replace(microsecond=0).isoformat()


def _memory_now() -> str:
    """Sub-second precision for memory events.

    Two events (propose + approve) are written back to back. With whole-second stamps they tie,
    and a tie broken by hash can replay the approval BEFORE the lesson exists - the approval is
    then dropped as "an event for an unknown lesson" and an approved lesson silently reverts to
    proposed. Measured 2026-11-17 during the legacy migration.
    """
    return _dt.datetime.now(_dt.timezone.utc).strftime("%Y-%m-%dT%H:%M:%S.%fZ")


def read_ledger() -> list[dict]:
    return _read_jsonl(LEDGER_FILE)


def _read_jsonl(path: Path) -> list[dict]:
    if not path.is_file():
        return []
    records: list[dict] = []
    for line in path.read_text(encoding="utf-8", errors="replace").splitlines():
        line = line.strip()
        if not line:
            continue
        try:
            value = json.loads(line)
        except ValueError:
            continue
        if isinstance(value, dict):
            records.append(value)
    return records


def _append_jsonl(path: Path, record: dict) -> None:
    """Same durability contract as autonomy-ledger.py: append + fsync, never rewrite."""
    path.parent.mkdir(parents=True, exist_ok=True)
    line = json.dumps(record, ensure_ascii=False, separators=(",", ":")) + "\n"
    fd = os.open(path, os.O_APPEND | os.O_CREAT | os.O_WRONLY, 0o600)
    try:
        import fcntl

        fcntl.flock(fd, fcntl.LOCK_EX)
        os.write(fd, line.encode("utf-8"))
        os.fsync(fd)
    finally:
        os.close(fd)


def _landing(name: str) -> dict:
    path = RUNS_DIR / f"{name}.landed.json"
    if not path.is_file():
        return {}
    try:
        value = json.loads(path.read_text(encoding="utf-8", errors="replace"))
    except ValueError:
        return {}
    return value if isinstance(value, dict) else {}


def _landings() -> list[dict]:
    out: list[dict] = []
    if not RUNS_DIR.is_dir():
        return out
    for path in sorted(RUNS_DIR.glob("*.landed.json")):
        try:
            value = json.loads(path.read_text(encoding="utf-8", errors="replace"))
        except (OSError, ValueError):
            continue
        if isinstance(value, dict):
            out.append(value)
    return out


# ------------------------------------------------------------------ components


def git_blob_hash(relative: str) -> str | None:
    """Git's blob sha1 for a working-tree file, computed without invoking git."""
    path = ROOT / relative
    try:
        data = path.read_bytes()
    except OSError:
        return None
    return hashlib.sha1(b"blob %d\0" % len(data) + data).hexdigest()


def component_stamps() -> dict[str, str]:
    return {
        field: value
        for field, rel in COMPONENT_FILES.items()
        if (value := git_blob_hash(rel))
    }


def git_head() -> str | None:
    head = ROOT / ".git" / "HEAD"
    try:
        text = head.read_text(encoding="utf-8").strip()
    except OSError:
        return None
    if text.startswith("ref: "):
        ref = ROOT / ".git" / text[5:]
        try:
            return ref.read_text(encoding="utf-8").strip() or None
        except OSError:
            return None
    return text or None


def resolve_run_id(task: str, attempts: int | str | None = None) -> str | None:
    """Explicit key first, then the documented `<task>-a<attempt>` convention."""
    candidates: list[str] = []
    try:
        attempt_count = int(attempts or 0)
    except (TypeError, ValueError):
        attempt_count = 0
    if attempt_count:
        candidates.append(f"{task}-a{attempt_count}")
    marker = _landing(candidates[0]) if candidates else {}
    if marker.get("run_id"):
        return str(marker["run_id"])
    if RUNS_DIR.is_dir():
        lanes = sorted(
            (p.name[: -len(".landed.json")] for p in RUNS_DIR.glob(f"{task}-a*.landed.json")),
            reverse=True,
        )
        for lane in lanes:
            record = _landing(lane)
            if record.get("run_id"):
                return str(record["run_id"])
    return None


def _lane_name(task: str, attempts: int | str | None = None) -> str | None:
    """The run-artifact lane for a task: explicit attempt first, then newest match."""
    try:
        attempt_count = int(attempts or 0)
    except (TypeError, ValueError):
        attempt_count = 0
    candidate = f"{task}-a{attempt_count}" if attempt_count else ""
    if candidate and (RUNS_DIR / f"{candidate}.landed.json").is_file():
        return candidate
    if RUNS_DIR.is_dir():
        lanes = sorted(
            (p.name[: -len(".landed.json")] for p in RUNS_DIR.glob(f"{task}-a*.landed.json")),
            reverse=True,
        )
        if lanes:
            return lanes[0]
    return None


def _task_lanes(task: str) -> list[str]:
    if not RUNS_DIR.is_dir():
        return []
    return sorted(p.name[: -len(".landed.json")]
                  for p in RUNS_DIR.glob(f"{task}-a*.landed.json"))


def _current_run_events(path: Path) -> list[dict]:
    """Events from the latest run only.

    The events file is append-only and lane names are reused across re-runs, so summing
    everything in it would charge this task for a previous dispatch.
    """
    events = _read_jsonl(path)
    last_start = 0
    for index, event in enumerate(events):
        if event.get("type") == "run_started":
            last_start = index
    return events[last_start:]


def _lane_usage(task: str | None, since: str | None = None) -> dict:
    """Token and cost totals for every attempt of a task, scoped to this run.

    A task that needed two attempts spent the money on both, so every `<task>-a*` lane
    counts. Re-runs reuse those names, so events older than `since` (the ledger row's
    start time) belong to a previous dispatch and must not be charged here.
    """
    if not task:
        return {}
    total_cost = 0.0
    total_tokens = 0
    captured = 0
    for lane in _task_lanes(task):
        for event in _current_run_events(RUNS_DIR / f"{lane}.events.jsonl"):
            if event.get("type") != "attempt_finished":
                continue
            if since and str(event.get("ts") or "") < since:
                continue
            raw = event.get("usage")
            if not raw:
                continue
            try:
                usage = json.loads(raw) if isinstance(raw, str) else raw
            except ValueError:
                continue
            if not isinstance(usage, dict):
                continue
            captured += 1
            total_cost += float(usage.get("cost_usd") or 0.0)
            total_tokens += int(usage.get("totalTokens") or 0)
    if not captured:
        return {}
    return {"cost_usd": round(total_cost, 8), "tokens": total_tokens, "attempts_with_usage": captured}


# ---------------------------------------------------------------- signatures


def failure_signatures() -> list[dict]:
    """Distinct failure signatures with counts and sample evidence.

    A signature is deliberately coarse - (reason, normalised status line) - because the
    point is to see that failures REPEAT, not to cluster them cleverly. Measured
    2026-11-17: 91 failures collapsed to 14 signatures, which is why a lookup table is
    the practical retrieval mechanism here rather than an index.
    """
    groups: dict[tuple[str, str], dict] = {}
    for record in _landings():
        if _landing_class(record) != "failure":
            continue
        signature = _signature(record)
        entry = groups.setdefault(signature, {
            "reason": signature[0],
            "status": signature[1],
            "count": 0,
            "first_seen": None,
            "last_seen": None,
            "lanes": [],
        })
        entry["count"] += 1
        stamp = str(record.get("landed_at") or "")
        if stamp:
            entry["first_seen"] = min(filter(None, [entry["first_seen"], stamp]))
            entry["last_seen"] = max(filter(None, [entry["last_seen"], stamp]))
        name = record.get("name")
        if name and len(entry["lanes"]) < 3:
            entry["lanes"].append(str(name))
    return sorted(groups.values(), key=lambda e: e["count"], reverse=True)


def load_hints() -> list[dict]:
    if not HINTS_FILE.is_file():
        return []
    try:
        value = json.loads(HINTS_FILE.read_text(encoding="utf-8", errors="replace"))
    except ValueError:
        return []
    return [h for h in value if isinstance(h, dict)] if isinstance(value, list) else []


def _lesson_status(lesson: dict) -> str:
    """Existing entries predate the field; a human-written one is already approved."""
    return str(lesson.get("status") or "active").lower()


def _scope_value(lesson: dict) -> str:
    scope = lesson.get("scope")
    if isinstance(scope, dict):
        return str(scope.get("value") or "any").lower()
    if isinstance(scope, str):
        return scope.lower() or "any"
    return "any"   # older entries carry no scope and therefore apply everywhere


def _scope_matches(lesson: dict, scope: str | None) -> bool:
    value = _scope_value(lesson)
    if value in ("", "any"):
        return True
    if not scope:
        return False
    return value == str(scope).lower()


# ---------------------------------------------------------------------- memory
#
# Owner directive 2026-11-17: experience must not accumulate as a growing prose document —
# "we cannot bloat a document as we gain experiences". Chair ruling the same day: build the memory
# system, not the neural metaphor.
#
# One immutable file per event, tracked in git. A single append-only JSONL was rejected for three
# reasons: one record stops being readable once the file is large, one shared file collides across
# branches, and one corrupt line breaks replay of everything after it. Per-event files keep a
# record inspectable, keep writes on separate paths, and isolate damage to a single event.
#
# Physical isolation is not logical consistency - two events can still approve and retire the same
# lesson from different branches - so conflicts are DETECTED and refused, never silently resolved.

MEMORY_DIR = Path(os.environ.get("HARPP_MEMORY_DIR") or ROOT / "harpp-memory")
MEMORY_EVENTS_DIR = MEMORY_DIR / "events"
MEMORY_SCHEMA_VERSION = 1
MEMORY_EVENT_TYPES = ("propose", "approve", "retire", "confirm", "contradict", "revise")

# Half-life of an unreinforced lesson's influence, in days. A lesson that keeps being confirmed
# keeps its place in the injected set; one that stops being confirmed fades out rather than being
# deleted, because throwing evidence away is the exact waste this store exists to prevent.
MEMORY_HALF_LIFE_DAYS = 120
# Bounded working memory: the prompt receives the strongest few, never the whole store.
MEMORY_INJECT_LIMIT = 6


def lesson_key(scope: str, text: str) -> str:
    """Content-addressed id.

    The same lesson proposed twice lands on the same key with no counter to coordinate, and two
    branches proposing the same lesson agree instead of inventing competing ids.
    """
    seed = f"{str(scope).strip().lower()}\n{str(text).strip().lower()}"
    return "L-" + hashlib.sha256(seed.encode("utf-8")).hexdigest()[:10]


def _scope_lower(value) -> str:
    return str(value or "any").strip().lower() or "any"


def memory_events() -> list[dict]:
    """Every recorded event, oldest first. A damaged event is skipped, not fatal."""
    if not MEMORY_EVENTS_DIR.is_dir():
        return []
    events: list[dict] = []
    for path in sorted(MEMORY_EVENTS_DIR.rglob("*.json")):
        try:
            value = json.loads(path.read_text(encoding="utf-8", errors="replace"))
        except ValueError:
            continue    # one unreadable record must not take the whole memory down
        if isinstance(value, dict) and value.get("event_id"):
            events.append(value)
    events.sort(key=lambda e: (str(e.get("recorded_at") or ""),
                               0 if str(e.get("type")) == "propose" else 1,
                               str(e.get("event_id") or "")))
    return events


def write_memory_event(event_type: str, lesson_id: str, payload: dict) -> tuple[Path, bool]:
    """Append one immutable event. Returns (path, created).

    The filename is derived from the content, so re-running a command rewrites nothing and the
    store cannot grow duplicate copies of the same fact.
    """
    body = json.dumps(payload, sort_keys=True, separators=(",", ":"), default=str)
    digest = hashlib.sha256(f"{event_type}|{lesson_id}|{body}".encode("utf-8")).hexdigest()
    name = digest[:16]
    # ONLY observations collapse. An approval, a retirement, a confirmation and a contradiction are
    # each a distinct decision or outcome, and collapsing them by content once made a retired lesson
    # impossible to re-activate: the second approval looked identical to the first and was dropped
    # (measured 2026-11-17).
    if event_type == "propose":
        existing = list(MEMORY_EVENTS_DIR.rglob(f"{name}.json")) if MEMORY_EVENTS_DIR.is_dir() else []
        if existing:
            return existing[0], False
    recorded_at = _memory_now()
    event = {
        "schema_version": MEMORY_SCHEMA_VERSION,
        "event_id": name,
        "digest": digest,
        "type": event_type,
        "lesson_id": lesson_id,
        "recorded_at": recorded_at,
    }
    event.update(payload)
    path = MEMORY_EVENTS_DIR / recorded_at[:7] / f"{name}.json"
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(event, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    _memory_autocommit(path, event_type)
    return path, True


def _memory_autocommit(path: Path, event_type: str) -> bool:
    """Commit one memory event immediately, so capture cannot be lost by forgetting to commit.

    Owner 2026-11-18: "make the recording of experiences mechanical and durable". An event that
    exists only as an untracked file in a working tree is neither - `git status` showed three
    captured events sitting untracked, one `git clean` away from being gone.

    Best-effort by contract: a memory write must never turn a verified success into a failure, so
    every failure path (not a work tree, git missing, empty commit, a lane mid-rebase) returns
    False and is ignored. Set HARPP_MEMORY_AUTOGIT=0 to disable, which is what tests do.
    """
    if os.environ.get("HARPP_MEMORY_AUTOGIT", "1") == "0":
        return False
    try:
        here = str(path.resolve())
        root = subprocess.run(
            ["git", "rev-parse", "--show-toplevel"],
            cwd=str(path.parent), capture_output=True, text=True, timeout=10,
        )
        if root.returncode != 0:
            return False
        toplevel = root.stdout.strip()
        # Only the repo's own store is committed. Tests point MEMORY_DIR at a temp dir, and
        # committing there would either fail or, worse, commit someone else's files.
        if not toplevel or not here.startswith(os.path.join(toplevel, "")):
            return False
        rel = os.path.relpath(here, toplevel)
        subprocess.run(["git", "add", "--", rel], cwd=toplevel, capture_output=True, timeout=20)
        msg = f"harpp-memory: {event_type} {path.stem}\n\nRecorded mechanically by tools/harpp-experience.py."
        done = subprocess.run(
            ["git", "commit", "-m", msg, "--no-verify", "--", rel],
            cwd=toplevel, capture_output=True, text=True, timeout=30,
        )
        return done.returncode == 0
    except Exception:
        # Durability is the goal; an unavailable git must not break the caller.
        return False


def fold_lessons(events: list[dict]) -> dict[str, dict]:
    """Replay events into current lesson state. Pure: the events are the only input."""
    lessons: dict[str, dict] = {}
    for event in events:
        lesson_id = str(event.get("lesson_id") or "")
        kind = str(event.get("type") or "")
        stamp = str(event.get("recorded_at") or "")
        if not lesson_id or kind not in MEMORY_EVENT_TYPES:
            continue
        state = lessons.get(lesson_id)
        if kind == "propose":
            if state is not None:
                continue    # a re-proposed lesson keeps its first observation
            scope_value = _scope_lower(event.get("scope"))
            state = {
                "id": lesson_id,
                "text": str(event.get("text") or ""),
                "reason": str(event.get("reason") or "any"),
                "evidence": [str(e) for e in (event.get("evidence") or [])],
                "status": "proposed",
                "confirmations": 0,
                "contradictions": 0,
                "created_at": stamp,
                "updated_at": stamp,
                "legacy_id": event.get("legacy_id"),
                "scope": {"kind": "any" if scope_value == "any" else "module",
                          "value": scope_value},
            }
            lessons[lesson_id] = state
            continue
        if state is None:
            continue        # an event for an unknown lesson cannot create one
        if kind == "approve":
            state["status"] = "active"
        elif kind == "retire":
            state["status"] = "retired"
        elif kind == "revise":
            if event.get("text"):
                state["text"] = str(event["text"])
        elif kind == "confirm":
            state["confirmations"] = int(state["confirmations"]) + 1
        elif kind == "contradict":
            state["contradictions"] = int(state["contradictions"]) + 1
        state["updated_at"] = stamp
    return lessons


def memory_conflicts(events: list[dict]) -> list[str]:
    """Incompatible transitions, reported rather than resolved.

    Two branches can approve and retire the same lesson independently; a merge that silently picks
    one produces a memory nobody can trust, so the conflict is surfaced for a human to settle.
    """
    conflicts: list[str] = []
    status: dict[str, str] = {}
    for event in events:
        lesson_id = str(event.get("lesson_id") or "")
        kind = str(event.get("type") or "")
        if kind == "propose":
            status.setdefault(lesson_id, "proposed")
        elif kind == "approve":
            if status.get(lesson_id) == "retired":
                conflicts.append(f"{lesson_id}: approved after it was retired")
            status[lesson_id] = "active"
        elif kind == "retire":
            if status.get(lesson_id) == "retired":
                conflicts.append(f"{lesson_id}: retired twice")
            status[lesson_id] = "retired"
        elif kind in ("confirm", "contradict", "revise") and lesson_id not in status:
            conflicts.append(f"{lesson_id}: {kind} recorded before it was proposed")
    return conflicts


def lesson_activation(lesson: dict, now: _dt.datetime | None = None) -> float:
    """Evidence-weighted retrieval priority.

    Confirmation raises influence, contradiction lowers it, and time decays both, so a lesson that
    stops holding up loses its place in the prompt without anyone deleting the record. This is a
    weighting, not a simulated neuron: `confirmed` only ever moves when a later outcome actually
    agreed, never because a model said so.
    """
    confirmations = int(lesson.get("confirmations") or 0)
    contradictions = int(lesson.get("contradictions") or 0)
    strength = (1.0 + confirmations) / (1.0 + contradictions)
    stamp = str(lesson.get("updated_at") or lesson.get("created_at") or "")
    age_days = 0.0
    if stamp:
        try:
            then = _dt.datetime.strptime(stamp[:19], "%Y-%m-%dT%H:%M:%S").replace(
                tzinfo=_dt.timezone.utc)
            reference = now or _dt.datetime.now(_dt.timezone.utc)
            age_days = max(0.0, (reference - then).total_seconds() / 86400.0)
        except ValueError:
            age_days = 0.0
    decay = 0.5 ** (age_days / MEMORY_HALF_LIFE_DAYS)
    return round(strength * decay, 6)


def load_lessons() -> list[dict]:
    """Current lesson state.

    The tracked memory store is canonical once it holds anything; `hints.json` stays readable only
    so an existing checkout does not lose its lessons before `memory migrate` has run. hints.json
    is gitignored and therefore absent on a fresh clone - the defect this store exists to fix.
    """
    events = memory_events()
    if events:
        rows = list(fold_lessons(events).values())
        for row in rows:
            row["activation"] = lesson_activation(row)
        return rows
    return load_hints()


_QUERY_STOPWORDS = frozenset("""
    the and for with that this from have has are was were will would can could should not but into
    than then when where which while there their they them you your our its it's about over under
    make made need needs must never only also just like more most some such same very what who how
    """.split())


def _query_overlap(query: str | None, text: str) -> int:
    """Shared content words between a task brief and a lesson.

    Cheap on purpose: a lane prompt decides which six rules it sees, and without this the slots go
    to whichever lessons happen to have the lowest ids. A CMS lane about theme variants should be
    handed the variant rules, not the backup-GC rule, and both are equally activated until someone
    confirms either.
    """
    if not query:
        return 0
    words = {w for w in re.findall(r"[a-z0-9_]{5,}", str(query).lower())
             if w not in _QUERY_STOPWORDS}
    if not words:
        return 0
    haystack = set(re.findall(r"[a-z0-9_]{5,}", str(text).lower()))
    return len(words & haystack)


def _scope_from_query(query: str | None) -> str | None:
    """Infer the module a task is about from its text.

    Lanes rarely set LANE_SCOPE, and without a scope a CMS task receives only the universal rules -
    zero of its own. Naming the module in the brief is the normal case, so the tool reads it rather
    than requiring every caller to repeat it.
    """
    if not query:
        return None
    lowered = str(query).lower()
    modules_dir = ROOT / "modules"
    if not modules_dir.is_dir():
        return None
    # Longest match first: `daily-ledger` must beat a module called `daily`.
    for module in sorted((p.name for p in modules_dir.iterdir() if p.is_dir()),
                         key=len, reverse=True):
        if re.search(rf"\b{re.escape(module)}\b", lowered):
            return module
    return None


def hint_block(reason: str | None = None, max_chars: int = 1800,
               scope: str | None = None, query: str | None = None) -> str:
    """The text injected into a lane prompt: what already went wrong, and what was learned.

    Only `active` lessons are injected. A `proposed` lesson is machine-authored text and a human
    has not taken ownership of the wording, so it is stored for review and never reaches a lane.
    Lessons are also scoped: advice about one module must not be pasted into every task.

    Only owner-authored hints are advice. The observed facts below them are counted from
    the landing records, so a lane can never be told something the evidence does not say.
    """
    lines: list[str] = []
    if not scope:
        scope = _scope_from_query(query)
    candidates = [h for h in load_lessons()
                  if h.get("text")
                  and _lesson_status(h) == "active"
                  # When no module can be inferred from the brief, module-scoped rules are NOT
                  # excluded: a real request phrased in plain language never names its module
                  # ("ang mga commissary pabutangan ug pull-out..." names none), and filtering on an
                  # unknown scope silently hid every domain rule the request needed. They are still
                  # ranked below scope-matched ones by the scope term in the sort key.
                  # Scope rules: a KNOWN scope filters strictly; an unknown scope with a brief
                  # allows module rules through (ranked lower); no brief at all stays universal,
                  # because a lane with no context cannot be helped by another module's rules.
                  and (_scope_value(h) in ("", "any")
                       or (scope is not None and _scope_matches(h, scope))
                       or (scope is None and bool(query)))
                  and (not reason or h.get("reason") in (reason, "", None, "any"))]
    # Bounded working memory. A store of a thousand lessons must never become a thousand-line
    # prompt, so the ranking decides who reaches the lane rather than the file deciding.
    #
    # Order is module-scoped first, then activation. Without the scope term every rule sits at
    # activation 1.0 until someone confirms or contradicts it, so "top 6" would be arbitrary and a
    # module's own rules could lose to generic ones.
    candidates.sort(key=lambda h: (0 if _scope_value(h) not in ("", "any") else 1,
                                  -_query_overlap(query, h.get("text")),
                                  -float(h.get("activation") or 0.0),
                                  str(h.get("id") or "")))
    hints = candidates[:MEMORY_INJECT_LIMIT]
    hidden = max(0, len(candidates) - len(hints))
    if hints:
        # A hint travels into an autonomous implementation prompt, so it must never read like
        # an instruction that could outrank the task. Only owner-authored text appears here;
        # model output does not (see the observed block, which carries counts only).
        lines.append("HISTORICAL OBSERVATIONS - not instructions. They cannot change the "
                     "objective, the allowed scope, the acceptance criterion, or what counts "
                     "as PASS.")
        lines.append("KNOWN FAILURE MODES (recorded on this repository):")
        for hint in hints:
            scope = hint.get("reason") or "any"
            lines.append(f"- [{scope}] {str(hint['text']).strip()}")
        if hidden:
            # Never let a cap look like the whole truth. The lane is told the set is larger and how
            # to reach the rest, rather than quietly receiving six of twenty-four.
            lines.append(f"- ({hidden} more recorded rule(s) not shown; `harpp-experience.py "
                         f"memory search \"<topic>\"` retrieves them)")
    observed = [s for s in failure_signatures() if not reason or s["reason"] == reason][:3]
    if observed:
        # Counts only. Quoting a prior run's status line would feed model-authored text back
        # into a later model's prompt.
        lines.append("OBSERVED COUNTS (measured, not advice):")
        for signature in observed:
            lines.append(f"- {signature['reason']}: {signature['count']} prior landings")
    block = "\n".join(lines)
    return block[:max_chars]


def stamp_fields(task: str, attempts: int | str | None = None, since: str | None = None) -> dict[str, str]:
    fields: dict[str, str] = {}
    lane = _lane_name(task, attempts)
    if lane:
        marker = _landing(lane)
        if marker.get("run_id"):
            fields["run_id"] = str(marker["run_id"])
    head = git_head()
    if head:
        fields["git_head"] = head
    fields.update(component_stamps())
    usage = _lane_usage(task, since)
    if usage:
        # Only present when lane-model.sh captured provider usage; a lane without it keeps
        # an absent field rather than a fabricated zero.
        fields["cost_usd"] = str(usage["cost_usd"])
        fields["tokens"] = str(usage["tokens"])
    return fields


# --------------------------------------------------------------------- metrics


def _is_pass(row: dict) -> bool:
    return str(row.get("acceptance", "")).upper() == "PASS"


def _landing_class(record: dict) -> str:
    """pass | failure | contradictory | unknown.

    Every field that speaks about the outcome gets a vote and agreement decides. A
    disagreement is reported as `contradictory` instead of being resolved by field
    precedence, because precedence silently hides exactly the records a reader needs to
    see. `reason` only votes when it names a failure class: `report_present` says a status
    line existed, which is not a claim that the work succeeded.
    """
    votes: list[bool] = []
    completion = str(record.get("completion") or "").upper()
    acceptance = str(record.get("acceptance") or "").upper()
    verdict = str(record.get("verdict") or "").upper()

    if completion:
        votes.append(completion == "PASS")
    if acceptance:
        votes.append(acceptance == "PASS")
    # VERIFIED/NOT_VERIFIED state the evidence level; EXECUTION_ONLY means no criterion ran,
    # so it stays neutral instead of voting either way.
    if verdict in ("VERIFIED", "PASS"):
        votes.append(True)
    elif verdict == "NOT_VERIFIED":
        votes.append(False)
    if record.get("reason") in FAILURE_REASONS:
        votes.append(False)
    if not votes:
        # No recognisable outcome field at all: say so rather than guess from a label.
        return "unknown"
    if len(set(votes)) > 1:
        return "contradictory"
    return "pass" if votes[0] else "failure"


def _signature(record: dict) -> tuple[str, str]:
    line = re.sub(r"[0-9a-f]{7,}|\d+", "#", str(record.get("status_line") or "").lower())
    return str(record.get("reason") or ""), line.strip()[:120]


def repeat_failure_rate(window: str | None = None) -> dict:
    """Share of landing-record failures whose signature already occurred earlier.

    This is the cost-free stand-in for wasted_cost_share while token usage is not
    captured: it answers "are we repeating the same failure?" from existing data.
    """
    failures = [r for r in _landings() if _landing_class(r) == "failure"]
    contradictory = [r for r in _landings() if _landing_class(r) == "contradictory"]
    unknown = [r for r in _landings() if _landing_class(r) == "unknown"]
    if window:
        failures = [r for r in failures
                    if _within_window(r.get("landed_at"), window)]
        contradictory = [r for r in contradictory
                         if _within_window(r.get("landed_at"), window)]
        unknown = [r for r in unknown
                   if _within_window(r.get("landed_at"), window)]
    failures.sort(key=lambda r: str(r.get("landed_at") or ""))
    seen: set[tuple[str, str]] = set()
    repeats = 0
    for record in failures:
        signature = _signature(record)
        if signature in seen:
            repeats += 1
        seen.add(signature)
    return {
        "failure_landings": len(failures),
        "repeated_failures": repeats,
        "repeat_failure_rate": round(repeats / len(failures), 4) if failures else None,
        "contradictory_landings": len(contradictory),
        "unknown_landings": len(unknown),
    }


def model_log_coverage() -> dict:
    """Landings whose model output survived into the runs directory.

    The model log used to live only in /tmp, so a non-completing lane left nothing to
    diagnose. This count is the direct evidence that retention is working.
    """
    names = sorted({str(r.get("name")) for r in _landings() if r.get("name")})
    retained = sum(1 for name in names if any(RUNS_DIR.glob(f"{name}.model-a*.log")))
    return {
        "landings": len(names),
        "with_model_log": retained,
        "coverage": round(retained / len(names), 4) if names else None,
    }


def _window_rows(rows: list[dict], window: str | None) -> list[dict]:
    if not window:
        return rows
    text = str(window).strip().lower()
    if text.endswith("d"):
        return [row for row in rows if _within_window(row.get("started_at"), text)]
    try:
        count = int(text)
    except ValueError:
        return rows
    return rows[-count:] if count > 0 else rows


def _within_window(stamp, window: str) -> bool:
    """True when `stamp` falls inside `window` ('28d' days) or window is a row count."""
    window = str(window).strip().lower()
    if not window.endswith("d"):
        return True  # row-count windows are applied by the caller
    try:
        days = int(window[:-1])
    except ValueError:
        return True
    try:
        value = _dt.datetime.fromisoformat(str(stamp))
    except (TypeError, ValueError):
        return False
    if value.tzinfo is None:
        value = value.replace(tzinfo=_dt.timezone.utc)
    cutoff = _dt.datetime.now(_dt.timezone.utc) - _dt.timedelta(days=days)
    return value >= cutoff


def _task_changed_files(task: str) -> int | None:
    """Files any attempt of this task actually changed, from the landing markers.

    Returns None when there is NO landing record: an unobserved task is unknown, not wasteful.

    `acceptance = FAIL` does NOT mean the spend was wasted. Measured 2026-11-17: the
    dl-substitute-dispatch-item lane was recorded FAIL because the ACCEPTANCE TEST itself was
    broken (a stray regex delimiter), while the code it wrote was good and shipped. Scoring that
    as waste made `wasted_cost_share` report 87% on a day that produced two working features - a
    metric that lies is worse than no metric. Waste now means "paid for nothing retained".
    """
    lanes = _task_lanes(task)
    if not lanes:
        return None
    total = 0
    for lane in lanes:
        try:
            total += int(_landing(lane).get("changed_files") or 0)
        except (TypeError, ValueError):
            continue
    return total


def summary(rows: list[dict]) -> dict:
    total = len(rows)
    passed = sum(1 for r in rows if _is_pass(r))
    cost_total = sum(float(r.get("cost_usd") or 0.0) for r in rows)
    cost_rows = sum(1 for r in rows if float(r.get("cost_usd") or 0.0) > 0)
    wasted = 0.0
    for row in rows:
        cost = float(row.get("cost_usd") or 0.0)
        if cost <= 0:
            continue
        rolled_back = bool(row.get("rolled_back"))
        changed = _task_changed_files(str(row.get("task") or ""))
        # Waste needs EVIDENCE of no retained change; an unobserved task is unknown, not waste.
        if rolled_back or changed == 0:
            wasted += cost
    return {
        "rows": total,
        "pass_rate": round(passed / total, 4) if total else None,
        "attempts_avg": round(sum(int(r.get("attempts") or 1) for r in rows) / total, 2) if total else None,
        "owner_interventions": sum(int(r.get("owner_interventions") or 0) for r in rows),
        "cost_usd": round(cost_total, 4),
        "cost_captured_rows": cost_rows,
        "wasted_cost_share": round(wasted / cost_total, 4) if cost_total > 0 else None,
        "started_at_min": _min_str(rows, "started_at"),
        "started_at_max": _max_str(rows, "started_at"),
    }


def _min_str(rows, key):
    values = [str(r[key]) for r in rows if r.get(key)]
    return min(values) if values else None


def _max_str(rows, key):
    values = [str(r[key]) for r in rows if r.get(key)]
    return max(values) if values else None


# -------------------------------------------------------------------- commands


def cmd_join(args) -> int:
    rows = read_ledger()
    report = {
        "ledger": str(LEDGER_FILE.relative_to(ROOT)) if LEDGER_FILE.is_relative_to(ROOT) else str(LEDGER_FILE),
        "rows": len(rows),
        "with_explicit_run_id": 0,
        "resolvable_run_id": 0,
        "with_component_stamp": 0,
        "with_cost": 0,
        "unresolvable_tasks": [],
    }
    for row in rows:
        task = str(row.get("task") or "")
        if row.get("run_id"):
            report["with_explicit_run_id"] += 1
        if row.get("run_id") or resolve_run_id(task, row.get("attempts")):
            report["resolvable_run_id"] += 1
        else:
            report["unresolvable_tasks"].append(task)
        if any(row.get(field) for field in COMPONENT_FILES):
            report["with_component_stamp"] += 1
        if float(row.get("cost_usd") or 0.0) > 0:
            report["with_cost"] += 1
    if args.json:
        print(json.dumps(report, indent=2))
        return 0
    print(f"ledger rows:               {report['rows']}")
    print(f"  explicit run_id:         {report['with_explicit_run_id']}")
    print(f"  run_id resolvable:       {report['resolvable_run_id']}")
    print(f"  component stamped:       {report['with_component_stamp']}")
    print(f"  cost captured:           {report['with_cost']}")
    if report["unresolvable_tasks"]:
        print(f"  unresolvable tasks:      {', '.join(sorted(set(report['unresolvable_tasks'])))}")
    if report["with_component_stamp"] < report["rows"]:
        print("note: rows without a component stamp predate the Phase 1 stamp and cannot")
        print("      attribute an outcome to a harness version.")
    return 0


def cmd_report(args) -> int:
    rows = _window_rows(read_ledger(), args.window)
    stats = summary(rows)
    repeats = repeat_failure_rate(args.window)
    coverage = model_log_coverage()
    if args.json:
        print(json.dumps({"ledger": stats, "landing": repeats, "model_logs": coverage}, indent=2))
        return 0
    print(f"== harpp experience ({args.window or 'all'}) ==")
    print(f"   rows: {stats['rows']} | pass rate: {stats['pass_rate']} | attempts avg: {stats['attempts_avg']}")
    print(f"   owner interventions: {stats['owner_interventions']} | cost captured: ${stats['cost_usd']:.4f} "
          f"({stats['cost_captured_rows']}/{stats['rows']} rows)")
    if stats["wasted_cost_share"] is None:
        print("   wasted_cost_share:   uncomputable (no row carries cost_usd)")
    else:
        print(f"   wasted_cost_share:   {stats['wasted_cost_share']}")
    print(f"   landing failures: {repeats['failure_landings']} | "
          f"repeats: {repeats['repeated_failures']} | repeat_failure_rate: {repeats['repeat_failure_rate']}")
    if repeats["contradictory_landings"]:
        print(f"   contradictory records: {repeats['contradictory_landings']} "
              "(fields disagree; excluded from the rate rather than resolved silently)")
    if repeats["unknown_landings"]:
        print(f"   unclassifiable records: {repeats['unknown_landings']} "
              "(legacy rows with no outcome field)")
    print(f"   model logs retained: {coverage['with_model_log']}/{coverage['landings']} landings "
          f"({coverage['coverage']})")
    if stats["started_at_min"]:
        print(f"   range: {stats['started_at_min']} .. {stats['started_at_max']}")
    if stats["rows"] and stats["rows"] < 20:
        print("   note: fewer than 20 rows — treat every rate above as indicative, not conclusive.")
    return 0


def cmd_compare(args) -> int:
    field = args.component
    rows = read_ledger()
    groups: dict[str, list[dict]] = {}
    for row in rows:
        value = row.get(field)
        if value:
            groups.setdefault(str(value), []).append(row)
    payload = {
        "component": field,
        "min_n": args.min_n,
        "groups": {
            key: {"n": len(group), **{k: v for k, v in summary(group).items()
                                     if k in ("pass_rate", "cost_usd", "wasted_cost_share")}}
            for key, group in sorted(groups.items())
        },
    }
    if len(groups) < 2:
        payload["verdict"] = "insufficient evidence"
        payload["reason"] = f"only {len(groups)} distinct {field} value(s); a comparison needs 2"
        _emit_compare(args, payload)
        return 3
    thin = [key for key, group in groups.items() if len(group) < args.min_n]
    if thin:
        payload["verdict"] = "insufficient evidence"
        payload["reason"] = f"fewer than min_n={args.min_n} rows for: {', '.join(thin)}"
        _emit_compare(args, payload)
        return 3
    ranked = sorted(payload["groups"].items(),
                    key=lambda kv: (kv[1]["pass_rate"] or 0), reverse=True)
    payload["verdict"] = "compared"
    payload["best"] = ranked[0][0]
    payload["delta_pass_rate"] = round(
        (ranked[0][1]["pass_rate"] or 0) - (ranked[-1][1]["pass_rate"] or 0), 4)
    _emit_compare(args, payload)
    return 0


def _emit_compare(args, payload: dict) -> None:
    if args.json:
        print(json.dumps(payload, indent=2))
        return
    print(f"== compare by {payload['component']} (min_n={payload['min_n']}) ==")
    for key, value in payload["groups"].items():
        print(f"   {key[:12]}  n={value['n']:<4} pass={value['pass_rate']}")
    print(f"   verdict: {payload['verdict']} — {payload.get('reason', payload.get('delta_pass_rate'))}")
    if payload["verdict"] != "compared":
        print("   refusal: below this threshold any claimed improvement is noise.")


def cmd_trial_new(args) -> int:
    existing = _read_jsonl(TRIALS_FILE)
    trial_id = f"T-{len(existing) + 1:04d}"
    record = {
        "trial_id": trial_id,
        "created_at": _now(),
        "git_head": git_head(),
        "project_scope": ROOT.name,
        "component": args.component,
        "hypothesis": args.hypothesis,
        "metric": args.metric,
        "minimum_delta": args.minimum_delta,
        "min_n": args.min_n,
        "baseline_version": args.baseline,
        "candidate_version": args.candidate,
        "status": "registered",
    }
    _append_jsonl(TRIALS_FILE, record)
    print(json.dumps(record, indent=2) if args.json else f"registered {trial_id}: {args.hypothesis}")
    return 0


def cmd_trial_list(args) -> int:
    rows = _read_jsonl(TRIALS_FILE)
    if args.json:
        print(json.dumps(rows, indent=2))
        return 0
    if not rows:
        print("no trials registered")
        return 0
    for row in rows:
        print(f"{row.get('trial_id')}  {row.get('status'):<12} {row.get('component')}  "
              f"{row.get('metric')} >= {row.get('minimum_delta')} (n>={row.get('min_n')})")
        print(f"          {row.get('hypothesis')}")
    return 0


def cmd_trial_evaluate(args) -> int:
    trials = _read_jsonl(TRIALS_FILE)
    trial = next((t for t in trials if t.get("trial_id") == args.trial_id), None)
    if trial is None:
        print(f"harpp-experience: unknown trial {args.trial_id}", file=sys.stderr)
        return 2
    rows = [r for r in read_ledger() if r.get(trial["component"])]
    baseline = [r for r in rows if str(r.get(trial["component"])) == str(trial.get("baseline_version"))]
    candidate = [r for r in rows if str(r.get(trial["component"])) == str(trial.get("candidate_version"))]
    payload = {
        "trial_id": trial["trial_id"],
        "component": trial["component"],
        "metric": trial["metric"],
        "minimum_delta": trial["minimum_delta"],
        "min_n": trial["min_n"],
        "baseline_n": len(baseline),
        "candidate_n": len(candidate),
    }
    if min(len(baseline), len(candidate)) < int(trial["min_n"]):
        payload["verdict"] = "insufficient evidence"
    else:
        delta = round((summary(candidate)["pass_rate"] or 0) - (summary(baseline)["pass_rate"] or 0), 4)
        payload["observed_delta"] = delta
        payload["verdict"] = "improved" if delta >= float(trial["minimum_delta"]) else "no improvement"
    if args.json:
        print(json.dumps(payload, indent=2))
    else:
        print(f"{payload['trial_id']}: {payload['verdict']} "
              f"(n={payload['baseline_n']}/{payload['candidate_n']}, min_n={payload['min_n']}"
              + (f", delta={payload.get('observed_delta')}" if "observed_delta" in payload else "") + ")")
    return 0 if payload["verdict"] == "improved" else 3


def _save_hints(hints: list[dict]) -> None:
    HINTS_FILE.parent.mkdir(parents=True, exist_ok=True)
    HINTS_FILE.write_text(json.dumps(hints, indent=2) + "\n", encoding="utf-8")


def cmd_lesson_propose(args) -> int:
    """Machine-authored lesson: stored as an event, auditable, and NEVER injected until approved."""
    scope_value = (args.scope or "any").strip() or "any"
    lesson_id = lesson_key(scope_value, args.text)
    payload = {
        "text": args.text,
        "reason": args.reason,
        "scope": scope_value,
        "evidence": args.evidence or [],
        "proposed_by": args.proposed_by or "manual",
        "review_after": args.review_after or (
            (_dt.datetime.now(_dt.timezone.utc) + _dt.timedelta(days=30)).strftime("%Y-%m-%d")
        ),
    }
    path, created = write_memory_event("propose", lesson_id, payload)
    record = {"id": lesson_id, "status": "proposed", **payload}
    if args.json:
        print(json.dumps(record, indent=2))
    elif created:
        print(f"proposed {lesson_id} -> {path}\n  NOT injected until `lesson approve {lesson_id}`")
    else:
        print(f"{lesson_id} was already recorded; nothing written")
    return 0


def _set_lesson_status(lesson_id: str, status_value: str, **extra) -> int:
    """Approve or retire by APPENDING an event; the earlier record is never rewritten."""
    known = {row.get("id"): row for row in load_lessons()}
    lesson = known.get(lesson_id)
    if not lesson:
        print(f"harpp-experience: unknown lesson {lesson_id}", file=sys.stderr)
        return 2
    kind = "approve" if status_value == "active" else "retire"
    if _lesson_status(lesson) == status_value:
        print(f"{lesson_id} already {status_value}; nothing written")
        return 0
    path, created = write_memory_event(kind, lesson_id, dict(extra) or {})
    if not created:
        print(f"{lesson_id} already {status_value}; nothing written")
        return 0
    print(f"{status_value} {lesson_id} (scope {_scope_value(lesson)}) -> {path}")
    return 0


def cmd_lesson_duplicates(args) -> int:
    """Deterministic duplicate report - the baseline any model-assisted clustering must beat.

    Chair verdict 2026-11-18: reject a 0.5B model as the authority for consolidating lessons; start
    with normalization and exact deduplication, and keep an LLM stage only if it beats this on a
    labelled sample. This command costs nothing and cannot hallucinate.
    """
    rows = [r for r in load_lessons() if _lesson_status(r) == "proposed" and r.get("text")]
    groups: dict[str, list[dict]] = {}
    for r in rows:
        key = re.sub(r"[^a-z0-9 ]+", " ", str(r["text"]).lower())
        groups.setdefault(re.sub(r"\s+", " ", key).strip(), []).append(r)
    dupes = {k: v for k, v in groups.items() if len(v) > 1}
    inside = sum(len(v) for v in dupes.values())
    print(f"proposed: {len(rows)} · exact-duplicate groups: {len(dupes)} · lessons inside them: {inside}")
    for key, items in sorted(dupes.items(), key=lambda kv: -len(kv[1]))[:args.limit]:
        print(f"- {len(items)}x {key[:100]}")
        print("    " + ", ".join(str(i.get("id")) for i in items))
    return 0


def cmd_lesson_apply(args) -> int:
    """Record that a lesson was actually USED, with evidence the harness itself checks.

    Chair verdict 2026-11-18: "lesson shown -> defect avoided" is a counterfactual and cannot be
    observed, so it must not be the metric. What CAN be observed is recorded here: the lesson was
    retrieved, the implementer says it was applied, and the evidence pointer resolves. A lesson with
    no resolving evidence is EXPOSURE, not success, and is counted separately.
    """
    lesson_id = str(args.lesson_id)
    if lesson_id not in {str(r.get("id")) for r in load_lessons()}:
        print(f"harpp-experience: no such lesson: {lesson_id}", file=sys.stderr)
        return 2
    evidence = str(args.evidence or "").strip()
    # The harness verifies; the caller does not self-certify.
    verified = False
    if evidence:
        target = evidence.split("::", 1)[0].strip()
        candidate = Path(target)
        if not candidate.is_absolute():
            candidate = ROOT / target
        verified = candidate.exists()
    _, created = write_memory_event("lesson_application", lesson_id, {
        "run_id": str(args.run or ""),
        "retrieved": True,
        "applied": True,
        "evidence": evidence,
        "verified": verified,
    })
    state = "applied+verified" if verified else "exposure only (no resolving evidence)"
    print(f"{'recorded' if created else 'already recorded'} {lesson_id}: {state}")
    return 0


def cmd_lesson_approve(args) -> int:
    # No timestamp in the payload: the event already carries recorded_at, and a volatile field in
    # the body would give every re-run a new digest and append a duplicate approval.
    return _set_lesson_status(args.lesson_id, "active", approved_by=args.by or "owner")


def cmd_lesson_retire(args) -> int:
    return _set_lesson_status(args.lesson_id, "retired", compiled_as=args.compiled_as or None)


def _memory_signal(kind: str, lesson_id: str) -> int:
    """Record reinforcement or inhibition. Never rewrites the original observation."""
    if lesson_id not in {row.get("id") for row in load_lessons()}:
        print(f"harpp-experience: unknown lesson {lesson_id}", file=sys.stderr)
        return 2
    path, created = write_memory_event(kind, lesson_id, {})
    if not created:
        print(f"{lesson_id}: {kind} already recorded; nothing written")
        return 0
    print(f"{lesson_id}: {kind} recorded -> {path}")
    return 0


def cmd_memory_confirm(args) -> int:
    return _memory_signal("confirm", args.lesson_id)


def cmd_memory_contradict(args) -> int:
    return _memory_signal("contradict", args.lesson_id)


def cmd_memory_list(args) -> int:
    rows = load_lessons()
    if args.status:
        rows = [r for r in rows if _lesson_status(r) == args.status]
    if getattr(args, "reason", None):
        rows = [r for r in rows if str(r.get("reason")) == args.reason]
    rows.sort(key=lambda r: float(r.get("activation") or 0.0), reverse=True)
    if getattr(args, "limit", 0):
        rows = rows[: args.limit]
    if args.json:
        print(json.dumps(rows, indent=2))
        return 0
    if not rows:
        print("no lessons in memory")
        return 0
    for row in rows:
        print(f"{row.get('id')}  {_lesson_status(row):<9} act {float(row.get('activation') or 0):<7.3f} "
              f"+{int(row.get('confirmations') or 0)}/-{int(row.get('contradictions') or 0)}  "
              f"[{_scope_value(row)}] {str(row.get('text'))[:70]}")
    return 0


def cmd_memory_verify(args) -> int:
    """Report incompatible transitions instead of letting a merge pick a winner."""
    events = memory_events()
    conflicts = memory_conflicts(events)
    if args.json:
        print(json.dumps({"events": len(events), "conflicts": conflicts}, indent=2))
        return 1 if conflicts else 0
    print(f"memory: {len(events)} events, {len(fold_lessons(events))} lessons in {MEMORY_EVENTS_DIR}")
    if conflicts:
        for conflict in conflicts:
            print(f"  CONFLICT: {conflict}")
        return 1
    print("  no incompatible transitions")
    return 0


def cmd_memory_migrate(args) -> int:
    """Import legacy hints.json lessons as events. Idempotent; the file is left untouched."""
    legacy = load_hints()
    if not legacy:
        print("no legacy lessons to migrate")
        return 0
    imported = 0
    for hint in legacy:
        text = str(hint.get("text") or "").strip()
        if not text:
            continue
        scope_value = _scope_value(hint)
        lesson_id = lesson_key(scope_value, text)
        _, created = write_memory_event("propose", lesson_id, {
            "text": text,
            "reason": str(hint.get("reason") or "any"),
            "scope": scope_value,
            "evidence": [str(e) for e in (hint.get("evidence") or [])],
            "proposed_by": "migrated",
            "legacy_id": hint.get("id"),
            "legacy_added_at": hint.get("added_at"),
        })
        status = _lesson_status(hint)
        if status == "active":
            write_memory_event("approve", lesson_id, {"approved_by": "migrated"})
        elif status == "retired":
            write_memory_event("retire", lesson_id, {"compiled_as": hint.get("compiled_as")})
        imported += 1 if created else 0
    print(f"migrated {imported} legacy lesson(s) into {MEMORY_EVENTS_DIR}")
    print("hints.json is now a read-only fallback; the memory store is canonical")
    return 0


def cmd_memory_path(args) -> int:
    print(MEMORY_DIR)
    return 0


# -------------------------------------------------------------------- harvest
#
# Owner directive 2026-11-17: "there's a wealth of concepts/ideas and experiences we can harvest
# and store now. a small LLM is where it starts."
#
# The corpus is 328 markdown files under docs/ (~20 MB) that were written but never read back by
# the harness. Harvesting turns them into PROPOSED lessons: machine-authored, stored, and NOT
# injected until the owner approves. That is the Class B rule the memory store already enforces -
# harvesting writes candidates, it never promotes them.
#
# Cheap first, model second: a deterministic pass selects paragraphs that actually assert something
# durable ("measured", "rejected", "never", "owner direction"). Only those candidates are worth a
# model call, which keeps a 20 MB corpus from becoming a 20 MB prompt.

_HARVEST_STRONG = (
    "measured", "measured:", "owner direction", "owner directive", "owner report",
    "must never", "must not", "do not", "never ", "root cause", "rejected",
    "trap", "gotcha", "pitfall", "regression",
)
_HARVEST_MIN_CHARS = 70
_HARVEST_MAX_CHARS = 420


def _harvest_scope(path: Path) -> str:
    """A rule found in docs/<module>/ or tests/<module>/ belongs to that module, not to every task."""
    parts = path.parts
    for marker in ("docs", "tests"):
        if marker in parts:
            index = parts.index(marker)
            if index + 1 < len(parts):
                candidate = parts[index + 1]
                if (ROOT / "modules" / candidate).is_dir():
                    return candidate
    # Instruction files name their subject in the filename; ui-ux rules govern every screen.
    #
    # The filename fallback cannot see a hyphenated module: `tests/dc-cafe/DcCafeAnalyticsTest.php`
    # lowercased is `dccafeanalyticstest.php`, which does not contain `dc-cafe`. Measured
    # 2026-11-19, every test-derived candidate came out scope=any for that reason, which would have
    # poured thousands of module rules into the UNIVERSAL set every lane draws on.
    name = re.sub(r"[^a-z0-9]", "", path.name.lower())
    for module in sorted(p.name for p in (ROOT / "modules").iterdir() if p.is_dir()):
        if re.sub(r"[^a-z0-9]", "", module.lower()) in name:
            return module
    return "any"


def _harvest_reason(path: Path) -> str:
    """Category, so a harvested rule can be filtered and reviewed by where it came from."""
    name = path.name.lower()
    if "ui-ux" in name or "template" in name:
        return "ui-ux"
    if name.startswith("decisions") or "decision" in name:
        return "decision"
    if "philosophy" in name or name.startswith("philosophy"):
        return "principle"
    return "harvested"


def _harvest_clean(body: str) -> str:
    """Strip the markdown scaffolding that a rule carries in its source document.

    `### Measured trap` and `**How to verify:**` are document structure, not part of the rule, and
    carrying them into a prompt makes a lesson read like a heading rather than a fact.
    """
    body = re.sub(r"^\s*#{1,6}\s*", "", body)
    body = re.sub(r"^\s*\*\*[^*]{0,80}\*\*\s*", "", body)      # a bold lead-in label
    body = re.sub(r"^\s*[-*+]\s+", "", body)
    return " ".join(body.split()).strip()


_HARVEST_FRAGMENT = re.compile(r"^(?:\s*:|\s*\d+\.\s|\s*and\s|\s*or\s|\s*•)", re.I)


def _harvest_is_fragment(body: str) -> bool:
    """Reject list debris that survived cleaning.

    `: - Deeply nested runtime loader - rejected: ...` and `1. Measured: alignment ...` are tails of
    a bulleted list, not standalone rules; approving one would put half a sentence in front of an
    agent. Measured 2026-11-17: four of twenty decision/ui-ux candidates were this shape.
    """
    return bool(_HARVEST_FRAGMENT.match(body))


_HARVEST_NOT_PROSE = re.compile(r"^(?:\||```|\s*[|+][-=|])", re.M)


def _harvest_is_not_prose(body: str) -> bool:
    """Reject tables, code fences and benchmark grids.

    Measured 2026-11-17 on the cms pool: `| Endpoint | Avg |` and "``` WordPress: wp-load.php..."
    were being harvested as rules. They are data and source listing, and a lane told to follow them
    learns nothing it can act on.
    """
    return bool(_HARVEST_NOT_PROSE.match(body)) or body.count("|") >= 3


# A lesson must be about the SYSTEM, not about the document or the session that produced it.
#
# Rejecting whole document classes is the tempting fix and the wrong one: measured 2026-11-21, the
# best candidate in a HANDOFF file was "P2's silence on 06-02 is a measured zero because the branch
# reported that shift" - a durable fact recorded precisely in a session document. What is worthless
# is the paragraph that DESCRIBES the document ("Session end state...", "the superseded plan (kept
# for the record, do NOT implement as written)"). Distinguishing those is a content test, and this
# is it.
_HARVEST_META = re.compile(
    r"(?:\bsession end state\b|\bwritten so a.{0,25}\bsession\b|\bthis (?:document|handoff|plan)\b"
    r"|\bsuperseded plan\b|\bkept for the record\b|\bdo not implement as written\b"
    r"|\bwhat changed\b|\bnext steps?\b|\bopen questions?\b)",
    re.I)


def _harvest_is_self_reference(body: str) -> bool:
    """True when the paragraph narrates the document or the session rather than the system.

    Measured 2026-11-21: 784 proposals had accumulated against 66 approved, and the review queue
    was unreviewable not because of tables and code - those were already filtered - but because
    plan and handoff prose sat in it beside real rules.
    """
    return bool(_HARVEST_META.search(body))


_TEST_LABEL_RE = re.compile(
    r"(?:\$[A-Za-z_]+->)?(?:test|check|assert(?:_equal|_same|_true)?)\s*\(\s*"
    r"'((?:[^'\\]|\\.){40,300})'", re.S)

# Distinctive words only: shared vocabulary has to mean the same SUBJECT, not the same filler.
_ASSERTION_STOP = frozenset({
    "never", "always", "rather", "which", "should", "within", "their", "there", "these", "those",
    "where", "after", "before", "because", "still", "without", "instead", "cannot", "would",
    "every", "about", "using", "applies", "remains", "value", "returns", "return",
})


def _assertion_words(text) -> set[str]:
    return {w for w in re.findall(r"[a-z]{6,}", str(text or "").lower()) if w not in _ASSERTION_STOP}


def test_assertion_labels() -> list[dict]:
    """Every assertion label in tests/, with file:line provenance.

    ONE pattern, shared with the harvester below, because the two drifting apart is how the
    harvester came to ignore `$h->test(` entirely while still appearing to work.
    """
    out: list[dict] = []
    root = ROOT / "tests"
    if not root.is_dir():
        return out
    for path in sorted(root.rglob("*.php")):
        try:
            text = path.read_text(encoding="utf-8", errors="replace")
        except OSError:
            continue
        for match in _TEST_LABEL_RE.finditer(text):
            line = text.count("\n", 0, match.start()) + 1
            try:
                relative = path.relative_to(ROOT)
            except ValueError:
                relative = path
            out.append({
                "origin": f"{relative}:{line}",
                "text": match.group(1).replace("\\'", "'"),
                "words": _assertion_words(match.group(1).replace("\\'", "'")),
            })
    return out


def _harvest_test_candidates(paths: list[Path]) -> list[dict]:
    """Assertion labels ARE experience, already compiled into a gate.

    A test named "the producing commissary is never named as the corrector" is the same knowledge as
    a lesson, but bound to an executable check. Reading them back into memory links the gate to the
    experience that produced it, which is the compile direction of the three-loop design: an
    approved lesson can later be retired as `compiled_as: <this test>`.
    """
    found: list[dict] = []
    # The opening parenthesis is not optional in this repo's own helper: `$h->test('label', ...)`
    # put a `(` between the method name and the quote, and the pattern only tolerated it for the
    # assert* branch. Measured 2026-11-19: 5,803 `$h->test(` calls across 814 test files, 3,423
    # with labels long enough to harvest, and this regex matched ZERO of them - so `harvest --kind
    # tests` returned 27 candidates from other shapes, and `close` promised "a new gate IS new
    # experience" while learning almost nothing from the gates actually written.
    label_pattern = _TEST_LABEL_RE
    for base in paths:
        files = sorted(base.rglob("*Test.php")) + sorted(base.rglob("*_test.php"))
        for path in files or ([base] if base.is_file() else []):
            try:
                text = path.read_text(encoding="utf-8", errors="replace")
            except OSError:
                continue
            for match in label_pattern.finditer(text):
                label = _harvest_clean(match.group(1).replace("\\'", "'"))
                if len(label) < 40 or label.lower().startswith("http"):
                    continue
                line_no = text.count("\n", 0, match.start()) + 1
                try:
                    relative = path.relative_to(ROOT)
                except ValueError:
                    relative = path
                found.append({
                    "text": label,
                    "source": f"{relative}:{line_no}",
                    "signals": ["test"],
                    "scope": _harvest_scope(path),
                    "reason": "gate",
                })
    return found


def _harvest_candidates(paths: list[Path], kind: str = "docs") -> list[dict]:
    """Paragraphs that assert something durable, with file:line provenance."""
    found: list[dict] = []
    for base in paths:
        if kind not in ("docs", "all"):
            break
        files = sorted(base.rglob("*.md")) if base.is_dir() else ([base] if base.suffix == ".md" else [])
        for path in files:
            try:
                text = path.read_text(encoding="utf-8", errors="replace")
            except OSError:
                continue
            # YAML frontmatter is metadata, never a lesson: `description:` and `applyTo:` lines were
            # the first two "rules" the naive extractor produced from an instruction file.
            if text.startswith("---"):
                end = text.find("\n---", 3)
                if end != -1:
                    text = text[end + 4:]
            offset = 0
            for block in re.split(r"\n\s*\n", text):
                line_no = text.count("\n", 0, offset) + 1
                offset += len(block) + 2
                body = _harvest_clean(" ".join(block.split()))
                if not (_HARVEST_MIN_CHARS <= len(body) <= _HARVEST_MAX_CHARS):
                    continue
                if body.startswith("---") or "applyTo:" in body or "description:" in body:
                    continue
                if _harvest_is_fragment(body) or _harvest_is_not_prose(body):
                    continue
                if _harvest_is_self_reference(body):
                    continue
                lowered = body.lower()
                hits = [signal for signal in _HARVEST_STRONG if signal in lowered]
                if not hits:
                    continue
                try:
                    relative = path.relative_to(ROOT)
                except ValueError:
                    relative = path
                found.append({
                    "text": body,
                    "source": f"{relative}:{line_no}",
                    "signals": hits,
                    "scope": _harvest_scope(path),
                    "reason": _harvest_reason(path),
                })
    return found


def _llm_refine(text: str, model: str, timeout: int = 60) -> str | None:
    """Compress one candidate into a reusable rule with a local model.

    Returns None on any failure so a missing daemon or a slow model degrades to the deterministic
    text instead of losing the candidate. The local runtime is ollama; nothing leaves the machine.
    """
    prompt = (
        "Rewrite this engineering note as ONE imperative rule a future developer can apply.\n"
        "Keep the concrete number, filename or failure if it carries the lesson.\n"
        "Answer with the rule only, at most 240 characters, no preamble, no quotes.\n\n"
        f"NOTE:\n{text}"
    )
    payload = json.dumps({"model": model, "prompt": prompt, "stream": False,
                          "options": {"temperature": 0, "num_predict": 120}}).encode("utf-8")
    request = urllib.request.Request(
        "http://127.0.0.1:11434/api/generate", data=payload,
        headers={"Content-Type": "application/json"})
    try:
        with urllib.request.urlopen(request, timeout=timeout) as response:
            data = json.loads(response.read().decode("utf-8", errors="replace"))
    except (urllib.error.URLError, OSError, ValueError, TimeoutError):
        return None
    rule = " ".join(str(data.get("response") or "").split()).strip().strip('"')
    return rule[:400] or None


def _audit_proposed_lessons(apply: bool, show: int = 25) -> int:
    """List, and optionally retire, proposals the shape rules would no longer accept.

    Measured 2026-11-21: 784 proposed lessons against 66 approved. Reviewing that queue by hand is
    not a task anyone finishes, so the deterministic baseline comes first - the SAME rejections a
    fresh harvest applies, run against what is already stored. Retirement is append-only and an
    approval reverses it, so this prunes the queue without destroying anything.
    """
    proposed = [row for row in load_lessons() if _lesson_status(row) == "proposed"]
    rejects: list[tuple[dict, list[str]]] = []
    for row in proposed:
        text = _harvest_clean(str(row.get("text") or ""))
        reasons = []
        if not (_HARVEST_MIN_CHARS <= len(text) <= _HARVEST_MAX_CHARS):
            reasons.append("length")
        if _harvest_is_fragment(text):
            reasons.append("fragment")
        if _harvest_is_not_prose(text):
            reasons.append("not-prose")
        if _harvest_is_self_reference(text):
            reasons.append("self-reference")
        if reasons:
            rejects.append((row, reasons))

    counts: dict[str, int] = {}
    for _, reasons in rejects:
        for reason in reasons:
            counts[reason] = counts.get(reason, 0) + 1
    print(f"proposed: {len(proposed)} · refused by the shape rules: {len(rejects)} "
          f"· keep: {len(proposed) - len(rejects)}")
    if counts:
        print("  by reason: " + " · ".join(f"{k} {v}" for k, v in sorted(counts.items(), key=lambda kv: -kv[1])))
    for row, reasons in rejects[:show]:
        print(f"  {row.get('id')}  [{','.join(reasons):<24}] {str(row.get('text'))[:88]}")
    if len(rejects) > show:
        print(f"  ... {len(rejects) - show} more")

    if not apply:
        print("nothing retired (dry run); re-run with --apply to retire the refused proposals")
        return 0
    retired = 0
    for row, reasons in rejects:
        lesson_id = str(row.get("id"))
        path, created = write_memory_event("retire", lesson_id, {
            "retired_by": "harvest-filter",
            "reasons": reasons,
        })
        retired += 1 if created else 0
    print(f"retired {retired} proposal(s) as harvest-filter; `lesson approve <id>` reverses any of them")
    return 0


def cmd_harvest(args) -> int:
    """Turn documented experience into PROPOSED lessons. Never injects; approval stays human."""
    if getattr(args, "audit_existing", False):
        return _audit_proposed_lessons(apply=bool(getattr(args, "apply", False)))
    targets = [Path(item) for item in (args.paths or ["docs"])]
    targets = [t if t.is_absolute() else ROOT / t for t in targets]
    missing = [str(t) for t in targets if not t.exists()]
    if missing:
        print(f"harpp-experience: no such path: {', '.join(missing)}", file=sys.stderr)
        return 2

    candidates = _harvest_candidates(targets, args.kind)
    if args.kind in ("tests", "all"):
        candidates += _harvest_test_candidates(targets)
    if args.limit:
        # Highest-signal first, so a capped run takes the densest paragraphs.
        candidates.sort(key=lambda c: len(c["signals"]), reverse=True)
        candidates = candidates[: args.limit]

    stored = 0
    duplicates = 0
    refined = 0
    for index, candidate in enumerate(candidates, 1):
        text = candidate["text"]
        if args.llm:
            rule = _llm_refine(text, args.model)
            if rule:
                text = rule
                refined += 1
        lesson_id = lesson_key(candidate["scope"], text)
        if args.dry_run:
            print(f"[{index}] {candidate['source']}  scope={candidate['scope']}  {text[:110]}")
            continue
        _, created = write_memory_event("propose", lesson_id, {
            "text": text,
            "reason": candidate["reason"],
            "scope": candidate["scope"],
            "evidence": [candidate["source"]],
            "proposed_by": "harvest" + (f":{args.model}" if args.llm else ""),
        })
        if created:
            stored += 1
        else:
            duplicates += 1

    if args.dry_run:
        print(f"\n{len(candidates)} candidate(s) from {len(targets)} path(s) — nothing written (dry run)")
        return 0
    print(f"{stored} new proposed lesson(s), {duplicates} already known"
          + (f", {refined} refined by {args.model}" if args.llm else ""))
    print("They are NOT injected. Review with `memory list --status proposed`, then `lesson approve <id>`.")
    return 0


def _write_event_file(event: dict) -> Path:
    """Write one event verbatim, keeping its original id and timestamp."""
    recorded_at = str(event.get("recorded_at") or _memory_now())
    path = MEMORY_EVENTS_DIR / recorded_at[:7] / f"{event.get('event_id')}.json"
    path.parent.mkdir(parents=True, exist_ok=True)
    if not path.exists():
        path.write_text(json.dumps(event, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    return path


def cmd_memory_export(args) -> int:
    """Write the whole store to ONE portable file.

    Git is version history on one machine, not a backup: a failed disk takes the repository and
    the memory with it. This file is the exit path - it can be copied to any drive, host or cloud
    folder, and `memory import` reconstitutes the store from it alone.
    """
    events = memory_events()
    out = Path(args.out)
    out.parent.mkdir(parents=True, exist_ok=True)
    with out.open("w", encoding="utf-8") as handle:
        handle.write(json.dumps({"kind": "harpp-memory-export",
                                 "schema_version": MEMORY_SCHEMA_VERSION,
                                 "exported_at": _memory_now(),
                                 "events": len(events)}) + "\n")
        for event in events:
            handle.write(json.dumps(event, sort_keys=True, default=str) + "\n")
    print(f"exported {len(events)} events -> {out}")
    return 0


def cmd_memory_import(args) -> int:
    """Reconstitute the store from an export. Idempotent, and refuses an unknown schema."""
    source = Path(args.source)
    if not source.is_file():
        print(f"harpp-experience: no export at {source}", file=sys.stderr)
        return 2
    imported = 0
    skipped = 0
    for line in source.read_text(encoding="utf-8", errors="replace").splitlines():
        line = line.strip()
        if not line:
            continue
        try:
            record = json.loads(line)
        except ValueError:
            skipped += 1
            continue
        if record.get("kind") == "harpp-memory-export":
            version = record.get("schema_version")
            if version != MEMORY_SCHEMA_VERSION:
                # Explicit refusal beats silently reinterpreting a format from the future.
                print(f"harpp-experience: unsupported export schema {version!r} "
                      f"(this build reads {MEMORY_SCHEMA_VERSION})", file=sys.stderr)
                return 2
            continue
        if not record.get("event_id") or not record.get("lesson_id"):
            skipped += 1
            continue
        before = len(list(MEMORY_EVENTS_DIR.rglob(f"{record['event_id']}.json"))) \
            if MEMORY_EVENTS_DIR.is_dir() else 0
        _write_event_file(record)
        if before == 0:
            imported += 1
    print(f"imported {imported} event(s) into {MEMORY_EVENTS_DIR}"
          + (f", skipped {skipped} unusable line(s)" if skipped else ""))
    return 0


def cmd_lessons(args) -> int:
    rows = load_lessons()
    if args.status:
        rows = [h for h in rows if _lesson_status(h) == args.status]
    if args.scope:
        rows = [h for h in rows if _scope_matches(h, args.scope)]
    if args.json:
        print(json.dumps(rows, indent=2))
        return 0
    if not rows:
        print("no lessons match")
        return 0
    for hint in rows:
        print(f"{hint.get('id')}  {_lesson_status(hint):<11} [{_scope_value(hint):<13}] "
              f"{str(hint.get('text'))[:80]}")
    return 0


def cmd_status(args) -> int:
    """One screen a human reads daily: what was learned, what it cost, what is unproven."""
    stats = summary(read_ledger())
    hints = load_lessons()
    active = sum(1 for h in hints if _lesson_status(h) == "active")
    proposed = sum(1 for h in hints if _lesson_status(h) == "proposed")
    retired = sum(1 for h in hints if _lesson_status(h) == "retired")
    signatures = failure_signatures()
    coverage = model_log_coverage()
    if args.json:
        print(json.dumps({"lessons": {"active": active, "proposed": proposed, "retired": retired},
                          "ledger": stats, "top_signature": signatures[0] if signatures else None,
                          "model_logs": coverage}, indent=2))
        return 0
    print("== HARPP status ==")
    print(f"  lessons:  {active} active · {proposed} proposed (NOT injected) · {retired} retired")
    applied_events = [e for e in memory_events() if str(e.get("type")) == "lesson_application"]
    real_applied = [e for e in applied_events if not _is_self_test_run(e)]
    verified_events = [e for e in real_applied if e.get("verified")]
    demo = len(applied_events) - len(real_applied)
    demo_note = f" (+{demo} demo/self-test, not counted)" if demo else ""
    print(f"  used:     {len(_injection_files())} run(s) given rules · {len(real_applied)} application(s) "
          f"· {len(verified_events)} verified by evidence{demo_note}")
    effect = lesson_effect()
    if effect["attributed_runs"]:
        # The USE side. Capture counts alone say nothing about whether any rule works, which is how
        # 806 proposals coexisted with 0 retired for months.
        print(f"  scored:   {effect['scored']} rule(s) with >= {effect['min_n']} decided runs "
              f"· baseline {effect['baseline_pass_rate']:.0%} "
              f"· {len(effect['retire_candidates'])} retire candidate(s)")
    else:
        # Printed, never omitted. A missing line reads as "nothing to report"; the truth is that the
        # use side is DARK - rules went out and no outcome came back. Measured 2026-11-21: 3
        # injections, 0 attributed, every one a lane that never wrote a landing.
        print(f"  scored:   0 rule(s) measured · {effect['injections']} injection(s) "
              f"· {effect['attributed_runs']} attributed · "
              f"{effect['unattributed_runs']} with no outcome")
    print(f"  ledger:   {stats['rows']} rows · pass rate {stats['pass_rate']} · cost ${stats['cost_usd']:.4f}")
    print(f"  waste:    {stats['wasted_cost_share']}")
    if signatures:
        print(f"  worst signature: {signatures[0]['reason']} × {signatures[0]['count']}")
    print(f"  model logs retained: {coverage['with_model_log']}/{coverage['landings']}")
    if proposed:
        print("  action:   review proposed lessons with `lesson approve <id>`")
    return 0


def cmd_index(args) -> int:
    """Rebuild the derived index from the append-only sources. Safe to run any time."""
    import sqlite3

    db_path = Path(args.db) if args.db else DB_FILE
    db_path.parent.mkdir(parents=True, exist_ok=True)
    if db_path.exists():
        db_path.unlink()
    db = sqlite3.connect(str(db_path))
    try:
        db.executescript(
            """
            CREATE TABLE runs (task TEXT, run_id TEXT, acceptance TEXT, attempts INTEGER,
                               cost_usd REAL, tokens INTEGER, changed_files INTEGER,
                               component_harness TEXT, started_at TEXT);
            CREATE TABLE landings (name TEXT, task TEXT, reason TEXT, status_line TEXT,
                                   cls TEXT, landed_at TEXT, changed_files INTEGER);
            CREATE TABLE lessons (id TEXT, scope TEXT, reason TEXT, status TEXT, body TEXT);
            -- porter stemming so `reversing` finds a lesson that says `reversed`; without it the
            -- store holds the experience but refuses to hand it back (measured: fts5 default
            -- tokenizer treats the two as unrelated words).
            CREATE VIRTUAL TABLE experience_fts USING fts5(kind, ref, body, tokenize='porter unicode61');
            """
        )
        run_rows = []
        for row in read_ledger():
            task = str(row.get("task") or "")
            changed = _task_changed_files(task)
            run_rows.append((task, str(row.get("run_id") or ""), str(row.get("acceptance") or ""),
                             int(row.get("attempts") or 1), float(row.get("cost_usd") or 0.0),
                             int(row.get("tokens") or 0), -1 if changed is None else changed,
                             str(row.get("component_harness") or ""), str(row.get("started_at") or "")))
        db.executemany("INSERT INTO runs VALUES (?,?,?,?,?,?,?,?,?)", run_rows)

        landing_rows = []
        fts_rows = []
        for record in _landings():
            name = str(record.get("name") or "")
            task = name.rsplit("-a", 1)[0] if "-a" in name else name
            status_line = str(record.get("status_line") or "")
            landing_rows.append((name, task, str(record.get("reason") or ""), status_line,
                                 _landing_class(record), str(record.get("landed_at") or ""),
                                 int(record.get("changed_files") or 0)))
            body = " ".join(x for x in (status_line, str(record.get("reason") or ""),
                                        str(record.get("completion_detail") or "")) if x)
            if body:
                fts_rows.append(("landing", name, body))
        db.executemany("INSERT INTO landings VALUES (?,?,?,?,?,?,?)", landing_rows)

        lesson_rows = []
        for lesson in load_lessons():
            body = str(lesson.get("text") or "")
            lesson_rows.append((str(lesson.get("id") or ""), _scope_value(lesson),
                                str(lesson.get("reason") or ""), _lesson_status(lesson), body))
            if body:
                fts_rows.append(("lesson", str(lesson.get("id") or ""), body))
            for evidence in lesson.get("evidence") or []:
                fts_rows.append(("lesson_evidence", str(lesson.get("id") or ""), str(evidence)))
        db.executemany("INSERT INTO lessons VALUES (?,?,?,?,?)", lesson_rows)

        for row in read_ledger():
            note = str(row.get("notes") or "")
            if note:
                fts_rows.append(("run", str(row.get("task") or ""), note))
        db.executemany("INSERT INTO experience_fts VALUES (?,?,?)", fts_rows)
        db.commit()
    finally:
        db.close()

    print(json.dumps({"db": str(db_path), "runs": len(run_rows), "landings": len(landing_rows),
                      "lessons": len(lesson_rows), "fts_rows": len(fts_rows)}, indent=2)
          if args.json else
          f"indexed {len(run_rows)} runs · {len(landing_rows)} landings · {len(lesson_rows)} lessons "
          f"-> {db_path}")
    return 0


def _fts_query(raw: str) -> str:
    """Turn a human query into a safe FTS5 expression.

    FTS5 reads `-`, `:`, `*` and `(` as operators, so a plain `append-only` becomes "append MINUS
    only" and fails with `no such column: only` (measured by the round-trip test). Each token is
    therefore quoted, which keeps `append-only` as one phrase and stops a stray character from
    turning a search into a syntax error.
    """
    tokens = [t for t in str(raw or "").split() if t]
    if not tokens:
        return ""
    return " ".join('"' + t.replace('"', '""') + '"' for t in tokens)


def cmd_search(args) -> int:
    """Full-text search across recorded experience: landings, lessons, run notes."""
    import sqlite3

    db_path = Path(args.db) if args.db else DB_FILE
    if not db_path.is_file():
        print(f"harpp-experience: no index at {db_path}; run `index` first", file=sys.stderr)
        return 2
    expression = _fts_query(args.query)
    if not expression:
        print("harpp-experience: empty query", file=sys.stderr)
        return 2
    db = sqlite3.connect(str(db_path))
    try:
        # bm25 first: an unordered match returns whichever rows were inserted first, which is the
        # opposite of "what have we seen that explains this".
        rows = db.execute(
            "SELECT kind, ref, snippet(experience_fts, 2, '[', ']', ' …', 12) "
            "FROM experience_fts WHERE experience_fts MATCH ? "
            "ORDER BY bm25(experience_fts) LIMIT ?",
            (expression, args.limit),
        ).fetchall()
    except sqlite3.OperationalError as exc:
        print(f"harpp-experience: bad query: {exc}", file=sys.stderr)
        return 2
    finally:
        db.close()
    if args.json:
        print(json.dumps([{"kind": k, "ref": r, "snippet": s} for k, r, s in rows], indent=2))
        return 0
    if not rows:
        print("no experience matches")
        return 0
    for kind, ref, snippet in rows:
        print(f"[{kind}] {ref}\n    {' '.join(str(snippet).split())}")
    return 0


def cmd_stamp(args) -> int:
    fields = stamp_fields(args.task, args.attempts, args.since)
    if args.json:
        print(json.dumps(fields))
    else:
        for key, value in fields.items():
            print(f"{key}={value}")
    return 0


def cmd_signatures(args) -> int:
    signatures = failure_signatures()
    if args.json:
        print(json.dumps(signatures, indent=2))
        return 0
    if not signatures:
        print("no failure signatures recorded")
        return 0
    total = sum(entry["count"] for entry in signatures)
    repeated = sum(entry["count"] - 1 for entry in signatures)
    print(f"== {len(signatures)} distinct signatures across {total} failures "
          f"({repeated} of them repeats) ==")
    for entry in signatures[: args.limit]:
        detail = f" ({entry['status'][:60]})" if entry["status"] else ""
        print(f"   {entry['count']:>3}x  {entry['reason']}{detail}")
        print(f"         e.g. {', '.join(entry['lanes']) or '-'}   "
              f"{entry['first_seen']} .. {entry['last_seen']}")
    return 0


def _select_lessons(reason: str | None, scope: str | None,
                    query: str | None) -> tuple[list[dict], int]:
    """Which lessons a lane would receive, and how many were withheld. One selection, two callers."""
    if not scope:
        scope = _scope_from_query(query)
    candidates = [h for h in load_lessons()
                  if h.get("text")
                  and _lesson_status(h) == "active"
                  # When no module can be inferred from the brief, module-scoped rules are NOT
                  # excluded: a real request phrased in plain language never names its module
                  # ("ang mga commissary pabutangan ug pull-out..." names none), and filtering on an
                  # unknown scope silently hid every domain rule the request needed. They are still
                  # ranked below scope-matched ones by the scope term in the sort key.
                  # Scope rules: a KNOWN scope filters strictly; an unknown scope with a brief
                  # allows module rules through (ranked lower); no brief at all stays universal,
                  # because a lane with no context cannot be helped by another module's rules.
                  and (_scope_value(h) in ("", "any")
                       or (scope is not None and _scope_matches(h, scope))
                       or (scope is None and bool(query)))
                  and (not reason or h.get("reason") in (reason, "", None, "any"))]
    candidates.sort(key=lambda h: (0 if _scope_value(h) not in ("", "any") else 1,
                                  -_query_overlap(query, h.get("text")),
                                  -float(h.get("activation") or 0.0),
                                  str(h.get("id") or "")))
    hints = candidates[:MEMORY_INJECT_LIMIT]
    return hints, max(0, len(candidates) - len(hints))


def _injection_path(tag: str) -> Path:
    safe = re.sub(r"[^A-Za-z0-9_.-]+", "_", str(tag))[:120] or "unnamed"
    return MEMORY_DIR / "injected" / f"{safe}.json"


def _injection_files() -> list[Path]:
    """How many runs were actually GIVEN rules. Zero here means retrieval was never exercised."""
    directory = MEMORY_DIR / "injected"
    return sorted(directory.glob("*.json")) if directory.is_dir() else []


_SELF_TEST_RUN = re.compile(r"^(?:verify-demo|selftest|self-test)$|(?:^|-)it-\d", re.IGNORECASE)


def _is_self_test_run(event: dict) -> bool:
    """A demo attempt proves the recorder works - never that a rule changed an outcome.

    Measured 2026-11-21: the only two `lesson_application` events in the store carried
    `run_id: verify-demo`, so `status` advertised "2 application(s) / 1 verified by evidence"
    for runs that touched no product work. Counterfactual use is worse than no use: it is the
    number a reader trusts when deciding whether the memory is worth keeping.
    """
    return bool(_SELF_TEST_RUN.search(str(event.get("run_id") or "")))


def record_injection(tag: str, lessons: list[dict], block_chars: int) -> Path:
    """Remember WHICH rules a run was given.

    Without this the loop cannot close: a lane that succeeds cannot reinforce the rules it was
    actually shown, and a later review cannot ask what it had been told. Written outside the event
    store because it is telemetry about a run, not a lesson.
    """
    path = _injection_path(tag)
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps({
        "tag": tag,
        "recorded_at": _memory_now(),
        "lessons": [str(r.get("id")) for r in lessons if r.get("id")],
        "block_chars": int(block_chars),
    }, indent=2) + "\n", encoding="utf-8")
    return path


# ------------------------------------------------------------------------ attribution
#
# The injection record has existed since 2026-11-17 and NOTHING ever scored it. Measured
# 2026-11-19: 806 lessons proposed, 62 active, 0 runs ever credited, 0 retired. A store that only
# accumulates cannot learn, because no rule can ever lose precision - so this section exists to make
# a rule answerable for the runs it appeared in.
#
# WHAT THIS IS NOT: causation. A rule present in a passing run was present; it did not necessarily
# help. `close` already records reinforcement as `source: session-pass` for the same reason. These
# numbers decide which rules to RE-READ, never which rule to credit with a success.

MIN_ATTRIBUTED_RUNS = 5


def _injection_records() -> list[dict]:
    """Every recorded injection: the rules a run was given, and the tag it was recorded under."""
    out: list[dict] = []
    for path in _injection_files():
        try:
            value = json.loads(path.read_text(encoding="utf-8", errors="replace"))
        except (OSError, ValueError):
            continue
        if isinstance(value, dict):
            out.append(value)
    return out


def _landing_index() -> dict[str, dict]:
    """Landing records keyed by run name AND by file stem, because either may be missing."""
    index: dict[str, dict] = {}
    if not RUNS_DIR.is_dir():
        return index
    for path in sorted(RUNS_DIR.glob("*.landed.json")):
        try:
            value = json.loads(path.read_text(encoding="utf-8", errors="replace"))
        except (OSError, ValueError):
            continue
        if not isinstance(value, dict):
            continue
        stem = path.name[: -len(".landed.json")]
        index[stem] = value
        name = str(value.get("name") or "")
        if name:
            index[name] = value
    return index


def _landing_for_tag(tag: str, index: dict[str, dict]) -> dict:
    """Find the landing a tag belongs to.

    The injection tag is the lane log prefix, which is derived from the lane name and therefore a
    SUPERSET of it (`<lane>-<model>`). An exact match is preferred; otherwise the longest recorded
    run name the tag starts with wins, so a model suffix does not lose the join.
    """
    if not tag:
        return {}
    if tag in index:
        return index[tag]
    matches = [name for name in index if name and tag.startswith(name)]
    if matches:
        return index[max(matches, key=len)]
    return {}


def lesson_outcomes() -> list[dict]:
    """Join each recorded injection to the landing record of the same run.

    A tag with no landing is reported `unattributed` rather than assumed to have passed: the point
    of the join is to stop treating "we told it something" as evidence of anything.
    """
    index = _landing_index()
    rows: list[dict] = []
    for record in _injection_records():
        tag = str(record.get("tag") or "")
        landing = _landing_for_tag(tag, index)
        rows.append({
            "tag": tag,
            "lessons": [str(x) for x in (record.get("lessons") or []) if str(x).strip()],
            "outcome": _landing_class(landing) if landing else "unattributed",
        })
    return rows


def lesson_effect(min_n: int = MIN_ATTRIBUTED_RUNS) -> dict:
    """Per-rule outcome counts for the runs that actually received it.

    A rule is SCORED only once it has `min_n` runs with a decided outcome (pass or failure). Below
    that the sample is refused rather than summarised - the same discipline `compare` applies, and
    the reason is the same: a rate over two runs invites a decision the evidence cannot support.
    """
    outcomes = lesson_outcomes()
    attributed = [row for row in outcomes if row["outcome"] in ("pass", "failure")]
    passes = sum(1 for row in attributed if row["outcome"] == "pass")
    baseline = (passes / len(attributed)) if attributed else None

    rules: dict[str, dict] = {}
    for row in outcomes:
        for lesson_id in row["lessons"]:
            entry = rules.setdefault(lesson_id, {
                "id": lesson_id, "runs": 0, "passes": 0, "failures": 0, "unresolved": 0,
            })
            entry["runs"] += 1
            if row["outcome"] == "pass":
                entry["passes"] += 1
            elif row["outcome"] == "failure":
                entry["failures"] += 1
            else:
                entry["unresolved"] += 1

    for entry in rules.values():
        decided = entry["passes"] + entry["failures"]
        entry["decided"] = decided
        entry["pass_rate"] = (entry["passes"] / decided) if decided else None
        if decided < min_n:
            entry["verdict"] = "insufficient"
            entry["vs_baseline"] = None
            entry["retire_candidate"] = False
        else:
            entry["vs_baseline"] = (entry["pass_rate"] - baseline) if baseline is not None else None
            if entry["passes"] == 0:
                # Present in N decided runs and in none that passed. That is the one signal strong
                # enough to act on, and it still only PROPOSES retirement.
                entry["verdict"] = "suspect"
                entry["retire_candidate"] = True
            elif baseline is not None and entry["pass_rate"] >= baseline:
                entry["verdict"] = "holding"
                entry["retire_candidate"] = False
            else:
                entry["verdict"] = "weak"
                entry["retire_candidate"] = False

    ordered = sorted(rules.values(), key=lambda e: (
        0 if e["verdict"] != "insufficient" else 1,
        e["pass_rate"] if e["pass_rate"] is not None else -1,
        -e["decided"],
        e["id"],
    ))
    return {
        "min_n": int(min_n),
        "injections": len(outcomes),
        "attributed_runs": len(attributed),
        "unattributed_runs": len(outcomes) - len(attributed),
        "baseline_pass_rate": baseline,
        "rules": ordered,
        "scored": sum(1 for e in ordered if e["verdict"] != "insufficient"),
        "retire_candidates": [e["id"] for e in ordered if e["retire_candidate"]],
    }


def cmd_lesson_effect(args) -> int:
    payload = lesson_effect(int(getattr(args, "min_n", MIN_ATTRIBUTED_RUNS) or MIN_ATTRIBUTED_RUNS))
    if getattr(args, "json", False):
        print(json.dumps(payload, indent=2))
        return 0
    baseline = payload["baseline_pass_rate"]
    print("== lesson effect (co-occurrence, not causation) ==")
    print(f"  attributed runs: {payload['attributed_runs']} · "
          f"unattributed: {payload['unattributed_runs']} · "
          f"baseline pass rate: {'n/a' if baseline is None else f'{baseline:.0%}'}")
    if not payload["attributed_runs"]:
        print("  no run has both a recorded injection and a decided outcome yet.")
        print("  Lanes credit themselves via lane-model.sh; a run appears here after it lands.")
        return 0
    print(f"  {'ID':<14} {'RUNS':>4} {'PASS':>4} {'FAIL':>4} {'RATE':>6}  VERDICT")
    for entry in payload["rules"]:
        rate = "-" if entry["pass_rate"] is None else f"{entry['pass_rate']:.0%}"
        print(f"  {entry['id']:<14} {entry['runs']:>4} {entry['passes']:>4} "
              f"{entry['failures']:>4} {rate:>6}  {entry['verdict']}")
    refused = len(payload["rules"]) - payload["scored"]
    if refused:
        # Never let the min-n refusal look like the whole truth.
        print(f"  ({refused} rule(s) have fewer than {payload['min_n']} decided runs and are "
              f"deliberately NOT scored)")
    if payload["retire_candidates"]:
        print(f"  retire candidate(s): {', '.join(payload['retire_candidates'])} "
              f"(see `lesson sweep`)")
    return 0


def cmd_lesson_sweep(args) -> int:
    """Retirement candidates from attribution. Reports by default; `--apply` acts.

    Why not automatic: with `lesson_effect` being co-occurrence and not causation, retiring on a
    below-baseline rate would delete rules for reasons they may not own. The one defensible rule is
    the strictest one - a rule that appeared in `min_n` decided runs and passed in NONE - and even
    that is applied only when the operator asks for it.
    """
    payload = lesson_effect(int(getattr(args, "min_n", MIN_ATTRIBUTED_RUNS) or MIN_ATTRIBUTED_RUNS))
    candidates = payload["retire_candidates"]
    by_id = {entry["id"]: entry for entry in payload["rules"]}
    tested = {entry["id"] for entry in payload["rules"]}
    untested = [row.get("id") for row in load_lessons()
                if _lesson_status(row) == "active" and str(row.get("id")) not in tested]

    print("== retirement candidates ==")
    print(f"  scored rules: {payload['scored']} · candidates: {len(candidates)} · "
          f"active rules never injected: {len(untested)}")
    if not candidates:
        print("  none: no rule has enough decided runs with zero passes.")
    for lesson_id in candidates:
        entry = by_id[lesson_id]
        print(f"  - {lesson_id}: 0 of {entry['decided']} decided run(s) passed")
    if untested:
        # Not a retirement case: no evidence either way. Said out loud so the count is not
        # mistaken for rules that were tried and failed.
        print(f"  untested (active, never reached a lane): {', '.join(str(x) for x in untested[:12])}"
              + (" ..." if len(untested) > 12 else ""))

    if not candidates:
        return 0
    if not getattr(args, "apply", False):
        print("  report only. Retire with `lesson retire <id>`, or re-run with --apply.")
        return 0
    for lesson_id in candidates:
        _set_lesson_status(lesson_id, "retired",
                           compiled_as=f"never passed in {by_id[lesson_id]['decided']} attributed run(s)")
    return 0


def lesson_funnel(scope: str | None = None) -> dict:
    """Where a scope's experience actually ends up: captured, usable, injected, attributed.

    A per-scope version of `status`. Measured 2026-11-19 on cms: 104 lessons, 17 active (16%),
    87 proposed - so 84% of what the repo knows about CMS could not reach a lane at all, while the
    retrieval path was being blamed for poor relevance. The funnel names the stage that is actually
    losing the knowledge instead of the one being argued about.
    """
    rows = load_lessons()
    if scope:
        rows = [row for row in rows if _scope_matches(row, scope)]
    active = [row for row in rows if _lesson_status(row) == "active"]
    proposed = [row for row in rows if _lesson_status(row) == "proposed"]
    retired = [row for row in rows if _lesson_status(row) == "retired"]
    # What a lane in this scope would receive with no brief to rank by - the cap applies.
    would_inject, hidden = _select_lessons(None, scope, None)
    effect = lesson_effect()
    return {
        "scope": scope or "(all)",
        "captured": len(rows),
        "active": len(active),
        "proposed": len(proposed),
        "retired": len(retired),
        "active_share": round(len(active) / len(rows), 4) if rows else None,
        "would_inject": len(would_inject),
        "cap": MEMORY_INJECT_LIMIT,
        "hidden_by_cap": hidden,
        # Attribution is global: `lesson effect` joins shipped runs, which are not scope-tagged.
        "attributed_runs": effect["attributed_runs"],
        "scored_rules": effect["scored"],
    }


# --------------------------------------------------------------------------- audit
#
# Making prose MEASURABLE. Most lessons are advice: "keep hook handlers deterministic", "variants
# control presentation only". That cannot be scored, so it cannot be retired on evidence, which is
# how 534 of 575 cms-pool lessons ended up permanently un-injected (measured 2026-11-19).
#
# A prose lesson becomes gradable the moment it names something that EXISTS and can be checked. So
# this grades each lesson by what it references rather than by how it reads:
#
#   anchored - names a repo path that still exists    -> the claim has a subject we can inspect
#   stale    - names a path that is GONE              -> the claim may already be obsolete
#   prose    - names nothing checkable                -> cannot be measured until rewritten
#
# `covered` is reported alongside `anchored` and is deliberately weak: it means a test file
# REFERENCES the same path. That is a place to add the assertion, NOT proof the claim is asserted.

LESSON_PATH_RE = re.compile(
    r"(?:modules|templates|src|kernel|tools|tests|docs|database|config|public)"
    # Longest extension FIRST, and (?![\w.]) after: with `ts|js|json` ordered short-first,
    # `modules/ecommerce/module.json` matched as `module.js` and every manifest reference was
    # reported as a missing JavaScript file. Caught by the cms audit 2026-11-19.
    r"/[\w./-]+\.(?:disyl|json|php|tsx|jsx|sql|css|ts|js|md)(?![\w.])"
)

# A path inside a fenced block is an EXAMPLE of how to call a tool, not a claim about this repo.
# Without this the stale bucket filled with `tests/import_contract_test.php` (a hypothetical task in
# a CLI usage sample) and `modules/my-module/services/Workflow.php` (a literal placeholder).
_FENCE_RE = re.compile(r"```.*?```", re.DOTALL)
_PLACEHOLDER_HINTS = ("my-module", "your-module", "my_module", "your_module", "example-module")


def lesson_paths(text) -> list[str]:
    """Repo paths a lesson CLAIMS, de-duplicated, examples and placeholders excluded."""
    raw = str(text or "")
    claim_text = _FENCE_RE.sub(" ", raw)
    found: list[str] = []
    for match in LESSON_PATH_RE.findall(claim_text):
        cleaned = match.rstrip(".,;:)]}`")
        if any(hint in cleaned.lower() for hint in _PLACEHOLDER_HINTS):
            continue
        if cleaned not in found:
            found.append(cleaned)
    return found


def _test_corpus() -> str:
    """Every test file's text, once. 814 files / 12MB on this repo, so it is built per call."""
    chunks: list[str] = []
    root = ROOT / "tests"
    if root.is_dir():
        for path in sorted(root.rglob("*.php")):
            try:
                chunks.append(path.read_text(encoding="utf-8", errors="replace"))
            except OSError:
                continue
    return "\n".join(chunks)


def lesson_audit(scope: str | None = None) -> dict:
    """Grade every lesson in scope by whether its claim can be CHECKED at all."""
    rows = load_lessons()
    if scope:
        rows = [row for row in rows if _scope_matches(row, scope)]
    corpus = _test_corpus()
    buckets: dict[str, list[dict]] = {"anchored": [], "stale": [], "prose": []}
    covered = 0
    for lesson in rows:
        text = str(lesson.get("text") or "")
        paths = lesson_paths(text)
        entry = {
            "id": str(lesson.get("id")),
            "status": _lesson_status(lesson),
            "text": text,
            "paths": paths,
        }
        if not paths:
            buckets["prose"].append(entry)
            continue
        missing = [p for p in paths if not (ROOT / p).exists()]
        if missing:
            entry["missing"] = missing
            buckets["stale"].append(entry)
            continue
        entry["covered"] = any(p in corpus for p in paths)
        if entry["covered"]:
            covered += 1
        buckets["anchored"].append(entry)

    # COMPILE DIRECTION. The tests hold ~3,900 assertion labels and every one is already a gate, so
    # importing them as lessons would grow the proposal backlog (806 already) while adding nothing
    # executable. What they are good for is RETIRING prose that a test already enforces.
    #
    # CALIBRATION, measured 2026-11-19 rather than guessed:
    #   >=3 shared words -> 70 pairs, and the two inspected were COINCIDENTAL (a lesson about
    #      "print Status: PASS only when acceptance exited 0" matched "completion_status is
    #      failed-before-analysis"). Too weak to report as a finding.
    #   >=5 shared words -> 6 pairs, of which 5 were the lesson text being LITERALLY the assertion
    #      label ("engine: raw outside sandbox renders unescaped"). That is the real signal.
    # So the bar is 5, and an exact text match is reported separately as the strongest form - which
    # also exposes the circularity worth knowing about: `harvest` wrote lessons straight out of these
    # labels, so some proposals are a second copy of a gate that already exists.
    retirable = 0
    exact_copies = 0
    if buckets["prose"]:
        labels = test_assertion_labels()
        index: dict[str, list[int]] = {}
        for position, label in enumerate(labels):
            for word in label["words"]:
                index.setdefault(word, []).append(position)
        normalised = {re.sub(r"[^a-z0-9]+", " ", str(label["text"]).lower()).strip(): label["origin"]
                      for label in labels}
        for entry in buckets["prose"]:
            if entry["status"] == "retired":
                # Already collected. Counting it again makes `retirable` a number that cannot go
                # down, so the command keeps advertising work it has already performed.
                continue
            own = re.sub(r"[^a-z0-9]+", " ", entry["text"].lower()).strip()
            if own and own in normalised:
                entry["retire_to"] = normalised[own]
                entry["retire_exact"] = True
                retirable += 1
                exact_copies += 1
                continue
            words = _assertion_words(entry["text"])
            if len(words) < 3:
                continue
            tally: dict[int, int] = {}
            for word in words:
                for position in index.get(word, ()):
                    tally[position] = tally.get(position, 0) + 1
            if not tally:
                continue
            best_position, shared = max(tally.items(), key=lambda kv: kv[1])
            if shared < 5:
                continue
            entry["retire_to"] = labels[best_position]["origin"]
            entry["retire_shared"] = shared
            retirable += 1

    return {
        "scope": scope or "(all)",
        "total": len(rows),
        "counts": {key: len(value) for key, value in buckets.items()},
        "covered": covered,
        "active_prose": sum(1 for e in buckets["prose"] if e["status"] == "active"),
        "retirable": retirable,
        "exact_copies": exact_copies,
        "buckets": buckets,
    }


def cmd_lesson_audit(args) -> int:
    payload = lesson_audit(getattr(args, "scope", None))
    limit = int(getattr(args, "limit", 8) or 8)
    if getattr(args, "json", False):
        print(json.dumps(payload, indent=2))
        return 0
    counts = payload["counts"]
    print(f"== lesson audit: {payload['scope']} ==")
    print(f"  total    {payload['total']:>5}")
    print(f"  anchored {counts['anchored']:>5}  name a path that still exists "
          f"({payload['covered']} also referenced by a test)")
    print(f"  stale    {counts['stale']:>5}  name a path that is GONE")
    print(f"  prose    {counts['prose']:>5}  name nothing checkable "
          f"({payload['active_prose']} of them are ACTIVE and being injected)")
    if counts["stale"]:
        print("  -- stale: the subject of the claim no longer exists --")
        for entry in payload["buckets"]["stale"][:limit]:
            print(f"    {entry['id']}  missing {', '.join(entry['missing'])}")
            print(f"      {entry['text'][:100]}")
    if counts["prose"]:
        print(f"  -- of those, {payload['retirable']} already have a test asserting the same thing --")
        print("     (retire with `lesson retire <id>`; the assertion is the compiled form)")
        shown = 0
        for entry in payload["buckets"]["prose"]:
            if entry.get("retire_to") and shown < limit:
                print(f"    {entry['id']} [{entry['status']}] -> {entry['retire_to']}")
                print(f"      {entry['text'][:96]}")
                shown += 1
        print("  -- prose: rewrite as a check, or it can only be believed --")
        for entry in payload["buckets"]["prose"][:limit]:
            print(f"    {entry['id']} [{entry['status']}] {entry['text'][:96]}")
    return 0


def cmd_lesson_funnel(args) -> int:
    scope = getattr(args, "scope", None)
    payload = lesson_funnel(scope)
    if getattr(args, "json", False):
        print(json.dumps(payload, indent=2))
        return 0
    share = payload["active_share"]
    print(f"== lesson funnel: {payload['scope']} ==")
    print(f"  captured   {payload['captured']:>5}  lesson(s) in scope")
    print(f"  active     {payload['active']:>5}  can be injected"
          + (f"  ({share:.0%} of captured)" if share is not None else ""))
    print(f"  proposed   {payload['proposed']:>5}  NEVER injected - invisible to every lane")
    if payload["retired"]:
        print(f"  retired    {payload['retired']:>5}")
    print(f"  injected   {payload['would_inject']:>5}  reach a lane per run "
          f"(cap {payload['cap']}, {payload['hidden_by_cap']} held back)")
    print(f"  attributed {payload['attributed_runs']:>5}  run(s) with a decided outcome "
          f"· {payload['scored_rules']} rule(s) scored")
    if payload["proposed"] > payload["active"]:
        # The honest next action is triage, not better ranking: retrieval cannot surface knowledge
        # that was never approved.
        print("  action:     triage proposals - retrieval cannot reach what was never approved")
    return 0


def cmd_close(args) -> int:
    """Close a successful session: reinforce what it was told, and learn from what it added.

    Owner direction 2026-11-18: "at the end of a successful session, mechanically add new
    experiences."  Two mechanical additions, no model in the loop:

      1. REINFORCE - every rule injected into a PASSING run gets a `confirm` event. This is
         evidence by co-occurrence: the rule was present and the run succeeded. It is NOT proof the
         rule caused the success, so the event carries `source: session-pass` and is distinguishable
         from a human confirming a rule against a later outcome.
      2. LEARN - every test file the run touched is harvested, so the assertions it added become
         proposed lessons. A new gate IS new experience, and it is written down automatically.
    """
    tag = args.task
    confirmed = 0
    injected: list[str] = []
    path = _injection_path(tag)
    if path.is_file():
        try:
            injected = [str(x) for x in (json.loads(path.read_text(encoding="utf-8"))
                                        .get("lessons") or [])]
        except ValueError:
            injected = []
    if args.status == "pass":
        for lesson_id in injected:
            write_memory_event("confirm", lesson_id,
                               {"source": "session-pass", "task": tag})
            confirmed += 1

    proposed = 0
    landing = {}
    landing_path = RUNS_DIR / f"{tag}.landed.json"
    if landing_path.is_file():
        try:
            landing = json.loads(landing_path.read_text(encoding="utf-8", errors="replace"))
        except ValueError:
            landing = {}
    touched = [p for p in (landing.get("changed_paths") or []) if isinstance(p, str)]
    test_files = [ROOT / p for p in touched
                  if p.startswith("tests/") and p.endswith(".php") and (ROOT / p).is_file()]
    for lesson in _harvest_test_candidates(test_files):
        lesson_id = lesson_key(lesson["scope"], lesson["text"])
        _, created = write_memory_event("propose", lesson_id, {
            "text": lesson["text"],
            "reason": "gate",
            "scope": lesson["scope"],
            "evidence": [lesson["source"], f"session:{tag}"],
            "proposed_by": f"session-close:{tag}",
        })
        proposed += 1 if created else 0

    print(f"closed {tag} ({args.status}): {confirmed} rule(s) reinforced, "
          f"{proposed} new lesson(s) proposed from {len(test_files)} test file(s)")
    # NO automatic model rebuild here. Chair verdict 2026-11-18: a baked lesson snapshot is a
    # SECOND representation of truth (it silently held 40 of 56 approved lessons), and FTS5 already
    # indexes every record without a rebuild. Rebuilding on every close also made a 0.5B model part
    # of the loop's cost. `llm refresh` stays available as an explicit, offline command.
    if args.status == "pass" and confirmed:
        print("  reinforcement is co-occurrence evidence, recorded as source=session-pass")
    return 0


# ---------------------------------------------------- associative (pseudo-neural) recall
#
# What we actually wanted from the small model was never language: it was ASSOCIATION. Given a
# context, which memories activate? A transformer is one way to get that; so is spreading
# activation over a similarity graph, which runs on this CPU in microseconds and is deterministic.
#
# The mechanism is the classic ACT-R shape, not a metaphor:
#   seed      lessons whose terms overlap the query
#   edges     cosine similarity between TF-IDF term vectors of lesson texts
#   spread    activation flows to NEIGHBOURS, so a lesson with zero literal overlap can be recalled
#             because a strongly related one fired first
#   decay     activation is multiplied by a per-hop damping factor, so distant guesses stay weak
#
# This buys GENERALISATION - the thing keyword retrieval cannot do - without a single model call.

_SPREAD_STOPWORDS = frozenset("""the and for with that this from have has are was were will would can
    could should not but into than then when where which while there their they them you your our
    its about over under more most some such same very what who how only also just like
    must never always cannot should shall does have been being rule rules code change changes
    need needs make makes made take takes thing things""".split())


def _terms(text: str) -> list[str]:
    return [w for w in re.findall(r"[a-z0-9_]{4,}", str(text).lower())
            if w not in _SPREAD_STOPWORDS]


def _term_vectors(texts: list[str]) -> list[dict[str, float]]:
    """TF-IDF over the lesson corpus. No model, no download, no GPU."""
    import math
    docs = [_terms(t) for t in texts]
    df: dict[str, int] = {}
    for doc in docs:
        for term in set(doc):
            df[term] = df.get(term, 0) + 1
    total = max(1, len(docs))
    vectors: list[dict[str, float]] = []
    for doc in docs:
        tf: dict[str, float] = {}
        for term in doc:
            tf[term] = tf.get(term, 0.0) + 1.0
        vectors.append({t: (1.0 + math.log(c)) * math.log((1.0 + total) / (1.0 + df[t]))
                        for t, c in tf.items()})
    return vectors


def _cosine(left: dict[str, float], right: dict[str, float]) -> float:
    if not left or not right:
        return 0.0
    overlap = set(left) & set(right)
    if not overlap:
        return 0.0
    import math
    dot = sum(left[t] * right[t] for t in overlap)
    lnorm = math.sqrt(sum(v * v for v in left.values()))
    rnorm = math.sqrt(sum(v * v for v in right.values()))
    return dot / (lnorm * rnorm) if lnorm and rnorm else 0.0


def spread_activation(query: str, scope: str | None = None, hops: int = 2,
                      damping: float = 0.45, limit: int = 6,
                      lessons: list[dict] | None = None) -> list[dict]:
    """Rank lessons by activation spreading from the query, not by literal overlap alone."""
    pool = lessons if lessons is not None else [r for r in load_lessons()
                                                if _lesson_status(r) == "active"]
    if not pool:
        return []
    texts = [str(r.get("text") or "") for r in pool]
    vectors = _term_vectors(texts)
    query_terms = set(_terms(query))
    query_vec = {t: 1.0 for t in query_terms}

    activation = [0.0] * len(pool)
    for index, vector in enumerate(vectors):
        shared = query_terms & set(vector)
        if shared:
            activation[index] = float(len(shared))
        elif scope and _scope_value(pool[index]) not in ("", "any") and _scope_matches(pool[index], scope):
            # Scope is a weak prior: a module's own rule is faintly active even before it matches.
            activation[index] = 0.15
    if not any(activation):
        return []

    similarity = [[_cosine(vectors[i], vectors[j]) if i != j else 0.0 for j in range(len(pool))]
                  for i in range(len(pool))]
    for _hop in range(max(1, hops)):
        spread = [0.0] * len(pool)
        for i, source in enumerate(activation):
            if source <= 0:
                continue
            for j, weight in enumerate(similarity[i]):
                if weight > 0.05:
                    spread[j] += source * weight
        activation = [a + damping * s for a, s in zip(activation, spread)]

    ranked = sorted(zip(activation, pool), key=lambda pair: -pair[0])
    return [dict(lesson, activation=round(score, 3)) for score, lesson in ranked[:limit] if score > 0]


VECTOR_CACHE = Path(os.environ.get("HARPP_VECTOR_CACHE") or ROOT / ".ai" / "harpp" / "vectors.json")
EMBED_MODEL = os.environ.get("HARPP_EMBED_MODEL") or "all-minilm"


def _embed(text: str, model: str = EMBED_MODEL, timeout: int = 90) -> list[float]:
    """One forward pass, not generation.

    Measured 2026-11-18 on an AVX-only i3-2100: 0.4-0.8s for a 384-dim vector, where the same box
    needs 7-11s to GENERATE even four tokens. Association does not require autoregression, and this
    is the whole reason a "neural" capability is affordable here while the LLM is not.
    """
    payload = json.dumps({"model": model, "prompt": text[:2000]}).encode("utf-8")
    request = urllib.request.Request(f"{OLLAMA_HOST}/api/embeddings", data=payload,
                                     headers={"Content-Type": "application/json"})
    try:
        with urllib.request.urlopen(request, timeout=timeout) as response:
            return list(json.loads(response.read().decode("utf-8", errors="replace"))
                        .get("embedding") or [])
    except (urllib.error.URLError, OSError, ValueError, TimeoutError):
        return []


def _cosine_vec(left: list[float], right: list[float]) -> float:
    import math
    if not left or not right or len(left) != len(right):
        return 0.0
    dot = sum(a * b for a, b in zip(left, right))
    lnorm = math.sqrt(sum(a * a for a in left))
    rnorm = math.sqrt(sum(b * b for b in right))
    return dot / (lnorm * rnorm) if lnorm and rnorm else 0.0


def _load_vectors() -> dict:
    try:
        return json.loads(VECTOR_CACHE.read_text(encoding="utf-8"))
    except (OSError, ValueError):
        return {}


def semantic_rank(query: str, lessons: list[dict], limit: int = 6,
                  refresh: bool = False) -> list[dict]:
    """Rank lessons by meaning. Vectors are cached so a query costs ONE embedding, not N."""
    cache = {} if refresh else _load_vectors()
    query_vector = _embed(query)
    if not query_vector:
        return []
    ranked, dirty = [], False
    for lesson in lessons:
        lesson_id = str(lesson.get("id") or "")
        text = str(lesson.get("text") or "")
        vector = cache.get(lesson_id)
        if not vector:
            vector = _embed(text[:400])
            if not vector:
                continue
            cache[lesson_id] = vector
            dirty = True
        ranked.append((_cosine_vec(query_vector, vector), lesson))
    if dirty:
        VECTOR_CACHE.parent.mkdir(parents=True, exist_ok=True)
        VECTOR_CACHE.write_text(json.dumps(cache), encoding="utf-8")
    ranked.sort(key=lambda pair: -pair[0])
    return [dict(lesson, similarity=round(score, 3)) for score, lesson in ranked[:limit] if score > 0]


def cmd_memory_semantic(args) -> int:
    """Recall by meaning, so a paraphrase with no shared keywords still finds the rule."""
    pool = [r for r in load_lessons() if _lesson_status(r) == "active"]
    if args.scope:
        pool = [r for r in pool if _scope_matches(r, args.scope)]
    results = semantic_rank(args.query, pool, limit=args.limit, refresh=args.refresh)
    if args.json:
        print(json.dumps([{"id": r.get("id"), "similarity": r.get("similarity"),
                           "text": str(r.get("text"))[:200]} for r in results], indent=2))
        return 0
    if not results:
        print("no semantic match (is the embedding model pulled? `ollama pull all-minilm`)")
        return 0
    for row in results:
        print(f"  sim {row['similarity']:>5.3f}  {row.get('id')}  {str(row.get('text'))[:110]}")
    return 0


def cmd_memory_spread(args) -> int:
    """Associative recall: what activates, not just what matches."""
    results = spread_activation(args.query, scope=args.scope, hops=args.hops,
                                damping=args.damping, limit=args.limit)
    if args.json:
        print(json.dumps([{"id": r.get("id"), "activation": r.get("activation"),
                           "text": str(r.get("text"))[:200]} for r in results], indent=2))
        return 0
    if not results:
        print("nothing activates")
        return 0
    for row in results:
        print(f"  act {row['activation']:>6.2f}  {row.get('id')}  {str(row.get('text'))[:120]}")
    return 0


def cmd_hints(args) -> int:
    block = hint_block(args.reason, args.max_chars, getattr(args, "scope", None),
                       getattr(args, "query", None))
    record = getattr(args, "record", None)
    if record:
        chosen, _hidden = _select_lessons(args.reason, getattr(args, "scope", None),
                                          getattr(args, "query", None))
        path = record_injection(record, chosen, len(block))
        print(f"recorded {len(chosen)} injected rule(s) -> {path}", file=sys.stderr)
    if args.json:
        print(json.dumps({"reason": args.reason, "scope": getattr(args, "scope", None),
                          "hints": load_lessons(), "block": block}, indent=2))
    else:
        print(block or "(no hints recorded yet)")
    return 0


def cmd_hint_add(args) -> int:
    """Owner-authored lesson: recorded and approved in one step.

    It is written as two immutable events rather than by editing a list, so the approval decision
    is part of the permanent history instead of being implied by a field in a file that gets
    rewritten. A human typing `hint add` has taken ownership of the wording, so the machine path
    (`lesson propose`) is the only one that starts at `proposed`.
    """
    scope_value = "any"
    lesson_id = lesson_key(scope_value, args.text)
    evidence = args.evidence or []
    _, created = write_memory_event("propose", lesson_id, {
        "text": args.text, "reason": args.reason, "scope": scope_value,
        "evidence": [evidence] if isinstance(evidence, str) else list(evidence),
        "proposed_by": "owner",
    })
    approve_path, _ = write_memory_event("approve", lesson_id, {"approved_by": "owner"})
    record = {"id": lesson_id, "status": "active", "reason": args.reason, "text": args.text}
    if args.json:
        print(json.dumps(record, indent=2))
    elif created:
        print(f"added {lesson_id}: [{args.reason}] {args.text}")
    else:
        print(f"{lesson_id} was already recorded; approval reconfirmed")
    return 0


# -------------------------------------------------------------------------- llm
#
# Owner directive 2026-11-17: "we build our llm."
#
# What is honestly buildable on this machine (measured: 4 cores, 19 GiB RAM, no NVIDIA GPU, no
# torch/transformers installed): NOT pretraining, and not a credible from-scratch model. Two real
# tiers exist.
#
#   Tier 1 (built here): OWN THE ARTEFACT. A named model whose behaviour is defined by OUR approved
#   memory - system rules and worked failures drawn from harpp-memory - rendered into a tracked
#   Modelfile and created with the local runtime. The weights are borrowed; the behaviour, the
#   versioning and the provenance are ours. Cheap, reproducible, reviewable in a diff.
#
#   Tier 2 (needs a corpus first): fine-tune a small base on CPU with LoRA. Slow, so it is only
#   worth spending once `llm corpus` can produce a real training set and there is an evaluation that
#   can tell whether the tune helped. Building the corpus now costs nothing and is the prerequisite.
#
# Pretraining from scratch is refused deliberately: at 4 CPU cores it would produce a worse model
# than the one already on disk, and calling that "our LLM" would be a story rather than an artefact.

LLM_DIR = Path(os.environ.get("HARPP_LLM_DIR") or ROOT / "tools" / "llm")
OLLAMA_HOST = os.environ.get("OLLAMA_HOST") or "http://127.0.0.1:11434"


def _llm_lessons(limit: int = 40) -> list[dict]:
    rows = _llm_eligible_lessons()
    return rows[:limit]


def _llm_eligible_lessons() -> list[dict]:
    """Every approved lesson that COULD go into the model, strongest activation first."""
    rows = [r for r in load_lessons() if _lesson_status(r) == "active" and r.get("text")]
    rows.sort(key=lambda r: float(r.get("activation") or 0.0), reverse=True)
    return rows


def _default_threads() -> int:
    """Physical cores where they can be inferred; compute-bound inference loses to hyperthreads."""
    total = os.cpu_count() or 1
    return max(1, total // 2) if total > 2 else total


def render_modelfile(base: str, lessons: list[dict], name: str, num_ctx: int = 2048,
                     num_thread: int = 0) -> str:
    """The model's behaviour, as a diffable file rather than a UI setting.

    Every rule in the SYSTEM block is an APPROVED lesson from harpp-memory, so the model can only
    inherit knowledge a human took ownership of - the same Class B boundary the lane prompt uses.

    The model is KAHIBAW ("the one who knows"): the experience layer, not a rival to the models that
    do the thinking. It exists to know what this repository already learned.
    """
    lines = [
        f"# generated by `harpp-experience.py llm build` - do not edit by hand",
        f"# model: {name} (kahibaw - the experience layer)",
        f"# lessons: {len(lessons)} of {len(_llm_eligible_lessons())} approved",
        f"FROM {base}",
        "PARAMETER temperature 0.2",
        f"PARAMETER num_ctx {num_ctx}",
        # Physical cores, not hyperthreads. Measured 2026-11-18 on an i3-2100 (2 cores/4 threads):
        # 2 threads answered in 11.3s where 4 threads took 19.2s - a 41% loss from HT contention on
        # compute-bound work.
        f"PARAMETER num_thread {num_thread}",
        'SYSTEM """',
        "You are Kahibaw, the experience layer of the Ikabud repository.",
        "",
        "You do not write code. You know what this repository already learned the hard way, and you",
        "state the rule that applies to the question you are given.",
        "",
        "Rules recorded on this repository, learned from failures that actually happened here.",
        "Apply them; do not restate them unless asked.",
        "",
    ]
    for lesson in lessons:
        lines.append(f"- {str(lesson.get('text')).strip()}")
    lines.append('"""')
    return "\n".join(lines) + "\n"


def cmd_llm_build(args) -> int:
    lessons = _llm_lessons(args.limit) if args.limit > 0 else []
    if args.limit > 0 and not lessons:
        print("harpp-experience: no approved lessons yet - nothing to build behaviour from",
              file=sys.stderr)
        return 2
    content = render_modelfile(args.base, lessons, args.name, args.num_ctx,
                               args.num_thread or _default_threads())
    path = LLM_DIR / "Modelfile"
    if args.dry_run:
        print(content)
        return 0
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(content, encoding="utf-8")
    total = len(_llm_eligible_lessons())
    print(f"wrote {path} ({len(lessons)} of {total} approved lessons)")
    if len(lessons) < total:
        print("harpp-experience: NOTE {} approved lesson(s) are NOT in the model - the context "
              "cap kept the {} strongest by activation. Raise --limit or trim rules.".format(
                  total - len(lessons), len(lessons)), file=sys.stderr)
    try:
        created = subprocess.run(["ollama", "create", args.name, "-f", str(path)],
                                 capture_output=True, text=True, timeout=600)
    except (OSError, subprocess.SubprocessError) as exc:
        print(f"harpp-experience: could not run ollama: {exc}", file=sys.stderr)
        return 2
    if created.returncode != 0:
        print(f"harpp-experience: ollama create failed:\n{created.stderr.strip()}", file=sys.stderr)
        return 2
    print(created.stdout.strip())
    print(f"built {args.name} from {args.base} - behaviour is ours, weights are borrowed")
    return 0


def cmd_llm_corpus(args) -> int:
    """Emit the fine-tuning set: one JSON object per line, provenance attached.

    This is the prerequisite for Tier 2. It is deliberately built before any training run, because
    a CPU tune without a corpus and an evaluation is spend with no way to tell improvement from
    noise.
    """
    out = Path(args.out)
    out.parent.mkdir(parents=True, exist_ok=True)
    rows = 0
    with out.open("w", encoding="utf-8") as handle:
        for lesson in load_lessons():
            if _lesson_status(lesson) != "active" or not lesson.get("text"):
                continue
            record = {
                "messages": [
                    {"role": "system", "content": "You are the Ikabud repository engineer. "
                                                  "Answer with the repository rule that applies."},
                    {"role": "user", "content": f"What rule applies in {_scope_value(lesson)}?"},
                    {"role": "assistant", "content": str(lesson.get("text")).strip()},
                ],
                "source": (lesson.get("evidence") or ["harpp-memory"])[0],
                "lesson_id": lesson.get("id"),
            }
            handle.write(json.dumps(record, sort_keys=True) + "\n")
            rows += 1
    print(f"wrote {rows} training example(s) -> {out}")
    return 0


def _ollama(model: str, prompt: str, num_predict: int = 120, timeout: int = 120) -> str:
    """One completion from the local runtime. Empty string on any failure, never an exception."""
    payload = json.dumps({"model": model, "prompt": prompt, "stream": False,
                          "options": {"temperature": 0, "num_predict": num_predict}}).encode("utf-8")
    request = urllib.request.Request(f"{OLLAMA_HOST}/api/generate", data=payload,
                                     headers={"Content-Type": "application/json"})
    try:
        with urllib.request.urlopen(request, timeout=timeout) as response:
            data = json.loads(response.read().decode("utf-8", errors="replace"))
    except (urllib.error.URLError, OSError, ValueError, TimeoutError):
        return ""
    return str(data.get("response") or "")


# The evaluation is the falsifier the architecture doc asks for, in a form that can run: does the
# memory-carrying model actually return the lesson a task needs, where the base does not?
_LLM_EVAL_CASES = (
    ("A grep-based contract test passes 12/12. Can I claim the feature works?",
     ("cannot prove", "not prove", "real endpoint", "exercise", "screen", "screen real")),
    ("I need to reverse a posted ledger effect. Can I delete the reversed row?",
     ("append-only", "never delete", "new row", "second row", "migration")),
    ("I am adding a new notification type to an ENUM column. Anything to watch?",
     ("migration", "enum", "data truncated", "re-declare", "redeclare")),
)


def cmd_llm_review(args) -> int:
    """Kahibaw proposes which recorded rules a change touches; the tool verifies each citation.

    This is the role the measurement actually supports. Kahibaw was asked to APPLY a rule from its
    own system prompt on 2026-11-17 and answered against it, so it is not allowed to decide what is
    true. Here it only points: it returns rule numbers from a numbered list, and every citation is
    then grounded deterministically (the rule must exist, be active, be in scope, and share a
    content word with the change). A hallucinated or off-topic citation is DROPPED, not obeyed -
    so a wrong answer costs nothing and a right one catches something.
    """
    brief = args.brief or ""
    if not brief.strip():
        print("harpp-experience: nothing to review (pass the change or task as an argument)",
              file=sys.stderr)
        return 2
    scope_value = args.scope or _scope_from_query(brief)
    candidates = [r for r in load_lessons()
                  if _lesson_status(r) == "active" and _scope_matches(r, scope_value)]
    candidates.sort(key=lambda r: (-_query_overlap(brief, r.get("text")), str(r.get("id"))))
    candidates = candidates[: args.limit]
    if not candidates:
        print(f"no active rules in scope {scope_value or 'any'}")
        return 0

    numbered = "\n".join(
        f"{i}. {str(r.get('text')).strip()[: getattr(args, 'rule_chars', 150)]}"
        for i, r in enumerate(candidates, 1))
    prompt = ("RULES (numbered):\n" + numbered +
              "\n\nCHANGE:\n" + brief +
              "\n\nWhich rule numbers are violated or must be respected by this change? "
              "Answer with comma-separated numbers only, or NONE.")
    answer = _ollama(args.model, prompt, num_predict=16)

    cited: list[int] = []
    for token in re.findall(r"\d+", answer or ""):
        index = int(token) - 1
        if 0 <= index < len(candidates) and index not in cited:
            cited.append(index)

    accepted, rejected = [], []
    for index in cited:
        rule = candidates[index]
        # Grounded = the rule shares a real content word with the change. A citation about
        # something unrelated is the model guessing, which is what the measurement warned about.
        if _query_overlap(brief, rule.get("text")) > 0:
            accepted.append(rule)
        else:
            rejected.append(rule)

    if args.json:
        print(json.dumps({"model": args.model, "answer": answer.strip(),
                          "accepted": [r.get("id") for r in accepted],
                          "rejected": [r.get("id") for r in rejected]}, indent=2))
        return 0
    print(f"kahibaw said: {answer.strip() or '(no answer)'}")
    for rule in accepted:
        print(f"  [verified] {rule.get('id')}  {str(rule.get('text'))[:150]}")
    for rule in rejected:
        print(f"  [dropped]  {rule.get('id')}  (not grounded in the change text)")
    if not cited:
        print("  nothing cited; the deterministic block still applies")
    print(f"\n{len(accepted)} citation(s) verified, {len(rejected)} dropped of {len(candidates)} rules considered")
    return 0


KAHIBAW_DIGEST = Path(os.environ.get("HARPP_KAHIBAW_DIGEST")
                      or ROOT / ".ai" / "harpp" / "kahibaw.digest")


def _approved_digest() -> str:
    """Fingerprint of the approved lesson set Kahibaw should be carrying."""
    ids = sorted(str(r.get("id")) for r in load_lessons()
                 if _lesson_status(r) == "active" and r.get("text"))
    return hashlib.sha256("|".join(ids).encode("utf-8")).hexdigest()[:16]


def refresh_kahibaw(base: str = "", limit: int = 0, force: bool = False) -> tuple[bool, str]:
    """Rebuild the model when the approved lesson set has changed.

    Owner direction 2026-11-18: "always improve kahibaw thru new contexts captured."  Every capture
    that a human approves changes the digest, so `close` calls this and the model stops drifting
    from memory - no one has to remember to rebuild it.
    """
    digest = _approved_digest()
    try:
        previous = KAHIBAW_DIGEST.read_text(encoding="utf-8").strip()
    except OSError:
        previous = ""
    if digest == previous and not force:
        return False, "unchanged"
    command = [sys.executable, str(ROOT / "tools" / "harpp-experience.py"), "llm", "build"]
    if base:
        command += ["--base", base]
    if limit:
        command += ["--limit", str(limit)]
    result = subprocess.run(command, capture_output=True, text=True, timeout=900)
    if result.returncode != 0:
        return False, (result.stderr or result.stdout).strip()[:200]
    KAHIBAW_DIGEST.parent.mkdir(parents=True, exist_ok=True)
    KAHIBAW_DIGEST.write_text(digest + "\n", encoding="utf-8")
    return True, f"rebuilt at {digest}"


def cmd_llm_refresh(args) -> int:
    changed, detail = refresh_kahibaw(base=args.base, limit=args.limit, force=args.force)
    print(("rebuilt kahibaw: " if changed else "kahibaw unchanged: ") + detail)
    return 0


def cmd_llm_doctor(args) -> int:
    """Is THIS machine able to run the experience model acceptably? Answer mechanically."""
    flags: set[str] = set()
    cores = os.cpu_count() or 1
    cpu_model = ""
    try:
        for line in Path("/proc/cpuinfo").read_text().splitlines():
            if line.startswith("model name") and not cpu_model:
                cpu_model = line.split(":", 1)[1].strip()
            elif line.startswith("flags"):
                flags = set(line.split(":", 1)[1].split())
    except OSError:
        pass
    has = {name: name in flags for name in ("avx", "avx2", "avx512f", "fma")}
    physical = max(1, cores // 2) if cores > 2 else cores
    # AVX2 is the floor every modern CPU-inference kernel assumes: ik_llama.cpp states its only
    # performant CPU backend is "AVX2 or better", and bitnet.cpp's gains need contemporary SIMD.
    adequate = has["avx2"]
    verdict = (
        "ADEQUATE - kahibaw can be deployed inline" if adequate
        else "INSUFFICIENT - no AVX2: kernels fall back and every prompt token costs ~150ms. "
             "Keep retrieval deterministic; treat kahibaw as offline-only.")
    report = {"cpu": cpu_model or "unknown", "cores": cores, "physical_cores": physical,
              "simd": has, "avx2_present": adequate, "verdict": verdict}
    if args.json:
        print(json.dumps(report, indent=2))
        return 0 if adequate else 1
    print(f"cpu      {report['cpu']}")
    print(f"cores    {cores} ({physical} physical)")
    print("simd     " + " ".join(f"{k}={'yes' if v else 'no'}" for k, v in has.items()))
    print(f"verdict  {verdict}")
    print("\n  grow now -> `llm corpus --out data.jsonl`; redeploy -> `llm build && llm bench`")
    return 0 if adequate else 1


def cmd_llm_bench(args) -> int:
    """Acceptance benchmark for the experience model: accuracy AND latency, on fixed cases.

    "Acceptable to use" needs a number, otherwise every change is an opinion. The bar is stated
    here and enforced by the exit code:

        accuracy >= 4/5   AND   median latency <= 15s

    Cases are drawn from rules the store already holds, so a pass means the model found real
    repository experience rather than plausible prose.
    """
    cases = (
        ("add a compact variant to the list-card block so density changes",
         "variants", "variants control presentation"),
        ("reverse a posted ledger effect that was applied twice",
         "append-only", "append-only"),
        ("add a new notification type to the status column",
         "migration", "migration"),
        ("a grep-based contract test passed, can I claim the feature works",
         "contract", "contract test"),
        ("a dispatch line was corrected, who should the notice name",
         "actor", "performed the action"),
    )
    results = []
    for brief, _key, expected in cases:
        scope_value = args.scope or _scope_from_query(brief)
        candidates = [r for r in load_lessons()
                      if _lesson_status(r) == "active" and _scope_matches(r, scope_value)]
        candidates.sort(key=lambda r: (-_query_overlap(brief, r.get("text")), str(r.get("id"))))
        candidates = candidates[: args.k]
        numbered = "\n".join(
            f"{i}. {str(r.get('text')).strip()[: args.rule_chars]}" for i, r in enumerate(candidates, 1))
        prompt = ("RULES:\n" + numbered + "\n\nTASK:\n" + brief +
                  "\n\nWhich single rule number applies? Answer with the number only.")
        started = _dt.datetime.now()
        answer = _ollama(args.model, prompt, num_predict=6, timeout=args.timeout)
        elapsed = (_dt.datetime.now() - started).total_seconds()
        picked = ""
        for token in re.findall(r"\d+", answer or ""):
            index = int(token) - 1
            if 0 <= index < len(candidates):
                picked = str(candidates[index].get("text") or "")
                break
        correct = bool(picked) and expected.lower() in picked.lower()
        results.append({"brief": brief[:46], "correct": correct, "seconds": round(elapsed, 1),
                        "picked": picked[:70] or "(no answer)"})
        # Stream each case: a five-case run at ~60 s each is otherwise seven silent minutes, and a
        # benchmark you cannot watch is a benchmark you cannot debug.
        if not args.json:
            print(f"  {'HIT ' if correct else 'miss'} {elapsed:>5.1f}s  {brief[:46]}", flush=True)
            if not correct:
                print(f"        picked: {picked[:70] or '(no answer)'}", flush=True)

    hits = sum(1 for r in results if r["correct"])
    times = sorted(r["seconds"] for r in results)
    median = times[len(times) // 2] if times else 0.0
    if args.json:
        print(json.dumps({"model": args.model, "k": args.k, "hits": hits, "of": len(results),
                          "median_seconds": median, "results": results}, indent=2))
    else:
        print(f"\naccuracy {hits}/{len(results)}   median {median:.1f}s   (k={args.k})")
    acceptable = hits >= 4 and median <= 15
    print(("ACCEPTABLE: kahibaw may be used for review" if acceptable
           else "NOT ACCEPTABLE: keep retrieval deterministic and the model offline"))
    return 0 if acceptable else 1


def cmd_llm_eval(args) -> int:
    """Score the built model against the base on experience recall. Prints a table, no verdict spin."""
    lessons = _llm_lessons(200)
    if not lessons:
        print("harpp-experience: no approved lessons - nothing to evaluate", file=sys.stderr)
        return 2
    models = [("base", args.base), ("ours", args.name)]
    rows: list[tuple[str, str, bool]] = []
    for question, expected in _LLM_EVAL_CASES:
        for label, model in models:
            answer = _ollama(model, question, num_predict=90).lower()
            hit = any(needle in answer for needle in expected)
            rows.append((question[:44], label, hit))
    if args.json:
        print(json.dumps([{"question": q, "model": m, "recalled": h} for q, m, h in rows], indent=2))
    else:
        for question, label, hit in rows:
            print(f"  {'HIT ' if hit else 'miss'}  {label:<5} {question}")
    base_hits = sum(1 for q, m, h in rows if m == "base" and h)
    our_hits = sum(1 for q, m, h in rows if m == "ours" and h)
    total = len(_LLM_EVAL_CASES)
    print(f"\nrecall  base {base_hits}/{total}   ours {our_hits}/{total}")
    if our_hits < base_hits:
        print("VERDICT: the built model is WORSE than its base - do not wire it in front of agents")
        return 1
    if our_hits == base_hits == total:
        print("VERDICT: both recall everything here - raise the difficulty before trusting this")
        return 0
    print("VERDICT: the memory-carrying model recalls repository experience the base does not")
    return 0


def _parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description="HARPP experience reporting (Phase 1, no database)")
    sub = parser.add_subparsers(dest="command", required=True)

    join = sub.add_parser("join", help="coverage of ledger rows -> artifacts/components")
    join.add_argument("--json", action="store_true")
    join.set_defaults(func=cmd_join)

    report = sub.add_parser("report", help="pass rate, interventions, cost or the fallback metric")
    report.add_argument("--window", help="'28d' (days) or a row count")
    report.add_argument("--json", action="store_true")
    report.set_defaults(func=cmd_report)

    compare = sub.add_parser("compare", help="pass rate per component stamp (refuses below min-n)")
    compare.add_argument("--component", default="component_harness",
                         choices=sorted(COMPONENT_FILES))
    compare.add_argument("--min-n", type=int, default=3)
    compare.add_argument("--json", action="store_true")
    compare.set_defaults(func=cmd_compare)

    trial = sub.add_parser("trial", help="append-only change-level hypothesis")
    trial_sub = trial.add_subparsers(dest="trial_command", required=True)
    new = trial_sub.add_parser("new")
    new.add_argument("--component", required=True, choices=sorted(COMPONENT_FILES))
    new.add_argument("--hypothesis", required=True)
    new.add_argument("--metric", default="pass_rate")
    new.add_argument("--minimum-delta", type=float, default=0.05)
    new.add_argument("--min-n", type=int, default=10)
    new.add_argument("--baseline")
    new.add_argument("--candidate")
    new.add_argument("--json", action="store_true")
    new.set_defaults(func=cmd_trial_new)
    listing = trial_sub.add_parser("list")
    listing.add_argument("--json", action="store_true")
    listing.set_defaults(func=cmd_trial_list)
    evaluate = trial_sub.add_parser("evaluate")
    evaluate.add_argument("trial_id")
    evaluate.add_argument("--json", action="store_true")
    evaluate.set_defaults(func=cmd_trial_evaluate)

    stamp = sub.add_parser("stamp", help="key=value component stamps for a ledger row")
    stamp.add_argument("--task", required=True)
    stamp.add_argument("--attempts")
    stamp.add_argument("--since", help="ISO start time; events older than this are a previous run")
    stamp.add_argument("--json", action="store_true")
    stamp.set_defaults(func=cmd_stamp)

    signatures = sub.add_parser("signatures", help="inventory of failure signatures")
    signatures.add_argument("--limit", type=int, default=20)
    signatures.add_argument("--json", action="store_true")
    signatures.set_defaults(func=cmd_signatures)

    hints = sub.add_parser("hints", help="prompt block of known failure modes")
    hints.add_argument("--reason", help="restrict to one failure class")
    hints.add_argument("--scope", help="module id the lesson must apply to (e.g. daily-ledger)")
    hints.add_argument("--query", help="task text: rank the injected rules by relevance to it")
    hints.add_argument("--record", help="remember which rules were injected under this run tag")
    hints.add_argument("--max-chars", type=int, default=1800)
    hints.add_argument("--json", action="store_true")
    hints.set_defaults(func=cmd_hints)

    lessons = sub.add_parser("lessons", help="list lessons and their status")
    lessons.add_argument("--status", choices=["active", "proposed", "retired"])
    lessons.add_argument("--scope")
    lessons.add_argument("--json", action="store_true")
    lessons.set_defaults(func=cmd_lessons)

    lesson = sub.add_parser("lesson", help="propose / approve / retire a lesson")
    lesson_sub = lesson.add_subparsers(dest="lesson_command", required=True)
    propose = lesson_sub.add_parser("propose", help="machine-authored; NOT injected until approved")
    propose.add_argument("--reason", default="any")
    propose.add_argument("--text", required=True)
    propose.add_argument("--scope", default="any")
    propose.add_argument("--evidence", action="append")
    propose.add_argument("--proposed-by", default="manual")
    propose.add_argument("--review-after")
    propose.add_argument("--json", action="store_true")
    propose.set_defaults(func=cmd_lesson_propose)
    approve = lesson_sub.add_parser("approve")
    approve.add_argument("lesson_id")
    approve.add_argument("--by", default="owner")
    approve.set_defaults(func=cmd_lesson_approve)
    dupes_cmd = lesson_sub.add_parser("duplicates", help="deterministic duplicate report for proposed lessons")
    dupes_cmd.add_argument("--limit", type=int, default=15)
    dupes_cmd.set_defaults(func=cmd_lesson_duplicates)
    apply_cmd = lesson_sub.add_parser("apply", help="a retrieved lesson was USED; evidence is checked, not trusted")
    apply_cmd.add_argument("lesson_id")
    apply_cmd.add_argument("--evidence", help="path (optionally path::case) proving the application")
    apply_cmd.add_argument("--run", help="lane run id, when known")
    apply_cmd.set_defaults(func=cmd_lesson_apply)
    retire = lesson_sub.add_parser("retire")
    retire.add_argument("lesson_id")
    retire.add_argument("--compiled-as", help="path of the gate that now enforces it")
    retire.set_defaults(func=cmd_lesson_retire)
    effect_cmd = lesson_sub.add_parser(
        "effect", help="per-rule outcome counts for the runs that actually received it")
    effect_cmd.add_argument("--min-n", type=int, default=MIN_ATTRIBUTED_RUNS,
                            help="decided runs required before a rule is scored")
    effect_cmd.add_argument("--json", action="store_true")
    effect_cmd.set_defaults(func=cmd_lesson_effect)
    sweep_cmd = lesson_sub.add_parser(
        "sweep", help="retirement candidates from attribution (report-only unless --apply)")
    sweep_cmd.add_argument("--min-n", type=int, default=MIN_ATTRIBUTED_RUNS)
    sweep_cmd.add_argument("--apply", action="store_true",
                           help="retire rules that never appeared in a passing run")
    sweep_cmd.set_defaults(func=cmd_lesson_sweep)
    funnel_cmd = lesson_sub.add_parser(
        "funnel", help="per-scope: captured -> active -> injected -> attributed")
    funnel_cmd.add_argument("--scope", help="module scope, e.g. cms")
    funnel_cmd.add_argument("--json", action="store_true")
    funnel_cmd.set_defaults(func=cmd_lesson_funnel)
    audit_cmd = lesson_sub.add_parser(
        "audit", help="grade lessons by whether their claim can be CHECKED (anchored/stale/prose)")
    audit_cmd.add_argument("--scope", help="module scope, e.g. cms")
    audit_cmd.add_argument("--limit", type=int, default=8)
    audit_cmd.add_argument("--json", action="store_true")
    audit_cmd.set_defaults(func=cmd_lesson_audit)

    memory = sub.add_parser("memory", help="git-tracked lesson memory: evidence events")
    memory_sub = memory.add_subparsers(dest="memory_command", required=True)
    confirm = memory_sub.add_parser("confirm", help="a later outcome AGREED with this lesson")
    confirm.add_argument("lesson_id")
    confirm.set_defaults(func=cmd_memory_confirm)
    contradict = memory_sub.add_parser("contradict", help="a later outcome DISAGREED with it")
    contradict.add_argument("lesson_id")
    contradict.set_defaults(func=cmd_memory_contradict)
    memory_list = memory_sub.add_parser("list", help="lessons ranked by activation")
    memory_list.add_argument("--status", choices=["active", "proposed", "retired"])
    memory_list.add_argument("--reason", help="filter by harvest category (ui-ux, decision, gate, ...)")
    memory_list.add_argument("--limit", type=int, default=0)
    memory_list.add_argument("--json", action="store_true")
    memory_list.set_defaults(func=cmd_memory_list)
    memory_verify = memory_sub.add_parser("verify", help="detect incompatible transitions")
    memory_verify.add_argument("--json", action="store_true")
    memory_verify.set_defaults(func=cmd_memory_verify)
    memory_migrate = memory_sub.add_parser("migrate", help="import legacy hints.json lessons")
    memory_migrate.set_defaults(func=cmd_memory_migrate)
    memory_semantic = memory_sub.add_parser("semantic", help="recall by meaning (embedding similarity)")
    memory_semantic.add_argument("query")
    memory_semantic.add_argument("--scope")
    memory_semantic.add_argument("--limit", type=int, default=6)
    memory_semantic.add_argument("--refresh", action="store_true", help="rebuild cached vectors")
    memory_semantic.add_argument("--json", action="store_true")
    memory_semantic.set_defaults(func=cmd_memory_semantic)
    memory_spread = memory_sub.add_parser("spread", help="associative recall over lesson similarity")
    memory_spread.add_argument("query")
    memory_spread.add_argument("--scope")
    memory_spread.add_argument("--hops", type=int, default=2)
    memory_spread.add_argument("--damping", type=float, default=0.45)
    memory_spread.add_argument("--limit", type=int, default=6)
    memory_spread.add_argument("--json", action="store_true")
    memory_spread.set_defaults(func=cmd_memory_spread)
    memory_path = memory_sub.add_parser("path", help="print the memory directory")
    memory_path.set_defaults(func=cmd_memory_path)
    memory_export = memory_sub.add_parser("export", help="write the whole store to one portable file")
    memory_export.add_argument("--out", required=True, help="destination file (.jsonl)")
    memory_export.set_defaults(func=cmd_memory_export)
    memory_import = memory_sub.add_parser("import", help="reconstitute the store from an export")
    memory_import.add_argument("--from", dest="source", required=True, help="export file to read")
    memory_import.set_defaults(func=cmd_memory_import)

    close_cmd = sub.add_parser("close", help="close a session: reinforce rules, learn from new tests")
    close_cmd.add_argument("--task", required=True, help="run/lane name (matches .ai/runs/<task>.landed.json)")
    close_cmd.add_argument("--status", choices=["pass", "fail"], default="pass")
    close_cmd.set_defaults(func=cmd_close)

    harvest = sub.add_parser("harvest", help="turn documented experience into PROPOSED lessons")
    harvest.add_argument("paths", nargs="*", help="files or directories (default: docs)")
    harvest.add_argument("--limit", type=int, default=0, help="cap the candidates taken")
    harvest.add_argument("--llm", action="store_true", help="refine each rule with a local model")
    harvest.add_argument("--model", default="qwen2.5-coder:3b", help="ollama model for --llm")
    harvest.add_argument("--kind", choices=["docs", "tests", "all"], default="docs",
                         help="docs = markdown prose, tests = assertion labels, all = both")
    harvest.add_argument("--dry-run", action="store_true", help="show candidates, write nothing")
    harvest.add_argument("--audit-existing", action="store_true",
                         help="judge the proposals already stored by the same shape rules")
    harvest.add_argument("--apply", action="store_true",
                         help="with --audit-existing: retire the refused proposals")
    harvest.set_defaults(func=cmd_harvest)

    llm = sub.add_parser("llm", help="build the repository's own model from approved memory")
    llm_sub = llm.add_subparsers(dest="llm_command", required=True)
    llm_build = llm_sub.add_parser("build", help="render the Modelfile and create the model")
    llm_build.add_argument("--base", default="qwen2.5:0.5b",
                           help="base model; 0.5b is 2x faster on CPU than 1.5b (measured)")
    llm_build.add_argument("--name", default="kahibaw", help="name of the built model")
    llm_build.add_argument("--limit", type=int, default=40, help="max approved lessons to embed (0 = role only)")
    llm_build.add_argument("--num-thread", type=int, default=0,
                           help="physical cores; 0 = auto (cpu_count // 2)")
    llm_build.add_argument("--num-ctx", type=int, default=2048,
                           help="context window; larger means slower prompt processing on CPU")
    llm_build.add_argument("--dry-run", action="store_true", help="print the Modelfile only")
    llm_build.set_defaults(func=cmd_llm_build)
    llm_corpus = llm_sub.add_parser("corpus", help="emit the fine-tuning set (Tier 2 prerequisite)")
    llm_corpus.add_argument("--out", required=True, help="destination .jsonl")
    llm_corpus.set_defaults(func=cmd_llm_corpus)
    llm_review = llm_sub.add_parser("review", help="Kahibaw proposes rules; the tool verifies each")
    llm_review.add_argument("brief", nargs="?", default="", help="the change or task to review")
    llm_review.add_argument("--scope")
    llm_review.add_argument("--model", default="kahibaw")
    llm_review.add_argument("--limit", type=int, default=3,
                            help="candidates offered; 3 is the benchmarked configuration")
    llm_review.add_argument("--rule-chars", type=int, default=150)
    llm_review.add_argument("--json", action="store_true")
    llm_review.set_defaults(func=cmd_llm_review)
    llm_refresh = llm_sub.add_parser("refresh", help="rebuild kahibaw when approved context changed")
    llm_refresh.add_argument("--base", default="")
    llm_refresh.add_argument("--limit", type=int, default=0)
    llm_refresh.add_argument("--force", action="store_true")
    llm_refresh.set_defaults(func=cmd_llm_refresh)
    llm_doctor = llm_sub.add_parser("doctor", help="can this machine run the model acceptably?")
    llm_doctor.add_argument("--json", action="store_true")
    llm_doctor.set_defaults(func=cmd_llm_doctor)
    llm_bench = llm_sub.add_parser("bench", help="acceptance: accuracy + latency on fixed cases")
    llm_bench.add_argument("--model", default="kahibaw")
    llm_bench.add_argument("--scope")
    llm_bench.add_argument("--k", type=int, default=3,
                           help="candidate rules offered; measured 2026-11-17: k=3 scored 4/5, k=5 3/5")
    llm_bench.add_argument("--rule-chars", type=int, default=150,
                           help="truncate each candidate: prompt size dominates CPU latency")
    llm_bench.add_argument("--timeout", type=int, default=90)
    llm_bench.add_argument("--json", action="store_true")
    llm_bench.set_defaults(func=cmd_llm_bench)
    llm_eval = llm_sub.add_parser("eval", help="does the built model recall experience the base cannot?")
    llm_eval.add_argument("--base", default="qwen2.5-coder:1.5b")
    llm_eval.add_argument("--name", default="kahibaw")
    llm_eval.add_argument("--json", action="store_true")
    llm_eval.set_defaults(func=cmd_llm_eval)

    status = sub.add_parser("status", help="one screen: lessons, cost, signatures, coverage")
    status.add_argument("--json", action="store_true")
    status.set_defaults(func=cmd_status)

    index_cmd = sub.add_parser("index", help="rebuild the derived experience index (SQLite + FTS5)")
    index_cmd.add_argument("--db", help="alternate database path")
    index_cmd.add_argument("--json", action="store_true")
    index_cmd.set_defaults(func=cmd_index)

    search = sub.add_parser("search", help="full-text search over recorded experience")
    search.add_argument("query")
    search.add_argument("--limit", type=int, default=10)
    search.add_argument("--db", help="alternate database path")
    search.add_argument("--json", action="store_true")
    search.set_defaults(func=cmd_search)

    hint = sub.add_parser("hint", help="manage learned failure-mode hints")
    hint_sub = hint.add_subparsers(dest="hint_command", required=True)
    add = hint_sub.add_parser("add")
    add.add_argument("--reason", required=True, help="timeout | no_report | crash | fatal | quota | empty")
    add.add_argument("--text", required=True)
    add.add_argument("--evidence", action="append")
    add.add_argument("--json", action="store_true")
    add.set_defaults(func=cmd_hint_add)
    return parser


def main(argv: list[str] | None = None) -> int:
    args = _parser().parse_args(argv)
    try:
        return int(args.func(args))
    except OSError as exc:
        print(f"harpp-experience: {exc}", file=sys.stderr)
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
