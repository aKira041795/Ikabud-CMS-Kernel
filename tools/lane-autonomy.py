#!/usr/bin/env python3
"""Bounded autonomous task loop for the lane harness.

Runs an implementation lane, and if its own acceptance criterion fails, feeds a
compact failure bundle back to a repair attempt. Every attempt is bounded; the
loop stops on no-progress, policy violations, exhausted models, or the attempt
limit. The outcome is recorded in the autonomy ledger.

This orchestrator never merges or pushes. It produces a verified tree and a
ledger record; landing remains a separate, risk-gated step.

Usage:
    tools/lane-autonomy.py run \
      --task my-task \
      --objective "what to change" \
      --acceptance "php tests/my_contract_test.php" \
      --pass-looks-like "the contract test prints OK" \
      --touches "modules/my-module/,tests/my_contract_test.php" \
      --max-attempts 2

    # compose prompts and the lane script without spending a token
    tools/lane-autonomy.py run ... --dry-run

    # test/override the lane transport (the stub reads LANE_AUTONOMY_ATTEMPT)
    tools/lane-autonomy.py run ... --lane-script /path/to/stub.sh
"""
from __future__ import annotations

import argparse
import hashlib
import json
import os
import re
import subprocess
import sys
import time
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
LANE = ROOT / "tools" / "lane.sh"
LEDGER = ROOT / "tools" / "autonomy-ledger.py"
MAX_BUNDLE_CHARS = 12000


def _now() -> str:
    return datetime.now(timezone.utc).isoformat(timespec="seconds")


def _parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("run", nargs="?", default="run", help=argparse.SUPPRESS)
    parser.add_argument("--task", help="task id (letters, digits, ._-); required unless --contract supplies it")
    prompt = parser.add_mutually_exclusive_group(required=False)
    prompt.add_argument("--objective", help="the task brief")
    prompt.add_argument("--prompt-file", help="file containing the task brief")
    parser.add_argument("--acceptance", help="command that fails now and passes when done")
    parser.add_argument("--pass-looks-like", help="what PASS looks like")
    parser.add_argument("--contract", help="contract JSON written by tools/autonomy-contract.py")
    parser.add_argument("--touches", default="", help="comma-separated path prefixes/globs")
    parser.add_argument("--role", default="implement",
                        choices=("architect", "implement", "review", "release-gate"))
    parser.add_argument("--max-attempts", type=int, default=2, help="implementation + repair attempts (default: 2)")
    parser.add_argument("--timeout", type=int, default=7200, help="per-lane timeout seconds (default: 7200)")
    parser.add_argument("--max-seconds", type=int, default=0, help="overall wall-clock cap (0 = no cap)")
    parser.add_argument("--class", dest="task_class", default=None,
                        choices=("routine", "high-risk"))
    parser.add_argument("--lane-script", help="override the generated model lane (advanced/testing)")
    parser.add_argument("--model-chain", default="", help="override LANE_MODEL_CHAIN for the generated lane")
    parser.add_argument("--work-dir", help="prompt/evidence directory (default: .ai/autonomy/<task>)")
    parser.add_argument("--ledger-dir", default=os.environ.get("LANE_AUTONOMY_DIR", ".ai/autonomy"),
                        help="autonomy ledger directory (default: .ai/autonomy)")
    parser.add_argument("--dry-run", action="store_true", help="write prompts/scripts; run nothing")
    return parser


def _slug(value: str) -> str:
    text = re.sub(r"[^a-zA-Z0-9._-]+", "-", str(value or "").strip()).strip("-")
    return text[:80] or "task"


def _read(path: Path) -> str:
    try:
        return path.read_text(encoding="utf-8", errors="replace")
    except OSError:
        return ""


def _tail(path: Path, lines: int = 120, chars: int = 4000) -> str:
    text = _read(path)
    if not text:
        return ""
    tail = "\n".join(text.splitlines()[-lines:])
    return tail[-chars:]


