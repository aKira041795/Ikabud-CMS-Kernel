<?php
/**
 * Modular Manifest Loader v0.4.0
 * 
 * Loads manifests using profiles, mount points, and namespaces
 * 
 * @version 0.4.0
 */

namespace IkabudKernel\Core\DiSyL;

class ModularManifestLoader
{
    private static ?array $config = null;
    private static array $loadedManifests = [];
    private static array $registry = [];
    private static array $namespaces = [];
    private static string $currentProfile = 'full';
    private static ?string $cmsType = null;
    
    /**
     * Initialize with profile and CMS type
     */
    public static function init(string $profile = 'full', ?string $cmsType = null): void
    {
        self::$currentProfile = $profile;
        self::$cmsType = $cmsType;
        self::loadConfig();
        self::loadProfile($profile);
        self::buildRegistry();
    }
    
    /**
     * Load configuration
     */
    private static function loadConfig(): void
    {
        $configPath = __DIR__ . '/Manifests/manifest.config.json';
        
        if (!file_exists($configPath)) {
            throw new \Exception('Manifest configuration not found');
        }
        
        self::$config = json_decode(file_get_contents($configPath), true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Invalid manifest configuration: ' . json_last_error_msg());
        }
        
        // Load namespaces
        if (isset(self::$config['namespaces']['registry'])) {
            self::$namespaces = self::$config['namespaces']['registry'];
        }
    }
    
    /**
     * Load profile
     */
    private static function loadProfile(string $profileName): void
    {
        $profilePath = __DIR__ . '/Manifests/profiles/' . $profileName . '.json';
        
        if (!file_exists($profilePath)) {
            error_log('[DiSyL] Profile not found: ' . $profileName . ', using full');
            $profilePath = __DIR__ . '/Manifests/profiles/full.json';
        }
        
        $profile = json_decode(file_get_contents($profilePath), true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Invalid profile: ' . json_last_error_msg());
        }
        
        // Load manifests specified in profile
        foreach ($profile['load']['manifests'] as $manifestPath) {
            // Replace {cms} placeholder
            $manifestPath = str_replace('{cms}', self::$cmsType ?? 'WordPress', $manifestPath);
            self::loadManifest($manifestPath);
        }
        
        error_log(sprintf(
            '[DiSyL] Loaded profile "%s": %d manifests',
            $profileName,
            count($profile['load']['manifests'])
        ));
    }
    
    /**
     * Load individual manifest
     */
    private static function loadManifest(string $relativePath): void
    {
        $fullPath = __DIR__ . '/Manifests/' . $relativePath;
        
        if (!file_exists($fullPath)) {
            error_log('[DiSyL] Manifest not found: ' . $relativePath);
            return;
        }
        
        $manifest = json_decode(file_get_contents($fullPath), true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('[DiSyL] Invalid manifest: ' . $relativePath);
            return;
        }
        
        self::$loadedManifests[$relativePath] = $manifest;
    }
    
    /**
     * Build component registry
     */
    private static function buildRegistry(): void
    {
        $registryPath = __DIR__ . '/Manifests/registry.json';
        
        if (file_exists($registryPath)) {
            self::$registry = json_decode(file_get_contents($registryPath), true);
        }
    }
    
    /**
     * Get all filters
     */
    public static function getFilters(): array
    {
        $filters = [];
        
        foreach (self::$loadedManifests as $path => $manifest) {
            if (isset($manifest['filters'])) {
                $filters = array_merge($filters, $manifest['filters']);
            }
        }
        
        return $filters;
    }
    
    /**
     * Get specific filter
     */
    public static function getFilter(string $name): ?array
    {
        $filters = self::getFilters();
        return $filters[$name] ?? null;
    }
    
    /**
     * Apply filter to value
     */
    public static function applyFilter(string $filterName, $value, array $params = [])
    {
        $filter = self::getFilter($filterName);
        
        if (!$filter) {
            error_log('[DiSyL] Unknown filter: ' . $filterName);
            return $value;
        }
        
        // Get PHP implementation
        $phpCode = $filter['php'] ?? null;
        
        if (!$phpCode) {
            return $value;
        }
        
        // Replace placeholders
        $phpCode = str_replace('{value}', '$value', $phpCode);
        
        // Replace parameters
        foreach ($params as $key => $val) {
            $phpCode = str_replace('{' . $key . '}', var_export($val, true), $phpCode);
        }
        
        // Evaluate
        try {
            return eval('return ' . $phpCode . ';');
        } catch (\Throwable $e) {
            error_log('[DiSyL] Filter error: ' . $e->getMessage());
            return $value;
        }
    }
    
    /**
     * Get all components
     */
    public static function getComponents(): array
    {
        return self::$registry['components'] ?? [];
    }
    
    /**
     * Get component by namespaced name
     */
    public static function getComponent(string $namespacedName): ?array
    {
        $components = self::getComponents();
        return $components[$namespacedName] ?? null;
    }
    
    /**
     * Resolve namespace
     */
    public static function resolveNamespace(string $namespacedName): ?string
    {
        if (strpos($namespacedName, ':') === false) {
            return $namespacedName;
        }
        
        list($namespace, $name) = explode(':', $namespacedName, 2);
        
        if (isset(self::$namespaces[$namespace])) {
            return 'ikb_' . $name;
        }
        
        return $namespacedName;
    }
    
    /**
     * Get loaded manifests
     */
    public static function getLoadedManifests(): array
    {
        return array_keys(self::$loadedManifests);
    }
    
    /**
     * Get registry
     */
    public static function getRegistry(): array
    {
        return self::$registry;
    }
    
    /**
     * List components by category
     */
    public static function getByCategory(string $category): array
    {
        $categories = self::$registry['categories'] ?? [];
        return $categories[$category] ?? [];
    }
    
    /**
     * Get component metadata
     */
    public static function getComponentMeta(string $namespacedName): ?array
    {
        return self::getComponent($namespacedName);
    }
    
    /**
     * Get filter signature
     */
    public static function getFilterSignature(string $filterName): ?string
    {
        $filter = self::getFilter($filterName);
        
        if (!$filter) {
            return null;
        }
        
        $params = [];
        if (isset($filter['params'])) {
            foreach ($filter['params'] as $paramName => $paramDef) {
                $params[] = $paramName . ': ' . $paramDef['type'];
            }
        }
        
        return $filterName . '(' . implode(', ', $params) . ')';
    }
    
    /**
     * Get current profile
     */
    public static function getCurrentProfile(): string
    {
        return self::$currentProfile;
    }
    
    /**
     * Get version
     */
    public static function getVersion(): string
    {
        return self::$config['version'] ?? '0.4.0';
    }
}
