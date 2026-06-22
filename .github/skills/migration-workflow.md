---
name: migration-workflow
description: Adding schema changes — file naming, module.json registration, CLI apply
---

# Migration Workflow

## Steps
1. Create file in `database/migrations/` with next sequential number + descriptive name: `023_add_thing.sql`
2. Write SQL — prefer `CREATE TABLE IF NOT EXISTS` or `ALTER TABLE ADD COLUMN IF NOT EXISTS` where supported
3. **CRITICAL**: Register in `module.json` `migrations` array — without this the migration runner won't find it
4. Apply to tenant: `php ikabud tenant:migrate <tenant_id|domain> [module_name]`
5. Verify in `_migrations` table that it ran

## Common errors
| Error | Cause | Fix |
|---|---|---|
| "Nothing to migrate" | Migration not in `module.json` | Add to `migrations` array |
| `42S22: Unknown column` | Column referenced in query but migration not run | Run migration or use `SHOW COLUMNS` check |
| `42S02: Base table not found` | Table doesn't exist in this tenant's DB | Run module migrations |

## Safe ALTER TABLE pattern
```sql
SET @dbname = (SELECT DATABASE());
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'target_table' AND COLUMN_NAME = 'new_column');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE `target_table` ADD COLUMN `new_column` ...', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
```

## Tenant vs app DB
- Module-owned tables go in tenant DB — migrate with `tenant:migrate`
- Kernel/shared tables go in app DB — migrate with `php ikabud migrate`
- When in doubt, check `module.json` `owns_tables` to see which DB the table belongs to
