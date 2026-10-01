#!/usr/bin/env bash
#
# tools/lane.sh — dispatch a lane and RELIABLY detect when it lands.
#
# Why this exists: lanes were dispatched with a detached waiter writing to
# /tmp/<lane>-wait.out. Nothing ever read that file, so a landed lane went unnoticed
# until a human asked "and?". Two lanes landed that way on 2026-10-01.
#
# The fix is twofold:
#   1. `run` BLOCKS until the lane is genuinely gone, then prints a landing record.
#      Invoke it with the terminal in ASYNC mode and the harness notifies the agent
#      on completion. Detection becomes push, not poll.
#   2. The landing record is written to .ai/runs/<name>.landed.json as an atomic
#      marker, so detection never depends on re-reading a log by hand.
#
# Detection signals, in order of trust:
#   1. a CONTENT SENTINEL written by the generated runner when the lane exits
#      (`__LANE_EXIT_CODE__=<n>`). This is primary: it needs no process lookup, cannot
#      be confused by pid reuse, and carries the real exit status.
#   2. fallback only - the lane process is gone AND the log has stopped growing.
#   3. last resort - classify WHY it ended from the log content (report / quota / nothing).
#
# Do NOT use `kill -0` on a wrapper pid to decide landing. It tests the shell, not the
# work, and cannot see a reused or unreaped pid. Do NOT pgrep for the lane script name
# from the caller either: the caller's own command line contains that path, so the
# pattern matches itself and the wait never ends.
#
# Usage:
#   tools/lane.sh run    <name> <lane-script> [--timeout=7200] [--require-clean]
#   tools/lane.sh status <name>
#   tools/lane.sh list
#   tools/lane.sh record <name> <log> <exit-code>   # called by the generated runner
#
# IMPORTANT: `run` blocks, and a terminal wrapper may cap a command at ~120s. For a lane
# that runs longer than that, run `run` DETACHED (nohup ... &) - the generated runner
# records the landing and notifies by itself, so nothing is lost if the monitor dies.
# Additionally arm `tools/lane-watch.sh` (one-shot) to be notified of the next landing.
#
set -u

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT" || exit 1

RUNS="$ROOT/.ai/runs"
mkdir -p "$RUNS"

iso() { date -Iseconds; }

# Report a landing where a HUMAN sees it, without anyone having to ask an agent.
# notify-send is available on this box (DISPLAY + DBUS set). Failure is non-fatal:
# a notification that cannot be shown must never stop the landing record existing.
notify_landing() {
  local name="$1" reason="$2" summary="$3"
  local urgency="normal"
  case "$reason" in
    quota|fatal|empty|crash) urgency="critical";;
  esac
  if command -v notify-send > /dev/null 2>&1; then
    notify-send -u "$urgency" -a "lane" \
      "lane $name: $reason" "${summary:-no status line}" > /dev/null 2>&1 || true
  fi
  # one glanceable line for every landing, newest last
  printf '%s  %-24s %-15s %s\n' "$(iso)" "$name" "$reason" "${summary:-}" \
    >> "$RUNS/LANDINGS.log" 2>/dev/null || true
}

# ── classify why a lane ended, from its log content ────────────────────────────
# echoes one of: report_present | quota | fatal | empty | unknown
# echoes one of: report_present | quota | crash | fatal | empty | unknown
#
# $2 is the lane's real process exit status, when known. A non-zero exit is NEVER
# a successful report: a lane that prints "status: PASS" and then exits 7 used to be
# classified report_present, which certified a crash as a landing.
classify_log() {
  local log="$1" rc="${2:-}"
  [ -f "$log" ] || { echo "empty"; return; }
  local bytes
  bytes=$(wc -c < "$log" 2>/dev/null || echo 0)
  if [ "$bytes" -eq 0 ]; then echo "empty"; return; fi
  if grep -qiE "usage limit has been reached|quota|rate limit" "$log" 2>/dev/null; then
    echo "quota"; return
  fi
  # A non-zero process exit (including timeout's 124) is a crash, whatever the log says.
  if [ -n "$rc" ] && [ "$rc" != "0" ]; then
    echo "crash"; return
  fi
  if grep -qE "^status:|^\*\*Status:|\*\*Status:" "$log" 2>/dev/null; then
    echo "report_present"; return
  fi
  if printf '%s' "$(head -3 "$log" 2>/dev/null)" | grep -qiE "error|fatal"; then
    echo "fatal"; return
  fi
  echo "unknown"
}

