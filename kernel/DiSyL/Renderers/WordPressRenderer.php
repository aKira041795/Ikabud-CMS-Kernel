<?php
/**
 * DiSyL WordPress Renderer
 * 
 * Renders DiSyL templates for WordPress CMS
 * Maps DiSyL components to WordPress functions and templates
 * 
 * @version 0.1.0
 */

namespace IkabudKernel\Core\DiSyL\Renderers;

use IkabudKernel\CMS\Adapters\WordPressAdapter;
use IkabudKernel\Core\DiSyL\ModularManifestLoader;

class WordPressRenderer extends ManifestDrivenRenderer
{
    private ?WordPressAdapter $cms = null;
    
    public function __construct(?WordPressAdapter $cms = null)
    {
        $this->cms = $cms;
        $this->initializeCMS();
    }
    
    /**
     * Initialize WordPress context
     */
    protected function initializeCMS(): void
    {
        // WordPress-specific initialization
        // Load WordPress functions if not already loaded
    }
    
    /**
     * Render ikb_section component
     * Uses manifest-driven rendering with WordPress-specific enhancements
     */
    protected function renderIkbSection(array $node, array $attrs, array $children): string
    {
        // Get component from manifest
        $component = ModularManifestLoader::getComponent('ikb_section');
        
        if (!$component) {
            // Fallback if manifest not loaded
            return $this->renderFromManifest('ikb_section', $attrs, $children);
        }
        
        // Build CSS classes from manifest
        $classes = $this->buildCssClasses($component, $attrs);
        
        // Build data attributes from manifest
        $dataAttrs = $this->buildDataAttributes($component, $attrs);
        
        // Allow WordPress filters to modify classes
        if (function_exists('apply_filters')) {
            $classes = apply_filters('disyl_section_classes', $classes, $attrs['type'] ?? '', $attrs);
        }
        
        // Build HTML
        $html = '<section';
        
        if (!empty($classes)) {
            $html .= ' class="' . esc_attr(implode(' ', $classes)) . '"';
        }
        
        foreach ($dataAttrs as $key => $value) {
            $html .= ' ' . $key . '="' . esc_attr($value) . '"';
        }
        
        $html .= '>';
        
        // Add title if provided
        if (!empty($attrs['title'])) {
            $html .= '<h2 class="ikb-section-title">' . esc_html($attrs['title']) . '</h2>';
        }
        
        $html .= $this->renderChildren($children);
        $html .= '</section>';
        
        return $html;
    }
    
    /**
     * Render ikb_block component
     * Uses manifest-driven rendering
     */
    protected function renderIkbBlock(array $node, array $attrs, array $children): string
    {
        $component = ModularManifestLoader::getComponent('ikb_block');
        
        if (!$component) {
            return $this->renderFromManifest('ikb_block', $attrs, $children);
        }
        
        $classes = $this->buildCssClasses($component, $attrs);
        $dataAttrs = $this->buildDataAttributes($component, $attrs);
        
        if (function_exists('apply_filters')) {
            $classes = apply_filters('disyl_block_classes', $classes, $attrs);
        }
        
        $html = '<div';
        
        if (!empty($classes)) {
            $html .= ' class="' . esc_attr(implode(' ', $classes)) . '"';
        }
        
        foreach ($dataAttrs as $key => $value) {
            $html .= ' ' . $key . '="' . esc_attr($value) . '"';
        }
        
        $html .= '>';
        $html .= $this->renderChildren($children);
        $html .= '</div>';
        
        return $html;
    }
    
    /**
     * Render ikb_container component
     * Uses manifest-driven rendering
     */
    protected function renderIkbContainer(array $node, array $attrs, array $children): string
    {
        $component = ModularManifestLoader::getComponent('ikb_container');
        
        if (!$component) {
            return $this->renderFromManifest('ikb_container', $attrs, $children);
        }
        
        $classes = $this->buildCssClasses($component, $attrs);
        $dataAttrs = $this->buildDataAttributes($component, $attrs);
        
        $html = '<div';
        
        if (!empty($classes)) {
            $html .= ' class="' . esc_attr(implode(' ', $classes)) . '"';
        }
        
        foreach ($dataAttrs as $key => $value) {
            $html .= ' ' . $key . '="' . esc_attr($value) . '"';
        }
        
        $html .= '>';
        $html .= $this->renderChildren($children);
        $html .= '</div>';
        
        return $html;
    }
    
