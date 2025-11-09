<?php
namespace IkabudKernel\Core;

/**
 * Conditional Loader Factory
 * 
 * Creates appropriate conditional loader based on CMS type
 */
class ConditionalLoaderFactory
{
    /**
     * Create conditional loader for instance
     * 
     * @param string $instanceDir Instance directory path
     * @param string $cmsType CMS type (wordpress, joomla, drupal, etc.)
     * @return ConditionalLoaderInterface|null
     */
    public static function create(string $instanceDir, string $cmsType): ?ConditionalLoaderInterface
    {
        $cmsType = strtolower($cmsType);
        
        switch ($cmsType) {
            case 'wordpress':
                return new WordPressConditionalLoader($instanceDir);
                
            case 'joomla':
                return new JoomlaConditionalLoader($instanceDir);
                
            case 'drupal':
                // Future implementation
                return null;
                
            default:
                error_log("Unknown CMS type for conditional loading: $cmsType");
                return null;
        }
    }
    
    /**
     * Detect CMS type from instance directory
     * 
     * @param string $instanceDir Instance directory path
     * @return string|null
     */
    public static function detectCMSType(string $instanceDir): ?string
    {
        // Check for WordPress
        if (file_exists($instanceDir . '/wp-config.php') || 
            file_exists($instanceDir . '/wp-load.php')) {
            return 'wordpress';
        }
        
        // Check for Joomla
        if (file_exists($instanceDir . '/configuration.php') && 
            is_dir($instanceDir . '/administrator')) {
            return 'joomla';
        }
        
        // Check for Drupal
        if (file_exists($instanceDir . '/sites/default/settings.php')) {
            return 'drupal';
        }
        
        return null;
    }
    
    /**
     * Check if conditional loading is available for CMS type
     * 
     * @param string $cmsType
     * @return bool
     */
    public static function isSupported(string $cmsType): bool
    {
        $cmsType = strtolower($cmsType);
        return in_array($cmsType, ['wordpress', 'joomla']);
    }
}
