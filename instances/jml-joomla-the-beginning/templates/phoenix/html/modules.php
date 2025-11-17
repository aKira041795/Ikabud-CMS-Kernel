<?php
/**
 * Phoenix Template - Module Chrome
 * Defines how modules are wrapped/styled
 */

defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;

/**
 * xhtml chrome - Standard XHTML with title
 */
function modChrome_xhtml($module, $params, $attribs)
{
    if (!empty($module->content)) {
        echo '<div class="moduletable' . htmlspecialchars($params->get('moduleclass_sfx'), ENT_COMPAT, 'UTF-8') . '">';
        
        if ($module->showtitle) {
            echo '<h3>' . $module->title . '</h3>';
        }
        
        echo $module->content;
        echo '</div>';
    }
}

/**
 * none chrome - No wrapper at all
 */
function modChrome_none($module, $params, $attribs)
{
    if (!empty($module->content)) {
        echo $module->content;
    }
}

/**
 * card chrome - Bootstrap card style with title
 */
function modChrome_card($module, $params, $attribs)
{
    if (!empty($module->content)) {
        echo '<div class="card' . htmlspecialchars($params->get('moduleclass_sfx'), ENT_COMPAT, 'UTF-8') . '">';
        
        if ($module->showtitle) {
            echo '<div class="card-header">';
            echo '<h3 class="card-title">' . $module->title . '</h3>';
            echo '</div>';
        }
        
        echo '<div class="card-body">';
        echo $module->content;
        echo '</div>';
        echo '</div>';
    }
}
