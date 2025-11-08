<?php
/**
 * Layout Engine - Wraps rendered content in layouts
 * 
 * Supports: vertical, horizontal, grid-2, grid-3, grid-4, etc.
 * Adds CSS classes and structure
 * 
 * @version 1.1.0
 */

namespace IkabudKernel\DSL;

class LayoutEngine
{
    /**
     * Wrap content in layout
     */
    public function wrap(string $content, string $layout = 'vertical', array $options = []): string
    {
        $columns = $options['columns'] ?? 3;
        $gap = $options['gap'] ?? 'medium';
        
        return match($layout) {
            'vertical' => $this->wrapVertical($content, $gap),
            'horizontal' => $this->wrapHorizontal($content, $gap),
            'grid-2' => $this->wrapGrid($content, 2, $gap),
            'grid-3' => $this->wrapGrid($content, 3, $gap),
            'grid-4' => $this->wrapGrid($content, 4, $gap),
            'masonry' => $this->wrapMasonry($content, $columns, $gap),
            'slider' => $this->wrapSlider($content),
            default => $this->wrapVertical($content, $gap)
        };
    }
    
    /**
     * Wrap in vertical layout
     */
    private function wrapVertical(string $content, string $gap): string
    {
        return sprintf(
            '<div class="ikb-layout ikb-layout-vertical ikb-gap-%s">%s</div>',
            $gap,
            $content
        );
    }
    
    /**
     * Wrap in horizontal layout
     */
    private function wrapHorizontal(string $content, string $gap): string
    {
        return sprintf(
            '<div class="ikb-layout ikb-layout-horizontal ikb-gap-%s">%s</div>',
            $gap,
            $content
        );
    }
    
    /**
     * Wrap in grid layout
     */
    private function wrapGrid(string $content, int $columns, string $gap): string
    {
        return sprintf(
            '<div class="ikb-layout ikb-layout-grid ikb-grid-cols-%d ikb-gap-%s">%s</div>',
            $columns,
            $gap,
            $content
        );
    }
    
    /**
     * Wrap in masonry layout
     */
    private function wrapMasonry(string $content, int $columns, string $gap): string
    {
        return sprintf(
            '<div class="ikb-layout ikb-layout-masonry ikb-cols-%d ikb-gap-%s">%s</div>',
            $columns,
            $gap,
            $content
        );
    }
    
    /**
     * Wrap in slider layout
     */
    private function wrapSlider(string $content): string
    {
        return sprintf(
            '<div class="ikb-layout ikb-layout-slider"><div class="ikb-slider-track">%s</div></div>',
            $content
        );
    }
    
    /**
     * Generate default CSS
     */
    public static function getDefaultCSS(): string
    {
        return <<<'CSS'
/* Ikabud DSL Default Styles */
.ikb-layout {
    width: 100%;
}

.ikb-layout-vertical {
    display: flex;
    flex-direction: column;
}

.ikb-layout-horizontal {
    display: flex;
    flex-direction: row;
    flex-wrap: wrap;
}

.ikb-layout-grid {
    display: grid;
}

.ikb-grid-cols-2 { grid-template-columns: repeat(2, 1fr); }
.ikb-grid-cols-3 { grid-template-columns: repeat(3, 1fr); }
.ikb-grid-cols-4 { grid-template-columns: repeat(4, 1fr); }

.ikb-gap-none { gap: 0; }
.ikb-gap-small { gap: 0.5rem; }
.ikb-gap-medium { gap: 1rem; }
.ikb-gap-large { gap: 2rem; }

.ikb-card {
    border: 1px solid #e5e7eb;
    border-radius: 0.5rem;
    overflow: hidden;
}

.ikb-card-image {
    width: 100%;
    height: auto;
}

.ikb-card-content {
    padding: 1rem;
}

.ikb-card-title {
    margin: 0 0 0.5rem 0;
    font-size: 1.25rem;
}

.ikb-card-excerpt {
    margin: 0 0 0.5rem 0;
    color: #6b7280;
}

.ikb-card-date {
    font-size: 0.875rem;
    color: #9ca3af;
}

.ikb-hero {
    position: relative;
    min-height: 400px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.ikb-hero-image {
    position: absolute;
    inset: 0;
    background-size: cover;
    background-position: center;
}

.ikb-hero-content {
    position: relative;
    z-index: 10;
    text-align: center;
    padding: 2rem;
    background: rgba(255, 255, 255, 0.9);
    border-radius: 0.5rem;
}

.ikb-no-results {
    padding: 2rem;
    text-align: center;
    color: #6b7280;
}
CSS;
    }
}
