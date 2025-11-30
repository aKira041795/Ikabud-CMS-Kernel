<?php
/**
 * Ikabud Kernel - Entry Point
 * 
 * Single entry point for all HTTP requests
 * 
 * @version 2.0.0
 */

declare(strict_types=1);

// ============================================================================
// EARLY CHECKS (before autoloading)
// ============================================================================

$host = $_SERVER['HTTP_HOST'] ?? '';
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';

// Maintenance check
if (file_exists(__DIR__ . '/../kernel/Middleware/MaintenanceMiddleware.php')) {
    require_once __DIR__ . '/../kernel/Middleware/MaintenanceMiddleware.php';
    \IkabudKernel\Core\Middleware\MaintenanceMiddleware::check($host, $requestUri);
}

// ============================================================================
// BOOTSTRAP
// ============================================================================

use IkabudKernel\Core\Http\Router;
use IkabudKernel\Core\Http\Middleware\CorsMiddleware;

// Bootstrap kernel and create app
$bootstrap = require __DIR__ . '/bootstrap.php';
$app = $bootstrap['app'];
$debugMode = $bootstrap['debugMode'];

// ============================================================================
// MIDDLEWARE
// ============================================================================

// CORS middleware
$app->add(new CorsMiddleware());

// API middleware (rate limiting, caching) - only for API routes
if (strpos($requestUri, '/api/') === 0) {
    // Rate limiting
    if (file_exists(__DIR__ . '/../api/middleware/RateLimitMiddleware.php')) {
        require_once __DIR__ . '/../api/middleware/RateLimitMiddleware.php';
        $app->add(new \IkabudKernel\Api\Middleware\RateLimitMiddleware(
            __DIR__ . '/../storage/rate-limits',
            true
        ));
    }
    
    // Response caching
    if (file_exists(__DIR__ . '/../api/middleware/ResponseCacheMiddleware.php')) {
        require_once __DIR__ . '/../api/middleware/ResponseCacheMiddleware.php';
        $app->add(new \IkabudKernel\Api\Middleware\ResponseCacheMiddleware(
            __DIR__ . '/../storage/api-cache',
            true
        ));
    }
}

// ============================================================================
// ROUTING
// ============================================================================

$router = new Router($app);
$router->register();

// Run application
$app->run();
