# Database Compatibility Profiles

Ikabud supports two database profiles with different feature sets. Every deployment chooses one profile based on its database server and operational requirements.

## Profiles

### Compatibility Profile (`db_profile: compatibility`)

**Target**: MySQL 5.7 / MariaDB 10.1+ on shared hosting.

This is the **production baseline**. All Ikabud features are designed to work within Compatibility profile constraints.

| Property | Value |
|----------|-------|
| **MySQL version** | 5.7.x (or MariaDB 10.1+) |
| **Engine** | InnoDB (required — MyISAM silently drops FK constraints) |
| **Character set** | `utf8mb4` / `utf8mb4_unicode_ci` |
| **CTEs (`WITH`)** | ❌ Not available |
| **Window functions** | ❌ Not available |
| **`JSON_TABLE()`** | ❌ Not available |
| **`CHECK` constraints** | ❌ Not enforced (parse but ignore) |
| **`EXCEPT` / `INTERSECT`** | ❌ Not available |
| **FK type matching** | Strict — column types must match exactly (signedness, width) |
| **Process model** | PHP-FPM, per-request — no persistent workers |
| **Scheduled jobs** | Via kernel job queue + web cron (no system crontab) |
| **Analytics** | Reduced — app-level aggregation instead of SQL windowing |

### Enterprise Profile (`db_profile: enterprise`)

**Target**: MySQL 8.0+ / MariaDB 10.11+ on dedicated or cloud hosting.

All Compatibility features work on Enterprise. Additionally:

| Property | Value |
|----------|-------|
| **MySQL version** | 8.0+ (or MariaDB 10.11+) |
| **CTEs (`WITH`)** | ✅ Available — recursive queries, readable analytics |
| **Window functions** | ✅ Available — `ROW_NUMBER()`, `RANK()`, `LAG()`, `LEAD()` |
| **`JSON_TABLE()`** | ✅ Available — JSON-to-relational in SQL |
| **`CHECK` constraints** | ✅ Enforced (8.0.16+) |
| **`EXCEPT` / `INTERSECT`** | ✅ Available |
| **Process model** | PHP-FPM + optional worker processes |
| **Scheduled jobs** | Kernel job queue + system crontab |
| **Analytics** | Full — SQL-level windowing for reports and dashboards |

## Feature gating

Some Ikabud features require Enterprise profile. These are gated at runtime:

| Feature | Minimum profile | Rationale |
|---------|----------------|-----------|
| Advanced report generation | Enterprise | Window functions for running totals, rankings |
| Dashboard analytics widgets | Enterprise | CTE-based data pipelines |
| JSON document search | Enterprise | `JSON_TABLE()` for indexed JSON queries |
| Recursive category/org trees | Enterprise | Recursive CTEs |

When a feature requires Enterprise but the deployment runs Compatibility, the system returns a clear error message referencing `docs/kernel/database-profiles.md`.

## Runtime detection

The kernel detects the active profile automatically:

```php
// kernel/Database/DatabaseProfile.php
$profile = DatabaseProfile::detect($pdo);
// Returns 'compatibility' or 'enterprise'
```

Detection is based on `SELECT VERSION()` and a capability probe query.

## CI testing

| Profile | CI matrix entry | DB image |
|---------|----------------|----------|
| Compatibility | `mysql-5.7` | `mysql:5.7` |
| Compatibility | `mariadb-10.6` | `mariadb:10.6` |
| Enterprise | `mysql-8` | `mysql:8.0` |

All three run on every push and PR. MySQL 5.7 is labeled the "production target" in CI.

## SQL coding rules

When writing SQL for Ikabud:

1. **Default to Compatibility SQL** — no CTEs, window functions, `JSON_TABLE()`, `CHECK`, `EXCEPT`, `INTERSECT`
2. **If you need Enterprise-only SQL**, wrap it in a profile gate:
   ```php
   if (DatabaseProfile::current()->isEnterprise()) {
       // Use CTE / window function
   } else {
       // Use Compatibility fallback
   }
   ```
3. **All migrations must include** `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`
4. **FK columns must match referenced column types exactly**

## Migration from Compatibility to Enterprise

1. Upgrade MySQL to 8.0+ (or MariaDB to 10.11+)
2. Run `php ikabud db:verify-profile` to confirm detection
3. No migration SQL needed — existing schema is fully compatible
4. Enterprise-optimized queries activate automatically via `DatabaseProfile::current()`

## References

- `@mysql57-compat` annotations in `.github/copilot-instructions.md` map to Compatibility profile constraints
- CI workflow: `.github/workflows/ci.yml`
- Architecture doc: `docs/kernel/ARCHITECTURE.md`
