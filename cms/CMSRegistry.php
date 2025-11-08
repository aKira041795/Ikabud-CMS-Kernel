<?php
/**
 * CMS Registry - Process Table Management
 * 
 * Manages all CMS instances like a Linux process table
 * Tracks PIDs, routes, status, and provides process control
 * 
 * @version 1.0.0
 */

namespace IkabudKernel\CMS;

use IkabudKernel\Core\Kernel;
use PDO;
use Exception;

class CMSRegistry
{
    private static array $registry = [];
    private static ?string $activeCMS = null;
    private static array $routes = [];
    private static PDO $db;
    
    /**
     * Initialize the registry
     */
    public static function initialize(): void
    {
        $kernel = Kernel::getInstance();
        self::$db = $kernel->getDatabase();
        
        // Load active instances from database
        self::loadInstances();
    }
    
    /**
     * Register a CMS instance
     * 
     * @param string $name CMS name/identifier
     * @param CMSInterface $cms CMS instance
     * @param array $config Configuration (routes, database, etc.)
     * @return int Process ID (PID)
     */
    public static function register(string $name, CMSInterface $cms, array $config = []): int
    {
        // Register process in kernel
        $pid = Kernel::registerProcess($name, 'cms', [
            'instance_id' => $cms->getInstanceId(),
            'cms_type' => $cms->getType(),
            'memory_limit' => $config['memory_limit'] ?? 256
        ]);
        
        // Store in registry
        self::$registry[$name] = [
            'pid' => $pid,
            'cms' => $cms,
            'config' => $config,
            'routes' => $config['routes'] ?? [],
            'status' => 'registered',
            'boot_time' => null,
            'request_count' => 0,
            'last_request' => null
        ];
        
        // Register routes
        if (isset($config['routes'])) {
            foreach ($config['routes'] as $route) {
                self::registerRoute($route, $name);
            }
        }
        
        return $pid;
    }
    
    /**
     * Register a route for a CMS
     * 
     * @param string $route Route pattern
     * @param string $cmsName CMS name
     */
    private static function registerRoute(string $route, string $cmsName): void
    {
        // Normalize route
        $route = rtrim($route, '/');
        if (empty($route)) {
            $route = '/';
        }
        
        self::$routes[$route] = $cmsName;
    }
    
    /**
     * Route a request to the appropriate CMS
     * 
     * @param string $path Request path
     * @return string|null CMS name or null if no match
     */
    public static function route(string $path): ?string
    {
        // Normalize path
        $path = '/' . trim($path, '/');
        
        // Exact match first
        if (isset(self::$routes[$path])) {
            return self::$routes[$path];
        }
        
        // Prefix match (longest first)
        $matches = [];
        foreach (self::$routes as $route => $cmsName) {
            if ($route === '/') continue; // Skip root for now
            
            if (strpos($path, $route) === 0) {
                // Check path boundary
                $nextChar = substr($path, strlen($route), 1);
                if ($nextChar === '' || $nextChar === '/') {
                    $matches[$route] = $cmsName;
                }
            }
        }
        
        // Return longest match
        if (!empty($matches)) {
            krsort($matches); // Sort by key length descending
            return reset($matches);
        }
        
        // Default to root route
        return self::$routes['/'] ?? null;
    }
    
    /**
     * Get a CMS instance by name
     * 
     * @param string $name CMS name
     * @return CMSInterface|null CMS instance or null
     */
    public static function get(string $name): ?CMSInterface
    {
        return self::$registry[$name]['cms'] ?? null;
    }
    
    /**
     * Get active CMS
     * 
     * @return CMSInterface|null Active CMS instance
     */
    public static function getActive(): ?CMSInterface
    {
        if (self::$activeCMS === null) {
            return null;
        }
        
        return self::get(self::$activeCMS);
    }
    
    /**
     * Set active CMS
     * 
     * @param string $name CMS name
     */
    public static function setActive(string $name): void
    {
        if (!isset(self::$registry[$name])) {
            throw new Exception("CMS not registered: {$name}");
        }
        
        self::$activeCMS = $name;
    }
    
