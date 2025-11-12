<?php
/**
 * Asset Loader
 * 
 * Automatically loads common dependencies like jQuery for CMS instances
 */

namespace IkabudKernel\Core;

class AssetLoader
{
    private static array $loadedAssets = [];
    private static bool $jqueryLoaded = false;
    
    /**
     * Auto-load jQuery if needed
     */
    public static function autoLoadJQuery(): void
    {
        if (self::$jqueryLoaded) {
            return;
        }
        
        // Check if we're in a CMS context that needs jQuery
        if (self::needsJQuery()) {
            self::loadJQuery();
        }
    }
    
    /**
     * Check if current context needs jQuery
     */
    private static function needsJQuery(): bool
    {
        // Check if WordPress
        if (defined('ABSPATH')) {
            return true;
        }
        
        // Check if Drupal
        if (defined('DRUPAL_ROOT')) {
            return true;
        }
        
        // Check if Joomla
        if (defined('_JEXEC')) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Load jQuery from CDN or local
     */
    private static function loadJQuery(): void
    {
        // Use WordPress's bundled jQuery if available
        if (defined('ABSPATH') && file_exists(ABSPATH . 'wp-includes/js/jquery/jquery.min.js')) {
            $jqueryPath = '/wp-includes/js/jquery/jquery.min.js';
        } 
        // Use Drupal's jQuery if available
        elseif (defined('DRUPAL_ROOT') && file_exists(DRUPAL_ROOT . '/core/assets/vendor/jquery/jquery.min.js')) {
            $jqueryPath = '/core/assets/vendor/jquery/jquery.min.js';
        }
        // Fallback to CDN
        else {
            $jqueryPath = 'https://code.jquery.com/jquery-3.7.1.min.js';
        }
        
        // Inject jQuery script tag
        echo '<script src="' . htmlspecialchars($jqueryPath) . '"></script>' . "\n";
        
        self::$jqueryLoaded = true;
        self::$loadedAssets['jquery'] = $jqueryPath;
    }
    
    /**
     * Inject jQuery into HTML output
     */
    public static function injectJQuery(string $html): string
    {
        if (self::$jqueryLoaded || !self::needsJQuery()) {
            return $html;
        }
        
        // Check if jQuery already loaded in HTML (avoid double-loading)
        if (stripos($html, 'jquery') !== false && 
            (stripos($html, 'jquery.min.js') !== false || stripos($html, 'jquery.js') !== false)) {
            return $html; // jQuery already present
        }
        
        // Find </head> tag and inject before it
        if (preg_match('/<\/head>/i', $html)) {
            ob_start();
            self::loadJQuery();
            $script = ob_get_clean();
            
            $html = preg_replace('/<\/head>/i', $script . '</head>', $html, 1);
        }
        
        return $html;
    }
    
    /**
     * Get loaded assets
     */
    public static function getLoadedAssets(): array
    {
        return self::$loadedAssets;
    }
}
