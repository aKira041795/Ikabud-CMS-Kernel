<?php
/**
 * DiSyL Component Registry v0.2.0
 * 
 * Manages component definitions and schemas for DiSyL v1.2.0
 * Aligned with Grammar v1.2.0 for:
 * - Platform compatibility
 * - Slot definitions
 * - Rich prop schemas
 * 
 * @version 0.2.0
 */

namespace IkabudKernel\Core\DiSyL;

class ComponentRegistry
{
    /**
     * Component categories (aligned with Grammar::COMPONENT_CATEGORIES)
     */
    public const CATEGORY_STRUCTURAL = 'structural';
    public const CATEGORY_DATA = 'data';
    public const CATEGORY_UI = 'ui';
    public const CATEGORY_CONTROL = 'control';
    public const CATEGORY_MEDIA = 'media';
    public const CATEGORY_LAYOUT = 'layout';
    public const CATEGORY_CONTENT = 'content';
    public const CATEGORY_INTERACTIVE = 'interactive';
    public const CATEGORY_NAVIGATION = 'navigation';
    public const CATEGORY_FORM = 'form';
    
    /**
     * Registered components
     */
    private static array $components = [];
    
    /**
     * Register a component
     * 
     * @param string $name Component name (e.g., 'ikb_section')
     * @param array $definition Component definition
     */
    public static function register(string $name, array $definition): void
    {
        self::$components[$name] = array_merge([
            'name' => $name,
            'category' => self::CATEGORY_UI,
            'description' => '',
            'attributes' => [],
            'slots' => [],
            'leaf' => false,
            'renderer' => null,
            'platforms' => [Grammar::PLATFORM_UNIVERSAL], // Default: works everywhere
            'version' => '1.0.0',
        ], $definition);
    }
    
    /**
     * Get component definition
     * 
     * @param string $name Component name
     * @return array|null Component definition or null if not found
     */
    public static function get(string $name): ?array
    {
        return self::$components[$name] ?? null;
    }
    
    /**
     * Check if component exists
     * 
     * @param string $name Component name
     * @return bool True if component is registered
     */
    public static function has(string $name): bool
    {
        return isset(self::$components[$name]);
    }
    
    /**
     * Get all registered components
     * 
     * @return array All components
     */
    public static function all(): array
    {
        return self::$components;
    }
    
    /**
     * Get components by category
     * 
     * @param string $category Category name
     * @return array Components in category
     */
    public static function getByCategory(string $category): array
    {
        return array_filter(
            self::$components,
            fn($component) => $component['category'] === $category
        );
    }
    
    /**
     * Get component attribute schemas
     * 
     * @param string $name Component name
     * @return array Attribute schemas
     */
    public static function getAttributeSchemas(string $name): array
    {
        $component = self::get($name);
        return $component ? $component['attributes'] : [];
    }
    
    /**
     * Clear all registered components (for testing)
     */
    public static function clear(): void
    {
        self::$components = [];
    }
    
    /**
     * List all components (for Visual Builder)
     * 
     * @param string|null $platform Filter by platform
     * @param string|null $category Filter by category
     * @return array Component list with metadata
     */
    public static function list(?string $platform = null, ?string $category = null): array
    {
        $result = [];
        
        foreach (self::$components as $name => $def) {
            // Filter by category
            if ($category !== null && ($def['category'] ?? '') !== $category) {
                continue;
            }
            
            // Filter by platform
            if ($platform !== null && isset($def['platforms'])) {
                $platforms = $def['platforms'];
                if (!in_array(Grammar::PLATFORM_UNIVERSAL, $platforms, true) &&
                    !in_array($platform, $platforms, true)) {
                    continue;
                }
            }
            
            $result[] = [
                'name' => $name,
                'category' => $def['category'] ?? 'ui',
                'description' => $def['description'] ?? '',
                'leaf' => $def['leaf'] ?? false,
                'platforms' => $def['platforms'] ?? [Grammar::PLATFORM_UNIVERSAL],
                'version' => $def['version'] ?? '1.0.0',
            ];
        }
        
        return $result;
    }
    
    /**
     * Get component slots
     * 
     * @param string $name Component name
     * @return array Slot definitions
     */
    public static function getSlots(string $name): array
    {
        $component = self::get($name);
        return $component ? ($component['slots'] ?? []) : [];
    }
    
    /**
     * Check if component supports platform
     * 
     * @param string $name Component name
     * @param string $platform Platform identifier
     * @return bool
     */
    public static function supportsPlatform(string $name, string $platform): bool
    {
        $component = self::get($name);
        if (!$component) {
            return false;
        }
        
        $platforms = $component['platforms'] ?? [Grammar::PLATFORM_UNIVERSAL];
        return in_array(Grammar::PLATFORM_UNIVERSAL, $platforms, true) ||
               in_array($platform, $platforms, true);
    }
    
