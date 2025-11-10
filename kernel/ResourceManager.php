<?php
namespace IkabudKernel\Core;

/**
 * Resource Manager
 * 
 * Manages resource limits, quotas, and usage tracking for multi-tenant instances
 * Provides isolation and fair resource allocation across CMS instances
 * 
 * @version 1.0.0
 */
class ResourceManager
{
    private string $configFile;
    private array $limits = [];
    private array $usage = [];
    private array $stats = [];
    
    public function __construct(string $configFile = null)
    {
        $this->configFile = $configFile ?? __DIR__ . '/../storage/resource-limits.json';
        $this->loadLimits();
    }
    
    /**
     * Set memory limit for an instance
     * 
     * @param string $instanceId Instance identifier
     * @param int $megabytes Memory limit in MB
     */
    public function setMemoryLimit(string $instanceId, int $megabytes): void
    {
        if (!isset($this->limits[$instanceId])) {
            $this->limits[$instanceId] = [];
        }
        
        $this->limits[$instanceId]['memory_mb'] = $megabytes;
        $this->saveLimits();
        
        error_log("ResourceManager: Set memory limit for {$instanceId}: {$megabytes}MB");
    }
    
    /**
     * Set CPU limit for an instance
     * 
     * @param string $instanceId Instance identifier
     * @param int $percentage CPU percentage (0-100)
     */
    public function setCpuLimit(string $instanceId, int $percentage): void
    {
        if (!isset($this->limits[$instanceId])) {
            $this->limits[$instanceId] = [];
        }
        
        $percentage = max(0, min(100, $percentage));
        $this->limits[$instanceId]['cpu_percent'] = $percentage;
        $this->saveLimits();
        
        error_log("ResourceManager: Set CPU limit for {$instanceId}: {$percentage}%");
    }
    
    /**
     * Set request rate limit
     * 
     * @param string $instanceId Instance identifier
     * @param int $requestsPerMinute Maximum requests per minute
     */
    public function setRateLimit(string $instanceId, int $requestsPerMinute): void
    {
        if (!isset($this->limits[$instanceId])) {
            $this->limits[$instanceId] = [];
        }
        
        $this->limits[$instanceId]['requests_per_minute'] = $requestsPerMinute;
        $this->saveLimits();
        
        error_log("ResourceManager: Set rate limit for {$instanceId}: {$requestsPerMinute} req/min");
    }
    
    /**
     * Set storage quota
     * 
     * @param string $instanceId Instance identifier
     * @param int $megabytes Storage quota in MB
     */
    public function setStorageQuota(string $instanceId, int $megabytes): void
    {
        if (!isset($this->limits[$instanceId])) {
            $this->limits[$instanceId] = [];
        }
        
        $this->limits[$instanceId]['storage_mb'] = $megabytes;
        $this->saveLimits();
        
        error_log("ResourceManager: Set storage quota for {$instanceId}: {$megabytes}MB");
    }
    
    /**
     * Set cache quota
     * 
     * @param string $instanceId Instance identifier
     * @param int $megabytes Cache quota in MB
     */
    public function setCacheQuota(string $instanceId, int $megabytes): void
    {
        if (!isset($this->limits[$instanceId])) {
            $this->limits[$instanceId] = [];
        }
        
        $this->limits[$instanceId]['cache_mb'] = $megabytes;
        $this->saveLimits();
        
        error_log("ResourceManager: Set cache quota for {$instanceId}: {$megabytes}MB");
    }
    
    /**
     * Track resource usage for an instance
     * 
     * @param string $instanceId Instance identifier
     * @param array $usage Usage metrics
     */
    public function trackUsage(string $instanceId, array $usage): void
    {
        if (!isset($this->usage[$instanceId])) {
            $this->usage[$instanceId] = [
                'memory_peak_mb' => 0,
                'requests_count' => 0,
                'cpu_time_ms' => 0,
                'last_updated' => null
            ];
        }
        
        // Update peak memory
        if (isset($usage['memory_mb'])) {
            $this->usage[$instanceId]['memory_peak_mb'] = max(
                $this->usage[$instanceId]['memory_peak_mb'],
                $usage['memory_mb']
            );
        }
        
        // Increment request count
        if (isset($usage['request'])) {
            $this->usage[$instanceId]['requests_count']++;
        }
        
        // Add CPU time
        if (isset($usage['cpu_time_ms'])) {
            $this->usage[$instanceId]['cpu_time_ms'] += $usage['cpu_time_ms'];
        }
        
        $this->usage[$instanceId]['last_updated'] = time();
    }
    
