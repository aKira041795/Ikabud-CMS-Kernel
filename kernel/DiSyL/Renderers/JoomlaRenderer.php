<?php
/**
 * DiSyL Joomla Renderer
 * 
 * Joomla-specific renderer for DiSyL templates
 * Extends BaseRenderer with Joomla CMS integration
 * 
 * @package     IkabudKernel
 * @subpackage  DiSyL
 * @version     0.5.0
 */

namespace IkabudKernel\Core\DiSyL\Renderers;

use IkabudKernel\Core\DiSyL\Renderers\BaseRenderer;
use Joomla\CMS\Factory;
use Joomla\CMS\Helper\ModuleHelper;
use Joomla\CMS\Router\Route;
use Joomla\Component\Content\Site\Helper\RouteHelper as ContentHelperRoute;
use Joomla\Component\Fields\Administrator\Helper\FieldsHelper;

// Load WordPress-compatible filter functions for Joomla
// These are defined in global namespace so they're available in eval() scope
require_once __DIR__ . '/joomla-compat-functions.php';

/**
 * Joomla-specific DiSyL renderer (v2)
 * Provides Joomla-native components and context
 */
class JoomlaRenderer extends BaseRenderer
{
    private $templatePath = '';
    
    /**
     * Set template path for includes
     */
    public function setTemplatePath(string $path): void
    {
        $this->templatePath = rtrim($path, '/');
    }
    
    /**
     * Initialize Joomla context
     */
    protected function initializeCMS(): void
    {
        // Joomla-specific initialization
        // Context is provided by the template integration layer
        
        // Initialize ModularManifestLoader with Joomla CMS type
        if (class_exists('\\IkabudKernel\\Core\\DiSyL\\ModularManifestLoader')) {
            \IkabudKernel\Core\DiSyL\ModularManifestLoader::init('full', 'joomla');
        }
    }
    
    /**
     * Render ikb_include component
     */
    protected function renderIkbInclude(array $node, array $attrs, array $children): string
    {
        $template = $attrs['template'] ?? '';
        
        if (empty($template)) {
            return '<!-- ikb_include: no template specified -->';
        }
        
        // Build full template path
        $templateFile = $this->templatePath . '/' . $template;
        
        if (!file_exists($templateFile)) {
            return '<!-- ikb_include: template not found: ' . htmlspecialchars($template) . ' -->';
        }
        
        // Read and compile the included template
        try {
            $engine = new \IkabudKernel\Core\DiSyL\Engine();
            $compiledAst = $engine->compile(file_get_contents($templateFile));
            
            // Render with current context
            return $this->renderChildren($compiledAst['children'] ?? []);
        } catch (\Exception $e) {
            return '<!-- ikb_include error: ' . htmlspecialchars($e->getMessage()) . ' -->';
        }
    }
    
    /**
     * Render ikb_section component
     */
    protected function renderIkbSection(array $node, array $attrs, array $children): string
    {
        $type = $attrs['type'] ?? 'default';
        $padding = $attrs['padding'] ?? 'medium';
        $background = $attrs['background'] ?? '';
        $classes = $attrs['class'] ?? '';
        
        $sectionClasses = "ikb-section section-{$type} padding-{$padding}";
        if ($classes) {
            $sectionClasses .= ' ' . $classes;
        }
        
        $style = '';
        if ($background) {
            $style = ' style="background: ' . htmlspecialchars($background) . ';"';
        }
        
        $content = $this->renderChildren($children);
        
        return "<section class=\"{$sectionClasses}\"{$style}>{$content}</section>";
    }
    
    /**
     * Render ikb_container component
     */
    protected function renderIkbContainer(array $node, array $attrs, array $children): string
    {
        $size = $attrs['size'] ?? 'large';
        $classes = $attrs['class'] ?? '';
        
        $containerClasses = "ikb-container container-{$size}";
        if ($classes) {
            $containerClasses .= ' ' . $classes;
        }
        
        $content = $this->renderChildren($children);
        
        return "<div class=\"{$containerClasses}\">{$content}</div>";
    }
    
