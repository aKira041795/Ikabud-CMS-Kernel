# Daily Ledger — Load & Concurrency Findings (2026-09-16)

Prepared ahead of a rollout from 10 to **23 branches** (up to **46 cashiers**, AM + PM).

This is a different application path from the April 2026 study
(`stress-and-load-test-findings-2026-04-16.md`), which measured the CMS/ecommerce
storefront. That study's caching conclusions do **not** transfer: the ledger is
authenticated, per-user, write-heavy, and never page-cached.

---

## 1. Executive summary

| # | Finding | Severity | Status |
|---|---|---|---|
| 1 | Concurrent ledger saves **deadlock** (`SQLSTATE 40001`, InnoDB 1213) when a day's rows do not exist yet | **High** | **Fixed & verified** |
| 2 | The `day-status` probe was **59% of all fleet demand**, returning 31 bytes for ~360 ms | **High** | **Fixed & verified** |
| 3 | Each ledger view issued a **fully redundant 588 KB row fetch** | Medium | **Fixed & verified** |
| 4 | `beg_bal`/`bal_end` cells carried ~3.4 KB of inline markup **per row** (793 KB page) | Medium | **Fixed & verified** |
| 5 | Login capped at **5 per IP per 5 min, counting successful logins** | Medium | **Fixed & verified** |

**Verdict after the fixes:** peak fleet demand falls from **2.58 → 1.43 req/s**, and
the per-view payload from **793 KB → 631 KB**. Against the April study's projected
Bluehost shared-hosting capacity (1.5–3 req/s), the 23-branch fleet now sits at
**roughly half** of the conservative projection instead of at or above it. The
remaining headroom is still thin enough that the production host must be re-measured
with the same harness before all 23 branches are enabled.

### Changes implemented the same day

| Change | Measured effect | Proof |
|---|---|---|
| Dropped `FOR UPDATE` from the audit pre-read (online + offline) | 0 deadlocks in 13 consecutive fresh-day runs (was 2 of 6 runs) | InnoDB report + isolation matrix |
| Probe 30 s → 120 s, skip hidden tabs, probe on tab return, 15 s drain tick | fleet probes **1.53 → 0.38 req/s**; total demand **−45%** | demand model, live behaviour check |
| Removed the automatic HTMX row re-fetch | **−1 request and −588 KB per ledger view** | 0 row fetches on load; manual refresh still fires |
| Collapsed repeated utility strings into CSS classes | rows **588 → 422 KB (−28%)**, page **793 → 631 KB (−21%)** | 0 mismatched computed styles; 0 differing pixels outside the live clocks |
| Reset the login counter on a **verified** success | 30/30 correct logins succeed (was 5); brute force still throttles at attempt 6 | live login probe |

All verified on the dev tenant (207) with 1305/0 across the 15 daily-ledger suites.


---

## 2. Method

Harness: `tests/load/daily_ledger_load_test.php` (30 virtual identities, `curl_multi`
pool). Fixtures: `tests/load/seed_load_users.php`.

Concurrency is driven from **N distinct sessions**. This is essential, not cosmetic:
daily-ledger handlers never call `releaseSessionAfterRender()`, so PHP holds the
session file lock for the whole request and simultaneous requests from one login
serialize. A single-session test would measure the session lock, not the server.

Endpoints exercised (the real cashier workload):

```
ledger page       GET  /daily-ledger/ledger
rows (HTMX swap)  GET  /daily-ledger/ledger/rows
day-status probe  GET  /daily-ledger/api/v1/cashier/ledger/day-status   (every 30s per tab)
adjustments       GET  /daily-ledger/api/v1/cashier/ledger/withdrawals/today
field save        POST /daily-ledger/api/v1/cashier/ledger/save
```

Writes target a synthetic date (`2027-01-15`) via admin sessions, so no real ledger
data is touched.

**Environment caveat:** single machine, Intel i3-2100 (**2 physical cores**), 4 threads,
16 GB RAM, Apache prefork (150 workers), PHP-FPM 8.3 (`pm.max_children=30`), MySQL 8.0.
The core count makes absolute throughput pessimistic; the *shape* (CPU-bound, latency
linear in concurrency) is the transferable part. Confirm on the real host before go-live.

