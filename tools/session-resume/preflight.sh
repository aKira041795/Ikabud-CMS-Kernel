#!/usr/bin/env bash
# Non-mutating compatibility preflight for the Ikabud session-resume pilot.
set -euo pipefail

umask 077
readonly REPO_ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd -P)"
readonly STATE_ROOT="${IKABUD_RESUME_STATE_DIR:-${XDG_STATE_HOME:-$HOME/.local/state}/ikabud-resume}"
readonly REPORT_DIR="$STATE_ROOT/preflight"

# This fixed interpreter is the only implementation dependency. The embedded
# program has a closed command allowlist and never invokes a shell.
PYTHON_BIN="$(command -v python3 || true)"
if [[ -z "$PYTHON_BIN" || ! -x "$PYTHON_BIN" ]]; then
    printf 'BLOCKED: python3 is required to create a durable preflight report\n' >&2
    exit 2
fi

mkdir -p -- "$REPORT_DIR"
chmod 0700 -- "$STATE_ROOT" "$REPORT_DIR"

set +e
"$PYTHON_BIN" - "$REPO_ROOT" "$REPORT_DIR" <<'PY'
import datetime as dt
import hashlib
import json
import os
import pathlib
import re
import shutil
import subprocess
import sys
import tempfile

repo = pathlib.Path(sys.argv[1]).resolve()
report_dir = pathlib.Path(sys.argv[2]).resolve()

# Every external process is selected from this allowlist and receives a fixed,
# non-mutating argument vector. shell=True and user-supplied argv are forbidden.
ALLOWED = {"code", "pi", "loginctl", "ip", "fwupdmgr"}

def run(name, args, timeout=10):
    if name not in ALLOWED:
        raise RuntimeError("command is not allowlisted: " + name)
    executable = shutil.which(name)
    if not executable:
        return {"available": False, "path": None, "rc": None, "stdout": "", "stderr": "not installed"}
    try:
        cp = subprocess.run([executable, *args], stdin=subprocess.DEVNULL,
                            stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                            text=True, timeout=timeout, check=False, env=os.environ.copy())
        return {"available": True, "path": str(pathlib.Path(executable).resolve()),
                "rc": cp.returncode, "stdout": cp.stdout, "stderr": cp.stderr}
    except (OSError, subprocess.TimeoutExpired) as exc:
        return {"available": True, "path": str(pathlib.Path(executable).resolve()),
                "rc": None, "stdout": "", "stderr": type(exc).__name__}

def sha256(path):
    h = hashlib.sha256()
    with path.open("rb") as fh:
        for chunk in iter(lambda: fh.read(1024 * 1024), b""):
            h.update(chunk)
    return h.hexdigest()

def read_text(path, limit=2_000_000):
    try:
        with path.open("r", encoding="utf-8", errors="replace") as fh:
            return fh.read(limit)
    except (OSError, PermissionError):
        return ""

def capability(status, mandatory, evidence, detail):
    return {"status": status, "mandatory": mandatory, "evidence": evidence, "detail": detail}

ADAPTER_SPECS = {
    "vscode": ("IKABUD_RESUME_VSCODE_ADAPTER", "ikabud-resume-vscode-adapter", {"workspace", "profile"}),
    "chat": ("IKABUD_RESUME_CHAT_ADAPTER", "ikabud-resume-chat-adapter", {"extension_id", "extension_version", "session_id"}),
    "pi": ("IKABUD_RESUME_PI_TERMINAL_ADAPTER", "ikabud-resume-pi-terminal-adapter", {"integrated_terminal", "pid", "executable", "sha256", "argv", "cwd", "branch", "head", "venv_python", "contract_path", "contract_sha256", "workbench_path", "workbench_sha256", "task_id", "state"}),
}

