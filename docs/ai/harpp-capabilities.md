# HARPP + RAG + MCP — Capabilities Reference

**Module**: `harpp` — Harness App (Decision Center & Messenger)
**Server**: `harpp-bridge` MCP server v1.4.0
**Status**: Live — capability inventory verified 2026-08-28 against test evidence.

**Author**: GPT-5.6 Sol (capability assessment)
**Created**: 2026-08-28
**Last updated**: 2026-08-28
**Related docs**: `docs/kernel/cli-tools-reference.md`, `docs/kernel/kernel-os-disyl-roadmap-status.md`

> HARPP is the governed decision layer that connects the local AI harness to operators:
> durable decisions + ADRs, a runner/work-queue engine, an approved-memory (RAG) keystone,
> artifact bundles, and an MCP bridge for machine/agent clients.

---

## 1. HARPP Module — Decision Center

### Decision lifecycle
```
CREATED -> PENDING -> NOTIFIED/VIEWED -> DECIDED -> ACKNOWLEDGED -> APPLIED -> CLOSED
```
Terminal states: `EXPIRED`, `SUPERSEDED`, `CANCELLED`.

- **Direct decisions**: owner/admin may decide directly from pre-decision states or close from non-terminal states; direct close records durable decision context.
- **`applyAndClose()`**: atomically performs `ACKNOWLEDGED -> APPLIED -> CLOSED`; already-closed requests are idempotent.
- **Optimistic concurrency**: decision updates use version checks; transitions are append-only.
- **ADR minting**: entering `DECIDED` (or a qualifying direct-close) atomically mints an ADR; approval-policy satisfaction is checked in the same transaction (`mintDecisionAdr` + `approvalSatisfied`).
- **Idempotency**: stable `decision_key` prevents duplicate decisions; message/outbox keys are idempotent.

### Approvals
- Approval policies with immutable snapshots, eligible reviewers, assignments, delegations, and recorded approvals.
- Quorum, creator/executor exclusion, and veto rules (see `HarppCollaborationPolicy`).

### Risk gating
- HIGH/CRITICAL run results are parked in `AWAITING_APPROVAL`.
- Promotion to `SUCCEEDED` requires a hashed one-time approval token; rejection cancels with rationale.

### Roles & access
- Roles: `owner`, `admin`, `member`.
- Members have restricted participation/review transitions and require an eligible policy snapshot to decide; management operations are owner/admin.
- Password surface: login, JWT cookie auth, user administration, soft delete, forced bootstrap-password replacement, forgot-password tokens, reset.

### Collaboration & messaging
- Workspaces, projects, memberships, conversation participants, receipts, audit events, idempotency keys, retention archives, governed purge.
- Notifications: preferences, read state, fan-out, Web Push subscriptions, durable outbox delivery + retries.

---

## 2. Runner / Work-Queue Engine

- One work run per owner message, initially `QUEUED` or `WAITING_FOR_RUNNER` per compatible runner availability.
- Run states: `QUEUED`, `WAITING_FOR_RUNNER`, `CLAIMED`, `RUNNING`, `STALLED`, `AWAITING_APPROVAL`, `SUCCEEDED`, `FAILED`, `CANCELLED`.
- Runner registration/heartbeat records capabilities + online status; stale heartbeats are marked offline.
- Capability-aware claiming uses opaque claim tokens and bounded leases; renewals require the active token.
- `recoverExpiredLeases()` recovers abandoned claims; `reconcileRuns()` aligns queued/stalled work with runner health.
- Terminal runs enter report delivery; `reportDelivered()` records success, `deadLetter()` records exhausted delivery, `dispatchRunReports()` retries with a five-attempt ceiling.
- Successful runs auto-build artifact bundles.
- Owner-visible fleet reporting returns runners, decoded capabilities, heartbeat timestamps, and live/offline health.

### Wake / supervisor (`harpp_wake.py`)
- Single-flight wake processing, stale-lock recovery, polling, process launch, workflow budgets, retries, escalation, resumable workflow manifests, supervisor behavior.
- Conversation context is fetched and inserted into task/quick-reply prompts with bounded size.
- Optional Wake-on-LAN validates MAC addresses and emits a UDP magic packet; work stays truthfully queued if waking is unavailable.

---

## 3. RAG / Memory Keystone (`HarppMemoryService`)

- **Approved-only retrieval**: searches tenant-local approved ADRs, decisions, and artifact bundles. Approved states: `DECIDED`, `ACKNOWLEDGED`, `APPLIED`, `CLOSED`.
- **Default = current only**; `include_historical=true` admits historical/unknown results marked non-authoritative.
- **Authority tiers**: `adr_current`, `decision_current`, `artifact`, `unknown`.
- **Status tiers**: `current`, `historical`, `unknown`. Current always ranks above historical/unknown; unresolved authority fails closed.
- **Supersession**: superseded ADRs, superseded/cancelled decisions, and their artifacts become historical.
- **Bounded & budgeted**: result limits are bounded; `budget_limit` 500–20,000 tokens (default 8,000); deterministic `token_estimate`; order-preserving `apply_budget()`.
- **`integrate()`**: supplies up to five short, current approved-memory snippets to the conversation context summary; excludes stale memory.
- **Context summary**: combines conversation state, messages, decisions, and integrated approved memory.
- **Visibility scoping**:
  - Owner sees all tenant memory.
  - Admin is excluded from another user's `private` decisions unless they created them or hold an active, unrevoked `private_grant`.
  - `actorAllowed()` is fail-closed (owner/admin only; members denied 403).
