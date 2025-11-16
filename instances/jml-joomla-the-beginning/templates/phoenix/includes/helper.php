<?php
/**
 * Phoenix Template Helper Functions
 * 
 * @package     Phoenix
 * @version     1.0.0
 */

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Router\Route;

/**
 * Get article excerpt
 */
function phoenix_get_excerpt($text, $length = 150)
{
    $text = strip_tags($text);
    if (strlen($text) > $length) {
        $text = substr($text, 0, $length) . '...';
    }
    return $text;
}

/**
 * Format date
 */
function phoenix_format_date($date, $format = 'F j, Y')
{
    return date($format, strtotime($date));
}

/**
 * Get article URL
 */
function phoenix_get_article_url($id, $alias, $catid)
{
    return Route::_('index.php?option=com_content&view=article&id=' . $id . ':' . $alias . '&catid=' . $catid);
}

/**
 * Get category URL
 */
function phoenix_get_category_url($id, $alias = '')
{
    return Route::_('index.php?option=com_content&view=category&id=' . $id . ($alias ? ':' . $alias : ''));
}

/**
 * Check if module position has content
 */
function phoenix_has_modules($position, $document)
{
    return $document->countModules($position, true) > 0;
}

/**
 * Get image from article images JSON
 */
function phoenix_get_article_image($imagesJson, $type = 'intro')
{
    $images = json_decode($imagesJson);
    
    if ($type === 'intro' && isset($images->image_intro)) {
        return $images->image_intro;
    }
    
    if ($type === 'full' && isset($images->image_fulltext)) {
        return $images->image_fulltext;
    }
    
    return null;
}

/**
 * Sanitize output
 */
function phoenix_esc_html($text)
{
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

/**
 * Sanitize URL
 */
function phoenix_esc_url($url)
{
    return htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
}