    /**
     * Render ikb_card component
     * Uses manifest-driven rendering
     */
    protected function renderIkbCard(array $node, array $attrs, array $children): string
    {
        $component = ModularManifestLoader::getComponent('ikb_card');
        
        if (!$component) {
            return $this->renderFromManifest('ikb_card', $attrs, $children);
        }
        
        $title = $attrs['title'] ?? '';
        $image = $attrs['image'] ?? '';
        $link = $attrs['link'] ?? '';
        
        $classes = $this->buildCssClasses($component, $attrs);
        $dataAttrs = $this->buildDataAttributes($component, $attrs);
        
        $html = '<div';
        
        if (!empty($classes)) {
            $html .= ' class="' . esc_attr(implode(' ', $classes)) . '"';
        }
        
        foreach ($dataAttrs as $key => $value) {
            $html .= ' ' . $key . '="' . esc_attr($value) . '"';
        }
        
        $html .= '>';
        
        if ($image) {
            if (function_exists('wp_get_attachment_image_url')) {
                $imageId = attachment_url_to_postid($image);
                if ($imageId) {
                    $html .= wp_get_attachment_image($imageId, 'medium', false, [
                        'class' => 'ikb-card-image',
                        'alt' => esc_attr($title)
                    ]);
                } else {
                    $html .= '<img src="' . esc_url($image) . '" alt="' . esc_attr($title) . '" class="ikb-card-image">';
                }
            } else {
                $html .= '<img src="' . esc_url($image) . '" alt="' . esc_attr($title) . '" class="ikb-card-image">';
            }
        }
        
        if ($title) {
            $html .= '<h3 class="ikb-card-title">' . esc_html($title) . '</h3>';
        }
        
        $html .= $this->renderChildren($children);
        
        if ($link) {
            $html = '<a href="' . esc_url($link) . '" class="ikb-card-link">' . $html . '</a>';
        }
        
        $html .= '</div>';
        
        return $html;
    }
    
    /**
     * Render ikb_image component
     */
    protected function renderIkbImage(array $node, array $attrs, array $children): string
    {
        $src = $attrs['src'] ?? '';
        $alt = $attrs['alt'] ?? '';
        $width = $attrs['width'] ?? null;
        $height = $attrs['height'] ?? null;
        $lazy = $attrs['lazy'] ?? true;
        $responsive = $attrs['responsive'] ?? true;
        
        // Try to use WordPress image functions
        if (function_exists('wp_get_attachment_image_url')) {
            $imageId = attachment_url_to_postid($src);
            if ($imageId) {
                $size = 'full';
                if ($width && $width <= 300) $size = 'medium';
                if ($width && $width <= 150) $size = 'thumbnail';
                
                $imageAttrs = ['alt' => $alt];
                if ($lazy) $imageAttrs['loading'] = 'lazy';
                if ($responsive) $imageAttrs['class'] = 'ikb-image-responsive';
                
                return wp_get_attachment_image($imageId, $size, false, $imageAttrs);
            }
        }
        
        // Fallback to standard img tag
        $html = '<img src="' . esc_url($src) . '" alt="' . esc_attr($alt) . '"';
        
        if ($width) {
            $html .= ' width="' . (int)$width . '"';
        }
        
        if ($height) {
            $html .= ' height="' . (int)$height . '"';
        }
        
        if ($lazy) {
            $html .= ' loading="lazy"';
        }
        
        if ($responsive) {
            $html .= ' class="ikb-image-responsive"';
        }
        
        $html .= '>';
        
        return $html;
    }
    
    /**
     * Render ikb_content component (raw HTML content)
     */
    protected function renderIkbContent(array $node, array $attrs, array $children): string
    {
        // Render children and evaluate expressions, but don't escape HTML
        $content = '';
        foreach ($children as $child) {
            if ($child['type'] === 'text') {
                $text = $child['value'];
                // Interpolate expressions but return raw value
                $text = preg_replace_callback('/\{([a-zA-Z0-9_.]+)\}/', function($matches) {
                    $expr = $matches[1];
                    $value = $this->evaluateExpression($expr);
                    // Return raw value without escaping
                    return is_string($value) ? $value : '';
                }, $text);
                $content .= $text;
            } elseif ($child['type'] === 'expression') {
                // Handle expression nodes - evaluate and return raw HTML
                $expr = $child['value'];
                $value = $this->evaluateExpression($expr);
                $content .= is_string($value) ? $value : '';
            } else {
                $content .= $this->renderNode($child);
            }
        }
        
        return $content;
    }
    
