# Caching Strategy

**Subsystem:** `kernel/Cache.php`  
**Status:** Production  
**Last updated:** 2026-06-11

## Overview

The kernel Cache provides a multi-tier caching system with automatic promotion between tiers, tag-based invalidation, LRU eviction, transparent compression, and atomic writes. It serves as the primary application-level cache for template output, query results, and computed values.

## Architecture

```
Request → APCu (L1, shared memory) → File cache (L2, disk) → Miss → Recompute
```

### Tier 1: APCu (In-Memory)

- Shared across PHP-FPM workers on the same server
- Microsecond reads, limited by `apc.shm_size`
- Used for hot data: resolved capabilities, template fragments, per-request caches
- TTL-based expiration

### Tier 2: File Cache (Disk)

- Persistent across restarts
- Located in `storage/cache/` directory (configurable)
- Atomic writes via temp file + rename
- Entries >1 KB auto-compressed with `gzencode()`
- LRU eviction when total size exceeds `maxCacheSizeMB`

## Core Class

### Cache

`kernel/Cache.php`

```php
$cache = new Cache(
    cacheDir: '/path/to/storage/cache',
    maxCacheSizeMB: 50,
    logInvalidations: true
);
```

### Constructor

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `cacheDir` | `string` | — | File cache directory |
| `maxCacheSizeMB` | `int` | `50` | Max disk cache size before LRU eviction |
| `logInvalidations` | `bool` | `false` | Log cache invalidation events |

### Methods

| Method | Signature | Purpose |
|--------|-----------|---------|
| `get` | `(string $key): mixed` | Get from L1, fall back to L2, return null on miss |
| `set` | `(string $key, mixed $value, int $ttl = 3600, array $tags = []): void` | Store in both tiers |
| `has` | `(string $key): bool` | Check existence in either tier |
| `delete` | `(string $key): void` | Remove from both tiers |
| `clear` | `(): void` | Flush all cache entries |
| `invalidateTag` | `(string $tag): void` | Invalidate all entries with given tag |
| `invalidateTags` | `(array $tags): void` | Bulk tag invalidation |
| `remember` | `(string $key, callable $fn, int $ttl = 3600, array $tags = []): mixed` | Get or compute+store |
| `getMultiple` | `(array $keys): array` | Batch get |
| `setMultiple` | `(array $items, int $ttl = 3600): void` | Batch set |
| `increment` | `(string $key, int $step = 1): int` | Atomic increment |
| `decrement` | `(string $key, int $step = 1): int` | Atomic decrement |
| `stats` | `(): array` | Cache statistics (hits, misses, size, entry count) |
| `prune` | `(): int` | Remove expired entries, return count removed |
| `gc` | `(): void` | Run LRU eviction if over size limit |

### Tag-Based Invalidation

Tags allow grouped cache invalidation without knowing individual keys.

```php
// Store with tags
$cache->set('product:42', $data, 3600, ['products', 'store:1']);
$cache->set('product:43', $data, 3600, ['products', 'store:1']);
$cache->set('category:5', $data, 3600, ['categories', 'store:1']);

// Invalidate all products
$cache->invalidateTag('products');
// → product:42 and product:43 removed, category:5 retained

// Invalidate everything for store 1
$cache->invalidateTag('store:1');
// → all three removed
```

Tag mappings are stored alongside cache entries in both tiers.

### Compression

Entries larger than 1 KB are automatically compressed with `gzencode()` before writing to disk. Decompression is transparent on read. APCu tier stores uncompressed values for speed.

### Atomic Writes

File cache writes use a two-phase protocol:
1. Write to temporary file in same directory
2. `rename()` to final path (atomic on POSIX)

This prevents partial reads during concurrent access.

### Retention and eviction

Not LRU. There is no access-time tracking anywhere in `Cache`, and eviction is not per-write.

A rate-limited maintenance sweep runs on the write path at most once per hour
(`maintenanceIfDue()`, marker `storage/cache/.cache_maintenance`). The marker is written
**before** the work, and if it cannot be written the sweep is skipped rather than run on every
write. Each pass:

1. prunes files whose `mtime + instance TTL` is in the past;
2. then, if a budget is set and the surviving total exceeds it, evicts **oldest-mtime first**
   down to 90% of the budget - the 10% headroom stops the next pass from being immediately due.

`maxCacheSizeMB` defaults to 256 (`app.cache.max_size_mb`); `0` disables enforcement.

Entry points: `Cache::maintenanceIfDue()` (automatic, write path), `Cache::pruneExpired()`,
`Cache::previewExpired()`, and `php ikabud cache:prune`. `enforceCacheLimit()` was removed as
dead code, and there is no `gc()`.

Two properties worth knowing:

