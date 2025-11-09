<?php
namespace IkabudKernel\Core;

/**
 * Configuration Manager
 * 
 * Loads and manages environment configuration from .env file
 */
class Config
{
    private static ?Config $instance = null;
    private array $config = [];
    private string $envFile;
    
    private function __construct()
    {
        $this->envFile = dirname(__DIR__) . '/.env';
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
     * Load environment variables from .env file
     */
    private function load(): void
    {
        if (!file_exists($this->envFile)) {
            // Try .env.example as fallback
            $exampleFile = dirname(__DIR__) . '/.env.example';
            if (file_exists($exampleFile)) {
                copy($exampleFile, $this->envFile);
            } else {
                return;
            }
        }
        
        $lines = file($this->envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        
        foreach ($lines as $line) {
            // Skip comments
            if (str_starts_with(trim($line), '#')) {
                continue;
            }
            
            // Parse KEY=VALUE
            if (strpos($line, '=') !== false) {
                list($key, $value) = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);
                
                // Remove quotes
                $value = trim($value, '"\'');
                
                $this->config[$key] = $value;
                
                // Also set as environment variable
                putenv("$key=$value");
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }
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
     * Reload configuration from file
     */
    public static function reload(): void
    {
        $instance = self::getInstance();
        $instance->config = [];
        $instance->load();
    }
}
