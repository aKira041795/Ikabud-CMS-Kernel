<?php
/**
 * Rate Limiting Middleware
 * 
 * Protects API endpoints from abuse using token bucket algorithm.
 * Uses APCu for fast rate limit tracking, falls back to file-based storage.
 * 
 * Features:
 * - Configurable limits per route
 * - IP-based and user-based limiting
 * - Sliding window algorithm
 * - Rate limit headers (X-RateLimit-*)
 * - Graceful degradation without APCu
 * 
 * @version 1.0.0
 */

namespace IkabudKernel\Api\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class RateLimitMiddleware implements MiddlewareInterface
{
    /** @var array Default rate limits per route pattern */
    private array $limits = [
        // Auth endpoints (stricter limits)
        'POST /api/v1/auth/login' => ['requests' => 5, 'window' => 60],      // 5 per minute
        'POST /api/v1/auth/register' => ['requests' => 3, 'window' => 60],   // 3 per minute
        
        // Write operations
        'POST /api/v1/*' => ['requests' => 30, 'window' => 60],   // 30 per minute
        'PUT /api/v1/*' => ['requests' => 30, 'window' => 60],    // 30 per minute
        'DELETE /api/v1/*' => ['requests' => 10, 'window' => 60], // 10 per minute
        
        // Read operations (more lenient)
        'GET /api/v1/*' => ['requests' => 100, 'window' => 60],   // 100 per minute
        
        // Default fallback
        '*' => ['requests' => 60, 'window' => 60],                // 60 per minute
    ];
    
    /** @var string Storage directory for file-based fallback */
    private string $storageDir;
    
    /** @var bool Whether APCu is available */
    private bool $apcuAvailable;
    
    /** @var bool Whether to use user ID for rate limiting (requires auth) */
    private bool $useUserId;
    