    /**
     * Render ikb_text component
     */
    protected function renderIkbText(array $node, array $attrs, array $children): string
    {
        $tag = $attrs['tag'] ?? 'p';
        $size = $attrs['size'] ?? 'base';
        $weight = $attrs['weight'] ?? 'normal';
        $align = $attrs['align'] ?? 'left';
        $color = $attrs['color'] ?? '';
        $classes = $attrs['class'] ?? '';
        
        $textClasses = "ikb-text text-{$size} font-{$weight} text-{$align}";
        if ($classes) {
            $textClasses .= ' ' . $classes;
        }
        
        $style = '';
        if ($color) {
            $style = ' style="color: ' . htmlspecialchars($color) . ';"';
        }
        
        $content = $this->renderChildren($children);
        
        return "<{$tag} class=\"{$textClasses}\"{$style}>{$content}</{$tag}>";
    }
    
    /**
     * Render ikb_button component
     */
    protected function renderIkbButton(array $node, array $attrs, array $children): string
    {
        $href = $attrs['href'] ?? '#';
        $variant = $attrs['variant'] ?? 'primary';
        $size = $attrs['size'] ?? 'medium';
        $classes = $attrs['class'] ?? '';
        
        $buttonClasses = "ikb-button btn-{$variant} btn-{$size}";
        if ($classes) {
            $buttonClasses .= ' ' . $classes;
        }
        
        $content = $this->renderChildren($children);
        
        return "<a href=\"" . htmlspecialchars($href) . "\" class=\"{$buttonClasses}\">{$content}</a>";
    }
    
    /**
     * Render ikb_grid component
     */
    protected function renderIkbGrid(array $node, array $attrs, array $children): string
    {
        $columns = $attrs['columns'] ?? '3';
        $gap = $attrs['gap'] ?? 'medium';
        $classes = $attrs['class'] ?? '';
        
        $gridClasses = "ikb-grid grid-cols-{$columns} gap-{$gap}";
        if ($classes) {
            $gridClasses .= ' ' . $classes;
        }
        
        $content = $this->renderChildren($children);
        
        return "<div class=\"{$gridClasses}\">{$content}</div>";
    }
    
    /**
     * Render ikb_card component
     */
    protected function renderIkbCard(array $node, array $attrs, array $children): string
    {
        $variant = $attrs['variant'] ?? 'default';
        $padding = $attrs['padding'] ?? 'medium';
        $classes = $attrs['class'] ?? '';
        
        $cardClasses = "ikb-card card-{$variant} padding-{$padding}";
        if ($classes) {
            $cardClasses .= ' ' . $classes;
        }
        
        $content = $this->renderChildren($children);
        
        return "<div class=\"{$cardClasses}\">{$content}</div>";
    }
    
    /**
     * Render ikb_image component
     */
    protected function renderIkbImage(array $node, array $attrs, array $children): string
    {
        $src = $attrs['src'] ?? '';
        $alt = $attrs['alt'] ?? '';
        $width = $attrs['width'] ?? '';
        $height = $attrs['height'] ?? '';
        $classes = $attrs['class'] ?? '';
        
        $imgClasses = "ikb-image";
        if ($classes) {
            $imgClasses .= ' ' . $classes;
        }
        
        $dimensions = '';
        if ($width) {
            $dimensions .= ' width="' . htmlspecialchars($width) . '"';
        }
        if ($height) {
            $dimensions .= ' height="' . htmlspecialchars($height) . '"';
        }
        
        return "<img src=\"" . htmlspecialchars($src) . "\" alt=\"" . htmlspecialchars($alt) . "\" class=\"{$imgClasses}\"{$dimensions} loading=\"lazy\" />";
    }
    
