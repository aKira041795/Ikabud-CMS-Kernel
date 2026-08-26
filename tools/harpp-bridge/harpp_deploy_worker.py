#!/usr/bin/env python3
"""HARPP deploy worker — executes phone-queued FTP/SFTP deploys on the local machine.

The HARPP phone GUI (live host) queues a deploy job (package + profile name).
This worker runs on the operator's machine — where the FTP profiles and the
deploy packages actually live — polls the bridge, CAS-claims queued jobs,
resolves the real profile (~/.config/harpp/deploy.json, 0600) and the package
locally, runs deploy_harpp.py, and reports progress + the receipt back. FTP
credentials never leave this machine; the module sees only profile metadata.

Usage:
    python3 tools/harpp-bridge/harpp_deploy_worker.py --once          # process pending, then exit
    python3 tools/harpp-bridge/harpp_deploy_worker.py --watch 30      # poll every 30s (default 60)
    python3 tools/harpp-bridge/harpp_deploy_worker.py --publish-only  # re-register inventory, then exit
    python3 tools/harpp-bridge/harpp_deploy_worker.py --dry-run       # claim + fail, never execute

Requires HARPP bridge config (harpp config set base_url/bridge_key/tenant_id).
Only profiles marked `"remote_allowed": true` in the local deploy.json are
published to the phone and executed remotely.
"""
import argparse
import datetime as dt
import json
import os
import re
import sys
import time
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
import deploy_harpp  # noqa: E402
import harpp_client  # noqa: E402

REPO_ROOT = Path(__file__).resolve().parent.parent.parent
DEFAULT_PROFILE_CONFIG = Path.home() / ".config" / "harpp" / "deploy.json"
PROGRESS_STEPS = {"uploading", "extracting", "verifying"}
PACKAGE_RE = re.compile(r"^harpp-deploy-[\w.,;()\[\] _-]+\.zip$")
LOG_CAP = 1_000_000


def _log(message, path=None):
    line = f"[{dt.datetime.now(dt.timezone.utc).isoformat(timespec='seconds')}] {message}"
    print(line, flush=True)
    if path:
        try:
            p = Path(path)
            p.parent.mkdir(parents=True, exist_ok=True)
            with p.open("a", encoding="utf-8") as f:
                f.write(line + "\n")
            if p.stat().st_size > LOG_CAP:
                tail = p.read_text(encoding="utf-8").splitlines()[-2000:]
                p.write_text("\n".join(tail) + "\n", encoding="utf-8")
        except Exception as e:  # noqa: BLE001
            print(f"harpp deploy-worker: log append failed: {e}", flush=True)


# ── Bridge operations (via harpp_client.api, unchanged vendored client) ─────

def _pending(config=None):
    return harpp_client.api("GET", "/api/v1/harpp/bridge/deploys/pending?limit=10", None, config=config)


def _claim(deploy_id, config=None):
    return harpp_client.api("POST", f"/api/v1/harpp/bridge/deploys/{int(deploy_id)}/claim", {}, config=config)


def _report(deploy_id, claim_token, body, config=None):
    payload = dict(body)
    payload["claim_token"] = claim_token
    return harpp_client.api("POST", f"/api/v1/harpp/bridge/deploys/{int(deploy_id)}/report", payload, config=config)


# ── Inventory ────────────────────────────────────────────────────────────────

def _list_packages(packages_dir):
    packages = []
    root = Path(packages_dir)
    if root.is_dir():
        for entry in sorted(root.glob("harpp-deploy-*.zip"),
                            key=lambda p: p.stat().st_mtime, reverse=True):
            st = entry.stat()
            packages.append({"name": entry.name, "size": st.st_size,
                             "modified": dt.datetime.fromtimestamp(st.st_mtime, dt.timezone.utc).isoformat()})
    return packages


def _remote_profiles(profile_config):
    """Non-secret summaries of profiles the operator pre-authorized for remote deploys."""
    profiles = []
    try:
        listing = deploy_harpp.list_profiles(profile_config)
    except deploy_harpp.DeployError as e:
        _log(f"inventory: cannot read profiles ({e})")
        return profiles
    for p in listing.get("profiles", []):
        if not p.get("remote_allowed"):
            continue
        entry = {"name": p.get("profile_name")}
        for key in ("host", "user", "port", "transport", "root_path",
                    "extraction_adapter", "allowed_operations", "health_url"):
            if p.get(key) is not None:
                entry[key] = p[key]
        profiles.append(entry)
    return profiles


def publish_inventory(config=None, packages_dir=None, profile_config=None):
    # Apply documented defaults so callers (e.g. harpp_wake.sync_deploys) can call
    # with no arguments; Path(None) would otherwise raise.
    packages_dir = packages_dir or str(REPO_ROOT)
    profile_config = profile_config or os.environ.get("HARPP_DEPLOY_CONFIG") or str(DEFAULT_PROFILE_CONFIG)
    packages = _list_packages(packages_dir)
    profiles = _remote_profiles(profile_config)
    result = harpp_client.api("POST", "/api/v1/harpp/bridge/deploys/inventory",
                              {"packages": packages, "profiles": profiles}, config=config)
    if not result.get("ok"):
        raise RuntimeError(str(result.get("error") or "inventory publish failed"))
    return {"packages": len(packages), "profiles": len(profiles)}


# ── Deploy execution ─────────────────────────────────────────────────────────

