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


def _parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--query", required=True, help="idea or intent to discuss")
    parser.add_argument("--instruction", default="", help="additional direction for ChatGPT")
    parser.add_argument("--workspace", default=".", help="git workspace (default: current directory)")
    parser.add_argument("--budget", type=int, default=6000, help="maximum context-pack characters")
    parser.add_argument("--dry-run", action="store_true", help="compose only; do not open a browser")
    parser.add_argument("--json", action="store_true", dest="as_json", help="print one JSON result line")
    parser.add_argument("--out", help="file to save; defaults under <workspace>/.ai/consult/")
    return parser


def _default_out(workspace: Path, query: str) -> Path:
    slug = re.sub(r"[^a-z0-9]+", "-", query.lower()).strip("-")[:64] or "consult"
    return workspace / ".ai" / "consult" / f"{slug}.md"


def _compose(query: str, instruction: str, pack: str) -> str:
    return (
        "# CHAIR CONSULTATION\n\n"
        "You are ChatGPT acting as a read-only architecture advisor. Discuss the intent using "
        "the retrieved repository facts. Cite path:line evidence for repository claims. If the "
        "facts do not answer a question, say exactly what evidence is missing; do not invent it.\n\n"
        f"## IDEA / INTENT\n{query.strip()}\n\n"
        f"## INSTRUCTION\n{instruction.strip() or '- Give a concrete, critical recommendation.'}\n\n"
        f"# RETRIEVED REPOSITORY FACTS\n{pack or '- none retrieved'}\n"
    )


def _emit(args, payload: dict, error: str = "") -> None:
    if args.as_json:
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
        pack = context_pack.build_context_pack(args.query, str(workspace), args.budget)
        prompt = _compose(str(args.query), str(args.instruction), pack)
        citations = len(_CITATION.findall(pack))
        out_path = Path(args.out).expanduser() if args.out else _default_out(workspace, args.query)
        out_path.parent.mkdir(parents=True, exist_ok=True)
        out_path.write_text(prompt, encoding="utf-8")

        if args.dry_run:
            payload = {"ok": True, "prompt": prompt, "citations": citations, "reply": ""}
            _emit(args, payload)
            return 0

        script = Path(__file__).resolve().parent / "chatgpt_page.js"
        node = shutil.which("node") or str(Path.home() / ".local/node-v22.23.2-linux-x64/bin/node")
        profile = Path(os.environ.get("XDG_CONFIG_HOME", str(Path.home() / ".config"))) / "harpp/chatgpt-profile"
        proc = subprocess.run(
            [node, str(script), "run", "--prompt", str(out_path), "--profile", str(profile)],
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
