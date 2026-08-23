# Kernel OS 6.x — Implementation Status

> **Release: 6.1.0 (intercoherence)** | Assessment: June 26, 2026 (updated August 5, 2026)
> Source roadmap: `kernel_os_disyl_consolidated_roadmap.md`
> Legend: ✅ Done · 🟡 Partial · 🔴 Not started

---

## Executive Summary

Kernel OS 6.1 is a **tooled, coherent, observable, async-capable, IDE-supported
business operating system**. All 9 architect-recommended CLI tools are live.
The DiSyL runtime can resolve independent asynchronous HTTP operations concurrently through a Fiber-based scheduler and multi-curl transport. A multi-step
WorkflowEngine orchestrates business processes with event-triggered auto-start.
The DiSyL LSP extension brings IDE features (hover, go-to-def, close-tag) to
VS Code. Builder hardening closes 5 seams. Report approvals are end-to-end.

**502+ tests pass** (429 pre-6.1 + 73 new), 0 linter errors.

---

## Quick Reference — What Ships in 6.1

| Component | Version | File |
|---|---|---|
| Kernel OS | `6.1.0` (intercoherence) | `kernel/App.php` |
| DiSyL | `4.7.0` | `kernel/DiSyL/Grammar.php` |
| TemplateEngine | `6.1.0` | `kernel/DiSyL/TemplateEngine.php` |
| Async Scheduler | `4.5.1` | `kernel/DiSyL/Async/Scheduler.php` |
| HttpClient | `4.5.1` | `kernel/DiSyL/Async/HttpClient.php` |
| WorkflowEngine | `1.0.0` | `kernel/WorkflowEngine.php` |
| WorkflowRuntime | `1.0.0` | `kernel/WorkflowRuntime.php` |
| DiSyL VS Code Extension | `1.0.0` | `extensions/disyl-lsp/src/extension.ts` |
| CLI tools (9 new) | `6.1.0` | `ikabud` |
| Parser (v4) | `4.7.0` | `kernel/DiSyL/v4/Parser.php` (per-block error recovery) |
| ComponentRegistry | `1.0.0` | `kernel/DiSyL/ComponentRegistry.php` |
| EntityViewResolver | `1.0.0` | `kernel/EntityContext/EntityViewResolver.php` |
| ServiceProxy | `1.0.0` | `kernel/Capabilities/ServiceProxy.php` |
| ExpressionEvaluator | `1.0.0` | `kernel/DiSyL/ExpressionEvaluator.php` |

---

## Updates Since 6.1.0 (August 5, 2026)

> Everything below landed on top of the 6.1.0 baseline and does not change the
> version numbers above. See `docs/releases/release-notes-2026-08-05-cms-akira-product-suite.md`
> for the full release note.

| Area | What landed |
|---|---|
| **CMS Akira suite** | CMS decomposed into the `cms-akira` product suite — 14 submodules (`core`, `seo`, `ai`, `editor`, `theme`, `navigation`, `workflow`, `search-adapter`, `media`, `builder`, `profile-minimal/standard/visual/headless`) under `modules/cms-akira/`. See `modules/cms-akira/README.md`. |
| **Additive suite manifest fields** | New **additive** manifest fields: `suite`, `kind`, `extends`, `extension_points`, `contributes`, `admin_contributions`, `compatibility`, `uninstall`. Legacy (schema-v1) manifests remain valid; `MODULE_MANIFEST_SCHEMA_VERSION` stays `'1'`. |
| **Product-suite extension architecture (C12/C13)** | Accepted 2026-08-04 in `docs/architecture/product-suite-extension-adr.md`. Suite certification implemented via `validateModuleSuiteContractV1()` in `src/helpers/manifest-validation.php` (invoked from `src/helpers/module-manager.php`); `php ikabud module:certify` runs the checks. |
| **CMS admin-shell conversion** | Akira suite modules render inside the CMS admin shell. |
| **Dynamic admin sidebar** | The admin sidebar is driven dynamically from `admin_contributions` — contributions carry `{host, location, group, label, icon, route, permission, order}` and register against declared extension points (e.g. `cms.sidebar`). |
| **DiSyL JSON function calls** | `{json_encode(...)}` and `{json_decode(...)}` supported at engine level (`kernel/DiSyL/v4/FunctionRegistry.php`); `json_encode` mirrors `JSON_UNESCAPED_SLASHES \| JSON_UNESCAPED_UNICODE`, `json_decode` returns an assoc array with dot-path access via `kernel/DiSyL/ExpressionEvaluator.php`. |

