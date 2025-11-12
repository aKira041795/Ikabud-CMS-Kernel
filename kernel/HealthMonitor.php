<?php
/**
 * Health Monitor
 * 
 * Monitors kernel and instance health, provides diagnostics
 */

namespace IkabudKernel\Core;

use PDO;
use Exception;

class HealthMonitor
{
    private PDO $db;
    private ResourceManager $resourceManager;
    private Cache $cache;
    private float $bootTime;
    private array $alertHooks = [];
    
    public function __construct(PDO $db, ResourceManager $resourceManager, Cache $cache, float $bootTime)
    {
        $this->db = $db;
        $this->resourceManager = $resourceManager;
        $this->cache = $cache;
        $this->bootTime = $bootTime;
    }
    
    /**
     * Register alert hook
     */
    public function onAlert(callable $callback): void
    {
        $this->alertHooks[] = $callback;
    }
    
    /**
     * Trigger alert hooks
     */
    private function triggerAlerts(string $level, array $details): void
    {
        foreach ($this->alertHooks as $hook) {
            try {
                $hook($level, $details);
            } catch (Exception $e) {
                error_log("Health alert hook failed: " . $e->getMessage());
            }
        }
    }
    
    /**
     * Perform comprehensive health check
     */
    public function check(): array
    {
        $checks = [
            'kernel' => $this->checkKernel(),
            'database' => $this->checkDatabase(),
            'cache' => $this->checkCache(),
            'filesystem' => $this->checkFilesystem(),
            'instances' => $this->checkInstances()
        ];
        
        $overallStatus = $this->determineOverallStatus($checks);
        
        // Trigger alerts if not healthy
        if ($overallStatus !== 'healthy') {
            $this->triggerAlerts($overallStatus, [
                'status' => $overallStatus,
                'checks' => $checks,
                'timestamp' => time()
            ]);
        }
        
        return [
            'status' => $overallStatus,
            'timestamp' => time(),
            'uptime_seconds' => microtime(true) - $this->bootTime,
            'checks' => $checks
        ];
    }
    
    /**
     * Check kernel health
     */
    private function checkKernel(): array
    {
        $status = 'healthy';
        $issues = [];
        
        // Check memory usage
        $memoryUsage = memory_get_usage(true);
        $memoryLimit = $this->parseMemoryLimit(ini_get('memory_limit'));
        $memoryPercent = ($memoryUsage / $memoryLimit) * 100;
        
        if ($memoryPercent > 90) {
            $status = 'critical';
            $issues[] = 'Memory usage above 90%';
        } elseif ($memoryPercent > 75) {
            $status = 'warning';
            $issues[] = 'Memory usage above 75%';
        }
        
        // Check uptime
        $uptime = microtime(true) - $this->bootTime;
        
        return [
            'status' => $status,
            'memory_usage_mb' => round($memoryUsage / 1024 / 1024, 2),
            'memory_limit_mb' => round($memoryLimit / 1024 / 1024, 2),
            'memory_percent' => round($memoryPercent, 2),
            'uptime_seconds' => round($uptime, 2),
            'issues' => $issues
        ];
    }
    
    /**
     * Check database health
     */
    private function checkDatabase(): array
    {
        $status = 'healthy';
        $issues = [];
        
        try {
            // Test connection
            $start = microtime(true);
            $this->db->query('SELECT 1');
            $responseTime = (microtime(true) - $start) * 1000;
            
            if ($responseTime > 1000) {
                $status = 'warning';
                $issues[] = 'Slow database response time';
            }
            
            // Check connection pool
            $stmt = $this->db->query("SHOW STATUS LIKE 'Threads_connected'");
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $connections = (int)($result['Value'] ?? 0);
            
            if ($connections > 100) {
                $status = 'warning';
                $issues[] = 'High number of database connections';
            }
            
            return [
                'status' => $status,
                'response_time_ms' => round($responseTime, 2),
                'connections' => $connections,
                'issues' => $issues
            ];
        } catch (Exception $e) {
            return [
                'status' => 'critical',
                'error' => $e->getMessage(),
                'issues' => ['Database connection failed']
            ];
        }
    }
    
