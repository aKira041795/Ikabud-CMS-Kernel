# Ikabud CMS — Performance & Scalability

**Last updated:** June 2026

This document explains why Ikabud CMS is faster than comparable PHP CMS platforms, how it scales under multi-tenant load, and what architectural decisions drive the performance advantage.

---

## 1. Benchmark Results

Measured at `kernelappos.ikabudkernel.com` (PHP 8.3, production, OPcache + APCu + compiled DiSyL).

| Probe | Result |
|-------|--------|
| DB ping (SELECT 1) | 0.35 ms |
| Module discovery (cached) | 0.01 ms |
| Cache round-trip | 1.16 ms |
| DiSyL render (login page) | 5.29 ms |
| Total wall time | 6.95 ms |
| Peak memory | 20 MB |

### Per-endpoint response times

| Endpoint | Avg | Notes |
|----------|-----|-------|
| Homepage (page-cached) | ~2 ms | Fast-path cache — skips kernel bootstrap |
| Health check (fast) | ~4 ms | Ultra-early handler — PHP-only liveness probe |
| CMS Login page | ~5 ms | Full kernel + compiled DiSyL render |
| API /me (auth) | ~5 ms | JWT decode + capability check |

---

## 2. Comparison with Other PHP CMS Platforms

| Metric | Ikabud | WordPress | Drupal | Craft CMS | Laravel (Octane) |
|--------|--------|-----------|--------|-----------|------------------|
| Page-cached | **2 ms** | 10–50 ms | 20–80 ms | 10–40 ms | 1–3 ms |
| Uncached page | **5 ms** | 200–800 ms | 300–1000 ms | 80–300 ms | 20–50 ms |
| DB query | **0.35 ms** | 2–15 ms | 5–30 ms | 2–10 ms | 0.5–2 ms |
| Template render | **5 ms** | 50–200 ms | 80–400 ms | 20–80 ms | 5–15 ms |
| Health check | **4 ms** | 100–300 ms | 150–500 ms | 50–150 ms | 2–5 ms |
| Peak memory | **20 MB** | 40–80 MB | 60–120 MB | 30–60 MB | 15–30 MB |
| Bootstrap files | **~8** | ~40–80 | ~60–100 | ~30–50 | ~20–40 |

*Laravel Octane uses Swoole/RoadRunner for persistent processes — comparable speed but fundamentally different architecture (long-running process vs per-request bootstrap).*

---

## 3. Why Ikabud is Faster

### 3.1 DiSyL Compiles to Native PHP

Most CMS template engines interpret on every request:

```
WordPress:  template-hierarchy.php → 30+ file_exists() checks → locate_template() → load_template() → PHP include
Craft CMS:  .twig → Twig Lexer → Twig Parser → Twig Compiler → PHP → OPcache
Ikabud:     .disyl → DiSyL Compiler → PHP class → OPcache  (compile once, execute forever)
```

After first compile, DiSyL templates are native PHP classes in OPcache — zero parsing, zero interpretation. The interpreted fallback only runs when the compiler is unavailable.

### 3.2 Fast-Path Architecture

Three ultra-early handlers run **before** `bootstrap.php`:

| Handler | Route | Loads | Latency |
|---------|-------|-------|---------|
| `fast-path-cache.php` | `GET /` (cached pages) | Nothing — reads from disk | ~1 ms |
| `fast-path-health.php` | `GET /api/v1/health` | PHP runtime only | ~1 ms |
| Static asset handler | `GET /assets/modules/*/uploads/*` | Filesystem only | ~1 ms |

No other CMS does this at the PHP level — all bootstrap the full framework first, then check cache. Ikabud checks cache first, boots only on miss.

### 3.3 Module Isolation — Less Code Per Request

```
WordPress: load all active plugins (20–50) → init() → wp_head() (50+ callbacks)
Ikabud:    tenantProvisionModulePlan(entryModule) → load ~8 modules → route match → handler
```

A CMS tenant needs ~8 modules (CMS, media, search, ecommerce, users). The EHR module's code, WMS module's code, Guidance module's code — none of it loads for a CMS request. No hook/action/filter chain fires unless explicitly registered.

### 3.4 Connection Pooling

```
WordPress: new wpdb() per request OR persistent connection (mysql_pconnect issues)
Ikabud:    1 PDO per tenant, reused across requests, SELECT 1 health probe, LRU eviction
```

