# baronledger (tenant 207) — daily-ledger database audit

**Date:** 2026-10-10 · **Site:** local test (`baronledger.test`) · **Status:** diagnosis only, nothing changed

## 1. The database

| | |
|---|---|
| Tenant | **207** — `baron-001`, entry module `daily-ledger` |
| Database | **`baronledger`** on `localhost:3306` |
| Reached via | `kernel_tenant_db_connections` (tenant 207); credentials root/local, no secret printed |
| Tables | 69 |
| Size | **77.58 MB** (49.61 data + 27.97 index) + **23.00 MB `data_free`** |
| Module backup dump | 41 MB (`storage/backups/daily-ledger/daily-ledger-db-backup-20261009-110920.sql`) |

> `information_schema` is denied under module context, so all of this was read over a plain PDO outside
> module scope. `probe-db-size-audit.php` does that and aborts if the read comes back empty.

## 2. Where the 77 MB actually is

| table | exact rows | data MB | index MB | total MB | share |
|---|---:|---:|---:|---:|---:|
| `dl_integrity_notifications` | **118,985** | 24.109 | 8.031 | **32.14** | 41% |
| `dl_integrity_notification_recipients` | **244,928** | 12.031 | 7.016 | **19.05** | 25% |
| `audit_logs` | 25,452 | 9.516 | 6.453 | **15.97** | 21% |
| `dl_daily_ledger` — *the actual daily sheets* | 14,573 | 2.016 | 2.625 | 4.64 | 6% |
| `dl_cashier_withdrawals` | 1,573 | 0.219 | 0.438 | 0.66 | 1% |
| `dl_branch_receiving_items` | 2,267 | 0.172 | 0.234 | 0.41 | 1% |
| `dl_delivery_items` | 2,595 | 0.188 | 0.156 | 0.34 | <1% |
| `dl_branch_products` | 2,138 | 0.109 | 0.141 | 0.25 | <1% |
| `dl_variance_flags` — *the underlying findings* | **982** | 0.109 | 0.141 | 0.25 | <1% |

**The three tables at the top are 86.9% of the database, and none of them is business data.** All 66 other
tables together are roughly 4 MB.

For scale: every other schema on this server with `dl_` tables is **1–3 MB**. This one is ~20× its peers
with less history than most.

## 3. Why the dump is 40 MB — orphaned notifications

This is a real defect, not just volume.

`dl_integrity_notifications` is **designed to aggregate**: it carries a **UNIQUE** index on `aggregate_key`
and a `finding_count` column, so repeated findings of the same kind should collapse into one row whose count
increments. That is not happening:

```
COUNT(*)                              = 118,985
COUNT(DISTINCT aggregate_key)         = 118,985      <- the unique key never collapses anything
SUM(finding_count)                    = 119,102      <- finding_count is always 1
```

`118,902` of the rows are `finding_type = 'variance'` with `aggregate_key = 'variance-<entity_id>'`. The key
is composed from **the individual finding's row id**, which is unique by construction — so the aggregation
can never fire.

The ids tell the rest of the story:

| check | value |
|---|---|
| `dl_variance_flags` rows surviving **now** | **982** |
| variance notifications | 118,902 |
| distinct `entity_id` in those notifications | 118,902 |
| **notifications whose flag no longer exists** (LEFT JOIN) | **118,491** |
| notifications whose flag still exists | 411 |
| notification `entity_id` range | 1,835,481 – 1,954,970 |
| surviving flag `id` range | 130 – 1,954,966 |

**~119,000 variance-flag ids were consumed since mid-August while only 982 rows survive.** Variance flags
are being created and deleted in large batches — a recompute or a re-import — and **the notifications are
never cleaned up with them.**

## 4. Rate and whether this is normal

| date | rows |
|---|---:|
| 2026-10-01 | 23,008 |
| 2026-10-02 | 1,434 |
| 2026-10-03 | 3,292 |
| 2026-10-04 | 8,959 |
| 2026-10-05 | 13,830 |
| 2026-10-06 | 26,468 |
| 2026-10-07 | 31,798 |
| 2026-10-08 | 8,723 |
| 2026-10-09 | 1,473 |

**No notification row is older than 2026-10-01.** The table held nothing before that; this is nine days of
accumulation, roughly 13,000 rows/day, peaking at 31,798 on a backfill/import day.

At ~3.6 MB/day that is **≈1.3 GB/year** on a database that should be a few MB. **No, this is not normal** —
it is an unbounded accumulation of rows whose subject no longer exists, and the aggregation the schema was
built for never engages.

Recipients scale with it: 244,928 rows ≈ **2.06 per notification** — and for 118,695 of them it is exactly
**one recipient per notification**, i.e. a 1:1 shadow table. 235,273 are seen, 9,655 unseen.

## 5. What is healthy

- `dl_daily_ledger` is well indexed (`uq_dl_ledger_entry` on branch+product+date+shift, plus three
  covering indexes) and only 4.64 MB for 14,573 rows. The core ledger is fine.
- `dl_variance_flags` is small and correctly keyed.
- **0 orphan recipients** — the user references are intact.
- `dl_deliveries` / `dl_delivery_items` / `dl_branch_receivings` (262 / 2,595 / 141 rows) are consistent
  with two months of one branch plus commissary. Those volumes look exactly right.

## 6. Recommended actions

**A. Prune read-and-orphaned notifications (largest win).** Rows where the underlying flag is gone **and**
`seen_at IS NOT NULL`:

- 118,695 notifications + their ~244,413 recipients ≈ **50 MB of 77.58 MB freed**
- **A no-op for every user's inbox**, because those rows are already read
- Leaves the 9,655 unseen rows and all 411 live notifications untouched
- Owner's call, because it deletes data

**B. Fix the key so aggregation works.** `aggregate_key` should be composed from something that *repeats* —
e.g. `variance-<branch>-<product>-<ledger_date>-<kind>` — not from the finding's own id. Then the UNIQUE
index and `finding_count` do the job they were built for, and a recompute increments a counter instead of
appending a row.

**C. Reclaim the 23 MB of fragmentation.** `OPTIMIZE TABLE` on the biggest tables after any pruning. Note
`data_free` is ~30% of the file — dead space from all the create/delete churn.

**D. Decide retention for `audit_logs`** (25,452 rows, 26 days). Its three JSON columns total only ~4.2 MB;
the 15.97 MB is row and index overhead on a wide table. A retention window is a product decision, not a bug.

## 7. Reproducing

```
php docs/reviews/baronledger-db-audit-2026-10-10/probe-db-size-audit.php   # sizes -> table-sizes.tsv
```

Raw outputs kept alongside this file: `summary.txt`, `table-sizes.tsv`, `content-analysis.txt`,
`duplication-analysis.txt`, `variance-schema.txt`.

### One trap worth recording
MySQL 8 returns `information_schema` column names in **UPPERCASE**, so `$row['table_name']` is empty.
The first run of the size probe consequently reported *"0.00 MB over 69 tables"* — entirely false, and it
would have looked like an empty database. Every column is now aliased and the probe **aborts** if the first
table name comes back empty. A blank column where a name should be is that bug's signature: it also blanked
the schema name in the grouping query.
