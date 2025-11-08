<?php
/**
 * Ikabud Kernel - Process Manager
 * 
 * Manages CMS instances as OS-level processes
 * Each instance runs in its own PHP-FPM pool with dedicated PID
 * 
 * @version 1.0.0
 */

namespace IkabudKernel\Core;

use Exception;

class ProcessManager
{
    private Kernel $kernel;
    private string $phpVersion;
    private string $phpFpmPath;
    private string $systemdPath;
    private array $runningProcesses = [];
    
    public function __construct(Kernel $kernel)
    {
        $this->kernel = $kernel;
        $this->detectEnvironment();
    }
    
    /**
     * Detect PHP and system environment
     */
    private function detectEnvironment(): void
    {
        // Detect PHP version
        $this->phpVersion = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
        
        // Detect PHP-FPM path
        $this->phpFpmPath = "/etc/php/{$this->phpVersion}/fpm";
        
        // Detect systemd path
        $this->systemdPath = "/etc/systemd/system";
        
        // Verify we have necessary permissions
        if (!$this->hasRootAccess()) {
            throw new Exception("Process isolation requires root access or sudo privileges");
        }
    }
    
    /**
     * Check if we have root access
     */
    private function hasRootAccess(): bool
    {
        // Check if we can write to system directories
        return is_writable($this->phpFpmPath) || 
               posix_getuid() === 0 ||
               $this->canUseSudo();
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
     * Create a new instance process
     * 
     * @param string $instanceId Instance identifier
     * @param array $config Instance configuration
     * @return array Process information (PID, socket, etc.)
     */
    public function createInstanceProcess(string $instanceId, array $config): array
    {
        // Step 1: Create Unix user for instance
        $user = $this->createInstanceUser($instanceId);
        
        // Step 2: Generate PHP-FPM pool configuration
        $poolConfig = $this->generatePoolConfig($instanceId, $user, $config);
        $poolFile = "{$this->phpFpmPath}/pool.d/ikabud-{$instanceId}.conf";
        $this->writePoolConfig($poolFile, $poolConfig);
        
        // Step 3: Generate systemd service file
        $serviceConfig = $this->generateServiceConfig($instanceId, $user, $config);
        $serviceFile = "{$this->systemdPath}/ikabud-{$instanceId}.service";
        $this->writeServiceConfig($serviceFile, $serviceConfig);
        
        // Step 4: Set proper permissions
        $this->setInstancePermissions($instanceId, $user);
        
        // Step 5: Reload systemd and PHP-FPM
        $this->reloadSystemd();
        $this->reloadPhpFpm();
        
        // Step 6: Start the instance service
        $pid = $this->startInstanceService($instanceId);
        
        // Step 7: Register process
        $processInfo = [
            'instance_id' => $instanceId,
            'pid' => $pid,
            'user' => $user,
            'socket' => "/var/run/php/ikabud-{$instanceId}.sock",
            'pool_file' => $poolFile,
            'service_file' => $serviceFile,
            'status' => 'running',
            'started_at' => time()
        ];
        
        $this->runningProcesses[$instanceId] = $processInfo;
        
        return $processInfo;
    }
    
    /**
     * Create Unix user for instance
     */
    private function createInstanceUser(string $instanceId): string
    {
        $user = "ikabud_" . str_replace('-', '_', $instanceId);
        
        // Check if user already exists
        $exists = posix_getpwnam($user);
        if ($exists) {
            return $user;
        }
        
        // Create user
        $cmd = "sudo useradd -r -s /bin/false -d /var/www/html/ikabud-kernel/instances/{$instanceId} {$user}";
        exec($cmd, $output, $returnCode);
        
        if ($returnCode !== 0) {
            throw new Exception("Failed to create user: {$user}");
        }
        
        // Add www-data to user's group for Apache access
        exec("sudo usermod -a -G {$user} www-data");
        
        return $user;
    }
    
    /**
     * Generate PHP-FPM pool configuration
     */
    private function generatePoolConfig(string $instanceId, string $user, array $config): string
    {
        $socket = "/var/run/php/ikabud-{$instanceId}.sock";
        $instancePath = dirname(__DIR__) . "/instances/{$instanceId}";
        
        // Get resource limits from config
        $maxChildren = $config['max_children'] ?? 5;
        $startServers = $config['start_servers'] ?? 2;
        $minSpareServers = $config['min_spare_servers'] ?? 1;
        $maxSpareServers = $config['max_spare_servers'] ?? 3;
        $memoryLimit = $config['memory_limit'] ?? '256M';
        $maxExecutionTime = $config['max_execution_time'] ?? 60;
        
        return <<<POOL
; Ikabud Kernel - PHP-FPM Pool Configuration
; Instance: {$instanceId}
; Generated: {date('Y-m-d H:i:s')}

[ikabud-{$instanceId}]

; Unix user/group of processes
user = {$user}
group = {$user}

; Socket path
listen = {$socket}
listen.owner = {$user}
listen.group = www-data
listen.mode = 0660

; Process manager configuration
pm = dynamic
pm.max_children = {$maxChildren}
pm.start_servers = {$startServers}
pm.min_spare_servers = {$minSpareServers}
pm.max_spare_servers = {$maxSpareServers}
pm.max_requests = 500

; PHP settings
php_admin_value[memory_limit] = {$memoryLimit}
php_admin_value[max_execution_time] = {$maxExecutionTime}
php_admin_value[upload_max_filesize] = 100M
php_admin_value[post_max_size] = 100M

; Error logging
php_admin_value[error_log] = /var/log/php/ikabud-{$instanceId}-error.log
php_admin_flag[log_errors] = on

; Instance-specific environment
env[IKABUD_INSTANCE_ID] = {$instanceId}
env[IKABUD_INSTANCE_PATH] = {$instancePath}
env[IKABUD_KERNEL_PATH] = {dirname(__DIR__)}/kernel

; Security
php_admin_value[open_basedir] = {$instancePath}:/tmp:/var/www/html/ikabud-kernel/shared-cores
php_admin_value[disable_functions] = exec,passthru,shell_exec,system,proc_open,popen

; Status page
pm.status_path = /status

POOL;
    }
    
    /**
     * Generate systemd service configuration
     */
    private function generateServiceConfig(string $instanceId, string $user, array $config): string
    {
        $poolFile = "{$this->phpFpmPath}/pool.d/ikabud-{$instanceId}.conf";
        $pidFile = "/var/run/php/ikabud-{$instanceId}.pid";
        
        return <<<SERVICE
# Ikabud Kernel - Systemd Service Configuration
# Instance: {$instanceId}
# Generated: {date('Y-m-d H:i:s')}

[Unit]
Description=Ikabud Kernel - CMS Instance: {$instanceId}
After=network.target mysql.service
PartOf=php{$this->phpVersion}-fpm.service

[Service]
Type=forking
PIDFile={$pidFile}
ExecStart=/usr/sbin/php-fpm{$this->phpVersion} --nodaemonize --fpm-config {$poolFile}
ExecReload=/bin/kill -USR2 \$MAINPID
Restart=on-failure
RestartSec=5s
User={$user}
Group={$user}

# Resource limits
MemoryLimit=512M
CPUQuota=50%

# Security
PrivateTmp=true
NoNewPrivileges=true
ProtectSystem=strict
ProtectHome=true
ReadWritePaths=/var/www/html/ikabud-kernel/instances/{$instanceId}

[Install]
WantedBy=multi-user.target

SERVICE;
    }
    
    /**
     * Write pool configuration file
     */
    private function writePoolConfig(string $file, string $content): void
    {
        $tmpFile = "/tmp/ikabud-pool-" . basename($file);
        file_put_contents($tmpFile, $content);
        
        $cmd = "sudo cp {$tmpFile} {$file}";
        exec($cmd, $output, $returnCode);
        
        if ($returnCode !== 0) {
            throw new Exception("Failed to write pool config: {$file}");
        }
        
        unlink($tmpFile);
    }
    
    /**
     * Write service configuration file
     */
    private function writeServiceConfig(string $file, string $content): void
    {
        $tmpFile = "/tmp/ikabud-service-" . basename($file);
        file_put_contents($tmpFile, $content);
        
        $cmd = "sudo cp {$tmpFile} {$file}";
        exec($cmd, $output, $returnCode);
        
        if ($returnCode !== 0) {
            throw new Exception("Failed to write service config: {$file}");
        }
        
        unlink($tmpFile);
    }
    
    /**
     * Set instance permissions
     */
    private function setInstancePermissions(string $instanceId, string $user): void
    {
        $instancePath = dirname(__DIR__) . "/instances/{$instanceId}";
        
        // Set ownership
        exec("sudo chown -R {$user}:{$user} {$instancePath}/wp-content");
        exec("sudo chmod -R 775 {$instancePath}/wp-content");
        
        // Ensure www-data can read
        exec("sudo chmod 755 {$instancePath}");
    }
    
    /**
     * Reload systemd daemon
     */
    private function reloadSystemd(): void
    {
        exec("sudo systemctl daemon-reload", $output, $returnCode);
        
        if ($returnCode !== 0) {
            throw new Exception("Failed to reload systemd");
        }
    }
    
    /**
     * Reload PHP-FPM
     */
    private function reloadPhpFpm(): void
    {
        exec("sudo systemctl reload php{$this->phpVersion}-fpm", $output, $returnCode);
        
        if ($returnCode !== 0) {
            throw new Exception("Failed to reload PHP-FPM");
        }
    }
    
    /**
     * Start instance service
     */
    private function startInstanceService(string $instanceId): int
    {
        // Enable service
        exec("sudo systemctl enable ikabud-{$instanceId}", $output, $returnCode);
        
        if ($returnCode !== 0) {
            throw new Exception("Failed to enable service: ikabud-{$instanceId}");
        }
        
        // Start service
        exec("sudo systemctl start ikabud-{$instanceId}", $output, $returnCode);
        
        if ($returnCode !== 0) {
            throw new Exception("Failed to start service: ikabud-{$instanceId}");
        }
        
        // Get PID
        sleep(1); // Wait for service to start
        return $this->getInstancePID($instanceId);
    }
    
    /**
     * Stop instance process
     */
    public function stopInstanceProcess(string $instanceId): bool
    {
        exec("sudo systemctl stop ikabud-{$instanceId}", $output, $returnCode);
        
        if ($returnCode === 0) {
            unset($this->runningProcesses[$instanceId]);
            return true;
        }
        
        return false;
    }
    
    /**
     * Restart instance process
     */
    public function restartInstanceProcess(string $instanceId): bool
    {
        exec("sudo systemctl restart ikabud-{$instanceId}", $output, $returnCode);
        
        if ($returnCode === 0) {
            $this->runningProcesses[$instanceId]['pid'] = $this->getInstancePID($instanceId);
            $this->runningProcesses[$instanceId]['restarted_at'] = time();
            return true;
        }
        
        return false;
    }
    
    /**
     * Get instance PID
     */
    public function getInstancePID(string $instanceId): ?int
    {
        $pidFile = "/var/run/php/ikabud-{$instanceId}.pid";
        
        if (file_exists($pidFile)) {
            $pid = (int)trim(file_get_contents($pidFile));
            return $pid > 0 ? $pid : null;
        }
        
        // Try to get from systemctl
        exec("sudo systemctl show -p MainPID ikabud-{$instanceId}", $output);
        if (!empty($output[0])) {
            preg_match('/MainPID=(\d+)/', $output[0], $matches);
            return isset($matches[1]) && $matches[1] > 0 ? (int)$matches[1] : null;
        }
        
        return null;
    }
    
    /**
     * Get instance status
     */
    public function getInstanceStatus(string $instanceId): array
    {
        $pid = $this->getInstancePID($instanceId);
        
        // Get systemd status
        exec("sudo systemctl is-active ikabud-{$instanceId}", $output, $returnCode);
        $isActive = $returnCode === 0 && trim($output[0]) === 'active';
        
        // Get process info if running
        $processInfo = [];
        if ($pid) {
            exec("ps -p {$pid} -o pid,user,%cpu,%mem,etime,cmd --no-headers", $psOutput);
            if (!empty($psOutput[0])) {
                $processInfo = [
                    'pid' => $pid,
                    'details' => trim($psOutput[0])
                ];
            }
        }
        
        return [
            'instance_id' => $instanceId,
            'status' => $isActive ? 'running' : 'stopped',
            'pid' => $pid,
            'process_info' => $processInfo,
            'socket' => "/var/run/php/ikabud-{$instanceId}.sock",
            'pool_file' => "{$this->phpFpmPath}/pool.d/ikabud-{$instanceId}.conf",
            'service_file' => "{$this->systemdPath}/ikabud-{$instanceId}.service"
        ];
    }
    
    /**
     * Kill instance process by PID
     */
    public function killInstanceProcess(string $instanceId, int $signal = 15): bool
    {
        $pid = $this->getInstancePID($instanceId);
        
        if (!$pid) {
            return false;
        }
        
        // Send signal to process
        exec("sudo kill -{$signal} {$pid}", $output, $returnCode);
        
        return $returnCode === 0;
    }
    
    /**
     * Remove instance process completely
     */
    public function removeInstanceProcess(string $instanceId): bool
    {
        // Stop service
        $this->stopInstanceProcess($instanceId);
        
        // Disable service
        exec("sudo systemctl disable ikabud-{$instanceId}");
        
        // Remove service file
        $serviceFile = "{$this->systemdPath}/ikabud-{$instanceId}.service";
        if (file_exists($serviceFile)) {
            exec("sudo rm {$serviceFile}");
        }
        
        // Remove pool file
        $poolFile = "{$this->phpFpmPath}/pool.d/ikabud-{$instanceId}.conf";
        if (file_exists($poolFile)) {
            exec("sudo rm {$poolFile}");
        }
        
        // Reload
        $this->reloadSystemd();
        $this->reloadPhpFpm();
        
        // Remove user
        $user = "ikabud_" . str_replace('-', '_', $instanceId);
        exec("sudo userdel {$user}");
        
        unset($this->runningProcesses[$instanceId]);
        
        return true;
    }
    
    /**
     * List all running instance processes
     */
    public function listRunningProcesses(): array
    {
        $processes = [];
        
        // Find all ikabud services
        exec("sudo systemctl list-units 'ikabud-*' --no-pager --no-legend", $output);
        
        foreach ($output as $line) {
            if (preg_match('/ikabud-([a-z0-9-]+)\.service/', $line, $matches)) {
                $instanceId = $matches[1];
                $processes[$instanceId] = $this->getInstanceStatus($instanceId);
            }
        }
        
        return $processes;
    }
    
    /**
     * Monitor instance health
     */
    public function monitorInstanceHealth(string $instanceId): array
    {
        $status = $this->getInstanceStatus($instanceId);
        $socket = $status['socket'];
        
        // Check if socket exists and is accessible
        $socketExists = file_exists($socket);
        $socketWritable = $socketExists && is_writable($socket);
        
        // Check PHP-FPM pool status
        $poolStatus = null;
        if ($socketExists) {
            // Try to get pool status via FastCGI
            // This would require FastCGI client implementation
            $poolStatus = 'unknown';
        }
        
        return [
            'instance_id' => $instanceId,
            'healthy' => $status['status'] === 'running' && $socketExists,
            'status' => $status['status'],
            'pid' => $status['pid'],
            'socket_exists' => $socketExists,
            'socket_writable' => $socketWritable,
            'pool_status' => $poolStatus,
            'checked_at' => date('Y-m-d H:i:s')
        ];
    }
}
