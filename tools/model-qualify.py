#!/usr/bin/env python3
"""Model qualification harness - decide WHICH model may do WHICH task class.

Why this exists
---------------
`tools/model-chain.txt` is a fallback chain: it answers "what runs if the first choice is unavailable",
not "is the next choice any good at this work". Those are different questions, and conflating them is
how a provider outage becomes low-quality autonomous code changes.

This tool answers the second question, deterministically and for free. A task passes only when its
check passes: an executable command for edits, or a fixed text rubric for judgement calls. No judge
model is involved, so a verdict is reproducible and re-running the corpus costs nothing.

A model is QUALIFIED for a class only when every task in that class passes inside its budget.
Nothing here writes to tools/model-chain.txt - qualification is evidence for a human decision, not
an automatic promotion.

Run
---
    python3 tools/model-qualify.py --list
    python3 tools/model-qualify.py --dry-run                      # compose prompts, spend nothing
    python3 tools/model-qualify.py --model ollama:qwen2.5-coder:1.5b
    python3 tools/model-qualify.py --model deepseek-v4-flash --class advisory-triage

Transports
----------
    ollama:<name>   local inference over the HTTP API; also reports tok/s
    <anything else> the harness contract: `$LANE_MODEL_CMD --model <name> <prompt>`
                    (default `pi --print --approve`), so cloud models are measured the
                    same way the lanes actually call them

Output is one line per task, plus result.json and report.md under
.ai/runs/model-qualify-<timestamp>/. Exit 0 only when every selected task passes.
"""
from __future__ import annotations

import argparse
import json
import os
import re
import shlex
import socket
import subprocess
import sys
import tempfile
import time
import urllib.error
import urllib.request
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
CORPUS_DIR = ROOT / "tools" / "model-qualify"
DEFAULT_MODEL_CMD = "pi --print --approve"
OLLAMA_HOST = os.environ.get("OLLAMA_HOST", "http://localhost:11434").rstrip("/")
# Offline defaults: a model already pulled locally, and a prompt ceiling that keeps a run inside a
# usable wait. Both overridable by environment for a different box.
#
# 1.5b rather than 3b on measured grounds: both pass mechanical-edit 3/3, but 1.5b takes 46.3s against
# 79.4s (1.7x). Offline, the acceptance gate catches an occasional miss and a retry costs seconds, so
# speed beats per-sample reliability - the inverse of the online calculus. Flip to 3b if a rehearsal
# shows misses costing more than the saved time.
OFFLINE_MODEL = os.environ.get("MODEL_QUALIFY_OFFLINE_MODEL", "ollama:qwen2.5-coder:1.5b")
OFFLINE_MAX_PROMPT = int(os.environ.get("MODEL_QUALIFY_OFFLINE_MAX_PROMPT", "750"))
# Measured on this box (qwen2.5-coder:3b, three-point curve). Used only to PREDICT wall time in
# --dry-run, so a slow run is planned rather than discovered. Override on different hardware.
S_PER_1K_PROMPT = float(os.environ.get("MODEL_QUALIFY_S_PER_1K", "502"))
# Ollama's own convention is `host:port` without a scheme; accept both forms.
if not OLLAMA_HOST.startswith(("http://", "https://")):
    OLLAMA_HOST = "http://" + OLLAMA_HOST


# --------------------------------------------------------------------------- corpus


def load_corpus(path: Path) -> dict:
    with path.open("r", encoding="utf-8") as fh:
        return json.load(fh)


