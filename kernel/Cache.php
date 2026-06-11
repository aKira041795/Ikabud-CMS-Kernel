<?php
namespace Ikabud\Kernel;

/**
 * Kernel Cache Layer
 * 
 * File-based caching for the Ikabud Kernel System.
 * Used for template compilation cache, query results, and general-purpose caching.
 * 
 * Features:
 * - Atomic writes to prevent race conditions
 * - Compression for large cache entries (>1KB)
 * - APCu integration for faster reads (when available)
 * - LRU eviction when cache is full
 * - Tag-based invalidation
 * 
 * @version 2.0.0
 */
class Cache
{
    private string $cacheDir;
    private int $ttl = 1800; // 30 minutes default (reduced from 1 hour for fresher content)
    private array $stats = [
        'hits' => 0,
        'misses' => 0,
        'bypasses' => 0,
        'errors' => 0,
        'compressed' => 0
    ];
    
    /** @var int Compression threshold in bytes */
    private const COMPRESSION_THRESHOLD = 1024;
    
    /** @var int Maximum cache size in MB (0 = unlimited) */
    private int $maxCacheSizeMB = 0;
    
    /** @var bool Whether APCu is available */
    private static ?bool $apcuAvailable = null;
    
    /** @var string Stats file path */
    private string $statsFile;

    /** @var bool Skip persisting stats on shutdown after an explicit full reset */
    private bool $skipStatsPersist = false;

    /** @var bool Whether stats have been loaded for this request */
    private bool $statsLoaded = false;

    /** @var bool Whether routine cache invalidations should be logged */
    private bool $logInvalidations;
    
    public function __construct(string $cacheDir = null, int $maxCacheSizeMB = 0, bool $logInvalidations = false)
    {
        $this->cacheDir = $cacheDir ?? dirname(__DIR__) . '/storage/cache';
        $this->maxCacheSizeMB = $maxCacheSizeMB;
        $this->logInvalidations = $logInvalidations;
        $this->statsFile = $this->cacheDir . '/.cache_stats.json';
        
        // Ensure cache directory exists
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }
        
        // Check APCu availability once
        if (self::$apcuAvailable === null) {
            self::$apcuAvailable = function_exists('apcu_fetch') && apcu_enabled();
        }
        
