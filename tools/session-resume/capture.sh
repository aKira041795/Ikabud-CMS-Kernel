#!/usr/bin/env bash
# Sole heartbeat writer: immutable snapshot -> marker commit -> last-session.
set -euo pipefail
source "$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)/lib.sh"
resume_prepare_state
resume_capture_environment
readonly BOOT_ID="$(resume_boot_id)"
readonly INTERVAL="${IKABUD_RESUME_CAPTURE_INTERVAL:-5}"
readonly MAX_ITERATIONS="${IKABUD_RESUME_CAPTURE_ITERATIONS:-0}"
[[ "$INTERVAL" =~ ^[1-5]$ ]] || { printf 'capture interval must be 1..5 seconds\n' >&2; exit 2; }
[[ "$MAX_ITERATIONS" =~ ^[0-9]+$ ]] || exit 2
stop=0
trap 'stop=1' TERM INT HUP
cleanup() {
    resume_lock
    resume_py pid-unlink "$$"
    resume_unlock
}
trap cleanup EXIT

resume_lock
# pid-write also validates the still-armed, same-boot marker while the stable
# lock excludes disarm. A capture queued behind disarm therefore cannot publish.
resume_py pid-write "$$" "$RESUME_TOOLS_DIR/capture.sh" "$BOOT_ID"
resume_unlock
iterations=0
while (( ! stop )); do
    resume_refresh_git_environment
    resume_lock
    # Holding the stable lock makes marker generation check and publication one
    # transaction with respect to graceful disarm.
    resume_py capture "$BOOT_ID"
    resume_unlock
    iterations=$((iterations + 1))
    if (( MAX_ITERATIONS > 0 && iterations >= MAX_ITERATIONS )); then break; fi
    sleep "$INTERVAL" & wait $! || true
done
