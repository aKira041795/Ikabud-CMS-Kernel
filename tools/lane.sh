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
#   tools/lane.sh run      <name> <lane-script> [--timeout=7200] [--slice=90] [--wait-grace=120]
#                          [--require-clean]
#                          --acceptance="<cmd>" --pass-looks-like="<what PASS looks like>"
#                          [--no-acceptance-gate="<reason>"] [--touches="<path,path>"]
#   tools/lane.sh status   <name>
#   tools/lane.sh list
#   tools/lane.sh pending                      # landed but not yet reported to the agent
#   tools/lane.sh ack                          # mark everything reported
#   tools/lane.sh selftest                     # prove this harness detects its own failures
#   tools/lane.sh record   <name> <log> <exit> [run-id]   # called by the generated runner
#
# THE PRE-DISPATCH ACCEPTANCE GATE. `run` REFUSES to dispatch unless:
#   (a) --acceptance="<cmd>" has been run against THIS tree and shown to FAIL, and
#   (b) --pass-looks-like="<text>" states what PASS looks like on the target.
# Both halves are required and they catch different errors. (a) alone was the observed gap:
# the criterion DID fail on HEAD and still could not pass on the new code, because it
# encoded the old interaction model. A criterion that already passes cannot discriminate
# the change, so "green afterwards" proves nothing. Assert OUTCOMES (a row exists, a
# message persists), never MECHANISMS (one click suffices), when the design changes the
# mechanism. Four false reds in one session shared this single cause, and restating the
# lesson was measured at three restatements and zero prevented - so it is enforced here.
# A genuinely non-verifiable lane may override with --no-acceptance-gate="<reason>";
# every override is recorded in .ai/runs/acceptance-gate.log with its reason.
#
# THE TOOLING ADVISORY. If a lane is python-dominant (declared with --touches, else inferred
# from the working tree), `run` says what that lane can and cannot be handed, and records it in
# .ai/runs/mcp-advice.log. Advisory only - it never refuses, because a wrong refusal is worse
# than no advice. See mcp_advice() for the measured reason a lane cannot be given Pylance MCP.
#
# Writing the lane script itself? Source tools/lane-model.sh for model invocation with
# automatic fallback, instead of hand-rolling a try/retry block per lane:
#
#   source tools/lane-model.sh
#   lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/mylane
#
# `LANE_MODEL_CHAIN` is the canonical ordered chain from tools/model-chain.txt (three
# models, so a single exhausted provider cannot stop the work). It carries the shared
# model-unavailability signatures in tools/model-unavailable.patterns, and names the
# model that completed. Success is decided by the exit status ALONE; log content only
# CLASSIFIES a failure, because a lane implementing the unavailability detector writes
# about quota by definition and an earlier content check rejected its correct work.
# Verified by tools/lane-model-selftest.sh.
#
# Exit status of `run`:  0 = landed (clean) | <n> = landed with the lane's exit code <n>
#                        3 = STILL RUNNING, re-arm the watcher | 1 = unverified / timeout
#
# Run it in an ASYNC terminal at dispatch. Every wake tells you what happened, or that the
# lane is still going - so the "is it done yet?" round-trip never has to be asked.
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
MODEL_UNAVAILABLE_PATTERNS="$ROOT/tools/model-unavailable.patterns"
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
  # The lock is deliberately NOT removed. It persists as proof that this dispatch already
  # committed, so a late second caller - the monitor and the runner can both record now
  # that the runner has an EXIT trap - cannot write a duplicate marker or journal line.
  # `run` clears it at dispatch, so a re-run of the same name still works.
  return 0
}

