<?php
/**
 * Ikabud Kernel - Entry Point
 * 
 * This is the single entry point for all HTTP requests
 * Kernel boots first, then routes to appropriate handler
 */

declare(strict_types=1);

use IkabudKernel\Core\Kernel;
use Slim\Factory\AppFactory;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

// Load Composer autoloader
require __DIR__ . '/../vendor/autoload.php';

// Boot the kernel FIRST (before anything else)
try {
    Kernel::boot();
} catch (Exception $e) {
    http_response_code(500);
    die("Kernel boot failed: " . $e->getMessage());
}

// Create Slim application
$app = AppFactory::create();

// Add error middleware
$errorMiddleware = $app->addErrorMiddleware(
    $_ENV['APP_DEBUG'] === 'true',
    true,
    true
);

// CORS Middleware
$app->add(function (Request $request, $handler) {
    $response = $handler->handle($request);
    
    $origins = explode(',', $_ENV['CORS_ORIGIN'] ?? '*');
    $origin = $request->getHeaderLine('Origin');
    
    if (in_array($origin, $origins) || in_array('*', $origins)) {
        $response = $response
            ->withHeader('Access-Control-Allow-Origin', $origin)
            ->withHeader('Access-Control-Allow-Methods', $_ENV['CORS_METHODS'] ?? 'GET,POST,PUT,DELETE,OPTIONS')
            ->withHeader('Access-Control-Allow-Headers', $_ENV['CORS_HEADERS'] ?? 'Content-Type,Authorization')
            ->withHeader('Access-Control-Allow-Credentials', 'true');
    }
    
    return $response;
});

// Handle OPTIONS requests
$app->options('/{routes:.+}', function (Request $request, Response $response) {
    return $response;
});

// ============================================================================
// API ROUTES
// ============================================================================

// Health check
$app->get('/api/health', function (Request $request, Response $response) {
    $stats = Kernel::getStats();
    $response->getBody()->write(json_encode([
        'status' => 'ok',
        'kernel' => $stats,
        'timestamp' => time()
    ]));
    return $response->withHeader('Content-Type', 'application/json');
});

// Load API routes
require __DIR__ . '/../api/routes/kernel.php';
require __DIR__ . '/../api/routes/instances.php';
require __DIR__ . '/../api/routes/themes.php';
require __DIR__ . '/../api/routes/dsl.php';

// ============================================================================
// FRONTEND ROUTES (CMS Routing)
// ============================================================================

// Catch-all route for CMS routing
$app->any('/{path:.*}', function (Request $request, Response $response, array $args) {
    // This will be handled by the CMS router
    $path = '/' . ($args['path'] ?? '');
    
    // TODO: Route to appropriate CMS instance
    $response->getBody()->write("CMS Routing: {$path}");
    return $response;
});

// Run application
$app->run();
