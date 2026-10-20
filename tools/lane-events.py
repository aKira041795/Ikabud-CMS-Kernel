#!/usr/bin/env python3
"""Event-sourced lane run log.

Why this exists: lane outcomes used to live in several places that disagreed -
`.ai/runs/<lane>.log` (the wrapper), `/tmp/<prefix>-<model>.log` (the model's own
output), `.landed.json` (the marker), and `landings.jsonl` (the journal). When those
disagreed, a successful lane could be reported as `no_report`, and a lane that did the
work could be called a timeout. The event log is the single append-only record from
which the others can be explained.

Commands:
    append RUN TYPE [key=value ...]
    replay RUN [--json]
    verify RUN
    list [--json]

Storage: <runs-dir>/<run>.events.jsonl (one JSON object per line, append-only,
locked with flock). Runs dir resolution: --runs-dir, LANE_RUNS_DIR, the nearest
`.ai/runs` from the current directory upward, or `./.ai/runs`.
"""

from __future__ import annotations

import argparse
import fcntl
import json
import os
import sys
from datetime import datetime, timezone
from pathlib import Path
from typing import Any


def _find_runs_dir(explicit: str | None) -> Path:
    if explicit:
        return Path(explicit).expanduser().resolve()
    if os.environ.get("LANE_RUNS_DIR"):
        return Path(os.environ["LANE_RUNS_DIR"]).expanduser().resolve()
    here = Path.cwd().resolve()
    for candidate in (here, *here.parents):
        if (candidate / ".ai" / "runs").is_dir():
            return candidate / ".ai" / "runs"
        if (candidate / ".git").exists():
            return candidate / ".ai" / "runs"
    return here / ".ai" / "runs"


def _parse_value(raw: str) -> Any:
    low = raw.lower()
    if low == "true":
        return True
    if low == "false":
        return False
    if low == "null":
        return None
    try:
        return int(raw)
    except ValueError:
        pass
    try:
        return float(raw)
    except ValueError:
        pass
    return raw


