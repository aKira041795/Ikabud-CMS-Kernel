#!/usr/bin/env python3
"""On-demand, repository-grounded consultation through the existing ChatGPT page adapter."""
from __future__ import annotations

import argparse
import json
import os
import re
import shutil
import subprocess
import sys
from pathlib import Path

import context_pack

_CITATION = re.compile(r"[\w./-]+\.(?:md|json|php|py|js|sh|sql|disyl|yml|yaml):\d+")
# A carried-over turn keeps the owner's words and the reply; re-feeding the previous pack
# would spend the next prompt's budget on facts the model already reasoned over.
_FACTS_BLOCK = re.compile(
    r"# RETRIEVED REPOSITORY FACTS.*?(?=\n# CHATGPT REPLY|\n## IDEA / INTENT|\Z)", re.S)


def _history_from(text: str, limit: int = 6000) -> str:
    """Prior turns of a discussion, without their retrieved-facts blocks."""
    return _FACTS_BLOCK.sub("", text or "")[-limit:].strip()


def _parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--query", required=True, help="idea or intent to discuss")
    parser.add_argument("--instruction", default="", help="additional direction for ChatGPT")
    parser.add_argument("--workspace", default=".", help="git workspace (default: current directory)")
    parser.add_argument("--budget", type=int, default=6000, help="maximum context-pack characters")
    parser.add_argument("--dry-run", action="store_true", help="compose only; do not open a browser")
    parser.add_argument("--json", action="store_true", dest="as_json", help="print one JSON result line")
    parser.add_argument("--out", help="file to save; defaults under <workspace>/.ai/consult/")
    parser.add_argument("--session", help="continue a saved discussion (the transcript is read "
                                           "back in and the reply appended, so a discussion "
                                           "carries on instead of restarting)")
    return parser


def _default_out(workspace: Path, query: str) -> Path:
    slug = re.sub(r"[^a-z0-9]+", "-", query.lower()).strip("-")[:64] or "consult"
    return workspace / ".ai" / "consult" / f"{slug}.md"


def _compose(query: str, instruction: str, pack: str, history: str = "") -> str:
    so_far = f"## SO FAR (this discussion)\n{history.strip()}\n\n" if history.strip() else ""
    return (
        "# CHAIR CONSULTATION\n\n"
        "You are ChatGPT acting as a read-only architecture advisor. Discuss the intent using "
        "the retrieved repository facts. Cite path:line evidence for repository claims. If the "
        "facts do not answer a question, say exactly what evidence is missing; do not invent it. "
        "When the discussion continues below, respond to the latest point rather than restating "
        "earlier ones.\n\n"
        + so_far
        + f"## IDEA / INTENT\n{query.strip()}\n\n"
        + f"## INSTRUCTION\n{instruction.strip() or '- Give a concrete, critical recommendation.'}\n\n"
        + f"# RETRIEVED REPOSITORY FACTS\n{pack or '- none retrieved'}\n"
    )


def _emit(args, payload: dict, error: str = "") -> None:
    if args.as_json:
        # The reason must survive JSON mode: without it a caller sees ok:false and an empty
        # reply, with no way to tell a login failure from a rate limit.
        if error:
            payload = {**payload, "error": error}
        print(json.dumps(payload, ensure_ascii=False, separators=(",", ":")))
    elif error:
        print(f"chair_consult: {error}", file=sys.stderr)
    else:
        print(str(payload.get("reply") or payload.get("prompt") or ""))


