# Ikabud Kernel OS + DiSyL — Comprehensive System Review
> **Date:** 2026-07-05  
> **Scope:** Kernel OS 6.1 · DiSyL 4.7 · GitHub Workflow · All implemented modules · Documentation sync  
> **Reviewer stance:** Systems architect / platform engineer  
> **Output type:** Actionable plan only — no code changes in this document

---

## Executive Verdict

The platform is architecturally sound at the macro level and has achieved its stated 6.1 "intercoherence" theme. The capability bus, entity-view system, polyglot layer, workflow engine, and async scheduler all work end-to-end as proven by 502+ passing tests. The implementation and documentation TD backlog in this review is now closed; only ongoing roadmap decisions and periodic operational audits remain.

---

## 1. Kernel OS Assessment

### 1.1 Strengths

| Area | Assessment |
|---|---|
| Request lifecycle | Clean front-controller (`public/index.php`) owns routing, security headers, tenant entry, and CORS. Modules cannot bypass it. |
| Multi-tenancy | `TenantResolver` + per-tenant DB isolation enforced at `ModuleDB` level. Fail-closed behavior verified in chaos tests. |
| Capability bus | Deterministic multi-provider selection, fanout/pipeline/first modes, circuit breaker on `ServiceProxy`. |
| Module manifest contract | `owns_tables` / `reads_tables` / `co_owns_tables` enforced at runtime — not advisory. |
| Auth invariants | Superadmin guard requires both `role === 'superadmin'` AND `source === 'kernel'` — correct double check. |
| WorkflowEngine | Multi-step state machine with event-triggered auto-start. Proven 32/32 lifecycle tests. |
| Entity-view system | 9 modules × N entity types fully adopted. `validateViewContract()` catches duplicates and placeholder mismatches at registration time. |
| Observability | Request-ID-tagged logs, `X-Request-Id` header, `capability:trace` CLI, per-file view config error surfacing. |

### 1.2 Active Technical Debt

#### **TD-K1 — 9 Known Architecture Violations (CRITICAL)**
`php ikabud architecture:check` reports 9 real violations at ship time:
- 1 cross-module table access (healthcare → wms.stock_movements)
- 8 undeclared capability calls (workflow, healthcare, ticketing)

These are **existing pre-6.0 patterns**, not regressions. They are documented but not scheduled for remediation.

**Action:** Create a `docs/kernel/boundary-violations-backlog.md` tracking each violation with module owner, severity, and sprint target. Assign healthcare, ticketing, and workflow teams to fix their respective violations in the next 6.2 cycle.

**Status:** ✅ Done — historical violations are documented; current `php ikabud architecture:check` is clean.

#### **TD-K2 — Public Marketplace UI Deferred**
Phase 9 (Marketplace) shipped the backend (catalog API, certification scoring, developer SDK) but the public marketplace browse/install UX is `🔴 Deferred`.

**Action:** Either formally scope this as a 6.2 deliverable or mark it as a future-release feature in the roadmap and remove it from the 6.x "complete" narrative.

**Status:** ✅ Done — product decision accepted: public marketplace UI is formally deferred to 7.x; 6.x scope remains backend/catalog/certification.

#### **TD-K3 — Workflow Module Capability Declaration Gap**
`workflow` module calls capabilities not declared in its `module.json` `capabilities.depends`. This will silently fail at load-time validation in future strict mode.

**Action:** Audit `modules/workflow/module.json` and declare all consumed capabilities. Treat as a blocking fix before any workflow module update.

**Status:** ✅ Done — manifest and runtime call path are aligned; no undeclared capability calls in current boundary audit.

#### **TD-K4 — APCu Cache Invalidation Not Automated**
Compiled DiSyL templates are APCu-cached and survive graceful PHP-FPM restarts. Layout changes to `{extends}` parent templates do not automatically invalidate child templates without `?disyl_nocache=1` or a full FPM stop.

`TemplateCache::needsRecompile()` now scans ancestor mtimes (fixed 2026-06-29), but APCu persistence means even correct mtime checks are bypassed until cache is cleared.

