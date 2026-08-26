#!/usr/bin/env python3
import json
import os
import re
import stat
import sys
import tempfile
import unittest
import zipfile
from pathlib import Path
from unittest import mock

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

import deploy_harpp  # noqa: E402
import harpp_deploy_worker  # noqa: E402


def _profile(name, **overrides):
    base = {
        "approved_host": "host.example", "host": "host.example", "user": "deploy",
        "port": 22, "transport": "sftp", "root_path": "/app",
        "private_key": "/secret/key", "password": "never-print-me",
        "known_hosts": "/known_hosts", "extraction_adapter": "ssh_unzip",
        "allowed_operations": ["preflight", "backup", "upload", "verify", "extract", "health_check"],
        "health_url": "https://host.example/health",
    }
    base.update(overrides)
    return base


class DeployWorkerTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.root = Path(self.tmp.name)
        self.packages = self.root / "packages"
        self.packages.mkdir()
        self.archive = self.packages / "harpp-deploy-20260827-010000.zip"
        with zipfile.ZipFile(self.archive, "w") as bundle:
            bundle.writestr("modules/harpp/module.json", "{}")
            bundle.writestr("templates/modules/harpp/login.disyl", "ok")
        self.config = self.root / "deploy.json"
        self.config.write_text(json.dumps({"profiles": {
            "prod": _profile("prod", remote_allowed=True),
            "staging": _profile("staging", remote_allowed=False),
        }}))
        os.chmod(self.config, 0o600)

    def tearDown(self):
        self.tmp.cleanup()

    def test_remote_profiles_only_remote_allowed_and_no_secrets(self):
        profiles = harpp_deploy_worker._remote_profiles(self.config)
        names = [p["name"] for p in profiles]
        self.assertEqual(names, ["prod"])
        rendered = json.dumps(profiles)
        self.assertNotIn("never-print-me", rendered)
        self.assertNotIn("/secret/key", rendered)

    def test_resolve_package_guards_traversal(self):
        ok = harpp_deploy_worker._resolve_package(self.packages, self.archive.name)
        self.assertEqual(ok, self.archive.resolve())
        for bad in ("../outside.zip", "/etc/passwd", "nope.zip"):
            with self.assertRaises(deploy_harpp.DeployError):
                harpp_deploy_worker._resolve_package(self.packages, bad)

    def test_list_packages(self):
        pkgs = harpp_deploy_worker._list_packages(self.packages)
        self.assertEqual([p["name"] for p in pkgs], [self.archive.name])
        self.assertGreater(pkgs[0]["size"], 0)

    def test_publish_inventory_posts_no_secrets(self):
        sent = {}
        def fake_api(method, path, body=None, config=None):
            sent.update({"method": method, "path": path, "body": body})
            return {"ok": True, "data": {"packages": len(body["packages"]), "profiles": len(body["profiles"])}}
        with mock.patch.object(harpp_deploy_worker.harpp_client, "api", side_effect=fake_api), \
             mock.patch.object(harpp_deploy_worker, "_inventory_sig_path", return_value=self.root / "sig1"):
            summary = harpp_deploy_worker.publish_inventory(packages_dir=self.packages, profile_config=self.config)
        self.assertEqual(summary["packages"], 1)
        self.assertEqual(summary["profiles"], 1)
        self.assertTrue(summary["published"])
        self.assertNotIn("password", json.dumps(sent))
        self.assertEqual(sent["path"], "/api/v1/harpp/bridge/deploys/inventory")

    def test_publish_inventory_skips_when_unchanged(self):
        sent = []
        def fake_api(method, path, body=None, config=None):
            sent.append(path)
            return {"ok": True, "data": {"packages": 0, "profiles": 0}}
        with mock.patch.object(harpp_deploy_worker.harpp_client, "api", side_effect=fake_api), \
             mock.patch.object(harpp_deploy_worker, "_inventory_sig_path", return_value=self.root / "sig2"):
            first = harpp_deploy_worker.publish_inventory(packages_dir=self.packages, profile_config=self.config)
            second = harpp_deploy_worker.publish_inventory(packages_dir=self.packages, profile_config=self.config)
        self.assertTrue(first["published"])
        self.assertFalse(second["published"])
        self.assertEqual(len(sent), 1)  # unchanged inventory -> no second network POST

    def test_publish_inventory_no_args_uses_defaults(self):
        # The watch (harpp_wake.sync_deploys) calls publish_inventory() with no args;
        # defaults must be applied (Path(None) used to raise every watch pass).
        with mock.patch.object(harpp_deploy_worker.harpp_client, "api",
                               return_value={"ok": True, "data": {"packages": 0, "profiles": 0}}), \
             mock.patch.object(harpp_deploy_worker, "_inventory_sig_path", return_value=self.root / "sig3"):
            summary = harpp_deploy_worker.publish_inventory()
        self.assertIn("packages", summary)
        self.assertIn("profiles", summary)

    def test_worker_full_flow_claims_executes_reports(self):
        calls = []
        def fake_api(method, path, body=None, config=None):
            calls.append((method, path, body))
            if "deploys/pending" in path:
                return {"ok": True, "data": {"deploys": [{"id": 7, "package_name": self.archive.name, "profile_name": "prod"}]}}
            if path.endswith("/deploys/7/claim"):
                return {"ok": True, "data": {"deploy_id": 7, "claim_token": "tok-123"}}
            if path.endswith("/deploys/7/report"):
                if body.get("status") == "progress":
                    return {"ok": True, "data": {"deploy_id": 7, "status": "UPLOADING"}}
                return {"ok": True, "data": {"deploy_id": 7, "status": "SUCCEEDED"}}
            raise AssertionError("unexpected " + path)
        fake_receipt = {"receipt_version": 1, "mode": "execute", "receipt_sha256": "abc123"}
        def fake_run(profile, artifact_path, execute=False, allow_plain_ftp=False, progress=None):
            if progress:
                progress("uploading")
                progress("extracting")
                progress("verifying")
            return fake_receipt
        with mock.patch.object(harpp_deploy_worker.harpp_client, "api", side_effect=fake_api), \
             mock.patch.object(deploy_harpp, "run_deploy", side_effect=fake_run) as rd:
            results = harpp_deploy_worker.process_pending_deploys(packages_dir=self.packages, profile_config=self.config)
        self.assertEqual(results[0]["result"], "SUCCEEDED")
        self.assertEqual(rd.call_args[1]["execute"], True)
        # order: pending, claim, progress(uploading), progress(extracting), progress(verifying), success
        reports = [c for c in calls if c[1].endswith("/deploys/7/report")]
        self.assertEqual([r[2]["status"] for r in reports], ["progress", "progress", "progress", "success"])
        self.assertEqual([r[2]["step"] for r in reports[:3]], ["uploading", "extracting", "verifying"])
        self.assertEqual(reports[-1][2]["receipt"]["receipt_sha256"], "abc123")

    def test_worker_fails_when_profile_not_remote_allowed(self):
        calls = []
        def fake_api(method, path, body=None, config=None):
            calls.append((path, body))
            if "deploys/pending" in path:
                return {"ok": True, "data": {"deploys": [{"id": 8, "package_name": self.archive.name, "profile_name": "staging"}]}}
            if path.endswith("/deploys/8/claim"):
                return {"ok": True, "data": {"deploy_id": 8, "claim_token": "tok-8"}}
            if path.endswith("/deploys/8/report"):
                return {"ok": True}
            raise AssertionError("unexpected " + path)
        with mock.patch.object(harpp_deploy_worker.harpp_client, "api", side_effect=fake_api):
            results = harpp_deploy_worker.process_pending_deploys(packages_dir=self.packages, profile_config=self.config)
        self.assertEqual(results[0]["result"], "FAILED")
        report = [c for c in calls if c[0].endswith("/deploys/8/report")][0][1]
        self.assertEqual(report["status"], "failure")
        self.assertIn("remote_allowed", report["error"])

    def test_worker_plain_ftp_fails_closed_without_remote_opt_in(self):
        # A remote_allowed plain-FTP profile without the explicit per-profile
        # remote_allow_plain_ftp opt-in must fail closed (never silently use
        # plaintext FTP for a phone-triggered deploy).
        config = self.root / "deploy-ftp.json"
        config.write_text(json.dumps({"profiles": {
            "ftp": _profile("ftp", transport="ftp", port=21,
                            extraction_adapter="manual_cpanel", remote_allowed=True),
        }}))
        os.chmod(config, 0o600)
        calls = []
        def fake_api(method, path, body=None, config=None):
            calls.append((path, body))
            if "deploys/pending" in path:
                return {"ok": True, "data": {"deploys": [{"id": 21, "package_name": self.archive.name, "profile_name": "ftp"}]}}
            if path.endswith("/deploys/21/claim"):
                return {"ok": True, "data": {"deploy_id": 21, "claim_token": "tok-21"}}
            if path.endswith("/deploys/21/report"):
                return {"ok": True}
            raise AssertionError("unexpected " + path)
        with mock.patch.object(harpp_deploy_worker.harpp_client, "api", side_effect=fake_api):
            results = harpp_deploy_worker.process_pending_deploys(packages_dir=self.packages, profile_config=config)
        self.assertEqual(results[0]["result"], "FAILED")
        report = [c for c in calls if c[0].endswith("/deploys/21/report")][0][1]
        self.assertEqual(report["status"], "failure")
        self.assertIn("risk opt-in", report["error"])

    def test_worker_plain_ftp_honors_explicit_remote_opt_in(self):
        # With the explicit remote_allow_plain_ftp opt-in, the worker passes
        # allow_plain_ftp=True through to run_deploy.
        config = self.root / "deploy-ftp-optin.json"
        config.write_text(json.dumps({"profiles": {
            "ftp": _profile("ftp", transport="ftp", port=21,
                            extraction_adapter="manual_cpanel", remote_allowed=True,
                            remote_allow_plain_ftp=True),
        }}))
        os.chmod(config, 0o600)
        calls = []
        def fake_api(method, path, body=None, config=None):
            calls.append((method, path, body))
            if "deploys/pending" in path:
                return {"ok": True, "data": {"deploys": [{"id": 22, "package_name": self.archive.name, "profile_name": "ftp"}]}}
            if path.endswith("/deploys/22/claim"):
                return {"ok": True, "data": {"deploy_id": 22, "claim_token": "tok-22"}}
            if path.endswith("/deploys/22/report"):
                return {"ok": True, "data": {"deploy_id": 22, "status": "SUCCEEDED"}}
            raise AssertionError("unexpected " + path)
        seen = {}
        def fake_run(profile, artifact_path, execute=False, allow_plain_ftp=False, progress=None):
            seen["allow_plain_ftp"] = allow_plain_ftp
            return {"receipt_version": 1, "mode": "execute", "receipt_sha256": "xyz"}
        with mock.patch.object(harpp_deploy_worker.harpp_client, "api", side_effect=fake_api), \
             mock.patch.object(deploy_harpp, "run_deploy", side_effect=fake_run):
            results = harpp_deploy_worker.process_pending_deploys(packages_dir=self.packages, profile_config=config)
        self.assertEqual(results[0]["result"], "SUCCEEDED")
        self.assertTrue(seen["allow_plain_ftp"])

    def test_run_deploy_dry_run_does_not_call_progress(self):
        profile = _profile("prod", transport="ftp", port=21, extraction_adapter="manual_cpanel")
        progress = mock.Mock()
        receipt = deploy_harpp.run_deploy(profile, self.archive, execute=False, progress=progress)
        self.assertEqual(receipt["mode"], "dry-run")
        progress.assert_not_called()

    def test_sync_deploys_spawns_worker_when_pending(self):
        import harpp_wake
        calls = []
        def fake_api(method, path, body=None, config=None):
            calls.append((method, path))
            if "deploys/pending" in path:
                return {"ok": True, "data": {"deploys": [{"id": 1, "package_name": "p.zip", "profile_name": "prod"}]}}
            return {"ok": True, "data": {"packages": 0, "profiles": 0}}
        with mock.patch.object(harpp_wake.harpp_client, "api", side_effect=fake_api), \
             mock.patch.object(harpp_wake.subprocess, "Popen", return_value=mock.Mock()) as popen, \
             mock.patch.object(harpp_deploy_worker, "_inventory_sig_path", return_value=self.root / "sig4"):
            n = harpp_wake.sync_deploys()
        self.assertEqual(n, 1)
        popen.assert_called_once()
        cmd = popen.call_args[0][0]
        self.assertTrue(any(arg == "--once" for arg in cmd))
        self.assertTrue(any(arg.endswith("harpp_deploy_worker.py") for arg in cmd))

    def test_sync_deploys_idle_does_not_spawn(self):
        import harpp_wake
        def fake_api(method, path, body=None, config=None):
            if "deploys/pending" in path:
                return {"ok": True, "data": {"deploys": []}}
            return {"ok": True, "data": {"packages": 0, "profiles": 0}}
        with mock.patch.object(harpp_wake.harpp_client, "api", side_effect=fake_api), \
             mock.patch.object(harpp_wake.subprocess, "Popen") as popen, \
             mock.patch.object(harpp_deploy_worker, "_inventory_sig_path", return_value=self.root / "sig5"):
            n = harpp_wake.sync_deploys()
        self.assertEqual(n, 0)
        popen.assert_not_called()


if __name__ == "__main__":
    unittest.main()
