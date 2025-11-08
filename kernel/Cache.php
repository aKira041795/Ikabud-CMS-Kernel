<?php
namespace IkabudKernel\Core;

/**
 * Kernel Cache Layer
 * 
 * Caches rendered pages to avoid loading WordPress for repeat requests.
 * Uses file-based caching for simplicity and shared hosting compatibility.
 */
class Cache
{
    private string $cacheDir;
    private int $ttl = 3600; // 1 hour default
    
    public function __construct(string $cacheDir = null)
    {
        $this->cacheDir = $cacheDir ?? dirname(__DIR__) . '/storage/cache';
        
        // Ensure cache directory exists
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }
    }
    
    /**
     * Get cache key for current request
     */
    private function getCacheKey(string $instanceId, string $uri): string
    {
        // Include query string in cache key
        $key = $instanceId . '_' . md5($uri);
        return $key;
    }
    
    /**
     * Get cache file path
     */
    private function getCacheFile(string $key): string
    {
        return $this->cacheDir . '/' . $key . '.cache';
    }
    
    /**
     * Check if cached response exists and is valid
     */
    public function has(string $instanceId, string $uri): bool
    {
        $key = $this->getCacheKey($instanceId, $uri);
        $file = $this->getCacheFile($key);
        
        if (!file_exists($file)) {
            return false;
        }
        
        // Check if cache is expired
        $age = time() - filemtime($file);
        if ($age > $this->ttl) {
            unlink($file);
            return false;
        }
        
        return true;
    }
    
    /**
     * Get cached response
     */
    public function get(string $instanceId, string $uri): ?array
    {
        if (!$this->has($instanceId, $uri)) {
            return null;
        }
        
        $key = $this->getCacheKey($instanceId, $uri);
        $file = $this->getCacheFile($key);
        
        $data = file_get_contents($file);
        return unserialize($data);
    }
    
    /**
     * Store response in cache
     */
    public function set(string $instanceId, string $uri, array $response): void
    {
        $key = $this->getCacheKey($instanceId, $uri);
        $file = $this->getCacheFile($key);
        
        $data = serialize($response);
        file_put_contents($file, $data, LOCK_EX);
    }
    
    /**
     * Clear cache for instance
     */
    public function clear(string $instanceId): void
    {
        $pattern = $this->cacheDir . '/' . $instanceId . '_*.cache';
        foreach (glob($pattern) as $file) {
            unlink($file);
        }
    }
    
    /**
     * Clear all cache
     */
    public function clearAll(): void
    {
        $pattern = $this->cacheDir . '/*.cache';
        foreach (glob($pattern) as $file) {
            unlink($file);
        }
    }
    
    /**
     * Check if request should be cached
     */
    public function shouldCache(string $uri): bool
    {
        // Don't cache admin, login, or POST requests
        if (
            str_contains($uri, '/wp-admin') ||
            str_contains($uri, '/wp-login') ||
            str_contains($uri, 'preview=') ||
            $_SERVER['REQUEST_METHOD'] !== 'GET'
        ) {
            return false;
        }
        
        // Don't cache if user is logged in (check WordPress cookies)
        foreach ($_COOKIE as $name => $value) {
            if (str_starts_with($name, 'wordpress_logged_in_')) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Set cache TTL
     */
    public function setTTL(int $seconds): void
    {
        $this->ttl = $seconds;
    }
}
