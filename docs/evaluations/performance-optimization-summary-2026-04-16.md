# Performance Optimization Summary — April 16, 2026

## What Was Done

Four optimization layers were added to the Ikabud Kernel Application OS platform to reduce page load times, increase throughput, prevent cache stampedes, and enforce stock integrity.

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

- **TTL:** 300 seconds (event-driven invalidation handles freshness)
- **Scope:** All public GET requests from unauthenticated visitors
- **Skip list:** API endpoints, admin panels, login/auth pages, cart, checkout, user-specific pages
- **ETag support:** Returns HTTP 304 Not Modified when the client already has the current version
- **Stampede protection:** flock()-based lock coalescing — first request builds, concurrent requests wait up to 2s for cache to populate
- **Invalidation:** Tag-based — CMS content changes flush CMS pages, ecommerce mutations flush ecommerce pages
- **Integration:** Wired into `executeModuleHandler()` in `src/helpers/module-manager.php`

On a cache hit, the entire request lifecycle is short-circuited: no handler execution, no DB queries, no template rendering — just read a file and send HTML.

### 3. DiSyL Template Extends Resolution Cache

**File:** `kernel/DiSyL/TemplateEngine.php`

Cross-request file cache for template inheritance chain resolution:

- **What it caches:** The fully-resolved template after walking the `{extends}` chain (parent layouts + block merging)
- **Validation:** All files in the extends chain tracked by `filemtime()` — any change invalidates
- **Storage:** `storage/cache/disyl-extends/` with atomic writes
- **Impact:** Eliminates repeated `file_get_contents()` calls and regex processing for template inheritance on subsequent requests

### 4. Stock Gate Enforcement

**Files:** `modules/ecommerce/helpers/20-orders.php`, `modules/ecommerce/handlers/86-api-checkout.php`

`ecOrderCreate()` now enforces the stock gate:

- When `ecProductDecrementStock()` returns `false`, a `RuntimeException(409)` is thrown
- The entire DB transaction rolls back (order row, items, meta)
- Checkout handler returns user-friendly "out of stock" message with product name
- Verified: exactly N orders succeed for N stock, zero silent acceptance of out-of-stock orders

---

## Measured Results (Cumulative — All Optimizations Active)

All measurements taken on the dev server (Intel i3-2100, 16 GB RAM, HDD, Apache prefork, no OPcache) with 10 concurrent connections and 100 requests per profile.

### Storefront Pages (HTML)

| Metric | Baseline | Current | Improvement |
|--------|----------|---------|-------------|
| Throughput | 2.7 req/s | 3.9 req/s | **+44%** |
| p50 latency | 3,465 ms | 2,322 ms | **−33%** |
| p95 latency | 6,332 ms | 4,071 ms | **−36%** |
| p99 latency | 9,137 ms | 4,562 ms | **−50%** |

### API Endpoints (JSON)

| Metric | Baseline | Current | Improvement |
|--------|----------|---------|-------------|
| Throughput | 3.9 req/s | 4.8 req/s | **+23%** |
| p50 latency | 2,442 ms | 1,993 ms | **−18%** |
| p95 latency | 4,121 ms | 3,076 ms | **−25%** |

### Shopping Journey (Sequential User Session)

| Metric | Baseline | Current | Improvement |
|--------|----------|---------|-------------|
| Throughput | 1.3 req/s | 2.1 req/s | **+62%** |
| p50 latency | 689 ms | 479 ms | **−30%** |
| p95 latency | 1,387 ms | 543 ms | **−61%** |
| p99 latency | 1,609 ms | 640 ms | **−60%** |

### Maximum Throughput (50 concurrent)

| Metric | Baseline | Current | Improvement |
|--------|----------|---------|-------------|
| RPS | 3.9/s | 5.1/s | **+31%** |
| p50 latency | 11,033 ms | 7,488 ms | **−32%** |
| p99 latency | 12,933 ms | 9,880 ms | **−24%** |

### Single-User Latency (1 concurrent)

| Metric | Baseline | Current | Improvement |
|--------|----------|---------|-------------|
| RPS | 2.0/s | 2.7/s | **+35%** |
| p50 latency | 475 ms | 369 ms | **−22%** |
| p99 latency | 777 ms | 443 ms | **−43%** |

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
| `src/helpers/page-cache.php` | **New** — Page cache API (11 functions + stampede protection) |
| `modules/ecommerce/helpers/29-cache.php` | **New** — Ecommerce query cache API |
| `src/helpers/module-manager.php` | Modified — Wire page cache + stampede protection into `executeModuleHandler()` |
| `public/index.php` | Modified — `require_once` page cache helper |
| `modules/cms/helpers/60-cache.php` | Modified — `cmsCacheFlushAll()` also invalidates CMS page cache |
| `modules/cms/helpers/99-misc.php` | Modified — 5 EventBus listeners also invalidate CMS page cache |
| `modules/ecommerce/helpers/29-cache.php` | Modified — Invalidation hooks also flush ecommerce page cache |
| `modules/ecommerce/helpers/30-catalog.php` | Modified — Wrap product queries with cache layer |
| `modules/ecommerce/helpers/31-inventory.php` | Modified — Stock changes trigger cache invalidation + bug fix |
| `modules/ecommerce/helpers/75-reviews.php` | Modified — Review moderation triggers cache invalidation |
| `modules/ecommerce/helpers/20-orders.php` | Modified — Stock gate enforcement in `ecOrderCreate()` |
| `modules/ecommerce/handlers/86-api-checkout.php` | Modified — Catch 409 stock errors, return user-friendly message |
| `kernel/DiSyL/TemplateEngine.php` | Modified — Cross-request extends resolution cache |
| `modules/ecommerce/module.json` | Modified — Add `cache_enabled` and `cache_ttl` settings |

## Tests Added / Updated

| Test | Assertions | Status |
|------|-----------|--------|
| `tests/page_cache_smoke_test.php` | 62 | All pass |
| `tests/ecommerce_cache_smoke_test.php` | 25 | All pass |
| `tests/ecommerce_cache_benchmark.php` | Micro-benchmarks | 70–187× speedup |
| `tests/stress_architecture_test.php` | 57 | All pass (stock gate verified in Scenario 1) |

All existing regression tests continue to pass (manifest settings: 34, AJAX catalog: 10, store catalog filter: 10, product attributes: 11).

---

## Remaining Optimization Opportunities

| Priority | Optimization | Expected Impact |
|----------|-------------|----------------|
| 1 | Enable OPcache on production | 2–3× base throughput improvement |
| 2 | Enable Cloudflare CDN (free with Bluehost) | 30–50% reduction in server load for repeat visitors |
| 3 | Add HTTP `Cache-Control` headers on public pages | Eliminates repeat page loads from same browser |

---

*Generated: April 16, 2026*
*Updated: April 16, 2026 — Added stampede protection, stock gate, extends cache, updated measurements*
