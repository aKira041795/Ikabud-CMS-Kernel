#!/usr/bin/env bash
set -euo pipefail

readonly TESTS_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
readonly CASES=(
    schema_validation
    real_entrypoint_live_pi_identity
    state_permissions
    secret_rejection
    safe_argv
    locking
    boot_generation_sequence
    heartbeat_skew_45s
    crash_commit_selection
    path_symlink_rejection
    acknowledgements
    ack_intent_tampering
    disarm_coordination
    capture_races_pid_reuse
    concurrent_triggers
    symlink_swap_windows
    adapter_swap_pinning
    adapter_retry_idempotence
    partial_failure_retry
    branch_mismatch_durable_adr
    wrong_pi_and_offline
    forced_commit_interruptions
    preflight_adapter_verification
    atomic_generation_replacement
)

failures=0
log_dir="$(mktemp -d "${TMPDIR:-/tmp}/ikabud-resume-test-logs.XXXXXX")"
trap 'rm -rf -- "$log_dir"' EXIT
for name in "${CASES[@]}"; do
    if bash "$TESTS_DIR/cases.sh" "$name" >"$log_dir/$name.log" 2>&1; then
        printf '%s: PASS\n' "$name"
    else
        printf '%s: FAIL\n' "$name"
        failures=$((failures + 1))
        printf '%s\n' "--- $name diagnostics ---" >&2
        tail -n 30 "$log_dir/$name.log" >&2
    fi
done

(( failures == 0 ))
