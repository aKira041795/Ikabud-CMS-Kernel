#!/usr/bin/env python3
import json
import os
import hashlib
import re
import ssl
import stat
import sys
import tempfile
import unittest
import zipfile
from pathlib import Path
from unittest import mock

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

import deploy_harpp  # noqa: E402


class DeployHarppTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.root = Path(self.tmp.name)
        self.archive = self.root / "harpp.zip"
        with zipfile.ZipFile(self.archive, "w") as bundle:
            bundle.writestr("modules/harpp/module.json", "{}")
            bundle.writestr("templates/modules/harpp/login.disyl", "ok")
        self.profile = {
            "profile_name": "test", "approved_host": "host.example", "host": "host.example",
            "user": "deploy", "port": 22, "transport": "sftp", "root_path": "/app",
            "private_key": "/secret/key", "password": "never-print-me",
            "known_hosts": "/known_hosts", "extraction_adapter": "ssh_unzip",
            "allowed_operations": ["preflight", "backup", "upload", "verify", "extract", "health_check"],
            "health_url": "https://host.example/health",
        }

    def tearDown(self):
        self.tmp.cleanup()

    def test_dry_run_receipt_has_manifest_and_redacts_secrets(self):
        artifact = deploy_harpp.inspect_artifact(self.archive)
        receipt = deploy_harpp.build_receipt(self.profile, artifact)
        rendered = json.dumps(receipt)
        self.assertEqual(receipt["mode"], "dry-run")
        self.assertEqual(receipt["manifest"]["member_count"], 2)
        # The receipt profile is a strict non-secret allowlist: secret keys are
        # absent entirely (never serialized), not merely redacted in place.
        self.assertNotIn("password", receipt["profile"])
        self.assertNotIn("private_key", receipt["profile"])
        self.assertEqual(receipt["profile"]["host"], "host.example")
        self.assertEqual(receipt["profile"]["transport"], "sftp")
        self.assertNotIn("never-print-me", rendered)

    def test_receipt_never_serializes_unknown_secret_like_profile_keys(self):
        profile = dict(self.profile, passphrase="secret-passphrase", api_token="tok-secret",
                       custom_credential="custom-secret")
        artifact = deploy_harpp.inspect_artifact(self.archive)
        receipt = deploy_harpp.build_receipt(profile, artifact)
        rendered = json.dumps(receipt)
        for secret in ("secret-passphrase", "tok-secret", "custom-secret"):
            self.assertNotIn(secret, rendered)
        self.assertNotIn("passphrase", receipt["profile"])
        self.assertNotIn("api_token", receipt["profile"])

    def test_profile_requires_0600_and_approved_host(self):
        path = self.root / "deploy.json"
        path.write_text(json.dumps(self.profile))
        path.chmod(0o644)
        with self.assertRaisesRegex(deploy_harpp.DeployError, "0600"):
            deploy_harpp.load_profile(path)
        path.chmod(0o600)
        data = json.loads(path.read_text())
        data["host"] = "wrong.example"
        path.write_text(json.dumps(data))
        path.chmod(0o600)
        with self.assertRaisesRegex(deploy_harpp.DeployError, "approved_host"):
            deploy_harpp.load_profile(path)

    def test_rejects_archive_traversal(self):
        bad = self.root / "bad.zip"
        with zipfile.ZipFile(bad, "w") as bundle:
            bundle.writestr("../outside.php", "bad")
        with self.assertRaisesRegex(deploy_harpp.DeployError, "allowlist"):
            deploy_harpp.inspect_artifact(bad)

    def test_plain_ftp_requires_explicit_opt_in(self):
        profile = dict(self.profile, transport="ftp", port=21, extraction_adapter="manual_cpanel")
        artifact = deploy_harpp.inspect_artifact(self.archive)
        receipt = deploy_harpp.build_receipt(profile, artifact, execute=True)
        with self.assertRaisesRegex(deploy_harpp.DeployError, "risk opt-in"):
            deploy_harpp.execute_ftp(profile, artifact, receipt)

    def test_single_profile_still_loads(self):
        path = self.root / "deploy.json"
        path.write_text(json.dumps(self.profile))
        path.chmod(0o600)
        profile = deploy_harpp.load_profile(path)
        self.assertEqual(profile["profile_name"], "test")

    def test_multi_profile_load_select(self):
        profiles = {
            "prod": dict(self.profile, profile_name="prod"),
            "staging": dict(self.profile, profile_name="staging",
                            host="stage.example", approved_host="stage.example"),
        }
        path = self.root / "deploy.json"
        path.write_text(json.dumps({"profiles": profiles}))
        path.chmod(0o600)
        profile = deploy_harpp.load_profile(path, "staging")
        self.assertEqual(profile["profile_name"], "staging")
        self.assertEqual(profile["host"], "stage.example")
        with self.assertRaisesRegex(deploy_harpp.DeployError, "unknown profile"):
            deploy_harpp.load_profile(path, "nope")
        with self.assertRaisesRegex(deploy_harpp.DeployError, "profile-name"):
            deploy_harpp.load_profile(path)

    def test_list_profiles_redacts_secrets(self):
        path = self.root / "deploy.json"
        path.write_text(json.dumps({"profiles": {
            "prod": dict(self.profile, profile_name="prod"),
            "staging": dict(self.profile, profile_name="staging"),
        }}))
        path.chmod(0o600)
        listing = deploy_harpp.list_profiles(path)
        names = [p["profile_name"] for p in listing["profiles"]]
        self.assertIn("prod", names)
        self.assertIn("staging", names)
        rendered = json.dumps(listing)
        self.assertNotIn("never-print-me", rendered)   # password
        self.assertNotIn("/secret/key", rendered)      # private_key path
        self.assertNotIn("password", rendered)         # secret key names are never echoed

    def test_run_deploy_dry_run(self):
        profile = dict(self.profile, transport="ftp", port=21, extraction_adapter="manual_cpanel")
        receipt = deploy_harpp.run_deploy(profile, self.archive, execute=False)
        self.assertEqual(receipt["mode"], "dry-run")
        self.assertEqual(receipt["manifest"]["member_count"], 2)

    def test_run_deploy_plain_ftp_requires_opt_in(self):
        profile = dict(self.profile, transport="ftp", port=21, extraction_adapter="manual_cpanel")
        with self.assertRaisesRegex(deploy_harpp.DeployError, "risk opt-in"):
            deploy_harpp.run_deploy(profile, self.archive, execute=True)

    def test_ui_deploy_dry_run(self):
        import harpp_deploy_ui
        packages = self.root / "packages"
        packages.mkdir()
        pkg_name = "harpp-deploy-20260826-120000.zip"
        (packages / pkg_name).write_bytes(self.archive.read_bytes())
        config = self.root / "deploy.json"
        config.write_text(json.dumps({"profiles": {"ftp-test": dict(
            self.profile, profile_name="ftp-test", transport="ftp", port=21,
            extraction_adapter="manual_cpanel")}}))
        config.chmod(0o600)
        listing = harpp_deploy_ui.list_packages(packages)
        self.assertEqual([p["name"] for p in listing], [pkg_name])
        receipt = harpp_deploy_ui.deploy(packages, config, pkg_name, "ftp-test", execute=False)
        self.assertEqual(receipt["mode"], "dry-run")
        with self.assertRaisesRegex(harpp_deploy_ui.DeployUIError, "confirmation"):
            harpp_deploy_ui.deploy(packages, config, pkg_name, "ftp-test",
                                   execute=True, confirm=False)

    def test_ui_page_script_is_valid_javascript(self):
        import shutil
        import subprocess
        import harpp_deploy_ui
        if not shutil.which("node"):
            self.skipTest("node not available")
        match = re.search(r"<script>([\s\S]*?)</script>", harpp_deploy_ui.PAGE_HTML)
        self.assertIsNotNone(match, "PAGE_HTML must contain an inline <script>")
        script = match.group(1)
        tmp = self.root / "page-script.js"
        tmp.write_text(script, encoding="utf-8")
        result = subprocess.run(["node", "--check", str(tmp)], capture_output=True, text=True)
        self.assertEqual(result.returncode, 0, "inline page script must parse: " + result.stderr)

    def test_connection_test_plain_ftp_requires_opt_in(self):
        profile = dict(self.profile, transport="ftp", port=21, extraction_adapter="manual_cpanel", password="pw")
        with self.assertRaisesRegex(deploy_harpp.DeployError, "risk opt-in"):
            deploy_harpp.test_connection(profile)
        with self.assertRaisesRegex(deploy_harpp.DeployError, "risk opt-in"):
            deploy_harpp.test_connection(profile, allow_plain_ftp=False)

    def test_connection_test_ftps_requires_password(self):
        profile = dict(self.profile, transport="ftps", port=21, extraction_adapter="manual_cpanel")
        profile.pop("password", None)
        with self.assertRaisesRegex(deploy_harpp.DeployError, "requires a password"):
            deploy_harpp.test_connection(profile, allow_plain_ftp=False)

    def test_connection_test_result_is_secret_free(self):
        profile = dict(self.profile, transport="ftps", port=21, extraction_adapter="manual_cpanel", password="pw")
        class FakeFTP:
            def __init__(self, *a, **k): pass
            def connect(self, *a, **k): pass
            def login(self, *a, **k): pass
            def prot_p(self): pass
            def getwelcome(self): return "220 Fake welcome"
            def quit(self): pass
            def close(self): pass
        with mock.patch("deploy_harpp.ftplib.FTP_TLS", FakeFTP):
            result = deploy_harpp.test_connection(profile, allow_plain_ftp=False, password="pw")
        self.assertEqual(result["auth"], "ok")
        rendered = json.dumps(result)
        self.assertNotIn("pw", rendered)
        self.assertNotIn("password", rendered)

    def test_ftps_pin_validation(self):
        good = dict(self.profile, transport="ftps", port=21, extraction_adapter="manual_cpanel", password="pw",
                    tls_pin="26:62:c9:9f:7c:e1:5a:4c:ca:16:6b:12:c8:33:75:07:58:10:4c:1e:56:c4:97:20:c1:07:ed:85:a2:e7:f2:74")
        deploy_harpp.validate_profile(good)  # pinned ftps is accepted
        bad = dict(good, tls_pin="not-a-fingerprint")
        with self.assertRaisesRegex(deploy_harpp.DeployError, "tls_pin"):
            deploy_harpp.validate_profile(bad)
        unpinned_unverified = dict(self.profile, transport="ftps", port=21, extraction_adapter="manual_cpanel", tls_verify=False)
        unpinned_unverified.pop("tls_pin", None)
        with self.assertRaisesRegex(deploy_harpp.DeployError, "tls_pin"):
            deploy_harpp.validate_profile(unpinned_unverified)

    def test_ftps_pin_verify_enforced(self):
        der = b"fake-certificate-der-bytes-for-pinning"
        expected = hashlib.sha256(der).hexdigest()
        pin = ":".join(expected[i:i + 2] for i in range(0, len(expected), 2))
        profile = dict(self.profile, transport="ftps", port=21, extraction_adapter="manual_cpanel", password="pw", tls_pin=pin)
        class FakeSock:
            def getpeercert(self, binary_form=True): return der
        class FakeFTP:
            sock = FakeSock()
            def __init__(self, *a, **k): pass
            def connect(self, *a, **k): pass
            def login(self, *a, **k): pass
            def prot_p(self): pass
            def getwelcome(self): return "220 Fake welcome"
            def quit(self): pass
            def close(self): pass
        with mock.patch("deploy_harpp.ftplib.FTP_TLS", FakeFTP):
            result = deploy_harpp.test_connection(profile, allow_plain_ftp=False, password="pw")
        self.assertEqual(result["auth"], "ok")
        wrong = dict(profile, tls_pin="00" * 64)
        with mock.patch("deploy_harpp.ftplib.FTP_TLS", FakeFTP):
            with self.assertRaisesRegex(deploy_harpp.DeployError, "does not match tls_pin"):
                deploy_harpp.test_connection(wrong, allow_plain_ftp=False, password="pw")

    def test_ftps_pinned_context_disables_hostname_check(self):
        profile = dict(self.profile, transport="ftps", port=21, extraction_adapter="manual_cpanel",
                       tls_pin="26:62:c9:9f:7c:e1:5a:4c:ca:16:6b:12:c8:33:75:07:58:10:4c:1e:56:c4:97:20:c1:07:ed:85:a2:e7:f2:74")
        ctx = deploy_harpp._ftps_context(profile)
        self.assertFalse(ctx.check_hostname)
        self.assertEqual(ctx.verify_mode, ssl.CERT_NONE)
        unpinned = dict(profile)
        unpinned.pop("tls_pin", None)
        ctx2 = deploy_harpp._ftps_context(unpinned)
        self.assertTrue(ctx2.check_hostname)

    def test_probe_upload_uploads_confirms_and_deletes(self):
        der = b"probe-cert-der"
        expected = hashlib.sha256(der).hexdigest()
        pin = ":".join(expected[i:i + 2] for i in range(0, len(expected), 2))
        profile = dict(self.profile, transport="ftps", port=21, extraction_adapter="manual_cpanel",
                       password="pw", tls_pin=pin, root_path="/app")
        calls = []
        class FakeSock:
            def getpeercert(self, binary_form=True): return der
        class FakeFTP:
            sock = FakeSock()
            def __init__(self, *a, **k): pass
            def connect(self, *a, **k): pass
            def login(self, *a, **k): pass
            def prot_p(self): pass
            def cwd(self, path): calls.append(("cwd", path))
            def storbinary(self, cmd, fp): calls.append(("stor", cmd))
            def size(self, name): calls.append(("size", name)); return 24
            def delete(self, name): calls.append(("delete", name))
            def quit(self): pass
            def close(self): pass
        with mock.patch("deploy_harpp.ftplib.FTP_TLS", FakeFTP):
            result = deploy_harpp.probe_upload(profile, allow_plain_ftp=False, password="pw")
        self.assertTrue(result["uploaded"])
        self.assertTrue(result["deleted"])
        self.assertEqual(result["size_confirmed"], 24)
        self.assertEqual(result["root_path"], "/app")
        commands = [c for c in calls if c[0] in ("stor", "delete")]
        self.assertEqual(commands[0][0], "stor")
        self.assertEqual(commands[1][0], "delete")
        self.assertEqual(commands[0][1].split(" ", 1)[1], commands[1][1])  # same scratch file deleted
        self.assertTrue(commands[1][1].startswith(".harpp-probe-"))
        rendered = json.dumps(result)
        self.assertNotIn("pw", rendered)

    def test_probe_upload_plain_ftp_requires_opt_in(self):
        profile = dict(self.profile, transport="ftp", port=21, extraction_adapter="manual_cpanel", password="pw")
        with self.assertRaisesRegex(deploy_harpp.DeployError, "risk opt-in"):
            deploy_harpp.probe_upload(profile)

    def test_probe_upload_sftp_rejected(self):
        profile = dict(self.profile, transport="sftp", port=22, extraction_adapter="ssh_unzip")
        with self.assertRaisesRegex(deploy_harpp.DeployError, "SFTP"):
            deploy_harpp.probe_upload(profile)

    def test_set_password_stores_0600(self):
        config = self.root / "deploy.json"
        config.write_text(json.dumps({"profiles": {"prod": {"user": "deploy", "host": "host.example"}}}))
        config.chmod(0o600)
        with mock.patch("deploy_harpp.getpass.getpass", side_effect=["sekret", "sekret"]):
            result = deploy_harpp.set_password(config, "prod")
        self.assertTrue(result["password_set"])
        self.assertEqual(json.loads(config.read_text())["profiles"]["prod"]["password"], "sekret")
        self.assertEqual(stat.S_IMODE(config.stat().st_mode), 0o600)
        with mock.patch("deploy_harpp.getpass.getpass", side_effect=["a", "b"]):
            with self.assertRaisesRegex(deploy_harpp.DeployError, "do not match"):
                deploy_harpp.set_password(config, "prod")

    def test_set_password_requires_profile_name_when_multiple(self):
        config = self.root / "deploy.json"
        config.write_text(json.dumps({"profiles": {"a": {"user": "u"}, "b": {"user": "u"}}}))
        config.chmod(0o600)
        with self.assertRaisesRegex(deploy_harpp.DeployError, "profile-name"):
            deploy_harpp.set_password(config)


if __name__ == "__main__":
    unittest.main()