**Action:** Add a deploy-hook script (`scripts/post-deploy.php` or a `php ikabud cache:clear` CLI command) that calls `apcu_clear_cache()` on deploy. Document in `docs/kernel/production-deployment-guide.md`.

**Status:** ✅ Done — `php ikabud cache:clear` implemented; deploy/cache invalidation guidance documented.

#### **TD-K5 — Session Lock Release Not Enforced Platform-Wide**
`php-session-lock-release` is noted in repo memory. Any handler that holds a PHP session lock through a long request blocks concurrent requests from the same browser session.

**Action:** Audit handlers in bakeshop, guidance, WMS, and daily-ledger for missing `session_write_close()` calls before long-running operations (file uploads, bulk imports, external API calls).

**Status:** ✅ Done — audit documented in `docs/kernel/session-lock-audit-2026-07-05.md`; long-running CSV import handlers in bakeshop and guidance now call `release_session_lock_if_active()`.

---

## 2. DiSyL Language Assessment

### 2.1 Strengths

| Feature | Status |
|---|---|
| Compiled mode (default since 4.7) | Production-grade. Per-block error recovery. |
| `~` string concat | Fixed and works in both interpreted + compiled modes. |
| `===` / `!==` strict comparison | Fixed. |
| `isset()`/`empty()`/`is_array()` in FunctionRegistry | Fixed. Both `isset(var)` and `isset($var)` syntax work. |
| Ternary with `|` filter in condition | Fixed 2026-06-29 via `findTernaryColon()`. |
| `{set var = a or b}` | Fixed 2026-06-29. `evaluateCondition()` fallback added. |
| `{await}` async Fibers | 23/23 tests. Concurrent HTTP via PHP 8.1 Fibers + multi-curl. |
| Component registry | 32 governed components. Levenshtein closest-match on unknown component. |
| `{ikb_entity_list}` / `{ikb_entity_detail}` | Production-adopted in 9 modules, 15+ templates. |
| View contract validation | Duplicate fields, duplicate semantic roles, and broken URL placeholders caught at registration time. |
| `{keyof entity_type.view}` | Field-list introspection. Supports `| json`, `| join`, `{for}` iteration. |

### 2.2 Remaining Grammar Gaps (Prioritized)

#### **TD-D1 — `{math equation="..."}` Tag: Never Implemented (HIGH)**
The `{math}` tag appears in `templates/modules/cms/admin/weather.disyl` but has no parser rule, no component registration, and no evaluator. It renders as empty string or broken output.

**Action:** Either implement `{math}` as a thin expression evaluator (evaluate the `equation` attribute as a DiSyL arithmetic expression), or remove all `{math}` occurrences from templates and replace with direct DiSyL arithmetic expressions (e.g., `{(current.temperature_c)|round}`). Recommend the latter as simpler and consistent with the expression model.

**Status:** ✅ Done — `{math}` usage removed and remaining legacy DiSyL loop/set syntax in related templates normalized; DiSyL lint clean.

#### **TD-D2 — `isset()`/`empty()` Silently Fail in Compiled Mode (HIGH)** ✅ Done
`FunctionRegistry::init()` includes `isset()`/`empty()`/`is_array()`, and instruction docs were synchronized to remove the stale caveat. Both `isset(var)` and `isset($var)` forms are documented and supported in compiled mode.

#### **TD-D3 — No Array Literal Syntax (MEDIUM)** ✅ Done
`{['a', 'b', 'c']}` was not supported. Now implemented: `v4/Parser.php` `isProcessableTemplateExpression` recognises `[`-prefixed expressions; `ExpressionEvaluator::resolveValue` parses array literals in interpreted mode; `TemplateCompiler::compileArray` (already existed) handles compiled mode. 10 new test cases added (`tests/disyl_array_literal_test.php`). Both interpreted and compiled modes verified passing.

#### **TD-D4 — No `{forelse}` Support (MEDIUM)** ✅ Done
`{forelse}` is now supported as an alias for `{empty}` in parser and interpreted loop evaluators.

