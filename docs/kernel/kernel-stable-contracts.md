# Kernel Stable Contracts

## Purpose

This document distinguishes extension points that modules and external integrations can rely on from internals that may be reorganized during kernel refactors.

## Stable Contracts

The following should be treated as compatibility-sensitive.

### 1. Module manifest structure

The following manifest concepts are stable contracts:

- module identity fields such as `id`, `name`, and `version`
- route and handler entry declarations
- `owns_tables`
- migration and SQL artifact declarations
- capability `provides` and `requires`
- settings field definitions used by module settings UIs
- auth-cookie declarations used by kernel auth discovery

Changing the meaning of these fields is a breaking platform change.

### 2. Route map conventions

These conventions are stable:

- route files remain declarative
- module handlers continue using `module-id:functionName`
- kernel-owned routing remains the gatekeeper for auth, tenant context, and dispatch

### 3. Capability IDs and payload contracts

Capability identifiers with version suffixes, for example `ecommerce.orders.tracking.sync@1`, are stable contracts.

Rules:

- do not change the meaning of an existing version in place
- add a new version when payload semantics change materially
- keep provider behavior compatible within a version

### 4. Hook and event names

Published hook and event names are compatibility-sensitive once used by modules or integrations.

Rules:

- do not rename hook or event identifiers casually
- do not silently remove event payload fields relied on by existing listeners
- prefer additive payload changes over destructive ones

### 5. Tenant and auth safety invariants

These behaviors are stable and must be preserved:

- fail-closed tenant DB behavior
- tenant-aware JWT rejection when multi-tenancy is enabled
- kernel-owned CSRF enforcement for browser-mutating routes
- centralized security-header application
- module manifest validation before load

### 6. Module settings and entitlement helpers

These helpers are effectively part of the platform surface while modules depend on them:

- tenant settings read/write helpers
- module enable/disable helpers
- entitlement and access-request helpers
- migration synchronization helpers used during provisioning and CLI flows

Internal implementation can move, but external behavior should stay stable during decomposition.

## Internal Implementation Details

The following can be reorganized as long as stable behavior remains unchanged:

- file placement of helper implementations
- service extraction from `kernel/App.php`
- front-controller helper extraction from `public/index.php`
- decomposition of `src/helpers/module-manager.php`
- caching strategy details that do not alter externally visible behavior

## Refactor Rule

When in doubt:

1. preserve IDs, names, and payload shapes
2. move implementation behind compatibility shims
3. update docs and tests before removing an old path

## Validation Expectations

Changes touching stable contracts should rerun:

- request dispatch integration coverage
- tenant isolation and fail-closed tests
- manifest and module settings defaults coverage
- any feature-specific bridge or module tests affected by the contract