#!/usr/bin/env python3
"""Provider-neutral, repository-grounded consultation for HARPP.

Two backends:

  chatgpt  the existing subscription page adapter (tools/harpp-bridge/chatgpt_page.js)
  claude   the Anthropic API, a Claude CLI command, or the claude.ai page adapter
           (the page transport uses a logged-in browser profile and is subject to
           the account's message cap; free accounts hit it faster)

Two modes:

  chair     discuss an intent and recommend (the existing chair role)
  red-team  attack a recommendation and list its assumptions and failure modes

A comparison runs both providers with the same grounded prompt and writes the
replies side by side.  An opinion is ADVICE, not evidence: repository claims
must still be verified before they are acted on.
"""
from __future__ import annotations

import argparse
import json
import os
import re
import shlex
import shutil
import subprocess
import sys
import urllib.error
import urllib.request
from pathlib import Path

import context_pack

PROVIDERS = ("chatgpt", "claude")
_FACTS_BLOCK = re.compile(r"# RETRIEVED REPOSITORY FACTS.*?(?=\n# |\Z)", re.S)
_CITATION = re.compile(r"[\w./-]+\.(?:md|json|php|py|js|sh|sql|disyl|yml|yaml):\d+")
_REPLY_HEADING = re.compile(r"^# (?:CHATGPT|CLAUDE) REPLY\s*$", re.M)
_NEXT_PROMPT = re.compile(r"^# (?:CHAIR CONSULTATION|RED TEAM REVIEW)\s*$", re.M)
_TURN_START = re.compile(r"^# (?:CHAIR CONSULTATION|RED TEAM REVIEW)\s*$", re.M)
_DEFAULT_CLAUDE_CMD = "claude -p"
_ANTHROPIC_URL = "https://api.anthropic.com/v1/messages"


def _parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--query", required=True, help="idea, question, or recommendation to discuss")
    parser.add_argument("--instruction", default="", help="additional direction for the model")
    parser.add_argument("--provider", choices=PROVIDERS, default="chatgpt",
                        help="provider for a single consultation (default: chatgpt)")
    parser.add_argument("--compare", action="store_true",
                        help="run both providers with the same grounded prompt and write a comparison")
    parser.add_argument("--mode", choices=("chair", "red-team"), default="chair",
                        help="chair = recommend; red-team = attack the recommendation")
    parser.add_argument("--target", default="", help="recommendation under review (red-team mode)")
    parser.add_argument("--target-file", help="file containing the recommendation under review")
    parser.add_argument("--session", help="continue a saved discussion instead of starting a new one")
    parser.add_argument("--workspace", default=".", help="git workspace (default: current directory)")
    parser.add_argument("--budget", type=int, default=6000, help="maximum context-pack characters")
    parser.add_argument("--max-tokens", type=int, default=4096, help="Claude API reply token cap")
    parser.add_argument("--timeout", type=int, default=340, help="provider timeout in seconds")
    parser.add_argument("--dry-run", action="store_true", help="compose and save prompts; call no provider")
    parser.add_argument("--json", action="store_true", dest="as_json", help="print one JSON result line")
    parser.add_argument("--out", help="file to save (single) or comparison file (with --compare)")
    parser.add_argument("--claude-backend", choices=("auto", "api", "cli", "page"), default=os.environ.get("CONSULT_CLAUDE_BACKEND", "auto"),
                        help="Claude transport (default: auto: API key, then CLI, then claude.ai page)")
    parser.add_argument("--claude-model", default=os.environ.get("CONSULT_CLAUDE_MODEL", ""),
                        help="Claude model id (API requires it; CLI passes it when set)")
    parser.add_argument("--claude-command", default=os.environ.get("CONSULT_CLAUDE_CMD", _DEFAULT_CLAUDE_CMD),
                        help="Claude CLI command; the prompt is written to stdin")
    parser.add_argument("--claude-profile", default=os.environ.get("CLAUDE_CONSULT_PROFILE", ""),
                        help="claude.ai browser profile for --claude-backend page")
    return parser


def _slug(text: str, limit: int = 64) -> str:
    value = re.sub(r"[^a-z0-9]+", "-", str(text or "").lower()).strip("-")
    return value[:limit] or "consult"


