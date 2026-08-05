# Ikabud Kernel OS 6.1.0 — Production Deployment Readiness Assessment

> **Assessment Date:** August 5, 2026
> **Scope:** Full kernel architecture + DiSyL template engine (grammar, lexer, parser, evaluator, compiler)
> **Reviewers:** Automated code review — Explore agents (kernel + DiSyL) + senior architect synthesis
> **Codebase:** 267 kernel PHP files (~65,500 lines) + 88 DiSyL engine files (~25,000 lines)

---

## Executive Summary

The Ikabud Kernel OS 6.1.0 and DiSyL v4.7 engine are **production-capable with specific, actionable hardening gaps**. The security architecture is well-designed at the high level — whitelisted function dispatch, capability-based sandboxing, sentinel-validated cache files, path traversal protection on all file operations, and proper CSP/CSRF/JWT implementations. The compiled-mode DiSyL pipeline (v4 AST → PHP code generation) is the correct architectural direction and shows mature engineering.

**Production readiness verdict: CONDITIONAL PASS.** Deployment can proceed, but the 6 critical items below should be resolved within the first week of production. Medium-severity items should be addressed in the first month.

### Executive-level findings

| Area | Status | Key risk |
|---|---|---|
| Kernel security (auth, CSRF, JWT) | ✅ Pass | Only HS256, no automatic CSRF middleware |
| Multi-tenancy isolation | ✅ Pass | Session-stored tenant IDs need re-validation |
| CSP / security headers | ✅ Pass | `'unsafe-eval'` required for Alpine.js/Tailwind CDN |
| Module packaging (ZIP install) | ✅ Pass | No code-signing verification |
| DiSyL parser security | ⚠️ Conditional | No recursion depth guard, no source size limit |
| DiSyL compiled cache | ⚠️ Conditional | `eval()` fallback with race condition window |
| DiSyL resource protection | ⚠️ Conditional | `range()`/`str_repeat()` DoS, no loop iteration guards |
| DiSyL architecture | ⚠️ Conditional | God Object (7,600-line TemplateEngine), dual pipeline |

---

## 1. Scope and Methodology

### 1.1 What was reviewed

This assessment covers the entire kernel runtime and the DiSyL template engine, which together form the critical path for every request served by the application.

**Kernel core:**
- `bootstrap.php` — environment loading, error handler, request context, CSP nonce
- `public/index.php` — front controller, routing, static serving, CORS, CSRF
- `kernel/App.php` — application singleton, auth, rendering, DB, JWT, CSRF
- `kernel/Http/SecurityHeaders.php` — CSP, HSTS, Referrer-Policy, Permissions-Policy
- `kernel/Http/CsrfManager.php` — token generation, validation, rotation
- `kernel/JWT.php` — HS256 JWT with key rotation
- `kernel/TenantResolver.php` — multi-tenant context resolution
- `kernel/Cache.php` — file-based + APCu caching with atomic writes
- `kernel/Crypto.php` — AES-256-GCM encryption with key rotation
- `kernel/EventBus.php` — inter-module event system with wildcards
- `kernel/Hooks.php` — filter/action hook system
- `kernel/WorkflowEngine.php` — YAML-driven state machine engine
- `kernel/Capabilities/CapabilityBus.php` — capability dispatch with circuit breakers
- `kernel/Database/KernelPDO.php` — guarded PDO with table-access enforcement

**Module system:**
- `src/helpers/module-manager.php` — discovery, ZIP install, suite graph, contribution registry
- `src/helpers/manifest-validation.php` — manifest schema v1 + additive suite fields validation

**DiSyL engine:**
- `kernel/DiSyL/v4/Parser.php` — single-pass recursive-descent parser (2,030 lines)
- `kernel/DiSyL/TemplateEngine.php` — monolithic renderer (7,600 lines)
- `kernel/DiSyL/ExpressionEvaluator.php` — variable resolution, arithmetic, conditions (800 lines)
- `kernel/DiSyL/v4/FunctionRegistry.php` — whitelisted template functions (140 lines)
- `kernel/DiSyL/v4/FilterRegistry.php` — default filter library (199 lines)
- `kernel/DiSyL/Security/Sandbox.php` — capability-based runtime sandbox (210 lines)
- `kernel/DiSyL/Compiler/TemplateCompiler.php` — AST → PHP code generation (800 lines)
- `kernel/DiSyL/Compiler/TemplateCache.php` — cache management, sentinel validation (500 lines)
- `kernel/DiSyL/Compiler/CompiledTemplate.php` — compiled template base class (200 lines)

### 1.2 What was NOT reviewed

- Module business logic (CMS, ecommerce, bakeshop, etc.) — module-level review is out of scope
- Frontend JavaScript (builder UI React app, Alpine.js templates)
- Android mobile client (`android/` directory)
- Database migrations (schema correctness)
- Third-party vendor code (`vendor/`)
- Test suite quality/coverage
- Build tooling (composer, npm, Vite)

### 1.3 Methodology

Both the kernel and DiSyL engine were explored by dedicated subagents using source-level file reading and pattern-based search. Each file was analyzed for: security vulnerabilities (SQLi, XSS, CSRF, auth bypass, SSTI, file inclusion), production-readiness concerns (error handling, resource exhaustion, race conditions), and architectural anti-patterns. Findings were cross-referenced against the codebase to verify line references and contextual accuracy. Severity ratings follow the rubric below.

| Severity | Definition |
|---|---|
| **CRITICAL** | Exploitable without authentication, causes data loss, or bypasses tenant isolation |
| **HIGH** | Exploitable under specific conditions, degrades security posture, or causes Denial of Service |
| **MEDIUM** | Robustness gap that could become exploitable under edge cases, or architectural risk |
| **LOW** | Best-practice deviation, missing hardening, or documentation gap |

---

## 2. Kernel Architecture Review

### 2.1 Entrypoints

#### `bootstrap.php` (~2,900 lines)
**Purpose:** Application bootstrap — environment loading, path constants, error handling, session configuration, request context store, CSP nonce, redirect validation.

