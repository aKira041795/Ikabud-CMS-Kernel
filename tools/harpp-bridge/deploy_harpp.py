#!/usr/bin/env python3
"""Profile-bound HARPP package deployment helper; dry-run unless --execute is set."""

import argparse
import datetime as dt
import ftplib
import getpass
import hashlib
import json
import os
import re
import shlex
import ssl
import stat
import subprocess
import sys
import urllib.error
import urllib.request
import zipfile
from pathlib import Path, PurePosixPath

CONFIG_PATH = Path.home() / ".config" / "harpp" / "deploy.json"
ALLOWED_PREFIXES = ("modules/harpp/", "templates/modules/harpp/")
TERMINAL_OPERATIONS = {"preflight", "backup", "upload", "verify", "extract", "health_check"}
SECRET_KEYS = {"password", "private_key", "HARPP_DEPLOY_PASSWORD", "HARPP_DEPLOY_PRIVATE_KEY"}
# Non-secret profile fields safe to include in a receipt that is sent to (and
# stored by) the module. Receipts must never serialize the whole profile dict —
# unknown secret-like keys (passphrase, tokens, custom fields) would otherwise
# leak into the module DB. Keep in sync with the worker's _remote_profiles().
RECEIPT_PROFILE_FIELDS = (
    "profile_name", "host", "user", "port", "transport", "root_path",
    "extraction_adapter", "allowed_operations", "health_url",
)


class DeployError(RuntimeError):
    pass


def _env(name, default=None):
    value = os.environ.get(name)
    return value if value not in (None, "") else default


def _read_profile_document(path=None):
    """Load and return (profile_path, raw dict) with the 0600 mode check intact."""
    profile_path = Path(path or _env("HARPP_DEPLOY_CONFIG", CONFIG_PATH)).expanduser()
    raw = {}
    if profile_path.exists():
        if stat.S_IMODE(profile_path.stat().st_mode) != 0o600:
            raise DeployError(f"deployment profile must have mode 0600: {profile_path}")
        raw = json.loads(profile_path.read_text(encoding="utf-8"))
    if not isinstance(raw, dict) or not raw:
        raise DeployError(f"deployment profile is empty: {profile_path}")
    return profile_path, raw


def _select_profile(raw, profile_name=None):
    """Resolve one named profile from a single-profile or {'profiles': {...}} document."""
    if isinstance(raw.get("profiles"), dict) and raw["profiles"]:
        profiles = raw["profiles"]
        if profile_name is None:
            if len(profiles) == 1:
                profile_name = next(iter(profiles))
            else:
                raise DeployError("multiple profiles defined; select one with --profile-name")
        if profile_name not in profiles:
            raise DeployError(f"unknown profile: {profile_name}")
        profile = dict(profiles[profile_name])
        profile["profile_name"] = profile_name
        return profile
    profile = dict(raw)
    if profile_name is not None:
        raise DeployError(f"unknown profile: {profile_name}")
    return profile


def load_profile(path=None, profile_name=None):
    _, raw = _read_profile_document(path)
    profile = _select_profile(raw, profile_name)
    overrides = {
        "host": _env("HARPP_DEPLOY_HOST"),
        "user": _env("HARPP_DEPLOY_USER"),
        "port": _env("HARPP_DEPLOY_PORT"),
        "transport": _env("HARPP_DEPLOY_TRANSPORT"),
        "root_path": _env("HARPP_DEPLOY_ROOT_PATH"),
        "private_key": _env("HARPP_DEPLOY_PRIVATE_KEY"),
        "password": _env("HARPP_DEPLOY_PASSWORD"),
        "known_hosts": _env("HARPP_DEPLOY_KNOWN_HOSTS"),
    }
    profile.update({key: value for key, value in overrides.items() if value is not None})
    profile["port"] = int(profile.get("port") or (22 if profile.get("transport", "sftp") == "sftp" else 21))
    validate_profile(profile)
    return profile


