#!/usr/bin/env bash
#
# tools/model-availability.sh — remember which models are exhausted, and for how long.
#
# WHY THIS EXISTS
#
# lane-model.sh already falls back when a model is unavailable, and lane.sh already NAMES a quota
# death (`reason=quota`). Both are reactive and both forget: nothing is persisted, so every new
# dispatch rediscovers exhaustion by burning an attempt. Measured 2026-10-07: lane `st13b` retried
# 8 times between 09:57 and 10:37 — every one `reason=quota`, `exit=1`, `files=0`. Eight paid round
# trips to learn one fact that was already known after the first.
#
# WHAT IT CAN AND CANNOT KNOW
#
# The provider does NOT state a reset time. The complete failure text recorded from Codex is:
#
#     rate limit exceeded
#
# 131 bytes, no window, no "try again at". So a reset time cannot be READ. Claiming one would be
# inventing a number. Instead the window is LEARNED: record when a model went down and when it next
# came back, and use that observation as the cooldown next time. If a provider ever does state a
# reset, parse it in preference to the learned window.
#
# FAIL-OPEN BY DESIGN
#
# A ledger that cannot be read must never stop dispatch. Every function degrades to "available".
# The cost of a stale ledger is one wasted attempt; the cost of a broken ledger is a stalled program.
#
# Usage:
#   source tools/model-availability.sh
#   model_is_available "openai-codex/gpt-5.6-sol"   # 0 = usable, 1 = inside cooldown
#   model_mark_exhausted "openai-codex/gpt-5.6-sol" "rate limit exceeded"
#   model_mark_ok "openai-codex/gpt-5.6-sol"
#   model_availability_line                          # one-line report for the chair
#

_MODEL_AVAIL_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
MODEL_AVAIL_LEDGER="${MODEL_AVAIL_LEDGER:-$(dirname "$_MODEL_AVAIL_DIR")/.ai/runs/model-availability.json}"

# Default cooldown when nothing has been learned yet, and the sane bounds on a learned window.
MODEL_AVAIL_DEFAULT_COOLDOWN="${MODEL_AVAIL_DEFAULT_COOLDOWN:-900}"     # 15 min
MODEL_AVAIL_MIN_WINDOW="${MODEL_AVAIL_MIN_WINDOW:-300}"                 # 5 min floor
MODEL_AVAIL_MAX_WINDOW="${MODEL_AVAIL_MAX_WINDOW:-21600}"               # 6 h ceiling

# PHP does the JSON work: it is guaranteed present (the whole product is PHP) and jq is not.
#
# ALL extra arguments are forwarded. An earlier version forwarded exactly three, which silently
# dropped the 5th argument — the failure REASON — so every recorded entry lost the one field that
# tells the next person why the model went down.
_model_avail_php() {
  local code="$1"
  shift
  php -r "$code" -- "$MODEL_AVAIL_LEDGER" "$@" 2>/dev/null
}

_model_avail_ensure_dir() {
  local dir
  dir="$(dirname "$MODEL_AVAIL_LEDGER")"
  [ -d "$dir" ] || mkdir -p "$dir" 2>/dev/null || true
}

# Reset time stated by a provider, if any. Returns ISO-8601 local, or empty.
#
# Kept deliberately narrow: it accepts only unambiguous absolute timestamps. A relative "in 20
# minutes" is NOT parsed here — turning it into an absolute time needs a clock read and a rounding
# decision, and a silently wrong reset is worse than a learned one.
model_stated_reset_at() {
  local line="${1:-}"
  [ -n "$line" ] || return 1
  printf '%s' "$line" | grep -oiE '(reset|available|retry|try again)[^0-9]{0,20}([0-9]{4}-[0-9]{2}-[0-9]{2}[T ][0-9]{2}:[0-9]{2})' \
    | grep -oE '[0-9]{4}-[0-9]{2}-[0-9]{2}[T ][0-9]{2}:[0-9]{2}' \
    | head -1
}

# 0 = usable, 1 = inside a recorded cooldown.
model_is_available() {
  local model="${1:-}"
  [ -n "$model" ] || return 0
  [ -f "$MODEL_AVAIL_LEDGER" ] || return 0
  _model_avail_php '
    $ledger = json_decode((string)@file_get_contents($argv[1]), true);
    if (!is_array($ledger) || !isset($ledger["models"][$argv[2]])) { exit(0); }
    $entry = $ledger["models"][$argv[2]];
    if (($entry["state"] ?? "") !== "exhausted") { exit(0); }
    $reset = (string)($entry["reset_at"] ?? "");
    if ($reset === "") { exit(0); }
    $ts = strtotime($reset);
    if ($ts === false) { exit(0); }
    exit($ts > time() ? 1 : 0);
  ' "$model"
}

# The recorded reset time for a model, as local HH:MM, or empty.
#
# The timezone is applied by BASH, not PHP. PHP's default zone here is UTC while the stored value
# carries a local offset, so `date("H:i")` inside PHP rendered a 16:28 reset as "08:28" - a hint
# that is confidently wrong is worse than none, because the reader trusts it.
model_reset_hint() {
  local model="${1:-}" iso
  [ -f "$MODEL_AVAIL_LEDGER" ] || return 0
  iso="$(_model_avail_php '
    $ledger = json_decode((string)@file_get_contents($argv[1]), true);
    $entry = is_array($ledger) ? ($ledger["models"][$argv[2]] ?? null) : null;
    if (!is_array($entry) || ($entry["state"] ?? "") !== "exhausted") { exit(0); }
    $ts = strtotime((string)($entry["reset_at"] ?? ""));
    if ($ts !== false && $ts > time()) { echo (string)$entry["reset_at"]; }
  ' "$model")"
  [ -n "$iso" ] || return 0
  date -d "$iso" +%H:%M 2>/dev/null || printf '%s' "$iso"
}