def _default_out(workspace: Path, provider: str, session: str | None, query: str) -> Path:
    name = _slug(session or query)
    return workspace / ".ai" / "consult" / f"{provider}-{name}.md"


def _section(text: str, heading: str) -> str:
    match = re.search(rf"(?ms)^{re.escape(heading)}\s*\n(.*?)(?=^## |^# |\Z)", text)
    return match.group(1).strip() if match else ""


def _history_from(text: str, limit: int = 6000) -> str:
    """Prior turns as clean IDEA / INSTRUCTION / REPLY blocks.

    Reading the raw transcript back in would nest each previous prompt (and its own
    ``## SO FAR`` block) inside the next one, so a long discussion grows quadratically
    and the model spends its budget re-reading scaffolding. Extract only the owner's
    intent and the provider's reply.
    """
    clean = _FACTS_BLOCK.sub("", text or "")
    starts = [match.start() for match in _TURN_START.finditer(clean)]
    if not starts:
        return clean[-limit:].strip()
    turns: list[str] = []
    for index, start in enumerate(starts):
        end = starts[index + 1] if index + 1 < len(starts) else len(clean)
        chunk = clean[start:end]
        idea = _section(chunk, "## IDEA / INTENT")
        instruction = _section(chunk, "## INSTRUCTION")
        reply = _last_reply(chunk)
        if reply:
            turns.append(
                f"### TURN {index + 1}\n"
                f"IDEA: {idea}\n"
                f"INSTRUCTION: {instruction}\n"
                f"REPLY:\n{reply}"
            )
    return "\n\n".join(turns)[-limit:].strip()


def _last_reply(text: str) -> str:
    """The last provider reply in a transcript, or '' when there is none."""
    matches = list(_REPLY_HEADING.finditer(text or ""))
    if not matches:
        return ""
    start = matches[-1].end()
    rest = text[start:]
    next_prompt = _NEXT_PROMPT.search(rest)
    return (rest[: next_prompt.start()] if next_prompt else rest).strip()


def _resolve_target(args: argparse.Namespace, history: str) -> tuple[str, str]:
    target = str(args.target or "").strip()
    if args.target_file:
        try:
            content = Path(args.target_file).expanduser().read_text(encoding="utf-8", errors="replace")
        except OSError as exc:
            return "", f"cannot read --target-file: {exc}"
        # A saved consultation is a transcript; the recommendation is its last reply.
        # A plan or note has no reply marker, so use the file as-is.
        target = _last_reply(content) or content.strip()
    if not target:
        target = _last_reply(history)
    if not target:
        return "", "red-team mode needs --target, --target-file, or a session with a prior reply"
    return target, ""


def _provider_name(provider: str) -> str:
    return "ChatGPT" if provider == "chatgpt" else "Claude"


def _compose(provider: str, mode: str, query: str, instruction: str, pack: str,
             history: str, target: str) -> str:
    name = _provider_name(provider)
    if mode == "red-team":
        intro = (
            "# RED TEAM REVIEW\n\n"
            f"You are {name} acting as a read-only red team. Your job is to FALSIFY the "
            "recommendation under review, not to produce a parallel recommendation. "
            "List (1) the assumptions it depends on, (2) failure modes and who is affected, "
            "(3) alternatives that satisfy the same objective, and (4) the evidence that "
            "would change the decision. Cite path:line for repository claims. If the facts "
            "do not answer a question, say exactly what evidence is missing; do not invent it.\n\n"
            f"## RECOMMENDATION UNDER REVIEW\n{target.strip()}\n\n"
        )
    else:
        intro = (
            "# CHAIR CONSULTATION\n\n"
            f"You are {name} acting as a read-only architecture advisor. Discuss the intent "
            "using the retrieved repository facts. Cite path:line evidence for repository "
            "claims. If the facts do not answer a question, say exactly what evidence is "
            "missing; do not invent it. When the discussion continues below, respond to the "
            "latest point rather than restating earlier ones.\n\n"
        )
    so_far = f"## SO FAR (this discussion)\n{history.strip()}\n\n" if history.strip() else ""
    return (
        intro
        + so_far
        + f"## IDEA / INTENT\n{query.strip()}\n\n"
        + f"## INSTRUCTION\n{instruction.strip() or '- Give a concrete, critical recommendation.'}\n\n"
        + f"# RETRIEVED REPOSITORY FACTS\n{pack or '- none retrieved'}\n"
    )


