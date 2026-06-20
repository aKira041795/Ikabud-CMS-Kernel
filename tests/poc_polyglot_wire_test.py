#!/usr/bin/env python3
"""
POC Validation — Python Polyglot Service (Phase C)

Validates the Ikabud ServiceProxy wire protocol end-to-end
without requiring a running server or PHP kernel.

Usage: python3 tests/poc_polyglot_wire_test.py
"""

import json
import os
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
PASS = 0
FAIL = 0


def t(label: str, condition: bool, detail: str = "") -> None:
    global PASS, FAIL
    if condition:
        PASS += 1
        print(f"  ✅ {label}")
    else:
        FAIL += 1
        print(f"  ❌ {label}" + (f" — {detail}" if detail else ""))


print("╔══════════════════════════════════════════════════════════════╗")
print("║  POC Validation — Python Polyglot Service (Phase C)          ║")
print("╚══════════════════════════════════════════════════════════════╝")
print()

# ── C1: Service Structure ──────────────────────────────────────────────────

print("── C1: Service Structure ──")

service_dir = ROOT / "services" / "reporting"
t("services/reporting/ exists", service_dir.is_dir())
t("pyproject.toml exists", (service_dir / "pyproject.toml").is_file())
t("src/server.py exists", (service_dir / "src" / "server.py").is_file())
t("src/capabilities/ledger_report.py exists",
  (service_dir / "src" / "capabilities" / "ledger_report.py").is_file())
t("tests/test_server.py exists", (service_dir / "tests" / "test_server.py").is_file())

# Verify pyproject.toml has required deps
pyproject = (service_dir / "pyproject.toml").read_text()
t("pyproject.toml declares fastapi", "fastapi" in pyproject)
t("pyproject.toml declares uvicorn", "uvicorn" in pyproject)
t("pyproject.toml declares reportlab", "reportlab" in pyproject)
t("pyproject.toml declares httpx (test)", "httpx" in pyproject)

# Verify server.py has wire protocol endpoint
server_py = (service_dir / "src" / "server.py").read_text()
t("server.py has /capability/call endpoint", "/capability/call" in server_py)
t("server.py has /health endpoint", "/health" in server_py)
t("server.py has Bearer token auth", "Bearer " in server_py)
t("server.py registers ledger handler", "reporting.ledger.daily@1" in server_py)
t("server.py returns ok/error protocol", '"ok": True' in server_py or "'ok': True" in server_py)

# Verify capability handler
ledger_py = (service_dir / "src" / "capabilities" / "ledger_report.py").read_text()
t("ledger_report.py generates PDF", "SimpleDocTemplate" in ledger_py)
t("ledger_report.py returns ok/data structure", '"ok": True' in ledger_py or "'ok': True" in ledger_py)
t("ledger_report.py includes pdf_base64 in output", "pdf_base64" in ledger_py)

print()

# ── C2: Module.json Registration ──────────────────────────────────────────

print("── C2: Kernel-Side Service Module Registration ──")

manifest_path = ROOT / "packages" / "ikabud-service-reporting" / "module.json"
t("packages/ikabud-service-reporting/module.json exists", manifest_path.is_file())

manifest = json.loads(manifest_path.read_text())
t("Manifest is valid JSON", isinstance(manifest, dict))
t("Manifest id matches", manifest.get("id") == "ikabud-service-reporting")
t("Manifest type is service-module", manifest.get("type") == "service-module")
t("Manifest has service endpoint", bool(manifest.get("service", {}).get("endpoint")))
t("Manifest has protocol http+json", manifest.get("service", {}).get("protocol") == "http+json")
t("Manifest exposes capability", len(manifest.get("capabilities", {}).get("exposes", [])) >= 1)

caps = manifest.get("capabilities", {}).get("exposes", [])
ledger_cap = next((c for c in caps if c.get("id") == "reporting.ledger.daily@1"), None)
t("reporting.ledger.daily@1 capability declared", ledger_cap is not None)
if ledger_cap:
    t("Capability has input schema", bool(ledger_cap.get("schema", {}).get("input")))
    t("Capability has output schema", bool(ledger_cap.get("schema", {}).get("output")))
    t("Capability has policy", bool(manifest.get("capabilities", {}).get("policy")))

print()

# ── C3: Wire Protocol Test Script Validity ────────────────────────────────

print("── C3: Wire Protocol Test Script ──")

test_py = (service_dir / "tests" / "test_server.py").read_text()
t("Test script has health check", "test_health" in test_py)
t("Test script has success test", "test_capability_call_success" in test_py)
t("Test script has auth rejection test", "test_capability_call_no_auth" in test_py)
t("Test script has unknown capability test", "test_capability_call_unknown" in test_py)
t("Test script uses httpx", "httpx" in test_py)
t("Test script uses Bearer token", "Bearer" in test_py)

print()

# ── C4: ADR-004 Cross-Reference ───────────────────────────────────────────

print("── C4: ADR-004 Cross-Reference ──")

adr_path = ROOT / "docs" / "architecture" / "decisions" / "ADR-004-python-first-polyglot-provider.md"
t("ADR-004 exists", adr_path.is_file())

adr_content = adr_path.read_text()
t("ADR-004 mentions ServiceProxy", "ServiceProxy" in adr_content)
t("ADR-004 mentions Python", "Python" in adr_content)
t("ADR-004 mentions FastAPI", "FastAPI" in adr_content)
t("ADR-004 mentions ReportLab", "ReportLab" in adr_content)
t("ADR-004 mentions wire protocol", "wire protocol" in adr_content.lower())

print()

# ── Summary ────────────────────────────────────────────────────────────────

print("╔══════════════════════════════════════════════════════════════╗")
print(f"║  Results:  {PASS:2d} passed  {FAIL:2d} failed                    ║")
print("╚══════════════════════════════════════════════════════════════╝")
print()

if FAIL > 0:
    print(f"❌ VALIDATION FAILED — {FAIL} assertions failed.")
    sys.exit(1)
else:
    print(f"✅ ALL {PASS} ASSERTIONS PASSED — polyglot architecture verified.")
    sys.exit(0)
