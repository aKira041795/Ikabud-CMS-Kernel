#!/usr/bin/env python3
"""Crash-consistent, descriptor-anchored state operations; no shell evaluation."""
import datetime as dt
import hashlib
import json
import os
import pathlib
import re
import signal
import stat
import sys
import uuid

STATE = pathlib.Path(os.environ["RESUME_STATE_DIR"])
REPO = pathlib.Path(os.environ["RESUME_REPO_ROOT"])
SCHEMA_PATH = pathlib.Path(os.environ["RESUME_SCHEMA"])
SECRET = re.compile(r"(?i)(api[-_]?key|access[-_]?token|authorization|password|passwd|secret|bearer)")
UUID = re.compile(r"^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$")
SHA = re.compile(r"^[0-9a-f]{64}$")
STAMP = re.compile(r"^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]+)?Z$")
NOFOLLOW = getattr(os, "O_NOFOLLOW", 0)
DIRECTORY = getattr(os, "O_DIRECTORY", 0)


def fail(message, code=2):
    print(message, file=sys.stderr)
    raise SystemExit(code)


def now():
    return dt.datetime.now(dt.timezone.utc).isoformat(timespec="seconds").replace("+00:00", "Z")


def parse_time(value):
    if not isinstance(value, str) or not STAMP.fullmatch(value):
        raise ValueError("timestamp shape")
    parsed = dt.datetime.fromisoformat(value.replace("Z", "+00:00"))
    if parsed.tzinfo != dt.timezone.utc:
        raise ValueError("timestamp timezone")
    return parsed


def sha_bytes(data):
    return hashlib.sha256(data).hexdigest()


def reject_secrets(value, location="$"):
    if isinstance(value, dict):
        for key, item in value.items():
            if SECRET.search(str(key)): fail("secret-bearing key rejected at " + location)
            reject_secrets(item, location + "." + str(key))
    elif isinstance(value, list):
        for index, item in enumerate(value): reject_secrets(item, f"{location}[{index}]")
    elif isinstance(value, str) and (SECRET.search(value) or "-----BEGIN " in value):
        fail("secret-bearing value rejected at " + location)


# Draft-07 subset covering session.schema.json.
def schema_validate(instance):
    try:
        schema = json.loads(SCHEMA_PATH.read_text())
    except (OSError, json.JSONDecodeError) as exc:
        fail("cannot read session schema: " + str(exc))
    def check(value, rule, where="$"):
        if "$ref" in rule:
            target = schema
            for part in rule["$ref"].removeprefix("#/").split("/"): target = target[part]
            return check(value, target, where)
        if "const" in rule and value != rule["const"]: raise ValueError(where + ": const")
        if "enum" in rule and value not in rule["enum"]: raise ValueError(where + ": enum")
        typ = rule.get("type")
        valid = {"object": isinstance(value, dict), "array": isinstance(value, list),
                 "string": isinstance(value, str), "integer": isinstance(value, int) and not isinstance(value, bool)}
        if typ and not valid.get(typ, False): raise ValueError(where + ": expected " + typ)
        if isinstance(value, dict):
            for key in rule.get("required", []):
                if key not in value: raise ValueError(where + ": missing " + key)
            props = rule.get("properties", {})
            if rule.get("additionalProperties") is False:
                extra = set(value) - set(props)
                if extra: raise ValueError(where + ": extra " + sorted(extra)[0])
            for key, item in value.items():
                if key in props: check(item, props[key], where + "." + key)
        if isinstance(value, list):
            if not rule.get("minItems", 0) <= len(value) <= rule.get("maxItems", 10**9): raise ValueError(where + ": array length")
            for i, item in enumerate(value): check(item, rule.get("items", {}), f"{where}[{i}]")
        if isinstance(value, str):
            if not rule.get("minLength", 0) <= len(value) <= rule.get("maxLength", 10**9): raise ValueError(where + ": string length")
            if "pattern" in rule and re.search(rule["pattern"], value) is None: raise ValueError(where + ": pattern")
            if rule.get("format") == "date-time": parse_time(value)
        if isinstance(value, int) and value < rule.get("minimum", value): raise ValueError(where + ": minimum")
    try: check(instance, schema)
    except (ValueError, TypeError) as exc: fail("schema validation failed: " + str(exc))
    reject_secrets(instance)


def valid_uuid(value, label="UUID"):
    if not UUID.fullmatch(str(value)): fail("malformed " + label)
    return str(value)


def json_bytes(value):
    reject_secrets(value)
    return (json.dumps(value, sort_keys=True, indent=2) + "\n").encode()