---

## Phase 1 — Kernel + DiSyL Foundation ✅

All April 2026 audit items resolved. Compiled mode is now the **default** (v4.7+) with lazy one-shot boot; component-tag templates auto-fallback to interpreted. Linter scans 398 templates with 0 errors. JWT algorithm validation, event caching, and dead code cleanup complete.

**DiSyL 4.7 improvements (June 19, 2026):**
- Compiled mode default (was opt-in `enableCompiledMode()`)
- Per-block parser error recovery (`recoverableParse()` wrapper on all 9 control structures)
- TemplateEngine split: `DefaultEntityRenderer` extracted (composable services replacing the trait)
- `EntityRenderingTrait` fully removed in 6.1.0 — rendering via `DefaultEntityRenderer` + `CellRendererRegistry`
- Grammar v11 ([archived plan](disyl-grammar-v11-planned-types.md)) — dead code removed, not an active roadmap target
- Grammar.php: 199 → 288 lines (+89; tightened keyword registries, added type validation, platform identifiers, and `keyof` support)

See: [April 2026 technical audit](docs/evaluations/kernel-disyl-architecture-evaluation-2026-04-15.md)

---

## Phase 2 — Shared Component Registry ✅

32 governed DiSyL components built and registered with full attribute schemas:

| Category | Components |
|---|---|
| Structural | `ikb_section`, `ikb_container`, `ikb_grid`, `ikb_panel` |
| Data | `ikb_entity_list`, `ikb_entity_detail`, `ikb_stat_card`, `ikb_timeline`, `ikb_audit_log`, `ikb_table`, `ikb_badge` |
| Form | `ikb_form`, `ikb_input`, `ikb_textarea`, `ikb_select` |
| Interactive | `ikb_button`, `ikb_export_button`, `ikb_confirm_action` |
| Layout | `ikb_card`, `ikb_modal`, `ikb_drawer`, `ikb_alert`, `ikb_spinner` |
| Content | `ikb_text`, `ikb_image`, `ikb_icon`, `ikb_link` |
| Report | `ikb_report`, `ikb_signature_block` |
| AI | `ikb_ai_summary`, `ikb_ai_assist` |

---

## Phase 3 — Entity-View-First Architecture ✅

| Deliverable | Status |
|---|---|
| `EntityViewResolver` — source parsing, view contracts, capability dispatch | ✅ |
| ContextRegistry Phase 3B stores (registerSchema/Profile/Mode) | ✅ |
| `app()->entityViews()` accessor | ✅ |
| CMS module adoption — 13 view contracts across 5 content types | ✅ |
| Built-in defaults for orders/products/cases/ledger/appointments/tickets | ✅ |
| Capability ID normalization (`cms.post` → `cms_post`) | ✅ |
| `entity.list` + `entity.get` handlers in CMS | ✅ |
| **Guidance module entity view POC** — 2 templates, source naming fix, HTMX forwarding | ✅ |

**Proven:** `tests/cms_integration_poc.php` — 25/25 assertions covering full
pipeline: DB → capability bus → entity resolver → DiSyL rendering.

