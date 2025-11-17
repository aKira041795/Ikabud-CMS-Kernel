<?php
/**
 * Phoenix DiSyL Integration for Joomla
 * 
 * Integrates DiSyL rendering engine with Joomla CMS
 * 
 * @package     Phoenix
 * @version     1.0.0
 */

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\Router\Route;
use Joomla\Component\Content\Site\Helper\RouteHelper as ContentHelperRoute;
use IkabudKernel\Core\DiSyL\Engine;
use IkabudKernel\Core\DiSyL\Renderers\JoomlaRenderer;

// Load service classes
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/services/MenuService.php';
require_once __DIR__ . '/services/ContentService.php';
require_once __DIR__ . '/services/ModuleService.php';

/**
 * Phoenix DiSyL Integration Class
 */
class PhoenixDisylIntegration
{
    private $document;
    private $app;
    private $templatePath;
    private $engine;
    private $renderer;
    private $menuService;
    private $contentService;
    private $moduleService;
    
    /**
     * Constructor
     */
    public function __construct($document, $app)
    {
        $this->document = $document;
        $this->app = $app;
        $this->templatePath = JPATH_THEMES . '/' . $document->template;
        
        // Initialize DiSyL Engine
        $this->engine = new Engine();
        
        // Create Joomla-specific renderer from kernel
        $this->renderer = new JoomlaRenderer();
        
        // Set template path for includes
        $this->renderer->setTemplatePath($this->templatePath . '/disyl');
        
        // Initialize services
        $this->menuService = new PhoenixMenuService();
        $this->contentService = new PhoenixContentService();
        $this->moduleService = new PhoenixModuleService();
    }
    
    /**
     * Get template file based on Joomla context
     */
    public function getTemplateFile($option, $view, $layout)
    {
        $disylPath = $this->templatePath . '/disyl/';
        
        // Check for specific layout
        if ($layout && file_exists($disylPath . $layout . '.disyl')) {
            return $disylPath . $layout . '.disyl';
        }
        
        // Check for view-specific template
        if ($view && file_exists($disylPath . $view . '.disyl')) {
            return $disylPath . $view . '.disyl';
        }
        
        // Map Joomla views to DiSyL templates
        $templateMap = [
            'featured' => 'home.disyl',
            'category' => 'category.disyl',
            'article' => 'single.disyl',
            'form' => 'page.disyl',
            'search' => 'search.disyl',
            'error' => '404.disyl',
        ];
        
        if (isset($templateMap[$view])) {
            $file = $disylPath . $templateMap[$view];
            if (file_exists($file)) {
                return $file;
            }
        }
        
        // Default to home template for front page
        if ($this->app->getMenu()->getActive() && $this->app->getMenu()->getActive()->home) {
            return $disylPath . 'home.disyl';
        }
        
        // Fallback to blog template
        return $disylPath . 'blog.disyl';
    }
    
    /**
     * Build context for DiSyL rendering
     */
    public function buildContext($params = [])
    {
        $context = array_merge($this->getBaseContext(), $params);
        
        // Add menu data
        $context['menu'] = $this->menuService->getMenuData();
        
        // Add module positions
        $context['modules'] = $this->moduleService->getModulePositions();
        
        // Add articles/posts
        $context['posts'] = $this->contentService->getArticles(10);
        
        // Add components configuration from template params
        $template = $this->app->getTemplate(true);
        $context['components'] = [
            'header' => [
                'logo' => $template->params->get('logoFile', ''),
                'sticky' => (bool)$template->params->get('stickyHeader', 1),
                'show_search' => (bool)$template->params->get('showSearch', 1),
            ],
            'slider' => [
                'autoplay' => (bool)$template->params->get('sliderAutoplay', 1),
                'interval' => (int)$template->params->get('sliderInterval', 5000),
                'transition' => $template->params->get('sliderTransition', 'fade'),
                'show_arrows' => (bool)$template->params->get('sliderShowArrows', 1),
                'show_dots' => (bool)$template->params->get('sliderShowDots', 1),
            ],
            'footer' => [
                'columns' => (int)$template->params->get('footerColumns', 4),
                'show_social' => (bool)$template->params->get('showSocial', 1),
                'copyright' => $template->params->get('copyrightText', '© 2025 All rights reserved.'),
            ],
            'layout' => [
                'style' => $template->params->get('layoutStyle', 'boxed'),
                'fluid' => (bool)$template->params->get('fluidContainer', 0),
                'back_top' => (bool)$template->params->get('backTop', 1),
                'color_scheme' => $template->params->get('colorScheme', 'default'),
            ],
        ];
        
        // Add current article if viewing single
        if ($this->app->input->get('view') === 'article') {
            $context['post'] = $this->contentService->getCurrentArticle();
        }
        
        // Add category data if viewing category
        if ($this->app->input->get('view') === 'category') {
            $context['category'] = $this->getCategoryData();
        }
        
        return $context;
    }
    
