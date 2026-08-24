# HARPP Bridge Client (local)

Local client for the **HARPP module's harness bridge** — lets your AI harness
(VS Code Copilot Chat, Pi, or any MCP client) raise decision-requests, message
the owner, poll for decisions/replies, and post status, exactly as the
**Decision MCP Bridge** was designed.

**Zero dependencies** — Python stdlib only. Nothing is installed on the server.

## Components

| File | Purpose |
|---|---|
| `harpp_client.py` | Core: config + HTTPS bridge calls (7 operations) |
| `harpp_mcp.py` | **MCP server** over stdio (newline-delimited JSON-RPC) — 7 tools |
| `harpp` | Thin CLI for shell / Pi (`harpp ...`) |
| `tests/test_harpp_bridge.py` | Local unit tests (no network) |

## 1. Configure

```bash
harpp config set base_url   https://yourdomain.com
harpp config set bridge_key <the bridge key from HARPP Settings>
harpp config set tenant_id  1
harpp check
```

Config is stored at `~/.config/harpp/config.json` (0600). Env overrides:
`HARPP_BASE_URL`, `HARPP_BRIDGE_KEY`, `HARPP_TENANT_ID`. For local dev against
self-signed HTTPS set `HARPP_INSECURE=1`; preview requests with `HARPP_DRY_RUN=1`.

## 2. Use as an MCP server (VS Code Copilot Chat / Pi)

Add to `.vscode/mcp.json` (project) — or your user MCP config:

```json
{
  "servers": {
    "harpp": {
      "type": "stdio",
      "command": "python3",
      "args": ["tools/harpp-bridge/harpp_mcp.py"],
      "env": {}
    }
  }
}
```

Restart VS Code. The model then has 7 tools:
`harpp_submit_decision`, `harpp_list_decisions`, `harpp_acknowledge_decision`,
`harpp_apply_decision`, `harpp_send_message`, `harpp_poll_messages`,
`harpp_post_status`.

**Pi** (0.84+): Pi can load the same MCP server — see Pi's MCP config
(`~/.pi/...`), or just shell out via the CLI below.

## 3. CLI reference (shell / Pi)

```bash
harpp decision submit --title "Blocked: nested extensions" --body "..." \
      --requested "Allow or deny?" --priority high --workbench-state ARCHITECTURE_DECISION_REQUIRED
harpp decision list --state PENDING
harpp decision ack 12
harpp decision apply 12
harpp msg send --body "Implementation finished, running tests..."
harpp msg poll --after 0
harpp status --message "Tests green" --status done --workbench-state READY_FOR_REVIEW
```

## 4. Self-test (no network)

```bash
harpp self-test          # or: python3 -m unittest tools.harpp-bridge.tests.test_harpp_bridge
```

## Workflow example

```
harness hits a decision point (BLOCKED_DECISION_REQUIRED)
  → harpp_submit_decision(...)      → owner gets Web Push on their phone
  → (owner replies in HARPP messenger)
  → harpp_poll_messages / harpp_list_decisions --state DECIDED
  → harpp_acknowledge_decision(id)
  → harness applies → harpp_apply_decision(id)   → decision CLOSED + ADR recorded
```