def adapter_capability(stage):
    var, basename, required_fields = ADAPTER_SPECS[stage]
    raw = os.environ.get(var, "")
    evidence = {"environment": var, "configured_path": raw or None, "required_identity_fields": sorted(required_fields)}
    if not raw or not pathlib.Path(raw).is_absolute():
        return False, evidence, "approved absolute adapter path is not configured"
    path = pathlib.Path(raw)
    try:
        lst = path.lstat(); resolved = path.resolve(strict=True)
        if path.is_symlink() or not path.is_file() or not os.access(path, os.X_OK) or resolved != path or path.name != basename:
            raise OSError("path, basename, regular-file, or executable check failed")
        fd = os.open(path, os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0))
        try:
            fst = os.fstat(fd); before = (fst.st_dev, fst.st_ino)
            if before != (lst.st_dev, lst.st_ino): raise OSError("adapter changed while pinning")
            responses = []
            probe_env = os.environ.copy(); probe_env["IKABUD_RESUME_ADAPTER_STAGE"] = stage
            pinned = f"/proc/self/fd/{fd}"
            for args in (["capabilities", "--json"], ["preflight-probe", "--json"]):
                cp = subprocess.run([pinned, *args], stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                                    text=True, timeout=10, check=False, env=probe_env, pass_fds=(fd,))
                responses.append({"argv": args, "rc": cp.returncode, "stdout": cp.stdout[:8192], "stderr": cp.stderr[:2048]})
        finally:
            os.close(fd)
        after_stat = path.stat(follow_symlinks=False)
        if before != (after_stat.st_dev, after_stat.st_ino): raise OSError("adapter changed during probes")
        caps = json.loads(responses[0]["stdout"]); probe = json.loads(responses[1]["stdout"])
        caps_keys = {"schema", "version", "stage", "actions", "identity_fields"}
        probe_keys = {"schema", "version", "stage", "supported", "identity_probe"}
        valid = (responses[0]["rc"] == responses[1]["rc"] == 0 and set(caps) == caps_keys and set(probe) == probe_keys and
                 caps["schema"] == "ikabud.resume-adapter-capabilities.v1" and probe["schema"] == "ikabud.resume-adapter-preflight.v1" and
                 caps["version"] == probe["version"] == "1.0" and caps["stage"] == probe["stage"] == stage and
                 caps["actions"] == ["probe", "restore"] and set(caps["identity_fields"]) == required_fields and
                 probe["supported"] is True and probe["identity_probe"] is True)
        evidence.update({"resolved_path": str(resolved), "sha256": sha256(path), "responses": responses,
                         "declared_identity_fields": caps.get("identity_fields") if isinstance(caps, dict) else None})
        return valid, evidence, "exact adapter interface and identity probe verified" if valid else "adapter returned an unapproved interface/probe result"
    except (OSError, subprocess.TimeoutExpired, json.JSONDecodeError, TypeError, AttributeError) as exc:
        evidence["error"] = type(exc).__name__ + ": " + str(exc)
        return False, evidence, "adapter could not be pinned and verified"

def atomic_write(path, data):
    # Temporary file and destination are on the same filesystem.
    fd, tmp_name = tempfile.mkstemp(prefix="." + path.name + ".", suffix=".tmp", dir=path.parent)
    try:
        os.fchmod(fd, 0o600)
        with os.fdopen(fd, "wb", closefd=True) as fh:
            fh.write(data)
            fh.flush()
            os.fsync(fh.fileno())
        os.replace(tmp_name, path)
        os.chmod(path, 0o600)
        try:
            dfd = os.open(path.parent, os.O_RDONLY | getattr(os, "O_DIRECTORY", 0))
            try:
                os.fsync(dfd)
            finally:
                os.close(dfd)
        except OSError:
            pass  # Directory fsync is not supported by every filesystem.
    except BaseException:
        try:
            os.unlink(tmp_name)
        except OSError:
            pass
        raise

now = dt.datetime.now(dt.timezone.utc)
stamp = now.strftime("%Y%m%dT%H%M%S.%fZ")
checks = {}

# Desktop, graphical login trigger, and clean-stop compatibility.
session_id = os.environ.get("XDG_SESSION_ID", "")
login = run("loginctl", ["show-session", session_id, "-p", "Type", "-p", "Class", "-p", "State", "-p", "Remote", "-p", "Desktop"]) if session_id else {"available": False, "stdout": "", "stderr": "XDG_SESSION_ID absent", "rc": None, "path": None}
props = {}
for line in login["stdout"].splitlines():
    if "=" in line:
        k, v = line.split("=", 1)
        props[k] = v
graphical = bool(os.environ.get("DISPLAY") or os.environ.get("WAYLAND_DISPLAY")) and props.get("Class", os.environ.get("XDG_SESSION_CLASS")) == "user"
autostart_home = pathlib.Path(os.environ.get("XDG_CONFIG_HOME", str(pathlib.Path.home() / ".config"))) / "autostart"
autostart_parent = autostart_home.parent
trigger_ok = graphical and autostart_parent.exists() and os.access(autostart_parent, os.W_OK | os.X_OK)
checks["graphical_trigger"] = capability("supported" if trigger_ok else "unsupported", True,
    {"session": props, "desktop": os.environ.get("XDG_CURRENT_DESKTOP") or os.environ.get("DESKTOP_SESSION"),
     "display_present": graphical, "xdg_autostart_path": str(autostart_home), "parent_writable": os.access(autostart_parent, os.W_OK | os.X_OK)},
    "Active graphical user session and writable XDG autostart location found." if trigger_ok else "No usable graphical XDG autostart trigger was found.")
