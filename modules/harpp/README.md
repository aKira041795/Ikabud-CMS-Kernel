# HARPP — Harness App (Decision Center & Messenger)

> **HARPP = Harness App.** The human-in-the-loop decision bridge between an autonomous AI
> development harness (Pi, VS Code Copilot, MCP clients) and its operator — over your phone.
>
> The harness hits a decision point → HARPP raises a **decision-request** → you get a
> **Web Push** on your phone → you reply in the **messenger** (or decide in the PWA) →
> the harness polls, acknowledges, and applies → the decision is recorded as a durable **ADR**.

This is a first-class **Ikabud module** (auth-owned tenant module): it owns its database
tables, its auth shell, its settings, and its capabilities. It is MySQL 5.7 / shared-hosting
compatible. HARPP 2.0 uses an explicitly invoked, retryable outbox dispatcher for external
effects; domain commits never claim push or Kernel audit atomicity.

---

## 1. Overview

| | |
|---|---|
| Module id | `harpp` |
| Version | `2.1.0` |
| Kind | Auth-owned tenant module (own DB + auth shell) |
| Auth | JWT cookie (`harpp_token`), roles `owner` / `admin` / `member` |
| Storage | 26 owned `harpp_*` tables in the tenant database |
| Capabilities | 31 exposed, 2 depended (`kernel.audit.record@1`, `kernel.auth.user@1`) |
| UI | Installable PWA messenger (DiSyL) + Web Push |
| Harness bridge | REST API (`/api/v1/harpp/bridge/*`) consumed by the local client |

### Workspaces → local project folders (zero-config)

HARPP workspaces (and their projects/conversations) map to real folders on the
**local daemon machine** so the agent works in the right project directory:

- A workspace `key` resolves to `<projects_base>/<key>`, e.g. `ikabudsix` →
  `/var/www/html/ikabudsix`.
- `projects_base` defaults to the **parent of the configured `workspace`** — no
  extra config is needed on a new desktop. Override only when desired:
  `harpp config set projects_base /path/to/base`.
- The daemon **creates the folder automatically** (`ensure_workspace_dir`, no-clobber:
  creates if missing, reuses existing dirs, never overwrites) on first work in that
  workspace, and a throttled `provision_workspaces()` pass ensures every active
  workspace has its folder. Conversation-scoped agent runs execute **inside** the
  workspace folder.
- Setup on any new desktop is just the normal HARPP config
  (`harpp config set base_url|bridge_key|tenant_id|workspace …`), then start the
  daemon (`harpp watch --wake`). No manual folder/config/Python edits.

### The HARPP loop

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
  **Owner/admin shortcuts:** decide directly from any pre-decision state, and close directly
  from any non-terminal state (the ADR is created atomically either way — no step cycling needed).
- **Automatic ADR memory** — every `DECIDED` decision atomically writes a durable ADR row
  (`harpp_adrs`) with context, decision, rationale, actor, and timestamps.
- **Integrity foundation** — optimistic versions, immutable local audit, transactional outbox,
  idempotency records, archive-only ordinary deletion, and governed purge request/approval.
- **Collaboration foundation** — workspaces/projects, memberships, participant/private visibility,
  per-user receipts, assignments/watchers, policy snapshots/approvals, preferences, and fanout.
  Scope enforcement features are independently flagged for staged dual-read rollout.
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
| `harpp_deploy_jobs` | Phone-queued deploy requests + lifecycle state + receipt (R-FTP MVP) |
| `harpp_deploy_inventory` | Non-secret packages/profiles the local client registered |
| `harpp_runners` | Registered runner identities, capabilities, online status, heartbeat |
| `harpp_work_runs` | One durable work item per eligible owner message; state machine + lease + report delivery |
| `harpp_context_summary` | Bounded, versioned per-conversation memory read-model (title, history, run, decisions) |

The manifest is authoritative for all owned tables. Migrations `001`–`015` are ordered:
`013` adds the runner + work-queue schema, `014` adds reconciliation (attempt/stalled) and
report-delivery (delivery_attempts/last_delivery_error) columns, and `015` adds the
`harpp_context_summary` read-model.

---

## 4. Capabilities

**Exposes:** `kernel.auth.authenticate@1`, `harpp.read@1`, `harpp.manage@1`,
`harpp.decision.review@1`, `harpp.notify@1`, `harpp.bridge@1`, `harpp.bridge.authenticate@1`,
`harpp.settings.read@1`, `harpp.settings.manage@1`.

**Depends:** `kernel.audit.record@1`, `kernel.auth.user@1`.

HARPP 2.0 additionally exposes the Phase 0 integrity and Phase 1 collaboration capabilities
listed in `module.json`. See `docs/compatibility-matrix.md` and `docs/phase0-phase1-rollout.md`.

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