    /**
     * Render ikb_text component
     * Uses manifest-driven rendering
     */
    protected function renderIkbText(array $node, array $attrs, array $children): string
    {
        $component = ModularManifestLoader::getComponent('ikb_text');
        
        if (!$component) {
            return $this->renderFromManifest('ikb_text', $attrs, $children);
        }
        
        $classes = $this->buildCssClasses($component, $attrs);
        $dataAttrs = $this->buildDataAttributes($component, $attrs);
        
        $html = '<div';
        
        if (!empty($classes)) {
            $html .= ' class="' . esc_attr(implode(' ', $classes)) . '"';
        }
        
        foreach ($dataAttrs as $key => $value) {
            $html .= ' ' . $key . '="' . esc_attr($value) . '"';
        }
        
        $html .= '>';
        $html .= $this->renderChildren($children);
        $html .= '</div>';
        
        return $html;
    }
    
    /**
     * Render ikb_query component
     */
    protected function renderIkbQuery(array $node, array $attrs, array $children): string
    {
        $type = $attrs['type'] ?? 'post';
        $limit = $attrs['limit'] ?? 10;
        $orderby = $attrs['orderby'] ?? 'date';
        $order = $attrs['order'] ?? 'desc';
        $category = $attrs['category'] ?? null;
        
        // Special case: On single post/page, use the main query if limit=1
        if ($limit == 1 && function_exists('is_singular') && is_singular()) {
            global $wp_query;
            $query = $wp_query;
        }
        // Special case: On category/tag/archive pages, use main query to respect filtering
        elseif (function_exists('is_category') && is_category() && !$category) {
            global $wp_query;
            $query = $wp_query;
        }
        elseif (function_exists('is_tag') && is_tag() && !$category) {
            global $wp_query;
            $query = $wp_query;
        }
        elseif (function_exists('is_archive') && is_archive() && !$category) {
            global $wp_query;
            $query = $wp_query;
        } else {
            // Build WP_Query args
            $args = [
                'post_type' => $type,
                'posts_per_page' => $limit,
                'orderby' => $orderby,
                'order' => strtoupper($order),
                'post_status' => 'publish'
            ];
            
            if ($category) {
                $args['category_name'] = $category;
            }
            
            // Allow WordPress filters
            if (function_exists('apply_filters')) {
                $args = apply_filters('disyl_query_args', $args, $attrs);
            }
            
            // Execute WP_Query
            if (class_exists('WP_Query')) {
                $query = new \WP_Query($args);
            } else {
                // Fallback if WordPress not loaded
                return '<!-- WordPress not loaded -->';
            }
        }
        
        $html = '';
        
        if ($query->have_posts()) {
            $originalContext = $this->context;
            
            while ($query->have_posts()) {
                $query->the_post();
                
                // Set item context with WordPress post data
                $this->context['item'] = [
                    'id' => get_the_ID(),
                    'title' => get_the_title(),
                    'content' => apply_filters('the_content', get_the_content()), // Apply WordPress filters
                    'excerpt' => get_the_excerpt(),
                    'url' => get_permalink(),
                    'date' => get_the_date(),
                    'author' => get_the_author(),
                    'thumbnail' => get_the_post_thumbnail_url('medium'),
                    'categories' => wp_get_post_categories(get_the_ID(), ['fields' => 'names'])
                ];
                
                error_log('[DiSyL] ikb_query rendering ' . count($children) . ' children');
                $html .= $this->renderChildren($children);
            }
            
            wp_reset_postdata();
            $this->context = $originalContext;
        }
        
        error_log('[DiSyL] ikb_query final output length: ' . strlen($html));
        return $html;
    }
    
    /**
     * Render if control structure
     */
    protected function renderIf(array $node, array $attrs, array $children): string
    {
        $condition = $attrs['condition'] ?? '';
        
        // Evaluate condition
        $result = $this->evaluateCondition($condition);
        
        if ($result) {
            return $this->renderChildren($children);
        }
        
        return '';
    }
    