| Severity | Finding |
|---|---|
| **LOW** | `.env` parsing uses `explode('=', $line, 2)` — multi-line values unsupported (non-standard in `.env` but sometimes used) |
| **LOW** | `$config['control_database']` falls back to `config/database.php` if `control_database.php` doesn't exist — could cause control plane to share the app database unintentionally |
| **LOW** | `.env` key validation regex `/^[A-Z][A-Z0-9_]*$/` prevents lowercase keys — non-standard but deliberate |
| ✅ | `display_errors = 0` in production mode |
| ✅ | `kernel_request_context_*()` functions provide typed, secure context store with `_kernel_` prefix protection |
| ✅ | Redirect target validation (`kernel_validate_redirect_target`) with origin allowlisting, scheme+host+port validation |

#### `public/index.php` (~940 lines)
**Purpose:** Front controller — fast-path caching, request routing, static file serving, CSRF, CORS, session management.

| Severity | Finding |
|---|---|
| **LOW** | Static file MIME types are hardcoded — new file types (`.avif`, `.mp4`) need manual addition |
| **LOW** | Module uploads static handler walks the entire `modules/` tree on every upload request |
| ✅ | **Fast-path page cache** BEFORE kernel boot (~5-20ms cache hits) |
| ✅ | **Fast-path health check** BEFORE kernel boot (~1ms) |
| ✅ | Path traversal hardening on all asset serving (`..` rejection, `realpath` verification) |
| ✅ | CORS with explicit allowlist (no `*` with credentials) |
| ✅ | Session gating for stateless API routes |
| ✅ | JWT sliding refresh on shutdown |
| ✅ | Slow request logging (>1s threshold) |

### 2.2 Security Infrastructure

#### `kernel/Http/SecurityHeaders.php` (~190 lines)
| Severity | Finding |
|---|---|
| **MEDIUM** | `script-src` includes `'unsafe-eval'` and `'unsafe-inline'` — documented as required for Alpine.js/Tailwind CDN. The nonce transition plan is documented but not yet active by default |
| **LOW** | `img-src` uses `https:` — allows images from any HTTPS origin, should be narrowed |
| **LOW** | Missing `Cross-Origin-Opener-Policy` and `Cross-Origin-Embedder-Policy` headers |
| ✅ | CSP nonce transition mode (`CSP_NONCE_MODE`) with documented guardrails |
| ✅ | Proxy-aware HTTPS detection (`X-Forwarded-Proto`) |
| ✅ | PHP session security: `HttpOnly`, `Secure`, `SameSite` |

**Critical note on CSP**: The canonical `script-src` is `'self' 'unsafe-inline' 'unsafe-eval' https://cdn.tailwindcss.com https://unpkg.com https://maps.googleapis.com`. The `'unsafe-eval'` is **mandatory** — Alpine.js v3 uses `new Function()` and Tailwind CDN JIT mode uses eval-based class scanning. Dropping it silently breaks all Tailwind utility classes and every Alpine-driven component.

#### `kernel/Http/CsrfManager.php` (~110 lines)
| Severity | Finding |
|---|---|
| **LOW** | Token stored in `$_SESSION['_csrf_token']` without expiry — lives for entire session lifetime |
| **INFO** | Per-form or per-request rotation available via `rotate()` but not enforced by default |
| **INFO** | `enforce()` does not automatically gate GET vs. POST — handler must call it explicitly |
| ✅ | `random_bytes(32)` for token generation — cryptographically secure |
| ✅ | `hash_equals()` for timing-safe comparison |
| ✅ | Supports both `$_POST['_token']` and `X-CSRF-Token` header |

#### `kernel/JWT.php` (~235 lines)
| Severity | Finding |
|---|---|
| **MEDIUM** | **Only HS256 supported** — all tokens are symmetric. For an API-driven system with Android clients, the secret must be shared with every client that needs to verify tokens. Architecturally limiting for distributed verification |
| **LOW** | Token extraction uses `getallheaders()` — Apache-specific, may fail under FastCGI/FPM without the Apache compatibility module |
| ✅ | Algorithm validation prevents algorithm confusion attacks |
| ✅ | Key ring with fallback for rotation support |
| ✅ | `token_version` claim for invalidation on password change |
| ✅ | Minimum 32-char key requirement |
| ✅ | `jti` claim uses `random_bytes(16)` |

#### `kernel/Crypto.php` (~155 lines)
| Severity | Finding |
|---|---|
| **LOW** | No key derivation function (KDF) used — raw key from environment variable must be exactly 32 random bytes (base64-encoded). Documentation should emphasize this |
| ✅ | AES-256-GCM (authenticated encryption) — correct choice |
| ✅ | Random IV per encryption (`random_bytes(12)`) |
| ✅ | Key ring with fallback decryption |
| ✅ | `reEncrypt()` method for key rotation |

### 2.3 Multi-Tenancy

#### `kernel/TenantResolver.php` (~340 lines)
| Severity | Finding |
|---|---|
| **MEDIUM** | **Subdomain strategy is unimplemented** — stub method returns `null`. Multi-tenant subdomain routing is non-functional |
| **LOW** | Session strategy trusts `$_SESSION['tenant_id']` without re-validation against active tenant status — a deactivated tenant could still be resolved from session |
| **LOW** | Header strategy checks `$isSuperadmin` but doesn't validate that the header value is a valid/active tenant ID |
| ✅ | Multiple resolution strategies with configurable priority |
| ✅ | Control-plane host→tenant mapping with APCu caching |
| ✅ | Superadmin-only gate for `X-Tenant` header |

#### `kernel/Database/KernelPDO.php` (~250 lines)
| Severity | Finding |
|---|---|
| **LOW** | `isDirectModuleCaller()` uses `debug_backtrace()` on every query — performance concern on high-traffic sites (mitigated by `$activeModule` fast-path caching) |
| ✅ | Module table access enforced via ModuleContext |
| ✅ | Escalation counter for kernel cross-cutting operations |
| ✅ | Self-healing runtime tables list for auto-repair |

### 2.4 Caching

