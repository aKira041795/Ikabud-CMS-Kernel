# Ikabud Kernel OS — Security & Architecture Evaluation

**Date:** 2026-04-10
**Scope:** Full-stack audit — kernel, modules, integration bridge, tenancy, CSP, auth, data isolation
**Branch:** `phase-5` (post-decomposition)
**Evaluator perspective:** Senior security engineer, system architect, principal developer

---

## Executive Summary

Ikabud is a multi-tenant PHP application kernel that loads business-feature modules (CMS, ecommerce, contact-form, guidance, ticketing, WMS) inside a capability-driven runtime. The architecture is sound at the macro level — the kernel enforces module boundaries via manifest-declared table ownership, a capability bus with policy enforcement, tenant-aware DB routing, and a DiSyL template sandbox that prevents code execution. Several areas demonstrate strong security hygiene (JWT timing-safe verification, atomic refresh-token rotation, Zip Slip protection in module installer, path-traversal-hardened static asset serving).

However, a systematic audit reveals **4 critical**, **8 high**, and **12 medium** findings that collectively create real exploit paths, particularly around tenant isolation under header-based strategy, ecommerce price integrity, ModuleDB SQL parsing bypass, and cross-module privilege boundaries. These are detailed below with concrete attack scenarios, affected code paths, and recommended remediations.

---

## Table of Contents