def validate_profile(profile):
    required = ("profile_name", "approved_host", "host", "user", "transport", "root_path",
                "extraction_adapter", "allowed_operations", "health_url")
    missing = [key for key in required if not profile.get(key)]
    if missing:
        raise DeployError("deployment profile missing: " + ", ".join(missing))
    if profile["host"] != profile["approved_host"]:
        raise DeployError("host does not match approved_host")
    if profile["transport"] not in ("sftp", "ftps", "ftp"):
        raise DeployError("transport must be sftp, ftps, or ftp")
    if not str(profile["root_path"]).startswith("/") or ".." in PurePosixPath(profile["root_path"]).parts:
        raise DeployError("root_path must be an absolute path without traversal")
    operations = set(profile["allowed_operations"])
    if not operations or not operations.issubset(TERMINAL_OPERATIONS):
        raise DeployError("allowed_operations contains an unsupported operation")
    if profile["transport"] == "sftp" and not profile.get("known_hosts"):
        raise DeployError("sftp requires a pinned known_hosts file")
    if profile["transport"] == "ftps":
        pin = profile.get("tls_pin")
        if pin is not None:
            if not re.fullmatch(r"(?:[0-9a-fA-F]{2}:){31}[0-9a-fA-F]{2}|[0-9a-fA-F]{64}", str(pin)):
                raise DeployError("tls_pin must be a SHA-256 certificate fingerprint (hex, with or without colons)")
        elif profile.get("tls_verify", True) is not True:
            raise DeployError("ftps certificate verification cannot be disabled; set tls_pin to pin a self-signed certificate instead")
    if not str(profile["health_url"]).startswith("https://"):
        raise DeployError("health_url must use https")


PROFILE_SUMMARY_FIELDS = (
    "profile_name", "approved_host", "host", "user", "port", "transport",
    "root_path", "extraction_adapter", "allowed_operations", "health_url",
    "remote_allowed", "tls_pin",
)


def list_profiles(path=None):
    """List non-secret profile summaries from the deploy document for CLI/UI."""
    _, raw = _read_profile_document(path)
    if isinstance(raw.get("profiles"), dict) and raw["profiles"]:
        candidates = [(name, dict(entry)) for name, entry in raw["profiles"].items()]
    else:
        candidates = [(str(raw.get("profile_name") or "default"), dict(raw))]
    profiles, invalid = [], []
    for name, entry in candidates:
        entry["profile_name"] = name
        try:
            validate_profile(entry)
        except DeployError as error:
            invalid.append({"name": name, "error": str(error)})
            continue
        profiles.append({key: entry[key] for key in PROFILE_SUMMARY_FIELDS if entry.get(key) is not None})
    return {"profiles": profiles, "invalid": invalid}


def inspect_artifact(path):
    artifact = Path(path).expanduser().resolve()
    if not artifact.is_file() or artifact.suffix.lower() != ".zip":
        raise DeployError("artifact must be an existing zip file")
    digest = hashlib.sha256()
    with artifact.open("rb") as stream:
        for chunk in iter(lambda: stream.read(1024 * 1024), b""):
            digest.update(chunk)
    members = []
    with zipfile.ZipFile(artifact) as archive:
        for info in archive.infolist():
            name = info.filename.replace("\\", "/")
            parts = PurePosixPath(name).parts
            if name.startswith("/") or ".." in parts or not name.startswith(ALLOWED_PREFIXES):
                raise DeployError(f"archive member is outside the HARPP allowlist: {name}")
            if stat.S_ISLNK(info.external_attr >> 16):
                raise DeployError(f"archive symlink is not allowed: {name}")
            members.append({"name": name, "size": info.file_size})
    if not members:
        raise DeployError("artifact contains no HARPP members")
    return {"path": str(artifact), "name": artifact.name, "size": artifact.stat().st_size,
            "sha256": digest.hexdigest(), "members": members}


def redact(value):
    if isinstance(value, dict):
        return {key: ("***redacted***" if key in SECRET_KEYS else redact(item)) for key, item in value.items()}
    if isinstance(value, list):
        return [redact(item) for item in value]
    return value