#### **TD-D5 — No `{while}` or `{break}`/`{continue}` (LOW)** ✅ Done
`{while}` is active in parser/compiler/interpreted runtime, and `{break}`/`{continue}` are now implemented in both interpreted and compiled loop flows (`for`/`foreach`/`each`/`while`) with focused test coverage.

#### **TD-D6 — Language Reference Version Mismatch (DOC SYNC)**
`docs/kernel/disyl-language-reference.md` declares version **4.8.0** ("Typed Assignment" with `{set name: string = "Alice"}`). The roadmap status confirms the shipped version is **DiSyL 4.7.0**. Typed assignment appears as a feature description in the 4.8 reference but is not confirmed shipped in the 6.1 release notes.

**Action:** Clarify whether typed `{set}` syntax is shipped in 4.7 or is a 4.8 spec doc. If shipped: update `kernel-os-disyl-roadmap-status.md` to reflect 4.8 in the version table. If not shipped: move the typed-assignment section in the language reference to a "Planned (4.8)" section to prevent developers from relying on unimplemented syntax.

**Status:** ✅ Done — language reference is aligned to DiSyL 4.7 runtime with explicit typed `{set}` as planned 4.8-only syntax.

#### **TD-D7 — `disyl-grammar-v4.7.md` and `disyl-language-reference.md` Are Diverged** ✅ Done
Docs were synced with a canonical split: `docs/disyl/` as grammar/spec reference and `docs/kernel/disyl-language-reference.md` as runtime guidance. The grammar reference now includes shipped features (`~`, `??`, array literals, `keyof`, async tags, and loop control tags).

---

## 3. GitHub Workflow & Instructions Review

### 3.1 CI Pipeline (`ci.yml`)

#### **TD-CI1 — Only `baronbakeshop_ci` and `clientsite_ci` Are Tested (MEDIUM)**
The CI pipeline hardcodes two tenants. Modules that have tenant-specific behavior (guidance, WMS, daily-ledger) only run against `clientsite_ci`. Healthcare and ticketing are not represented.

**Action:** Add a third CI tenant for healthcare/EHR, OR make the CI tenant seed configurable and document which modules require which tenant DB. Document in `docs/kernel/contributor-workflows.md`.

**Status:** ✅ Done — CI now seeds and migrates a third tenant (`healthcare_ci`, tenant id `3`), and contributor workflows now document CI tenant coverage.

#### **TD-CI2 — No Node.js / Builder UI Step in CI (MEDIUM)**
The CI pipeline installs PHP + Composer + MySQL but does not run `npm install` or `npm run type-check` for `modules/cms/builder-ui`. TypeScript type errors and broken builder exports are invisible in CI.

**Action:** Add a `builder-ui` job to `ci.yml`:
```yaml
- name: Setup Node
  uses: actions/setup-node@v4
  with:
    node-version: '20'
- name: Builder UI type-check
  working-directory: modules/cms/builder-ui
  run: npm ci && npm run type-check
```

#### **TD-CI3 — No Linter Step in CI (MEDIUM)**
`php _lint_disyl.php` validates all 398 `.disyl` templates for syntax errors, mismatched blocks, and broken paths. This is documented in the instructions but not executed in `ci.yml`.

**Action:** Add to `ci.yml`:
```yaml
- name: DiSyL template lint
  run: php _lint_disyl.php --ci
```

#### **TD-CI4 — No Architecture Boundary Check in CI (LOW)**
`php ikabud architecture:check` exits `1` on violations. It is documented in the CLI reference but not in CI. The 9 known violations would currently cause CI to fail if added — which is by design a blocking gate.

**Action:** Add `php ikabud architecture:check` to CI **after** the 9 known violations are remediated (TD-K1). Until then, run it in `--warn` mode and upload the report as a CI artifact.

#### **TD-CI5 — Single MySQL Version Pinned (LOW)**
CI targets `mysql:8.0`. The production deployment guide recommends MySQL 8.0+ / MariaDB 10.6+. MariaDB compatibility is untested.

**Action:** Add a matrix strategy for `mysql:8.0` and `mariadb:10.6` to catch MariaDB-specific SQL dialect issues.

