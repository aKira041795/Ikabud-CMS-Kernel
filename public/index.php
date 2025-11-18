<?php
/**
 * Ikabud Kernel - Entry Point
 * 
 * This is the single entry point for all HTTP requests
 * Kernel boots first, then routes to appropriate handler
 */

declare(strict_types=1);

// Early maintenance check (before autoloading)
$host = $_SERVER['HTTP_HOST'] ?? '';
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';

// Check maintenance mode before loading anything heavy
if (file_exists(__DIR__ . '/../kernel/Middleware/MaintenanceMiddleware.php')) {
    require_once __DIR__ . '/../kernel/Middleware/MaintenanceMiddleware.php';
    \IkabudKernel\Core\Middleware\MaintenanceMiddleware::check($host, $requestUri);
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
    
    // CRITICAL: Handle WordPress admin-ajax.php BEFORE any kernel processing
    // This prevents WordPress from being loaded multiple times (function redeclaration errors)
    if ($requestUri === '/wp-admin/admin-ajax.php' || strpos($requestUri, '/wp-admin/admin-ajax.php') !== false) {
        // Look up instance by domain
        $kernel = \IkabudKernel\Core\Kernel::getInstance();
        $db = $kernel->getDatabase();
        $stmt = $db->prepare("SELECT instance_id, cms_type FROM instances WHERE domain = ? LIMIT 1");
        $stmt->execute([$host]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result && $result['cms_type'] === 'wordpress') {
            $instanceDir = __DIR__ . '/../instances/' . $result['instance_id'];
            $ajaxFile = $instanceDir . '/wp-admin/admin-ajax.php';
            
            if (file_exists($ajaxFile)) {
                chdir($instanceDir);
                $_SERVER['DOCUMENT_ROOT'] = $instanceDir;
                $_SERVER['SCRIPT_FILENAME'] = $ajaxFile;
                $_SERVER['SCRIPT_NAME'] = '/wp-admin/admin-ajax.php';
                require $ajaxFile;
                exit;
            }
        }
    }
    
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
    
    // CORS headers are handled by middleware - no need for duplicate native headers
    
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
        // Show maintenance page when instance is stopped or not found
        $maintenanceHtml = file_get_contents(__DIR__ . '/../templates/maintenance.html');
        $response->getBody()->write($maintenanceHtml);
        return $response->withStatus(503)
                        ->withHeader('Content-Type', 'text/html')
                        ->withHeader('Retry-After', '300');
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
        
        // Register shutdown function to handle output buffering
        // This ensures buffered content is flushed even if CMS exits early
        register_shutdown_function(function() use ($cache, $instanceId, $requestUri, $cmsType, &$conditionalLoader) {
            if (ob_get_level() > 0) {
                $body = ob_get_contents();
                ob_end_clean();
                
                // Only cache if there were no errors
                if ($body !== false && is_string($body) && !empty($body) && !preg_match('/<b>(Warning|Error|Notice|Fatal error)<\/b>/', $body)) {
                    $headers = headers_list();
                    $cacheData = [
                        'headers' => $headers,
                        'body' => $body,
                        'timestamp' => time(),
                        'cms_type' => $cmsType
                    ];
                    
                    // Add extension loading stats if available
                    if (isset($conditionalLoader)) {
                        $cacheData['extensions_loaded'] = $conditionalLoader->getLoadedExtensions();
                        $cacheData['extension_count'] = count($conditionalLoader->getLoadedExtensions());
                    }
                    
                    $cache->set($instanceId, $requestUri, $cacheData);
                }
                
                // Output the captured content
                echo $body;
            }
        });
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
                    // Initialize WordPress integrations via Kernel
                    Kernel::initCMSIntegrations('wordpress');
                } elseif ($cmsType === 'joomla') {
                    define('_JEXEC', 1);
                    // Load instance-specific defines first (sets JPATH_BASE, etc.)
                    if (file_exists($instanceDir . '/defines.php')) {
                        require_once $instanceDir . '/defines.php';
                    }
                    // Then load framework from shared core
                    require_once $instanceDir . '/includes/framework.php';
                    Kernel::initCMSIntegrations('joomla');
                } elseif ($cmsType === 'drupal') {
                    // Drupal uses its own index.php with DrupalKernel bootstrap
                    if (!defined('IKABUD_DRUPAL_KERNEL')) {
                        define('IKABUD_DRUPAL_KERNEL', true);
                    }
                    Kernel::initCMSIntegrations('drupal');
                }
                
                // Load determined extensions after CMS core
                if ($conditionalLoader && !empty($extensionsToLoad)) {
                    $conditionalLoader->loadExtensions($extensionsToLoad);
                }
            }
            $_SERVER['SCRIPT_FILENAME'] = $requestedFile;
            require $requestedFile;
            
            // Handle Drupal response if stored in globals
            if ($cmsType === 'drupal' && isset($GLOBALS['ikabud_drupal_response'])) {
                $drupalResponse = $GLOBALS['ikabud_drupal_response'];
                $drupalResponse->send();
                exit;
            }
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
        
        // Handle Drupal response if stored in globals
        if ($cmsType === 'drupal' && isset($GLOBALS['ikabud_drupal_response'])) {
            $drupalResponse = $GLOBALS['ikabud_drupal_response'];
            $drupalResponse->send();
            exit;
        }
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
        
        // Handle Drupal response if stored in globals
        if ($cmsType === 'drupal' && isset($GLOBALS['ikabud_drupal_response'])) {
            $drupalResponse = $GLOBALS['ikabud_drupal_response'];
            
            // If caching is enabled, capture the response
            if ($shouldCacheResponse) {
                $responseBody = $drupalResponse->getContent();
                $responseHeaders = [];
                
                // Get headers from Symfony response
                foreach ($drupalResponse->headers->all() as $name => $values) {
                    foreach ($values as $value) {
                        $responseHeaders[] = "{$name}: {$value}";
                    }
                }
                
                // Cache the response
                if (!preg_match('/<b>(Warning|Error|Notice|Fatal error)<\/b>/', $responseBody)) {
                    $cacheData = [
                        'headers' => $responseHeaders,
                        'body' => $responseBody,
                        'timestamp' => time(),
                        'cms_type' => 'drupal'
                    ];
                    $cache->set($instanceId, $requestUri, $cacheData);
                }
            }
            
            // Send the Drupal response
            $drupalResponse->send();
            exit;
        }
    }
    
    // Capture and cache if needed
    if ($shouldCacheResponse) {
        // Capture output and cache
        if (ob_get_level() > 0) {
            $body = ob_get_contents();
            ob_end_clean();
            
            // Only cache if there were no errors
            if ($body !== false && is_string($body) && !preg_match('/<b>(Warning|Error|Notice|Fatal error)<\/b>/', $body)) {
                $headers = headers_list();
                $cacheData = [
                    'headers' => $headers,
                    'body' => $body,
                    'timestamp' => time(),
                    'cms_type' => $cmsType
                ];
                
                // Add extension loading stats if available
                if (isset($conditionalLoader)) {
                    $cacheData['extensions_loaded'] = $conditionalLoader->getLoadedExtensions();
                    $cacheData['extension_count'] = count($conditionalLoader->getLoadedExtensions());
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
