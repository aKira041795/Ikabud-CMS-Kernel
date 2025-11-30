<?php
/**
 * DiSyL Rate Limiter
 * 
 * Limits cross-instance query frequency to prevent abuse.
 * Uses file-based or APCu storage for rate tracking.
 * 
 * @package IkabudKernel\Core\DiSyL\Security
 * @version 0.6.0
 */

namespace IkabudKernel\Core\DiSyL\Security;

class RateLimiter
{
    /** @var int Queries allowed per window */
    private static int $maxQueries = 60;
    
    /** @var int Time window in seconds */
    private static int $windowSeconds = 60;
    
    /** @var int Maximum results per query */
    private static int $maxResultsPerQuery = 100;
    
    /** @var bool Whether rate limiting is enabled */
    private static bool $enabled = true;
    
    /** @var string Storage path for file-based limiting */
    private static ?string $storagePath = null;
    
    /** @var bool Whether APCu is available */
    private static ?bool $apcuAvailable = null;
    
    /**
     * Initialize the rate limiter
     * 
     * @param array $options Configuration options
     */
    public static function init(array $options = []): void
    {
        if (isset($options['max_queries'])) {
            self::$maxQueries = (int)$options['max_queries'];
        }
        if (isset($options['window_seconds'])) {
            self::$windowSeconds = (int)$options['window_seconds'];
        }
        if (isset($options['max_results_per_query'])) {
            self::$maxResultsPerQuery = (int)$options['max_results_per_query'];
        }
        if (isset($options['enabled'])) {
            self::$enabled = (bool)$options['enabled'];
        }
        if (isset($options['storage_path'])) {
            self::$storagePath = $options['storage_path'];
        }
        
        // Check APCu availability
        if (self::$apcuAvailable === null) {
            self::$apcuAvailable = function_exists('apcu_fetch') && apcu_enabled();
        }
        
        // Set default storage path
        if (self::$storagePath === null) {
            self::$storagePath = dirname(__DIR__, 3) . '/storage/rate-limits';
        }
    }
    
    /**
     * Enable or disable rate limiting
     * 
     * @param bool $enabled
     */
    public static function setEnabled(bool $enabled): void
    {
        self::$enabled = $enabled;
    }
    
    /**
     * Set rate limit parameters
     * 
     * @param int $maxQueries Maximum queries per window
     * @param int $windowSeconds Time window in seconds
     */
    public static function setLimits(int $maxQueries, int $windowSeconds = 60): void
    {
        self::$maxQueries = $maxQueries;
        self::$windowSeconds = $windowSeconds;
    }
    
    /**
     * Set maximum results per query
     * 
     * @param int $max
     */
    public static function setMaxResultsPerQuery(int $max): void
    {
        self::$maxResultsPerQuery = $max;
    }
    
    /**
     * Check if a query is allowed and record it
     * 
     * @param string $sourceInstance Instance making the query
     * @param string $targetInstance Instance being queried
     * @return RateLimitResult
     */
    public static function check(string $sourceInstance, string $targetInstance): RateLimitResult
    {
        if (!self::$enabled) {
            return new RateLimitResult(true, self::$maxQueries, self::$maxQueries);
        }
        
        $key = self::getKey($sourceInstance, $targetInstance);
        $now = time();
        $windowStart = $now - self::$windowSeconds;
        
        // Get current count
        $data = self::getData($key);
        
        // Clean old entries
        $data = array_filter($data, fn($timestamp) => $timestamp > $windowStart);
        
        $currentCount = count($data);
        $remaining = max(0, self::$maxQueries - $currentCount);
        
        if ($currentCount >= self::$maxQueries) {
            // Find when the oldest entry expires
            $oldestTimestamp = min($data);
            $retryAfter = ($oldestTimestamp + self::$windowSeconds) - $now;
            
            return new RateLimitResult(
                false,
                self::$maxQueries,
                0,
                $retryAfter,
                "Rate limit exceeded. Try again in {$retryAfter} seconds."
            );
        }
        
        // Record this query
        $data[] = $now;
        self::setData($key, $data);
        
        return new RateLimitResult(true, self::$maxQueries, $remaining - 1);
    }
    
    /**
     * Get rate limit status without recording a query
     * 
     * @param string $sourceInstance
     * @param string $targetInstance
     * @return RateLimitResult
     */
    public static function status(string $sourceInstance, string $targetInstance): RateLimitResult
    {
        if (!self::$enabled) {
            return new RateLimitResult(true, self::$maxQueries, self::$maxQueries);
        }
        
        $key = self::getKey($sourceInstance, $targetInstance);
        $now = time();
        $windowStart = $now - self::$windowSeconds;
        
        $data = self::getData($key);
        $data = array_filter($data, fn($timestamp) => $timestamp > $windowStart);
        
        $currentCount = count($data);
        $remaining = max(0, self::$maxQueries - $currentCount);
        
        return new RateLimitResult(
            $currentCount < self::$maxQueries,
            self::$maxQueries,
            $remaining
        );
    }
    
