# baronledger table optimisation — measured on the 2026-10-20 live import

Tested against `baronledger` (tenant 207) after the owner imported the live database on 2026-10-20.
All numbers below are measured, not projected, and each was taken with
`SET SESSION information_schema_stats_expiry = 0` (MySQL 8 otherwise serves 24-hour-old statistics and
makes a successful delete look like a no-op).

## What the imported dataset actually is

| | |
|---|---|
| branches | 21 on record, 18 active in the ledger |
| ledger range | 2026-08-15 → 2026-10-20 |
| `dl_daily_ledger` | 54,265 rows |
| notifications range | **2026-10-01 → 2026-10-20 only (20 days)** |
| `dl_integrity_notifications` | 130,513 rows |
| `dl_integrity_notification_recipients` | 261,025 rows |
| variance flags (the findings notifications are about) | 1,954 |

Largest branch is #8 with 18,512 ledger rows (the pre-October single-branch operation); the other 17
branches each hold 1,000–4,500 rows from 2026-10-02 onward.

## The headline: the notification tables were 96% orphan

Prune dry run (`prune-integrity-notifications.php --tenant=207`):

```
notifications now    : 130,513
variance flags now   : 1,954
CANDIDATES to prune  : 125,761      (96.4% of all notifications)
their recipient rows : 251,522      (removed by CASCADE)
```

130,513 notifications against 1,954 live flags is a **67x bloat factor**: the recompute path
re-derives variance flags on every save and, before the capture fix, minted a fresh notification row
each time instead of collapsing onto `aggregate_key`.

**130,513 rows accumulated in 20 days = 6,526 rows/day.** Left alone that is ~2.4 M rows/year, or
roughly 245 MB/year at the measured ~280 B/row including index — on a schema whose entire working set
must compete for a 128 MB buffer pool shared across all tenants.

## Applied, on the disposable copy

```
deleted              : 125,761
batches              : 252 in 32.3 s
notifications now    : 4,752
candidates remaining : 0        <- idempotent; a re-run finds nothing
```

The worker is safe to re-run and leaves no residue: it is keyed on the flag actually being gone and
no recipient having read it, so a second pass is a no-op rather than a second delete.

## Space actually returned

Two measurements, because they answer different questions.

**Per table (information_schema.tables):**

| table | before | after | |
|---|---:|---:|---|
| `dl_integrity_notifications` | 35.39 MB | 1.89 MB | data 26.22 → 1.52, index 9.17 → 0.38 |
| `dl_integrity_notification_recipients` | 19.19 MB | 0.64 MB | data 12.13 → 0.42, index 7.06 → 0.22 |

**On disk (information_schema.innodb_tablespaces — the ground truth, and it disagrees with the table
view):** the schema occupied **178.77 MB** across 556 tablespaces, not the 112.11 MB the table view
reported. The table view under-reports InnoDB's file-per-table footprint, so any capacity decision
should use the tablespace figure.

`OPTIMIZE TABLE` on the five largest remaining tables then reclaimed **20.06 MB in 32.5 s**:

| table | before | after | reclaimed |
|---|---:|---:|---:|
| `dl_deliveries` | 9.00 MB | **0.94 MB** | 8.06 MB |
| `dl_daily_ledger` | 28.00 MB | 23.00 MB | 5.00 MB |
| `dl_cashier_withdrawals` | 12.00 MB | 9.00 MB | 3.00 MB |
| `dl_delivery_items` | 10.00 MB | 7.00 MB | 3.00 MB |
| `audit_logs` | 23.00 MB | 22.00 MB | 1.00 MB |
| **schema** | **178.77 MB** | **158.70 MB** | **20.06 MB (11.2%)** |

`dl_deliveries` is the cautionary one: 9 MB on disk for 2,872 rows, 89% of the file empty. Fragmentation
on this schema is not proportional to table size, so "which tables are big" is the wrong question —
"which files are mostly empty" is the right one.

## Structural overhead nobody budgeted for

| | tablespaces | disk |
|---|---:|---:|
| holding under 64 KB of data+index | **201** | **27.16 MB** |
| actually holding data | 322 | 127.94 MB |
| total | 556 | 158.70 MB |

201 barely-used tablespaces average 138 KB each — that is 27.16 MB (17% of the schema) occupied by
module tables this tenant does not use. It is the residue of installing every module into the tenant
schema. Reducible only by uninstalling unused modules; not by `OPTIMIZE`.

## Index counterpart

`dl_daily_ledger` carries **8 indexes**, and with fresh statistics (post-`OPTIMIZE`, cache disabled)
its index is **11.61 MB against 5.52 MB of data — 2.1x the data**, about 224 bytes of index per row.

That ratio is worse than the 1.65x first reported, because the earlier figure came from
`information_schema` estimates that had gone stale and were under-reporting both sides. The corrected
figure makes the index question worth more attention than it first appeared: the table is 54,265 rows
in 17.1 MB, and two thirds of that is index maintenance paid on every save.

Six structural duplicates exist across other `dl_` tables, all verified FK-safe (another index still
leads with the same column). See `probe-index-audit.php` and `probe-index-usage.php`. Every secondary
index is maintained on every save and is an extra lock entry on the insert path, so this is the lever
that scales with concurrent branches — but on this data the win is still small next to the prune.

## Recommended order (highest measured value first)

1. **Schedule the prune.** It is the only step measured to remove ~96% of a table pair, it is
   idempotent, and the bloat recurs at 6,526 rows/day until it runs regularly.
2. **Prune, then `OPTIMIZE`, then `ANALYZE`.** Order matters: optimizing first leaves the space free
   again. Expect ~20 MB per pass on the fragmented set.
3. **Confirm the capture fix is deployed to live.** Pruning repeatedly treats the symptom; the
   `aggregate_key` / `ON DUPLICATE KEY` collapse is what stops the 67x regeneration.
4. **Drop the six duplicate indexes** (migration) — small here, but it narrows the lock surface under
   concurrent branch writes.
5. **Do not touch `dl_daily_ledger`'s own indexes on local evidence.** Local counters cover 33 hours of
   staging traffic; `run probe-index-usage.php` against live before deciding.

## Caveats on this test

- The local copy has now had its orphan notifications deleted and five tables rebuilt. It is no longer
  a pristine import; redump if an exact replica is needed.
- `OPTIMIZE` on MySQL 5.7 (Bluehost) rebuilds the table and needs free disk roughly equal to the table
  size. `innodb_file_per_table` is `ON` on this server, which is what allows the space to be returned
  to the OS.
- `information_schema.tables` reported 112.11 MB where the tablespaces said 178.77 MB. Treat the table
  view as a ranking tool, not a capacity tool.
