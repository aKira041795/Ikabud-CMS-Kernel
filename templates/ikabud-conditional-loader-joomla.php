<?php
/**
 * Ikabud Kernel - Conditional Extension Loader for Joomla
 * 
 * Place this file in plugins/system/ikabudloader/ to enable conditional extension loading
 * when Joomla loads through its own index.php
 */

defined('_JEXEC') or die;

use Joomla\CMS\Plugin\CMSPlugin;

/**
 * Ikabud Conditional Loader Plugin
 */
class PlgSystemIkabudloader extends CMSPlugin
{
    /**
     * Load extensions conditionally after Joomla framework loads
     */
    public function onAfterInitialise()
    {
        // Only run if conditional loading is enabled
        if (!defined('IKABUD_CONDITIONAL_LOADING') || IKABUD_CONDITIONAL_LOADING !== true) {
            return;
        }
        
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
    }
}