def secure_abs_fd(raw, want_dir=False):
    """Open an absolute realpath component-by-component without following links."""
    path = pathlib.Path(raw)
    if not path.is_absolute(): fail("path is not absolute: " + str(raw))
    parts = path.parts[1:]
    fd = os.open("/", os.O_RDONLY | DIRECTORY)
    try:
        for index, part in enumerate(parts):
            if part in ("", ".", ".."):
                fail("unsafe path component: " + str(raw))
            flags = os.O_RDONLY | NOFOLLOW
            if index < len(parts) - 1 or want_dir: flags |= DIRECTORY
            newfd = os.open(part, flags, dir_fd=fd)
            os.close(fd); fd = newfd
        mode = os.fstat(fd).st_mode
        if want_dir and not stat.S_ISDIR(mode): fail("not a directory: " + str(raw))
        if not want_dir and not stat.S_ISREG(mode): fail("not a regular file: " + str(raw))
        return fd
    except BaseException:
        try: os.close(fd)
        except OSError: pass
        raise


def test_pause(kind, raw):
    ready = os.environ.get("IKABUD_RESUME_TEST_" + kind + "_READY")
    release = os.environ.get("IKABUD_RESUME_TEST_" + kind + "_RELEASE")
    target = os.environ.get("IKABUD_RESUME_TEST_SWAP_TARGET")
    if ready and release and (not target or str(raw) == target):
        pathlib.Path(ready).touch()
        import time
        deadline = time.monotonic() + 5
        while not pathlib.Path(release).exists() and time.monotonic() < deadline: time.sleep(0.005)
        if not pathlib.Path(release).exists(): fail("test swap synchronization timed out")


def secure_file(raw):
    try: fd = secure_abs_fd(raw, False)
    except OSError: fail("invalid or symlinked file: " + str(raw))
    try:
        test_pause("PATH_PIN", raw)
        data = b""
        while True:
            chunk = os.read(fd, 1024 * 1024)
            if not chunk: break
            data += chunk
        before = os.fstat(fd)
        after = os.stat(raw, follow_symlinks=False)
        if (before.st_dev, before.st_ino) != (after.st_dev, after.st_ino): fail("file changed during validation: " + str(raw))
        return pathlib.Path(raw), data
    except OSError: fail("file changed during validation: " + str(raw))
    finally: os.close(fd)


def secure_dir(raw):
    try: fd = secure_abs_fd(raw, True)
    except OSError: fail("invalid or symlinked directory: " + str(raw))
    try:
        st = os.fstat(fd); live = os.stat(raw, follow_symlinks=False)
        if (st.st_dev, st.st_ino) != (live.st_dev, live.st_ino): fail("directory changed during validation: " + str(raw))
        return pathlib.Path(raw)
    finally: os.close(fd)


def resolved_file(raw):
    path = pathlib.Path(raw)
    if not path.is_absolute(): fail("path is not absolute: " + str(raw))
    try: resolved = path.resolve(strict=True)
    except OSError: fail("missing resolved file: " + str(raw))
    secure_file(resolved)
    return resolved


def beneath(path, root):
    return path == root or root in path.parents


