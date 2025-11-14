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

class WordPressRenderer extends BaseRenderer
{
    private WordPressAdapter $cms;
    
    public function __construct(WordPressAdapter $cms)
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
     */
    protected function renderIkbSection(array $node, array $attrs, array $children): string
    {
        $type = $attrs['type'] ?? 'content';
        $title = $attrs['title'] ?? '';
        $bg = $attrs['bg'] ?? 'transparent';
        $padding = $attrs['padding'] ?? 'normal';
        
        $paddingMap = [
            'none' => '0',
            'small' => '1rem',
            'normal' => '2rem',
            'large' => '4rem'
        ];
        
        $paddingValue = $paddingMap[$padding] ?? '2rem';
        
        // WordPress-specific classes
        $classes = ['ikb-section', 'ikb-section-' . $type];
        
        // Allow WordPress filters to modify classes
        if (function_exists('apply_filters')) {
            $classes = apply_filters('disyl_section_classes', $classes, $type, $attrs);
        }
        
        $html = sprintf(
            '<section class="%s" style="background: %s; padding: %s;">',
            esc_attr(implode(' ', $classes)),
            esc_attr($bg),
            $paddingValue
        );
        
        if ($title) {
            $html .= '<h2 class="ikb-section-title">' . esc_html($title) . '</h2>';
        }
        
        $html .= $this->renderChildren($children);
        $html .= '</section>';
        
        return $html;
    }
    
    /**
     * Render ikb_block component
     */
    protected function renderIkbBlock(array $node, array $attrs, array $children): string
    {
        $cols = $attrs['cols'] ?? 1;
        $gap = $attrs['gap'] ?? 1;
        $align = $attrs['align'] ?? 'left';
        
        $classes = ['ikb-block', 'ikb-block-cols-' . $cols];
        
        if (function_exists('apply_filters')) {
            $classes = apply_filters('disyl_block_classes', $classes, $cols, $attrs);
        }
        
        $html = sprintf(
            '<div class="%s" style="display: grid; grid-template-columns: repeat(%d, 1fr); gap: %srem; text-align: %s;">',
            esc_attr(implode(' ', $classes)),
            $cols,
            $gap,
            esc_attr($align)
        );
        
        $html .= $this->renderChildren($children);
        $html .= '</div>';
        
        return $html;
    }
    
    /**
     * Render ikb_container component
     */
    protected function renderIkbContainer(array $node, array $attrs, array $children): string
    {
        $width = $attrs['width'] ?? 'lg';
        $center = $attrs['center'] ?? true;
        
        $widthMap = [
            'sm' => '640px',
            'md' => '768px',
            'lg' => '1024px',
            'xl' => '1280px',
            'full' => '100%'
        ];
        
        $maxWidth = $widthMap[$width] ?? '1024px';
        $margin = $center ? '0 auto' : '0';
        
        $classes = ['ikb-container', 'ikb-container-' . $width];
        
        $html = sprintf(
            '<div class="%s" style="max-width: %s; margin: %s;">',
            esc_attr(implode(' ', $classes)),
            $maxWidth,
            $margin
        );
        
        $html .= $this->renderChildren($children);
        $html .= '</div>';
        
        return $html;
    }
    