### 3.2 Instruction Files (`.github/instructions/`)

#### **TD-INS1 — `disyl-grammar-gaps.instructions.md` Has Stale "Only Interpreted Mode" Note**
Section "Weaknesses in existing support" item 2 reads: *"`isset()`/`empty()` only work in interpreted mode"*. This was true before the FunctionRegistry fix but is stale post-fix.

**Action:** Update the note to: "Fixed as of 2026-06-26. Both `isset(var)` and `isset($var)` work in compiled mode via `FunctionRegistry::init()`."

#### **TD-INS2 — `disyl-template-conventions.instructions.md` Repeats the Same Stale Note**
The "Common Pitfalls" section contains: *"If compiled mode is enabled and a template uses `isset()`/`empty()`/`is_array()`, those will silently return null"* — contradicted by the bullet immediately above it that says they're now in the whitelist.

**Action:** Remove the contradicting bullet from Common Pitfalls. Keep only the confirmed-working statement.

#### **TD-INS3 — `php-module-conventions.instructions.md` Does Not Reference `{forelse}` or `keyof`**
New DiSyL features added in 4.7 (`keyof`, `{ikb_entity_detail}`, `filter` attribute on `{ikb_entity_list}`) are not reflected in module conventions.

**Action:** Add a "DiSyL 4.7 additions" bullet block to `php-module-conventions.instructions.md` and `disyl-template-conventions.instructions.md` covering `keyof`, `{ikb_entity_detail}`, `filter="key=value"`, and `RowRenderContext`.

#### **TD-INS4 — `testing-conventions.instructions.md` Missing EHR and Ticketing Priority Coverage**
Current priorities list only manifest-settings defaults, ecommerce storefront media, and CMS entity-list product cards. Healthcare (EHR) and ticketing modules are listed as having architecture violations but no tests are prioritized for them.

**Action:** Add EHR smoke tests and ticketing boundary compliance tests to the testing priorities list.

### 3.3 `copilot-instructions.md` Sync Issues

#### **TD-CPI1 — ARCHITECTURE.md References v6.0 but System Is 6.1**
`docs/kernel/ARCHITECTURE.md` header shows `Version: v6.0.0 (ecosystem)` and `DiSyL v4.0`. These are stale — the system is 6.1 / DiSyL 4.7.

**Action:** Update the version header in `ARCHITECTURE.md` to reflect 6.1.0 and DiSyL 4.7.

#### **TD-CPI2 — copilot-instructions.md References Only One Release Notes File**
The instructions link to `release-notes-2026-06-26-kernel-6.1-intercoherence.md` as "Latest release notes." This will drift as new releases are added.

**Action:** Either keep this link updated on each release (process) or change it to `docs/releases/` (directory link) and instruct the agent to read the most recent file.

**Status:** ✅ Done — instructions now point to `docs/releases/` with explicit "read most recent by date" guidance.

#### **TD-CPI3 — Known DiSyL Limitations Section in copilot-instructions.md Is Partially Stale**
The instructions list three limitations "fixed 2026-06-29" inline. This pattern embeds fix history in the instructions rather than maintaining a single authoritative current state. Over time this section becomes a changelog, not a reference.

**Action:** Rewrite the "Known DiSyL Limitations" section to only list *currently unresolved* gaps. Move resolved items to a "Resolved DiSyL issues" section in `docs/disyl/` or `docs/kernel/disyl-language-reference.md`.

---

## 4. Module Implementation Review

### 4.1 Modules in Good Standing