class StateStore:
    def __init__(self):
        try:
            self.fd = os.open(STATE, os.O_RDONLY | DIRECTORY | NOFOLLOW)
            self.identity = self._identity(self.fd)
            self.revalidate()
        except OSError as exc:
            fail("invalid state root: " + str(exc))

    @staticmethod
    def _identity(fd):
        st = os.fstat(fd); return st.st_dev, st.st_ino

    def revalidate(self):
        try:
            st = os.stat(STATE, follow_symlinks=False)
            if not stat.S_ISDIR(st.st_mode) or (st.st_dev, st.st_ino) != self.identity:
                fail("state root changed during operation")
        except OSError: fail("state root changed during operation")

    def child_dir(self, name, create=False):
        if "/" in name or name in ("", ".", ".."): fail("unsafe state directory name")
        self.revalidate()
        if create:
            try: os.mkdir(name, 0o700, dir_fd=self.fd)
            except FileExistsError: pass
        try: fd = os.open(name, os.O_RDONLY | DIRECTORY | NOFOLLOW, dir_fd=self.fd)
        except OSError: fail("missing or symlinked state directory: " + name)
        if stat.S_IMODE(os.fstat(fd).st_mode) != 0o700:
            os.close(fd); fail("state directory permissions must be 0700: " + name)
        return fd

    def generation_dir(self, kind, generation, create=False):
        generation = valid_uuid(generation, "generation UUID")
        rootfd = self.child_dir(kind, False)
        try:
            if create:
                try: os.mkdir(generation, 0o700, dir_fd=rootfd)
                except FileExistsError: pass
            try: genfd = os.open(generation, os.O_RDONLY | DIRECTORY | NOFOLLOW, dir_fd=rootfd)
            except OSError: fail("missing or symlinked state generation directory")
            if stat.S_IMODE(os.fstat(genfd).st_mode) != 0o700:
                os.close(genfd); fail("generation directory permissions must be 0700")
            test_pause("GEN_PIN", str(STATE / kind / generation))
            live = os.stat(generation, dir_fd=rootfd, follow_symlinks=False)
            opened = os.fstat(genfd)
            if not stat.S_ISDIR(live.st_mode) or (live.st_dev, live.st_ino) != (opened.st_dev, opened.st_ino):
                os.close(genfd); fail("generation directory changed while opening")
            return rootfd, genfd
        except BaseException:
            os.close(rootfd); raise

    def read(self, dirfd, name, require_mode=True):
        self.revalidate()
        if "/" in name or name in ("", ".", ".."): fail("unsafe state filename")
        try: fd = os.open(name, os.O_RDONLY | NOFOLLOW, dir_fd=dirfd)
        except FileNotFoundError: raise
        except OSError: fail("missing or symlinked state file: " + name)
        try:
            st = os.fstat(fd)
            if not stat.S_ISREG(st.st_mode): fail("state record is not regular: " + name)
            if require_mode and stat.S_IMODE(st.st_mode) != 0o600: fail("state file permissions must be 0600: " + name)
            chunks = []
            while True:
                block = os.read(fd, 1024 * 1024)
                if not block: break
                chunks.append(block)
            self.revalidate()
            return b"".join(chunks)
        finally: os.close(fd)

    def read_json(self, dirfd, name):
        try: return json.loads(self.read(dirfd, name))
        except FileNotFoundError: fail("missing state file: " + name)
        except json.JSONDecodeError: fail("malformed JSON: " + name)

    def exists(self, dirfd, name):
        try:
            fd = os.open(name, os.O_RDONLY | NOFOLLOW, dir_fd=dirfd); os.close(fd); return True
        except FileNotFoundError: return False
        except OSError: fail("symlinked or invalid state record: " + name)

    def atomic(self, dirfd, name, data):
        tmp = "." + name + "." + uuid.uuid4().hex + ".tmp"
        fd = os.open(tmp, os.O_WRONLY | os.O_CREAT | os.O_EXCL | NOFOLLOW, 0o600, dir_fd=dirfd)
        try:
            os.write(fd, data); os.fsync(fd); os.close(fd); fd = -1
            os.replace(tmp, name, src_dir_fd=dirfd, dst_dir_fd=dirfd)
            os.fsync(dirfd); self.revalidate()
        except BaseException:
            if fd >= 0: os.close(fd)
            try: os.unlink(tmp, dir_fd=dirfd)
            except OSError: pass
            raise

    def atomic_json(self, dirfd, name, value): self.atomic(dirfd, name, json_bytes(value))

    def immutable_json(self, dirfd, name, value):
        data = json_bytes(value)
        fd = os.open(name, os.O_WRONLY | os.O_CREAT | os.O_EXCL | NOFOLLOW, 0o600, dir_fd=dirfd)
        try: os.write(fd, data); os.fsync(fd)
        except BaseException:
            try: os.unlink(name, dir_fd=dirfd)
            except OSError: pass
            raise
        finally: os.close(fd)
        os.fsync(dirfd); self.revalidate()

    def unlink(self, dirfd, name):
        try: os.unlink(name, dir_fd=dirfd)
        except FileNotFoundError: return
        os.fsync(dirfd); self.revalidate()

    def revalidate_generation(self, rootfd, genfd, generation):
        try: live = os.stat(generation, dir_fd=rootfd, follow_symlinks=False)
        except OSError: fail("generation directory changed during operation")
        opened = os.fstat(genfd)
        if not stat.S_ISDIR(live.st_mode) or (live.st_dev, live.st_ino) != (opened.st_dev, opened.st_ino):
            fail("generation directory changed during operation")


STORE = StateStore()


def env_required(name):
    value = os.environ.get(name, "")
    if not value: fail(name + " is required")
    return value


def validate_pi_argv(argv, executable):
    if not isinstance(argv, list) or not argv or argv[0] != executable or len(argv) > 64: fail("Pi argv[0] must be the resolved Pi executable")
    no_value = {"--continue", "-c", "--resume", "-r"}; with_value = {"--session", "--session-id", "--session-dir", "--name", "-n"}
    index = 1
    while index < len(argv):
        option = argv[index]
        if option in no_value: index += 1
        elif option in with_value and index + 1 < len(argv):
            value = argv[index + 1]
            if not isinstance(value, str) or not value or value.startswith("-") or SECRET.search(value): fail("unsafe Pi option value")
            index += 2
        else: fail("Pi option is not replay-allowlisted: " + str(option))