    /**
     * Render ikb_card component
     */
    protected function renderIkbCard(array $node, array $attrs, array $children): string
    {
        $title = $attrs['title'] ?? '';
        $image = $attrs['image'] ?? '';
        $link = $attrs['link'] ?? '';
        $variant = $attrs['variant'] ?? 'default';
        
        $variantStyles = [
            'default' => 'border: 1px solid #ddd; padding: 1rem;',
            'outlined' => 'border: 2px solid #333; padding: 1rem;',
            'elevated' => 'box-shadow: 0 4px 6px rgba(0,0,0,0.1); padding: 1rem;'
        ];
        
        $style = $variantStyles[$variant] ?? $variantStyles['default'];
        $classes = ['ikb-card', 'ikb-card-' . $variant];
        
        $html = '<div class="' . esc_attr(implode(' ', $classes)) . '" style="' . $style . '">';
        
        if ($image) {
            // Use WordPress image functions if available
            if (function_exists('wp_get_attachment_image_url')) {
                $imageId = attachment_url_to_postid($image);
                if ($imageId) {
                    $html .= wp_get_attachment_image($imageId, 'medium', false, [
                        'class' => 'ikb-card-image',
                        'alt' => esc_attr($title)
                    ]);
                } else {
                    $html .= '<img src="' . esc_url($image) . '" alt="' . esc_attr($title) . '" class="ikb-card-image" style="width: 100%; height: auto;">';
                }
            } else {
                $html .= '<img src="' . esc_url($image) . '" alt="' . esc_attr($title) . '" class="ikb-card-image" style="width: 100%; height: auto;">';
            }
        }
        
        if ($title) {
            $html .= '<h3 class="ikb-card-title">' . esc_html($title) . '</h3>';
        }
        
        $html .= $this->renderChildren($children);
        
        if ($link) {
            $html = '<a href="' . esc_url($link) . '" class="ikb-card-link" style="text-decoration: none; color: inherit;">' . $html . '</a>';
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
            $html .= ' style="max-width: 100%; height: auto;"';
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
     */
    protected function renderIkbText(array $node, array $attrs, array $children): string
    {
        $size = $attrs['size'] ?? 'md';
        $weight = $attrs['weight'] ?? 'normal';
        $color = $attrs['color'] ?? '';
        $align = $attrs['align'] ?? 'left';
        
        $sizeMap = [
            'xs' => '0.75rem',
            'sm' => '0.875rem',
            'md' => '1rem',
            'lg' => '1.125rem',
            'xl' => '1.25rem',
            '2xl' => '1.5rem'
        ];
        
        $weightMap = [
            'light' => '300',
            'normal' => '400',
            'medium' => '500',
            'bold' => '700'
        ];
        
        $fontSize = $sizeMap[$size] ?? '1rem';
        $fontWeight = $weightMap[$weight] ?? '400';
        
        $style = sprintf(
            'font-size: %s; font-weight: %s; text-align: %s;',
            $fontSize,
            $fontWeight,
            esc_attr($align)
        );
        
        if ($color) {
            $style .= ' color: ' . esc_attr($color) . ';';
        }
        
        $classes = ['ikb-text', 'ikb-text-' . $size];
        
        $html = '<div class="' . esc_attr(implode(' ', $classes)) . '" style="' . $style . '">';
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
     */
    private function evaluateCondition(string $condition): bool
    {
        // Check for WordPress conditional tags
        if (function_exists($condition)) {
            return call_user_func($condition);
        }
        
        // Simple evaluation
        $value = $this->evaluateExpression($condition);
        return (bool)$value;
    }
    
    /**
     * Override text rendering to use WordPress escaping and support filters
     */
    protected function renderText(array $node): string
    {
        $text = $node['value'];
        
        // First, handle filter expressions: {item.title | upper}
        $text = preg_replace_callback('/\{([a-zA-Z0-9_.]+)\s*\|\s*([^}]+)\}/', function($matches) {
            $expr = $matches[1];
            $filterChain = $matches[2];
            
            // Evaluate base expression
            $value = $this->evaluateExpression($expr);
            
            // Apply filters
            $value = $this->applyFilters($value, $filterChain);
            
            // Convert to string
            return $this->valueToString($value);
        }, $text);
        
        // Then, interpolate simple expressions like {title}, {item.title}
        $text = preg_replace_callback('/\{([a-zA-Z0-9_.]+)\}/', function($matches) {
            $expr = $matches[1];
            $value = $this->evaluateExpression($expr);
            
            // Convert value to string and escape
            if (function_exists('esc_html')) {
                return esc_html($this->valueToString($value));
            }
            return htmlspecialchars($this->valueToString($value), ENT_QUOTES, 'UTF-8');
        }, $text);
        
        // Return text as-is - it may contain raw HTML which should not be escaped
        // The embedded expressions have already been processed and escaped as needed
        return $text;
    }
    
    /**
     * Override expression rendering to use WordPress escaping
     */
    protected function renderExpression(array $node): string
    {
        $expr = $node['value'];
        $value = $this->evaluateExpression($expr);
        
        // Convert value to string
        if (is_array($value)) {
            $str = implode(', ', $value);
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
