#!/usr/bin/env bash
#
# tools/lane-platform-selftest.sh — proves BOTH platform branches, including the one this machine
# does not naturally use.
#
# WHY: a fallback nobody has run is not a fallback. The Windows/macOS path (no util-linux `script`)
# is the one a recipient of this harness depends on, and the machine running this test may well
# HAVE util-linux - so the fallback is forced with LANE_PTY_MODE=none / LANE_DETACH_MODE=nohup and
# executed, not described.
#
# Every case has a must-allow and, where a guard exists, a must-refuse direction: a detector that
# always answers "none" passes a fallback test just as well as a correct one, which is why the
# detection cases are falsified with stub `script` binaries in both directions.
#
# Usage: bash tools/lane-platform-selftest.sh
#
set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT" || exit 1

pass=0; fail=0
ok()  { echo "   PASS  $1"; pass=$((pass+1)); }
bad() { echo "   FAIL  $1"; fail=$((fail+1)); }
chk() { if [ "$2" = "$3" ]; then ok "$1"; else bad "$1 (got '$2', want '$3')"; fi; }

P="$(mktemp -d)"
trap 'rm -rf "$P"' EXIT

. tools/lane-platform.sh

# Wait for a pattern to appear in a file, bounded. The spawned process is detached, so it cannot be
# waited on directly - which is exactly why the harness polls the log rather than a pid.
wait_for() { # wait_for <file> <pattern> <seconds>
  local f="$1" pat="$2" secs="${3:-15}" i=0
  while [ "$i" -lt "$secs" ]; do
    grep -q "$pat" "$f" 2>/dev/null && return 0
    sleep 1; i=$((i+1))
  done
  return 1
}

echo "== which platform primitives does this host have? =="

native_pty="$(lane_platform_pty_mode)"
native_detach="$(lane_platform_detach_cmd)"
echo "   native pty mode:    $native_pty"
echo "   native detach mode: $native_detach"

case "$native_pty" in
  util-linux|none) ok "auto pty detection returns a known mode ($native_pty)";;
  *) bad "auto pty detection returned '$native_pty', which nothing handles";;
esac
case "$native_detach" in
  setsid|nohup) ok "auto detach detection returns a known primitive ($native_detach)";;
  *) bad "auto detach detection returned '$native_detach', which nothing handles";;
esac

# ── detection is by CONTENT, not by presence ────────────────────────────────────────────────────
# A `script` that exists but is BSD/macOS must NOT be treated as util-linux, or the pty branch
# would be chosen and every lane would die inside a background job.
mkdir -p "$P/fakebsd" "$P/fakeutl"
printf '#!/bin/sh\necho "script: illegal option -- -" >&2\nexit 1\n' > "$P/fakebsd/script"
printf '#!/bin/sh\necho "script from util-linux 9.9"\n'                    > "$P/fakeutl/script"
chmod +x "$P/fakebsd/script" "$P/fakeutl/script"

got_bsd="$(PATH="$P/fakebsd:$PATH" LANE_PTY_MODE=auto bash -c '. tools/lane-platform.sh; lane_platform_pty_mode')"
chk "a non-util-linux 'script' is refused (falls back to none)" "$got_bsd" "none"

got_utl="$(PATH="$P/fakeutl:$PATH" LANE_PTY_MODE=auto bash -c '. tools/lane-platform.sh; lane_platform_pty_mode')"
chk "a real util-linux 'script' is detected" "$got_utl" "util-linux"

# ── forcing a branch must work, or the fallback is untestable ────────────────────────────────────
chk "LANE_PTY_MODE=none forces no-pty"    "$(LANE_PTY_MODE=none    bash -c '. tools/lane-platform.sh; lane_platform_pty_mode')"    "none"
chk "LANE_PTY_MODE=util-linux forces pty" "$(LANE_PTY_MODE=util-linux bash -c '. tools/lane-platform.sh; lane_platform_pty_mode')" "util-linux"
chk "LANE_DETACH_MODE=nohup forces nohup" "$(LANE_DETACH_MODE=nohup  bash -c '. tools/lane-platform.sh; lane_platform_detach_cmd')" "nohup"
chk "LANE_DETACH_MODE=setsid forces setsid" "$(LANE_DETACH_MODE=setsid bash -c '. tools/lane-platform.sh; lane_platform_detach_cmd')" "setsid"

# ── the spawn, in BOTH modes ─────────────────────────────────────────────────────────────────────
# The sentinel is what lane.sh trusts, so it is asserted here rather than assumed: a spawn that runs
# the command but loses its exit status is the false-green this whole harness is built to avoid.
spawn_case() { # spawn_case <label> <pty-mode> <detach-mode>
  local label="$1" ptymode="$2" detachmode="$3"
  local log="$P/spawn-$ptymode-$detachmode.log"
  rm -f "$log"
  LANE_PTY_MODE="$ptymode" LANE_DETACH_MODE="$detachmode" \
    lane_spawn 20 'echo LANE_RAN_OK; exit 0' "$log"
  if [ -z "${LANE_SPAWN_PID:-}" ]; then
    bad "$label: lane_spawn reported no pid"
    return
  fi
  disown 2>/dev/null || true
  if wait_for "$log" 'LANE_RAN_OK' 20; then
    ok "$label: the command ran and its output reached the log"
  else
    bad "$label: the command's output never reached the log"
  fi
}

