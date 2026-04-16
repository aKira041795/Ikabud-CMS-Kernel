# Performance Optimization Summary — April 16, 2026

## What Was Done

Seven optimization layers were added to the Ikabud Kernel Application OS platform to reduce page load times, increase throughput, prevent cache stampedes, and enforce stock integrity.

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

### 5. OPcache

**Config:** `/etc/php/8.3/apache2/conf.d/99-disable-opcache.ini` (converted from disable to enable)

OPcache was installed but explicitly disabled on this server. Re-enabled:

- `opcache.memory_consumption=128` — 128 MB opcode cache
- `opcache.max_accelerated_files=10000` — covers the full app + vendor tree
- `opcache.revalidate_freq=2` — validates file timestamps every 2s (safe for local dev)

**Impact:** Eliminates ~300–500 ms of PHP compilation overhead per request. Single-user warm latency dropped from ~1.3s → ~330ms (4× speedup) before any page caching.

### 6. APCu In-Memory L1 Cache

**Package:** `php8.3-apcu` (installed system-wide, auto-detected by kernel)

The kernel `Cache` class already had APCu support gated behind `extension_loaded('apcu')`. Installing the extension activates it automatically:

- **Tenant host lookup** — `ikabud:tenant_host:{sha1(host)}` (30s TTL): eliminates control-DB query for every request
- **Page cache entries** — promoted from file to APCu on first read; subsequent requests skip file I/O
- **Capability and admin caches** — same auto-promotion pattern from `kernel/Cache.php`

### 7. Pre-Bootstrap Fast-Path Cache

**File:** `src/helpers/fast-path-cache.php`

A self-contained page cache reader that runs before `bootstrap.php` — before Composer autoloader, before the App singleton, before module-manager, before any DB connection.

On a cache **hit** the entire kernel stack is skipped. Response time: **~2ms** (APCu L1) or **~5–10ms** (file L2).

On a cache **miss** or any guard failure (authenticated user, non-GET method, APCu tenant miss), the file returns immediately and the full kernel handles the request.

Headers emitted on fast-path hits:
- `X-Page-Cache: fast-hit` — served from file (with APCu promotion)
- `X-Page-Cache: fast-304` — ETag matched, no body transmitted

### 8. Cache TTL Segmentation

**File:** `src/helpers/page-cache.php`

`pageCacheTtlForModule()` returns a per-module TTL:

| Module | TTL | Rationale |
|--------|-----|-----------|
| `cms` | 600s | Static pages change infrequently; invalidation handles explicit edits |
| `ecommerce` | 180s | Product pages change more often (pricing, stock); shorter safety net |
| *(default)* | 300s | All other modules |

---

## Measured Results (Cumulative — All Optimizations Active)

All measurements taken on the dev server (Intel i3-2100, 16 GB RAM, HDD, Apache prefork, **OPcache enabled**, **APCu enabled**, **fast-path cache active**) with 10 concurrent connections and 100 requests per profile.

### Storefront Pages (HTML)

| Metric | Baseline | Current | Improvement |
|--------|----------|---------|-------------|
| Throughput | 2.7 req/s | **17.3 req/s** | **+541%** |
| p50 latency | 3,465 ms | **44 ms** | **−99%** |
| p95 latency | 6,332 ms | **2,875 ms** | **−55%** |
| p99 latency | 9,137 ms | **3,175 ms** | **−65%** |

> Storefront p50 dropped to **44ms** due to the fast-path cache serving repeated page loads in ~2ms without booting the kernel. The bimodal distribution (2ms hits + ~3s cold misses) yields 44ms at p50 under 10-concurrent load.

### API Endpoints (JSON)

| Metric | Baseline | Current | Improvement |
|--------|----------|---------|-------------|
| Throughput | 3.9 req/s | **8.3 req/s** | **+113%** |
| p50 latency | 2,442 ms | **1,172 ms** | **−52%** |
| p95 latency | 4,121 ms | **1,859 ms** | **−55%** |
| p99 latency | 4,232 ms | **2,284 ms** | **−46%** |

