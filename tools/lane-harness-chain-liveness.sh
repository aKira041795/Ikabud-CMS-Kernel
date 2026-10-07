#!/usr/bin/env bash
#
# Lane: harness-chain-liveness
#
# Makes tools/lane-model.sh budget-aware so that a HANG on one model cannot consume enough of the
# run to make the configured fallback unreachable.
#
# WHY: the chain advances only on EXIT, and the per-attempt cap defaulted to 4500s against a lane
# budget of 7200s across three models - so two hangs exceed the budget and model 3 is never
# reached. Measured 2026-10-04: a full 40-minute budget lost, nothing recorded.
#
# CONTRACT (AUTHORITATIVE): .ai/harness-chain-liveness-contract.md
# CRITERION (DO NOT EDIT):  tools/harness-chain-liveness-probe.sh

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

LANE_MODEL_CHAIN="openai-codex/gpt-5.6-sol,openai-codex/gpt-5.6-terra,deepseek-v4-flash"

PROMPT="$(cat <<'PROMPT_EOF'
You are implementing a change to the GOVERNED LANE HARNESS in the Ikabud repo at
/var/www/html/applicationostest.

READ FIRST, and treat as authoritative:
  .ai/harness-chain-liveness-contract.md     (the contract: the invariant, the scope, the pins)
  tools/harness-chain-liveness-probe.sh      (the criterion - READ IT, DO NOT EDIT IT)
  tools/lane-model.sh                        (196 lines - the whole change lives here)
  tools/lane-model-selftest.sh               (19 cases; every one must stay green)

The objective in one sentence: no individual model attempt may consume enough of the remaining
budget to make the configured fallback impossible.

The probe is the spec and it is measured to FAIL on the tree you are starting from
(1 passed, 3 failed) - the chair ran it before dispatching you. Make it 4 passed, 0 failed WITHOUT
touching it. L3 is a PIN: it already passes on the base tree and must stay passing.

## WHAT MATTERS MOST

- ONE invariant, not new machinery: derive each attempt's cap from the REMAINING budget divided by
  the ATTEMPTS LEFT, floored by a minimum. Announce the computed cap per attempt.
- An EXPLICITLY SET LANE_MODEL_TIMEOUT keeps overriding the derived cap. lane-model-selftest.sh
  sets it to 10 and its 19 cases depend on it - break that and you have rewritten the harness's own
  oracle, which is worse than failing.
- A timeout must be NAMED as a timeout (rc 124/137/143 = cut by its own cap), distinct from
  `unavailable`/quota. Today the summary blames credentials for a budget kill.
- Default budget BELOW the lane default: LANE_MODEL_BUDGET=6600 against the lane's --timeout=7200,
  so the harness keeps room to record the landing and re-run its acceptance command.
- Guard against empty or non-numeric budget/min under `set -u`: fall back to the default rather
  than computing a zero or negative cap.

## RULES

- Smallest correct change. Do not restructure lane-model.sh, do not rename exported variables, do
  not reformat the file, do not touch tools/model-chain.txt (the ordering is a separate cost
  decision).
- Do NOT touch tools/lane.sh - another lane is editing it right now. In particular DO NOT run
  `bash tools/lane.sh selftest`: it takes the fixture names st1..st13b in .ai/runs and would
  collide with the running lane. Your oracles are lane-model-selftest.sh and the probe.
- Do NOT weaken or delete an existing selftest case to reach green. If a case must change because
  the behaviour it pinned is deliberately replaced, say which and why - and add a case for the new
  behaviour.
- `bash -n tools/lane-model.sh` after every edit.

## VERIFY (report observed numbers, not claims)

  bash -n tools/lane-model.sh
  bash tools/harness-chain-liveness-probe.sh     # want: probe: 4 passed, 0 failed
  bash tools/lane-model-selftest.sh              # want: 19 passed, 0 failed (unchanged)

Then RUN THE MUTATION and report what you saw:
  remove the division (give every attempt the full remaining budget) -> probe L2 must go RED.
If L2 does not go red, the guard is decorative - say so plainly instead of reporting a pass.

Also check that neither storage/logs/app.log nor storage/logs/error.log gained a line.

## REPORT

status: PASS | PARTIAL | BLOCKED; files changed; per-direction probe result; the selftest count;
the mutation result; anything unverified; and any place THE CONTRACT is wrong. Reporting BLOCKED
with a precise reason is a valid, valued outcome - never weaken the criterion to make it pass.
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/harness-chain-liveness
rc=$?
echo "lane: harness-chain-liveness - completed by: ${LANE_MODEL_USED:-<none>} (rc=$rc)"
exit "$rc"