def _resolve_package(packages_dir, name):
    if not PACKAGE_RE.match(name):
        raise deploy_harpp.DeployError("package name not allowed")
    root = Path(packages_dir).resolve()
    candidate = (root / name).resolve()
    if candidate.parent != root or not candidate.is_file():
        raise deploy_harpp.DeployError(f"package not found in packages dir: {name}")
    return candidate


def process_pending_deploys(config=None, packages_dir=None, profile_config=None,
                            dry_run=False, log=None):
    """Claim and execute queued deploys; return a summary of outcomes."""
    pending = _pending(config)
    if not pending.get("ok"):
        raise RuntimeError(str(pending.get("error") or "pending poll failed"))
    jobs = pending.get("data", {}).get("deploys", [])
    if not jobs:
        return []
    results = []
    for job in jobs:
        deploy_id = int(job.get("id") or 0)
        package = str(job.get("package_name") or "")
        profile = str(job.get("profile_name") or "")
        if deploy_id <= 0 or not package or not profile:
            continue
        claimed = _claim(deploy_id, config)
        if not claimed.get("ok"):
            continue  # lost the claim race / no longer queued
        token = str(claimed.get("data", {}).get("claim_token") or "")
        try:
            profile_data = deploy_harpp.load_profile(profile_config, profile)
            if not profile_data.get("remote_allowed"):
                raise deploy_harpp.DeployError(
                    f"profile '{profile}' is not marked remote_allowed for phone-triggered deploys")
            artifact = _resolve_package(packages_dir, package)
            if dry_run:
                _report(deploy_id, token, {"status": "failure",
                                           "error": "dry-run worker refused to execute"}, config)
                results.append({"deploy_id": deploy_id, "result": "DRY_RUN_SKIPPED", "package": package, "profile": profile})
                continue

            def progress(step):
                if step in PROGRESS_STEPS:
                    try:
                        _report(deploy_id, token, {"status": "progress", "step": step}, config)
                    except Exception as e:  # noqa: BLE001
                        _log(f"progress {step} not reported: {e}", log)

            _log(f"deploy #{deploy_id}: {package} -> {profile}", log)
            allow_plain = profile_data.get("transport") == "ftp"
            receipt = deploy_harpp.run_deploy(profile_data, artifact, execute=True,
                                              allow_plain_ftp=allow_plain, progress=progress)
            _report(deploy_id, token, {"status": "success", "receipt": receipt}, config)
            _log(f"deploy #{deploy_id}: SUCCEEDED ({receipt.get('receipt_sha256', '?')})", log)
            results.append({"deploy_id": deploy_id, "result": "SUCCEEDED",
                            "package": package, "profile": profile,
                            "receipt_sha256": receipt.get("receipt_sha256")})
        except Exception as e:  # noqa: BLE001
            try:
                _report(deploy_id, token, {"status": "failure", "error": str(e)[:2000]}, config)
            except Exception as report_error:  # noqa: BLE001
                _log(f"deploy #{deploy_id}: could not report failure ({report_error})", log)
            _log(f"deploy #{deploy_id}: FAILED — {e}", log)
            results.append({"deploy_id": deploy_id, "result": "FAILED",
                            "package": package, "profile": profile, "error": str(e)[:300]})
    return results


# ── CLI ──────────────────────────────────────────────────────────────────────

def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--once", action="store_true", help="process pending deploys once, then exit")
    parser.add_argument("--watch", nargs="?", const=60, type=int, default=None,
                        help="poll every N seconds (default 60); implies publishing inventory")
    parser.add_argument("--publish-only", action="store_true", help="re-register inventory and exit")
    parser.add_argument("--dry-run", action="store_true", help="claim + fail pending deploys without executing")
    parser.add_argument("--config", default=None, help="deploy profile JSON (default ~/.config/harpp/deploy.json)")
    parser.add_argument("--packages-dir", default=str(REPO_ROOT), help="folder containing harpp-deploy-*.zip")
    parser.add_argument("--log", default=None, help="append worker activity to this file")
    args = parser.parse_args(argv)

    profile_config = args.config or os.environ.get("HARPP_DEPLOY_CONFIG") or str(DEFAULT_PROFILE_CONFIG)
    packages_dir = args.packages_dir or str(REPO_ROOT)

    def one_pass(publish=True):
        out = []
        if publish:
            try:
                summary = publish_inventory(config=None, packages_dir=packages_dir, profile_config=profile_config)
                _log(f"inventory published: {summary['packages']} package(s), {summary['profiles']} profile(s)", args.log)
            except harpp_client.HarppError as e:
                _log(f"inventory publish failed: {e}", args.log)
                raise
        out.extend(process_pending_deploys(config=None, packages_dir=packages_dir,
                                           profile_config=profile_config, dry_run=args.dry_run, log=args.log))
        return out

    if args.publish_only:
        one_pass(publish=True)
        return 0

    if args.once:
        one_pass(publish=True)
        return 0

    interval = args.watch if args.watch is not None else 60
    _log(f"harpp deploy-worker: watching every {interval}s (packages: {packages_dir})", args.log)
    while True:
        try:
            one_pass(publish=True)
        except harpp_client.HarppError as e:
            _log(f"harpp deploy-worker: {e}", args.log)
            sys.stderr.write(f"harpp deploy-worker: {e}\n")
            raise SystemExit(1)
        except Exception as e:  # noqa: BLE001
            _log(f"harpp deploy-worker: pass failed — {e}", args.log)
        time.sleep(max(5, interval))


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except harpp_client.HarppError as error:
        print(f"harpp deploy-worker: {error}", file=sys.stderr)
        raise SystemExit(1)
