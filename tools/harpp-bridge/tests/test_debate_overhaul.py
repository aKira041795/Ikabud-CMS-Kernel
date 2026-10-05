"""Chair-led debate overhaul — the oracle.

OWNER DIRECTIVE 2026-10-06: "harpp debate must be overhauled. it's too mechanical. you as chair can
decide and approve. you can delegate to chatgpt the approved debate context and have an
implementable plan created for delegation to Sol if not rate limited."

What is mechanical today (measured, tools/pi-arch-debate.py):
- the CHAIR does not judge: the verdict is scraped from the CRITIC's prose by `verdict_of()`
  (line 332), `DEBATE_AUTO_APPROVE=1` flips REVISIONS to APPROVED unconditionally (line 499), and
  approval is a separate HUMAN step (`--approve`, writing "approved" with no reason recorded);
- the round count is fixed (`DEBATE_MAX_ROUNDS`, default 3) with a crude text-similarity early
  stop, so the chair cannot decide that it has heard enough, or that the idea is not worth pursuing;
- the pipeline stops at a draft artefact. There is no implementable plan and no delegation.

The overhauled shape, and what this oracle pins:
  1. the CHAIR decides convergence, per round, with a recorded reason;
  2. approval is the chair's, and an absent decision is NOT a silent approval;
  3. the approved context goes to ChatGPT (the advisor's `page` backend) to produce an
     IMPLEMENTABLE plan;
  4. that plan is only delegatable when it carries an acceptance command and what pass looks like
     (tools/lane.sh refuses a lane without them, so a plan lacking them cannot be acted on);
  5. delegation prefers Sol and falls back explicitly — never silently skips.

RED on the 2026-10-06 tree: `tools/harpp-bridge/debate_pipeline.py` does not exist, so these
imports fail. The interface below is the chair's decision, not a suggestion.
"""

import unittest

from debate_pipeline import (  # noqa: E402 - module under construction
    ChairDecision,
    choose_implementer,
    parse_chair_decision,
    plan_is_delegatable,
)

CHAIR_CONVERGE = """\
The discussion has converged: both sides now agree on a single routing key and the toggle is an
entry point only.

CHAIR_DECISION:
decision: converge
reason: both sides converged on one routing key; no open disagreement remains
"""

CHAIR_ANOTHER_ROUND = """\
ChatGPT raised a quota question the critic has not answered.

CHAIR_DECISION:
decision: another_round
reason: the isolation guarantee is still contested and needs one more exchange
"""

CHAIR_ABORT = """\
The idea duplicates the existing advisor lane.

CHAIR_DECISION:
decision: abort
reason: the proposal adds a second advisor surface with no benefit; stop rather than spend more
"""

NO_DECISION = """\
This round produced prose with a verdict word like APPROVED in it, but no chair decision block.
"""

PLAN_OK = """\
## Objective
Make the Messenger toggle the only advisor entry point.

## Scope
allowed: modules/harpp/assets/messenger.js, templates/modules/harpp/messenger.disyl
prohibited: no route renames, no config changes

## Acceptance
command: python3 -m unittest tests.test_lane_isolation
pass-looks-like: Ran 9 tests ... OK

## Tests
Extend tests/test_lane_isolation.py with the session-marker case.

## Risks
Thread rename once bound to the lane — already covered by the bound conversation id.
"""

PLAN_NO_ACCEPTANCE = """\
## Objective
Make the Messenger toggle the only advisor entry point.

## Scope
allowed: modules/harpp/assets/messenger.js

## Notes
This looks sensible and should be straightforward.
"""

PLAN_PLACEHOLDER = """\
## Objective
<describe the objective>

## Acceptance
command: TBD
pass-looks-like: TBD
"""


class ChairDecisionTest(unittest.TestCase):
    """The chair decides — not a token in the critic's prose."""

    def test_converge_is_parsed_with_its_reason(self):
        decision = parse_chair_decision(CHAIR_CONVERGE)
        self.assertIsNotNone(decision, "a CHAIR_DECISION block must be recognised")
        self.assertEqual(decision.action, "converge")
        self.assertIn("one routing key", decision.reason)

    def test_another_round_and_abort_are_recognised(self):
        self.assertEqual(parse_chair_decision(CHAIR_ANOTHER_ROUND).action, "another_round")
        self.assertEqual(parse_chair_decision(CHAIR_ABORT).action, "abort")

    def test_missing_block_is_not_a_silent_approval(self):
        # The whole point: prose containing the word APPROVED must never authorise the pipeline.
        self.assertIsNone(
            parse_chair_decision(NO_DECISION),
            "prose without a chair decision block must not be read as approval")
        self.assertIsNone(parse_chair_decision(""))
        self.assertIsNone(parse_chair_decision(None))

    def test_unknown_action_is_rejected_not_coerced(self):
        text = "CHAIR_DECISION:\ndecision: approve\nreason: looks fine\n"
        self.assertIsNone(parse_chair_decision(text),
                          "only the defined actions are accepted; 'approve' is not one of them")

    def test_decision_carries_provenance(self):
        decision = parse_chair_decision(CHAIR_CONVERGE)
        self.assertIsInstance(decision, ChairDecision)
        self.assertTrue(decision.reason.strip(), "an approval without a reason is unauditable")


class ImplementablePlanTest(unittest.TestCase):
    """A plan is only delegatable when a lane could actually run it."""

    def test_plan_with_acceptance_and_pass_looks_like_is_delegatable(self):
        ok, reason = plan_is_delegatable(PLAN_OK)
        self.assertTrue(ok, f"a complete plan must be delegatable; refused because: {reason}")

    def test_plan_without_acceptance_is_refused(self):
        ok, reason = plan_is_delegatable(PLAN_NO_ACCEPTANCE)
        self.assertFalse(ok, "a plan without an acceptance command cannot be gated, so it must not "
                             "be delegated")
        self.assertIn("acceptance", reason.lower())

    def test_placeholder_plan_is_refused(self):
        ok, reason = plan_is_delegatable(PLAN_PLACEHOLDER)
        self.assertFalse(ok, "a plan full of TBD placeholders is not implementable")
        self.assertIn("placeholder", reason.lower())

    def test_empty_plan_is_refused(self):
        for empty in ("", None, "   "):
            ok, _ = plan_is_delegatable(empty)
            self.assertFalse(ok, "an empty plan must never be delegated")


class ImplementerChoiceTest(unittest.TestCase):
    """Delegate to Sol when it has quota; otherwise say who and why."""

    def test_sol_is_preferred_when_available(self):
        model, reason = choose_implementer({"openai-codex/gpt-5.6-sol": True,
                                            "deepseek-v4-flash": True})
        self.assertEqual(model, "openai-codex/gpt-5.6-sol")
        self.assertTrue(reason.strip(), "the choice must be stated, not silent")

    def test_falls_back_with_a_stated_reason_when_sol_is_exhausted(self):
        model, reason = choose_implementer({"openai-codex/gpt-5.6-sol": False,
                                            "deepseek-v4-flash": True})
        self.assertEqual(model, "deepseek-v4-flash")
        self.assertIn("sol", reason.lower())

    def test_no_implementer_available_is_reported_not_swallowed(self):
        model, reason = choose_implementer({"openai-codex/gpt-5.6-sol": False,
                                            "deepseek-v4-flash": False})
        self.assertIsNone(model)
        self.assertTrue(reason.strip(), "an unavailable implementer must be reported")


if __name__ == "__main__":
    unittest.main()
