<?php
/**
 * Phoenix Module Service
 * 
 * Handles module position and data retrieval
 * 
 * @package     Phoenix
 * @version     2.0.0
 */

defined('_JEXEC') or die;

use Joomla\CMS\Helper\ModuleHelper;

/**
 * Module Service Class
 */
class PhoenixModuleService
{
    /**
     * Get all module positions with counts
     */
    public function getModulePositions()
    {
        $positions = [
            'topbar', 'header', 'menu', 'search', 'banner', 'hero', 'features',
            'top-a', 'top-b', 'main-top', 'main-bottom', 'breadcrumbs',
            'sidebar-left', 'sidebar-right', 'bottom-a', 'bottom-b',
            'footer-1', 'footer-2', 'footer-3', 'footer-4', 'footer', 'debug'
        ];
        
        $result = [];
        foreach ($positions as $position) {
            $modules = ModuleHelper::getModules($position);
            $result[$position] = count($modules);
        }
        
        return $result;
    }
    
    /**
     * Check if a position has modules
     */
    public function hasModules($position)
    {
        $modules = ModuleHelper::getModules($position);
        return count($modules) > 0;
    }
    
    /**
     * Get modules for a specific position
     */
    public function getModules($position)
    {
        return ModuleHelper::getModules($position);
    }
}
