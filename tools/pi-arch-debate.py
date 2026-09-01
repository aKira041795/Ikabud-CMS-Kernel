#!/usr/bin/env python3
"""pi-arch-debate.py — Architecture debate via the Pi execution harness.

A debate is ALWAYS run by TWO DIFFERENT LLM models — one per side. Side A
(DEFAULT drafter) and Side B (DEFAULT critic) must be distinct models; the
runner fails closed if they resolve to the same model, so a one-sided debate
can never happen. Override the pair with DEBATE_MODEL_A / DEBATE_MODEL_B
(legacy aliases: DEBATE_CODEX_MODEL / DEBATE_DEEPSEEK_MODEL).

Each model's output is STREAMED LIVE to the terminal as a visible chat:
  Side A — Round N draft/revise
  Side B — Round N critique
Tool activity shows as compact [⌛ tool: …] markers.

Usage:
  python3 tools/pi-arch-debate.py "<intent description>"
  python3 tools/pi-arch-debate.py --first A|B "<intent>"   # chair picks the drafter side
  python3 tools/pi-arch-debate.py --preflight "<short intent>"  # firm intent first (flash)
  python3 tools/pi-arch-debate.py --fast "<intent>"        # single-round triage
  python3 tools/pi-arch-debate.py --approve                  # chair-approve last saved draft (no API)
  python3 tools/pi-arch-debate.py --quiet "<intent>"        # no live print

Default opener is AUTO (intent-based chair decision):
  - Side A opens when intent signals precision/security/correctness/gap-hunting.
  - Side B opens when intent signals broad building/exploration.
  The decision + reason is printed; override with --first.

Env:
  DEBATE_MAX_ROUNDS     max draft/critique cycles (default 3)
  PI_MODEL_TIMEOUT      per-model timeout in seconds (default 600)
  DEBATE_AUTO_APPROVE=1 auto-approve the last draft (scripted use)
  DEBATE_MODEL_A        provider-qualified model for Side A
  DEBATE_MODEL_B        provider-qualified model for Side B (must differ from A; enforced)
  DEBATE_CODEX_MODEL    legacy alias for DEBATE_MODEL_A
  DEBATE_DEEPSEEK_MODEL legacy alias for DEBATE_MODEL_B
  DEBATE_FLASH_MODEL    provider-qualified preflight model
  DEBATE_WORK_DIR       artifact directory override (tests/isolated runs)
  DEBATE_TASK_FILE      approved task destination override

Artifacts:
  .ai/debate/round-N-draft.jsonl|txt
  .ai/debate/round-N-critique.jsonl|txt
  .ai/current-task.md            (replaced atomically on approval only)
"""
import json
import os
import re
import shutil
import subprocess
import sys
import tempfile
import threading
from difflib import SequenceMatcher

QUIET = False

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
os.chdir(ROOT)

def rooted_path(value: str) -> str:
    return value if os.path.isabs(value) else os.path.join(ROOT, value)


WORK = rooted_path(os.environ.get("DEBATE_WORK_DIR", ".ai/debate"))
TASK_FILE = rooted_path(os.environ.get("DEBATE_TASK_FILE", ".ai/current-task.md"))
os.makedirs(WORK, exist_ok=True)
MAX_ROUNDS = int(os.environ.get("DEBATE_MAX_ROUNDS", "3"))

DS_FLASH = os.environ.get("DEBATE_FLASH_MODEL", "deepseek/deepseek-v4-flash")
# Two INDEPENDENT, DISTINCT LLM models — one per debate side. The runner fails
# closed if they resolve to the same model, so both sides can never be played by
# the same LLM. Override with DEBATE_MODEL_A/B (legacy per-provider vars still
# work as aliases).
MODEL_A = os.environ.get("DEBATE_MODEL_A") or os.environ.get("DEBATE_CODEX_MODEL") or "openai-codex/gpt-5.6-sol"
MODEL_B = os.environ.get("DEBATE_MODEL_B") or os.environ.get("DEBATE_DEEPSEEK_MODEL") or "deepseek/deepseek-v4-pro"


class DebateError(RuntimeError):
    """A fail-closed debate error that must never replace the task contract."""


