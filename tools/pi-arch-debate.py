#!/usr/bin/env python3
"""pi-arch-debate.py — Architecture debate via the Pi execution harness.

DeepSeek Pro drafts/revises the architecture from your intent; Codex Sol
(ChatGPT Pro) critiques it. Loop converges on APPROVED, then writes the agreed
architecture to .ai/current-task.md.

Each model's output is STREAMED LIVE to the terminal as a visible chat:
  🧠 DeepSeek Pro — Round N draft/revise
  🔍 Codex Sol — Round N critique
Tool activity shows as compact [⌛ tool: …] markers.

Usage:
  python3 tools/pi-arch-debate.py "<intent description>"
  python3 tools/pi-arch-debate.py --first codex|deepseek "<intent>"   # chair picks the drafter
  python3 tools/pi-arch-debate.py --preflight "<short intent>"        # firm intent first (DeepSeek Flash)
  python3 tools/pi-arch-debate.py --fast "<intent>"                   # single-round triage
  python3 tools/pi-arch-debate.py --approve                            # chair-approve last saved draft (no API)
  python3 tools/pi-arch-debate.py --quiet "<intent>"                  # no live print

Default opener is AUTO (intent-based chair decision):
  - Codex Sol opens when intent signals precision/security/correctness/gap-hunting.
  - DeepSeek Pro opens when intent signals broad building/exploration.
  The decision + reason is printed; override with --first.

Env:
  DEBATE_MAX_ROUNDS     max draft/critique cycles (default 3)
  PI_MODEL_TIMEOUT      per-model timeout in seconds (default 600)
  DEBATE_AUTO_APPROVE=1 auto-approve the last draft (scripted use)

Artifacts:
  .ai/debate/round-N-draft.jsonl|txt
  .ai/debate/round-N-critique.jsonl|txt
  .ai/current-task.md            (agreed contract on approval / last draft)
"""
import json
import os
import re
import subprocess
import sys
import threading
from difflib import SequenceMatcher

QUIET = False

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
os.chdir(ROOT)

WORK = ".ai/debate"
os.makedirs(WORK, exist_ok=True)
MAX_ROUNDS = int(os.environ.get("DEBATE_MAX_ROUNDS", "3"))

DS_PRO = "deepseek-v4-pro"
CODEX = "openai-codex/gpt-5.6-sol"


def extract_text(jsonl_path: str) -> str:
    parts = []
    try:
        with open(jsonl_path) as f:
            for line in f:
                line = line.strip()
                if not line:
                    continue
                obj = json.loads(line)
                if obj.get("type") == "message_update":
                    ae = obj.get("assistantMessageEvent", {})
                    if ae.get("type") == "text_delta":
                        parts.append(ae.get("delta", ""))
    except Exception as exc:
        print(f"  (parse warning: {exc})")
    return "".join(parts).strip()


def run_pi(model: str, prompt: str, tag: str, round_no: int, label: str) -> str:
    """Run Pi, streaming the model's text to the terminal live (chat-style).

    The model's text deltas are printed as they arrive; tool activity is shown
    as a compact marker. Full JSONL + extracted text are still saved per round.
    """
    jsonl = os.path.join(WORK, f"round-{round_no}-{tag}.jsonl")
    timeout_s = int(os.environ.get("PI_MODEL_TIMEOUT", "600"))
    parts: list[str] = []

    def banner() -> None:
        print()
        print("=" * 62)
        print(f"  {label}")
        print("=" * 62, flush=True)

    proc = subprocess.Popen(
        ["pi", "--model", model, "--mode", "json", "--print", prompt],
        stdout=subprocess.PIPE, stderr=subprocess.STDOUT,
    )

    def reader() -> None:
        try:
            with open(jsonl, "w") as fh:
                for raw in proc.stdout:
                    line = raw.decode("utf-8", "replace")
                    fh.write(line)
                    fh.flush()
                    line = line.strip()
                    if not line:
                        continue
                    try:
                        obj = json.loads(line)
                    except Exception:
                        continue
                    if obj.get("type") == "message_update":
                        ae = obj.get("assistantMessageEvent", {})
                        if ae.get("type") == "text_delta":
                            delta = ae.get("delta", "")
                            parts.append(delta)
                            if not QUIET:
                                print(delta, end="", flush=True)
                    elif obj.get("type") == "toolcall_start":
                        if not QUIET:
                            print(f"\n[⌛ tool: {obj.get('name') or '?'} …]", flush=True)
        except Exception as exc:
            if not QUIET:
                print(f"\n[stream error: {exc}]", flush=True)

    if not QUIET:
        banner()
    t = threading.Thread(target=reader, daemon=True)
    t.start()
    try:
        proc.wait(timeout=timeout_s)
    except subprocess.TimeoutExpired:
        proc.kill()
        proc.wait()
        if not QUIET:
            print(f"\n[⚠ {tag} timed out after {timeout_s}s — using partial output]")
    t.join(timeout=5)
    text = "".join(parts).strip()
    with open(os.path.join(WORK, f"round-{round_no}-{tag}.txt"), "w") as fh:
        fh.write(text)
    return text


