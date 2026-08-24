#!/usr/bin/env bash
# First-login recovery decision and idempotent staged replay.
set -Eeuo pipefail
source "$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)/lib.sh"
resume_prepare_state
readonly BOOT_ID="$(resume_boot_id)"
readonly DRY_RUN="${IKABUD_RESUME_DRY_RUN:-0}"
[[ "$DRY_RUN" == 0 || "$DRY_RUN" == 1 ]] || exit 2

resume_lock
if [[ ! -e "$RESUME_STATE_DIR/unclean.marker" && ! -L "$RESUME_STATE_DIR/unclean.marker" ]]; then
    resume_unlock
    printf 'resume: no marker; clean login, no replay\n'
    exit 0
fi
# Recovery validates current read-only Git identity; it never checks out or
# otherwise mutates the branch/worktree. Establish the same Pi identity/version,
# argv, and Git environment that capture recorded, so live validation matches
# (state.py validate_live requires IKABUD_RESUME_PI_IDENTITY/PI_VERSION).
resume_capture_environment
set +e
decision="$(resume_py decide "$BOOT_ID")"
rc=$?
set -e
if (( rc == 10 )); then
    resume_unlock
    printf 'resume: same boot; fail closed, no replay\n'
    exit 0
elif (( rc != 0 )); then
    resume_unlock
    printf 'resume: invalid or stale state; fail closed, no replay\n' >&2
    exit 2
fi
generation="$(jq -er '.generation' <<<"$decision")"
snapshot="$(jq -er '.snapshot' <<<"$decision")"
nonce="$(resume_py nonce "$generation" "$snapshot")"
stage=network
failure() {
    local rc=$?
    resume_py failure "$generation" "$stage" "recovery stage failed (rc=$rc)" || true
    resume_unlock || true
    exit "$rc"
}
trap failure ERR

# Network wait is bounded. Local restoration continues offline after 60s.
if [[ "$DRY_RUN" == 0 ]]; then
    network_wait="${IKABUD_RESUME_NETWORK_WAIT_SECONDS:-60}"
    [[ "$network_wait" =~ ^[0-9]+$ ]] && (( network_wait <= 60 )) || { printf 'network wait must be 0..60 seconds\n' >&2; false; }
    deadline=$((SECONDS + network_wait))
    while :; do
        online=0
        for state in /sys/class/net/*/operstate; do
            [[ "${IKABUD_RESUME_TEST_OFFLINE:-0}" != 1 && -r "$state" && "$(<"$state")" == up ]] && online=1 && break
        done
        (( online || SECONDS >= deadline )) && break
        sleep 2
    done
fi

for stage in vscode chat pi; do
    if resume_py ack-valid "$generation" "$stage" "$nonce" "$snapshot"; then
        ack_rc=0
    else
        ack_rc=$?
    fi
    if (( ack_rc == 0 )); then
        if [[ "$DRY_RUN" == 0 ]]; then
            # A prior acknowledgement suppresses launch only after a fresh identity probe.
            resume_adapter "$stage" probe "$snapshot" "$nonce"
        fi
        continue
    elif (( ack_rc != 1 )); then
        printf 'malformed acknowledgement; refusing restore\n' >&2
        false
    fi
    if [[ "$DRY_RUN" == 1 ]]; then
        resume_py ack "$generation" "$stage" "$nonce" "$snapshot"
        continue
    fi
    if resume_adapter "$stage" probe "$snapshot" "$nonce"; then
        probe_rc=0
    else
        probe_rc=$?
    fi
    if (( probe_rc != 0 )); then
        resume_adapter "$stage" restore "$snapshot" "$nonce"
        if [[ "$stage" == chat ]]; then
            chat_wait="${IKABUD_RESUME_CHAT_WAIT_SECONDS:-600}"
            chat_retry="${IKABUD_RESUME_CHAT_RETRY_SECONDS:-5}"
            [[ "$chat_wait" =~ ^[0-9]+$ && "$chat_retry" =~ ^[1-5]$ ]] && (( chat_wait <= 600 )) || { printf 'chat retry bounds are invalid\n' >&2; false; }
            chat_deadline=$((SECONDS + chat_wait))
            until resume_adapter chat probe "$snapshot" "$nonce"; do
                (( SECONDS < chat_deadline )) || { printf 'exact chat probe timed out\n' >&2; false; }
                sleep "$chat_retry"
            done
        else
            resume_adapter "$stage" probe "$snapshot" "$nonce"
        fi
    fi
    if [[ "${IKABUD_RESUME_TEST_INTERRUPT_STAGE:-}" == "launch-before-ack:$stage" ]]; then
        printf 'forced launch-before-ack interruption\n' >&2
        false
    fi
    resume_py ack "$generation" "$stage" "$nonce" "$snapshot"
done
stage=complete
resume_py ack "$generation" complete "$nonce" "$snapshot"
stage=rollover
new_generation="$(resume_py rollover "$generation" "$snapshot" "$BOOT_ID")"
trap - ERR
resume_unlock
printf 'resume: replay complete; generation %s -> %s\n' "$generation" "$new_generation"
# 10 tells the desktop chain that rollover already armed the current session.
exit 10
