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
#   lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/mylane
#   rc=$?
#   echo "completed by: $LANE_MODEL_USED"
#
# $LANE_MODEL_CHAIN is the canonical ordered chain from tools/model-chain.txt. Use it
# rather than hard-coding a two-model list: a two-model chain stops the work when both
# providers are unavailable, which is what happened on 2026-10-03. `lane_model_chain_ok`
# checks the minimum length, and tools/lane-model-selftest.sh proves both directions.
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

# ── the canonical model chain ─────────────────────────────────────────────────────────
# Read, not hard-coded per lane. A chain shorter than LANE_MODEL_CHAIN_MIN is the exact
# defect this exists to remove (a single exhausted provider ending the work), so it is
# checked rather than trusted.
LANE_MODEL_CHAIN_FILE="${LANE_MODEL_CHAIN_FILE:-$_LANE_MODEL_TOOLS_DIR/model-chain.txt}"
LANE_MODEL_CHAIN_MIN="${LANE_MODEL_CHAIN_MIN:-3}"

# lane_model_chain_from <file> - print that file's models as a comma-separated list.
lane_model_chain_from() {
  local f="${1:-}"
  [ -r "$f" ] || return 1
  awk '!/^[[:space:]]*#/ && NF {gsub(/[[:space:]]/,""); printf "%s%s", (n++ ? "," : ""), $0}' "$f"
}

# The chain lanes use. Overridable for an experiment, but the default is the shared file.
LANE_MODEL_CHAIN="${LANE_MODEL_CHAIN:-$(lane_model_chain_from "$LANE_MODEL_CHAIN_FILE")}"
# An empty chain means the file is missing or carries no models. Say it at source time,
# rather than letting a lane fail later with a puzzling "need 3 arguments".
if [ -z "$LANE_MODEL_CHAIN" ]; then
  echo "lane-model.sh: WARNING: no models found in ${LANE_MODEL_CHAIN_FILE} - \$LANE_MODEL_CHAIN is empty" >&2
fi

# lane_model_chain_ok <csv> - 0 when the chain carries at least LANE_MODEL_CHAIN_MIN models.
lane_model_chain_ok() {
  # ${1:-} not $1: under `set -u` an omitted argument must not become a raw
  # "unbound variable" - that names the shell's problem instead of the caller's.
  local csv="${1:-}" n=0 item
  [ -n "$csv" ] || return 1
  local oldifs="$IFS"; IFS=','
  local items=($csv)
  IFS="$oldifs"
  for item in "${items[@]}"; do
    item="$(printf '%s' "$item" | tr -d '[:space:]')"
    [ -n "$item" ] && n=$((n + 1))
  done
  [ "$n" -ge "$LANE_MODEL_CHAIN_MIN" ]
}

# Unless a per-attempt timeout is explicitly supplied, divide the remaining run budget
# among the attempts still available. Keep the run budget below lane.sh's 7200s default
# so the lane has time to record the result and run its acceptance command.
LANE_MODEL_TIMEOUT="${LANE_MODEL_TIMEOUT-}"
LANE_MODEL_BUDGET="${LANE_MODEL_BUDGET:-6600}"
LANE_MODEL_TIMEOUT_MIN="${LANE_MODEL_TIMEOUT_MIN:-300}"
if ! [[ "$LANE_MODEL_BUDGET" =~ ^[0-9]+$ ]] || [ "$((10#$LANE_MODEL_BUDGET))" -le 0 ]; then
  LANE_MODEL_BUDGET=6600
else
  LANE_MODEL_BUDGET="$((10#$LANE_MODEL_BUDGET))"
fi
if ! [[ "$LANE_MODEL_TIMEOUT_MIN" =~ ^[0-9]+$ ]] || [ "$((10#$LANE_MODEL_TIMEOUT_MIN))" -le 0 ]; then
  LANE_MODEL_TIMEOUT_MIN=300
else
  LANE_MODEL_TIMEOUT_MIN="$((10#$LANE_MODEL_TIMEOUT_MIN))"
