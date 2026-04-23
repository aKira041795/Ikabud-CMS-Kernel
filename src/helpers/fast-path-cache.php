<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────
// Kernel — Fast-Path Page Cache (Pre-Bootstrap)
//
// Ultra-fast cache check that runs BEFORE the kernel boots.  On a cache
// hit the entire response is served from file (with optional APCu L1)
// without loading bootstrap.php, module-manager, event bus, DB, or any
// autoloaded classes.
//
// This file must be self-contained: no kernel functions, no App singleton,
// no Composer autoloader.  It uses only PHP builtins + APCu (optional).
//
// Designed to be required from the very top of public/index.php, before
// bootstrap.php.
// ─────────────────────────────────────────────────────────────────────────

(static function (): void {
    // ── 1. Only GET requests ──────────────────────────────────────────
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        return;
    }

    // ── 2. Parse URI ──────────────────────────────────────────────────
    $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $uri = rawurldecode($uri);
    $uri = rtrim($uri, '/');
    if ($uri === '') {
        $uri = '/';
    }

    // ── 3. Skip ineligible paths ──────────────────────────────────────
    static $skipPrefixes = [
        '/api/',
        '/admin/',
        '/login',
        '/logout',
        '/register',
        '/lock.php',
        '/superadmin',
        '/ecommerce/cart',
        '/ecommerce/checkout',
        '/ecommerce/my-orders',
        '/ecommerce/my-wishlist',
        '/ecommerce/recover-cart',
        '/ecommerce/compare',
        '/ecommerce/admin',
        '/ecommerce/store-admin',
        '/cms/login',
        '/cms/register',
        '/cms/admin',
        '/cms/auth',
        '/assets/',
    ];
    foreach ($skipPrefixes as $prefix) {
        if (str_starts_with($uri, $prefix)) {
            return;
        }
    }

    // ── 4. Skip if user is authenticated ──────────────────────────────
    // Check kernel auth cookie (derived from APP_URL or APP_COOKIE_NAME)
    // We replicate the kernel's cookie-name derivation logic without
    // loading bootstrap.php.
    $envFile = dirname(__DIR__, 2) . '/.env';
    $cookieName = '';
    $appUrl = '';
    if (is_file($envFile)) {
        // Fast scan: only read lines we need, no full .env parser
        $fh = @fopen($envFile, 'r');
        if ($fh !== false) {
            while (($line = fgets($fh)) !== false) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#') {
                    continue;
                }
                if (str_starts_with($line, 'APP_COOKIE_NAME=')) {
                    $cookieName = trim(substr($line, 16));
                    // Strip optional quotes
                    if (strlen($cookieName) >= 2) {
                        $f = $cookieName[0];
                        $l = $cookieName[strlen($cookieName) - 1];
                        if (($f === '"' && $l === '"') || ($f === "'" && $l === "'")) {
                            $cookieName = substr($cookieName, 1, -1);
                        }
                    }
                } elseif (str_starts_with($line, 'APP_URL=')) {
                    $appUrl = trim(substr($line, 8));
                    if (strlen($appUrl) >= 2) {
                        $f = $appUrl[0];
                        $l = $appUrl[strlen($appUrl) - 1];
                        if (($f === '"' && $l === '"') || ($f === "'" && $l === "'")) {
                            $appUrl = substr($appUrl, 1, -1);
                        }
                    }
                }
                // Once we have both, stop scanning
                if ($cookieName !== '' && $appUrl !== '') {
                    break;
                }
            }
            fclose($fh);
        }
    }

    if ($cookieName === '' && $appUrl !== '') {
        $cookieHost = strtolower((string)(parse_url($appUrl, PHP_URL_HOST) ?? ''));
        $cookieHost = preg_replace('/[^a-z0-9]+/', '_', $cookieHost) ?? '';
        $cookieHost = trim($cookieHost, '_');
        $cookieName = ($cookieHost !== '' ? $cookieHost : 'app') . '_token';
    }

    // Kernel auth cookie present → authenticated user → skip
    if ($cookieName !== '' && !empty($_COOKIE[$cookieName])) {
        return;
    }

    // Module auth cookies — any of these means authenticated
    static $moduleAuthCookies = ['cms_token', 'wms_token', 'daily_ledger_token', 'guidance_staff_token'];
    foreach ($moduleAuthCookies as $mc) {
        if (!empty($_COOKIE[$mc])) {
            return;
        }
    }

    // Skip AJAX requests expecting JSON
    $accept = (string)($_SERVER['HTTP_ACCEPT'] ?? '');
    if ($accept !== '' && str_contains($accept, 'application/json') && !str_contains($accept, 'text/html')) {
        return;
    }

    // ── 5. Resolve tenant ID ──────────────────────────────────────────
    // Use APCu to look up hostname → tenant record (populated by the
    // full kernel on non-fast-path requests).  If APCu is unavailable
    // or the host is unknown, fall through to the full kernel.
    $tenantId = null;
    $host = strtolower(preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? '')) ?? '');

    if ($host !== '' && function_exists('apcu_fetch') && apcu_enabled()) {
        $apcuKey = 'ikabud:tenant_host:' . sha1($host);
        $record = apcu_fetch($apcuKey, $success);
        if ($success && is_array($record) && isset($record['tenant_id'])) {
            $tenantId = (int)$record['tenant_id'];
        }
    }

    // No tenant ID from APCu → can't build cache path → fall through
    if ($tenantId === null) {
        return;
    }

    // ── 6. Build cache file path ──────────────────────────────────────
    $storagePath = dirname(__DIR__, 2) . '/storage';
    $instanceDir = $storagePath . '/cache/pagecache_t' . $tenantId;

    if (!is_dir($instanceDir)) {
        return;
    }

    // Replicate pageCacheKey() logic:
    //   origin = md5(rtrim(BASE_URL, '/'))  — but BASE_URL isn't defined yet.
    //   We derive it the same way external_base_url() does.
    $basePath = '';
    // Check for APP_BASE_PATH or path prefix from APP_URL
    if ($appUrl !== '') {
        $configuredPath = (string)(parse_url($appUrl, PHP_URL_PATH) ?? '');
        $configuredPath = rtrim($configuredPath, '/');
        if ($configuredPath !== '') {
            $basePath = $configuredPath;
        }
    }
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (int)($_SERVER['SERVER_PORT'] ?? 80) === 443
        || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
        ? 'https' : 'http';
    $baseUrl = $scheme . '://' . ($host ?: 'localhost') . $basePath;
    $origin = md5(rtrim($baseUrl, '/'));

    $qs = (string)($_SERVER['QUERY_STRING'] ?? '');
    $raw = $uri;
    if ($qs !== '') {
        parse_str($qs, $params);
        ksort($params);
        $raw .= '?' . http_build_query($params);
    }
    $cacheKey = 'page:' . $origin . ':' . md5($raw);
    $cacheFile = $instanceDir . '/' . md5($cacheKey) . '.cache';

    // ── 7. APCu L1: try in-memory first ──────────────────────────────
    $apcuCacheKey = 'cache_pagecache_t' . $tenantId . '_' . md5($cacheKey);
    $entry = null;

    if (function_exists('apcu_fetch')) {
        $cached = apcu_fetch($apcuCacheKey, $apcuHit);
        if ($apcuHit && is_array($cached)) {
            // Check expiry
            if (isset($cached['_cache_expires_at']) && time() < (int)$cached['_cache_expires_at']) {
                $entry = $cached;
            }
        }
    }

    // ── 8. File L2: read cache file directly ─────────────────────────
    if ($entry === null) {
        if (!is_file($cacheFile)) {
            return;
        }

        $raw = @file_get_contents($cacheFile);
        if ($raw === false || $raw === '') {
            return;
        }

        // Decompress if gzipped
        if (str_starts_with($raw, 'GZ:')) {
            $raw = @gzuncompress(substr($raw, 3));
            if ($raw === false) {
                return;
            }
        }

        $entry = @unserialize($raw);
        if (!is_array($entry)) {
            return;
        }

        // Check expiry
        if (!isset($entry['_cache_expires_at']) || time() >= (int)$entry['_cache_expires_at']) {
            return;
        }

        // Promote to APCu L1 for next request
        if (function_exists('apcu_store')) {
            $remainingTtl = (int)$entry['_cache_expires_at'] - time();
            if ($remainingTtl > 0) {
                apcu_store($apcuCacheKey, $entry, $remainingTtl);
            }
        }
    }

    // ── 9. Validate entry ─────────────────────────────────────────────
    if (!isset($entry['html']) || !is_string($entry['html']) || strlen($entry['html']) < 100) {
        return;
    }

    // ── 10. ETag conditional ──────────────────────────────────────────
    $etag = '"' . ($entry['etag'] ?? md5($entry['html'])) . '"';
    $clientEtag = trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));

    if ($clientEtag === $etag) {
        http_response_code(304);
        header('ETag: ' . $etag);
        header('Cache-Control: public, no-cache');
        header('X-Page-Cache: fast-304');
        exit;
    }

    // ── 11. Serve ─────────────────────────────────────────────────────
    http_response_code((int)($entry['status'] ?? 200));
    header('Content-Type: text/html; charset=UTF-8');
    header('ETag: ' . $etag);
    header('Cache-Control: public, no-cache');
    header('X-Page-Cache: fast-hit');
    echo $entry['html'];
    exit;
})();
