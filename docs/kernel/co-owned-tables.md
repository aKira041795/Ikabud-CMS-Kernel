# Co-Owned Tables (`co_owns_tables`)

Introduced in kernel **4.0.0** (planned). Backported into the 3.2.x guard so
existing collisions can be cleared up before the bump.

## What

`co_owns_tables` is a third manifest field alongside `owns_tables` and
`reads_tables`. It declares that a module **shares full CRUD** on a table that
is **canonically owned by another module**.

```jsonc
{
  "id": "media",
  "owns_tables": [],                  // module has no tables of its own
  "co_owns_tables": ["cms_media"],    // shared with cms (canonical owner)
  "reads_tables": ["cms_users"]
}
```

## Why

Before `co_owns_tables` two modules declaring the same table in `owns_tables`
produced a guard warning ("intentional sharing should be documented"). Real-world
sharing was mixed with accidental ownership conflicts. The new field makes intent
explicit:

| Manifest field | Meaning | CRUD | Collision rule |
| -------------- | ------- | ---- | -------------- |
| `owns_tables`  | This module is the **canonical owner** (migrations, schema authority). | full | Only one module per table. Second `owns_tables` declaration is a guard error. |
| `co_owns_tables` | This module shares full CRUD on a table owned elsewhere. | full | Multiple modules may co-own the same table. |
| `reads_tables` | Read-only access. | SELECT | No collision rules. |

## Enforcement

`kernel/Contracts/ModuleDB::__construct(...)` now accepts a fourth
`$coOwnsTables` parameter. Internally it merges `owns_tables` + `co_owns_tables`
into the same access list — both grant full CRUD identically. The split exists
purely for **manifest provenance** so the guard can reason about ownership
intent.

`scripts/guard-module-manifests.php`:

- Two `owns_tables` entries for the same table → **error**.
- Any number of `co_owns_tables` entries with at most one `owns_tables` → **ok**.
- `co_owns_tables` without any canonical `owns_tables` declaration → currently
  permitted (canonical owner may be the kernel, e.g. `audit_logs`,
  `rate_limits`).

## Current canonical owners (3.2 → 4.0)

| Table | Canonical owner | Co-owners |
| ----- | --------------- | --------- |
| `audit_logs` | `cms` | `daily-ledger`, `wms` |
| `cms_media` | `cms` | `media` |
| `kernel_search_index` | `cms` | `search` |
| `rate_limits` | `ecommerce` | `wms` |

These canonical assignments are **transitional** — `audit_logs` and
`rate_limits` are conceptually kernel-owned and should migrate to a kernel-level
declaration in a future release.

## Migration checklist when introducing a new shared table

1. Pick the **canonical owner** — usually the module that ships the migration.
2. The owner declares `owns_tables: ["my_table"]`.
3. Every other consumer with write needs declares `co_owns_tables: ["my_table"]`.
4. Every consumer with read-only needs declares `reads_tables: ["my_table"]`.
5. Re-run `php scripts/guard-module-manifests.php` and confirm 0 warnings.
