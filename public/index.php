<?php
/**
 * Ikabud Kernel - Entry Point
 * 
 * This is the single entry point for all HTTP requests
 * Kernel boots first, then routes to appropriate handler
 */

declare(strict_types=1);

// EARLY CHECKS (before any autoloading)
// This must run FIRST to catch requests before Slim routing
$host = $_SERVER['HTTP_HOST'] ?? '';
$subdomain = explode('.', $host)[0];
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';

// Map subdomain to instance
$instanceMap = ['wp-test' => 'wp-test-001'];
$instanceId = $instanceMap[$subdomain] ?? null;

if ($instanceId) {
    $instanceDir = __DIR__ . '/../instances/' . $instanceId;
    
    // Check for maintenance mode
    $maintenanceFile = $instanceDir . '/.maintenance';
    if (file_exists($maintenanceFile)) {
        http_response_code(503);
        header('Content-Type: text/html; charset=utf-8');
        header('Retry-After: 300');
        readfile(__DIR__ . '/../templates/maintenance.html');
        exit;
    }
    
    // Serve CMS admin files directly (wp-admin, wp-login, wp-content, wp-includes)
    // This avoids cluttering /public with symlinks
    if (preg_match('#^/(wp-admin|wp-login\.php|wp-content|wp-includes)(/|$)#', $requestUri, $matches)) {
        $relativePath = ltrim($requestUri, '/');
        $filePath = $instanceDir . '/' . $relativePath;
        
        // Security: Prevent directory traversal
        $realPath = realpath($filePath);
        $realInstanceDir = realpath($instanceDir);
        
        if ($realPath && strpos($realPath, $realInstanceDir) === 0) {
            if (is_file($realPath)) {
                // Serve the file with correct MIME type
                $ext = pathinfo($realPath, PATHINFO_EXTENSION);
                $mimeTypes = [
                    'css' => 'text/css',
                    'js' => 'application/javascript',
                    'png' => 'image/png',
                    'jpg' => 'image/jpeg',
                    'jpeg' => 'image/jpeg',
                    'gif' => 'image/gif',
                    'svg' => 'image/svg+xml',
                    'woff' => 'font/woff',
                    'woff2' => 'font/woff2',
                    'ttf' => 'font/ttf',
                    'php' => 'text/html'
                ];
                
                $mimeType = $mimeTypes[$ext] ?? 'application/octet-stream';
                
                if ($ext === 'php') {
                    // Execute PHP files (wp-login.php, wp-admin/*.php)
                    chdir($instanceDir);
                    $_SERVER['SCRIPT_FILENAME'] = $realPath;
                    $_SERVER['SCRIPT_NAME'] = '/' . $relativePath;
                    require $realPath;
                    exit;
                } else {
                    // Serve static files
                    header('Content-Type: ' . $mimeType);
                    readfile($realPath);
                    exit;
                }
            } elseif (is_dir($realPath)) {
                // Redirect directories to index.php
                $indexPath = $realPath . '/index.php';
                if (file_exists($indexPath)) {
                    chdir($instanceDir);
                    $_SERVER['SCRIPT_FILENAME'] = $indexPath;
                    $_SERVER['SCRIPT_NAME'] = '/' . $relativePath . '/index.php';
                    require $indexPath;
                    exit;
                }
            }
        }
    }
}

use IkabudKernel\Core\Kernel;
use Slim\Factory\AppFactory;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

// Load Composer autoloader
require __DIR__ . '/../vendor/autoload.php';

// Initialize Config (loads .env file)
use IkabudKernel\Core\Config;
Config::getInstance();

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
require __DIR__ . '/../api/routes/auth.php';
require __DIR__ . '/../api/routes/kernel.php';
require __DIR__ . '/../api/routes/instances.php';
require __DIR__ . '/../api/routes/instances-actions.php';
require __DIR__ . '/../api/routes/themes.php';
require __DIR__ . '/../api/routes/dsl.php';
require __DIR__ . '/../api/routes/conditional-loading.php';

// ============================================================================
// ADMIN ROUTES (React SPA)
// ============================================================================

// Serve admin React app for all /admin/* routes and /login
$app->get('/admin[/{path:.*}]', function (Request $request, Response $response) {
    $indexPath = __DIR__ . '/admin/index.html';
    
    if (!file_exists($indexPath)) {
        $response->getBody()->write('Admin interface not found. Run: cd admin && npm run build');
        return $response->withStatus(404);
    }
    
    $html = file_get_contents($indexPath);
    $response->getBody()->write($html);
    return $response->withHeader('Content-Type', 'text/html');
});

