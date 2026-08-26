# HARPP — Harness App (Decision Center & Messenger)

> **HARPP = Harness App.** The human-in-the-loop decision bridge between an autonomous AI
> development harness (Pi, VS Code Copilot, MCP clients) and its operator — over your phone.
>
> The harness hits a decision point → HARPP raises a **decision-request** → you get a
> **Web Push** on your phone → you reply in the **messenger** (or decide in the PWA) →
> the harness polls, acknowledges, and applies → the decision is recorded as a durable **ADR**.

This is a first-class **Ikabud module** (auth-owned tenant module): it owns its database
tables, its auth shell, its settings, and its capabilities. It is MySQL 5.7 / shared-hosting
(Bluehost) compatible and requires **no background workers**.

---

## 1. Overview

| | |
|---|---|
| Module id | `harpp` |
| Version | `1.0.0` |
| Kind | Auth-owned tenant module (own DB + auth shell) |
| Auth | JWT cookie (`harpp_token`), roles `owner` / `admin` / `member` |
| Storage | 9 owned `harpp_*` tables in the tenant database |
| Capabilities | 9 exposed, 2 depended (`kernel.audit.record@1`, `kernel.auth.user@1`) |
| UI | Installable PWA messenger (DiSyL) + Web Push |
| Harness bridge | REST API (`/api/v1/harpp/bridge/*`) consumed by the local client |

### The HARPP loop

```
local AI harness (Pi / VS Code Copilot / MCP)
        │  1. hits a decision point / has an update
        ▼
HARPP bridge (REST, X-HARPP-BRIDGE-KEY)          ← local client (CLI / MCP / Pi)
        │  2. creates decision / message / notification
        ▼
HARPP module (Bluehost, HTTPS)
        │  3. Web Push (VAPID) → PWA messenger
        ▼
you on your phone
        │  4. decide / reply / acknowledge
        ▼
harness polls → acknowledges → applies → decision CLOSED + ADR recorded
```

---

## 2. Features

- **Decision lifecycle** — `CREATED → PENDING → NOTIFIED → VIEWED → DECIDED → ACKNOWLEDGED → APPLIED → CLOSED`
  (+ `EXPIRED` / `SUPERSEDED` / `CANCELLED`), fail-closed allowed-transition table, append-only
  transition audit, and preserved Workbench state (`ARCHITECTURE_DECISION_REQUIRED`, etc.).
- **Automatic ADR memory** — every `DECIDED` decision atomically writes a durable ADR row
  (`harpp_adrs`) with context, decision, rationale, actor, and timestamps.
- **Messenger** — conversations + messages between the harness and the owner, read state, unread counts.
- **Web Push (PWA)** — installable messenger app with a service worker; ES256 VAPID push with
  SSRF-safe delivery (public-IP-only, DNS-pinned, no redirects). **SMS/email deferred to v2.**
- **Notification settings** — `notify_decisions`, `notify_messages`, `notification_channels`,
  `push_enabled` gate creation and dispatch.
- **Harness bridge** — dedicated machine-to-machine API with a per-tenant bridge key
  (hashed at rest, rotatable, rate-limited) and **idempotent** decision/message endpoints.
- **Security** — VAPID keys from environment (never settings), secret redaction, member-restricted
  secret reads, blocked bootstrap password hashes, role gating everywhere.

---

## 3. Data model (owned tables)

| Table | Purpose |
|---|---|
| `harpp_users` | HARPP users (auth-owned shell: email, password_hash, role, tenant) |
| `harpp_password_resets` | Forgot/reset password tokens |
| `harpp_conversations` | Messenger conversations |
| `harpp_messages` | Messages (sender_type: user/harness/system) |
| `harpp_decisions` | Decision-requests + lifecycle state + workbench_state |
| `harpp_notifications` | In-app notifications |
| `harpp_push_subscriptions` | Browser Web Push subscriptions |
| `harpp_adrs` | Architecture Decision Records (auto-created on DECIDED) |
| `harpp_settings` | Per-tenant settings (secrets redacted; VAPID is env-only) |

Migrations: `001_harpp_core.sql`, `002_harpp_bootstrap_users.sql`, `003_harpp_phase3_domain.sql`.

---

## 4. Capabilities

