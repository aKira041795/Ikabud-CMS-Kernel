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
    private int $ttl = 1800; // 30 minutes default (reduced from 1 hour for fresher content)
    private array $stats = [
        'hits' => 0,
        'misses' => 0,
        'bypasses' => 0,
        'errors' => 0
    ];
    
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
        $key = $instanceId . '_' . $uri;
        
        // Include query parameters for GET requests
        if ($_SERVER['REQUEST_METHOD'] === 'GET' && !empty($_GET)) {
            $key .= '_' . md5(http_build_query($_GET));
        }
        
        return md5($key);
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
            $this->stats['misses']++;
            return null;
        }
        
        try {
            $key = $this->getCacheKey($instanceId, $uri);
            $file = $this->getCacheFile($key);
            
            $data = file_get_contents($file);
            if (!$data) {
                // Corrupted cache file
                unlink($file);
                $this->stats['errors']++;
                return null;
            }
            
            $result = @unserialize($data);
            if ($result === false && $data !== serialize(false)) {
                // Unserialize failed - corrupted data
                unlink($file);
                $this->stats['errors']++;
                return null;
            }
            
            $this->stats['hits']++;
            return $result;
        } catch (\Exception $e) {
            error_log("Cache read error: " . $e->getMessage());
            $this->stats['errors']++;
            return null;
        }
    }
    
    /**
     * Store response in cache
     */
    public function set(string $instanceId, string $uri, array $response): void
    {
        try {
            $key = $this->getCacheKey($instanceId, $uri);
            $file = $this->getCacheFile($key);
            
            $data = serialize($response);
            $result = file_put_contents($file, $data, LOCK_EX);
            
            if ($result === false) {
                error_log("Cache write error: Failed to write to $file");
                $this->stats['errors']++;
            }
        } catch (\Exception $e) {
            error_log("Cache write error: " . $e->getMessage());
            $this->stats['errors']++;
        }
    }
    
    /**
     * Store response in cache with tags for granular invalidation
     */
    public function setWithTags(string $instanceId, string $uri, array $response, array $tags = []): void
    {
        // Add tags to response metadata
        $response['cache_tags'] = $tags;
        $response['cache_uri'] = $uri;
        
        // Store the cache file
        $this->set($instanceId, $uri, $response);
        
        // Create tag index files for quick lookup
        foreach ($tags as $tag) {
            $this->addToTagIndex($instanceId, $tag, $uri);
        }
    }
    
    /**
     * Add URI to tag index for fast tag-based invalidation
     */
    private function addToTagIndex(string $instanceId, string $tag, string $uri): void
    {
        $tagFile = $this->cacheDir . '/.tags_' . md5($instanceId . '_' . $tag) . '.idx';
        
        // Read existing URIs for this tag
        $uris = [];
        if (file_exists($tagFile)) {
            $content = file_get_contents($tagFile);
            $uris = $content ? unserialize($content) : [];
        }
        
        // Add new URI if not already present
        if (!in_array($uri, $uris)) {
            $uris[] = $uri;
            file_put_contents($tagFile, serialize($uris), LOCK_EX);
        }
    }
    
    /**
     * Clear cache by tag (e.g., 'post-123', 'category-5')
     */
    public function clearByTag(string $instanceId, string $tag): int
    {
        $cleared = 0;
        $tagFile = $this->cacheDir . '/.tags_' . md5($instanceId . '_' . $tag) . '.idx';
        
        if (!file_exists($tagFile)) {
            return 0;
        }
        
        // Read URIs associated with this tag
        $content = file_get_contents($tagFile);
        $uris = $content ? unserialize($content) : [];
        
        // Clear each cached URI
        foreach ($uris as $uri) {
            $key = $this->getCacheKey($instanceId, $uri);
            $file = $this->getCacheFile($key);
            if (file_exists($file)) {
                @unlink($file);
                $cleared++;
            }
        }
        
        // Remove tag index file
        @unlink($tagFile);
        
        error_log("Ikabud Cache: Cleared $cleared files for tag '$tag' in instance $instanceId");
        return $cleared;
    }
    
    /**
     * Clear cache by multiple tags
     */
    public function clearByTags(string $instanceId, array $tags): int
    {
        $totalCleared = 0;
        foreach ($tags as $tag) {
            $totalCleared += $this->clearByTag($instanceId, $tag);
        }
        return $totalCleared;
    }
    
    /**
     * Clear cache for instance
     */
    public function clear(string $instanceId): void
    {
        $pattern = $this->cacheDir . '/' . $instanceId . '_*.cache';
        foreach (glob($pattern) as $file) {
            @unlink($file);
        }
    }
    
    /**
     * Clear cache by pattern (alias for clearByUrlPattern for API compatibility)
     */
    public function clearByPattern(string $instanceId, string $pattern): int
    {
        return $this->clearByUrlPattern($instanceId, $pattern);
    }
    
    /**
     * Clear cache by URL pattern (e.g., '/blog/*', '/category/*')
     */
    public function clearByUrlPattern(string $instanceId, string $urlPattern): int
    {
        $cleared = 0;
        $files = glob($this->cacheDir . '/*.cache');
        
        // Convert pattern to regex
        $regex = $this->patternToRegex($urlPattern);
        
        foreach ($files as $file) {
            // Read cache file to get URI
            $data = @file_get_contents($file);
            if (!$data) continue;
            
            $cached = @unserialize($data);
            if (!$cached || !isset($cached['cache_uri'])) continue;
            
            // Check if URI matches pattern
            if (preg_match($regex, $cached['cache_uri'])) {
                @unlink($file);
                $cleared++;
            }
        }
        
        error_log("Ikabud Cache: Cleared $cleared files matching pattern '$urlPattern' in instance $instanceId");
        return $cleared;
    }
    
    /**
     * Convert URL pattern to regex
     */
    private function patternToRegex(string $pattern): string
    {
        // Escape special regex characters except *
        $pattern = preg_quote($pattern, '/');
        // Convert * to .*
        $pattern = str_replace('\*', '.*', $pattern);
        return '/^' . $pattern . '$/';
    }
    
    /**
     * Clear cache with dependencies (e.g., clear homepage when post updates)
     */
    public function clearWithDependencies(string $instanceId, string $uri, array $dependencies = []): int
    {
        $cleared = 0;
        
        // Clear the main URI
        $key = $this->getCacheKey($instanceId, $uri);
        $file = $this->getCacheFile($key);
        if (file_exists($file)) {
            @unlink($file);
            $cleared++;
        }
        
        // Clear dependent URIs
        foreach ($dependencies as $depUri) {
            $depKey = $this->getCacheKey($instanceId, $depUri);
            $depFile = $this->getCacheFile($depKey);
            if (file_exists($depFile)) {
                @unlink($depFile);
                $cleared++;
            }
        }
        
        return $cleared;
    }
    
    /**
     * Clear all cache (including tag indexes)
     */
    public function clearAll(): array
    {
        $cleared = 0;
        $errors = [];
        
        // Clear cache files
        $cachePattern = $this->cacheDir . '/*.cache';
        $cacheFiles = glob($cachePattern);
        
        if ($cacheFiles === false) {
            $errors[] = 'Failed to read cache directory';
        } else {
            foreach ($cacheFiles as $file) {
                if (@unlink($file)) {
                    $cleared++;
                } else {
                    $errors[] = "Failed to delete: " . basename($file);
                }
            }
        }
        
        // Clear tag index files
        $tagPattern = $this->cacheDir . '/.tags_*.idx';
        $tagFiles = glob($tagPattern);
        
        if ($tagFiles !== false) {
            foreach ($tagFiles as $file) {
                @unlink($file);
            }
        }
        
        error_log("Ikabud Cache: Cleared $cleared cache files" . 
                  (count($errors) > 0 ? " with " . count($errors) . " errors" : ""));
        
        return [
            'cleared' => $cleared,
            'errors' => $errors
        ];
    }
    
    /**
     * Check if request should be cached
     */
    public function shouldCache(string $uri): bool
    {
        // Don't cache admin, login, installation, or POST requests
        if (
            str_contains($uri, '/wp-admin') ||
            str_contains($uri, '/wp-login') ||
            str_contains($uri, '/administrator') ||  // Joomla admin
            str_contains($uri, '/installation') ||   // Joomla/Drupal installation
            str_contains($uri, 'preview=') ||
            $_SERVER['REQUEST_METHOD'] !== 'GET'
        ) {
            $this->stats['bypasses']++;
            return false;
        }
        
        // Don't cache if user is logged in (check CMS cookies)
        foreach ($_COOKIE as $name => $value) {
            if (str_starts_with($name, 'wordpress_logged_in_') ||  // WordPress login
                str_starts_with($name, 'wordpress_sec_') ||        // WordPress security
                str_starts_with($name, 'wp-') ||                    // WordPress general
                str_starts_with($name, 'joomla_') ||                // Joomla
                str_starts_with($name, 'SESS') ||                   // Drupal 7
                str_starts_with($name, 'SSESS') ||                  // Drupal 8/9/10/11
                str_starts_with($name, 'PHPSESSID')) {              // Generic PHP session
                $this->stats['bypasses']++;
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
    
    /**
     * Get all cached files with metadata
     */
    private function getAllCachedFiles(): array
    {
        $files = [];
        $cachePattern = $this->cacheDir . '/*.cache';
        $cacheFiles = glob($cachePattern);
        
        if ($cacheFiles === false) {
            return [];
        }
        
        foreach ($cacheFiles as $file) {
            $files[] = [
                'file' => $file,
                'size' => filesize($file),
                'age' => time() - filemtime($file),
                'expired' => (time() - filemtime($file)) > $this->ttl
            ];
        }
        
        return $files;
    }
    
    /**
     * Get cache statistics (scans actual cache files)
     */
    public function getStats(): array
    {
        $files = $this->getAllCachedFiles();
        $totalFiles = count($files);
        $totalSize = array_sum(array_column($files, 'size'));
        $expiredFiles = count(array_filter($files, fn($f) => $f['expired']));
        $activeFiles = $totalFiles - $expiredFiles;
        
        // Calculate hit rate from in-memory stats
        $total = $this->stats['hits'] + $this->stats['misses'] + $this->stats['bypasses'];
        $hitRate = $total > 0 ? round(($this->stats['hits'] / $total) * 100, 2) : 0;
        
        return [
            'hits' => $this->stats['hits'],
            'misses' => $this->stats['misses'],
            'bypasses' => $this->stats['bypasses'],
            'errors' => $this->stats['errors'],
            'total_requests' => $total,
            'hit_rate' => $hitRate . '%',
            'cached_files' => $totalFiles,
            'active_files' => $activeFiles,
            'expired_files' => $expiredFiles,
            'total_size_bytes' => $totalSize,
            'total_size_mb' => round($totalSize / 1024 / 1024, 2)
        ];
    }
    
    /**
     * Get cache size for instance
     */
    public function getSize(string $instanceId): array
    {
        $pattern = $this->cacheDir . '/' . $instanceId . '_*.cache';
        $files = glob($pattern);
        $totalSize = 0;
        $fileCount = count($files);
        
        foreach ($files as $file) {
            $totalSize += filesize($file);
        }
        
        return [
            'files' => $fileCount,
            'size_bytes' => $totalSize,
            'size_mb' => round($totalSize / 1024 / 1024, 2)
        ];
    }
    
    /**
     * Warm cache by pre-generating pages
     */
    public function warm(string $instanceId, array $urls): array
    {
        $results = [];
        foreach ($urls as $url) {
            if (!$this->has($instanceId, $url)) {
                $results[$url] = 'pending';
            } else {
                $results[$url] = 'cached';
            }
        }
        return $results;
    }
}