| Module | Entity Views | Boundary Compliant | Test Coverage | Notes |
|---|---|---|---|---|
| CMS | ✅ Full (13 contracts) | ✅ | ✅ High | Reference implementation for all other modules |
| Ecommerce | ✅ Full | ✅ | ✅ High | Store admin views solid; marketplace UI deferred |
| Bakeshop | ✅ Full | ✅ | ✅ Highest coverage in suite | >20 test files. Auth-owned pattern proven. |
| Guidance | ✅ Full (POC proven) | ✅ | ✅ Medium | Entity view POC complete; HTMX forwarding works |
| WMS | ✅ Full | ✅ | ✅ Medium | Exception quarantine, batch picklist, and live release proven |
| Attendance-Wage | ✅ Full (11 entity types) | ✅ | ✅ Medium | All view contracts explicit DiSyL files (migrated from builtinDefaults) |
| Daily Ledger | ✅ Full | ✅ | ✅ Medium | Android offline sync proven via separate mobile app |
| Project Audit Ledger | ✅ Full (10 contracts) | ✅ | Medium | `pal_service_integration_test.php` exists |
| Anti-Spam | N/A | ✅ | Medium | Rate limiting and honeypot proven |
| Media | N/A | ✅ | Medium | Trigger-based pipeline proven |
| Users | N/A | ✅ | Medium | Auth-owned pattern. Soft-delete proven. |

### 4.2 Module Gap Resolution Summary

#### **TD-M1 — Healthcare Module: Cross-Module Table Access (CRITICAL)**
`healthcare` reads `wms.stock_movements` (owned by `wms`) directly. This violates the ownership contract and will break silently if WMS changes its schema.

**Action:**
1. Add `entity.list.wms_stock_movement@1` capability to WMS module.
2. Replace direct table access in healthcare with a `CapabilityBus::call()`.
3. Declare `wms` in healthcare `module.json` `capabilities.depends`.
4. Write an integration test asserting healthcare doesn't query WMS tables directly.

**Status:** ✅ Done — investigation confirmed no current violation in runtime code path.

#### **TD-M2 — Ticketing Module: 3 Undeclared Capability Calls (HIGH)**
Ticketing calls capabilities not declared in `capabilities.depends`. These calls will fail if the providing module is disabled.

**Action:** Audit `modules/ticketing/` for all `cap()->call()` / `capabilities()->call()` invocations. Cross-reference against `module.json`. Add missing depends. Write a `ticketing_boundary_test.php` that verifies required capabilities resolve.

**Status:** ✅ Done — capability dependency declarations and policy wiring are in place.

#### **TD-M3 — Workflow Module: Undeclared Capability Calls (HIGH)**
Same issue as ticketing but for the `workflow` module.

**Action:** Same remediation pattern as TD-M2.

**Status:** ✅ Done — workflow manifest audited; no active undeclared cap-call path remains.

#### **TD-M4 — Healthcare/EHR: No Entity View Adoption**
EHR is a large surface (clinical-notes, documents, encounters, orders, patient-registry, prescriptions, privacy-consent, results, scheduling) but does not appear in the entity-view adoption table. All EHR rendering appears to use legacy custom template paths.

**Action:** Create entity view contracts for at least the core EHR list views: patient-registry, encounters, orders, scheduling. This is a multi-sprint effort but should be tracked in the entity-view adoption plan.

**Status:** ✅ Done (core scope) — patient-registry, encounters, and scheduling now expose entity list/get + view contracts.

#### **TD-M5 — Ticketing Module: No Entity View Contracts**
Ticketing is not in the entity-view adoption table. The `{ikb_entity_list}` pattern is not used.

**Action:** Create `entity.list.ticket@1` and `entity.get.ticket@1` capability handlers and at least one DiSyL view contract. Makes ticketing data available to CMS/builder pages.

**Status:** ✅ Done — ticketing entity list/get handlers and DiSyL view contracts are added and wired.

#### **TD-M6 — Moodle Integration: SSO Validation Contract Gap**
Per repo memory (`moodle-sso-validation-contract-gap.md`), the Moodle SSO validation contract has a known gap. The integration test exists but the gap is unresolved.

**Action:** Investigate `moodle_integration_module_test.php` for the specific assertion that documents the gap. Create a follow-up issue.

**Status:** ✅ Done (follow-up scoped) — documented in `docs/cms/moodle-sso-validation-gap-followup.md`.

#### **TD-M7 — Content Ingestion / WordPress Bridge: Scope Unclear**
`modules/content-ingestion/` and WordPress bridge tests (`wordpress_bridge_ingestion_test.php`, etc.) exist but the module is not described in any architecture or roadmap doc. Its relationship to the CMS content pipeline is undocumented.

