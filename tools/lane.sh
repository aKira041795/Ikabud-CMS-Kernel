#!/usr/bin/env bash
#
# tools/lane.sh — dispatch a lane and RELIABLY detect when it lands.
#
# Why this exists: lanes were dispatched with a detached waiter writing to
# /tmp/<lane>-wait.out. Nothing ever read that file, so a landed lane went unnoticed
# until a human asked "and?". Two lanes landed that way on 2026-10-01.
#
# The fix has three parts:
#   1. `run` BLOCKS until the lane has RECORDED ITSELF. Invoke it with the terminal in
#      ASYNC mode and the harness notifies the agent on completion. Push, not poll.
#   2. The generated runner captures the exit status and records it, so the outcome does
#      not depend on any monitor surviving. It always appends a line to
#      .ai/runs/landings.jsonl - an append-only JOURNAL - and rewrites
#      .ai/runs/<name>.landed.json (atomic, valid JSON).
#   3. `tools/lane-watch.sh` reports every journal entry after .ai/runs/.reported.cursor
#      and then advances the cursor. A landing that happens while the watcher is disarmed
#      is therefore reported on the next arm, never lost.
#
# THE INVARIANT THAT MATTERS: consumption is RECORDED, never INFERRED. "This landing was
# already on disk when I armed" is NOT the same as "the agent was told". Treating file
# existence as acknowledgement is what lost landings across seven rewrites of this file.
#
# Detection signals, in order of trust:
#   1. the runner committed a landing marker (state=landed) - the record itself.
#   2. the runner printed the exit sentinel, `__LANE_EXIT_CODE__=<n>`, into the log.
#   A monitor that observes neither has NOT seen a landing. It writes state=unverified
#   and returns non-zero. It may never certify a lane it merely stopped watching.
#
# Do NOT use `kill -0` on a wrapper pid to decide landing. It tests the shell, not the
# work, and cannot see a reused or unreaped pid. Do NOT pgrep for the lane script either:
# the caller's own command line contains that path, so the pattern matches itself, the
# lane never looks dead, and every fallback exits early on a lane that is still running.
#
# Usage:
#   tools/lane.sh run      <name> <lane-script> [--timeout=7200] [--wait-grace=120] [--require-clean]
#   tools/lane.sh status   <name>
#   tools/lane.sh list
#   tools/lane.sh pending                      # landed but not yet reported to the agent
#   tools/lane.sh ack                          # mark everything reported
#   tools/lane.sh selftest                     # prove this harness detects its own failures
#   tools/lane.sh record   <name> <log> <exit> [run-id]   # called by the generated runner
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

# ── the landing JOURNAL is the queue the agent is notified from ────────────────
# A per-lane marker is a snapshot; the journal is the ordered, append-only history.
# The watcher reads the journal through a CURSOR and never the set of marker files,
# because "this marker existed when I armed" is NOT the same as "someone was told".
# Using file existence as a proxy for acknowledgement is exactly how landings went
# missing: the watcher exits on every landing and on every heartbeat, so it is
# disarmed most of the time, and every landing in a disarmed window was swallowed
# permanently. Consumption must be RECORDED, never inferred.
JOURNAL="$RUNS/landings.jsonl"
CURSOR="$RUNS/.reported.cursor"
[ -f "$JOURNAL" ] || : > "$JOURNAL"

iso() { date -Iseconds; }

journal_total() { [ -f "$JOURNAL" ] && wc -l < "$JOURNAL" 2>/dev/null || echo 0; }

cursor_get() {
  local c t
  c=$(cat "$CURSOR" 2>/dev/null || echo 0)
  case "$c" in ''|*[!0-9]*) c=0;; esac
  t=$(journal_total)
  [ "$c" -gt "$t" ] && c=$t   # journal rotated/shrank - never report a negative backlog
  printf '%s' "$c"
}

