<?php
/**
 * Phoenix Content Service
 * 
 * Handles content/article data retrieval
 * 
 * @package     Phoenix
 * @version     2.0.0
 */

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Router\Route;
use Joomla\Component\Content\Site\Helper\RouteHelper as ContentHelperRoute;

/**
 * Content Service Class
 */
class PhoenixContentService
{
    private $app;
    private $db;
    
    public function __construct()
    {
        $this->app = Factory::getApplication();
        $this->db = Factory::getDbo();
    }
    
    /**
     * Get articles for listing
     */
    public function getArticles($limit = 10, $categoryId = null)
    {
        $query = $this->db->getQuery(true);
        
        $query->select('a.id, a.title, a.alias, a.introtext, a.fulltext, a.created, a.modified')
            ->select('a.catid, a.images, a.state, a.featured')
            ->select('c.title AS category_title, c.alias AS category_alias')
            ->select('u.name AS author')
            ->from($this->db->quoteName('#__content', 'a'))
            ->join('LEFT', $this->db->quoteName('#__categories', 'c') . ' ON a.catid = c.id')
            ->join('LEFT', $this->db->quoteName('#__users', 'u') . ' ON a.created_by = u.id')
            ->where('a.state = 1')
            ->order('a.created DESC')
            ->setLimit($limit);
        
        if ($categoryId) {
            $query->where('a.catid = ' . (int) $categoryId);
        }
        
        $this->db->setQuery($query);
        $articles = $this->db->loadObjectList();
        
        return array_map([$this, 'formatArticle'], $articles);
    }
    
    /**
     * Get single article by ID
     */
    public function getArticle($id)
    {
        $query = $this->db->getQuery(true);
        
        $query->select('a.*')
            ->select('c.title AS category_title, c.alias AS category_alias')
            ->select('u.name AS author')
            ->from($this->db->quoteName('#__content', 'a'))
            ->join('LEFT', $this->db->quoteName('#__categories', 'c') . ' ON a.catid = c.id')
            ->join('LEFT', $this->db->quoteName('#__users', 'u') . ' ON a.created_by = u.id')
            ->where('a.id = ' . (int) $id)
            ->where('a.state = 1');
        
        $this->db->setQuery($query);
        $article = $this->db->loadObject();
        
        return $article ? $this->formatArticle($article) : null;
    }
    
    /**
     * Get current article from request
     */
    public function getCurrentArticle()
    {
        $id = $this->app->input->getInt('id');
        return $id ? $this->getArticle($id) : null;
    }
    
    /**
     * Format article data for templates
     */
    private function formatArticle($article)
    {
        $images = json_decode($article->images ?? '{}');
        
        // Generate article URL safely (suppress warnings from Joomla router)
        $articleUrl = '#';
        if (!empty($article->id)) {
            try {
                // Suppress warnings from StandardRules when menu items don't exist
                // @ suppresses both getArticleRoute() and Route::_() warnings
                $articleUrl = @Route::_(@ContentHelperRoute::getArticleRoute($article->id, $article->catid ?? 0));
                if (empty($articleUrl)) {
                    $articleUrl = '/?option=com_content&view=article&id=' . $article->id;
                }
            } catch (\Exception $e) {
                // Article route failed, use fallback with Itemid
                $articleUrl = '/?option=com_content&view=article&id=' . $article->id;
            }
        }
        
        // Generate category URL safely (suppress warnings from Joomla router)
        $categoryUrl = '#';
        if (!empty($article->catid) && $article->catid > 0) {
            try {
                // Suppress warnings from StandardRules when menu items don't exist
                // @ suppresses both getCategoryRoute() and Route::_() warnings
                $categoryUrl = @Route::_(@ContentHelperRoute::getCategoryRoute($article->catid));
                if (empty($categoryUrl)) {
                    $categoryUrl = '#';
                }
            } catch (\Exception $e) {
                // Category route failed, use fallback
                $categoryUrl = '#';
            }
        }
        
        return [
            'id' => $article->id,
            'title' => $article->title,
            'content' => $article->introtext . ($article->fulltext ?? ''),
            'excerpt' => strip_tags($article->introtext),
            'url' => $articleUrl,
            'thumbnail' => $images->image_intro ?? '',
            'featured_image' => $images->image_fulltext ?? '',
            'date' => $article->created,
            'modified' => $article->modified ?? $article->created,
            'author' => $article->author ?? '',
            'category' => [
                'title' => $article->category_title ?? '',
                'url' => $categoryUrl,
            ],
        ];
    }
}