# Merely having a logout command does not prove that an autostart process is
# synchronously stopped and joined before poweroff; do not approximate it.
checks["clean_stop_delivery"] = capability("unverified", True,
    {"desktop": props.get("Desktop") or os.environ.get("XDG_SESSION_DESKTOP"), "session_state": props.get("State"),
     "xfce_logout_command_present": shutil.which("xfce4-session-logout") is not None},
    "No installed session-resume guard has demonstrated graceful stop delivery and join semantics on this desktop.")

# Firmware recovery: only accept a machine-readable fwupd BIOS setting. Never
# use sudo/dmidecode or alter firmware.
fw = run("fwupdmgr", ["get-bios-setting", "--json"], timeout=15)
fw_evidence = {"probe": "fwupdmgr get-bios-setting --json", "available": fw["available"], "rc": fw["rc"]}
firmware_ok = False
if fw["rc"] == 0:
    try:
        fw_data = json.loads(fw["stdout"])
        settings = fw_data.get("BiosSettings", []) if isinstance(fw_data, dict) else []
        matches = []
        sys_firmware = pathlib.Path("/sys/class/firmware-attributes")
        for setting in settings:
            if not isinstance(setting, dict):
                continue
            label = " ".join(str(setting.get(k, "")) for k in ("Name", "Description", "BiosSettingId")).lower()
            if not any(x in label for x in ("restore ac", "ac power recovery", "after power loss")):
                continue
            current = setting.get("BiosSettingCurrentValue") or setting.get("CurrentValue")
            # Some fwupd versions expose only a sysfs directory and value filename.
            raw_dir = setting.get("Filename")
            raw_name = setting.get("BiosSettingFilename")
            if current is None and raw_dir and raw_name:
                value_path = (pathlib.Path(str(raw_dir)) / str(raw_name)).resolve()
                if sys_firmware in value_path.parents:
                    value_text = read_text(value_path, 4096).strip()
                    current = value_text or None
            matches.append({"name": setting.get("Name"), "current_value": current,
                            "possible_values": setting.get("BiosSettingPossibleValues")})
        enabled_values = {"power on", "last state", "enabled", "on"}
        firmware_ok = any(str(m.get("current_value", "")).strip().lower() in enabled_values for m in matches)
        fw_evidence["matching_settings"] = matches
        fw_evidence["enabled_current_value_detected"] = firmware_ok
    except (json.JSONDecodeError, AttributeError):
        fw_evidence["json_valid"] = False
checks["firmware_ac_recovery"] = capability("supported" if firmware_ok else "unverified", True, fw_evidence,
    "A readable firmware AC-recovery setting appears enabled." if firmware_ok else "Firmware AC recovery could not be verified through a non-privileged machine-readable interface.")

# Display-manager auto-login is supported only when the configured account is
# the current account. Read common configs without changing them.
user = os.environ.get("USER") or pathlib.Path.home().name
auto_records = []
for p in [pathlib.Path("/etc/lightdm/lightdm.conf"), pathlib.Path("/etc/gdm3/custom.conf"), pathlib.Path("/etc/sddm.conf")]:
    text = read_text(p, 200_000)
    if not text:
        continue
    for line in text.splitlines():
        stripped = line.strip()
        if stripped.startswith("#") or "=" not in stripped:
            continue
        key, value = [x.strip() for x in stripped.split("=", 1)]
        if key.lower() in {"autologin-user", "automaticlogin", "user"} and value:
            auto_records.append({"path": str(p), "key": key, "user": value})
auto_ok = any(r["user"] == user for r in auto_records)
checks["desktop_auto_login"] = capability("supported" if auto_ok else "unsupported", True,
    {"current_user": user, "configured": auto_records},
    "Auto-login is configured for the current user." if auto_ok else "No supported display-manager auto-login configuration for the current user was found.")

# Network target is recorded, not contacted. Local recovery is allowed offline.
route = run("ip", ["route", "show", "default"])
default_route = route["stdout"].splitlines()[0] if route["rc"] == 0 and route["stdout"].splitlines() else None
checks["network_target"] = capability("available" if default_route else "unavailable", False,
    {"default_route": default_route}, "Default-route target recorded; no network request was made.")