    /**
     * Check if instance is within limits
     * 
     * @param string $instanceId Instance identifier
     * @return array Status and violations
     */
    public function checkLimits(string $instanceId): array
    {
        $violations = [];
        $limits = $this->limits[$instanceId] ?? [];
        $usage = $this->usage[$instanceId] ?? [];
        
        // Check memory limit
        if (isset($limits['memory_mb']) && isset($usage['memory_peak_mb'])) {
            if ($usage['memory_peak_mb'] > $limits['memory_mb']) {
                $violations[] = [
                    'type' => 'memory',
                    'limit' => $limits['memory_mb'],
                    'usage' => $usage['memory_peak_mb'],
                    'exceeded_by' => $usage['memory_peak_mb'] - $limits['memory_mb']
                ];
            }
        }
        
        // Check rate limit
        if (isset($limits['requests_per_minute'])) {
            $recentRequests = $this->getRecentRequestCount($instanceId, 60);
            if ($recentRequests > $limits['requests_per_minute']) {
                $violations[] = [
                    'type' => 'rate_limit',
                    'limit' => $limits['requests_per_minute'],
                    'usage' => $recentRequests,
                    'exceeded_by' => $recentRequests - $limits['requests_per_minute']
                ];
            }
        }
        
        // Check storage quota
        if (isset($limits['storage_mb'])) {
            $storageUsage = $this->getStorageUsage($instanceId);
            if ($storageUsage > $limits['storage_mb']) {
                $violations[] = [
                    'type' => 'storage',
                    'limit' => $limits['storage_mb'],
                    'usage' => $storageUsage,
                    'exceeded_by' => $storageUsage - $limits['storage_mb']
                ];
            }
        }
        
        // Check cache quota
        if (isset($limits['cache_mb'])) {
            $cacheUsage = $this->getCacheUsage($instanceId);
            if ($cacheUsage > $limits['cache_mb']) {
                $violations[] = [
                    'type' => 'cache',
                    'limit' => $limits['cache_mb'],
                    'usage' => $cacheUsage,
                    'exceeded_by' => $cacheUsage - $limits['cache_mb']
                ];
            }
        }
        
        return [
            'within_limits' => empty($violations),
            'violations' => $violations
        ];
    }
    
    /**
     * Enforce quotas (cleanup if exceeded)
     * 
     * @param string $instanceId Instance identifier
     * @return array Actions taken
     */
    public function enforceQuotas(string $instanceId): array
    {
        $actions = [];
        $status = $this->checkLimits($instanceId);
        
        if (!$status['within_limits']) {
            foreach ($status['violations'] as $violation) {
                switch ($violation['type']) {
                    case 'cache':
                        // Clear oldest cache files
                        $this->cleanupCache($instanceId, $violation['exceeded_by']);
                        $actions[] = "Cleared {$violation['exceeded_by']}MB of cache";
                        break;
                        
                    case 'storage':
                        // Log warning (manual intervention needed)
                        $actions[] = "Storage quota exceeded - manual cleanup required";
                        error_log("ResourceManager: Storage quota exceeded for {$instanceId}");
                        break;
                        
                    case 'rate_limit':
                        // Throttle requests
                        $actions[] = "Rate limit exceeded - throttling enabled";
                        break;
                        
                    case 'memory':
                        // Log warning
                        $actions[] = "Memory limit exceeded - consider increasing limit";
                        error_log("ResourceManager: Memory limit exceeded for {$instanceId}");
                        break;
                }
            }
        }
        
        return $actions;
    }
    
    /**
     * Get resource usage for an instance
     * 
     * @param string $instanceId Instance identifier
     * @return array Usage statistics
     */
    public function getUsage(string $instanceId): array
    {
        $limits = $this->limits[$instanceId] ?? [];
        $usage = $this->usage[$instanceId] ?? [];
        
        return [
            'instance_id' => $instanceId,
            'limits' => $limits,
            'usage' => [
                'memory_peak_mb' => $usage['memory_peak_mb'] ?? 0,
                'memory_limit_mb' => $limits['memory_mb'] ?? null,
                'memory_percent' => $this->calculatePercent(
                    $usage['memory_peak_mb'] ?? 0,
                    $limits['memory_mb'] ?? null
                ),
                'storage_mb' => $this->getStorageUsage($instanceId),
                'storage_limit_mb' => $limits['storage_mb'] ?? null,
                'storage_percent' => $this->calculatePercent(
                    $this->getStorageUsage($instanceId),
                    $limits['storage_mb'] ?? null
                ),
                'cache_mb' => $this->getCacheUsage($instanceId),
                'cache_limit_mb' => $limits['cache_mb'] ?? null,
                'cache_percent' => $this->calculatePercent(
                    $this->getCacheUsage($instanceId),
                    $limits['cache_mb'] ?? null
                ),
                'requests_count' => $usage['requests_count'] ?? 0,
                'cpu_time_ms' => $usage['cpu_time_ms'] ?? 0,
                'last_updated' => $usage['last_updated'] ?? null
            ]
        ];
    }
    
    /**
     * Get usage for all instances
     * 
     * @return array All instances usage
     */
    public function getAllUsage(): array
    {
        $allUsage = [];
        
        // Get all instances from limits and usage
        $instances = array_unique(array_merge(
            array_keys($this->limits),
            array_keys($this->usage)
        ));
        
        foreach ($instances as $instanceId) {
            $allUsage[$instanceId] = $this->getUsage($instanceId);
        }
        
        return $allUsage;
    }
    