# extract the status line wherever the lane chose to put it
# NOTE: `script` drives a raw pty, so every line carries a trailing CR. Leaving it in
# made the landing marker INVALID JSON (unescaped control character), which would break
# any automated consumer - the marker's entire purpose. Strip CR and any other control
# character, not just CR.
status_line() {
  local log="$1"
  grep -m1 -E "^status:|Status:" "$log" 2>/dev/null \
    | head -1 \
    | tr -d '\r' \
    | tr -d '\000-\010\013-\037\177' \
    | cut -c1-160
}

# Escape a value for use inside a JSON string literal. JSON forbids raw control
# characters and requires backslash and double-quote to be escaped. The lane NAME
# used to be interpolated raw, so a name containing a quote produced invalid JSON.
json_escape() {
  printf '%s' "$1" | tr -d '\r' | tr -d '\000-\037\177' | sed 's/\\/\\\\/g; s/"/\\"/g'
}

write_marker() {
  local name="$1" reason="$2" log="$3" bytes="$4" mtime="$5" status="$6" changed="$7" exitCode="${8:-}"
  local marker="$RUNS/$name.landed.json"
  local tmp="$marker.tmp"
  # sanitise every free-text field for JSON: no CR/control chars, quotes/backslashes escaped
  name=$(json_escape "$name")
  status=$(json_escape "$status")
  {
    printf '{\n'
    printf '  "name": "%s",\n' "$name"
    printf '  "landed_at": "%s",\n' "$(iso)"
    printf '  "reason": "%s",\n' "$reason"
    if [ -n "$exitCode" ] && [ "$exitCode" -eq "$exitCode" ] 2>/dev/null; then
      printf '  "exit_code": %s,\n' "$exitCode"
    else
      printf '  "exit_code": null,\n'
    fi
    printf '  "log_bytes": %s,\n' "${bytes:-0}"
    printf '  "log_mtime": "%s",\n' "${mtime:-}"
    printf '  "status_line": "%s",\n' "$status"
    printf '  "changed_files": %s,\n' "${changed:-0}"
    printf '  "detected_by": "tools/lane.sh run"\n'
    printf '}\n'
  } > "$tmp"
  mv "$tmp" "$marker"          # atomic: a reader never sees a half-written marker
}

