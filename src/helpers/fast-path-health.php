<?php

declare(strict_types=1);

/**
 * Ultra-early fast-path health check.
 *
 * Serves GET /api/v1/health (and /api/v1/wms/health) BEFORE booting
 * bootstrap.php, the autoloader, module-manager, or opening a DB connection.
 *
 * On shared hosting this cuts health-check latency from ~480ms to ~1ms
 * and eliminates DB connection pressure from monitoring probes.
 *
 * This handler returns a minimal liveness JSON.  The full health payload
 * (module counts, tenant info, etc.) is still available via the full
 * bootstrap path when the client sends ?full=1.
 *
 * Must be required BEFORE require_once bootstrap.php in public/index.php.
 */

(function (): void {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        return;
    }

    $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $uri = rtrim($uri, '/');

    if ($uri !== '/api/v1/health' && $uri !== '/api/v1/wms/health') {
        return;
    }

    // Full health check requested — fall through to full bootstrap
    if (!empty($_GET['full'])) {
        return;
    }

    // Try APCu cache first (collapses bursts from monitoring probes)
    if (extension_loaded('apcu') && function_exists('apcu_enabled') && apcu_enabled()) {
        $cached = apcu_fetch('kernel:fast_health:payload', $hit);
        if ($hit && is_array($cached)) {
            http_response_code(200);
            header('Content-Type: application/json');
            header('Cache-Control: no-store');
            header('X-Health-Cache: HIT');
            header('X-Response-Time-Ms: 0');
            echo json_encode($cached, JSON_UNESCAPED_SLASHES);
            exit;
        }
    }

    // Minimal liveness — no kernel, no DB, no modules
    $payload = [
        'ok' => true,
        'status' => 'alive',
        'php_version' => PHP_VERSION,
        'time' => gmdate('c'),
    ];

    // Cache for 5 seconds to collapse bursts, 2 seconds preferred for freshness
    $ttl = max(2, (int)($_ENV['HEALTH_FAST_CACHE_TTL'] ?? 5));
    if (extension_loaded('apcu') && function_exists('apcu_enabled') && apcu_enabled()) {
        apcu_store('kernel:fast_health:payload', $payload, $ttl);
    }

    http_response_code(200);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    header('X-Health-Cache: MISS');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
})();
