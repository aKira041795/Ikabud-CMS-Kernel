# Daily Ledger page-load baseline — 2026-11-20

## Environment and method

- Local Apache host: `http://baronledger.test` (host mapped to `127.0.0.1`), tenant `baronledger` / tenant id 207.
- Authenticated principal: Daily Ledger admin `shiela_baina` using the module-owned JSON login form at `/daily-ledger/auth/login`; one resulting session was reused for every authenticated page.
- Measured on 2026-11-20 (local time, UTC+08:00). Each page received one discarded warm-up followed by five sequential measured requests, with no concurrency and no `disyl_nocache` parameter.
- Curl captured HTTP status, TTFB (`time_starttransfer`), total time (`time_total`), and downloaded bytes for every sample. Authenticated response titles were checked and no authenticated response contained the Daily Ledger login form.
- `storage/cache/dl-variance-refresh/` exists. It is mode `0755`, owned by `www-data:www-data`; the Apache workers run as `www-data`, so it is writable by the serving process (the measurement shell user itself is not the owner and cannot write there).
- Method deviation: an initial authenticated session was discarded before any page measurement because of a measurement-shell quoting error. A second session was then used consistently for all authenticated warm-ups and samples; no measured page used the discarded session.

Times below are milliseconds. The TTFB min–max is included in Notes so that median, minimum, and maximum are reported for both TTFB and total time.

| page | median TTFB | median total | min total | max total | HTTP | notes |
|---|---:|---:|---:|---:|---:|---|
| `/` | 5.180 ms | 5.283 ms | 3.910 ms | 7.650 ms | 200 | TTFB min–max 3.729–7.544 ms; real Daily Ledger sign-in page |
| `/login` | 83.111 ms | 83.232 ms | 73.264 ms | 99.964 ms | 200 | TTFB min–max 73.159–99.883 ms; real Daily Ledger sign-in page |
| `/cms/login` | — | — | — | — | 404 | **FAILED**; all five samples returned the real Not Found page, not a login page |
| `/daily-ledger/login` | 72.746 ms | 72.957 ms | 67.202 ms | 76.595 ms | 200 | TTFB min–max 67.089–76.413 ms; real Daily Ledger sign-in page |
| `/daily-ledger/ledger` | 377.821 ms | 395.809 ms | 330.756 ms | 454.163 ms | 200 | TTFB min–max 307.959–437.543 ms; authenticated Daily Ledger page |
| `/daily-ledger/admin/dashboard` | 3,621.189 ms | 3,623.159 ms | 3,137.338 ms | 4,548.235 ms | 200 | TTFB min–max 3,135.722–4,547.374 ms; slow-page mechanism below |
| `/daily-ledger/admin/overview` | 5,655.420 ms | 5,656.997 ms | 4,895.540 ms | 6,817.169 ms | 200 | TTFB min–max 4,892.732–6,813.723 ms; slow-page mechanism below |
| `/daily-ledger/admin/commissary` | 271.718 ms | 285.991 ms | 280.964 ms | 321.734 ms | 200 | TTFB min–max 268.109–308.997 ms; authenticated Commissary page |
| `/daily-ledger/admin/variances` | 353.612 ms | 362.164 ms | 344.470 ms | 416.931 ms | 200 | TTFB min–max 333.513–402.024 ms; cached/fingerprint-gated path |
| `/daily-ledger/admin/deliveries` | 59.828 ms | 60.923 ms | 58.133 ms | 65.209 ms | 200 | TTFB min–max 56.906–64.182 ms; authenticated Transfer Records page |
| `/daily-ledger/admin/products` | 129.983 ms | 134.196 ms | 131.718 ms | 155.697 ms | 200 | TTFB min–max 127.502–150.409 ms; authenticated Products page |
| `/daily-ledger/admin/reports/data-integrity` | 90.017 ms | 91.189 ms | 82.934 ms | 122.336 ms | 200 | TTFB min–max 82.127–120.423 ms; authenticated Encoding Exceptions page |

## FAILED

- `/cms/login`: HTTP 404 in the warm-up and all five measured samples. The response title was `Not Found — APPLICATION KERNEL OS`; it was neither a redirect nor the requested CMS login page. No successful timing is reported for it.

