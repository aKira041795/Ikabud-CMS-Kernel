<?php
/**
 * DiSyL Manifest Loader
 * 
 * Loads and manages CMS-specific component mappings from JSON manifest
 * Enables extensible CMS adapters without modifying core code
 * 
 * @version 0.1.0
 */

namespace IkabudKernel\Core\DiSyL;

class ManifestLoader
{
    private static ?array $manifest = null;
    private static string $manifestPath = __DIR__ . '/ComponentManifest.json';
    
    /**
     * Load manifest from JSON file
     */
    public static function load(): array
    {
        if (self::$manifest !== null) {
            return self::$manifest;
        }
        
        if (!file_exists(self::$manifestPath)) {
            throw new \RuntimeException("Component manifest not found: " . self::$manifestPath);
        }
        
        $json = file_get_contents(self::$manifestPath);
        self::$manifest = json_decode($json, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException("Invalid JSON in manifest: " . json_last_error_msg());
        }
        
        return self::$manifest;
    }
    
    /**
     * Get CMS-specific component mapping
     */
    public static function getCMSComponent(string $cmsType, string $componentName): ?array
    {
        $manifest = self::load();
        
        return $manifest['cms_adapters'][$cmsType]['components'][$componentName] ?? null;
    }
    
    /**
     * Get universal component mapping
     */
    public static function getUniversalComponent(string $componentName): ?array
    {
        $manifest = self::load();
        
        return $manifest['universal_components'][$componentName] ?? null;
    }
    
    /**
     * Get control structure definition
     */
    public static function getControlStructure(string $name): ?array
    {
        $manifest = self::load();
        
        return $manifest['control_structures'][$name] ?? null;
    }
    
    /**
     * Get all components for a CMS
     */
    public static function getCMSComponents(string $cmsType): array
    {
        $manifest = self::load();
        
        return $manifest['cms_adapters'][$cmsType]['components'] ?? [];
    }
    
    /**
     * Get CMS hooks
     */
    public static function getCMSHooks(string $cmsType): array
    {
        $manifest = self::load();
        
        return $manifest['cms_adapters'][$cmsType]['hooks'] ?? [];
    }
    
    /**
     * Get context variable mapping for CMS
     */
    public static function getContextVariables(string $cmsType, string $componentName): array
    {
        $manifest = self::load();
        
        return $manifest['cms_adapters'][$cmsType]['components'][$componentName]['context_variables'] ?? [];
    }
    
    /**
     * Check if CMS is supported
     */
    public static function isCMSSupported(string $cmsType): bool
    {
        $manifest = self::load();
        
        return isset($manifest['cms_adapters'][$cmsType]);
    }
    
    /**
     * Get list of supported CMS types
     */
    public static function getSupportedCMS(): array
    {
        $manifest = self::load();
        
        return array_keys($manifest['cms_adapters']);
    }
    
    /**
     * Register custom manifest (for third-party extensions)
     */
    public static function registerCustomManifest(string $path): void
    {
        if (!file_exists($path)) {
            throw new \RuntimeException("Custom manifest not found: " . $path);
        }
        
        $json = file_get_contents($path);
        $customManifest = json_decode($json, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException("Invalid JSON in custom manifest: " . json_last_error_msg());
        }
        
        // Merge custom manifest with main manifest
        $manifest = self::load();
        self::$manifest = array_merge_recursive($manifest, $customManifest);
    }
    
    /**
     * Clear cached manifest (for testing)
     */
    public static function clearCache(): void
    {
        self::$manifest = null;
    }
    
    /**
     * Validate manifest structure
     */
    public static function validate(): array
    {
        $manifest = self::load();
        $errors = [];
        
        // Check required top-level keys
        $requiredKeys = ['version', 'cms_adapters', 'universal_components', 'control_structures'];
        foreach ($requiredKeys as $key) {
            if (!isset($manifest[$key])) {
                $errors[] = "Missing required key: {$key}";
            }
        }
        
        // Validate CMS adapters
        foreach ($manifest['cms_adapters'] ?? [] as $cmsType => $adapter) {
            if (!isset($adapter['name'])) {
                $errors[] = "CMS adapter '{$cmsType}' missing 'name'";
            }
            if (!isset($adapter['components'])) {
                $errors[] = "CMS adapter '{$cmsType}' missing 'components'";
            }
        }
        
        return $errors;
    }
}
