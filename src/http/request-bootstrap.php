<?php

declare(strict_types=1);

if (!function_exists('releaseSessionAfterRender')) {
    /**
     * Release PHP session write lock for safe GET/HEAD requests after render.
     * This allows concurrent subsequent requests to proceed instead of being blocked.
     * Mutating requests (POST/PUT/DELETE) keep the lock until exit/redirect.
     */
    function releaseSessionAfterRender(): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }

        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($method === 'GET' || $method === 'HEAD') {
            release_session_lock_if_active();
        }
    }
}