**Hardened (June 19, 2026):**
- Result normalisation: `resolve()` now accepts `rows`, `data` envelope, and bare array-of-arrays — `isListOfAssocArrays()` helper added
- All 8 modules now expose `entity.list`/`entity.get` in `module.json`
- Ecommerce product handlers rewritten to `cms_content` (type=product); WMS stock handlers fixed to `wms_stocks` (plural)
- `renderEntityList` logs zero-row diagnostic when data resolves but returns empty
- TemplateEngine default view fallthrough fixed: missing `$actionLabels` parameter restored
- Custom cell renderers: `badge`, `badge:map`, `money:N`, `datetime`, `boolean`
- DELETE actions via POST with auto-injected CSRF tokens
- Header slot for inline forms/filters above entity lists
- `action_show_if` conditions + `action_labels` for row actions
- Entity rendering extracted to `DefaultEntityRenderer` (TemplateEngine: 6478 → 6792 lines; trait deleted in 6.1.0)
- **DiSyL v4.7 engine hardening (June 23):** HTML-style `<ikb_>` tag detection with friendly error; unknown component name check (`renderUnknownComponent`); shorthand `{var = expr}` set syntax; field renderer + view name validation at config load time
- **DiSyL engine improvements (June 24):**
  - **`loadViewConfigs` error surfacing** — log level changed from `warning` to `error` for parse failures; new `getLastLoadErrors()` static method returns per-file error details; throws `RuntimeException` with summary when any file has errors — no more silent contract registration failures
  - **`validateViewContract()`** — validates every `{ikb_entity_view}` before registration, checking duplicate field names, duplicate semantic role assignments (`role="title"` on two fields), and action URL placeholders (`{id}`, `{slug}`) not matching any declared field
  - **`renderUnknownComponent` closest-match suggestion** — when an unknown `ikb_*` component is encountered, uses `levenshtein()` to find the closest match from 32 governed components and suggests it in the error (e.g., "Did you mean 'ikb_card'?")
  - **`keyof` runtime expression** — `{keyof entity_type.view}` resolves to field list from registered entity view contracts. Supports filters (`| json`, `| join`), `{for}` iteration, and entity-list field validation. (June 24)
- **`{ikb_entity_detail}`** — single-entity rendering now working; `resolveDetail()` unwraps `data` key from capability result; `renderDetail()` handles array-type `$attrs['fields']` correctly; default container padding `px-4 py-2`
- **`filter` attribute** on `{ikb_entity_list}` — comma-separated `key=value` pairs with `{var.path}` context resolution; passed as `$overrides['filters']` to `EntityViewResolver::resolve()`
- **`RowRenderContext`** value object — 15 shared params (including `roleFields`) consolidated across `renderCompactRow/CardGridRow/TableRow/RowActions`
- **PAL module** — 10 list templates + 4 new entity types migrated; expense detail uses `{ikb_entity_detail}`

---

## Phase 4 — Theme & Design Tokens ✅

| Deliverable | Status |
|---|---|
| `theme.manifest.json` — formal theme contract (colors, typography, spacing, radius, shadows, component variants) | ✅ |
| `tokens.json` — CSS custom-property token definitions | ✅ |
| `ikb_panel` — semantic token component (tone/spacing/radius) | ✅ |
| `cmsThemeManifestForSlug()` reads `theme.manifest.json` + `tokens.json` | ✅ |

---

## Phase 5 — Reporting & Export ✅

| Deliverable | Status |
|---|---|
| `KernelExport` service — CSV + DOCX (PHPWord), handler registry, wildcard defaults | ✅ |
| `ikb_export_button` — governed download link with format/variant/size | ✅ |
| `/api/v1/export?source=&format=` route — resolves via EntityViewResolver → KernelExport → streams file | ✅ |
| `ikb_report` + `ikb_signature_block` components | ✅ |
| `KernelExport::registerDefaults()` called during kernel boot | ✅ |

---

## Phase 6 — AI-Safe DiSyL Blocks ✅

| Deliverable | Status |
|---|---|
| AI Policy engine (kill switch, model allowlist, cost ceiling, token cap) | ✅ |
| `ikb_ai_summary` — governed summarization with review badge | ✅ |
| `ikb_ai_assist` — governed drafting (draft_only/suggest modes) | ✅ |
| `OpenAiProvider` — reads `OPENAI_API_KEY` from env, falls back to Echo | ✅ |
| CMS AI content automation (plans/runs tables, auto-publish/refine) | ✅ |