spawn_case "pty mode (util-linux)"    util-linux setsid
spawn_case "NO-PTY fallback (Windows/macOS path)" none setsid
spawn_case "nohup detach fallback"    util-linux nohup
spawn_case "no pty AND nohup (worst case)" none nohup

# The exit status must survive the spawn. The pty leg uses `script -e` to carry the child's status,
# but the harness does NOT depend on it - the generated runner records its own exit status in an
# EXIT trap, which is the whole reason it is a file. So the status is asserted on the no-pty path,
# where it is unambiguously the spawn's own.
log2="$P/exit-none.log"; rm -f "$log2"
LANE_PTY_MODE=none bash -c 'set -u; . tools/lane-platform.sh; lane_spawn 20 "exit 7" "'"$log2"'" >/dev/null 2>&1; wait "$LANE_SPAWN_PID" 2>/dev/null; echo "rc=$?"' > "$P/exit-none.rc" 2>&1
rc_none="$(grep -o 'rc=[0-9]*' "$P/exit-none.rc" | head -1 | cut -d= -f2)"
chk "the no-pty path propagates the command's exit status" "${rc_none:-none}" "7"

# ── a bad argument must fail LOUDLY, not start a half-configured lane ────────────────────────────
LANE_SPAWN_PID=""
lane_spawn 20 'echo x' >/dev/null 2>&1; chk "lane_spawn with 2 args refuses" "$?" "2"
lane_spawn "" 'echo x' "$P/x.log" >/dev/null 2>&1; chk "lane_spawn with an empty budget refuses" "$?" "2"
lane_spawn "abc" 'echo x' "$P/x.log" >/dev/null 2>&1; chk "lane_spawn with a non-numeric budget refuses" "$?" "2"
lane_spawn 0 'echo x' "$P/x.log" >/dev/null 2>&1; chk "lane_spawn with a zero budget refuses" "$?" "2"
# `timeout 0` means NO timeout, so a zero budget reaching it would produce an unbounded lane - the
# opposite of what the caller asked for. That is why zero is refused rather than passed through.
lane_detach_background >/dev/null 2>&1; chk "lane_detach_background with no command refuses" "$?" "2"

# ── the note must not claim a pty it does not have ───────────────────────────────────────────────
note_claimed="$(LANE_PTY_MODE=util-linux bash -c '. tools/lane-platform.sh; lane_spawn_note' | head -1)"
case "$note_claimed" in
  *util-linux*) ok "the dispatch note reports full pty mode when it has it";;
  *) bad "the dispatch note did not report pty mode: $note_claimed";;
esac
note_plain="$(LANE_PTY_MODE=none bash -c '. tools/lane-platform.sh; lane_spawn_note' | head -1)"
case "$note_plain" in
  *NONE*) ok "the dispatch note says NONE when there is no pty (it never inflates the mode)";;
  *) bad "the dispatch note overclaimed without a pty: $note_plain";;
esac

# ── END TO END: a REAL lane carried dispatch -> landing with NO pty ─────────────────────────────
# The strongest evidence for the Windows/macOS path, and the cheapest: it is the actual harness, not
# a simulation of it. A fallback that can start a process but cannot get a landing RECORDED would be
# worthless, and only this case proves the record survives.
if [ -f "$ROOT/tools/lane.sh" ]; then
  fixture="$P/plat-none-lane.sh"
  { echo '#!/usr/bin/env bash'
    echo 'echo "status: PASS"'
    echo 'exit 0'
  } > "$fixture"
  chmod +x "$fixture"
  rm -f "$ROOT/.ai/runs/plat-none.landed.json" "$ROOT/.ai/runs/plat-none.log"
  rmdir "$ROOT/.ai/runs/plat-none.commit.lock" 2>/dev/null || true
  LANE_PTY_MODE=none bash "$ROOT/tools/lane.sh" run plat-none "$fixture" \
    --timeout=60 --wait-grace=2 --slice=25 \
    --acceptance='exit 1' --pass-looks-like='the fixture lane reaches LANDED' > "$P/plat-none.mon.log" 2>&1
  marker="$ROOT/.ai/runs/plat-none.landed.json"
  if [ -f "$marker" ] \
     && grep -q '"state": "landed"' "$marker" \
     && grep -q '"exit_code": 0' "$marker"; then
    ok "a real lane completes dispatch -> recorded landing with NO pty (the Windows/macOS path)"
  else
    bad "a lane could not be carried end to end without a pty - the fallback is not usable"
  fi
  if grep -q 'WITHOUT a terminal' "$P/plat-none.mon.log"; then
    ok "the dispatch said the lane was running without a terminal (it never hid the degradation)"
  else
    bad "the dispatch did not disclose that it ran without a pty"
  fi
fi

echo
echo "== lane-platform-selftest: $pass passed, $fail failed =="
[ "$fail" -eq 0 ] || exit 1
exit 0
