#!/usr/bin/env python3
"""Autonomy ledger for the lane harness.

A task is counted as autonomous only when it reaches a verified, merged result
without a human intervention. High-risk work is reported separately and never
counted toward the routine rate.

Usage:
    tools/autonomy-ledger.py record <task> [key=value...]
    tools/autonomy-ledger.py report [--json] [--window N]
    tools/autonomy-ledger.py list [--json]

Fields:
    class=routine|high-risk
    owner_interventions=0
    intervention_reasons=acceptance,merge
    attempts=1
    acceptance=PASS|FAIL|BLOCKED|SKIPPED|UNKNOWN
    gate=PASS|BLOCKED|SKIPPED|UNKNOWN
    merged=true|false
    rolled_back=true|false
    cost_usd=0.0
    notes="..."

The ledger is append-only; the latest record per task wins. `.ai/autonomy/` is
gitignored with the rest of `.ai/`, so this is local execution evidence, not a
committed source of truth.
"""
from __future__ import annotations

import argparse
import fcntl
import json
import os
import sys
from datetime import datetime, timezone
from pathlib import Path
from statistics import mean

DEFAULT_DIR = ".ai/autonomy"
CLASSES = ("routine", "high-risk")
ACCEPTANCE = ("PASS", "FAIL", "BLOCKED", "SKIPPED", "UNKNOWN")
GATE = ("PASS", "BLOCKED", "SKIPPED", "UNKNOWN")
_TS_FORMAT = "%Y-%m-%dT%H:%M:%S%z"


def _now() -> str:
    return datetime.now(timezone.utc).isoformat(timespec="seconds")


def _parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description="Autonomy ledger")
    parser.add_argument("--dir", default=os.environ.get("LANE_AUTONOMY_DIR", DEFAULT_DIR),
                        help=f"ledger directory (default: {DEFAULT_DIR})")
    sub = parser.add_subparsers(dest="command", required=True)

    record = sub.add_parser("record", help="append a task outcome")
    record.add_argument("task")
    record.add_argument("fields", nargs="*", help="key=value pairs")
    record.set_defaults(func=cmd_record)

    report = sub.add_parser("report", help="compute the autonomy rate")
    report.add_argument("--json", action="store_true", dest="as_json")
    report.add_argument("--window", type=int, default=20, help="last N tasks (default: 20)")
    report.set_defaults(func=cmd_report)

    listing = sub.add_parser("list", help="list the latest record per task")
    listing.add_argument("--json", action="store_true", dest="as_json")
    listing.set_defaults(func=cmd_list)
    return parser


def _ledger_path(directory: str) -> Path:
    return Path(directory).expanduser() / "ledger.jsonl"


def _read(path: Path) -> list[dict]:
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
        if isinstance(value, dict) and value.get("task"):
            records.append(value)
    return records


def _parse_value(raw: str):
    value = raw.strip()
    if value.lower() in ("true", "false"):
        return value.lower() == "true"
    if value.startswith("["):
        try:
            parsed = json.loads(value)
            return parsed if isinstance(parsed, list) else [value]
        except ValueError:
            return [item.strip() for item in value.strip("[]").split(",") if item.strip()]
    if value.isdigit() or (value.startswith("-") and value[1:].isdigit()):
        return int(value)
    try:
        if value.replace(".", "", 1).replace("-", "", 1).isdigit():
            return float(value)
    except ValueError:
        pass
    if value.lower() in ("null", "none"):
        return None
    return value


def _defaults(task: str) -> dict:
    return {
        "task": task,
        "class": "routine",
        "started_at": _now(),
        "finished_at": _now(),
        "owner_interventions": 0,
        "intervention_reasons": [],
        "attempts": 1,
        "acceptance": "UNKNOWN",
        "gate": "SKIPPED",
        "merged": False,
        "rolled_back": False,
        "cost_usd": 0.0,
        "notes": "",
    }


def _append(path: Path, record: dict) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    line = json.dumps(record, ensure_ascii=False, separators=(",", ":")) + "\n"
    fd = os.open(path, os.O_APPEND | os.O_CREAT | os.O_WRONLY, 0o600)
    try:
        fcntl.flock(fd, fcntl.LOCK_EX)
        os.write(fd, line.encode("utf-8"))
        os.fsync(fd)
    finally:
        fcntl.flock(fd, fcntl.LOCK_UN)
        os.close(fd)