- **Eviction orders by mtime, not by each entry's declared expiry.** Read-time expiry is
  enforced separately from the entry's own `_cache_expires_at`, so a short-TTL entry is
  correctly not served after its TTL but may still occupy disk until the sweep's
  `mtime + TTL` rule retires it. Harmless while the tree sits far below budget (2.6 MB against
  256 MB, measured 2026-10-11); revisit if a deployment approaches the budget.
- **Per-write bounding was deliberately avoided.** It would cost a full directory walk plus a
  `filesize()` per entry on every `set()`; the sweep's hot-path cost is one `filemtime()` that
  returns immediately until the interval elapses.

## Invalidation: two file-backed authorities

Cross-worker invalidation cannot rely on APCu. APCu is per-worker (the php-fpm pool), and a CLI
purge cannot reach another worker's store at all - so a purge signalled only through APCu reaches
one worker and silently misses the rest. Both authorities below are therefore file-backed and
read on the request path.

| Authority | Covers | Location |
|---|---|---|
| Generation tokens | `Cache` instances, generation-bound memos, tenant routing and tenant-DB config | `storage/cache/generations/<namespace>/<key>.gen` |
| Page-cache markers | Full-page cache (kernel layer and the pre-bootstrap fast path) | `<instance>/.cache_version`, `<instance>/.flush` |

### Why they are not unified (closed decision, 2026-10-11)

The page-cache markers stay in the instance directory because the **pre-bootstrap fast path
computes those paths itself**, as string literals, before any helper can be loaded
(`src/helpers/fast-path-cache.php`). Moving that layout onto the generations tree would require
the literal path and the helper's path to stay in sync, and a mismatch there **silently skips the
version check** - a defect this layer has already had once. The duplication is the cost of not
re-introducing it.

Generations are compared by **equality of an opaque token**, never by ordering. A bump writes a
unique token via temp-file + `rename()`; a `+1` counter would be an unlocked read-modify-write and
can lose a bump, which - the signals being compared by equality - means a purge that does not
purge.

**Falsifier:** if the fast path ever gains a bootstrap phase able to load helpers before it checks,
the literal-path constraint disappears and the two authorities could merge.

### The durable event bus is not an invalidation transport (closed decision, 2026-10-11)

`EventBus::fireDurable()` provides producer-owned outbox + consumer inbox semantics, which is
structurally the same problem as cross-worker invalidation - and **no cache registers a listener
for it**. That is deliberate, not an omission. The generation authority already delivers
cross-worker invalidation, and routing it through the bus would couple the bus to caches and add a
durable outbox write per invalidation, for no gain the generation tokens do not provide.

**Falsifier:** invalidation ever needs to survive a crash between the state change and the file
write, or needs an auditable invalidation log. Neither is required today.

## Per-Request Caches

Several kernel subsystems maintain per-request in-memory caches that bypass the Cache class:

| Subsystem | Cache | Cleared |
|-----------|-------|---------|
| `IntegrationBridge` | `$requestCache` (resolved integrations) | End of request |
| `EventTriggers` | `$triggerCache` (loaded triggers) | End of request |
| `CapabilityBus` | APCu state storage with file fallback | TTL-based |
| `ComponentLoader` | `$components` (loaded definitions) | `clear()` or end of request |

## Operational bounds

Growth is bounded by construction, not by an operator cron:

| What | Bound | Mechanism |
|---|---|---|
| `*.cache` entries | 256 MB default | hourly rate-limited sweep, write path |
| Page locks | collected once older than 1 h; sweeps at most hourly | `pageCacheSweepStaleLocks()`, triggered from `pageCacheGet()` (every page-cache read, hit or miss) |
| Shared module-scan cache | one file per manifest fingerprint; superseded ones retired after 1 h | write-path stale cleanup |
| Generation tokens | one file per namespace/key | bounded by tenants x concerns, not by traffic |

Two of these were invisible until recently, and the reason is worth keeping. A bound that cannot
be observed gets misread as a fault: with the page-lock sweep reachable only from the cache-miss
path, a warm site never ran it, and the count sat unchanged at 239 across a deploy that contained
the sweep - which looks identical to a working sweep doing nothing. `php ikabud cache:stats` now
reports the page-lock count, the age of the oldest lock, and the age of the maintenance marker, so
a single reading distinguishes "not running" from "not due".

Bounds are also reported where they can be misread: the page-lock line judges on **count AND age
together**, because either alone gives the wrong answer - age alone fires on an idle site (no
page-cache reads, so no sweep, so the age grows while nothing accumulates), and count alone misses
a stalled sweep on a low-traffic site.

## Conventions

- Cache keys use colon-delimited namespaces: `module:entity:id`
- Tags use similar namespacing: `products`, `store:1`, `tenant:42`
- TTL defaults to 3600s (1 hour); set 0 for no expiration
- Use `remember()` for compute-on-miss patterns
- Call `invalidateTag()` on data mutations, not `clear()`
- Production: set `maxCacheSizeMB` based on available disk (50–200 MB typical)
- Monitor hit rates via `stats()` to tune TTLs
