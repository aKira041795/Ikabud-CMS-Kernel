#!/usr/bin/env bash
#
# tools/lane-platform.sh — the two OS primitives the lane harness needs, in ONE place, each with a
# fallback that is SELECTABLE so both branches can be exercised on a single machine.
#
# WHY THIS FILE EXISTS. The harness was built and measured on Linux, where it used `script -qec`
# (util-linux) to give a lane a pty, and `setsid` to detach a watchdog from the terminal's process
# group. Both are util-linux. On Windows the realistic environments are:
#
#   WSL2                    a real Linux — everything works, and this is the RECOMMENDED path.
#   Git Bash / MSYS2        bash + GNU coreutils, but `setsid` and `script` are absent or behave
#                           differently. Runs on the fallbacks below.
#   macOS                   BSD userland: `date -Iseconds` is GNU-only, and `script` takes
#                           different flags. Runs on the fallbacks, with the GNU `date` fields
#                           degrading to empty rather than failing.
#
# Without a fallback, a Windows or macOS user gets `script: command not found` inside a background
# job — which is INVISIBLE, because the job's stderr goes to the lane log. The lane would appear to
# hang and then be recorded `unverified`. A silent platform failure is exactly the class of defect
# this harness exists to remove, so the two primitives are named here and each has a path that can
# be RUN and verified.
#
# FORCING A BRANCH (this is how both branches are tested on Linux):
#   LANE_PTY_MODE=util-linux|none|auto     (default auto)
#   LANE_DETACH_MODE=setsid|nohup|auto     (default auto)
#
# Verified by tools/lane-platform-selftest.sh — which exercises BOTH branches, because a fallback
# nobody has run is not a fallback.
#
set -u

# ── pty: util-linux `script -qec` gives the lane a terminal ─────────────────────────────────────
# A pty matters because several model CLIs change behaviour (or refuse) without a TTY. Losing it is
# a DEGRADATION, not a failure: output still lands in the log and the runner still records the exit
# status. So the fallback runs the command directly instead of giving up.
lane_platform_pty_mode() {
  case "${LANE_PTY_MODE:-auto}" in
    util-linux) echo "util-linux"; return 0;;
    none)       echo "none";       return 0;;
  esac
  # `script --version` is the cheapest discriminator: util-linux prints "script from util-linux X.Y".
  # BSD/macOS `script` has no --version, and MSYS2 without util-linux has no script at all.
  if script --version 2>/dev/null | grep -q 'util-linux'; then
    echo "util-linux"
  else
    echo "none"
  fi
}

# ── detach: survive the terminal's process group ────────────────────────────────────────────────
# The lane wrapper and the deadline watchdog must outlive the shell that started them (the terminal
# can be closed, and the wrapper caps a command at ~120s). `setsid` starts a new session; `nohup`
# only ignores SIGHUP. `nohup` is the weaker but far more widely available primitive, so it is the
# fallback rather than a hard failure.
lane_platform_detach_cmd() {
  case "${LANE_DETACH_MODE:-auto}" in
    setsid) echo "setsid"; return 0;;
    nohup)  echo "nohup";  return 0;;
  esac
  if command -v setsid > /dev/null 2>&1; then
    echo "setsid"
  else
    echo "nohup"
  fi
}

# lane_spawn <timeout-secs> <inner-shell-command> <log-file>
# Starts a lane detached under a hard budget, with a pty when one is available.
# Sets LANE_SPAWN_PID. Returns 2 on bad arguments, 1 if nothing started.
lane_spawn() {
  local secs="${1:-}" inner="${2:-}" log="${3:-}"
  LANE_SPAWN_PID=""
  if [ -z "$secs" ] || [ -z "$inner" ] || [ -z "$log" ]; then
    echo "lane_spawn: need 3 arguments: <timeout-secs> <inner-shell-command> <log-file>" >&2
    return 2
  fi
  # Validate the budget here rather than letting `timeout` fail inside a background job, where the
  # error would only ever reach the lane's own log.
  case "$secs" in
    ''|*[!0-9]*)
      echo "lane_spawn: timeout must be a positive integer of seconds, got '$secs'" >&2
      return 2;;
  esac
  [ "$secs" -gt 0 ] || { echo "lane_spawn: timeout must be > 0, got '$secs'" >&2; return 2; }

  local detach pty
  detach="$(lane_platform_detach_cmd)"
  pty="$(lane_platform_pty_mode)"

  if [ "$pty" = "util-linux" ]; then
    # shellcheck disable=SC2086  # $detach is deliberately word-split: it is a command name.
    $detach timeout --signal=TERM --kill-after=60 "$secs" script -qec "$inner" /dev/null \
      > "$log" 2>&1 < /dev/null &
  else
    # shellcheck disable=SC2086
    $detach timeout --signal=TERM --kill-after=60 "$secs" bash -c "$inner" \
      > "$log" 2>&1 < /dev/null &
  fi
  LANE_SPAWN_PID=$!
  [ -n "$LANE_SPAWN_PID" ] || return 1
  return 0
}

# lane_spawn_note - one line describing the mode actually in use, for the dispatch output.
lane_spawn_note() {
  case "$(lane_platform_pty_mode)" in
    util-linux) echo "pty: util-linux script (lane gets a terminal)";;
    *)          echo "pty: NONE - no util-linux 'script' here, so the lane runs WITHOUT a terminal."
                echo "     That is a degradation, not a failure. On Windows use WSL2 for full mode.";;
  esac
  echo "   detach: $(lane_platform_detach_cmd)"
}

# lane_detach_background <cmd...> - start something detached that must outlive this shell.
lane_detach_background() {
  LANE_SPAWN_PID=""
  [ "$#" -gt 0 ] || { echo "lane_detach_background: need a command" >&2; return 2; }
  local detach
  detach="$(lane_platform_detach_cmd)"
  # shellcheck disable=SC2086
  $detach "$@" </dev/null > /dev/null 2>&1 &
  LANE_SPAWN_PID=$!
  [ -n "$LANE_SPAWN_PID" ] || return 1
  return 0
}
