<?php
/**
 * Query Executor - Executes compiled queries
 * 
 * Integrates with CMS adapters to fetch data
 * Handles caching and error recovery
 * 
 * @version 1.1.0
 */

namespace IkabudKernel\DSL;

use IkabudKernel\CMS\CMSRegistry;
use IkabudKernel\Core\Kernel;
use PDO;
use Exception;

class QueryExecutor
{
    private PDO $db;
    private bool $cacheEnabled;
    
    /**
     * Constructor
     */
    public function __construct()
    {
        $kernel = Kernel::getInstance();
        $this->db = $kernel->getDatabase();
        $this->cacheEnabled = $_ENV['DSL_CACHE_ENABLED'] === 'true';
    }
    
    /**
     * Execute compiled query
     */
    public function execute(array $ast): array
    {
        $startTime = microtime(true);
        
        // Check cache first
        if ($this->cacheEnabled && $ast['attributes']['cache'] ?? true) {
            $cached = $this->getFromCache($ast['metadata']['cache_key']);
            if ($cached !== null) {
                return [
                    'success' => true,
                    'data' => $cached,
                    'cached' => true,
                    'execution_time_ms' => (microtime(true) - $startTime) * 1000
                ];
            }
        }
        
        try {
            // Determine which CMS to use
            $cmsName = $ast['attributes']['cms'] ?? null;
            
            if ($cmsName) {
                // Use specified CMS
                $cms = CMSRegistry::get($cmsName);
                if (!$cms) {
                    throw new Exception("CMS '{$cmsName}' not found");
                }
            } else {
                // Use active CMS
                $cms = CMSRegistry::getActive();
                if (!$cms) {
                    throw new Exception("No active CMS");
                }
            }
            
            // Ensure CMS is booted
            if (!$cms->isBooted()) {
                CMSRegistry::boot($cmsName ?? 'default');
            }
            
            // Execute query through CMS
            $data = $cms->executeQuery($ast['attributes']);
            
            // Cache result
            if ($this->cacheEnabled && $ast['attributes']['cache'] ?? true) {
                $this->saveToCache(
                    $ast['metadata']['cache_key'],
                    $data,
                    $ast['attributes']['cache_ttl'] ?? 3600
                );
            }
            
            return [
                'success' => true,
                'data' => $data,
                'cached' => false,
                'execution_time_ms' => (microtime(true) - $startTime) * 1000
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'data' => [],
                'execution_time_ms' => (microtime(true) - $startTime) * 1000
            ];
        }
    }
    
    /**
     * Get from cache
     */
    private function getFromCache(string $key): ?array
    {
        $stmt = $this->db->prepare("
            SELECT compiled_ast
            FROM dsl_cache
            WHERE cache_key = ? AND (expires_at IS NULL OR expires_at > NOW())
        ");
        
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        
        if ($row) {
            // Update hit count
            $this->db->prepare("
                UPDATE dsl_cache
                SET hit_count = hit_count + 1, last_hit_at = NOW()
                WHERE cache_key = ?
            ")->execute([$key]);
            
            return json_decode($row['compiled_ast'], true);
        }
        
        return null;
    }
    
    /**
     * Save to cache
     */
    private function saveToCache(string $key, array $data, int $ttl): void
    {
        $expiresAt = $ttl > 0 ? date('Y-m-d H:i:s', time() + $ttl) : null;
        
        $stmt = $this->db->prepare("
            INSERT INTO dsl_cache (cache_key, query_string, compiled_ast, expires_at)
            VALUES (?, '', ?, ?)
            ON DUPLICATE KEY UPDATE
                compiled_ast = VALUES(compiled_ast),
                expires_at = VALUES(expires_at),
                hit_count = 0
        ");
        
        $stmt->execute([$key, json_encode($data), $expiresAt]);
    }
}
