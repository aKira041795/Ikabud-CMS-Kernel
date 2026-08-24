#!/usr/bin/env bash
set -euo pipefail

readonly TESTS_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
readonly TOOLS_DIR="$(cd -- "$TESTS_DIR/.." && pwd -P)"
readonly REPO_ROOT="$(cd -- "$TOOLS_DIR/../.." && pwd -P)"
readonly BOOT_A=11111111-1111-4111-8111-111111111111
readonly BOOT_B=22222222-2222-4222-8222-222222222222
readonly BOOT_C=33333333-3333-4333-8333-333333333333
TMP_ROOT=""

cleanup() {
    if [[ -n "$TMP_ROOT" && -d "$TMP_ROOT" ]]; then
        rm -rf -- "$TMP_ROOT"
    fi
    rm -f -- "$TOOLS_DIR/tests/.resume-dirty.$$" "$TOOLS_DIR/tests/.contract.$$" "$TOOLS_DIR/tests/.contract.$$.old"
}
trap cleanup EXIT

setup_env() {
    if [[ -n "$TMP_ROOT" && -d "$TMP_ROOT" ]]; then rm -rf -- "$TMP_ROOT"; fi
    TMP_ROOT="$(mktemp -d "${TMPDIR:-/tmp}/ikabud-resume-test.XXXXXX")"
    export IKABUD_RESUME_STATE_DIR="$TMP_ROOT/state"
    export IKABUD_RESUME_BOOT_ID="$BOOT_A"
    export IKABUD_RESUME_DRY_RUN=1
    export IKABUD_RESUME_CAPTURE_INTERVAL=1
    export IKABUD_RESUME_CAPTURE_ITERATIONS=0
    mkdir -p -- "$TMP_ROOT/bin" "$TMP_ROOT/venv/bin"
    printf '#!/usr/bin/env bash\nif [[ "${1:-}" == --version ]]; then printf "1.0.0\\n"; fi\nexit 0\n' >"$TMP_ROOT/bin/pi"
    printf '#!/usr/bin/env bash\nexit 0\n' >"$TMP_ROOT/venv/bin/python"
    chmod 0700 "$TMP_ROOT/bin/pi" "$TMP_ROOT/venv/bin/python"
    export IKABUD_RESUME_PI_EXECUTABLE="$TMP_ROOT/bin/pi"
    export IKABUD_RESUME_PI_VERSION=1.0.0
    export IKABUD_RESUME_PI_IDENTITY='pi - AI coding assistant'
    IKABUD_RESUME_PI_ARGV="$(python3 - "$IKABUD_RESUME_PI_EXECUTABLE" <<'PY'
import json, sys
print(json.dumps([sys.argv[1], "--session-id", "fixture-session"]))
PY
)"
    export IKABUD_RESUME_PI_ARGV
    export IKABUD_RESUME_WORKSPACE="$REPO_ROOT"
    export IKABUD_RESUME_CWD="$REPO_ROOT"
    export IKABUD_RESUME_VENV="$TMP_ROOT/venv"
    export IKABUD_RESUME_VENV_PYTHON="$TMP_ROOT/venv/bin/python"
    export IKABUD_RESUME_CONTRACT="$REPO_ROOT/.ai/current-task.md"
    export IKABUD_RESUME_WORKBENCH_TASK="$TESTS_DIR/fixtures/task.json"
    export IKABUD_RESUME_CHAT_EXTENSION_ID=openai.chatgpt
    export IKABUD_RESUME_CHAT_EXTENSION_VERSION=1.2.3
    export IKABUD_RESUME_CHAT_SESSION_ID=chat-session-1
    export IKABUD_RESUME_VSCODE_PROFILE=Phase3Test
    export IKABUD_RESUME_GIT_BRANCH="$(git -C "$REPO_ROOT" branch --show-current)"
    export IKABUD_RESUME_GIT_HEAD="$(git -C "$REPO_ROOT" rev-parse HEAD)"
}

assert() {
    "$@" || { printf 'assertion failed: %q' "$1" >&2; printf ' %q' "${@:2}" >&2; printf '\n' >&2; return 1; }
}

expect_status() {
    local expected="$1"
    shift
    set +e
    if [[ "${TEST_SHOW_OUTPUT:-0}" == 1 ]]; then "$@"; else "$@" >/dev/null 2>&1; fi
    local actual=$?
    set -e
    [[ "$actual" == "$expected" ]]
}

arm() {
    "$TOOLS_DIR/session-guard.sh" arm >/dev/null
}

prepare_python() {
    if ! declare -F resume_py >/dev/null; then
        # shellcheck source=../lib.sh
        source "$TOOLS_DIR/lib.sh"
    fi
    resume_prepare_state
}

case_schema_validation() {
    setup_env
    arm
    prepare_python
    python3 "$TOOLS_DIR/state.py" validate "$IKABUD_RESUME_STATE_DIR/last-session.json" >/dev/null
    cp "$IKABUD_RESUME_STATE_DIR/last-session.json" "$TMP_ROOT/missing.json"
    python3 - "$TMP_ROOT/missing.json" <<'PY'
import json, pathlib, sys
p = pathlib.Path(sys.argv[1]); value = json.loads(p.read_text()); del value["chat"]; p.write_text(json.dumps(value))
PY
    expect_status 2 env RESUME_STATE_DIR="$IKABUD_RESUME_STATE_DIR" RESUME_REPO_ROOT="$REPO_ROOT" RESUME_SCHEMA="$TOOLS_DIR/session.schema.json" python3 "$TOOLS_DIR/state.py" validate "$TMP_ROOT/missing.json"
    printf '{not-json\n' >"$TMP_ROOT/malformed.json"
    expect_status 2 env RESUME_STATE_DIR="$IKABUD_RESUME_STATE_DIR" RESUME_REPO_ROOT="$REPO_ROOT" RESUME_SCHEMA="$TOOLS_DIR/session.schema.json" python3 "$TOOLS_DIR/state.py" validate "$TMP_ROOT/malformed.json"
}

