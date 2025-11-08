<?php
/**
 * Ikabud Kernel - Virtual Process Manager
 * 
 * Simulates process management for shared hosting environments
 * Provides admin control interface without requiring root access
 * Seamlessly upgrades to real ProcessManager when VPS is available
 * 
 * @version 1.0.0
 */

namespace IkabudKernel\Core;

use PDO;
use Exception;

class VirtualProcessManager
{
    private Kernel $kernel;
    private PDO $db;
    private string $mode = 'virtual'; // 'virtual' or 'real'
    
    public function __construct(Kernel $kernel)
    {
        $this->kernel = $kernel;
        $this->db = $kernel->getDatabase();
        
        // Detect if we can use real ProcessManager
        $this->detectMode();
    }
    
    /**
     * Detect if we're in VPS (can use real ProcessManager) or shared hosting
     */
    private function detectMode(): void
    {
        // Check if we have root access
        if (posix_getuid() === 0 || $this->canUseSudo()) {
            $this->mode = 'real';
        } else {
            $this->mode = 'virtual';
        }
    }
    
    /**
     * Check if sudo is available
     */
    private function canUseSudo(): bool
    {
        exec('sudo -n true 2>/dev/null', $output, $returnCode);
        return $returnCode === 0;
    }
    
    /**
     * Get instance status (works in both modes)
     */
    public function getInstanceStatus(string $instanceId): array
    {
        if ($this->mode === 'real') {
            return $this->getRealProcessStatus($instanceId);
        }
        
        return $this->getVirtualProcessStatus($instanceId);
    }
    
    /**
     * Get virtual process status from database
     */
    private function getVirtualProcessStatus(string $instanceId): array
    {
        $stmt = $this->db->prepare("
            SELECT i.*, vp.virtual_pid, vp.started_at as process_started_at
            FROM instances i
            LEFT JOIN virtual_processes vp ON i.instance_id = vp.instance_id
            WHERE i.instance_id = ?
        ");
        $stmt->execute([$instanceId]);
        $instance = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$instance) {
            throw new Exception("Instance not found: {$instanceId}");
        }
        
        return [
            'pid' => $instance['virtual_pid'],
            'status' => $instance['status'] === 'active' ? 'running' : 'stopped',
            'mode' => 'virtual',
            'socket' => null,
            'started_at' => $instance['process_started_at']
        ];
    }
    
    /**
     * Get real process status (when in VPS mode)
     */
    private function getRealProcessStatus(string $instanceId): array
    {
        // Delegate to real ProcessManager
        $processManager = new ProcessManager($this->kernel);
        return $processManager->getInstanceStatus($instanceId);
    }
    
    /**
     * Start an instance
     */
    public function startInstance(string $instanceId): array
    {
        if ($this->mode === 'real') {
            return $this->startRealProcess($instanceId);
        }
        
        return $this->startVirtualProcess($instanceId);
    }
    
