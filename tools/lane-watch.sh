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
# WHY IT READS A JOURNAL AND NOT THE MARKER FILES: it used to snapshot which
# `.landed.json` files existed when it armed, and skip everything in that snapshot. That
# treats "the file was already there" as "someone was already told" - a proxy for
# acknowledgement - so every landing that happened while the watcher was disarmed was
# swallowed permanently. Since the watcher exits on every landing AND on every heartbeat,
# it is disarmed most of the time, which made the miss the normal path rather than an
# edge case. It now reads an append-only journal through a cursor that records what was
# actually reported, so a landing that occurs while disarmed is still reported next arm.
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

JOURNAL="$RUNS/landings.jsonl"
CURSOR="$RUNS/.reported.cursor"
[ -f "$JOURNAL" ] || : > "$JOURNAL"

journal_total() { [ -f "$JOURNAL" ] && wc -l < "$JOURNAL" 2>/dev/null || echo 0; }

# Pull one field out of a compact journal line. The journal is written by the process
# that held the lane's exit status, so this is the authoritative record - the watcher
# must not re-derive a verdict of its own.
jfield() { printf '%s' "$1" | grep -o "\"$2\":\"[^\"]*\"" | head -1 | cut -d'"' -f4; }

# The cursor records WHICH LANDINGS HAVE BEEN REPORTED. Consumption is recorded, never
# inferred from which files happen to be on disk.
total=$(journal_total)
if [ ! -f "$CURSOR" ]; then
  # First ever run: adopt the existing history instead of replaying it, and say so
  # rather than doing it silently.
  printf '%s' "$total" > "$CURSOR"
  echo "   cursor initialised at $total (existing history is not replayed)"
fi
cursor=$(cat "$CURSOR" 2>/dev/null || echo 0)
case "$cursor" in ''|*[!0-9]*) cursor=0;; esac
if [ "$cursor" -gt "$total" ]; then cursor=$total; fi

echo "lane-watch armed at $(iso) - waiting for a landing (timeout ${timeoutSecs}s)"
echo "   journal: $total entries, $((total - cursor)) not yet reported"

waited=0
while true; do
  total=$(journal_total)
  if [ "$total" -gt "$cursor" ]; then
    echo
    echo "== LANDING(S) DETECTED: $((total - cursor)) =="
    # Report EVERY unreported landing in one exit, so N landings cost one wake, not N.
    while [ "$cursor" -lt "$total" ]; do
      line=$(sed -n "$((cursor+1))p" "$JOURNAL")
      cursor=$((cursor+1))
      [ -n "$line" ] || continue

      name=$(jfield "$line" name)
      state=$(jfield "$line" state)
      reason=$(jfield "$line" reason)
      status=$(jfield "$line" status_line)
      landed=$(jfield "$line" landed_at)
      changed=$(printf '%s' "$line" | grep -o '"changed_files":[0-9]*' | cut -d: -f2)

      urgency="normal"
      [ "$state" = "unverified" ] && urgency="critical"
      case "$reason" in quota|fatal|empty|crash|timeout) urgency="critical";; esac

      echo
      echo "   --- landing ---"
      echo "   lane:          ${name:-unknown}"
      echo "   state:         ${state:-unknown}"
      echo "   reason:        ${reason:-unknown}"
      echo "   landed_at:     ${landed:-unknown}"
      echo "   status:        ${status:-<none>}"
      echo "   changed files: ${changed:-unknown}"
      echo "   log:           $RUNS/$name.log"
      if [ "$state" = "unverified" ]; then
        echo "   NOTE: not verified - the lane never recorded an exit status."
      fi

      notify "lane $name: ${reason:-unknown}" "${status:-no status line}" "$urgency"
    done
    # Advance ONLY now, having actually reported them. If anything above failed the
    # cursor is untouched and the landings are reported again rather than lost: the
    # failure direction must be over-reporting, never under-reporting.
    printf '%s' "$cursor" > "$CURSOR"
    echo
    echo "watch exiting so the harness raises a completion notification - re-arm it."
    exit 0
  fi

  sleep 3
  waited=$((waited+3))
  if [ "$waited" -ge "$timeoutSecs" ]; then
    # A HEARTBEAT, not a failure. Its whole purpose is to finish so the agent is woken and
    # can re-arm. Say plainly that nothing landed.
    echo "lane-watch: no landing yet after ${timeoutSecs}s (journal at $total entries)"
    echo "   RE-ARM:  bash tools/lane-watch.sh      (or --forever to leave it detached)"
    exit 0
  fi
done