    /**
     * Constructor
     * 
     * @param string|null $storageDir Storage directory for file-based fallback
     * @param bool $useUserId Whether to use user ID instead of IP for authenticated requests
     */
    public function __construct(?string $storageDir = null, bool $useUserId = true)
    {
        $this->storageDir = $storageDir ?? dirname(__DIR__, 2) . '/storage/rate-limits';
        $this->apcuAvailable = function_exists('apcu_fetch') && apcu_enabled();
        $this->useUserId = $useUserId;
        
        // Ensure storage directory exists
        if (!$this->apcuAvailable && !is_dir($this->storageDir)) {
            mkdir($this->storageDir, 0755, true);
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
        
        // Get rate limit for this route
        $limit = $this->getLimit($routeKey);
        
        // Get identifier (user ID or IP)
        $identifier = $this->getIdentifier($request);
        
        // Check rate limit
        $result = $this->checkRateLimit($identifier, $routeKey, $limit);
        
        if (!$result['allowed']) {
            // Rate limit exceeded
            $response = new \Slim\Psr7\Response(429);
            $response->getBody()->write(json_encode([
                'error' => 'Rate limit exceeded',
                'retry_after' => $result['retry_after'],
                'message' => "Too many requests. Please wait {$result['retry_after']} seconds."
            ]));
            
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withHeader('Retry-After', (string)$result['retry_after'])
                ->withHeader('X-RateLimit-Limit', (string)$limit['requests'])
                ->withHeader('X-RateLimit-Remaining', '0')
                ->withHeader('X-RateLimit-Reset', (string)$result['reset']);
        }
        
        // Process request
        $response = $handler->handle($request);
        
        // Add rate limit headers
        return $response
            ->withHeader('X-RateLimit-Limit', (string)$limit['requests'])
            ->withHeader('X-RateLimit-Remaining', (string)$result['remaining'])
            ->withHeader('X-RateLimit-Reset', (string)$result['reset']);
    }
    
    /**
     * Get rate limit for a route
     */
    private function getLimit(string $routeKey): array
    {
        // Exact match
        if (isset($this->limits[$routeKey])) {
            return $this->limits[$routeKey];
        }
        
        // Pattern match
        foreach ($this->limits as $pattern => $limit) {
            if ($this->matchPattern($pattern, $routeKey)) {
                return $limit;
            }
        }
        
        // Default
        return $this->limits['*'];
    }
    
    /**
     * Match route pattern
     */
    private function matchPattern(string $pattern, string $routeKey): bool
    {
        if ($pattern === '*') {
            return true;
        }
        
        // Convert pattern to regex
        $regex = str_replace(['*', '/'], ['.*', '\/'], $pattern);
        return (bool)preg_match("/^{$regex}$/", $routeKey);
    }
    
    /**
     * Get identifier for rate limiting
     */
    private function getIdentifier(Request $request): string
    {
        // Try to get user ID from JWT token
        if ($this->useUserId) {
            $authHeader = $request->getHeaderLine('Authorization');
            if (preg_match('/Bearer\s+(.+)$/i', $authHeader, $matches)) {
                try {
                    $payload = \IkabudKernel\Core\JWT::decode($matches[1]);
                    if (isset($payload['user_id'])) {
                        return 'user_' . $payload['user_id'];
                    }
                } catch (\Exception $e) {
                    // Invalid token, fall back to IP
                }
            }
        }
        
        // Fall back to IP address
        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? '127.0.0.1';
        
        // Check for proxy headers
        $forwardedFor = $request->getHeaderLine('X-Forwarded-For');
        if ($forwardedFor) {
            $ips = explode(',', $forwardedFor);
            $ip = trim($ips[0]);
        }
        
        return 'ip_' . $ip;
    }
    
    /**
     * Check rate limit using sliding window
     */
    private function checkRateLimit(string $identifier, string $routeKey, array $limit): array
    {
        $key = 'ratelimit_' . md5($identifier . '_' . $routeKey);
        $now = time();
        $windowStart = $now - $limit['window'];
        
        // Get current request timestamps
        $timestamps = $this->getTimestamps($key);
        
        // Remove expired timestamps
        $timestamps = array_filter($timestamps, fn($ts) => $ts > $windowStart);
        
        // Check if limit exceeded
        $count = count($timestamps);
        
        if ($count >= $limit['requests']) {
            // Find when oldest request expires
            $oldestTimestamp = min($timestamps);
            $retryAfter = ($oldestTimestamp + $limit['window']) - $now;
            
            return [
                'allowed' => false,
                'remaining' => 0,
                'reset' => $oldestTimestamp + $limit['window'],
                'retry_after' => max(1, $retryAfter),
            ];
        }
        
        // Add current request
        $timestamps[] = $now;
        $this->setTimestamps($key, $timestamps, $limit['window']);
        
        return [
            'allowed' => true,
            'remaining' => $limit['requests'] - count($timestamps),
            'reset' => $now + $limit['window'],
            'retry_after' => 0,
        ];
    }
    
    /**
     * Get timestamps from storage
     */
    private function getTimestamps(string $key): array
    {
        if ($this->apcuAvailable) {
            $data = apcu_fetch($key, $success);
            return $success ? $data : [];
        }
        
        // File-based fallback
        $file = $this->storageDir . '/' . $key . '.json';
        if (file_exists($file)) {
            $data = @file_get_contents($file);
            return $data ? json_decode($data, true) : [];
        }
        
        return [];
    }
    
    /**
     * Set timestamps in storage
     */
    private function setTimestamps(string $key, array $timestamps, int $ttl): void
    {
        if ($this->apcuAvailable) {
            apcu_store($key, $timestamps, $ttl);
            return;
        }
        
        // File-based fallback
        $file = $this->storageDir . '/' . $key . '.json';
        file_put_contents($file, json_encode($timestamps), LOCK_EX);
    }
    
    /**
     * Add or update a rate limit
     */
    public function setLimit(string $pattern, int $requests, int $window = 60): self
    {
        $this->limits[$pattern] = ['requests' => $requests, 'window' => $window];
        return $this;
    }
    
    /**
     * Clear rate limit for an identifier
     */
    public function clearLimit(string $identifier): void
    {
        $pattern = 'ratelimit_' . md5($identifier . '_*');
        
        if ($this->apcuAvailable) {
            $iterator = new \APCUIterator('/^' . preg_quote($pattern, '/') . '/', APC_ITER_KEY);
            foreach ($iterator as $item) {
                apcu_delete($item['key']);
            }
        } else {
            // File-based cleanup
            $files = glob($this->storageDir . '/ratelimit_*.json');
            foreach ($files as $file) {
                @unlink($file);
            }
        }
    }
    
    /**
     * Get rate limit statistics
     */
    public function getStats(): array
    {
        return [
            'apcu_available' => $this->apcuAvailable,
            'storage_dir' => $this->storageDir,
            'configured_limits' => count($this->limits),
            'limits' => $this->limits,
        ];
    }
}