**Exposes:** `kernel.auth.authenticate@1`, `harpp.read@1`, `harpp.manage@1`,
`harpp.decision.review@1`, `harpp.notify@1`, `harpp.bridge@1`, `harpp.bridge.authenticate@1`,
`harpp.settings.read@1`, `harpp.settings.manage@1`.

**Depends:** `kernel.audit.record@1`, `kernel.auth.user@1`.

Capability handlers live in `modules/harpp/helpers.php` (`harpp_capability_handlers()`).

---

## 5. Routes

**PWA pages** (auth-owned shell):

```
/harpp/login               /harpp/forgot-password      /harpp/reset-password
/harpp                     (messenger)                 /harpp/decisions
/harpp/decisions/{id}      /harpp/settings             /harpp/notifications
/harpp/sw.js               /harpp/manifest.webmanifest /harpp/icon.svg
```

**REST API** (user auth, JWT cookie):

```
/api/v1/harpp/auth/*               login, refresh, logout, forgot/reset-password,
                                   register, invite, select-tenant, profile
/api/v1/harpp/decisions            list/get + {id}/transition + {id}/apply-and-close (owner/admin)
/api/v1/harpp/conversations        list/create + {id}/messages + {id}/read
/api/v1/harpp/notifications        list + /unread-count
/api/v1/harpp/push/vapid-public-key
/api/v1/harpp/adrs
/api/v1/harpp/settings
```

**Harness bridge** (machine auth: `X-HARPP-BRIDGE-KEY` + `X-HARPP-TENANT-ID`):

```
POST/GET  /api/v1/harpp/bridge/decisions                create / list (filters)
POST      /api/v1/harpp/bridge/decisions/{id}/view        NOTIFIED → VIEWED (bridge)
POST      /api/v1/harpp/bridge/decisions/{id}/decide      VIEWED → DECIDED (owner decision via harness)
POST      /api/v1/harpp/bridge/decisions/{id}/acknowledge
POST      /api/v1/harpp/bridge/decisions/{id}/applied
POST/GET  /api/v1/harpp/bridge/messages                 send / poll (cursor)
POST      /api/v1/harpp/bridge/status                   harness status → notification
GET/POST  /api/v1/harpp/bridge/key                      owner-only: get / rotate bridge key
```

---

## 6. Prerequisites

- Ikabud Kernel with tenant modules + auth-owned module support
- PHP 8.1+ with **OpenSSL EC** (`openssl_pkey_new`, `prime256v1`), **cURL**, `pdo_mysql`
- **HTTPS** (required for Web Push + the PWA service worker)
- MySQL 5.7 / MariaDB (InnoDB, utf8mb4)
- No cron / no background workers required

---

## 7. Installation

### 7.1 Enable the module and run migrations

```bash
# From the repo root. Replace <tenant> with your tenant id/key/domain.
php ikabud tenant:migrate <tenant_id|tenant_key|domain> harpp
```

The three migrations create the tables and seed bootstrap users:

| Email | Role |
|---|---|
| `owner@harpp.local` | owner |
| `admin@harpp.local` | admin |
| `member@harpp.local` | member |

> All three bootstrap hashes are **blocked** — first login forces a password reset
> (`password_reset_required`). Set real passwords before use.

Verify:

```bash
php ikabud module:validate harpp
bash modules/harpp/tests/run-all.sh        # 212/212 assertions
```

### 7.2 Configure VAPID (Web Push) — required

```bash
php modules/harpp/bin/harpp-vapid --subject mailto:you@example.com --write
# writes HARPP_VAPID_PUBLIC_KEY / HARPP_VAPID_PRIVATE_KEY / HARPP_VAPID_SUBJECT into .env
# (self-verifies that public/private match; --json for scripts)
```

Or set the environment directly (Bluehost: add to the site env / `.env`):

```
HARPP_VAPID_PUBLIC_KEY=<base64url of 0x04||X||Y>
HARPP_VAPID_PRIVATE_KEY="-----BEGIN PRIVATE KEY-----\n...\n-----END PRIVATE KEY-----\n"
HARPP_VAPID_SUBJECT=mailto:you@example.com
```

### 7.3 Generate the bridge key (for the harness)

