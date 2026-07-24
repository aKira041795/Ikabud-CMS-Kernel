# Contributor Workflows

## Purpose

This document is the quick-start guide for engineers making changes in the Ikabud kernel and module runtime.

It focuses on:

- local prerequisites
- tenancy-aware expectations
- how to run tests safely
- where to inspect failures
- how to keep refactors low-risk

## Environment Prerequisites

- PHP 8.2+
- MySQL 5.7+ (CI: MySQL 8.0, MariaDB 10.6; production target: Bluehost MySQL 5.7)
- Apache with `mod_rewrite` for browser-driven routing checks
- Composer
- a configured `.env` file for app and database access

For builder work under `modules/cms/builder-ui`:

- Node.js and npm

## Core Runtime Expectations

- `bootstrap.php` runs on every request and sets up environment loading, error handling, helpers, and autoloading.
- `public/index.php` is the request entry point and owns routing, dispatch, security-header setup, tenant entry behavior, and key admin APIs.
- multi-tenant behavior is kernel-owned, not module-owned
- module enablement, settings, entitlements, and migrations are manifest- and registry-driven

## Database Model

There are two database scopes in normal operation:

- control-plane database: tenant registry, shared module catalog and entitlement state
- tenant database: tenant-local application state and enabled module data

When debugging tenant or module behavior, verify which database a code path should be using before changing queries.

## Minimum Test Workflow

Full suite:

```bash
composer test
```

Single test file:

```bash
php tests/request_dispatch_integration_test.php
```

Useful focused tests for kernel and refactor work:

- `php tests/request_dispatch_integration_test.php`
- `php tests/tenant_chaos_test.php`
- `php tests/tenant_db_fail_closed_test.php`
- `php tests/tenant_entry_router_fast_reject_test.php`
- `php tests/manifest_settings_defaults_test.php`
- `php tests/module_catalog_entitlement_test.php`
- `php tests/module_access_request_test.php`

If a change touches ecommerce↔WMS inventory or bridge behavior, also run:

- `php tests/ecommerce_wms_inventory_authority_test.php`
- `php tests/integration_bridge_ecommerce_wms_test.php`

## Test Runner Behavior

The main suite runner is `scripts/run-tests.php`.

Important behavior:

- each `tests/*_test.php` file runs in a subprocess
- `storage/modules.json` is cleared between test files
- cached CMS settings files are cleared between test files

This reduces state leakage, but it does not remove the need to keep each test self-contained.

## CI Tenant Coverage

CI runs against three seeded tenants so module surfaces are exercised across entry contexts:

- tenant `1` — `baronbakeshop` (`daily-ledger` entry)
- tenant `2` — `clientsite` (`cms` entry)
- tenant `3` — `healthcare` (`ehr` entry)

Tenant-local migrations are executed for tenant `2` and tenant `3` using `APP_TENANT_DEFAULT` in CI.

## Logs To Check

Always inspect both logs after reproducing a bug or after a failing test run:

- `storage/logs/app.log`
- `storage/logs/error.log`

Use request IDs to correlate runtime behavior where possible.

## Common Change Workflows

### Kernel refactor

1. identify the exact runtime seam first
2. add or confirm focused regression coverage
3. extract one responsibility at a time
4. re-run the focused suite before moving the next slice

### Module settings or manifest work

1. update the manifest and any owned table or migration declarations
2. validate manifest defaults and tenant settings behavior
3. verify no module DB guard regressions are introduced

### Request dispatch or auth work

1. confirm current dispatch ordering in `public/index.php`
2. preserve CSRF, auth, and tenant resolution order
3. re-run dispatch and tenant hardening tests immediately after edits

## Refactor Guardrails

- prefer seam extraction over rewrite
- keep route paths and public helper names stable during structural work
- do not weaken fail-closed tenant DB behavior
- do not change CSP policy casually; follow the documented compatibility constraints
- inspect logs after failures before assuming the bug is in the latest change

## Related Docs

- `docs/kernel/ARCHITECTURE.md`
- `docs/kernel/kernel-stable-contracts.md`
- `docs/kernel/kernel-os-disyl-roadmap-status.md`
- `docs/kernel/polyglot-service-guide.md`
- `docs/evaluations/ikabud-kernel-refactor-baseline-2026-04-10.md`
- `docs/evaluations/ikabud-kernel-action-plan-2026-04-10.md`