    /**
     * Boot a CMS instance
     * 
     * @param string $name CMS name
     * @return void
     */
    public static function boot(string $name): void
    {
        if (!isset(self::$registry[$name])) {
            throw new Exception("CMS not registered: {$name}");
        }
        
        $entry = &self::$registry[$name];
        
        if ($entry['status'] === 'booted') {
            return; // Already booted
        }
        
        $startTime = microtime(true);
        
        try {
            $cms = $entry['cms'];
            
            // Initialize if not already
            if (!$cms->isInitialized()) {
                $cms->initialize($entry['config']);
            }
            
            // Boot the CMS
            $cms->boot();
            
            $bootTime = (microtime(true) - $startTime) * 1000;
            
            $entry['status'] = 'booted';
            $entry['boot_time'] = $bootTime;
            
            // Update process in database
            $stmt = self::$db->prepare("
                UPDATE kernel_processes 
                SET status = 'running', boot_time = ?, started_at = NOW()
                WHERE pid = ?
            ");
            $stmt->execute([$bootTime, $entry['pid']]);
            
        } catch (Exception $e) {
            $entry['status'] = 'crashed';
            
            // Update process status
            $stmt = self::$db->prepare("
                UPDATE kernel_processes 
                SET status = 'crashed'
                WHERE pid = ?
            ");
            $stmt->execute([$entry['pid']]);
            
            throw new Exception("Failed to boot CMS '{$name}': " . $e->getMessage());
        }
    }
    
    /**
     * Shutdown a CMS instance
     * 
     * @param string $name CMS name
     */
    public static function shutdown(string $name): void
    {
        if (!isset(self::$registry[$name])) {
            return;
        }
        
        $entry = &self::$registry[$name];
        $cms = $entry['cms'];
        
        $cms->shutdown();
        
        $entry['status'] = 'stopped';
        
        // Update process
        $stmt = self::$db->prepare("
            UPDATE kernel_processes 
            SET status = 'stopped', stopped_at = NOW()
            WHERE pid = ?
        ");
        $stmt->execute([$entry['pid']]);
    }
    
    /**
     * Kill a CMS instance
     * 
     * @param string $name CMS name
     */
    public static function kill(string $name): void
    {
        self::shutdown($name);
        unset(self::$registry[$name]);
    }
    
    /**
     * Get all registered CMS instances
     * 
     * @return array Registry entries
     */
    public static function getAll(): array
    {
        $result = [];
        
        foreach (self::$registry as $name => $entry) {
            $result[$name] = [
                'pid' => $entry['pid'],
                'type' => $entry['cms']->getType(),
                'status' => $entry['status'],
                'boot_time' => $entry['boot_time'],
                'request_count' => $entry['request_count'],
                'routes' => $entry['routes']
            ];
        }
        
        return $result;
    }
    
    /**
     * Get registry statistics
     * 
     * @return array Statistics
     */
    public static function getStats(): array
    {
        return [
            'total_cms' => count(self::$registry),
            'active_cms' => self::$activeCMS,
            'total_routes' => count(self::$routes),
            'cms_list' => array_keys(self::$registry)
        ];
    }
    
    /**
     * Track a request to a CMS
     * 
     * @param string $name CMS name
     */
    public static function trackRequest(string $name): void
    {
        if (isset(self::$registry[$name])) {
            self::$registry[$name]['request_count']++;
            self::$registry[$name]['last_request'] = time();
        }
    }
    
    /**
     * Load instances from database
     */
    private static function loadInstances(): void
    {
        $stmt = self::$db->query("
            SELECT instance_id, instance_name, cms_type, config
            FROM instances
            WHERE status = 'active'
        ");
        
        $instances = $stmt->fetchAll();
        
        // Instances will be registered when needed
        // This just loads the configuration
    }
    
    /**
     * Check if CMS is registered
     * 
     * @param string $name CMS name
     * @return bool Registration status
     */
    public static function has(string $name): bool
    {
        return isset(self::$registry[$name]);
    }
    
    /**
     * Get CMS by instance ID
     * 
     * @param string $instanceId Instance ID
     * @return CMSInterface|null CMS instance
     */
    public static function getByInstanceId(string $instanceId): ?CMSInterface
    {
        foreach (self::$registry as $entry) {
            if ($entry['cms']->getInstanceId() === $instanceId) {
                return $entry['cms'];
            }
        }
        
        return null;
    }
}
