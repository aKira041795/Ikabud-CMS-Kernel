<?php
/**
 * DiSyL Manifest Loader v0.2
 * 
 * Enhanced manifest loader with:
 * - Component inheritance
 * - Capabilities validation
 * - Expression filters
 * - Manifest caching
 * - Hook system
 * - JSON Schema validation
 * 
 * @version 0.2.0
 */

namespace IkabudKernel\Core\DiSyL;

class ManifestLoader
{
    private static ?array $manifest = null;
    private static ?array $compiledManifest = null;
    private static string $manifestPath = __DIR__ . '/ComponentManifest.v0.2.json';
    private static string $schemaPath = __DIR__ . '/manifest.schema.json';
    private static string $cacheDir = __DIR__ . '/../../storage/cache/';
    
    /**
     * Load manifest from JSON file with caching
     */
    public static function load(bool $useCache = true): array
    {
        if (self::$manifest !== null) {
            return self::$manifest;
        }
        
        // Try to load from cache
        if ($useCache && self::loadFromCache()) {
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
        
        // Resolve component inheritance
        self::resolveInheritance();
        
        // Save to cache
        if ($useCache) {
            self::saveToCache();
        }
        
        return self::$manifest;
    }
    
    /**
     * Resolve component inheritance (extends)
     */
    private static function resolveInheritance(): void
    {
        $manifest = &self::$manifest;
        
        // Resolve CMS component inheritance
        foreach ($manifest['cms_adapters'] ?? [] as $cmsType => &$adapter) {
            foreach ($adapter['components'] ?? [] as $componentName => &$component) {
                if (isset($component['extends'])) {
                    $baseComponent = $manifest['base_components'][$component['extends']] ?? null;
                    if ($baseComponent) {
                        // Merge base component with current (current overrides base)
                        $component = array_replace_recursive($baseComponent, $component);
                        unset($component['extends']); // Remove extends after resolution
                    }
                }
            }
        }
        
        // Resolve universal component inheritance
        foreach ($manifest['universal_components'] ?? [] as $componentName => &$component) {
            if (isset($component['extends'])) {
                $baseComponent = $manifest['base_components'][$component['extends']] ?? null;
                if ($baseComponent) {
                    $component = array_replace_recursive($baseComponent, $component);
                    unset($component['extends']);
                }
            }
        }
    }
    
    /**
     * Load manifest from cache
     */
    private static function loadFromCache(): bool
    {
        $hash = self::getManifestHash();
        $cacheFile = self::$cacheDir . "manifest.{$hash}.compiled";
        
        if (file_exists($cacheFile)) {
            $cached = include $cacheFile;
            if (is_array($cached)) {
                self::$manifest = $cached;
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Save manifest to cache
     */
    private static function saveToCache(): void
    {
        if (!is_dir(self::$cacheDir)) {
            mkdir(self::$cacheDir, 0755, true);
        }
        
        $hash = self::getManifestHash();
        $cacheFile = self::$cacheDir . "manifest.{$hash}.compiled";
        
        // Write as PHP array for OPcache optimization
        $export = var_export(self::$manifest, true);
        file_put_contents($cacheFile, "<?php\nreturn {$export};\n");
        
        // Clear old cache files
        self::clearOldCacheFiles($hash);
    }
    
    /**
     * Get manifest file hash
     */
    private static function getManifestHash(): string
    {
        return md5_file(self::$manifestPath);
    }
    
    /**
     * Clear old cache files
     */
    private static function clearOldCacheFiles(string $currentHash): void
    {
        $pattern = self::$cacheDir . 'manifest.*.compiled';
        foreach (glob($pattern) as $file) {
            if (strpos($file, $currentHash) === false) {
                @unlink($file);
            }
        }
    }
    
    /**
     * Get component with inheritance resolved
     */
    public static function getComponent(string $componentName, ?string $cmsType = null): ?array
    {
        $manifest = self::load();
        
        // Try CMS-specific first
        if ($cmsType && isset($manifest['cms_adapters'][$cmsType]['components'][$componentName])) {
            return $manifest['cms_adapters'][$cmsType]['components'][$componentName];
        }
        
        // Fall back to universal
        return $manifest['universal_components'][$componentName] ?? null;
    }
    
    /**
     * Get component capabilities
     */
    public static function getCapabilities(string $componentName, ?string $cmsType = null): ?array
    {
        $component = self::getComponent($componentName, $cmsType);
        return $component['capabilities'] ?? null;
    }
    
    /**
     * Validate component supports children
     */
    public static function supportsChildren(string $componentName, ?string $cmsType = null): bool
    {
        $capabilities = self::getCapabilities($componentName, $cmsType);
        return $capabilities['supports_children'] ?? false;
    }
    
    /**
     * Get component output mode
     */
    public static function getOutputMode(string $componentName, ?string $cmsType = null): ?string
    {
        $capabilities = self::getCapabilities($componentName, $cmsType);
        return $capabilities['output_mode'] ?? null;
    }
    
    /**
     * Get context variables provided by component
     */
    public static function getProvidesContext(string $componentName, ?string $cmsType = null): array
    {
        $capabilities = self::getCapabilities($componentName, $cmsType);
        return $capabilities['provides_context'] ?? [];
    }
    
    /**
     * Get expression filter
     */
    public static function getFilter(string $filterName): ?array
    {
        $manifest = self::load();
        return $manifest['filters'][$filterName] ?? null;
    }
    
    /**
     * Get all filters
     */
    public static function getFilters(): array
    {
        $manifest = self::load();
        return $manifest['filters'] ?? [];
    }
    
    /**
     * Apply filter to value
     */
    public static function applyFilter(string $filterName, $value, array $params = [])
    {
        $filter = self::getFilter($filterName);
        if (!$filter) {
            return $value;
        }
        
        // Get PHP implementation
        $phpCode = $filter['php'] ?? null;
        if (!$phpCode) {
            return $value;
        }
        
        // Replace placeholders
        $phpCode = str_replace('{value}', var_export($value, true), $phpCode);
        foreach ($params as $key => $paramValue) {
            $phpCode = str_replace("{{$key}}", var_export($paramValue, true), $phpCode);
        }
        
        // Evaluate (be careful with this in production!)
        try {
            return eval("return {$phpCode};");
        } catch (\Throwable $e) {
            error_log("Filter '{$filterName}' failed: " . $e->getMessage());
            return $value;
        }
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
     * Execute hook
     */
    public static function executeHook(string $cmsType, string $hookName, ...$args)
    {
        $hooks = self::getCMSHooks($cmsType);
        $hookCallback = $hooks[$hookName] ?? null;
        
        if ($hookCallback && is_callable($hookCallback)) {
            return call_user_func_array($hookCallback, $args);
        }
        
        return null;
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
     * Check if component is deprecated
     */
    public static function isDeprecated(string $componentName): bool
    {
        $manifest = self::load();
        return isset($manifest['deprecated'][$componentName]);
    }
    
    /**
     * Get deprecation info
     */
    public static function getDeprecationInfo(string $componentName): ?array
    {
        $manifest = self::load();
        return $manifest['deprecated'][$componentName] ?? null;
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
        self::$manifest = array_replace_recursive($manifest, $customManifest);
        
        // Re-resolve inheritance after merge
        self::resolveInheritance();
    }
    
    /**
     * Clear cached manifest (for testing)
     */
    public static function clearCache(): void
    {
        self::$manifest = null;
        self::$compiledManifest = null;
        
        // Clear cache files
        $pattern = self::$cacheDir . 'manifest.*.compiled';
        foreach (glob($pattern) as $file) {
            @unlink($file);
        }
    }
    
    /**
     * Validate manifest structure against JSON Schema
     */
    public static function validate(): array
    {
        $manifest = self::load(false); // Don't use cache for validation
        $errors = [];
        
        // Basic structure validation
        $requiredKeys = ['version', 'description'];
        foreach ($requiredKeys as $key) {
            if (!isset($manifest[$key])) {
                $errors[] = "Missing required key: {$key}";
            }
        }
        
        // Validate version format
        if (isset($manifest['version']) && !preg_match('/^\d+\.\d+\.\d+$/', $manifest['version'])) {
            $errors[] = "Invalid version format: {$manifest['version']} (expected semver)";
        }
        
        // Validate CMS adapters
        foreach ($manifest['cms_adapters'] ?? [] as $cmsType => $adapter) {
            if (!isset($adapter['name'])) {
                $errors[] = "CMS adapter '{$cmsType}' missing 'name'";
            }
            if (!isset($adapter['version'])) {
                $errors[] = "CMS adapter '{$cmsType}' missing 'version'";
            }
            if (!isset($adapter['components'])) {
                $errors[] = "CMS adapter '{$cmsType}' missing 'components'";
            }
        }
        
        // Validate component capabilities
        $allComponents = array_merge(
            $manifest['universal_components'] ?? [],
            ...array_column($manifest['cms_adapters'] ?? [], 'components')
        );
        
        foreach ($allComponents as $name => $component) {
            if (isset($component['capabilities'])) {
                $caps = $component['capabilities'];
                
                // Validate output_mode
                $validModes = ['inline', 'container', 'loop', 'conditional', 'include'];
                if (isset($caps['output_mode']) && !in_array($caps['output_mode'], $validModes)) {
                    $errors[] = "Component '{$name}' has invalid output_mode: {$caps['output_mode']}";
                }
                
                // Validate provides_context is array
                if (isset($caps['provides_context']) && !is_array($caps['provides_context'])) {
                    $errors[] = "Component '{$name}' provides_context must be an array";
                }
            }
        }
        
        // Validate filters
        foreach ($manifest['filters'] ?? [] as $filterName => $filter) {
            if (!isset($filter['php'])) {
                $errors[] = "Filter '{$filterName}' missing 'php' implementation";
            }
            if (!isset($filter['description'])) {
                $errors[] = "Filter '{$filterName}' missing 'description'";
            }
        }
        
        return $errors;
    }
    
    /**
     * Get manifest version
     */
    public static function getVersion(): string
    {
        $manifest = self::load();
        return $manifest['version'] ?? '0.0.0';
    }
    
    /**
     * Get cache configuration
     */
    public static function getCacheConfig(): array
    {
        $manifest = self::load();
        return $manifest['cache'] ?? [];
    }
}
