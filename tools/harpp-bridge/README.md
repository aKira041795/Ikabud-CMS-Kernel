# HARPP Bridge Client (local)

Local client for the **HARPP module's harness bridge** — lets your AI harness
(VS Code Copilot Chat, Pi, or any MCP client) raise decision-requests, message
the owner, poll for decisions/replies, and post status, exactly as the
**Decision MCP Bridge** was designed.

> **Module + full installation/deployment:** see [`modules/harpp/README.md`](../../modules/harpp/README.md).

**Zero dependencies** — Python stdlib only. Nothing is installed on the server.

## Components

| File | Purpose |
|---|---|
| `harpp_client.py` | Core: config + HTTPS bridge calls (7 operations) |
| `harpp_mcp.py` | **MCP server** over stdio (newline-delimited JSON-RPC) — 7 tools |
| `harpp` | Thin CLI for shell / Pi (`harpp ...`) |
| `deploy_harpp.py` | Profile-bound deploy helper (multi-profile, dry-run by default) |
| `harpp_deploy_ui.py` | Local deploy UI on 127.0.0.1 — choose package + FTP profile, upload |
| `deploy.example.json` / `deploy.profiles.example.json` | Single / multi-profile templates |
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

## 5. Deploy a HARPP package

Profiles live in a single local JSON document, either as one profile at the top
level (`deploy.example.json`) or as a named map (`deploy.profiles.example.json`):

```json
{
  "profiles": {
    "bluehost-harpp": { "...profile fields..." },
    "bluehost-ftps":  { "...profile fields..." }
  }
}
```

Copy `deploy.example.json` (or `deploy.profiles.example.json`) to
`~/.config/harpp/deploy.json`, replace placeholders, and run
`chmod 600 ~/.config/harpp/deploy.json`. Keep passwords out of the file when
possible; use an SSH agent/private key for SFTP or enter `HARPP_DEPLOY_PASSWORD`
directly in the terminal for FTPS. With multiple profiles, select one via
`--profile-name` (a single-profile document needs no flag):

```bash
php create-harpp-deploy-package.php
python3 tools/harpp-bridge/deploy_harpp.py harpp-deploy-YYYYMMDD-HHMMSS.zip
python3 tools/harpp-bridge/deploy_harpp.py --list-profiles          # names + hosts only, no secrets
python3 tools/harpp-bridge/deploy_harpp.py harpp-deploy-YYYYMMDD-HHMMSS.zip --profile-name bluehost-harpp
# Review the dry-run receipt, then explicitly execute:
python3 tools/harpp-bridge/deploy_harpp.py harpp-deploy-YYYYMMDD-HHMMSS.zip --profile-name bluehost-harpp --execute
```

Dry-run is the default and never connects. SFTP requires a pinned `known_hosts`
file. FTPS verifies the server certificate and leaves extraction as an explicit
cPanel action. Plain FTP additionally requires `--allow-plain-ftp` and should
only be used when the owner accepts that credentials and content are unencrypted.
Successful execution writes a mode-0600 receipt under
`~/.config/harpp/deploy-receipts/`; record its SHA-256 with `harpp status` after
owner verification.

**Self-signed FTPS certificates (common on shared hosting).** Many FTP servers
(Bluehost/cPanel, Pure-FTPd) present a self-signed certificate that cannot be
CA- or hostname-verified. Rather than disabling verification, pin the exact
certificate with `"tls_pin": "<SHA-256 fingerprint>"` in the profile — the same
trust model as SFTP `known_hosts`. To obtain the fingerprint:

```bash
python3 tools/harpp-bridge/deploy_harpp.py --test-connection --profile-name <name> --interactive-password
# if it reports CERTIFICATE_VERIFY_FAILED, read the fingerprint with:
#   openssl s_client -connect ftp.example:21 -starttls ftp </dev/null 2>/dev/null \
#     | openssl x509 -noout -fingerprint -sha256
```

With `tls_pin` set, FTPS still requires the certificate fingerprint to match on
every connection (a changed cert fails loudly and you re-pin). Use
`python3 -c "from pathlib import Path;import hashlib,ssl,socket;...` or the
`--test-connection` flow to capture it.

**Non-destructive write probe.** Before the first real deploy, confirm write
access + the profile `root_path` by uploading a tiny scratch file, confirming
it landed, then deleting it:

```bash
python3 tools/harpp-bridge/deploy_harpp.py --probe-upload --profile-name <name> --interactive-password
# → { "uploaded": true, "size_confirmed": 24, "deleted": true, "root_path": "..." }
```

If `deleted` is `false`, the probe file name is reported so you can remove it.
The probe never touches real files.