---

## 3. Single-user (warm) cost — c=1

| endpoint | p50 | mean | payload |
|---|---|---|---|
| ledger page | 1081 ms | 1061 ms | **793 KB** |
| rows | 497 ms | 547 ms | **588 KB** |
| probe | 361 ms | 379 ms | **31 B** |
| adjustments | 348 ms | 348 ms | 48 B |
| field save | 353 ms | 382 ms | 69 B |

Note the shape: the probe returns **31 bytes** for ~379 ms of PHP worker time — the
cost is almost entirely the per-request kernel bootstrap, not the payload.

---

## 4. Throughput ceiling (this box)

```
PROBE                          PAGE                           SAVE
c=1   2.7 rps  p50    361ms     c=1   0.9 rps  p50  1081ms     c=1   2.7 rps
c=4   5.5 rps  p50    694ms     c=4   1.8 rps  p50  2255ms     c=4   5.8 rps
c=8   5.9 rps  p50   1285ms     c=8   1.7 rps  p50  4417ms     c=8   5.5 rps
c=16  5.7 rps  p50   2644ms     c=16  1.8 rps  p50  8086ms     c=16  5.5 rps
c=30  6.3 rps  p50   4019ms     c=30  1.8 rps  p50 15272ms     c=30  5.2 rps
```

All three flatten: **adding concurrency adds latency, not throughput**.
At c=30 the page's p50 is **15.3 s** — a 14× inflation. Error rate stayed 0%.

Mixed realistic profile at c=12: **5.2 req/s**, p50 2039 ms, p95 3611 ms, 0% errors,
11.3 worker-seconds consumed per wall second.

---

## 5. Finding 1 — Concurrent saves deadlock on a fresh day (FIXED)

### Reproduction
10 concurrent saves across **10 different branches** on a date with no existing rows:

```
distinct-branch: 10 reqs, 4.9 rps, p50 1904ms, err 40%
```

Server log:

```
[daily-ledger] apiSaveLedgerField failed: SQLSTATE[40001]: Serialization failure:
1213 Deadlock found when trying to get lock; try restarting transaction
```

### Root cause (InnoDB verdict)

```
index uq_dl_ledger_entry of table `baronledger`.`dl_daily_ledger`
lock_mode X insert intention waiting
PHYSICAL RECORD: n_fields 1; compact format; info bits 0
 0: len 8; hex 73757072656d756d; asc supremum;;
```

Both transactions held an X lock on the index **supremum** (the last gap) and each
waited for an insert-intention lock inside it.

`apiSaveLedgerField` ran this purely to capture the audit "before" value:

```sql
SELECT {$column} FROM dl_daily_ledger
 WHERE branch_id=? AND product_id=? AND ledger_date=? AND shift=? LIMIT 1 FOR UPDATE
```

Under REPEATABLE READ, a locking read of a **row that does not exist** takes a next-key
lock on the gap it would occupy. At the start of a business day every row is missing
**and the new date is the newest in the table**, so every concurrent save gap-locks the
same supremum and then requests an insert-intention lock inside it → deadlock.

This is why it looked random and why it is branch-independent: it is not about two
cashiers touching the same product, it is about many branches inserting the day's
first rows into the same index end-gap.

### Isolation experiment (confirms the mechanism)

| condition | result |
|---|---|
| both `dl_daily_ledger` and `dl_ledger_day_status` rows missing | **deadlock (40%, 10%, 0%, 0%)** |
| `dl_daily_ledger` missing, `dl_ledger_day_status` present | 0 deadlocks |
| `dl_daily_ledger` present, `dl_ledger_day_status` missing | 0 deadlocks |
| both present | 0 deadlocks |

### Fix

Dropped `FOR UPDATE` from that read in `apiSaveLedgerField` and its offline mirror
`dl_offlineApplyWithdrawal`. The read only feeds the audit "before" value; the
`INSERT ... ON DUPLICATE KEY UPDATE` takes the row lock it actually needs, so the
pre-lock was redundant as well as harmful.

### Verification

| | fresh-day runs | deadlocks |
|---|---|---|
| before fix | 6 | 2 runs affected (40%, 10%) |
| **after fix** | **13** | **0 (0% error rate)** |

