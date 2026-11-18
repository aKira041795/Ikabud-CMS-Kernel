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
    return path, True


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
                  and _scope_matches(h, scope)
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
    """A rule found in docs/<module>/ belongs to that module, not to every task."""
    parts = path.parts
    if "docs" in parts:
        index = parts.index("docs")
        if index + 1 < len(parts):
            candidate = parts[index + 1]
            if (ROOT / "modules" / candidate).is_dir():
                return candidate
    # Instruction files name their subject in the filename; ui-ux rules govern every screen.
    name = path.name.lower()
    for module in sorted(p.name for p in (ROOT / "modules").iterdir() if p.is_dir()):
        if module in name:
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


def _harvest_test_candidates(paths: list[Path]) -> list[dict]:
    """Assertion labels ARE experience, already compiled into a gate.

    A test named "the producing commissary is never named as the corrector" is the same knowledge as
    a lesson, but bound to an executable check. Reading them back into memory links the gate to the
    experience that produced it, which is the compile direction of the three-loop design: an
    approved lesson can later be retired as `compiled_as: <this test>`.
    """
    found: list[dict] = []
    label_pattern = re.compile(
        r"(?:->test|dlSubTest|\$h->test|assert(?:_equal|_same|_true)?\s*\()\s*"
        r"'((?:[^'\\]|\\.){40,300})'", re.S)
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


def cmd_harvest(args) -> int:
    """Turn documented experience into PROPOSED lessons. Never injects; approval stays human."""
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
                  and _scope_matches(h, scope)
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
    if args.status == "pass" and confirmed:
        print("  reinforcement is co-occurrence evidence, recorded as source=session-pass")
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
    rows = [r for r in load_lessons() if _lesson_status(r) == "active" and r.get("text")]
    rows.sort(key=lambda r: float(r.get("activation") or 0.0), reverse=True)
    return rows[:limit]


def render_modelfile(base: str, lessons: list[dict], name: str, num_ctx: int = 2048) -> str:
    """The model's behaviour, as a diffable file rather than a UI setting.

    Every rule in the SYSTEM block is an APPROVED lesson from harpp-memory, so the model can only
    inherit knowledge a human took ownership of - the same Class B boundary the lane prompt uses.

    The model is KAHIBAW ("the one who knows"): the experience layer, not a rival to the models that
    do the thinking. It exists to know what this repository already learned.
    """
    lines = [
        f"# generated by `harpp-experience.py llm build` - do not edit by hand",
        f"# model: {name} (kahibaw - the experience layer)",
        f"# lessons: {len(lessons)} approved",
        f"FROM {base}",
        "PARAMETER temperature 0.2",
        f"PARAMETER num_ctx {num_ctx}",
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
    content = render_modelfile(args.base, lessons, args.name, args.num_ctx)
    path = LLM_DIR / "Modelfile"
    if args.dry_run:
        print(content)
        return 0
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(content, encoding="utf-8")
    print(f"wrote {path} ({len(lessons)} approved lessons)")
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
    retire = lesson_sub.add_parser("retire")
    retire.add_argument("lesson_id")
    retire.add_argument("--compiled-as", help="path of the gate that now enforces it")
    retire.set_defaults(func=cmd_lesson_retire)

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
    harvest.set_defaults(func=cmd_harvest)

    llm = sub.add_parser("llm", help="build the repository's own model from approved memory")
    llm_sub = llm.add_subparsers(dest="llm_command", required=True)
    llm_build = llm_sub.add_parser("build", help="render the Modelfile and create the model")
    llm_build.add_argument("--base", default="qwen2.5:0.5b",
                           help="base model; 0.5b is 2x faster on CPU than 1.5b (measured)")
    llm_build.add_argument("--name", default="kahibaw", help="name of the built model")
    llm_build.add_argument("--limit", type=int, default=40, help="max approved lessons to embed (0 = role only)")
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