def build_snapshot(generation, sequence, started, boot):
    repository = secure_dir(REPO.resolve(strict=True))
    workspace = secure_dir(pathlib.Path(os.environ.get("IKABUD_RESUME_WORKSPACE", str(repository))).resolve(strict=True))
    cwd = secure_dir(pathlib.Path(os.environ.get("IKABUD_RESUME_CWD", str(repository))).resolve(strict=True))
    venv = secure_dir(pathlib.Path(os.environ.get("IKABUD_RESUME_VENV", os.environ.get("VIRTUAL_ENV", str(repository / ".venv")))).resolve(strict=True))
    python = resolved_file(os.environ.get("IKABUD_RESUME_VENV_PYTHON", str(venv / "bin/python")))
    pi = resolved_file(env_required("IKABUD_RESUME_PI_EXECUTABLE"))
    if beneath(pi, repository): fail("repository executable cannot be Pi")
    contract = pathlib.Path(os.environ.get("IKABUD_RESUME_CONTRACT", str(repository / ".ai/current-task.md"))).resolve(strict=True)
    task = pathlib.Path(env_required("IKABUD_RESUME_WORKBENCH_TASK")).resolve(strict=True)
    _, contract_data = secure_file(contract); _, task_bytes = secure_file(task); _, pi_data = secure_file(pi)
    if not all(beneath(p, repository) for p in (workspace, cwd, contract, task)): fail("repository-owned path escaped repository")
    try: task_data = json.loads(task_bytes)
    except json.JSONDecodeError: fail("invalid Workbench task JSON")
    argv = json.loads(os.environ.get("IKABUD_RESUME_PI_ARGV", json.dumps([str(pi), "--continue"])))
    validate_pi_argv(argv, str(pi)); timestamp = now()
    snapshot = {
      "schema_version":"1.0", "adaptor_versions":{k:"1.0" for k in ("capture","vscode","chat","terminal","pi","workbench")},
      "generation":generation, "sequence":sequence, "boot_id":boot,
      "timestamps":{"generation_started_at":started,"captured_at":timestamp,"heartbeat_at":timestamp},
      "approved_realpaths":{"repository":str(repository),"workspace":str(workspace),"cwd":str(cwd),"venv":str(venv),"pi_executable":str(pi),"contract":str(contract),"workbench_task":str(task)},
      "workspace":{"path":str(workspace),"kind":os.environ.get("IKABUD_RESUME_WORKSPACE_KIND","folder"),"profile":os.environ.get("IKABUD_RESUME_VSCODE_PROFILE","Default")},
      "chat":{"extension_id":env_required("IKABUD_RESUME_CHAT_EXTENSION_ID"),"extension_version":env_required("IKABUD_RESUME_CHAT_EXTENSION_VERSION"),"session_id":env_required("IKABUD_RESUME_CHAT_SESSION_ID")},
      "cwd":str(cwd), "git":{"branch":env_required("IKABUD_RESUME_GIT_BRANCH"),"head":env_required("IKABUD_RESUME_GIT_HEAD")},
      "venv":{"root":str(venv),"python":str(python)},
      "pi":{"executable":str(pi),"sha256":sha_bytes(pi_data),"version":env_required("IKABUD_RESUME_PI_VERSION"),"identity":env_required("IKABUD_RESUME_PI_IDENTITY"),"argv":argv},
      "contract":{"path":str(contract),"sha256":sha_bytes(contract_data)},
      "workbench":{"task_path":str(task),"sha256":sha_bytes(task_bytes),"task_id":str(task_data.get("task_id","")),"state":str(task_data.get("state",""))}}
    schema_validate(snapshot); return snapshot


def snapshot_name(sequence): return f"{sequence:020d}.json"


def read_snapshot(generation, sequence):
    rootfd, genfd = STORE.generation_dir("snapshots", generation)
    try:
        data = STORE.read(genfd, snapshot_name(sequence)); value = json.loads(data); schema_validate(value)
        STORE.revalidate_generation(rootfd, genfd, generation); return data, value
    except json.JSONDecodeError: fail("malformed snapshot JSON")
    finally: os.close(genfd); os.close(rootfd)


def checked_snapshot(raw, generation):
    path = pathlib.Path(raw); expected = STATE / "snapshots" / generation
    if not path.is_absolute() or path.parent != expected or not re.fullmatch(r"[0-9]{20}\.json", path.name): fail("snapshot path escaped generation")
    sequence = int(path.stem); data, snapshot = read_snapshot(generation, sequence)
    if snapshot["generation"] != generation or snapshot["sequence"] != sequence: fail("snapshot generation/sequence mismatch")
    return path, data, snapshot