---

## Phase 7 — Visual Builder Contract Composer ✅

| Deliverable | Status |
|---|---|
| React/Vite builder rebuilt (728 KB, 1,461 modules) | ✅ |
| `cmsRenderWidget_entity_list` → delegates to `ikb_entity_list` | ✅ |
| `GET /api/v1/cms/builder/components` — governed component catalog API | ✅ |
| Governed component palette ("Governed" tab in builder) | ✅ |
| Entity source picker — dropdown of registered entity-view sources | ✅ |
| View contract picker — dynamic per-source with field preview | ✅ |
| Contract validation before save — source, view, capability checks | ✅ |
| Permission role preview — toggle admin/editor/author/subscriber/guest | ✅ |
| Empty/error/loading state preview toggles | ✅ |
| Export button format config — CSV/DOCX/PDF, variant, size | ✅ |
| Report approval workflow integration | ✅ |
| AI block config — mode (draft_only/suggest/auto_publish), review badge, redaction | ✅ |
| Save contract patterns for reuse across pages | ✅ |
| Theme token guidance → Global Styles panel | ✅ |
| Builder composes arbitrary DiSyL contracts | ✅ |

---

## Phase 8 — Polyglot Service Modules ✅

| Deliverable | Status |
|---|---|
| `"type": "service-module"` manifest validation | ✅ |
| `loadModuleHelpers()` skips PHP helpers for service modules | ✅ |
| `ServiceProxy` — HTTP proxy callable, drop-in for `CapabilityRegistry::register()` | ✅ |
| Module-manager auto-registers `ServiceProxy` for service-module capabilities | ✅ |
| Capability wire protocol: `POST /capability/call` with JSON | ✅ |
| Circuit breaker + retry inherited from `CapabilityBus` | ✅ |
| Auth token resolution from env (`service.auth.token_env`) | ✅ |
| Test seam via `setHttpHandler()` for offline unit testing | ✅ |
| Example manifest: `modules/ai-orchestrator/module.json` | ✅ |
| **Real polyglot service: Python weather-service** | ✅ |

### Phase 8 E2E Proof (2026-06-08)

The polyglot pipeline is proven end-to-end with the Python weather-service:

```
PHP DiSyL Template (weather-public.disyl)
  → ikb_entity_detail / ikb_entity_list
    → TemplateEngine::renderEntityDetail() / renderEntityList()
      → EntityViewResolver::resolve() [timeout_ms: 10000]
        → CapabilityBus::call('entity.get.weather_current@1')
          → ServiceProxy → HTTP POST /capability/call
            → Python Flask (port 9002) → wttr.in
              ← JSON {ok:true, data:{city, temperature_c, ...}}
            ← CapabilityBus returns data
          ← EntityViewResolver returns resolved entity
        ← TemplateEngine renders HTML card
      ← DiSyL outputs final HTML
```

**Key findings & fixes:**
- `timeout_ms: 10000` required in both `EntityViewResolver::resolve()` and `TemplateEngine::renderEntityDetail()` for polyglot calls exceeding the default 2000ms
- Wildcard `*` field expansion added to `TemplateEngine::renderEntityList()` — auto-detects field keys from first result row when no explicit view contract is found
- Entity detail works even without explicit view contracts (falls back to `array_keys($entity)`)
- Weather entity type added to `EntityViewResolver` `builtinDefaults`
- Service must be running on expected port; `Failed to connect to 127.0.0.1 port 9002` means the service process died
| CMS entity-view integration with polyglot data source | ✅ |

**Proven:** `tests/polyglot_weather_test.php` — 17/17 assertions covering
PHP → ServiceProxy → HTTP → Python → wttr.in with real weather data.
`tests/cms_weather_e2e.php` — 15/15 assertions covering the full
CMS → EntityViewResolver → CapabilityBus → ServiceProxy → Python pipeline.
`tests/service_proxy_test.php` — 20/20 unit tests for ServiceProxy,
including HTTP error handling, invalid JSON, circuit breaker config, and
CapabilityBus integration.

