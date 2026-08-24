#!/usr/bin/env bash
# Exclusive owner of marker arming and graceful disarming.
set -euo pipefail
source "$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)/lib.sh"
resume_prepare_state

case "${1:-}" in
arm)
    resume_capture_environment
    boot_id="$(resume_boot_id)"
    resume_lock
    resume_py arm "$boot_id"
    resume_unlock
    ;;
disarm)
    deadline=$((SECONDS + 20))
    join_hook_done=0
    saw_writer=0
    while :; do
        # Startup/PID publication and this final scan are serialized. pid-signal
        # opens a pidfd and revalidates start-time/exe/argv immediately before
        # signaling, so exit and numeric PID reuse cannot redirect SIGTERM.
        resume_lock
        set +e
        identity="$(resume_py pid-status)"
        identity_rc=$?
        set -e
        if (( identity_rc == 3 )); then
            if (( ! saw_writer )); then
                resume_unlock
                printf 'capture is starting; marker retained\n' >&2
                exit 2
            fi
            resume_py unlink-marker
            resume_unlock
            break
        elif (( identity_rc == 1 )); then
            resume_unlock
            printf 'stale capture identity; marker retained\n' >&2
            exit 2
        elif (( identity_rc != 0 )); then
            resume_unlock
            printf 'capture PID identity is invalid; marker retained\n' >&2
            exit 2
        fi
        saw_writer=1
        pid="$(jq -er .pid <<<"$identity")"
        start_time="$(jq -er .start_time <<<"$identity")"
        executable="$(jq -er .executable <<<"$identity")"
        argv_json="$(jq -c .argv <<<"$identity")"
        set +e
        resume_py pid-signal >/dev/null
        signal_rc=$?
        set -e
        resume_unlock
        if (( signal_rc != 0 )); then
            printf 'capture changed before pidfd signal; marker retained\n' >&2
            exit 2
        fi

        while resume_py pid-match "$pid" "$start_time" "$executable" "$argv_json"; do
            (( SECONDS < deadline )) || { printf 'capture did not stop; marker retained\n' >&2; exit 2; }
            sleep 0.05
        done
        # Deterministically exercise a replacement publishing between join and
        # the final scan. The loop must discover, stop, and join that writer too.
        if (( ! join_hook_done )) && [[ -n "${IKABUD_RESUME_TEST_DISARM_JOIN_READY:-}" && -n "${IKABUD_RESUME_TEST_DISARM_JOIN_RELEASE:-}" ]]; then
            : >"$IKABUD_RESUME_TEST_DISARM_JOIN_READY"
            while [[ ! -e "$IKABUD_RESUME_TEST_DISARM_JOIN_RELEASE" ]]; do
                (( SECONDS < deadline )) || { printf 'disarm test synchronization timed out\n' >&2; exit 2; }
                sleep 0.01
            done
            join_hook_done=1
        fi
        # Give the exiting writer's cleanup trap an opportunity to remove its
        # exact PID record before rescanning for a possible replacement.
        sleep 0.05
    done
    ;;
*)
    printf 'usage: %s {arm|disarm}\n' "$0" >&2
    exit 2
    ;;
esac