def _exclude(workspace: Path, paths: list[Path]) -> list[str]:
    result: list[str] = []
    for path in paths:
        try:
            result.append(path.resolve().relative_to(workspace).as_posix())
        except ValueError:
            continue
    return result


def _write_transcript(path: Path, prompt: str, provider: str, reply: str | None,
                      append: bool) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    if append:
        with path.open("a", encoding="utf-8") as handle:
            handle.write(prompt + "\n\n")
            if reply is not None:
                handle.write(f"# {provider.upper()} REPLY\n{reply}\n\n")
    else:
        body = prompt + "\n\n"
        if reply is not None:
            body += f"# {provider.upper()} REPLY\n{reply}\n"
        path.write_text(body, encoding="utf-8")


def _chatgpt(prompt_path: Path, timeout: int) -> tuple[bool, str, str]:
    script = Path(os.environ.get(
        "CONSULT_CHATGPT_ADAPTER",
        str(Path(__file__).resolve().parent / "chatgpt_page.js"),
    ))
    node = shutil.which("node") or str(Path.home() / ".local/node-v22.23.2-linux-x64/bin/node")
    profile = Path(
        os.environ.get("CHAIR_CONSULT_PROFILE")
        or Path(os.environ.get("XDG_CONFIG_HOME", str(Path.home() / ".config")))
        / "harpp" / "chatgpt-profile"
    )
    if not script.is_file():
        return False, "", f"ChatGPT adapter missing: {script}"
    if not Path(node).is_file() and shutil.which("node") is None:
        return False, "", "node is not installed; ChatGPT page adapter cannot run"
    try:
        proc = subprocess.run(
            [node, str(script), "run", "--prompt", str(prompt_path), "--profile", str(profile)],
            capture_output=True, text=True, timeout=timeout, check=False,
        )
    except subprocess.TimeoutExpired:
        return False, "", f"ChatGPT adapter timed out after {timeout}s"
    except OSError as exc:
        return False, "", f"ChatGPT adapter failed to start: {exc}"
    result = None
    for line in proc.stdout.splitlines():
        try:
            candidate = json.loads(line)
        except (TypeError, ValueError):
            continue
        if isinstance(candidate, dict):
            result = candidate
    if proc.returncode != 0 or not result or not result.get("ok"):
        error = str((result or {}).get("error") or proc.stderr.strip() or "ChatGPT page adapter failed")
        return False, "", error
    return True, str(result.get("text") or "").strip(), ""


def _claude_api(prompt: str, model: str, max_tokens: int, timeout: int) -> tuple[bool, str, str]:
    key = os.environ.get("ANTHROPIC_API_KEY", "").strip()
    if not key:
        return False, "", "ANTHROPIC_API_KEY is not set"
    if not model:
        return False, "", "set --claude-model or CONSULT_CLAUDE_MODEL for the Anthropic API"
    body = json.dumps({
        "model": model,
        "max_tokens": max(1, int(max_tokens)),
        "messages": [{"role": "user", "content": prompt}],
    }).encode("utf-8")
    request = urllib.request.Request(
        os.environ.get("CONSULT_ANTHROPIC_URL", _ANTHROPIC_URL),
        data=body,
        method="POST",
        headers={
            "content-type": "application/json",
            "x-api-key": key,
            "anthropic-version": "2023-06-01",
        },
    )
    try:
        with urllib.request.urlopen(request, timeout=timeout) as response:
            payload = json.loads(response.read().decode("utf-8", errors="replace"))
    except urllib.error.HTTPError as exc:
        detail = exc.read().decode("utf-8", errors="replace")[:400]
        return False, "", f"Anthropic API HTTP {exc.code}: {detail}".strip()
    except Exception as exc:  # network/JSON boundary: never expose a traceback
        return False, "", f"Anthropic API failed: {exc}"
    blocks = payload.get("content") if isinstance(payload, dict) else None
    text = "\n".join(
        str(block.get("text") or "")
        for block in (blocks or [])
        if isinstance(block, dict) and block.get("type") == "text"
    ).strip()
    if not text:
        return False, "", "Anthropic API returned no text content"
    return True, text, ""


