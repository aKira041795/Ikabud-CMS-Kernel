<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Contracts;

/**
 * CacheContract
 *
 * Defines the Phase 3B interface for kernel and module-level cache adapters.
 * Ensures consistent serialization, invalidation, and TTL semantics across drivers.
 */
interface CacheContract
{
    /**
     * Retrieve an item from the cache.
     */
    public function get(string $key, mixed $default = null): mixed;

    /**
     * Store an item in the cache.
     */
    public function set(string $key, mixed $value, ?int $ttl = null): bool;

    /**
     * Delete an item from the cache.
     */
    public function delete(string $key): bool;

    /**
     * Clear all items from the cache.
     */
    public function clear(): bool;

    /**
     * Check if an item exists in the cache.
     */
    public function has(string $key): bool;
}