cmd_run() {
  local name="${1:-}"; shift || true
  local laneScript="${1:-}"; shift || true
  local timeoutSecs=7200
  local requireClean=0
  for arg in "$@"; do
    case "$arg" in
      --timeout=*) timeoutSecs="${arg#*=}";;
      --require-clean) requireClean=1;;
    esac
  done

  [ -n "$name" ] && [ -n "$laneScript" ] || {
    echo "usage: tools/lane.sh run <name> <lane-script> [--timeout=N] [--require-clean]" >&2
    exit 2
  }

  if [ "$requireClean" = "1" ]; then
    local dirty
    dirty=$(git status --porcelain | wc -l)
    if [ "$dirty" != "0" ]; then
      echo "REFUSING: tree not clean ($dirty entries). Commit or revert first."
      git status --porcelain | head -20
      exit 1
    fi
  fi

  local log="$RUNS/$name.log"
  rm -f "$log" "$RUNS/$name.landed.json" "$RUNS/$name.pid"

  # Scope detection to THIS lane script. A bare "lane-.*\.sh" pattern matches any
  # concurrent lane, so two lanes running at once would confuse each other's detection.
  # The [x] bracket trick stops pgrep matching its own command line.
  local base pat
  base=$(basename "$laneScript")
  pat="[${base:0:1}]${base:1}"

  echo "== dispatching $name =="
  # A CONTENT SENTINEL, not process matching. v1 of this script used
  # pgrep -f "[l]ane-<name>.sh" and it matched the CALLER's own command line (which
  # contains the lane script path), so it always believed the lane was still running
  # and waited the full timeout.
  #
  # The sentinel lives in a generated RUNNER FILE rather than inline. Inline, the
  # capture is expanded by the wrong shell: it took four layers (outer bash -> inner
  # bash -c -> script -> sh -c) and the exit status was expanded while BUILDING an
  # argument, so every lane reported "lane exit: 0" - including one that crashed.
  # A crash reported as 0 is a false green, worse than no signal. In a file, the shell
  # that runs the lane expands it itself, which is the only correct place.
  local sentinel="__LANE_EXIT_CODE__"
  local runner="$RUNS/$name.runner.sh"
  local absRoot
  absRoot="$ROOT"
  # The runner RECORDS ITSELF. This is the fix for the deepest failure here: the marker
  # and the notification used to live in the monitoring process, so when the monitor was
  # killed (the terminal wrapper caps a command at 120s) the lane landed in silence -
  # exactly the complaint this tool exists to remove. The lane's outcome must not depend
  # on anyone watching it.
  {
    printf '#!/usr/bin/env bash\n'
    printf 'cd %q || exit 1\n' "$absRoot"
    printf 'bash %q\n' "$laneScript"
    printf 'rc=$?\n'
    printf 'bash %q record %q %q "$rc"\n' "$absRoot/tools/lane.sh" "$name" "$RUNS/$name.log"
    printf 'echo "%s=$rc"\n' "$sentinel"
  } > "$runner"
  chmod +x "$runner"

  # Quote the runner path for the shell `script -c` will use. The old form
  # `bash -c "... script -qec \"bash $runner\" ..."` re-parsed a lane name containing
  # a quote, space or `$` as shell syntax, so such a lane never ran (and a `$(...)`
  # name could inject commands). Build the `script -c` command with printf %q and
  # invoke it directly, with no intervening `bash -c` string to re-parse.
  local scriptCmd
  printf -v scriptCmd 'bash %q' "$runner"
  setsid timeout --signal=TERM --kill-after=60 "$timeoutSecs" script -qec "$scriptCmd" /dev/null \
    > "$log" 2>&1 < /dev/null &
  local wrapperPid=$!
  disown 2>/dev/null || true

  # Identify the REAL lane process, not the wrapper.
  sleep 3
  local realPid=""
  realPid=$(pgrep -f "$pat" | tail -1 || true)
  [ -n "$realPid" ] && echo "$realPid" > "$RUNS/$name.pid"
  echo "   wrapper=$wrapperPid  lane=${realPid:-unknown}"
  echo "   log=$log"
  echo "   watching: $pat"

  # BLOCK until the lane is genuinely gone. Primary signal: the sentinel in the log.
  # Fallback: the process is gone AND the log has stopped growing.
  local waited=0 lastSize=0 stable=0 graceAfterGone=0
  while true; do
    if grep -q "$sentinel" "$log" 2>/dev/null; then
      break
    fi
    sleep 3
    waited=$((waited+3))

    # secondary: gone + log stable for two consecutive samples
    local alive nowSize
    alive=$(pgrep -f "$pat" 2>/dev/null | grep -vx -e "$$" -e "$PPID" || true)
    nowSize=$(wc -c < "$log" 2>/dev/null || echo 0)
    if [ -z "$alive" ]; then
      if [ "$nowSize" = "$lastSize" ]; then
        stable=$((stable+1))
      else
        stable=0
      fi
      graceAfterGone=$((graceAfterGone+3))
      # only trust the fallback well after the process vanished
      if [ "$stable" -ge 2 ] && [ "$graceAfterGone" -ge 9 ]; then
        echo "   note: no sentinel found; fell back to process+log-stability detection"
        break
      fi
    else
      stable=0
    fi
    lastSize="$nowSize"

    if [ "$waited" -gt "$timeoutSecs" ]; then
      echo "   !! watchdog: lane exceeded ${timeoutSecs}s, not waiting further"
      break
    fi
  done

  local bytes mtime reason status changed exitCode
  bytes=$(wc -c < "$log" 2>/dev/null || echo 0)
  mtime=$(date -r "$log" -Iseconds 2>/dev/null || echo "")
  exitCode=$(grep -o "${sentinel}=[0-9]*" "$log" 2>/dev/null | tail -1 | cut -d= -f2 || echo "")
  # If the sentinel never reached the log but the runner still recorded a marker,
  # take the exit status from the marker rather than losing it.
  if [ -z "$exitCode" ] && [ -f "$RUNS/$name.landed.json" ]; then
    exitCode=$(grep -o '"exit_code": [0-9]*' "$RUNS/$name.landed.json" 2>/dev/null | grep -o '[0-9]*' | tail -1 || echo "")
  fi
  reason=$(classify_log "$log" "$exitCode")
  status=$(status_line "$log")
  changed=$(git status --porcelain 2>/dev/null | wc -l)

  # The RUNNER owns the record - it writes the marker and notifies on exit, so the
  # outcome survives this monitor being killed. Only record here as a FALLBACK, when no
  # marker exists (the runner died before it could record). Recording unconditionally
  # produced two desktop notifications per landing.
  if [ -f "$RUNS/$name.landed.json" ]; then
    echo "   (marker already written by the runner - not re-recording)"
  else
    write_marker "$name" "$reason" "$log" "$bytes" "$mtime" "$status" "$changed" "$exitCode"
    notify_landing "$name" "$reason" "${status:-no status line | exit=${exitCode} | files=${changed}}"
  fi

  echo
  echo "== $name LANDED =="
  echo "   at:            $(iso)"
  echo "   reason:        $reason"
  echo "   log bytes:     $bytes"
  echo "   log mtime:     $mtime"
  echo "   changed files: $changed"
  echo "   lane exit:     ${exitCode:-unknown}"
  echo "   status:        ${status:-<none found>}"
  echo "   --- first 3 lines ---"
  head -3 "$log" 2>/dev/null | sed 's/^/   /'
  echo "   marker: $RUNS/$name.landed.json"

  case "$reason" in
    report_present) echo "   VERDICT: landed with a report - verify it, do not trust it";;
    quota)          echo "   VERDICT: CRASHED on quota - partial edits may exist, check the tree";;
    crash)          echo "   VERDICT: CRASHED (non-zero exit) - partial edits may exist, check the tree";;
    fatal)          echo "   VERDICT: CRASHED - partial edits may exist, check the tree";;
    empty)          echo "   VERDICT: CRASHED with no output - nothing landed";;
    *)              echo "   VERDICT: ended without a recognisable status line - read the log";;
  esac

  # Tell the human, wherever they are. This is the part that was missing: detection
  # that only reaches an agent turn is useless when the agent's turn has ended.
  notify_landing "$name" "$reason" "${status:-no status line | exit=${exitCode:-?} | files=${changed}}"
  echo "   notified: desktop + $RUNS/LANDINGS.log"

  # The lane's real exit status must reach the command's own exit status, or a
  # crashing lane is reported as a successful landing by the shell return code.
  if [ -n "$exitCode" ] && [ "$exitCode" -eq "$exitCode" ] 2>/dev/null; then
    return "$exitCode"
  fi
  return 1
}