$app->get('/login', function (Request $request, Response $response) {
    $indexPath = __DIR__ . '/admin/index.html';
    
    if (!file_exists($indexPath)) {
        $response->getBody()->write('Admin interface not found. Run: cd admin && npm run build');
        return $response->withStatus(404);
    }
    
    $html = file_get_contents($indexPath);
    $response->getBody()->write($html);
    return $response->withHeader('Content-Type', 'text/html');
});

// ============================================================================
// FRONTEND ROUTES (CMS Routing)
// ============================================================================

// Catch-all route for CMS routing
$app->any('/{path:.*}', function (Request $request, Response $response, array $args) {
    // Get instance from subdomain
    $host = $request->getUri()->getHost();
    $parts = explode('.', $host);
    $subdomain = $parts[0];
    
    // Map subdomain to instance
    $instanceMap = [
        'wp-test' => 'wp-test-001'
    ];
    
    $instanceId = $instanceMap[$subdomain] ?? null;
    
    if (!$instanceId) {
        $response->getBody()->write("Instance not found for subdomain: {$subdomain}");
        return $response->withStatus(404);
    }
    
    // Check instance status
    $kernel = \IkabudKernel\Core\Kernel::getInstance();
    $db = $kernel->getDatabase();
    
    $stmt = $db->prepare("SELECT * FROM instances WHERE instance_id = ? LIMIT 1");
    $stmt->execute([$instanceId]);
    $instance = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Check for maintenance mode
    $instanceDir = __DIR__ . '/../instances/' . $instanceId;
    $maintenanceFile = $instanceDir . '/.maintenance';
    
    if (file_exists($maintenanceFile)) {
        // Show maintenance page
        $maintenanceHtml = file_get_contents(__DIR__ . '/../templates/maintenance.html');
        $response->getBody()->write($maintenanceHtml);
        return $response->withStatus(503)->withHeader('Content-Type', 'text/html');
    }
    
    if (!$instance || $instance['status'] !== 'active') {
        $response->getBody()->write("Instance is not active: {$instanceId}");
        return $response->withStatus(503);
    }
    
    // Instance is active - proceed with caching layer
    $requestUri = $request->getUri()->getPath();
    if ($query = $request->getUri()->getQuery()) {
        $requestUri .= '?' . $query;
    }
    
    // Initialize cache
    $cache = new \IkabudKernel\Core\Cache();
    
    // Check if this request should be cached
    if ($cache->shouldCache($requestUri)) {
        // Try to serve from cache
        if ($cached = $cache->get($instanceId, $requestUri)) {
            // Cache HIT - serve without loading WordPress ⚡
            foreach ($cached['headers'] as $header) {
                if (preg_match('/^([^:]+):\s*(.*)$/', $header, $matches)) {
                    $response = $response->withHeader($matches[1], $matches[2]);
                }
            }
            $response->getBody()->write($cached['body']);
            return $response;
        }
    }
    
    // Cache MISS or uncacheable - load CMS instance
    chdir($instanceDir);
    $_SERVER['DOCUMENT_ROOT'] = $instanceDir;
    $_SERVER['IKABUD_INSTANCE_ID'] = $instanceId;
    
    $requestPath = parse_url($requestUri, PHP_URL_PATH);
    $requestedFile = $instanceDir . $requestPath;
    
    // Detect CMS type for this instance
    $cmsType = \IkabudKernel\Core\ConditionalLoaderFactory::detectCMSType($instanceDir);
    if (!$cmsType) {
        $cmsType = $instance['cms_type'] ?? 'wordpress'; // Fallback to instance config
    }
    
    // Initialize CMS-specific conditional loader
    $conditionalLoader = \IkabudKernel\Core\ConditionalLoaderFactory::create($instanceDir, $cmsType);
    
    // Determine which extensions to load based on request (if conditional loading enabled)
    $extensionsToLoad = [];
    if ($conditionalLoader && $conditionalLoader->isEnabled()) {
        $context = [
            'request_uri' => $requestUri,
            'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
            'cms_type' => $cmsType
        ];
        $extensionsToLoad = $conditionalLoader->determineExtensions($requestUri, $context);
    }
    
    // Start output buffering if we should cache
    $shouldCacheResponse = $cache->shouldCache($requestUri);
    if ($shouldCacheResponse) {
        ob_start();
    }
    
    // Serve file if it exists
    if (is_file($requestedFile)) {
        $ext = strtolower(pathinfo($requestedFile, PATHINFO_EXTENSION));
        
        if ($ext === 'php') {
            // PHP files need CMS loaded first
            if (!defined('ABSPATH') && !defined('_JEXEC')) {
                // Set flag for conditional loading
                if ($conditionalLoader) {
                    define('IKABUD_CONDITIONAL_LOADING', $conditionalLoader->isEnabled());
                }
                
                // Load CMS core based on type
                if ($cmsType === 'wordpress') {
                    require_once $instanceDir . '/wp-load.php';
                } elseif ($cmsType === 'joomla') {
                    define('_JEXEC', 1);
                    require_once $instanceDir . '/includes/defines.php';
                    require_once $instanceDir . '/includes/framework.php';
                }
                
                // Load determined extensions after CMS core
                if ($conditionalLoader && !empty($extensionsToLoad)) {
                    $conditionalLoader->loadExtensions($extensionsToLoad);
                }
            }
            $_SERVER['SCRIPT_FILENAME'] = $requestedFile;
            require $requestedFile;
        } else {
            // Static file - use proper MIME type map
            $mimeTypes = [
                'css' => 'text/css',
                'js' => 'application/javascript',
                'json' => 'application/json',
                'png' => 'image/png',
                'jpg' => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'gif' => 'image/gif',
                'svg' => 'image/svg+xml',
                'woff' => 'font/woff',
                'woff2' => 'font/woff2',
                'ttf' => 'font/ttf',
                'eot' => 'application/vnd.ms-fontobject',
                'ico' => 'image/x-icon',
                'webp' => 'image/webp',
                'xml' => 'application/xml',
                'txt' => 'text/plain'
            ];
            
            $mimeType = $mimeTypes[$ext] ?? 'application/octet-stream';
            
            header('Content-Type: ' . $mimeType);
            readfile($requestedFile);
        }
    } elseif (is_dir($requestedFile) && is_file($requestedFile . '/index.php')) {
        // Load CMS first
        if (!defined('ABSPATH') && !defined('_JEXEC')) {
            if ($conditionalLoader) {
                define('IKABUD_CONDITIONAL_LOADING', $conditionalLoader->isEnabled());
            }
            
            // Load CMS core
            if ($cmsType === 'wordpress') {
                require_once $instanceDir . '/wp-load.php';
            } elseif ($cmsType === 'joomla') {
                define('_JEXEC', 1);
                require_once $instanceDir . '/includes/defines.php';
                require_once $instanceDir . '/includes/framework.php';
            }
            
            // Load determined extensions after CMS core
            if ($conditionalLoader && !empty($extensionsToLoad)) {
                $conditionalLoader->loadExtensions($extensionsToLoad);
            }
        }
        $_SERVER['SCRIPT_FILENAME'] = $requestedFile . '/index.php';
        require $requestedFile . '/index.php';
    } else {
        // Use CMS index.php for pretty URLs
        // Set flag before CMS loads itself
        if (!defined('IKABUD_CONDITIONAL_LOADING') && $conditionalLoader) {
            define('IKABUD_CONDITIONAL_LOADING', $conditionalLoader->isEnabled());
        }
        
        // Store extensions to load in global for CMS hook
        if ($conditionalLoader && $conditionalLoader->isEnabled() && !empty($extensionsToLoad)) {
            $GLOBALS['ikabud_extensions_to_load'] = $extensionsToLoad;
            $GLOBALS['ikabud_conditional_loader'] = $conditionalLoader;
        }
        
        $_SERVER['SCRIPT_FILENAME'] = $instanceDir . '/index.php';
        require $instanceDir . '/index.php';
    }
    
    // Capture and cache if needed
    if ($shouldCacheResponse) {
        $body = ob_get_contents();
        ob_end_clean();
        
        // Only cache if there were no errors (check for PHP warnings/errors in output)
        if (!preg_match('/<b>(Warning|Error|Notice|Fatal error)<\/b>/', $body)) {
            // Store in cache with plugin metadata
            $headers = headers_list();
            $cacheData = [
                'headers' => $headers,
                'body' => $body,
                'timestamp' => time()
            ];
            
            // Add extension loading stats if available
            if (isset($conditionalLoader)) {
                $cacheData['extensions_loaded'] = $conditionalLoader->getLoadedExtensions();
                $cacheData['extension_count'] = count($conditionalLoader->getLoadedExtensions());
                $cacheData['cms_type'] = $conditionalLoader->getCMSType();
            }
            
            $cache->set($instanceId, $requestUri, $cacheData);
        }
        
        // Output the captured content
        echo $body;
    }
    
    exit;
});

// Run application
$app->run();