fi

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
    echo "  e.g.   lane_model_run \"\$LANE_MODEL_CHAIN\" \"\$PROMPT\" /tmp/mylane" >&2
    LANE_MODEL_USED=""
    LANE_MODEL_LOG=""
    return 2
  fi

  local models="$1" prompt="$2" prefix="$3"

  # A list shorter than the canonical chain is the defect that ended the work on
  # 2026-10-03: both models exhausted, nothing continued. This is a WARNING, not a
  # refusal, because the documented contract accepts any list and a lane may legitimately
  # pin one model for a cheap task. It fires at the exact moment of the defect, which is
  # the only place a lane author will actually see it.
  if ! lane_model_chain_ok "$models"; then
    echo "lane_model_run: WARNING: '${models}' carries fewer than ${LANE_MODEL_CHAIN_MIN} models." >&2
    echo "  One exhausted provider ends the work. Prefer the shared chain:" >&2
    echo "    lane_model_run \"\$LANE_MODEL_CHAIN\" \"\$PROMPT\" <log-prefix>" >&2
    echo "  (warning only; set LANE_MODEL_CHAIN_MIN=1 to silence)" >&2
  fi

  LANE_MODEL_USED=""
  LANE_MODEL_LOG=""

  local oldifs="$IFS"
  IFS=','
  local list=($models)
  IFS="$oldifs"

  local model safe log rc attempt=0 total="${#list[@]}"
  local saw_unavailable=0 saw_timeout=0 started=$SECONDS
  local elapsed remaining attempts_left attempt_cap

  # A configuration that cannot honour BOTH the floor and the budget is announced rather than
  # silently absorbed. Measured by the adversarial review 2026-10-07: LANE_MODEL_TIMEOUT_MIN=2
  # against LANE_MODEL_BUDGET=1 ran 2s, because the floor was applied with no reference to what was
  # actually left. This is a configuration error, so say so once, up front, where it can be fixed.
  if [ -z "$LANE_MODEL_TIMEOUT" ] && [ "$((LANE_MODEL_TIMEOUT_MIN * total))" -gt "$LANE_MODEL_BUDGET" ]; then
    echo "lane_model_run: WARNING: budget ${LANE_MODEL_BUDGET}s cannot honour a ${LANE_MODEL_TIMEOUT_MIN}s floor" >&2
    echo "  for ${total} attempts - later attempts are clamped to what is left, so the run may" >&2
    echo "  exceed its budget. Raise LANE_MODEL_BUDGET or lower LANE_MODEL_TIMEOUT_MIN." >&2
  fi

  for model in "${list[@]}"; do
    # trim surrounding whitespace
    model="$(printf '%s' "$model" | tr -d '[:space:]')"
    [ -n "$model" ] || continue
    attempt=$((attempt + 1))

    # A model name contains '/', which is not usable in a filename.
    safe="$(printf '%s' "$model" | tr '/:' '__')"
    log="${prefix}-${safe}.log"

    if [ -n "$LANE_MODEL_TIMEOUT" ]; then
      attempt_cap="$LANE_MODEL_TIMEOUT"
      echo "--- attempt ${attempt}/${total}: ${model}; cap=${attempt_cap}s (explicit override) ---"
    else
      elapsed=$((SECONDS - started))
      remaining=$((LANE_MODEL_BUDGET - elapsed))
      attempts_left=$((total - attempt + 1))
      attempt_cap=$((remaining / attempts_left))
      [ "$attempt_cap" -ge "$LANE_MODEL_TIMEOUT_MIN" ] || attempt_cap="$LANE_MODEL_TIMEOUT_MIN"
      # THE FLOOR MUST NOT LET THE WHOLE RUN EXCEED THE BUDGET IT ADVERTISES. On any budget that can
      # accommodate the floor this is a NO-OP, because remaining/attempts_left is already <=
      # remaining; it only bites on the degenerate configuration the warning above names.
      # Never clamp below 1s: `timeout 0` DISABLES the timeout, which is the last thing a spent
      # budget should do.
      [ "$attempt_cap" -gt "$remaining" ] && attempt_cap="$remaining"
      [ "$attempt_cap" -ge 1 ] || attempt_cap=1
      echo "--- attempt ${attempt}/${total}: ${model}; cap=${attempt_cap}s (budget ${LANE_MODEL_BUDGET}s, ${attempts_left} attempts left) ---"
    fi
    # shellcheck disable=SC2086
    timeout --signal=TERM --kill-after=60 "$attempt_cap" \
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
    case "$rc" in
      124|137|143)
        saw_timeout=1
        echo "    timeout: attempt was cut by its ${attempt_cap}s cap"
        ;;
      *)
        if lane_model_unavailable "$log"; then
          saw_unavailable=1
          echo "    unavailable signature:"
          lane_model_unavailable_line "$log" | head -3
        else
          echo "    (failed without an unavailability signature - treating as a crash)"
        fi
        ;;
    esac
  done

  # Distinguish the two very different outcomes. This line used to assert "was unavailable"
  # unconditionally, even when every attempt above had printed "treating as a crash". A reader
  # duly reported a quota story to the owner while the log contradicted it, and the real cause
  # (a SIGTERM, exit=143, zero-byte logs) was nearly missed. A verdict that contradicts its own
  # evidence is worse than no verdict.
  if [ "$saw_timeout" -eq 1 ]; then
    echo "=== MODEL THAT COMPLETED: none — one or more attempts hit their timeout ==="
    echo "=== This is NOT a quota result — read the timeout and exit details above. ==="
  elif [ "$saw_unavailable" -eq 1 ]; then
    echo "=== MODEL THAT COMPLETED: none — every model in '${models}' was unavailable ==="
  else
    echo "=== MODEL THAT COMPLETED: none — NO unavailability signature seen. Every attempt ==="
    echo "=== CRASHED or was KILLED. This is NOT a quota result — read the exit codes above. ==="
  fi
  return 1
}