    /**
     * Register core DiSyL components
     */
    public static function registerCoreComponents(): void
    {
        // Structural: ikb_section
        self::register('ikb_section', [
            'category' => self::CATEGORY_STRUCTURAL,
            'description' => 'Main structural container for page sections',
            'attributes' => [
                'type' => [
                    'type' => Grammar::TYPE_STRING,
                    'enum' => ['hero', 'content', 'footer', 'sidebar'],
                    'default' => 'content',
                    'description' => 'Section type'
                ],
                'title' => [
                    'type' => Grammar::TYPE_STRING,
                    'required' => false,
                    'description' => 'Section title'
                ],
                'bg' => [
                    'type' => Grammar::TYPE_STRING,
                    'default' => 'transparent',
                    'description' => 'Background color'
                ],
                'padding' => [
                    'type' => Grammar::TYPE_STRING,
                    'default' => 'normal',
                    'enum' => ['none', 'small', 'normal', 'large'],
                    'description' => 'Section padding'
                ]
            ],
            'leaf' => false
        ]);
        
        // Structural: ikb_block
        self::register('ikb_block', [
            'category' => self::CATEGORY_STRUCTURAL,
            'description' => 'Generic content block with layout options',
            'attributes' => [
                'cols' => [
                    'type' => Grammar::TYPE_INTEGER,
                    'default' => 1,
                    'min' => 1,
                    'max' => 12,
                    'description' => 'Number of columns'
                ],
                'gap' => [
                    'type' => Grammar::TYPE_NUMBER,
                    'default' => 1,
                    'min' => 0,
                    'max' => 10,
                    'description' => 'Gap between items (in rem)'
                ],
                'align' => [
                    'type' => Grammar::TYPE_STRING,
                    'enum' => ['left', 'center', 'right', 'justify'],
                    'default' => 'left',
                    'description' => 'Content alignment'
                ]
            ],
            'leaf' => false
        ]);
        
        // Structural: ikb_container
        self::register('ikb_container', [
            'category' => self::CATEGORY_STRUCTURAL,
            'description' => 'Responsive container with max-width',
            'attributes' => [
                'width' => [
                    'type' => Grammar::TYPE_STRING,
                    'enum' => ['sm', 'md', 'lg', 'xl', 'full'],
                    'default' => 'lg',
                    'description' => 'Container width'
                ],
                'center' => [
                    'type' => Grammar::TYPE_BOOLEAN,
                    'default' => true,
                    'description' => 'Center container horizontally'
                ]
            ],
            'leaf' => false
        ]);
        
        // Data: ikb_query
        self::register('ikb_query', [
            'category' => self::CATEGORY_DATA,
            'description' => 'Query and loop over content items with optional auto-rendering',
            'attributes' => [
                'type' => [
                    'type' => Grammar::TYPE_STRING,
                    'default' => 'post',
                    'description' => 'Content type to query'
                ],
                'limit' => [
                    'type' => Grammar::TYPE_INTEGER,
                    'default' => 10,
                    'min' => 1,
                    'max' => 100,
                    'description' => 'Maximum number of items'
                ],
                'orderby' => [
                    'type' => Grammar::TYPE_STRING,
                    'enum' => ['date', 'title', 'modified', 'random'],
                    'default' => 'date',
                    'description' => 'Order by field'
                ],
                'order' => [
                    'type' => Grammar::TYPE_STRING,
                    'enum' => ['asc', 'desc'],
                    'default' => 'desc',
                    'description' => 'Sort order'
                ],
                'category' => [
                    'type' => Grammar::TYPE_STRING,
                    'required' => false,
                    'description' => 'Filter by category'
                ],
                'exclude_category' => [
                    'type' => Grammar::TYPE_STRING,
                    'required' => false,
                    'description' => 'Exclude category (comma-separated)'
                ],
                'format' => [
                    'type' => Grammar::TYPE_STRING,
                    'required' => false,
                    'enum' => ['card', 'list', 'grid', 'hero', 'minimal', 'full', 'timeline', 'carousel', 'table', 'accordion'],
                    'description' => 'Auto-render format (enables DSL rendering)'
                ],
                'layout' => [
                    'type' => Grammar::TYPE_STRING,
                    'required' => false,
                    'enum' => ['vertical', 'horizontal', 'grid-2', 'grid-3', 'grid-4', 'masonry', 'slider'],
                    'default' => 'vertical',
                    'description' => 'Layout wrapper (used with format)'
                ],
                'columns' => [
                    'type' => Grammar::TYPE_INTEGER,
                    'required' => false,
                    'default' => 3,
                    'min' => 1,
                    'max' => 6,
                    'description' => 'Number of columns for grid layouts'
                ],
                'gap' => [
                    'type' => Grammar::TYPE_STRING,
                    'required' => false,
                    'enum' => ['none', 'small', 'medium', 'large'],
                    'default' => 'medium',
                    'description' => 'Gap between items'
                ]
            ],
            'leaf' => false
        ]);
        
        // UI: ikb_card
        self::register('ikb_card', [
            'category' => self::CATEGORY_UI,
            'description' => 'Card component for displaying content',
            'attributes' => [
                'title' => [
                    'type' => Grammar::TYPE_STRING,
                    'required' => false,
                    'description' => 'Card title'
                ],
                'image' => [
                    'type' => Grammar::TYPE_STRING,
                    'required' => false,
                    'description' => 'Card image URL'
                ],
                'link' => [
                    'type' => Grammar::TYPE_STRING,
                    'required' => false,
                    'description' => 'Card link URL'
                ],
                'variant' => [
                    'type' => Grammar::TYPE_STRING,
                    'enum' => ['default', 'outlined', 'elevated'],
                    'default' => 'default',
                    'description' => 'Card style variant'
                ]
            ],
            'leaf' => false
        ]);
        
        // UI: ikb_image
        self::register('ikb_image', [
            'category' => self::CATEGORY_MEDIA,
            'description' => 'Responsive image with optimization',
            'attributes' => [
                'src' => [
                    'type' => Grammar::TYPE_STRING,
                    'required' => true,
                    'description' => 'Image source URL'
                ],
                'alt' => [
                    'type' => Grammar::TYPE_STRING,
                    'required' => true,
                    'description' => 'Image alt text'
                ],
                'width' => [
                    'type' => Grammar::TYPE_INTEGER,
                    'required' => false,
                    'min' => 1,
                    'description' => 'Image width in pixels'
                ],
                'height' => [
                    'type' => Grammar::TYPE_INTEGER,
                    'required' => false,
                    'min' => 1,
                    'description' => 'Image height in pixels'
                ],
                'lazy' => [
                    'type' => Grammar::TYPE_BOOLEAN,
                    'default' => true,
                    'description' => 'Enable lazy loading'
                ],
                'responsive' => [
                    'type' => Grammar::TYPE_BOOLEAN,
                    'default' => true,
                    'description' => 'Make image responsive'
                ]
            ],
            'leaf' => true
        ]);
        
        // UI: ikb_text
        self::register('ikb_text', [
            'category' => self::CATEGORY_UI,
            'description' => 'Text content with formatting',
            'attributes' => [
                'size' => [
                    'type' => Grammar::TYPE_STRING,
                    'enum' => ['xs', 'sm', 'md', 'lg', 'xl', '2xl'],
                    'default' => 'md',
                    'description' => 'Text size'
                ],
                'weight' => [
                    'type' => Grammar::TYPE_STRING,
                    'enum' => ['light', 'normal', 'medium', 'bold'],
                    'default' => 'normal',
                    'description' => 'Font weight'
                ],
                'color' => [
                    'type' => Grammar::TYPE_STRING,
                    'required' => false,
                    'description' => 'Text color'
                ],
                'align' => [
                    'type' => Grammar::TYPE_STRING,
                    'enum' => ['left', 'center', 'right', 'justify'],
                    'default' => 'left',
                    'description' => 'Text alignment'
                ]
            ],
            'leaf' => false
        ]);
        
        // Control: if
        self::register('if', [
            'category' => self::CATEGORY_CONTROL,
            'description' => 'Conditional rendering',
            'attributes' => [
                'condition' => [
                    'type' => Grammar::TYPE_STRING,
                    'required' => true,
                    'description' => 'Condition expression'
                ]
            ],
            'leaf' => false
        ]);
        
        // Control: for
        self::register('for', [
            'category' => self::CATEGORY_CONTROL,
            'description' => 'Loop over items',
            'attributes' => [
                'items' => [
                    'type' => Grammar::TYPE_STRING,
                    'required' => true,
                    'description' => 'Items to loop over'
                ],
                'as' => [
                    'type' => Grammar::TYPE_STRING,
                    'default' => 'item',
                    'description' => 'Variable name for each item'
                ]
            ],
            'leaf' => false
        ]);
        
        // Control: include
        self::register('include', [
            'category' => self::CATEGORY_CONTROL,
            'description' => 'Include another template',
            'attributes' => [
                'template' => [
                    'type' => Grammar::TYPE_STRING,
                    'required' => true,
                    'description' => 'Template path to include'
                ]
            ],
            'leaf' => true
        ]);
    }
}

// Auto-register core components
ComponentRegistry::registerCoreComponents();
