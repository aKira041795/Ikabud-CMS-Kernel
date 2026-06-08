# Polyglot Service Developer Guide

> **Kernel OS 5.0 (nexus)** — Build governed capability providers in any language using HTTP+JSON.
> Python ✅ · Node.js ✅ · Go ✅ · Rust ✅ · Ruby ✅ · anything that speaks HTTP+JSON.

---

## Minimum Service Contract

A Kernel OS polyglot service **must** provide:

1. `POST /capability/call` — the main capability endpoint
2. `GET /health` — health check endpoint
3. JSON request/response
4. Stable capability IDs (match `module.json` exactly)
5. Timeout-safe behavior (respond or fail fast, don't hang)
6. Auth token validation in production (see [Auth](#auth-protocol))
7. No direct access to kernel database credentials
8. Clear error responses with correct HTTP status codes

---

## Pipeline Overview

```
DiSyL Template
  ↓
ComponentRegistry (ikb_entity_list, ikb_entity_detail)
  ↓
EntityViewResolver
  ↓
CapabilityBus
  ↓
ServiceProxy
  ↓
External Service (Python, Node, Go, Rust, …)
  ↓
Capability Response
  ↓
Entity View (card, table, grid, timeline, …)
  ↓
Rendered UI / Report / Export
```

---

## Overview

The Kernel OS capability bus is **language-agnostic**. A `ServiceProxy` translates
`CapabilityBus::call()` invocations into HTTP requests to external services. The
bus handles circuit breaking, retries, timeouts, and metrics — your service just
needs to accept JSON and return JSON.

```
PHP Kernel                    Your Service (any language)
────────────                  ──────────────────────────
CapabilityBus::call()
  → ServiceProxy::__invoke()
    → POST /capability/call ──→  handle_request()
    ← {"ok":true,"data":{}}  ←─  return response
  → return data
```

---

## Quick Start (5 minutes)

### 1. Create the module directory

```
modules/my-service/
├── module.json     # REQUIRED — manifest
└── service.py      # Your service (any language, any filename)
```

### 2. Write the manifest (`module.json`)

```json
{
    "id": "my-service",
    "type": "service-module",
    "name": "My Polyglot Service",
    "version": "1.0.0",
    "description": "Does something useful in a non-PHP language.",
    "author": "Your Name",
    "service": {
        "endpoint": "http://127.0.0.1:9003",
        "protocol": "http+json",
        "timeout_ms": 10000,
        "retry": {
            "max_attempts": 2,
            "backoff_ms": 500,
            "backoff_multiplier": 1.5
        },
        "circuit_breaker": {
            "failure_threshold": 5,
            "cooldown_seconds": 30,
            "half_open_max_requests": 1
        },
        "auth": {
            "type": "signed_token",
            "token_env": "MY_SERVICE_TOKEN"
        }
    },
    "capabilities": {
        "exposes": [
            {
                "id": "myservice.action@1",
                "priority": 100,
                "modes": ["first"],
                "description": "Performs an action",
                "schema": {
                    "input": {
                        "type": "object",
                        "required": ["param1"],
                        "properties": {
                            "param1": {"type": "string"}
                        }
                    },
                    "output": {
                        "type": "object",
                        "properties": {
                            "result": {"type": "string"}
                        }
                    }
                }
            }
        ]
    },
    "routes": []
}
```

### 3. Implement the wire protocol

Your service must expose **one endpoint**:

```
POST /capability/call
Content-Type: application/json

{
    "capability_id": "myservice.action@1",
    "payload": {
        "param1": "hello"
    },
    "caller": {
        "module": "cms",
        "request_id": "req_abc123",
        "tenant_id": "tenant_01",
        "user": {
            "id": "42",
            "role": "administrator"
        }
    }
}
```

**Success response:**
```json
{
    "ok": true,
    "data": {
        "result": "processed: hello"
    }
}
```

**Error response:**
```json
{
    "ok": false,
    "error": "Descriptive error message"
}
```

### HTTP Status Codes

| HTTP Status | `ok` | Meaning |
|---|---|---|
| `200` | `true` | Success |
| `400` | `false` | Invalid payload |
| `401` | `false` | Unauthorized (missing/invalid auth token) |
| `403` | `false` | Forbidden (valid token, insufficient permissions) |
| `404` | `false` | Unknown capability ID |
| `422` | `false` | Validation failed (payload correct but invalid) |
| `500` | `false` | Service error |
| `503` | `false` | Dependency unavailable |

### Auth Protocol

When `service.auth` is configured in the manifest, the kernel sends these headers:

```
POST /capability/call
Content-Type: application/json
Authorization: Bearer <token-from-env>
X-Kernel-Service: my-service
X-Kernel-Request-Id: req_abc123
```

Your service should validate the `Authorization` header in production:

```python
# Production auth check (add to your service):
import os

AUTH_TOKEN = os.environ.get("MY_SERVICE_TOKEN", "")

class Handler(BaseHTTPRequestHandler):
    def do_POST(self):
        # Validate auth token
        auth_header = self.headers.get("Authorization", "")
        if AUTH_TOKEN and auth_header != f"Bearer {AUTH_TOKEN}":
            self._json(401, {"ok": False, "error": "unauthorized"})
            return
        # ... rest of handler
```

> **Demo examples below omit auth validation for simplicity.**
> Production services must validate the configured token.

#### Python example (zero dependencies)

> ⚠️ **Demo only — no auth validation.** Production services must validate the configured auth token.

```python
#!/usr/bin/env python3
import json, sys
from http.server import HTTPServer, BaseHTTPRequestHandler

CAPABILITIES = {
    "myservice.action@1": lambda p: {"result": f"processed: {p.get('param1', '')}"},
}

class Handler(BaseHTTPRequestHandler):
    def do_GET(self):
        if self.path == "/health":
            self._json(200, {"ok": True, "service": "my-service"})

    def do_POST(self):
        if self.path != "/capability/call":
            self._json(404, {"ok": False, "error": "not found"})
            return
        body = json.loads(self.rfile.read(int(self.headers["Content-Length"])))
        cap_id = body.get("capability_id", "")
        handler = CAPABILITIES.get(cap_id)
        if not handler:
            self._json(404, {"ok": False, "error": f"unknown: {cap_id}"})
            return
        try:
            result = handler(body.get("payload", {}))
            self._json(200, {"ok": True, "data": result})
        except Exception as e:
            self._json(500, {"ok": False, "error": str(e)})

    def _json(self, status, data):
        body = json.dumps(data).encode()
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

if __name__ == "__main__":
    HTTPServer(("127.0.0.1", 9003), Handler).serve_forever()
```

#### Node.js example

> ⚠️ **Demo only — no auth validation.** Production services must validate the configured auth token.

```javascript
const http = require('http');

const CAPABILITIES = {
  'myservice.action@1': (payload) => ({ result: `processed: ${payload.param1 || ''}` }),
};

const server = http.createServer((req, res) => {
  const json = (status, data) => {
    const body = JSON.stringify(data);
    res.writeHead(status, { 'Content-Type': 'application/json' });
    res.end(body);
  };

  if (req.method === 'GET' && req.url === '/health') {
    return json(200, { ok: true, service: 'my-service' });
  }

  if (req.method !== 'POST' || req.url !== '/capability/call') {
    return json(404, { ok: false, error: 'not found' });
  }

  let body = '';
  req.on('data', (chunk) => (body += chunk));
  req.on('end', () => {
    const { capability_id, payload } = JSON.parse(body);
    const handler = CAPABILITIES[capability_id];
    if (!handler) return json(404, { ok: false, error: `unknown: ${capability_id}` });
    try {
      json(200, { ok: true, data: handler(payload || {}) });
    } catch (e) {
      json(500, { ok: false, error: e.message });
    }
  });
});

server.listen(9003, '127.0.0.1', () => console.log('my-service on :9003'));
```

### 4. Start your service

```bash
python3 modules/my-service/service.py &
# or: node modules/my-service/service.js &
```

### 5. Verify registration

The module-manager auto-discovers `module.json` and registers a `ServiceProxy` for
each exposed capability. Verify:

```bash
php -r '
require_once "bootstrap.php";
echo app()->capabilities()->has("myservice.action@1") ? "REGISTERED" : "MISSING";
'
```

### 6. Call from PHP

```php
$result = app()->cap()->call('myservice.action@1', ['param1' => 'hello'], [
    'caller' => ['module' => 'cms'],
]);
// $result = ['result' => 'processed: hello']
```

---

## CMS Entity-View Integration

Polyglot services integrate with the CMS entity-view system for zero-code
admin UI rendering. There are two approaches:

### Option A — Declarative `entity_sources` in module.json (preferred, no PHP code)

Add an `entity_sources` section to your `module.json`. The kernel auto-registers
entity views and auto-generates `entity.list.*` + `entity.get.*` capability handlers
from the manifest — no PHP bridge code needed.

```json
{
    "entity_sources": {
        "<entity_type>": {
            "qualifiers": {
                "<qualifier>": {
                    "capability": "<capability_id>",
                    "result_path": "<optional nested key for rows>"
                }
            },
            "get_capability": "<default get capability>",
            "default_view": "<view_name>",
            "views": {
                "<view_name>": {
                    "fields": ["field1", "field2"],
                    "limit": 10,
                    "empty_state": "No data available.",
                    "error_state": "Service unavailable."
                }
            }
        }
    }
}
```

**How it works:**

1. `DiSyL source="myservice.data"` → `parseSource` extracts entity_type=`myservice`, qualifier=`data`
2. EntityViewResolver calls `entity.list.myservice@1`
3. Auto-generated handler reads the qualifier, looks up the matching capability in `qualifiers`
4. Calls the polyglot capability → maps result rows via `result_path`
5. Returns `{rows: [...], total: N}`

**Real example (weather-service):**

```json
"entity_sources": {
    "weather": {
        "qualifiers": {
            "forecast": {
                "capability": "weather.forecast@1",
                "result_path": "forecast"
            },
            "current": {
                "capability": "weather.current@1"
            }
        },
        "get_capability": "weather.current@1",
        "default_view": "card_grid",
        "views": {
            "card_grid": {
                "fields": ["date", "high_c", "low_c", "condition"],
                "limit": 5
            }
        }
    }
}
```

### Option B — PHP bridge module (legacy, for complex integration)

Create a companion PHP module that registers entity views and handlers manually:

```
modules/my-service/              # service-module, external capability provider
modules/my-service-cms-bridge/   # php-module, registers entity views and CMS routes
```

In the bridge module's helper:


### Use in DiSyL templates

```disyl
{ikb_entity_list source="myservice.data" view="card" /}
```

The EntityViewResolver will call `entity.list.myservice@1` → your proxy → your polyglot service.

**Field auto-detection:** When no explicit view contract is registered (or when the
contract's `fields` is `"*"`), `TemplateEngine::renderEntityList()` automatically
expands `"*"` to the actual keys from the first result row. This means polyglot
services work **without** pre-declaring field schemas — just return rows with the
fields you want displayed, and the renderer picks them up.

**Entity detail** (`ikb_entity_detail`) works even without any view contract —
it falls back to rendering all `array_keys($entity)`.

**Timeout:** Polyglot services that hit external APIs may need longer timeouts.
Both `EntityViewResolver::resolve()` and `TemplateEngine::renderEntityDetail()`
support `timeout_ms` in capability call options (set to 10000ms for weather-service).

---

## Service Lifecycle

### Development

- Run service manually from the terminal
- Bind to `127.0.0.1` only
- Use stdout/stderr for logs
- Restart manually after code changes

### Production

Polyglot services are **operational dependencies** — if they're down, capability
calls fail. Use a process manager:

**systemd example** (`/etc/systemd/system/my-service.service`):
```ini
[Unit]
Description=My Polyglot Service
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/html/applicationostest
ExecStart=/usr/bin/python3 modules/my-service/service.py
Restart=always
RestartSec=5
Environment=MY_SERVICE_TOKEN=your-secret-token

[Install]
WantedBy=multi-user.target
```

**Docker example**:
```dockerfile
FROM python:3-alpine
WORKDIR /app
COPY service.py .
EXPOSE 9003
CMD ["python3", "service.py"]
```

**Supervisor example**:
```ini
[program:my-service]
command=python3 /var/www/html/applicationostest/modules/my-service/service.py
directory=/var/www/html/applicationostest
autostart=true
autorestart=true
stderr_logfile=/var/log/my-service.err.log
stdout_logfile=/var/log/my-service.out.log
```

### Health Monitoring

All polyglot services must expose `GET /health`. The kernel periodically probes
this endpoint. Return:
```json
{"ok": true, "service": "my-service", "version": "1.0.0"}
```

### General Rules

- Never bind to a public interface (`0.0.0.0`) unless behind an internal firewall/reverse proxy
- Log JSON lines when possible (easier to parse with `jq`, log aggregators)
- Set resource limits (memory, CPU) in your process manager
- The kernel handles circuit breaking and retries — don't implement your own

---

| Field | Type | Default | Description |
|---|---|---|---|
| `service.endpoint` | string | **required** | Base URL of your service (e.g. `http://127.0.0.1:9003`) |
| `service.protocol` | string | `http+json` | Wire protocol (only `http+json` currently) |
| `service.timeout_ms` | int | `30000` | Per-request timeout in milliseconds |
| `service.health_check` | string | `/health` | GET endpoint for health checks |
| `service.retry.max_attempts` | int | `3` | Max retry attempts on failure |
| `service.retry.backoff_ms` | int | `1000` | Initial backoff delay |
| `service.retry.backoff_multiplier` | float | `2.0` | Exponential backoff multiplier |
| `service.circuit_breaker.failure_threshold` | int | `5` | Consecutive failures before circuit opens |
| `service.circuit_breaker.cooldown_seconds` | int | `60` | Seconds before half-open probe |
| `service.circuit_breaker.half_open_max_requests` | int | `1` | Max probe requests in half-open state |
| `service.auth.type` | string | `null` | Auth type (`signed_token`) |
| `service.auth.token_env` | string | `null` | Environment variable for the auth token |

---

## How It Works (Internals)

### ServiceProxy

`kernel/Capabilities/ServiceProxy.php` is a PHP callable that implements the
same `($payload, $capabilityId, $providerId): mixed` signature as any other
capability handler. It is **drop-in compatible** with `CapabilityRegistry::register()`.

When invoked:
1. Reads `service.endpoint` from the module manifest
2. Resolves the auth token from `$_ENV` or `$_SERVER`
3. Builds the capability caller context (module, request_id, tenant_id, user)
4. POSTs JSON to `{endpoint}/capability/call`
5. Validates the response (`ok: true` with `data`)
6. Returns the `data` payload

The **CapabilityBus** wraps every `ServiceProxy` call with:
- **Circuit breaking** — per-capability failure tracking with half-open probing
- **Retries** — configurable attempts with exponential backoff
- **Timeout** — SIGALRM-based with configurable timeout_ms
- **Metrics** — p95 latency, error rate, call count
- **Schema validation** — optional input/output JSON Schema enforcement

### Module Auto-Registration

In `src/helpers/module-manager.php`, when a module has `"type": "service-module"`
and a valid `service.endpoint`:
1. `ServiceProxy::fromManifest($module)` builds the proxy
2. `app()->capabilities()->register(...)` registers it for each exposed capability
3. No PHP handlers or helpers are loaded — the module is purely external

---

## Testing

### Unit testing ServiceProxy

```php
use Ikabud\Kernel\Capabilities\ServiceProxy;

$proxy = ServiceProxy::fromManifest($manifest);
$proxy->setHttpHandler(function (string $url, array $opts): array {
    // Return mock responses, never hit the network
    return ['status' => 200, 'body' => json_encode(['ok' => true, 'data' => 'mock'])];
});

$result = $proxy(['test'], 'cap@1', 'my-service');
// $result = 'mock'
```

### Full E2E testing

See `tests/polyglot_weather_test.php` and `tests/cms_weather_e2e.php` for
complete examples of testing the full pipeline with a real external service.

---

## Real-World Example: Weather Service

`modules/weather-service/` is a production-quality polyglot service:

- **Language:** Python 3 (zero dependencies, stdlib only)
- **Capabilities:** `weather.current@1`, `weather.forecast@1`
- **Data source:** wttr.in (free, no API key) with graceful mock fallback
- **Port:** 9002
- **CMS integration:** Entity view contracts + admin dashboard at `/cms/admin/weather`

Start it:
```bash
python3 modules/weather-service/service.py &
```

Test it:
```bash
curl -X POST http://127.0.0.1:9002/capability/call \
  -H "Content-Type: application/json" \
  -d '{"capability_id":"weather.current@1","payload":{"city":"Tokyo"},"caller":{"module":"test"}}'
```

---

## Security Considerations

1. **Bind to localhost** — Services should listen on `127.0.0.1`, not `0.0.0.0`
2. **Auth tokens** — Use `service.auth.token_env` to pass signed tokens
3. **Input validation** — Validate all inputs; the kernel passes caller context you can use for authorization
4. **No kernel secrets** — Services should not receive database credentials or kernel secrets
5. **Timeouts** — Keep `timeout_ms` reasonable; the bus handles hanging services via SIGALRM

---

## Troubleshooting

| Symptom | Likely Cause | Fix |
|---|---|---|
| `Capability not found` | Module not discovered, manifest invalid, capability ID mismatch, or module disabled | Run `php ikabud module:certify my-service`, check `module.json`, clear module cache |
| `ServiceProxy connection failed` | Service not running or endpoint unreachable | Start service, verify `service.endpoint`, test `/health` |
| `ServiceProxy error: unknown capability` | Service received a capability ID it does not handle | Match `capabilities.exposes[].id` with service handler map |
| `ServiceProxy HTTP 500` | Your service crashed or returned invalid JSON | Check service logs |
| Module not discovered | Missing `module.json` or invalid manifest | Run `php ikabud module:certify my-service` |
| Timeout errors | Service too slow or hung | Increase `timeout_ms` or optimize service |
| Circuit open | Too many consecutive failures | Check service health, circuit auto-resets after `cooldown_seconds` |
| `ServiceProxy authentication failed` | Auth token mismatch or not configured | Verify `token_env` is set, check `Authorization` header in service logs |
