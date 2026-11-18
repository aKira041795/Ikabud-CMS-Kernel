#!/usr/bin/env python3
"""Read `pi --mode json` output: extract the assistant text and the usage totals.

Why this exists: the lane log must stay plain text (status_line() and classify_log()
grep it) but the provider's token and cost figures only appear in JSON mode. Rather
than choose one, lane-model.sh runs JSON mode and calls this tool to split the two:
stdout gets the assistant text for the log, `usage` gets the numbers for events.jsonl.

Measured 2026-11-17 from a one-word probe:
    "usage":{"input":174,"output":2,"cacheRead":2304,...,"totalTokens":2480,
             "cost":{"...","total":0.0000313712}}
    "responseModel":"deepseek-flash"        <- the SERVED model, not the alias asked for

That last field matters: tools/model-chain.txt warns that an alias can roll its served
snapshot without any tree change, so recording it is the only way to attribute a result
to the model that actually answered.

Subcommands
    extract <file>   assistant text to stdout (exit 1 when there is none)
    usage <file>     aggregated usage as JSON (text plus --json)
"""
from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path

USAGE_KEYS = ("input", "output", "cacheRead", "cacheWrite", "reasoning", "totalTokens")


def _events(path: Path) -> list[dict]:
    out: list[dict] = []
    try:
        lines = path.read_text(encoding="utf-8", errors="replace").splitlines()
    except OSError:
        return out
    for line in lines:
        line = line.strip()
        if not line.startswith("{"):
            continue
        try:
            value = json.loads(line)
        except ValueError:
            continue
        if isinstance(value, dict):
            out.append(value)
    return out


COMPLETE_MARKERS = ("turn_end", "agent_end", "agent_settled")


def is_complete_stream(events: list[dict]) -> bool:
    """True only when the stream ENDED on a terminal event.

    The check is on the LAST event, not on whether any completion appeared earlier: a run
    killed during a later turn still contains an earlier `turn_end`, and accepting that
    would extract a stale answer while the real failure sat in the truncated tail.
    """
    if not events:
        return False
    return events[-1].get("type") in COMPLETE_MARKERS


def assistant_text(events: list[dict]) -> str:
    """The final assistant message content, as the lane would have printed it."""
    if not is_complete_stream(events):
        return ""
    for event in reversed(events):
        message = event.get("message")
        if isinstance(message, dict) and message.get("role") == "assistant":
            text = _content_text(message.get("content"))
            if text:
                return text
    # A stream that completed without a final message object: use the deltas.
    chunks: list[str] = []
    for event in events:
        delta = (event.get("assistantMessageEvent") or {}).get("delta")
        if isinstance(delta, str):
            chunks.append(delta)
    return "".join(chunks)


def _content_text(content) -> str:
    if isinstance(content, str):
        return content
    if not isinstance(content, list):
        return ""
    parts = []
    for block in content:
        if isinstance(block, dict) and block.get("type") == "text":
            parts.append(str(block.get("text") or ""))
    return "".join(parts)


def aggregate(events: list[dict]) -> dict:
    """Sum usage once per response.

    The rule is exact rather than heuristic, and it leans on `is_complete_stream()`:

    * A COMPLETE stream emits `message_end` AND `turn_end` for the same response, so only
      `turn_end` is summed. Mixing the two would bill every turn twice.
    * `message_end` is used only when the stream has no `turn_end` at all - an older or
      different provider format, never a complete pi stream.
    * An INCOMPLETE stream reports no captured usage. Guessing which of two identical
      responses is a duplicate, or charging a killed turn we never saw the end of, would
      produce a confident wrong number - the failure mode this file exists to prevent.
      `lane_model.sh` stores usage only after a successful extraction, so a failed attempt
      simply carries no cost rather than an invented one.
    """
    complete = is_complete_stream(events)
    candidates = [e for e in events
                  if e.get("type") in ("turn_end", "message_end")
                  and isinstance(e.get("message"), dict)]
    turn_ends = [e for e in candidates if e["type"] == "turn_end"]
    source = turn_ends or candidates

    totals = {key: 0 for key in USAGE_KEYS}
    cost = 0.0
    models: list[str] = []
    seen: set[str] = set()
    counted = 0
    for event in source:
        message = event["message"]
        if str(message.get("role") or "assistant") != "assistant":
            continue
        usage = message.get("usage")
        if not isinstance(usage, dict):
            continue
        response_id = str(message.get("responseId") or "")
        if response_id:
            if response_id in seen:  # a retried response must not be billed twice
                continue
            seen.add(response_id)
        counted += 1
        for key in USAGE_KEYS:
            value = usage.get(key)
            if isinstance(value, (int, float)):
                totals[key] += value
        cost_block = usage.get("cost") or {}
        if isinstance(cost_block.get("total"), (int, float)):
            cost += float(cost_block["total"])
        if message.get("responseModel"):
            models.append(str(message["responseModel"]))
    if not complete:
        # No partial figures: a caller that reads `totalTokens` without checking `captured`
        # must not be handed a number that is only half of the truth.
        return {
            "turns": 0,
            **{key: 0 for key in USAGE_KEYS},
            "cost_usd": 0.0,
            "served_model": models[-1] if models else None,
            "response_id": None,
            "captured": False,
            "complete": False,
        }
    return {
        "turns": counted,
        **totals,
        "cost_usd": round(cost, 8),
        "served_model": models[-1] if models else None,
        "response_id": sorted(seen)[-1] if seen else None,
        "captured": counted > 0,
        "complete": complete,
    }


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="split pi --mode json output")
    sub = parser.add_subparsers(dest="command", required=True)
    extract = sub.add_parser("extract", help="assistant text to stdout")
    extract.add_argument("file")
    usage = sub.add_parser("usage", help="aggregated usage")
    usage.add_argument("file")
    usage.add_argument("--json", action="store_true")
    args = parser.parse_args(argv)

    events = _events(Path(args.file))
    if args.command == "extract":
        text = assistant_text(events)
        if not text.strip():
            return 1
        sys.stdout.write(text if text.endswith("\n") else text + "\n")
        return 0

    totals = aggregate(events)
    if args.json:
        print(json.dumps(totals))
        return 0 if totals["captured"] else 1
    print(f"tokens={totals['totalTokens']} cost=${totals['cost_usd']:.6f} "
          f"served={totals['served_model'] or '?'}")
    return 0 if totals["captured"] else 1


if __name__ == "__main__":
    raise SystemExit(main())
