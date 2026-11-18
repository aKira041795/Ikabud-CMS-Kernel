#!/usr/bin/env python3
"""Tests for the pi-json splitter (tools/lane-usage.py).

These pin the two failure modes an adversarial review found: extracting a STALE
assistant message from a killed stream, and summing the same billable response twice.
Run: python3 -m unittest tools.tests.test_lane_usage -v
"""
from __future__ import annotations

import importlib.util
import json
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]


def _load():
    spec = importlib.util.spec_from_file_location("lane_usage", ROOT / "tools" / "lane-usage.py")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def _usage(cost: float, tokens: int) -> dict:
    return {"input": tokens, "output": 1, "cacheRead": 0, "cacheWrite": 0, "reasoning": 0,
            "totalTokens": tokens,
            "cost": {"input": cost, "output": 0, "cacheRead": 0, "cacheWrite": 0, "total": cost}}


def _assistant(text: str, response_id: str | None = None, usage: dict | None = None,
               role: str = "assistant") -> dict:
    message = {"role": role, "content": [{"type": "text", "text": text}],
               "responseModel": "deepseek-flash"}
    if response_id:
        message["responseId"] = response_id
    if usage:
        message["usage"] = usage
    return message


class LaneUsageTest(unittest.TestCase):
    def setUp(self) -> None:
        self.module = _load()

    # --------------------------------------------------------------- extraction
    def test_complete_stream_yields_the_final_reply(self) -> None:
        events = [
            {"type": "turn_end", "message": _assistant("first attempt", "r1")},
            {"type": "turn_end", "message": _assistant("Status: PASS\ndone", "r2")},
            {"type": "agent_end"},
        ]
        self.assertEqual(self.module.assistant_text(events), "Status: PASS\ndone")

    def test_killed_stream_never_leaks_a_stale_earlier_message(self) -> None:
        """A truncated final turn must yield NOTHING so the caller falls back to the raw log."""
        events = [
            {"type": "message_end", "message": _assistant("Status: PASS\nlooks fine", "r1")},
            {"type": "message_update", "assistantMessageEvent": {"type": "text_delta", "delta": "429 "}},
        ]
        self.assertFalse(self.module.is_complete_stream(events))
        self.assertEqual(self.module.assistant_text(events), "",
                         "a stale PASS was extracted from a stream that never completed")

    def test_an_earlier_turn_end_does_not_certify_a_truncated_later_turn(self) -> None:
        """Completion is about the END of the stream, not about any completion inside it."""
        events = [
            {"type": "turn_end", "message": _assistant("Status: PASS\nearly", "r1")},
            {"type": "message_update", "assistantMessageEvent": {"type": "text_delta", "delta": "err"}},
        ]
        self.assertFalse(self.module.is_complete_stream(events),
                         "an earlier turn_end certified a stream that did not finish")
        self.assertEqual(self.module.assistant_text(events), "")

    def test_empty_stream_is_not_complete(self) -> None:
        self.assertFalse(self.module.is_complete_stream([]))
        self.assertEqual(self.module.assistant_text([]), "")

    # -------------------------------------------------------------------- usage
    def test_same_response_in_both_event_types_is_counted_once(self) -> None:
        events = [
            {"type": "message_end", "message": _assistant("ok", "r1", _usage(0.01, 100))},
            {"type": "turn_end", "message": _assistant("ok", "r1", _usage(0.01, 100))},
        ]
        totals = self.module.aggregate(events)
        self.assertAlmostEqual(totals["cost_usd"], 0.01, msg="double counted one response")
        self.assertEqual(totals["totalTokens"], 100)
        self.assertEqual(totals["turns"], 1)

    def test_multi_turn_tool_loop_is_summed(self) -> None:
        events = [
            {"type": "turn_end", "message": _assistant("step 1", "r1", _usage(0.01, 100))},
            {"type": "turn_end", "message": _assistant("step 2", "r2", _usage(0.02, 200))},
            {"type": "agent_end"},
        ]
        totals = self.module.aggregate(events)
        self.assertAlmostEqual(totals["cost_usd"], 0.03)
        self.assertEqual(totals["totalTokens"], 300)

    def test_a_response_only_message_end_still_counts(self) -> None:
        """Preferring turn_end GLOBALLY would silently drop this response."""
        events = [
            {"type": "turn_end", "message": _assistant("step 1", "r1", _usage(0.01, 100))},
            {"type": "message_end", "message": _assistant("step 2", "r2", _usage(0.02, 200))},
            {"type": "turn_end", "message": _assistant("step 3", "r3", _usage(0.04, 400))},
            {"type": "agent_end"},
        ]
        totals = self.module.aggregate(events)
        self.assertAlmostEqual(totals["cost_usd"], 0.05,
                               msg="only turn_end is authoritative once any turn_end exists")
        self.assertEqual(totals["turns"], 2)

    def test_message_end_is_the_whole_stream_fallback(self) -> None:
        """A provider format that never emits turn_end is still measurable."""
        events = [
            {"type": "message_end", "message": _assistant("only", "r1", _usage(0.03, 300))},
            {"type": "agent_end"},
        ]
        totals = self.module.aggregate(events)
        self.assertAlmostEqual(totals["cost_usd"], 0.03)
        self.assertTrue(totals["captured"])

    def test_incomplete_stream_never_reports_captured_usage(self) -> None:
        """A killed turn cannot be charged: the number would be a guess."""
        events = [{"type": "turn_end", "message": _assistant("partial", "r1", _usage(0.05, 500))},
                  {"type": "message_update", "assistantMessageEvent": {"type": "text_delta", "delta": "x"}}]
        totals = self.module.aggregate(events)
        self.assertFalse(totals["captured"])
        self.assertFalse(totals["complete"])
        self.assertEqual(totals["turns"], 0)

    def test_non_assistant_usage_never_counts_as_captured(self) -> None:
        events = [{"type": "message_end", "message": _assistant("tool output", None,
                                                                _usage(0.5, 999), role="user")}]
        totals = self.module.aggregate(events)
        self.assertFalse(totals["captured"], "a user/tool message was billed as a response")
        self.assertAlmostEqual(totals["cost_usd"], 0.0)

    def test_captured_is_false_without_a_usage_block(self) -> None:
        events = [{"type": "turn_end", "message": _assistant("ok", "r1")},
                  {"type": "agent_end"}]
        self.assertFalse(self.module.aggregate(events)["captured"])

    def test_anonymous_duplicate_across_event_types_counts_once(self) -> None:
        """With no responseId, only turn_end is summed, so the pair cannot double count."""
        events = [
            {"type": "message_end", "message": _assistant("same answer", None, _usage(0.01, 100))},
            {"type": "turn_end", "message": _assistant("same answer", None, _usage(0.01, 100))},
            {"type": "agent_end"},
        ]
        totals = self.module.aggregate(events)
        self.assertAlmostEqual(totals["cost_usd"], 0.01, msg="anonymous response counted twice")
        self.assertEqual(totals["turns"], 1)

    def test_two_identical_anonymous_turns_both_count(self) -> None:
        events = [
            {"type": "turn_end", "message": _assistant("same", None, _usage(0.01, 100))},
            {"type": "turn_end", "message": _assistant("same", None, _usage(0.01, 100))},
            {"type": "agent_end"},
        ]
        totals = self.module.aggregate(events)
        self.assertAlmostEqual(totals["cost_usd"], 0.02, msg="distinct turns were merged")
        self.assertEqual(totals["turns"], 2)


if __name__ == "__main__":
    unittest.main()
