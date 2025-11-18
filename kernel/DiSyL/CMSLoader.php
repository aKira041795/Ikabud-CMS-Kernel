<?php
/**
 * DiSyL CMS Loader
 * 
 * Handles loading and registration of CMS-specific manifests
 * based on {ikb_cms} header declarations
 * 
 * @version 0.6.0
 */

namespace IkabudKernel\Core\DiSyL;

use IkabudKernel\Core\DiSyL\Exceptions\CMSLoaderException;

class CMSLoader
{
    private static array $loadedCMS = [];
    private static array $validCMSTypes = ['wordpress', 'drupal', 'joomla', 'generic'];
    private static array $validSets = ['filters', 'components', 'renderers', 'views', 'functions', 'hooks', 'context'];
    
    /**
     * Load CMS-specific manifests
     * 
     * @param string $cmsType CMS type (wordpress, drupal, joomla, generic)
     * @param array $sets Sets to load (filters, components, etc.)
     * @return array Loaded manifest data
     * @throws CMSLoaderException if CMS type is invalid
     */
    public static function load(string $cmsType, array $sets = []): array
    {
        // Validate CMS type
        $cmsType = strtolower($cmsType);
        if (!in_array($cmsType, self::$validCMSTypes)) {
            throw new CMSLoaderException(
                "Invalid CMS type '{$cmsType}'. Valid types: " . implode(', ', self::$validCMSTypes)
            );
        }
        
        // Validate sets
        foreach ($sets as $set) {
            if (!in_array($set, self::$validSets)) {
                throw new CMSLoaderException(
                    "Invalid set '{$set}'. Valid sets: " . implode(', ', self::$validSets)
                );
            }
        }
        
        // If no sets specified, load all available
        if (empty($sets)) {
            $sets = self::$validSets;
        }
        
        // Check if already loaded
        $cacheKey = $cmsType . ':' . implode(',', $sets);
        if (isset(self::$loadedCMS[$cacheKey])) {
            return self::$loadedCMS[$cacheKey];
        }
        
        // Load manifests using ModularManifestLoader
        $manifestData = [
            'cms_type' => $cmsType,
            'sets' => $sets,
            'components' => [],
            'filters' => [],
            'hooks' => [],
            'functions' => [],
            'context' => []
        ];
        
        // Initialize ModularManifestLoader if not already done
        if (!class_exists('\\IkabudKernel\\Core\\DiSyL\\ModularManifestLoader')) {
            throw new CMSLoaderException('ModularManifestLoader not available');
        }
        
        // Initialize with CMS type
        ModularManifestLoader::init('full', $cmsType);
        
        // Load requested sets
        foreach ($sets as $set) {
            switch ($set) {
                case 'filters':
                    $manifestData['filters'] = ModularManifestLoader::getFilters();
                    break;
                    
                case 'components':
                    $manifestData['components'] = ModularManifestLoader::getComponents();
                    break;
                    
                case 'hooks':
                case 'functions':
                case 'context':
                case 'renderers':
                case 'views':
                    // These will be loaded from specific manifest files
                    $manifestData[$set] = self::loadSet($cmsType, $set);
                    break;
            }
        }
        
        // Register components with ComponentRegistry
        foreach ($manifestData['components'] as $name => $component) {
            $fullName = $component['full_name'] ?? $name;
            if (!ComponentRegistry::has($fullName)) {
                ComponentRegistry::register($fullName, $component);
            }
        }
        
        // Cache the loaded data
        self::$loadedCMS[$cacheKey] = $manifestData;
        
        return $manifestData;
    }
    
    /**
     * Load a specific set from manifest files
     * 
     * @param string $cmsType CMS type
     * @param string $set Set name
     * @return array Set data
     */
    private static function loadSet(string $cmsType, string $set): array
    {
        $cmsFolder = match($cmsType) {
            'wordpress' => 'WordPress',
            'drupal' => 'Drupal',
            'joomla' => 'Joomla',
            'generic' => 'Core',
            default => ucfirst($cmsType)
        };
        
        $manifestPath = __DIR__ . '/Manifests/' . $cmsFolder . '/' . $set . '.manifest.json';
        
        if (!file_exists($manifestPath)) {
            // Silently return empty array if manifest doesn't exist
            return [];
        }
        
        $json = file_get_contents($manifestPath);
        $data = json_decode($json, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new CMSLoaderException(
                "Invalid JSON in {$manifestPath}: " . json_last_error_msg()
            );
        }
        
        // Return the relevant section
        return $data[$set] ?? $data;
    }
    
    /**
     * Validate CMS type
     * 
     * @param string $cmsType CMS type to validate
     * @return bool True if valid
     */
    public static function isValidCMSType(string $cmsType): bool
    {
        return in_array(strtolower($cmsType), self::$validCMSTypes);
    }
    
    /**
     * Validate set name
     * 
     * @param string $set Set name to validate
     * @return bool True if valid
     */
    public static function isValidSet(string $set): bool
    {
        return in_array(strtolower($set), self::$validSets);
    }
    
    /**
     * Get list of valid CMS types
     * 
     * @return array Valid CMS types
     */
    public static function getValidCMSTypes(): array
    {
        return self::$validCMSTypes;
    }
    
    /**
     * Get list of valid sets
     * 
     * @return array Valid sets
     */
    public static function getValidSets(): array
    {
        return self::$validSets;
    }
    
    /**
     * Clear loaded CMS cache
     */
    public static function clearCache(): void
    {
        self::$loadedCMS = [];
    }
    
    /**
     * Get loaded CMS data
     * 
     * @param string $cmsType CMS type
     * @param array $sets Sets
     * @return array|null Loaded data or null if not loaded
     */
    public static function getLoaded(string $cmsType, array $sets = []): ?array
    {
        $cacheKey = $cmsType . ':' . implode(',', $sets);
        return self::$loadedCMS[$cacheKey] ?? null;
    }
}
