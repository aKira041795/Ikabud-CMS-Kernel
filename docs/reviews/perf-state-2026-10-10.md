# Performance state — handoff (as of 2026-10-10)

Everything here is measured, not estimated. Vintage is marked per section because this host drifts.

## How to read these numbers (do not skip)

- **The host is noisy: ±10–30% between readings with no deploy, in BOTH directions.** A before/after
  pair 4 minutes apart showed dispatch 55.88 → 47.06 ms while the workload was byte-identical. That
  was noise, not a win. **Trust SHARES within one reading, never absolute ms across readings.**
- The live concurrency figures come from ONE client over WAN, so ~350–450 ms RTT and a fresh TLS
  handshake per request are inside every latency. Shape and error counts are the signal.
- Daily-ledger numbers are the local 2-core box, not Bluehost.

## 1. Live kernel request cost (kernelappos.ikabudkernel.com, 2026-10-09 20:24, PHP 8.5.11)

`dispatch = 47.06 ms`. The perf page's own 118 ms wall includes a 59.45 ms probe self-measurement,
so a normal request is the dispatch figure, not the wall figure.

| block | ms | share of dispatch |
|---|---:|---:|
| **module routes** | 24.76 | **52.6%** |
| – helpers load (347 files / 5,009.2 KB) | 9.35 | 19.9% |
| – discovery | 9.06 | 19.3% |
| – route merge | 3.17 | 6.7% |
| **boot** | 14.88 | **31.6%** |
| **route match** | 7.17 | **15.2%** |

Those three blocks are 99.3% of dispatch. Everything else is noise.

**Invariant per request:** 347 files / 5,009.2 KB included, 85 queries, 172 dirs / 71 files, 6 compiled
scripts, 71 modules.

**Healthy, not a problem:** DB 85 queries in 2.94 ms (slowest 0.3 ms); DiSyL render 2.68 ms; OPcache
724 / 16229 keys, 38 / 89.2 MB, **0 restarts**, 0.8 MB wasted.

**Manifest fingerprint fix is confirmed live:** fingerprint 0.553 ms and state revalidate 0.381 ms
against a 4.226 ms one-off tree walk.

## 2. Live capacity (2026-10-09, one client over WAN)

| concurrency | throughput | p50 | p95 | failures |
|---|---:|---:|---:|---:|
| 1 | 1.38 req/s | 603 ms | 1304 ms | 0 |
| 6 | 10.16 req/s | 458 ms | 1250 ms | 0 |
| 12 (`/login`, cache bypassed) | 11.56 req/s | 621 ms | 1956 ms | 0 |
| 12 (`/`, public) | 10.86 req/s | 780 ms | 1926 ms | 0 |

**152 requests, zero 5xx, zero 429, zero transport errors.** Knee is ~c=6; beyond it latency grows
rather than throughput (expected saturation). Same-session concurrency costs ~20% throughput
(c=6: 8.09 vs 10.16 req/s) — mild, because these paths call `releaseSessionAfterRender()`.
**daily-ledger does NOT call it** and serializes hard; check that first if an endpoint misbehaves.

## 3. Daily-ledger write contention (local, 2026-10-09)

| writers | spread | throughput | p50 | errors |
|---|---:|---:|---:|---:|
| 12 | all 12 branches | 4.7–5.5 req/s | 1872–2454 ms | **0%** |
| 12 | one branch | 3.0–3.5 req/s | 2600–3073 ms | **0%** |
| 10 | 10 branches | 5.0 req/s | 1721 ms | **0%** |
| 10 | one branch | 2.9 req/s | 2704 ms | **0%** |

Distinct branches do not contend; throughput is flat as branch count rises. One branch serializes at
~3 req/s (`dl_lockDayStatusRow` + `dl_recomputeVariancesForDay`) but never fails.

**Capacity model, 23 branches: peak demand 1.43 req/s** (saves 1.02 closing burst, probes 0.38, pages
0.03) = **36% utilization on the smallest 2-worker Bluehost tier**, 2.4% on a 30-worker box.

The historic deadlock is fixed and stays fixed: the old `FOR UPDATE` bug reproduced **40% failures at
10 concurrent same-branch saves**; 12-way now gives 0%.