## What changed vs the only recorded prior measurement

The current `/daily-ledger/admin/variances` median total is **362.164 ms** (five samples, 344.470–416.931 ms). This confirms the recorded post-change result in practical terms: the fingerprint-gated cached path remains sub-second and is currently 169.836 ms (31.9%) faster than the recorded 532 ms result. It also remains far below the recorded pre-gate 8,840 ms / 5,531-query result. The current run did not instrument DB query count, so it does not establish a current query-count comparison.

There is no honest before-baseline for the other pages. Their results are a **current baseline for future comparison**, not measurements of improvement.

## Mechanisms named

- **Dashboard (3,623.159 ms median):** `handleAdminDashboard()` synchronously calls `dl_maybeAutoCloseBranches()` before rendering. That function loops over every accessible branch and calls `dl_maybeAutoCloseBranchDay()` per branch; even the already-closed path performs a per-branch yesterday-status lookup and a bounded stale-open-day lookup. This is explicit synchronous per-branch query fan-out, followed by the dashboard's sales, status, variance-count, activity, and offline-device queries.
- **Overview (5,656.997 ms median):** `handleAdminOverview()` synchronously performs multiple separate set-based aggregates over `dl_daily_ledger`: current branch signals/current sales, a grouped 14-day forecast, prior-period official total and completeness, and an all-history lifetime aggregate (`FORCE INDEX (idx_dl_branch_date)`). The named mechanism is repeated serial aggregate scans of the ledger, not template size or a claimed missing index.

## Query counts and attribution (measured after the timings above)

The timings above measure wall time only. These add the MySQL query count, taken as a global
`Questions` delta around each request: 3 samples after one discarded warm-up, same session.

| page | median ms | queries | verdict |
|---|---:|---:|---|
| `/daily-ledger/admin/dashboard` | 2,744 | 352 | **slow: query fan-out** |
| `/daily-ledger/admin/overview` | 4,421 | 41 | **slow: expensive queries, not many** |
| `/daily-ledger/admin/variances` | 342 | 43 | fine |
| `/daily-ledger/admin/commissary` | 290 | 69 | fine |
| `/daily-ledger/ledger` | 223 | 77 | fine |

`/cms/login` returns 404 on this tenant because CMS is not enabled for `baronledger` — a
tenant-configuration fact, not a broken route.

**Dashboard attribution — measured, not inferred.** Flipping only
`tenant_module_settings(setting_key='auto_close_enabled')` for tenant 207 and re-measuring the same page:

| auto-close | median ms | queries |
|---|---:|---:|
| enabled (as configured) | 2,744 | 352 |
| disabled | 436 | 40 |

So `dl_maybeAutoCloseBranches()` was **~2.3 s and ~312 queries of that page, on every view**, across 28
active branches (~11 queries each). Not one slow query: the same per-branch pass retried on every
request, because the common outcome is `deferred` (endings still owed), which leaves the branch
eligible again immediately. The setting was restored to enabled after the measurement.

**Fix applied.** A per-branch retry throttle (`dl_autoClosePassDue()` / `dl_autoClosePassMark()`): one
`stat()`, no query, 60 s between permitted passes for web requests, unthrottled for CLI (a scheduled
sweep is one deliberate run). No close decision changes — whatever the pass would have done, it still
does, just not forty times a minute. Re-measured after the change:

| page | before | after | queries after |
|---|---:|---:|---:|
| dashboard | 2,744 ms | **363 ms** | 40 |
| overview | 4,421 ms | 4,368 ms | 41 |
| variances | 342 ms | 364 ms | 42 |

The dashboard's post-fix query count (40) equals the auto-close-disabled count (40), which is the proof
the throttle removes exactly the fan-out and nothing else. The first load after each 60 s window still
pays the ~2.3 s pass — that work has to happen somewhere; it no longer happens on every view.

**Overview is NOT fixed and is now the slowest page.** 4.4 s from 41 queries is ~108 ms per query:
aggregate scans over `dl_daily_ledger`, not a fan-out. Reducing it means changing those queries
(indexing or pre-aggregation) — a separate change with its own risk.

## Caveats

These local-host timings include no real network latency and prove nothing about concurrent-user behavior. They cover one tenant and one authenticated principal only.
