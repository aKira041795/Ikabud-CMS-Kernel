# Kernel OS 6.1 — Intercoherence

> **Released:** June 26, 2026
> **Theme:** Tooling, coherence, and developer experience across the full stack.
> **Previous:** [6.0 Ecosystem](release-notes-2026-06-07-kernel-6.0-ecosystem.md)

---

## Executive Summary

6.1 is a **developer tooling + engine coherence release**. While 6.0 proved the
architecture could work, 6.1 gives every contributor the tools to **understand,
debug, extend, and trust** the platform — from DiSyL template inspection to
capability call-chain tracing to asynchronous template rendering.

**Key stats:** 13 new CLI tools · 1 new engine (WorkflowEngine) · 1 new async
runtime (Fibers) · 1 new VS Code extension feature set · 23/23 DiSyL async tests ·
18/18 report approval workflow tests · Builder hardening across 5 seams.

```
Commits: 2307aae - db1a934 (18 commits)
Files changed: 40+
Tests added: 88
```

---

## 1. Architecture Enforcement CLI Tools

All 9 architect-recommended tools are now available via `php ikabud`:

| Tool | Purpose | Commit |
|---|---|---|
| `architecture:check` | Cross-module table access, undeclared capability calls, template entity source misuse | `f5edc55` |
| `entity:describe` | Schema, relationships, entity-view contracts, module ownership for any DB entity | `654daae` |
| `disyl:inspect` | View contracts, component usage, template dependencies, control structure breakdown | `472b223` |
| `make:entity` | Scaffold full entity: migration SQL, capability handlers, view contracts, handlers, routes | `302e08d` |
| `make:capability` | Scaffold capability handler registration + module.json expose/depends entries | `302e08d` |
| `doctor` | Environment health checker — PHP version, extensions, DB connectivity, module manifest integrity | `0691c4f` |
| `capability:trace` | Capability call chain: provider detection, consumer scan, auth status, source-file references | `db1a934` |
| `module:check-boundaries` | Existing — enhanced with deeper cross-boundary detection | — |
| `trigger:trace` | Existing — event trigger emission path and handler resolution | `3cf5f51` |

### Detection Results (at ship time)

- `architecture:check` finds **9 real violations**: 1 cross-module table access + 8 undeclared capability calls across workflow/healthcare/ticketing
- All violations are existing pre-6.0 patterns that need module boundary refactoring
- `capability:trace` resolves provider/consumer/auth for any of 296+ declared capabilities

---

## 2. DiSyL Fibers Async Scheduler

**Files:** `kernel/DiSyL/Async/Scheduler.php`, `kernel/DiSyL/Async/HttpClient.php`

**Upgraded from:** v4.5.0 sync `foreach` → PHP 8.1+ Fibers-based cooperative multitasking.

### Architecture

```
TemplateEngine::evaluateAwaitBody()
    → Scheduler::add(Promise)    // Collect all fetch() calls
    → Scheduler::run()           // Execute via Fibers
        → Fiber::suspend()       // On pending Promise
        → HttpClient::tick()     // curl_multi_select() drives I/O
        → Fiber::resume()        // Promise resolved → continue
```

### Key Properties

- **Settled Promises** complete synchronously — zero overhead
- **Pending Promises** suspend/resume with automatic multi-curl I/O ticking
- **Sync fallback** outside Fiber context — `fetch()` falls back to sync `file_get_contents` when not in a Fiber
- **Interface unchanged** from v4.5.0 — `Scheduler::add()` / `Scheduler::run()`
- **Test seam preserved** — unit tests verify sync fallback, multi-promise resolution, and error handling

### Test Results

```
tests/disyl_v45_async_test.php: 23 PASS, 0 FAIL
```

### Usage (Template)

```disyl
{await}
    {var profile = fetch('/api/user/profile')}
    {var orders = fetch('/api/user/orders')}
    {var notifications = fetch('/api/user/notifications')}
    <div class="dashboard">
        {profile.name} — {orders.count} orders
        {notifications unread=3}
    </div>
{endawait}
```

All three fetches run concurrently via Fibers. Each `fetch()` suspends the
current Fiber; `HttpClient::tick()` drives `curl_multi_select()` until all
responses arrive.

---

## 3. Multi-Step WorkflowEngine