def marker_for(snapshot, data):
    return {"schema":"ikabud.unclean-marker.v1","generation":snapshot["generation"],"sequence":snapshot["sequence"],
            "snapshot":str(pathlib.Path("snapshots") / snapshot["generation"] / snapshot_name(snapshot["sequence"])),"digest":sha_bytes(data),
            "boot_id":snapshot["boot_id"],"heartbeat_at":snapshot["timestamps"]["heartbeat_at"],"generation_started_at":snapshot["timestamps"]["generation_started_at"]}


def validate_marker(marker):
    required = {"schema","generation","sequence","snapshot","digest","boot_id","heartbeat_at","generation_started_at"}
    if not isinstance(marker, dict) or set(marker) != required or marker.get("schema") != "ikabud.unclean-marker.v1": fail("malformed marker")
    valid_uuid(marker["generation"], "marker generation"); valid_uuid(marker["boot_id"], "marker boot UUID")
    if not isinstance(marker["sequence"], int) or marker["sequence"] < 1 or not SHA.fullmatch(str(marker["digest"])): fail("malformed marker commit")
    try: parse_time(marker["heartbeat_at"]); parse_time(marker["generation_started_at"])
    except ValueError: fail("malformed marker timestamp")
    expected = pathlib.Path("snapshots") / marker["generation"] / snapshot_name(marker["sequence"])
    if pathlib.Path(marker["snapshot"]) != expected: fail("marker snapshot path escaped or mismatched")


def root_json(name): return STORE.read_json(STORE.fd, name)


def fault(point):
    if os.environ.get("IKABUD_RESUME_TEST_INTERRUPT") == point: os._exit(97)


def publish_snapshot(snapshot, replace_marker=True):
    rootfd, genfd = STORE.generation_dir("snapshots", snapshot["generation"], create=True)
    try:
        data = json_bytes(snapshot); STORE.immutable_json(genfd, snapshot_name(snapshot["sequence"]), snapshot)
        STORE.revalidate_generation(rootfd, genfd, snapshot["generation"])
    finally: os.close(genfd); os.close(rootfd)
    fault("after-snapshot")
    if replace_marker:
        STORE.atomic_json(STORE.fd, "unclean.marker", marker_for(snapshot, data)); fault("after-marker")
    STORE.atomic_json(STORE.fd, "last-session.json", snapshot); fault("after-last-session")


def cmd_arm(boot):
    valid_uuid(boot, "boot UUID")
    if STORE.exists(STORE.fd, "unclean.marker"): fail("marker already exists; refusing to replace a generation")
    generation = str(uuid.uuid4()); snapshot = build_snapshot(generation, 1, now(), boot)
    publish_snapshot(snapshot); print(generation)


def cmd_capture(boot):
    marker = root_json("unclean.marker"); validate_marker(marker)
    if marker["boot_id"] != boot: fail("capture cannot update a marker from another boot")
    snapshot = build_snapshot(marker["generation"], marker["sequence"] + 1, marker["generation_started_at"], boot)
    # Caller holds stable.lock. Re-read the descriptor-anchored commit before publication.
    current = root_json("unclean.marker")
    if current != marker: fail("marker changed during capture")
    publish_snapshot(snapshot); print(snapshot["sequence"])


def record_failure(generation, stage, detail, lifecycle_state=None):
    rootfd, genfd = STORE.generation_dir("acks", generation, create=True)
    try:
        value = {"schema":"ikabud.resume-failure.v1","generation":generation,"stage":stage,"detail":detail[:500],"failed_at":now()}
        if lifecycle_state: value["lifecycle_state"] = lifecycle_state
        STORE.atomic_json(genfd, "failure.json", value)
        STORE.revalidate_generation(rootfd, genfd, generation)
    finally: os.close(genfd); os.close(rootfd)


