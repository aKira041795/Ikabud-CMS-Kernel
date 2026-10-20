#!/usr/bin/env python3
"""Validate an autonomous task before it is dispatched.

The contract records what the task is, what it may touch, how risky that is,
and proof that the acceptance criterion fails on the current tree. A criterion
that already passes cannot discriminate the change, so it is refused here for
the same reason `tools/lane.sh` refuses it at dispatch.

Usage:
    tools/autonomy-contract.py \
      --task fix-import \
      --objective "Reject duplicate import rows with a clear error." \
      --acceptance "php tests/import_contract_test.php" \
      --pass-looks-like "the contract test prints OK and exits 0" \
      --touches "modules/import/,tests/import_contract_test.php"

    tools/lane-autonomy.py run --contract .ai/autonomy/contracts/fix-import.json
"""
from __future__ import annotations

import argparse
import json
import re
import subprocess
import sys
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
DEFAULT_DIR = ROOT / ".ai" / "autonomy" / "contracts"
TAUTOLOGY = re.compile(r"^\s*(?:true|:|exit\s+0|echo(?:\s+[^;&|]*)?)\s*$", re.IGNORECASE)
ALWAYS_TRUE = re.compile(r"(?:\|\||;|&&)\s*(?:true|:|exit\s+0)\s*$", re.IGNORECASE)
HIGH_RISK_PATTERNS = (
    re.compile(r"^kernel/"),
    re.compile(r"^(?:database/)?migrations?/"),
    re.compile(r"^config/"),
    re.compile(r"^public/index\.php$"),
    re.compile(r"^bootstrap\.php$"),
    re.compile(r"^composer\.(?:json|lock)$"),
    re.compile(r"^modules/[^/]+/module\.json$"),
    re.compile(r"^tools/lane-.*\.(?:sh|py)$"),
    re.compile(r"^tools/(?:autonomy-ledger|lane-autonomy)\.py$"),
    re.compile(r"^\.github/(?:workflows|instructions)/"),
)
HIGH_RISK_TOKENS = ("auth", "security", "crypto", "jwt", "payment", "billing", "provision", "migration")


def _now() -> str:
    return datetime.now(timezone.utc).isoformat(timespec="seconds")


def _slug(value: str) -> str:
    text = re.sub(r"[^a-zA-Z0-9._-]+", "-", str(value or "").strip()).strip("-")
    return text[:80] or "task"


def _risk_paths(touches: list[str]) -> list[str]:
    hits: list[str] = []
    for path in touches:
        low = path.lower()
        if any(pattern.search(low) for pattern in HIGH_RISK_PATTERNS) or any(
            token in low for token in HIGH_RISK_TOKENS
        ):
            hits.append(path)
    return hits


def _normalize_touches(raw: str) -> tuple[list[str], str]:
    values: list[str] = []
    for value in str(raw or "").split(","):
        value = value.strip().replace("\\", "/")
        if not value:
            continue
        if value.startswith("/") or ".." in Path(value).parts:
            return [], f"touch must be repository-relative: {value}"
        values.append(value.rstrip("/") or value)
    return values, ""


def _probe(acceptance: str, timeout: int) -> subprocess.CompletedProcess | None:
    try:
        return subprocess.run(
            ["bash", "-c", acceptance],
            cwd=ROOT,
            capture_output=True,
            text=True,
            timeout=timeout,
            check=False,
        )
    except subprocess.TimeoutExpired:
        return None


def _parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(
        description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter
    )
    parser.add_argument("--task", required=True, help="task id (letters, digits, ._-)")
    parser.add_argument("--objective", required=True, help="the task brief")
    parser.add_argument("--acceptance", required=True, help="command that fails now and passes when done")
    parser.add_argument("--pass-looks-like", required=True, help="what PASS looks like")
    parser.add_argument("--touches", default="", help="comma-separated repository-relative paths")
    parser.add_argument("--class", dest="task_class", choices=("routine", "high-risk"),
                        help="risk class; defaults to the classifier's result")
    parser.add_argument("--no-probe", default="",
                        help="skip the failing-criterion probe; a reason is required")
    parser.add_argument("--probe-timeout", type=int, default=600,
                        help="probe timeout seconds (default: 600)")
    parser.add_argument("--contract-dir", default=str(DEFAULT_DIR),
                        help="where the contract JSON is written")
    parser.add_argument("--json", action="store_true", help="print the contract JSON")
    return parser


def main(argv: list[str] | None = None) -> int:
    args = _parser().parse_args(argv)
    task = _slug(args.task)
    if task != args.task:
        print(f"autonomy-contract: using sanitized task id '{task}'", file=sys.stderr)
    objective = args.objective.strip()
    acceptance = args.acceptance.strip()
    pass_looks_like = args.pass_looks_like.strip()
    if len(objective) < 10:
        print("autonomy-contract: objective is too short to be a contract", file=sys.stderr)
        return 2
    if not acceptance:
        print("autonomy-contract: acceptance command is empty", file=sys.stderr)
        return 2
    if TAUTOLOGY.match(acceptance) or ALWAYS_TRUE.search(acceptance):
        print("autonomy-contract: acceptance command cannot fail; it cannot discriminate a change",
              file=sys.stderr)
        return 2
    if len(pass_looks_like) < 8:
        print("autonomy-contract: --pass-looks-like must describe the observable PASS state",
              file=sys.stderr)
        return 2
    touches, error = _normalize_touches(args.touches)
    if error:
        print(f"autonomy-contract: {error}", file=sys.stderr)
        return 2
    risk = _risk_paths(touches)
    task_class = args.task_class or ("high-risk" if risk else "routine")
    if task_class == "routine" and risk:
        print("autonomy-contract: task touches high-risk paths; declare --class high-risk: "
              + ", ".join(risk), file=sys.stderr)
        return 2

    probe: dict = {"acceptance_fails_now": None}
    if args.no_probe:
        probe["probe_skipped_reason"] = args.no_probe.strip()
    else:
        result = _probe(acceptance, max(1, args.probe_timeout))
        if result is None:
            print("autonomy-contract: acceptance probe timed out; a criterion that hangs is not a "
                  "usable contract", file=sys.stderr)
            return 2
        if result.returncode == 0:
            print("autonomy-contract: acceptance already passes on this tree; it cannot prove the "
                  "change", file=sys.stderr)
            return 2
        probe = {"acceptance_fails_now": True, "probe_exit": result.returncode}

    contract = {
        "version": 1,
        "task": task,
        "objective": objective,
        "acceptance": acceptance,
        "pass_looks_like": pass_looks_like,
        "touches": touches,
        "class": task_class,
        "risk_paths": risk,
        "created_at": _now(),
        **probe,
    }
    contract_dir = Path(args.contract_dir).expanduser()
    contract_dir.mkdir(parents=True, exist_ok=True)
    path = contract_dir / f"{task}.json"
    path.write_text(json.dumps(contract, indent=2, sort_keys=True) + "\n", encoding="utf-8")

    if args.json:
        print(json.dumps(contract, indent=2, sort_keys=True))
    else:
        print(f"contract: {path}")
        print(f"class: {task_class}" + (f" (risk: {', '.join(risk)})" if risk else " (no high-risk paths)"))
        if probe["acceptance_fails_now"] is True:
            print(f"acceptance fails now: yes (exit {probe['probe_exit']})")
        else:
            print(f"acceptance probe: SKIPPED ({probe.get('probe_skipped_reason', '')})")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