# ── classify why a lane ended, from its log content ────────────────────────────
# echoes one of: report_present | quota | crash | timeout | fatal | empty | unknown
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
  if grep -qiE -f "$MODEL_UNAVAILABLE_PATTERNS" "$log" 2>/dev/null; then
    echo "quota"; return
  fi
  # 124 = timeout(1) itself, 137 = SIGKILL, 143 = SIGTERM. A lane killed by its own budget
  # is a timeout rather than a generic crash, and naming it makes the report actionable.
  case "$rc" in
    124|137|143) echo "timeout"; return;;
  esac
  # A non-zero process exit (including timeout's 124) is a crash, whatever the log says.
  if [ -n "$rc" ] && [ "$rc" != "0" ]; then
    echo "crash"; return
  fi
  # Reuse status_line() so the two can never drift apart again. They DID drift: this used a
  # stricter pattern than status_line(), so a lane reporting "## Status: Complete" was announced
  # to the owner as "ended without a recognisable status line" while its marker held the status.
  # A guard that cries wolf is worse than none, because it teaches the reader to ignore it.
  if [ -n "$(status_line "$log")" ]; then
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
  # Anchored to line start, tolerating markdown decoration the lanes actually emit:
  #   status: PASS   /   Status: PASS   /   ## Status: Complete   /   **Status:** PASS
  # Deliberately NOT a bare "Status:" anywhere - that matched incidental prose.
  grep -m1 -E "^[[:space:]]*(#+[[:space:]]+)?\**[Ss]tatus:" "$log" 2>/dev/null \
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

# ── tooling advisory: what a lane can and cannot be handed ─────────────────────────────
# Python work has two audiences and they are NOT interchangeable:
#   - the CHAIR (a VS Code agent turn) can use the Pylance MCP server;
#   - a LANE (pi, in a terminal) CANNOT.
# Measured 2026-10-04, so this is a property of the tool and not a preference:
#   - Pylance exposes its server only via contributes.mcpServerDefinitionProviders (id
#     "pylanceMcp"), registered from inside the extension at runtime. package.json declares no
#     CLI entry point and the dist bundles are browser/extension bundles - nothing to spawn.
#   - ~/.pi/agent/settings.json carries no `mcp` key at all, so a pi lane has no MCP access.
#   - There is also no type checker to hand over: pyright, mypy and ruff are absent from PATH
#     AND from .venv. pytest IS present (.venv/bin/pytest).
# So the only actionable advice for a Python lane is "give it an executable criterion". This
# prints and returns; it never refuses, so it can never manufacture a false red.
mcp_advice() {
  local list="${1:-}" who="${2:-lane}" total=0 py=0 f
  if [ -z "$list" ]; then
    # Nothing declared: fall back to what is actually in play in the tree.
    list=$(git -C "$ROOT" status --porcelain 2>/dev/null | awk '{print $NF}')
  else
    list=$(printf '%s\n' "$list" | tr ',' '\n')
  fi
  while IFS= read -r f; do
    [ -n "$f" ] || continue
    total=$((total + 1))
    case "$f" in *.py) py=$((py + 1));; esac
  done < <(printf '%s\n' "$list")
  [ "$total" -gt 0 ] || return 0
  # Python-DOMINANT only: one .py among ten .php is not a Python lane, and calling it one would
  # train the reader to ignore the advisory.
  [ "$py" -gt 0 ] || return 0
  [ "$((py * 2))" -ge "$total" ] || return 0
  echo "== tooling advisory: python-dominant ($py/$total touched files are .py) =="
  echo "   Pylance MCP is CHAIR-side only: pi lanes cannot reach it, and no type checker is"
  echo "   installed (pyright/mypy/ruff absent). Give this lane an EXECUTABLE criterion -"
  echo "   pytest is at .venv/bin/pytest."
  printf '%s  %-24s python %s/%s\n' "$(iso)" "$who" "$py" "$total" \
    >> "$RUNS/mcp-advice.log" 2>/dev/null || true
}