def _append(path: Path, event: dict[str, Any]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    line = json.dumps(event, ensure_ascii=False, separators=(",", ":")) + "\n"
    fd = os.open(path, os.O_APPEND | os.O_CREAT | os.O_WRONLY, 0o600)
    try:
        fcntl.flock(fd, fcntl.LOCK_EX)
        os.write(fd, line.encode("utf-8"))
        os.fsync(fd)
    finally:
        fcntl.flock(fd, fcntl.LOCK_UN)
        os.close(fd)


def _read(path: Path) -> list[dict[str, Any]]:
    if not path.is_file():
        return []
    events: list[dict[str, Any]] = []
    for line in path.read_text(encoding="utf-8", errors="replace").splitlines():
        line = line.strip()
        if not line:
            continue
        try:
            value = json.loads(line)
        except ValueError:
            continue
        if isinstance(value, dict):
            events.append(value)
    return events


def _summary(events: list[dict[str, Any]]) -> dict[str, Any]:
    summary: dict[str, Any] = {
        "run": events[0].get("run") if events else None,
        "started_at": None,
        "finished_at": None,
        "state": None,
        "reason": None,
        "exit_code": None,
        "acceptance": None,
        "completion": None,
        "completion_detail": None,
        "role": None,
        "attempts": 0,
        "duration_s": None,
        "model": None,
        "model_log": None,
        "model_log_bytes": None,
        "changed_files": None,
        "run_id": None,
        "policy": None,
        "events": len(events),
    }
    for event in events:
        kind = event.get("type")
        if kind == "run_started" and summary["started_at"] is None:
            summary["started_at"] = event.get("ts")
            summary["role"] = event.get("role")
        if kind == "attempt_started":
            summary["attempts"] += 1
        if kind == "model_completed":
            summary["model"] = event.get("model")
            summary["model_log"] = event.get("log")
            summary["model_log_bytes"] = event.get("bytes")
        if kind == "run_finished":
            summary["finished_at"] = event.get("ts")
            for key in ("state", "reason", "exit_code", "acceptance", "changed_files", "run_id", "completion"):
                if key in event:
                    summary[key] = event.get(key)
            summary["completion_detail"] = event.get("completion_detail")
        if kind == "policy_violation":
            summary["policy"] = event.get("detail") or event.get("role")
    started, finished = summary["started_at"], summary["finished_at"]
    if started and finished:
        try:
            summary["duration_s"] = int(
                (datetime.fromisoformat(str(finished)) - datetime.fromisoformat(str(started))).total_seconds()
            )
        except ValueError:
            summary["duration_s"] = None
    return summary


def _timeline(events: list[dict[str, Any]]) -> str:
    lines: list[str] = []
    for event in events:
        ts = str(event.get("ts", ""))
        kind = str(event.get("type", "?"))
        detail = {
            k: v
            for k, v in event.items()
            if k not in ("ts", "run", "type") and v not in (None, "", [])
        }
        rendered = " ".join(f"{k}={v}" for k, v in detail.items())
        lines.append(f"{ts}  {kind:<18} {rendered}".rstrip())
    return "\n".join(lines)


def cmd_append(args: argparse.Namespace) -> int:
    runs_dir = _find_runs_dir(args.runs_dir)
    event: dict[str, Any] = {
        "ts": datetime.now(timezone.utc).isoformat(timespec="seconds"),
        "run": args.run,
        "type": args.type,
    }
    for pair in args.fields:
        if "=" not in pair:
            print(f"lane-events: ignoring field without '=': {pair}", file=sys.stderr)
            continue
        key, raw = pair.split("=", 1)
        if key:
            event[key] = _parse_value(raw)
    _append(runs_dir / f"{args.run}.events.jsonl", event)
    return 0


def cmd_replay(args: argparse.Namespace) -> int:
    runs_dir = _find_runs_dir(args.runs_dir)
    events = _read(runs_dir / f"{args.run}.events.jsonl")
    if not events:
        print(f"lane-events: no events for {args.run!r} in {runs_dir}")
        return 1
    if args.json:
        print(json.dumps(_summary(events), ensure_ascii=False, indent=2))
    else:
        print(f"== {args.run} ==")
        print(_timeline(events))
        print()
        print(json.dumps(_summary(events), ensure_ascii=False, indent=2))
    return 0


def cmd_verify(args: argparse.Namespace) -> int:
    runs_dir = _find_runs_dir(args.runs_dir)
    events = _read(runs_dir / f"{args.run}.events.jsonl")
    if not events:
        print(f"VERIFY FAIL: no events for {args.run!r} in {runs_dir}")
        return 1

    problems: list[str] = []
    attempts = [e for e in events if e.get("type") == "attempt_finished"]
    completed = [e for e in events if e.get("type") == "model_completed"]
    finished = [e for e in events if e.get("type") == "run_finished"]
    violations = [e for e in events if e.get("type") == "policy_violation"]

    for attempt in attempts:
        if attempt.get("exit") == 0 and not completed:
            problems.append(
                f"attempt exit=0 for {attempt.get('model')} but no model_completed event"
            )
        if int(attempt.get("bytes") or 0) > 0 and attempt.get("log"):
            log_path = Path(str(attempt.get("log")))
            if not log_path.is_file():
                problems.append(
                    f"attempt log recorded but missing on disk: {log_path}"
                )

    if finished:
        terminal = finished[-1]
        if terminal.get("reason") == "no_report" and completed:
            completed_log = str(completed[-1].get("log") or "")
            completed_bytes = int(completed[-1].get("bytes") or 0)
            problems.append(
                "landing says no_report but a completed model output exists "
                f"({completed_bytes} bytes at {completed_log or '<unrecorded>'}); "
                "the report may be in the model log, not the lane log"
            )
    else:
        problems.append("no run_finished event")

    if violations and finished and finished[-1].get("exit_code") == 0:
        problems.append(
            "policy_violation recorded but exit_code is 0: "
            + str(violations[-1].get("detail") or violations[-1].get("role") or "unknown")
        )

    if finished and finished[-1].get("completion") == "BLOCKED" and finished[-1].get("exit_code") == 0:
        problems.append(
            "completion=BLOCKED but exit_code is 0: the lane exited cleanly while its own "
            "criterion did not pass (see completion_detail/acceptance)"
        )

    if problems:
        print(f"VERIFY FAIL: {args.run}")
        for problem in problems:
            print(f"  - {problem}")
        return 1
    print(f"VERIFY OK: {args.run} ({len(events)} events)")
    return 0


def cmd_list(args: argparse.Namespace) -> int:
    runs_dir = _find_runs_dir(args.runs_dir)
    if not runs_dir.is_dir():
        print(f"lane-events: no runs directory at {runs_dir}")
        return 0
    rows: list[dict[str, Any]] = []
    for path in sorted(runs_dir.glob("*.events.jsonl")):
        events = _read(path)
        if not events:
            continue
        summary = _summary(events)
        summary["run"] = summary["run"] or path.name[: -len(".events.jsonl")]
        rows.append(summary)
    if args.json:
        print(json.dumps(rows, ensure_ascii=False, indent=2))
        return 0
    if not rows:
        print("no lane event logs")
        return 0
    print(f"== lane runs ({len(rows)}) ==")
    for row in rows:
        duration = row.get("duration_s")
        print(
            f"   {str(row['run']):<28} {str(row['state'] or '-'):<10} "
            f"{str(row['completion'] or '-'):<11} {str(row['role'] or '-'):<10} "
            f"{str(row['reason'] or '-'):<16} {row['attempts']} att "
            f"{str(row['model'] or '-'):<24} "
            f"{str(duration) + 's' if duration is not None else '-'}"
        )
    return 0


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Event-sourced lane run log")
    parser.add_argument("--runs-dir", default=None, help="override the .ai/runs directory")
    sub = parser.add_subparsers(dest="command", required=True)

    append = sub.add_parser("append", help="append one event")
    append.add_argument("run")
    append.add_argument("type")
    append.add_argument("fields", nargs="*", help="key=value pairs")
    append.set_defaults(func=cmd_append)

    replay = sub.add_parser("replay", help="print a run timeline")
    replay.add_argument("run")
    replay.add_argument("--json", action="store_true", help="summary only")
    replay.set_defaults(func=cmd_replay)

    verify = sub.add_parser("verify", help="check the run's evidence for contradictions")
    verify.add_argument("run")
    verify.set_defaults(func=cmd_verify)

    listing = sub.add_parser("list", help="list runs with event logs")
    listing.add_argument("--json", action="store_true")
    listing.set_defaults(func=cmd_list)

    args = parser.parse_args(argv)
    return int(args.func(args))


if __name__ == "__main__":
    raise SystemExit(main())