def _claude_cli(prompt: str, command: str, model: str, timeout: int) -> tuple[bool, str, str]:
    try:
        parts = shlex.split(command)
    except ValueError as exc:
        return False, "", f"invalid --claude-command: {exc}"
    if not parts:
        return False, "", "--claude-command is empty"
    if shutil.which(parts[0]) is None:
        return False, "", f"Claude CLI not found: {parts[0]}"
    if model and command.strip() == _DEFAULT_CLAUDE_CMD:
        parts += ["--model", model]
    try:
        proc = subprocess.run(
            parts, input=prompt, capture_output=True, text=True,
            timeout=timeout, check=False,
        )
    except subprocess.TimeoutExpired:
        return False, "", f"Claude CLI timed out after {timeout}s"
    except OSError as exc:
        return False, "", f"Claude CLI failed to start: {exc}"
    if proc.returncode != 0:
        detail = (proc.stderr or proc.stdout or "").strip()[:400]
        return False, "", f"Claude CLI exited {proc.returncode}: {detail}".strip()
    reply = (proc.stdout or "").strip()
    if not reply:
        return False, "", "Claude CLI returned no output"
    return True, reply, ""


def _claude_page(prompt_path: Path, timeout: int, profile: str) -> tuple[bool, str, str]:
    adapter = Path(os.environ.get(
        "CONSULT_CLAUDE_PAGE_ADAPTER",
        str(Path(__file__).resolve().parent / "claude_page.js"),
    ))
    node = shutil.which("node") or str(Path.home() / ".local/node-v22.23.2-linux-x64/bin/node")
    profile_path = Path(profile).expanduser() if profile else Path(
        os.environ.get("XDG_CONFIG_HOME", str(Path.home() / ".config"))
    ) / "harpp" / "claude-profile"
    if not adapter.is_file():
        return False, "", f"Claude page adapter missing: {adapter}"
    if shutil.which("node") is None and not Path(node).is_file():
        return False, "", "node is not installed; the claude.ai page adapter cannot run"
    try:
        proc = subprocess.run(
            [node, str(adapter), "run", "--prompt", str(prompt_path),
             "--profile", str(profile_path), "--timeout", str(timeout)],
            capture_output=True, text=True, timeout=timeout + 30, check=False,
        )
    except subprocess.TimeoutExpired:
        return False, "", f"claude.ai page adapter timed out after {timeout}s"
    except OSError as exc:
        return False, "", f"claude.ai page adapter failed to start: {exc}"
    result = None
    for line in proc.stdout.splitlines():
        try:
            candidate = json.loads(line)
        except (TypeError, ValueError):
            continue
        if isinstance(candidate, dict):
            result = candidate
    if proc.returncode != 0 or not result or not result.get("ok"):
        error = str((result or {}).get("error") or proc.stderr.strip() or "claude.ai page adapter failed")
        if result and result.get("limit"):
            error = "Claude usage limit reached (free account): " + error
        return False, "", error
    return True, str(result.get("text") or "").strip(), ""


def _run_claude(prompt: str, turn_path: Path, args: argparse.Namespace) -> tuple[bool, str, str]:
    backend = args.claude_backend
    if backend == "auto":
        first = "claude"
        try:
            first = shlex.split(args.claude_command)[0]
        except (ValueError, IndexError):
            pass
        adapter = Path(os.environ.get(
            "CONSULT_CLAUDE_PAGE_ADAPTER",
            str(Path(__file__).resolve().parent / "claude_page.js"),
        ))
        if os.environ.get("ANTHROPIC_API_KEY", "").strip() and args.claude_model:
            backend = "api"
        elif shutil.which(first):
            backend = "cli"
        elif adapter.is_file() and shutil.which("node"):
            backend = "page"
        else:
            backend = ""
    if backend == "api":
        return _claude_api(prompt, args.claude_model, args.max_tokens, args.timeout)
    if backend == "cli":
        return _claude_cli(prompt, args.claude_command, args.claude_model, args.timeout)
    if backend == "page":
        return _claude_page(turn_path, args.timeout, args.claude_profile)
    return False, "", (
        "no Claude backend available: set ANTHROPIC_API_KEY + CONSULT_CLAUDE_MODEL, "
        "install the Claude CLI (CONSULT_CLAUDE_CMD), or log in to claude.ai "
        "(node tools/harpp-bridge/claude_page.js login)"
    )