def _task_prompt(task: str, objective: str, acceptance: str, pass_looks_like: str,
                 touches: str, attempt: int) -> str:
    scope = touches.strip() or "no explicit scope. Keep the change as small as possible."
    if attempt == 1:
        return (
            f"# AUTONOMOUS TASK: {task}\n\n"
            "You are implementing one bounded task in the Ikabud repository. Work directly in the tree.\n\n"
            f"## OBJECTIVE\n{objective.strip()}\n\n"
            f"## ALLOWED SCOPE\n{scope}\n\n"
            "Do not modify anything outside the allowed scope. If the task genuinely requires it, stop and "
            "explain why instead of expanding scope silently.\n\n"
            f"## ACCEPTANCE (fixed; do not change it)\n    {acceptance.strip()}\n\n"
            f"PASS looks like: {pass_looks_like.strip()}\n\n"
            "## RULES\n"
            "- Make the smallest correct change.\n"
            "- Never weaken, skip, or delete an assertion or test to reach green.\n"
            "- Run the acceptance command before finishing and report its real result.\n"
            "- Check storage/logs/app.log and storage/logs/error.log for new errors.\n"
            "- If the task is blocked by missing architecture or an environmental failure, stop and report "
            "BLOCKED with the evidence.\n"
            "- Report: status PASS | PARTIAL | BLOCKED, what changed, acceptance output, and anything unresolved.\n"
        )
    return (
        f"# REPAIR ATTEMPT {attempt}: {task}\n\n"
        "The previous attempt did not satisfy the fixed acceptance criterion. Repair it; do not change the "
        "criterion and do not expand the task.\n\n"
        f"## OBJECTIVE\n{objective.strip()}\n\n"
        f"## ALLOWED SCOPE\n{scope}\n\n"
        f"## ACCEPTANCE (fixed)\n    {acceptance.strip()}\n\n"
        f"PASS looks like: {pass_looks_like.strip()}\n\n"
        "## RULES\n"
        "- Read the failure evidence below before editing.\n"
        "- Fix the cause, not the assertion.\n"
        "- Keep the diff inside the allowed scope.\n"
        "- Run the acceptance command before finishing and report its real result.\n"
        "- If the failure is environmental, architectural, or the same failure persists without new evidence, "
        "stop and report BLOCKED with the evidence instead of guessing.\n"
    )


def _repair_prompt(task: str, objective: str, acceptance: str, pass_looks_like: str,
                   touches: str, attempt: int, evidence: str) -> str:
    return (
        _task_prompt(task, objective, acceptance, pass_looks_like, touches, attempt)
        + "\n## FAILURE EVIDENCE\n"
        + (evidence.strip() or "(no evidence captured)")
        + "\n"
    )


def _evidence(lane_name: str, acceptance: str, touches: str = "") -> str:
    runs = ROOT / ".ai" / "runs"
    parts: list[str] = []
    for name, label in (
        (f"{lane_name}.landed.json", "landing record"),
        (f"{lane_name}.acceptance.log", "acceptance output"),
        (f"{lane_name}.log", "lane log"),
        (f"{lane_name}.events.jsonl", "lane events"),
    ):
        path = runs / name
        text = _tail(path, lines=80, chars=3500)
        if text:
            parts.append(f"### {label} ({name})\n{text}")
    for log_name in ("app.log", "error.log"):
        text = _tail(ROOT / "storage" / "logs" / log_name, lines=40, chars=2000)
        if text:
            parts.append(f"### storage/logs/{log_name} (tail)\n{text}")
    paths = [value.strip() for value in str(touches or "").split(",") if value.strip()]
    stat_cmd = ["git", "diff", "--stat"] + (["--", *paths] if paths else [])
    diff_cmd = ["git", "diff", "--unified=2"] + (["--", *paths] if paths else [])
    diff_stat = _run(stat_cmd, timeout=20).stdout.strip()
    if diff_stat:
        parts.append(f"### current diff stat\n{diff_stat}")
    diff = _run(diff_cmd, timeout=30).stdout
    if diff:
        parts.append(f"### current diff (truncated)\n{diff[-5000:]}")
    bundle = "\n\n".join(parts)
    if len(bundle) > MAX_BUNDLE_CHARS:
        bundle = bundle[:MAX_BUNDLE_CHARS] + "\n... (truncated)"
    return bundle