    /**
     * Render for loop
     */
    protected function renderFor(array $node, array $attrs, array $children): string
    {
        $items = $attrs['items'] ?? '';
        $as = $attrs['as'] ?? 'item';
        
        // Get items from context
        $itemsArray = $this->evaluateExpression($items);
        
        if (!is_array($itemsArray)) {
            return '';
        }
        
        $html = '';
        $originalContext = $this->context;
        
        foreach ($itemsArray as $item) {
            $this->context[$as] = $item;
            $html .= $this->renderChildren($children);
        }
        
        $this->context = $originalContext;
        
        return $html;
    }
    
    /**
     * Render include
     */
    protected function renderInclude(array $node, array $attrs, array $children): string
    {
        // Support both 'template' and 'file' attributes
        $template = $attrs['file'] ?? $attrs['template'] ?? '';
        
        if (empty($template)) {
            return '<!-- Include: no template specified -->';
        }
        
        // Remove .disyl extension if provided
        $template = preg_replace('/\.disyl$/', '', $template);
        
        // Build path to DiSyL template
        $theme_dir = get_stylesheet_directory();
        $template_path = $theme_dir . '/disyl/' . $template . '.disyl';
        
        // Check if template exists
        if (!file_exists($template_path)) {
            return '<!-- Include: template not found: ' . esc_html($template) . ' -->';
        }
        
        // Load and render directly with current renderer
        try {
            $template_content = file_get_contents($template_path);
            
            $lexer = new \IkabudKernel\Core\DiSyL\Lexer();
            $parser = new \IkabudKernel\Core\DiSyL\Parser();
            $compiler = new \IkabudKernel\Core\DiSyL\Compiler();
            
            $tokens = $lexer->tokenize($template_content);
            $ast = $parser->parse($tokens);
            $compiled = $compiler->compile($ast);
            
            // Render children directly with current context (not through CMS adapter)
            return $this->renderChildren($compiled['children'] ?? []);
            
        } catch (\Exception $e) {
            return '<!-- Include error: ' . esc_html($e->getMessage()) . ' -->';
        }
    }
    
    /**
     * Evaluate condition expression
     * Supports: ||, &&, >, <, >=, <=, ==, !=
     */
    private function evaluateCondition(string $condition): bool
    {
        // Check for WordPress conditional tags (simple function calls)
        if (function_exists($condition) && strpos($condition, ' ') === false) {
            return call_user_func($condition);
        }
        
        // Handle OR operator (||)
        if (strpos($condition, '||') !== false) {
            $parts = explode('||', $condition);
            foreach ($parts as $part) {
                if ($this->evaluateCondition(trim($part))) {
                    return true;
                }
            }
            return false;
        }
        
        // Handle AND operator (&&)
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
                $parts = explode($op, $condition, 2);
                if (count($parts) === 2) {
                    $left = trim($parts[0]);
                    $right = trim($parts[1]);
                    
                    // Evaluate both sides
                    $leftValue = $this->evaluateExpression($left);
                    $rightValue = is_numeric($right) ? (float)$right : $this->evaluateExpression($right);
                    
                    // Perform comparison
                    switch ($op) {
                        case '>': return $leftValue > $rightValue;
                        case '<': return $leftValue < $rightValue;
                        case '>=': return $leftValue >= $rightValue;
                        case '<=': return $leftValue <= $rightValue;
                        case '==': return $leftValue == $rightValue;
                        case '!=': return $leftValue != $rightValue;
                    }
                }
            }
        }
        
        // Simple evaluation (truthy check)
        $value = $this->evaluateExpression($condition);
        return (bool)$value;
    }
    
    /**
     * DO NOT OVERRIDE renderText() - BaseRenderer handles it correctly
     * 
     * The BaseRenderer's renderText() properly:
     * 1. Processes embedded expressions with filters
     * 2. Escapes dynamic content
     * 3. Preserves raw HTML
     * 
     * WordPressRenderer should only override CMS-specific component rendering,
     * not core text rendering logic.
     * 
     * This follows the Liskov Substitution Principle - child classes should
     * extend parent behavior, not break it.
     */
    
    /**
     * Override expression rendering to use WordPress escaping
     */
    protected function renderExpression(array $node): string
    {
        $expr = $node['value'];
        $value = $this->evaluateExpression($expr);
        
        // Convert value to string
        if (is_array($value)) {
            $str = $this->arrayToString($value);
        } elseif (is_object($value)) {
            $str = method_exists($value, '__toString') ? (string)$value : '';
        } else {
            $str = (string)$value;
        }
        
        // Use WordPress escaping if available
        if (function_exists('esc_html')) {
            return esc_html($str);
        }
        
        return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
    }
}