def estimate_tokens(text: str) -> int:
    """Rough prompt-size estimate (chars/4). The exact count comes back from the server after a run.

    Used only to refuse a dispatch that is obviously outside the shape a class was qualified at -
    never to report a measurement.
    """
    return max(1, len(text) // 4)


def compose_prompt(task: dict) -> str:
    prompt_file = CORPUS_DIR / task["prompt"]
    text = prompt_file.read_text(encoding="utf-8")
    if "{{INPUT}}" in text:
        if not task.get("input"):
            raise SystemExit(f"task {task['id']}: prompt expects {{INPUT}} but declares no input file")
        payload = (CORPUS_DIR / task["input"]).read_text(encoding="utf-8").rstrip("\n")
        text = text.replace("{{INPUT}}", payload)
    return text


# ----------------------------------------------------------------------- transports


class TransportError(Exception):
    """Raised when a model could not be invoked - always a task failure, never a skip."""


def call_ollama(model: str, prompt: str, timeout: float, max_tokens: int) -> dict:
    body = json.dumps(
        {
            "model": model,
            "prompt": prompt,
            "stream": False,
            "options": {"num_predict": max_tokens, "temperature": 0.2},
        }
    ).encode()
    req = urllib.request.Request(
        f"{OLLAMA_HOST}/api/generate", data=body, headers={"Content-Type": "application/json"}
    )
    started = time.time()
    try:
        with urllib.request.urlopen(req, timeout=timeout) as resp:
            data = json.load(resp)
    except urllib.error.HTTPError as exc:
        raise TransportError(f"ollama HTTP {exc.code}: {exc.read()[:200]!r}") from exc
    except (socket.timeout, TimeoutError) as exc:
        raise TransportError(
            f"timed out after {timeout:.0f}s - exceeds this task's budget; a speed failure, "
            "not a network fault"
        ) from exc
    except urllib.error.URLError as exc:
        if isinstance(getattr(exc, "reason", None), (socket.timeout, TimeoutError)):
            raise TransportError(
                f"timed out after {timeout:.0f}s - exceeds this task's budget; a speed failure, "
                "not a network fault"
            ) from exc
        raise TransportError(f"ollama unreachable: {exc.reason}") from exc
    except Exception as exc:  # noqa: BLE001 - any transport fault is a fail-closed outcome
        raise TransportError(f"ollama unavailable: {exc}") from exc

    wall = time.time() - started
    pre_n, pre_ns = data.get("prompt_eval_count", 0), max(data.get("prompt_eval_duration", 1), 1)
    gen_n, gen_ns = data.get("eval_count", 0), max(data.get("eval_duration", 1), 1)
    return {
        "text": (data.get("response") or "").strip(),
        "wall_seconds": round(wall, 1),
        "prompt_tokens": pre_n,
        "output_tokens": gen_n,
        "prefill_tps": round(pre_n / pre_ns * 1e9, 1),
        "gen_tps": round(gen_n / gen_ns * 1e9, 1),
    }


def call_command(model: str, prompt: str, timeout: float) -> dict:
    # Mirrors tools/lane-model.sh exactly: $LANE_MODEL_CMD ${LANE_POLICY_PI_FLAGS} --model X <prompt>
    cmd_template = os.environ.get("LANE_MODEL_CMD", DEFAULT_MODEL_CMD)
    flags = shlex.split(os.environ.get("LANE_POLICY_PI_FLAGS", ""))
    argv = shlex.split(cmd_template) + flags + ["--model", model, prompt]
    env = dict(os.environ)

    started = time.time()
    try:
        proc = subprocess.run(  # noqa: S603 - argv is built here, no shell
            argv, capture_output=True, text=True, timeout=timeout, cwd=str(ROOT), env=env
        )
    except subprocess.TimeoutExpired as exc:
        raise TransportError(f"timed out after {timeout:.0f}s") from exc
    except FileNotFoundError as exc:
        raise TransportError(f"model command not found: {argv[0]}") from exc

    wall = time.time() - started
    out = (proc.stdout or "").strip()
    if proc.returncode != 0 and not out:
        raise TransportError(f"exit {proc.returncode}: {(proc.stderr or '')[:200]}")
    return {
        "text": out,
        "wall_seconds": round(wall, 1),
        "prompt_tokens": None,
        "output_tokens": len(out.split()),
        "prefill_tps": None,
        "gen_tps": None,
    }


def invoke(model: str, prompt: str, budget: dict) -> dict:
    scale = float(os.environ.get("MODEL_QUALIFY_BUDGET_SCALE", "1") or 1)
    wall_budget = float(budget.get("max_wall_seconds", 300)) * scale
    max_tokens = int(budget.get("max_output_tokens", 600))
    if model.startswith("ollama:"):
        return call_ollama(model.split(":", 1)[1], prompt, wall_budget, max_tokens)
    return call_command(model, prompt, wall_budget)


# -------------------------------------------------------------------------- scoring


def extract_fenced(text: str) -> str:
    blocks = re.findall(r"```[ \t]*(?:php)?[ \t]*\r?\n(.*?)```", text, re.DOTALL | re.IGNORECASE)
    body = blocks[-1] if blocks else text
    body = re.sub(r"^\s*<\?php\s*", "", body.strip())
    body = re.sub(r"\?>\s*$", "", body).strip()
    return body


def score_rubric(text: str, rubric: dict) -> tuple[bool, list[str]]:
    """Deterministic text rubric. Every alternative is a regex, applied case-insensitively.

    Regex rather than substring on purpose: substring matching produced a false red on the first
    self-test - a correct answer saying "the tab fragment" failed a rubric that demanded the
    literal plural "tabs". Alternatives must be written to state the OUTCOME, not one phrasing.
    """
    # Validate every pattern BEFORE matching. An earlier version reused re.search's None for
    # "no match" and also for "invalid pattern", so every correctly non-matching exclude rule
    # was reported as a broken regex - a scorer fault masquerading as a model fault.
    broken: list[str] = []
    for group in rubric.get("must_include_all_of", []):
        for alt in group:
            try:
                re.compile(alt, re.IGNORECASE)
            except re.error as exc:
                broken.append(f"{alt!r} ({exc})")
    for pattern in rubric.get("must_exclude", []):
        try:
            re.compile(pattern, re.IGNORECASE)
        except re.error as exc:
            broken.append(f"{pattern!r} ({exc})")
    if broken:
        return False, [f"invalid rubric regex: {b}" for b in broken]

    problems: list[str] = []

    for group in rubric.get("must_include_all_of", []):
        if not any(re.search(alt, text, re.IGNORECASE) for alt in group):
            problems.append(f"missing any of: {' | '.join(group)}")

    for pattern in rubric.get("must_exclude", []):
        hit = re.search(pattern, text, re.IGNORECASE)
        if hit:
            problems.append(f"forbidden pattern matched: {hit.group(0)!r}")

    max_words = rubric.get("max_words")
    if max_words and len(text.split()) > int(max_words):
        problems.append(f"over word budget: {len(text.split())} > {max_words}")

    return (not problems), problems


def score_check(text: str, check: dict) -> tuple[bool, list[str]]:
    problems: list[str] = []
    body = extract_fenced(text) if check.get("extract", "").startswith("fenced") else text
    if not body.strip():
        return False, ["empty answer - nothing to execute"]

    with tempfile.NamedTemporaryFile("w", suffix=".php", prefix="qualify-", delete=False) as fh:
        fh.write("<?php\n" + body + "\n")
        candidate = fh.name

    try:
        for spec in check.get("commands", []):
            argv = shlex.split(spec["run"].replace("{file}", candidate))
            proc = subprocess.run(  # noqa: S603
                argv, capture_output=True, text=True, cwd=str(ROOT), timeout=120
            )
            expected = int(spec.get("expect_exit", 0))
            if proc.returncode != expected:
                detail = (proc.stderr or proc.stdout or "").strip().splitlines()
                problems.append(
                    f"`{spec['run']}` exit {proc.returncode} (expected {expected})"
                    + (f": {detail[0][:200]}" if detail else "")
                )
                break
    finally:
        try:
            os.unlink(candidate)
        except OSError:
            pass
    return (not problems), problems


def score(task: dict, text: str) -> tuple[bool, list[str]]:
    if "rubric" in task:
        return score_rubric(text, task["rubric"])
    if "check" in task:
        return score_check(text, task["check"])
    return False, ["task declares neither a rubric nor a check"]


def selftest(corpus: dict) -> int:
    """Prove each task's scorer can both pass a known-good answer and fail a known-bad one.

    A rubric that has never rejected anything is decoration, not a guard. This runs every
    scorer against fixtures/<id>.good.txt and fixtures/<id>.bad.txt, so the corpus is
    falsifiable without spending a token.
    """
    fixture_dir = CORPUS_DIR / "fixtures"
    broken = 0
    for task in corpus["tasks"]:
        for suffix, expect in (("good", True), ("bad", False)):
            path = fixture_dir / f"{task['id']}.{suffix}.txt"
            if not path.is_file():
                print(f"MISS   {task['id']:<32} {path.name} not found")
                broken += 1
                continue
            passed, problems = score(task, path.read_text(encoding="utf-8"))
            ok = passed == expect
            print(
                f"{'OK' if ok else 'BROKEN':<7}{task['id']:<32}{path.name:<44}"
                f"expected {'PASS' if expect else 'FAIL'}, got {'PASS' if passed else 'FAIL'}"
            )
            if not ok:
                broken += 1
                for problem in problems:
                    print(f"        - {problem}")
    print()
    if broken:
        print(f"selftest: {broken} broken scorer result(s) - fix the scorer before trusting a verdict")
        return 1
    print("selftest: every scorer discriminates (passes good, fails bad)")
    return 0


# --------------------------------------------------------------------------- runner


def run_task(model: str, task: dict, prompt: str | None = None) -> dict:
    prompt = prompt if prompt is not None else compose_prompt(task)
    record = {"id": task["id"], "class": task["class"], "gating": task.get("gating", True)}
    try:
        result = invoke(model, prompt, task.get("budget", {}))
    except TransportError as exc:
        record.update(passed=False, problems=[f"transport: {exc}"])
        return record

    passed, problems = score(task, result["text"])
    record.update(result)
    record.update(passed=passed, problems=problems)
    return record


def run_task_repeated(model: str, task: dict, runs: int, min_pass_rate: float,
                      max_prompt_tokens: int | None = None) -> dict:
    """Run a task N times and judge on the PASS RATE, not on one sample.

    Also enforces the prompt SHAPE. A verdict is a property of (model, class, prompt size): a model
    that passes a 900-token repair has not thereby been shown to pass a 12,000-token lane prompt, and
    on a CPU where prefill dominates, time scales with prompt length. Refusing an over-long prompt is
    cheaper and more honest than producing a verdict that does not transfer.
    """
    prompt = compose_prompt(task)
    est = estimate_tokens(prompt)
    # The tighter ceiling wins: a CLI limit must never be loosened by a task's own envelope.
    limits = [x for x in (task.get("max_prompt_tokens"), max_prompt_tokens) if x]
    limit = min(limits) if limits else None
    base = {
        "id": task["id"],
        "class": task["class"],
        "gating": task.get("gating", True),
        "prompt_tokens_est": est,
    }
    if limit and est > limit:
        base.update(
            passed=False, runs=0, passes=0, pass_rate=0.0, answers=[], attempts=[],
            problems=[
                f"prompt is ~{est} tokens but this class was qualified at <= {limit}; "
                "shorten the prompt or re-qualify at the larger size - a verdict does not "
                "transfer across prompt shapes"
            ],
        )
        return base

    results = [run_task(model, task, prompt) for _ in range(max(1, runs))]
    passes = sum(1 for r in results if r["passed"])
    rate = passes / len(results)

    record = dict(results[0])
    record.update(base)
    record.update(
        runs=len(results),
        passes=passes,
        pass_rate=round(rate, 2),
        passed=rate >= min_pass_rate,
        problems=results[0].get("problems", []),
        answers=[r.get("text", "") for r in results],
        attempts=[
            {
                key: r.get(key)
                for key in ("passed", "wall_seconds", "prompt_tokens", "output_tokens", "prefill_tps", "gen_tps", "problems")
            }
            for r in results
        ],
    )
    if passes < len(results):
        record["problems"] = list(record["problems"]) + [f"{passes}/{len(results)} runs passed"]
    return record


def offline_check(model: str) -> int:
    """Answer 'will local AI work right now?' in a few seconds, without spending anything meaningful.

    Run this while the internet is still up. Discovering that a model was never pulled - or that the
    server is down - during an outage is the failure mode this check exists to remove.
    """
    name = model.split(":", 1)[1] if model.startswith("ollama:") else model
    print(f"offline readiness check for {model}\n")

    try:
        with urllib.request.urlopen(f"{OLLAMA_HOST}/api/tags", timeout=5) as resp:
            tags = json.load(resp)
    except Exception as exc:  # noqa: BLE001
        print(f"  FAIL  ollama not reachable at {OLLAMA_HOST}: {exc}")
        return 1
    print(f"  ok    ollama reachable at {OLLAMA_HOST}")

    available = [m.get("name") for m in tags.get("models", [])]
    if name not in available:
        print(f"  FAIL  model '{name}' is not installed. Available: {', '.join(available) or 'none'}")
        return 1
    print(f"  ok    model '{name}' installed")

    started = time.time()
    try:
        body = json.dumps(
            {"model": name, "prompt": "Reply with the word READY.", "stream": False,
             "options": {"num_predict": 8, "temperature": 0.0}}
        ).encode()
        req = urllib.request.Request(f"{OLLAMA_HOST}/api/generate", data=body,
                                     headers={"Content-Type": "application/json"})
        with urllib.request.urlopen(req, timeout=600) as resp:
            d = json.load(resp)
    except Exception as exc:  # noqa: BLE001
        print(f"  FAIL  generation failed: {exc}")
        # A killed client request can leave the runner busy server-side: the next request then queues
        # and looks like a timeout. That reads as "the model is broken" when the fix is one command,
        # and a confusing error costs exactly the productive time offline mode exists to protect.
        try:
            with urllib.request.urlopen(f"{OLLAMA_HOST}/api/ps", timeout=5) as resp:
                loaded = [m.get("name") for m in json.load(resp).get("models", [])]
        except Exception:  # noqa: BLE001
            loaded = []
        if loaded:
            print(f"  hint  still loaded: {', '.join(loaded)} - a previous request may be stuck.")
            print(f"        Run:  ollama stop {name}   then retry this check.")
        return 1
    wall = time.time() - started
    print(f"  ok    generation round-trip in {wall:.1f}s (includes model load if cold)")
    gen_ns = max(d.get("eval_duration", 1), 1)
    gen = d.get("eval_count", 0)
    if gen:
        print(f"  info  generation ~{gen / gen_ns * 1e9:.1f} tok/s on this hardware")

    print("\n  What this does NOT tell you: whether the model is good enough for a class.")
    print("  That is what qualification measures - see .ai/runs/model-qualify-*/report.md.")
    print("\nREADY for offline use" if wall < 300 else "\nREADY, but slow - expect long waits")
    return 0


def main() -> int:
    ap = argparse.ArgumentParser(description="Qualify a model for named harness task classes.")
    ap.add_argument("--model", help="ollama:<name> or a model name for the LANE_MODEL_CMD contract")
    ap.add_argument("--class", dest="klass", help="only run tasks in this class")
    ap.add_argument("--task", help="only run this task id")
    ap.add_argument("--corpus", default=str(CORPUS_DIR / "tasks.json"))
    ap.add_argument("--runs", type=int, default=3,
                    help="samples per task (default 3); a verdict is a pass rate, not one sample")
    ap.add_argument("--min-pass-rate", type=float, default=1.0,
                    help="fraction of runs that must pass for a task to count as passed (default 1.0)")
    ap.add_argument("--scale-budget", type=float, default=1.0,
                    help="multiply every task's max_wall_seconds (default 1.0). Use on slow hardware "
                         "to separate 'too slow for this budget' from 'wrong'.")
    ap.add_argument("--max-prompt-tokens", type=int, default=None,
                    help="refuse any task whose prompt exceeds this size - a verdict measured at one "
                         "prompt size is not evidence for a much larger one")
    ap.add_argument("--offline", action="store_true",
                    help="degraded mode for a link outage: local model only, prompts under the offline "
                         "ceiling, gating classes only")
    ap.add_argument("--offline-check", action="store_true",
                    help="verify local inference works right now; run this while still online")
    ap.add_argument("--list", action="store_true", help="list the corpus and exit")
    ap.add_argument("--selftest", action="store_true", help="prove the scorers pass good and fail bad fixtures")
    ap.add_argument("--score-file", help="re-score a saved answer with --task, without calling a model")
    ap.add_argument("--dry-run", action="store_true", help="compose prompts only, spend nothing")
    args = ap.parse_args()

    corpus = load_corpus(Path(args.corpus))
    if args.offline_check:
        return offline_check(args.model or OFFLINE_MODEL)

    if args.offline:
        args.model = args.model or OFFLINE_MODEL
        if args.max_prompt_tokens is None:
            args.max_prompt_tokens = OFFLINE_MAX_PROMPT

    tasks = corpus["tasks"]
    if args.klass:
        tasks = [t for t in tasks if t["class"] == args.klass]
    if args.task:
        tasks = [t for t in tasks if t["id"] == args.task]
    if args.offline:
        tasks = [t for t in tasks if t.get("gating", True)]
    if not tasks:
        print("no tasks selected", file=sys.stderr)
        return 2

    if args.list:
        for t in corpus["tasks"]:
            print(f"{t['class']:<18} {t['id']:<32} {corpus['classes'].get(t['class'], '')}")
        return 0

    if args.selftest:
        return selftest(corpus)

    if args.score_file:
        text = Path(args.score_file).read_text(encoding="utf-8")
        task = tasks[0]
        passed, problems = score(task, text)
        print(f"{'PASS' if passed else 'FAIL'}  {task['id']}  <- {args.score_file}")
        for problem in problems:
            print(f"      - {problem}")
        return 0 if passed else 1

    if args.dry_run:
        for t in tasks:
            prompt = compose_prompt(t)
            tok = estimate_tokens(prompt)
            minutes = tok / 1000 * S_PER_1K_PROMPT / 60
            print(f"=== {t['id']} ({t['class']}) - {len(prompt)} chars, ~{tok} tokens "
                  f"(~{minutes:.1f} min on CPU at {S_PER_1K_PROMPT:.0f} s/1k) ===")
            print(prompt[:400] + ("\n...[truncated]" if len(prompt) > 400 else ""))
            print()
        return 0

    if not args.model:
        print("--model is required unless --list or --dry-run", file=sys.stderr)
        return 2

    stamp = time.strftime("%Y%m%d-%H%M%S")
    outdir = ROOT / ".ai" / "runs" / f"model-qualify-{stamp}"
    outdir.mkdir(parents=True, exist_ok=True)
    os.environ["MODEL_QUALIFY_BUDGET_SCALE"] = str(args.scale_budget)

    print(f"model: {args.model}" + (f"  (budget x{args.scale_budget})" if args.scale_budget != 1.0 else "") + "\n")
    if args.offline:
        print(f"OFFLINE MODE: local model only; prompts over {args.max_prompt_tokens} tokens are "
              "refused rather than guessed at; verdicts are gating classes only.\n")
    records = []
    for task in tasks:
        record = run_task_repeated(args.model, task, args.runs, args.min_pass_rate,
                                   args.max_prompt_tokens)
        records.append(record)
        verdict = "PASS" if record["passed"] else "FAIL"
        timing = ""
        if record.get("gen_tps"):
            timing = f"  [{record['wall_seconds']}s, {record['prefill_tps']} pre / {record['gen_tps']} gen tok/s]"
        elif record.get("wall_seconds"):
            timing = f"  [{record['wall_seconds']}s]"
        print(f"{verdict}  {record['id']:<32} {record['class']:<18} {record['passes']}/{record['runs']}{timing}")
        for problem in record.get("problems", []):
            print(f"        - {problem}")

        for index, answer in enumerate(record.get("answers", []), start=1):
            (outdir / f"{record['id']}.answer-{index}.txt").write_text(answer, encoding="utf-8")

    by_class: dict[str, list[dict]] = {}
    for record in records:
        by_class.setdefault(record["class"], []).append(record)

    print()
    qualified: dict[str, bool | None] = {}
    for klass, group in sorted(by_class.items()):
        passed_n = sum(1 for r in group if r["passed"])
        if not all(r.get("gating", True) for r in group):
            qualified[klass] = None
            print(f"{'SCREENED':<14} {klass} ({passed_n}/{len(group)}) "
                  "- non-gating: no deterministic oracle for judgement work")
            continue
        ok = passed_n == len(group)
        qualified[klass] = ok
        print(f"{'QUALIFIED' if ok else 'NOT QUALIFIED':<14} {klass} ({passed_n}/{len(group)})")

    payload = {
        "model": args.model,
        "when": stamp,
        "qualified": qualified,
        "transport": os.environ.get("LANE_MODEL_CMD", DEFAULT_MODEL_CMD),
        "records": records,
    }
    (outdir / "result.json").write_text(json.dumps(payload, indent=2), encoding="utf-8")

    lines = [f"# Model qualification - {args.model}", "", f"Run: {stamp}", "",
             f"Samples per task: {args.runs}, required pass rate: {args.min_pass_rate}", "",
             "| task | class | runs | verdict | wall | tok/s |", "|---|---|---|---|---|---|"]
    for record in records:
        speed = ""
        if record.get("gen_tps"):
            speed = f"{record['prefill_tps']} pre / {record['gen_tps']} gen"
        lines.append(
            f"| {record['id']} | {record['class']} | {record['passes']}/{record['runs']} "
            f"| {'PASS' if record['passed'] else 'FAIL'} "
            f"| {record.get('wall_seconds', '-')}s | {speed} |"
        )
    lines += ["", "## Class verdicts", ""]
    for klass, ok in qualified.items():
        if ok is None:
            lines.append(f"- **SCREENED (non-gating)** - {klass}: no deterministic oracle, so this class cannot certify")
        else:
            lines.append(f"- **{'QUALIFIED' if ok else 'NOT QUALIFIED'}** - {klass}")
    failures = [r for r in records if not r["passed"]]
    lines += ["", "## Cost model", "",
              "Wall seconds per 1,000 prompt tokens. On a CPU, prefill dominates - this number is what",
              "predicts the cost of a real lane prompt (thousands of tokens), not the corpus task.", "",
              "| task | prompt tok | wall s | s per 1k prompt tok |", "|---|---|---|---|"]
    for record in records:
        ptok = record.get("prompt_tokens") or record.get("prompt_tokens_est")
        wall = record.get("wall_seconds")
        if ptok and wall:
            lines.append(f"| {record['id']} | {ptok} | {wall} | {wall / ptok * 1000:.0f} |")

    if failures:
        lines += ["", "## Failure detail", ""]
        for record in failures:
            lines.append(f"### {record['id']}")
            for problem in record.get("problems", []):
                lines.append(f"- {problem}")
            lines.append("")
    (outdir / "report.md").write_text("\n".join(lines) + "\n", encoding="utf-8")

    print(f"\nartifacts: {outdir.relative_to(ROOT)}")
    gating_failures = [r for r in records if r.get("gating", True) and not r["passed"]]
    return 0 if not gating_failures else 1


if __name__ == "__main__":
    sys.exit(main())