def _failure_hash(lane_name: str) -> str:
    text = _read(ROOT / ".ai" / "runs" / f"{lane_name}.acceptance.log")
    if not text:
        text = _read(ROOT / ".ai" / "runs" / f"{lane_name}.log")
    return hashlib.sha256(text[-4000:].encode("utf-8", errors="replace")).hexdigest()[:16]


def _run(cmd: list[str], timeout: int = 60, env: dict | None = None) -> subprocess.CompletedProcess:
    try:
        return subprocess.run(cmd, cwd=ROOT, capture_output=True, text=True,
                              timeout=timeout, env=env, check=False)
    except subprocess.TimeoutExpired as exc:
        return subprocess.CompletedProcess(cmd, 124, str(exc.stdout or ""), str(exc.stderr or "timed out"))


def _marker(lane_name: str) -> dict:
    path = ROOT / ".ai" / "runs" / f"{lane_name}.landed.json"
    if not path.is_file():
        return {}
    try:
        value = json.loads(path.read_text(encoding="utf-8", errors="replace"))
    except ValueError:
        return {}
    return value if isinstance(value, dict) else {}


def _generated_lane(path: Path) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(
        "#!/usr/bin/env bash\n"
        "set -u\n"
        f"cd {json.dumps(str(ROOT))} || exit 1\n"
        f"source {json.dumps(str(ROOT / 'tools' / 'lane-model.sh'))}\n"
        'PROMPT_FILE="${LANE_AUTONOMY_PROMPT:?}"\n'
        'PROMPT="$(cat "$PROMPT_FILE")"\n'
        'LOG_PREFIX="${LANE_AUTONOMY_LOG:-/tmp/lane-autonomy}"\n'
        'lane_model_run "${LANE_MODEL_CHAIN}" "$PROMPT" "$LOG_PREFIX"\n'
        "rc=$?\n"
        'echo "lane: ${LANE_AUTONOMY_TASK:-task} attempt ${LANE_AUTONOMY_ATTEMPT:-?} '
        '— completed by: ${LANE_MODEL_USED:-<none>} (rc=$rc)"\n'
        'exit "$rc"\n',
        encoding="utf-8",
    )
    path.chmod(0o755)


def _run_lane(task: str, attempt: int, prompt_path: Path, lane_script: Path,
              args: argparse.Namespace) -> tuple[subprocess.CompletedProcess, str, dict]:
    lane_name = f"{task}-a{attempt}"
    env = dict(os.environ)
    env["LANE_AUTONOMY_TASK"] = task
    env["LANE_AUTONOMY_ATTEMPT"] = str(attempt)
    env["LANE_AUTONOMY_PROMPT"] = str(prompt_path)
    env["LANE_AUTONOMY_LOG"] = f"/tmp/{lane_name}"
    if args.model_chain:
        env["LANE_MODEL_CHAIN"] = args.model_chain
    cmd = [
        "bash", str(LANE), "run", lane_name, str(lane_script),
        f"--role={args.role}",
        f"--acceptance={args.acceptance}",
        f"--pass-looks-like={args.pass_looks_like}",
        f"--timeout={args.timeout}",
        f"--slice={args.timeout}",
        "--wait-grace=30",
        "--strict-completion",
    ]
    if args.touches:
        cmd.append(f"--touches={args.touches}")
    result = _run(cmd, timeout=args.timeout + 120, env=env)
    return result, lane_name, _marker(lane_name)


def _acceptance_passes(acceptance: str) -> bool:
    result = _run(["bash", "-c", acceptance], timeout=3600)
    return result.returncode == 0


