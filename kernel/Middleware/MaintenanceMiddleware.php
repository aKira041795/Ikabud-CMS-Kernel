<?php
/**
 * Maintenance Mode Middleware
 * 
 * Checks if instance is in maintenance mode and serves maintenance page
 */

namespace IkabudKernel\Core\Middleware;

use PDO;

class MaintenanceMiddleware
{
    private static ?PDO $db = null;
    private static array $domainCache = [];
    
    /**
     * Check if request should be blocked by maintenance mode
     * 
     * @param string $host Domain name
     * @param string $requestUri Request URI
     * @return bool True if maintenance page was served (exit called)
     */
    public static function check(string $host, string $requestUri): bool
    {
        // Skip maintenance check for admin/API routes
        if (self::shouldSkip($requestUri)) {
            return false;
        }
        
        // Get instance ID for domain
        $instanceId = self::getInstanceId($host);
        if (!$instanceId) {
            return false;
        }
        
        // Check for maintenance file
        $instanceDir = dirname(__DIR__, 2) . '/instances/' . $instanceId;
        $maintenanceFile = $instanceDir . '/.maintenance';
        
        if (file_exists($maintenanceFile)) {
            self::serveMaintenancePage();
            return true; // Will never reach here due to exit
        }
        
        return false;
    }
    
    /**
     * Check if maintenance check should be skipped for this route
     */
    private static function shouldSkip(string $requestUri): bool
    {
        $skipPrefixes = ['/admin', '/api/', '/login'];
        
        foreach ($skipPrefixes as $prefix) {
            if (strpos($requestUri, $prefix) === 0) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Get instance ID for domain (with caching)
     */
    private static function getInstanceId(string $host): ?string
    {
        if (isset(self::$domainCache[$host])) {
            return self::$domainCache[$host];
        }
        
        try {
            $db = self::getDatabase();
            $stmt = $db->prepare("SELECT instance_id FROM instances WHERE domain = ? LIMIT 1");
            $stmt->execute([$host]);
            $instanceId = $stmt->fetchColumn() ?: null;
            
            self::$domainCache[$host] = $instanceId;
            return $instanceId;
        } catch (\PDOException $e) {
            error_log("[Ikabud] Maintenance check DB error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get database connection (lazy initialization)
     */
    private static function getDatabase(): PDO
    {
        if (self::$db === null) {
            $envFile = dirname(__DIR__, 2) . '/.env';
            if (!file_exists($envFile)) {
                throw new \RuntimeException("Environment file not found");
            }
            
            $env = parse_ini_file($envFile);
            
            self::$db = new PDO(
                "mysql:host={$env['DB_HOST']};dbname={$env['DB_DATABASE']}",
                $env['DB_USERNAME'],
                $env['DB_PASSWORD'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        }
        
        return self::$db;
    }
    
    /**
     * Serve maintenance page and exit
     */
    private static function serveMaintenancePage(): void
    {
        http_response_code(503);
        header('Content-Type: text/html; charset=utf-8');
        header('Retry-After: 300');
        
        $templatePath = dirname(__DIR__, 2) . '/templates/maintenance.html';
        if (file_exists($templatePath)) {
            readfile($templatePath);
        } else {
            echo '<h1>503 Service Unavailable</h1><p>Site is under maintenance.</p>';
        }
        exit;
    }
}
