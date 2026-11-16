#!/usr/bin/env bash
# Review-only lane: Sol reads the Daily Ledger concern changes and reports findings.
# No code edits. The report is written next to the prompt under .ai/drafts/ so the
# owner can read it from the workspace (never /tmp).
set -u

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT" || exit 1

source "$ROOT/tools/lane-model.sh"

PROMPT_FILE="${PROMPT_FILE:-$ROOT/.ai/drafts/dl-concerns-2026-11-16.sol-review.prompt.md}"
OUT_PREFIX="${OUT_PREFIX:-$ROOT/.ai/drafts/dl-concerns-2026-11-16.sol-review}"

if [ ! -f "$PROMPT_FILE" ]; then
  echo "prompt file missing: $PROMPT_FILE" >&2
  exit 2
fi

PROMPT="$(cat "$PROMPT_FILE")

Repository root: $ROOT
Work read-only. Do not modify files. Reply with the review only."

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" "$OUT_PREFIX"
rc=$?
echo "completed by: ${LANE_MODEL_USED:-none}"
echo "report: ${LANE_MODEL_LOG:-none}"
exit $rc
