#!/usr/bin/env bash
# Shared fail-closed primitives for session-resume scripts.
set -euo pipefail

umask 077
readonly RESUME_TOOLS_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
readonly RESUME_REPO_ROOT="$(cd -- "$RESUME_TOOLS_DIR/../.." && pwd -P)"
readonly RESUME_SCHEMA="$RESUME_TOOLS_DIR/session.schema.json"
readonly RESUME_PY="$RESUME_TOOLS_DIR/state.py"

resume_state_dir() {
    local value="${IKABUD_RESUME_STATE_DIR:-${XDG_STATE_HOME:-$HOME/.local/state}/ikabud-resume}"
    [[ "$value" = /* ]] || { printf 'state directory must be absolute: %s\n' "$value" >&2; return 2; }
    printf '%s\n' "$value"
}

resume_prepare_state() {
    RESUME_STATE_DIR="$(resume_state_dir)"
    # Creation and validation are rooted at no-follow directory descriptors;
    # chmod/open-by-path check/use sequences are deliberately avoided.
    local prepared
    if ! prepared="$(python3 - "$RESUME_STATE_DIR" <<'PY'
import os, pathlib, stat, sys
raw = pathlib.Path(sys.argv[1])
if not raw.is_absolute(): raise SystemExit("state directory must be absolute")
flags = os.O_RDONLY | getattr(os, "O_DIRECTORY", 0) | getattr(os, "O_NOFOLLOW", 0)
parent = os.open("/", flags)
try:
    for component in raw.parts[1:-1]:
        try: child = os.open(component, flags, dir_fd=parent)
        except FileNotFoundError:
            os.mkdir(component, 0o700, dir_fd=parent); child = os.open(component, flags, dir_fd=parent)
        os.close(parent); parent = child
    try: os.mkdir(raw.name, 0o700, dir_fd=parent)
    except FileExistsError: pass
    root = os.open(raw.name, flags, dir_fd=parent)
except OSError as exc:
    raise SystemExit("invalid or symlinked state path: " + str(exc))
finally:
    os.close(parent)
try:
    rst = os.fstat(root); live = os.stat(raw, follow_symlinks=False)
    if not stat.S_ISDIR(live.st_mode) or (rst.st_dev, rst.st_ino) != (live.st_dev, live.st_ino): raise SystemExit("state root changed while opening")
    os.fchmod(root, 0o700)
    for name in ("snapshots", "acks", "logs"):
        try: os.mkdir(name, 0o700, dir_fd=root)
        except FileExistsError: pass
        child = os.open(name, flags, dir_fd=root)
        try:
            os.fchmod(child, 0o700); cst=os.fstat(child); clive=os.stat(name, dir_fd=root, follow_symlinks=False)
            if not stat.S_ISDIR(clive.st_mode) or (cst.st_dev,cst.st_ino)!=(clive.st_dev,clive.st_ino): raise SystemExit("state child changed while opening")
        finally: os.close(child)
    lock = os.open("stable.lock", os.O_RDWR | os.O_CREAT | getattr(os, "O_NOFOLLOW", 0), 0o600, dir_fd=root)
    try:
        lst=os.fstat(lock)
        if not stat.S_ISREG(lst.st_mode) or lst.st_nlink != 1: raise SystemExit("stable lock identity is invalid")
        os.fchmod(lock, 0o600); os.fsync(lock)
    finally: os.close(lock)
    os.fsync(root)
    final=os.stat(raw, follow_symlinks=False)
    if (final.st_dev,final.st_ino)!=(rst.st_dev,rst.st_ino): raise SystemExit("state root changed during initialization")
    print(pathlib.Path(f"/proc/self/fd/{root}").resolve(strict=True))
finally: os.close(root)
PY
)"; then
        return 2
    fi
    RESUME_STATE_DIR="$prepared"
    RESUME_LOCK="$RESUME_STATE_DIR/stable.lock"
    export RESUME_STATE_DIR RESUME_REPO_ROOT RESUME_SCHEMA
}

resume_lock() {
    [[ ! -L "$RESUME_LOCK" ]] || { printf 'stable lock was symlink-swapped\n' >&2; return 2; }
    exec 9<>"$RESUME_LOCK"
    local fd_identity path_identity
    fd_identity="$(stat -Lc %d:%i:%h:%a:%F -- "/proc/$$/fd/9")"
    path_identity="$(stat -c %d:%i:%h:%a:%F -- "$RESUME_LOCK")"
    [[ "$fd_identity" == "$path_identity" && "$path_identity" == *':1:600:regular empty file' ]] || {
        exec 9>&-; printf 'stable lock changed while opening\n' >&2; return 2;
    }
    flock -x 9
    [[ "$(stat -Lc %d:%i -- "/proc/$$/fd/9")" == "$(stat -c %d:%i -- "$RESUME_LOCK")" ]] || {
        resume_unlock; printf 'stable lock changed while acquiring\n' >&2; return 2;
    }
}

resume_unlock() {
    flock -u 9
    exec 9>&-
}

resume_py() {
    python3 "$RESUME_PY" "$@"
}

resume_boot_id() {
    if [[ -n "${IKABUD_RESUME_BOOT_ID:-}" ]]; then
        printf '%s\n' "$IKABUD_RESUME_BOOT_ID"
    else
        tr -d '\n' </proc/sys/kernel/random/boot_id
        printf '\n'
    fi
}

# Resolve capture inputs with fixed, read-only probes. Exact chat identity and
# Workbench task are deliberately configuration/adaptor supplied; no generic
# fallback can satisfy the contract.
resume_capture_environment() {
    local pi_path
    pi_path="${IKABUD_RESUME_PI_EXECUTABLE:-$(command -v pi || true)}"
    [[ -n "$pi_path" ]] || { printf 'global pi is unavailable\n' >&2; return 2; }
    export IKABUD_RESUME_PI_EXECUTABLE="$(realpath -e -- "$pi_path")"
    if [[ -z "${IKABUD_RESUME_STATE_DIR:-}" ]]; then
        global_pi="$(realpath -e -- "$(command -v pi)")"
        [[ "$IKABUD_RESUME_PI_EXECUTABLE" == "$global_pi" && "$IKABUD_RESUME_PI_EXECUTABLE" != "$RESUME_REPO_ROOT"/* ]] || {
            printf 'Pi is not the resolved global executable\n' >&2; return 2;
        }
        pi_help="$($IKABUD_RESUME_PI_EXECUTABLE --help)"
        [[ "$pi_help" == *'pi - AI coding assistant'* && "$pi_help" == *'--session <path|id>'* ]] || {
            printf 'global Pi identity/resume syntax verification failed\n' >&2; return 2;
        }
    fi
    if [[ -z "${IKABUD_RESUME_PI_VERSION:-}" ]]; then
        IKABUD_RESUME_PI_VERSION="$($IKABUD_RESUME_PI_EXECUTABLE --version | head -n 1)"
        export IKABUD_RESUME_PI_VERSION
    fi
    export IKABUD_RESUME_PI_IDENTITY="${IKABUD_RESUME_PI_IDENTITY:-pi - AI coding assistant}"
    if [[ -z "${IKABUD_RESUME_PI_ARGV:-}" ]]; then
        IKABUD_RESUME_PI_ARGV="$(python3 - "$IKABUD_RESUME_PI_EXECUTABLE" <<'PY'
import json, sys
print(json.dumps([sys.argv[1], "--continue"]))
PY
)"
        export IKABUD_RESUME_PI_ARGV
    fi
    resume_refresh_git_environment
    [[ -n "${IKABUD_RESUME_CHAT_EXTENSION_ID:-}" && -n "${IKABUD_RESUME_CHAT_EXTENSION_VERSION:-}" && -n "${IKABUD_RESUME_CHAT_SESSION_ID:-}" ]] || {
        printf 'exact chat extension/version/session identity is required\n' >&2; return 2;
    }
    [[ -n "${IKABUD_RESUME_WORKBENCH_TASK:-}" ]] || {
        printf 'IKABUD_RESUME_WORKBENCH_TASK is required\n' >&2; return 2;
    }
}

resume_refresh_git_environment() {
    IKABUD_RESUME_GIT_BRANCH="${IKABUD_RESUME_TEST_GIT_BRANCH:-$(git -C "$RESUME_REPO_ROOT" branch --show-current)}"
    IKABUD_RESUME_GIT_HEAD="${IKABUD_RESUME_TEST_GIT_HEAD:-$(git -C "$RESUME_REPO_ROOT" rev-parse HEAD)}"
    [[ -n "$IKABUD_RESUME_GIT_BRANCH" ]] || { printf 'detached Git HEAD is not replayable\n' >&2; return 2; }
    export IKABUD_RESUME_GIT_BRANCH IKABUD_RESUME_GIT_HEAD
}

# Executes only a configured, resolved adapter whose basename is fixed by stage.
# Arguments stay an argv array; eval and shell command strings are never used.
resume_adapter() {
    local stage="$1" action="$2" snapshot="$3" nonce="$4" var expected configured resolved adapter_fd snapshot_fd adapter_identity snapshot_identity owner_pid
    owner_pid="$BASHPID"
    [[ "$action" == probe || "$action" == restore ]] || { printf 'adapter action is not allowlisted: %s\n' "$action" >&2; return 2; }
    [[ "$snapshot" = /* && -f "$snapshot" && ! -L "$snapshot" ]] || { printf 'adapter snapshot must be an absolute regular file\n' >&2; return 2; }
    [[ "$nonce" =~ ^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$ ]] || { printf 'adapter nonce is malformed\n' >&2; return 2; }
    case "$stage" in
        vscode) var=IKABUD_RESUME_VSCODE_ADAPTER; expected=ikabud-resume-vscode-adapter ;;
        chat) var=IKABUD_RESUME_CHAT_ADAPTER; expected=ikabud-resume-chat-adapter ;;
        pi) var=IKABUD_RESUME_PI_TERMINAL_ADAPTER; expected=ikabud-resume-pi-terminal-adapter ;;
        *) printf 'adapter stage is not allowlisted: %s\n' "$stage" >&2; return 2 ;;
    esac
    configured="${!var:-}"
    [[ "$configured" = /* && -x "$configured" && ! -L "$configured" ]] || {
        printf '%s is not a configured absolute regular executable\n' "$var" >&2; return 2;
    }
    resolved="$(realpath -e -- "$configured")"
    [[ "$resolved" == "$configured" && "$(basename -- "$resolved")" == "$expected" ]] || {
        printf 'adapter realpath/basename is not allowlisted for %s\n' "$stage" >&2; return 2;
    }
    # Pin both inputs as open inodes. Revalidate their names after opening, then
    # execute/read through inherited descriptors so a later rename cannot swap them.
    exec {adapter_fd}<"$resolved"
    exec {snapshot_fd}<"$snapshot"
    adapter_identity="$(stat -Lc %d:%i -- "/proc/$owner_pid/fd/$adapter_fd")"
    snapshot_identity="$(stat -Lc %d:%i -- "/proc/$owner_pid/fd/$snapshot_fd")"
    [[ "$adapter_identity" == "$(stat -c %d:%i -- "$resolved")" && "$snapshot_identity" == "$(stat -c %d:%i -- "$snapshot")" ]] || {
        exec {adapter_fd}<&-; exec {snapshot_fd}<&-; printf 'adapter or snapshot changed while pinning\n' >&2; return 2;
    }
    if [[ -n "${IKABUD_RESUME_TEST_ADAPTER_PIN_READY:-}" && -n "${IKABUD_RESUME_TEST_ADAPTER_PIN_RELEASE:-}" ]]; then
        : >"$IKABUD_RESUME_TEST_ADAPTER_PIN_READY"
        local pin_deadline=$((SECONDS + 5))
        while [[ ! -e "$IKABUD_RESUME_TEST_ADAPTER_PIN_RELEASE" ]]; do
            (( SECONDS < pin_deadline )) || { exec {adapter_fd}<&-; exec {snapshot_fd}<&-; return 2; }
            sleep 0.01
        done
    fi
    local rc
    if IKABUD_RESUME_ADAPTER_STAGE="$stage" "/proc/$owner_pid/fd/$adapter_fd" "$action" --snapshot "/proc/$owner_pid/fd/$snapshot_fd" --nonce "$nonce"; then
        rc=0
    else
        rc=$?
    fi
    exec {adapter_fd}<&-
    exec {snapshot_fd}<&-
    return "$rc"
}