    /**
     * Render ikb_query component (Joomla articles)
     */
    protected function renderIkbQuery(array $node, array $attrs, array $children): string
    {
        $type = $attrs['type'] ?? 'article';
        $limit = $attrs['limit'] ?? 10;
        $category = $attrs['category'] ?? null;
        
        // Get items from context
        $items = $this->context['posts'] ?? [];
        
        if ($category) {
            $items = array_filter($items, function($item) use ($category) {
                return isset($item['category']['name']) && $item['category']['name'] === $category;
            });
        }
        
        $items = array_slice($items, 0, (int)$limit);
        
        $output = '';
        foreach ($items as $item) {
            // Set item context
            $oldContext = $this->context;
            $this->context['item'] = $item;
            
            // Render children for each item
            $output .= $this->renderChildren($children);
            
            // Restore context
            $this->context = $oldContext;
        }
        
        return $output;
    }
    
    /**
     * Render ikb_menu component
     */
    protected function renderIkbMenu(array $node, array $attrs, array $children): string
    {
        $location = $attrs['location'] ?? 'primary';
        $classes = $attrs['class'] ?? '';
        
        $menuClasses = "ikb-menu menu-{$location}";
        if ($classes) {
            $menuClasses .= ' ' . $classes;
        }
        
        $menuItems = $this->context['menu'][$location] ?? [];
        
        if (empty($menuItems)) {
            return '';
        }
        
        $output = "<nav class=\"{$menuClasses}\"><ul class=\"menu-list\">";
        
        foreach ($menuItems as $item) {
            $activeClass = ($item['active'] ?? false) ? ' active' : '';
            $output .= '<li class="menu-item' . $activeClass . '">';
            $output .= '<a href="' . htmlspecialchars($item['url']) . '">' . htmlspecialchars($item['title']) . '</a>';
            
            if (!empty($item['children'])) {
                $output .= '<ul class="submenu">';
                foreach ($item['children'] as $child) {
                    $childActiveClass = ($child['active'] ?? false) ? ' active' : '';
                    $output .= '<li class="menu-item' . $childActiveClass . '">';
                    $output .= '<a href="' . htmlspecialchars($child['url']) . '">' . htmlspecialchars($child['title']) . '</a>';
                    $output .= '</li>';
                }
                $output .= '</ul>';
            }
            
            $output .= '</li>';
        }
        
        $output .= '</ul></nav>';
        
        return $output;
    }
    
    /**
     * Render ikb_widget_area component (Joomla module positions)
     */
    protected function renderIkbWidgetArea(array $node, array $attrs, array $children): string
    {
        $id = $attrs['id'] ?? 'sidebar';
        $classes = $attrs['class'] ?? '';
        
        $widgetClasses = "ikb-widget-area widget-{$id}";
        if ($classes) {
            $widgetClasses .= ' ' . $classes;
        }
        
        $widgets = $this->context['modules'][$id] ?? null;
        
        if (!$widgets || !($widgets['active'] ?? false)) {
            return '';
        }
        
        return "<div class=\"{$widgetClasses}\">{$widgets['content']}</div>";
    }
    
    
    /**
     * Render joomla_message
     */
    protected function renderJoomlaMessage(array $node, array $attrs, array $children): string
    {
        return '<jdoc:include type="message" />';
    }
    
    /**
     * Render for loop
     */
    protected function renderFor(array $node, array $attrs, array $children): string
    {
        $items = $attrs['items'] ?? '';
        $as = $attrs['as'] ?? 'item';
        
        // Get items from context
        $itemsArray = $this->getContextValue($items);
        
        if (!is_array($itemsArray) || empty($itemsArray)) {
            return '';
        }
        
        $output = '';
        $originalContext = $this->context;
        
        foreach ($itemsArray as $index => $item) {
            // Set loop variable in context
            $this->context[$as] = $item;
            $this->context[$as . '_index'] = $index;
            $this->context[$as . '_first'] = ($index === 0);
            $this->context[$as . '_last'] = ($index === count($itemsArray) - 1);
            
            // Render children with item context
            $output .= $this->renderChildren($children);
        }
        
        // Restore original context
        $this->context = $originalContext;
        
        return $output;
    }
    