cmd_status() {
  local name="${1:-}"
  [ -n "$name" ] || { echo "usage: tools/lane.sh status <name>" >&2; exit 2; }
  local log="$RUNS/$name.log"
  local marker="$RUNS/$name.landed.json"

  echo "== $name =="
  local pat
  pat="[l]ane-.*\.sh"
  if pgrep -f "$pat" > /dev/null 2>&1; then
    echo "   state:    SOME lane is RUNNING (name-scoped detection needs 'run')"
    echo "   log:      $(wc -c < "$log" 2>/dev/null || echo 0) bytes"
    echo "   mtime:    $(date -r "$log" -Iseconds 2>/dev/null || echo '-')"
    echo "   (a running lane writes nothing until it exits - that is normal, not a hang)"
    return
  fi
  if [ -f "$marker" ]; then
    echo "   state:    LANDED"
    sed 's/^/   /' "$marker"
    return
  fi
  echo "   state:    not running, no landing marker"
  echo "   log:      $(wc -c < "$log" 2>/dev/null || echo 0) bytes"
}

cmd_list() {
  echo "== recent landings =="
  for m in "$RUNS"/*.landed.json; do
    [ -f "$m" ] || continue
    local name
    name=$(basename "$m" .landed.json)
    local reason landed
    reason=$(grep -o '"reason": "[^"]*"' "$m" | cut -d'"' -f4)
    landed=$(grep -o '"landed_at": "[^"]*"' "$m" | cut -d'"' -f4)
    printf '   %-28s %-16s %s\n' "$name" "$reason" "$landed"
  done
}

# Record a landing. Called by the generated runner, so it happens even if every
# monitor has been killed. Safe to call twice: it simply rewrites the same record.
cmd_record() {
  local name="${1:-}" log="${2:-}" exitCode="${3:-}"
  [ -n "$name" ] || { echo "usage: tools/lane.sh record <name> <log> <exit-code>" >&2; exit 2; }
  [ -n "$log" ] || log="$RUNS/$name.log"

  local bytes mtime reason status changed
  bytes=$(wc -c < "$log" 2>/dev/null || echo 0)
  mtime=$(date -r "$log" -Iseconds 2>/dev/null || echo "")
  reason=$(classify_log "$log" "$exitCode")
  status=$(status_line "$log")
  changed=$(git status --porcelain 2>/dev/null | wc -l)

  write_marker "$name" "$reason" "$log" "$bytes" "$mtime" "$status" "$changed" "$exitCode"
  notify_landing "$name" "$reason" "${status:-no status line | exit=${exitCode} | files=${changed}}"

  echo "[lane record] $name: reason=$reason exit=$exitCode bytes=$bytes files=$changed"
}

case "${1:-}" in
  run)    shift; cmd_run "$@";;
  status) shift; cmd_status "$@";;
  list)   shift; cmd_list "$@";;
  record) shift; cmd_record "$@";;
  *)      sed -n '2,36p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'; exit 2;;
esac