def _emit(args: argparse.Namespace, payload: dict, error: str = "") -> None:
    if args.as_json:
        if error:
            payload = {**payload, "error": error}
        print(json.dumps(payload, ensure_ascii=False, separators=(",", ":")))
    elif error:
        print(f"consult: {error}", file=sys.stderr)
    else:
        print(str(payload.get("reply") or payload.get("prompt") or ""))


def _single_paths(args: argparse.Namespace, workspace: Path) -> tuple[Path, Path]:
    out_path = Path(args.out).expanduser() if args.out else _default_out(
        workspace, args.provider, args.session, args.query)
    turn_path = out_path.with_name(out_path.stem + ".turn" + out_path.suffix)
    return out_path, turn_path


def _compare_base(args: argparse.Namespace, workspace: Path) -> Path:
    return Path(args.out).expanduser() if args.out else (
        workspace / ".ai" / "consult" / f"compare-{_slug(args.session or args.query)}.md"
    )


def _compare_paths(args: argparse.Namespace, workspace: Path) -> tuple[Path, list[tuple[str, Path, Path]]]:
    base = _compare_base(args, workspace)
    items: list[tuple[str, Path, Path]] = []
    for provider in PROVIDERS:
        out_path = base.with_name(base.stem + f".{provider}" + base.suffix)
        turn_path = out_path.with_name(out_path.stem + ".turn" + out_path.suffix)
        items.append((provider, out_path, turn_path))
    return base, items


def _write_error(path: Path, prompt: str, provider: str, error: str, append: bool) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    block = prompt + f"\n\n# {provider.upper()} ERROR\n{error}\n\n"
    if append:
        with path.open("a", encoding="utf-8") as handle:
            handle.write(block)
    else:
        path.write_text(block, encoding="utf-8")


def _single(args: argparse.Namespace, workspace: Path, pack: str, history: str, target: str) -> int:
    provider = args.provider
    prompt = _compose(provider, args.mode, args.query, args.instruction, pack, history, target)
    out_path, turn_path = _single_paths(args, workspace)
    out_path.parent.mkdir(parents=True, exist_ok=True)
    turn_path.write_text(prompt, encoding="utf-8")
    append = bool(args.session)
    payload = {
        "ok": True, "provider": provider, "mode": args.mode, "prompt": prompt,
        "reply": "", "citations": len(_CITATION.findall(pack)),
        "out": str(out_path), "turn": str(turn_path),
    }
    if args.dry_run:
        # The turn file is the saved prompt. Do NOT append a prompt-only turn to a
        # session transcript: the next turn would carry an unanswered question.
        _emit(args, payload)
        return 0
    if provider == "chatgpt":
        ok, reply, error = _chatgpt(turn_path, args.timeout)
    else:
        ok, reply, error = _run_claude(prompt, turn_path, args)
    if not ok:
        _write_error(out_path, prompt, provider, error, append)
        _emit(args, {**payload, "ok": False}, error)
        return 1
    _write_transcript(out_path, prompt, provider, reply, append)
    payload["reply"] = reply
    _emit(args, payload)
    return 0