case_real_entrypoint_live_pi_identity() {
    setup_env
    arm
    IKABUD_RESUME_CAPTURE_ITERATIONS=1 "$TOOLS_DIR/capture.sh" >/dev/null
    local old_generation new_generation
    old_generation="$(jq -r .generation "$IKABUD_RESUME_STATE_DIR/unclean.marker")"
    [[ "$(jq -r .pi.version "$IKABUD_RESUME_STATE_DIR/last-session.json")" == 1.0.0 ]]
    unset IKABUD_RESUME_PI_IDENTITY IKABUD_RESUME_PI_VERSION
    export IKABUD_RESUME_BOOT_ID="$BOOT_B"
    expect_status 10 "$TOOLS_DIR/resume.sh"
    new_generation="$(jq -r .generation "$IKABUD_RESUME_STATE_DIR/unclean.marker")"
    [[ "$new_generation" != "$old_generation" ]]
    [[ "$(jq -r .boot_id "$IKABUD_RESUME_STATE_DIR/unclean.marker")" == "$BOOT_B" ]]
}

case_state_permissions() {
    setup_env
    arm
    IKABUD_RESUME_CAPTURE_ITERATIONS=1 "$TOOLS_DIR/capture.sh" >/dev/null
    while IFS= read -r -d '' path; do
        [[ "$(stat -c %a "$path")" == 700 ]]
    done < <(find "$IKABUD_RESUME_STATE_DIR" -type d -print0)
    while IFS= read -r -d '' path; do
        [[ "$(stat -c %a "$path")" == 600 ]]
    done < <(find "$IKABUD_RESUME_STATE_DIR" -type f -print0)
}

case_secret_rejection() {
    setup_env
    arm
    cp "$IKABUD_RESUME_STATE_DIR/last-session.json" "$TMP_ROOT/secret.json"
    python3 - "$TMP_ROOT/secret.json" <<'PY'
import json, pathlib, sys
p = pathlib.Path(sys.argv[1]); value = json.loads(p.read_text()); value["workspace"]["profile"] = "Bearer credential"; p.write_text(json.dumps(value))
PY
    expect_status 2 env RESUME_STATE_DIR="$IKABUD_RESUME_STATE_DIR" RESUME_REPO_ROOT="$REPO_ROOT" RESUME_SCHEMA="$TOOLS_DIR/session.schema.json" python3 "$TOOLS_DIR/state.py" validate "$TMP_ROOT/secret.json"
}

