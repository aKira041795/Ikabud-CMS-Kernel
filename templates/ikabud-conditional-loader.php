<?php
/**
 * Ikabud Kernel - Conditional Plugin Loader Drop-in
 * 
 * Place this file in wp-content/mu-plugins/ to enable conditional plugin loading
 * when WordPress loads through its own index.php
 */

// Only run if conditional loading is enabled
if (!defined('IKABUD_CONDITIONAL_LOADING') || IKABUD_CONDITIONAL_LOADING !== true) {
    return;
}

// Hook into muplugins_loaded to load conditional extensions
add_action('muplugins_loaded', function() {
    // Check if we have extensions to load from kernel
    if (isset($GLOBALS['ikabud_extensions_to_load']) && isset($GLOBALS['ikabud_conditional_loader'])) {
        $extensionsToLoad = $GLOBALS['ikabud_extensions_to_load'];
        $conditionalLoader = $GLOBALS['ikabud_conditional_loader'];
        
        if (!empty($extensionsToLoad)) {
            $conditionalLoader->loadExtensions($extensionsToLoad);
        }
        
        // Clean up globals
        unset($GLOBALS['ikabud_extensions_to_load']);
        unset($GLOBALS['ikabud_conditional_loader']);
    }
}, 1);

// Prevent WordPress from loading plugins automatically
add_filter('option_active_plugins', function($plugins) {
    // If conditional loading is active, return empty array
    // Plugins will be loaded by ConditionalPluginLoader instead
    if (defined('IKABUD_CONDITIONAL_LOADING') && IKABUD_CONDITIONAL_LOADING === true) {
        // Check if we're in admin - load all plugins in admin
        if (is_admin() || (defined('WP_CLI') && WP_CLI)) {
            return $plugins; // Load all in admin/CLI
        }
        
        // For frontend, return empty - plugins loaded conditionally
        return [];
    }
    
    return $plugins;
}, 1);
