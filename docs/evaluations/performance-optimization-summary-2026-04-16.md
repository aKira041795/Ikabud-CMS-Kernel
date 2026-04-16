# Performance Optimization Summary — April 16, 2026

## What Was Done

Two caching layers were added to the Ikabud Kernel Application OS platform to reduce page load times and increase throughput for public-facing pages.

### 1. Ecommerce Catalog Query Cache

**File:** `modules/ecommerce/helpers/29-cache.php`

A file-based, tag-invalidated query cache for the three hottest ecommerce read paths:

| Cached Function | What It Caches | Speedup (cache hit) |
|----------------|----------------|---------------------|
| `ecProductList()` | Product listing queries with filters, pagination, sorting | **70×** |
| `ecProductGet()` | Single product by ID with relations | **182×** |
| `ecProductGetBySlug()` | Single product by slug with relations | **187×** |

- **TTL:** 300 seconds (configurable via module admin settings)
- **Invalidation:** Automatic on any product create/update/delete, pricing change, inventory change, or review moderation
- **Storage:** Tenant-scoped file cache (`ec_t{tenantId}`) with gzip compression for entries >1 KB

### 2. Kernel Page-Level Output Cache

**File:** `src/helpers/page-cache.php`

A full-page output cache that intercepts public GET requests before the module handler runs:

- **TTL:** 60 seconds
- **Scope:** All public GET requests from unauthenticated visitors
- **Skip list:** API endpoints, admin panels, login/auth pages, cart, checkout, user-specific pages
- **ETag support:** Returns HTTP 304 Not Modified when the client already has the current version
- **Invalidation:** Tag-based — CMS content changes flush CMS pages, ecommerce mutations flush ecommerce pages
- **Integration:** Wired into `executeModuleHandler()` in `src/helpers/module-manager.php`

On a cache hit, the entire request lifecycle is short-circuited: no handler execution, no DB queries, no template rendering — just read a file and send HTML.

---

## Measured Results

All measurements taken on the dev server (Intel i3-2100, 16 GB RAM, HDD, Apache prefork, no OPcache) with 10 concurrent connections and 100 requests per profile.

### Storefront Pages (HTML)

| Metric | Before | After | Improvement |
|--------|--------|-------|-------------|
| Throughput | 2.7 req/s | 3.5 req/s | **+30%** |
| p50 latency | 3,465 ms | 2,751 ms | **−21%** |
| p95 latency | 6,332 ms | 4,304 ms | **−32%** |
| p99 latency | 9,137 ms | 4,710 ms | **−48%** |

### API Endpoints (JSON)

| Metric | Before | After | Improvement |
|--------|--------|-------|-------------|
| Throughput | 3.9 req/s | 4.4 req/s | **+13%** |
| p50 latency | 2,442 ms | 2,226 ms | **−9%** |
| p95 latency | 4,121 ms | 3,539 ms | **−14%** |

### Shopping Journey (Sequential User Session)

| Metric | Before | After | Improvement |
|--------|--------|-------|-------------|
| Throughput | 1.3 req/s | 1.8 req/s | **+38%** |
| p50 latency | 689 ms | 552 ms | **−20%** |
| p95 latency | 1,387 ms | 708 ms | **−49%** |
| p99 latency | 1,609 ms | 805 ms | **−50%** |

### Maximum Throughput (50 concurrent)

| Metric | Before | After | Improvement |
|--------|--------|-------|-------------|
| RPS | 3.9/s | 5.1/s | **+28%** |
| p50 latency | 11,033 ms | 8,015 ms | **−27%** |
| p99 latency | 12,933 ms | 9,872 ms | **−24%** |

### Single-User Latency (1 concurrent)

| Metric | Before | After | Improvement |
|--------|--------|-------|-------------|
| RPS | 2.0/s | 2.6/s | **+30%** |
| p50 latency | 475 ms | 375 ms | **−21%** |
| p99 latency | 777 ms | 525 ms | **−32%** |

---

## Why the Gains Are Modest on This Server

The dev server lacks **OPcache** — PHP recompiles ~100+ files from source on every request, consuming ~300–500 ms regardless of whether the page content is cached. This compilation overhead dwarfs the time saved by skipping handler/DB/template work.

On Bluehost (which has OPcache + NVMe SSD), the caching layers should deliver much larger gains because:

- OPcache eliminates the ~300–500 ms compilation overhead → per-request baseline drops to ~100–200 ms
- NVMe SSD makes file cache reads ~100× faster than HDD
- Page cache hits then reduce per-request time to ~5–20 ms (just file read + HTTP response)
- **Projected improvement with OPcache: 5–10× for cached pages** (from ~200 ms to ~20 ms)

---

## Files Changed

| File | Change |
|------|--------|
| `src/helpers/page-cache.php` | **New** — Page cache API (11 functions) |
| `modules/ecommerce/helpers/29-cache.php` | **New** — Ecommerce query cache API |
| `src/helpers/module-manager.php` | Modified — Wire page cache into `executeModuleHandler()` |
| `public/index.php` | Modified — `require_once` page cache helper |
| `modules/cms/helpers/60-cache.php` | Modified — `cmsCacheFlushAll()` also invalidates CMS page cache |
| `modules/cms/helpers/99-misc.php` | Modified — 5 EventBus listeners also invalidate CMS page cache |
| `modules/ecommerce/helpers/29-cache.php` | Modified — Invalidation hooks also flush ecommerce page cache |
| `modules/ecommerce/helpers/30-catalog.php` | Modified — Wrap product queries with cache layer |
| `modules/ecommerce/helpers/31-inventory.php` | Modified — Stock changes trigger cache invalidation + bug fix |
| `modules/ecommerce/helpers/75-reviews.php` | Modified — Review moderation triggers cache invalidation |
| `modules/ecommerce/module.json` | Modified — Add `cache_enabled` and `cache_ttl` settings |

## Tests Added

| Test | Assertions | Status |
|------|-----------|--------|
| `tests/page_cache_smoke_test.php` | 62 | All pass |
| `tests/ecommerce_cache_smoke_test.php` | 25 | All pass |
| `tests/ecommerce_cache_benchmark.php` | Micro-benchmarks | 70–187× speedup |

All existing regression tests continue to pass (manifest settings: 34, AJAX catalog: 10, store catalog filter: 10, product attributes: 11).

---

## Remaining Optimization Opportunities

| Priority | Optimization | Expected Impact |
|----------|-------------|----------------|
| 1 | Enable OPcache on production | 2–3× base throughput improvement |
| 2 | Enable Cloudflare CDN (free with Bluehost) | 30–50% reduction in server load for repeat visitors |
| 3 | Add HTTP `Cache-Control` headers on public pages | Eliminates repeat page loads from same browser |
| 4 | Optimize DiSyL template compilation caching | Reduces per-request PHP work |
| 5 | Add stock gate to `ecOrderCreate()` | Prevents wasted DB writes on out-of-stock orders |

---

*Generated: April 16, 2026*