    /**
     * Start virtual process (change status to active)
     */
    private function startVirtualProcess(string $instanceId): array
    {
        // Update instance status
        $stmt = $this->db->prepare("
            UPDATE instances 
            SET status = 'active', activated_at = NOW()
            WHERE instance_id = ?
        ");
        $stmt->execute([$instanceId]);
        
        // Create or update virtual process record
        $virtualPid = $this->generateVirtualPid();
        
        $stmt = $this->db->prepare("
            INSERT INTO virtual_processes 
            (instance_id, virtual_pid, status, started_at, last_activity)
            VALUES (?, ?, 'running', NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                virtual_pid = VALUES(virtual_pid),
                status = 'running',
                started_at = NOW(),
                last_activity = NOW()
        ");
        $stmt->execute([$instanceId, $virtualPid]);
        
        return [
            'success' => true,
            'instance_id' => $instanceId,
            'virtual_pid' => $virtualPid,
            'status' => 'running',
            'mode' => 'virtual',
            'message' => 'Instance activated successfully'
        ];
    }
    
    /**
     * Start real process (when in VPS mode)
     */
    private function startRealProcess(string $instanceId): array
    {
        $processManager = new ProcessManager($this->kernel);
        return $processManager->startInstanceService($instanceId);
    }
    
    /**
     * Stop an instance
     */
    public function stopInstance(string $instanceId): array
    {
        if ($this->mode === 'real') {
            return $this->stopRealProcess($instanceId);
        }
        
        return $this->stopVirtualProcess($instanceId);
    }
    
    /**
     * Stop virtual process (change status to inactive)
     */
    private function stopVirtualProcess(string $instanceId): array
    {
        // Update instance status
        $stmt = $this->db->prepare("
            UPDATE instances 
            SET status = 'inactive'
            WHERE instance_id = ?
        ");
        $stmt->execute([$instanceId]);
        
        // Update virtual process
        $stmt = $this->db->prepare("
            UPDATE virtual_processes 
            SET status = 'stopped', stopped_at = NOW()
            WHERE instance_id = ?
        ");
        $stmt->execute([$instanceId]);
        
        return [
            'success' => true,
            'instance_id' => $instanceId,
            'status' => 'stopped',
            'mode' => 'virtual',
            'message' => 'Instance deactivated successfully'
        ];
    }
    
    /**
     * Stop real process
     */
    private function stopRealProcess(string $instanceId): array
    {
        $processManager = new ProcessManager($this->kernel);
        return $processManager->stopInstanceProcess($instanceId);
    }
    
    /**
     * Restart an instance
     */
    public function restartInstance(string $instanceId): array
    {
        $this->stopInstance($instanceId);
        sleep(1); // Brief pause
        return $this->startInstance($instanceId);
    }
    
    /**
     * Monitor instance health
     */
    public function monitorInstanceHealth(string $instanceId): array
    {
        if ($this->mode === 'real') {
            $processManager = new ProcessManager($this->kernel);
            return $processManager->monitorInstanceHealth($instanceId);
        }
        
        return $this->monitorVirtualHealth($instanceId);
    }
    
    /**
     * Monitor virtual instance health
     */
    private function monitorVirtualHealth(string $instanceId): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM instances WHERE instance_id = ?
        ");
        $stmt->execute([$instanceId]);
        $instance = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$instance) {
            return ['healthy' => false, 'reason' => 'Instance not found'];
        }
        
        // Get resource usage
        $resources = $this->getResourceUsage($instanceId);
        
        return [
            'healthy' => $instance['status'] === 'active',
            'status' => $instance['status'],
            'mode' => 'virtual',
            'resources' => $resources,
            'last_check' => date('Y-m-d H:i:s')
        ];
    }
    
    /**
     * Get resource usage for an instance
     */
    public function getResourceUsage(string $instanceId): array
    {
        $instance = $this->getInstanceInfo($instanceId);
        
        // Calculate disk usage
        $instancePath = dirname(__DIR__) . "/instances/{$instanceId}";
        $diskUsage = $this->calculateDiskUsage($instancePath);
        
        // Get database size
        $dbSize = $this->getDatabaseSize($instance['database_name']);
        
        // Estimate memory (rough calculation)
        $estimatedMemory = $dbSize * 2; // Database size * 2 as rough estimate
        
        // Count recent queries (from slow query log or general log if available)
        $queryCount = $this->estimateQueryCount($instance['database_name']);
        
        return [
            'memory' => $estimatedMemory,
            'disk_usage' => $diskUsage,
            'database_size' => $dbSize,
            'queries' => $queryCount,
            'mode' => $this->mode
        ];
    }
    
    /**
     * Calculate disk usage for instance directory
     */
    private function calculateDiskUsage(string $path): int
    {
        if (!is_dir($path)) {
            return 0;
        }
        
        $size = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS)
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile() && !$file->isLink()) {
                $size += $file->getSize();
            }
        }
        
        return $size;
    }
    
    /**
     * Get database size
     */
    private function getDatabaseSize(string $dbName): int
    {
        try {
            $stmt = $this->db->prepare("
                SELECT SUM(data_length + index_length) as size
                FROM information_schema.TABLES
                WHERE table_schema = ?
            ");
            $stmt->execute([$dbName]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return (int) ($result['size'] ?? 0);
        } catch (Exception $e) {
            return 0;
        }
    }
    
    /**
     * Estimate query count (simplified)
     */
    private function estimateQueryCount(string $dbName): int
    {
        try {
            $stmt = $this->db->prepare("
                SELECT COUNT(*) as count
                FROM information_schema.TABLES
                WHERE table_schema = ?
            ");
            $stmt->execute([$dbName]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Rough estimate: tables * 10
            return (int) ($result['count'] ?? 0) * 10;
        } catch (Exception $e) {
            return 0;
        }
    }
    
    /**
     * Get instance information
     */
    private function getInstanceInfo(string $instanceId): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM instances WHERE instance_id = ?
        ");
        $stmt->execute([$instanceId]);
        $instance = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$instance) {
            throw new Exception("Instance not found: {$instanceId}");
        }
        
        return $instance;
    }
    
    /**
     * Generate virtual PID
     */
    private function generateVirtualPid(): string
    {
        return 'v' . time() . rand(1000, 9999);
    }
    
    /**
     * Get current mode
     */
    public function getMode(): string
    {
        return $this->mode;
    }
    
    /**
     * Check if real ProcessManager is available
     */
    public function canUseRealProcessManager(): bool
    {
        return $this->mode === 'real';
    }
}