def build_receipt(profile, artifact, execute=False):
    stamp = dt.datetime.now(dt.timezone.utc).strftime("%Y%m%dT%H%M%SZ")
    remote_name = f".harpp-deploy-{stamp}-{artifact['sha256'][:12]}.zip"
    root = profile["root_path"].rstrip("/")
    # Only allowlisted non-secret profile fields enter the receipt (see
    # RECEIPT_PROFILE_FIELDS). The original profile may carry secrets (password,
    # private_key, passphrase, tokens) and must never be serialized wholesale.
    profile_summary = {key: profile[key] for key in RECEIPT_PROFILE_FIELDS if key in profile}
    return redact({
        "receipt_version": 1,
        "mode": "execute" if execute else "dry-run",
        "created_at": dt.datetime.now(dt.timezone.utc).isoformat(),
        "profile": profile_summary,
        "artifact": {key: artifact[key] for key in ("path", "name", "size", "sha256")},
        "manifest": {"member_count": len(artifact["members"]),
                     "uncompressed_bytes": sum(item["size"] for item in artifact["members"]),
                     "allowed_prefixes": list(ALLOWED_PREFIXES)},
        "remote_temp": f"{root}/{remote_name}",
        "steps": [op for op in ("preflight", "backup", "upload", "verify", "extract", "health_check")
                  if op in profile["allowed_operations"]],
        "rollback": f"restore the timestamped HARPP backup under {root}/.harpp-deploy-backups/",
        "bridge_note": "Record this receipt SHA-256 with `harpp status` after owner verification; no server deploy action is invoked.",
    })


def _ssh_base(profile, subsystem=None):
    command = [subsystem or "ssh", "-p" if subsystem != "sftp" else "-P", str(profile["port"]),
               "-o", "BatchMode=yes", "-o", "StrictHostKeyChecking=yes",
               "-o", f"UserKnownHostsFile={Path(profile['known_hosts']).expanduser()}"]
    if profile.get("private_key"):
        command.extend(["-i", str(Path(profile["private_key"]).expanduser())])
    command.append(f"{profile['user']}@{profile['host']}")
    return command


def _run(command, input_text=None):
    result = subprocess.run(command, input=input_text, text=True, capture_output=True, check=False)
    if result.returncode:
        raise DeployError((result.stderr or result.stdout or "command failed").strip())
    return result.stdout.strip()


def _ftps_context(profile):
    """TLS context for FTPS. With tls_pin set, hostname + CA-chain verification are
    replaced by exact certificate pinning (the profile records the server cert's
    SHA-256 fingerprint) — the same trust model as SFTP known_hosts. Without a pin,
    full hostname + chain verification stays on."""
    context = ssl.create_default_context()
    if profile.get("tls_pin"):
        context.check_hostname = False
        context.verify_mode = ssl.CERT_NONE
    return context


def _verify_ftps_pin(client, profile):
    pin = profile.get("tls_pin")
    if not pin:
        return
    der = client.sock.getpeercert(binary_form=True)
    if not der:
        raise DeployError("FTPS server presented no TLS certificate")
    actual = hashlib.sha256(der).hexdigest()
    if actual != str(pin).replace(":", "").lower():
        raise DeployError("FTPS certificate fingerprint does not match tls_pin; update tls_pin if the server certificate changed")


def execute_sftp(profile, artifact, receipt, progress=None):
    if profile.get("password"):
        raise DeployError("sftp passwords are not passed on the command line; use an SSH agent or private key")
    root = shlex.quote(profile["root_path"].rstrip("/"))
    remote = receipt["remote_temp"]
    backup = f"{profile['root_path'].rstrip('/')}/.harpp-deploy-backups/{receipt['created_at'].replace(':', '')}"
    stage = f"{profile['root_path'].rstrip('/')}/.harpp-deploy-stage-{artifact['sha256'][:12]}"
    preflight = f"test -d {root} && df -Pk {root} | tail -1"
    if progress: progress("preflight")
    receipt["preflight"] = _run(_ssh_base(profile) + [preflight])
    if progress: progress("uploading")
    batch = f"put {artifact['path']} {remote}.part\nrename {remote}.part {remote}\n"
    _run(_ssh_base(profile, "sftp") + ["-b", "-"], batch)
    if progress: progress("verifying")
    remote_hash = _run(_ssh_base(profile) + [f"sha256sum {shlex.quote(remote)}"]).split()[0]
    if remote_hash != artifact["sha256"]:
        raise DeployError("remote SHA-256 does not match local artifact")
    if profile["extraction_adapter"] == "ssh_unzip":
        if progress: progress("extracting")
        command = (
            f"set -eu; rm -rf {shlex.quote(stage)}; mkdir -p {shlex.quote(stage)} {shlex.quote(backup)}; "
            f"unzip -q {shlex.quote(remote)} -d {shlex.quote(stage)}; "
            f"for p in modules/harpp templates/modules/harpp; do "
            f"if test -e {root}/$p; then mkdir -p {shlex.quote(backup)}/$(dirname $p); mv {root}/$p {shlex.quote(backup)}/$p; fi; done; "
            f"mkdir -p {root}/modules {root}/templates/modules; "
            f"mv {shlex.quote(stage)}/modules/harpp {root}/modules/harpp; "
            f"mv {shlex.quote(stage)}/templates/modules/harpp {root}/templates/modules/harpp; "
            f"rm -rf {shlex.quote(stage)}; rm -f {shlex.quote(remote)}"
        )
        _run(_ssh_base(profile) + [command])
        receipt["backup_path"] = backup
    elif profile["extraction_adapter"] == "manual_cpanel":
        receipt["manual_action"] = f"Extract {remote} in cPanel after verifying SHA-256 {artifact['sha256']}"
    else:
        raise DeployError("unsupported extraction_adapter")


