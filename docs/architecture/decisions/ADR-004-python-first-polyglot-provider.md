# ADR-004: Python as First Polyglot Capability Provider

## Status
Accepted (2026-06-21)

## Context
The manifesto and architecture describe a polyglot capability model: providers can be written in any language that speaks the ServiceProxy wire protocol (HTTP+JSON). All capability providers to date have been PHP modules running inside the kernel process.

To prove the polyglot boundary is real and not just aspirational, we need at least one non-PHP capability provider in production.

## Decision
Python (FastAPI + ReportLab) is the first polyglot service. It provides `reporting.ledger.daily@1` — PDF generation for daily ledger reports.

### Why Python
- ReportLab is the most mature open-source PDF generation library
- Python's data processing ecosystem (pandas, numpy) is useful for future reporting capabilities
- FastAPI provides a modern, performant HTTP server with automatic OpenAPI docs
- The Python SDK (`httpx` + JSON) is simple — no complex framework dependencies

### Why Reporting
- Report generation is a natural service boundary — heavy lifting, read-only, async-friendly
- Failure is non-critical (retry with backoff)
- No PHP ecosystem dependency (PHP's PDF libraries are weaker than Python's)
- The daily-ledger and WMS modules already need PDF exports

## Wire Protocol Confirmation
The ServiceProxy wire protocol defined in `kernel/Capabilities/ServiceProxy.php` is confirmed working:
- `POST /capability/call` with Bearer token auth
- Request body: `{capability_id, payload, caller}`
- Response: `{ok: true, data: ...}` or `{ok: false, error: ...}`
- Request-ID propagation via `X-Kernel-Request-Id` header

## Consequences

### Positive
- Polyglot architecture is demonstrated end-to-end, not just in documentation
- ServiceProxy infrastructure (circuit breaker, retries, metrics) now has a real consumer
- The module.json `type: service-module` pattern is validated
- Reporting capabilities can grow independently of the PHP kernel release cycle

### Negative
- Adds a Python runtime dependency for deployments that use reporting
- Service must be started separately from the PHP kernel (Supervisor, systemd)
- Debugging across process boundaries requires log correlation via request IDs
- Token management (SERVICE_TOKEN env var) must be added to deployment scripts

## Deployment
```
# Start the service
cd services/reporting
pip install -e .
SERVICE_TOKEN=${REPORTING_SERVICE_TOKEN} uvicorn src.server:app --host 127.0.0.1 --port 5001
```

The PHP kernel discovers the service via `packages/ikabud-service-reporting/module.json` and routes `reporting.ledger.daily@1` through ServiceProxy automatically.

## Future Polyglot Candidates
- Go: High-concurrency queue worker for async event processing
- Rust: Specialized cryptographic or image processing service
- Node.js: Real-time WebSocket push notifications (already partially supported via DiSyL Reactive/HTMX)
