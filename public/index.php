<?php
/**
 * Ikabud Kernel - Entry Point
 * 
 * This is the single entry point for all HTTP requests
 * Kernel boots first, then routes to appropriate handler
 */

declare(strict_types=1);

// EARLY MAINTENANCE CHECK (before any autoloading)
// This must run FIRST to catch maintenance mode before Slim routing
$host = $_SERVER['HTTP_HOST'] ?? '';
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';

// Skip early checks for admin/API routes (they don't need maintenance check)
if (strpos($requestUri, '/admin') !== 0 && 
    strpos($requestUri, '/api/') !== 0 && 
    strpos($requestUri, '/login') !== 0) {
    
    // Simple domain-to-instance cache (in-memory for this request)
    static $domainCache = [];
    
    if (!isset($domainCache[$host])) {
        // Load .env for database connection (minimal parsing)
        $envFile = __DIR__ . '/../.env';
        if (file_exists($envFile)) {
            $env = parse_ini_file($envFile);
            
            try {
                $pdo = new PDO(
                    "mysql:host={$env['DB_HOST']};dbname={$env['DB_DATABASE']}",
                    $env['DB_USERNAME'],
                    $env['DB_PASSWORD'],
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                );
                
                $stmt = $pdo->prepare("SELECT instance_id FROM instances WHERE domain = ? LIMIT 1");
                $stmt->execute([$host]);
                $domainCache[$host] = $stmt->fetchColumn() ?: null;
            } catch (PDOException $e) {
                error_log("[Ikabud] Early DB check failed: " . $e->getMessage());
                $domainCache[$host] = null;
            }
        }
    }

    if ($domainCache[$host]) {
        $instanceDir = __DIR__ . '/../instances/' . $domainCache[$host];
        $maintenanceFile = $instanceDir . '/.maintenance';
        
        if (file_exists($maintenanceFile)) {
            http_response_code(503);
            header('Content-Type: text/html; charset=utf-8');
            header('Retry-After: 300');
            
            $templatePath = __DIR__ . '/../templates/maintenance.html';
            if (file_exists($templatePath)) {
                readfile($templatePath);
            } else {
                echo '<h1>503 Service Unavailable</h1><p>Site is under maintenance.</p>';
            }
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
$config = Config::getInstance();
$debugMode = ($config->get('APP_DEBUG') === 'true');

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
require __DIR__ . '/../api/routes/instance-logs.php';
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

// Catch-all route for CMS routing (matches all paths including root)
// Using empty string default to match root path
$app->any('[/{path:.*}]', function (Request $request, Response $response, array $args = ['path' => '']) {
    // Get request info
    $host = $request->getUri()->getHost();
    $requestUri = $request->getUri()->getPath();
    
    // Handle admin panel BEFORE instance lookup (no database entry needed)
    if (strpos($requestUri, '/admin') === 0) {
        $adminPath = __DIR__ . '/admin';
        $filePath = preg_replace('#^/admin#', '', $requestUri);
        
        if (empty($filePath) || $filePath === '/') {
            $filePath = '/index.html';
        }
        
        $fullPath = $adminPath . $filePath;
        
        // Serve the file if it exists
        if (file_exists($fullPath) && is_file($fullPath)) {
            $mimeType = mime_content_type($fullPath) ?: 'application/octet-stream';
            $response = $response->withHeader('Content-Type', $mimeType);
            $response->getBody()->write(file_get_contents($fullPath));
            return $response;
        } else {
            // SPA fallback - serve index.html for client-side routing
            $indexPath = $adminPath . '/index.html';
            if (file_exists($indexPath)) {
                $response = $response->withHeader('Content-Type', 'text/html');
                $response->getBody()->write(file_get_contents($indexPath));
                return $response;
            }
        }
        
        $response->getBody()->write("Admin panel file not found: {$filePath}");
        return $response->withStatus(404);
    }
    
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
    
    // Log instance routing in debug mode
    if ($debugMode ?? false) {
        error_log("[Ikabud] Routing: {$host} -> {$instanceId}");
    }
    
    $stmt = $db->prepare("SELECT * FROM instances WHERE instance_id = ? LIMIT 1");
    $stmt->execute([$instanceId]);
    $instance = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$instance || $instance['status'] !== 'active') {
        $response->getBody()->write("Instance is not active: {$instanceId}");
        return $response->withStatus(503);
    }
    
    // Handle native kernel instance (no CMS to load)
    if ($instance['cms_type'] === 'native') {
        $config = json_decode($instance['config'] ?? '{}', true);
        
        // If this is the kernel itself
        if (isset($config['type']) && $config['type'] === 'kernel') {
            // Redirect root path to admin panel
            if ($requestUri === '/' || $requestUri === '') {
                return $response
                    ->withHeader('Location', '/admin')
                    ->withStatus(302);
            }
            
            // For other paths, return kernel status
            $response->getBody()->write(json_encode([
                'status' => 'ok',
                'message' => 'Ikabud Kernel is running',
                'instance' => $instanceId,
                'version' => '1.0.0',
                'admin_url' => '/admin'
            ]));
            return $response->withHeader('Content-Type', 'application/json');
        }
    }
    
    // Check for maintenance mode
    $instanceDir = __DIR__ . '/../instances/' . $instanceId;
    $maintenanceFile = $instanceDir . '/.maintenance';
    
    if (file_exists($maintenanceFile)) {
        // Show maintenance page
        $maintenanceHtml = file_get_contents(__DIR__ . '/../templates/maintenance.html');
        $response->getBody()->write($maintenanceHtml);
        return $response->withStatus(503)->withHeader('Content-Type', 'text/html');
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
    
    // Verify directory change in debug mode
    if ($debugMode ?? false) {
        error_log("[Ikabud] Changed to: {$instanceDir} (cwd: " . getcwd() . ")");
    }
    
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
                    // Set flag so Drupal knows it's running through the kernel
                    if (!defined('IKABUD_DRUPAL_KERNEL')) {
                        define('IKABUD_DRUPAL_KERNEL', true);
                    }
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
        
        // Set Drupal kernel flag
        if ($cmsType === 'drupal' && !defined('IKABUD_DRUPAL_KERNEL')) {
            define('IKABUD_DRUPAL_KERNEL', true);
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
        
        // Set Drupal kernel flag
        if ($cmsType === 'drupal' && !defined('IKABUD_DRUPAL_KERNEL')) {
            define('IKABUD_DRUPAL_KERNEL', true);
            if ($debugMode ?? false) {
                error_log("[Ikabud] Drupal kernel mode enabled for: {$instanceId}");
            }
        }
        
        $_SERVER['SCRIPT_FILENAME'] = $instanceDir . '/index.php';
        require $instanceDir . '/index.php';
    }
    
    // Capture and cache if needed
    if ($shouldCacheResponse) {
        // Check if Drupal response object is available
        if (isset($GLOBALS['ikabud_drupal_response'])) {
            // Handle Drupal's Symfony Response object
            $drupalResponse = $GLOBALS['ikabud_drupal_response'];
            $body = $drupalResponse->getContent();
            
            // Inject jQuery if needed
            $body = \IkabudKernel\Core\AssetLoader::injectJQuery($body);
            
            // Override Drupal's cache headers
            $drupalResponse->headers->set('X-Cache', 'MISS');
            $drupalResponse->headers->set('X-Cache-Instance', $instanceId);
            $drupalResponse->headers->set('X-Powered-By', 'Ikabud-Kernel');
            $drupalResponse->headers->set('Cache-Control', 'public, max-age=3600');
            $drupalResponse->headers->remove('Pragma');
            $drupalResponse->headers->remove('Expires');
            
            if ($debugMode ?? false) {
                error_log("[Ikabud] Caching Drupal response for: {$instanceId}");
            }
            
            // Cache the response
            if ($body !== false && is_string($body) && !empty($body)) {
                $cacheData = [
                    'headers' => [],
                    'body' => $body,
                    'timestamp' => time(),
                    'cms_type' => 'drupal'
                ];
                
                // Convert Symfony headers to array
                foreach ($drupalResponse->headers->all() as $name => $values) {
                    foreach ($values as $value) {
                        $cacheData['headers'][] = $name . ': ' . $value;
                    }
                }
                
                $cache->set($instanceId, $requestUri, $cacheData);
            }
            
            // Send the response
            $drupalResponse->send();
            
        } elseif (ob_get_level() > 0) {
            // Handle WordPress/Joomla output buffering
            $body = ob_get_contents();
            ob_end_clean();
            
            // Inject jQuery if needed
            $body = \IkabudKernel\Core\AssetLoader::injectJQuery($body);
            
            // Override CMS cache headers for kernel caching
            header_remove('Cache-Control');
            header_remove('Pragma');
            header_remove('Expires');
            
            // Add kernel cache headers
            $cacheHeaders = [
                'X-Cache: MISS',
                'X-Cache-Instance: ' . $instanceId,
                'X-Powered-By: Ikabud-Kernel',
                'Cache-Control: public, max-age=3600'
            ];
            
            foreach ($cacheHeaders as $headerLine) {
                header($headerLine);
            }
            
            // Only cache if there were no errors
            if ($body !== false && is_string($body) && !preg_match('/<b>(Warning|Error|Notice|Fatal error)<\/b>/', $body)) {
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
    }
    
    exit;
});

// Run application
$app->run();
