# Weather Service (Python)

Polyglot weather information service — proves that Kernel OS capability bus can dispatch to non-PHP runtimes via `ServiceProxy`. Runs as an independent Python process.

## Capabilities

| Capability | Purpose |
|-----------|---------|
| `weather.current@1` | Get current weather for a location |

## Architecture

Demonstrates the polyglot service pattern:
1. PHP module declares capability in `module.json`
2. Kernel OS routes capability calls via `ServiceProxy`
3. Python service receives JSON over HTTP, returns structured response
4. No shared filesystem state — all data through wire protocol

## Files

- Manifest: [`module.json`](module.json)
- Service: [`service.py`](service.py)
