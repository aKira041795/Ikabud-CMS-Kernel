<?php
/**
 * Phoenix Template Override - Custom HTML Module (Slider Style)
 * @package     Phoenix
 * @subpackage  mod_custom
 * 
 * This override renders the slider component when used with a Custom HTML module
 */

defined('_JEXEC') or die;

use Joomla\CMS\Factory;

// Get the DiSyL renderer
$app = Factory::getApplication();
$template = $app->getTemplate();
$templatePath = JPATH_THEMES . '/' . $template;

// Include DiSyL renderer if available
$rendererPath = JPATH_ROOT . '/kernel/DiSyL/Renderers/JoomlaRenderer.php';
if (file_exists($rendererPath)) {
    require_once $rendererPath;
    
    $renderer = new \IkabudKernel\Core\DiSyL\Renderers\JoomlaRenderer();
    $sliderTemplate = $templatePath . '/disyl/components/slider.disyl';
    
    if (file_exists($sliderTemplate)) {
        echo $renderer->render(file_get_contents($sliderTemplate));
    } else {
        echo '<!-- Slider template not found -->';
    }
} else {
    // Fallback if DiSyL not available
    echo $module->content;
}