Throughput also improved slightly (5.0–5.6 vs 4.4–5.4 req/s) with tighter p50
(1620–1843 ms vs 1662–1858 ms). Full daily-ledger regression: **1305 passed / 0 failed**.

### Residual risk
Three other shared lock points remain in the save transaction and were not observed to
deadlock in these tests, but should be watched in production:

- `dl_lockDayStatusRow` → `INSERT ... ON DUPLICATE KEY UPDATE` on `dl_ledger_day_status`
- `dl_recomputeVariancesForDay` → range `DELETE` + INSERT on `dl_variance_flags`
- `dl_daily_ledger` insert itself (only deadlocked in combination with the above)

Consider a bounded retry-on-1213 wrapper as defence in depth.

---

## 6. Finding 2 — The 30 s probe was the scale limiter (now 120 s)

**Implemented.** `CLOUD_PROBE_MS = 120000`, hidden tabs skip the probe entirely,
returning to the tab probes immediately, and a 15 s tick drains queued work early
(issuing no request at all when nothing is pending). The probe was confirmed to be a
pure connectivity/sync heartbeat — it never repaints `day_status` into the UI — so
slowing it down cannot make the cashier's view stale.

Every open ledger tab polls `day-status` **every 30 seconds**, for the whole shift,
regardless of whether anything changed.

Demand model for 23 branches × 2 shifts = 46 cashiers:

| component | req/s | share |
|---|---|---|
| day-status probes (2/min per open tab) | **1.53** | **59%** |
| field saves (closing-30-min burst, ~40 edits/cashier) | 1.02 | 40% |
| ledger page loads | 0.03 | 1% |
| **total peak** | **2.58** | |

That is ~49% of the measured 5.2 req/s mixed ceiling **on this box**, but the April
study projected Bluehost shared hosting at only **1.5–3 req/s** — so the fleet lands at
**86–172% of projected capacity**. A single endpoint returning 31 bytes is consuming
the majority of that budget.

### Options considered

- ✅ Raise the interval from 30 s to 120 s (peak → ~1.4 req/s total).
- ✅ Pause while the tab is hidden (`document.hidden`), probe on return to the tab, and
  drain queued work on a faster tick when something is actually pending.
- Still available if more headroom is ever needed: return the day status **with** the
  rows/adjustments responses and drop the standalone poll entirely.

---

## 7. Finding 3 — Every ledger view made a redundant 588 KB fetch (removed)

**Implemented.** The trigger is now the explicit `dl:rows-refresh` event, so the
server-rendered rows are used as-is. Verified: 0 row fetches on load, and the two
callers that need a refresh (AM carry-forward, PM hand-off) still fire it.

The tbody both server-renders the rows and immediately re-fetches them:

```disyl
<tbody id="ledger-body"
       hx-get="{base_url}/ledger/rows?date={ledger_date}&branch_id={branch_id}&shift={shift}"
       hx-trigger="load"
       hx-swap="innerHTML">
    {include "modules/daily-ledger/cashier/partials/ledger-rows.disyl"}
```

Measured: the page already contains **178** `beg_bal` inputs; the HTMX call re-fetches
**174** of them (588,120 bytes) to replace identical markup.

Cost per ledger view: one extra HTTP request, one extra query + DiSyL render, 588 KB of
transfer (~43% of the page). Removing `hx-trigger="load"` (the server already rendered
the rows) or dropping the initial `{include}` recovers it.

---

## 8. Finding 4 — Row markup was heavy (collapsed)

**Implemented.** The repeated utility strings became `.ledger-cell`, `.ledger-chip`,
`.ledger-chip-pending`, `.ledger-trigger-add`, `.ledger-trigger-out`, `.ledger-num`
and `.ledger-name` in the daily-ledger layout, following the pattern the layout
already used for `.btn`/`.form-input`. The two JS `span.className` rewrites in
`computeSales()` were updated too, or the long strings would have returned on the
first recompute.

Verified by comparing computed styles before/after: **0 mismatches** across display,
size, padding, margins, borders, radius, font, colours, gap and alignment. Pixel diff:
**0 differing pixels** in the header area and **0** in everything below the banner
(the entire ledger grid) — the only differences anywhere are the two live clock
strings. Payload: rows 588,120 → 422,434 B (−28.2%), page 793,647 → 631,340 B (−20.5%).