def execute_ftp(profile, artifact, receipt, allow_plain_ftp=False, progress=None):
    if profile["transport"] == "ftp" and not allow_plain_ftp:
        raise DeployError("plain FTP requires --allow-plain-ftp explicit risk opt-in")
    password = profile.get("password")
    if not password:
        raise DeployError("FTPS/FTP execution requires HARPP_DEPLOY_PASSWORD or profile password")
    client_class = ftplib.FTP_TLS if profile["transport"] == "ftps" else ftplib.FTP
    context = _ftps_context(profile) if profile["transport"] == "ftps" else None
    client = client_class(context=context) if context else client_class()
    remote = receipt["remote_temp"]
    try:
        if progress: progress("uploading")
        client.connect(profile["host"], profile["port"], timeout=30)
        client.login(profile["user"], password)
        if isinstance(client, ftplib.FTP_TLS):
            client.prot_p()
        if profile["transport"] == "ftps":
            _verify_ftps_pin(client, profile)
        client.cwd(profile["root_path"])
        with open(artifact["path"], "rb") as stream:
            client.storbinary(f"STOR {PurePosixPath(remote).name}.part", stream)
        client.rename(f"{PurePosixPath(remote).name}.part", PurePosixPath(remote).name)
        receipt["manual_action"] = (
            f"Verify SHA-256 {artifact['sha256']} and extract {remote} with cPanel; "
            "FTPS/FTP cannot perform verified remote extraction"
        )
    finally:
        try:
            client.quit()
        except Exception:
            client.close()


def health_check(url):
    request = urllib.request.Request(url, method="HEAD", headers={"User-Agent": "harpp-deploy/1.0"})
    try:
        with urllib.request.urlopen(request, timeout=20) as response:
            status = int(response.status)
    except urllib.error.HTTPError as error:
        status = int(error.code)
    if status >= 500:
        raise DeployError(f"health check returned HTTP {status}")
    return {"url": url, "status": status}


def write_receipt(receipt):
    directory = Path.home() / ".config" / "harpp" / "deploy-receipts"
    directory.mkdir(parents=True, exist_ok=True, mode=0o700)
    payload = json.dumps(receipt, sort_keys=True, indent=2) + "\n"
    digest = hashlib.sha256(payload.encode()).hexdigest()
    path = directory / f"{receipt['created_at'].replace(':', '')}-{digest[:12]}.json"
    path.write_text(payload, encoding="utf-8")
    path.chmod(0o600)
    return path, digest


def set_password(profile_path=None, profile_name=None):
    """Store an FTP password in the local profile store (0600, atomic write).

    The password is prompted (getpass, no echo), confirmed, and written only into
    the local profile document. It is never printed or sent anywhere; the worker
    and CLI read it back from this 0600 file.
    """
    path, raw = _read_profile_document(profile_path)
    if isinstance(raw.get("profiles"), dict) and raw["profiles"]:
        if profile_name is None:
            if len(raw["profiles"]) == 1:
                profile_name = next(iter(raw["profiles"]))
            else:
                raise DeployError("specify --profile-name to store the password")
        if profile_name not in raw["profiles"]:
            raise DeployError(f"unknown profile: {profile_name}")
        target = raw["profiles"][profile_name]
    else:
        if profile_name is not None:
            raise DeployError(f"unknown profile: {profile_name}")
        target = raw
    prompt = f"FTP password for {target.get('user') or '?'}@{target.get('host') or '?'}: "
    password = getpass.getpass(prompt)
    if password == "":
        raise DeployError("password cannot be empty")
    if getpass.getpass("Confirm password: ") != password:
        raise DeployError("passwords do not match")
    target["password"] = password
    tmp = path.with_name(f".{path.name}.{os.getpid()}.tmp")
    tmp.write_text(json.dumps(raw, indent=4) + "\n", encoding="utf-8")
    tmp.chmod(0o600)
    os.replace(tmp, path)
    return {"profile": profile_name or str(target.get("profile_name") or "default"), "password_set": True}