def load_template(name: str) -> str:
    with open(os.path.join("tools", "arch-debate-prompts", name)) as fh:
        return fh.read()


def verdict_of(critique: str) -> str:
    for line in critique.splitlines():
        m = re.search(r"VERDICT:\s*(APPROVED|REVISIONS)", line, re.I)
        if m:
            return m.group(1).upper()
    return "REVISIONS"  # ambiguous -> one more revision round


def latest_draft_path() -> str:
    drafts = sorted(
        (f for f in os.listdir(WORK) if re.fullmatch(r"round-\d+-draft\.txt", f)),
        key=lambda f: int(re.match(r"round-(\d+)-draft\.txt", f).group(1)),
    )
    return os.path.join(WORK, drafts[-1]) if drafts else ""


def chair_approve() -> None:
    src = latest_draft_path()
    if not src:
        print("No prior debate draft found in .ai/debate/ — run the debate first.")
        sys.exit(1)
    draft = open(src).read()
    dst = ".ai/current-task.md"
    with open(dst, "w") as fh:
        fh.write(draft + "\n")
    with open(os.path.join(WORK, "approved.txt"), "w") as fh:
        fh.write("chair-approved\n")
    print(f"APPROVED (chair): wrote {dst} from {src} ({len(draft)} chars)")


def run_preflight(intent: str) -> str:
    tmpl = load_template("preflight.txt").replace("{{INTENT}}", intent)
    out = run_pi("deepseek-v4-flash", tmpl, "preflight", 0, "🧭 Intent pre-flight (DeepSeek Flash)")
    with open(os.path.join(WORK, "preflight.txt"), "w") as fh:
        fh.write(out)
    return out


def suggest_first(intent: str) -> tuple[str, str]:
    """Chair decision: which model opens (drafts) based on intent signals."""
    i = intent.lower()
    codex_signals = [
        "secur", "correct", "bug", "vulnerab", "csrf", "jwt", "edge",
        "tight", "fix", "regress", "compliance", "privacy", "authoriz",
        "sanitiz", "strict", "precis", "audit", "review", "risk", "gap",
    ]
    deepseek_signals = [
        "build", "create", "design", "new module", "greenfield", "explore",
        "discover", "comprehensive", "multi", "sync", "port", "roadmap",
        "architecture", "from scratch", "broad", "full", "implement", "module",
    ]
    cs = [s for s in codex_signals if s in i]
    ds = [s for s in deepseek_signals if s in i]
    if cs and (not ds or len(cs) >= len(ds)):
        return "codex", "precision/gap-hunting signals: " + ", ".join(sorted(set(cs))[:5])
    return "deepseek", ("broad/building signals: " + ", ".join(sorted(set(ds))[:5])) if ds else "default"