case_safe_argv() {
    setup_env
    arm
    local snapshot nonce adapter payload
    snapshot="$(find "$IKABUD_RESUME_STATE_DIR/snapshots" -type f)"
    nonce=44444444-4444-4444-8444-444444444444
    adapter="$TMP_ROOT/bin/ikabud-resume-vscode-adapter"
    cat >"$adapter" <<'SH'
#!/usr/bin/env bash
set -euo pipefail
printf '%s\n' "$@" >"$ADAPTER_LOG"
sha256sum "$3" | cut -d' ' -f1 >>"$ADAPTER_LOG"
SH
    chmod 0700 "$adapter"
    export ADAPTER_LOG="$TMP_ROOT/adapter.log"
    export IKABUD_RESUME_VSCODE_ADAPTER="$adapter"
    prepare_python
    resume_adapter vscode probe "$snapshot" "$nonce"
    [[ "$(sed -n '1p' "$ADAPTER_LOG")" == probe ]]
    [[ "$(sed -n '2p' "$ADAPTER_LOG")" == --snapshot ]]
    [[ "$(sed -n '4p' "$ADAPTER_LOG")" == --nonce ]]
    [[ "$(sed -n '5p' "$ADAPTER_LOG")" == "$nonce" ]]
    [[ "$(sed -n '3p' "$ADAPTER_LOG")" == /proc/*/fd/* ]]
    [[ "$(sed -n '6p' "$ADAPTER_LOG")" == "$(sha256sum "$snapshot" | cut -d' ' -f1)" ]]
    payload="$TMP_ROOT/evaluated"
    expect_status 2 resume_adapter vscode "probe;touch $payload" "$snapshot" "$nonce"
    [[ ! -e "$payload" ]]
    IKABUD_RESUME_PI_ARGV="$(python3 - "$IKABUD_RESUME_PI_EXECUTABLE" "$payload" <<'PY'
import json, sys
print(json.dumps([sys.argv[1], "--session-id", "ok", ";touch", sys.argv[2]]))
PY
)"
    export IKABUD_RESUME_PI_ARGV
    rm -f "$IKABUD_RESUME_STATE_DIR/unclean.marker"
    expect_status 2 "$TOOLS_DIR/session-guard.sh" arm
    [[ ! -e "$payload" ]]
}

case_locking() {
    setup_env
    prepare_python
    exec 8>>"$RESUME_LOCK"
    flock -x 8
    ( set +e; "$TOOLS_DIR/session-guard.sh" arm >/dev/null 2>&1; printf '%s\n' "$?" >"$TMP_ROOT/arm.rc" ) &
    local arm_pid=$!
    sleep 0.15
    kill -0 "$arm_pid"
    flock -u 8
    wait "$arm_pid"
    [[ "$(<"$TMP_ROOT/arm.rc")" == 0 ]]

    export IKABUD_RESUME_BOOT_ID="$BOOT_B"
    flock -x 8
    ( set +e; "$TOOLS_DIR/resume.sh" >/dev/null 2>&1; printf '%s\n' "$?" >"$TMP_ROOT/resume.rc" ) &
    local resume_pid=$!
    sleep 0.15
    kill -0 "$resume_pid"
    flock -u 8
    wait "$resume_pid"
    exec 8>&-
    [[ "$(<"$TMP_ROOT/resume.rc")" == 10 ]]
}

case_boot_generation_sequence() {
    setup_env
    arm
    prepare_python
    expect_status 10 resume_py decide "$BOOT_A"
    [[ "$(resume_py capture "$BOOT_A")" == 2 ]]
    [[ "$(jq -r .sequence "$IKABUD_RESUME_STATE_DIR/unclean.marker")" == 2 ]]
    local replay
    replay="$(resume_py decide "$BOOT_B")"
    [[ "$(jq -r .decision <<<"$replay")" == replay ]]
    cp "$IKABUD_RESUME_STATE_DIR/unclean.marker" "$TMP_ROOT/marker.good"
    python3 - "$IKABUD_RESUME_STATE_DIR/unclean.marker" <<'PY'
import json, pathlib, sys
p=pathlib.Path(sys.argv[1]); v=json.loads(p.read_text()); v["generation"]="55555555-5555-4555-8555-555555555555"; p.write_text(json.dumps(v))
PY
    expect_status 2 resume_py decide "$BOOT_B"
    cp "$TMP_ROOT/marker.good" "$IKABUD_RESUME_STATE_DIR/unclean.marker"
}

case_heartbeat_skew() {
    setup_env
    arm
    prepare_python
    cp "$IKABUD_RESUME_STATE_DIR/unclean.marker" "$TMP_ROOT/marker.original"
    python3 - "$IKABUD_RESUME_STATE_DIR/unclean.marker" <<'PY'
import datetime as dt, json, os, pathlib, sys
p=pathlib.Path(sys.argv[1]); v=json.loads(p.read_text()); t=dt.datetime.fromisoformat(v["heartbeat_at"].replace("Z", "+00:00")); v["heartbeat_at"]=(t+dt.timedelta(seconds=45)).isoformat(timespec="seconds").replace("+00:00", "Z"); p.write_text(json.dumps(v)); os.chmod(p, 0o600)
PY
    resume_py decide "$BOOT_B" >/dev/null
    cp "$TMP_ROOT/marker.original" "$IKABUD_RESUME_STATE_DIR/unclean.marker"
    python3 - "$IKABUD_RESUME_STATE_DIR/unclean.marker" <<'PY'
import datetime as dt, json, os, pathlib, sys
p=pathlib.Path(sys.argv[1]); v=json.loads(p.read_text()); t=dt.datetime.fromisoformat(v["heartbeat_at"].replace("Z", "+00:00")); v["heartbeat_at"]=(t+dt.timedelta(seconds=46)).isoformat(timespec="seconds").replace("+00:00", "Z"); p.write_text(json.dumps(v)); os.chmod(p, 0o600)
PY
    expect_status 2 resume_py decide "$BOOT_B"
}

case_crash_commit_selection() {
    setup_env
    arm
    prepare_python
    resume_py capture "$BOOT_A" >/dev/null
    local generation seq2 seq3 decision
    generation="$(jq -r .generation "$IKABUD_RESUME_STATE_DIR/unclean.marker")"
    seq2="$IKABUD_RESUME_STATE_DIR/snapshots/$generation/00000000000000000002.json"
    seq3="$IKABUD_RESUME_STATE_DIR/snapshots/$generation/00000000000000000003.json"
    python3 - "$seq2" "$seq3" "$IKABUD_RESUME_STATE_DIR/last-session.json" <<'PY'
import json, os, pathlib, sys
v=json.loads(pathlib.Path(sys.argv[1]).read_text()); v["sequence"]=3
data=(json.dumps(v, sort_keys=True, indent=2)+"\n").encode()
for raw in sys.argv[2:]:
    p=pathlib.Path(raw); p.write_bytes(data); os.chmod(p, 0o600)
PY
    decision="$(resume_py decide "$BOOT_B")"
    [[ "$(jq -r .snapshot <<<"$decision")" == "$seq2" ]]
}

case_path_symlink_rejection() {
    setup_env
    local target="$TMP_ROOT/target" link="$TMP_ROOT/state-link"
    mkdir "$target"
    ln -s "$target" "$link"
    export IKABUD_RESUME_STATE_DIR="$link"
    expect_status 2 "$TOOLS_DIR/session-guard.sh" arm
    [[ -z "$(find "$target" -mindepth 1 -print -quit)" ]]

    export IKABUD_RESUME_STATE_DIR="$TMP_ROOT/state"
    arm
    prepare_python
    python3 - "$IKABUD_RESUME_STATE_DIR/unclean.marker" <<'PY'
import json, pathlib, sys
p=pathlib.Path(sys.argv[1]); v=json.loads(p.read_text()); v["snapshot"]="../escaped.json"; p.write_text(json.dumps(v))
PY
    expect_status 2 resume_py decide "$BOOT_B"
}

case_acknowledgements() {
    setup_env
    arm
    local old_generation old_snapshot nonce before after
    old_generation="$(jq -r .generation "$IKABUD_RESUME_STATE_DIR/unclean.marker")"
    old_snapshot="$(find "$IKABUD_RESUME_STATE_DIR/snapshots/$old_generation" -type f)"
    export IKABUD_RESUME_BOOT_ID="$BOOT_B"
    expect_status 10 "$TOOLS_DIR/resume.sh"
    nonce="$(jq -r .nonce "$IKABUD_RESUME_STATE_DIR/acks/$old_generation/intent.json")"
    for stage in vscode chat pi complete; do
        [[ "$(jq -r .nonce "$IKABUD_RESUME_STATE_DIR/acks/$old_generation/$stage.json")" == "$nonce" ]]
    done
    before="$(sha256sum "$IKABUD_RESUME_STATE_DIR/acks/$old_generation"/*.json)"
    expect_status 0 "$TOOLS_DIR/resume.sh"
    after="$(sha256sum "$IKABUD_RESUME_STATE_DIR/acks/$old_generation"/*.json)"
    [[ "$before" == "$after" ]]
    prepare_python
    expect_status 1 resume_py ack-valid "$old_generation" chat "$BOOT_C" "$old_snapshot"
    expect_status 2 resume_py ack "$old_generation" chat "$BOOT_C" "$old_snapshot"
}

case_disarm_coordination() {
    setup_env
    arm
    IKABUD_RESUME_CAPTURE_ITERATIONS=0 "$TOOLS_DIR/capture.sh" >"$TMP_ROOT/capture.log" 2>&1 &
    local capture_pid=$!
    for _ in {1..40}; do
        [[ -f "$IKABUD_RESUME_STATE_DIR/capture.pid" ]] && break
        sleep 0.05
    done
    [[ -f "$IKABUD_RESUME_STATE_DIR/capture.pid" ]]
    "$TOOLS_DIR/session-guard.sh" disarm >/dev/null
    wait "$capture_pid"
    [[ ! -e "$IKABUD_RESUME_STATE_DIR/unclean.marker" ]]
    [[ ! -e "$IKABUD_RESUME_STATE_DIR/capture.pid" ]]

    arm
    printf '%s\n' "$$" >"$IKABUD_RESUME_STATE_DIR/capture.pid"
    chmod 0600 "$IKABUD_RESUME_STATE_DIR/capture.pid"
    expect_status 2 "$TOOLS_DIR/session-guard.sh" disarm
    [[ -f "$IKABUD_RESUME_STATE_DIR/unclean.marker" ]]
}

install_adapters() {
    export ADAPTER_STATE="$TMP_ROOT/adapter-state"
    mkdir -p "$ADAPTER_STATE"
    local adapter="$TMP_ROOT/bin/adapter-template"
    cat >"$adapter" <<'SH'
#!/usr/bin/env bash
set -euo pipefail
stage="${IKABUD_RESUME_ADAPTER_STAGE:-$(basename "$0")}" 
case "$stage" in
  ikabud-resume-vscode-adapter) stage=vscode; fields='["workspace","profile"]' ;;
  ikabud-resume-chat-adapter) stage=chat; fields='["extension_id","extension_version","session_id"]' ;;
  ikabud-resume-pi-terminal-adapter) stage=pi; fields='["integrated_terminal","pid","executable","sha256","argv","cwd","branch","head","venv_python","contract_path","contract_sha256","workbench_path","workbench_sha256","task_id","state"]' ;;
  vscode) fields='["workspace","profile"]' ;;
  chat) fields='["extension_id","extension_version","session_id"]' ;;
  pi) fields='["integrated_terminal","pid","executable","sha256","argv","cwd","branch","head","venv_python","contract_path","contract_sha256","workbench_path","workbench_sha256","task_id","state"]' ;;
  *) exit 2 ;;
esac
if [[ "${1:-}" == capabilities && "${2:-}" == --json ]]; then
  printf '{"schema":"ikabud.resume-adapter-capabilities.v1","version":"1.0","stage":"%s","actions":["probe","restore"],"identity_fields":%s}\n' "$stage" "$fields"
  exit 0
elif [[ "${1:-}" == preflight-probe && "${2:-}" == --json ]]; then
  printf '{"schema":"ikabud.resume-adapter-preflight.v1","version":"1.0","stage":"%s","supported":true,"identity_probe":true}\n' "$stage"
  exit 0
fi
[[ $# == 5 && "$1" =~ ^(probe|restore)$ && "$2" == --snapshot && "$4" == --nonce ]]
action="$1" snapshot="$3" nonce="$5"
validate_identity() {
python3 - "$stage" "$snapshot" <<'PY'
import hashlib, json, os, pathlib, sys
stage, raw = sys.argv[1:]; s=json.loads(pathlib.Path(raw).read_text())
assert s["workspace"]["path"] == os.environ["IKABUD_RESUME_WORKSPACE"] and s["workspace"]["profile"] == os.environ["IKABUD_RESUME_VSCODE_PROFILE"]
assert s["chat"]["extension_id"] == os.environ["IKABUD_RESUME_CHAT_EXTENSION_ID"] and s["chat"]["extension_version"] == os.environ["IKABUD_RESUME_CHAT_EXTENSION_VERSION"] and s["chat"]["session_id"] == os.environ["IKABUD_RESUME_CHAT_SESSION_ID"]
if stage == "pi":
 p=pathlib.Path(s["pi"]["executable"]); assert hashlib.sha256(p.read_bytes()).hexdigest() == s["pi"]["sha256"]
 assert s["pi"]["argv"] == json.loads(os.environ["IKABUD_RESUME_PI_ARGV"]) and s["pi"]["identity"] == os.environ["IKABUD_RESUME_PI_IDENTITY"]
 assert s["cwd"] == os.environ["IKABUD_RESUME_CWD"] and s["git"]["branch"] == os.environ["IKABUD_RESUME_GIT_BRANCH"] and s["git"]["head"] == os.environ["IKABUD_RESUME_GIT_HEAD"]
 assert s["venv"]["python"] == str(pathlib.Path(os.environ["IKABUD_RESUME_VENV_PYTHON"]).resolve())
 contract=pathlib.Path(s["contract"]["path"]); task=pathlib.Path(s["workbench"]["task_path"])
 assert contract == pathlib.Path(os.environ["IKABUD_RESUME_CONTRACT"]).resolve() and hashlib.sha256(contract.read_bytes()).hexdigest() == s["contract"]["sha256"]
 assert task == pathlib.Path(os.environ["IKABUD_RESUME_WORKBENCH_TASK"]).resolve() and hashlib.sha256(task.read_bytes()).hexdigest() == s["workbench"]["sha256"]
 assert s["workbench"]["task_id"] == json.loads(task.read_text())["task_id"] and s["workbench"]["state"] == "ARCHITECTURE_DECISION_REQUIRED"
PY
}
if [[ "$action" == probe ]]; then
  [[ -f "$ADAPTER_STATE/$stage-$nonce" ]] || exit 1
  delay="$ADAPTER_STATE/$stage-delay"
  if [[ -f "$delay" ]]; then n="$(<"$delay")"; if (( n > 0 )); then printf '%s\n' "$((n-1))" >"$delay"; exit 1; fi; fi
  validate_identity
  printf '%s probe\n' "$stage" >>"$ADAPTER_STATE/events"
  exit 0
fi
printf '%s restore\n' "$stage" >>"$ADAPTER_STATE/events"
if [[ "${ADAPTER_FAIL_ONCE_STAGE:-}" == "$stage" && ! -e "$ADAPTER_STATE/$stage-failed" ]]; then
  : >"$ADAPTER_STATE/$stage-failed"; exit 7
fi
# Non-duplicating launch intent: repeated restore for a nonce is a no-op.
if [[ ! -e "$ADAPTER_STATE/$stage-$nonce" ]]; then
  : >"$ADAPTER_STATE/$stage-$nonce"
  printf '%s launch\n' "$stage" >>"$ADAPTER_STATE/launches"
  if [[ "$stage" == chat && "${ADAPTER_CHAT_DELAY_PROBES:-0}" =~ ^[0-9]+$ ]]; then printf '%s\n' "${ADAPTER_CHAT_DELAY_PROBES:-0}" >"$ADAPTER_STATE/chat-delay"; fi
fi
exit 0
SH
    chmod 0700 "$adapter"
    for stage in vscode chat pi-terminal; do
        cp "$adapter" "$TMP_ROOT/bin/ikabud-resume-$stage-adapter"
        chmod 0700 "$TMP_ROOT/bin/ikabud-resume-$stage-adapter"
    done
    export IKABUD_RESUME_VSCODE_ADAPTER="$TMP_ROOT/bin/ikabud-resume-vscode-adapter"
    export IKABUD_RESUME_CHAT_ADAPTER="$TMP_ROOT/bin/ikabud-resume-chat-adapter"
    export IKABUD_RESUME_PI_TERMINAL_ADAPTER="$TMP_ROOT/bin/ikabud-resume-pi-terminal-adapter"
}

real_resume_env() {
    export IKABUD_RESUME_DRY_RUN=0 IKABUD_RESUME_NETWORK_WAIT_SECONDS=0
    export IKABUD_RESUME_CHAT_WAIT_SECONDS=3 IKABUD_RESUME_CHAT_RETRY_SECONDS=1
}

case_ack_intent_tampering() {
    setup_env; arm; prepare_python
    local generation snapshot nonce ack intent before
    generation="$(jq -r .generation "$IKABUD_RESUME_STATE_DIR/unclean.marker")"
    snapshot="$(find "$IKABUD_RESUME_STATE_DIR/snapshots/$generation" -type f)"
    nonce="$(resume_py nonce "$generation" "$snapshot")"
    resume_py ack "$generation" vscode "$nonce" "$snapshot"
    ack="$IKABUD_RESUME_STATE_DIR/acks/$generation/vscode.json"
    python3 - "$ack" <<'PY'
import json, pathlib, sys
p=pathlib.Path(sys.argv[1]); v=json.loads(p.read_text()); v["unexpected"]=1; p.write_text(json.dumps(v))
PY
    chmod 0600 "$ack"; before="$(sha256sum "$ack")"
    expect_status 2 resume_py ack-valid "$generation" vscode "$nonce" "$snapshot"
    expect_status 2 resume_py ack "$generation" vscode "$nonce" "$snapshot"
    [[ "$before" == "$(sha256sum "$ack")" ]]
    # Snapshot binding, timestamp shape, permissions, and symlinks are each checked.
    resume_py ack "$generation" chat "$nonce" "$snapshot"; ack="$IKABUD_RESUME_STATE_DIR/acks/$generation/chat.json"
    python3 - "$ack" <<'PY'
import json, pathlib, sys
p=pathlib.Path(sys.argv[1]); v=json.loads(p.read_text()); v["snapshot_digest"]="0"*64; v["acknowledged_at"]="today"; p.write_text(json.dumps(v))
PY
    chmod 0600 "$ack"; expect_status 2 resume_py ack-valid "$generation" chat "$nonce" "$snapshot"
    resume_py ack "$generation" pi "$nonce" "$snapshot"; ack="$IKABUD_RESUME_STATE_DIR/acks/$generation/pi.json"; chmod 0644 "$ack"
    expect_status 2 resume_py ack-valid "$generation" pi "$nonce" "$snapshot"
    resume_py ack "$generation" complete "$nonce" "$snapshot"; ack="$IKABUD_RESUME_STATE_DIR/acks/$generation/complete.json"
    mv "$ack" "$TMP_ROOT/not-an-ack"; ln -s "$TMP_ROOT/not-an-ack" "$ack"
    expect_status 2 resume_py ack-valid "$generation" complete "$nonce" "$snapshot"

    intent="$IKABUD_RESUME_STATE_DIR/acks/$generation/intent.json"
    python3 - "$intent" <<'PY'
import json, pathlib, sys
p=pathlib.Path(sys.argv[1]); v=json.loads(p.read_text()); v["snapshot_digest"]="0"*64; v["created_at"]="today"; v["unexpected"]=1; p.write_text(json.dumps(v))
PY
    chmod 0600 "$intent"
    expect_status 2 resume_py nonce "$generation" "$snapshot"
}

case_symlink_swap_windows() {
    setup_env
    cp "$IKABUD_RESUME_CONTRACT" "$TOOLS_DIR/tests/.contract.$$"
    export IKABUD_RESUME_CONTRACT="$TOOLS_DIR/tests/.contract.$$"
    arm; prepare_python
    local generation gen old ready release contract_old
    generation="$(jq -r .generation "$IKABUD_RESUME_STATE_DIR/unclean.marker")"
    gen="$IKABUD_RESUME_STATE_DIR/snapshots/$generation"; old="$gen.old"; ready="$TMP_ROOT/gen.ready"; release="$TMP_ROOT/gen.release"
    ( set +e; IKABUD_RESUME_TEST_GEN_PIN_READY="$ready" IKABUD_RESUME_TEST_GEN_PIN_RELEASE="$release" resume_py decide "$BOOT_B" >/dev/null 2>&1; printf '%s' "$?" >"$TMP_ROOT/gen.rc" ) & local worker=$!
    for _ in {1..200}; do [[ -e "$ready" ]] && break; sleep 0.01; done
    mv "$gen" "$old"; ln -s "$old" "$gen"; touch "$release"; wait "$worker" || true
    [[ "$(<"$TMP_ROOT/gen.rc")" == 2 ]]
    rm "$gen"; mv "$old" "$gen"

    ready="$TMP_ROOT/path.ready"; release="$TMP_ROOT/path.release"; contract_old="$IKABUD_RESUME_CONTRACT.old"
    ( set +e; IKABUD_RESUME_TEST_PATH_PIN_READY="$ready" IKABUD_RESUME_TEST_PATH_PIN_RELEASE="$release" IKABUD_RESUME_TEST_SWAP_TARGET="$IKABUD_RESUME_CONTRACT" resume_py decide "$BOOT_B" >/dev/null 2>&1; printf '%s' "$?" >"$TMP_ROOT/path.rc" ) & worker=$!
    for _ in {1..200}; do [[ -e "$ready" ]] && break; sleep 0.01; done
    mv "$IKABUD_RESUME_CONTRACT" "$contract_old"; ln -s "$contract_old" "$IKABUD_RESUME_CONTRACT"; touch "$release"; wait "$worker" || true
    [[ "$(<"$TMP_ROOT/path.rc")" == 2 ]]
    rm "$IKABUD_RESUME_CONTRACT"; mv "$contract_old" "$IKABUD_RESUME_CONTRACT"
}

case_adapter_swap_pinning() {
    setup_env; arm; install_adapters; prepare_python
    local snapshot nonce adapter old worker
    snapshot="$(find "$IKABUD_RESUME_STATE_DIR/snapshots" -type f)"; nonce="$BOOT_C"; adapter="$IKABUD_RESUME_VSCODE_ADAPTER"; old="$adapter.old"
    export IKABUD_RESUME_TEST_ADAPTER_PIN_READY="$TMP_ROOT/pin.ready" IKABUD_RESUME_TEST_ADAPTER_PIN_RELEASE="$TMP_ROOT/pin.release"
    ( resume_adapter vscode restore "$snapshot" "$nonce" ) & worker=$!
    for _ in {1..200}; do [[ -e "$TMP_ROOT/pin.ready" ]] && break; sleep 0.01; done
    mv "$adapter" "$old"; printf '#!/usr/bin/env bash\ntouch "$ADAPTER_STATE/malicious"\n' >"$adapter"; chmod 0700 "$adapter"; touch "$TMP_ROOT/pin.release"
    wait "$worker"
    [[ ! -e "$ADAPTER_STATE/malicious" && -e "$ADAPTER_STATE/vscode-$nonce" ]]
}

case_capture_races_pid_reuse() {
    setup_env; arm
    # No published PID is a possible startup state and must retain the marker.
    expect_status 2 "$TOOLS_DIR/session-guard.sh" disarm
    [[ -e "$IKABUD_RESUME_STATE_DIR/unclean.marker" ]]
    "$TOOLS_DIR/capture.sh" >"$TMP_ROOT/capture.log" 2>&1 & local capture_pid=$!
    for _ in {1..200}; do [[ -f "$IKABUD_RESUME_STATE_DIR/capture.pid" ]] && break; sleep 0.01; done
    cp "$IKABUD_RESUME_STATE_DIR/capture.pid" "$TMP_ROOT/pid.good"
    python3 - "$IKABUD_RESUME_STATE_DIR/capture.pid" <<'PY'
import json, pathlib, sys
p=pathlib.Path(sys.argv[1]); v=json.loads(p.read_text()); v["start_time"]=str(int(v["start_time"])+1); p.write_text(json.dumps(v))
PY
    chmod 0600 "$IKABUD_RESUME_STATE_DIR/capture.pid"
    expect_status 2 "$TOOLS_DIR/session-guard.sh" disarm
    kill -0 "$capture_pid"; [[ -e "$IKABUD_RESUME_STATE_DIR/unclean.marker" ]]
    mv "$TMP_ROOT/pid.good" "$IKABUD_RESUME_STATE_DIR/capture.pid"; kill -TERM "$capture_pid"; wait "$capture_pid"

    # Exit after pidfd_open but before signal. A subsequently started process
    # stands in for a reused numeric PID and must never receive SIGTERM.
    setup_env; arm
    "$TOOLS_DIR/capture.sh" >"$TMP_ROOT/capture-exit.log" 2>&1 & capture_pid=$!
    for _ in {1..200}; do [[ -f "$IKABUD_RESUME_STATE_DIR/capture.pid" ]] && break; sleep 0.01; done
    export IKABUD_RESUME_TEST_PIDFD_SIGNAL_READY="$TMP_ROOT/pidfd.ready"
    export IKABUD_RESUME_TEST_PIDFD_SIGNAL_RELEASE="$TMP_ROOT/pidfd.release"
    ( set +e; "$TOOLS_DIR/session-guard.sh" disarm >"$TMP_ROOT/disarm-exit.log" 2>&1; printf '%s\n' "$?" >"$TMP_ROOT/disarm-exit.rc" ) & local disarm_pid=$!
    for _ in {1..200}; do [[ -e "$TMP_ROOT/pidfd.ready" ]] && break; sleep 0.01; done
    [[ -e "$TMP_ROOT/pidfd.ready" ]]
    kill -KILL "$capture_pid"; wait "$capture_pid" 2>/dev/null || true
    ( trap 'printf term >"$TMP_ROOT/sentinel.term"; exit 9' TERM; while :; do sleep 1; done ) & local sentinel_pid=$!
    touch "$TMP_ROOT/pidfd.release"
    wait "$disarm_pid"
    [[ "$(<"$TMP_ROOT/disarm-exit.rc")" == 2 ]]
    kill -0 "$sentinel_pid"; [[ ! -e "$TMP_ROOT/sentinel.term" && -e "$IKABUD_RESUME_STATE_DIR/unclean.marker" ]]
    kill -KILL "$sentinel_pid"; wait "$sentinel_pid" 2>/dev/null || true
    unset IKABUD_RESUME_TEST_PIDFD_SIGNAL_READY IKABUD_RESUME_TEST_PIDFD_SIGNAL_RELEASE

    # A replacement writer published after the first join is also stopped and
    # joined; disarm cannot unlink beneath it.
    setup_env; arm
    "$TOOLS_DIR/capture.sh" >"$TMP_ROOT/capture-first.log" 2>&1 & capture_pid=$!
    for _ in {1..200}; do [[ -f "$IKABUD_RESUME_STATE_DIR/capture.pid" ]] && break; sleep 0.01; done
    export IKABUD_RESUME_TEST_DISARM_JOIN_READY="$TMP_ROOT/join.ready"
    export IKABUD_RESUME_TEST_DISARM_JOIN_RELEASE="$TMP_ROOT/join.release"
    ( set +e; "$TOOLS_DIR/session-guard.sh" disarm >"$TMP_ROOT/disarm-replacement.log" 2>&1; printf '%s\n' "$?" >"$TMP_ROOT/disarm-replacement.rc" ) & disarm_pid=$!
    for _ in {1..200}; do [[ -e "$TMP_ROOT/join.ready" ]] && break; sleep 0.01; done
    [[ -e "$TMP_ROOT/join.ready" ]]; wait "$capture_pid"
    "$TOOLS_DIR/capture.sh" >"$TMP_ROOT/capture-replacement.log" 2>&1 & local replacement_pid=$!
    for _ in {1..200}; do [[ -f "$IKABUD_RESUME_STATE_DIR/capture.pid" ]] && break; sleep 0.01; done
    kill -0 "$replacement_pid"
    touch "$TMP_ROOT/join.release"
    wait "$disarm_pid"; [[ "$(<"$TMP_ROOT/disarm-replacement.rc")" == 0 ]]
    wait "$replacement_pid"
    [[ ! -e "$IKABUD_RESUME_STATE_DIR/capture.pid" && ! -e "$IKABUD_RESUME_STATE_DIR/unclean.marker" ]]
    unset IKABUD_RESUME_TEST_DISARM_JOIN_READY IKABUD_RESUME_TEST_DISARM_JOIN_RELEASE
}

case_concurrent_triggers() {
    setup_env
    "$TOOLS_DIR/session-guard.sh" arm >"$TMP_ROOT/a1" 2>&1 & local p1=$!
    "$TOOLS_DIR/session-guard.sh" arm >"$TMP_ROOT/a2" 2>&1 & local p2=$!
    local ok=0; wait "$p1" && ok=$((ok+1)) || true; wait "$p2" && ok=$((ok+1)) || true; [[ "$ok" == 1 ]]
    "$TOOLS_DIR/capture.sh" >"$TMP_ROOT/c1" 2>&1 & local c1=$!
    for _ in {1..200}; do [[ -f "$IKABUD_RESUME_STATE_DIR/capture.pid" ]] && break; sleep 0.01; done
    expect_status 2 env IKABUD_RESUME_CAPTURE_ITERATIONS=1 "$TOOLS_DIR/capture.sh"
    kill -TERM "$c1"; wait "$c1"
    export IKABUD_RESUME_BOOT_ID="$BOOT_B"
    "$TOOLS_DIR/resume.sh" >/dev/null 2>&1 & p1=$!; "$TOOLS_DIR/resume.sh" >/dev/null 2>&1 & p2=$!
    set +e; wait "$p1"; local r1=$?; wait "$p2"; local r2=$?; set -e
    [[ ( "$r1" == 10 && "$r2" == 0 ) || ( "$r2" == 10 && "$r1" == 0 ) ]]
}

case_adapter_retry_idempotence() {
    setup_env; arm; install_adapters; real_resume_env; export IKABUD_RESUME_BOOT_ID="$BOOT_B"
    export IKABUD_RESUME_TEST_INTERRUPT_STAGE=launch-before-ack:vscode
    expect_status 1 "$TOOLS_DIR/resume.sh"
    unset IKABUD_RESUME_TEST_INTERRUPT_STAGE
    expect_status 10 "$TOOLS_DIR/resume.sh"
    [[ "$(grep -c '^vscode launch$' "$ADAPTER_STATE/launches")" == 1 ]]
}

case_partial_failure_retry() {
    setup_env; arm; install_adapters; real_resume_env; export IKABUD_RESUME_BOOT_ID="$BOOT_B" ADAPTER_FAIL_ONCE_STAGE=chat
    expect_status 7 "$TOOLS_DIR/resume.sh"
    local generation="$(jq -r .generation "$IKABUD_RESUME_STATE_DIR/unclean.marker")"
    [[ "$(jq -r .stage "$IKABUD_RESUME_STATE_DIR/acks/$generation/failure.json")" == chat ]]
    unset ADAPTER_FAIL_ONCE_STAGE
    expect_status 10 "$TOOLS_DIR/resume.sh"
    [[ "$(grep -c '^vscode launch$' "$ADAPTER_STATE/launches")" == 1 && "$(grep -c '^chat launch$' "$ADAPTER_STATE/launches")" == 1 && "$(grep -c '^pi launch$' "$ADAPTER_STATE/launches")" == 1 ]]
}

case_branch_mismatch_durable_adr() {
    setup_env; arm
    local generation dirty="$TOOLS_DIR/tests/.resume-dirty.$$"; generation="$(jq -r .generation "$IKABUD_RESUME_STATE_DIR/unclean.marker")"
    printf 'preserve me\n' >"$dirty"; local hash="$(sha256sum "$dirty")"
    export IKABUD_RESUME_BOOT_ID="$BOOT_B" IKABUD_RESUME_TEST_GIT_BRANCH=review-mismatch
    expect_status 2 "$TOOLS_DIR/resume.sh"
    [[ "$hash" == "$(sha256sum "$dirty")" ]]
    jq -e '.stage=="architecture" and .lifecycle_state=="ARCHITECTURE_DECISION_REQUIRED" and (.detail|contains("worktree preserved"))' "$IKABUD_RESUME_STATE_DIR/acks/$generation/failure.json" >/dev/null
    rm "$dirty"
}

case_wrong_pi_and_offline() {
    setup_env; arm; prepare_python
    printf '# changed\n' >>"$IKABUD_RESUME_PI_EXECUTABLE"
    expect_status 2 resume_py decide "$BOOT_B"
    setup_env; arm; prepare_python; export IKABUD_RESUME_PI_IDENTITY=wrong-pi
    expect_status 2 resume_py decide "$BOOT_B"
    setup_env; arm; install_adapters; real_resume_env
    export IKABUD_RESUME_BOOT_ID="$BOOT_B" IKABUD_RESUME_TEST_OFFLINE=1 ADAPTER_CHAT_DELAY_PROBES=1
    expect_status 10 "$TOOLS_DIR/resume.sh"
    [[ -s "$ADAPTER_STATE/events" ]]
}

case_forced_commit_interruptions() {
    local point generation before
    for point in after-snapshot after-marker after-last-session; do
        setup_env; prepare_python
        expect_status 97 env IKABUD_RESUME_TEST_INTERRUPT="$point" RESUME_STATE_DIR="$RESUME_STATE_DIR" RESUME_REPO_ROOT="$RESUME_REPO_ROOT" RESUME_SCHEMA="$RESUME_SCHEMA" python3 "$TOOLS_DIR/state.py" arm "$BOOT_A"
        if [[ "$point" == after-snapshot ]]; then [[ ! -e "$IKABUD_RESUME_STATE_DIR/unclean.marker" ]]; else resume_py decide "$BOOT_B" >/dev/null; fi
    done
    for point in after-snapshot after-marker after-last-session; do
        setup_env; arm; prepare_python; before="$(jq -r .sequence "$IKABUD_RESUME_STATE_DIR/unclean.marker")"
        expect_status 97 env IKABUD_RESUME_TEST_INTERRUPT="$point" RESUME_STATE_DIR="$RESUME_STATE_DIR" RESUME_REPO_ROOT="$RESUME_REPO_ROOT" RESUME_SCHEMA="$RESUME_SCHEMA" python3 "$TOOLS_DIR/state.py" capture "$BOOT_A"
        if [[ "$point" == after-snapshot ]]; then [[ "$(jq -r .sequence "$IKABUD_RESUME_STATE_DIR/unclean.marker")" == "$before" ]]; else [[ "$(jq -r .sequence "$IKABUD_RESUME_STATE_DIR/unclean.marker")" == 2 ]]; fi
    done
    for point in after-snapshot after-marker after-last-session; do
        setup_env; arm; prepare_python; generation="$(jq -r .generation "$IKABUD_RESUME_STATE_DIR/unclean.marker")"; local snapshot="$(find "$IKABUD_RESUME_STATE_DIR/snapshots/$generation" -type f)"
        expect_status 97 env IKABUD_RESUME_TEST_INTERRUPT="$point" RESUME_STATE_DIR="$RESUME_STATE_DIR" RESUME_REPO_ROOT="$RESUME_REPO_ROOT" RESUME_SCHEMA="$RESUME_SCHEMA" python3 "$TOOLS_DIR/state.py" rollover "$generation" "$snapshot" "$BOOT_B"
        if [[ "$point" == after-snapshot ]]; then [[ "$(jq -r .generation "$IKABUD_RESUME_STATE_DIR/unclean.marker")" == "$generation" ]]; else [[ "$(jq -r .generation "$IKABUD_RESUME_STATE_DIR/unclean.marker")" != "$generation" ]]; fi
    done
}

case_preflight_adapter_verification() {
    setup_env; install_adapters
    mkdir -p "$TMP_ROOT/extension" "$TMP_ROOT/config"; printf '{"contributes":{"commands":[]}}\n' >"$TMP_ROOT/extension/package.json"
    cat >"$TMP_ROOT/bin/code" <<'SH'
#!/usr/bin/env bash
case "${1:-}" in
 --version) printf '1.99.0\n' ;;
 --help) printf '%s\n' 'code [options] [paths...] --profile <profileName> --reuse-window' ;;
 --list-extensions) printf 'openai.chatgpt@1.2.3\n' ;;
 --locate-extension) printf '%s\n' "$FAKE_EXTENSION" ;;
 *) exit 1 ;;
esac
SH
    chmod 0700 "$TMP_ROOT/bin/code"; export PATH="$TMP_ROOT/bin:$PATH" FAKE_EXTENSION="$TMP_ROOT/extension" XDG_CONFIG_HOME="$TMP_ROOT/config"
    expect_status 3 "$TOOLS_DIR/preflight.sh"
    local report="$(find "$IKABUD_RESUME_STATE_DIR/preflight" -name '*.json' | sort | tail -1)"
    jq -e '.checks.vscode_cli.status=="supported" and .checks.exact_chat_session_api.status=="supported" and .checks.integrated_terminal_api.status=="supported" and (.checks.exact_chat_session_api.evidence.name_regex_used_for_verdict==false)' "$report" >/dev/null
}

case_atomic_generation_replacement() {
    setup_env
    arm
    prepare_python
    local old_generation old_snapshot monitor new_generation
    old_generation="$(jq -r .generation "$IKABUD_RESUME_STATE_DIR/unclean.marker")"
    old_snapshot="$(find "$IKABUD_RESUME_STATE_DIR/snapshots/$old_generation" -type f)"
    python3 - "$IKABUD_RESUME_STATE_DIR/unclean.marker" "$TMP_ROOT/ready" "$TMP_ROOT/stop" "$TMP_ROOT/gap" <<'PY' &
import os, pathlib, sys, time
marker, ready, stop, gap = map(pathlib.Path, sys.argv[1:])
ready.touch()
while not stop.exists():
    if not marker.exists(): gap.touch()
    time.sleep(0.0005)
PY
    monitor=$!
    for _ in {1..40}; do [[ -e "$TMP_ROOT/ready" ]] && break; sleep 0.01; done
    new_generation="$(resume_py rollover "$old_generation" "$old_snapshot" "$BOOT_B")"
    touch "$TMP_ROOT/stop"
    wait "$monitor"
    [[ ! -e "$TMP_ROOT/gap" ]]
    [[ "$new_generation" != "$old_generation" ]]
    [[ "$(jq -r .generation "$IKABUD_RESUME_STATE_DIR/unclean.marker")" == "$new_generation" ]]
    [[ "$(jq -r .sequence "$IKABUD_RESUME_STATE_DIR/unclean.marker")" == 1 ]]
}

case "${1:-}" in
    schema_validation) case_schema_validation ;;
    real_entrypoint_live_pi_identity) case_real_entrypoint_live_pi_identity ;;
    state_permissions) case_state_permissions ;;
    secret_rejection) case_secret_rejection ;;
    safe_argv) case_safe_argv ;;
    locking) case_locking ;;
    boot_generation_sequence) case_boot_generation_sequence ;;
    heartbeat_skew_45s) case_heartbeat_skew ;;
    crash_commit_selection) case_crash_commit_selection ;;
    path_symlink_rejection) case_path_symlink_rejection ;;
    acknowledgements) case_acknowledgements ;;
    ack_intent_tampering) case_ack_intent_tampering ;;
    disarm_coordination) case_disarm_coordination ;;
    capture_races_pid_reuse) case_capture_races_pid_reuse ;;
    concurrent_triggers) case_concurrent_triggers ;;
    symlink_swap_windows) case_symlink_swap_windows ;;
    adapter_swap_pinning) case_adapter_swap_pinning ;;
    adapter_retry_idempotence) case_adapter_retry_idempotence ;;
    partial_failure_retry) case_partial_failure_retry ;;
    branch_mismatch_durable_adr) case_branch_mismatch_durable_adr ;;
    wrong_pi_and_offline) case_wrong_pi_and_offline ;;
    forced_commit_interruptions) case_forced_commit_interruptions ;;
    preflight_adapter_verification) case_preflight_adapter_verification ;;
    atomic_generation_replacement) case_atomic_generation_replacement ;;
    *) printf 'unknown test case: %s\n' "${1:-}" >&2; exit 2 ;;
esac