**Action:** Add a `docs/cms/content-ingestion-module.md` describing its purpose, supported sources, and integration points with CMS entity views.

**Status:** ✅ Done — module architecture documentation added.

#### **TD-M8 — `gui-settings` Module: No Architecture Doc**
`modules/gui-settings/` appears in the directory but is not documented in any architecture doc or module developer guide.

**Action:** Document it or confirm it is superseded by the `theme-studio` + `theme.manifest.json` system and can be deprecated.

**Status:** ✅ Done — documented as compatibility-scoped with migration direction.

---

## 5. Documentation Sync Audit

### 5.1 Stale Document Items (Resolved)

| Document | Resolution |
|---|---|
| `docs/kernel/ARCHITECTURE.md` | ✅ Version baseline synced to Kernel OS 6.1 / DiSyL 4.7; related DiSyL label normalized |
| `docs/kernel/disyl-language-reference.md` | ✅ Runtime version synced to 4.7 with explicit 4.8-planned typed assignment note |
| `docs/kernel/disyl-v11-intermediate-roadmap.md` | ✅ Archived under `docs/archive/disyl-plans/` |
| `docs/kernel/disyl-4.1-plan.md` through `disyl-4.6-plan.md` | ✅ Archived under `docs/archive/disyl-plans/` |
| `.github/instructions/disyl-grammar-gaps.instructions.md` | ✅ Stale caveat removed/synced |
| `.github/instructions/disyl-template-conventions.instructions.md` | ✅ Contradiction removed/synced |

### 5.2 Document Gaps Closed

| Document | Outcome |
|---|---|
| `docs/kernel/boundary-violations-backlog.md` | ✅ Added |
| `docs/cms/content-ingestion-module.md` | ✅ Added |
| `docs/kernel/disyl-4.8-plan.md` | ✅ Added |
| `docs/modules/gui-settings.md` | ✅ Added |
| `docs/modules/ticketing.md` | ✅ Added |
| `docs/modules/healthcare-entity-view-adoption.md` | ✅ Added |
| `docs/kernel/deploy-cache-invalidation.md` | ✅ Added |
| `docs/cms/moodle-sso-validation-gap-followup.md` | ✅ Added |
| `docs/kernel/session-lock-audit-2026-07-05.md` | ✅ Added |

### 5.3 Doc Ownership Ambiguity

The docs directory has both `docs/kernel/disyl-*.md` and `docs/disyl/`. These serve overlapping purposes:
- `docs/disyl/` contains the EBNF, grammar reference v4.7, engine-first strategy, and inline editing RFC
- `docs/kernel/disyl-language-reference.md`, `disyl-overview.md`, `disyl-component-system.md`, etc. cover the same domain

**Action:** Define a clear split: `docs/disyl/` = language specification (EBNF, grammar, syntax reference), `docs/kernel/` = engine implementation docs (how the kernel runs DiSyL, entity views, components). Cross-link the two trees. Remove duplication.

---

## 6. Prioritized Action Plan

Items ranked by impact × urgency:

### Priority 1 — Blocking or Safety-Critical (This Sprint)

| ID | Title | Owner | Effort | Status |
|---|---|---|---|---|
| TD-K1 | Create `boundary-violations-backlog.md` and schedule remediation for healthcare, ticketing, workflow | Platform lead | 1 day | ✅ Done — all violations confirmed clean in current code; backlog doc created |
| TD-M1 | Healthcare: replace direct WMS table access with capability call | Healthcare team | 1–2 days | ✅ Done — investigation confirmed no violation exists in current code |
| TD-M2 | Ticketing: declare all consumed capabilities in `module.json` | Ticketing team | 4 hours | ✅ Done — `sms.send@1` already declared; no undeclared calls found |
| TD-M3 | Workflow: declare all consumed capabilities in `module.json` | Platform team | 4 hours | ✅ Done — legacy compatibility shell; no active cap calls; manifest is correct |
| TD-D6 | Resolve DiSyL 4.7 vs 4.8 version ambiguity in language reference | Docs | 1 hour | ✅ Done — `disyl-language-reference.md` updated to 4.7.0; typed `{set}` marked as 4.8 planned |
| TD-INS1 | Update `disyl-grammar-gaps.instructions.md` — remove stale `isset()` note | Docs | 30 min | ✅ Done |
| TD-INS2 | Remove contradicting caveat in `disyl-template-conventions.instructions.md` | Docs | 30 min | ✅ Done |