793 KB page / 588 KB rows for 174 products = **~3.4 KB per table row**. Each cell
repeats a ~200-character Tailwind class string. At 46 cashiers this is a real
bandwidth and render-CPU cost on shared hosting and on mobile data.
Moving the repeated classes into a stylesheet is the obvious lever.

---

## 9. Finding 5 — Login rate limit counted successful logins (fixed)

**Implemented** via `kernelResetLoginRateLimit()`, called from the daily-ledger login
handler only after credentials verify. Verified live: **30/30 consecutive correct
logins succeed** (previously the 6th was refused) and the counter is left clean; six
wrong passwords still throttle at attempt 6, so the brute-force bound is unchanged.

What still needs confirming is the **branch IP topology**. If all 23 branches really
do share one public address, then five mistyped passwords anywhere in the company
within five minutes locks out every branch for five minutes. In that case raise
`AUTH_LOGIN_RATE_LIMIT_MAX`; both it and `AUTH_LOGIN_RATE_LIMIT_WINDOW` are read from
the environment (`kernelLoginRateLimitMaxAttempts()` / `kernelLoginRateLimitWindowSeconds()`).

`AUTH_LOGIN_RATE_LIMIT_MAX` (default **5**) per `AUTH_LOGIN_RATE_LIMIT_WINDOW`
(default **300 s**), keyed on `$_SERVER['REMOTE_ADDR']`. The counter increments on
**every** call, including logins with correct credentials — 30 correct logins tripped
it after 5.

```
auth.login_rate_limited {"identifier":"t207:module:daily-ledger:ip:127.0.0.1",
  "max_attempts":5,"window_seconds":300,"retry_after":231}
```

**Before rollout, confirm how the 23 branches appear to the server.** If they share an
egress IP (corporate NAT, VPN, or the site sitting behind a CDN/proxy where
`REMOTE_ADDR` is the proxy), then only 5 logins per 5 minutes are permitted for the
entire company — most cashiers would be locked out at shift start. If each branch has
its own ISP address, 5/5 min per branch is fine for 2 cashiers and needs no change.

Also note the limit is consumed by typos: 5 mistyped passwords lock out **every** shift
at that branch for 5 minutes.

---

## 10. Reproduce

```bash
php tests/load/seed_load_users.php --db=baronledger
php tests/load/daily_ledger_load_test.php --reset-limiter   # dev only; see finding 5
php tests/load/daily_ledger_load_test.php --phase=ceiling
php tests/load/daily_ledger_load_test.php --phase=contention
php tests/load/daily_ledger_load_test.php --phase=mixed
php tests/load/daily_ledger_load_test.php --phase=all --clean-writes
php tests/load/seed_load_users.php --db=baronledger --clean
```

Deadlock re-check (fresh-day condition):

```bash
mysql ... -e "DELETE FROM dl_daily_ledger WHERE ledger_date='2027-01-15';
              DELETE FROM dl_ledger_day_status WHERE ledger_date='2027-01-15';"
php tests/load/daily_ledger_load_test.php --phase=contention
grep -c 'Deadlock found' storage/logs/app.log
```

Raw results: `test_results/daily-ledger-load.json`.

---

## 11. Status and remaining work

Findings 1–5 are fixed and verified on the dev tenant (see the table in §1). Remaining:

1. **Deploy to live** — tenant 203, plus `php ikabud tenant:migrate 203 daily-ledger`
   for migrations 055–060. Live is still on build `f4dadcb8`.
2. **Confirm the branch IP topology** and, if the branches share one egress address,
   raise `AUTH_LOGIN_RATE_LIMIT_MAX` accordingly (see §9).
3. **Re-measure on the production host** with the same harness before enabling all 23
   branches. If the margin is thin, ramp branches in waves rather than all at once.
4. **Optional:** a bounded retry on InnoDB 1213 as defence in depth for the remaining
   shared lock points (`dl_lockDayStatusRow`, `dl_recomputeVariancesForDay`). Not
   currently required — the reproduced deadlock is gone.
