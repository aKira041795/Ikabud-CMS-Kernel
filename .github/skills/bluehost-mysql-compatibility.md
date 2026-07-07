---
name: bluehost-mysql-compatibility
description: Bluehost MySQL 5.7 / MariaDB &lt;10.2 compatibility — forbidden features, migration rules, FK type matching, engine requirements, and pre-deployment audit checklist
applyTo: "**/*.php,**/*.sql"
---

# Bluehost / MySQL 5.7 Compatibility

Production deploys to **Bluehost shared hosting** running **MySQL 5.7** (or MariaDB <10.2). Every SQL query, migration, and PHP data-access layer **must** be compatible with these versions.

## Forbidden MySQL 8.0+ features

| Forbidden | Use instead |
|---|---|
| Window functions (`COUNT(*) OVER()`, `ROW_NUMBER() OVER()`, `RANK()`, `LAG()`, `LEAD()`, etc.) | Separate `SELECT COUNT(*)` query, or app-level aggregation |
| Common Table Expressions (`WITH ... AS (...)`) | Derived tables, temporary tables, or app-level logic |
| `JSON_TABLE()` | App-level JSON decode + loop |
| `CHECK` constraints (enforced only in 8.0.16+) | App-level validation or triggers |
| `EXCEPT` / `INTERSECT` set operators | `NOT EXISTS` / `IN` / `JOIN` equivalents |

## Migration rules

- Every `CREATE TABLE` must end with `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci` — Bluehost defaults to MyISAM, which silently drops FOREIGN KEY constraints.
- FK columns must have **exactly** the same type, signedness, and width as referenced columns. `BIGINT UNSIGNED` cannot reference `INT UNSIGNED`.
- Wrap cross-module FK references in `SET FOREIGN_KEY_CHECKS = 0` / `SET FOREIGN_KEY_CHECKS = 1` around the `CREATE TABLE` statement.
- Kernel migrations run before module migrations — never rely on a module-created table in a kernel migration FK without `FOREIGN_KEY_CHECKS=0`.

## Query rules

- Never use `COUNT(*) OVER()` — use a separate `SELECT COUNT(*) FROM ...` query with the same WHERE/JOIN conditions.
- Never use `WITH ... AS (...)` CTEs.
- Always quote identifiers with backticks (MySQL 5.7 is stricter about reserved words).

## Pre-deployment SQL audit

Before deploying to Bluehost, run these checks:

```bash
# 1. No window functions in PHP query strings
grep -rn "OVER()" modules/ src/ kernel/ --include="*.php"

# 2. No CTEs in PHP query strings
grep -rn "WITH.*AS\s*\(" modules/ src/ kernel/ --include="*.php"

# 3. Every migration CREATE TABLE has ENGINE=InnoDB
for f in migrations/*.sql modules/*/database/migrations/*.sql; do
  if grep -ql "FOREIGN KEY" "$f" 2>/dev/null; then
    grep -q "ENGINE=InnoDB" "$f" || echo "MISSING ENGINE=InnoDB: $f"
  fi
done

# 4. No JSON_TABLE, EXCEPT, INTERSECT
grep -rn "JSON_TABLE\|EXCEPT\|INTERSECT" modules/ src/ kernel/ --include="*.php"
```