`DatabaseManager` pools up to 20 tenant connections with idle validation at 60-second intervals. Transient `max_user_connections` errors are retried with exponential backoff (50ms → 100ms → 200ms).

### 3.5 Single-Responsibility Bootstrap

```
WordPress: wp-load.php → wp-config.php → wp-settings.php → 30+ files → plugins → theme → init → wp()
Ikabud:    bootstrap.php → config merge → module-manager → route match → handler → exit
```

Ikabud's bootstrap loads what the request needs, nothing more. `discoverModules()` caches results in a static variable — called once per process, reused for every request in that FPM worker.

---

## 4. Scaling Under Multi-Tenant Load

### 4.1 Architecture Prevents Cross-Tenant Contention

```
WordPress multisite:   1 DB → wp_1_posts, wp_2_posts, wp_3_posts → table locks, slow joins
Ikabud multi-tenant:   1 DB per tenant → no cross-tenant queries, no shared table space
```

### 4.2 Scaling Projection

| Resource | 1 tenant | 100 tenants | 1,000 tenants | Mitigation |
|----------|----------|-------------|---------------|------------|
| Per-request latency | 5 ms | 5 ms | ~7 ms | Same bootstrap per request |
| DB connections | 1 | Up to 20 (pool) | Up to 20 (LRU) | `APP_TENANT_DB_POOL_MAX` |
| Module discovery | 0.01 ms | 0.01 ms | 0.01 ms | Static cached |
| DiSyL templates | Shared OPcache | Shared OPcache | Shared OPcache | Zero per-tenant overhead |
| Page cache | 2 ms | 2 ms | 2 ms | Fast-path bypasses all tenants |
| Storage/cache | 1 dir | 100 dirs | 1,000 dirs | Tag invalidation + LRU cap |

### 4.3 The One Scaling Bottleneck

Cold tenant DB resolution — `dbForTenant($id)` queries the control plane for credentials on first access per FPM worker. Mitigations:

- **Static cache**: `$tenantDbConnectionRowCache` — holds config rows in-memory per worker
- **APCu cache**: Config rows cached to APCu with configurable TTL
- **`kernelEscalationEnter/Leave`**: Safe control-plane access without module sandbox restrictions

At 1,000 tenants with high churn, add more FPM workers (each caches its own working set) and increase APCu `shm_size`.

---

## 5. Scaling vs Competitor Projection

```
                    WordPress    Laravel     Ikabud
1 tenant             50 ms       30 ms        5 ms
10 tenants           65 ms       38 ms        5 ms
100 tenants          80 ms       45 ms        5 ms
1,000 tenants       200 ms      100 ms        7 ms
```

The gap **widens** at scale because:

- **Ikabud**: Per-request cost is constant (same bootstrap, same module count). Only the connection pool warms up.
- **WordPress/Laravel**: Per-request cost grows with data volume in shared tables and active plugin/provider count.
- **Craft CMS**: Twig re-parses per site; warmup cost increases with site count.

---

## 6. Honest Trade-offs

| Strength | Trade-off |
|----------|-----------|
| 5ms uncached page render | New template language (DiSyL learning curve) |
| 2ms page-cached response | Smaller ecosystem (no plugin marketplace yet) |
| Compiled templates → OPcache | Must clear OPcache after template changes |
| Module isolation → less code | Requires disciplined `module.json` declarations |
| Fast-path handlers | Custom routes can't use fast-path without kernel changes |
| 20 MB peak memory | PHP 8.2+ required (WordPress runs on 7.4) |
| DB-per-tenant isolation | Cross-tenant analytics require control-plane queries |

---

## 7. When Ikabud is the Right Choice

- You're building a **multi-tenant SaaS** where performance at scale matters
- You need **500ms+ to <10ms** improvement without rewriting your stack
- You want **separate databases per client** without multi-instance overhead
- Your team is comfortable with a **disciplined module architecture**
- You need **CMS + commerce + operations** in one platform, not three

## 8. When Another CMS Might Be Better

- You need **50,000+ plugins** available today (WordPress ecosystem)
- Your team is **deeply invested in Twig/Blade** and can't switch
- You're building a **single-site brochure** — Ikabud's multi-tenant overhead isn't needed
- You need **WYSIWYG page building for non-technical users** (the governed DiSyL builder is developer-oriented)
