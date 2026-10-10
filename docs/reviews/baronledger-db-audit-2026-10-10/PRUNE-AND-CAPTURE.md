# Daily Ledger notification storage — prune design + efficient capture

**Tenant 207 (`baron-001`, DB `baronledger`)** · 2026-10-10 · prune executed on the local test DB

---

## 1. The problem, in one line

86% of a 77 MB database was `dl_integrity_notifications` + `dl_integrity_notification_recipients`, and
**118,491 of 118,902 variance notifications pointed at a variance flag that no longer exists.**

## 2. Root cause (measured, not inferred)

`dl_upsertVarianceFlag()` raised its notification with:

```php
dl_raiseIntegrityNotification($db, 'variance-' . $flagId, ...);   // the flag's SURROGATE id
```

But a ledger recompute deliberately owns those flags and replaces them (handlers.php ~6911):

```sql
DELETE FROM dl_variance_flags
 WHERE branch_id = :bid AND ledger_date = :d
   AND resolution_status = 'unreviewed' AND kind <> 'delivery'
```

The delete is correct. The bug is that the notification key was derived from the **auto-increment id**, so
re-deriving the *same* variance minted a new id → a new notification row → plus its recipient rows. Every
recompute, forever. Confirmed by JOIN rather than assumed:

| check | value |
|---|---:|
| `COUNT(*)` | 118,985 |
| `COUNT(DISTINCT aggregate_key)` | 118,985 ← the UNIQUE index never collapsed anything |
| `SUM(finding_count)` | 119,102 ← every count is 1 |
| notifications whose flag is gone (LEFT JOIN) | **118,491** |
| `dl_variance_flags` rows surviving | **982** |
| rate | ~13,000 rows/day, ~3.6 MB/day ≈ **1.3 GB/year** |

Two second-order defects shared the cause:

- **`INSERT IGNORE` discarded repeats** instead of incrementing. `aggregate_key` is UNIQUE and
  `finding_count` exists precisely to collapse repeats — migration 068 says *"One digest, never one row per
  historical receipt"*. The count stayed 1 and the occurrence was silently lost.
- **The recipient fan-out was one statement per user**, up to 27 round-trips per finding, on a path that can
  run once per finding during a recompute.

Note the delivery path was **already correct**: it does SELECT-then-UPDATE (handlers.php ~7104) so its flag id
stays stable, and only 13 `delivery-` rows existed versus 118,900 `variance-` ones. That is why the fix is
one call site, not a rewrite.

## 3. Prune design

`modules/daily-ledger/cli/prune-integrity-notifications.php`

**The criterion — all four must hold**, so it cannot touch anything live or unread:

1. `finding_type = 'variance'` AND `entity_type = 'dl_variance_flags'`
2. the referenced `dl_variance_flags` row **no longer exists** — the subject is gone, so it is stale, not pending
3. at least one recipient exists — someone was actually told
4. **no** recipient still has `seen_at IS NULL` — everyone has read it

Recipient rows are removed by the existing `ON DELETE CASCADE` (`fk_dl_inr_notification`), not a second
statement. Verified present on the live schema, not assumed from the migration.

### Why it is stable

| property | how |
|---|---|
| non-destructive by default | **dry run unless `--apply`** |
| bounded | `--batch` rows per statement, `--max-batches` ceiling → known worst-case runtime |
| resumable + idempotent | criterion is state-based; each batch commits; a re-run finds nothing (proven) |
| no long locks | 500-row statements with a pause between them |
| cannot collide | `flock` guard per tenant |
| leaves a rollback path | pre-prune snapshot taken and load-tested |

### MySQL 5.7 compatibility (live runs an older server than this dev box)

Deliberately uses only 5.7-safe syntax — **running on local MySQL 8 proves nothing about 5.7, so the
constraints are avoided by construction**:

- no CTE (`WITH`), no window functions, no `JSON_TABLE`, no `EXCEPT`/`INTERSECT`
- **no multi-table DELETE with `LIMIT`/`ORDER BY`** — 5.7 permits those in the single-table form only
- the batch id set is a **materialised derived table**, which is also what avoids MySQL error 1093
  (*can't specify target table for update in FROM clause*). No scratch table, so nothing is added to the
  module schema and no migration is needed.
- `LIMIT` takes a bound parameter (supported in 5.7 prepared statements)

### Result on the local DB

| | before | after |
|---|---:|---:|
| notifications | 118,985 | **3,064** |
| recipients | 244,928 | **13,462** |
| schema size | 77.58 MB | **29.89 MB** |
| data-only dump (whole schema) | 41 MB | **12.1 MB** |

Pruned **115,936 notifications + 231,872 recipients** in **116 batches / 20.3 s**. Then `OPTIMIZE TABLE`
returned the freed extents to the filesystem — without it the tables report the same size and nothing is
actually reclaimed.

### Retention floor — your call

`--retention-days` (default 7) keeps recent rows out of reach entirely:

| floor | candidates |
|---|---:|
| 0–1 day | **115,936** |
| 7 days | 24,428 |

Every row is ≤9 days old, so the floor is the whole difference. Read notifications whose subject is gone are
safe to drop immediately; if you want a week of visible history, keep the default.

## 4. Capture fix

**`dl_upsertVarianceFlag()` — key on the flag's natural identity, matching `uq_dl_variance`:**

```php
'variance-b' . $branchId . '-p' . $productId . '-' . $date . '-' . $kind . '-' . ($shift ?? 'any')
```

Stable across delete/re-insert, and ≤60 chars against a `VARCHAR(190)` key. A recompute now re-raises the
**same** row and increments `finding_count`.

**`dl_raiseIntegrityNotification()` — `INSERT ... ON DUPLICATE KEY UPDATE`:**

```sql
ON DUPLICATE KEY UPDATE
   finding_count = finding_count + 1,
   entity_id = VALUES(entity_id),
   detail = VALUES(detail)
```

`rowCount()` is 1 for an insert and 2 for an update, so `$created` still means *"first time this key was
seen"* and the email-once rule is untouched. `entity_id` is refreshed so the row points at the **current**
flag, which is what keeps the prune's orphan test accurate.

**Recipient fan-out** collapsed to a single multi-row `INSERT IGNORE` (was up to 27 statements per finding);
the email loop is hoisted out and behaviour is unchanged.

Deliberately **not** done: memoising the recipient SELECT per branch. It is a sub-millisecond query over 68
users, and a process-lifetime static cache would go stale in long-running CLI workers for negligible gain.

## 5. Verification

| check | result |
|---|---|
| `test-prune-safety.php` | **8 passed, 0 failed** — protected sets, partition invariant, CASCADE, idempotence |
| `test-capture-fix.php` | **12 passed, 0 failed** — incl. a **control arm** reproducing the defect |
| module tests (`delivery_variance_visibility`, `preserve_cashier_variance`) | **identical with and without the fix** (14/20, 25/29) → the 10 failures are pre-existing |
| partition invariant | `protected 3,049 + candidates 115,936 == 118,985` exactly |

The capture test's control arm is the part that matters: it runs the **old** key form in the same process,
so it demonstrates the defect rather than asserting the fix in isolation.

```
fixed key total rows: 1 | old key total rows: 2
```

Three recomputes on the fixed key → one row, `finding_count` 1 → 2 → 3, same notification id.
Two recomputes on the surrogate key → two rows.

## 6. Operations runbook

```bash
# 1. snapshot FIRST — and use mysqldump, not a per-row writer (a 364k single-row INSERT restore is slow;
#    an extended-insert dump restores in seconds)
mysqldump --single-transaction --quick planet4 baronledger > pre-prune-$(date +%Y%m%d-%H%M%S).sql

# 2. always look before deleting
php modules/daily-ledger/cli/prune-integrity-notifications.php --tenant=207

# 3. apply
php modules/daily-ledger/cli/prune-integrity-notifications.php --tenant=207 --apply

# 4. reclaim — without this the tables still report the old size
#    OPTIMIZE TABLE dl_integrity_notifications, dl_integrity_notification_recipients;
```

Schedule it (weekly is ample once the capture fix is live) after `run-scheduled-reports.php`.

## 7. Two traps worth keeping

- **MySQL 8 caches `information_schema` statistics for 24 h.** After the prune the tables still reported
  32.14 MB / 19.05 MB and the schema still said 77.58 MB — completely false. Use
  `SET SESSION information_schema_stats_expiry = 0`, or read `SHOW TABLE STATUS`. This also affects the
  original audit: its numbers were right, but a stale re-read is indistinguishable from "nothing happened".
- **`information_schema` is denied under module context.** All measurement here goes through a plain PDO
  outside module scope, which is also why the verifier does not share the worker's access path.

## 8. Still open

- **`audit_logs`** is now the largest table (25,452 rows / 15.97 MB). Its three JSON columns are only ~4.2 MB;
  the rest is row and index overhead. A retention window is a product decision.
- The 15 `closed_without_pm_finalize` rows raised during this session already use a natural key
  (`day-<branch>-<date>`), so that path needs no change — worth confirming as new finding types are added.
