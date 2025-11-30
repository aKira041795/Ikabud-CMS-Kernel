<?php
/**
 * DiSyL Native Renderer
 * 
 * Renders DiSyL templates for Native Ikabud CMS
 * 
 * @version 0.1.0
 */

namespace IkabudKernel\Core\DiSyL\Renderers;

use IkabudKernel\CMS\Adapters\NativeAdapter;

class NativeRenderer extends BaseRenderer
{
    private NativeAdapter $cms;
    
    public function __construct(NativeAdapter $cms)
    {
        $this->cms = $cms;
        $this->initializeCMS();
    }
    
    /**
     * Initialize CMS context
     */
    protected function initializeCMS(): void
    {
        // Native CMS initialization (if needed)
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
        
        $html = sprintf(
            '<section class="ikb-section ikb-section-%s" style="background: %s; padding: %s;">',
            htmlspecialchars($type),
            htmlspecialchars($bg),
            $paddingValue
        );
        
        if ($title) {
            $html .= '<h2 class="ikb-section-title">' . htmlspecialchars($title) . '</h2>';
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
        
        $html = sprintf(
            '<div class="ikb-block" style="display: grid; grid-template-columns: repeat(%d, 1fr); gap: %srem; text-align: %s;">',
            $cols,
            $gap,
            htmlspecialchars($align)
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
        
        $html = sprintf(
            '<div class="ikb-container" style="max-width: %s; margin: %s;">',
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
        
        $html = '<div class="ikb-card" style="' . $style . '">';
        
        if ($image) {
            $html .= '<img src="' . htmlspecialchars($image) . '" alt="' . htmlspecialchars($title) . '" style="width: 100%; height: auto;">';
        }
        
        if ($title) {
            $html .= '<h3 class="ikb-card-title">' . htmlspecialchars($title) . '</h3>';
        }
        
        $html .= $this->renderChildren($children);
        
        if ($link) {
            $html = '<a href="' . htmlspecialchars($link) . '" style="text-decoration: none; color: inherit;">' . $html . '</a>';
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
        
        $html = '<img src="' . htmlspecialchars($src) . '" alt="' . htmlspecialchars($alt) . '"';
        
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
            htmlspecialchars($align)
        );
        
        if ($color) {
            $style .= ' color: ' . htmlspecialchars($color) . ';';
        }
        
        $html = '<div class="ikb-text" style="' . $style . '">';
        $html .= $this->renderChildren($children);
        $html .= '</div>';
        
        return $html;
    }
    
    /**
     * Render ikb_query component
     * Supports cross-instance queries via instance="" or cms="" attributes
     */
    protected function renderIkbQuery(array $node, array $attrs, array $children): string
    {
        // Check for cross-instance query first
        $crossInstanceResult = $this->handleCrossInstanceQuery($attrs, $children);
        if ($crossInstanceResult !== null) {
            return $crossInstanceResult;
        }
        
        // Local/static query
        $type = $attrs['type'] ?? 'post';
        $limit = $attrs['limit'] ?? 10;
        $orderby = $attrs['orderby'] ?? 'date';
        $order = $attrs['order'] ?? 'desc';
        $category = $attrs['category'] ?? null;
        
        // Execute query through CMS
        $query = [
            'type' => $type,
            'limit' => $limit,
            'orderby' => $orderby,
            'order' => $order
        ];
        
        if ($category) {
            $query['category'] = $category;
        }
        
        $items = $this->cms->executeQuery($query);
        
        $html = '';
        
        // Render children for each item
        foreach ($items as $item) {
            // Set item context
            $originalContext = $this->context;
            $this->context['item'] = $item;
            
            $html .= $this->renderChildren($children);
            
            // Restore context
            $this->context = $originalContext;
        }
        
        return $html;
    }
    
    /**
     * Render if control structure
     */
    protected function renderIf(array $node, array $attrs, array $children): string
    {
        $condition = $attrs['condition'] ?? '';
        
        // Simple condition evaluation (can be enhanced)
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
        $template = $attrs['template'] ?? '';
        
        // TODO: Load and render included template
        return '<!-- Include: ' . htmlspecialchars($template) . ' -->';
    }
    
    /**
     * Evaluate condition expression
     * Supports: ||, &&, >, <, >=, <=, ==, !=
     */
    private function evaluateCondition(string $condition): bool
    {
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
}