**Work-queue / runner / memory bridge** (machine auth; durable, tenant-scoped):

```
POST      /api/v1/harpp/bridge/runs                      queue one work item for an owner message
POST      /api/v1/harpp/bridge/runs/claim                runner claims a queued item (lease token)
POST      /api/v1/harpp/bridge/runs/{id}/running         mark started (attempt_count++)
POST      /api/v1/harpp/bridge/runs/{id}/renew           extend a lease
POST      /api/v1/harpp/bridge/runs/{id}/complete        → SUCCEEDED (report_state=PENDING)
POST      /api/v1/harpp/bridge/runs/{id}/fail            → FAILED
POST      /api/v1/harpp/bridge/runs/{id}/stall           → STALLED (dead child / no heartbeat)
POST      /api/v1/harpp/bridge/runs/reconcile            runner reports healthy set; others → STALLED
POST      /api/v1/harpp/bridge/runs/{id}/report/delivered       PENDING → DELIVERED
POST      /api/v1/harpp/bridge/runs/{id}/report/dead-letter     PENDING → DEAD_LETTER (+ error)
GET       /api/v1/harpp/bridge/runs/{id}                 owner-visible run status
GET       /api/v1/harpp/bridge/conversations/{id}/context  title + history + run + durable summary
POST      /api/v1/harpp/bridge/runners                   register / heartbeat a runner
```

Run lifecycle states: `QUEUED` → `WAITING_FOR_RUNNER` → `CLAIMED` → `RUNNING` →
(`STALLED` | `SUCCEEDED` | `FAILED` | `CANCELLED`). Report delivery is tracked
separately as `PENDING` / `DELIVERED` / `DEAD_LETTER` so execution outcome never
collapses into delivery state.

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

The ordered migrations create the tables and seed bootstrap users:

| Email | Role |
|---|---|
| `owner@harpp.local` | owner |
| `admin@harpp.local` | admin |
| `member@harpp.local` | member |

> All three bootstrap hashes are **blocked** — first login forces a password reset
> (`password_reset_required`). Set real passwords before use.

Verify:

```bash
php modules/harpp/tests/integrity_collaboration_contract_test.php
php modules/harpp/tests/strict-command-gate.php php ikabud module:validate harpp
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

## 8b. Work queue, runners, reconciliation, and conversation memory

The HARPP module is the **durable coordinator**. Files under `~/.config/harpp` on the desktop
may be caches or migration inputs only — they are never the sole authority for an accepted run.

### Offline semantics
- A work request submitted while no compatible runner is online is durably recorded as
  `WAITING_FOR_RUNNER` within normal request latency — never lost, never described as running.
- The web request never launches local models/shell processes. A runner claims work through the
  authenticated tenant-bound bridge using a **lease token, heartbeat, expiry, attempt count, and
  stable runner identity**.
- Exactly **one work item per eligible owner source message** (`uq_harpp_work_runs_source_message`),
  so replay/retry cannot create duplicate substantive replies.

### Lease recovery & child-process reconciliation
- An expired **never-started** claim (`CLAIMED`) is requeued for retry.
- An expired **RUNNING** run with retry budget is requeued; with the budget exhausted it is
  reconciled to `STALLED` (`stalled_at` set) — a dead child process is never left represented as
  `RUNNING` merely because its owner report never arrived.
- `POST /runs/reconcile`: the supervising runner reports its **healthy set** of run ids; any run
  it claimed that is not in the set is independently repaired to `STALLED` (covers watcher/runner
  restart and report-delivery loss).

### Report delivery is separate from execution
- Terminal runs set `report_state='PENDING'`; delivery advances it to `DELIVERED` or, after the
  attempt ceiling, to `DEAD_LETTER` with an inspectable `last_delivery_error`. Failed status
  delivery is retained and retried — never swallowed.

### Conversation memory (durable context)
- `harpp_context_summary` holds a bounded, versioned per-conversation digest: title, recent turns,
  active/latest run, and applicable durable decisions. It is derived only from the canonical
  `harpp_messages` / `harpp_decisions` / `harpp_work_runs` tables and versioned by the latest
  message aggregate sequence, so the client context cache invalidates on new content.
- `GET /conversations/{id}/context` returns title, history, run state, and the summary; the client
  caches it locally at `~/.config/harpp/context-cache/` (mode `0600`, bounded, version-invalidated).
- Every worker prompt receives this bounded, redacted context (title + recent turns + active run +
  applicable durable decisions), making "status?"/update answers conversation-specific.

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

# Work queue / reconciliation / report delivery / context memory (PHP CLI, no PHPUnit)
php modules/harpp/tests/runner_work_queue_cli_test.php 1
php modules/harpp/tests/runner_reconcile_cli_test.php 1
php modules/harpp/tests/context_summary_cli_test.php 1
```