    /**
     * Get base context
     */
    private function getBaseContext()
    {
        $config = Factory::getConfig();
        $user = Factory::getUser();
        $template = $this->app->getTemplate(true);
        
        return [
            'site' => [
                'name' => $config->get('sitename'),
                'url' => Uri::root(),
                'description' => $config->get('MetaDesc'),
                'theme_url' => Uri::root() . 'templates/' . $this->document->template,
                'logo' => $template->params->get('logoFile', ''),
                'template_version' => '2.0.0',
            ],
            'user' => [
                'logged_in' => !$user->guest,
                'name' => $user->name,
                'id' => $user->id,
                'guest' => $user->guest,
                'groups' => $user->getAuthorisedGroups(),
            ],
            'current_url' => Uri::current(),
            'base_url' => Uri::base(),
            'joomla' => [
                'params' => json_decode($template->params->toString(), true),
                'module_positions' => $this->moduleService->getModulePositions(),
                'fields' => [], // Will be populated per-article/category
            ],
        ];
    }
    
    /**
     * Get available module positions with module counts
     */
    private function getModulePositions()
    {
        $positions = [
            'topbar', 'header', 'menu', 'search', 'banner', 'hero', 'features',
            'top-a', 'top-b', 'main-top', 'main-bottom', 'breadcrumbs',
            'sidebar-left', 'sidebar-right', 'bottom-a', 'bottom-b',
            'footer-1', 'footer-2', 'footer-3', 'footer-4', 'footer', 'debug'
        ];
        
        $result = [];
        foreach ($positions as $position) {
            $modules = \Joomla\CMS\Helper\ModuleHelper::getModules($position);
            $result[$position] = count($modules);
        }
        
        return $result;
    }
    
    /**
     * Get menu data
     */
    private function getMenuData()
    {
        $menu = $this->app->getMenu();
        $items = $menu->getItems('menutype', 'mainmenu');
        
        $menuData = [];
        foreach ($items as $item) {
            // Generate proper URL based on menu item type
            if ($item->type === 'url') {
                // External URL - use as-is
                $url = $item->link;
            } elseif ($item->type === 'alias') {
                // Menu alias - route to the aliased item
                $aliasItemId = $item->params->get('aliasoptions');
                $url = '/?Itemid=' . $aliasItemId;
            } else {
                // Component link - use Itemid parameter (works reliably)
                if ($item->home == 1) {
                    $url = '/';
                } else {
                    // Use simple Itemid parameter - most reliable routing method
                    $url = '/?Itemid=' . $item->id;
                }
            }
            
            $menuData[] = [
                'id' => $item->id,
                'title' => $item->title,
                'url' => htmlspecialchars_decode($url),
                'active' => ($menu->getActive() && $menu->getActive()->id == $item->id),
                'parent_id' => $item->parent_id,
                'level' => $item->level,
            ];
        }
        
        return [
            'primary' => $this->buildMenuTree($menuData, 1),
            'footer' => [],
            'social' => [],
        ];
    }
    
    /**
     * Build hierarchical menu tree
     */
    private function buildMenuTree($items, $parent_id = 1)
    {
        $branch = [];
        
        foreach ($items as $item) {
            if ($item['parent_id'] == $parent_id) {
                $children = $this->buildMenuTree($items, $item['id']);
                $item['children'] = $children;
                $branch[] = $item;
            }
        }
        
        return $branch;
    }
    
    /**
     * Get module data
     */
    private function getModuleData()
    {
        $modules = [];
        $positions = ['sidebar-left', 'sidebar-right', 'footer-1', 'footer-2', 'footer-3', 'footer-4', 'hero', 'features'];
        
        foreach ($positions as $position) {
            $modules[$position] = [
                'active' => $this->document->countModules($position, true) > 0,
                'content' => $this->renderModulePosition($position),
            ];
        }
        
        return $modules;
    }
    