1. Log in at `/harpp/login` as owner → **Settings**
2. Generate the bridge key (raw value shown once) → give it to the local client
3. Optional: rotate any time (immediately invalidates the old key)

---

## 8. Using HARPP from your local harness

The local client lives in `tools/harpp-bridge/` (committed with the repo):

```bash
harpp config set base_url   https://yourdomain.com
harpp config set bridge_key <key from HARPP Settings>
harpp config set tenant_id  1
harpp check
```

**CLI / Pi:**

```bash
harpp decision submit --title "Blocked: nested extensions" --body "..." --priority high
harpp decision list --state PENDING
harpp decision view 12 && harpp decision decide 12 --decision "Option A"   # owner decided via messenger
harpp decision ack 12 && harpp decision apply 12
harpp msg send --body "tests green" && harpp msg poll --after 0
harpp status --message "running" --workbench-state IMPLEMENTING
```

**Standard watch loop (always-on when enabled):**

```bash
harpp watch --once                # catch-up scan: stages new owner input into .ai/harpp-inbox.jsonl
harpp watch --interval 60         # background daemon: same, plus auto-process + desktop notify
harpp inbox [--clear]             # read / clear staged owner input
```

The watch loop is the **standard behavior when HARPP is enabled**: when new owner input
arrives it (a) stages it to `.ai/harpp-inbox.jsonl`, (b) fires a desktop notification, and
(c) **auto-processes deterministically** (no LLM needed, cursor-deduped, logged to
`.ai/harpp-wake.log`):

- New **owner message** → the harness auto-replies an acknowledgment through the bridge
  (the owner gets an immediate confirmation push on their phone).
- Newly **DECIDED** decision → the harness auto-acknowledges + auto-applies it
  (closes the loop → CLOSED, durable ADR).

Run it detached as the standard daemon:

```bash
nohup setsid env python3 tools/harpp-bridge/harpp watch --interval 45 \
  > /tmp/harpp-watch.log 2>&1 < /dev/null &
```

Use `--no-autoprocess` to keep stage+notify only.

**Optional autonomous wake (mechanism E):** add `--wake` (or `HARPP_WAKE_ENABLED=1`) so that
unprocessed owner input spawns **one headless Pi agent** that replies semantically via the
bridge, acknowledges + applies decided decisions, and exits. Guarded by a single-flight lock
(stale TTL), cooldown (300s), max-per-hour (6), a hard timeout kill (900s), and an
idempotent processed-id ledger. On any failure it falls back to staging + notify — nothing
is dropped. Task contract: `tools/harpp-bridge/wake/task-contract.md`; log:
`~/.config/harpp/wake.log`.

```bash
HARPP_WAKE_ENABLED=1 harpp watch --interval 45          # full autonomous wake
harpp watch --once --wake --wake-command 'echo dry'     # dry-run of the spawn decision
```

**MCP (VS Code Copilot Chat):** add to `.vscode/mcp.json` (or user MCP config):

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

**Pi extension:** copy `tools/harpp-bridge/pi/harpp-tools.ts` to
`~/.pi/agent/extensions/harpp-tools.ts`, `/reload`, and the model gets the 7 `harpp_*` tools.

See `tools/harpp-bridge/README.md` for the full client reference.

---

## 9. Deployment to Bluehost (shared hosting)

1. **Package:** build the deploy archive (includes this module):

   ```bash
   php create-bluehost-archive.php
   ```

2. **Upload:** cPanel → File Manager → `public_html/` → Upload the `.zip` → **Extract**.
3. **Install the app:** visit `https://yourdomain.com/lock.php` → DB credentials + admin →
   the installer runs the schema, seeds, and generates `.env` (including HARPP tables).
4. **Enable the module + run HARPP migrations:**

   ```bash
   php ikabud tenant:migrate <tenant> harpp
   ```

5. **Set env / VAPID** (cPanel → MultiPHP INI or the app `.env`):

   ```
   HARPP_VAPID_PUBLIC_KEY=...
   HARPP_VAPID_PRIVATE_KEY="...\n..."
   HARPP_VAPID_SUBJECT=mailto:you@example.com
   ```

   (generate on the server with `php modules/harpp/bin/harpp-vapid --write` if SSH is enabled)