def _record(task: str, args: argparse.Namespace, attempts: int, acceptance: str,
            notes: str, started: str) -> None:
    cmd = [
        sys.executable, str(LEDGER), "--dir", str(args.ledger_dir), "record", task,
        f"class={args.task_class}",
        f"attempts={attempts}",
        f"acceptance={acceptance}",
        "gate=SKIPPED",
        "merged=false",
        "owner_interventions=0",
        f"started_at={started}",
        f"finished_at={_now()}",
        f"notes={notes}",
    ]
    result = _run(cmd, timeout=30)
    if result.returncode != 0:
        print(f"lane-autonomy: ledger record failed: {result.stderr.strip()}", file=sys.stderr)


def _load_contract(path: str | None) -> dict | None:
    if not path:
        return {}
    source = Path(path).expanduser()
    try:
        value = json.loads(source.read_text(encoding="utf-8", errors="replace"))
    except OSError as exc:
        print(f"lane-autonomy: contract unreadable: {source}: {exc}", file=sys.stderr)
        return None
    except ValueError as exc:
        print(f"lane-autonomy: contract is not valid JSON: {source}: {exc}", file=sys.stderr)
        return None
    if not isinstance(value, dict):
        print(f"lane-autonomy: contract must be a JSON object: {source}", file=sys.stderr)
        return None
    return value