    /**
     * Render module position
     */
    private function renderModulePosition($position)
    {
        if (!$this->document->countModules($position, true)) {
            return '';
        }
        
        ob_start();
        echo '<jdoc:include type="modules" name="' . $position . '" style="none" />';
        return ob_get_clean();
    }
    
    /**
     * Get articles
     */
    private function getArticles($limit = 10)
    {
        $db = Factory::getDbo();
        $query = $db->getQuery(true);
        
        $query->select('a.id, a.title, a.alias, a.introtext, a.fulltext, a.created, a.images')
            ->select('a.catid, c.title AS category_title, c.alias AS category_alias')
            ->select('u.name AS author_name')
            ->from('#__content AS a')
            ->join('LEFT', '#__categories AS c ON c.id = a.catid')
            ->join('LEFT', '#__users AS u ON u.id = a.created_by')
            ->where('a.state = 1')
            ->order('a.created DESC')
            ->setLimit($limit);
        
        $db->setQuery($query);
        $articles = $db->loadObjectList();
        
        $posts = [];
        foreach ($articles as $article) {
            $images = json_decode($article->images);
            
            // Generate proper SEF URLs using ContentHelperRoute
            $articleUrl = ContentHelperRoute::getArticleRoute($article->id, $article->catid);
            $categoryUrl = ContentHelperRoute::getCategoryRoute($article->catid);
            
            $posts[] = [
                'id' => $article->id,
                'title' => $article->title,
                'url' => htmlspecialchars_decode(Route::_($articleUrl)),
                'excerpt' => strip_tags($article->introtext),
                'content' => $article->fulltext,
                'date' => $article->created,
                'author' => $article->author_name,
                'category' => [
                    'name' => $article->category_title,
                    'url' => htmlspecialchars_decode(Route::_($categoryUrl)),
                ],
                'thumbnail' => isset($images->image_intro) ? Uri::root() . $images->image_intro : null,
            ];
        }
        
        return $posts;
    }
    
    /**
     * Get current article
     */
    private function getCurrentArticle()
    {
        $articleId = $this->app->input->getInt('id');
        
        if (!$articleId) {
            return null;
        }
        
        $db = Factory::getDbo();
        $query = $db->getQuery(true);
        
        $query->select('a.*, c.title AS category_title, u.name AS author_name')
            ->from('#__content AS a')
            ->join('LEFT', '#__categories AS c ON c.id = a.catid')
            ->join('LEFT', '#__users AS u ON u.id = a.created_by')
            ->where('a.id = ' . $articleId);
        
        $db->setQuery($query);
        $article = $db->loadObject();
        
        if (!$article) {
            return null;
        }
        
        $images = json_decode($article->images);
        
        return [
            'id' => $article->id,
            'title' => $article->title,
            'content' => $article->introtext . $article->fulltext,
            'date' => $article->created,
            'author' => $article->author_name,
            'category' => [
                'name' => $article->category_title,
            ],
            'thumbnail' => isset($images->image_fulltext) ? Uri::root() . $images->image_fulltext : null,
        ];
    }
    
    /**
     * Get category data
     */
    private function getCategoryData()
    {
        $categoryId = $this->app->input->getInt('id');
        
        if (!$categoryId) {
            return null;
        }
        
        $db = Factory::getDbo();
        $query = $db->getQuery(true);
        
        $query->select('*')
            ->from('#__categories')
            ->where('id = ' . $categoryId);
        
        $db->setQuery($query);
        $category = $db->loadObject();
        
        if (!$category) {
            return null;
        }
        
        return [
            'id' => $category->id,
            'name' => $category->title,
            'description' => $category->description,
            'url' => Route::_('index.php?option=com_content&view=category&id=' . $category->id),
        ];
    }
    
    /**
     * Render template with DiSyL
     */
    public function render($templateFile, $context)
    {
        return $this->engine->renderFile($templateFile, $this->renderer, $context);
    }
}

/**
 * Note: This template now uses the kernel's JoomlaRenderer
 * located at /kernel/DiSyL/Renderers/JoomlaRenderer.php
 * 
 * The kernel renderer provides:
 * - All DiSyL components (ikb_section, ikb_container, ikb_grid, etc.)
 * - Joomla-specific components (joomla_module, joomla_component, joomla_message)
 * - Query support for articles
 * - Menu rendering
 * - Conditional logic
 */