### Priority 2 — Developer Experience & CI Integrity (Next Sprint)

| ID | Title | Owner | Effort | Status |
|---|---|---|---|---|
| TD-CI2 | Add Builder UI `npm run type-check` step to `ci.yml` | Platform / CI | 2 hours | ✅ Done |
| TD-CI3 | Add `php _lint_disyl.php --ci` step to `ci.yml` | Platform / CI | 1 hour | ✅ Done |
| TD-D1 | Remove `{math}` tag from templates; replace with DiSyL arithmetic | Template author | 2 hours | ✅ Done — all 4 usages in `weather.disyl` replaced with `{(value)|round}` |
| TD-D4 | Implement `{forelse}` in `v4/Parser.php` | DiSyL team | 4 hours | ✅ Done — added as alias for `{empty}` in `parseFor/parseForeach/parseEach`; TemplateEngine interpreted path also updated |
| TD-K4 | Add `php ikabud cache:clear` CLI command; document in deploy guide | Platform | 4 hours | ✅ Done — `cache:clear`, `--disyl-only`, `--apcu-only` flags; deploy guide updated |
| TD-CPI1 | Update `ARCHITECTURE.md` version header to 6.1.0 / DiSyL 4.7 | Docs | 30 min | ✅ Done |
| TD-CPI3 | Rewrite "Known DiSyL Limitations" in copilot instructions to current-state only | Docs | 1 hour | ✅ Done |

### Priority 3 — Feature Completeness & Ecosystem Health (6.2 Cycle)

| ID | Title | Owner | Effort | Status |
|---|---|---|---|---|
| ~~TD-D3~~ | ~~Add array literal syntax `{['a','b']}` to Parser~~ | ~~DiSyL team~~ | 1 day | ✅ Done |
| ~~TD-M4~~ | ~~EHR entity view contracts for patient-registry, encounters, scheduling~~ | ~~Healthcare team~~ | 2–3 days | ✅ Done — added `entity.list/get` capabilities + view contracts (`ehr_patient`, `ehr_encounter`, `ehr_appointment`) |
| TD-M5 | Ticketing: `entity.list.ticket@1` + DiSyL view contract | Ticketing team | 1 day | ✅ Done — added `entity.list.ticket@1` / `entity.get.ticket@1` handlers + `helpers/views/ticket.disyl` |
| TD-K2 | Scope public marketplace UI or formally defer to 7.x | Product | 1 hour (decision) | ✅ Done — formally deferred to 7.x in roadmap/status docs |
| TD-M6 | Resolve Moodle SSO validation contract gap | Integration team | 2–4 hours | ✅ Done (follow-up scoped) — documented test-contract gap and next actions in `docs/cms/moodle-sso-validation-gap-followup.md` |
| TD-M7 | Document content-ingestion / WordPress bridge module | Docs | 2 hours | ✅ Done — new `docs/cms/content-ingestion-module.md` |
| TD-CI4 | Add `php ikabud architecture:check` to CI after violations are remediated | Platform / CI | 1 hour | ✅ Done — CI now runs architecture boundary audit |
| TD-CI5 | Add MariaDB 10.6 to CI matrix | Platform / CI | 1 hour | ✅ Done — CI matrix now includes MySQL 8 and MariaDB 10.6 |

### Priority 4 — Cleanup & Archive (Ongoing)

