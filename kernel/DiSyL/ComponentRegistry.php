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
 * @version 1.0.0
 */

namespace Ikabud\Kernel\DiSyL;

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
     * Validate all registered component definitions for common issues.
     *
     * Checks:
     *  - Renderer template paths exist (when 'renderer' is a non-null string)
     *  - Slot names follow dot-notation convention (when 'slots' is non-empty)
     *
     * Logs warnings via write_log() — never throws.
     *
     * @return list<string> List of warning messages (for testing/inspection)
     */
    public static function validateRegisteredComponents(): array
    {
        $warnings = [];
        $projectRoot = \dirname(__DIR__, 2); // kernel/DiSyL/ -> project root

        foreach (self::$components as $name => $def) {
            // --- Renderer template path check ---
            $renderer = $def['renderer'] ?? null;
            if ($renderer !== null && \is_string($renderer) && $renderer !== '') {
                $pathToCheck = $renderer;
                if (\str_starts_with($renderer, '/')) {
                    // Absolute path — check directly
                    $pathToCheck = $renderer;
                } elseif (\str_starts_with($renderer, './') || \str_starts_with($renderer, '../')) {
                    // Relative path — resolve against project root
                    $pathToCheck = $projectRoot . '/' . \ltrim($renderer, './');
                } else {
                    // Plain filename or template reference — resolve against project root
                    $pathToCheck = $projectRoot . '/' . $renderer;
                }

                // Normalize to prevent false negatives from double slashes etc.
                $normalized = self::normalizePath($pathToCheck);

                if (!\file_exists($normalized)) {
                    $msg = "Component '{$name}': renderer template path not found: {$renderer} (resolved: {$normalized})";
                    $warnings[] = $msg;
                    if (\function_exists('write_log')) {
                        \write_log('disyl.component_registry', 'warning', [
                            'component' => $name,
                            'renderer' => $renderer,
                            'resolved' => $normalized,
                            'message' => 'Renderer template path not found',
                        ]);
                    }
                }
            }

            // --- Slot name convention check ---
            $slots = $def['slots'] ?? [];
            if (!empty($slots) && \is_array($slots)) {
                foreach ($slots as $slotKey => $slotDef) {
                    // Support both indexed arrays and keyed slot definitions
                    $slotName = \is_string($slotKey) ? $slotKey : (\is_string($slotDef) ? $slotDef : ($slotDef['name'] ?? ''));
                    if (\is_string($slotName) && $slotName !== '') {
                        // Validate dot-notation convention: segment.segment[.segment...]
                        if (!\preg_match('/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/', $slotName)) {
                            $msg = "Component '{$name}': slot name '{$slotName}' does not follow dot-notation convention (e.g. 'content.after', 'header.main')";
                            $warnings[] = $msg;
                            if (\function_exists('write_log')) {
                                \write_log('disyl.component_registry', 'warning', [
                                    'component' => $name,
                                    'slot' => $slotName,
                                    'message' => 'Slot name does not follow dot-notation convention',
                                ]);
                            }
                        }
                    }
                }
            }
        }

        return $warnings;
    }

    /**
     * Normalize a file path — collapses '..' and '.' segments.
     */
    private static function normalizePath(string $path): string
    {
        $parts = \explode('/', \str_replace('\\', '/', $path));
        $resolved = [];
        foreach ($parts as $part) {
            if ($part === '.' || $part === '') {
                continue;
            }
            if ($part === '..') {
                \array_pop($resolved);
            } else {
                $resolved[] = $part;
            }
        }
        $prefix = '';
        if (\str_starts_with($path, '/')) {
            $prefix = '/';
        }
        return $prefix . \implode('/', $resolved);
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

        // Structural: ikb_grid
        self::register('ikb_grid', [
            'category' => self::CATEGORY_STRUCTURAL,
            'description' => 'Responsive grid layout with configurable columns and gap',
            'attributes' => [
                'columns' => [
                    'type' => Grammar::TYPE_INTEGER,
                    'default' => 2,
                    'min' => 1,
                    'max' => 12,
                    'description' => 'Number of grid columns'
                ],
                'gap' => [
                    'type' => Grammar::TYPE_STRING,
                    'default' => 'md',
                    'enum' => ['none', 'xs', 'sm', 'md', 'lg', 'xl'],
                    'description' => 'Grid gap token'
                ],
                'align' => [
                    'type' => Grammar::TYPE_STRING,
                    'enum' => ['start', 'center', 'end', 'stretch'],
                    'default' => 'stretch',
                    'description' => 'Cross-axis item alignment'
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
        
        // Structural: ikb_region (governed theme region)
        self::register('ikb_region', [
            'category' => self::CATEGORY_STRUCTURAL,
            'description' => 'Governed theme region — renders theme-customizer region HTML if available (via ThemeCustomizerOrchestrator), otherwise renders fallback children content. Replaces the {if *_region_present}...{else}...{/if} boilerplate pattern.',
            'attributes' => [
                'name' => [
                    'type' => Grammar::TYPE_STRING,
                    'required' => true,
                    'description' => 'Region identifier (e.g., "header", "footer", "sidebar")'
                ],
                'fallback' => [
                    'type' => Grammar::TYPE_STRING,
                    'required' => false,
                    'description' => 'Deprecated — use children instead. Fallback template path if region is not present.'
                ]
            ],
            'leaf' => false
        ]);

        // Structural: ikb_slot (governed theme slot)
        self::register('ikb_slot', [
            'category' => self::CATEGORY_STRUCTURAL,
            'description' => 'Governed theme slot — modules may register content contributions via SlotRegistry. Renders all matching contributions in priority order, each wrapped in its contributed component.',
            'attributes' => [
                'name' => [
                    'type' => Grammar::TYPE_STRING,
                    'required' => true,
                    'description' => 'Slot identifier (e.g., "content.after", "header.main")'
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

        // Data: ikb_entity_list (business component — resolves source/view to governed data)
        self::register('ikb_entity_list', [
            'category' => self::CATEGORY_DATA,
            'description' => 'Governed entity list resolved from source/view declaration',
            'attributes' => [
                'source' => [
                    'type' => Grammar::TYPE_STRING,
                    'required' => true,
                    'description' => 'Entity source string (e.g. orders.recent, products.featured)'
                ],
                'view' => [
                    'type' => Grammar::TYPE_STRING,
                    'enum' => ['compact', 'detailed', 'card_grid', 'table', 'admin_row'],
                    'default' => 'compact',
                    'description' => 'View preset'
                ],
                'limit' => [
                    'type' => Grammar::TYPE_INTEGER,
                    'required' => false,
                    'description' => 'Max rows (overrides view contract default)'
                ],
                'empty' => [
                    'type' => Grammar::TYPE_STRING,
                    'required' => false,
                    'description' => 'Custom empty-state message'
                ],
                'excerpt-length' => [
                    'type' => Grammar::TYPE_INTEGER,
                    'required' => false,
                    'description' => 'Maximum subtitle/excerpt characters for card-grid rendering'
                ],
                'excerpt_length' => [
                    'type' => Grammar::TYPE_INTEGER,
                    'required' => false,
                    'description' => 'Maximum subtitle/excerpt characters for card-grid rendering'
                ],
                'excerptLength' => [
                    'type' => Grammar::TYPE_INTEGER,
                    'required' => false,
                    'description' => 'Maximum subtitle/excerpt characters for card-grid rendering'
                ],
                'actions' => [
                    'type' => Grammar::TYPE_STRING,
                    'required' => false,
                    'description' => 'Comma-separated action names (view, edit, delete, add_to_cart)'
                ],
                'header' => [
                    'type' => Grammar::TYPE_STRING,
                    'required' => false,
                    'description' => 'HTML or #blockName rendered above the list (for inline forms, filters)'
                ],
                'row-click' => [
                    'type' => Grammar::TYPE_STRING,
                    'required' => false,
                    'description' => 'URL pattern for clickable rows (e.g. /admin/employees/{id})'
                ],
                'row-click-target' => [
                    'type' => Grammar::TYPE_STRING,
                    'required' => false,
                    'description' => 'Browser target for row-click navigation (e.g. _blank)'
                ],
                'search' => [
                    'type' => Grammar::TYPE_BOOLEAN,
                    'required' => false,
                    'default' => false,
                    'description' => 'Enable client-side search/filter bar (Alpine.js)'
                ],
                'search-placeholder' => [
                    'type' => Grammar::TYPE_STRING,
                    'required' => false,
                    'default' => 'Search...',
                    'description' => 'Placeholder text for the search input'
                ],
                'bulk-actions' => [
                    'type' => Grammar::TYPE_STRING,
                    'required' => false,
                    'description' => 'Comma-separated bulk action names (delete, export, approve)'
                ],
                'bulk-action-url' => [
                    'type' => Grammar::TYPE_STRING,
                    'required' => false,
                    'description' => 'POST endpoint for bulk actions'
                ],
                'auth-role' => [
                    'type' => Grammar::TYPE_STRING,
                    'required' => false,
                    'description' => 'Override user role for auth-aware action visibility'
                ],
                'action-roles' => [
                    'type' => Grammar::TYPE_EXPRESSION,
                    'required' => false,
                    'description' => 'JSON map of action → required role(s) (e.g. {"delete":"admin"})'
                ]
            ],
            'leaf' => false
        ]);

        // Data: ikb_entity_detail (business component — single entity detail view)
        self::register('ikb_entity_detail', [
            'category' => self::CATEGORY_DATA,
            'description' => 'Governed entity detail view for a single entity',
            'attributes' => [
                'source' => [
                    'type' => Grammar::TYPE_STRING,
                    'required' => true,
                    'description' => 'Entity type (e.g. order, product, case)'
                ],
                'id' => [
                    'type' => Grammar::TYPE_STRING,
                    'required' => true,
                    'description' => 'Entity ID'
                ],
                'view' => [
                    'type' => Grammar::TYPE_STRING,
                    'enum' => ['detailed', 'summary', 'admin'],
                    'default' => 'detailed',
                    'description' => 'View preset'
                ],
                'fields' => [
                    'type' => Grammar::TYPE_STRING,
                    'required' => false,
                    'description' => 'Comma-separated field names to show'
                ]
            ],
            'leaf' => false
        ]);

        // Interactive: ikb_export_button (export entity data as PDF/DOCX/CSV)
        self::register('ikb_export_button', [
            'category' => self::CATEGORY_INTERACTIVE,
            'description' => 'Governed export button — downloads entity data as PDF, DOCX, CSV, or XLSX',
            'attributes' => [
                'source' => [
                    'type' => Grammar::TYPE_STRING,
                    'required' => true,
                    'description' => 'Entity type to export (e.g. orders, cases, ledger)'
                ],
                'format' => [
                    'type' => Grammar::TYPE_STRING,
                    'enum' => ['csv', 'pdf', 'docx', 'xlsx'],
                    'default' => 'csv',
                    'description' => 'Export format'
                ],
                'label' => [
                    'type' => Grammar::TYPE_STRING,
                    'required' => false,
                    'description' => 'Button label (default: "Export CSV" etc.)'
                ],
                'variant' => [
                    'type' => Grammar::TYPE_STRING,
                    'enum' => ['outline', 'primary', 'secondary'],
                    'default' => 'outline',
                    'description' => 'Button visual variant'
                ],
                'size' => [
                    'type' => Grammar::TYPE_STRING,
                    'enum' => ['small', 'medium', 'large'],
                    'default' => 'medium',
                    'description' => 'Button size'
                ]
            ],
            'leaf' => true
        ]);

        // Form: ikb_form (governed form with capability action binding)
        self::register('ikb_form', [
            'category' => self::CATEGORY_FORM,
            'description' => 'Governed form — submits to capability actions with CSRF protection',
            'attributes' => [
                'action' => [
                    'type' => Grammar::TYPE_STRING,
                    'required' => false,
                    'description' => 'Capability action ID (e.g. ticket.create, order.submit)'
                ],
                'method' => [
                    'type' => Grammar::TYPE_STRING,
                    'enum' => ['POST', 'GET'],
                    'default' => 'POST',
                    'description' => 'Form method'
                ],
                'layout' => [
                    'type' => Grammar::TYPE_STRING,
                    'enum' => ['stacked', 'inline', 'guided'],
                    'default' => 'stacked',
                    'description' => 'Form layout preset'
                ],
                'csrf' => [
                    'type' => Grammar::TYPE_BOOLEAN,
                    'required' => false,
                    'description' => 'Include CSRF token (default: true)'
                ],
                'id' => [
                    'type' => Grammar::TYPE_STRING,
                    'required' => false,
                    'description' => 'Form element ID'
                ]
            ],
            'leaf' => false
        ]);

        // Data: ikb_stat_card (single metric display)
        self::register('ikb_stat_card', [
            'category' => self::CATEGORY_DATA,
            'description' => 'Stat card — label, value, trend indicator, and optional icon',
            'attributes' => [
                'label' => [
                    'type' => Grammar::TYPE_STRING,
                    'required' => true,
                    'description' => 'Stat label (e.g. Total Orders)'
                ],
                'value' => [
                    'type' => Grammar::TYPE_STRING,
                    'required' => true,
                    'description' => 'Stat value (e.g. 1,234)'
                ],
                'trend' => [
                    'type' => Grammar::TYPE_STRING,
                    'enum' => ['up', 'down', 'neutral'],
                    'required' => false,
                    'description' => 'Trend direction'
                ],
                'trend_value' => [
                    'type' => Grammar::TYPE_STRING,
                    'required' => false,
                    'description' => 'Trend change value (e.g. +12%)'
                ],
                'icon' => [
                    'type' => Grammar::TYPE_STRING,
                    'required' => false,
                    'description' => 'FontAwesome icon name'
                ],
                'variant' => [
                    'type' => Grammar::TYPE_STRING,
                    'enum' => ['elevated', 'outlined', 'flat'],
                    'default' => 'elevated',
                    'description' => 'Card visual variant'
                ]
            ],
            'leaf' => false
        ]);

        // Data: ikb_timeline (chronological event display)
        self::register('ikb_timeline', [
            'category' => self::CATEGORY_DATA,
            'description' => 'Timeline — chronological list of events with vertical connector',
            'attributes' => [
                'source' => [
                    'type' => Grammar::TYPE_STRING,
                    'required' => false,
                    'description' => 'Entity source for timeline data (optional; supports child nodes)'
                ]
            ],
            'leaf' => false
        ]);

        // Interactive: ikb_confirm_action (destructive action guard)
        self::register('ikb_confirm_action', [
            'category' => self::CATEGORY_INTERACTIVE,
            'description' => 'Confirm action — wraps destructive actions with Alpine.js confirmation dialog',
            'attributes' => [
                'message' => [
                    'type' => Grammar::TYPE_STRING,
                    'required' => false,
                    'description' => 'Confirmation message (default: Are you sure?)'
                ],
                'confirm' => [
                    'type' => Grammar::TYPE_STRING,
                    'required' => false,
                    'description' => 'Confirm button label (default: Confirm)'
                ],
                'cancel' => [
                    'type' => Grammar::TYPE_STRING,
                    'required' => false,
                    'description' => 'Cancel button label (default: Cancel)'
                ],
                'variant' => [
                    'type' => Grammar::TYPE_STRING,
                    'enum' => ['danger', 'warning', 'default'],
                    'default' => 'danger',
                    'description' => 'Confirm button color variant'
                ]
            ],
            'leaf' => false
        ]);

        // Layout: ikb_panel (theme-aware semantic panel)
        self::register('ikb_panel', [
            'category' => self::CATEGORY_LAYOUT,
            'description' => 'Semantic panel — theme-aware container with design tokens (tone, spacing, radius)',
            'attributes' => [
                'tone' => [
                    'type' => Grammar::TYPE_STRING,
                    'enum' => ['surface', 'muted', 'elevated', 'primary'],
                    'default' => 'surface',
                    'description' => 'Visual tone mapped to theme tokens'
                ],
                'spacing' => [
                    'type' => Grammar::TYPE_STRING,
                    'enum' => ['none', 'sm', 'md', 'lg', 'xl'],
                    'default' => 'md',
                    'description' => 'Inner spacing'
                ],
                'radius' => [
                    'type' => Grammar::TYPE_STRING,
                    'enum' => ['none', 'sm', 'md', 'lg', 'full'],
                    'default' => 'md',
                    'description' => 'Border radius'
                ]
            ],
            'leaf' => false
        ]);

        // Navigation: ikb_drawer (slide-out panel)
        self::register('ikb_drawer', [
            'category' => self::CATEGORY_NAVIGATION,
            'description' => 'Slide-out drawer panel with Alpine.js teleport',
            'attributes' => [
                'id' => [
                    'type' => Grammar::TYPE_STRING,
                    'required' => true,
                    'description' => 'Drawer ID for accessibility'
                ],
                'position' => [
                    'type' => Grammar::TYPE_STRING,
                    'enum' => ['left', 'right'],
                    'default' => 'right',
                    'description' => 'Slide direction'
                ],
                'title' => [
                    'type' => Grammar::TYPE_STRING,
                    'required' => false,
                    'description' => 'Drawer header title'
                ],
                'open' => [
                    'type' => Grammar::TYPE_BOOLEAN,
                    'required' => false,
                    'description' => 'Initially open (default: false)'
                ],
                'width' => [
                    'type' => Grammar::TYPE_STRING,
                    'required' => false,
                    'description' => 'CSS width (default: 320px)'
                ]
            ],
            'leaf' => false
        ]);

        // Data: ikb_audit_log (governed audit trail viewer)
        self::register('ikb_audit_log', [
            'category' => self::CATEGORY_DATA,
            'description' => 'Governed audit log viewer — displays audit trail entries via kernel.audit.list capability',
            'attributes' => [
                'source' => [
                    'type' => Grammar::TYPE_STRING,
                    'required' => true,
                    'description' => 'Entity type whose audit entries to display'
                ],
                'entity_id' => [
                    'type' => Grammar::TYPE_STRING,
                    'required' => false,
                    'description' => 'Specific entity ID (omit for all entries of type)'
                ],
                'limit' => [
                    'type' => Grammar::TYPE_INTEGER,
                    'required' => false,
                    'default' => 20,
                    'description' => 'Max audit entries'
                ]
            ],
            'leaf' => false
        ]);

        // AI: ikb_ai_summary (governed AI content generation)
        self::register('ikb_ai_summary', [
            'category' => self::CATEGORY_DATA,
            'description' => 'Governed AI summary block — generates summaries under kernel AI policy',
            'attributes' => [
                'capability' => [
                    'type' => Grammar::TYPE_STRING,
                    'required' => false,
                    'description' => 'Capability ID defining what to summarize'
                ],
                'source' => [
                    'type' => Grammar::TYPE_STRING,
                    'required' => false,
                    'description' => 'Entity source to fetch data for summarization'
                ],
                'review' => [
                    'type' => Grammar::TYPE_STRING,
                    'enum' => ['required', 'none'],
                    'default' => 'required',
                    'description' => 'Whether the output requires human review'
                ],
                'model' => [
                    'type' => Grammar::TYPE_STRING,
                    'required' => false,
                    'default' => 'gpt-4o-mini',
                    'description' => 'AI model ID (governed by Policy allowlist)'
                ],
                'max_tokens' => [
                    'type' => Grammar::TYPE_INTEGER,
                    'required' => false,
                    'default' => 256,
                    'description' => 'Max output tokens'
                ]
            ],
            'leaf' => false
        ]);

        // AI: ikb_ai_assist (governed AI drafting with human approval)
        self::register('ikb_ai_assist', [
            'category' => self::CATEGORY_DATA,
            'description' => 'Governed AI assist block — drafts content under kernel AI policy with human approval',
            'attributes' => [
                'capability' => [
                    'type' => Grammar::TYPE_STRING,
                    'required' => true,
                    'description' => 'Capability ID for the draft operation'
                ],
                'mode' => [
                    'type' => Grammar::TYPE_STRING,
                    'enum' => ['draft_only', 'suggest'],
                    'default' => 'draft_only',
                    'description' => 'Draft mode (draft_only = read-only, suggest = pre-filled)'
                ],
                'model' => [
                    'type' => Grammar::TYPE_STRING,
                    'required' => false,
                    'default' => 'gpt-4o-mini',
                    'description' => 'AI model ID'
                ],
                'max_tokens' => [
                    'type' => Grammar::TYPE_INTEGER,
                    'required' => false,
                    'default' => 512,
                    'description' => 'Max output tokens'
                ]
            ],
            'leaf' => false
        ]);
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

        // Validate all registered core components
        self::validateRegisteredComponents();
    }
}

// Auto-register core components
ComponentRegistry::registerCoreComponents();