def validate_live(s):
    approved = s["approved_realpaths"]; validate_pi_argv(s["pi"]["argv"], s["pi"]["executable"])
    repository = secure_dir(approved["repository"])
    dirs = [(secure_dir(s["workspace"]["path"]), approved["workspace"]),(secure_dir(s["cwd"]), approved["cwd"]),(secure_dir(s["venv"]["root"]), approved["venv"])]
    files = [(s["venv"]["python"], s["venv"]["python"]),(s["pi"]["executable"], approved["pi_executable"]),(s["contract"]["path"], approved["contract"]),(s["workbench"]["task_path"], approved["workbench_task"])]
    if any(str(path) != recorded for path, recorded in dirs): fail("approved realpath substitution")
    loaded = []
    for raw, recorded in files:
        path, data = secure_file(raw)
        if str(path) != recorded: fail("approved realpath substitution")
        loaded.append((path, data))
    if not all(beneath(path, repository) for path in (dirs[0][0], dirs[1][0], loaded[2][0], loaded[3][0])): fail("approved path escaped repository")
    if sha_bytes(loaded[1][1]) != s["pi"]["sha256"] or sha_bytes(loaded[2][1]) != s["contract"]["sha256"] or sha_bytes(loaded[3][1]) != s["workbench"]["sha256"]: fail("critical hash mismatch")
    try: task = json.loads(loaded[3][1])
    except json.JSONDecodeError: fail("Workbench task malformed")
    if task.get("task_id") != s["workbench"]["task_id"] or task.get("state") != s["workbench"]["state"]: fail("Workbench identity/state mismatch")
    if os.environ.get("IKABUD_RESUME_PI_IDENTITY", "") != s["pi"]["identity"] or os.environ.get("IKABUD_RESUME_PI_VERSION", "") != s["pi"]["version"]: fail("Pi identity/version mismatch")
    if os.environ.get("IKABUD_RESUME_GIT_BRANCH", "") != s["git"]["branch"] or os.environ.get("IKABUD_RESUME_GIT_HEAD", "") != s["git"]["head"]:
        record_failure(s["generation"], "architecture", "Git branch/HEAD mismatch; worktree preserved", "ARCHITECTURE_DECISION_REQUIRED")
        fail("ARCHITECTURE_DECISION_REQUIRED: Git branch/HEAD mismatch; worktree preserved")


def validate_recovery(boot):
    marker = root_json("unclean.marker"); validate_marker(marker)
    if marker["boot_id"] == boot:
        print(json.dumps({"decision":"same-boot","generation":marker["generation"]})); return 10
    data, snapshot = read_snapshot(marker["generation"], marker["sequence"])
    if sha_bytes(data) != marker["digest"]: fail("snapshot digest mismatch")
    if snapshot["generation"] != marker["generation"] or snapshot["sequence"] != marker["sequence"] or snapshot["boot_id"] != marker["boot_id"]: fail("stale marker/snapshot pair")
    try: skew = abs((parse_time(snapshot["timestamps"]["heartbeat_at"]) - parse_time(marker["heartbeat_at"])).total_seconds())
    except ValueError: fail("invalid heartbeat timestamp")
    if skew > 45: fail("heartbeat skew exceeds 45 seconds")
    validate_live(snapshot)
    path = STATE / "snapshots" / marker["generation"] / snapshot_name(marker["sequence"])
    print(json.dumps({"decision":"replay","generation":marker["generation"],"snapshot":str(path)})); return 0


def validate_intent(value, generation, snapshot_digest=None):
    keys = {"schema","generation","nonce","created_at","snapshot_digest"}
    if not isinstance(value, dict) or set(value) != keys or value.get("schema") != "ikabud.resume-intent.v1" or value.get("generation") != generation: fail("invalid recovery intent schema")
    valid_uuid(value.get("nonce"), "intent nonce")
    if not SHA.fullmatch(str(value.get("snapshot_digest"))): fail("invalid recovery intent digest")
    try: parse_time(value.get("created_at"))
    except (ValueError, TypeError): fail("invalid recovery intent timestamp")
    if snapshot_digest is not None and value["snapshot_digest"] != snapshot_digest: fail("recovery intent snapshot mismatch")
    return value


def validate_ack(value, generation, stage, nonce, snapshot_digest):
    keys = {"schema","generation","stage","nonce","acknowledged_at","snapshot_digest"}
    if not isinstance(value, dict) or set(value) != keys or value.get("schema") != "ikabud.resume-ack.v1": fail("invalid acknowledgement schema")
    if value.get("generation") != generation or value.get("stage") != stage or value.get("nonce") != nonce: fail("acknowledgement identity mismatch")
    if value.get("snapshot_digest") != snapshot_digest or not SHA.fullmatch(str(value.get("snapshot_digest"))): fail("acknowledgement snapshot digest mismatch")
    try: parse_time(value.get("acknowledged_at"))
    except (ValueError, TypeError): fail("invalid acknowledgement timestamp")


def ack_dir(generation, create=True): return STORE.generation_dir("acks", generation, create=create)


def cmd_nonce(generation, snapshot):
    generation = valid_uuid(generation, "generation UUID"); _, data, _ = checked_snapshot(snapshot, generation); expected = sha_bytes(data)
    rootfd, genfd = ack_dir(generation)
    try:
        if STORE.exists(genfd, "intent.json"):
            value = validate_intent(STORE.read_json(genfd, "intent.json"), generation, expected)
        else:
            value = {"schema":"ikabud.resume-intent.v1","generation":generation,"nonce":str(uuid.uuid4()),"created_at":now(),"snapshot_digest":expected}
            STORE.atomic_json(genfd, "intent.json", value)
        STORE.revalidate_generation(rootfd, genfd, generation)
        print(value["nonce"])
    finally: os.close(genfd); os.close(rootfd)