**Store the FTP password permanently (for the deploy worker / phone GUI).** The
worker runs unattended and needs the password from the local store, not an
interactive prompt. Store it once (0600, atomic, prompted — never echoed):

```bash
python3 tools/harpp-bridge/deploy_harpp.py --set-password --profile-name <name>
```

After this, both `harpp_deploy_worker.py` and `deploy_harpp.py --execute` pick
the password up from `~/.config/harpp/deploy.json` automatically. It is never
sent to the module (inventory publishes non-secret metadata only).

## 5b. Local deploy UI (choose package + FTP profile, upload)

A phone-friendly local UI drives the same profile-bound deploy without the
terminal. It binds to **127.0.0.1 only**; profiles and receipts never leave this
machine.

```bash
python3 tools/harpp-bridge/harpp_deploy_ui.py --open      # default http://127.0.0.1:8787/
# options: --port 8787, --config ~/.config/harpp/deploy.json,
#          --packages-dir <folder> (default: repo root), --list
```

The UI lets you:

1. **Choose the deployment package** — pick from the `harpp-deploy-*.zip` files
   in the packages directory, or press **Build fresh package** to run
   `create-harpp-deploy-package.php`.
2. **Select the FTP profile** — pick a named profile from
   `~/.config/harpp/deploy.json` (shows host/transport/root only; secrets are
   never displayed or sent).
3. **Upload** — **Dry run** builds the receipt without connecting; **Deploy**
   confirms and executes the upload (preflight/backup/upload/verify/extract/
   health check per the profile), writing a mode-0600 receipt locally.

Plain-FTP profiles require ticking the explicit **"Allow plain FTP (cleartext)"**
risk-opt-in box before executing — the same rule as `--allow-plain-ftp` on the
CLI. The UI adds a `Host` + `X-HARPP-DEPLOY-UI` header check so a random local
web page cannot trigger an upload (localhost CSRF/DNS-rebinding guard).

## 5c. Deploy worker — drive FTP deploys from the HARPP phone GUI

The live HARPP app has a **Deploy** page (nav → Deploy). It lists the packages
and profiles this machine publishes, and lets you (while away, on your phone)
pick a package + profile, confirm, and watch the deploy run. The upload itself
always executes **here** — on the machine that holds the FTP profiles.

1. Mark the profiles you want deployable from the phone with
   `"remote_allowed": true` in `~/.config/harpp/deploy.json`. Only those appear
   in the phone GUI, and the worker refuses to run a phone-triggered deploy for
   any other profile.
2. Keep `harpp watch` running (it is the always-on service). On each pass it now
   **publishes the deploy inventory** and, if a deploy is queued, **starts the
   deploy worker once** to execute it — so the FTP worker process exists only
   while a deploy is actually running. No separate `--watch` worker needed:

   ```bash
   # harpp watch now handles deploys automatically; optionally verify one pass:
   python3 tools/harpp-bridge/harpp_deploy_worker.py --once
   # standalone manual run (not driven by the watch): --watch 30
   ```

3. On your phone: HARPP → Deploy → choose package + profile → **Review deploy**
   → **Confirm & deploy**. The phone shows live steps (uploading → extracting →
   verifying) and the receipt; you get a push on completion or failure.

The worker publishes the inventory (non-secret), polls `/deploys/pending`,
CAS-claims (`/deploys/{id}/claim`), executes `deploy_harpp.py` locally, and
reports progress + receipt (`/deploys/{id}/report`). Deploys are recorded in
`harpp_deploy_jobs` on the host; FTP credentials never leave this machine.

## Workflow example

### Temporary model selection

An owner can select a model in a HARPP message for that request only:

```text
use gpt sol to review this change
workflow start governed-loop --model gpt-sol --max-repairs 2
run the governed loop using deepseek flash
```

Supported aliases resolve to provider-qualified model IDs. The preference is
stored only on the resulting job/workflow record for observability; it does not
rewrite a workflow manifest or HARPP configuration. Different pending
conversations are batched by their own temporary preference.

If the chosen model reports token/usage/quota exhaustion or cannot start, HARPP
delegates that request or workflow stage to the next untried model. It does not
switch models for timeouts, malformed completion markers, or ordinary task
failures because rerunning those can duplicate side effects or hide a real defect.
Fallback is bounded by the known model list and the workflow cycle budget.

```
harness hits a decision point (BLOCKED_DECISION_REQUIRED)
  → harpp_submit_decision(...)      → owner gets Web Push on their phone
  → (owner replies in HARPP messenger)
  → harpp_poll_messages / harpp_list_decisions --state DECIDED
  → harpp_acknowledge_decision(id)
  → harness applies → harpp_apply_decision(id)   → decision CLOSED + ADR recorded
```