6. **Set the bridge key** (owner → Settings → generate).
7. **HTTPS / cURL / OpenSSL EC** must be enabled (standard on Bluehost).
8. **Delete `public/lock.php`** after install (mandatory).

---

## 10. Testing

```bash
bash modules/harpp/tests/run-all.sh        # all phase suites + module:validate + lint
                                           # fails (exit 1) if error.log is non-empty
```

---

## 11. Roadmap (future work)

Items below are deliberately out of scope for the current phase; each is a separate
reviewed decision before implementation.

### R-FTP — FTP deployment capability (module side)

**Driver:** operators deploy/patch files on shared hosts (e.g., Bluehost) over FTP and
want that driven from HARPP rather than by hand.

**Where FTP runs:** FTP execution stays on the **local machine** — the local client holds
the saved FTP profiles (host/port/user/secret/transport + optional path root) in its
secure store (`0600`/encrypted) and performs the upload + unarchive. The module never
stores or proxies FTP credentials.

**Module contributions (this README):**
1. **Bridge action endpoint** — a machine-authenticated deploy action (e.g.,
   `POST /api/v1/harpp/bridge/actions/ftp` or a dedicated `/bridge/ftp/*` surface) that the
   local client calls to: (a) declare an FTP **upload** of a local file to a saved profile
   path, and (b) issue the **unarchive** command over that FTP connection (site-specific
   verb, e.g. `SITE UNARCHIVE <file>`). The module records the request/outcome durably.
2. **Lifecycle/ADR integration** — an FTP deployment runs as a HARPP decision/action so the
   outcome is audited and a durable ADR is written (`harpp_adrs`), consistent with the
   existing `DECIDED → ACKNOWLEDGED → APPLIED → CLOSED` flow and the audit trail.
3. **Notification parity** — push/notification on completion or failure, honoring the
   existing `notify_decisions` / `notify_messages` gates.

**Security (module side):**
- FTP credentials are local-machine secrets only; the module sees a named profile
  reference, never the secret.
- The unarchive verb is allowlisted and profile-bound; remote filenames are validated —
  no arbitrary host/command combinations through the module.
- The action endpoint stays bridge-header-auth (`X-HARPP-BRIDGE-KEY`), idempotent, and
  rate-limited like the other bridge endpoints.

**Feasibility gate (before implementation):** a raw FTP `SITE` unzip/unarchive verb is
host-specific and NOT part of the FTP standard (RFC 959 leaves `SITE` as an opaque,
server-defined hook). Verify against the real target host (Bluehost/cPanel) before
building: if cPanel does not honor an FTP `SITE` unarchive command, pivot extraction to
cPanel's Archive Manager (UAPI `Fileman::extract_file`) and keep FTP upload-only, or
drop the unarchive step and notify "uploaded — extract in cPanel". Do not build an
FTP-`SITE` unarchive on an assumption.

**Acceptance sketch:** the owner saves an FTP profile on their machine, triggers an upload
of a build archive to a saved path, issues the unarchive command, and HARPP records the
success as a decision/ADR + push — with the FTP secret never crossing the module.

> Cross-cutting: the local client half of this roadmap is tracked in the standalone
> HARPP `ARCHITECTURE.md` roadmap as **R3 — FTP capability (saved FTP profiles: uploads +
> unarchive command)**; implement this module surface together with it.

Current coverage: 212/212 assertions across auth, decision lifecycle (incl. N×N transition
matrix), auto-ADR, messaging, Web Push/VAPID, PWA routes/assets, bridge auth/idempotency/rotation,
secret redaction, SSRF rejection, and audit-failure rollback.

---

## 11. Security notes

- VAPID key material is **environment-only** (never in settings; settings responses redact secrets).
- Bridge key stored **hashed** (SHA-256) with `hash_equals` validation; rotation is atomic.
- Push endpoints reject loopback/private/link-local/redirects and pin resolved DNS
  (`CURLOPT_RESOLVE`) to prevent SSRF.
- Decision transitions are fail-closed (illegal transitions → 409), with append-only audit.
- Bootstrap password hashes are blocked until a real password reset.

---

## 12. Reference

- Architecture/phase spec: `.ai/current-task.harpp.md`
- Local client: `tools/harpp-bridge/README.md`
- Reconnect pilot (resumable harness state): `.ai/current-task.reconnect-resume.md`
