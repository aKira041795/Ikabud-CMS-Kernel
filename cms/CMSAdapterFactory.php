<?php
/**
 * CMS Adapter Factory
 * 
 * Creates appropriate CMS adapter based on CMS type
 * Provides centralized adapter instantiation
 * 
 * @version 1.0.0
 */

namespace IkabudKernel\CMS;

use IkabudKernel\CMS\Adapters\WordPressAdapter;
use IkabudKernel\CMS\Adapters\DrupalAdapter;
use IkabudKernel\CMS\Adapters\NativeAdapter;
use Exception;

class CMSAdapterFactory
{
    /**
     * Create CMS adapter based on type
     * 
     * @param string $cmsType CMS type (wordpress, drupal, joomla, native)
     * @param string|null $corePath Path to CMS core (optional)
     * @return CMSInterface CMS adapter instance
     * @throws Exception If CMS type is not supported
     */
    public static function create(string $cmsType, ?string $corePath = null): CMSInterface
    {
        $cmsType = strtolower($cmsType);
        
        switch ($cmsType) {
            case 'wordpress':
                $corePath = $corePath ?? __DIR__ . '/../shared-cores/wordpress';
                return new WordPressAdapter($corePath);
                
            case 'drupal':
                $corePath = $corePath ?? __DIR__ . '/../shared-cores/drupal';
                return new DrupalAdapter($corePath);
                
            case 'joomla':
                // Joomla adapter not yet implemented
                // For now, use native adapter
                return new NativeAdapter();
                
            case 'native':
                return new NativeAdapter();
                
            default:
                throw new Exception("Unsupported CMS type: {$cmsType}");
        }
    }
    
    /**
     * Detect CMS type from instance directory
     * 
     * @param string $instanceDir Instance directory path
     * @return string|null CMS type or null if not detected
     */
    public static function detectCMSType(string $instanceDir): ?string
    {
        // Check for WordPress
        if (file_exists($instanceDir . '/wp-config.php') || 
            file_exists($instanceDir . '/wp-content')) {
            return 'wordpress';
        }
        
        // Check for Drupal
        if (file_exists($instanceDir . '/sites/default/settings.php') ||
            is_dir($instanceDir . '/core')) {
            return 'drupal';
        }
        
        // Check for Joomla
        if (file_exists($instanceDir . '/configuration.php') && 
            is_dir($instanceDir . '/administrator')) {
            return 'joomla';
        }
        
        return null;
    }
    
    /**
     * Check if CMS type is supported
     * 
     * @param string $cmsType CMS type
     * @return bool True if supported
     */
    public static function isSupported(string $cmsType): bool
    {
        $cmsType = strtolower($cmsType);
        return in_array($cmsType, ['wordpress', 'drupal', 'joomla', 'native']);
    }
    
    /**
     * Get list of supported CMS types
     * 
     * @return array List of supported CMS types
     */
    public static function getSupportedTypes(): array
    {
        return [
            'wordpress' => [
                'name' => 'WordPress',
                'adapter' => 'WordPressAdapter',
                'conditional_loading' => true
            ],
            'drupal' => [
                'name' => 'Drupal',
                'adapter' => 'DrupalAdapter',
                'conditional_loading' => true
            ],
            'joomla' => [
                'name' => 'Joomla',
                'adapter' => 'NativeAdapter',
                'conditional_loading' => true
            ],
            'native' => [
                'name' => 'Native (No CMS)',
                'adapter' => 'NativeAdapter',
                'conditional_loading' => false
            ]
        ];
    }
    
    /**
     * Create adapter with auto-detection
     * 
     * @param string $instanceDir Instance directory
     * @param string|null $corePath Optional core path
     * @return CMSInterface CMS adapter
     * @throws Exception If CMS type cannot be detected
     */
    public static function createFromInstance(string $instanceDir, ?string $corePath = null): CMSInterface
    {
        $cmsType = self::detectCMSType($instanceDir);
        
        if ($cmsType === null) {
            throw new Exception("Could not detect CMS type for instance: {$instanceDir}");
        }
        
        return self::create($cmsType, $corePath);
    }
}
