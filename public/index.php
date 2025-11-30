<?php
/**
 * Ikabud Kernel - Entry Point
 * 
 * Single entry point for all HTTP requests
 * Kernel boots first, then routes to appropriate handler
 * 
 * @version 2.0.0 - Refactored for separation of concerns
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

use IkabudKernel\Core\Kernel;
use IkabudKernel\Core\Config;
use IkabudKernel\Core\Http\Router;
use IkabudKernel\Core\Http\CorsMiddleware;
use Slim\Factory\AppFactory;

// Composer autoloader
require __DIR__ . '/../vendor/autoload.php';

// Load configuration
$config = Config::getInstance();
$debugMode = ($config->get('APP_DEBUG') === 'true');

// Boot kernel
try {
    Kernel::boot();
} catch (Exception $e) {
    http_response_code(500);
    if ($debugMode) {
        die("Kernel boot failed: " . $e->getMessage());
    }
    die("Service temporarily unavailable");
}

// ============================================================================
// APPLICATION SETUP
// ============================================================================

$app = AppFactory::create();

// Error middleware
$app->addErrorMiddleware($debugMode, true, true);

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
