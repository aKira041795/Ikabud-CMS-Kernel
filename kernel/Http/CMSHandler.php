<?php
/**
 * CMS Handler
 * 
 * Handles routing requests to CMS instances (WordPress, Joomla, Drupal)
 * Extracted from index.php for separation of concerns
 * 
 * @version 1.0.0
 */

namespace IkabudKernel\Core\Http;

use IkabudKernel\Core\Kernel;
use IkabudKernel\Core\Cache;
use IkabudKernel\Core\ConditionalLoaderFactory;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use PDO;

class CMSHandler
{
    private Cache $cache;
    private bool $debugMode;
    private string $instancesDir;
    
    private static array $mimeTypes = [
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
        'txt' => 'text/plain',
    ];
    
    public function __construct(bool $debugMode = false)
    {
        $this->cache = new Cache();
        $this->debugMode = $debugMode;
        $this->instancesDir = dirname(__DIR__, 2) . '/instances';
    }
    
    /**
     * Handle CMS request
     */
    public function handle(Request $request, Response $response): Response
    {
        $host = $request->getUri()->getHost();
        $requestUri = $request->getUri()->getPath();
        
        // Handle WordPress admin-ajax.php early
        if ($this->isWordPressAjax($requestUri)) {
            return $this->handleWordPressAjax($request, $response, $host);
        }
        
        // Look up instance
        $instance = $this->lookupInstance($host);
        
        if (!$instance) {
            $response->getBody()->write("Instance not found for domain: {$host}");
            return $response->withStatus(404);
        }
        
        $instanceId = $instance['instance_id'];
        $instanceDir = $this->instancesDir . '/' . $instanceId;
        
        if ($this->debugMode) {
            error_log("[Ikabud] Routing: {$host} -> {$instanceId}");
        }
        
        // Check instance status
        if ($instance['status'] !== 'active') {
            return $this->serveMaintenancePage($response);
        }
        
        // Handle native kernel instance
        if ($instance['cms_type'] === 'native') {
            return $this->handleNativeInstance($request, $response, $instance, $requestUri);
        }
        
        // Check maintenance mode file
        if (file_exists($instanceDir . '/.maintenance')) {
            return $this->serveMaintenancePage($response);
        }
        
        // Build full request URI with query string
        $fullUri = $requestUri;
        if ($query = $request->getUri()->getQuery()) {
            $fullUri .= '?' . $query;
        }
        
        // Try cache first
        if ($this->cache->shouldCache($fullUri)) {
            $cached = $this->cache->get($instanceId, $fullUri);
            if ($cached) {
                return $this->serveCachedResponse($response, $cached, $instanceId);
            }
        }
        
        // Route to CMS
        return $this->routeToCMS($request, $response, $instance, $instanceDir, $fullUri);
    }
    
    /**
     * Check if request is WordPress admin-ajax
     */
    private function isWordPressAjax(string $uri): bool
    {
        return $uri === '/wp-admin/admin-ajax.php' || 
               strpos($uri, '/wp-admin/admin-ajax.php') !== false;
    }
    