# VS Code CLI and workspace/profile semantics are statically probed via help.
code_version = run("code", ["--version"])
code_help = run("code", ["--help"])
code_ok = code_version["rc"] == 0
workspace_ok = code_ok and "--profile <profileName>" in code_help["stdout"] and "--reuse-window" in code_help["stdout"]
vscode_adapter_ok, vscode_adapter_evidence, vscode_adapter_detail = adapter_capability("vscode")
checks["vscode_cli"] = capability("supported" if workspace_ok and vscode_adapter_ok else "unsupported", True,
    {"path": code_version["path"], "version": code_version["stdout"].splitlines()[0] if code_version["stdout"].splitlines() else None,
     "profile_option": "--profile <profileName>" in code_help["stdout"], "reuse_window_option": "--reuse-window" in code_help["stdout"],
     "workspace_argument_behavior": "code [options] [paths...]" in code_help["stdout"], "adapter": vscode_adapter_evidence},
    "CLI behavior and exact workspace/profile identity adapter are verified." if workspace_ok and vscode_adapter_ok else "VS Code CLI semantics or its exact identity adapter is unverified: " + vscode_adapter_detail)

# Detect the installed OpenAI chat extension and inspect only its public command
# contributions. Generic panel/new-chat commands are deliberately insufficient.
extensions = run("code", ["--list-extensions", "--show-versions"])
chat_id, chat_ver = None, None
for line in extensions["stdout"].splitlines():
    if line.lower().startswith("openai.chatgpt@"):
        chat_id, chat_ver = line.rsplit("@", 1)
        break
chat_path = None
public_commands = []
if chat_id:
    located = run("code", ["--locate-extension", chat_id])
    if located["rc"] == 0 and located["stdout"].strip():
        chat_path = pathlib.Path(located["stdout"].strip()).resolve()
        try:
            manifest = json.loads(read_text(chat_path / "package.json"))
            public_commands = [str(c.get("command")) for c in manifest.get("contributes", {}).get("commands", []) if isinstance(c, dict)]
        except (json.JSONDecodeError, AttributeError):
            pass
chat_adapter_ok, chat_adapter_evidence, chat_adapter_detail = adapter_capability("chat")
# Public command names are evidence only and never establish exact restoration.
chat_ok = bool(chat_id and chat_ver and chat_adapter_ok)
checks["exact_chat_session_api"] = capability("supported" if chat_ok else "unsupported", True,
    {"extension_id": chat_id, "extension_version": chat_ver, "extension_path": str(chat_path) if chat_path else None,
     "public_commands": public_commands, "name_regex_used_for_verdict": False, "adapter": chat_adapter_evidence},
    "Installed extension identity and exact session restore/probe adapter verified." if chat_ok else "Exact chat remains blocked: " + chat_adapter_detail)

terminal_adapter_ok, terminal_adapter_evidence, terminal_adapter_detail = adapter_capability("pi")
checks["integrated_terminal_api"] = capability("supported" if terminal_adapter_ok else "unsupported", True,
    {"code_execute_command_option": "--command" in code_help["stdout"], "adapter": terminal_adapter_evidence},
    "Integrated-terminal creation and full Pi identity probe interface verified." if terminal_adapter_ok else "Integrated terminal remains blocked: " + terminal_adapter_detail)

# Resolve only a separately installed global pi. Probe fixed --version/--help
# vectors; never invoke a session, prompt, provider, or repository executable.
pi_cmd = shutil.which("pi")
pi_real = pathlib.Path(pi_cmd).resolve() if pi_cmd else None
pi_global = bool(pi_real and repo not in [pi_real, *pi_real.parents])
pi_version = run("pi", ["--version"]) if pi_cmd else {"rc": None, "stdout": "", "path": None}
pi_help = run("pi", ["--help"]) if pi_cmd else {"rc": None, "stdout": "", "path": None}
pi_exact = pi_help["rc"] == 0 and "--session <path|id>" in pi_help["stdout"] and "--session-id <id>" in pi_help["stdout"]
pi_ok = pi_global and pi_version["rc"] == 0 and pi_exact
checks["global_pi_resume_probe"] = capability("supported" if pi_ok else "unsupported", True,
    {"command_path": pi_cmd, "resolved_realpath": str(pi_real) if pi_real else None,
     "sha256": sha256(pi_real) if pi_real and pi_real.is_file() else None,
     "version": pi_version["stdout"].strip() or None, "identity": "pi - AI coding assistant" if "AI coding assistant" in pi_help["stdout"] else None,
     "exact_session_option": "--session <path|id>" in pi_help["stdout"], "session_id_option": "--session-id <id>" in pi_help["stdout"],
     "probe_vectors": [["pi", "--version"], ["pi", "--help"]]},
    "Verified non-repository global Pi identity and exact-session syntax." if pi_ok else "Global Pi identity and exact-session resume syntax could not be verified.")