def cmd_ack(generation, stage, nonce, snapshot):
    generation = valid_uuid(generation, "generation UUID"); valid_uuid(nonce, "acknowledgement nonce")
    if stage not in {"vscode","chat","pi","complete"}: fail("invalid acknowledgement stage")
    _, data, _ = checked_snapshot(snapshot, generation); expected = sha_bytes(data)
    rootfd, genfd = ack_dir(generation)
    try:
        intent = validate_intent(STORE.read_json(genfd, "intent.json"), generation, expected)
        if intent["nonce"] != nonce: fail("acknowledgement nonce conflicts with intent")
        name = stage + ".json"; value = {"schema":"ikabud.resume-ack.v1","generation":generation,"stage":stage,"nonce":nonce,"acknowledged_at":now(),"snapshot_digest":expected}
        if STORE.exists(genfd, name):
            existing = STORE.read_json(genfd, name)
            try: validate_ack(existing, generation, stage, nonce, expected)
            except SystemExit: fail("conflicting acknowledgement already exists")
            STORE.revalidate_generation(rootfd, genfd, generation); return
        STORE.atomic_json(genfd, name, value)
        STORE.revalidate_generation(rootfd, genfd, generation)
    finally: os.close(genfd); os.close(rootfd)


def cmd_ack_valid(generation, stage, nonce, snapshot):
    generation = valid_uuid(generation, "generation UUID"); valid_uuid(nonce, "acknowledgement nonce")
    if stage not in {"vscode","chat","pi","complete"}: fail("invalid acknowledgement stage")
    _, data, _ = checked_snapshot(snapshot, generation); expected = sha_bytes(data)
    rootfd, genfd = ack_dir(generation)
    try:
        intent = validate_intent(STORE.read_json(genfd, "intent.json"), generation, expected)
        if intent["nonce"] != nonce:
            STORE.revalidate_generation(rootfd, genfd, generation); return 1
        name = stage + ".json"
        if not STORE.exists(genfd, name):
            STORE.revalidate_generation(rootfd, genfd, generation); return 1
        validate_ack(STORE.read_json(genfd, name), generation, stage, nonce, expected)
        STORE.revalidate_generation(rootfd, genfd, generation); return 0
    finally: os.close(genfd); os.close(rootfd)


def cmd_rollover(old_generation, snapshot_path_value, boot):
    old_generation = valid_uuid(old_generation, "generation UUID"); valid_uuid(boot, "boot UUID")
    marker = root_json("unclean.marker"); validate_marker(marker)
    if marker["generation"] != old_generation: fail("newer generation must not be replaced")
    _, _, old = checked_snapshot(snapshot_path_value, old_generation)
    generation = str(uuid.uuid4()); timestamp = now(); new = dict(old)
    new["generation"] = generation; new["sequence"] = 1; new["boot_id"] = boot; new["timestamps"] = {"generation_started_at":timestamp,"captured_at":timestamp,"heartbeat_at":timestamp}
    schema_validate(new); publish_snapshot(new); print(generation)


def proc_identity(pid):
    try:
        stat_fields = pathlib.Path(f"/proc/{pid}/stat").read_text().split()
        start = stat_fields[21]
        exe = os.readlink(f"/proc/{pid}/exe")
        argv = pathlib.Path(f"/proc/{pid}/cmdline").read_bytes().split(b"\0")
        if argv and argv[-1] == b"": argv.pop()
        return {"pid":pid,"start_time":start,"executable":exe,"argv":[os.fsdecode(x) for x in argv]}
    except (OSError, IndexError, ValueError): return None


def validate_pid_record(value):
    if not isinstance(value, dict) or set(value) != {"schema","pid","start_time","executable","argv","capture_script"} or value.get("schema") != "ikabud.capture-pid.v1": fail("invalid capture PID record")
    if not isinstance(value.get("pid"), int) or value["pid"] < 1 or not str(value.get("start_time", "")).isdigit(): fail("invalid capture PID identity")
    expected_script = str(pathlib.Path(value.get("capture_script", "")))
    if expected_script != str((REPO / "tools/session-resume/capture.sh").resolve()): fail("capture script identity mismatch")
    if not isinstance(value.get("argv"), list) or expected_script not in value["argv"] or len(value["argv"]) not in (1, 2): fail("capture argv identity mismatch")
    if len(value["argv"]) == 2 and value["argv"][1] != expected_script: fail("capture argv identity mismatch")
    if value["executable"] not in ("/usr/bin/bash", "/bin/bash"): fail("capture executable identity mismatch")
    return value