    /**
     * Render conditional blocks
     * Properly handles {if}...{else}...{/if} structure
     */
    protected function renderIf(array $node, array $attrs, array $children): string
    {
        $condition = $attrs['condition'] ?? '';
        
        // Evaluate condition
        $result = $this->evaluateCondition($condition);
        
        // Split children into if-block and else-block
        $ifChildren = [];
        $elseChildren = [];
        $inElseBlock = false;
        
        foreach ($children as $child) {
            // Check if this is an {else} tag
            if (isset($child['type']) && $child['type'] === 'tag' && 
                isset($child['name']) && $child['name'] === 'else') {
                $inElseBlock = true;
                continue; // Skip the {else} tag itself
            }
            
            if ($inElseBlock) {
                $elseChildren[] = $child;
            } else {
                $ifChildren[] = $child;
            }
        }
        
        if ($result) {
            // Render if block
            return $this->renderChildren($ifChildren);
        } else {
            // Render else block
            return $this->renderChildren($elseChildren);
        }
    }
    
    /**
     * Evaluate condition expression
     * Enhanced to handle complex conditions like ||, &&, comparisons
     */
    private function evaluateCondition(string $condition): bool
    {
        // Handle OR conditions
        if (strpos($condition, '||') !== false) {
            $parts = explode('||', $condition);
            foreach ($parts as $part) {
                if ($this->evaluateCondition(trim($part))) {
                    return true;
                }
            }
            return false;
        }
        
        // Handle AND conditions
        if (strpos($condition, '&&') !== false) {
            $parts = explode('&&', $condition);
            foreach ($parts as $part) {
                if (!$this->evaluateCondition(trim($part))) {
                    return false;
                }
            }
            return true;
        }
        
        // Handle comparison operators
        $operators = ['>=', '<=', '==', '!=', '>', '<'];
        foreach ($operators as $op) {
            if (strpos($condition, $op) !== false) {
                list($left, $right) = explode($op, $condition, 2);
                $left = $this->evaluateExpression(trim($left));
                $right = $this->evaluateExpression(trim($right));
                
                switch ($op) {
                    case '>=': return $left >= $right;
                    case '<=': return $left <= $right;
                    case '==': return $left == $right;
                    case '!=': return $left != $right;
                    case '>': return $left > $right;
                    case '<': return $left < $right;
                }
            }
        }
        
        // Handle negation
        if (strpos($condition, '!') === 0) {
            return !$this->evaluateCondition(substr($condition, 1));
        }
        
        // Simple truthy check
        $value = $this->evaluateExpression($condition);
        return !empty($value);
    }
    
    /**
     * Get value from context by dot notation
     */
    private function getContextValue(string $path)
    {
        $keys = explode('.', $path);
        $value = $this->context;
        
        foreach ($keys as $key) {
            if (is_array($value) && isset($value[$key])) {
                $value = $value[$key];
            } elseif (is_object($value) && isset($value->$key)) {
                $value = $value->$key;
            } else {
                return null;
            }
        }
        
        return $value;
    }
    