#### `kernel/Cache.php` (~1,145 lines)
| Severity | Finding |
|---|---|
| **LOW** | Cache key uses `md5($uri)` — not collision-resistant, but keys derived from internal URIs, not user input |
| **INFO** | `$maxCacheSizeMB = 0` means unlimited — production should set a limit |
| ✅ | Atomic writes (temp file → rename) |
| ✅ | Multi-tier (APCu + file) with proper promotion |
| ✅ | `allowed_classes => false` on `unserialize` — prevents PHP object injection |
| ✅ | Compression for entries >1KB |

### 2.5 Module System

#### `src/helpers/module-manager.php` (~4,600 lines)
| Severity | Finding |
|---|---|
| **LOW** | `installModuleFromZip` creates directories with `0775` — world-readable |
| **LOW** | **No code-signing verification** for module packages — Zip signature check is present (`PK\x03\x04`) but not cryptographic |
| **INFO** | `moduleInstallTargetDirForId` uses heuristic suite detection — could misclassify modules |
| ✅ | **Zip Slip prevention** — `sanitizeEntryName()` rejects `..`, `\0`, absolute paths, Windows drive letters |
| ✅ | **External attributes check** — rejects symlinks via `getExternalAttributesIndex` |
| ✅ | **Size limits** — max 200MB uncompressed, max 2000 entries |
| ✅ | **Preflight validation** — all zip entries validated before extraction |
| ✅ | **Post-extraction re-validation** — manifest re-validated against extracted files |
| ✅ | **Suite contract gate** — validated before enable |

#### `src/helpers/manifest-validation.php` (~640 lines)
| Severity | Finding |
|---|---|
| **LOW** | `validateModuleSuiteFleetV1` validates contribution host existence but **not** whether the host declares the location in `extension_points` for `admin_contributions` (only checks for `contributes` entries) |
| ✅ | Structured diagnostic system with severity levels |
| ✅ | Comprehensive field validation: id, version (semver), table names, routes, capabilities, events |
| ✅ | Route path traversal check |
| ✅ | Suite contract validation: all 8 additive fields |
| ✅ | Fleet-level validation: cross-module checks for extends targets, contribution hosts, duplicate IDs |

### 2.6 Application Singleton

#### `kernel/App.php` (~1,860 lines)
| Severity | Finding |
|---|---|
| **MEDIUM** | `authTableMap` uses direct table name concatenation — regex-validated so safe, but string interpolation rather than whitelist |
| **LOW** | `$this->config` is a flat array from `config/app.php` — if writable at runtime, could be configuration injection |
| **LOW** | `buildRenderBaseContext()` passes `$_SERVER` values (`HTTP_HOST`, `REQUEST_URI`) into template context — mitigated by DiSyL auto-escaping |
| ✅ | Singleton pattern with `$booted` flag prevents double-boot |
| ✅ | Re-entrant guard (`$resolvingCurrentUser`) prevents infinite recursion |
| ✅ | Proper `KernelPDO::kernelEscalationEnter/Leave` for audit logging |
| ✅ | Sliding JWT refresh with halfway-lifetime heuristic |
| ✅ | Auth cookie rotation happens on shutdown via `register_shutdown_function` |

---

## 3. DiSyL Engine Review

### 3.1 Architecture Overview

DiSyL is the template language engine (~25,000 lines across 88 PHP files) with two rendering pipelines:

- **Compiled mode** (default since v4.7): Source → `Parser` (AST) → `TemplateCompiler` (PHP code) → `require` cached PHP file
- **Interpreted mode** (fallback): Source → `Parser` → `TemplateEngine` regex-based evaluation

The compiled pipeline is the correct architectural direction. The interpreted pipeline exists for backward compatibility but uses regex-based control structure matching that is fundamentally less maintainable.

**Key subsystems:**
- `v4/Parser.php` — single-pass recursive-descent parser (2,030 lines)
- `TemplateEngine.php` — monolithic renderer (7,600 lines)
- `Compiler/TemplateCompiler.php` — AST → PHP code generation (800 lines)
- `Compiler/TemplateCache.php` — HMAC-sentinel validated cache files (500 lines)
- `Security/Sandbox.php` — capability-based runtime isolation (210 lines)
- `ExpressionEvaluator.php` — expression evaluation (800 lines)
- `v4/FunctionRegistry.php` — whitelisted functions (140 lines)
- `Reactive/` — HTMX/Turbo integration (10 files)
- `AI/` — AI provider integration (5 files)
- `Hydration/` — Island hydration system (8 files)
- `Async/` — Fiber-based async I/O (3 files)

### 3.2 Parser (`v4/Parser.php`)

| Severity | Finding |
|---|---|
| **HIGH** | **No recursion depth guard.** The parser has no `MAX_PARSE_DEPTH` limit on recursive expression parsing or control structure nesting. A template with 1000+ levels of `{if}` nesting will cause a PHP stack overflow. The `parseChildren()` → `parseIf()` → `parseChildren()` cycle is unbounded |
| **MEDIUM** | **No source size limit.** The parser processes the entire source string in memory without guard. A 500MB template would be loaded and parsed without rejection |
| **MEDIUM** | `readTagContent()` could overflow `$this->len` during backslash-escape skip (`$this->pos += 2` without bounds check) in edge cases |
| **LOW** | `readPlainText()` iterates character-by-character — O(n) is acceptable but combined with multiple `strpos()`/`substr()` calls, large templates will be slow |
| ✅ | **Error recovery:** `recoverableParse()` wraps every control-structure parse call — a malformed block doesn't kill the entire template |
| ✅ | **Quote-aware splitting:** `splitCommaTopLevel()` and `splitByPipe()` correctly handle quoted strings and nested braces |
| ✅ | **Expression parsing:** Full recursive-descent with proper operator precedence |
| ✅ | **JS/XSS awareness:** `looksLikeDisyl()` skips `${...}` JS template literals |

### 3.3 Template Engine (`TemplateEngine.php`)

