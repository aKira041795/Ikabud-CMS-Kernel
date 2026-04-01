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

## Verification

- Full suite of Kernel Hardening test cases passing comprehensively around `kernel_request_context`, deferred queues, shutdown firing, and redirect validation logic.
- Request Dispatch Subprocess Integration Test passing.
- Infrastructure and capability functional suites verified and stable.
- Storage and log output verification completed ensuring zero unexpected errors during healthy kernel boot cycles.