- **Tenant isolation**: inherited from the tenant-bound `ModuleDB`; tenant context required by bridge auth.

---

## 4. Artifact Bundles (`HarppArtifactService`)

- Types: `adr`, `decision`, `contract`, `stage_result`, `file`.
- `buildForDecision()` derives canonical ADR/decision artifacts from an approved/terminal decision.
- `buildForRun()` derives contract/stage-result artifacts from completed run data.
- `attachFile()` / `downloadFile()` for owner/admin file attach + authorized download.
- `createShare()` / `resolveShare()` / `revokeShare()` / `shareDownloadFile()`: addressed, expiring, revocable, view-only shares.
- Bundles are unique by source type/source ID and auto-built after approved decisions or successful approved runs.

---

## 5. MCP Bridge (`harpp-bridge` v1.4.0)

- Protocol: MCP `2024-11-05`, zero-dependency stdlib JSON-RPC over stdio.
- Auth: bridge key + tenant ID headers, HTTPS required; rate limited.

| Tool | Purpose |
|---|---|
| `harpp_submit_decision` | Create a decision/notification (idempotent `decision_key`) |
| `harpp_list_decisions` | List/filter decisions by state, priority, workbench state, limit |
| `harpp_acknowledge_decision` | Record harness acknowledgement (+ rationale) |
| `harpp_apply_decision` | Atomically apply + close an acknowledged decision |
| `harpp_send_message` | Post to an existing conversation (idempotent key) |
| `harpp_poll_messages` | Cursor-poll owner messages by conversation / `after` / limit |
| `harpp_post_status` | Post harness heartbeat/status, workbench state, session metadata |
| `harpp_get_run` | Return live run state, runner, status, result, delivery, risk |
| `harpp_list_runners` | Return the registered runner fleet + heartbeat health |
| `harpp_get_artifact_bundle` | Retrieve the approved decision's ADR/decision/files bundle |
| `harpp_get_decision` | Return full decision detail + ADR context |
| `harpp_memory_search` | Search approved memory (`q`, `limit`, `include_historical`, `budget_limit`) |
| `harpp_approve_run` | Promote a risk-gated run via one-time approval token |
| `harpp_reject_run` | Cancel a risk-gated run (required rationale) |

### Python client (`harpp_client.py`)
- Run/runner register/claim/renew/start/complete/fail, report/reconcile APIs, artifact/file/share APIs, deploy APIs.
- Config: environment/file precedence, authority levels L0–L4, workflow budgets, quiet/testing notification suppression, active workspace selection.
- Durable local delivery receipts (idempotency key/conversation/message IDs, no message bodies); context cache for bounded conversation summaries.

### Deployment (`harpp_deploy_ui.py` / `harpp_deploy_worker.py`)
- Localhost package/profile picker, package build, guarded execution, deployment receipts.
- Worker publishes inventory, claims phone-queued jobs, streams progress, executes deploys, reports final receipts.

---

## 6. Cross-Cutting

- **Auth model**: owner REST/UI via `harppAuthenticated('harpp.read@1')` + HARPP JWT/cookie; machine via `harppBridgeAuthenticated` + hashed/rotatable bridge API key + rate limiting.
- **Tenant isolation**: tenant-owned DBs; services additionally validate tenant scope and actor access.
- **MySQL 5.7-safe**: InnoDB, `utf8mb4_unicode_ci`, resumable/idempotent DDL, no CTEs or window functions.
- **Idempotency**: decision keys, message delivery, notifications/outbox, work-run creation, deploy claims/reports, artifact bundle creation, retry-safe lifecycle ops.
- **Durability / audit**: audit events, domain effects, outbox records, transition history, approval snapshots, deployment/run receipts.

---

## 7. Verified Status (2026-08-28)

| Suite | Result |
|---|---|
| `memory_search_cli_test` | 30/30 |
| `mcp_spine_cli_test` | 10/10 |
| `context_summary_cli_test` | 7/7 |
| Python bridge tests | 199/199 |
| decision-inbox browser journeys | 3/3 |
| `integrity_collaboration_contract_test` | 267 checks pass |

> **Note (2026-08-28):** `integrity_collaboration_contract_test` previously hard-coded 29 owned tables / migration `012`. It now contract-validates owned tables against the actual migration files and asserts the latest registered migration equals the newest migration file — so it cannot silently drift again.

---

## Quick command reference

```bash
# MCP server (stdio)
python3 tools/harpp-bridge/harpp_mcp.py

# Bridge self-test
python3 -m unittest tools.harpp-bridge.tests.test_harpp_bridge

# Key PHP suites
php modules/harpp/tests/memory_search_cli_test.php 1
php modules/harpp/tests/mcp_spine_cli_test.php 1
php modules/harpp/tests/context_summary_cli_test.php 1
php modules/harpp/tests/integrity_collaboration_contract_test.php 1

# Browser journey
npx playwright test tests/browser/modules/harpp/decision-inbox.spec.js
```