# Record that a model worked. Also LEARNS the recovery window from the previous outage, so the
# cooldown converges on the provider's real behaviour instead of a hard-coded guess.
model_mark_ok() {
  local model="${1:-}" now
  [ -n "$model" ] || return 0
  now="$(date -Iseconds)"
  _model_avail_ensure_dir
  _model_avail_php '
    $path = $argv[1]; $model = $argv[2]; $now = $argv[3];
    $ledger = json_decode((string)@file_get_contents($path), true);
    if (!is_array($ledger)) { $ledger = ["models" => []]; }
    $entry = $ledger["models"][$model] ?? [];
    $min = (int)getenv("MODEL_AVAIL_MIN_WINDOW") ?: 300;
    $max = (int)getenv("MODEL_AVAIL_MAX_WINDOW") ?: 21600;
    if (($entry["state"] ?? "") === "exhausted" && !empty($entry["last_exhausted_at"])) {
      $down = strtotime((string)$entry["last_exhausted_at"]);
      $up = strtotime($now);
      // >=, not >: a failure and recovery inside the same second is unusual but real (a lane
      // refused at dispatch then retried immediately). Treating it as "no observation" would
      // silently discard it; the floor clamp below is what keeps it from becoming a tiny window.
      if ($down !== false && $up !== false && $up >= $down) {
        $entry["observed_window_seconds"] = max($min, min($max, $up - $down));
      }
    }
    $entry["state"] = "ok";
    $entry["last_ok_at"] = $now;
    $entry["reset_at"] = "";
    unset($entry["reason"]);
    $ledger["models"][$model] = $entry;
    $ledger["updated_at"] = $now;
    @file_put_contents($path, json_encode($ledger, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
  ' "$model" "$now"
}

# Record that a model is exhausted, and compute when to try it again.
model_mark_exhausted() {
  local model="${1:-}" reason="${2:-}" now stated reset
  [ -n "$model" ] || return 0
  now="$(date -Iseconds)"
  stated="$(model_stated_reset_at "$reason" || true)"
  if [ -n "$stated" ]; then
    reset="$stated"
  else
    # No provider statement: use the learned window, else the default cooldown.
    local window
    window="$(_model_avail_php '
      $ledger = json_decode((string)@file_get_contents($argv[1]), true);
      $entry = is_array($ledger) ? ($ledger["models"][$argv[2]] ?? null) : null;
      $w = is_array($entry) ? (int)($entry["observed_window_seconds"] ?? 0) : 0;
      echo $w > 0 ? $w : (int)(getenv("MODEL_AVAIL_DEFAULT_COOLDOWN") ?: 900);
    ' "$model")"
    [ -n "$window" ] || window="$MODEL_AVAIL_DEFAULT_COOLDOWN"
    reset="$(date -Iseconds -d "+${window} seconds" 2>/dev/null || date -Iseconds)"
  fi

  _model_avail_ensure_dir
  _model_avail_php '
    $path = $argv[1]; $model = $argv[2]; $now = $argv[3]; $reset = $argv[4];
    $reason = isset($argv[5]) ? $argv[5] : "";
    $ledger = json_decode((string)@file_get_contents($path), true);
    if (!is_array($ledger)) { $ledger = ["models" => []]; }
    $entry = $ledger["models"][$model] ?? [];
    $entry["state"] = "exhausted";
    $entry["last_exhausted_at"] = $now;
    $entry["reset_at"] = $reset;
    $entry["reason"] = mb_substr(trim(preg_replace("/\s+/", " ", $reason)), 0, 200);
    $entry["last_ok_at"] = $entry["last_ok_at"] ?? "";
    $ledger["models"][$model] = $entry;
    $ledger["updated_at"] = $now;
    @file_put_contents($path, json_encode($ledger, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
  ' "$model" "$now" "$reset" "$reason"
}

# One-line report, for the chair to see without asking. Empty string when the ledger knows nothing.
#
# PHP emits `<short-name>|<reset-iso>|<seconds>` records and BASH formats the clock, for the same
# timezone reason as model_reset_hint.
model_availability_line() {
  [ -f "$MODEL_AVAIL_LEDGER" ] || return 0
  local records line="" name iso secs mins
  records="$(_model_avail_php '
    $ledger = json_decode((string)@file_get_contents($argv[1]), true);
    if (!is_array($ledger) || empty($ledger["models"])) { exit(0); }
    $now = time();
    foreach ($ledger["models"] as $model => $entry) {
      if (($entry["state"] ?? "") !== "exhausted") { continue; }
      $ts = strtotime((string)($entry["reset_at"] ?? ""));
      $short = preg_replace("#^.*/#", "", $model);
      if ($ts !== false && $ts > $now) {
        echo $short, "|", $entry["reset_at"], "|", ($ts - $now), "\n";
      } else {
        echo $short, "||0\n";
      }
    }
  ')"
  [ -n "$records" ] || return 0
  while IFS='|' read -r name iso secs; do
    [ -n "$name" ] || continue
    if [ -n "$iso" ]; then
      mins=$(( (secs + 59) / 60 ))
      line="${line:+$line; }${name} exhausted until $(date -d "$iso" +%H:%M 2>/dev/null || printf '%s' "$iso") (${mins}m)"
    else
      line="${line:+$line; }${name} exhausted, cooldown elapsed - retry eligible"
    fi
  done <<< "$records"
  [ -n "$line" ] && printf 'models: %s\n' "$line"
}