**To add a new polyglot service (any language):**
1. Implement `POST /capability/call` accepting `{capability_id, payload, caller}`
2. Return `{"ok": true, "data": {...}}` or `{"ok": false, "error": "..."}`
3. Create `module.json` with `"type": "service-module"` and `service.endpoint`
4. Drop in `modules/<name>/` — the module-manager auto-registers it

See: [Polyglot Service Developer Guide](polyglot-service-guide.md)

---

## Phase 9 — Marketplace & Ecosystem ✅

| Deliverable | Status |
|---|---|
| `validateModuleCertification()` — 10-point checklist | ✅ |
| `php ikabud module:certify [module|--all]` CLI command | ✅ |
| `GET /api/v1/cms/marketplace/catalog` — module catalog with cert scores | ✅ |
| Major production modules pass 9/9 certification (CMS, bakeshop, guidance, wms, ecommerce) | ✅ |
| Module scaffolding: `make:module`, `make:service-module`, `make:example` | ✅ |
| Example modules: hello-world (PHP), random-facts (Python), weather-service (Python) | ✅ |
| Developer SDK: Polyglot Service Guide, Module Development Guide, Quickstart | ✅ |
| Compatibility matrix via certification checks | ✅ |
| Marketplace backend + certification | ✅ |
| Public marketplace UI (catalog/browse/install UX) | 🔴 Deferred to 7.x |

> **Note:** The ecosystem backend is complete — module certification, catalog API, compatibility matrix, and developer SDK all ship. Product decision: the public marketplace storefront experience is deferred to 7.x.

---

## Quality Gates

| Gate | Status |
|---|---|
| 308 regression tests (264 engine + 44 hardening) | ✅ |
| 25 CMS integration POC tests | ✅ |
| 20 ServiceProxy unit tests | ✅ |
| 17 polyglot weather E2E tests | ✅ |
| 15 CMS weather entity-view E2E tests | ✅ |
| 23 DiSyL async Fibers tests | ✅ |
| 32 WorkflowEngine lifecycle tests | ✅ |
| 18 report approval workflow tests | ✅ |
| Linter: 0 errors across 398 templates | ✅ |
| Load test: 22ms for 100 iterations across 6 paths | ✅ |
| 4 critical bugs fixed (capabilities()->call → cap()->call, content_type → type, module() guard, log level) | ✅ |
| Version bumped: 6.0.0 → 6.1.0 | ✅ |
| error.log: clean | ✅ |
| loadViewConfigs throws on parse errors | ✅ |
| validateViewContract() duplicate/role/URL checks | ✅ |
| renderUnknownComponent levenshtein suggestion | ✅ |

---

## Production DiSyL Component Adoption

| Template | Components |
|---|---|
| `templates/modules/cms/admin/dashboard.disyl` | `ikb_stat_card` (4×), `ikb_entity_list` |
| `templates/modules/cms/admin/content-list.disyl` | `ikb_confirm_action`, `ikb_export_button` |
| `templates/modules/guidance/pages/dashboard.disyl` | `ikb_panel`, `ikb_entity_list` |
| PAL module (10 templates) | `ikb_entity_list`, `ikb_entity_detail`, `ikb_stat_card` |
| Guidance case views | `ikb_entity_list`, `ikb_entity_detail` |
| Report approval queue | `ikb_table`, `ikb_button`, `ikb_confirm_action` |

---

## What Remains — Current

| Item | Priority |
|---|---|
| Phase 9: Public marketplace UI (catalog/browse/install UX) | 🔴 Deferred |
| Remaining module certification fixes (20/41 pass) | 🟡 Mechanical |
| Real AI provider in production | 🔴 Needs API keys |
| Service health active alerts / historical uptime | 🟡 Nice to have |
| loadViewConfigs throws on parse errors with per-file diagnostics | ✅ |
| validateViewContract() — duplicate fields, roles, URL placeholders | ✅ |
| renderUnknownComponent levenshtein suggestion | ✅ |
| 11 DiSyL view contracts for attendance-wage (migrated from builtinDefaults) | ✅ |

