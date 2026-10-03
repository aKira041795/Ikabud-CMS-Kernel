#!/usr/bin/env bash
#
# tools/lane-model.sh — shared model invocation with automatic fallback.
#
# Why this exists: every lane script used to hand-roll its own "try model A, and if it
# is rate-limited try model B" block. Three lanes written on 2026-10-03 carried the same
# ~40 lines, including the same unavailability check - so a bug in that check had to be
# fixed in three places, and a lane written without it would simply stall when its
# primary model became unavailable.
#
# The signature is the part that matters and the part that is easy to get subtly wrong.
# It is therefore loaded from the same data file as lane.sh and HARPP rather than being
# repeated here. Unsupported models use the same fallback path as temporary exhaustion.
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
# Returns: 0 if a model completed, 1 if every model was unavailable.
#
# Testing: set LANE_MODEL_CMD to a stub to exercise the fallback without spending tokens.
# The default is `pi --print --approve`; the model is appended as `--model <name>`, then
# the prompt. A stub therefore receives: <stub> --model <name> <prompt>.

LANE_MODEL_CMD="${LANE_MODEL_CMD:-pi --print --approve}"
_LANE_MODEL_TOOLS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
MODEL_UNAVAILABLE_PATTERNS="$_LANE_MODEL_TOOLS_DIR/model-unavailable.patterns"

# One second per unit; kept deliberately below common dispatcher timeouts so a stalled
# model returns control to the caller rather than dying at the caller's own cap.
LANE_MODEL_TIMEOUT="${LANE_MODEL_TIMEOUT:-4500}"

# Signatures that mean "this model cannot serve this request - try the next one".
# Matched case-insensitively against the model's own log.
lane_model_unavailable() {
  local log="$1"
  [ -f "$log" ] || return 0
  [ -r "$MODEL_UNAVAILABLE_PATTERNS" ] || return 0
  grep -qiE -f "$MODEL_UNAVAILABLE_PATTERNS" "$log" 2>/dev/null
}

# Print the matching lines, so a caller can say WHY a run was called unavailable.
# Exists because a bare "unavailable" gives the lane author nothing to act on.
lane_model_unavailable_line() {
  local log="$1"
  [ -f "$log" ] || return 1
  [ -r "$MODEL_UNAVAILABLE_PATTERNS" ] || return 1
  grep -aiE -f "$MODEL_UNAVAILABLE_PATTERNS" "$log" 2>/dev/null
}

# lane_model_run <comma-separated-models> <prompt> <log-prefix>
lane_model_run() {
  # Validate loudly. Omitted arguments used to die as "$3: unbound variable" under
  # `set -u`, which names the shell's problem rather than the caller's mistake - and a
  # lane author sees a crash with no hint that the fix is a missing prefix argument.
  if [ "$#" -lt 3 ] || [ -z "${1:-}" ] || [ -z "${2:-}" ] || [ -z "${3:-}" ]; then
    echo "lane_model_run: need 3 arguments, got $#." >&2
    echo "  usage: lane_model_run <models-csv> <prompt> <log-prefix>" >&2
    echo "  e.g.   lane_model_run \"openai-codex/gpt-5.6-sol,deepseek-v4-flash\" \"\$PROMPT\" /tmp/mylane" >&2
    LANE_MODEL_USED=""
    LANE_MODEL_LOG=""
    return 2
  fi

  local models="$1" prompt="$2" prefix="$3"

  LANE_MODEL_USED=""
  LANE_MODEL_LOG=""

  local oldifs="$IFS"
  IFS=','
  local list=($models)
  IFS="$oldifs"

  local model safe log rc attempt=0 total="${#list[@]}"
  local saw_unavailable=0
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

    if [ "$rc" -eq 0 ]; then
      # SUCCESS IS DECIDED BY THE EXIT STATUS ALONE.
      #
      # An earlier version also failed an exit-0 run whose log merely CONTAINED an
      # unavailability word. That produced a real false red on the very first
      # production use: a lane implementing the unavailability detector writes about
      # "quota" and "rate limit" by definition, so its own successful output matched
      # the signature, its correct work was rejected, a second model was spent for
      # nothing, and the run reported "none completed".
      #
      # A wrong guard is worse than none because it is trusted. Content is used here
      # to CLASSIFY a failure, never to overrule a success.
      LANE_MODEL_USED="$model"
      LANE_MODEL_LOG="$log"
      echo "=== MODEL THAT COMPLETED: ${model} ==="
      cat "$log"
      return 0
    fi

    # The run failed. Report WHY, so the next attempt is an informed choice.
    if lane_model_unavailable "$log"; then
      saw_unavailable=1
      echo "    unavailable signature:"
      lane_model_unavailable_line "$log" | head -3
    else
      echo "    (failed without an unavailability signature - treating as a crash)"
    fi
  done

  # Distinguish the two very different outcomes. This line used to assert "was unavailable"
  # unconditionally, even when every attempt above had printed "treating as a crash". A reader
  # duly reported a quota story to the owner while the log contradicted it, and the real cause
  # (a SIGTERM, exit=143, zero-byte logs) was nearly missed. A verdict that contradicts its own
  # evidence is worse than no verdict.
  if [ "$saw_unavailable" -eq 1 ]; then
    echo "=== MODEL THAT COMPLETED: none — every model in '${models}' was unavailable ==="
  else
    echo "=== MODEL THAT COMPLETED: none — NO unavailability signature seen. Every attempt ==="
    echo "=== CRASHED or was KILLED. This is NOT a quota result — read the exit codes above. ==="
  fi
  return 1
}
