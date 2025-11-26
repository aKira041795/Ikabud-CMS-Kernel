<?php
/**
 * API Response Cache Middleware
 * 
 * Caches API responses for improved performance.
 * Uses multi-tier caching: APCu (fastest) -> File cache (persistent)
 * 
 * Features:
 * - Configurable TTL per route
 * - Cache key includes query parameters
 * - Automatic cache invalidation headers
 * - Bypass for authenticated requests (optional)
 * 
 * @version 1.0.0
 */

namespace IkabudKernel\Api\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class ResponseCacheMiddleware implements MiddlewareInterface
{
    /** @var array Route patterns and their TTL in seconds */
    private array $cacheableRoutes = [
        // Read-only endpoints with short TTL
        'GET /api/v1/instances' => 60,           // 1 minute
        'GET /api/v1/kernel/stats' => 30,        // 30 seconds
        'GET /api/v1/kernel/health' => 15,       // 15 seconds
        'GET /api/health' => 15,                 // 15 seconds
        'GET /api/v1/themes' => 300,             // 5 minutes
        'GET /api/v1/dsl/formats' => 600,        // 10 minutes
        'GET /api/v1/conditional/stats' => 60,   // 1 minute
        'GET /api/v1/cache/stats' => 30,         // 30 seconds
    ];
    
    /** @var string Cache directory for file-based cache */
    private string $cacheDir;
    
    /** @var bool Whether APCu is available */
    private bool $apcuAvailable;
    
    /** @var bool Whether to bypass cache for authenticated requests */
    private bool $bypassForAuth;
    