## 4. Public site frontend (ikabudkernel.com, 2026-10-09)

| metric | value |
|---|---|
| FCP | 1,156 ms |
| LCP | 1,172 ms |
| TTFB | 842 ms (includes my WAN distance) |
| CLS | 0.0012 |
| long tasks | 0 ms |

**Where the bytes are: ~3.9 MB total, of which ~3.7 MB is six images.** Two CMS uploads alone are
1,465 KB and 1,044 KB on the wire (PNG — gzip recovers only ~11%). Everything else is ~40 KB on the
wire: theme `style.css` 77 KB → 19.9 KB gzipped, `cms-public.css` 24 KB → 7.1 KB, all JS ~17 KB.

So the page is structurally fast and heavy in content. The largest remaining lever is re-exporting
those images at display resolution (content, not code).

## 5. What shipped (all verified on the live host)

- `8a9c0ead` Tailwind runtime JIT → compiled stylesheet across 52 templates: **−82.7% on the wire**
  (407,279 raw / 123,340 gzip JS → 142,590 raw / **25,422 gzip** CSS), no eval, one less origin.
- `ea37435c` two PHP-emitted pages the sweep missed + content globs extended to PHP surfaces.
- `4031d810` a falsifiable guard (23/23).
- `c66f15bf` bounded lock-conflict retry for the save path (real 1213 reproduced across two forked
  processes; **does not currently fire** — 0 retry lines in production-shaped traffic).
- `14c128b8` daily-ledger suite triage: 27 → 25 failing, two real fixes, one real defect found.

Live `app.css` md5 `8d4e050106590738a0e7e884de7a7f63` — byte-identical to the local build.

## 6. Open items, in the order I would attack them

1. **`boot` is 31.6% of dispatch and has never been examined.** Largest unexamined block. Start here.
2. **347 files / 5 MB of module helpers load per request** (19.9%). `opcache.preload` is
   `PHP_INI_SYSTEM` and unavailable on Bluehost, so reducing includes is architectural.
3. **Asset caching — a real regression the Tailwind migration introduced, owner chose to leave.**
   `app.css` has **no `Cache-Control`, no `ETag`, and the host ignores `If-Modified-Since`** (future
   dates → 200), so every navigation re-downloads 25 KB of render-blocking CSS. The CDN it replaced
   served `max-age=31536000`. Host-wide: no static asset here has cache headers. Fixing it properly
   needs guarded `mod_expires`/`mod_headers` in `public/.htaccess` **plus** a `?v=<filemtime>` version
   (precedent: `cmsThemeAssetUrl()`), or a `max-age=3600` interim with no template edits.
4. **Shell-copy drift is a real product defect (HARPP decision 152).** `commissary.disyl` and
   `variances.disyl` are missing the layout's `feature_consignee` wrapper (0 occurrences in both).
   Failed `shell_drift_guard` 91/95. Needs a decision — `templates/**` was outside the triage scope.
5. **Elevation commands still unrun** (all local-only, none affect production):
   `sudo chmod -R g+rwX storage/cache`; `php ikabud migrate:control` (this one clears ~20 red suites);
   `sudo chown kajagogoo:www-data storage/logs/app.log && sudo chmod 664 …`.

## 7. Reproducing these

- Live kernel probe: `/superadmin/perf` (needs a superadmin session). "Scripts compiled this request"
  is the row that tells you warm vs cold.
- Page cache bypass is `?disyl_nocache=1` — `?nocache=1` does NOT bypass it.
- Load harness: `php tests/load/daily_ledger_load_test.php --phase=contention --write-concurrency=12`
  (default 10; the cap used to be hard-coded and silently tested only 10 of 12 branches).
- OPcache warm-up: `php scripts/warm-opcache.php --base=<url>` — **run it ON the server**; over WAN its
  verdict is meaningless (a run from a laptop reported "ALREADY WARM" on a ~1s WAN path).

## 8. One methodological warning for the next session

Six verification tools produced false results during this work — a coverage checker (four separate
lies), a source-window probe, and a warm-up verdict. Every one was caught only by testing the checker
against a value already known to be present. **Before believing any "missing" or "0 failures" output,
make the tool report something you know is there.**
