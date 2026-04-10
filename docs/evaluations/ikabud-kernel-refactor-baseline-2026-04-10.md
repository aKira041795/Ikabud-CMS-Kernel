# Ikabud Kernel Refactor Baseline

**Date:** 2026-04-10  
**Purpose:** Baseline metrics and guardrails for the kernel refactor program.

## Current Hotspots

Measured on 2026-04-10:

| File | Lines | Primary risk |
|---|---:|---|
| `public/index.php` | 4962 | request lifecycle, routing, admin handlers, dispatch coupling |
| `src/helpers/module-manager.php` | 4988 | discovery, settings, entitlements, catalog, migrations in one file |
| `bootstrap.php` | 1888 | bootstrap and global runtime complexity |
| `kernel/App.php` | 1636 | god-object pressure across DB, auth, render, tenancy |

## Immediate Non-Goals

These are not acceptable side effects of the refactor program:

- changing public route paths while restructuring internals
- weakening tenant DB fail-closed behavior
- changing hook, event, or capability identifiers without versioning or migration
- mixing broad feature work into decomposition PRs
- starting CSP nonce rollout before template coverage exists end to end

## Minimum Safe Regression Suite

Run this set for every PR that touches `bootstrap.php`, `public/index.php`, `kernel/App.php`, or `src/helpers/module-manager.php`.

### Required

```bash
composer test
php tests/request_dispatch_integration_test.php
php tests/tenant_chaos_test.php
php tests/tenant_db_fail_closed_test.php
php tests/tenant_entry_router_fast_reject_test.php
php tests/manifest_settings_defaults_test.php
php tests/module_catalog_entitlement_test.php
php tests/module_access_request_test.php
```

### Add when the touched area requires it

If routing, auth cookies, or admin entry behavior changes:

```bash
php tests/e2e_shared_hosting_test.php
```

If tenant migration or provisioning behavior changes:

```bash
php tests/cli_tenant_migrate_sync_test.php
```

If ecommerce↔WMS inventory or bridge seams change:

```bash
php tests/ecommerce_wms_inventory_authority_test.php
php tests/integration_bridge_ecommerce_wms_test.php
```

## Manual Smoke Checks

When a phase touches routing, auth, or security headers, manually verify:

- `/login`
- `/cms/login`
- one tenant-routed public module page
- superadmin module management screens

## Logs To Inspect

Always inspect after failures:

- `storage/logs/app.log`
- `storage/logs/error.log`

## PR Shape Guidance

- keep extraction PRs narrow and responsibility-based
- move one subsystem at a time
- preserve external helper names with compatibility shims when needed
- add or update targeted tests before deleting old internal code paths

## Exit Criteria For The First Refactor Wave

The first refactor wave is complete when:

- `public/index.php` is primarily orchestration
- `kernel/App.php` delegates DB, auth, render-context, and CSRF concerns
- `src/helpers/module-manager.php` is split by dominant responsibility
- the minimum regression suite stays green across each extraction step