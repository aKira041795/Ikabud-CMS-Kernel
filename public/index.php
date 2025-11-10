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
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';

// Skip early checks for admin/API routes
if (strpos($requestUri, '/admin') !== 0 && 
    strpos($requestUri, '/api/') !== 0 && 
    strpos($requestUri, '/login') !== 0) {
    
    // Dynamic domain lookup from database
    $instanceId = null;
    $envFile = __DIR__ . '/../.env';
    if (file_exists($envFile)) {
        $envVars = [];
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos(trim($line), '#') === 0) continue;
            if (strpos($line, '=') !== false) {
                list($key, $value) = explode('=', $line, 2);
                $envVars[trim($key)] = trim($value);
            }
        }
        
        $dbHost = $envVars['DB_HOST'] ?? 'localhost';
        $dbName = $envVars['DB_DATABASE'] ?? 'ikabud-kernel';
        $dbUser = $envVars['DB_USERNAME'] ?? 'root';
        $dbPass = $envVars['DB_PASSWORD'] ?? '';
        
        try {
            $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName", $dbUser, $dbPass);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $stmt = $pdo->prepare("SELECT instance_id FROM instances WHERE domain = ? LIMIT 1");
            $stmt->execute([$host]);
            $instance = $stmt->fetch(PDO::FETCH_ASSOC);
            $instanceId = $instance['instance_id'] ?? null;
        } catch (PDOException $e) {
            error_log("Database connection failed: " . $e->getMessage());
        }
    }

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

// CORS Middleware - Allow cross-subdomain requests for .test domains
$app->add(function (Request $request, $handler) {
    $response = $handler->handle($request);
    
    $origin = $request->getHeaderLine('Origin');
    
    // Allow any subdomain of .test domains (e.g., admin.thejake.test, dashboard.magic.test)
    if ($origin && preg_match('/^https?:\/\/(.+\.)?[^.]+\.test$/', $origin)) {
        $response = $response
            ->withHeader('Access-Control-Allow-Origin', $origin)
            ->withHeader('Access-Control-Allow-Methods', 'GET,POST,PUT,DELETE,OPTIONS')
            ->withHeader('Access-Control-Allow-Headers', 'Content-Type,Authorization,X-WP-Nonce,X-Requested-With,X-HTTP-Method-Override,Origin,Accept')
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
require __DIR__ . '/../api/routes/users.php';
require __DIR__ . '/../api/routes/kernel.php';
require __DIR__ . '/../api/routes/instances.php';
require __DIR__ . '/../api/routes/instances-actions.php';
require __DIR__ . '/../api/routes/themes.php';
require __DIR__ . '/../api/routes/dsl.php';
require __DIR__ . '/../api/routes/conditional-loading.php';
require __DIR__ . '/../api/routes/cache.php';

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
    // Skip instance routing for admin paths (already handled above)
    $requestPath = $request->getUri()->getPath();
    if (strpos($requestPath, '/admin') === 0 || $requestPath === '/login') {
        return $response->withStatus(404);
    }
    
    // Get instance from domain using database lookup
    $host = $request->getUri()->getHost();
    
    // Add CORS headers for cross-subdomain API requests
    // This allows any subdomain (admin.*, dashboard.*, etc.) to make API calls
    $origin = $request->getHeaderLine('Origin');
    if ($origin && preg_match('/^https?:\/\/(.+\.)?[^.]+\.test$/', $origin)) {
        // Set headers using native PHP header() function
        // This ensures they're sent before WordPress processes the request
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Authorization, Content-Type, X-WP-Nonce, X-Requested-With, Origin, Accept, X-HTTP-Method-Override');
        header('Access-Control-Allow-Credentials: true');
        
        // Handle OPTIONS preflight request
        if ($request->getMethod() === 'OPTIONS') {
            http_response_code(200);
            exit;
        }
    }
    
    // Get kernel and database
    $kernel = \IkabudKernel\Core\Kernel::getInstance();
    $db = $kernel->getDatabase();
    
    // Look up instance by domain
    $stmt = $db->prepare("SELECT instance_id FROM instances WHERE domain = ? LIMIT 1");
    $stmt->execute([$host]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $instanceId = $result['instance_id'] ?? null;
    
    if (!$instanceId) {
        $response->getBody()->write("Instance not found for domain: {$host}");
        return $response->withStatus(404);
    }
    
    // DEBUG: Log which instance is being loaded
    error_log("IKABUD_ROUTING: domain={$host} -> instance_id={$instanceId}");
    
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
            
            // Add cache status headers
            $response = $response->withHeader('X-Cache', 'HIT')
                                 ->withHeader('X-Cache-Instance', $instanceId)
                                 ->withHeader('Cache-Control', 'public, max-age=3600')
                                 ->withHeader('X-Powered-By', 'Ikabud-Kernel');
            
            $response->getBody()->write($cached['body']);
            return $response;
        }
    }
    
    // Cache MISS or uncacheable - load CMS instance
    chdir($instanceDir);
    $_SERVER['DOCUMENT_ROOT'] = $instanceDir;
    $_SERVER['IKABUD_INSTANCE_ID'] = $instanceId;
    
    // DEBUG: Verify directory change
    error_log("IKABUD_CHDIR: Changed to {$instanceDir}, getcwd()=" . getcwd() . ", instance_id={$instanceId}");
    
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
                } elseif ($cmsType === 'drupal') {
                    // Drupal uses its own index.php with DrupalKernel bootstrap
                    // We don't need to load anything here - the instance's index.php handles it
                    // Just set a flag so Drupal knows it's running through the kernel
                    define('IKABUD_DRUPAL_KERNEL', true);
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
        // Set conditional loading flag if enabled
        if ($conditionalLoader && !defined('IKABUD_CONDITIONAL_LOADING')) {
            define('IKABUD_CONDITIONAL_LOADING', $conditionalLoader->isEnabled());
        }
        
        // Store extensions to load for CMS hooks
        if ($conditionalLoader && $conditionalLoader->isEnabled() && !empty($extensionsToLoad)) {
            $GLOBALS['ikabud_extensions_to_load'] = $extensionsToLoad;
            $GLOBALS['ikabud_conditional_loader'] = $conditionalLoader;
        }
        
        $_SERVER['SCRIPT_FILENAME'] = $requestedFile . '/index.php';
        require $requestedFile . '/index.php';
    } else {
        // Use CMS index.php for pretty URLs
        if (!defined('IKABUD_CONDITIONAL_LOADING') && $conditionalLoader) {
            define('IKABUD_CONDITIONAL_LOADING', $conditionalLoader->isEnabled());
        }
        
        if ($conditionalLoader && $conditionalLoader->isEnabled() && !empty($extensionsToLoad)) {
            $GLOBALS['ikabud_extensions_to_load'] = $extensionsToLoad;
            $GLOBALS['ikabud_conditional_loader'] = $conditionalLoader;
        }
        
        $_SERVER['SCRIPT_FILENAME'] = $instanceDir . '/index.php';
        require $instanceDir . '/index.php';
    }
    
    // Capture and cache if needed
    if ($shouldCacheResponse && ob_get_level() > 0) {
        $body = ob_get_contents();
        ob_end_clean();
        
        // Add X-Cache: MISS header for first request
        header('X-Cache: MISS');
        header('X-Cache-Instance: ' . $instanceId);
        header('X-Powered-By: Ikabud-Kernel');
        
        // Only cache if there were no errors (check for PHP warnings/errors in output)
        if ($body !== false && is_string($body) && !preg_match('/<b>(Warning|Error|Notice|Fatal error)<\/b>/', $body)) {
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