def atomic_write(path: str, content: str) -> None:
    """Replace a text artifact atomically without exposing a partial file."""
    directory = os.path.dirname(path) or ROOT
    os.makedirs(directory, exist_ok=True)
    fd, tmp_path = tempfile.mkstemp(prefix=".debate-", dir=directory, text=True)
    try:
        with os.fdopen(fd, "w") as fh:
            fh.write(content)
            fh.flush()
            os.fsync(fh.fileno())
        os.replace(tmp_path, path)
    except BaseException:
        try:
            os.unlink(tmp_path)
        except FileNotFoundError:
            pass
        raise


def validate_draft(draft: str) -> None:
    """Reject empty, truncated, or non-contract model output."""
    if len(draft.strip()) < 100:
        raise DebateError(f"draft is too short ({len(draft.strip())} chars)")
    lowered = draft.lower()
    required = ("task:", "objective:")
    # A contract must carry the structural markers plus a non-empty `status:` line.
    # The status VALUE is deliverable-dependent (implementation contracts end
    # READY_FOR_IMPLEMENTATION; roadmap/brief deliverables end READY_FOR_ARCHITECTURE_REVIEW
    # or READY_FOR_AUTHORING), so validation stays structural rather than enumerating values.
    # Fail-closed safety is preserved: empty/truncated/non-contract output is still rejected,
    # and the critic remains the substantive quality gate.
    status_ok = bool(re.search(r"^\s*status:\s*\S+", lowered, re.MULTILINE))
    missing = [marker for marker in required if marker not in lowered]
    if missing:
        raise DebateError("draft is missing required contract markers: " + ", ".join(missing))
    if not status_ok:
        raise DebateError("draft is missing a non-empty terminal status line")


def validate_critique(critique: str) -> None:
    if len(critique.strip()) < 20:
        raise DebateError(f"critique is too short ({len(critique.strip())} chars)")
    first_line = critique.splitlines()[0].strip().upper()
    if first_line not in ("VERDICT: APPROVED", "VERDICT: REVISIONS"):
        raise DebateError("critique does not begin with a valid VERDICT line")


def validate_model_name(model: str, setting: str) -> None:
    """Pi requires provider/model to avoid ambiguous cross-provider aliases."""
    provider, separator, name = model.partition("/")
    if separator == "" or provider.strip() == "" or name.strip() == "":
        raise DebateError(f"{setting} must be provider-qualified as provider/model; got {model!r}")


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
    diagnostics: list[str] = []
    reader_errors: list[str] = []

    pi_executable = shutil.which("pi")
    if not pi_executable:
        raise DebateError("pi executable not found on PATH")

    def banner() -> None:
        print()
        print("=" * 62)
        print(f"  {label}")
        print("=" * 62, flush=True)

    try:
        proc = subprocess.Popen(
            [pi_executable, "--model", model, "--mode", "json", "--print", prompt],
            stdout=subprocess.PIPE, stderr=subprocess.STDOUT,
        )
    except OSError as exc:
        raise DebateError(f"unable to start Pi for {tag} with model {model}: {exc}") from exc

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
                        diagnostics.append(line)
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
            reader_errors.append(str(exc))
            if not QUIET:
                print(f"\n[stream error: {exc}]", flush=True)

    if not QUIET:
        banner()
    t = threading.Thread(target=reader, daemon=True)
    t.start()
    timed_out = False
    try:
        proc.wait(timeout=timeout_s)
    except subprocess.TimeoutExpired:
        timed_out = True
        proc.kill()
        proc.wait()
        if not QUIET:
            print(f"\n[⚠ {tag} timed out after {timeout_s}s]")
    t.join(timeout=5)
    text = "".join(parts).strip()
    atomic_write(os.path.join(WORK, f"round-{round_no}-{tag}.txt"), text)

    if timed_out:
        raise DebateError(f"{tag} timed out after {timeout_s}s; partial output was not accepted")
    if t.is_alive():
        raise DebateError(f"{tag} output reader did not terminate cleanly")
    if reader_errors:
        raise DebateError(f"{tag} output reader failed: {reader_errors[-1]}")
    if proc.returncode != 0:
        detail = diagnostics[-1] if diagnostics else "no diagnostic output"
        raise DebateError(f"{tag} model {model} exited {proc.returncode}: {detail}")
    if not text:
        detail = diagnostics[-1] if diagnostics else "no diagnostic output"
        raise DebateError(f"{tag} model {model} returned no text: {detail}")
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
    with open(src) as fh:
        draft = fh.read()
    validate_draft(draft)
    atomic_write(TASK_FILE, draft.rstrip() + "\n")
    # Write the same uniform verdict as natural approval so the debate verify
    # (checks approved.txt == 'APPROVED') passes; chair provenance is preserved in
    # the printed/reported outcome ("APPROVED (chair)").
    atomic_write(os.path.join(WORK, "approved.txt"), "approved\n")
    print(f"APPROVED (chair): wrote {TASK_FILE} from {src} ({len(draft)} chars)")


