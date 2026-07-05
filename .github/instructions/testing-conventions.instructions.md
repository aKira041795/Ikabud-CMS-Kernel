---
description: "Testing conventions — how to write and run PHP integration-style tests in the Ikabud application. Covers bootstrap, fixtures, log checking, and assertion patterns."
applyTo: "**/*Test.php"
---
# Testing Conventions

## Test Style
- Prefer plain PHP integration-style tests under `tests/` that bootstrap the app directly
- Avoid mocks where possible — test concrete behavior

## Before Each Test
- Clear `storage/logs/app.log` and `storage/logs/error.log`

## After Running Tests
- Always check **both** `storage/logs/app.log` and `storage/logs/error.log` — not just test output
- Use `request_id()` / `X-Request-Id` for correlating API failures

## Assertion Patterns
- Assert on concrete behavior (response codes, database state, rendered output)
- Use `app()->dbForTenant()` or module DB patterns as appropriate for database assertions
- For tenant-specific modules, run migrations via `php ikabud tenant:migrate <tenant_id|tenant_key|domain> [module]`

## Current Coverage Priorities
1. Manifest-settings default contract tests across all settings-bearing modules
2. Ecommerce storefront media tests (featured image, gallery fallback, placeholder)
3. CMS entity-list product-card image tests for `/ecommerce/shop` rendering path
4. EHR entity-view integration tests for patient-registry, encounters, and scheduling (`entity.list/*` + `entity.get/*`)
5. Ticketing entity-view integration tests for `entity.list.ticket@1` and `entity.get.ticket@1`, including CMS list/detail rendering paths

## Test Runner
- PHP deps: `composer install` (repo root)
- Kernel tests (from `ikabud-kernel`): `composer test`
- Run individual test files directly via PHPUnit