cmd_run() {
  local name="${1:-}"; shift || true
  local laneScript="${1:-}"; shift || true
  local timeoutSecs=7200
  local requireClean=0
  local waitGrace=120
  local deadlineGrace=90
  # How long ONE call is willing to block before handing the wait back to the caller.
  # Deliberately below the ~120s terminal cap that has been observed on some invocation
  # paths: a process killed at the cap produces NO signal, whereas a process that exits
  # on purpose produces a wake-up and an instruction.
  local sliceSecs=90
  # Pre-dispatch acceptance gate. Empty means "not supplied", which is a refusal, not a
  # pass - a gate that defaults open is decoration.
  local acceptanceCmd=""
  local passLooksLike=""
  local gateOverride=""
  local touches=""
  for arg in "$@"; do
    case "$arg" in
      --timeout=*) timeoutSecs="${arg#*=}";;
      --require-clean) requireClean=1;;
      --wait-grace=*) waitGrace="${arg#*=}";;
      --deadline-grace=*) deadlineGrace="${arg#*=}";;
      --slice=*) sliceSecs="${arg#*=}";;
      --acceptance=*) acceptanceCmd="${arg#*=}";;
      --pass-looks-like=*) passLooksLike="${arg#*=}";;
      --no-acceptance-gate=*) gateOverride="${arg#*=}";;
      --touches=*) touches="${arg#*=}";;
    esac
  done

  [ -n "$name" ] && [ -n "$laneScript" ] || {
    echo "usage: tools/lane.sh run <name> <lane-script> [--timeout=N] [--slice=N] [--require-clean]" >&2
    echo "       --acceptance=\"<cmd>\" --pass-looks-like=\"<what PASS looks like>\" [--no-acceptance-gate=\"<reason>\"] [--touches=\"<path,path>\"]" >&2
    exit 2
  }

  # ── pre-dispatch acceptance gate ───────────────────────────────────────────
  # Runs BEFORE --require-clean so any artifact the criterion writes is caught by that
  # check rather than silently handed to the lane.
  if [ -n "$gateOverride" ]; then
    echo "== acceptance gate BYPASSED: $gateOverride =="
    printf '%s  %-24s BYPASSED  %s\n' "$(iso)" "$name" "$gateOverride" \
      >> "$RUNS/acceptance-gate.log" 2>/dev/null || true
  elif [ -z "$acceptanceCmd" ] || [ -z "$passLooksLike" ]; then
    echo "REFUSING TO DISPATCH: no acceptance criterion." >&2
    echo "  A lane that cannot be told apart from a no-op cannot be trusted when it reports PASS." >&2
    echo "  required: --acceptance=\"<cmd>\" --pass-looks-like=\"<what PASS looks like>\"" >&2
    echo "  deliberate override (recorded with its reason): --no-acceptance-gate=\"<reason>\"" >&2
    exit 2
  else
    local gateOut gateRc
    echo "== acceptance gate =="
    echo "   command: $acceptanceCmd"
    gateOut=$(timeout 300 bash -c "$acceptanceCmd" 2>&1); gateRc=$?
    case "$gateRc" in
      124|137|143)
        echo "REFUSING TO DISPATCH: the acceptance command TIMED OUT (rc=$gateRc)." >&2
        echo "  That demonstrates a hang, not a failing criterion." >&2
        exit 2;;
    esac
    if [ "$gateRc" -eq 0 ]; then
      echo "REFUSING TO DISPATCH: the acceptance command ALREADY PASSES on this tree." >&2
      echo "  A criterion that passes before the change cannot show the change worked." >&2
      printf '%s\n' "$gateOut" | tail -20 | sed 's/^/     /' >&2
      exit 2
    fi
    echo "   fails on this tree as required (rc=$gateRc)"
    if [ -n "$gateOut" ]; then
      printf '%s\n' "$gateOut" | tail -5 | sed 's/^/     /'
    fi
    echo "   PASS on the target looks like: $passLooksLike"
    printf '%s  %-24s rc=%s  %s | pass=%s\n' "$(iso)" "$name" "$gateRc" "$acceptanceCmd" "$passLooksLike" \
      >> "$RUNS/acceptance-gate.log" 2>/dev/null || true
  fi

  if [ "$requireClean" = "1" ]; then
    local dirty
    dirty=$(git status --porcelain | wc -l)
    if [ "$dirty" != "0" ]; then
      echo "REFUSING: tree not clean ($dirty entries). Commit or revert first."
      git status --porcelain | head -20
      exit 1
    fi
  fi

  # Tooling advisory. Deliberately at the moment the decision is made rather than after it is
  # missed - the same reasoning as the chain-length warning.
  mcp_advice "$touches" "$name"

  local log="$RUNS/$name.log"
  rm -f "$log" "$RUNS/$name.landed.json" "$RUNS/$name.pid"
  rmdir "$RUNS/$name.commit.lock" 2>/dev/null || true

  # NOTE: landing detection is deliberately NOT scoped by matching the lane script's
  # process name. `pgrep -f <lane-script>` matches THIS command line, because the lane
  # script path is one of our own arguments - so "the lane is gone" was never true, the
  # fallback exited early on a lane still running, and the pid written to .pid was often
  # this monitor's own. Detection uses the runner's own record instead; see below.

  echo "== dispatching $name =="
  echo "   This call waits up to ${sliceSecs}s, then hands the wait back instead of blocking"
  echo "   past the terminal cap. If the lane is still running it returns 3 and says to"
  echo "   re-arm. The runner records the landing itself, so nothing is lost either way."
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
    # RECORD ON THE WAY OUT, however we leave. Without an EXIT trap a lane killed by its
    # own --timeout recorded NOTHING: the shell died at the lane call, before reaching the
    # record line, so there was no marker, no journal line and no notification, and nobody
    # could tell the lane had ever existed. A real 50-minute lane was lost exactly this way.
    # The record is the entire point, so it must be written by whatever holds the outcome
    # at the moment it ends - including a TERM. (SIGKILL still defeats this: nothing runs
    # after SIGKILL, which is what --wait-grace exists to cover.)
    printf 'lane_finish() {\n'
    printf '  rc=$?\n'
    printf '  bash %q record %q %q "$rc" %q\n' "$absRoot/tools/lane.sh" "$name" "$RUNS/$name.log" "$runId"
    printf '  echo "%s=$rc"\n' "$sentinel"
    printf '}\n'
    # EXIT alone is not enough: bash runs an EXIT trap on a signal only if that signal is
    # trapped, and `script` kills the session when `timeout` fires. Trap them explicitly.
    printf 'trap lane_finish EXIT HUP INT TERM\n'
    printf 'bash %q\n' "$laneScript"
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

  # A DEADLINE RECORDER, living outside the process tree that `timeout` kills. The runner
  # cannot always record its own end: `timeout` signals `script`, and `script` terminates the
  # session with a signal bash never gets to handle, so a lane killed by its own --timeout
  # wrote no marker, no journal line and no notification. A real 50-minute lane was lost that
  # way. This process is detached, so it survives, and it commits `unverified` only if
  # nothing else has recorded by the time the lane's budget is certainly over.
  local deadlineSecs=$((timeoutSecs + deadlineGrace))
  setsid bash "$ROOT/tools/lane.sh" watchdog "$name" "$deadlineSecs" \
    </dev/null >/dev/null 2>&1 &
  disown 2>/dev/null || true

  echo "   wrapper=$wrapperPid"
  echo "   deadline recorder: ${deadlineSecs}s (commits 'unverified' if the lane dies silent)"
  echo "   log=$log"
  echo "   run_id=$runId"

  # WAIT in bounded slices. Two different endings that must never be confused:
  #   - the lane RECORDED ITSELF          -> report it, below
  #   - the lane is STILL RUNNING at the  -> wake the caller and ask to be re-armed
  #     slice boundary
  # A slice matters because a terminal wrapper may cap a command (~120s). Blocking for the
  # whole lane either gets killed with no signal at all - the original complaint - or was
  # treated as "gave up" and certified the lane. Exiting deliberately at the boundary turns
  # "killed silently" into "woken on purpose, with an instruction".
  # A monitor that observes neither signal has NOT seen a landing, and may never certify
  # one it merely stopped watching.
  local waited=0 saw=0 sliceExpired=0
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
    # Hand the wait back only while the lane is still inside its OWN budget. Past that
    # the lane has been killed, so the correct ending is the unverified verdict, not a
    # cheerful "still running".
    if [ "$waited" -ge "$sliceSecs" ] && [ "$waited" -lt "$timeoutSecs" ]; then
      sliceExpired=1
      break
    fi
  done

  if [ "$sliceExpired" = "1" ] && [ "$saw" = "0" ]; then
    # STILL RUNNING. Write NO verdict - there is nothing yet to have a verdict about.
    # Returning here IS the wake-up; the instruction is what removes the "is it done?"
    # round-trip. Exit 3, distinct from 0 (landed) and 1 (unverified).
    echo
    echo "== $name STILL RUNNING after ${sliceSecs}s =="
    echo "   log:    $log ($(wc -c < "$log" 2>/dev/null || echo 0) bytes)"
    echo "   runner: $runner"
    echo "   Nothing is lost - the runner records the landing by itself."
    echo "   RE-ARM to be woken by it:  bash tools/lane-watch.sh --timeout=90"
    return 3
  fi

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
      timeout)        echo "   VERDICT: KILLED by its own timeout - partial edits may exist, check the tree";;
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

