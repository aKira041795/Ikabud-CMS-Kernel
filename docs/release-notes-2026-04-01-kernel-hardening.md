# Release Notes - 2026-04-01 (Kernel Hardening Wave - Phases 1-3A)

## Summary

This release introduces comprehensive foundational stability, scalability, and security enhancements to the Ikabud Application Kernel OS. It completes Phases 1, 2, and the first part of Phase 3 of the **Kernel Hardening Wave**, stabilizing the request lifecycle, ensuring deterministic event/hook systems, isolating tenant databases gracefully, and locking down redirect, CSRF, and static access attack vectors.

## Included Changes

### Architecture & Capabilities
- **Request Pre-Dispatch Seam:** Introduced `kernel.request.before_dispatch` hook inside `public/index.php`. Allows intercepting requests, rewriting URIs/methods, or fully short-circuiting control flow prior to module route resolution.
- **Centralized Auth Redirects:** Refactored core/CMS login and authenticated boundary verification (like `pageHome` and `pageLogin`) into the `kernel.request.before_dispatch` layer, standardizing redirects and significantly cleaning up root handler logic.
- **Request Lifecycle Shutdown:** Formalized `kernel.shutdown` hook registry with guaranteed execution per-request via `register_shutdown_function()`, useful for post-request resource teardown or queued work.
- **Deferred Events Queue:** Enhanced the `EventBus` with `defer()`, `fireDeferred()`, and `flushDeferred()` APIs, allowing reliable non-blocking event dispatch auto-flushed at request shutdown.
- **Request Context Helpers:** Migrated raw kernel globals (like `_kernel_db_unguarded` and capability call states) to the `kernel_request_context_...` API for clean per-request isolation overhead.

### Security
- **Redirect Hardening:** Direct redirects via `app()->redirect()` or core handlers are strictly validating targets explicitly to prevent CRLF injection and Open-Redirect vulnerabilities.
- **CSRF Rotation:** Enhanced token rotation capabilities inside `App::csrfRotate()`, explicitly deployed across privilege elevation boundaries like successful logins and targeted refreshes.
- **Login Rate-Limiting:** Added `kernelConsumeLoginRateLimit()` to provide configurable atomic limit buckets across the unified login capabilities, averting credential-stuffing.
- **Tenant Module Settings Firewall:** Blocked unintentional multi-tenant data bleed by actively rejecting global fallback reads/writes when tenant resolution fails (`tenant mode active but tenant ID unresolved`).

### Performance & Stability
- **Database Resilience:** Added auto-validation of stale PDO connection handles inside `KernelPDO`, `ConnectionPool`, and the tenant control planes via idle timestamps, solving connection dropouts during long-lived processing requests.
- **Workflow State Redundancy:** Augmented the WorkFlow Runtime with comprehensive DB-failure diagnostic payload logging and robust connection-aware query retries during safe entity transitions.
- **Hook Semantics & Caching:** Formalized hook `filter()` semantics to explicitly bypass processing if a listener returns `null` unless invoked specifically via `filterNullable()`. Implemented lazy sorting across event and hook busses alongside lazy statistics caching for faster startup execution speeds.
- **Deterministic Capability Testing:** Extended the Capability Test Runner CLI to seamlessly interpret isolated sql setups/teardowns per test case from capability JSON fixtures.

## Phase 3B: DB Interceptor Seam, Adapter Contracts, and Registry Introspection

### DB Query Interceptor Seam
- **`kernel.database.query.before` filter hook** — Registered on `QueryBuilder::execute()`. Listeners receive `['sql' => ..., 'bindings' => [...]]` and may return a modified array to rewrite the SQL or bindings before execution. Registered via `app()->hooks()->filter()`.
- **`kernel.database.query.after` event** — Emitted on `QueryBuilder::execute()`, `KernelPDO::query()`, `KernelPDO::exec()`, and `KernelPDOStatement::execute()`. Carries `['sql' => ..., 'bindings' => [...], 'duration_ms' => ...]` for observability (logging, profiling, APM). Registered via `app()->events()->fire()`. Hook/event failures in both seams are caught and suppressed so that no hook error can crash a DB operation.
- **`KernelPDOStatement`** (`kernel/Database/KernelPDOStatement.php`) — New `PDOStatement` subclass registered via `PDO::ATTR_STATEMENT_CLASS` on every `KernelPDO` instance. Intercepts `execute()` to emit the after-event for all prepared-statement executions.

### Kernel Adapter Contracts
Two new PHP interfaces have been added under `kernel/Contracts/` to enable typed, swappable adapter injection:
- **`CacheContract`** (`kernel/Contracts/CacheContract.php`) — `get`, `set`, `delete`, `clear`, `has` — standard cache adapter surface.
- **`CapabilityProviderContract`** (`kernel/Contracts/CapabilityProviderContract.php`) — `getCapabilityId`, `getInputSchema`, `getOutputSchema`, `handle` — typed capability provider surface for modules that export structured capabilities.

### ContextRegistry Introspection
Three public getter methods added to `kernel/EntityContext/ContextRegistry.php`:
- `getRegisteredSchemas()` — returns all registered render schemas
- `getRegisteredProfiles()` — returns all registered context profiles
- `getRegisteredModes()` — returns all registered render modes

These enable diagnostic tooling and test assertions without accessing internal state directly.

---

## Phase 4: JSON Response Standardisation, Ecommerce Contract Fixes, and Workspace Cleanup

### JSON Response Standardisation
- All 41 bare `Content-Type: application/json` header emissions in `public/index.php` have been standardised to include `charset=utf-8` and the `X-Request-Id` correlation header.
- The `429 Too Many Requests` rate-limit response in `bootstrap.php` received the same normalisation.
- New global helper **`kernel_emit_json_response(array $payload, int $status = 200)`** added to `bootstrap.php`. Emits correctly typed JSON with consistent headers and terminates the request lifecycle.

### Ecommerce Render Contract Fixes
- `product.badges` — corrected `$row['badges']` duplicate-assignment bug in `modules/ecommerce/helpers/30-products.php`; badges array is now reliably populated.
- `inventory.badge` — `modules/ecommerce/helpers/05-render-contracts.php` updated to surface inventory badge status correctly.
- `cart.message` — `modules/ecommerce/handlers/15-public-cart.php` default `$message` now initialises as `['type' => '', 'text' => '']` (not `null`) to satisfy the render contract type check.
- `cart.totals.coupon` — `modules/ecommerce/helpers/40-pricing.php` coupon data initialised as `[]` instead of `null` to prevent type mismatch in totals rendering.
- `order.payment.label` — `modules/ecommerce/helpers/20-orders.php` sets `$payment['label'] = ucfirst($payment['gateway'])` before returning, satisfying the payment label contract.

### Workspace Cleanup
- Removed 12 temporary `patch_*.php` and `fix-*.php` scripts from the workspace root that were no longer needed after the hardening fixes were applied.

---

## Verification

- Full suite of Kernel Hardening test cases passing comprehensively around `kernel_request_context`, deferred queues, shutdown firing, and redirect validation logic.
- Request Dispatch Subprocess Integration Test passing.
- Infrastructure and capability functional suites verified and stable.
- Storage and log output verification completed ensuring zero unexpected errors during healthy kernel boot cycles.
- Application log clean after Phase 3B crash fix (resolved `App::bound()` regression and `Hooks::fire()` misuse — both replaced with correct API calls: `app()->hooks()->filter()` and `app()->events()->fire()`).