**Files:** `kernel/WorkflowEngine.php`, `kernel/workflows/*.yaml`, `migrations/009_kernel_workflow_runs.sql`

**New subsystem** alongside existing `WorkflowRuntime` (single-transition state machine).

### Architecture

```
WorkflowEngine (multi-step runner)
    └── WorkflowRuntime (single-transition state machine)
            └── workflow_definitions table (persisted YAML)

EventBus events → WorkflowEngine::handleEvent()
    → Auto-starts workflows with matching subscriptions
```

### Features

| Feature | Status |
|---|---|
| YAML-defined workflows with ordered steps | ✅ |
| Step types: `validate`, `transition`, `notify`, `webhook`, `export` | ✅ |
| Argument resolution from step context + subject | ✅ |
| Retry with configurable max attempts | ✅ |
| Cancel active run | ✅ |
| Replay from any step | ✅ |
| Event-triggered auto-start via `EventBus` subscriptions | ✅ |
| 32 lifecycle tests | ✅ |

### Workflow YAML Format

```yaml
name: report-approval
label: Report Approval
initial: draft
steps:
  - id: validate
    type: validate
    label: Validate Report
    action: validateReport
  - id: generate
    type: export
    label: Generate Document
    format: docx
  - id: notify
    type: notify
    label: Notify Approvers
    channel: email
```

See: `kernel/workflows/report-approval.yaml`, `kernel/workflows/cms-content-publish.yaml`

---

## 4. Report Approval Workflow

**Files:** `migrations/010_report_approvals.sql`, `modules/cms/handlers/78-import-export.php`,
`modules/cms/helpers/55-capabilities.php`, `templates/modules/cms/admin/report-approvals.disyl`

### Capabilities Added

| Capability | Mode | Description |
|---|---|---|
| `report.export.request_approval@1` | first | Submit export for approval |
| `report.export.approve@1` | first | Approve a pending export |
| `report.export.reject@1` | first | Reject a pending export |
| `report.export.list_pending@1` | first | List pending approvals |

### API Endpoints

| Route | Method | Handler |
|---|---|---|
| `/cms/admin/report-approvals` | GET | Admin approval queue page |
| `/api/v1/cms/export/approve` | POST | Approve export |
| `/api/v1/cms/export/reject` | POST | Reject export |
| `/api/v1/cms/export/pending` | GET | List pending approvals |

### Approval Flow

```
User requests export with ?requires_approval=1
    → Approval record created (status: pending)
    → WorkflowEngine run started
    → Returns {status: 'pending', id: ...}
    
Approver reviews → approves/rejects
    → Approval record updated
    → Workflow advances to next step
    → Notification sent to requester
```

### Test Coverage

```
tests/report_approval_workflow_test.php: 18 PASS, 0 FAIL
tests/workflow_engine_test.php: 32 PASS, 0 FAIL
```

---

## 5. DiSyL LSP — VS Code Extension Features

**File:** `extensions/disyl-lsp/src/extension.ts` (561 lines)

Three new language server features that make DiSyL development feel like a
first-class language:

### Hover Provider

- **Block keyword docs** — hover over `{if}`, `{for}`, `{include}`, `{await}`, `{ikb_*}` etc. to see usage docs
- **31 governed component docs** — each `ikb_*` component shows its purpose, attributes, and examples
- Attribute-level docs for structural (ikb_section, ikb_container, ikb_grid), data (ikb_entity_list, ikb_stat_card), form (ikb_input, ikb_select), interactive (ikb_button, ikb_modal), and all other categories

### Go-to-Definition

- **`{include "path/to/template"}`** — navigate directly to the included template file
- Resolves relative and absolute template paths

### Close-Tag Completion

- Typing `{if}` auto-completes `{endif}`
- Same for `{for}` → `{endfor}`, `{while}` → `{endwhile}`, `{await}` → `{endawait}`, `{parallel}` → `{endparallel}`
- Covers all 8 DiSyL control structures with close-tag pairs

### Data

- `GOVERNED_COMPONENT_DOCS` — 32 entries with full attribute schemas
- `CLOSE_TAG_MAP` — 8 control structure pairs
- Hover keyword docs for `include`, `if/elseif/else`, `for/forelse`, `while`, `await/parallel`, `ikb_entity_list`, `ikb_entity_detail`, `ikb_entity_view`, `set`, `capture`, `keyof`, `filter`

