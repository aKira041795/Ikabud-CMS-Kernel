<?php
namespace IkabudKernel\Core;

/**
 * Configuration Manager
 * 
 * Provides unified access to environment and kernel configuration.
 * Integrates with Kernel's APCu caching for performance.
 * 
 * @version 1.2.0
 */
class Config
{
    private static ?Config $instance = null;
    private array $config = [];
    private string $envFile;
    private static ?bool $apcuAvailable = null;
    private const APCU_KEY = 'ikabud_config_manager';
    private const APCU_TTL = 300; // 5 minutes
    
    private function __construct()
    {
        $this->envFile = dirname(__DIR__) . '/.env';
        self::$apcuAvailable = function_exists('apcu_fetch') && apcu_enabled();
        $this->load();
    }
    
    /**
     * Get singleton instance
     */
    public static function getInstance(): Config
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Load environment variables from .env file or cache
     */
    private function load(): void
    {
        // Try APCu cache first
        if (self::$apcuAvailable) {
            $cached = apcu_fetch(self::APCU_KEY);
            if ($cached !== false) {
                $this->config = $cached;
                // Still set env vars from cache
                foreach ($this->config as $key => $value) {
                    if (!isset($_ENV[$key])) {
                        $_ENV[$key] = $value;
                        putenv("$key=$value");
                    }
                }
                return;
            }
        }
        
        // Check if Kernel has already loaded env
        if (class_exists('\IkabudKernel\Core\Kernel') && Kernel::isBooted()) {
            // Use Kernel's config if available
            $kernel = Kernel::getInstance();
            $kernelConfig = $kernel->getConfig();
            if (!empty($kernelConfig)) {
                $this->config = array_merge($_ENV, $kernelConfig);
                $this->cacheConfig();
                return;
            }
        }
        
        // Load from file
        if (!file_exists($this->envFile)) {
            $exampleFile = dirname(__DIR__) . '/.env.example';
            if (file_exists($exampleFile)) {
                copy($exampleFile, $this->envFile);
            } else {
                return;
            }
        }
        
        $lines = file($this->envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || str_starts_with($line, '#')) {
                continue;
            }
            
            if (strpos($line, '=') !== false) {
                list($key, $value) = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value, " \t\n\r\0\x0B\"'");
                
                $this->config[$key] = $value;
                putenv("$key=$value");
                $_ENV[$key] = $value;
            }
        }
        
        $this->cacheConfig();
    }
    
    /**
     * Cache config in APCu (excluding sensitive data)
     */
    private function cacheConfig(): void
    {
        if (self::$apcuAvailable) {
            $safeConfig = array_diff_key(
                $this->config, 
                array_flip(['DB_PASSWORD', 'APP_KEY', 'JWT_SECRET', 'MAIL_PASSWORD'])
            );
            apcu_store(self::APCU_KEY, $safeConfig, self::APCU_TTL);
        }
    }
    
    /**
     * Get configuration value
     */
    public static function get(string $key, $default = null)
    {
        $instance = self::getInstance();
        return $instance->config[$key] ?? $default;
    }
    
    /**
     * Set configuration value
     */
    public static function set(string $key, $value): void
    {
        $instance = self::getInstance();
        $instance->config[$key] = $value;
        putenv("$key=$value");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
    
    /**
     * Check if configuration key exists
     */
    public static function has(string $key): bool
    {
        $instance = self::getInstance();
        return isset($instance->config[$key]);
    }
    
    /**
     * Get all configuration
     */
    public static function all(): array
    {
        $instance = self::getInstance();
        return $instance->config;
    }
    
    /**
     * Reload configuration from file (clears cache)
     */
    public static function reload(): void
    {
        // Clear APCu cache
        if (self::$apcuAvailable) {
            apcu_delete(self::APCU_KEY);
        }
        
        $instance = self::getInstance();
        $instance->config = [];
        $instance->load();
    }
    
    /**
     * Clear configuration cache
     */
    public static function clearCache(): void
    {
        if (self::$apcuAvailable) {
            apcu_delete(self::APCU_KEY);
        }
    }
}