    /**
     * Handle WordPress admin-ajax.php
     */
    private function handleWordPressAjax(Request $request, Response $response, string $host): Response
    {
        $instance = $this->lookupInstance($host);
        
        if ($instance && $instance['cms_type'] === 'wordpress') {
            $instanceDir = $this->instancesDir . '/' . $instance['instance_id'];
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
        
        $response->getBody()->write('WordPress AJAX handler not found');
        return $response->withStatus(404);
    }
    
    /**
     * Look up instance by domain
     */
    private function lookupInstance(string $host): ?array
    {
        $kernel = Kernel::getInstance();
        $db = $kernel->getDatabase();
        
        $stmt = $db->prepare("SELECT * FROM instances WHERE domain = ? LIMIT 1");
        $stmt->execute([$host]);
        
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    
    /**
     * Serve maintenance page
     */
    private function serveMaintenancePage(Response $response): Response
    {
        $templatePath = dirname(__DIR__, 2) . '/templates/maintenance.html';
        $html = file_exists($templatePath) 
            ? file_get_contents($templatePath)
            : '<h1>Site Under Maintenance</h1><p>Please check back later.</p>';
        
        $response->getBody()->write($html);
        return $response->withStatus(503)
                        ->withHeader('Content-Type', 'text/html')
                        ->withHeader('Retry-After', '300');
    }
    
    /**
     * Handle native kernel instance
     */
    private function handleNativeInstance(Request $request, Response $response, array $instance, string $requestUri): Response
    {
        $config = json_decode($instance['config'] ?? '{}', true);
        
        if (isset($config['type']) && $config['type'] === 'kernel') {
            if ($requestUri === '/' || $requestUri === '') {
                return $response->withHeader('Location', '/admin')->withStatus(302);
            }
            
            $response->getBody()->write(json_encode([
                'status' => 'ok',
                'message' => 'Ikabud Kernel is running',
                'instance' => $instance['instance_id'],
                'version' => Kernel::VERSION,
                'admin_url' => '/admin'
            ]));
            return $response->withHeader('Content-Type', 'application/json');
        }
        
        $response->getBody()->write('Native instance');
        return $response;
    }
    
    /**
     * Serve cached response
     */
    private function serveCachedResponse(Response $response, array $cached, string $instanceId): Response
    {
        foreach ($cached['headers'] ?? [] as $header) {
            if (preg_match('/^([^:]+):\s*(.*)$/', $header, $matches)) {
                $response = $response->withHeader($matches[1], $matches[2]);
            }
        }
        
        $response = $response
            ->withHeader('X-Cache', 'HIT')
            ->withHeader('X-Cache-Instance', $instanceId)
            ->withHeader('Cache-Control', 'public, max-age=3600')
            ->withHeader('X-Powered-By', 'Ikabud-Kernel');
        
        $response->getBody()->write($cached['body'] ?? '');
        return $response;
    }
    
    /**
     * Route request to CMS
     */
    private function routeToCMS(Request $request, Response $response, array $instance, string $instanceDir, string $requestUri): Response
    {
        $instanceId = $instance['instance_id'];
        $cmsType = $instance['cms_type'] ?? 'wordpress';
        
        // Change to instance directory
        chdir($instanceDir);
        $_SERVER['DOCUMENT_ROOT'] = $instanceDir;
        $_SERVER['IKABUD_INSTANCE_ID'] = $instanceId;
        
        if ($this->debugMode) {
            error_log("[Ikabud] Changed to: {$instanceDir}");
        }
        
        // Detect CMS type
        $detectedType = ConditionalLoaderFactory::detectCMSType($instanceDir);
        if ($detectedType) {
            $cmsType = $detectedType;
        }
        
        // Initialize conditional loader
        $conditionalLoader = ConditionalLoaderFactory::create($instanceDir, $cmsType);
        $extensionsToLoad = [];
        
        if ($conditionalLoader && $conditionalLoader->isEnabled()) {
            $context = [
                'request_uri' => $requestUri,
                'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
                'cms_type' => $cmsType
            ];
            $extensionsToLoad = $conditionalLoader->determineExtensions($requestUri, $context);
        }
        
        // Determine file to serve
        $requestPath = parse_url($requestUri, PHP_URL_PATH);
        $requestedFile = $instanceDir . $requestPath;
        
        // Should we cache?
        $shouldCache = $this->cache->shouldCache($requestUri);
        
        if ($shouldCache) {
            ob_start();
            $this->registerCacheShutdown($instanceId, $requestUri, $cmsType, $conditionalLoader);
        }
        
        // Route to appropriate file
        if (is_file($requestedFile)) {
            $this->serveFile($requestedFile, $instanceDir, $cmsType, $conditionalLoader, $extensionsToLoad);
        } elseif (is_dir($requestedFile) && is_file($requestedFile . '/index.php')) {
            $this->serveCMSIndex($requestedFile . '/index.php', $cmsType, $conditionalLoader, $extensionsToLoad);
        } else {
            $this->serveCMSIndex($instanceDir . '/index.php', $cmsType, $conditionalLoader, $extensionsToLoad);
        }
        
        // Handle Drupal response
        if ($cmsType === 'drupal' && isset($GLOBALS['ikabud_drupal_response'])) {
            $this->handleDrupalResponse($instanceId, $requestUri, $shouldCache);
        }
        
        // Capture and cache
        if ($shouldCache && ob_get_level() > 0) {
            $this->captureAndCache($instanceId, $requestUri, $cmsType, $conditionalLoader);
        }
        
        exit;
    }
    
    /**
     * Serve a file (PHP or static)
     */
    private function serveFile(string $file, string $instanceDir, string $cmsType, $conditionalLoader, array $extensionsToLoad): void
    {
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        
        if ($ext === 'php') {
            $this->loadCMSCore($instanceDir, $cmsType, $conditionalLoader, $extensionsToLoad);
            $_SERVER['SCRIPT_FILENAME'] = $file;
            require $file;
        } else {
            $mimeType = self::$mimeTypes[$ext] ?? 'application/octet-stream';
            header('Content-Type: ' . $mimeType);
            readfile($file);
        }
    }
    
    /**
     * Serve CMS index.php
     */
    private function serveCMSIndex(string $indexFile, string $cmsType, $conditionalLoader, array $extensionsToLoad): void
    {
        $instanceDir = dirname($indexFile);
        
        // Set conditional loading globals
        if ($conditionalLoader && $conditionalLoader->isEnabled()) {
            if (!defined('IKABUD_CONDITIONAL_LOADING')) {
                define('IKABUD_CONDITIONAL_LOADING', true);
            }
            if (!empty($extensionsToLoad)) {
                $GLOBALS['ikabud_extensions_to_load'] = $extensionsToLoad;
                $GLOBALS['ikabud_conditional_loader'] = $conditionalLoader;
            }
        }
        
        // Set Drupal flag
        if ($cmsType === 'drupal' && !defined('IKABUD_DRUPAL_KERNEL')) {
            define('IKABUD_DRUPAL_KERNEL', true);
        }
        
        $_SERVER['SCRIPT_FILENAME'] = $indexFile;
        require $indexFile;
    }
    
    /**
     * Load CMS core
     */
    private function loadCMSCore(string $instanceDir, string $cmsType, $conditionalLoader, array $extensionsToLoad): void
    {
        if (defined('ABSPATH') || defined('_JEXEC')) {
            return; // Already loaded
        }
        
        if ($conditionalLoader) {
            define('IKABUD_CONDITIONAL_LOADING', $conditionalLoader->isEnabled());
        }
        
        switch ($cmsType) {
            case 'wordpress':
                require_once $instanceDir . '/wp-load.php';
                Kernel::initCMSIntegrations('wordpress');
                break;
                
            case 'joomla':
                define('_JEXEC', 1);
                if (file_exists($instanceDir . '/defines.php')) {
                    require_once $instanceDir . '/defines.php';
                }
                require_once $instanceDir . '/includes/framework.php';
                Kernel::initCMSIntegrations('joomla');
                break;
                
            case 'drupal':
                if (!defined('IKABUD_DRUPAL_KERNEL')) {
                    define('IKABUD_DRUPAL_KERNEL', true);
                }
                Kernel::initCMSIntegrations('drupal');
                break;
        }
        
        // Load extensions after CMS core
        if ($conditionalLoader && !empty($extensionsToLoad)) {
            $conditionalLoader->loadExtensions($extensionsToLoad);
        }
    }
    
    /**
     * Register shutdown function for caching
     */
    private function registerCacheShutdown(string $instanceId, string $requestUri, string $cmsType, $conditionalLoader): void
    {
        register_shutdown_function(function() use ($instanceId, $requestUri, $cmsType, $conditionalLoader) {
            if (ob_get_level() > 0) {
                $body = ob_get_contents();
                ob_end_clean();
                
                if ($this->isValidCacheContent($body)) {
                    $this->cacheResponse($instanceId, $requestUri, $body, $cmsType, $conditionalLoader);
                }
                
                echo $body;
            }
        });
    }
    
    /**
     * Handle Drupal response
     */
    private function handleDrupalResponse(string $instanceId, string $requestUri, bool $shouldCache): void
    {
        $drupalResponse = $GLOBALS['ikabud_drupal_response'];
        
        if ($shouldCache) {
            $responseBody = $drupalResponse->getContent();
            $responseHeaders = [];
            
            foreach ($drupalResponse->headers->all() as $name => $values) {
                foreach ($values as $value) {
                    $responseHeaders[] = "{$name}: {$value}";
                }
            }
            
            if ($this->isValidCacheContent($responseBody)) {
                $this->cache->set($instanceId, $requestUri, [
                    'headers' => $responseHeaders,
                    'body' => $responseBody,
                    'timestamp' => time(),
                    'cms_type' => 'drupal'
                ]);
            }
        }
        
        $drupalResponse->send();
        exit;
    }
    
    /**
     * Capture output and cache
     */
    private function captureAndCache(string $instanceId, string $requestUri, string $cmsType, $conditionalLoader): void
    {
        $body = ob_get_contents();
        ob_end_clean();
        
        if ($this->isValidCacheContent($body)) {
            $this->cacheResponse($instanceId, $requestUri, $body, $cmsType, $conditionalLoader);
        }
        
        echo $body;
    }
    
    /**
     * Check if content is valid for caching
     */
    private function isValidCacheContent($body): bool
    {
        return $body !== false && 
               is_string($body) && 
               !empty($body) && 
               !preg_match('/<b>(Warning|Error|Notice|Fatal error)<\/b>/', $body);
    }
    
    /**
     * Cache response
     */
    private function cacheResponse(string $instanceId, string $requestUri, string $body, string $cmsType, $conditionalLoader): void
    {
        $cacheData = [
            'headers' => headers_list(),
            'body' => $body,
            'timestamp' => time(),
            'cms_type' => $cmsType
        ];
        
        if ($conditionalLoader) {
            $cacheData['extensions_loaded'] = $conditionalLoader->getLoadedExtensions();
            $cacheData['extension_count'] = count($conditionalLoader->getLoadedExtensions());
        }
        
        $this->cache->set($instanceId, $requestUri, $cacheData);
    }
}
