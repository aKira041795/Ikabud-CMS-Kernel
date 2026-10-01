#!/usr/bin/env bash
#
# tools/lane-watch.sh — a ONE-SHOT watcher that arms the harness's own notification.
#
# Why one-shot matters: an async terminal command notifies the agent only when the
# command FINISHES. A waiter that loops forever never finishes, so it can never notify.
# That is why "the harness will tell me" was false in practice. This script blocks
# until the NEXT lane landing, then reports it and EXITS - and exiting is what makes
# the completion notification fire.
#
# It also sends a desktop notification, so the owner learns about a landing directly
# rather than having to ask whether one happened.
#
# Usage:
#   tools/lane-watch.sh              # wait for the next landing, then exit
#   tools/lane-watch.sh --timeout=N  # give up after N seconds (default 3600)
#
# Intended invocation: run it in an ASYNC terminal at the start of a work session.
# Every land-on-completion re-arms it; the pattern is disarm -> notify -> re-arm.
#
set -u

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT" || exit 1
RUNS="$ROOT/.ai/runs"
mkdir -p "$RUNS"

# IMPORTANT: a command notifies the agent only when it FINISHES, and a terminal wrapper
# caps a command at ~120s. So the default timeout here is deliberately BELOW that cap.
# The intended loop is:
#     arm `lane-watch.sh`  ->  it exits on landing OR on timeout  ->  that waking is the
#     notification  ->  read the marker, report, re-arm.
# With the default below, a landing is caught within ~90s without anyone asking.
#
# A watcher that runs forever can NEVER notify: it never finishes. That was the flaw in
# every previous attempt, including one that wrote to a file nobody read.
timeoutSecs=90
for arg in "$@"; do
  case "$arg" in
    --timeout=*) timeoutSecs="${arg#*=}";;
    --forever)   timeoutSecs=86400;;
  esac
done

iso() { date -Iseconds; }

notify() {
  local title="$1" body="$2" urgency="$3"
  if command -v notify-send > /dev/null 2>&1; then
    notify-send -u "$urgency" -a "lane-watch" "$title" "$body" > /dev/null 2>&1 || true
  fi
}

# A marker's identity is its FILE IDENTITY (inode/nanosecond mtime/size, falling back
# to content hash), not its filename. A lane name can be reused, which atomically
# replaces the marker at the same path; keying on the filename alone made the watcher
# ignore the replacement forever and miss the landing.
marker_sig() {
  stat -c '%i-%y-%s' "$1" 2>/dev/null || md5sum "$1" 2>/dev/null | cut -d' ' -f1
}

# Snapshot the markers already on disk, so we only react to a NEW landing.
declare -A seen=()
shopt -s nullglob
for m in "$RUNS"/*.landed.json; do
  seen["$(basename "$m")"]="$(marker_sig "$m")"
done
shopt -u nullglob

echo "lane-watch armed at $(iso) - waiting for the next landing (timeout ${timeoutSecs}s)"
echo "   markers currently on disk: ${#seen[@]}"

waited=0
while true; do
  shopt -s nullglob
  for m in "$RUNS"/*.landed.json; do
    b="$(basename "$m")"
    # Skip only if both the name is known AND the file identity is unchanged. A
    # same-named replacement marker has a new identity and is therefore a landing.
    [ -n "${seen[$b]:-}" ] && [ "${seen[$b]}" = "$(marker_sig "$m")" ] && continue

    # new landing. Give the writer a moment to finish moving the marker into place.
    sleep 1
    name="${b%.landed.json}"
    reason=$(grep -o '"reason": "[^"]*"' "$m" 2>/dev/null | cut -d'"' -f4)
    status=$(grep -o '"status_line": "[^"]*"' "$m" 2>/dev/null | cut -d'"' -f4)
    landed=$(grep -o '"landed_at": "[^"]*"' "$m" 2>/dev/null | cut -d'"' -f4)
    changed=$(grep -o '"changed_files": [0-9]*' "$m" 2>/dev/null | cut -d' ' -f2)

    urgency="normal"
    case "$reason" in quota|fatal|empty) urgency="critical";; esac

    echo
    echo "== LANDING DETECTED =="
    echo "   lane:          $name"
    echo "   reason:        ${reason:-unknown}"
    echo "   landed_at:     ${landed:-unknown}"
    echo "   status:        ${status:-<none>}"
    echo "   changed files: ${changed:-unknown}"
    echo "   log:           $RUNS/$name.log"

    notify "lane $name: ${reason:-unknown}" "${status:-no status line}" "$urgency"
    echo "   notified the desktop."
    echo
    echo "watch exiting so the harness raises a completion notification - re-arm it."
    exit 0
  done
  shopt -u nullglob

  sleep 5
  waited=$((waited+5))
  if [ "$waited" -ge "$timeoutSecs" ]; then
    # A HEARTBEAT, not a failure. Its whole purpose is to finish so the agent is woken and
    # can re-arm. Say so plainly, and say what is still running.
    echo "lane-watch: no landing yet after ${timeoutSecs}s"
    running=$(pgrep -af "bash /tmp/lane-.*\\.sh" 2>/dev/null | grep -cv lean-ctx || true)
    echo "   lanes still running: ${running:-0}"
    if [ "${running:-0}" = "0" ]; then
      echo "   NOTE: no lane process is running - a landing may have been recorded without a"
      echo "   marker (old-style dispatch). Check .ai/runs/*.log and git status."
    fi
    echo "   RE-ARM:  bash tools/lane-watch.sh      (or --forever to leave it detached)"
    exit 0
  fi
done