| Severity | Finding |
|---|---|
| **HIGH** | **God Object anti-pattern:** 7,600+ lines in a single class. Concerns include: compilation, interpreted evaluation, component rendering, macro processing, include resolution, extends/layout, caching, security filtering, entity view rendering, form rendering, AI integration, reporting, HTMX integration, island hydration. This makes the class extremely difficult to test, reason about, and secure |
| **MEDIUM** | **Dual pipeline complexity:** Every feature must be implemented twice (interpreted regex-based + compiled AST-based) and kept in sync. The interpreted pipeline will inevitably diverge |
| **MEDIUM** | **Interpreted pipeline uses `preg_replace_callback` extensively** — regex-based control structure matching is prone to catastrophic backtracking and incorrect nesting detection |
| ✅ | Path traversal protection: `resolveTemplatePath()` and `resolveModuleTemplateAliasPath()` use `normalizePath()` with two layers of defense |
| ✅ | Circular include detection: `includeStack` with real paths |
| ✅ | Component depth limit: `COMPONENT_MAX_DEPTH = 30` |
| ✅ | Extends chain limit: `EXTENDS_CHAIN_MAX = 20` |
| ✅ | Output size limit: `MAX_OUTPUT_BYTES = 5MB` |
| ✅ | Verbatim/literal extraction before control structure processing |
| ✅ | Macro isolation at top-level compile only |
| ⚠️ | `?disyl_nocache=1` debug GET parameter read unconditionally — should be gated behind debug mode in production |

### 3.4 Compiled Cache (`Compiler/TemplateCache.php`)

| Severity | Finding |
|---|---|
| **HIGH** | **`eval()` fallback with sentinel chicken-and-egg race.** When a compiled file can't be `require`d (e.g., sentinel algorithm changes), the code falls back to `eval("?>" . $freshCompiledCode).` The sentinel is both the protection AND the reason `eval()` might be needed — if sentinel validation changes, all existing cache files become invalid, and `eval()` is used to regenerate them. During this window, a race condition could allow a compromised cache file to be `eval()`'d |
| **HIGH** | **`?disyl_nocache=1` bypass** forces recompilation and bypasses validity checks. Read unconditionally from `$_GET` |
| **LOW** | **Race condition on cache write:** Two concurrent requests for the same uncached template both compile and write. Atomic rename prevents corruption but CPU is wasted. No `flock()` locking |
| **LOW** | `glob($this->cacheDir . '/Template_*.php')` — if millions of files, could exhaust memory |
| ✅ | Sentinel validation: HMAC using `APP_KEY` when available |
| ✅ | Atomic writes: temp file + rename |
| ✅ | Compiler version in class name — stale files bypassed after upgrades |
| ✅ | Source hash in filename — content changes produce new classes |
| ✅ | `{extends}` mtime tracking — recursively checks ancestor layouts (depth 10) |
| ✅ | `{include}` mtime tracking |
| ✅ | Opcache invalidation after cache write |

### 3.5 Function Registry (`v4/FunctionRegistry.php`)

| Severity | Finding |
|---|---|
| **MEDIUM** | **`range()` DoS:** Accepts `$start`, `$end`, `$step` from template context without validation — `range(1, 1000000000, 1)` creates a billion-element array, exhausting memory |
| **MEDIUM** | **`str_repeat()` DoS:** `$n` capped at `max(0, (int)$n)` but no upper bound — `str_repeat('x', 1000000000)` produces a 1GB string |
| ✅ | **Whitelist-only:** `call()` returns `null` for unregistered functions |
| ✅ | 20 built-in functions — math, strings, counting, formatting, JSON |
| ✅ | Type casting on all inputs |
| ✅ | Registered in `kernel/DiSyL/v4/FunctionRegistry.php` |
| ✅ | `json_encode` mirrors `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE` |
| ✅ | `json_decode` returns associative array with dot-path access |

### 3.6 Filter Registry (`v4/FilterRegistry.php`)

| Severity | Finding |
|---|---|
| **LOW** | **`|raw` filter** passes through unescaped HTML — necessary escape hatch but dangerous. No audit logging for `|raw` usage |
| **LOW** | **`|json` filter** uses `JSON_UNESCAPED_SLASHES` — could allow `</script>` injection in edge cases (script block extraction should prevent) |
| ✅ | Unknown filter returns value unchanged and logs warning — fails safe |
| ✅ | `esc_html`, `esc_attr`, `esc_url`, `esc_js` all use appropriate PHP functions |
| ✅ | `esc_url` protocol whitelist: only `http`, `https`, `mailto`, `tel`, `ftp` — protocol-relative URLs blocked |

### 3.7 Template Compiler (`Compiler/TemplateCompiler.php`)

| Severity | Finding |
|---|---|
| **MEDIUM** | **No while-loop iteration guard:** `compileWhile()` emits a raw PHP `while` loop — infinite loop possible, only stopped by `MAX_OUTPUT_BYTES` or `max_execution_time` |
| **MEDIUM** | **No for-loop iteration guard:** `compileCFor()` similarly has no iteration limit |
| **LOW** | `compileText()` uses `var_export()` — for 10MB text nodes, produces multi-MB PHP string literals |
| ✅ | Each AST node type has dedicated `compile*()` method |
| ✅ | Function calls routed through `FunctionRegistry` — only whitelisted functions |
| ✅ | Filter chains routed through `FilterRegistry` |
| ✅ | Division by zero guards on `/` and `%` |
| ✅ | Include depth protection via `CompiledTemplate::$includeStack` |
| ✅ | Variable access via `$ctx->get()`/`$ctx->set()` — no direct array access |

### 3.8 Security Sandbox (`Security/Sandbox.php`)

| Severity | Finding |
|---|---|
| **MEDIUM** | **Unbounded audit log growth:** `violations.json` is a JSON array that grows without limit — no rotation, size limit, or cleanup. Could fill the disk on sustained attack |
| **LOW** | **`@mkdir($this->auditRoot, 0775, true)`** — world-readable audit directory, should be `0750` or `0700` |
| **LOW** | `pushTrusted()` inside `untrusted` silently ignores the elevation request — stack balanced but "trusted" elevation silently fails |
| ✅ | Immutable narrowing: capability sets only narrow, never expand |
| ✅ | Resource limits: CPU time (default 5s) and memory growth (default 16MB) per sandbox block |
| ✅ | Audit logging with redaction of passwords/bearer tokens |
| ✅ | Strict mode can throw `SandboxViolation` exceptions |