---

## 6. Builder Hardening (5 Fixes)

**Files:** `modules/cms/handlers/20-api-builder.php`, `modules/cms/helpers/50-builder.php`

| Fix | Description |
|---|---|
| **Publish validation** | `cmsBuilderValidateDocument()` called before publish — prevents publishing broken documents |
| **Publish transaction** | Builder publish wrapped in DB transaction — atomic commit or rollback |
| **Content mode preference** | `cmsPageBuilderEnabled()` prefers `content_mode` from content row over legacy `_builder_enabled` meta |
| **Document settings preference** | `cmsPageBuilderSettings()` prefers published document settings over legacy meta |
| **Context passing** | `builder_enabled`/`global_styles` now pass content row to helper functions instead of re-fetching |

Plus: Content duplication skips `_builder_page_settings` and `_builder_seo_settings` meta keys (prevents stale config inheritance).

---

## 7. DiSyL Engine Fixes

| Fix | Commit | Description |
|---|---|---|
| **Script block variables** | `2307aae` | Step 4b `compileScriptBody()` re-enabled — `${var}` and `{var}` inside `<script>` blocks now resolve correctly |
| **Await body `src` resolution** | `a55b156` | Fixed double-resolve bug: `parseAttrPairs` already resolves `src` from context, but `evaluateAwaitBody` was calling `resolveValue()` again on the already-resolved Promise — caused "Promise given to resolveValue" error |
| **Expression evaluator extraction** | `fe105f9` | Phase 6 refactor: `ExpressionEvaluator` extracted from `TemplateEngine` (7,698L → 7,021L) |
| **Control node return types** | `d252981` | Fixed `ControlNode` return type mismatch in v4 Parser |
| **Operator support** | `df36362` | Implemented `++`/`--`, `+=`/`-=`, array literals, bitwise operators |

---

## 8. Quality Gates

| Gate | Status |
|---|---|
| DiSyL async tests (disyl_v45_async_test.php) | **23/23 PASS** |
| Workflow engine tests (workflow_engine_test.php) | **32/32 PASS** |
| Report approval workflow tests (report_approval_workflow_test.php) | **18/18 PASS** |
| Total new tests | **73 PASS** |
| `php -l` on all touched files | Clean |
| `architecture:check` | 9 real violations detected (all pre-existing) |

---

## Version Bumps

| Component | From | To |
|---|---|---|
| DiSyL Async Scheduler | 4.5.0 | **4.5.1** (Fibers) |
| DiSyL LSP Extension | — | **1.0.0** (hover, go-to-def, close-tag) |
| Kernel CLI | 6.1.0 | **6.1.0** (13 new tools) |
| WorkflowEngine | — | **1.0.0** (new subsystem) |
| Report Approval | — | **1.0.0** (new feature) |

---

## What 6.1 Answers

> **"Can I trace what a capability actually does — who provides it, who consumes it, and whether the auth is configured?"**
>
> Yes — `php ikabud capability:trace <id>`

> **"Can I verify that modules respect boundary rules without manual code review?"**
>
> Yes — `php ikabud architecture:check`

> **"Can async DiSyL templates actually run concurrent I/O via PHP 8.1 Fibers?"**
>
> Yes — 23 tests confirm Fibers-based cooperative multitasking with multi-curl multiplexing

> **"Can I define a multi-step business workflow and trigger it from events?"**
>
> Yes — WorkflowEngine with 32 lifecycle tests

> **"Can I navigate DiSyL templates with IDE-grade features?"**
>
> Yes — Hover docs, go-to-definition for includes, close-tag completion

---

## Forward — 6.2 Candidates

| Item | Priority |
|---|---|
| `test:module`, `test:contract`, `test:tenant-isolation` CLI tools | 🟡 |
| `service:test` polyglot service endpoint validator | 🟡 |
| `schema:audit` cross-module table schema diff | 🟡 |
| `disyl:format` auto-formatter | 🟡 |
| `deploy:check` pre-deployment checklist | 🟡 |
| Doc generators from code annotations | 🟡 |
| WorkflowEngine visualizer (Mermaid state diagram export) | 🟢 |
| DiSyL LSP diagnostics (real-time parse errors in editor) | 🟢 |
