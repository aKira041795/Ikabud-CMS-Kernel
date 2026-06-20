# Ikabud Reporting Service

Polyglot capability provider for the Ikabud Kernel OS. Implements the ServiceProxy wire protocol (HTTP+JSON) to provide PDF report generation capabilities.

## Quick Start

```bash
cd services/reporting
pip install -e .
SERVICE_TOKEN=dev-token uvicorn src.server:app --port 5001 --reload
```

## Capabilities

| Capability ID | Description |
|---|---|
| `reporting.ledger.daily@1` | Generate a daily ledger PDF report |

## Wire Protocol

```
POST /capability/call
Authorization: Bearer {service_token}
Content-Type: application/json

{
  "capability_id": "reporting.ledger.daily@1",
  "payload": { "date": "2026-06-21", "store_id": 42 },
  "caller": { "module": "daily-ledger", "tenant_id": 7, "request_id": "abc123" }
}

→ { "ok": true, "data": { "pdf_base64": "...", "generated_at": "..." } }
```

## Environment Variables

| Variable | Required | Default | Description |
|---|---|---|---|
| `SERVICE_TOKEN` | Yes | — | Bearer token for auth |
| `PORT` | No | `5001` | HTTP listen port |
| `HOST` | No | `127.0.0.1` | Bind address |
| `REPORT_STORAGE_DIR` | No | `./storage/reports` | PDF output directory |