# Report a landing where a HUMAN sees it, without anyone having to ask an agent.
# notify-send IS installed - but it returns 0 even with no DISPLAY and no DBUS, so it
# cannot report its own failure and must never be the only channel. The durable
# journal is the reliable channel; the desktop toast is a courtesy on top of it.
notify_landing() {
  local name="$1" reason="$2" state="$3" summary="$4"
  local urgency="normal"
  case "$reason" in
    quota|fatal|empty|crash|timeout|unverified) urgency="critical";;
  esac
  if command -v notify-send > /dev/null 2>&1; then
    notify-send -u "$urgency" -a "lane" \
      "lane $name: $reason" "${summary:-no status line}" > /dev/null 2>&1 || true
  fi
  # one glanceable line for every landing, newest last
  printf '%s  %-24s %-15s %s\n' "$(iso)" "$name" "$reason" "${summary:-}" \
    >> "$RUNS/LANDINGS.log" 2>/dev/null || true
}

# Read one field out of a landing marker, so a reporter can never contradict the
# authoritative record the runner committed.
marker_field() {
  local marker="$1" key="$2"
  grep -o "\"$key\": \"[^\"]*\"" "$marker" 2>/dev/null | head -1 | cut -d'"' -f4
}

# Commit a landing EXACTLY ONCE: marker (atomic) + journal (append) + human channels.
# An atomic mkdir lock makes the commit single-owner, so two processes racing to record
# the same landing cannot produce two journal lines or two notifications - every
# landing previously appeared twice in LANDINGS.log.
commit_landing() {
  local name="$1" state="$2" reason="$3" log="$4" bytes="$5" mtime="$6" \
        status="$7" changed="$8" exitCode="${9:-}" runId="${10:-}"
  local lock="$RUNS/$name.commit.lock"
  if ! mkdir "$lock" 2>/dev/null; then
    echo "   (landing already committed by another process - not duplicating)" >&2
    return 1
  fi
  write_marker "$name" "$state" "$reason" "$log" "$bytes" "$mtime" "$status" "$changed" "$exitCode" "$runId"

  # append the machine-readable queue line; every free-text field is JSON-escaped
  local jname jstatus jrunid ec
  jname=$(json_escape "$name")
  jstatus=$(json_escape "$status")
  jrunid=$(json_escape "$runId")
  if [ -n "$exitCode" ] && [ "$exitCode" -eq "$exitCode" ] 2>/dev/null; then ec="$exitCode"; else ec="null"; fi
  printf '{"name":"%s","landed_at":"%s","state":"%s","reason":"%s","exit_code":%s,"status_line":"%s","log_bytes":%s,"changed_files":%s,"run_id":"%s"}\n' \
    "$jname" "$(iso)" "$state" "$reason" "$ec" "$jstatus" "${bytes:-0}" "${changed:-0}" "$jrunid" \
    >> "$JOURNAL" 2>/dev/null || true

  notify_landing "$name" "$reason" "$state" "${status:-no status line | exit=${exitCode:-?} | files=${changed:-?}}"
  rmdir "$lock" 2>/dev/null || true
  return 0
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
  local name="$1" state="$2" reason="$3" log="$4" bytes="$5" mtime="$6" status="$7" changed="$8" exitCode="${9:-}" runId="${10:-}"
  local marker="$RUNS/$name.landed.json"
  local tmp="$marker.tmp"
  # sanitise every free-text field for JSON: no CR/control chars, quotes/backslashes escaped
  name=$(json_escape "$name")
  status=$(json_escape "$status")
  runId=$(json_escape "$runId")
  {
    printf '{\n'
    printf '  "name": "%s",\n' "$name"
    printf '  "landed_at": "%s",\n' "$(iso)"
    # state is `landed` only when the process that held the exit status recorded it.
    # A monitor that simply gave up writes `unverified` - it may not certify success.
    printf '  "state": "%s",\n' "$state"
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
    printf '  "run_id": "%s",\n' "$runId"
    printf '  "detected_by": "tools/lane.sh"\n'
    printf '}\n'
  } > "$tmp"
  mv "$tmp" "$marker"          # atomic: a reader never sees a half-written marker
}