### 3.9 Expression Evaluator (`ExpressionEvaluator.php`)

| Severity | Finding |
|---|---|
| **LOW** | **`evaluateCondition()` regex associativity ambiguity:** Uses loosely-greedy regexes for boolean parsing — `.+?` non-greedy matching can produce incorrect operator associativity, leading to unexpected template behavior |
| **LOW** | **No dot-path depth limit:** `resolveValue()` iterates through `explode('.', $path)` without limiting chain depth |
| ✅ | Filter chain max depth: `FILTER_CHAIN_MAX = 10` |
| ✅ | Strict mode logs undefined vars and type mismatches |
| ✅ | Script context tracking adjusts behavior inside `<script>` blocks |
| ✅ | Sandbox integration via `setSandboxRequire()` callback |

---

## 4. Cross-Cutting Security Analysis

### 4.1 SQL Injection

**Verdict: LOW RISK.** All SQL uses prepared statements with bound parameters. No string interpolation of user input into SQL queries observed. `KernelPDO` wraps all query methods. The `authTableMap` table name is regex-validated (`/^[a-zA-Z_][a-zA-Z0-9_]*$/`) before use.

### 4.2 XSS Vectors

**Verdict: MODERATE RISK.** CSP allows `'unsafe-inline'` and `'unsafe-eval'` (documented as required for Alpine.js/Tailwind CDN). The `img-src` allows `https:` (any HTTPS origin). DiSyL's auto-escaping mitigates template-level XSS. The nonce transition plan exists but is gated behind `CSP_NONCE_MODE`.

**Key mitigations in place:**
- DiSyL `{...}` auto-escapes by default via `htmlspecialchars(ENT_QUOTES, 'UTF-8')`
- `|raw` filter is an explicit opt-out
- `esc_url` filter has protocol whitelist
- `<script>` block content is extracted before processing

### 4.3 CSRF Bypass

**Verdict: LOW RISK.** CSRF tokens use `random_bytes(32)` and `hash_equals`. Supports header-based token for SPAs. The main risk is that handlers must remember to call `csrfEnforce()` — there is no automatic middleware enforcement.

### 4.4 Auth Bypass

**Verdict: LOW RISK.** JWT uses algorithm validation to prevent confusion attacks. `token_version` support exists for invalidation. Re-entrant guard prevents recursion. Multi-tenant JWT cross-validation checks `tenant_id` claim. The sliding refresh rotates only after half-lifetime — reduces attack window.

### 4.5 Template Injection / SSTI

**Verdict: LOW RISK.** DiSyL is intentionally a server-side template language, but the sandbox is well-defended:
- Function whitelist only (no `call_user_func` or dynamic function resolution)
- No `{php}` tag — cannot inject raw PHP
- Variable scope isolation — no `extract()` or `$$var`
- `eval()` is compiler-internal only, on sentinel-validated generated code

**Gap:** If an attacker can write files to a registered component directory, they could achieve template injection via file-system compromise (not a DiSyL-specific vulnerability).

### 4.6 Tenant Isolation

**Verdict: LOW-MODERATE RISK.** Tenant resolution has multiple strategies with reasonable gates. JWT cross-validation checks `tenant_id` claim. However: session-stored tenant IDs are not re-validated against active tenant status; subdomain strategy is unimplemented.

### 4.7 File Inclusion

**Verdict: LOW RISK.** Path traversal protection on all file operations. Include cycle detection in both compiled and interpreted modes. Include depth limits (20). Component namespace resolution restricted to registered directories.

### 4.8 Race Conditions

**Verdict: LOW RISK.** Cache uses atomic writes (temp file + rename). Workflow engine uses DB transactions. JWT rotation is best-effort (non-fatal on failure). The circuit breaker uses APCu with atomic operations. Deferred event flushing could have non-deterministic ordering between concurrent requests.

### 4.9 Resource Exhaustion

**Verdict: MODERATE RISK.**

| Resource | Protection | Gap |
|---|---|---|
| Output size | `MAX_OUTPUT_BYTES = 5MB` | Single massive variable could bypass until concatenation |
| Template source size | **NONE** | No limit on source template size |
| Memory per sandbox | 16MB default | Sandbox only |
| CPU per sandbox | 5s default | Sandbox only |
| `range()` array size | **NONE** | Can create billion-element arrays |
| `str_repeat()` output | **NONE** | Can create multi-GB strings |
| Parser recursion depth | **NONE** | Can overflow PHP stack |
| While/for iterations | **NONE** | Infinite loops possible |

---

## 5. Risk Matrix

| ID | Finding | Severity | Likelihood | Impact | Risk Score |
|---|---|---|---|---|---|
| D1 | Parser no recursion depth guard — stack overflow | HIGH | Medium | DoS (PHP crash) | **Critical** |
| D2 | `eval()` fallback in compiled cache | HIGH | Low | Code execution | **High** |
| D3 | `?disyl_nocache=1` debug bypass in production | HIGH | Low | Cache poisoning / information disclosure | **High** |
| D4 | `range()` / `str_repeat()` DoS vectors | MEDIUM | Medium | Memory exhaustion DoS | **High** |
| D5 | No while/for iteration guard in compiled mode | MEDIUM | Medium | CPU exhaustion DoS | **Medium** |
| D6 | Unbounded Sandbox audit log growth | MEDIUM | Low | Disk exhaustion | **Medium** |
| D7 | No template source size limit | MEDIUM | Low | Memory exhaustion | **Medium** |
| D8 | God Object anti-pattern (TemplateEngine) | MEDIUM | N/A | Maintenance risk | **Medium** |
| K1 | CSP `'unsafe-eval'` / `'unsafe-inline'` required | MEDIUM | Medium | XSS amplification | **Medium** |
| K2 | JWT HS256 only — no asymmetric support | MEDIUM | N/A | Architectural limitation | **Medium** |
| K3 | Subdomain tenant strategy unimplemented | MEDIUM | N/A | Feature gap | **Low** |
| K4 | Session tenant ID not re-validated | LOW | Low | Stale tenant access | **Low** |
| K5 | No code-signing for module packages | LOW | Low | Module tampering | **Low** |
| K6 | `img-src: https:` overly permissive | LOW | Low | XSS surface | **Low** |
| K7 | No automatic CSRF middleware | LOW | Low | Missed enforcement | **Low** |
| K8 | `evaluateCondition()` regex associativity | LOW | Low | Template logic errors | **Low** |