---

## Strategic Position (Post-6.0)

Kernel OS 6.0 is an **architectural graduation release**. The question has shifted
from "can it work?" to "can it be operated, explained, trusted, and extended safely?"

The next phase is not more modules — it's coherence:

> **From architecture proof to operating discipline.**

### Doctrine

> **PHP is the kernel host. Capabilities can live anywhere.**
>
> Python → AI, analytics, OCR, forecasting
> Node → realtime collaboration, websockets, builder preview
> Go → high-throughput workers, sync engines
> Rust → security-sensitive or high-performance utilities
> PHP → CMS, admin, business modules, reports, standard workflows
>
> All obey Kernel OS through the capability protocol.

### The Platform Shape

```
PHP Kernel OS
    governs identity, modules, capabilities, rendering, policy

DiSyL
    expresses human-readable interface intent

CapabilityBus
    resolves business actions and data sources

ServiceProxy
    allows any language to fulfill capabilities

EntityViewResolver
    connects business data to UI contracts

ComponentRegistry
    turns contracts into governed UI
```

---

## Forward Roadmap

### Kernel OS 5.1 — Hardening + Observability ✅
**Theme:** Make 5.0 trustworthy in real operations.

| Priority | Status |
|---|---|
| Service health dashboard | ✅ |
| service-module status in superadmin | ✅ |
| ServiceProxy logs and diagnostics | ✅ |
| Signed internal service calls | 🟡 |
| Stricter service timeout rules | ✅ |
| Circuit breaker visibility | ✅ |
| Polyglot service error viewer | ✅ |
| External dependency isolation in tests | 🟡 |
| Certification fixes for remaining modules | 🟡 |
| Stronger export permission checks | 🟡 |
| AI audit log review | 🟡 |
| Capability call trace viewer | ✅ |
| Entity-view debug panel | ✅ |

**Answers:** \"When something fails, can I see where and why?\" — **Yes.**

### Kernel OS 5.2 — Visual Builder Contract Release ✅
**Theme:** Make DiSyL usable by builders, not just developers.

| Priority | Status |
|---|---|
| React builder component palette | ✅ |
| Visual component inspector | ✅ |
| Source picker for entity views | ✅ |
| View picker for registered contracts | ✅ |
| Live preview of DiSyL contracts | ✅ |
| Validation before save | ✅ |
| Permission-aware preview | ✅ |
| Empty/error state preview | ✅ |
| Export button configuration | ✅ |
| AI block configuration | ✅ |
| Theme token controls | ✅ |
| Saved section patterns | ✅ |

**Answers:** \"Can a human compose governed business screens without writing code?\" — **Yes.**

### Kernel OS 5.3 — Reporting + Business Output ✅
**Theme:** Make documents and reports a core selling point.

| Priority | Status |
|---|---|
| PDF support | ✅ |
| Report template manager | ✅ |
| Scheduled reports | ✅ |
| Report approval workflows | ✅ |
| Signature block presets | ✅ |
| Report archive | ✅ |
| Export audit logs | ✅ |
| Report permissions | ✅ |
| Module-specific report packs | ✅ |
| DOCX/PDF/XLSX consistency tests | ✅ |

**Answers:** \"Can businesses run official paperwork through Kernel OS?\" — **Yes.**

### Kernel OS 5.4 — AI Governance ✅
**Theme:** Make AI useful, safe, and auditable.

| Priority | Status |
|---|---|
| Real provider configuration UI | ✅ |
| Tenant-level AI settings | ✅ |
| Per-capability AI policy | ✅ |
| Token/cost usage dashboard | ✅ |
| Prompt template registry | ✅ |
| Redaction rules | ✅ |
| Review queue for AI drafts | ✅ |
| AI output audit trail | ✅ |
| AI provider fallback behavior | ✅ |
| AI capability certification | ✅ |

**Answers:** \"Can AI help without becoming a risk?\" — **Yes.**

### Kernel OS 6.0 — Ecosystem Release ✅
**Theme:** Make Kernel OS extendable by others.