def main() -> None:
    global QUIET
    args = [a for a in sys.argv[1:]]
    QUIET = "--quiet" in args
    PREFLIGHT = "--preflight" in args
    CHAIR = "--approve" in args
    FAST = "--fast" in args
    AUTO = os.environ.get("DEBATE_AUTO_APPROVE", "0") == "1"
    first = "auto"
    clean = []
    i = 0
    while i < len(args):
        a = args[i]
        if a == "--first" and i + 1 < len(args):
            first = args[i + 1].lower()
            i += 2
            continue
        if a in ("--quiet", "--preflight", "--approve", "--fast"):
            i += 1
            continue
        clean.append(a)
        i += 1
    intent = " ".join(clean).strip()

    if CHAIR:
        chair_approve()
        return

    if not intent:
        print(__doc__)
        sys.exit(1)

    rounds = 1 if FAST else MAX_ROUNDS

    # Pre-flight: firm up short/fuzzy intent with a cheap DeepSeek Flash call.
    if PREFLIGHT or len(intent) < 120:
        print("→ Pre-flighting intent (DeepSeek Flash)…")
        brief = run_preflight(intent)
        if len(brief) > 40:
            intent = brief
        else:
            print("    (preflight too short — using raw intent)")

    # Chair: decide which model opens (drafts) based on intent.
    if first == "auto":
        first, reason = suggest_first(intent)
        print(f"→ Chair: intent-based opener = {first.upper()} ({reason})")
    else:
        print(f"→ Chair: explicit opener = {first.upper()}")
    if first not in ("codex", "deepseek"):
        print("    (unknown --first value; defaulting to deepseek)")
        first = "deepseek"
    DRAFTER, CRITIC = (CODEX, DS_PRO) if first == "codex" else (DS_PRO, CODEX)
    draft_label = f"{'🔍' if first == 'codex' else '🧠'} {'Codex Sol' if first == 'codex' else 'DeepSeek Pro'}"
    critic_label = f"{'🧠' if first == 'codex' else '🔍'} {'DeepSeek Pro' if first == 'codex' else 'Codex Sol'}"

    draft = ""
    prev_draft = ""
    critique = ""
    verdict = "REVISIONS"
    rounds_used = 0
    converged = False

    for r in range(1, rounds + 1):
        rounds_used = r
        dp = load_template("draft.txt")
        dp = dp.replace("{{INTENT}}", intent)
        dp = dp.replace("{{PREVIOUS_DRAFT}}", draft or "(none)")
        dp = dp.replace("{{CRITIQUE}}", critique or "(none)")
        draft = run_pi(DRAFTER, dp, "draft", r, f"{draft_label} — Round {r} draft/revise")
        print(f"\n    → draft len={len(draft)}")
        if len(draft) < 100:
            print("    WARN: draft suspiciously short")

        # Convergence check: if the draft stopped changing, stop to save cost.
        if prev_draft and SequenceMatcher(None, prev_draft, draft).ratio() > 0.9:
            print(f"    → converged (draft ~unchanged); stopping early to save cost")
            converged = True
            break

        cp = load_template("critique.txt")
        cp = cp.replace("{{INTENT}}", intent)
        cp = cp.replace("{{DRAFT}}", draft)
        critique = run_pi(CRITIC, cp, "critique", r, f"{critic_label} — Round {r} critique")
        verdict = verdict_of(critique)
        print(f"    → verdict={verdict}  critique len={len(critique)}")
        if verdict == "APPROVED":
            break
        prev_draft = draft

    if verdict != "APPROVED" and (AUTO or converged):
        verdict = "APPROVED"

    dst = ".ai/current-task.md"
    with open(dst, "w") as fh:
        fh.write(draft + "\n")
    if verdict == "APPROVED":
        with open(os.path.join(WORK, "approved.txt"), "w") as fh:
            fh.write("approved\n")

    print("=== DONE ===")
    print(f"verdict: {verdict} after {rounds_used} round(s)")
    print(f"wrote: {dst} ({len(draft)} chars)")
    print(f"artifacts: {WORK}/")
    if verdict != "APPROVED":
        print("NOTE: not APPROVED. Options:")
        print("  arch-debate --approve                      # accept the last draft (chair-approved)")
        print("  DEBATE_MAX_ROUNDS=5 arch-debate …          # more rounds")
        print("  DEBATE_AUTO_APPROVE=1 arch-debate …        # auto-approve the last draft")


if __name__ == "__main__":
    main()
