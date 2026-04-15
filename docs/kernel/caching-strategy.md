# Caching Strategy

**Subsystem:** `kernel/Cache.php`  
**Status:** Production  
**Last updated:** 2026-04-15

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

### LRU Eviction

When file cache exceeds `maxCacheSizeMB`:
1. Sort entries by last access time (oldest first)
2. Remove entries until total size is under limit
3. Runs automatically on `set()` or explicitly via `gc()`

## Per-Request Caches

Several kernel subsystems maintain per-request in-memory caches that bypass the Cache class:

| Subsystem | Cache | Cleared |
|-----------|-------|---------|
| `IntegrationBridge` | `$requestCache` (resolved integrations) | End of request |
| `EventTriggers` | `$triggerCache` (loaded triggers) | End of request |
| `CapabilityBus` | APCu state storage with file fallback | TTL-based |
| `ComponentLoader` | `$components` (loaded definitions) | `clear()` or end of request |

## Conventions

- Cache keys use colon-delimited namespaces: `module:entity:id`
- Tags use similar namespacing: `products`, `store:1`, `tenant:42`
- TTL defaults to 3600s (1 hour); set 0 for no expiration
- Use `remember()` for compute-on-miss patterns
- Call `invalidateTag()` on data mutations, not `clear()`
- Production: set `maxCacheSizeMB` based on available disk (50–200 MB typical)
- Monitor hit rates via `stats()` to tune TTLs