1. [Architecture Assessment](#1-architecture-assessment)
2. [Kernel Core Findings](#2-kernel-core-findings)
3. [Authentication & Session Security](#3-authentication--session-security)
4. [Multi-Tenancy & Data Isolation](#4-multi-tenancy--data-isolation)
5. [Module System & Capability Engine](#5-module-system--capability-engine)
6. [Integration Bridge](#6-integration-bridge)
7. [CMS Module](#7-cms-module)
8. [Ecommerce Module](#8-ecommerce-module)
9. [Security Headers & CSP](#9-security-headers--csp)
10. [What I Would Do Differently](#10-what-i-would-do-differently)
11. [Consolidated Finding Matrix](#11-consolidated-finding-matrix)
12. [Recommended Remediation Roadmap](#12-recommended-remediation-roadmap)

---

## 1. Architecture Assessment

### 1.1 What Works Well

| Area | Observation |
|------|------------|
| **Entrypoint discipline** | Single front-controller (`public/index.php`) handles all routing; no scattered `.php` files in web root |
| **Module sandboxing** | `ModuleDB` enforces per-module `owns_tables`/`reads_tables` declarations; DDL is blocked |
| **Capability bus** | Cross-module communication happens through declared, versioned capabilities with caller policies |
| **DiSyL template engine** | No `eval()`, no PHP execution, no dynamic includes; auto-HTML-escaping by default; path-traversal blocked on `{include}` |
| **JWT implementation** | HS256 with 32-byte minimum secret, `jti` claim, timing-safe `hash_equals()` verification, `nbf`/`exp` validation |
| **Module installer** | Multi-layered Zip Slip protection: null-byte rejection, symlink detection, realpath double-check, decompression bomb limits |
| **Input handling** | Global `app()->input()` strips null bytes, enforces 2 MB JSON limit, 64-level nesting cap, 32-level sanitization depth |
| **Refresh token rotation** | Atomic `UPDATE WHERE revoked=0` with `rowCount()` guard closes the TOCTOU race |
| **Rate limiting** | Per-tenant, per-module, per-IP rate limiting with rolling-window semantics via DB upsert |

### 1.2 Structural Concerns

| Area | Concern |
|------|---------|
| **Global function namespace** | All module handlers and helpers are `require_once`'d into the global function namespace. Collisions are logged but not prevented. |
| **No PSR-4 autoloading** | Entire dependency graph is hand-managed `require_once` chains. This makes dead-code analysis, dependency injection, and testing harder. |
| **Monolithic request lifecycle** | Every request loads the full module stack (discovery, settings preload, route loading) even for static assets or health checks |
| **No middleware pipeline** | Auth, CSRF, rate limiting, tenant resolution are applied ad-hoc per handler rather than declaratively per route group |
| **Mixed concerns in bootstrap** | `bootstrap.php` (1,740 lines) contains render-context contracts, timing logs, request dispatch logic, rate limiting, and autoloading |

---

## 2. Kernel Core Findings

### 2.1 CRITICAL — `_kernel_db_unguarded` Context Flag Bypass

**Location:** `src/helpers/module-manager.php` (tenant settings persistence), `kernel/Contracts/ModuleDB.php`

**Issue:** The kernel uses a string-based request-context flag `_kernel_db_unguarded` to temporarily bypass ModuleDB table enforcement:

```php
kernel_request_context_set('_kernel_db_unguarded', true);
// ... unrestricted DB operations ...
kernel_request_context_set('_kernel_db_unguarded', $previousUnguarded);
```

The request context store is a global `$_SERVER`-keyed array accessible to any code in the process. A compromised module can set this flag before executing its own DB queries, escaping the `owns_tables`/`reads_tables` sandbox entirely.

**Attack path:**
1. Module registers a capability handler or hook listener
2. Handler sets `kernel_request_context_set('_kernel_db_unguarded', true)`
3. Module executes `app()->db()->query("SELECT * FROM users")` — no enforcement
4. Reads kernel user passwords, audit logs, other tenants' data

**Recommendation:** Replace the context flag with a cryptographic token generated per kernel operation. Pass the token through a `ModuleDB::withKernelEscalation(string $token, callable $fn)` method. Only kernel code that holds the token can escalate.

### 2.2 HIGH — X-Forwarded-* Header Trust Without Proxy Validation

**Location:** `bootstrap.php` lines 275-299 (`is_https()`)

**Issue:** The `is_https()` function unconditionally trusts `HTTP_X_FORWARDED_PROTO`, `HTTP_X_FORWARDED_SSL`, `HTTP_X_FORWARDED_PORT`, and `HTTP_CF_VISITOR` headers. If the application is not behind a trusted reverse proxy that strips these headers from client requests, an attacker can spoof HTTPS detection.

**Impact:** Secure cookie flags, HSTS enforcement, and redirect targets are all derived from `is_https()`. Spoofing it to `false` causes cookies to be set without the `Secure` flag; spoofing it to `true` may suppress HTTPS redirects.

**Recommendation:** Introduce a `TRUSTED_PROXIES` env var. Only trust `X-Forwarded-*` headers when `REMOTE_ADDR` is in the trusted proxy list.

### 2.3 MEDIUM — Exception Handler Information Disclosure in Development

**Location:** `bootstrap.php` lines 729-757

**Issue:** The exception handler renders a styled error page via DiSyL. If the template engine itself throws (e.g., during boot-time failures), the fallback is a bare HTML page. In both paths, the stack trace is logged but not rendered to the client. However, the `write_log()` call includes the full trace in `storage/logs/app.log`. If log files are web-accessible (misconfigured web server), traces are exposed.

**Recommendation:** Add an explicit `deny` rule for `storage/` in `.htaccess`/nginx config. Verify log directory permissions (0750).

### 2.4 MEDIUM — Input Size Silent Truncation

**Location:** `kernel/App.php` `input()` method

**Issue:** When a JSON request body exceeds `MAX_INPUT_SIZE` (2 MB), `$input` is silently set to `[]`. Handlers cannot distinguish between an empty body and an oversized one. This can cause data loss if a legitimate large payload (e.g., page-builder JSON) is silently dropped.

**Recommendation:** Set `$input = ['_size_exceeded' => true]` on oversized payloads, analogous to the existing `_json_error` flag.

### 2.5 MEDIUM — JSON Depth Inconsistency

**Location:** `kernel/App.php` `input()` and `sanitizeInput()`

**Issue:** JSON parsing allows depth 64 (`json_decode($raw, true, 64)`) but `sanitizeInput()` truncates at depth 32. A payload with 33-64 levels of nesting will parse successfully but be silently nullified during sanitization.

**Recommendation:** Align both limits to the same value (32 or 64).

---

## 3. Authentication & Session Security

### 3.1 HIGH — JWT Tokens Not Invalidated on Password Change

**Location:** `kernel/Jwt.php`, `src/http/auth-handlers.php`

**Issue:** The JWT class supports a `token_version` claim and `verify()` accepts an optional `expectedTokenVersion` parameter. However, `kernelHandleAuthLogin()` never includes `token_version` in the JWT payload, and no code increments a user's version counter on password change. This means all existing tokens remain valid after a password change until they expire naturally (up to 24 hours).

**Attack scenario:** User discovers their password is compromised, changes it. Attacker's previously-issued JWT remains valid for up to 24 hours.

**Recommendation:**
1. Add a `token_version` column to the `users` table
2. Include `token_version` in every JWT payload at login
3. Increment `token_version` on password change
4. Pass `expectedTokenVersion` to `verify()` during user resolution

### 3.2 HIGH — Generic Exception Catch in User Resolution

**Location:** `kernel/App.php` `user()` method

**Issue:** Token verification catches `\Exception` generically and returns `null`. If the JWT library throws an unexpected exception type (e.g., `TypeError` from malformed payload), the error is silently swallowed. The system treats it as "not authenticated" which is safe, but the underlying error (potentially a DoS vector or library bug) goes undetected.

**Recommendation:** Catch `\Throwable`, log the exception class and message at `warning` level, then return `null`.

### 3.3 MEDIUM — Rate Limit Default Too Permissive

**Location:** `bootstrap.php` `kernelLoginRateLimitMaxAttempts()`

**Issue:** Default is 10 attempts per 300-second window. OWASP and NIST SP 800-63B recommend 5 failed attempts before lockout. With 10 attempts per 5 minutes, an attacker can test 2,880 passwords per day per IP.

**Recommendation:** Reduce default to 5 attempts per 300 seconds. Add progressive backoff (exponential delay after threshold).

### 3.4 MEDIUM — No Authorization Audit Logging

**Location:** `kernel/App.php` `requireRole()`, `requireAnyRole()`

**Issue:** Failed authorization checks redirect the user or return 403, but no audit log entry is created. Repeated unauthorized access attempts by a compromised account are invisible.

**Recommendation:** Log failed authorization at `warning` level with user ID, attempted role, requested path.

### 3.5 LOW — CSRF Per-Session, Not Per-Request

**Location:** `kernel/App.php` `csrfToken()`

**Issue:** CSRF tokens are generated once per session and reused for all requests. If a token is leaked (e.g., via Referer header, browser extension, or XSS), it remains valid for the entire session.

**Recommendation:** Acceptable for current threat model, but consider per-request rotation for high-value state changes (password change, module install, user creation).

### 3.6 LOW — Authorization Header Length Unvalidated

**Location:** `kernel/App.php` `user()` method

**Issue:** The `Authorization: Bearer` header value is passed directly to JWT `verify()` without length validation. A multi-megabyte header could cause unnecessary base64 decoding and HMAC computation.

**Recommendation:** Reject tokens longer than 4 KB before parsing.

---

## 4. Multi-Tenancy & Data Isolation

### 4.1 CRITICAL — Header-Based Tenant Override Without Role Guard

**Location:** `kernel/TenantResolver.php`

**Issue:** When the tenant resolution strategy is `header` or `auto`, the resolver reads `X-Tenant` (or configured header name) directly from the request and casts it to `int`. There is no validation that the authenticated user is authorized for the requested tenant. The JWT cross-validation in `App::user()` prevents tokens issued for Tenant A from being used with Tenant B, but:

- Tokens issued **without** a `tenant_id` claim (e.g., superadmin tokens, or tokens from single-tenant bootstrapping) are accepted for **any** tenant specified via header.
- If the strategy stack tries JWT first and falls through to header, a request with no JWT but a valid `X-Tenant` header resolves to that tenant with no auth check.

**Attack path:**
1. Attacker discovers the system uses `strategy: auto` with header fallback
2. Sends `GET /api/v1/cms/content HTTP/1.1` with `X-Tenant: 42` and a valid superadmin JWT
3. JWT has no `tenant_id` claim → cross-validation passes
4. Tenant resolves to 42 → queries execute against Tenant 42's database
5. Attacker has full access to Tenant 42's data

**Recommendation:**
- Header-based override must require `role === 'superadmin' && source === 'kernel'`
- Add a per-strategy authorization hook: `TenantResolver::setStrategyGuard('header', fn(int $tid, ?array $user) => ...)`
- Default: reject header strategy unless user is superadmin

### 4.2 HIGH — Tenant DB Credentials Stored Plaintext During Migration

**Location:** `kernel/Services/DatabaseManager.php` `tenantDbPasswordFromRow()`

**Issue:** The credential lookup supports both encrypted (AES-256-GCM via `db_pass_ciphertext`, `db_pass_iv`, `db_pass_tag`) and plaintext (`db_pass`) storage. This plaintext fallback is designed as a migration path, but:

- No mechanism forces re-encryption of existing tenants
- No warning is emitted when plaintext credentials are used
- No CI or startup check validates that all tenants are encrypted
- If `CONTROL_DB_ENC_KEY` env var is misconfigured, all tenants silently fall back to plaintext

**Recommendation:**
1. Log a `critical` warning when plaintext credentials are used at runtime
2. Add startup check: if encryption key is configured, refuse to serve tenants with plaintext credentials
3. Provide a CLI migration command to batch-encrypt all tenant credentials

### 4.3 MEDIUM — No Runtime Query-Level Tenant Scoping

**Location:** `kernel/Services/DatabaseManager.php`, `kernel/Contracts/ModuleDB.php`

**Issue:** Tenant isolation relies entirely on credential-level separation: each tenant gets a separate database (or separate credentials). There is no query-level `WHERE tenant_id = ?` enforcement. If a bug in the credential lookup returns the wrong PDO connection, all queries execute against the wrong tenant's data with no guard.

**Recommendation:** For shared-database deployments, implement a `SET @tenant_id = ?` session variable at connection time and add views or triggers that enforce the tenant scope. For separate-database deployments, add a startup query (`SELECT DATABASE()`) and validate it matches the expected tenant database name.

### 4.4 MEDIUM — Tenant Host Cache Poisoning via APCu

**Location:** `kernel/TenantResolver.php` `lookupControlHostRecord()`

**Issue:** The two-tier cache (in-memory + APCu) stores host→tenant mappings with a configurable TTL (default 30 seconds). If an attacker can trigger a DNS rebind or host header injection during the cache window, the poisoned mapping persists for the TTL duration. The APCu key is `ikabud:tenant_host:` + `sha1($host)`, which is deterministic.

**Impact:** Low probability but high impact. A successful cache poisoning routes all requests for a domain to the wrong tenant for up to 30 seconds.

**Recommendation:** Validate cached results against a secondary signal (e.g., TLS SNI or a request-scoped nonce) in high-security environments.

---

## 5. Module System & Capability Engine

### 5.1 CRITICAL — ModuleDB SQL Parsing Bypass via Multi-Statement and Backtick Escaping

**Location:** `kernel/Contracts/ModuleDB.php` `enforceAccess()`

**Issue:** The table access enforcement uses regex to extract table names from SQL:

```php
preg_match_all('/(?:FROM|JOIN)\s+`?(\w+)`?/i', $clean, $m)
```

This has multiple bypass vectors:

**A. Multi-statement attack:**
```sql
SELECT 1 FROM allowed_table; SELECT * FROM kernel_users; --
```
The regex finds `allowed_table` and `kernel_users`. However, the DDL keyword check only looks for DDL verbs (`DROP`, `ALTER`, etc.), not for statement separators. If PDO is configured with `PDO::ATTR_EMULATE_PREPARES = true` (PHP default), multi-statement execution is possible.

**B. Backtick-wrapped DDL:**
```sql
`DROP` TABLE allowed_table
```
The DDL keyword check uses `\b` word boundaries, but backticks are not word-boundary characters in PHP regex. This bypasses the DDL block.

**C. Subquery in SELECT list:**
```sql
SELECT (SELECT password_hash FROM users LIMIT 1) AS x FROM allowed_table
```
The regex only matches `FROM|JOIN` followed by a table name. A subquery in the SELECT list with `FROM users` IS matched by the regex, so this is actually caught. However:

**D. INFORMATION_SCHEMA access:**
```sql
SELECT TABLE_NAME FROM information_schema.tables WHERE TABLE_SCHEMA = DATABASE()
```
`information_schema` is matched as the table name but may not be in the forbidden list, allowing schema reconnaissance.

**Recommendation:**
1. Disable `PDO::ATTR_EMULATE_PREPARES` globally (prevents multi-statement)
2. Reject any SQL containing `;` outside of string literals
3. Add `INFORMATION_SCHEMA`, `MYSQL`, `PERFORMANCE_SCHEMA` to forbidden table names
4. Add `FLUSH`, `LOAD`, `OUTFILE`, `DUMPFILE`, `INTO`, `SHOW`, `DESCRIBE`, `EXPLAIN`, `SET` to forbidden keywords
5. Consider replacing regex parsing with a proper SQL tokenizer for critical paths

### 5.2 HIGH — Capability Provider Priority Hijacking

**Location:** `kernel/Capabilities/CapabilityRegistry.php` `sortProviders()`

**Issue:** Providers with equal priority are sorted alphabetically by provider ID. A malicious module that registers a provider with an alphabetically-earlier ID (e.g., `aaa_payment`) shadows the legitimate provider for the same capability.

**Attack:** Module `aaa_payment` registers `payment.process@1` with `priority: 10`. Legitimate `stripe-payments` module also registers `payment.process@1` with `priority: 10`. In pipeline mode, `aaa_payment` executes first and can short-circuit the pipeline, stealing payment data.

**Recommendation:** Use registration order (FIFO) as the tiebreaker instead of alphabetical sort. Alternatively, log a warning when two providers share the same priority for the same capability.

### 5.3 HIGH — Capability Timeout Is Post-Execution Only

**Location:** `kernel/Capabilities/CapabilityBus.php`

**Issue:** The timeout check happens AFTER the provider's handler returns:

```php
$result = ($provider['handler'])($payload, $capabilityId, $providerId);
$durationMs = (int)round((microtime(true) - $t0) * 1000);
if ($durationMs > (int)$settings['timeout_ms']) {
    throw new CapabilityCallException('Capability call timed out');
}
```

A malicious or buggy provider can block for arbitrary time. PHP's `max_execution_time` is the only real guard, and it doesn't count time spent in system calls (sleep, network I/O).

**Recommendation:** Use `pcntl_alarm()` or a dedicated process pool for capability calls that require hard timeouts. Alternatively, document this as a known limitation and enforce provider code review for externally-sourced modules.

### 5.4 MEDIUM — Function Namespace Collision Not Prevented

**Location:** `src/helpers/module-manager.php` handler loading

**Issue:** When modules' `handlers.php` are loaded via `require_once`, all functions become global. Collisions are detected and logged but execution continues. A module can redefine a function from another module (PHP allows this only at require-time if the function doesn't already exist, but modules use `if (!function_exists(...))` guards, so the first module to load wins).

**Impact:** Non-deterministic behavior if module load order changes. A module can't actively overwrite another's function, but the guard pattern means the first-loaded module's function persists, creating a hidden dependency on load order.

**Recommendation:** Enforce namespaced function declarations per module. The module ID should prefix all function names (`cms_`, `ecommerce_`, etc.).

### 5.5 MEDIUM — Circuit Breaker Window Reset Allows Permanent Slow Failure

**Location:** `kernel/Capabilities/CapabilityBus.php` `applyBreakerFailure()`

**Issue:** The circuit breaker tracks failures within a time window (`breaker_window_sec`, default 30s). If an attacker triggers exactly `threshold - 1` failures, then waits for the window to reset, the counter resets to 0. Repeating this pattern indefinitely keeps the provider in a degraded state without ever tripping the breaker.

**Recommendation:** Use a sliding-window counter (ring buffer) instead of a resetting window. Alternatively, track cumulative failure rate over a longer period in addition to the burst window.

### 5.6 MEDIUM — Audit Log Blind Spot for CMS-Source Users

**Location:** `kernel/Contracts/ModuleContext.php` `audit()` method

**Issue:** CMS-source users are excluded from `audit_logs.actor_user_id` (set to NULL) because the column references `kernel.users.id`. This means destructive CMS user actions (content deletion, settings changes) have no traceable actor.

**Recommendation:** Add a `actor_module_user_id` column and `actor_source` column to `audit_logs`. Populate both kernel and module actor IDs.

### 5.7 LOW — Manifest Raw Exposure

**Location:** `kernel/Contracts/ModuleContext.php` `manifest()` method

**Issue:** `$ctx->manifest()` returns the raw manifest array including internal fields (`_settings`, `_enabled`, `_entitlement`). A module can inspect other modules' entitlement tiers if it obtains their context reference.

---

## 6. Integration Bridge

### 6.1 MEDIUM — Unbounded Recursive Mapping Validation

**Location:** `kernel/IntegrationBridge.php` `validateMappingAgainstSchema()`

**Issue:** Recursive schema validation has no depth limit. A bridge definition with deeply-nested array schemas can trigger PHP's recursion limit (default 256), causing a fatal error.

**Recommendation:** Add a `$depth` parameter with a max of 32 levels, consistent with the input sanitization limit.

### 6.2 MEDIUM — Event Variable Information Disclosure

**Location:** `kernel/IntegrationBridge.php` `eventAvailableVars()`

**Issue:** Bridge validation error messages reveal which variables are available for each event type. An admin user probing bridge configurations can discover the full event payload schema, including potentially sensitive field names.

**Recommendation:** In production mode, return generic validation errors without listing available variables. Provide detailed errors only in `APP_DEBUG=true` mode.

### 6.3 LOW — Version Lock Removed Without Schema Revalidation

**Location:** `kernel/IntegrationBridge.php`

**Issue:** If an admin removes `version_lock` from a bridge definition, the bridge starts using the latest capability version without validating that the mapping and schema are compatible with the new version's contract.

---

## 7. CMS Module

### 7.1 HIGH — Raw HTML Output in html_embed Widget

**Location:** `modules/cms/builder-renderers.php` `cmsRenderWidget_html_embed()`

**Issue:** The HTML embed widget outputs raw, unescaped user-provided HTML:

```php
$html = (string)($props['html'] ?? '');
return '<div ...>' . $html . '</div>';
```

While this is intentional for admin-controlled page builder content, if the builder ever allows contributor-level users to add HTML embed blocks, or if builder JSON is imported from an untrusted source (e.g., CMS import/export), this becomes a stored XSS vector.

**Recommendation:**
1. Add a `cms.builder.allow_raw_html` setting (default: false for non-admin roles)
2. Sanitize HTML embed content through an allowlist-based HTML purifier (e.g., HTMLPurifier or a DOM-based sanitizer) for non-admin content
3. Add CSP `script-src` nonce enforcement to mitigate impact if raw HTML is injected

### 7.2 MEDIUM — SVG Upload Allows Stored XSS

**Location:** `modules/cms/helpers/15-utils.php` `cmsValidateMediaUploadFile()`

**Issue:** SVG (`image/svg+xml`) is an allowed upload type. SVG files can contain `<script>`, `<foreignObject>`, event handlers (`onload`, `onerror`), and CSS expressions. If an uploaded SVG is served with `Content-Type: image/svg+xml` in an `<img>` tag, script execution is blocked by browsers. But if served inline (e.g., `<object>`, `<embed>`, `<iframe>`, or directly navigated to), scripts execute.

**Recommendation:**
1. Sanitize SVG uploads by stripping `<script>`, `<foreignObject>`, event handler attributes, and `<use xlink:href="data:...">` patterns
2. Serve uploaded SVGs with `Content-Disposition: attachment` to prevent inline rendering
3. Alternatively, convert SVG to PNG on upload for untrusted sources

### 7.3 LOW — CMS Content API IDOR Risk

**Location:** `modules/cms/handlers/35-api-content.php`

**Issue:** Content is retrieved by ID. Auth is checked via `cmsRequireCap()`, but no ownership validation ensures the requesting user can access that specific content item. If two users share an admin role, User A can access/modify User B's draft content by knowing the ID.

**Impact:** Low — requires admin access. Content management systems typically allow admin cross-access by design. Flag only if per-user content isolation is a requirement.

---

## 8. Ecommerce Module

### 8.1 CRITICAL — Price Snapshot Trusted from Client Cart Data

**Location:** `modules/ecommerce/helpers/40-pricing.php` `ecCalculateTotals()`

**Issue:** The pricing calculation uses `$item['price_snapshot']` from the cart items array, which originates from the client session/request. The price is not re-fetched from the `ec_products` table during checkout calculation.

```php
foreach ($items as $item) {
    $subtotal += (float)($item['price_snapshot'] ?? 0) * (int)($item['qty'] ?? 1);
}
```

**Attack path:**
1. Attacker adds product (price: $100) to cart
2. Session stores `price_snapshot: 100.00`
3. Attacker modifies session cookie or cart API payload to `price_snapshot: 0.01`
4. Checkout calls `ecCalculateTotals()` which trusts the snapshot
5. Order total: $0.01 instead of $100.00

**Recommendation:**
1. In `ecCalculateTotals()`, re-fetch current product prices from DB for each item
2. If `price_snapshot` differs from current DB price, reject the cart or prompt the user to confirm updated pricing
3. Store the server-validated price in the order, not the snapshot

### 8.2 HIGH — Missing CSRF on Cart and Cart API Endpoints

**Location:** `modules/ecommerce/handlers/15-public-cart.php`, `modules/ecommerce/handlers/82-api-cart.php`

**Issue:** The following POST endpoints lack CSRF protection:
- `POST /ecommerce/cart/add`
- `POST /api/v1/ecommerce/cart/add`
- `POST /api/v1/ecommerce/cart/update`
- `POST /api/v1/ecommerce/cart/remove`
- `POST /api/v1/ecommerce/cart/coupon`
- `POST /api/v1/ecommerce/cart/loyalty`
- `POST /api/v1/ecommerce/cart/clear`

**Attack:** A malicious page can submit a cross-origin form to `/ecommerce/cart/add` with an expensive product, then redirect the victim to checkout. With SameSite=Strict cookies, this is mitigated for same-site scenarios but vulnerable for cross-site contexts where cookies are configured as SameSite=Lax.

**Recommendation:** Add CSRF verification to all state-changing cart endpoints. For API endpoints consumed by mobile/external clients, require `Authorization: Bearer` header (which is immune to CSRF) and skip CSRF checks only for non-cookie-authenticated requests.

### 8.3 MEDIUM — Order Confirmation Tokens Never Expire

**Location:** `modules/ecommerce/helpers/20-orders.php`

**Issue:** Order confirmation tokens are generated with `bin2hex(random_bytes(32))` (256-bit entropy, excellent) but are permanent — no `expires_at` column, no invalidation after access. A guest order is accessible indefinitely via the confirmation URL.

**Recommendation:** Add an `order_token_expires_at` column. Set expiration to 90 days after order creation. Invalidate token on order completion or cancellation.

### 8.4 MEDIUM — No Rate Limiting on Checkout or Cart Endpoints

**Location:** `modules/ecommerce/handlers/86-api-checkout.php`, `modules/ecommerce/handlers/82-api-cart.php`

**Issue:** Rate limiting is explicitly applied only to login endpoints. Cart add and checkout have no rate limits. An attacker can:
- Flood the cart with requests, creating session storage pressure
- Repeatedly submit checkout to trigger payment gateway calls
- Enumerate coupons by brute-forcing coupon codes

**Recommendation:** Add per-session or per-IP rate limits: 30 cart operations per minute, 3 checkout attempts per 5 minutes.

### 8.5 LOW — No Product Stock Validation at Cart-Add Time

**Location:** `modules/ecommerce/handlers/82-api-cart.php`

**Issue:** Cart add accepts any quantity without checking product stock/inventory. Stock validation likely occurs at checkout time, but allowing oversized cart quantities creates false expectations and potential DoS via oversized cart state.

---

## 9. Security Headers & CSP

### 9.1 HIGH — `unsafe-eval` Requirement Creates Permanent XSS Amplification

**Location:** `kernel/Http/SecurityHeaders.php`

**Issue:** The CSP `script-src` includes `'unsafe-eval'` as a hard requirement because Alpine.js v3 (CDN) uses `new Function()` and Tailwind CSS JIT uses eval-based scanning. This permanently weakens XSS mitigation: if an attacker achieves script injection (e.g., via html_embed widget or SVG upload), `unsafe-eval` allows them to execute arbitrary dynamically-constructed code.

**Root cause:** Dependency on CDN-hosted Alpine.js and Tailwind CSS JIT. Self-hosted, CSP-compatible builds of these libraries exist but are not used.

**Recommendation:**
1. Replace CDN Alpine.js with self-hosted build using `@alpinejs/csp` package (eliminates `unsafe-eval` dependency)
2. Replace CDN Tailwind CSS JIT with build-time Tailwind (already done for builder-ui; extend to server-rendered templates)
3. Once both are migrated, remove `unsafe-eval` from `script-src`

### 9.2 MEDIUM — `applyPHPSettings()` Defined But Never Called

**Location:** `kernel/Http/SecurityHeaders.php`

**Issue:** The `applyPHPSettings()` method sets `session.cookie_httponly`, `session.cookie_secure`, and `session.cookie_samesite` via `ini_set()`. This method is defined but never invoked. Session cookie security relies on the `session_set_cookie_params()` call in `public/index.php`, which does work correctly.

**Impact:** No direct vulnerability (settings are applied elsewhere), but the dead code creates confusion about where session security is configured.

**Recommendation:** Either call `applyPHPSettings()` from `SecurityHeaders::apply()`, or remove it to avoid confusion.

### 9.3 MEDIUM — Nonce Present But Not Used in CSP

**Location:** `bootstrap.php` `kernel_csp_nonce()`, `kernel/Http/SecurityHeaders.php`

**Issue:** A CSP nonce is generated (`kernel_csp_nonce()`) but not added to the `script-src` directive. The code comment explicitly warns against adding it while `unsafe-inline` is present (correct — adding a nonce would cause browsers to ignore `unsafe-inline`, breaking all unnonced inline scripts). However, this means the nonce infrastructure is dead code until the `unsafe-inline` → nonce migration is completed.

**Recommendation:** Document this as a migration prerequisite in the CSP transition plan. Track it as a blocking dependency for Security Headers Hardening Phase 2.

---

## 10. What I Would Do Differently

### 10.1 Architecture

| Current | What I Would Change | Why |
|---------|-------------------|-----|
| Global function namespace for modules | Enforce PHP namespaces per module (`namespace Modules\Cms\Handlers;`) | Eliminates collision risk, enables autoloading, improves IDE support |
| Ad-hoc auth/CSRF per handler | Declarative middleware groups on route definitions (`'middleware' => ['auth', 'csrf', 'rate:30/min']`) | Eliminates auth/CSRF omissions, centralizes security policy |
| `require_once` chain for dependencies | PSR-4 autoloading via Composer | Enables dependency injection, testability, lazy loading |
| Monolithic bootstrap + request lifecycle | Separate boot phases: `config → services → middleware → route → dispatch` | Reduces per-request overhead, enables selective loading |
| String-based request context store | Typed service container with scoped access | Prevents flag spoofing (`_kernel_db_unguarded`), enables DI |
| CDN dependencies (Alpine, Tailwind) | Self-hosted, build-time compiled assets | Eliminates `unsafe-eval` CSP requirement, improves reliability |

### 10.2 Security

| Current | What I Would Change | Why |
|---------|-------------------|-----|
| ModuleDB regex SQL parsing | Proper SQL tokenizer or AST parser for access enforcement | Eliminates regex bypass vectors |
| Plaintext DB credential fallback | Hard-fail on missing encryption; batch migration CLI tool | Closes the credential exposure window |
| Header-based tenant override open to all | Guard with `requireRole('superadmin')` middleware | Prevents tenant isolation bypass |
| JWT without token_version by default | Include user's `token_version` in every JWT; verify on every request | Enables instant token invalidation on password change |
| `unsafe-eval` in CSP | Migrate to `@alpinejs/csp` + build-time Tailwind | Eliminates XSS amplification vector |
| No per-request CSRF rotation | Double-submit cookie pattern with per-request token for critical operations | Reduces CSRF token reuse window |
| Cart prices from client snapshot | Server-side price re-validation at `ecCalculateTotals()` | Eliminates price manipulation |

### 10.3 Operational

| Current | What I Would Change | Why |
|---------|-------------------|-----|
| DB-based rate limiting | Redis/APCu first, DB fallback | Reduces auth-path latency under load |
| No structured error codes | Return machine-readable error codes in all API responses | Enables client-side error handling, monitoring, alerting |
| Log file-based only | Structured JSON logging to stdout (12-factor) + centralized log aggregation | Enables real-time alerting, cross-request correlation |
| Manual module install via ZIP upload | Signed module packages with GPG/RSA signature verification | Prevents tampered module installation |
| No webhook/event signature verification | HMAC-SHA256 signatures on all outbound webhooks | Prevents webhook spoofing |

### 10.4 Testing

| Current | What I Would Change | Why |
|---------|-------------------|-----|
| Integration tests only | Add unit tests for security-critical paths (JWT, ModuleDB enforcement, tenant resolver, rate limiter) | Isolates security regressions, faster feedback |
| No fuzzing | Fuzz all input parsers (JSON, SQL in ModuleDB, ZIP installer, DiSyL template parser) | Discovers edge cases regex-based parsers miss |
| No static analysis | PHPStan level 8 + Psalm security analysis | Catches type errors, taint analysis, dead code |
| No dependency scanning | `composer audit` in CI + Dependabot | Catches known CVEs in dependencies |

---

## 11. Consolidated Finding Matrix

| # | Severity | Category | Finding | Location |
|---|----------|----------|---------|----------|
| F1 | **CRITICAL** | Data Isolation | `_kernel_db_unguarded` context flag bypass | `ModuleDB`, module-manager.php |
| F2 | **CRITICAL** | Tenancy | Header-based tenant override without role guard | `TenantResolver.php` |
| F3 | **CRITICAL** | Ecommerce | Price snapshot trusted from client cart data | `40-pricing.php` |
| F4 | **CRITICAL** | Data Isolation | ModuleDB SQL parsing bypass (multi-statement, backtick DDL) | `ModuleDB.php` |
| F5 | **HIGH** | Auth | JWT tokens not invalidated on password change | `Jwt.php`, auth-handlers.php |
| F6 | **HIGH** | Tenancy | Plaintext DB credentials during migration | `DatabaseManager.php` |
| F7 | **HIGH** | CSP | `unsafe-eval` permanent XSS amplification | `SecurityHeaders.php` |
| F8 | **HIGH** | Ecommerce | Missing CSRF on cart endpoints | `15-public-cart.php`, `82-api-cart.php` |
| F9 | **HIGH** | CMS | Raw HTML output in html_embed widget | `builder-renderers.php` |
| F10 | **HIGH** | Module System | Capability provider priority hijacking | `CapabilityRegistry.php` |
| F11 | **HIGH** | Module System | Capability timeout is post-execution only | `CapabilityBus.php` |
| F12 | **HIGH** | Network | X-Forwarded-* header trust without proxy validation | `bootstrap.php` |
| F13 | **MEDIUM** | CMS | SVG upload allows stored XSS | `15-utils.php` |
| F14 | **MEDIUM** | Auth | Rate limit default too permissive (10/5min) | `bootstrap.php` |
| F15 | **MEDIUM** | Auth | No authorization audit logging | `App.php` |
| F16 | **MEDIUM** | Auth | Generic exception catch in user resolution | `App.php` |
| F17 | **MEDIUM** | Tenancy | No runtime query-level tenant scoping | `DatabaseManager.php` |
| F18 | **MEDIUM** | Ecommerce | Order confirmation tokens never expire | `20-orders.php` |
| F19 | **MEDIUM** | Ecommerce | No rate limiting on checkout/cart | `82-api-cart.php`, `86-api-checkout.php` |
| F20 | **MEDIUM** | Bridge | Unbounded recursive mapping validation | `IntegrationBridge.php` |
| F21 | **MEDIUM** | Bridge | Event variable information disclosure | `IntegrationBridge.php` |
| F22 | **MEDIUM** | Module System | Audit log blind spot for CMS-source users | `ModuleContext.php` |
| F23 | **MEDIUM** | Module System | Function namespace collision not prevented | module-manager.php |
| F24 | **MEDIUM** | Security Headers | `applyPHPSettings()` defined but never called | `SecurityHeaders.php` |

---

## 12. Recommended Remediation Roadmap

### Phase 1 — Critical (Immediate, within 1 sprint)

| Finding | Action | Effort |
|---------|--------|--------|
| F1 | Replace `_kernel_db_unguarded` with cryptographic escalation token | 1 day |
| F2 | Add superadmin role guard to header-based tenant strategy | 0.5 day |
| F3 | Re-fetch product prices from DB in `ecCalculateTotals()` | 1 day |
| F4 | Disable `PDO::ATTR_EMULATE_PREPARES`, reject `;` in ModuleDB, expand forbidden keywords | 1 day |

### Phase 2 — High (Within 2 sprints)

| Finding | Action | Effort |
|---------|--------|--------|
| F5 | Add `token_version` to users table + JWT flow | 1 day |
| F6 | Add plaintext credential warning + batch migration CLI | 1 day |
| F7 | Migrate to `@alpinejs/csp` + build-time Tailwind; remove `unsafe-eval` | 3 days |
| F8 | Add CSRF verification to all cart POST endpoints | 0.5 day |
| F9 | Add HTML purifier for html_embed widget for non-admin roles | 1 day |
| F10 | Use FIFO registration order as priority tiebreaker | 0.5 day |
| F11 | Document timeout limitation; add `pcntl_alarm()` guard for untrusted providers | 1 day |
| F12 | Add `TRUSTED_PROXIES` env var; validate `REMOTE_ADDR` before trusting forwarded headers | 0.5 day |

### Phase 3 — Medium (Within 1 quarter)

| Finding | Action | Effort |
|---------|--------|--------|
| F13 | SVG sanitization on upload (strip scripts/event handlers) | 1 day |
| F14-F16 | Reduce rate limit, add auth audit logging, narrow exception catch | 0.5 day |
| F17 | Add startup DB name validation per tenant connection | 0.5 day |
| F18-F19 | Add order token expiration, cart/checkout rate limits | 1 day |
| F20-F21 | Add recursion depth limit, redact event vars in production | 0.5 day |
| F22 | Add `actor_module_user_id` column to audit_logs | 0.5 day |
| F23-F24 | Enforce function prefixes, remove dead `applyPHPSettings()` | 0.5 day |

### Phase 4 — Strategic (Ongoing)

- Migrate to namespaced modules with PSR-4 autoloading
- Implement declarative middleware pipeline on route definitions
- Replace ModuleDB regex parser with SQL tokenizer
- Add PHPStan level 8 + security-focused Psalm rules to CI
- Introduce signed module packages for production installs
- Move to structured JSON logging with centralized aggregation

---

*This evaluation is based on static code review of the `phase-5` branch as of 2026-04-10. Runtime dynamic analysis (penetration testing, fuzzing) is recommended as a follow-up to validate these findings and discover additional runtime-specific vulnerabilities.*