Coverage includes: one-work-item-per-message; explicit `WAITING_FOR_RUNNER` when offline;
lease claim/renew/complete and invalid-token rejection; `reconcileRuns` stalling a dead child;
expired-lease recovery (retry vs `STALLED`); report delivery `PENDING → DELIVERED` and
dead-letter with inspectable error; and the context-summary read-model (run-N+1 decision reuse,
per-conversation isolation, version advance, bounded lists).

---

## 11. Roadmap (future work)

Items below are deliberately out of scope for the current phase; each is a separate
reviewed decision before implementation.

### R-FTP — FTP deployment capability (module side) — **MVP IMPLEMENTED (2.1.0)**

**Driver:** operators deploy/patch files on shared hosts (e.g., Bluehost) over FTP and
want that driven from HARPP while away from the machine.

**Where FTP runs:** FTP execution stays on the **local machine** — the local client holds
the saved FTP profiles (host/port/user/secret/transport + optional path root) in its
secure store (`0600`/encrypted) and performs the upload. The module never stores or
proxies FTP credentials; it sees only a named profile reference and non-secret metadata.

**MVP flow (implemented):**
1. The local client (`tools/harpp-bridge/harpp_deploy_worker.py`) publishes the deploy
   inventory (packages + profile names/hosts — no secrets) over the bridge and then
   polls for pending deploys.
2. The phone GUI (`/harpp/deploy`) lists that inventory, the operator picks a package +
   profile and confirms the deploy (an explicit second step shows the target host).
3. The module records a `harpp_deploy_jobs` row (`QUEUED`) and the local worker
   CAS-claims it (`CLAIMED`), resolves the real profile/package locally, runs
   `deploy_harpp.py`, and reports live steps (`UPLOADING → EXTRACTING → VERIFYING`) then
   a redacted receipt (`SUCCEEDED`/`FAILED`) back.
4. The operator gets a push + notification on completion/failure.

**Module surface (implemented):** user routes `/api/v1/harpp/deploys*` (CSRF + capability
`harpp.deploy.read/request@1`), bridge routes `/api/v1/harpp/bridge/deploys*` (machine-auth
`X-HARPP-BRIDGE-KEY`, capabilities `harpp.deploy.inventory/claim/report@1`), and a strict
append-only state machine with claim-token ownership + optimistic concurrency. Every
transition writes an immutable audit event + outbox entry via `HarppFoundationService`.

**Security (module side):**
- FTP credentials are local-machine secrets only; the module sees a named profile
  reference, never the secret. `harpp_deploy_inventory` carries host/transport/root/ops
  metadata only.
- Only profiles the operator explicitly marked `remote_allowed` locally appear in the
  phone GUI, and the worker refuses to execute a phone-triggered deploy for any other
  profile (plain-FTP risk gate is decided locally, never remotely).
- The bridge endpoints are bridge-header-auth, and deploys are idempotent, claim-token
  bound, and CAS-versioned.

**Deferred from the MVP (future):** formal decision/ADR lifecycle integration (a deploy
as a HARPP decision with quorum/policy), uploading a package *from* the phone, and remote
unarchive beyond `ssh_unzip`/`manual_cpanel`. The FTP `SITE` unarchive feasibility gate
still applies — see below.

**Feasibility gate (unarchive):** a raw FTP `SITE` unzip/unarchive verb is host-specific
and NOT part of the FTP standard (RFC 959 leaves `SITE` as an opaque, server-defined
hook). Verify against the real target host (Bluehost/cPanel) before building: if cPanel
does not honor an FTP `SITE` unarchive command, pivot extraction to cPanel's Archive
Manager (UAPI `Fileman::extract_file`) and keep FTP upload-only, or drop the unarchive
step and notify "uploaded — extract in cPanel". The MVP ships `ssh_unzip` (SSH available)
and `manual_cpanel` (upload + verify + extract in cPanel) — no FTP-`SITE` assumption.

> Cross-cutting: the local client half (profiles, worker, local UI) lives in
> `tools/harpp-bridge/`; the standalone distribution's R3 tracks its own packaging.

Static contract checks: **219** (integrity/collaboration/deploy) plus the phase suites and
the Python bridge + deploy-worker suites. Coverage spans auth, decision lifecycle (incl.
N×N transition matrix), auto-ADR, messaging, Web Push/VAPID, PWA routes/assets, bridge
auth/idempotency/rotation, secret redaction, SSRF rejection, audit-failure rollback, and
the R-FTP deploy slice (phone queue → local executor → receipt).

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
