# `co_owns_tables` Governance Policy

## Status
Accepted (2026-06). Codifies existing practice.

## What `co_owns_tables` Means

A module declaring `co_owns_tables` in `module.json` grants itself full CRUD access (SELECT, INSERT, UPDATE, DELETE) on tables owned by another module — as if it were the owner. This is enforced by `ModuleDB`, which merges `owns_tables` and `co_owns_tables` into the permission set.

Unlike `reads_tables` (SELECT only), `co_owns_tables` is a **shared mutation authority** declaration.

## When It Is Acceptable

### Shared Infrastructure Tables
Tables that exist to serve multiple modules and don't belong to any single business domain:

| Table | Co-owned By | Purpose |
|---|---|---|
| `audit_logs` | wms, cms | Centralized audit trail |
| `rate_limits` | ecommerce, wms | Rate limiting infrastructure |

These are acceptable because no single module "owns" the audit trail concept — it's a cross-cutting concern.

### Tightly Coupled Domain Tables
Tables where one module's business domain naturally extends another's:

| Table | Owned By | Co-owned By | Rationale |
|---|---|---|---|
| `cms_content_types` | cms | ecommerce | Ecommerce products use CMS content types as their entity foundation |
| `cms_password_resets` | cms | ecommerce | Ecommerce shares the CMS auth flow for password resets |

These are **design smells** that should be tracked for future refactoring.

## Governance Rules

1. **DDL authority stays with the owner.** The owning module's migrations define the table schema. Co-owning modules must not run DDL (CREATE/ALTER/DROP) on co-owned tables — `ModuleDB` blocks DDL for all modules anyway, but the convention must be documented.

2. **Co-ownership is declared at module load time.** The `co_owns_tables` entry in `module.json` is parsed during `discoverModules()` and enforced by `ModuleDB` on every query.

3. **Co-ownership cannot be transitive.** If module A co-owns a table from module B, that does not grant module C any access to the table. Module C must declare its own `reads_tables` or `co_owns_tables`.

4. **Co-ownership should have a sunset plan.** Tables in the "design smell" category should have a documented path to capability-mediated access. Example: `cms_content_types` co-owned by ecommerce → future capability `cms.content_type.resolve@1`.

## Sunset Path

For each co-owned table in the "design smell" category:
1. Identify the capability that should replace direct mutation
2. Implement the capability in the owning module
3. Migrate the co-owning module to use the capability
4. Remove the `co_owns_tables` entry
5. The table returns to single-owner status

## Migration Coordination

When the owning module needs to alter a co-owned table's schema:
1. The owning module writes the migration
2. Before deploying, check all `module.json` files for `co_owns_tables` referencing that table
3. Verify that the schema change won't break the co-owning module's queries
4. If it would break, coordinate: either update the co-owning module first, or add a compatibility migration

## Enforcement

`ModuleDB` (`kernel/Contracts/ModuleDB.php`) enforces table ownership at the PDO level:
- `owns_tables` + `co_owns_tables` → full CRUD
- `reads_tables` → SELECT only
- Undeclared → `RuntimeException`

This enforcement is active in every request — it is not a recommendation.