def main(argv=None) -> int:
    args = _parser().parse_args(argv)
    prompt = ""
    reply = ""
    try:
        workspace = Path(args.workspace).resolve()
        session_path = None
        if args.session:
            session_path = (Path(args.out).expanduser() if args.out
                            else _default_out(workspace, str(args.session)))
        history = ""
        if session_path and session_path.is_file():
            history = _history_from(session_path.read_text(encoding="utf-8", errors="replace"))
        out_path = session_path or (Path(args.out).expanduser() if args.out
                                    else _default_out(workspace, args.query))
        # Never retrieve the transcript being written.  It holds this very prompt and, on a
        # continued session, the previous reply; feeding it back as "conclusions" crowds out
        # the repository and lets the model cite the discussion instead of the code.
        # The adapter is handed a FILE to read as its prompt. For a session that file used to be the
        # transcript itself - which is appended to every turn, so the prompt grew without bound and
        # eventually could not be typed into the composer at all. Measured 2026-10-10: turn 1 ~10 KB
        # and turn 2 ~25 KB fine, turn 3 ~35 KB hit a Playwright fill timeout, and the ~80 KB
        # transcript then exceeded the 340 s adapter budget. Prior turns are already carried by
        # _history_from (capped), so the adapter only needs the CURRENT turn.
        adapter_prompt_path = out_path
        if session_path:
            adapter_prompt_path = out_path.with_name(out_path.stem + ".turn" + out_path.suffix)
        excluded: list[str] = []
        for candidate in (out_path, adapter_prompt_path):
            try:
                excluded.append(candidate.resolve().relative_to(workspace).as_posix())
            except ValueError:
                pass
        pack = context_pack.build_context_pack(args.query, str(workspace), args.budget,
                                               exclude_paths=excluded)
        prompt = _compose(str(args.query), str(args.instruction), pack, history)
        citations = len(_CITATION.findall(pack))
        out_path.parent.mkdir(parents=True, exist_ok=True)
        if session_path:
            # A discussion accumulates turns; a one-shot consult is just inspectable.
            with out_path.open("a", encoding="utf-8") as handle:
                handle.write(prompt + "\n\n")
            # ...but the adapter gets only this turn, so the prompt stays bounded.
            adapter_prompt_path.write_text(prompt, encoding="utf-8")
        else:
            out_path.write_text(prompt, encoding="utf-8")

        if args.dry_run:
            payload = {"ok": True, "prompt": prompt, "citations": citations, "reply": ""}
            _emit(args, payload)
            return 0

        script = Path(__file__).resolve().parent / "chatgpt_page.js"
        node = shutil.which("node") or str(Path.home() / ".local/node-v22.23.2-linux-x64/bin/node")
        # This is the browser profile holding the ChatGPT SUBSCRIPTION login (cookies) - not an API
        # key, and no key is ever written here. Overridable so an existing profile can be reused
        # without a fresh login:  CHAIR_CONSULT_PROFILE=/path/to/profile
        # The ChatGPT SUBSCRIPTION login lives in this browser profile (cookies; no API key is
        # ever written here).  Measured 2026-10-10: ~/.config/chair-consult/chatgpt-profile is
        # logged OUT while the harness profile is logged in, so default to the harness profile -
        # the same path harpp_wake.py uses as ADVISOR_DEFAULT_PROFILE - and keep the override.
        profile = Path(
            os.environ.get("CHAIR_CONSULT_PROFILE")
            or Path(os.environ.get("XDG_CONFIG_HOME", str(Path.home() / ".config")))
            / "harpp" / "chatgpt-profile"
        )
        proc = subprocess.run(
            [node, str(script), "run", "--prompt", str(adapter_prompt_path), "--profile", str(profile)],
            capture_output=True, text=True, timeout=340, check=False,
        )
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
            payload = {"ok": False, "prompt": prompt, "citations": citations, "reply": ""}
            _emit(args, payload, error)
            return 1
        reply = str(result.get("text") or "")
        if session_path:
            with out_path.open("a", encoding="utf-8") as handle:
                handle.write("# CHATGPT REPLY\n" + reply + "\n\n")
        else:
            out_path.write_text(prompt + "\n\n# CHATGPT REPLY\n" + reply + "\n", encoding="utf-8")
        payload = {"ok": True, "prompt": prompt, "citations": citations, "reply": reply}
        _emit(args, payload)
        return 0
    except Exception as exc:  # command-line boundary: never expose a traceback
        payload = {"ok": False, "prompt": prompt, "citations": len(_CITATION.findall(prompt)),
                   "reply": reply}
        _emit(args, payload, str(exc))
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
