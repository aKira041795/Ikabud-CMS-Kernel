"""
Test the Ikabud Reporting Service wire protocol.
Run after starting the service:
  cd services/reporting && SERVICE_TOKEN=dev-token uvicorn src.server:app --port 5001

Then:
  python services/reporting/tests/test_server.py
"""

import json
import httpx

BASE_URL = "http://127.0.0.1:5001"
TOKEN = "dev-token"


def test_health():
    """Health check endpoint."""
    r = httpx.get(f"{BASE_URL}/health")
    assert r.status_code == 200
    data = r.json()
    assert data["ok"] is True
    assert data["service"] == "reporting"
    print("✅ Health check passed")


def test_capability_call_success():
    """Valid capability call with auth."""
    r = httpx.post(
        f"{BASE_URL}/capability/call",
        json={
            "capability_id": "reporting.ledger.daily@1",
            "payload": {
                "date": "2026-06-21",
                "store_id": 42,
                "entries": [
                    {"time": "08:00", "description": "Opening balance", "amount": 5000.00},
                    {"time": "10:00", "description": "Cash sale #42", "amount": 350.50},
                ],
                "summary": {
                    "total_sales": 350.50,
                    "total_expenses": 0,
                    "closing_balance": 5350.50,
                },
            },
            "caller": {
                "module": "daily-ledger",
                "tenant_id": 7,
                "request_id": "test-123",
            },
        },
        headers={"Authorization": f"Bearer {TOKEN}"},
    )
    assert r.status_code == 200
    data = r.json()
    assert data["ok"] is True
    assert "pdf_base64" in data["data"]
    assert data["data"]["report_date"] == "2026-06-21"
    print("✅ Capability call succeeded")


def test_capability_call_no_auth():
    """Capability call without auth should fail."""
    r = httpx.post(
        f"{BASE_URL}/capability/call",
        json={"capability_id": "reporting.ledger.daily@1", "payload": {}},
    )
    assert r.status_code == 401
    print("✅ Auth rejection passed")


def test_capability_call_unknown():
    """Unknown capability should return 404."""
    r = httpx.post(
        f"{BASE_URL}/capability/call",
        json={"capability_id": "nonexistent.cap@1", "payload": {}},
        headers={"Authorization": f"Bearer {TOKEN}"},
    )
    assert r.status_code == 404
    data = r.json()
    assert data["ok"] is False
    print("✅ Unknown capability rejection passed")


if __name__ == "__main__":
    test_health()
    test_capability_call_success()
    test_capability_call_no_auth()
    test_capability_call_unknown()
    print("\n🎉 All tests passed!")
