"""
Ikabud Reporting Service — FastAPI server implementing the ServiceProxy wire protocol.

Wire protocol (HTTP+JSON):
  POST /capability/call
  Authorization: Bearer {service_token}
  Content-Type: application/json
  Body: { "capability_id": "...", "payload": {...}, "caller": {...} }

Response:
  {"ok": true, "data": ...}
  {"ok": false, "error": "..."}

Health check:
  GET /health → {"ok": true, "service": "reporting", "version": "1.0.0"}
"""

import os
import sys
from typing import Any

from fastapi import FastAPI, HTTPException, Request
from fastapi.responses import JSONResponse

# Add src to path for direct execution
sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))

from capabilities import handle_ledger_daily

app = FastAPI(title="Ikabud Reporting Service", version="1.0.0")

SERVICE_TOKEN = os.environ.get("SERVICE_TOKEN", "dev-token")

# ── Capability handler registry ──────────────────────────────────────────
CAPABILITY_HANDLERS: dict[str, Any] = {
    "reporting.ledger.daily@1": handle_ledger_daily,
}


# ── Auth middleware ──────────────────────────────────────────────────────
def _verify_auth(request: Request) -> bool:
    """Verify Bearer token against SERVICE_TOKEN."""
    auth_header = request.headers.get("Authorization", "")
    if not auth_header.startswith("Bearer "):
        return False
    token = auth_header[7:]
    return token == SERVICE_TOKEN


# ── Routes ───────────────────────────────────────────────────────────────

@app.get("/health")
async def health():
    """Health check endpoint — probed by kernel superadmin dashboard."""
    return {"ok": True, "service": "reporting", "version": "1.0.0"}


@app.post("/capability/call")
async def capability_call(request: Request):
    """Main capability dispatch endpoint (ServiceProxy wire protocol)."""
    if not _verify_auth(request):
        raise HTTPException(status_code=401, detail="Unauthorized")

    try:
        body = await request.json()
    except Exception:
        return JSONResponse(
            status_code=400,
            content={"ok": False, "error": "Invalid JSON request body"},
        )

    capability_id = str(body.get("capability_id", ""))
    payload = body.get("payload", {}) or {}
    caller = body.get("caller", {}) or {}

    if not capability_id:
        return JSONResponse(
            status_code=400,
            content={"ok": False, "error": "capability_id is required"},
        )

    handler = CAPABILITY_HANDLERS.get(capability_id)
    if handler is None:
        return JSONResponse(
            status_code=404,
            content={
                "ok": False,
                "error": f"Unknown capability: {capability_id}",
                "available": list(CAPABILITY_HANDLERS.keys()),
            },
        )

    try:
        result = handler(payload, caller)
        return result
    except Exception as e:
        return JSONResponse(
            status_code=500,
            content={
                "ok": False,
                "error": str(e),
                "capability_id": capability_id,
            },
        )


# ── Entrypoint ───────────────────────────────────────────────────────────

if __name__ == "__main__":
    import uvicorn

    host = os.environ.get("HOST", "127.0.0.1")
    port = int(os.environ.get("PORT", "5001"))
    uvicorn.run(app, host=host, port=port)