        // Register shutdown handler to save stats at end of request
        register_shutdown_function([$this, 'saveStats']);
    }

    private function logInvalidationNotice(string $message): void
    {
        if (!$this->logInvalidations) {
            return;
        }

        error_log($message);
    }
    
    /**
     * Load persisted stats from file or APCu
     */
    private function loadStats(): void
    {
        // Try APCu first (faster)
        if (self::$apcuAvailable) {
            $stats = apcu_fetch('guidance_cache_stats', $success);
            if ($success && is_array($stats)) {
                $this->stats = array_merge($this->stats, $stats);
                return;
            }
        }
        
        // Fall back to file
        if (file_exists($this->statsFile)) {
            $data = @file_get_contents($this->statsFile);
            if ($data) {
                $stats = @json_decode($data, true);
                if (is_array($stats)) {
                    $this->stats = array_merge($this->stats, $stats);
                }
            }
        }

        $this->statsLoaded = true;
    }

    private function ensureStatsLoaded(): void
    {
        if ($this->statsLoaded) {
            return;
        }

        $this->loadStats();
    }
    
    /**
     * Persist stats to file and APCu
     * Called automatically on shutdown
     */
    public function saveStats(): void
    {
        if ($this->skipStatsPersist) {
            return;
        }

        if (!$this->statsLoaded) {
            return;
        }

        if (array_sum($this->stats) <= 0) {
            if (self::$apcuAvailable) {
                apcu_delete('guidance_cache_stats');
            }
            @unlink($this->statsFile);
            return;
        }

        // Save to APCu (fast, shared across requests)
        if (self::$apcuAvailable) {
            apcu_store('guidance_cache_stats', $this->stats, 86400); // 24 hours
        }
        
        // Also save to file (persistent across restarts)
        @file_put_contents($this->statsFile, json_encode($this->stats), LOCK_EX);
    }
    
    /**
     * Increment a stat counter
     * Stats are saved on shutdown via register_shutdown_function
     */
    private function incrementStat(string $key): void
    {
        $this->ensureStatsLoaded();
        $this->stats[$key]++;
    }
    
    /**
     * Get cache key for a URI (without instance - just the URI hash)
     */
    private function getCacheKey(string $uri): string
    {
        // Cache keys must be deterministic from the logical URI/key only.
        // Using ambient request globals (method/$_GET) breaks invalidation
        // when cache writes happen on GET and invalidation happens on POST/CLI.
        return md5($uri);
    }
    
    /**
     * Get instance cache directory (creates if needed)
     */
    private function getInstanceDir(string $instanceId): string
    {
        // Sanitize instance ID for filesystem safety
        $safeId = preg_replace('/[^a-zA-Z0-9_-]/', '_', $instanceId);
        $dir = $this->cacheDir . '/' . $safeId;
        
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        
        return $dir;
    }
    
    /**
     * Get cache file path for an instance and key
     */
    private function getCacheFile(string $instanceId, string $key): string
    {
        return $this->getInstanceDir($instanceId) . '/' . $key . '.cache';
    }
    
    /**
     * Check if cached response exists and is valid
     */
    public function has(string $instanceId, string $uri): bool
    {
        $key = $this->getCacheKey($uri);
        $file = $this->getCacheFile($instanceId, $key);
        
        if (!file_exists($file)) {
            return false;
        }
        
        // Check if cache is expired
        $age = time() - filemtime($file);
        if ($age > $this->ttl) {
            @unlink($file);
            return false;
        }
        
        return true;
    }
    
    /**
     * Get cached response
     * 
     * Uses multi-tier lookup: APCu (fastest) -> File cache
     */
    public function get(string $instanceId, string $uri): ?array
    {
        $key = $this->getCacheKey($uri);
        $apcuKey = $instanceId . '_' . $key;
        
        // Tier 1: Try APCu first (fastest)
        if (self::$apcuAvailable) {
            $cached = apcu_fetch('cache_' . $apcuKey, $success);
            if ($success && is_array($cached)) {
                $this->incrementStat('hits');
                return $cached;
            }
        }
        
        // Tier 2: Check file cache
        if (!$this->has($instanceId, $uri)) {
            $this->incrementStat('misses');
            return null;
        }
        
        try {
            $file = $this->getCacheFile($instanceId, $key);
            
            $data = file_get_contents($file);
            if (!$data) {
                // Corrupted cache file
                @unlink($file);
                $this->incrementStat('errors');
                return null;
            }
            
            // Check if data is compressed (starts with GZ:)
            if (strpos($data, 'GZ:') === 0) {
                $data = @gzuncompress(substr($data, 3));
                if ($data === false) {
                    @unlink($file);
                    $this->incrementStat('errors');
                    return null;
                }
                $this->incrementStat('compressed');
            }
            
            $result = @unserialize($data, ['allowed_classes' => false]);
            if ($result === false && $data !== serialize(false)) {
                // Unserialize failed - corrupted data
                @unlink($file);
                $this->incrementStat('errors');
                return null;
            }

            // Respect the per-entry expiry timestamp stamped at write time.
            // This corrects the bug where $this->ttl (1800 s) was used as the
            // expiry bound for all entries regardless of their written TTL.
            $expiresAt = isset($result['_cache_expires_at']) ? (int)$result['_cache_expires_at'] : 0;
            if ($expiresAt === 0) {
                // Legacy entry with no expiry stamp (written before this fix).
                // Evict it so it is re-written with the correct stamp on next set().
                @unlink($file);
                if (self::$apcuAvailable) {
                    apcu_delete('cache_' . $apcuKey);
                }
                $this->incrementStat('misses');
                return null;
            }
            if (time() >= $expiresAt) {
                // Entry has expired — remove stale file and return miss.
                @unlink($file);
                if (self::$apcuAvailable) {
                    apcu_delete('cache_' . $apcuKey);
                }
                $this->incrementStat('misses');
                return null;
            }

            // Promote to APCu using the remaining time from the stored expiry.
            if (self::$apcuAvailable) {
                $remainingTtl = max(1, $expiresAt - time());
                apcu_store('cache_' . $apcuKey, $result, $remainingTtl);
            }

            $this->incrementStat('hits');
            return $result;
        } catch (\Exception $e) {
            error_log("Cache read error: " . $e->getMessage());
            $this->incrementStat('errors');
            return null;
        }
    }
    
    /**
     * Store response in cache
     * 
     * Uses atomic writes and compression for reliability and performance.
     * Also stores in APCu for faster subsequent reads.
     * 
     * @param string $instanceId Instance identifier
     * @param string $uri Cache key/URI
     * @param array $response Data to cache
     * @param int|null $ttl Optional TTL override (uses default if null)
     */
    public function set(string $instanceId, string $uri, array $response, ?int $ttl = null): void
    {
        $key = $this->getCacheKey($uri);
        $file = $this->getCacheFile($instanceId, $key);
        $tempFile = $file . '.tmp.' . getmypid();
        $apcuKey = $instanceId . '_' . $key;
        $cacheTtl = $ttl ?? $this->ttl;
        
        try {
            // Check cache size limit before writing
            if ($this->maxCacheSizeMB > 0) {
                $this->enforceCacheLimit();
            }

            // Stamp expiry so all cache tiers honour the caller-specified TTL.
            // Without this, file entries are checked against $this->ttl (default
            // 1800 s) and APCu re-promotions use the same default — meaning an
            // entry written with ttl=20 can survive stale for 30 minutes.
            $response['_cache_expires_at'] = time() + $cacheTtl;

            $data = serialize($response);
            
            // Compress if data is large
            if (strlen($data) > self::COMPRESSION_THRESHOLD) {
                $compressed = @gzcompress($data, 6);
                if ($compressed !== false) {
                    $data = 'GZ:' . $compressed;
                }
            }
            
            // Atomic write: write to temp file, then rename
            $result = @file_put_contents($tempFile, $data, LOCK_EX);
            
            if ($result === false) {
                $this->incrementStat('errors');
                return;
            }
            
            // Atomic rename
            if (!@rename($tempFile, $file)) {
                @unlink($tempFile);
                $this->incrementStat('errors');
                return;
            }
            
            // Also store in APCu for faster reads
            if (self::$apcuAvailable) {
                apcu_store('cache_' . $apcuKey, $response, $cacheTtl);
            }
            
        } catch (\Exception $e) {
            @unlink($tempFile);
            $this->incrementStat('errors');
        }
    }
    
    /**
     * Enforce cache size limit using LRU eviction
     */
    private function enforceCacheLimit(): void
    {
        $maxBytes = $this->maxCacheSizeMB * 1024 * 1024;
        $files = $this->getAllCachedFiles();
        
        // Calculate current size
        $currentSize = array_sum(array_column($files, 'size'));
        
        if ($currentSize <= $maxBytes) {
            return;
        }
        
        // Sort by age (oldest first) for LRU eviction
        usort($files, fn($a, $b) => $b['age'] - $a['age']);
        
        // Delete oldest files until under limit
        foreach ($files as $fileInfo) {
            if ($currentSize <= $maxBytes * 0.9) { // Leave 10% headroom
                break;
            }
            
            if (@unlink($fileInfo['file'])) {
                $currentSize -= $fileInfo['size'];
            }
        }
    }
    
    /**
     * Store response in cache with tags for granular invalidation
     */
    public function setWithTags(string $instanceId, string $uri, array $response, array $tags = [], ?int $ttl = null): void
    {
        // Add tags to response metadata
        $response['cache_tags'] = $tags;
        $response['cache_uri'] = $uri;
        
        // Store the cache file
        $this->set($instanceId, $uri, $response, $ttl);
        
        // Create tag index files for quick lookup
        foreach ($tags as $tag) {
            $this->addToTagIndex($instanceId, $tag, $uri);
        }
    }

    /**
     * Read a tag index file, returning an empty list when the file is missing,
     * unreadable, or corrupted.
     */
    private function readTagIndex(string $tagFile): array
    {
        if (!is_file($tagFile) || !is_readable($tagFile)) {
            return [];
        }

        $content = @file_get_contents($tagFile);
        if ($content === false || $content === '') {
            return [];
        }

        $uris = @unserialize($content, ['allowed_classes' => false]);
        if (!is_array($uris)) {
            return [];
        }

        return array_values(array_filter($uris, static fn($uri): bool => is_string($uri) && $uri !== ''));
    }

    /**
     * Persist a tag index file atomically. When the target directory is not
     * writable, fail quietly so tag invalidation does not pollute error logs.
     */
    private function writeTagIndex(string $tagFile, array $uris): bool
    {
        $dir = dirname($tagFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        if (!is_dir($dir) || (!is_writable($dir) && (!file_exists($tagFile) || !is_writable($tagFile)))) {
            return false;
        }

        $payload = serialize(array_values(array_unique($uris)));
        $tempFile = $tagFile . '.tmp.' . getmypid();
        $result = @file_put_contents($tempFile, $payload, LOCK_EX);
        if ($result === false) {
            @unlink($tempFile);
            return false;
        }

        if (!@rename($tempFile, $tagFile)) {
            @unlink($tempFile);
            return false;
        }

        return true;
    }
    
    /**
     * Add URI to tag index for fast tag-based invalidation
     * Tag indexes are stored in the instance directory
     */
    private function addToTagIndex(string $instanceId, string $tag, string $uri): void
    {
        $instanceDir = $this->getInstanceDir($instanceId);
        $tagFile = $instanceDir . '/.tag_' . md5($tag) . '.idx';

        // Read existing URIs for this tag
        $uris = $this->readTagIndex($tagFile);
        
        // Add new URI if not already present
        if (!in_array($uri, $uris, true)) {
            $uris[] = $uri;
            $this->writeTagIndex($tagFile, $uris);
        }
    }
    
    /**
     * Clear cache by tag (e.g., 'post-123', 'category-5')
     */
    public function clearByTag(string $instanceId, string $tag): int
    {
        $cleared = 0;
        $instanceDir = $this->getInstanceDir($instanceId);
        $tagFile = $instanceDir . '/.tag_' . md5($tag) . '.idx';
        
        if (!file_exists($tagFile)) {
            return 0;
        }
        
        // Read URIs associated with this tag
        $uris = $this->readTagIndex($tagFile);
        
        // Clear each cached URI
        foreach ($uris as $uri) {
            $key = $this->getCacheKey($uri);
            $file = $this->getCacheFile($instanceId, $key);
            if (file_exists($file)) {
                @unlink($file);
                $cleared++;
            }
            // Also clear from APCu
            if (self::$apcuAvailable) {
                apcu_delete('cache_' . $instanceId . '_' . $key);
            }
        }
        
        // Remove tag index file
        @unlink($tagFile);
        
        $this->logInvalidationNotice("Ikabud Cache: Cleared $cleared files for tag '$tag' in instance $instanceId");
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
     * Clear all cache for a specific instance
     */
    public function clear(string $instanceId): int
    {
        $cleared = 0;
        $instanceDir = $this->getInstanceDir($instanceId);
        
        // Clear all cache files in instance directory
        $files = glob($instanceDir . '/*.cache');
        if ($files) {
            foreach ($files as $file) {
                if (@unlink($file)) {
                    $cleared++;
                }
            }
        }
        
        // Clear tag index files
        $tagFiles = glob($instanceDir . '/.tag_*.idx');
        if ($tagFiles) {
            foreach ($tagFiles as $file) {
                @unlink($file);
            }
        }
        
        // Clear APCu entries for this instance
        if (self::$apcuAvailable) {
            // APCu doesn't support prefix deletion, but we can iterate
            $info = apcu_cache_info();
            if (isset($info['cache_list'])) {
                foreach ($info['cache_list'] as $entry) {
                    if (isset($entry['info']) && str_starts_with($entry['info'], 'cache_' . $instanceId . '_')) {
                        apcu_delete($entry['info']);
                    }
                }
            }
        }
        
        $this->logInvalidationNotice("Ikabud Cache: Cleared $cleared files for instance $instanceId");
        return $cleared;
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
        $instanceDir = $this->getInstanceDir($instanceId);
        $files = glob($instanceDir . '/*.cache');
        
        if (!$files) {
            return 0;
        }
        
        // Convert pattern to regex
        $regex = $this->patternToRegex($urlPattern);
        
        foreach ($files as $file) {
            // Read cache file to get URI
            $data = @file_get_contents($file);
            if (!$data) continue;
            
            // Handle compressed data
            if (strpos($data, 'GZ:') === 0) {
                $data = @gzuncompress(substr($data, 3));
                if ($data === false) continue;
            }
            
            $cached = @unserialize($data, ['allowed_classes' => false]);
            if (!$cached || !isset($cached['cache_uri'])) continue;
            
            // Check if URI matches pattern
            if (preg_match($regex, $cached['cache_uri'])) {
                @unlink($file);
                $cleared++;
            }
        }
        
        $this->logInvalidationNotice("Ikabud Cache: Cleared $cleared files matching pattern '$urlPattern' in instance $instanceId");
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
        $key = $this->getCacheKey($uri);
        $file = $this->getCacheFile($instanceId, $key);
        if (file_exists($file)) {
            @unlink($file);
            $cleared++;
            // Also clear from APCu
            if (self::$apcuAvailable) {
                apcu_delete('cache_' . $instanceId . '_' . $key);
            }
        }
        
        // Clear dependent URIs
        foreach ($dependencies as $depUri) {
            $depKey = $this->getCacheKey($depUri);
            $depFile = $this->getCacheFile($instanceId, $depKey);
            if (file_exists($depFile)) {
                @unlink($depFile);
                $cleared++;
                if (self::$apcuAvailable) {
                    apcu_delete('cache_' . $instanceId . '_' . $depKey);
                }
            }
        }
        
        return $cleared;
    }
    
    /**
     * Clear all cache artifacts stored under the cache directory.
     */
    public function clearAll(): array
    {
        $cleared = 0;
        $errors = [];

        if (is_dir($this->cacheDir)) {
            $items = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->cacheDir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($items as $item) {
                $path = $item->getPathname();

                if ($item->isDir()) {
                    if (!$this->shouldPreserveCacheDirectory($path)) {
                        @rmdir($path);
                    }
                    continue;
                }

                if ($this->isPreservedCachePlaceholder($item->getBasename())) {
                    continue;
                }

                if (@unlink($path)) {
                    $cleared++;
                } else {
                    $errors[] = "Failed to delete: " . $path;
                }
            }

            $topLevelEntries = @scandir($this->cacheDir);
            if (is_array($topLevelEntries)) {
                foreach ($topLevelEntries as $entry) {
                    if ($entry === '.' || $entry === '..') {
                        continue;
                    }

                    $path = $this->cacheDir . '/' . $entry;
                    if (is_dir($path) && !$this->shouldPreserveCacheDirectory($path)) {
                        @rmdir($path);
                    }
                }
            }
        }

        // Clear APCu
        if (self::$apcuAvailable) {
            apcu_clear_cache();
        }

        // Reset stats
        $this->resetStats();

        $this->logInvalidationNotice(
            "Ikabud Cache: Cleared $cleared cache files"
            . (count($errors) > 0 ? " with " . count($errors) . " errors" : "")
        );

        return [
            'cleared' => $cleared,
            'errors' => $errors
        ];
    }

    private function isPreservedCachePlaceholder(string $filename): bool
    {
        return in_array($filename, ['.gitkeep', '.keep'], true);
    }

    private function shouldPreserveCacheDirectory(string $path): bool
    {
        $entries = @scandir($path);
        if (!is_array($entries)) {
            return false;
        }

        $hasKeepFile = false;
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            if (!$this->isPreservedCachePlaceholder($entry)) {
                return false;
            }

            $hasKeepFile = true;
        }

        return $hasKeepFile;
    }
    
    /**
     * Reset cache statistics
     */
    public function resetStats(): void
    {
        $this->skipStatsPersist = true;
        $this->statsLoaded = true;
        $this->stats = [
            'hits' => 0,
            'misses' => 0,
            'bypasses' => 0,
            'errors' => 0,
            'compressed' => 0
        ];
        
        // Clear persisted stats
        if (self::$apcuAvailable) {
            apcu_delete('guidance_cache_stats');
        }
        @unlink($this->statsFile);
    }
    
    /**
     * Check if request should be cached
     */
    public function shouldCache(string $uri): bool
    {
        // Only cache GET requests
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            $this->incrementStat('bypasses');
            return false;
        }
        
        // Don't cache API endpoints, auth pages, or installer
        $skipPaths = ['/api/', '/login', '/lock.php', '/logout'];
        foreach ($skipPaths as $path) {
            if (str_contains($uri, $path)) {
                $this->incrementStat('bypasses');
                return false;
            }
        }
        
        // Don't cache if user is authenticated (dynamic content)
        $cookieName = trim((string)($_ENV['APP_COOKIE_NAME'] ?? ''));
        if ($cookieName === '') {
            $appUrl = (string)($_ENV['APP_URL'] ?? '');
            $cookieHost = (string)(parse_url($appUrl, PHP_URL_HOST) ?? '');
            $cookieHost = strtolower($cookieHost);
            $cookieHost = preg_replace('/[^a-z0-9]+/', '_', $cookieHost) ?? '';
            $cookieHost = trim($cookieHost, '_');
            $cookieName = ($cookieHost !== '' ? $cookieHost : 'app') . '_token';
        }
        if (!empty($_COOKIE[$cookieName])) {
            $this->incrementStat('bypasses');
            return false;
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
     * Get all cached files with metadata (across all instances)
     */
    private function getAllCachedFiles(): array
    {
        $files = [];
        
        // Get files from all instance directories
        $dirs = glob($this->cacheDir . '/*', GLOB_ONLYDIR);
        if ($dirs) {
            foreach ($dirs as $dir) {
                $cacheFiles = glob($dir . '/*.cache');
                if ($cacheFiles) {
                    foreach ($cacheFiles as $file) {
                        $files[] = [
                            'file' => $file,
                            'size' => filesize($file),
                            'age' => time() - filemtime($file),
                            'expired' => (time() - filemtime($file)) > $this->ttl,
                            'instance' => basename($dir)
                        ];
                    }
                }
            }
        }
        
        // Also check for legacy flat files
        $legacyFiles = glob($this->cacheDir . '/*.cache');
        if ($legacyFiles) {
            foreach ($legacyFiles as $file) {
                $files[] = [
                    'file' => $file,
                    'size' => filesize($file),
                    'age' => time() - filemtime($file),
                    'expired' => (time() - filemtime($file)) > $this->ttl,
                    'instance' => '_legacy'
                ];
            }
        }
        
        return $files;
    }
    
    /**
     * Get cache statistics (scans actual cache files)
     */
    public function getStats(): array
    {
        $this->ensureStatsLoaded();
        $files = $this->getAllCachedFiles();
        $totalFiles = count($files);
        $totalSize = array_sum(array_column($files, 'size'));
        $expiredFiles = count(array_filter($files, fn($f) => $f['expired']));
        $activeFiles = $totalFiles - $expiredFiles;
        
        // Calculate hit rate from in-memory stats
        $total = $this->stats['hits'] + $this->stats['misses'] + $this->stats['bypasses'];
        $hitRate = $total > 0 ? round(($this->stats['hits'] / $total) * 100, 2) : 0;
        
        $stats = [
            'hits' => $this->stats['hits'],
            'misses' => $this->stats['misses'],
            'bypasses' => $this->stats['bypasses'],
            'errors' => $this->stats['errors'],
            'compressed_reads' => $this->stats['compressed'],
            'total_requests' => $total,
            'hit_rate' => $hitRate . '%',
            'cached_files' => $totalFiles,
            'active_files' => $activeFiles,
            'expired_files' => $expiredFiles,
            'total_size_bytes' => $totalSize,
            'total_size_mb' => round($totalSize / 1024 / 1024, 2),
            'max_size_mb' => $this->maxCacheSizeMB,
            'apcu_available' => self::$apcuAvailable ?? false,
        ];
        
        // Add APCu stats if available
        if (self::$apcuAvailable) {
            $apcuInfo = apcu_cache_info(true);
            $stats['apcu_entries'] = $apcuInfo['num_entries'] ?? 0;
            $stats['apcu_memory_bytes'] = $apcuInfo['mem_size'] ?? 0;
        }
        
        return $stats;
    }
    
    /**
     * Get cache size for instance
     */
    public function getSize(string $instanceId): array
    {
        $instanceDir = $this->getInstanceDir($instanceId);
        $files = glob($instanceDir . '/*.cache');
        $totalSize = 0;
        $fileCount = $files ? count($files) : 0;
        
        if ($files) {
            foreach ($files as $file) {
                $totalSize += filesize($file);
            }
        }
        
        return [
            'files' => $fileCount,
            'size_bytes' => $totalSize,
            'size_mb' => round($totalSize / 1024 / 1024, 2)
        ];
    }
    
    /**
     * List all cached instances
     */
    public function listInstances(): array
    {
        $instances = [];
        $dirs = glob($this->cacheDir . '/*', GLOB_ONLYDIR);
        
        if ($dirs) {
            foreach ($dirs as $dir) {
                $instanceId = basename($dir);
                $instances[$instanceId] = $this->getSize($instanceId);
            }
        }
        
        return $instances;
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
