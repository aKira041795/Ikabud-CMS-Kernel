<?php
/**
 * Phoenix Menu Service
 * 
 * Handles menu data retrieval and processing
 * 
 * @package     Phoenix
 * @version     2.0.0
 */

defined('_JEXEC') or die;

use Joomla\CMS\Factory;

/**
 * Menu Service Class
 */
class PhoenixMenuService
{
    private $app;
    
    public function __construct()
    {
        $this->app = Factory::getApplication();
    }
    
    /**
     * Get menu data for all menu types
     */
    public function getMenuData()
    {
        return [
            'primary' => $this->getPrimaryMenu(),
            'footer' => $this->getFooterMenu(),
            'social' => $this->getSocialMenu(),
        ];
    }
    
    /**
     * Get primary navigation menu
     */
    public function getPrimaryMenu()
    {
        $menu = $this->app->getMenu();
        $items = $menu->getItems('menutype', 'mainmenu');
        
        if (!$items) {
            return [];
        }
        
        $menuData = [];
        foreach ($items as $item) {
            $menuData[] = [
                'id' => $item->id,
                'title' => $item->title,
                'url' => $this->generateMenuUrl($item),
                'active' => $this->isActive($item),
                'parent_id' => $item->parent_id,
                'level' => $item->level,
                'type' => $item->type,
            ];
        }
        
        return $this->buildMenuTree($menuData, 1);
    }
    
    /**
     * Get footer menu
     */
    public function getFooterMenu()
    {
        $menu = $this->app->getMenu();
        $items = $menu->getItems('menutype', 'footer');
        
        if (!$items) {
            return [];
        }
        
        $menuData = [];
        foreach ($items as $item) {
            $menuData[] = [
                'id' => $item->id,
                'title' => $item->title,
                'url' => $this->generateMenuUrl($item),
                'active' => $this->isActive($item),
            ];
        }
        
        return $menuData;
    }
    
    /**
     * Get social media menu
     */
    public function getSocialMenu()
    {
        // Could be from menu type 'social' or template params
        return [];
    }
    
    /**
     * Generate URL for menu item
     */
    private function generateMenuUrl($item)
    {
        if ($item->type === 'url') {
            return $item->link;
        }
        
        if ($item->type === 'alias') {
            $aliasItemId = $item->params->get('aliasoptions');
            return '/?Itemid=' . $aliasItemId;
        }
        
        if ($item->home == 1) {
            return '/';
        }
        
        return '/?Itemid=' . $item->id;
    }
    
    /**
     * Check if menu item is active
     */
    private function isActive($item)
    {
        $menu = $this->app->getMenu();
        $active = $menu->getActive();
        return $active && $active->id == $item->id;
    }
    
    /**
     * Build hierarchical menu tree
     */
    private function buildMenuTree($items, $parentId = 1)
    {
        $tree = [];
        
        foreach ($items as $item) {
            if ($item['parent_id'] == $parentId) {
                $children = $this->buildMenuTree($items, $item['id']);
                if ($children) {
                    $item['children'] = $children;
                }
                $tree[] = $item;
            }
        }
        
        return $tree;
    }
}