    /**
     * Constructor
     * 
     * @param string|null $cacheDir Cache directory path
     * @param bool $bypassForAuth Whether to bypass cache for authenticated requests
     */
    public function __construct(?string $cacheDir = null, bool $bypassForAuth = false)
    {
        $this->cacheDir = $cacheDir ?? dirname(__DIR__, 2) . '/storage/api-cache';
        $this->apcuAvailable = function_exists('apcu_fetch') && apcu_enabled();
        $this->bypassForAuth = $bypassForAuth;
        
        // Ensure cache directory exists
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }
    }
    
    /**
     * Process the request
     */
    public function process(Request $request, RequestHandlerInterface $handler): Response
    {
        $method = $request->getMethod();
        $path = $request->getUri()->getPath();
        $routeKey = "{$method} {$path}";
        
        // Only cache GET requests
        if ($method !== 'GET') {
            return $handler->handle($request);
        }
        
        // Check if route is cacheable
        $ttl = $this->getCacheTTL($routeKey);
        if ($ttl === null) {
            return $handler->handle($request);
        }
        
        // Bypass cache for authenticated requests if configured
        if ($this->bypassForAuth && $request->hasHeader('Authorization')) {
            return $handler->handle($request);
        }
        
        // Generate cache key
        $cacheKey = $this->generateCacheKey($request);
        
        // Try to get from cache
        $cached = $this->getFromCache($cacheKey);
        if ($cached !== null) {
            return $this->createCachedResponse($cached, $request);
        }
        
        // Cache miss - handle request
        $response = $handler->handle($request);
        
        // Only cache successful responses
        if ($response->getStatusCode() === 200) {
            $this->storeInCache($cacheKey, $response, $ttl);
        }
        
        // Add cache headers
        return $response
            ->withHeader('X-Cache', 'MISS')
            ->withHeader('X-Cache-TTL', (string)$ttl);
    }
    
    /**
     * Get cache TTL for a route
     */
    private function getCacheTTL(string $routeKey): ?int
    {
        // Exact match
        if (isset($this->cacheableRoutes[$routeKey])) {
            return $this->cacheableRoutes[$routeKey];
        }
        
        // Pattern match (for routes with parameters)
        foreach ($this->cacheableRoutes as $pattern => $ttl) {
            // Convert route pattern to regex
            $regex = preg_replace('/\{[^}]+\}/', '[^/]+', $pattern);
            $regex = str_replace('/', '\/', $regex);
            if (preg_match("/^{$regex}$/", $routeKey)) {
                return $ttl;
            }
        }
        
        return null;
    }
    
    /**
     * Generate cache key from request
     */
    private function generateCacheKey(Request $request): string
    {
        $parts = [
            $request->getMethod(),
            $request->getUri()->getPath(),
        ];
        
        // Include query parameters
        $queryParams = $request->getQueryParams();
        if (!empty($queryParams)) {
            ksort($queryParams);
            $parts[] = http_build_query($queryParams);
        }
        
        return 'api_cache_' . md5(implode('|', $parts));
    }
    
    /**
     * Get cached response
     */
    private function getFromCache(string $key): ?array
    {
        // Try APCu first (fastest)
        if ($this->apcuAvailable) {
            $cached = apcu_fetch($key, $success);
            if ($success && is_array($cached)) {
                return $cached;
            }
        }
        
        // Try file cache
        $cacheFile = $this->cacheDir . '/' . $key . '.cache';
        if (file_exists($cacheFile)) {
            $data = file_get_contents($cacheFile);
            if ($data !== false) {
                // Check if compressed
                if (strpos($data, 'GZ:') === 0) {
                    $data = gzuncompress(substr($data, 3));
                }
                
                $cached = @unserialize($data);
                if (is_array($cached) && isset($cached['expires']) && $cached['expires'] > time()) {
                    // Promote to APCu
                    if ($this->apcuAvailable) {
                        apcu_store($key, $cached, $cached['expires'] - time());
                    }
                    return $cached;
                }
                
                // Expired - delete
                @unlink($cacheFile);
            }
        }
        
        return null;
    }
    
    /**
     * Store response in cache
     */
    private function storeInCache(string $key, Response $response, int $ttl): void
    {
        $body = (string)$response->getBody();
        
        $cached = [
            'status' => $response->getStatusCode(),
            'headers' => $response->getHeaders(),
            'body' => $body,
            'expires' => time() + $ttl,
            'created' => time(),
        ];
        
        // Store in APCu
        if ($this->apcuAvailable) {
            apcu_store($key, $cached, $ttl);
        }
        
        // Store in file cache
        $cacheFile = $this->cacheDir . '/' . $key . '.cache';
        $data = serialize($cached);
        
        // Compress if large
        if (strlen($data) > 1024) {
            $data = 'GZ:' . gzcompress($data, 6);
        }
        
        // Atomic write
        $tempFile = $cacheFile . '.tmp.' . getmypid();
        if (file_put_contents($tempFile, $data, LOCK_EX) !== false) {
            rename($tempFile, $cacheFile);
        }
    }
    
    /**
     * Create response from cached data
     */
    private function createCachedResponse(array $cached, Request $request): Response
    {
        $response = new \Slim\Psr7\Response($cached['status']);
        
        // Restore headers
        foreach ($cached['headers'] as $name => $values) {
            foreach ($values as $value) {
                $response = $response->withAddedHeader($name, $value);
            }
        }
        
        // Write body
        $response->getBody()->write($cached['body']);
        
        // Add cache headers
        $age = time() - $cached['created'];
        $remaining = $cached['expires'] - time();
        
        return $response
            ->withHeader('X-Cache', 'HIT')
            ->withHeader('X-Cache-Age', (string)$age)
            ->withHeader('X-Cache-Remaining', (string)$remaining);
    }
    
    /**
     * Add a cacheable route
     */
    public function addCacheableRoute(string $routeKey, int $ttl): self
    {
        $this->cacheableRoutes[$routeKey] = $ttl;
        return $this;
    }
    
    /**
     * Clear all API cache
     */
    public function clearCache(): void
    {
        // Clear APCu
        if ($this->apcuAvailable) {
            $iterator = new \APCUIterator('/^api_cache_/', APC_ITER_KEY);
            foreach ($iterator as $item) {
                apcu_delete($item['key']);
            }
        }
        
        // Clear file cache
        $files = glob($this->cacheDir . '/*.cache');
        foreach ($files as $file) {
            @unlink($file);
        }
    }
    
    /**
     * Get cache statistics
     */
    public function getStats(): array
    {
        $stats = [
            'apcu_available' => $this->apcuAvailable,
            'cache_dir' => $this->cacheDir,
            'cacheable_routes' => count($this->cacheableRoutes),
        ];
        
        // Count file cache entries
        $files = glob($this->cacheDir . '/*.cache');
        $stats['file_cache_entries'] = count($files);
        $stats['file_cache_size'] = array_sum(array_map('filesize', $files));
        
        // APCu stats
        if ($this->apcuAvailable) {
            $apcuInfo = apcu_cache_info(true);
            $stats['apcu_entries'] = $apcuInfo['num_entries'] ?? 0;
            $stats['apcu_memory'] = $apcuInfo['mem_size'] ?? 0;
        }
        
        return $stats;
    }
}