def main(argv: list[str] | None = None) -> int:
    args = _parser().parse_args(argv)
    contract = _load_contract(args.contract)
    if contract is None:
        return 2
    if contract:
        if args.task and contract.get("task") and _slug(args.task) != _slug(str(contract["task"])):
            print("lane-autonomy: --task does not match the contract task", file=sys.stderr)
            return 2
        args.task = args.task or contract.get("task")
        if not args.objective and not args.prompt_file:
            args.objective = contract.get("objective")
        args.acceptance = args.acceptance or contract.get("acceptance")
        args.pass_looks_like = args.pass_looks_like or contract.get("pass_looks_like")
        if not args.touches:
            value = contract.get("touches") or []
            args.touches = ",".join(str(item) for item in value) if isinstance(value, list) else str(value)
        args.task_class = args.task_class or contract.get("class")
    args.task_class = args.task_class or "routine"
    if args.task_class not in ("routine", "high-risk"):
        print(f"lane-autonomy: invalid contract class: {args.task_class}", file=sys.stderr)
        return 2
    if not args.task:
        print("lane-autonomy: --task is required (or supply --contract)", file=sys.stderr)
        return 2
    if not args.acceptance or not args.pass_looks_like:
        print("lane-autonomy: --acceptance and --pass-looks-like are required (or supply --contract)",
              file=sys.stderr)
        return 2
    task = _slug(args.task)
    if task != args.task:
        print(f"lane-autonomy: using sanitized task id '{task}'", file=sys.stderr)
    objective = str(args.objective or "")
    if args.prompt_file:
        objective = _read(Path(args.prompt_file).expanduser())
        if not objective.strip():
            print(f"lane-autonomy: prompt file is empty or unreadable: {args.prompt_file}", file=sys.stderr)
            return 2
    if not objective.strip():
        print("lane-autonomy: objective is empty", file=sys.stderr)
        return 2
    work_dir = Path(args.work_dir).expanduser() if args.work_dir else ROOT / ".ai" / "autonomy" / task
    work_dir.mkdir(parents=True, exist_ok=True)
    lane_script = Path(args.lane_script).expanduser() if args.lane_script else work_dir / "lane.sh"
    if not args.lane_script:
        _generated_lane(lane_script)
    if not lane_script.is_file():
        print(f"lane-autonomy: lane script missing: {lane_script}", file=sys.stderr)
        return 2

    started = _now()
    deadline = time.time() + args.max_seconds if args.max_seconds > 0 else None
    attempts = 0
    previous_hash = ""
    for attempt in range(1, max(1, args.max_attempts) + 1):
        if deadline is not None and time.time() > deadline:
            _record(task, args, attempts, "BLOCKED", "overall wall-clock cap reached", started)
            print(f"lane-autonomy: {task} stopped: wall-clock cap reached", file=sys.stderr)
            return 3
        if attempt > 1:
            # A previous attempt may already satisfy the criterion even though lane.sh
            # reported a failure (for example a late write). Treat that as success.
            if _acceptance_passes(args.acceptance):
                _record(task, args, attempts, "PASS", "acceptance already passed before retry", started)
                print(f"lane-autonomy: {task} PASS (acceptance passes before attempt {attempt})")
                return 0
        if attempt == 1:
            prompt = _task_prompt(task, objective, args.acceptance, args.pass_looks_like,
                                  args.touches, attempt)
        else:
            evidence = _evidence(f"{task}-a{attempt - 1}", args.acceptance, args.touches)
            prompt = _repair_prompt(task, objective, args.acceptance, args.pass_looks_like,
                                    args.touches, attempt, evidence)
        prompt_path = work_dir / f"attempt-{attempt}.prompt.md"
        prompt_path.write_text(prompt, encoding="utf-8")
        print(f"== {task} attempt {attempt}/{args.max_attempts} ==")
        print(f"   prompt: {prompt_path}")
        print(f"   lane:   {lane_script}")
        if args.dry_run:
            print(f"lane-autonomy: dry-run wrote {prompt_path}; no lane dispatched")
            return 0
        result, lane_name, marker = _run_lane(task, attempt, prompt_path, lane_script, args)
        attempts = attempt
        print(f"   lane:   {lane_name} rc={result.returncode}")
        if result.returncode == 0 and marker.get("completion") == "PASS":
            _record(task, args, attempt, "PASS", "acceptance passed", started)
            print(f"lane-autonomy: {task} PASS (attempt {attempt})")
            return 0
        if result.returncode == 0:
            # A zero exit without a completion=PASS record is not success. Verify the
            # criterion directly before deciding.
            if _acceptance_passes(args.acceptance):
                _record(task, args, attempt, "PASS", "acceptance passed (marker unverified)", started)
                print(f"lane-autonomy: {task} PASS (acceptance verified)")
                return 0
            _record(task, args, attempt, "FAIL", "zero exit without completion=PASS", started)
            print(f"lane-autonomy: {task} BLOCKED: zero exit without completion=PASS", file=sys.stderr)
            return 3
        reason = str(marker.get("reason") or "")
        if reason in ("quota", "fatal", "empty", "timeout") or marker.get("state") == "unverified":
            _record(task, args, attempt, "BLOCKED", f"lane stopped: {reason or 'unverified'}", started)
            print(f"lane-autonomy: {task} BLOCKED: {reason or 'unverified'}", file=sys.stderr)
            return 3
        if result.returncode == 97:
            _record(task, args, attempt, "BLOCKED", "policy violation", started)
            print(f"lane-autonomy: {task} BLOCKED: policy violation", file=sys.stderr)
            return 3
        failure_hash = _failure_hash(lane_name)
        if failure_hash and failure_hash == previous_hash:
            _record(task, args, attempt, "FAIL", "identical failure repeated", started)
            print(f"lane-autonomy: {task} BLOCKED: identical failure repeated", file=sys.stderr)
            return 3
        previous_hash = failure_hash
        if result.returncode == 2 and _acceptance_passes(args.acceptance):
            _record(task, args, attempt, "PASS", "acceptance passed after gate refusal", started)
            print(f"lane-autonomy: {task} PASS (acceptance already passes)")
            return 0
        if attempt >= args.max_attempts:
            _record(task, args, attempt, "FAIL", "max attempts reached", started)
            print(f"lane-autonomy: {task} BLOCKED: max attempts reached", file=sys.stderr)
            return 1
    _record(task, args, attempts, "FAIL", "loop ended without a verdict", started)
    return 1


if __name__ == "__main__":
    raise SystemExit(main())