def cmd_record(args: argparse.Namespace) -> int:
    task = str(args.task or "").strip()
    if not task:
        print("autonomy-ledger: task is required", file=sys.stderr)
        return 2
    record = _defaults(task)
    for pair in args.fields:
        if "=" not in pair:
            print(f"autonomy-ledger: ignoring field without '=': {pair}", file=sys.stderr)
            continue
        key, raw = pair.split("=", 1)
        key = key.strip()
        if not key:
            continue
        record[key] = _parse_value(raw)
    record["class"] = str(record.get("class", "routine")).strip().lower()
    record["acceptance"] = str(record.get("acceptance", "UNKNOWN")).strip().upper()
    record["gate"] = str(record.get("gate", "SKIPPED")).strip().upper()
    if record.get("class") not in CLASSES:
        print(f"autonomy-ledger: class must be one of {', '.join(CLASSES)}", file=sys.stderr)
        return 2
    if record.get("acceptance") not in ACCEPTANCE:
        print(f"autonomy-ledger: acceptance must be one of {', '.join(ACCEPTANCE)}", file=sys.stderr)
        return 2
    if record.get("gate") not in GATE:
        print(f"autonomy-ledger: gate must be one of {', '.join(GATE)}", file=sys.stderr)
        return 2
    if not isinstance(record.get("intervention_reasons"), list):
        record["intervention_reasons"] = [
            item.strip() for item in str(record.get("intervention_reasons") or "").split(",") if item.strip()
        ]
    try:
        record["owner_interventions"] = max(0, int(record.get("owner_interventions", 0)))
        record["attempts"] = max(1, int(record.get("attempts", 1)))
        record["cost_usd"] = float(record.get("cost_usd", 0.0) or 0.0)
    except (TypeError, ValueError):
        print("autonomy-ledger: owner_interventions, attempts and cost_usd must be numeric", file=sys.stderr)
        return 2
    _append(_ledger_path(args.dir), record)
    print(f"autonomy-ledger: recorded {task} (class={record['class']}, acceptance={record['acceptance']}, merged={record['merged']})")
    return 0


def _latest(records: list[dict]) -> list[dict]:
    latest: dict[str, dict] = {}
    order: list[str] = []
    for record in records:
        task = str(record.get("task"))
        if task in latest:
            order.remove(task)
        order.append(task)
        latest[task] = record
    return [latest[task] for task in order]


def _window(records: list[dict], size: int) -> list[dict]:
    if size > 0:
        return records[-size:]
    return records


def _report(records: list[dict]) -> dict:
    routine = [r for r in records if r.get("class") == "routine"]
    high_risk = [r for r in records if r.get("class") == "high-risk"]
    autonomous = [
        r for r in routine
        if int(r.get("owner_interventions", 0)) == 0
        and r.get("acceptance") == "PASS"
        and r.get("gate") == "PASS"
        and bool(r.get("merged"))
        and not bool(r.get("rolled_back"))
    ]
    reasons: dict[str, int] = {}
    for record in records:
        for reason in record.get("intervention_reasons") or []:
            reasons[str(reason)] = reasons.get(str(reason), 0) + 1
    attempts = [int(r.get("attempts", 1)) for r in records]
    return {
        "tasks": len(records),
        "routine": len(routine),
        "autonomous": len(autonomous),
        "autonomy_rate": (100.0 * len(autonomous) / len(routine)) if routine else None,
        "high_risk": len(high_risk),
        "owner_interventions": sum(int(r.get("owner_interventions", 0)) for r in records),
        "interventions_by_reason": dict(sorted(reasons.items())),
        "attempts_avg": mean(attempts) if attempts else 0.0,
        "merged": sum(1 for r in records if r.get("merged")),
        "rollbacks": sum(1 for r in records if r.get("rolled_back")),
        "cost_usd": round(sum(float(r.get("cost_usd", 0.0) or 0.0) for r in records), 4),
    }


def cmd_report(args: argparse.Namespace) -> int:
    records = _window(_latest(_read(_ledger_path(args.dir))), int(args.window))
    report = _report(records)
    if args.as_json:
        print(json.dumps(report, ensure_ascii=False, indent=2))
        return 0
    print(f"== autonomy ledger ({report['tasks']} tasks, window={args.window}) ==")
    if report["autonomy_rate"] is None:
        print("   routine: 0 (no routine tasks recorded)")
    else:
        print(f"   routine: {report['routine']} | autonomous: {report['autonomous']} | "
              f"rate: {report['autonomy_rate']:.1f}%")
    print(f"   high-risk: {report['high_risk']} | owner interventions: {report['owner_interventions']}")
    print(f"   attempts avg: {report['attempts_avg']:.2f} | merged: {report['merged']} | "
          f"rollbacks: {report['rollbacks']} | cost: ${report['cost_usd']:.4f}")
    if report["interventions_by_reason"]:
        print("   intervention reasons:")
        for reason, count in report["interventions_by_reason"].items():
            print(f"     {reason}: {count}")
    return 0


def cmd_list(args: argparse.Namespace) -> int:
    records = _latest(_read(_ledger_path(args.dir)))
    if args.as_json:
        print(json.dumps(records, ensure_ascii=False, indent=2))
        return 0
    if not records:
        print("autonomy-ledger: no records")
        return 0
    for record in records:
        print(
            f"   {str(record.get('task')):<32} {str(record.get('class')):<10} "
            f"accept={str(record.get('acceptance')):<7} gate={str(record.get('gate')):<7} "
            f"merged={str(bool(record.get('merged'))):<5} interventions={record.get('owner_interventions', 0)} "
            f"attempts={record.get('attempts', 1)}"
        )
    return 0


def main(argv: list[str] | None = None) -> int:
    args = _parser().parse_args(argv)
    return int(args.func(args))


if __name__ == "__main__":
    raise SystemExit(main())