# A deadline recorder, spawned detached at dispatch. It exists because the record must not
# depend on anything inside the tree `timeout` kills.
cmd_watchdog() {
  local name="${1:-}" secs="${2:-}"
  [ -n "$name" ] && [ -n "$secs" ] || exit 2

  # POLL, do not sleep the whole deadline in one call.
  #
  # `sleep "$secs"` followed by a single existence check meant every dispatch leaked a
  # process for its entire deadline - up to timeout+grace (3690s live, 7290s in the
  # selftest). Measured 2026-10-03: 34 such processes alive at once, ~4 leaked per
  # selftest run.
  #
  # The leak was not merely untidy, it made the selftest FLAKY. Selftest lanes reuse
  # names (st1, st3, st8...), so a watchdog left over from an earlier run would wake
  # during a LATER run and commit a second landing for the same name. S8 asserts
  # "exactly one journal entry" and saw journal=2, so it failed intermittently - three
  # runs with a dirty process table gave 10/11, 11/11, 9/11, while three runs with a
  # clean table gave 11/11 three times. A flaky selftest discredits every other result
  # it reports, and it very nearly caused correct work to be rejected.
  #
  # Exiting within one poll of a landing is also simply more correct: once a marker
  # exists there is nothing left for this process to fill.
  local waited=0
  while [ "$waited" -lt "$secs" ]; do
    [ -f "$RUNS/$name.landed.json" ] && exit 0
    sleep 5
    waited=$((waited + 5))
  done

  # Whatever committed first owns the record; this only fills a silence.
  if [ -f "$RUNS/$name.landed.json" ]; then
    exit 0
  fi

  local log="$RUNS/$name.log"
  local bytes mtime status changed exitCode
  bytes=$(wc -c < "$log" 2>/dev/null || echo 0)
  mtime=$(date -r "$log" -Iseconds 2>/dev/null || echo "")
  status=$(status_line "$log")
  changed=$(git status --porcelain 2>/dev/null | wc -l)
  exitCode=""
  # A lane still inside its budget is NOT a timeout - do not invent one.
  if pgrep -f "$name.runner.sh" > /dev/null 2>&1; then
    echo "[lane watchdog] $name: still running at ${secs}s - leaving it alone" >&2
    exit 0
  fi

  commit_landing "$name" "unverified" "timeout" "$log" "$bytes" "$mtime" "$status" "$changed" "$exitCode" "deadline" >&2
  echo "[lane watchdog] $name: nothing recorded within ${secs}s - committed unverified/timeout" >&2
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

  # Every fixture lane below is dispatched through the REAL gate, so the gate is exercised
  # on every selftest run rather than only when a lane is dispatched by hand. 'exit 1' is a
  # criterion that fails on this tree - which is exactly what the gate requires before it
  # will dispatch.
  local GATE=(--acceptance='exit 1' --pass-looks-like='the fixture lane reaches LANDED')

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
  nohup bash "$SELF" run st1 "$P/lane-st1.sh" --wait-grace=2 "${GATE[@]}" > "$P/st1.mon.log" 2>&1 &
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
  bash "$SELF" run st3 "$P/lane-st3.sh" --timeout=60 --wait-grace=2 "${GATE[@]}" > "$P/st3.mon.log" 2>&1
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
  bash "$SELF" run st4 "$P/lane-st4.sh" --timeout=3 --wait-grace=2 "${GATE[@]}" > "$P/st4.mon.log" 2>&1
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
  bash "$SELF" run st4b "$P/lane-st4b.sh" --timeout=60 --wait-grace=2 "${GATE[@]}" > "$P/st4b.mon.log" 2>&1
  rc4b=$?
  s4b=$(marker_field "$RUNS/st4b.landed.json" state)
  if [ "$rc4b" -eq 0 ] && [ "$s4b" = "landed" ]; then
    ok "S4b must-allow: a lane inside its budget is 'landed' and returns 0"
  else
    bad "S4b a good lane was not certified (rc=$rc4b, state=$s4b)"
  fi

  # S7 (must-refuse) - a lane STILL RUNNING at the slice boundary must not be certified,
  # and must have NO verdict written for it. `run` hands the wait back (exit 3) instead of
  # blocking past the terminal cap, where a killed process produces no signal at all.
  mklane "$P/lane-st7.sh" 8 0
  local rc7 m7
  bash "$SELF" run st7 "$P/lane-st7.sh" --timeout=120 --slice=4 --wait-grace=2 "${GATE[@]}" > "$P/st7.mon.log" 2>&1
  rc7=$?
  m7="none"
  [ -f "$RUNS/st7.landed.json" ] && m7=$(marker_field "$RUNS/st7.landed.json" state)
  if [ "$rc7" -eq 3 ] && [ "$m7" = "none" ]; then
    ok "S7 a lane still running at the slice boundary returns 3 and writes no verdict"
  else
    bad "S7 still-running lane mishandled (rc=$rc7, marker state=$m7)"
  fi

  # S7b (must-allow) - handing the wait back must not LOSE the lane: it still lands, is
  # still recorded, and is still reported on the next arm.
  local i7 w7
  for i7 in $(seq 1 20); do [ -f "$RUNS/st7.landed.json" ] && break; sleep 1; done
  w7=$(bash "$ROOT/tools/lane-watch.sh" --timeout=5 2>&1)
  case "$w7" in
    *st7*) ok "S7b a handed-back lane still lands, is recorded, and is reported";;
    *)     bad "S7b a handed-back lane was LOST"; echo "$w7" | sed 's/^/         /';;
  esac

  # S8 (must-allow) - a lane killed by its OWN --timeout, with the monitor already gone,
  # must still be RECORDED. It used to write nothing at all - no marker, no journal line, no
  # notification - so a real 50-minute lane simply vanished and could only be discovered by
  # asking. The runner cannot catch this: `timeout` signals `script`, and `script` kills the
  # session with a signal bash never handles. Hence the detached deadline recorder.
  mklane "$P/lane-st8.sh" 30 0
  rm -f "$RUNS/st8.landed.json" "$RUNS/st8.log"
  local rc8 r8 c8
  bash "$SELF" run st8 "$P/lane-st8.sh" --timeout=4 --slice=2 --wait-grace=2 --deadline-grace=2 "${GATE[@]}" > "$P/st8.mon.log" 2>&1
  rc8=$?
  local i8
  for i8 in $(seq 1 25); do [ -f "$RUNS/st8.landed.json" ] && break; sleep 1; done
  r8=$(marker_field "$RUNS/st8.landed.json" reason)
  c8=$(tail -n +$((jbase + 1)) "$JOURNAL" 2>/dev/null | grep -c '"name":"st8"'); c8=${c8:-0}
  if [ "$rc8" -eq 3 ] && [ "$r8" = "timeout" ] && [ "$c8" = "1" ]; then
    ok "S8 a silent timeout kill is still recorded, exactly once (reason=$r8)"
  else
    bad "S8 a lane killed by its own timeout was LOST (rc=$rc8 reason=$r8 journal=$c8)"
  fi

  # S9 (must-refuse) - the PRE-DISPATCH ACCEPTANCE GATE. A dispatch with no acceptance
  # criterion must be refused, and must leave no runner and no marker behind. This is
  # enforced rather than restated because four false reds in one session shared ONE cause -
  # a criterion written before the change and then trusted - and restating that lesson has a
  # measured record of three restatements and zero prevented.
  mklane "$P/lane-st9.sh" 1 0
  rm -f "$RUNS/st9.landed.json" "$RUNS/st9.log" "$RUNS/st9.runner.sh"
  rmdir "$RUNS/st9.commit.lock" 2>/dev/null || true
  local rc9
  bash "$SELF" run st9 "$P/lane-st9.sh" --wait-grace=2 > "$P/st9.mon.log" 2>&1
  rc9=$?
  if [ "$rc9" -eq 2 ] && [ ! -f "$RUNS/st9.runner.sh" ] && [ ! -f "$RUNS/st9.landed.json" ]; then
    ok "S9 a dispatch with no acceptance criterion is refused (rc=$rc9) and runs nothing"
  else
    bad "S9 a lane was dispatched with no acceptance criterion (rc=$rc9)"
  fi

  # S9b (must-refuse) - a criterion that ALREADY PASSES cannot discriminate the change, so
  # it must be refused even though it was supplied. This is the half that "(a) fails on HEAD"
  # alone could not catch: the real criterion failed on HEAD and STILL could not pass on the
  # new code, because it asserted a MECHANISM the change had replaced.
  local rc9b
  bash "$SELF" run st9b "$P/lane-st9.sh" --wait-grace=2 \
    --acceptance='exit 0' --pass-looks-like='something or other' > "$P/st9b.mon.log" 2>&1
  rc9b=$?
  if [ "$rc9b" -eq 2 ]; then
    ok "S9b an acceptance criterion that already passes is refused (rc=$rc9b)"
  else
    bad "S9b a criterion that cannot discriminate the change was accepted (rc=$rc9b)"
  fi

  # S9c (must-allow) - a criterion that fails on this tree, plus a stated PASS, must let the
  # lane through and land. A gate that refuses everything is a wrong guard, not a strict one.
  local rc9c s9c
  bash "$SELF" run st9c "$P/lane-st9.sh" --timeout=60 --wait-grace=2 \
    --acceptance='exit 1' --pass-looks-like='the row persists (a message reaches the database)' \
    > "$P/st9c.mon.log" 2>&1
  rc9c=$?
  s9c=$(marker_field "$RUNS/st9c.landed.json" state)
  if [ "$rc9c" -eq 0 ] && [ "$s9c" = "landed" ]; then
    ok "S9c must-allow: a failing criterion plus a stated PASS dispatches and lands"
  else
    bad "S9c the gate refused a valid dispatch (rc=$rc9c state=$s9c)"
  fi

  # S10/S10b - the TOOLING ADVISORY. It only ever prints, so both directions are asserted: a
  # python-dominant lane must be told, and a php/disyl one must NOT be. A one-directional test
  # here would pass just as well against an advisory that fires unconditionally.
  mklane "$P/lane-st10.sh" 1 0
  bash "$SELF" run st10 "$P/lane-st10.sh" --timeout=60 --wait-grace=2 \
    --acceptance='exit 1' --pass-looks-like='fixture' \
    --touches='tools/a.py,tools/b.py' > "$P/st10.mon.log" 2>&1
  if grep -q 'python-dominant (2/2' "$P/st10.mon.log"; then
    ok "S10 must-allow: a python-dominant lane is told Pylance MCP is unreachable from it"
  else
    bad "S10 a python-dominant lane got no tooling advisory"
  fi

  mklane "$P/lane-st10b.sh" 1 0
  bash "$SELF" run st10b "$P/lane-st10b.sh" --timeout=60 --wait-grace=2 \
    --acceptance='exit 1' --pass-looks-like='fixture' \
    --touches='modules/cms/handlers.php,templates/x.disyl' > "$P/st10b.mon.log" 2>&1
  if grep -q 'tooling advisory' "$P/st10b.mon.log"; then
    bad "S10b a php/disyl lane was given a python advisory (it must not be)"
  else
    ok "S10 must-refuse: a php/disyl lane gets no python advisory"
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
  watchdog) shift; cmd_watchdog "$@";;
  pending) shift; cmd_pending "$@";;
  ack)     shift; cmd_ack "$@";;
  selftest) shift; cmd_selftest "$@";;
  classify-log) shift; classify_log "$@";;
  # Print the header comment block as usage. Derived, not a hard-coded line range, so
  # editing the header can never silently truncate or overrun the help text.
  *)       awk 'NR>1 && /^[^#]/ {exit} NR>1 {sub(/^# ?/,""); print}' "${BASH_SOURCE[0]}"; exit 2;;
esac
