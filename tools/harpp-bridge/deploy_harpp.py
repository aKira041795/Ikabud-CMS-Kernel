#!/usr/bin/env python3
"""Profile-bound HARPP package deployment helper; dry-run unless --execute is set."""

import argparse
import datetime as dt
import ftplib
import hashlib
import json
import os
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


class DeployError(RuntimeError):
    pass


def _env(name, default=None):
    value = os.environ.get(name)
    return value if value not in (None, "") else default


def load_profile(path=None):
    profile_path = Path(path or _env("HARPP_DEPLOY_CONFIG", CONFIG_PATH)).expanduser()
    profile = {}
    if profile_path.exists():
        if stat.S_IMODE(profile_path.stat().st_mode) != 0o600:
            raise DeployError(f"deployment profile must have mode 0600: {profile_path}")
        profile = json.loads(profile_path.read_text(encoding="utf-8"))
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
    if profile["transport"] == "ftps" and profile.get("tls_verify", True) is not True:
        raise DeployError("ftps certificate verification cannot be disabled")
    if not str(profile["health_url"]).startswith("https://"):
        raise DeployError("health_url must use https")


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
    return redact({
        "receipt_version": 1,
        "mode": "execute" if execute else "dry-run",
        "created_at": dt.datetime.now(dt.timezone.utc).isoformat(),
        "profile": profile,
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


def execute_sftp(profile, artifact, receipt):
    if profile.get("password"):
        raise DeployError("sftp passwords are not passed on the command line; use an SSH agent or private key")
    root = shlex.quote(profile["root_path"].rstrip("/"))
    remote = receipt["remote_temp"]
    backup = f"{profile['root_path'].rstrip('/')}/.harpp-deploy-backups/{receipt['created_at'].replace(':', '')}"
    stage = f"{profile['root_path'].rstrip('/')}/.harpp-deploy-stage-{artifact['sha256'][:12]}"
    preflight = f"test -d {root} && df -Pk {root} | tail -1"
    receipt["preflight"] = _run(_ssh_base(profile) + [preflight])
    batch = f"put {artifact['path']} {remote}.part\nrename {remote}.part {remote}\n"
    _run(_ssh_base(profile, "sftp") + ["-b", "-"], batch)
    remote_hash = _run(_ssh_base(profile) + [f"sha256sum {shlex.quote(remote)}"]).split()[0]
    if remote_hash != artifact["sha256"]:
        raise DeployError("remote SHA-256 does not match local artifact")
    if profile["extraction_adapter"] == "ssh_unzip":
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


def execute_ftp(profile, artifact, receipt, allow_plain_ftp=False):
    if profile["transport"] == "ftp" and not allow_plain_ftp:
        raise DeployError("plain FTP requires --allow-plain-ftp explicit risk opt-in")
    password = profile.get("password")
    if not password:
        raise DeployError("FTPS/FTP execution requires HARPP_DEPLOY_PASSWORD or profile password")
    client_class = ftplib.FTP_TLS if profile["transport"] == "ftps" else ftplib.FTP
    context = ssl.create_default_context() if profile["transport"] == "ftps" else None
    client = client_class(context=context) if context else client_class()
    remote = receipt["remote_temp"]
    try:
        client.connect(profile["host"], profile["port"], timeout=30)
        client.login(profile["user"], password)
        if isinstance(client, ftplib.FTP_TLS):
            client.prot_p()
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


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("artifact", help="HARPP deployment zip")
    parser.add_argument("--profile", help="profile path (default ~/.config/harpp/deploy.json)")
    parser.add_argument("--execute", action="store_true", help="connect and execute approved operations")
    parser.add_argument("--allow-plain-ftp", action="store_true", help="accept unencrypted FTP credential/data risk")
    args = parser.parse_args(argv)
    profile = load_profile(args.profile)
    artifact = inspect_artifact(args.artifact)
    receipt = build_receipt(profile, artifact, args.execute)
    if args.execute:
        if profile["transport"] == "sftp":
            execute_sftp(profile, artifact, receipt)
        else:
            execute_ftp(profile, artifact, receipt, args.allow_plain_ftp)
        if "health_check" in profile["allowed_operations"] and not receipt.get("manual_action"):
            receipt["health_check"] = health_check(profile["health_url"])
        path, digest = write_receipt(receipt)
        receipt["receipt_path"] = str(path)
        receipt["receipt_sha256"] = digest
    print(json.dumps(receipt, sort_keys=True, indent=2))
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except (DeployError, OSError, ValueError, zipfile.BadZipFile) as error:
        print(f"deploy_harpp: {error}", file=sys.stderr)
        raise SystemExit(2)