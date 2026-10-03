#!/usr/bin/env bash
#
# tools/lane-model.sh — shared model invocation with automatic fallback.
#
# Why this exists: every lane script used to hand-roll its own "try model A, and if it
# is rate-limited try model B" block. Three lanes written on 2026-10-03 carried the same
# ~40 lines, including the same rate-limit regex - so a bug in that regex had to be
# fixed in three places, and a lane written without it would simply stall when its
# primary model hit a quota.
#
# The regex is the part that matters and the part that is easy to get subtly wrong:
#   - "rate limit" and "429" are the obvious forms
#   - "quota" and "usage limit" are how Codex actually reports exhaustion
#   - "model is not supported" is NOT a rate limit but has the same remedy (the account
#     cannot use that model at all), and leaving it out means a lane retries a model
#     that can never succeed. Observed 2026-09-18: gpt-5.6-astra is not available to a
#     ChatGPT-account Codex, and the failure text is exactly this.
#
# Usage, from a lane script:
#
#   source "$(dirname "${BASH_SOURCE[0]}")/lane-model.sh"   # or an absolute path
#   lane_model_run "openai-codex/gpt-5.6-sol,deepseek-v4-flash" "$PROMPT" /tmp/mylane
#   rc=$?
#   echo "completed by: $LANE_MODEL_USED"
#
# Sets, on return:
#   LANE_MODEL_USED   the model that completed (empty if none did)
#   LANE_MODEL_LOG    path to that model's log
# Returns: 0 if a model completed, 1 if every model was rate-limited/unavailable.
#
# Testing: set LANE_MODEL_CMD to a stub to exercise the fallback without spending tokens.
# The default is `pi --print --approve`; the model is appended as `--model <name>`, then
# the prompt. A stub therefore receives: <stub> --model <name> <prompt>.

LANE_MODEL_CMD="${LANE_MODEL_CMD:-pi --print --approve}"

# One second per unit; kept deliberately below common dispatcher timeouts so a stalled
# model returns control to the caller rather than dying at the caller's own cap.
LANE_MODEL_TIMEOUT="${LANE_MODEL_TIMEOUT:-4500}"

# Signatures that mean "this model cannot serve this request - try the next one".
# Matched case-insensitively against the model's own log.
lane_model_unavailable() {
  local log="$1"
  [ -f "$log" ] || return 0
  grep -qiE 'rate.?limit|429|quota|usage limit|too many requests|model is not supported' "$log" 2>/dev/null
}

# lane_model_run <comma-separated-models> <prompt> <log-prefix>
lane_model_run() {
  local models="$1" prompt="$2" prefix="$3"

  LANE_MODEL_USED=""
  LANE_MODEL_LOG=""

  local oldifs="$IFS"
  IFS=','
  local list=($models)
  IFS="$oldifs"

  local model safe log rc attempt=0 total="${#list[@]}"
  for model in "${list[@]}"; do
    # trim surrounding whitespace
    model="$(printf '%s' "$model" | tr -d '[:space:]')"
    [ -n "$model" ] || continue
    attempt=$((attempt + 1))

    # A model name contains '/', which is not usable in a filename.
    safe="$(printf '%s' "$model" | tr '/:' '__')"
    log="${prefix}-${safe}.log"

    echo "--- attempt ${attempt}/${total}: ${model} ---"
    # shellcheck disable=SC2086
    timeout --signal=TERM --kill-after=60 "$LANE_MODEL_TIMEOUT" \
      $LANE_MODEL_CMD --model "$model" "$prompt" > "$log" 2>&1
    rc=$?
    echo "    exit=${rc} log=$(wc -c < "$log" 2>/dev/null || echo 0)b"

    if [ "$rc" -eq 0 ] && ! lane_model_unavailable "$log"; then
      LANE_MODEL_USED="$model"
      LANE_MODEL_LOG="$log"
      echo "=== MODEL THAT COMPLETED: ${model} ==="
      cat "$log"
      return 0
    fi

    if [ "$rc" -eq 0 ]; then
      echo "    (exit 0 but the log shows the model was unavailable - treating as failure)"
    fi
    lane_model_unavailable "$log" && \
      echo "    unavailable signature:" && \
      grep -iE 'rate.?limit|429|quota|usage limit|too many requests|model is not supported' "$log" | head -3
  done

  echo "=== MODEL THAT COMPLETED: none — every model in '${models}' was unavailable ==="
  return 1
}