    /**
     * Enforce result limit
     * 
     * @param int $requestedLimit
     * @return int Adjusted limit
     */
    public static function enforceResultLimit(int $requestedLimit): int
    {
        return min($requestedLimit, self::$maxResultsPerQuery);
    }
    
    /**
     * Get maximum results per query
     * 
     * @return int
     */
    public static function getMaxResultsPerQuery(): int
    {
        return self::$maxResultsPerQuery;
    }
    
    /**
     * Reset rate limit for a source-target pair
     * 
     * @param string $sourceInstance
     * @param string $targetInstance
     */
    public static function reset(string $sourceInstance, string $targetInstance): void
    {
        $key = self::getKey($sourceInstance, $targetInstance);
        self::deleteData($key);
    }
    
    /**
     * Reset all rate limits
     */
    public static function resetAll(): void
    {
        if (self::$apcuAvailable) {
            // Clear all APCu keys with our prefix
            $iterator = new \APCUIterator('/^disyl_ratelimit_/');
            foreach ($iterator as $item) {
                apcu_delete($item['key']);
            }
        } else {
            // Clear file-based storage
            if (self::$storagePath && is_dir(self::$storagePath)) {
                $files = glob(self::$storagePath . '/*.json');
                foreach ($files as $file) {
                    @unlink($file);
                }
            }
        }
    }
    
    /**
     * Generate storage key
     * 
     * @param string $sourceInstance
     * @param string $targetInstance
     * @return string
     */
    private static function getKey(string $sourceInstance, string $targetInstance): string
    {
        return 'disyl_ratelimit_' . md5($sourceInstance . ':' . $targetInstance);
    }
    
    /**
     * Get rate limit data
     * 
     * @param string $key
     * @return array
     */
    private static function getData(string $key): array
    {
        if (self::$apcuAvailable) {
            $data = apcu_fetch($key);
            return $data !== false ? $data : [];
        }
        
        // File-based storage
        $file = self::getFilePath($key);
        if (file_exists($file)) {
            $content = file_get_contents($file);
            $data = json_decode($content, true);
            return is_array($data) ? $data : [];
        }
        
        return [];
    }
    
    /**
     * Set rate limit data
     * 
     * @param string $key
     * @param array $data
     */
    private static function setData(string $key, array $data): void
    {
        if (self::$apcuAvailable) {
            apcu_store($key, $data, self::$windowSeconds * 2);
            return;
        }
        
        // File-based storage
        $file = self::getFilePath($key);
        $dir = dirname($file);
        
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        
        file_put_contents($file, json_encode($data));
    }
    
    /**
     * Delete rate limit data
     * 
     * @param string $key
     */
    private static function deleteData(string $key): void
    {
        if (self::$apcuAvailable) {
            apcu_delete($key);
            return;
        }
        
        $file = self::getFilePath($key);
        if (file_exists($file)) {
            @unlink($file);
        }
    }
    
    /**
     * Get file path for key
     * 
     * @param string $key
     * @return string
     */
    private static function getFilePath(string $key): string
    {
        return self::$storagePath . '/' . $key . '.json';
    }
    
    /**
     * Clean up expired rate limit files
     */
    public static function cleanup(): void
    {
        if (self::$apcuAvailable) {
            return; // APCu handles expiration automatically
        }
        
        if (!self::$storagePath || !is_dir(self::$storagePath)) {
            return;
        }
        
        $files = glob(self::$storagePath . '/*.json');
        $now = time();
        $windowStart = $now - self::$windowSeconds;
        
        foreach ($files as $file) {
            $content = file_get_contents($file);
            $data = json_decode($content, true);
            
            if (!is_array($data)) {
                @unlink($file);
                continue;
            }
            
            // Remove entries older than window
            $data = array_filter($data, fn($timestamp) => $timestamp > $windowStart);
            
            if (empty($data)) {
                @unlink($file);
            } else {
                file_put_contents($file, json_encode($data));
            }
        }
    }
}

/**
 * Rate limit check result
 */
class RateLimitResult
{
    public bool $allowed;
    public int $limit;
    public int $remaining;
    public int $retryAfter;
    public ?string $message;
    
    public function __construct(
        bool $allowed,
        int $limit,
        int $remaining,
        int $retryAfter = 0,
        ?string $message = null
    ) {
        $this->allowed = $allowed;
        $this->limit = $limit;
        $this->remaining = $remaining;
        $this->retryAfter = $retryAfter;
        $this->message = $message;
    }
    
    public function isAllowed(): bool
    {
        return $this->allowed;
    }
    
    public function getLimit(): int
    {
        return $this->limit;
    }
    
    public function getRemaining(): int
    {
        return $this->remaining;
    }
    
    public function getRetryAfter(): int
    {
        return $this->retryAfter;
    }
    
    public function getMessage(): ?string
    {
        return $this->message;
    }
    
    /**
     * Get headers for rate limit response
     * 
     * @return array
     */
    public function getHeaders(): array
    {
        $headers = [
            'X-RateLimit-Limit' => $this->limit,
            'X-RateLimit-Remaining' => $this->remaining,
        ];
        
        if (!$this->allowed && $this->retryAfter > 0) {
            $headers['Retry-After'] = $this->retryAfter;
        }
        
        return $headers;
    }
}