def run_preflight(intent: str) -> str:
    tmpl = load_template("preflight.txt").replace("{{INTENT}}", intent)
    out = run_pi(DS_FLASH, tmpl, "preflight", 0, "🧭 Intent pre-flight (DeepSeek Flash)")
    if len(out.strip()) < 40:
        raise DebateError(f"preflight output is too short ({len(out.strip())} chars)")
    atomic_write(os.path.join(WORK, "preflight.txt"), out)
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

    validate_model_name(MODEL_A, "DEBATE_MODEL_A")
    validate_model_name(MODEL_B, "DEBATE_MODEL_B")
    # Fail-closed: a debate must always be argued by TWO DIFFERENT LLM models.
    if MODEL_A.strip().lower() == MODEL_B.strip().lower():
        raise DebateError(
            f"debate requires TWO DIFFERENT LLM models; both sides resolved to {MODEL_A!r}. "
            "Set DEBATE_MODEL_A and DEBATE_MODEL_B to distinct provider-qualified models."
        )
    if PREFLIGHT or len(intent) < 120:
        validate_model_name(DS_FLASH, "DEBATE_FLASH_MODEL")

    atomic_write(os.path.join(WORK, "approved.txt"), "pending\n")

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
        print("    (unknown --first value; defaulting to side B)")
        first = "deepseek"
    DRAFTER, CRITIC = (MODEL_A, MODEL_B) if first == "codex" else (MODEL_B, MODEL_A)
    draft_label = f"{'🔍' if first == 'codex' else '🧠'} {MODEL_A if first == 'codex' else MODEL_B}"
    critic_label = f"{'🧠' if first == 'codex' else '🔍'} {MODEL_B if first == 'codex' else MODEL_A}"

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
        validate_draft(draft)
        print(f"\n    → draft len={len(draft)}")

        # Convergence check: if the draft stopped changing, stop to save cost.
        if prev_draft and SequenceMatcher(None, prev_draft, draft).ratio() > 0.9:
            print(f"    → converged (draft ~unchanged); stopping early to save cost")
            converged = True
            break

        cp = load_template("critique.txt")
        cp = cp.replace("{{INTENT}}", intent)
        cp = cp.replace("{{DRAFT}}", draft)
        critique = run_pi(CRITIC, cp, "critique", r, f"{critic_label} — Round {r} critique")
        validate_critique(critique)
        verdict = verdict_of(critique)
        print(f"    → verdict={verdict}  critique len={len(critique)}")
        if verdict == "APPROVED":
            break
        prev_draft = draft

    if verdict != "APPROVED" and AUTO:
        verdict = "APPROVED"

    if verdict == "APPROVED":
        validate_draft(draft)
        atomic_write(TASK_FILE, draft.rstrip() + "\n")
        atomic_write(os.path.join(WORK, "approved.txt"), "approved\n")
    else:
        atomic_write(os.path.join(WORK, "approved.txt"), "revisions\n")

    print("=== DONE ===")
    print(f"verdict: {verdict} after {rounds_used} round(s)")
    print(f"artifacts: {WORK}/")
    if verdict == "APPROVED":
        print(f"wrote: {TASK_FILE} ({len(draft)} chars)")
    if verdict != "APPROVED":
        print(f"preserved: {TASK_FILE}")
        print("NOTE: not APPROVED. Options:")
        print("  arch-debate --approve                      # accept the last draft (chair-approved)")
        print("  DEBATE_MAX_ROUNDS=5 arch-debate …          # more rounds")
        print("  DEBATE_AUTO_APPROVE=1 arch-debate …        # auto-approve the last draft")
        sys.exit(2)


if __name__ == "__main__":
    try:
        main()
    except DebateError as exc:
        try:
            atomic_write(os.path.join(WORK, "approved.txt"), "failed\n")
        except OSError:
            pass
        print(f"DEBATE_FAILED: {exc}", file=sys.stderr)
        sys.exit(2)