    /**
     * Render joomla_module component
     * Usage: {joomla_module position="header" style="card" /}
     */
    protected function renderJoomlaModule(array $node, array $attrs, array $children): string
    {
        $position = $attrs['position'] ?? '';
        $style = $attrs['style'] ?? 'none';
        $limit = isset($attrs['limit']) ? (int)$attrs['limit'] : 0;
        
        if (empty($position)) {
            return '<!-- joomla_module: no position specified -->';
        }
        
        try {
            $modules = ModuleHelper::getModules($position);
            
            if ($limit > 0) {
                $modules = array_slice($modules, 0, $limit);
            }
            
            if (empty($modules)) {
                return '';
            }
            
            // Get current template and load chrome file
            $app = \Joomla\CMS\Factory::getApplication();
            $template = $app->getTemplate();
            $chromePath = JPATH_THEMES . '/' . $template . '/html/modules.php';
            
            // Include chrome file if it exists
            $chromeFunction = 'modChrome_' . $style;
            if (file_exists($chromePath) && !function_exists($chromeFunction)) {
                include_once $chromePath;
            }
            
            $output = '';
            foreach ($modules as $module) {
                // Use Joomla's module rendering with chrome style
                ob_start();
                
                // If chrome function exists, use it directly
                if (function_exists($chromeFunction)) {
                    // Ensure params is a Registry object
                    $params = $module->params;
                    if (is_string($params)) {
                        $params = new \Joomla\Registry\Registry($params);
                    } elseif (!is_object($params)) {
                        $params = new \Joomla\Registry\Registry();
                    }
                    
                    $attribs = [];
                    call_user_func($chromeFunction, $module, $params, $attribs);
                } else {
                    // Fallback to default rendering
                    $attribs = ['style' => $style];
                    echo ModuleHelper::renderModule($module, $attribs);
                }
                
                $output .= ob_get_clean();
            }
            
            return $output;
        } catch (\Exception $e) {
            return '<!-- joomla_module error: ' . htmlspecialchars($e->getMessage()) . ' -->';
        }
    }
    
    /**
     * Render joomla_params component
     * Usage: {joomla_params name="logoFile" default="" /}
     */
    protected function renderJoomlaParams(array $node, array $attrs, array $children): string
    {
        $name = $attrs['name'] ?? '';
        $default = $attrs['default'] ?? '';
        
        if (empty($name)) {
            return $default;
        }
        
        // Get from context first (already loaded in disyl-integration.php)
        $value = $this->getContextValue('joomla.params.' . $name);
        
        if ($value !== null) {
            return (string)$value;
        }
        
        return $default;
    }
    
    /**
     * Render joomla_field component
     * Usage: {joomla_field name="hero_image" context="com_content.article" id=1 /}
     */
    protected function renderJoomlaField(array $node, array $attrs, array $children): string
    {
        $name = $attrs['name'] ?? '';
        $context = $attrs['context'] ?? 'com_content.article';
        $itemId = $attrs['id'] ?? 0;
        
        if (empty($name) || empty($itemId)) {
            return '';
        }
        
        try {
            // Check if already in context
            $contextKey = 'joomla.fields.' . $context . '.' . $itemId . '.' . $name;
            $value = $this->getContextValue($contextKey);
            
            if ($value !== null) {
                return (string)$value;
            }
            
            // Fallback: load from Joomla fields API
            if (class_exists('\\Joomla\\Component\\Fields\\Administrator\\Helper\\FieldsHelper')) {
                $fields = FieldsHelper::getFields($context, (object)['id' => $itemId]);
                
                foreach ($fields as $field) {
                    if ($field->name === $name) {
                        return (string)$field->rawvalue;
                    }
                }
            }
            
            return '';
        } catch (\Exception $e) {
            return '<!-- joomla_field error: ' . htmlspecialchars($e->getMessage()) . ' -->';
        }
    }
    
    /**
     * Render joomla_route component
     * Usage: {joomla_route view="article" id=1 catid=8 /}
     */
    protected function renderJoomlaRoute(array $node, array $attrs, array $children): string
    {
        $view = $attrs['view'] ?? '';
        $id = $attrs['id'] ?? 0;
        $catid = $attrs['catid'] ?? 0;
        
        // Use direct URLs (non-SEF) to avoid routing warnings and 404s
        // SEF routing can be enabled later once properly configured
        
        if ($view === 'article' && $id) {
            // Direct article URL
            return '/?option=com_content&view=article&id=' . $id . ($catid ? '&catid=' . $catid : '');
        } elseif ($view === 'category' && $catid) {
            // Direct category URL
            return '/?option=com_content&view=category&id=' . $catid;
        } else {
            // Generic URL
            return $attrs['url'] ?? '';
        }
    }
}