| Priority | Status |
|---|---|
| Marketplace backend complete; public storefront deferred | 🔴 |
| Module certification dashboard | ✅ |
| Module install/update flow | 🟡 |
| Compatibility matrix | ✅ |
| Service-module templates | ✅ |
| Developer SDK | ✅ |
| Module scaffolding improvements | ✅ |
| Example modules | ✅ |
| Official docs site | ✅ |
| DiSyL VS Code Extension | ✅ |
| Test harness for third-party modules | ✅ |

**Answers:** "Can other developers build through governed scaffolding, certification, inspection, and test tools?" — **Yes. Full safety still depends on resolving current architecture violations and enforcing CI gates.**

---

## Kernel OS 6.1 — Intercoherence ✅
**Release:** June 26, 2026
**Theme:** Developer tooling, engine coherence, polyglot async, architectural enforcement.

### Architecture Enforcement CLI (9 tools)

| Tool | Purpose | Status |
|---|---|---|
| `architecture:check` | Cross-module table access, undeclared capability calls, template entity source misuse | ✅ |
| `entity:describe` | Schema, relationships, entity-view contracts, module ownership | ✅ |
| `disyl:inspect` | View contracts, component usage, template dependencies | ✅ |
| `make:entity` | Scaffold full entity: migration, handlers, routes, view contracts | ✅ |
| `make:capability` | Scaffold capability handler registration + module.json entries | ✅ |
| `doctor` | Environment health checker | ✅ |
| `capability:trace` | Provider detection, consumer scan, auth status, source references | ✅ |
| `module:check-boundaries` | Cross-module boundary enforcement (enhanced) | ✅ |
| `trigger:trace` | Event trigger emission path and handler resolution | ✅ |

**Detection results:** `architecture:check` finds 9 real violations (1 cross-module table + 8 undeclared capability calls).

### DiSyL Fibers Async Scheduler (v4.5.1)

| Component | Status |
|---|---|
| PHP 8.1+ Fibers-based cooperative multitasking | ✅ |
| Multi-curl HTTP multiplexing (`curl_multi_select`) | ✅ |
| Sync fallback outside Fiber context | ✅ |
| Settled promises complete synchronously | ✅ |
| Interface unchanged from v4.5.0 | ✅ |
| `tests/disyl_v45_async_test.php`: 23/23 PASS | ✅ |

### Multi-Step WorkflowEngine

| Component | Status |
|---|---|
| YAML-defined ordered steps | ✅ |
| Step types: validate, transition, notify, webhook, export | ✅ |
| Argument resolution from step context | ✅ |
| Retry with configurable max attempts | ✅ |
| Cancel / replay from any step | ✅ |
| Event-triggered auto-start (EventBus subscriptions) | ✅ |
| `migrations/009_kernel_workflow_runs.sql` | ✅ |
| `tests/workflow_engine_test.php`: 32/32 PASS | ✅ |

### Report Approval Workflow

| Component | Status |
|---|---|
| `report.export.request_approval@1` capability | ✅ |
| `report.export.approve@1` / `reject@1` / `list_pending@1` | ✅ |
| Admin approval queue page (`/cms/admin/report-approvals`) | ✅ |
| WorkflowEngine integration (auto-start on request) | ✅ |
| `migrations/010_report_approvals.sql` | ✅ |
| `tests/report_approval_workflow_test.php`: 18/18 PASS | ✅ |

### DiSyL VS Code Extension

| Feature | Status |
|---|---|
| Hover provider — block keyword docs + 32 governed component docs | ✅ |
| Go-to-definition for `{include}` navigation | ✅ |
| Close-tag completion (8 control structure pairs) | ✅ |
| `GOVERNED_COMPONENT_DOCS` data (attributes, examples) | ✅ |
| `extensions/disyl-lsp/src/extension.ts`: 561 lines | ✅ |

### Builder Hardening

