# MySQL Upgrade Path — MySQL 5.7 → 8.0+

## Current State

Production runs on **MySQL 5.7** via **Bluehost shared hosting**. This is an EOL version (end-of-life since October 2023) that imposes significant feature constraints.

## Features Unlocked by MySQL 8.0

These are currently forbidden due to MySQL 5.7 and are tagged `@mysql57-compat` in `.github/copilot-instructions.md`:

| Feature | Use case | Benefit |
|---------|----------|---------|
| **Common Table Expressions (CTEs)** | Recursive category trees, hierarchical org charts, nested comment threads | Replace app-level recursion with single SQL queries |
| **Window Functions** | Rankings, running totals, time-series comparisons, paginated aggregates | Eliminate separate `SELECT COUNT(*)` for each page |
| **`JSON_TABLE()`** | JSON-to-rows conversion for flexible metadata fields | Replace app-level JSON decode + loop |
| **Enforced `CHECK` constraints** | Column-level validation (e.g., `status IN ('a','b','c')`) | Move validation from app layer to database |
| **`EXCEPT` / `INTERSECT`** | Set difference/intersection queries | Replace `NOT EXISTS` / `IN` with cleaner syntax |
| **`ROW_NUMBER()`** | Deduplication, pagination with stable ordering | Remove temporary-table workarounds |

## Queries That Would Benefit

1. **Category tree traversal** — Recursive CTE to load nested CMS categories in one query
2. **Dashboard rankings** — `RANK() OVER (PARTITION BY ...)` for top-N reports
3. **Time-series aggregation** — `LAG()`/`LEAD()` for period-over-period comparisons
4. **Paginated lists** — `ROW_NUMBER() OVER ()` for stable cursor-based pagination
5. **EHR encounter timelines** — Window functions for patient history sequencing

## Migration Steps

1. **Dump production database:**
   ```bash
   mysqldump -h hostname -u username -p --routines --triggers --single-transaction \
     --default-character-set=utf8mb4 baronbakeshop > production-backup.sql
   ```

2. **Verify charset compatibility:**
   ```bash
   grep -c "utf8mb4" production-backup.sql   # Should be non-zero
   grep "ENGINE=MyISAM" production-backup.sql # Should return nothing
   ```

3. **Restore on MySQL 8.0:**
   ```bash
   mysql -h new-host -u username -p baronbakeshop < production-backup.sql
   ```

4. **Verify foreign key types:**
   ```sql
   SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
   FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
   WHERE REFERENCED_TABLE_SCHEMA = 'baronbakeshop'
     AND REFERENCED_TABLE_NAME IS NOT NULL;
   ```

5. **Update configuration:**
   ```php
   // config/database.php — ensure utf8mb4 charset
   'charset' => 'utf8mb4',
   'collation' => 'utf8mb4_unicode_ci',
   ```

6. **Update `.github/copilot-instructions.md`:**
   - Remove or rewrite the `@mysql57-compat` section
   - Remove the Pre-deployment SQL audit checklist items that are no longer constraints
   - Remove the `bluehost-mysql-compatibility` skill reference from the skills registry

## Hosting Migration

### Bluehost
- Bluehost has not announced MySQL 8.0 availability. Current supported versions are 5.7 and MariaDB 10.3/10.4/10.5/10.6.
- Contact Bluehost support to inquire about MySQL 8.0 timeline.
- Alternative: request a manual upgrade or move to a VPS.

### Alternative Hosts
| Host | MySQL Version | PHP Support | Cost (basic) |
|------|--------------|-------------|-------------|
| **DigitalOcean** App Platform | MySQL 8.0 | PHP 8.x | $12/mo |
| **DigitalOcean** Droplet (VPS) | MySQL 8.0 (self-managed) | PHP 8.x (self-managed) | $6/mo |
| **Linode** (Akamai) | MySQL 8.0 (self-managed) | PHP 8.x (self-managed) | $5/mo |
| **Hetzner** | MySQL 8.0 (self-managed) | PHP 8.x (self-managed) | €3.99/mo |

### CI Considerations
When MySQL 5.7 support ends:
1. Remove the `mysql:5.7` job from `.github/workflows/ci.yml`
2. Remove `label: "production target"` from the mysql-8 job
3. Clean up `@mysql57-compat` tags from codebase

## Post-Migration Cleanup

- [ ] Run `grep -rn "@mysql57-compat" .github/` to find all tagged rules
- [ ] Enable CTEs in new queries where appropriate
- [ ] Enable window functions in reporting queries
- [ ] Add `CHECK` constraints to new tables
- [ ] Remove `SET FOREIGN_KEY_CHECKS = 0/1` workarounds from migration files
- [ ] Update CI to remove the `mysql:5.7` matrix entry
- [ ] Remove the `bluehost-mysql-compatibility` skill from `.github/skills/`
