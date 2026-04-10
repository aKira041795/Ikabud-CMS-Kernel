<?php

declare(strict_types=1);

if (!function_exists('adminViewCacheTtl')) {
    function adminViewCacheTtl(): int
    {
        return max(0, (int)($_ENV['ADMIN_VIEW_CACHE_TTL'] ?? 20));
    }
}

if (!function_exists('adminViewCacheInstance')) {
    function adminViewCacheInstance(): string
    {
        $tenantId = app()->tenant()->current();

        return 'admin_view_t' . ($tenantId ?? 0);
    }
}

if (!function_exists('adminViewCacheScopedKey')) {
    function adminViewCacheScopedKey(string $key, ?array $user = null): string
    {
        $role = (string)($user['role'] ?? 'guest');
        $source = (string)($user['source'] ?? 'none');

        return $key . '|role:' . $role . '|source:' . $source;
    }
}

if (!function_exists('adminViewCacheGet')) {
    function adminViewCacheGet(string $key, ?array $user = null): ?array
    {
        if (adminViewCacheTtl() <= 0) {
            return null;
        }

        $scopedKey = adminViewCacheScopedKey($key, $user);
        $hit = app()->cache()->get(adminViewCacheInstance(), $scopedKey);
        if (!is_array($hit)) {
            return null;
        }

        $payload = $hit['payload'] ?? null;

        return is_array($payload) ? $payload : null;
    }
}

if (!function_exists('adminViewCacheSet')) {
    function adminViewCacheSet(string $key, array $payload, array $tags = [], ?array $user = null): void
    {
        $ttl = adminViewCacheTtl();
        if ($ttl <= 0) {
            return;
        }

        $scopedKey = adminViewCacheScopedKey($key, $user);
        app()->cache()->setWithTags(
            adminViewCacheInstance(),
            $scopedKey,
            ['payload' => $payload],
            $tags,
            $ttl
        );
    }
}

if (!function_exists('adminViewCacheInvalidate')) {
    function adminViewCacheInvalidate(array $tags): void
    {
        if (empty($tags)) {
            return;
        }

        app()->cache()->clearByTags(adminViewCacheInstance(), array_values(array_unique($tags)));
    }
}