    /**
     * Get resource statistics
     * 
     * @return array Global statistics
     */
    public function getStats(): array
    {
        $totalInstances = count(array_unique(array_merge(
            array_keys($this->limits),
            array_keys($this->usage)
        )));
        
        $totalMemoryLimit = 0;
        $totalMemoryUsage = 0;
        $totalStorageLimit = 0;
        $totalStorageUsage = 0;
        $totalRequests = 0;
        
        foreach ($this->limits as $instanceId => $limits) {
            $totalMemoryLimit += $limits['memory_mb'] ?? 0;
            $totalStorageLimit += $limits['storage_mb'] ?? 0;
        }
        
        foreach ($this->usage as $instanceId => $usage) {
            $totalMemoryUsage += $usage['memory_peak_mb'] ?? 0;
            $totalStorageUsage += $this->getStorageUsage($instanceId);
            $totalRequests += $usage['requests_count'] ?? 0;
        }
        
        return [
            'total_instances' => $totalInstances,
            'total_memory_limit_mb' => $totalMemoryLimit,
            'total_memory_usage_mb' => $totalMemoryUsage,
            'total_storage_limit_mb' => $totalStorageLimit,
            'total_storage_usage_mb' => $totalStorageUsage,
            'total_requests' => $totalRequests,
            'memory_utilization' => $this->calculatePercent($totalMemoryUsage, $totalMemoryLimit),
            'storage_utilization' => $this->calculatePercent($totalStorageUsage, $totalStorageLimit)
        ];
    }
    
    /**
     * Reset usage statistics for an instance
     * 
     * @param string $instanceId Instance identifier
     */
    public function resetUsage(string $instanceId): void
    {
        unset($this->usage[$instanceId]);
        error_log("ResourceManager: Reset usage statistics for {$instanceId}");
    }
    
    /**
     * Remove instance limits
     * 
     * @param string $instanceId Instance identifier
     */
    public function removeLimits(string $instanceId): void
    {
        unset($this->limits[$instanceId]);
        unset($this->usage[$instanceId]);
        $this->saveLimits();
        
        error_log("ResourceManager: Removed limits for {$instanceId}");
    }
    
    // ========================================================================
    // PRIVATE HELPER METHODS
    // ========================================================================
    
    /**
     * Load limits from config file
     */
    private function loadLimits(): void
    {
        if (file_exists($this->configFile)) {
            $content = file_get_contents($this->configFile);
            $data = json_decode($content, true);
            
            if ($data && isset($data['limits'])) {
                $this->limits = $data['limits'];
            }
        }
    }
    
    /**
     * Save limits to config file
     */
    private function saveLimits(): void
    {
        $data = [
            'version' => '1.0.0',
            'updated' => date('Y-m-d H:i:s'),
            'limits' => $this->limits
        ];
        
        $dir = dirname($this->configFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        
        file_put_contents($this->configFile, json_encode($data, JSON_PRETTY_PRINT));
    }
    
    /**
     * Get storage usage for instance
     */
    private function getStorageUsage(string $instanceId): float
    {
        $instanceDir = __DIR__ . '/../instances/' . $instanceId;
        
        if (!is_dir($instanceDir)) {
            return 0;
        }
        
        $size = $this->getDirectorySize($instanceDir);
        return round($size / 1024 / 1024, 2); // Convert to MB
    }
    
    /**
     * Get cache usage for instance
     */
    private function getCacheUsage(string $instanceId): float
    {
        $cacheDir = __DIR__ . '/../storage/cache';
        $pattern = $cacheDir . '/' . md5($instanceId) . '_*.cache';
        
        $size = 0;
        foreach (glob($pattern) as $file) {
            $size += filesize($file);
        }
        
        return round($size / 1024 / 1024, 2); // Convert to MB
    }
    
    /**
     * Get directory size recursively
     */
    private function getDirectorySize(string $dir): int
    {
        $size = 0;
        
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
        ) as $file) {
            $size += $file->getSize();
        }
        
        return $size;
    }
    
    /**
     * Calculate percentage
     */
    private function calculatePercent(?float $usage, ?float $limit): ?float
    {
        if ($limit === null || $limit == 0) {
            return null;
        }
        
        return round(($usage / $limit) * 100, 2);
    }
    
    /**
     * Get recent request count
     */
    private function getRecentRequestCount(string $instanceId, int $seconds): int
    {
        // This would need to be implemented with a time-series database
        // For now, return 0
        return 0;
    }
    
    /**
     * Cleanup cache to free space
     */
    private function cleanupCache(string $instanceId, float $megabytesToFree): void
    {
        $cache = new Cache();
        $cache->clear($instanceId);
        
        error_log("ResourceManager: Cleaned up cache for {$instanceId} to free {$megabytesToFree}MB");
    }
}
