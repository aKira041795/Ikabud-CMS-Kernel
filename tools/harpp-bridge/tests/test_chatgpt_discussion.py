"""Chair-steered discussions with ChatGPT (advisor) — the Messenger trigger.

OWNER-CONTROLLED ORACLE. Red on the 2026-10-05 tree: an owner can only debate the two dev-pool
models. The owner's goal is "when i bounce ideas to you and ask you to get inputs from chatgpt,
you and chatgpt can discuss autonomously, you control and steer the discussion to arrive at a
working plan."

The vehicle already exists — `tools/pi-arch-debate.py` runs N drafter/critic rounds between TWO
DISTINCT models (it fails closed if they resolve to the same one) and the chair picks the opener,
so "chair steers the discussion" is what it already does. What is missing is a way to name
ChatGPT as the other participant, which here means the dedicated ideation model
(`openai-ideation/gpt-5.4`) that the advisor lane already owns.

Each guard carries its must-allow pair, so a trigger that fires on any mention of ChatGPT is red,
and so is a change that alters the ordinary debate.
"""

import re
import unittest
from unittest import mock

import harpp_wake

IDEA = "Resolve: should HARPP keep one lane per concern or unify them?"
# The advisor's ChatGPT is the owner's SUBSCRIPTION driven through the web UI, NOT the
# openai-ideation API model — whose credits ran out on 2026-10-05 (HTTP 429
# credit_balance_exhausted) and silently produced a debate draft with no text.
CHATGPT_PAGE_ALIAS = "chatgpt/page"


def _launched_command(message):
    """Run the debate router for one owner message and return the command it would launch."""
    cmd = harpp_wake.parse_debate_command(message)
    if cmd is None:
        return None
    with mock.patch.object(harpp_wake, "launch_job",
                           side_effect=lambda **kw: ("job-1", None)) as launched:
        harpp_wake._exec_debate_command(cmd, 1)
    return launched.call_args.kwargs.get("command", "")


class ChatgptParticipantTest(unittest.TestCase):
    """Naming ChatGPT must select the ideation model as the other debate side."""

    def test_debate_with_chatgpt_selects_the_ideation_model(self):
        command = _launched_command("start debate with chatgpt: " + IDEA)
        self.assertIsNotNone(command, "the message was not recognised as a debate request")
        self.assertIn(f"DEBATE_MODEL_B={CHATGPT_PAGE_ALIAS}", command,
                      f"ChatGPT was named but the advisor's own ChatGPT was not used: {command}")
        self.assertNotIn("openai-ideation", command,
                         "the debate must not depend on API credit to reach ChatGPT")

    def test_discuss_with_chatgpt_alone_is_a_request(self):
        # The owner's phrasing is "ask you to get inputs from chatgpt"; an explicit discussion
        # verb must be enough without the word "debate".
        command = _launched_command("discuss with chatgpt: " + IDEA)
        self.assertIsNotNone(command, "an explicit 'discuss with chatgpt' request was ignored")
        self.assertIn(f"DEBATE_MODEL_B={CHATGPT_PAGE_ALIAS}", command)

    def test_intent_is_still_captured(self):
        cmd = harpp_wake.parse_debate_command("start debate with chatgpt: " + IDEA)
        self.assertIsNotNone(cmd)
        self.assertIn("one lane per concern", cmd["intent"])

    # -- must-allow: the ordinary debate is untouched ----------------------

    def test_plain_debate_keeps_the_default_pair(self):
        command = _launched_command("start debate: " + IDEA)
        self.assertIsNotNone(command)
        self.assertNotIn("DEBATE_MODEL_B=", command,
                         "an ordinary debate must keep its configured default models")

    # -- must-refuse: mentioning ChatGPT is not a request ------------------

    def test_mentioning_chatgpt_is_not_a_request(self):
        for message in (
            "I asked chatgpt about the lane design yesterday",
            "chatgpt thinks the router is fine",
            "what did chatgpt say about the status page?",
        ):
            self.assertIsNone(harpp_wake.parse_debate_command(message),
                              f"a passing mention launched a debate: {message!r}")

    def test_compound_word_still_rejected(self):
        self.assertIsNone(harpp_wake.parse_debate_command("Start inspect-debate-logs"))

    def test_request_without_intent_is_rejected(self):
        self.assertIsNone(harpp_wake.parse_debate_command("discuss with chatgpt"))


if __name__ == "__main__":
    unittest.main()