def run_deploy(profile, artifact_path, execute=False, allow_plain_ftp=False, progress=None):
    """Inspect an artifact, build a profile-bound receipt, and optionally execute.

    Dry-run (default) never connects. Executing a plain-FTP profile still requires
    the explicit allow_plain_ftp risk opt-in. `progress` is an optional callable
    receiving step names (preflight/uploading/verifying/extracting/health_check).
    """
    artifact = inspect_artifact(artifact_path)
    receipt = build_receipt(profile, artifact, execute)
    if execute:
        if profile["transport"] == "sftp":
            execute_sftp(profile, artifact, receipt, progress)
        else:
            execute_ftp(profile, artifact, receipt, allow_plain_ftp, progress)
        if "health_check" in profile["allowed_operations"] and not receipt.get("manual_action"):
            if progress: progress("health_check")
            receipt["health_check"] = health_check(profile["health_url"])
        path, digest = write_receipt(receipt)
        receipt["receipt_path"] = str(path)
        receipt["receipt_sha256"] = digest
    return receipt


def test_connection(profile, allow_plain_ftp=False, password=None):
    """Connect and authenticate only — no files, no mutations.

    Returns a small non-secret result dict. FTPS/FTP uses the profile password,
    HARPP_DEPLOY_PASSWORD, or the caller-supplied password (getpass on the CLI).
    Plain FTP still requires the allow_plain_ftp risk opt-in.
    """
    if profile["transport"] == "sftp":
        command = _ssh_base(profile) + ["-o", "ConnectTimeout=20", "true"]
        try:
            _run(command)
        except DeployError as error:
            raise DeployError(f"SFTP connection failed: {error}")
        return {"transport": "sftp", "host": profile["host"], "port": profile["port"],
                "user": profile["user"], "auth": "ok"}
    if profile["transport"] == "ftp" and not allow_plain_ftp:
        raise DeployError("plain FTP requires --allow-plain-ftp explicit risk opt-in")
    password = password or profile.get("password") or _env("HARPP_DEPLOY_PASSWORD")
    if not password:
        raise DeployError("FTPS/FTP connection test requires a password (HARPP_DEPLOY_PASSWORD, profile, or --interactive)")
    client_class = ftplib.FTP_TLS if profile["transport"] == "ftps" else ftplib.FTP
    context = _ftps_context(profile) if profile["transport"] == "ftps" else None
    client = client_class(context=context) if context else client_class()
    try:
        client.connect(profile["host"], profile["port"], timeout=25)
        client.login(profile["user"], password)
        if isinstance(client, ftplib.FTP_TLS):
            client.prot_p()
        if profile["transport"] == "ftps":
            _verify_ftps_pin(client, profile)
        return {"transport": profile["transport"], "host": profile["host"], "port": profile["port"],
                "user": profile["user"], "auth": "ok", "welcome": str(client.getwelcome() or "")[:120]}
    finally:
        try:
            client.quit()
        except Exception:
            client.close()


PROBE_CONTENT = "HARPP FTP write probe ok\n"