def _compare(args: argparse.Namespace, workspace: Path, pack: str, history: str, target: str) -> int:
    base, planned = _compare_paths(args, workspace)
    base.parent.mkdir(parents=True, exist_ok=True)
    results: dict[str, dict] = {}
    append = bool(args.session)
    for provider, out_path, turn_path in planned:
        prompt = _compose(provider, args.mode, args.query, args.instruction, pack, history, target)
        turn_path.write_text(prompt, encoding="utf-8")
        results[provider] = {
            "ok": bool(args.dry_run), "provider": provider, "prompt": prompt, "reply": "",
            "error": "", "out": str(out_path), "turn": str(turn_path),
            "citations": len(_CITATION.findall(pack)),
        }
        if args.dry_run:
            continue
        if provider == "chatgpt":
            ok, reply, error = _chatgpt(turn_path, args.timeout)
        else:
            ok, reply, error = _run_claude(prompt, turn_path, args)
        results[provider]["ok"] = ok
        results[provider]["reply"] = reply
        results[provider]["error"] = error
        if ok:
            _write_transcript(out_path, prompt, provider, reply, append)
        else:
            _write_error(out_path, prompt, provider, error, append)

    comparison = [
        "# CONSULT COMPARISON",
        "",
        f"Mode: {args.mode}",
        "",
        "## QUESTION",
        "",
        args.query.strip(),
        "",
    ]
    if target:
        comparison += ["## TARGET", "", target.strip(), ""]
    for provider in PROVIDERS:
        result = results[provider]
        comparison += [f"## {_provider_name(provider).upper()}", ""]
        if args.dry_run:
            comparison.append("(dry run: prompt saved; no provider call)")
        else:
            comparison.append(result["reply"] if result["ok"] else f"(unavailable: {result['error']})")
        comparison.append("")
    comparison += [
        "## DETERMINISTIC SIGNALS",
        "",
        f"- citations in pack: {len(_CITATION.findall(pack))}",
    ]
    for provider in PROVIDERS:
        reply = results[provider]["reply"]
        comparison.append(f"- {provider}: {len(reply)} reply chars")
    comparison += [
        "",
        "The signals above are descriptive only. Agreement and disagreement must be read "
        "from the replies; neither provider is evidence.",
        "",
    ]
    base.write_text("\n".join(comparison), encoding="utf-8")
    ok = all(results[provider]["ok"] for provider in PROVIDERS)
    payload = {
        "ok": ok, "mode": "compare", "providers": results, "out": str(base),
        "citations": len(_CITATION.findall(pack)),
    }
    if args.as_json:
        _emit(args, payload, "" if ok else "one or more providers failed")
    elif not ok:
        for provider in PROVIDERS:
            result = results[provider]
            print(f"== {_provider_name(provider)} ==")
            print(result["reply"] if result["ok"] else f"(unavailable: {result['error']})")
            print()
        _emit(args, payload, "one or more providers failed")
    else:
        print(base.read_text(encoding="utf-8"))
        print(f"comparison: {base}")
    return 0 if ok else 1


def _history_candidates(args: argparse.Namespace, workspace: Path) -> list[Path]:
    if args.compare:
        _base, planned = _compare_paths(args, workspace)
        candidates = [out_path for _provider, out_path, _turn in planned]
        # A comparison may continue a discussion that started as a single-provider
        # session. Those files use <provider>-<session>.md.
        slug = _slug(args.session or args.query)
        candidates += [workspace / ".ai" / "consult" / f"{provider}-{slug}.md" for provider in PROVIDERS]
        return candidates
    out_path, _turn = _single_paths(args, workspace)
    return [out_path]


def _planned_files(args: argparse.Namespace, workspace: Path) -> list[Path]:
    if args.compare:
        base, planned = _compare_paths(args, workspace)
        files = [base]
        for _provider, out_path, turn_path in planned:
            files.extend((out_path, turn_path))
        return files
    out_path, turn_path = _single_paths(args, workspace)
    return [out_path, turn_path]


def main(argv: list[str] | None = None) -> int:
    args = _parser().parse_args(argv)
    try:
        workspace = Path(args.workspace).resolve()
        history = ""
        if args.session:
            for candidate in _history_candidates(args, workspace):
                if candidate.is_file():
                    history = _history_from(candidate.read_text(encoding="utf-8", errors="replace"))
                    break
        target = ""
        if args.mode == "red-team":
            target, error = _resolve_target(args, history)
            if error:
                _emit(args, {"ok": False, "provider": args.provider, "reply": ""}, error)
                return 2
        query_for_pack = f"{args.query}\n{target}" if target else args.query
        # Never retrieve the files being written; a transcript would let the model cite
        # the discussion instead of the repository. Other saved consultations remain
        # eligible as prior conclusions, as they are for the ChatGPT-only chair.
        excluded = _exclude(workspace, _planned_files(args, workspace))
        pack = context_pack.build_context_pack(query_for_pack, str(workspace), args.budget,
                                               exclude_paths=excluded)
        if args.compare:
            return _compare(args, workspace, pack, history, target)
        return _single(args, workspace, pack, history, target)
    except Exception as exc:  # command-line boundary: never expose a traceback
        _emit(args, {"ok": False, "provider": args.provider, "reply": ""}, str(exc))
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
