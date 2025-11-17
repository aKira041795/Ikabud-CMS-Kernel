<?php
/**
 * Phoenix Template Configuration
 * 
 * Centralized configuration for paths and constants
 * 
 * @package     Phoenix
 * @version     2.0.0
 */

defined('_JEXEC') or die;

/**
 * Phoenix Configuration Class
 */
class PhoenixConfig
{
    /**
     * Get kernel autoloader path
     */
    public static function getAutoloaderPath()
    {
        // Try relative path first (production)
        $relativePath = dirname(JPATH_ROOT) . '/vendor/autoload.php';
        if (file_exists($relativePath)) {
            return $relativePath;
        }
        
        // Fallback to absolute path (development)
        $absolutePath = '/var/www/html/ikabud-kernel/vendor/autoload.php';
        if (file_exists($absolutePath)) {
            return $absolutePath;
        }
        
        return null;
    }
    
    /**
     * Get DiSyL template path
     */
    public static function getDisylPath($template)
    {
        return JPATH_THEMES . '/' . $template . '/disyl';
    }
    
    /**
     * Get template includes path
     */
    public static function getIncludesPath($template)
    {
        return JPATH_THEMES . '/' . $template . '/includes';
    }
    
    /**
     * Check if debug mode is enabled
     */
    public static function isDebugMode()
    {
        $config = \Joomla\CMS\Factory::getConfig();
        return (bool) $config->get('debug');
    }
    
    /**
     * Get template version
     */
    public static function getVersion()
    {
        return '2.0.0';
    }
    
    /**
     * Get default template parameters
     */
    public static function getDefaults()
    {
        return [
            'stickyHeader' => 1,
            'showSearch' => 1,
            'footerColumns' => 4,
            'showSocial' => 1,
            'copyrightText' => '© 2025 All rights reserved.',
            'colorScheme' => 'default',
            'fluidContainer' => 0,
            'backTop' => 1,
            'sliderAutoplay' => 1,
            'sliderInterval' => 5000,
            'sliderTransition' => 'fade',
            'sliderShowArrows' => 1,
            'sliderShowDots' => 1,
            'layoutStyle' => 'boxed',
        ];
    }
}