def probe_upload(profile, allow_plain_ftp=False, password=None):
    """Non-destructive write probe: upload a tiny scratch file, confirm it landed,
    then delete it. Confirms write access + the profile root_path without touching
    anything real. FTPS/FTP only (the live target).
    """
    if profile["transport"] == "sftp":
        raise DeployError("probe upload currently supports FTPS/FTP profiles (SFTP: use a real dry-run first)")
    if profile["transport"] == "ftp" and not allow_plain_ftp:
        raise DeployError("plain FTP requires --allow-plain-ftp explicit risk opt-in")
    password = password or profile.get("password") or _env("HARPP_DEPLOY_PASSWORD")
    if not password:
        raise DeployError("FTPS/FTP probe upload requires a password (HARPP_DEPLOY_PASSWORD, profile, or --interactive)")
    client_class = ftplib.FTP_TLS if profile["transport"] == "ftps" else ftplib.FTP
    context = _ftps_context(profile) if profile["transport"] == "ftps" else None
    client = client_class(context=context) if context else client_class()
    name = f".harpp-probe-{dt.datetime.now(dt.timezone.utc).strftime('%Y%m%dT%H%M%SZ')}.txt"
    uploaded = False
    size = None
    deleted = False
    try:
        client.connect(profile["host"], profile["port"], timeout=25)
        client.login(profile["user"], password)
        if isinstance(client, ftplib.FTP_TLS):
            client.prot_p()
        if profile["transport"] == "ftps":
            _verify_ftps_pin(client, profile)
        client.cwd(profile["root_path"])
        from io import BytesIO
        client.storbinary(f"STOR {name}", BytesIO(PROBE_CONTENT.encode()))
        uploaded = True
        try:
            size = client.size(name)
        except Exception:
            size = None
    finally:
        try:
            if uploaded:
                try:
                    client.delete(name)
                    deleted = True
                except Exception:
                    deleted = False
        finally:
            try:
                client.quit()
            except Exception:
                client.close()
    return {"transport": profile["transport"], "host": profile["host"], "port": profile["port"],
            "user": profile["user"], "root_path": profile["root_path"], "probe_file": name,
            "uploaded": uploaded, "uploaded_bytes": len(PROBE_CONTENT.encode()),
            "size_confirmed": size, "deleted": deleted}


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("artifact", nargs="?", help="HARPP deployment zip")
    parser.add_argument("--profile", help="profile path (default ~/.config/harpp/deploy.json)")
    parser.add_argument("--profile-name", help="named profile inside a {'profiles': {...}} deploy document")
    parser.add_argument("--list-profiles", action="store_true", help="list saved profiles (no secrets) and exit")
    parser.add_argument("--set-password", action="store_true",
                        help="prompt to store the FTP password in the local 0600 profile (persists for the worker)")
    parser.add_argument("--test-connection", action="store_true",
                        help="connect + authenticate only (no upload); prompts for the password if none is configured")
    parser.add_argument("--probe-upload", action="store_true",
                        help="non-destructive write probe: upload a scratch file, confirm, then delete it")
    parser.add_argument("--interactive-password", action="store_true", help="prompt for the FTP password (getpass)")
    parser.add_argument("--execute", action="store_true", help="connect and execute approved operations")
    parser.add_argument("--allow-plain-ftp", action="store_true", help="accept unencrypted FTP credential/data risk")
    args = parser.parse_args(argv)
    if args.list_profiles:
        print(json.dumps(list_profiles(args.profile), sort_keys=True, indent=2))
        return 0
    if args.set_password:
        result = set_password(args.profile, args.profile_name)
        print(json.dumps(result, sort_keys=True, indent=2))
        return 0
    profile = load_profile(args.profile, args.profile_name)
    if args.test_connection:
        password = None
        if args.interactive_password or not (profile.get("password") or _env("HARPP_DEPLOY_PASSWORD")):
            import getpass
            password = getpass.getpass(f"FTP password for {profile['user']}@{profile['host']}: ")
        result = test_connection(profile, args.allow_plain_ftp, password=password)
        print(json.dumps(result, sort_keys=True, indent=2))
        return 0
    if args.probe_upload:
        password = None
        if args.interactive_password or not (profile.get("password") or _env("HARPP_DEPLOY_PASSWORD")):
            import getpass
            password = getpass.getpass(f"FTP password for {profile['user']}@{profile['host']}: ")
        result = probe_upload(profile, args.allow_plain_ftp, password=password)
        print(json.dumps(result, sort_keys=True, indent=2))
        return 0
    if not args.artifact:
        parser.error("artifact is required (or use --list-profiles)")
    if args.execute and (args.interactive_password or not (profile.get("password") or _env("HARPP_DEPLOY_PASSWORD"))):
        import getpass
        profile["password"] = getpass.getpass(f"FTP password for {profile['user']}@{profile['host']}: ")
    receipt = run_deploy(profile, args.artifact, execute=args.execute, allow_plain_ftp=args.allow_plain_ftp)
    print(json.dumps(receipt, sort_keys=True, indent=2))
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except (DeployError, OSError, ValueError, zipfile.BadZipFile) as error:
        print(f"deploy_harpp: {error}", file=sys.stderr)
        raise SystemExit(2)