---

## 6. Remediation Roadmap

### Phase 1 — Immediate (before production launch)

| # | Task | Effort | Risk addressed |
|---|---|---|---|
| 1 | **Add parser recursion depth guard** — increment counter in `parseChildren()`, throw at 256 | 1 hour | D1 (Critical) |
| 2 | **Cap `range()` and `str_repeat()` inputs** — max 100,000 for range, max 10,000 for str_repeat | 30 min | D4 (High) |
| 3 | **Add template source size limit** — reject templates >10MB before parsing | 30 min | D7 (Medium) |
| 4 | **Gate `?disyl_nocache=1` behind `APP_DEBUG`** — skip in production unless debug mode is on | 15 min | D3 (High) |
| 5 | **Add iteration guards to while/for loops** — counter + break after 10,000 iterations in compiled mode | 1 hour | D5 (Medium) |

**Phase 1 total effort: ~3 hours**

### Phase 2 — Short-term (first week of production)

| # | Task | Effort | Risk addressed |
|---|---|---|---|
| 6 | **Remove `eval()` fallback from compiled cache** — always regenerate to file and `require`; add `flock()` locking for concurrent compilations | 2 hours | D2 (High) |
| 7 | **Add Sandbox audit log rotation** — cap at 10MB, rotate daily, keep 7 days | 1 hour | D6 (Medium) |
| 8 | **Add session tenant validation** — check tenant active status on session-based resolution | 1 hour | K4 (Low) |
| 9 | **Narrow `img-src` CSP** — replace `https:` with specific CDN origins | 30 min | K6 (Low) |

**Phase 2 total effort: ~4.5 hours**

### Phase 3 — Medium-term (first month)

| # | Task | Effort | Risk addressed |
|---|---|---|---|
| 10 | **Implement RS256 JWT support** — add asymmetric key support for Android/API clients | 1 day | K2 (Medium) |
| 11 | **Implement subdomain tenant resolution** — add lookup table strategy | 1 day | K3 (Medium) |
| 12 | **Add automatic CSRF middleware** — `before` handler check on all POST routes | 2 hours | K7 (Low) |
| 13 | **Add code-signing to module packages** — GPG or Ed25519 signature in ZIP | 3 days | K5 (Low) |

**Phase 3 total effort: ~5 days**

### Phase 4 — Long-term (architectural)

| # | Task | Effort | Risk addressed |
|---|---|---|---|
| 14 | **Split `TemplateEngine` into focused classes** — `TemplateRenderer`, `TemplateCompiler`, `ComponentRenderer`, `MacroProcessor`, `IncludeResolver`, `ExtendsProcessor` | 2-3 weeks | D8 (Medium) |
| 15 | **Deprecate interpreted pipeline** — keep as fallback but stop adding features; all new features compiled-mode-only | Ongoing | D8 (Medium) |
| 16 | **Replace custom YAML parser** — use `yaml_parse_file()` or Symfony YAML component | 1 day | (Architecture) |

**Phase 4 total effort: ~3 weeks**

---

## 7. Architectural Observations

### 7.1 Strengths

| Pattern | Assessment |
|---|---|
| **Singleton with `reset()`** | Hooks, TenantResolver, EntityViewResolver, SlotRegistry all have `reset()` for test isolation — well-designed for testability |
| **"Best effort, never crash"** philosophy | Listeners/hooks catch Throwable and continue. JWT rotation is best-effort. Cache writes are best-effort. Appropriate for multi-tenant SaaS, though can mask bugs |
| **Modularity** | Kernel never calls modules directly (Hooks system). Capability bus for inter-module communication. Suite/extension contract for product suites. Well-enforced boundaries |
| **Observability** | Request IDs, correlation IDs, capability tracing, slow request logging (>1s), cache metrics, tenant host cache metrics, event history. Production-ready observability |
| **Defense-in-depth** | Path traversal has 2+ layers. Compiled cache has sentinel + atomic write + opcache invalidation. Module ZIP install has preflight + post-extraction validation. Consistent multi-layered approach |
| **Fast-path optimization** | Page cache and health check before kernel boot. Uploads static handler before kernel boot. Well-optimized critical path |

### 7.2 Areas for Improvement

| Pattern | Assessment |
|---|---|
| **Service locator anti-pattern** | `app()` is used as a global service locator throughout. Common in PHP but makes testing harder and creates hidden dependencies |
| **Monolithic TemplateEngine** | 7,600-line God Object is the single biggest architectural concern. Should be refactored into focused classes with clear interfaces |
| **Dual pipeline** | Interpreted (regex) + compiled (AST) modes create maintenance burden. Interpreted mode should be deprecated once compiled mode is fully hardened |
| **Static mutable state** | `CompiledTemplate::$includeStack` is static — fragile in async/Fiber contexts |
| **Inline SQL in App.php** | Auth queries use inline SQL with column interpolation (though column names are hardcoded, not user-supplied). Should use a query builder or repository pattern |
| **Hardcoded class references** | `ExpressionEvaluator::resolveKeyof()` hardcodes `EntityViewResolver`. `TemplateEngine::renderComponent()` has 40+ branch match statement |

---

## 8. Deployment Readiness Verdict

### Overall: CONDITIONAL PASS — Proceed with Phase 1 fixes