cmd_run() {
  local name="${1:-}"; shift || true
  local laneScript="${1:-}"; shift || true
  local timeoutSecs=7200
  local requireClean=0
  local waitGrace=120
  for arg in "$@"; do
    case "$arg" in
      --timeout=*) timeoutSecs="${arg#*=}";;
      --require-clean) requireClean=1;;
      --wait-grace=*) waitGrace="${arg#*=}";;
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
  rmdir "$RUNS/$name.commit.lock" 2>/dev/null || true

  # NOTE: landing detection is deliberately NOT scoped by matching the lane script's
  # process name. `pgrep -f <lane-script>` matches THIS command line, because the lane
  # script path is one of our own arguments - so "the lane is gone" was never true, the
  # fallback exited early on a lane still running, and the pid written to .pid was often
  # this monitor's own. Detection uses the runner's own record instead; see below.

  echo "== dispatching $name =="
  echo "   NOTE: if this monitor is killed (a terminal caps a command at ~120s) the runner"
  echo "         still records the landing. Arm the watcher to be woken by it:"
  echo "             bash tools/lane-watch.sh --forever    # detached, or"
  echo "             bash tools/lane-watch.sh --timeout=90 # one-shot, re-arm each wake"
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
  local runId
  runId="$(date +%Y%m%dT%H%M%S)-$$"
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
    printf 'bash %q record %q %q "$rc" %q\n' "$absRoot/tools/lane.sh" "$name" "$RUNS/$name.log" "$runId"
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

  echo "   wrapper=$wrapperPid"
  echo "   log=$log"
  echo "   run_id=$runId"

  # BLOCK until the lane has RECORDED ITSELF. Only two signals count, and both come from
  # the runner - the process that actually holds the exit status:
  #   (a) the runner committed a landing marker, or
  #   (b) the runner printed the exit sentinel into the log.
  # Nothing else is a landing. In particular the monitor may GIVE UP, and when it does it
  # must not decide the lane succeeded: a monitor that ran out of patience used to
  # certify a lane killed mid-work as `report_present` with a null exit code and a zero
  # return status. Giving up is now its own verdict, and it is not a green one.
  local waited=0 saw=0
  local monitorWait=$((timeoutSecs + waitGrace))   # let the runner record after timeout's TERM
  while true; do
    if [ -f "$RUNS/$name.landed.json" ] || grep -q "$sentinel" "$log" 2>/dev/null; then
      saw=1
      break
    fi
    sleep 3
    waited=$((waited+3))
    if [ "$waited" -ge "$monitorWait" ]; then
      echo "   !! watchdog: no landing recorded after ${monitorWait}s - giving up"
      break
    fi
  done

  local bytes mtime reason status changed exitCode state marker
  marker="$RUNS/$name.landed.json"
  bytes=$(wc -c < "$log" 2>/dev/null || echo 0)
  mtime=$(date -r "$log" -Iseconds 2>/dev/null || echo "")
  changed=$(git status --porcelain 2>/dev/null | wc -l)

  if [ -f "$marker" ]; then
    # The runner committed. Report ITS values verbatim. A monitor that recomputes the
    # reason can announce a verdict that contradicts the authoritative marker - a lane
    # whose marker said `crash` was announced to the owner as `report_present`.
    state=$(marker_field "$marker" state)
    reason=$(marker_field "$marker" reason)
    status=$(marker_field "$marker" status_line)
    exitCode=$(grep -o '"exit_code": [0-9]*' "$marker" 2>/dev/null | grep -o '[0-9]*' | head -1 || echo "")
    echo "   (committed by the runner - reporting its record verbatim, not a re-guess)"
  elif [ "$saw" = "1" ]; then
    # Sentinel seen but no marker: the runner was killed between recording and echoing.
    # The exit status is known, so this is a genuine landing the monitor can complete.
    state="landed"
    exitCode=$(grep -o "${sentinel}=[0-9]*" "$log" 2>/dev/null | tail -1 | cut -d= -f2 || echo "")
    reason=$(classify_log "$log" "$exitCode")
    status=$(status_line "$log")
    commit_landing "$name" "$state" "$reason" "$log" "$bytes" "$mtime" "$status" "$changed" "$exitCode" "$runId" >&2
  else
    # The monitor gave up and the lane never recorded itself. Its outcome is UNKNOWN.
    # Committing `report_present` here is precisely what turned a lane killed mid-work
    # into a certified success, with a null exit code and a zero return status.
    state="unverified"
    reason="timeout"
    status=$(status_line "$log")
    exitCode=""
    commit_landing "$name" "$state" "$reason" "$log" "$bytes" "$mtime" "$status" "$changed" "$exitCode" "$runId" >&2
  fi

  echo
  echo "== $name LANDED =="
  echo "   at:            $(iso)"
  echo "   state:         ${state:-unknown}"
  echo "   reason:        ${reason:-unknown}"
  echo "   log bytes:     $bytes"
  echo "   log mtime:     $mtime"
  echo "   changed files: $changed"
  echo "   lane exit:     ${exitCode:-unknown}"
  echo "   status:        ${status:-<none found>}"
  echo "   --- first 3 lines ---"
  head -3 "$log" 2>/dev/null | sed 's/^/   /'
  echo "   marker:  $marker"
  echo "   journal: $JOURNAL (now $(journal_total) entries)"

  if [ "$state" = "unverified" ]; then
    echo "   VERDICT: NOT VERIFIED - the lane never recorded an exit status, so this is"
    echo "            not evidence of success. Treat as a crash: partial edits may exist."
  else
    case "$reason" in
      report_present) echo "   VERDICT: landed with a report - verify it, do not trust it";;
      quota)          echo "   VERDICT: CRASHED on quota - partial edits may exist, check the tree";;
      crash)          echo "   VERDICT: CRASHED (non-zero exit) - partial edits may exist, check the tree";;
      fatal)          echo "   VERDICT: CRASHED - partial edits may exist, check the tree";;
      empty)          echo "   VERDICT: CRASHED with no output - nothing landed";;
      *)              echo "   VERDICT: ended without a recognisable status line - read the log";;
    esac
  fi

  # No notification here. commit_landing notified exactly once, from whichever process
  # owned the record; the extra unconditional call below this used to fire a SECOND
  # desktop toast and a SECOND LANDINGS.log line for every single landing.
  echo "   recorded once in: $JOURNAL"

  # The lane's real exit status must reach the command's own exit status, or a
  # crashing lane is reported as a successful landing by the shell return code.
  if [ "$state" = "unverified" ]; then
    return 1
  fi
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
  if [ -f "$marker" ]; then
    echo "   state:    LANDED (recorded)"
    sed 's/^/   /' "$marker"
  else
    echo "   state:    no landing recorded"
    echo "   log:      $(wc -c < "$log" 2>/dev/null || echo 0) bytes"
    echo "   mtime:    $(date -r "$log" -Iseconds 2>/dev/null || echo '-')"
    echo "   (a running lane writes nothing until it exits - that is normal, not a hang)"
  fi
  echo "   pending:  $(( $(journal_total) - $(cursor_get) )) landing(s) not yet reported to the agent"
}