def cmd_pid_write(pid_raw, script, boot):
    boot = valid_uuid(boot, "boot UUID")
    # This check runs under stable.lock.  It prevents a capture queued behind a
    # disarm from publishing a writer PID after disarm removes the marker.
    if not STORE.exists(STORE.fd, "unclean.marker"): fail("capture marker is not armed")
    marker = root_json("unclean.marker"); validate_marker(marker)
    if marker["boot_id"] != boot: fail("capture boot does not match armed marker")
    pid = int(pid_raw); identity = proc_identity(pid)
    if identity is None: fail("capture process vanished before PID publication")
    expected = str(pathlib.Path(script).resolve())
    identity.update({"schema":"ikabud.capture-pid.v1","capture_script":expected})
    validate_pid_record(identity)
    if STORE.exists(STORE.fd, "capture.pid"):
        existing = validate_pid_record(root_json("capture.pid")); live = proc_identity(existing["pid"])
        if live and all(live[k] == existing[k] for k in ("pid","start_time","executable","argv")): fail("capture writer already active")
        fail("stale capture PID record requires operator review")
    STORE.atomic_json(STORE.fd, "capture.pid", identity)


def pid_matches(record):
    current = proc_identity(record["pid"])
    return bool(current and all(current[k] == record[k] for k in ("pid","start_time","executable","argv")))


def cmd_pid_status():
    if not STORE.exists(STORE.fd, "capture.pid"): return 3
    record = validate_pid_record(root_json("capture.pid")); print(json.dumps(record, separators=(",", ":"))); return 0 if pid_matches(record) else 1


def cmd_pid_signal():
    record = validate_pid_record(root_json("capture.pid"))
    try:
        pidfd = os.pidfd_open(record["pid"], 0)
    except (AttributeError, OSError) as exc:
        fail("cannot pin capture process with pidfd: " + str(exc))
    try:
        # Test-only synchronization makes exit/reuse before signal deterministic.
        test_pause("PIDFD_SIGNAL", record["pid"])
        if not pid_matches(record): fail("capture exited or identity changed before signal")
        signal.pidfd_send_signal(pidfd, signal.SIGTERM)
    except (AttributeError, OSError) as exc:
        fail("cannot signal pinned capture process: " + str(exc))
    finally:
        os.close(pidfd)
    print(json.dumps(record, separators=(",", ":")))


def cmd_pid_match(pid_raw, start_time, executable, argv_json):
    try: argv = json.loads(argv_json)
    except json.JSONDecodeError: fail("invalid saved capture argv")
    current = proc_identity(int(pid_raw))
    return 0 if current and current["start_time"] == start_time and current["executable"] == executable and current["argv"] == argv else 1


def cmd_pid_unlink(pid_raw):
    if not STORE.exists(STORE.fd, "capture.pid"): return
    record = validate_pid_record(root_json("capture.pid"))
    if record["pid"] == int(pid_raw) and pid_matches(record): STORE.unlink(STORE.fd, "capture.pid")


def main():
    if len(sys.argv) < 2: fail("operation required")
    op = sys.argv[1]
    if op == "arm": cmd_arm(sys.argv[2])
    elif op == "capture": cmd_capture(sys.argv[2])
    elif op == "validate":
        _, data = secure_file(sys.argv[2])
        try: value = json.loads(data)
        except json.JSONDecodeError: fail("malformed JSON")
        schema_validate(value); print("valid")
    elif op == "decide": raise SystemExit(validate_recovery(sys.argv[2]))
    elif op == "nonce": cmd_nonce(sys.argv[2], sys.argv[3])
    elif op == "ack": cmd_ack(sys.argv[2], sys.argv[3], sys.argv[4], sys.argv[5])
    elif op == "ack-valid": raise SystemExit(cmd_ack_valid(sys.argv[2], sys.argv[3], sys.argv[4], sys.argv[5]))
    elif op == "rollover": cmd_rollover(sys.argv[2], sys.argv[3], sys.argv[4])
    elif op == "failure":
        generation = valid_uuid(sys.argv[2], "generation UUID"); stage = sys.argv[3]
        if stage not in {"network","vscode","chat","pi","complete","rollover","architecture"}: fail("invalid failure stage")
        record_failure(generation, stage, sys.argv[4], "ARCHITECTURE_DECISION_REQUIRED" if stage == "architecture" else None)
    elif op == "pid-write": cmd_pid_write(sys.argv[2], sys.argv[3], sys.argv[4])
    elif op == "pid-status": raise SystemExit(cmd_pid_status())
    elif op == "pid-signal": cmd_pid_signal()
    elif op == "pid-match": raise SystemExit(cmd_pid_match(sys.argv[2], sys.argv[3], sys.argv[4], sys.argv[5]))
    elif op == "pid-unlink": cmd_pid_unlink(sys.argv[2])
    elif op == "unlink-marker": STORE.unlink(STORE.fd, "unclean.marker")
    else: fail("unknown operation: " + op)

if __name__ == "__main__": main()
