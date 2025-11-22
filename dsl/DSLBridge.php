<?php
/**
 * DSL Bridge - Integration layer between DiSyL and DSL
 * 
 * Provides seamless integration between DiSyL renderers and DSL components
 * Handles data normalization and format rendering
 * 
 * @version 1.2.0
 */

namespace IkabudKernel\DSL;

class DSLBridge
{
    private static ?FormatRenderer $formatter = null;
    private static ?LayoutEngine $layoutEngine = null;
    
    /**
     * Initialize DSL components
     */
    private static function init(): void
    {
        if (self::$formatter === null) {
            self::$formatter = new FormatRenderer();
        }
        
        if (self::$layoutEngine === null) {
            self::$layoutEngine = new LayoutEngine();
        }
    }
    
    /**
     * Execute and render DSL query from DiSyL attributes
     * 
     * @param array $items Normalized data items
     * @param array $attrs DiSyL ikb_query attributes
     * @return string Rendered HTML
     */
    public static function renderItems(array $items, array $attrs): string
    {
        self::init();
        
        if (empty($items)) {
            return '<div class="ikb-no-results">No results found.</div>';
        }
        
        try {
            // Get format and layout from attributes
            $format = $attrs['format'] ?? 'card';
            $layout = $attrs['layout'] ?? 'vertical';
            
            // Render items with format
            $content = self::$formatter->render($items, $format);
            
            // Wrap with layout
            $layoutOptions = [
                'columns' => $attrs['columns'] ?? 3,
                'gap' => $attrs['gap'] ?? 'medium'
            ];
            
            return self::$layoutEngine->wrap($content, $layout, $layoutOptions);
            
        } catch (\Exception $e) {
            return '<!-- DSL Bridge Error: ' . htmlspecialchars($e->getMessage()) . ' -->';
        }
    }
    
    /**
     * Normalize CMS-specific data to DSL format
     * 
     * @param array $item Raw CMS item data
     * @param string $cms CMS type (wordpress, joomla, drupal)
     * @return array Normalized item
     */
    public static function normalizeItem(array $item, string $cms = 'wordpress'): array
    {
        return [
            'id' => $item['id'] ?? $item['ID'] ?? null,
            'title' => $item['title'] ?? $item['post_title'] ?? '',
            'excerpt' => $item['excerpt'] ?? $item['post_excerpt'] ?? '',
            'content' => $item['content'] ?? $item['post_content'] ?? '',
            'permalink' => $item['permalink'] ?? $item['url'] ?? $item['link'] ?? '',
            'date' => $item['date'] ?? $item['post_date'] ?? $item['created'] ?? '',
            'author' => $item['author'] ?? $item['post_author'] ?? '',
            'thumbnail' => $item['thumbnail'] ?? $item['featured_image'] ?? $item['image'] ?? '',
            'categories' => $item['categories'] ?? []
        ];
    }
    
    /**
     * Batch normalize items
     * 
     * @param array $items Array of items to normalize
     * @param string $cms CMS type
     * @return array Array of normalized items
     */
    public static function normalizeItems(array $items, string $cms = 'wordpress'): array
    {
        return array_map(function($item) use ($cms) {
            return self::normalizeItem($item, $cms);
        }, $items);
    }
    
    /**
     * Check if DSL rendering should be used
     * 
     * @param array $attrs DiSyL attributes
     * @return bool True if format attribute is present
     */
    public static function shouldUseDSL(array $attrs): bool
    {
        return isset($attrs['format']) && !empty($attrs['format']);
    }
    
    /**
     * Get available formats
     * 
     * @return array List of available format names
     */
    public static function getAvailableFormats(): array
    {
        return [
            'card',
            'list',
            'grid',
            'hero',
            'minimal',
            'full',
            'timeline',
            'carousel',
            'table',
            'accordion'
        ];
    }
    
    /**
     * Get available layouts
     * 
     * @return array List of available layout names
     */
    public static function getAvailableLayouts(): array
    {
        return [
            'vertical',
            'horizontal',
            'grid-2',
            'grid-3',
            'grid-4',
            'masonry',
            'slider'
        ];
    }
    
    /**
     * Validate format and layout combination
     * 
     * @param string $format Format name
     * @param string $layout Layout name
     * @return bool True if valid combination
     */
    public static function isValidCombination(string $format, string $layout): bool
    {
        // Some formats work better with specific layouts
        $incompatible = [
            'hero' => ['grid-2', 'grid-3', 'grid-4', 'masonry'],
            'table' => ['grid-2', 'grid-3', 'grid-4', 'masonry', 'slider'],
            'carousel' => ['grid-2', 'grid-3', 'grid-4', 'masonry']
        ];
        
        if (isset($incompatible[$format]) && in_array($layout, $incompatible[$format])) {
            return false;
        }
        
        return true;
    }
}