The Ikabud Kernel OS 6.1.0 and DiSyL v4.7 engine demonstrate mature, security-conscious engineering. The security architecture is sound at every layer: whitelisted functions, capability-based sandboxing, sentinel-validated cache files, path traversal protection, proper CSP/CSRF/JWT implementations, and comprehensive module packaging validation. The compiled-mode DiSyL pipeline is the correct long-term architecture.

**The system can be deployed to production** provided the Phase 1 hardening items (parser recursion guard, `range()`/`str_repeat()` caps, source size limit, `?disyl_nocache` gate, loop iteration guards) are completed first. These fixes are low-effort (~3 hours total) and close the highest-risk resource exhaustion vectors.

Phase 2 items (removing `eval()` fallback, Sandbox log rotation, session tenant validation, narrowing `img-src`) should be completed within the first week of production deployment.

### What gives confidence

1. **Security-first design patterns** throughout: whitelisting over blacklisting, atomic operations, defense-in-depth
2. **Production-proven subsystems**: CSP construction, CSRF protection, JWT auth, multi-tenant resolution, atomic caching
3. **Comprehensive test surface**: CsrfManager, SecurityHeaders, JWT, Crypto, TenantResolver, Cache all designed for testability
4. **Mature module packaging**: ZIP install with 6+ security validation layers
5. **Observability built-in**: request tracing, capability metrics, slow request logging, cache metrics
6. **Clean codebase:** consistent conventions, clear comments on security boundaries, documented trade-offs

### What to watch

1. **DiSyL's `eval()` fallback** — the highest-risk code in the system. Phase 2 removal is important
2. **Template source from user input** — if any module allows templates to be uploaded or edited via the web UI, the sandbox must be strictly enforced
3. **APCu cache poisoning** — the `?disyl_nocache=1` bypass in production is a real attack surface until gated
4. **Module package origin** — without code-signing, modules must be trusted at the distribution level

---

## 9. Appendix: Codebase Metrics

### Kernel
| Metric | Value |
|---|---|
| Total PHP files | 267 |
| Total lines of code | ~65,500 |
| Largest file | `src/helpers/module-manager.php` (4,617 lines) |
| Entry points | `bootstrap.php` (2,891 lines), `public/index.php` (939 lines) |
| Application singleton | `kernel/App.php` (1,859 lines) |
| Key security files | `SecurityHeaders.php` (189), `CsrfManager.php` (108), `JWT.php` (235), `Crypto.php` (154) |
| Caching | `Cache.php` (1,145 lines) |

### DiSyL Engine
| Metric | Value |
|---|---|
| Total PHP files | 88 |
| Total lines of code | ~25,000 |
| Largest file | `TemplateEngine.php` (7,600 lines) |
| Parser | `v4/Parser.php` (2,030 lines) |
| Compiled cache | `TemplateCache.php` (500 lines) |
| Compiler | `TemplateCompiler.php` (800 lines) |
| Expression evaluator | `ExpressionEvaluator.php` (800 lines) |
| Function registry | 20 whitelisted functions |
| Filter registry | 32 default filters |
| AST node classes | 17 files |
| Subsystems | Reactive (10 files), AI (5), Hydration (8), Async (3), Federation (1), i18n (1) |

### Products & Deployments
| Metric | Value |
|---|---|
| Registered routes | 1,129 |
| Module manifests | 68 |
| Module folders | 37 (34 standalone + 3 suite containers) |
| CMS Akira submodules | 14 |
| DiSyL template files | 583 |
| Entity view contracts | 16+ registered |
| DiSyL governed components | 32 |
| Production target | Bluehost shared hosting (MySQL 5.7 Compatibility profile) |

---

## 10. Remediation Status (2026-08-05)

> All remediation tasks from the roadmap were implemented on the same day as the
> assessment. Status reflects the code state after the fixes and before the
> follow-up regression run.

### Completed

| ID | Finding | Status | Implementation |
|---|---|---|---|
| D1 | Parser no recursion depth guard — stack overflow | ✅ **FIXED** | `kernel/DiSyL/v4/Parser.php` — `MAX_PARSE_DEPTH = 256` counter on `parseChildren()` + `parseExprValue()` with try/finally; also bounded `readTagContent()` backslash skip |
| D3 | `?disyl_nocache=1` debug bypass in production | ✅ **FIXED** | `TemplateCache::isForceRecompileRequested()` — honored only outside production or in debug mode |
| D4 | `range()` / `str_repeat()` DoS vectors | ✅ **FIXED** | `FunctionRegistry` — `range()` capped at 100k elements, `str_repeat()` capped at 10k |
| D5 | No while/for iteration guard in compiled mode | ✅ **FIXED** | `TemplateCompiler` — `MAX_LOOP_ITERATIONS = 100000` guard emitted in `compileWhile()` + `compileCFor()`; `COMPILER_VERSION` bumped 11→12 |
| D6 | Unbounded Sandbox audit log growth | ✅ **FIXED** | `Sandbox` — 10MB cap, daily rotation (`violations-YYYYMMDD.json`), 7-day retention prune; audit dir `0775`→`0750` |
| D7 | No template source size limit | ✅ **FIXED** | `Parser::parse()` rejects sources >10MB before parsing |
| D2 | `eval()` fallback in compiled cache | ✅ **FIXED** | `TemplateCache` — removed both `eval()` paths; cache file always written then `require`'d; sentinel validated before require; `flock()` compile-lock serializes concurrent compilations |
| K4 | Session tenant ID not re-validated | ✅ **FIXED** | `TenantResolver::tenantIsActive()` — session & header strategies validate against `kernel_tenants.status = 'active'` (fail-open only when control DB unavailable) |
| K6 | `img-src: https:` overly permissive | ✅ **FIXED** | `SecurityHeaders` — narrowed to explicit `IMG_SRC_ORIGINS` allowlist (CDNs actually used) |
| K2 | JWT HS256 only — no asymmetric support | ✅ **FIXED** | `JWT` — RS256 support via `JWT_PRIVATE_KEY`/`JWT_PUBLIC_KEY`(+ring); algorithm-confusion guard preserved; also fixed FastCGI `getallheaders()` fallback |
| K3 | Subdomain tenant strategy unimplemented | ✅ **FIXED** | `TenantResolver` — strategy 5 now resolves via control-plane domain mapping + `tenant_key` subdomain lookup |
| K7 | No automatic CSRF enforcement | ✅ **FIXED** | `public/index.php` — auto-enforces CSRF on non-API POST/PUT/PATCH/DELETE with session; skips `stateless`/`csrf_exempt` route meta + `/api/` (incl. payment webhooks); `CSRF_AUTO_ENFORCE=false` kill-switch |
| K5 | No code-signing for module packages | ✅ **FIXED** (opt-in) | `module-manager.php` — Ed25519 `module.sig.json` verify (`moduleVerifyPackageSignature`) wired into `installModuleFromZip` + `moduleSignPackageForPath` signer; `MODULE_SIGNING_PUBLIC_KEY[(_PATH)]` / `MODULE_SIGNING_REQUIRED`; legacy unsigned packages remain valid |
| P4-2 | Interpreted pipeline deprecation | ✅ **FIXED** | `TemplateEngine` — one-time-per-template production `disyl.interpreted.deprecated` warning |
| P4-3 | Custom YAML parser silent misparse | ✅ **FIXED** | `WorkflowEngine` — malformed/incomplete transitions now logged instead of silently dropped |