| Fix | Status |
|---|---|
| Publish-time document validation (`cmsBuilderValidateDocument`) | ✅ |
| Publish wrapped in DB transaction | ✅ |
| Content mode preference over legacy meta | ✅ |
| Document settings preference over legacy meta | ✅ |
| Context row passed to `builder_enabled`/`global_styles` helpers | ✅ |
| Content duplication skips `_builder_page_settings`, `_builder_seo_settings` | ✅ |

### DiSyL Engine Fixes

| Fix | Status |
|---|---|
| `<script>` block variable resolution re-enabled (`compileScriptBody()`) | ✅ |
| `evaluateAwaitBody` double-resolve bug fix (Promise → resolveValue) | ✅ |
| ExpressionEvaluator extraction (7,698L → 7,021L) | ✅ |
| ControlNode return type mismatch in v4 Parser | ✅ |
| `++`/`--`, `+=`/`-=`, array literals, bitwise operators | ✅ |
| Typed `{set name: type = expr}` + strict-mode validation (interpreted + compiled) | ✅ (2026-08-23) |
| `{math equation="..."}` tag (interpreted + compiled; `format`/`assign`; visible error on missing equation) | ✅ (2026-08-23) |

---

### Strategic Position (Post-6.1)

> **From architecture proof to operating discipline — now with the tools to enforce it.**

6.1 gives every developer the ability to:
- **Inspect** the full capability call chain from provider to consumer
- **Trace** async template execution (HTTP only; PDO/filesystem/blocking calls remain synchronous) through Fibers and multi-curl
- **Validate** module boundaries automatically in CI
- **Navigate** DiSyL templates with IDE-assisted features (hover docs, go-to-definition, close-tag completion)
- **Orchestrate** multi-step business workflows with event triggers
- **Verify** environment health before deployment

The platform is no longer just extensible — it is **instrumented**.

---

## Kernel OS 6.2 — Trust Boundaries (Planned)

**Theme:** Can every boundary be trusted under failure, retry, concurrency, and hostile input?

| Priority | Description |
|---|---|
| 1. Baseline violations | Eliminate or formally baseline all 9 architecture violations |
| 2. CI enforcement | `php ikabud architecture:check --baseline=architecture-baseline.json --fail-on-new` in CI; move toward `--strict` with zero violations |
| 3. Signed service requests | Complete signed internal service calls with replay protection (nonce, timestamp, body hash, key rotation) |
| 4. Entity timeouts | Replace hardcoded 10s timeouts with hierarchy: governed call → entity source → service manifest → capability policy → kernel default |
| 5. Entity source schemas | Declare fields, filters, sorting, limits per entity source; validate filter values against schema before reaching query handlers |
| 6. Wildcard field exposure | Restrict auto-detected fields in production; unknown fields hidden by default; explicit/schema-approved modes only |
| 7. Workflow hardening | Step-level idempotency keys, locks, tenant/actor context, immutable inputs, output snapshots, duplicate event suppression, replay authorization, max workflow age |
| 8. Immutable report artifacts | Approve specific snapshot (definition + params + data hash + generated hash + requester + timestamp); regenerated artifact requires new approval |
| 9. Script interpolation | Resolve `<script>` block semantics: default raw passthrough, opt-in `disyl:compile` attribute, JSON serialization helper |
| 10. Test separation | Deterministic offline E2E tests separate from optional live external-service canaries |
| 11. Browser tests | Add Playwright/Puppeteer tests for builder, report approvals, async rendering |
| 12. Doc consolidation | Remove stale status sections; auto-generate component counts from registry |

**Release question:** Can every boundary be trusted under failure, retry, concurrency, and hostile input?

---

### Version Compatibility

```
Kernel OS 6.1
├── DiSyL language/runtime 4.7
├── DiSyL async API 4.5.1 (scheduler-integrated HTTP only)
├── DiSyL manifest schema 1.x
└── Service protocol 1.x
```

> **DiSyL v11** (referenced in older docs as `docs/kernel/disyl-grammar-v11-planned-types.md`) was an experimental grammar expansion that was never implemented. The dead code was removed in 4.7.0. The archived plan remains for reference only — there are no active plans to pursue v11.