    /**
     * Check cache health
     */
    private function checkCache(): array
    {
        $status = 'healthy';
        $issues = [];
        
        try {
            $stats = $this->cache->getStats();
            $hitRate = $stats['hit_rate'] ?? 0;
            
            if ($hitRate < 50) {
                $status = 'warning';
                $issues[] = 'Low cache hit rate';
            }
            
            return [
                'status' => $status,
                'hit_rate' => $hitRate,
                'hits' => $stats['hits'] ?? 0,
                'misses' => $stats['misses'] ?? 0,
                'size_mb' => $stats['size_mb'] ?? 0,
                'issues' => $issues
            ];
        } catch (Exception $e) {
            return [
                'status' => 'warning',
                'error' => $e->getMessage(),
                'issues' => ['Cache stats unavailable']
            ];
        }
    }
    
    /**
     * Check filesystem health
     */
    private function checkFilesystem(): array
    {
        $status = 'healthy';
        $issues = [];
        
        $path = dirname(__DIR__);
        $diskFree = disk_free_space($path);
        $diskTotal = disk_total_space($path);
        $diskUsedPercent = (($diskTotal - $diskFree) / $diskTotal) * 100;
        
        if ($diskUsedPercent > 95) {
            $status = 'critical';
            $issues[] = 'Disk usage above 95%';
        } elseif ($diskUsedPercent > 85) {
            $status = 'warning';
            $issues[] = 'Disk usage above 85%';
        }
        
        // Check write permissions
        $testFile = $path . '/storage/.health_check';
        $writable = @file_put_contents($testFile, 'test') !== false;
        if ($writable) {
            @unlink($testFile);
        } else {
            $status = 'critical';
            $issues[] = 'Storage directory not writable';
        }
        
        return [
            'status' => $status,
            'disk_free_gb' => round($diskFree / 1024 / 1024 / 1024, 2),
            'disk_total_gb' => round($diskTotal / 1024 / 1024 / 1024, 2),
            'disk_used_percent' => round($diskUsedPercent, 2),
            'writable' => $writable,
            'issues' => $issues
        ];
    }
    
    /**
     * Check instances health
     */
    private function checkInstances(): array
    {
        $status = 'healthy';
        $issues = [];
        
        try {
            $stmt = $this->db->query("SELECT COUNT(*) as count FROM instances WHERE status = 'active'");
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $activeInstances = (int)($result['count'] ?? 0);
            
            // Check each instance resource usage
            $stmt = $this->db->query("SELECT instance_id FROM instances WHERE status = 'active'");
            $instances = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            $overQuota = 0;
            foreach ($instances as $instanceId) {
                $limits = $this->resourceManager->checkLimits($instanceId);
                if (!$limits['within_limits']) {
                    $overQuota++;
                }
            }
            
            if ($overQuota > 0) {
                $status = 'warning';
                $issues[] = "{$overQuota} instances over quota";
            }
            
            return [
                'status' => $status,
                'active_instances' => $activeInstances,
                'over_quota' => $overQuota,
                'issues' => $issues
            ];
        } catch (Exception $e) {
            return [
                'status' => 'warning',
                'error' => $e->getMessage(),
                'issues' => ['Unable to check instances']
            ];
        }
    }
    
    /**
     * Determine overall status from individual checks
     */
    private function determineOverallStatus(array $checks): string
    {
        $statuses = array_column($checks, 'status');
        
        if (in_array('critical', $statuses)) {
            return 'critical';
        }
        
        if (in_array('warning', $statuses)) {
            return 'warning';
        }
        
        return 'healthy';
    }
    
    /**
     * Parse memory limit string to bytes
     */
    private function parseMemoryLimit(string $limit): int
    {
        $limit = trim($limit);
        $last = strtolower($limit[strlen($limit)-1]);
        $value = (int)$limit;
        
        switch($last) {
            case 'g':
                $value *= 1024;
            case 'm':
                $value *= 1024;
            case 'k':
                $value *= 1024;
        }
        
        return $value;
    }
    
    /**
     * Get quick status (lightweight check)
     */
    public function getQuickStatus(): array
    {
        return [
            'status' => 'healthy',
            'uptime' => microtime(true) - $this->bootTime,
            'memory_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
            'timestamp' => time()
        ];
    }
}