### Verified by direct tests

| Fix | Result |
|---|---|
| JWT HS256 round-trip | ✅ PASS |
| JWT RS256 round-trip | ✅ PASS |
| JWT algorithm-confusion guard (RS256 token under HS256) | ✅ PASS |
| JWT tamper detection | ✅ PASS |
| Module signature: unsigned+required rejects | ✅ PASS |
| Module signature: sign + verify | ✅ PASS |
| Module signature: file tamper detected | ✅ PASS |
| Module signature: wrong key rejected | ✅ PASS |
| `php -l` all 12 modified files | ✅ PASS |
| DiSyL compiled render (extends/if/c-for/json) end-to-end | ✅ PASS (compiled mode active, cache reuse consistent) |
| Compiled loop guard fires at 100k (infinite `{for}`) | ✅ PASS (0.12s) |
| Bounded C-style `{for ...; i++}` in compiled mode | ✅ PASS (`0,1,2,`) |
| Parser deep-nesting guard (2000 nested `{if}`) — no stack overflow | ✅ PASS (graceful recovery) |
| `range()` cap (1→10,000,000 capped at 100k) | ✅ PASS |
| `img-src` CSP narrowed (no blanket `https:`) | ✅ PASS |
| CSRF valid token accepted / invalid token rejected | ✅ PASS |

### Regression test run (DiSyL suites)

| Suite | Result |
|---|---|
| `disyl_v4_parser_test` | ✅ 61 PASSED / 0 FAILED |
| `disyl_v4_compiler_test` | ✅ 55 PASSED / 0 FAILED |
| `disyl_template_cache_test` | ✅ 5 PASS / 0 FAIL |
| `disyl_engine_test` | ✅ ALL 282 TESTS PASSED |
| `disyl_v44_sandbox_test` | ✅ 28 PASS / 0 FAIL |
| `disyl_hardening_coverage_test` | ✅ 44/44 passed |
| `disyl_security_fuzz_test` | ✅ 64 passed / 0 failed |
| `disyl_loop_control_test` | ✅ ALL 6 TESTS PASSED |
| `disyl_parity_test` (interpreted vs compiled) | ✅ 97/97 passed |
| `disyl_v4_test` | ✅ 36 PASSED / 0 FAILED |
| `disyl_compiled_component_fallback_test` | ✅ 3 passed |
| `manifest_suite_contract_test` | ✅ 28 passed / 0 failed |

### Bonus fix (found during verification)

While validating the compiled loop guard, a pre-existing parser bug was found and
fixed: `i++` in C-style `{for}` increments was parsed as `i + '+'`
(`BinaryOpNode`), producing a `TypeError` in compiled mode and forcing every
C-style `for` to fall back to the (unguarded) interpreted pipeline. Fixed in
`Parser::parseExprValue()` — postfix `++`/`--` is now resolved to a
`UnaryOpNode('postinc'/'postdec')` before binary-op splitting. This makes the
compiled loop guard effective for C-style loops and fixes C-style `for`
rendering in compiled mode (was previously broken/fallback-only).

### Deferred / scheduled (architectural)

| ID | Finding | Status | Plan |
|---|---|---|---|
| D8 | God Object `TemplateEngine` (7,600 lines) | ⏳ **Scheduled** | Split into `TemplateRenderer`, `TemplateCompiler`, `ComponentRenderer`, `MacroProcessor`, `IncludeResolver`, `ExtendsProcessor` — 2-3 week refactor, must not run immediately before a live deployment. Deprecation warning now makes interpreted usage observable |
| P4-1 | Interpreted pipeline full removal | ⏳ **Scheduled** | Keep as fallback; all new features compiled-mode-only; migrate templates flagged by the deprecation log |
| P4-3 | Replace custom YAML parser with a library | ⏳ **Scheduled** | Hardened in place (dangling transitions logged); full `yaml_parse_file`/Symfony swap is a separate change |

### Updated risk posture after remediation

| Area | Before | After |
|---|---|---|
| DiSyL resource exhaustion | MODERATE (4 unguarded vectors) | **LOW** (all 4 guarded) |
| DiSyL compiled cache | `eval()` fallback race | **LOW** (no eval, sentinel + lock) |
| Multi-tenant isolation | LOW-MODERATE | **LOW** (session/header re-validated) |
| JWT verification | HS256 only | **LOW-MEDIUM** (RS256 added) |
| CSRF | handler-discipline only | **LOW** (automatic + opt-out) |
| Module supply chain | unsigned ZIPs | **LOW** (opt-in Ed25519) |

> **Next review:** After the follow-up regression test run completes, re-verify
> D1-D7 and K1-K7 against the DiSyL + kernel test suites, then close out the
> scheduled architectural items (D8, P4-1, P4-3).
> **Signed:** Automated code review + remediation — August 5, 2026