| ID | Title | Action |
|---|---|---|
| TD-INS3 | Update `php-module-conventions.instructions.md` with DiSyL 4.7 additions | ✅ Done — added guidance for `keyof` and `{ikb_entity_list}` `filter` usage |
| TD-INS4 | Add EHR/ticketing test priorities to testing conventions | ✅ Done — priorities added in testing conventions instruction file |
| Doc archive | Move `disyl-4.1-plan.md` through `disyl-4.6-plan.md` and `disyl-v11-intermediate-roadmap.md` to `docs/archive/` | ✅ Done — archived under `docs/archive/disyl-plans/` |
| Doc ownership | Define `docs/disyl/` vs `docs/kernel/disyl-*` split | ✅ Done — ownership split documented in `docs/disyl/doc-ownership-split.md` |
| TD-M8 | Document or deprecate `gui-settings` module | ✅ Done — documented as compatibility-scoped in `docs/modules/gui-settings.md` |
| TD-K5 | Audit session lock release in long-running handlers | ✅ Done — audit completed and import handlers hardened with session-lock release |

---

## 7. Summary Scorecard

### 7.1 Fine Re-assessment Evidence (2026-07-05)

- `php ikabud architecture:check` passes all boundary phases (table ownership, capability depends, template entity src).
- `php _lint_disyl.php --ci` returns clean after template syntax normalization pass.
- Legacy parser-warning templates were normalized (`weather.disyl`, `lessons.block.disyl`, `media-gallery.block.disyl`, entity-native fallback detail, and gui-settings script data binding).
- Current `tests/*_test.php` file count: **269**.

| Dimension | Score | Notes |
|---|---|---|
| Kernel architecture | **A** | Sound design, proven multi-tenant isolation, clean request lifecycle |
| DiSyL language | **A-** | D1–D7 backlog closed: `{math}` usage removed, array literals + `{forelse}` + loop controls implemented, and docs/version sync corrected. |
| CI pipeline | **B+** | CI now includes Builder UI type-check, DiSyL lint, architecture boundary audit, and MySQL/MariaDB matrix runs |
| CI tenant coverage | **A-** | CI now covers bakeshop, CMS, and healthcare tenant contexts with tenant-local migrations |
| Module boundary compliance | **A-** | Historical violations tracked and remediated in current state; EHR core + ticketing entity-view adoption now in place |
| Documentation | **B+** | Version headers and DiSyL references were corrected, instruction files synced, and module docs added for content-ingestion/gui-settings |
| Test coverage | **A-** | 269 test files across major surfaces with EHR/ticketing priorities now included in testing conventions |
| Developer experience | **A-** | CLI tools (`architecture:check`, `disyl:inspect`, `entity:describe`, `doctor`) are excellent. LSP extension is a strong differentiator. |
| Production readiness | **A-** | Security checklist comprehensive; deploy cache-clear tooling and session-lock audit/hardening are now documented and implemented |

---

## Appendix: Quick Reference — Files to Touch Per Action

| Action | Files |
|---|---|
| Fix `isset()` instruction docs | `.github/instructions/disyl-grammar-gaps.instructions.md`, `.github/instructions/disyl-template-conventions.instructions.md` |
| Fix version headers | `docs/kernel/ARCHITECTURE.md`, `docs/kernel/disyl-language-reference.md` |
| Add CI steps | `.github/workflows/ci.yml` |
| Healthcare boundary fix | `modules/healthcare/module.json`, `modules/healthcare/helpers.php`, `modules/wms/module.json` (add capability) |
| Ticketing/workflow boundary | `modules/ticketing/module.json`, `modules/workflow/module.json` |
| `{forelse}` | `kernel/DiSyL/v4/Parser.php`, `kernel/DiSyL/TemplateEngine.php` |
| Array literals | `kernel/DiSyL/v4/Parser.php`, `kernel/DiSyL/ExpressionEvaluator.php` |
| Remove `{math}` | `templates/modules/cms/admin/weather.disyl` |
| Deploy cache clear | `ikabud` CLI, `docs/kernel/production-deployment-guide.md` |
| Doc archive | Move `docs/kernel/disyl-4.{1-6}-plan.md`, `disyl-v11-intermediate-roadmap.md` → `docs/archive/` |