# Venv and active contract resolver (informational at this phase).
venv_raw = os.environ.get("VIRTUAL_ENV")
venv_source = "VIRTUAL_ENV" if venv_raw else None
if not venv_raw and (repo / ".venv/pyvenv.cfg").is_file():
    venv_raw = str(repo / ".venv")
    venv_source = "repository .venv"
venv_root = pathlib.Path(venv_raw).resolve() if venv_raw else None
venv_python_path = venv_root / "bin/python" if venv_root else None
venv_python = venv_python_path.resolve() if venv_python_path and venv_python_path.exists() else None
checks["venv_python"] = capability("available" if venv_python else "unavailable", False,
    {"source": venv_source, "virtual_env_realpath": str(venv_root) if venv_root else None,
     "python_path": str(venv_python_path) if venv_python_path else None,
     "python_executable_realpath": str(venv_python) if venv_python else None},
    "Virtualenv root and Python executable resolved." if venv_python else "No active or repository-local virtualenv with bin/python was found.")
contract_path = (repo / ".ai/current-task.md").resolve()
contract_ok = contract_path.is_file() and repo in contract_path.parents
checks["ai_contract_resolver"] = capability("available" if contract_ok else "unavailable", False,
    {"path": str(contract_path), "sha256": sha256(contract_path) if contract_ok else None, "parsed_as_state": False},
    "Active contract resolved by path/hash only; it was not parsed as lifecycle state." if contract_ok else "Active .ai/current-task.md did not resolve safely beneath the repository.")

# Workbench resolver follows the application's existing file-backed root and
# reads index/task projections only. It performs no PHP invocation or DB access.
tasks_root = (repo / "storage/workbench/development/tasks").resolve()
index_path = tasks_root / "index.json"
task_rows = []
try:
    index = json.loads(read_text(index_path))
    if isinstance(index, dict):
        for task_id, row in index.items():
            task_path = (tasks_root / str(task_id) / "task.json").resolve()
            if tasks_root in task_path.parents and task_path.is_file():
                task = json.loads(read_text(task_path))
                task_rows.append({"task_id": task.get("task_id"), "state": task.get("state"), "path": str(task_path), "sha256": sha256(task_path)})
except (json.JSONDecodeError, AttributeError, OSError):
    pass
active = [r for r in task_rows if r["state"] == "ARCHITECTURE_DECISION_REQUIRED"]
checks["workbench_task_resolver"] = capability("available" if active else "unavailable", False,
    {"root": str(tasks_root), "index_path": str(index_path), "task_count": len(task_rows), "architecture_decision_required_tasks": active},
    "Canonical file-backed task state resolved from task.json." if active else "No readable task.json in canonical ARCHITECTURE_DECISION_REQUIRED state was found.")

blocked = [name for name, value in checks.items() if value["mandatory"] and value["status"] != "supported"]
verdict = "BLOCKED" if blocked else "READY"
report = {
    "schema": "ikabud.session-resume-preflight.v1", "schema_version": "1.0",
    "timestamp": now.isoformat(timespec="microseconds").replace("+00:00", "Z"),
    "repository_realpath": str(repo), "non_mutating": True, "verdict": verdict,
    "blocked_mandatory_capabilities": blocked, "checks": checks
}
json_path = report_dir / ("preflight-" + stamp + ".json")
md_path = report_dir / ("preflight-" + stamp + ".md")
md = ["# Session-resume preflight", "", f"- **Verdict:** `{verdict}`", f"- **Timestamp:** `{report['timestamp']}`",
      f"- **Repository:** `{repo}`", "- **Mode:** non-mutating", "", "## Capability results", ""]
for name, value in checks.items():
    mandatory = "mandatory" if value["mandatory"] else "informational"
    md.extend([f"### `{name}` — {value['status'].upper()} ({mandatory})", "", value["detail"], "",
               "```json", json.dumps(value["evidence"], indent=2, sort_keys=True), "```", ""])
if blocked:
    md.extend(["## Blockers", "", *[f"- `{x}`" for x in blocked], ""])
atomic_write(json_path, (json.dumps(report, indent=2, sort_keys=True) + "\n").encode())
atomic_write(md_path, ("\n".join(md) + "\n").encode())
print(f"{verdict}: {json_path}")
print(f"Markdown: {md_path}")
if blocked:
    print("Missing/unsupported mandatory capabilities: " + ", ".join(blocked), file=sys.stderr)
sys.exit(0 if verdict == "READY" else 3)
PY
rc=$?
set -e
exit "$rc"
