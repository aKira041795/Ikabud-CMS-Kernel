#!/usr/bin/env bash
#
# examples/lane-example.sh — the smallest useful lane script. Copy it, rename it, rewrite the prompt.
#
# A lane script is just a bash script that:
#   1. works from the repository root,
#   2. sources tools/lane-model.sh (so the model chain, the budget and the fallback are shared),
#   3. builds ONE prompt string,
#   4. calls lane_model_run and exits with its status.
#
# Everything that makes it trustworthy lives in the harness, not here:
#   - `lane.sh run` refuses to dispatch unless your --acceptance command FAILS on this tree first;
#   - after the lane lands, that SAME command is re-run and decides the verdict;
#   - the exit status of the model CLI decides success; log content only CLASSIFIES a failure.
#
# Usage (from the repository root):
#   bash tools/lane.sh run my-task tools/lane-my-task.sh \
#     --acceptance="bash tests/run.sh 2>&1 | grep -qx '  12/12 passed'" \
#     --pass-looks-like="12/12 passed and exit status 0"
#
set -u

# Work from the repo root however this script was invoked. (A lane is dispatched by absolute path.)
cd "$(git rev-parse --show-toplevel 2>/dev/null || dirname "$(cd "$(dirname "$0")" && pwd)")" || exit 1
source tools/lane-model.sh

# $LANE_MODEL_CHAIN is already the ordered chain from tools/model-chain.txt. Override it only when a
# task genuinely needs one model (e.g. a cheap classification job).
# LANE_MODEL_CHAIN="openai-codex/gpt-5.6-sol,deepseek-v4-flash,openai-codex/gpt-5.6-terra"

PROMPT="$(cat <<'PROMPT_EOF'
You are working in this repository. Read AGENTS.md or CONTRIBUTING.md for conventions first.

## OBJECTIVE
<one sentence: what must be true when you are done>

## SCOPE
allowed:     <files or areas you may touch>
prohibited:  <files or areas you may NOT touch>

## WHAT I HAVE ALREADY MEASURED
<put facts here, not guesses. If you do not have facts, say so and ask rather than assuming.>

## ACCEPTANCE
<the exact command the harness will re-run. It fails right now - that is why you were dispatched.>

## RULES
- Smallest correct change. Do not refactor unrelated code, do not reformat files.
- Never weaken, skip or delete a test assertion to reach green.
- `bash -n` every shell file you touch.
- If a requirement is unsatisfiable, report BLOCKED with the precise reason. That is a valued
  outcome - do not improvise around it, and do not weaken the criterion to make it pass.

## REPORT
status: PASS | PARTIAL | BLOCKED; files changed; the command you ran and its observed output;
anything you could not verify; and any place this brief is wrong.
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/lane-my-task
rc=$?
echo "lane: my-task - completed by: ${LANE_MODEL_USED:-<none>} (rc=$rc)"
exit "$rc"