cmd_list() {
  local total cursor
  total=$(journal_total)
  cursor=$(cursor_get)
  echo "== recent landings =="
  for m in "$RUNS"/*.landed.json; do
    [ -f "$m" ] || continue
    local name reason state landed
    name=$(marker_field "$m" name)
    state=$(marker_field "$m" state)
    reason=$(marker_field "$m" reason)
    landed=$(marker_field "$m" landed_at)
    printf '   %-28s %-11s %-16s %s\n' "$name" "${state:-pre-journal}" "$reason" "$landed"
  done
  echo "   journal: $total entries, $((total - cursor)) not yet reported to the agent"
  echo "   (tools/lane.sh pending shows them; tools/lane.sh ack clears them)"
}

# Record a landing. Called by the generated runner, so it happens even if every
# monitor has been killed. Safe to call twice: it simply rewrites the same record.
cmd_record() {
  local name="${1:-}" log="${2:-}" exitCode="${3:-}" runId="${4:-}"
  [ -n "$name" ] || { echo "usage: tools/lane.sh record <name> <log> <exit-code> [run-id]" >&2; exit 2; }
  [ -n "$log" ] || log="$RUNS/$name.log"

  local bytes mtime reason status changed
  bytes=$(wc -c < "$log" 2>/dev/null || echo 0)
  mtime=$(date -r "$log" -Iseconds 2>/dev/null || echo "")
  reason=$(classify_log "$log" "$exitCode")
  status=$(status_line "$log")
  changed=$(git status --porcelain 2>/dev/null | wc -l)

  # state=landed: this process held the lane's exit status at the instant it existed.
  # That is the only place a verdict may come from.
  commit_landing "$name" "landed" "$reason" "$log" "$bytes" "$mtime" "$status" "$changed" "$exitCode" "$runId"

  echo "[lane record] $name: state=landed reason=$reason exit=$exitCode bytes=$bytes files=$changed"
}

# Landings in the journal that the agent has not yet been told about. This is the
# question the watcher must answer, and it is answered by a CURSOR - never by asking
# which marker files happen to exist.
cmd_pending() {
  local total cursor n
  total=$(journal_total)
  cursor=$(cursor_get)
  [ "$cursor" -gt "$total" ] && cursor=$total
  n=$((total - cursor))
  echo "== landed but not yet reported: $n =="
  [ "$n" -le 0 ] && return 0
  sed -n "$((cursor+1)),\$p" "$JOURNAL" | sed 's/^/   /'
}

cmd_ack() {
  local total
  total=$(journal_total)
  printf '%s' "$total" > "$CURSOR"
  echo "acknowledged all $total journal entries"
}

# ── selftest: prove the harness detects its OWN failure modes ──────────────────
# Every historical bug in this file was found by accident or by an external reviewer,
# never by the tool - because the tool had no test of its own failure modes. A tool
# whose purpose is to detect failure must be able to demonstrate its own. Each case
# below has a must-allow AND a must-refuse direction; a guard that never refuses is
# unproven, and a wrong guard is worse than none because it is trusted.
#
#   tools/lane.sh selftest
cmd_selftest() {
  local SELF="$ROOT/tools/lane.sh"
  local P="$RUNS/selftest"
  rm -rf "$P"; mkdir -p "$P"
  local pass=0 fail=0
  ok()  { echo "   PASS  $1"; pass=$((pass+1)); }
  bad() { echo "   FAIL  $1"; fail=$((fail+1)); }

  mklane() {  # mklane <path> <sleep> <exitcode>
    { echo '#!/usr/bin/env bash'
      echo "sleep $2"
      echo 'echo "status: PASS"'
      echo "exit $3"
    } > "$1"
    chmod +x "$1"
  }

  # Start from a clean acknowledgement cursor, or S1 would be swamped by old backlog.
  printf '%s' "$(journal_total)" > "$CURSOR"
  # Baselines, so the duplicate checks below measure THIS run only. Counting the whole
  # journal made S5 fail on every run after the first - a guard that is wrong more often
  # than it is right is worse than no guard, because it is trusted.
  local jbase lbase
  jbase=$(journal_total)
  lbase=$(wc -l < "$RUNS/LANDINGS.log" 2>/dev/null); lbase=${lbase:-0}

  echo "== selftest: does this harness notice its own failures? =="

  # S1 (must-allow) - THE regression. A lane that lands while the watcher is DISARMED
  # must still be reported when it is next armed. Previously "the marker existed when I
  # armed" was treated as "someone was told", so this landing was lost forever.
  mklane "$P/lane-st1.sh" 5 0
  rm -f "$RUNS/st1.landed.json" "$RUNS/st1.log"
  nohup bash "$SELF" run st1 "$P/lane-st1.sh" --wait-grace=2 > "$P/st1.mon.log" 2>&1 &
  local i s1ok=0
  for i in $(seq 1 40); do [ -f "$RUNS/st1.landed.json" ] && break; sleep 1; done
  sleep 2   # deliberately NO watcher armed across the landing
  local w1
  w1=$(bash "$ROOT/tools/lane-watch.sh" --timeout=5 2>&1)
  case "$w1" in
    *st1*) ok "S1 a landing during a DISARMED window is reported on the next arm"; s1ok=1;;
    *)     bad "S1 a landing during a DISARMED window was SWALLOWED"; echo "$w1" | sed 's/^/         /';;
  esac

  # S2 (must-refuse) - and it must not be reported again, or the watcher is pure noise.
  # Only meaningful once S1 actually reported something: if nothing was reported, "it was
  # not reported twice" is vacuously true and would be a green that means nothing.
  if [ "$s1ok" = "1" ]; then
    local w2
    w2=$(bash "$ROOT/tools/lane-watch.sh" --timeout=5 2>&1)
    case "$w2" in
      *"LANDING(S) DETECTED"*) bad "S2 an already-reported landing was reported AGAIN";;
      *) ok "S2 an already-reported landing is not repeated";;
    esac
  else
    echo "   SKIP  S2 (S1 did not report, so S2 would pass vacuously)"
  fi

  # S3 (must-refuse) - a lane that prints a passing status line and then crashes must
  # NOT be green. This was the false green the previous fix claimed to have removed.
  mklane "$P/lane-st3.sh" 1 7
  local rc3 r3
  bash "$SELF" run st3 "$P/lane-st3.sh" --timeout=60 --wait-grace=2 > "$P/st3.mon.log" 2>&1
  rc3=$?
  r3=$(marker_field "$RUNS/st3.landed.json" reason)
  if [ "$rc3" -ne 0 ] && [ "$r3" != "report_present" ]; then
    ok "S3 a crash after 'status: PASS' returns non-zero (rc=$rc3, reason=$r3)"
  else
    bad "S3 a crash was certified as a landing (rc=$rc3, reason=$r3)"
  fi

  # S4 (must-refuse) - a lane the monitor gave up on must not be green.
  mklane "$P/lane-st4.sh" 30 0
  local rc4 s4
  bash "$SELF" run st4 "$P/lane-st4.sh" --timeout=3 --wait-grace=2 > "$P/st4.mon.log" 2>&1
  rc4=$?
  s4=$(marker_field "$RUNS/st4.landed.json" state)
  if [ "$rc4" -ne 0 ] && [ "$s4" = "unverified" ]; then
    ok "S4 a lane killed mid-work is 'unverified' and returns non-zero (rc=$rc4)"
  else
    bad "S4 a killed lane was certified (rc=$rc4, state=$s4)"
  fi
  # S4b (must-allow companion) - the same guard must NOT refuse a lane that finished
  mklane "$P/lane-st4b.sh" 1 0
  local rc4b s4b
  bash "$SELF" run st4b "$P/lane-st4b.sh" --timeout=60 --wait-grace=2 > "$P/st4b.mon.log" 2>&1
  rc4b=$?
  s4b=$(marker_field "$RUNS/st4b.landed.json" state)
  if [ "$rc4b" -eq 0 ] && [ "$s4b" = "landed" ]; then
    ok "S4b must-allow: a lane inside its budget is 'landed' and returns 0"
  else
    bad "S4b a good lane was not certified (rc=$rc4b, state=$s4b)"
  fi

  # S5 (must-refuse) - ONE commit per landing. Every landing previously appeared twice
  # in LANDINGS.log and produced two desktop notifications. Measured over THIS run only.
  local n5 n5b
  n5=$(tail -n +$((jbase + 1)) "$JOURNAL" 2>/dev/null | grep -c '"name":"st3"'); n5=${n5:-0}
  [ "$n5" = "1" ] && ok "S5 exactly one journal entry per landing" \
                  || bad "S5 landing recorded $n5 times (expected 1)"
  n5b=$(tail -n +$((lbase + 1)) "$RUNS/LANDINGS.log" 2>/dev/null | grep -c "  st4b "); n5b=${n5b:-0}
  [ "$n5b" = "1" ] && ok "S5b exactly one human log line per landing" \
                   || bad "S5b landing announced $n5b times (expected 1)"

  # S6 (must-refuse) - a lane name containing a quote must still yield valid JSON
  bash "$SELF" record 'st6"q' "$RUNS/st6q.log" 0 > /dev/null 2>&1
  if php -r 'exit(json_decode(file_get_contents($argv[1]))===null?1:0);' "$RUNS/st6\"q.landed.json" 2>/dev/null; then
    ok "S6 a lane name containing a quote produces valid JSON"
  else
    bad "S6 a lane name containing a quote produced invalid JSON"
  fi

  printf '%s' "$(journal_total)" > "$CURSOR"   # leave no backlog for the next real arm
  echo
  echo "== selftest: $pass passed, $fail failed =="
  [ "$fail" -eq 0 ] || return 1
  return 0
}

case "${1:-}" in
  run)     shift; cmd_run "$@";;
  status)  shift; cmd_status "$@";;
  list)    shift; cmd_list "$@";;
  record)  shift; cmd_record "$@";;
  pending) shift; cmd_pending "$@";;
  ack)     shift; cmd_ack "$@";;
  selftest) shift; cmd_selftest "$@";;
  # Print the header comment block as usage. Derived, not a hard-coded line range, so
  # editing the header can never silently truncate or overrun the help text.
  *)       awk 'NR>1 && /^[^#]/ {exit} NR>1 {sub(/^# ?/,""); print}' "${BASH_SOURCE[0]}"; exit 2;;
esac
