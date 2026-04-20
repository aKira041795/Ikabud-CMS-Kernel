# Ikabud Kernel & DiSyL Template Engine — Exhaustive Architecture Evaluation

**Repository:** `Ikabud-CMS-Kernel`  
**Evaluation date:** 2026-04-15  
**Scope:** Full file-by-file audit of all 76 kernel + DiSyL files (~18,700 lines), all 18 kernel docs, 30 related test files  
**Method:** Every public, protected, and private method signature cataloged; every regex audited; every error path traced; every hardcoded value recorded; every cross-dependency mapped.

---

## TABLE OF CONTENTS

1. [Executive Summary](#1-executive-summary)
2. [Kernel Core — File-by-File Audit](#2-kernel-core--file-by-file-audit)
3. [Kernel Subsystems — File-by-File Audit](#3-kernel-subsystems--file-by-file-audit)
4. [DiSyL Template Engine — File-by-File Audit](#4-disyl-template-engine--file-by-file-audit)
5. [Security Findings](#5-security-findings)
6. [Performance Findings](#6-performance-findings)
7. [Architectural Gaps](#7-architectural-gaps)
8. [Test Coverage Analysis](#8-test-coverage-analysis)
9. [Documentation Gaps](#9-documentation-gaps)
10. [Dead Code & Technical Debt](#10-dead-code--technical-debt)
11. [Dependency Graph](#11-dependency-graph)
12. [Prioritized Remediation Plan](#12-prioritized-remediation-plan)

---

## 1. Executive Summary

The kernel is genuinely well-architected. The capability-driven module system with registry/bus separation, the multi-strategy tenant resolver with 3-tier host cache, the SQL firewall in ModuleDB, the event-trigger-capability pipeline with correlation tracing, and the integration bridge with payload mapping + version lock are all production-quality designs. The TemplateEngine's auto-escaping, depth limits, and regex safety are solid. The test suite has 30 kernel-relevant files with 500+ assertions.

**The gap is not in design intent. The gap is in completion, hardening and operational maturity.**

Three critical structural issues:

1. **DiSyL has ~2,600 lines of dead code** — `DiSyLEngine.php` (970 lines, constructor throws), the entire `Compiler/` pipeline (5 files, ~1,250 lines targeting non-existent v4 AST classes), and `IslandsRuntime.php` (empty shim). A second, complete compiled-template pipeline exists but is not wired to anything.

2. **Two fatal duplicate class/enum definitions** — `HydrationStrategy` enum is defined in both `HydrationRuntime.php` and `HydrationStrategy.php` with *different* member names; `SlotDefinition` class is defined in both `ComponentDefinition.php` and `SlotSystem.php` with different implementations. Loading both files in one process is a fatal error.

3. **The live TemplateEngine is interpreted, not compiled** — every render re-parses state via regex. The compiled pipeline exists but is disconnected. For high-throughput paths (HTMX partials, entity lists), this is the performance ceiling.

---

## 2. Kernel Core — File-by-File Audit

### 2.1 App.php — Application Kernel (1,352 lines)

**Pattern:** Singleton + Service Locator + Lazy Factory  
**Constants:** `KERNEL_VERSION = '3.1.0'`, `KERNEL_CODENAME = 'clarity'`, `MAX_INPUT_SIZE = 2MB`

**67 methods total** including subsystem accessors, auth pipeline, CSRF, rendering, HTMX, I/O.

| Category | Methods | Assessment |
|----------|---------|------------|
| Subsystem accessors | `hooks()`, `events()`, `workflow()`, `capabilities()`, `cap()`, `entityContexts()`, `entityAuthority()`, `syncContracts()`, `tenant()`, `templates()`, `jwt()`, `cache()` | ✅ Clean lazy init |
| DB management | `db()`, `controlDb()`, `dbForTenant()`, `reconnectDb()`, `reconnectControlDb()`, `reconnectDbForTenant()` | ✅ Pool-backed |
| Auth pipeline | `user()`, `setUser()`, `isAuthenticated()`, `hasRole()`, `requireAuth()`, `requireRole()`, `requireAnyRole()` | ✅ Capability-driven |
| CSRF | `csrfToken()`, `csrfRotate()`, `csrfField()`, `csrfEnforce()` | ✅ `hash_equals`, session-based |
| Rendering | `render()`, `buildRenderBaseContext()`, `finalizeRenderContext()`, `wrapRenderFailure()`, `logRenderFailure()` | ✅ Failure recovery pipeline |
| Input | `input()`, `sanitizeInput()` | ✅ 2MB cap, null-byte strip, depth 32 |
| HTMX | `isHtmx()`, `isHtmxBoosted()`, `htmx()`, `htmxResponse()` | ✅ Header-based detection |
| Response | `json()`, `html()`, `redirect()` | ✅ `kernel_validate_redirect_target()` |

**Gaps found:**

| # | Gap | Severity | Detail |
|---|-----|----------|--------|
| K1 | Source→table map is hardcoded | Medium | `['kernel' => 'users', 'cms' => 'cms_users', 'guidance' => 'gm_users', 'daily-ledger' => 'dl_admins']` requires code edit to add new auth sources |
| K2 | `boot()` queries DB every request | Low | Queries `kernel_integrations` before `$booted` is set; first-request cost |
| K3 | `glossary()` fires hook on first access | Low | `kernel.glossary` hook fires on every request's first `glossary()` call |
| K4 | `'Ikabud'` hardcoded as default app name | Low | Default branding in `buildKernelGuiDefaults()` |
| K5 | JWT expiry hardcoded 86400 | Low | Should be configurable via env |

---

### 2.2 EventBus.php — Event Dispatch (453 lines)

**Pattern:** Singleton + Observer + Priority Queue + Deferred Dispatch  
**Constant:** `MAX_DEFERRED_FLUSH_BATCHES = 100`

**21 methods.** Supports exact + wildcard listeners, priority ordering, deferred-at-shutdown semantics.

**Gaps found:**

| # | Gap | Severity | Detail |
|---|-----|----------|--------|
| K6 | IntegrationBridge.handle() called on every fire() | High | Queries `kernel_integrations` DB table per event fire for non-excluded events |
| K7 | No slow-listener detection | Medium | No timing or warning for listeners exceeding a threshold |
| K8 | Wildcard regex recompiled on any new wildcard listener | Low | `$wildcardsDirty` flag forces `patternToRegex()` rebuild |
| K9 | History max hardcoded to 100 | Low | Not configurable |

---

### 2.3 Hooks.php — Filter/Action Pipeline (198 lines)

**Pattern:** Singleton + Chain of Responsibility (WordPress-style)  
**10 methods.** Clean, minimal design. Priority sort on dirty flag. Exceptions caught per listener.

No gaps found. This is the cleanest kernel file.

---

### 2.4 Cache.php — Multi-Tier Cache (994 lines)

**Pattern:** APCu → File with tag-based invalidation + LRU eviction + atomic writes  
**37 methods.** Compression at 1KB threshold, LOCK_EX on tag writes.

**Gaps found:**

| # | Gap | Severity | Detail |
|---|-----|----------|--------|
| K10 | `clearByUrlPattern()` deserializes every cache file | Medium | O(n) full scan with decompression |
| K11 | `enforceCacheLimit()` scans all files + sorts | Medium | glob() + filesize() per file under eviction |
| K12 | `warm()` is a no-op | Low | Method exists but returns empty results |
| K13 | `has()` + `get()` double file check | Low | `has()` before `get()` in the file tier does 2× stat calls |
| K14 | APCu stats key is `'guidance_cache_stats'` | Low | Legacy naming from Guidance origin |

---

### 2.5 Crypto.php — AES-256-GCM Encryption (72 lines)

**Pattern:** Facade around OpenSSL  
12-byte random IV, `strict_types`, 32-byte minimum key.

**Gap:**

| # | Gap | Severity | Detail |
|---|-----|----------|--------|
| K15 | No key rotation support | Medium | Single static key, no version tagging on encrypted payloads |

---

### 2.6 JWT.php — Token Handler (173 lines)

**Pattern:** Custom minimal JWT (no library)  
HS256 via `hash_hmac('sha256')`, `hash_equals()` timing-safe verify, 16-byte jti.

**Gaps found:**

| # | Gap | Severity | Detail |
|---|-----|----------|--------|
| K16 | Only HS256 supported | Medium | `$algorithm` property stored but never branched on — all tokens use hash_hmac regardless of header claim |
| K17 | Algorithm not validated during verify | High | JWT header `alg` field set during generate but not checked during verify — potential confusion attack if mixed-algorithm tokens exist |
| K18 | No token revocation list | Low | Relies solely on `token_version` DB check — no immediate revocation without DB update |

---

### 2.7 IntegrationBridge.php — Event→Capability Mediator (804 lines)

**Pattern:** Static Mediator + Payload Mapper + Version Lock  
**24 methods (all static).** `{{path.expression}}` template mapping, schema validation, caller policy enforcement.

**Gaps found:**

| # | Gap | Severity | Detail |
|---|-----|----------|--------|
| K19 | handle() queries DB per event fire | High | Same as K6 — no caching of active bridge configs |
| K20 | eventAvailableVars() cascades to filesystem scan | Medium | 3-fallback strategy: DB → globals → filesystem scan of all module.json files |
| K21 | Entirely static — cannot be mocked/injected | Medium | No instance state except `$activeDepth`, untestable in isolation |
| K22 | No circuit breaker | Medium | Individual integration failures logged but no breaker prevents repeated calls to a failing target |
| K23 | No retry logic | Medium | `retry_count` stored in trigger config but never consumed by bridge execution |

---

### 2.8 TenantResolver.php — Multi-Tenant Resolution (325 lines)

**Pattern:** Singleton + 7-Strategy Resolution Stack + 3-Tier Cache  
Strategies: CLI arg → env → X-Tenant header → JWT claim → host map → host→DB → subdomain → session → default.

**Gaps found:**

| # | Gap | Severity | Detail |
|---|-----|----------|--------|
| K24 | Subdomain strategy returns null | Medium | Implementation comment: "For now, return null" — incomplete |
| K25 | `clearControlHostCache()` iterates all APCu entries | Low | O(n) on total APCu store |

---

### 2.9 WorkflowRuntime.php — State Machine (470 lines)

**Pattern:** Final class, State Machine + Repository, retry-on-disconnect  
**20 methods.** Definitions seeded via cache-gated upsert, transitions within DB transactions, actor tracking.

**Gaps found:**

| # | Gap | Severity | Detail |
|---|-----|----------|--------|
| K26 | getOrCreateInstance() race condition | Medium | INSERT without IGNORE/ON DUPLICATE KEY — relies on catch-and-re-SELECT (works but fragile) |
| K27 | Only CMS content workflow defined | Medium | `ensureCmsContentWorkflow()` hardcodes draft→review→approved→published; no custom workflow registration API |
| K28 | No custom transition guards | Medium | Role-based gating only; no callbacks like "can publish only if all fields filled" |
| K29 | Hardcoded allowed callers | Low | `['cms', 'guidance', 'workflow', 'kernel']` — new modules can't use workflow without code change |

---

### 2.10 EventTriggers.php — Trigger System (775 lines, procedural)

**Pattern:** Procedural functions using `$GLOBALS` for state  
**20 functions.** Template replacement, schema validation, rate limiting, execution recording, correlation tracing.

**Gaps found:**

| # | Gap | Severity | Detail |
|---|-----|----------|--------|
| K30 | kernelEmitEvent() queries DB per event | High | Same pattern as K6/K19 — no caching of trigger configs |
| K31 | retry_count and timeout_ms stored but never used | High | Trigger config stores retry/timeout but `kernelEmitEvent()` never implements retry logic or timeout enforcement |
| K32 | sms.send@1 hardcoded in payload builder | Medium | `kernelBuildTriggerCapabilityPayload()` has special-case logic for SMS capability |
| K33 | Procedural 775 lines with $GLOBALS state | Medium | Only kernel file without a class; uses `$GLOBALS['_kernel_pending_event_registrations']` |
| K34 | kernelTemplateReplace() strips unreplaced vars | Low | 2× preg_replace per invocation to clean unreplaced `{var}` / `#{var}` |

---

## 3. Kernel Subsystems — File-by-File Audit

### 3.1 Capabilities

#### CapabilityRegistry.php (177 lines)

7 public methods. Registration + resolution + introspection.

| # | Gap | Severity | Detail |
|---|-----|----------|--------|
| K35 | resolve() linear scans all registered IDs | Low | O(n) per unversioned lookup |
| K36 | sortProviders() runs on every register() | Low | O(n log n) per registration |
| K37 | Duplicates baseId()/majorVersion() with Catalog and Bus | Low | DRY violation — same code in 3 files |

#### CapabilityBus.php (1,074 lines) — **Largest kernel file**

36 methods. Call modes: first / pipeline / fanout. Schema validation (input/output). Circuit breaker with metrics. pcntl_alarm timeout.

| # | Gap | Severity | Detail |
|---|-----|----------|--------|
| K38 | File-based breaker/metrics creates lock contention | High | `mutateJsonFile()` uses `flock(LOCK_EX)` on `capability_breakers.json` and `capability_metrics.json` — serial bottleneck under concurrent requests |
| K39 | flock fallback is non-atomic | High | If `flock()` fails, falls back to non-atomic read-modify-write — race condition |
| K40 | pcntl_alarm not available in FPM | Medium | Timeout is only post-execution duration check in FPM — cannot interrupt a truly blocking handler |
| K41 | No formal interface | Medium | CapabilityBus is a concrete class with no interface — tight coupling |

#### CapabilityCatalog.php (583 lines)

22 methods. Read-only catalog builder for admin introspection.

| # | Gap | Severity | Detail |
|---|-----|----------|--------|
| K42 | Duplicates baseId()/majorVersion() | Low | Same as K37 |

#### CapabilityTestRunner.php (284 lines)

**Security flag:**

| # | Gap | Severity | Detail |
|---|-----|----------|--------|
| K43 | runSqlStatements() executes arbitrary SQL | Medium | `$db->exec($statement)` from fixture data — safe if fixtures are trusted; no input validation |
| K44 | withTenantContext() mutates App/TenantResolver privates via Reflection | Medium | Fragile; crash between save/restore corrupts global state |

#### CapabilityProviderContract.php (35 lines)

| # | Gap | Severity | Detail |
|---|-----|----------|--------|
| K45 | Interface is architecturally orphaned | High | Nothing in Bus or Registry validates or consumes this interface. Providers are registered as raw callables. The contract exists but is never enforced. |

#### Exception classes (3 files, 35 lines total)

Clean hierarchy: `CapabilityException` → `CapabilityCallException` (final), `CapabilityNotFoundException` (final). No issues.

---

### 3.2 Contracts

#### AuthContract.php (48 lines)

| # | Gap | Severity | Detail |
|---|-----|----------|--------|
| K46 | Returns untyped arrays | Medium | `user(): ?array` — no User DTO, no typed structure |
| K47 | No permission-level auth | Medium | Only role-based (`requireRole`). No `requirePermission()` or capability-level auth |

#### CacheContract.php (39 lines)

| # | Gap | Severity | Detail |
|---|-----|----------|--------|
| K48 | Not PSR-16 compliant | Low | Missing `getMultiple()`, `setMultiple()`, `deleteMultiple()` |

#### DatabaseContract.php (71 lines)

| # | Gap | Severity | Detail |
|---|-----|----------|--------|
| K49 | KernelPDO extends PDO directly, not DatabaseContract | Medium | ModuleDB implements DatabaseContract but KernelPDO bypasses it — inconsistent layering |

#### LogContract.php (32 lines)

| # | Gap | Severity | Detail |
|---|-----|----------|--------|
| K50 | Not PSR-3 compliant | Low | No LogLevel enum/constants |
| K51 | audit() has 7 nullable params | Low | Should use a payload DTO |

#### ModuleContext.php (245 lines)

| # | Gap | Severity | Detail |
|---|-----|----------|--------|
| K52 | audit() bypasses ModuleDB | Low | Writes directly to `$this->app->db()` for kernel `audit_logs` table — intentional but implicit |
| K53 | Class is not final | Medium | Subclassable — weakens sandbox contract |

#### ModuleDB.php (369 lines) — **SQL Firewall**

Blocks DDL/DCL, multi-statement injection, system schema access. Regex-based SQL parsing.

| # | Gap | Severity | Detail |
|---|-----|----------|--------|
| K54 | extractTables() cannot handle CTEs | Medium | `WITH ... AS (...)` clauses confuse the regex parser |
| K55 | REPLACE INTO detection may miss table in extractTables() | Low | detectQueryType maps to INSERT but extractTables regex may not capture the table name |
| K56 | Regex SQL parsing fundamentally limited | Medium | Complex queries (deep subqueries, UNION nesting) may bypass table detection |

---

### 3.3 Database

#### ConnectionPool.php (189 lines)

| # | Gap | Severity | Detail |
|---|-----|----------|--------|
| K57 | No max pool size limit | Low | `register()` accepts unlimited entries; `closeAll()` is the only cleanup |
| K58 | Uses error_log() instead of write_log() | Low | Inconsistent logging |

#### KernelPDO.php (178 lines) — **Backtrace-based module enforcement**

| # | Gap | Severity | Detail |
|---|-----|----------|--------|
| K59 | debug_backtrace() on every query from module context | Medium | Backtrace cache (256 entries) mitigates but periodic flush causes performance dip |
| K60 | Cache flushes entirely at 256 entries | Low | Cliff-edge behavior — should use LRU |
| K61 | Fires kernel.database.query.after on every query | Low | Event overhead per query |

#### MigrationRunner.php (495 lines)

| # | Gap | Severity | Detail |
|---|-----|----------|--------|
| K62 | No advisory locking for concurrent migrations | High | Two concurrent `migrateAll()` calls can duplicate work |
| K63 | No transaction wrapping around multi-migration batches | Medium | Failure mid-batch leaves partial state |
| K64 | rollback() requires companion .down.sql files | Low | Throws RuntimeException if missing |

#### QueryBuilder.php (694 lines)

| # | Gap | Severity | Detail |
|---|-----|----------|--------|
| K65 | Inconsistent immutability | Low | `table()` clones, but all other methods mutate `$this` |
| K66 | Column names in where() are unescaped | Low | Standard for query builders but callers must not pass user input as column names |

#### KernelPDOStatement.php (35 lines)

No issues. Fires query.after event with duration.

---

### 3.4 Services

#### DatabaseManager.php (442 lines)

| # | Gap | Severity | Detail |
|---|-----|----------|--------|
| K67 | DSN injection prevention is solid | ✅ | Database name validated against `^[a-zA-Z0-9_]{1,64}$` |
| K68 | Encrypted credentials with fail-closed option | ✅ | `ENFORCE_ENCRYPTED_DB_PASS` env flag |

No gaps — this file is production-hardened.

---

### 3.5 HTTP

#### SecurityHeaders.php (189 lines)

| # | Gap | Severity | Detail |
|---|-----|----------|--------|
| K69 | getBaseDomain() is dead code | Low | Defined but never called |
| K70 | img-src includes `https:` | Medium | Overly permissive — allows images from any HTTPS origin |
| K71 | connect-src 'self' may break external frontend API calls | Low | Intentionally restrictive |

#### TenantEntryRouter.php (325 lines)

| # | Gap | Severity | Detail |
|---|-----|----------|--------|
| K72 | entryLandingPath() requires module routes.php | Medium | Executes PHP from module on every root request — side effects possible |
| K73 | routePatternMatches() compiles regex per match | Low | No caching of compiled route patterns |

---

### 3.6 Entity System

#### EntityAuthorityRegistry.php (57 lines)

| # | Gap | Severity | Detail |
|---|-----|----------|--------|
| K74 | Only 2 test assertions cover this file | Medium | Authority conflict detection tested; no resolution, multi-entity, or de-registration tests |

#### SyncContractRegistry.php (42 lines)

| # | Gap | Severity | Detail |
|---|-----|----------|--------|
| K75 | No validation that handler is resolvable | Medium | Placeholder for event-bridge routing; very thin |
| K76 | No duplicate detection | Low | Same contract can be registered twice |

#### ContextProfile.php (218 lines)

Clean value object. No issues.

#### ContextRegistry.php (762 lines)

| # | Gap | Severity | Detail |
|---|-----|----------|--------|
| K77 | **3 methods reference undefined properties** | **Critical** | `getRegisteredSchemas()` → `$this->schemas`, `getRegisteredProfiles()` → `$this->profiles`, `getRegisteredModes()` → `$this->modes` — these properties DO NOT EXIST. Any call produces `Undefined property` fatal error |
| K78 | No reset() method | Low | Unlike sibling registries, cannot reset for testing |
| K79 | Duplicates normalizeId()/defaultLabel() with ContextProfile | Low | DRY violation |

---

### 3.7 Control Plane

#### IntegrationCatalog.php (865 lines)

| # | Gap | Severity | Detail |
|---|-----|----------|--------|
| K80 | build() executes 8 SQL queries in one call | Medium | Heavyweight; acceptable for admin dashboard, problematic if called frequently |
| K81 | fetchAll() silently returns empty array on DB error | Medium | Hides database failures |
| K82 | Log query LIMIT 100 hardcoded | Low | May miss recent entries for high-traffic integrations |

---

## 4. DiSyL Template Engine — File-by-File Audit

### 4.1 TemplateEngine.php — THE LIVE ENGINE (2,659 lines)

**The only active template engine in the entire application.** All rendering flows through this file.

**Constants (depth/safety limits):**

| Constant | Value | Purpose |
|----------|-------|---------|
| `OUTPUT_CACHE_MAX` | 200 | Max in-memory output cache entries |
| `TEMPLATE_SOURCE_CACHE_MAX` | 100 | Max template source entries cached |
| `OUTPUT_CACHE_KEY_FAST_DEPTH` | 8 | Max nesting for fast cache key generation |
| `EXTENDS_CHAIN_MAX` | 20 | Max template inheritance chain |
| `COMPONENT_MAX_DEPTH` | 30 | Max component nesting |
| `FILTER_CHAIN_MAX` | 20 | Max filters in a single chain |

**60+ methods** covering the complete compile pipeline:

| Phase | Methods | Limits |
|-------|---------|--------|
| Entry | `render()`, `renderString()` | Output cache check |
| Comments | `removeComments()` | — |
| Verbatim/Literal | Extraction + restoration | — |
| Set statements | `processSetStatements()` | — |
| Extends/Blocks | `processExtends()`, `processBlocks()` | 20 chain max |
| Control structures | `processControlStructures()`, `processOneControlStructure()` | 100 iteration max |
| Loops | `processForStructure()`, `processForeachStructure()`, `processEachStructure()` | — |
| If/Else | `processIfStructure()`, `parseIfBranches()` | — |
| Includes | `processIncludes()` | 20 iteration max, circular detection |
| Components | `processComponents()` | 200 iteration max, 30 depth max |
| Variables | `processVariables()` | Single-pass regex, per-call resolution cache, auto-escape |
| Ternary | `evaluateTernary()` | — |
| Arithmetic | `evaluateArithmetic()` | — |
| Conditions | `evaluateCondition()`, `resolveConditionOperand()` | — |
| Filters | `applyFilter()`, `resolveValueWithFilters()` | 20 chain max |

**Regex Audit — 17 patterns, ALL SAFE:**

Every regex uses non-overlapping character classes (`[^}]+`, `[^"]+`, `[^']+`) or anchored lazy quantifiers. No catastrophic backtracking (ReDoS) vectors found. Specific audit:

| # | Pattern Context | Safety |
|---|----------------|--------|
| 1 | Verbatim: `\{verbatim\}(.*?)\{\/verbatim\}/s` | ✅ Lazy + terminator |
| 2 | Script: `/<script\b([^>]*)>(.*?)<\/script>/si` | ✅ Single-pass |
| 3 | Set: `/\{set\s+(\w+)\s*=\s*([^}]+)\}/` | ✅ Linear class |
| 4 | Extends: `/\{extends\s+"([^"]+)"\s*\}/` | ✅ Linear class |
| 5 | Block: `/\{block\s+(\w+)\}(.*?)\{\/block\}/s` | ✅ Lazy + terminator |
| 6 | Ternary: `/\{([^}]+\?[^}]+:[^}]+)\}/` | ✅ Linear class |
| 7 | Variable: `/(?<!\$)\{([a-zA-Z_][\w.]*(?:\s*\|\s*[^}]+)?)\}/` | ✅ Linear inner class |
| 8 | Include: `/\{include\s+"([^"]+)"(?:\s+with\s+(\{[^}]+\}))?\s*\}/` | ✅ Linear classes |
| 9 | Conditions: `/^(.+?)\s*(===\|!==\|==\|..)\s*(.+)$/` | ✅ Anchored |
| 10 | Attributes: `/([\w-]+)=(?:"([^"]*)"\|'([^']*)'\|\{([^}]+)\})/` | ✅ Non-overlapping |
| 11-17 | Various arithmetic, AND/OR, comments | ✅ All anchored or linear |

**XSS Prevention:**
- ✅ Auto-escaping ON by default — all variables wrapped in `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')` unless `| raw` used
- ✅ `esc_html`, `esc_attr`, `esc_url`, `esc_js` filters properly implemented
- ✅ `esc_url` rejects `javascript:`, `vbscript:`, `data:` schemes and protocol-relative `//` URLs
- ✅ `sanitizeHref()` enforces same allowlist for component href attributes  
- ✅ HTMX attributes escaped in `buildHtmxAttrs()`
- ⚠️ `processScriptVariables()` outputs RAW in `<script>` blocks — intentional but `| esc_js` not enforced

**Template Injection Prevention:**
- ✅ No `eval()`, no `preg_replace` with `/e`, no `create_function()` anywhere
- ✅ Variable resolution only does array/object traversal — no method calls, no class instantiation
- ✅ Condition evaluation only supports comparisons and truthiness — no function calls
- ✅ Filter callables registered by trusted kernel code only — template content cannot register filters

**Gaps found:**

| # | Gap | Severity | Detail |
|---|-----|----------|--------|
| D1 | Absolute paths bypass traversal check | High | `resolveTemplatePath()` passes paths starting with `/` verbatim. If module passes user input to `render()`, path traversal is possible |
| D2 | No memory limit on template output | Medium | Nested loops can produce unbounded output without any limit |
| D3 | Interpreted, not compiled | Medium | Every render re-parses via regex string manipulation. O(N × body_size) for N-item loops |
| D4 | processControlStructures loops up to 100 times | Medium | Each iteration scans entire content string for opening tags |
| D5 | compileScriptBody iterates char-by-char | Low | O(n) with per-character work — slow for large script blocks |
| D6 | No template file size limit | Low | `file_get_contents()` with no size cap |
| D7 | All depth constants are private and non-configurable | Low | `OUTPUT_CACHE_MAX`, `EXTENDS_CHAIN_MAX`, etc. hardcoded |

---

### 4.2 DiSyLEngine.php — ASPIRATIONAL STUB (970 lines)

**Constructor throws `\LogicException` unconditionally.** Every method after `__construct` is unreachable dead code. 87 `use` imports reference classes that don't exist (AI, Federation, Security, Cache, Targets, Framework, Plugin, Debug subsystems). Reports version `'11.1.0'`.

| # | Gap | Severity | Detail |
|---|-----|----------|--------|
| D8 | 970 lines of dead code | Medium | Constructor throws; all 80+ methods are unreachable |
| D9 | findTemplate() has path traversal | Low | Dead code, but accepts absolute paths with no validation |
| D10 | 87 non-existent class imports | Low | Creates false impression of available subsystems |

---

### 4.3 Grammar.php — Type System & Keywords (289 lines)

Constants-only file. Schema version `4.0.0`. Types, platforms, categories, keywords.

| # | Gap | Severity | Detail |
|---|-----|----------|--------|
| D11 | All v11/v11.1 keywords are "PLANNED — not yet implemented" | Low | Pattern matching, async, i18n, experiments, cache, security, federation, AI — all planned constants |
| D12 | PLATFORM_IKABUD defined but not in getPlatforms() return | Low | Inconsistency |

---

### 4.4 ComponentRegistry.php — Static Registry (503 lines)

Global mutable static state. 10 core component definitions registered in `registerCoreComponents()`.

| # | Gap | Severity | Detail |
|---|-----|----------|--------|
| D13 | No input validation on component names or definitions | Low | register() accepts any string/array |

---

### 4.5 Compiler Pipeline (5 files, ~1,250 lines)

**This entire subsystem targets v4 AST classes that are not present in the kernel directory.** It constitutes a parallel compiled-template pipeline that is not wired to the live TemplateEngine.

#### TemplateCompiler.php (581 lines)

Generates PHP class code from AST nodes (`DocumentNode`, `TextNode`, `ExpressionNode`, etc.). Division-by-zero guards. `var_export()` for safe literal embedding.

| # | Gap | Severity | Detail |
|---|-----|----------|--------|
| D14 | Not connected to live engine | High | TemplateEngine uses regex interpretation; this compiler is unused |
| D15 | compileApply uses ob_start() without guaranteed cleanup | Low | Error between start/end could leak buffer |

#### IncrementalCompiler.php (177 lines)

Manifest-tracked dependency graph. MD5 file hashing. Deferred recompilation.

| # | Gap | Severity | Detail |
|---|-----|----------|--------|
| D16 | Manifest has no integrity validation | Medium | Plain JSON; no HMAC or checksum |
| D17 | md5_file for cache invalidation | Low | Fast but not collision-resistant — acceptable for caching |

#### CompiledTemplate.php (146 lines)

Abstract base for compiled templates. `renderBlock()` and `renderSlot()` are stubs returning HTML comments.

#### TemplateCache.php (317 lines)

HMAC-SHA256 sentinel system, atomic writes, opcache invalidation.

| # | Gap | Severity | Detail |
|---|-----|----------|--------|
| D18 | Sentinel secret is deterministic | High | `'DISYL_CACHE_' . sha1($this->cacheDir)` — not a real secret; bypassable by anyone who knows the cache dir path |
| D19 | Namespace mismatch in generated code | Medium | Generated class uses `Ikabud\Kernel\Core\DiSyL\Compiled\...` but actual namespace is `Ikabud\Kernel\DiSyL\Compiled` — require_once will fail |

#### TreeShaker.php (129 lines)

AST analysis for unused macros/filters. `getFilterImports()` generates PHP code with unescaped filter names from AST.

---

### 4.6 Component System (6 files, ~2,700 lines)

#### ComponentDefinition.php (473 lines) + ComponentInstance.php (387 lines)

| # | Gap | Severity | Detail |
|---|-----|----------|--------|
| D20 | **SlotDefinition duplicate class** | **Critical** | Defined here AND in SlotSystem.php with different implementations. Fatal error if both loaded. |
| D21 | ComponentInstance.getComputed() returns null | Medium | Stub — "actual evaluation happens in renderer" |
| D22 | ComponentInstance.callMethod() returns null | Medium | Stub |
| D23 | ComponentInstance.triggerWatchers() is empty | Medium | Stub |
| D24 | ComponentInstance.emit() has no recursion depth limit | Low | Bubbles events to parent without limit |

#### ComponentLoader.php (181 lines)

| # | Gap | Severity | Detail |
|---|-----|----------|--------|
| D25 | Component names not sanitized before path construction | High | Name like `../../etc/passwd` traverses directories. Combined with `file_exists()`, leaks path existence |

#### ComponentParser.php (920 lines)

Token-based parser. Error recovery via `skipToClosingBrace` with depth tracking. No direct regex — all token-based.

No gaps found. Clean parser.

#### SingleFileComponent.php (295 lines)

| # | Gap | Severity | Detail |
|---|-----|----------|--------|
| D26 | CSS scoping regex is naive about CSS strings/comments | Low | `([^{}]+)\{` can be confused by `}` in CSS string values |

#### SlotSystem.php (444 lines)

| # | Gap | Severity | Detail |
|---|-----|----------|--------|
| D27 | **SlotDefinition duplicate** | **Critical** | Same as D20 — this is the other copy |

---

### 4.7 Hydration System (9 files, ~1,140 lines)

#### HydrationRuntime.php (631 lines)

| # | Gap | Severity | Detail |
|---|-----|----------|--------|
| D28 | **HydrationStrategy enum duplicate** | **Critical** | Defined here with cases `IMMEDIATE, IDLE, VISIBLE, INTERACTION, MEDIA, NEVER` AND in HydrationStrategy.php with `LOAD, IDLE, VISIBLE, MEDIA, INTERACTION, NEVER`. Fatal error if both loaded. |
| D29 | JSON embedding uses JSON_HEX_TAG correctly | ✅ | `toJSON()` and `toScriptTag()` properly handle script context |

#### HydrationStrategy.php (22 lines)

Duplicate enum (see D28).

#### IslandManifest.php (48 lines)

| # | Gap | Severity | Detail |
|---|-----|----------|--------|
| D30 | generateScriptTag() embeds JSON without JSON_HEX_TAG | High | `<script type="application/json">` content could contain `</script>` in module paths, breaking out of the tag |

#### IslandRenderer.php (66 lines)

| # | Gap | Severity | Detail |
|---|-----|----------|--------|
| D31 | $island->mediaQuery not HTML-escaped | Medium | Embedded in `data-media` attribute — `"` in value breaks attribute boundary |

#### ClientBundleGenerator.php (60 lines)

| # | Gap | Severity | Detail |
|---|-----|----------|--------|
| D32 | $modulePath embedded in link href without escaping | Medium | `<link rel="modulepreload" href="...">` — arbitrary HTML injection |

#### IslandsRuntime.php (26 lines)

Empty backward-compatibility shim. Dead code.

#### disyl-runtime.js (288 lines)

Client-side hydration runtime. `import(modulePath)` from manifest JSON. IntersectionObserver for lazy hydration.

| # | Gap | Severity | Detail |
|---|-----|----------|--------|
| D33 | No component name validation in loadComponent() | Medium | Unlike the PHP inline script which validates `/^[a-zA-Z0-9_-]+$/`, the standalone JS module doesn't — relies on manifest being server-controlled |

---

### 4.8 Reactive System (11 files, ~1,400 lines)

#### ClientBlock.php (114 lines)

| # | Gap | Severity | Detail |
|---|-----|----------|--------|
| D34 | **EventHandler::compile() embeds $handler in JS without escaping** | **Critical** | Direct string interpolation into generated JavaScript. If handler source is untrusted, this is arbitrary code execution. |
| D35 | **ClientBlock::toModule() wraps $code in IIFE without sanitization** | **Critical** | Raw JS code embedded in output. Trusted source assumed but not enforced. |
| D36 | $elementId embedded unsanitized in getElementById() | High | Attribute injection if elementId contains `'` |

#### ClientBlockRegistry.php (41 lines)

| # | Gap | Severity | Detail |
|---|-----|----------|--------|
| D37 | renderScripts() concatenates all blocks without escaping | Medium | Relies entirely on ClientBlock content being trusted |

#### HTMXIntegration.php (106 lines)

✅ All HTMX filters properly escape with `htmlspecialchars` and `JSON_HEX_*`.

#### HTMXRequest.php (90 lines), HTMXHeaders.php (26 lines), SwapStrategy.php (24 lines)

No issues. Clean value objects and constants.

#### HTMXResponse.php (176 lines)

| # | Gap | Severity | Detail |
|---|-----|----------|--------|
| D38 | redirect() and pushUrl() pass URLs to headers without validation | Medium | HTMX processes redirect headers client-side |

#### OOBSwap.php (27 lines)

| # | Gap | Severity | Detail |
|---|-----|----------|--------|
| D39 | $targetId not HTML-escaped in id attribute | Medium | `"` in value breaks attribute boundary |

#### ReactiveState.php (65 lines)

| # | Gap | Severity | Detail |
|---|-----|----------|--------|
| D40 | toJSON() lacks JSON_HEX_TAG | Medium | Unsafe for script-tag embedding |

#### SignalSystem.php (586 lines)

Full reactive signal/computed/effect graph. Singleton ReactiveContext. Batch processing.

| # | Gap | Severity | Detail |
|---|-----|----------|--------|
| D41 | No circular dependency protection in computed graph | Medium | Circular computed chain → infinite recursion |
| D42 | No garbage collection for disposed effects | Low | removeDependent() requires manual calls |

#### TurboStreamResponse.php (79 lines)

| # | Gap | Severity | Detail |
|---|-----|----------|--------|
| D43 | **$target and $content embedded in HTML without escaping** | **High** | `$target` in attribute → attribute injection; `$content` in body → XSS |

---

### 4.9 Exceptions (4 files, ~225 lines)

Clean exception hierarchy with structured metadata (line, column, position, token type, AST node). No issues.

---

## 5. Security Findings — Consolidated

### Critical (fix immediately)

| # | File | Finding |
|---|------|---------|
| D34 | ClientBlock.php | `EventHandler::compile()` embeds `$handler` directly in JS — JavaScript injection |
| D35 | ClientBlock.php | `ClientBlock::toModule()` wraps raw `$code` in JS IIFE — code injection |
| D18 | TemplateCache.php | Cache sentinel secret is `sha1($cacheDir)` — deterministic, not truly secret |
| D20/D27 | ComponentDefinition/SlotSystem | Duplicate SlotDefinition class — fatal error if both loaded |
| D28 | HydrationRuntime/HydrationStrategy | Duplicate HydrationStrategy enum — fatal error if both loaded |
| K77 | ContextRegistry.php | 3 methods reference undefined properties — runtime fatal error |

### High

| # | File | Finding |
|---|------|---------|
| D1 | TemplateEngine.php | Absolute paths bypass `resolveTemplatePath()` traversal check |
| D25 | ComponentLoader.php | Component names not sanitized — path traversal in loading |
| D30 | IslandManifest.php | JSON embedded in script tag without `JSON_HEX_TAG` |
| D43 | TurboStreamResponse.php | `$target` and `$content` embedded without escaping |
| K17 | JWT.php | Algorithm not validated during verify — confusion attack vector |
| K38 | CapabilityBus.php | File-based breaker/metrics creates serial lock contention |
| K39 | CapabilityBus.php | flock fallback is non-atomic read-modify-write |
| K45 | CapabilityProviderContract.php | Interface exists but is never enforced — false contract |
| K62 | MigrationRunner.php | No advisory locking for concurrent migrations |

### Medium

| # | File area | Count | Detail |
|---|-----------|-------|--------|
| D2, D31, D32, D36-D40 | Hydration/Reactive escaping | 8 | Various unescaped attribute/HTML output |
| K6, K19, K30 | Event→DB per fire | 3 | Same issue in EventBus, Bridge, Triggers — no caching |
| K16, K22-K23, K31 | Missing runtime enforcement | 4 | Algorithm, circuit breaker, retry logic |
| K40, K54, K56 | Runtime limitations | 3 | pcntl_alarm in FPM, CTE parsing, regex SQL limits |

---

## 6. Performance Findings — Consolidated

### Hot Path Analysis

The request critical path for a template render is:

```
index.php → App::render() → TemplateEngine::render()
  → Handler-level cache (login 60s, health 2s — authoritative full-page cache)
  → APCu shared output cache (disabled by default; opt-in via DISYL_SHARED_OUTPUT_TTL)
  → readTemplateSource() [APCu source cache, 300s TTL; hit ratio instrumented]
  → compile() [stage-gated pipeline, recursive for loop bodies]
    → processControlStructures() [single-pass O(N) scanner]
    → processIncludes() [file I/O per include, up to 20]
    → processComponents() [up to 200 iterations, depth 30]
    → processVariables() [single-pass regex, per-call resolution cache]
```

**Update (2026-04-16):** `processControlStructures` was rewritten from an O(N²) while-loop (one structure per iteration, full rescan) to a single-pass O(N) left-to-right scanner. The new implementation finds all top-level structures in one traversal and processes each in-place. Nested structures in loop bodies are still handled via recursive `compile()` calls; nested structures in chosen if-branches are handled by recursive single-pass invocation. Benchmarks show control_ms avg dropped from 40.5ms → 9ms (−78%), total compile avg from 53ms → 28ms (−47%).

**Update (2026-04-16, cont.):** `processVariables` rewritten from three sequential regex passes to a single unified pass with a per-call resolution cache. variables_ms max dropped from 148ms → ~14ms (−90%). Cache authority simplified: handler-level caches are primary, APCu shared output cache disabled by default. Cache hit ratio instrumentation added (`TemplateEngine::getCacheMetrics()`). Compiled mode env-gated via `DISYL_COMPILED_MODE` (awaiting v4 Parser).

**Update (2026-04-16, v4 Parser):** The v4 compiled template pipeline is now fully operational. Implemented: `kernel/DiSyL/v4/Parser.php` (recursive-descent parser producing AST from DiSyL source), 16 AST node classes in `kernel/DiSyL/v4/AST/`, `kernel/DiSyL/v4/RenderContext.php` (scoped variable resolution), `kernel/DiSyL/v4/FilterRegistry.php` (compiled-path filter runtime), `kernel/DiSyL/CMS/CMSAdapterInterface.php` + `NullAdapter.php`. The existing `Compiler/TemplateCompiler`, `Compiler/TemplateCache`, `Compiler/CompiledTemplate`, `Compiler/TreeShaker`, and `Compiler/IncrementalCompiler` now have all their dependencies satisfied. Setting `DISYL_COMPILED_MODE=true` in `.env` activates the compiled fast path: parse → compile to PHP class → cache on disk → opcache. Compiled templates bypass the 13-stage interpreted pipeline entirely.

### Ranked Performance Bottlenecks

| # | Bottleneck | Impact | Location |
|---|-----------|--------|----------|
| P1 | DB query per event fire (×3 systems) | Every event fire → at least 1 DB query (IntegrationBridge) + 1 DB query (EventTriggers) | EventBus, IntegrationBridge, EventTriggers |
| P2 | ~~TemplateEngine interpreted, not compiled~~ | **RESOLVED 2026-04-16** — v4 Parser, AST, RenderContext, FilterRegistry, and CMS adapters implemented. `DISYL_COMPILED_MODE=true` activates compiled fast path (parse → PHP class → opcache). | TemplateEngine.php, kernel/DiSyL/v4/ |
| P3 | ~~processControlStructures O(N²)~~ | **RESOLVED 2026-04-16** — Rewritten as single-pass O(N) scanner. control_ms avg 40.5→9ms (−78%). | TemplateEngine.php |
| P3a | ~~processVariables multi-pass + no cache~~ | **RESOLVED 2026-04-16** — Rewritten as single-pass with resolution cache. variables_ms max 148→14ms (−90%). | TemplateEngine.php |
| P4 | File-based capability metrics with flock | Serial bottleneck under concurrency | CapabilityBus.php |
| P5 | debug_backtrace() per module query | Expensive; cached but cliff-edge flush | KernelPDO.php |
| P6 | Cache clearByUrlPattern O(n) full scan | Reads + decompresses every cache file | Cache.php |
| P7 | IntegrationCatalog build() — 8 SQL queries | Heavyweight admin operation | IntegrationCatalog.php |
| P8 | eventAvailableVars filesystem fallback | Scans all module.json files | EventTriggers.php, IntegrationBridge.php |

---

## 7. Architectural Gaps — Consolidated

### Design-Level Issues

| # | Gap | Impact |
|---|-----|--------|
| A1 | **No interface contracts for core classes** | EventBus, Hooks, Cache, CapabilityBus, CapabilityRegistry all concrete — tight coupling, hard to mock |
| A2 | **Singleton pattern everywhere** | App, EventBus, Hooks, TenantResolver, ReactiveContext — all require `reset()` hacks for testing |
| A3 | **EventTriggers is 775 lines of procedural code** | Only kernel file without a class; uses `$GLOBALS` for state |
| A4 | **IntegrationBridge is entirely static** | Cannot be dependency-injected or mocked; only `$activeDepth` as instance state |
| A5 | **No DI container** | Everything flows through `app()` global; no constructor injection for kernel subsystems |
| A6 | **Compiled template pipeline disconnected** | 1,250 lines of compiler code targeting non-existent v4 AST classes, not wired to live engine |
| A7 | **CapabilityProviderContract is orphaned** | Interface defined but never validated or consumed by Bus/Registry |
| A8 | **DRY violations** | `baseId()`/`majorVersion()` in 3 files; `normalizeId()`/`defaultLabel()` in 2 files |

### Missing Infrastructure

| # | Missing | Impact |
|---|---------|--------|
| A9 | Event/trigger config caching | DB query per fire is the #1 performance issue |
| A10 | Compilation mode for TemplateEngine | Regex interpretation on every render is the #2 performance issue |
| A11 | Advisory lock for migrations | Concurrent deploys can corrupt migration state |
| A12 | Custom workflow definitions | Only CMS hardcoded; no module API for declaring workflows |
| A13 | Circuit breaker in IntegrationBridge | Failed integrations keep getting called |
| A14 | Retry logic for trigger execution | `retry_count` stored but never used |
| A15 | Key rotation for Crypto | Single static key with no versioning |
| A16 | RS256/ES256 for JWT | Only HS256; no asymmetric verification |

---

## 8. Test Coverage Analysis

### Kernel/DiSyL Test Files — 30 files, 500+ assertions

| Test File | Assertions | Coverage Target | Quality |
|-----------|------------|----------------|---------|
| kernel_hardening_test | 40+ | SecurityHeaders, CSRF, Hooks, Events, Cache, Capabilities | ✅ Comprehensive |
| disyl_engine_test | 100+ | TemplateEngine variables, filters, conditions, loops | ✅ Comprehensive |
| disyl_v4_test | 30 | v4 features: verbatim, literal, script context, json filter | ✅ Good |
| disyl_template_cache_test | 5 | Cache sentinel HMAC validation | ✅ Focused |
| tenancy_e2e_test | 55+ | Full tenant lifecycle, JWT binding, isolation | ✅ Comprehensive |
| request_dispatch_integration_test | 60+ | Full entrypoint integration, store portal, admin routes | ✅ Comprehensive |
| integration_bridge_ecommerce_wms_test | 45+ | Bridge lifecycle, idempotency, version lock | ✅ Comprehensive |
| render_context_contracts_test | 45+ | Per-module contracts, mismatch detection, strict mode | ✅ Comprehensive |
| entity_context_registry_test | 35+ | Registry, resolution, hook extension, course/service | ✅ Good |
| entity_context_runtime_bridge_test | 20+ | Runtime state, activation, guest fallback | ✅ Good |
| capability_registry_introspection_test | 40+ | Registry, catalog, version resolution | ✅ Good |
| admin_kernel_control_plane_api_test | 35+ | Events, triggers, executions, integrations API | ✅ Good |
| manifest_settings_defaults_test | 30+ | 10-module manifest→settings contract | ✅ Good |
| workflow_cms_integration_test | 10 | Draft→review transition only | ⚠️ Minimal |
| trigger_validation_test | 4 | Save valid/invalid trigger | ⚠️ Minimal |
| kernel_entity_authority_conflict_test | 2 | Single conflict scenario | ⚠️ Minimal |

### Critical Coverage Gaps

| Missing Coverage | Impact |
|-----------------|--------|
| Circuit breaker behavior under failure load | Breaker registered but never tested tripping/recovering |
| Multi-provider pipeline/fanout call modes | Capability system supports these but no test |
| Workflow lifecycle beyond draft→review | No approve/reject/publish/rollback tests |
| DiSyL block/extends inheritance | Core templating feature, zero test coverage |
| DiSyL error mode (undefined variables) | No test for what happens with missing context keys |
| DiSyL include with circular reference | Circular detection exists but untested |
| Concurrent migration races | MigrationRunner has no locking, no concurrency test |
| EventBus listener execution order | No test for priority ordering guarantee |
| Rate limit decay/reset in triggers | Only blocking tested, not recovery |
| Hydration system end-to-end | No test for island render → hydrate → interactive |
| Reactive signal system | SignalSystem has 586 lines, zero test coverage |
| TenantResolver subdomain strategy | Strategy returns null, no test |
| ModuleDB CTE/complex SQL handling | SQL firewall regex limitations untested |

---

## 9. Documentation Gaps

### Missing Documentation Files

The following kernel subsystems have NO standalone documentation:

| Subsystem | Lines of Code | Importance |
|-----------|--------------|------------|
| Entity Context System | 980 | High — complex registry + profiles + capabilities + blocks |
| Entity Authority System | 100 | Medium — governance for data ownership |
| Workflow System | 470 | Medium — state machine used by CMS, extensible |
| Caching Strategy | 994 | Medium — multi-tier with tags, LRU, compression |
| DiSyL Component System | 2,700 | High — 6 files, definitions, instances, loading, parsing, slots |
| DiSyL Hydration System | 1,140 | Medium — islands architecture, server→client bridge |
| DiSyL Islands Architecture | (part of hydration) | Medium — documented in roadmap but no spec |
| DiSyL Reactive System | 1,400 | Medium — signals, effects, HTMX, Turbo Streams |

Combined: **~7,800 lines of undocumented code** across 8 subsystems.

### Existing Documentation Quality

| Doc File | Assessment |
|----------|------------|
| architecture.md | ✅ Comprehensive (~600 lines) |
| module-development-guide.md | ✅ Complete reference (~800 lines) |
| module-quickstart.md | ✅ Good tutorial (~500 lines) |
| api-reference.md | ✅ Good (~500 lines) |
| security-checklist.md | ✅ Complete for current state |
| installation.md | ⚠️ Dev-focused; no production deployment guide |
| ikabud-roadmap.md | ✅ Clear 7-phase plan |
| kernel-triggers-capabilities-plan.md | ✅ Good 7-milestone plan |
| kernel-stable-contracts.md | ✅ Good formal contracts |
| cross-module-playbook.md | ✅ Excellent decision tree |
| integration-bridge.md | ✅ Complete with examples |

---

## 10. Dead Code & Technical Debt

### Dead Code Inventory

| File | Lines | Type | Recommendation |
|------|-------|------|----------------|
| DiSyLEngine.php | 970 | Constructor throws; 80+ methods unreachable | **Remove or quarantine to `_future/`** |
| IslandsRuntime.php | 26 | Empty backward-compat shim | Remove |
| SecurityHeaders::getBaseDomain() | ~15 | Defined, never called | Remove |
| Grammar.php v11 keywords | ~80 | Constants for unimplemented features | Keep but document as future |
| ComponentInstance stubs | ~30 | getComputed, callMethod, triggerWatchers | Document as stubs |

**Total dead code: ~1,100 lines**

### Duplicate Definitions (Fatal)

| Entity | File A | File B | Resolution |
|--------|--------|--------|------------|
| `HydrationStrategy` enum | HydrationRuntime.php (`IMMEDIATE`) | HydrationStrategy.php (`LOAD`) | Remove one; standardize enum member names |
| `SlotDefinition` class | ComponentDefinition.php (mutable) | SlotSystem.php (readonly) | Consolidate to one canonical definition |

### Technical Debt Patterns

| Pattern | Instances | Impact |
|---------|-----------|--------|
| `$GLOBALS` usage | EventTriggers.php, bootstrap.php request context | Fragile global state |
| Hardcoded allowed callers | WorkflowRuntime `['cms', 'guidance', 'workflow', 'kernel']` | New modules locked out |
| Source→table map | App.php `['kernel' => 'users', ...]` | New auth sources require code edit |
| Legacy naming | Cache APCu key `'guidance_cache_stats'` | Confusing origin |
| Namespace mismatch | TemplateCache compiled class `...Core.DiSyL...` vs actual `...DiSyL...` | require_once failure |

---

## 11. Dependency Graph

### Kernel Internal Dependencies

```
App (1,352 lines)
├── Hooks (198)
├── EventBus (453)
│   └── IntegrationBridge (804) ──static──▶ CapabilityBus
├── JWT (173)
├── Cache (994)
├── TenantResolver (325)
├── WorkflowRuntime (470) ──▶ Cache, EventBus
├── CapabilityRegistry (177) ◄── CapabilityBus (1,074)
├── ContextRegistry (762) ──▶ Hooks
├── EntityAuthorityRegistry (57)
├── SyncContractRegistry (42)
├── Services/DatabaseManager (442) ──▶ Crypto (72)
└── DiSyL/TemplateEngine (2,659)

EventTriggers (775, procedural)
──▶ App, EventBus, CapabilityBus, Cache, KernelPDO

Database/KernelPDO (178) ──▶ ModuleDB (369, via backtrace enforcement)
Database/ConnectionPool (189)
Database/MigrationRunner (495)
Database/QueryBuilder (694)

Http/SecurityHeaders (189)
Http/TenantEntryRouter (325)

ControlPlane/IntegrationCatalog (865) ──▶ CapabilityCatalog (583)
```

### DiSyL Internal Dependencies

```
TemplateEngine (2,659) [LIVE]
├── ComponentRegistry (503, static)
└── (standalone — no DiSyL sub-imports)

DiSyLEngine (970) [DEAD — throws on construct]
├── TemplateCache (317) ──▶ TemplateCompiler (581)
│   └── IncrementalCompiler (177)
├── TreeShaker (129)
├── ComponentLoader (181)
├── IslandRegistry (51)
└── ClientBlockRegistry (41)

Component/
├── ComponentDefinition (473) ◄▸ CONFLICT ▸► SlotSystem (444)
├── ComponentInstance (387)
├── ComponentLoader (181)
├── ComponentParser (920)
└── SingleFileComponent (295)

Hydration/
├── HydrationRuntime (631) ◄▸ CONFLICT ▸► HydrationStrategy (22)
├── Island (34), IslandManifest (48), IslandRegistry (51)
├── IslandRenderer (66)
├── ClientBundleGenerator (60)
└── disyl-runtime.js (288)

Reactive/
├── ClientBlock (114) + ClientBlockRegistry (41)
├── HTMXIntegration (106) + HTMXRequest (90) + HTMXResponse (176)
├── HTMXHeaders (26), SwapStrategy (24)
├── OOBSwap (27)
├── ReactiveState (65)
├── SignalSystem (586)
└── TurboStreamResponse (79)
```

---

## 12. Prioritized Remediation Plan

### Tier 0 — Fatal Errors & Critical Security (Immediate)

| # | Item | Complexity | Files |
|---|------|-----------|-------|
| R1 | Fix HydrationStrategy enum conflict | S | Remove one file or reconcile member names |
| R2 | Fix SlotDefinition class conflict | S | Consolidate to one definition, import from canonical location |
| R3 | Fix ContextRegistry undefined properties ($schemas, $profiles, $modes) | S | Either add properties or remove the three stub methods |
| R4 | Add htmlspecialchars() to TurboStreamResponse $target and $content | S | TurboStreamResponse.php |
| R5 | Add JSON_HEX_TAG to IslandManifest.generateScriptTag() | S | IslandManifest.php |
| R6 | Sanitize ComponentLoader component names against path traversal | S | ComponentLoader.php |
| R7 | Add input validation for ClientBlock $handler and $code sources | M | ClientBlock.php |
| R8 | Fix TemplateCache sentinel to use a proper application secret | S | TemplateCache.php |
| R9 | Fix TemplateCache namespace mismatch (`Core` segment) | S | TemplateCache.php |

### Tier 1 — High-Impact Reliability & Performance (This Sprint)

| # | Item | Complexity | Files |
|---|------|-----------|-------|
| R10 | Cache event/trigger configs per request (eliminate P1) | M | EventBus, IntegrationBridge, EventTriggers — add request-level cache for DB lookups |
| R11 | Add advisory lock to MigrationRunner | S | MigrationRunner.php — `GET_LOCK('ikabud_migration', 10)` |
| R12 | Move capability metrics/breaker to APCu | M | CapabilityBus.php — replace file-based storage with APCu (already available) |
| R13 | Add JWT algorithm validation during verify | S | JWT.php — check header `alg` matches `$this->algorithm` |
| R14 | Add path validation for absolute paths in resolveTemplatePath() | S | TemplateEngine.php — reject or validate absolute paths |
| R15 | Implement retry/timeout enforcement for triggers | M | EventTriggers.php — use stored retry_count and timeout_ms values |
| R16 | Escape $modulePath in ClientBundleGenerator | S | ClientBundleGenerator.php |
| R17 | Escape $island->mediaQuery in IslandRenderer | S | IslandRenderer.php |
| R18 | Escape $targetId in OOBSwap | S | OOBSwap.php |
| R19 | Add JSON_HEX_TAG to ReactiveState.toJSON() | S | ReactiveState.php |

### Tier 2 — Architectural Maturation (Next Sprint)

| # | Item | Complexity | Files |
|---|------|-----------|-------|
| R20 | Extract interfaces for EventBus, Cache, CapabilityBus, CapabilityRegistry | M | New interface files + type updates |
| R21 | Convert EventTriggers.php to a class | L | EventTriggers.php — 775 lines; eliminate $GLOBALS |
| R22 | Make IntegrationBridge instance-based | M | IntegrationBridge.php — inject via App |
| R23 | ~~Connect compiled template pipeline to TemplateEngine~~ | ~~L~~ | **DONE 2026-04-16** — v4 Parser + AST + RenderContext + FilterRegistry + CMS adapters implemented. TemplateEngine render() wired to compiled fast path via `execute()`. |
| R24 | Add output size limit to TemplateEngine | S | TemplateEngine.php — abort if output exceeds configurable ceiling |
| R25 | Clean up dead code (DiSyLEngine.php, IslandsRuntime.php, getBaseDomain) | S | Remove ~1,100 lines |
| R26 | Add custom workflow registration API | M | WorkflowRuntime.php — accept module-declared definitions |
| R27 | Replace hardcoded source→table map with manifest declaration | M | App.php + module.json auth_table key |
| R28 | Consolidate baseId()/majorVersion() into shared trait | S | 3 files |

### Tier 3 — Test Coverage (Ongoing)

| # | Item | Complexity | Target |
|---|------|-----------|--------|
| R29 | Circuit breaker failure/recovery test | M | CapabilityBus — simulate 5 failures, verify breaker opens, verify half-open recovery |
| R30 | Pipeline/fanout mode capability tests | M | CapabilityBus — test all 3 call modes with multi-provider scenarios |
| R31 | Full workflow lifecycle test | M | WorkflowRuntime — draft→review→approve→publish→reject→rollback |
| R32 | DiSyL extends/block inheritance test | M | TemplateEngine — parent→child→grandchild block override |
| R33 | DiSyL circular include detection test | S | TemplateEngine — A includes B includes A |
| R34 | SignalSystem reactive graph test | M | Signal→Computed→Effect dependency tracking |
| R35 | Concurrent migration race test | M | MigrationRunner — parallel execution safety |
| R36 | ModuleDB CTE/complex SQL test | M | SQL firewall — WITH clause, UNION, subquery |
| R37 | Hydration end-to-end test | L | Island render → serialize → hydrate → interactive |

### Tier 4 — Documentation (Parallel Track)

| # | Item | Files |
|---|------|-------|
| R38 | Write entity-context-system.md | Covers ContextRegistry, ContextProfile, resolution, customizer schema |
| R39 | Write disyl-component-system.md | Covers ComponentDefinition, Instance, Loader, Parser, SlotSystem |
| R40 | Write disyl-hydration-system.md | Covers HydrationRuntime, Islands, renderer, client runtime |
| R41 | Write disyl-reactive-system.md | Covers Signals, HTMX integration, ClientBlocks, OOB swaps |
| R42 | Write workflow-system.md | Covers WorkflowRuntime, state machine, transitions, events |
| R43 | Write caching-strategy.md | Covers Cache tiers, tags, LRU, compression, invalidation |
| R44 | Write entity-authority-system.md | Covers EntityAuthorityRegistry, SyncContractRegistry |
| R45 | Write production-deployment-guide.md | Server requirements, sizing, cron, monitoring, backup |

---

## Appendix A — Complete Method Inventory

### Kernel — 10 Core Files

| File | Lines | Methods | Pattern |
|------|-------|---------|---------|
| App.php | 1,352 | 67 | Singleton + Service Locator |
| EventBus.php | 453 | 21 | Singleton + Observer |
| Hooks.php | 198 | 10 | Singleton + Chain of Responsibility |
| Cache.php | 994 | 37 | Multi-Tier + Atomic Write + LRU |
| Crypto.php | 72 | 3 | Facade |
| JWT.php | 173 | 8 | Custom minimal implementation |
| IntegrationBridge.php | 804 | 24 | Static Mediator + Payload Mapper |
| TenantResolver.php | 325 | 12 | Singleton + 7-Strategy + 3-Tier Cache |
| WorkflowRuntime.php | 470 | 20 | State Machine + Repository |
| EventTriggers.php | 775 | 20 | Procedural + Rate Limiting |
| **Total** | **5,616** | **222** | |

### Kernel — 27 Subsystem Files

| File | Lines | Methods | Pattern |
|------|-------|---------|---------|
| CapabilityRegistry.php | 177 | 11 | Registry |
| CapabilityBus.php | 1,074 | 36 | Bus + Circuit Breaker + Schema |
| CapabilityCatalog.php | 583 | 22 | Read-only catalog |
| CapabilityTestRunner.php | 284 | 8 | Test fixture runner |
| Exceptions (3 files) | 35 | 3 | Exception hierarchy |
| AuthContract.php | 48 | 6 | Interface |
| CacheContract.php | 39 | 5 | Interface |
| CapabilityProviderContract.php | 35 | 4 | Interface (orphaned) |
| DatabaseContract.php | 71 | 8 | Interface |
| LogContract.php | 32 | 2 | Interface |
| ModuleContext.php | 245 | 22 | Facade (implements Auth+Log) |
| ModuleDB.php | 369 | 20 | SQL Firewall |
| ConnectionPool.php | 189 | 7 | Pool with idle validation |
| KernelPDO.php | 178 | 6 | PDO + backtrace enforcement |
| KernelPDOStatement.php | 35 | 1 | Statement + event |
| MigrationRunner.php | 495 | 18 | Forward migration + rollback |
| QueryBuilder.php | 694 | 40 | Fluent query builder |
| DatabaseManager.php | 442 | 18 | Multi-tenant connection manager |
| SecurityHeaders.php | 189 | 8 | CSP + HSTS + cookie |
| TenantEntryRouter.php | 325 | 8 | URI rewriter + WAF reject |
| EntityAuthorityRegistry.php | 57 | 5 | Authority registry |
| SyncContractRegistry.php | 42 | 3 | Contract registry |
| ContextProfile.php | 218 | 11 | Value object |
| ContextRegistry.php | 762 | 32 | Entity-context resolution |
| IntegrationCatalog.php | 865 | 26 | Control-plane catalog |
| **Total** | **7,483** | **~330** | |

### DiSyL — 39 Files

| File | Lines | Methods | Notes |
|------|-------|---------|-------|
| TemplateEngine.php | 2,659 | 60+ | **LIVE ENGINE** |
| DiSyLEngine.php | 970 | 80+ | **DEAD CODE** |
| Grammar.php | 289 | 8 | Constants + validation |
| ComponentRegistry.php | 503 | 11 | Static registry |
| TemplateCompiler.php | 581 | 29 | AST→PHP (disconnected) |
| IncrementalCompiler.php | 177 | 11 | Manifest-tracked |
| CompiledTemplate.php | 146 | 11 | Abstract base |
| TemplateCache.php | 317 | 14 | HMAC sentinel |
| TreeShaker.php | 129 | 5 | Dead code analysis |
| ComponentDefinition.php | 473 | 20 | + PropDefinition + SlotDefinition |
| ComponentInstance.php | 387 | 25 | Stubs for compute/method/watch |
| ComponentLoader.php | 181 | 8 | Directory scanner |
| ComponentParser.php | 920 | 30+ | Token-based parser |
| SingleFileComponent.php | 295 | 11 | `.disyl` file parser |
| SlotSystem.php | 444 | 18 | 4 classes + duplicate SlotDef |
| HydrationRuntime.php | 631 | 20 | + HydrationData + duplicate enum |
| HydrationStrategy.php | 22 | 0 | Duplicate enum |
| Island.php | 34 | 1 | Value object |
| IslandManifest.php | 48 | 2 | JSON manifest |
| IslandRegistry.php | 51 | 5 | Registry |
| IslandRenderer.php | 66 | 2 | HTML renderer |
| IslandsRuntime.php | 26 | 0 | Empty shim |
| ClientBundleGenerator.php | 60 | 2 | Script/preload |
| disyl-runtime.js | 288 | 7 | Client JS |
| ClientBlock.php | 114 | 8 | JS code gen |
| ClientBlockRegistry.php | 41 | 4 | Registry |
| HTMXHeaders.php | 26 | 0 | Constants |
| HTMXIntegration.php | 106 | 3 | Template↔HTMX |
| HTMXRequest.php | 90 | 12 | Request parser |
| HTMXResponse.php | 176 | 16 | Response builder |
| OOBSwap.php | 27 | 1 | Value object |
| ReactiveState.php | 65 | 8 | Observable state |
| SignalSystem.php | 586 | 35+ | Full reactive graph |
| SwapStrategy.php | 24 | 0 | Enum |
| TurboStreamResponse.php | 79 | 10 | Turbo Streams |
| Exceptions (4 files) | 225 | 4 | Exception hierarchy |
| **Total** | **~11,258** | **~480** | |

## Appendix B — Hardcoded Values Registry

| Value | Location | Recommendation |
|-------|----------|----------------|
| `'Ikabud'` | App.php | Move to env/config |
| `86400` JWT expiry | App/JWT | Environment variable |
| `'ikabud'` JWT issuer | JWT.php | Config |
| Source→table map | App.php | Module manifest declaration |
| `['cms', 'guidance', 'workflow', 'kernel']` | WorkflowRuntime | Module manifest declaration |
| `'guidance_cache_stats'` | Cache.php | Rename to `'ikabud_cache_stats'` |
| `MAX_DEFERRED_FLUSH_BATCHES = 100` | EventBus | Environment variable |
| `100` max history | EventBus | Environment variable |
| `5000ms` trigger timeout default | EventTriggers | Environment variable |
| `IDLE_VALIDATION_SECONDS = 15` | ConnectionPool | Environment variable |
| `256` backtrace cache entries | KernelPDO | Constant (acceptable) |
| All TemplateEngine depth constants | TemplateEngine | At minimum, move to constructor params |
| `'DISYL_CACHE_' . sha1(...)` sentinel | TemplateCache | Use APP_ENCRYPTION_KEY |
| CDN hosts in CSP | SecurityHeaders | Config array |
| HTMX attribute list | TemplateEngine | Constant array (acceptable) |

---

*End of evaluation. All findings based on direct code inspection of 76 files totaling ~18,700 lines. No documentation claims accepted without code verification.*
