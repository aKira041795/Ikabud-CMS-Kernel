<?php
/**
 * Ikabud Cache Invalidation for Joomla
 * 
 * @package     Joomla.Plugin
 * @subpackage  System.ikabudcache
 * 
 * To install:
 * 1. Create directory: plugins/system/ikabudcache/
 * 2. Copy this file as: ikabudcache.php
 * 3. Create ikabudcache.xml with plugin metadata
 * 4. Install and enable via Extensions > Plugins
 */

defined('_JEXEC') or die;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Factory;

/**
 * Ikabud Cache Plugin
 */
class PlgSystemIkabudcache extends CMSPlugin
{
    protected $cache;
    protected $instance_id;
    
    /**
     * Constructor
     */
    public function __construct(&$subject, $config = array())
    {
        parent::__construct($subject, $config);
        
        // Initialize Ikabud Cache
        $this->instance_id = getenv('IKABUD_INSTANCE_ID') ?: null;
        if ($this->instance_id) {
            $kernel_path = getenv('IKABUD_KERNEL_PATH') ?: JPATH_ROOT . '/../../kernel';
            require_once $kernel_path . '/Cache.php';
            $this->cache = new \IkabudKernel\Core\Cache();
        }
    }
    
    /**
     * Get tags for an article
     */
    protected function getArticleTags($article)
    {
        $tags = [
            'article-' . $article->id,
            'category-' . $article->catid,
        ];
        
        // Add author tag
        if (isset($article->created_by)) {
            $tags[] = 'author-' . $article->created_by;
        }
        
        // Add date-based tags
        if (isset($article->created)) {
            $date = Factory::getDate($article->created);
            $tags[] = 'year-' . $date->format('Y');
            $tags[] = 'month-' . $date->format('Y-m');
        }
        
        // Add Joomla tags
        if (isset($article->tags) && is_array($article->tags)) {
            foreach ($article->tags as $tag) {
                $tags[] = 'tag-' . $tag->id;
            }
        }
        
        return $tags;
    }
    
    /**
     * Get dependency URLs for an article
     */
    protected function getArticleDependencies($article)
    {
        $dependencies = [
            '/',  // Homepage
        ];
        
        // Add category page
        if (isset($article->catid)) {
            $dependencies[] = \Joomla\CMS\Router\Route::_('index.php?option=com_content&view=category&id=' . $article->catid);
        }
        
        // Add author page
        if (isset($article->created_by)) {
            $dependencies[] = \Joomla\CMS\Router\Route::_('index.php?option=com_content&view=author&id=' . $article->created_by);
        }
        
        return $dependencies;
    }
    
    /**
     * After content save event
     */
    public function onContentAfterSave($context, $article, $isNew)
    {
        if (!$this->cache || !$this->instance_id) {
            return true;
        }
        
        // Only handle articles
        if ($context !== 'com_content.article') {
            return true;
        }
        
        // Only clear for published articles
        if ($article->state != 1) {
            return true;
        }
        
        // Get tags and clear cache
        $tags = $this->getArticleTags($article);
        $cleared = $this->cache->clearByTags($this->instance_id, $tags);
        
        // Clear dependencies
        $article_url = \Joomla\CMS\Router\Route::_('index.php?option=com_content&view=article&id=' . $article->id);
        $dependencies = $this->getArticleDependencies($article);
        $cleared += $this->cache->clearWithDependencies($this->instance_id, $article_url, $dependencies);
        
        Factory::getApplication()->enqueueMessage(
            "Ikabud Cache: Cleared $cleared cache files for article {$article->id}",
            'info'
        );
        
        return true;
    }
    
    /**
     * Before content delete event
     */
    public function onContentBeforeDelete($context, $article)
    {
        if (!$this->cache || !$this->instance_id) {
            return true;
        }
        
        if ($context !== 'com_content.article') {
            return true;
        }
        
        $tags = $this->getArticleTags($article);
        $cleared = $this->cache->clearByTags($this->instance_id, $tags);
        
        Factory::getApplication()->enqueueMessage(
            "Ikabud Cache: Cleared $cleared cache files for deleted article {$article->id}",
            'info'
        );
        
        return true;
    }
    
    /**
     * After category save event
     */
    public function onCategoryAfterSave($context, $category, $isNew)
    {
        if (!$this->cache || !$this->instance_id) {
            return true;
        }
        
        $tags = ['category-' . $category->id];
        $cleared = $this->cache->clearByTags($this->instance_id, $tags);
        
        Factory::getApplication()->enqueueMessage(
            "Ikabud Cache: Cleared $cleared cache files for category {$category->id}",
            'info'
        );
        
        return true;
    }
    
    /**
     * Before category delete event
     */
    public function onCategoryBeforeDelete($context, $category)
    {
        if (!$this->cache || !$this->instance_id) {
            return true;
        }
        
        $tags = ['category-' . $category->id];
        $cleared = $this->cache->clearByTags($this->instance_id, $tags);
        
        Factory::getApplication()->enqueueMessage(
            "Ikabud Cache: Cleared $cleared cache files for deleted category {$category->id}",
            'info'
        );
        
        return true;
    }
    
    /**
     * After menu save event
     */
    public function onMenuAfterSave($context, $menu, $isNew)
    {
        if (!$this->cache || !$this->instance_id) {
            return true;
        }
        
        // Clear all cache when menu changes (affects navigation)
        $this->cache->clear($this->instance_id);
        
        Factory::getApplication()->enqueueMessage(
            'Ikabud Cache: Cleared all cache after menu update',
            'info'
        );
        
        return true;
    }
    
    /**
     * After module save event
     */
    public function onModuleAfterSave($context, $module, $isNew)
    {
        if (!$this->cache || !$this->instance_id) {
            return true;
        }
        
        // Clear all cache when modules change
        $this->cache->clear($this->instance_id);
        
        Factory::getApplication()->enqueueMessage(
            'Ikabud Cache: Cleared all cache after module update',
            'info'
        );
        
        return true;
    }
    
    /**
     * After template style save event
     */
    public function onExtensionAfterSave($context, $table, $isNew)
    {
        if (!$this->cache || !$this->instance_id) {
            return true;
        }
        
        // Clear all cache when template changes
        if ($context === 'com_templates.style') {
            $this->cache->clear($this->instance_id);
            
            Factory::getApplication()->enqueueMessage(
                'Ikabud Cache: Cleared all cache after template update',
                'info'
            );
        }
        
        return true;
    }
}