> API endpoints are not page-cached — gains here come from OPcache eliminating PHP compilation cost and freed workers from fast-path cache reducing contention.

### Shopping Journey (Sequential User Session)

| Metric | Baseline | Current | Improvement |
|--------|----------|---------|-------------|
| Throughput | 1.3 req/s | **11.6 req/s** | **+792%** |
| p50 latency | 689 ms | **2 ms** | **−99.7%** |
| p95 latency | 1,387 ms | **428 ms** | **−69%** |
| p99 latency | 1,609 ms | **555 ms** | **−66%** |

> Shopping journey p50 is **2ms** — repeated product page views within the 180s ecommerce TTL hit the fast-path and never touch the kernel.

### Maximum Throughput (50 concurrent)

| Metric | Baseline | Current | Improvement |
|--------|----------|---------|-------------|
| RPS | 3.9/s | **7.8/s** | **+100%** |
| p50 latency | 11,033 ms | **3,793 ms** | **−66%** |
| p99 latency | 12,933 ms | **6,424 ms** | **−50%** |

### Single-User Latency (1 concurrent)

| Metric | Baseline | Current | Improvement |
|--------|----------|---------|-------------|
| RPS | 2.0/s | **4.0/s** | **+100%** |
| p50 latency | 475 ms | **238 ms** | **−50%** |
| p99 latency | 777 ms | **474 ms** | **−39%** |

---

## Why the Remaining Latency Exists

After all optimizations, cache **misses** still take ~330ms–1s depending on the module. The remaining bottleneck is Apache prefork worker availability under concurrent load — once all workers are busy serving cache misses, new requests queue. With nginx+PHP-FPM, this ceiling would be higher, but that's not available on Bluehost shared hosting.

On **Bluehost with OPcache + NVMe SSD**: cache hits projected at ~5–20ms (file I/O 10–30× faster than HDD), cache misses at ~100–200ms (OPcache already active). Effective throughput ceiling ~1.5–3 req/s due to worker limits on shared plans.

---

## Files Changed

| File | Change |
|------|--------|
| `src/helpers/page-cache.php` | **New** — Page cache API (12 functions, per-module TTL segmentation) |
| `src/helpers/fast-path-cache.php` | **New** — Pre-bootstrap fast-path cache (serves hits in ~2ms) |
| `modules/ecommerce/helpers/29-cache.php` | **New** — Ecommerce query cache API |
| `src/helpers/module-manager.php` | Modified — Wire page cache + stampede protection into `executeModuleHandler()` |
| `public/index.php` | Modified — `require_once` fast-path cache helper before `bootstrap.php` |
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
| `/etc/php/8.3/apache2/conf.d/99-disable-opcache.ini` | Modified — OPcache enabled (was explicitly disabled) |

## Tests Added / Updated

| Test | Assertions | Status |
|------|-----------|--------|
| `tests/page_cache_smoke_test.php` | **67** | All pass |
| `tests/ecommerce_cache_smoke_test.php` | 25 | All pass |
| `tests/ecommerce_cache_benchmark.php` | Micro-benchmarks | 70–187× speedup |
| `tests/stress_architecture_test.php` | 57 | All pass (stock gate verified in Scenario 1) |

All existing regression tests continue to pass (manifest settings: 34, AJAX catalog: 10, store catalog filter: 10, product attributes: 11).

---

## Remaining Optimization Opportunities

| Priority | Optimization | Expected Impact |
|----------|-------------|----------------|
| 1 | Enable Cloudflare CDN (free with Bluehost) | 30–50% reduction in server load for repeat visitors |
| 2 | Add HTTP `Cache-Control` headers on public pages | Eliminates repeat page loads from same browser |
| 3 | nginx + PHP-FPM (not available on Bluehost shared) | Higher concurrency ceiling — more workers without Apache prefork overhead |

---

*Generated: April 16, 2026*
*Updated: April 16, 2026 — Added stampede protection, stock gate, extends cache, updated measurements*
*Updated: April 16, 2026 — OPcache enabled, APCu installed, fast-path cache, TTL segmentation — cumulative +541% storefront throughput*
