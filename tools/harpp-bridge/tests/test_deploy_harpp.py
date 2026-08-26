#!/usr/bin/env python3
import json
import os
import stat
import sys
import tempfile
import unittest
import zipfile
from pathlib import Path

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
        self.assertEqual(receipt["profile"]["password"], "***redacted***")
        self.assertEqual(receipt["profile"]["private_key"], "***redacted***")
        self.assertNotIn("never-print-me", rendered)

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


if __name__ == "__main__":
    unittest.main()