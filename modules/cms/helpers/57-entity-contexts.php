<?php

declare(strict_types=1);

function cmsEntityContextOptionLabels(array $values): array
{
    $options = [];
    foreach ($values as $value) {
        if (!is_string($value)) {
            continue;
        }
        $options[] = [
            'value' => $value,
            'label' => $value === '' ? 'Default' : ucwords(str_replace(['-', '_'], ' ', $value)),
        ];
    }

    return $options;
}

function cmsEntityContextFontOptions(): array
{
    return [
        ['value' => 'Inter', 'label' => 'Inter'],
        ['value' => 'Roboto', 'label' => 'Roboto'],
        ['value' => 'Open Sans', 'label' => 'Open Sans'],
        ['value' => 'Lato', 'label' => 'Lato'],
        ['value' => 'Montserrat', 'label' => 'Montserrat'],
        ['value' => 'Poppins', 'label' => 'Poppins'],
        ['value' => 'Playfair Display', 'label' => 'Playfair Display'],
        ['value' => 'Merriweather', 'label' => 'Merriweather'],
        ['value' => 'DM Sans', 'label' => 'DM Sans'],
        ['value' => 'Nunito', 'label' => 'Nunito'],
        ['value' => 'Source Sans Pro', 'label' => 'Source Sans Pro'],
        ['value' => 'Georgia', 'label' => 'Georgia'],
        ['value' => 'system-ui', 'label' => 'System UI'],
    ];
}

function cmsEntityContextFieldCatalog(): array
{
    $layoutProfiles = function_exists('cmsEntityLayoutProfiles') ? cmsEntityLayoutProfiles() : ['default', 'content', 'commerce'];
    $variantOptions = function_exists('cmsThemeBlockVariantOptions') ? cmsThemeBlockVariantOptions() : [];
    $fontOptions = cmsEntityContextFontOptions();

    return [
        'entity_layout_profile' => [
            'name' => 'entity_layout_profile',
            'label' => 'Layout Profile',
            'type' => 'select',
            'priority' => 90,
            'options' => cmsEntityContextOptionLabels($layoutProfiles),
            'default' => 'default',
            'description' => 'Use approved canonical orderings rather than theme-specific template forks.',
        ],
        'entity_spacing_scale' => [
            'name' => 'entity_spacing_scale',
            'label' => 'Spacing Scale',
            'type' => 'select',
            'priority' => 80,
            'options' => cmsEntityContextOptionLabels(['compact', 'comfortable', 'airy']),
            'default' => 'comfortable',
            'description' => 'Adjust the canonical spacing rhythm inside entity layouts.',
        ],
        'entity_media_ratio' => [
            'name' => 'entity_media_ratio',
            'label' => 'Media Ratio',
            'type' => 'select',
            'priority' => 70,
            'options' => cmsEntityContextOptionLabels(['auto', '16:9', '4:3', '1:1']),
            'default' => 'auto',
            'description' => 'Applies to hero media and gallery surfaces when that capability is present.',
        ],
        'entity_action_size' => [
            'name' => 'entity_action_size',
            'label' => 'Action Size',
            'type' => 'select',
            'priority' => 60,
            'options' => cmsEntityContextOptionLabels(['sm', 'md', 'lg']),
            'default' => 'md',
            'description' => 'Controls the size of canonical entity actions.',
        ],
        'entity_pricing_variant' => [
            'name' => 'entity_pricing_variant',
            'label' => 'Pricing Variant',
            'type' => 'select',
            'priority' => 70,
            'options' => cmsEntityContextOptionLabels($variantOptions['pricing'] ?? ['']),
            'default' => '',
            'description' => 'Choose an approved pricing block style.',
        ],
        'entity_action_variant' => [
            'name' => 'entity_action_variant',
            'label' => 'Action Variant',
            'type' => 'select',
            'priority' => 65,
            'options' => cmsEntityContextOptionLabels($variantOptions['action'] ?? ['']),
            'default' => '',
            'description' => 'Inline keeps actions in flow; sticky footer pins them on compact viewports.',
        ],
        'entity_summary_width' => [
            'name' => 'entity_summary_width',
            'label' => 'Summary Width',
            'type' => 'range',
            'priority' => 55,
            'default' => '320',
            'description' => 'Width of the summary rail in pixels.',
            'min' => 260,
            'max' => 420,
            'step' => 10,
            'unit' => 'px',
        ],
        'entity_summary_sticky' => [
            'name' => 'entity_summary_sticky',
            'label' => 'Sticky Summary Rail',
            'type' => 'toggle',
            'priority' => 50,
            'default' => 1,
            'description' => 'Keeps the storefront summary rail anchored while the narrative content scrolls.',
        ],
        'entity_list_card_density' => [
            'name' => 'entity_list_card_density',
            'label' => 'List Card Density',
            'type' => 'select',
            'priority' => 45,
            'options' => cmsEntityContextOptionLabels(['compact', 'comfortable', 'airy']),
            'default' => 'comfortable',
            'description' => 'Adjust the breathing room inside canonical list cards.',
        ],
        'entity_list_show_excerpt' => [
            'name' => 'entity_list_show_excerpt',
            'label' => 'Show Card Excerpts',
            'type' => 'toggle',
            'priority' => 40,
            'default' => 1,
            'description' => 'Control whether canonical list cards surface a narrative teaser.',
        ],
        'entity_list_excerpt_length' => [
            'name' => 'entity_list_excerpt_length',
            'label' => 'Excerpt Length',
            'type' => 'number',
            'priority' => 35,
            'default' => '120',
            'description' => 'Maximum excerpt length for canonical list cards.',
            'min' => 40,
            'max' => 220,
            'step' => 10,
            'unit' => 'chars',
            'depends_on' => [
                'field' => 'entity_list_show_excerpt',
                'value' => 1,
            ],
        ],
        'entity_list_category_navigation' => [
            'name' => 'entity_list_category_navigation',
            'label' => 'Shop Categories',
            'type' => 'select',
            'priority' => 30,
            'options' => cmsEntityContextOptionLabels(['list', 'dropdown']),
            'default' => 'list',
            'description' => 'Choose how canonical list routes expose category navigation.',
        ],
        'entity_list_show_filter_summary' => [
            'name' => 'entity_list_show_filter_summary',
            'label' => 'Show Filter Summary',
            'type' => 'toggle',
            'priority' => 25,
            'default' => 1,
            'description' => 'Keep active catalog filters visible above the canonical list.',
        ],
        'entity_list_pricing_variant' => [
            'name' => 'entity_list_pricing_variant',
            'label' => 'List Pricing Variant',
            'type' => 'select',
            'priority' => 20,
            'options' => cmsEntityContextOptionLabels($variantOptions['list-card-pricing'] ?? ['']),
            'default' => '',
            'description' => 'Choose how pricing appears inside canonical list cards.',
        ],
        'entity_list_inventory_variant' => [
            'name' => 'entity_list_inventory_variant',
            'label' => 'List Inventory Variant',
            'type' => 'select',
            'priority' => 15,
            'options' => cmsEntityContextOptionLabels($variantOptions['list-card-inventory'] ?? ['']),
            'default' => '',
            'description' => 'Choose how inventory messaging appears inside canonical list cards.',
        ],
        'entity_list_progress_variant' => [
            'name' => 'entity_list_progress_variant',
            'label' => 'List Progress Variant',
            'type' => 'select',
            'priority' => 10,
            'options' => cmsEntityContextOptionLabels($variantOptions['list-card-progress'] ?? ['']),
            'default' => '',
            'description' => 'Choose how progress appears inside guidance-oriented list cards.',
        ],
        'entity_list_title_font' => [
            'name' => 'entity_list_title_font',
            'label' => 'Catalog Title Font',
            'type' => 'font_select',
            'priority' => 28,
            'options' => $fontOptions,
            'default' => '',
            'empty_option_label' => 'Inherit Heading Font',
            'description' => 'Optional font override for canonical list card titles.',
        ],
        'entity_list_text_font' => [
            'name' => 'entity_list_text_font',
            'label' => 'Catalog Text Font',
            'type' => 'font_select',
            'priority' => 27,
            'options' => $fontOptions,
            'default' => '',
            'empty_option_label' => 'Inherit Body Font',
            'description' => 'Optional font override for canonical list card text.',
        ],
        'entity_list_title_size' => [
            'name' => 'entity_list_title_size',
            'label' => 'Title Size',
            'type' => 'range',
            'priority' => 26,
            'default' => '19',
            'min' => 16,
            'max' => 32,
            'step' => 1,
            'unit' => 'px',
        ],
        'entity_list_price_size' => [
            'name' => 'entity_list_price_size',
            'label' => 'Price Size',
            'type' => 'range',
            'priority' => 24,
            'default' => '17',
            'min' => 14,
            'max' => 28,
            'step' => 1,
            'unit' => 'px',
        ],
        'entity_list_card_min_width' => [
            'name' => 'entity_list_card_min_width',
            'label' => 'Card Min Width',
            'type' => 'range',
            'priority' => 23,
            'default' => '240',
            'min' => 200,
            'max' => 340,
            'step' => 10,
            'unit' => 'px',
        ],
        'entity_list_title_lines' => [
            'name' => 'entity_list_title_lines',
            'label' => 'Title Clamp',
            'type' => 'select',
            'priority' => 22,
            'options' => [
                ['value' => '1', 'label' => '1 line'],
                ['value' => '2', 'label' => '2 lines'],
                ['value' => '3', 'label' => '3 lines'],
                ['value' => '4', 'label' => '4 lines'],
            ],
            'default' => '2',
        ],
        'blog_layout' => [
            'name' => 'blog_layout',
            'label' => 'List Layout',
            'type' => 'select',
            'priority' => 100,
            'options' => cmsEntityContextOptionLabels(['list', 'grid', 'cards']),
            'default' => 'list',
        ],
        'blog_columns' => [
            'name' => 'blog_columns',
            'label' => 'Columns',
            'type' => 'select',
            'priority' => 95,
            'options' => [
                ['value' => '2', 'label' => '2 Columns'],
                ['value' => '3', 'label' => '3 Columns'],
                ['value' => '4', 'label' => '4 Columns'],
            ],
            'default' => '2',
            'depends_on' => [
                'field' => 'blog_layout',
                'operator' => '!=',
                'value' => 'list',
            ],
        ],
        'blog_gap' => [
            'name' => 'blog_gap',
            'label' => 'Gap',
            'type' => 'number',
            'priority' => 90,
            'default' => '24',
            'min' => 0,
            'max' => 64,
            'step' => 1,
            'unit' => 'px',
        ],
        'blog_card_radius' => [
            'name' => 'blog_card_radius',
            'label' => 'Card Radius',
            'type' => 'number',
            'priority' => 85,
            'default' => '8',
            'min' => 0,
            'max' => 24,
            'step' => 1,
            'unit' => 'px',
        ],
        'blog_featured_image' => [
            'name' => 'blog_featured_image',
            'label' => 'Show Image',
            'type' => 'toggle',
            'priority' => 80,
            'default' => 1,
        ],
        'blog_image_height' => [
            'name' => 'blog_image_height',
            'label' => 'Image Height',
            'type' => 'number',
            'priority' => 75,
            'default' => '208',
            'min' => 100,
            'max' => 500,
            'step' => 1,
            'unit' => 'px',
            'depends_on' => [
                'field' => 'blog_featured_image',
                'value' => 1,
            ],
        ],
        'blog_image_ratio' => [
            'name' => 'blog_image_ratio',
            'label' => 'Aspect Ratio',
            'type' => 'select',
            'priority' => 70,
            'options' => cmsEntityContextOptionLabels(['auto', '16:9', '4:3', '1:1']),
            'default' => 'auto',
            'depends_on' => [
                'field' => 'blog_featured_image',
                'value' => 1,
            ],
        ],
        'blog_card_border' => [
            'name' => 'blog_card_border',
            'label' => 'Show Border',
            'type' => 'toggle',
            'priority' => 65,
            'default' => 1,
        ],
        'blog_card_shadow' => [
            'name' => 'blog_card_shadow',
            'label' => 'Hover Shadow',
            'type' => 'toggle',
            'priority' => 64,
            'default' => 1,
        ],
        'blog_show_excerpt' => [
            'name' => 'blog_show_excerpt',
            'label' => 'Show Excerpt',
            'type' => 'toggle',
            'priority' => 63,
            'default' => 1,
        ],
        'blog_show_author' => [
            'name' => 'blog_show_author',
            'label' => 'Show Author',
            'type' => 'toggle',
            'priority' => 62,
            'default' => 1,
        ],
        'blog_show_date' => [
            'name' => 'blog_show_date',
            'label' => 'Show Date',
            'type' => 'toggle',
            'priority' => 61,
            'default' => 1,
        ],
        'blog_show_readmore' => [
            'name' => 'blog_show_readmore',
            'label' => 'Show Read More',
            'type' => 'toggle',
            'priority' => 60,
            'default' => 1,
        ],
        'blog_readmore_text' => [
            'name' => 'blog_readmore_text',
            'label' => 'Read More Text',
            'type' => 'text',
            'priority' => 55,
            'default' => 'Read more →',
            'depends_on' => [
                'field' => 'blog_show_readmore',
                'value' => 1,
            ],
        ],
        'single_max_width' => [
            'name' => 'single_max_width',
            'label' => 'Content Max Width',
            'type' => 'number',
            'priority' => 100,
            'default' => '768',
            'description' => 'Controls canonical single, article, and detail content width.',
            'min' => 480,
            'max' => 1200,
            'step' => 10,
            'unit' => 'px',
        ],
        'single_show_author' => [
            'name' => 'single_show_author',
            'label' => 'Show Author',
            'type' => 'toggle',
            'priority' => 90,
            'default' => 1,
        ],
        'single_show_date' => [
            'name' => 'single_show_date',
            'label' => 'Show Date',
            'type' => 'toggle',
            'priority' => 89,
            'default' => 1,
        ],
        'single_show_categories' => [
            'name' => 'single_show_categories',
            'label' => 'Show Categories',
            'type' => 'toggle',
            'priority' => 88,
            'default' => 1,
        ],
        'single_show_tags' => [
            'name' => 'single_show_tags',
            'label' => 'Show Tags',
            'type' => 'toggle',
            'priority' => 87,
            'default' => 1,
        ],
        'single_show_nav' => [
            'name' => 'single_show_nav',
            'label' => 'Show Back Link',
            'type' => 'toggle',
            'priority' => 86,
            'default' => 1,
        ],
    ];
}

function cmsEntityContextFieldDefinition(string $name, array $overrides = []): array
{
    $catalog = cmsEntityContextFieldCatalog();
    $field = $catalog[$name] ?? [
        'name' => $name,
        'label' => ucwords(str_replace(['_', '-'], ' ', $name)),
        'type' => 'text',
        'priority' => 10,
        'options' => [],
    ];

    return array_replace_recursive($field, $overrides);
}

function cmsEntityContextFields(array $definitions): array
{
    $fields = [];
    foreach ($definitions as $definition) {
        if (is_string($definition) && $definition !== '') {
            $fields[] = cmsEntityContextFieldDefinition($definition);
            continue;
        }

        if (!is_array($definition)) {
            continue;
        }

        $name = trim((string)($definition['name'] ?? ''));
        if ($name === '') {
            continue;
        }

        $fields[] = cmsEntityContextFieldDefinition($name, $definition);
    }

    return $fields;
}

function cmsBuiltinEntityContextCapabilityMetadata(): array
{
    return [
        [
            'id' => 'media_gallery',
            'label' => 'Media Gallery',
            'block' => 'media-gallery.block',
            'priority' => 100,
            'meta' => [
                'runtime_activation' => 'gallery_required',
            ],
            'customizer' => [
                'section' => [
                    'id' => 'media',
                    'label' => 'Media',
                    'priority' => 100,
                    'description' => 'Media-capable contexts reuse the shared hero and gallery controls from the canonical entity contract.',
                ],
                'fields' => [],
            ],
        ],
        [
            'id' => 'progress_tracking',
            'label' => 'Progress Tracking',
            'block' => 'progress.block',
            'priority' => 90,
            'meta' => [
                'runtime_activation' => 'profile_default',
            ],
            'customizer' => [
                'section' => [
                    'id' => 'progress',
                    'label' => 'Progress',
                    'priority' => 90,
                    'description' => 'Progress-aware contexts can surface lightweight completion signals inside canonical list cards.',
                ],
                'fields' => cmsEntityContextFields(['entity_list_progress_variant']),
            ],
        ],
        [
            'id' => 'pricing',
            'label' => 'Pricing',
            'block' => 'pricing.block',
            'priority' => 80,
            'meta' => [
                'runtime_activation' => 'price_required',
            ],
            'customizer' => [
                'section' => [
                    'id' => 'pricing',
                    'label' => 'Pricing',
                    'priority' => 80,
                    'description' => 'Approved pricing variants for canonical detail and list blocks.',
                ],
                'fields' => cmsEntityContextFields(['entity_pricing_variant', 'entity_list_pricing_variant']),
            ],
        ],
        [
            'id' => 'inventory',
            'label' => 'Inventory',
            'block' => 'inventory.block',
            'priority' => 70,
            'meta' => [
                'runtime_activation' => 'inventory_required',
            ],
            'customizer' => [
                'section' => [
                    'id' => 'inventory',
                    'label' => 'Inventory',
                    'priority' => 70,
                    'description' => 'Controls inventory messaging when stock data is available for canonical list cards.',
                ],
                'fields' => cmsEntityContextFields(['entity_list_inventory_variant']),
            ],
        ],
        [
            'id' => 'lessons_index',
            'label' => 'Lessons',
            'block' => 'lessons.block',
            'priority' => 60,
            'meta' => [
                'runtime_activation' => 'items_required',
            ],
            'customizer' => [
                'section' => [
                    'id' => 'lessons',
                    'label' => 'Lessons',
                    'priority' => 60,
                    'description' => 'Guidance contexts inherit the shared canonical list and detail controls without introducing extra presentation forks.',
                ],
                'fields' => [],
            ],
        ],
        [
            'id' => 'booking',
            'label' => 'Booking',
            'block' => 'action.block',
            'priority' => 50,
            'meta' => [
                'runtime_activation' => 'profile_default',
            ],
            'customizer' => [
                'section' => [
                    'id' => 'actions',
                    'label' => 'Actions',
                    'priority' => 50,
                    'description' => 'Configure canonical inquiry and booking actions without forking entity templates.',
                ],
                'fields' => cmsEntityContextFields(['entity_action_variant', 'entity_action_size']),
            ],
        ],
        [
            'id' => 'inquiry',
            'label' => 'Inquiry',
            'block' => 'action.block',
            'priority' => 40,
            'meta' => [
                'runtime_activation' => 'profile_default',
            ],
            'customizer' => [
                'section' => [
                    'id' => 'actions',
                    'label' => 'Actions',
                    'priority' => 50,
                    'description' => 'Configure canonical inquiry and booking actions without forking entity templates.',
                ],
                'fields' => cmsEntityContextFields(['entity_action_variant', 'entity_action_size']),
            ],
        ],
    ];
}

function cmsBuiltinEntityContextDefinitions(): array
{
    return [
        [
            'id' => 'content',
            'label' => 'Content',
            'capabilities' => [],
        ],
        [
            'id' => 'business',
            'label' => 'Business',
            'capabilities' => ['booking', 'inquiry', 'media_gallery'],
        ],
    ];
}

function cmsBuiltinEntityContextBindings(): array
{
    return [
        [
            'entity_type' => 'post',
            'base' => 'content',
            'priority' => 100,
        ],
        [
            'entity_type' => 'page',
            'base' => 'content',
            'priority' => 100,
        ],
        [
            'entity_type' => 'service',
            'base' => 'business',
            'priority' => 100,
        ],
    ];
}

function cmsEntityContextBaseSchemaSections(): array
{
    return [
        [
            'id' => 'general',
            'label' => 'Entity Layout',
            'priority' => 140,
            'description' => 'Shared ordering, spacing, and media defaults for the canonical entity view.',
            'fields' => cmsEntityContextFields([
                'entity_layout_profile',
                'entity_spacing_scale',
                'entity_media_ratio',
                'entity_summary_width',
                'entity_summary_sticky',
            ]),
        ],
        [
            'id' => 'catalog',
            'label' => 'Catalog Cards',
            'priority' => 130,
            'description' => 'Typography, geometry, and excerpt controls for canonical entity lists and storefront grids.',
            'fields' => cmsEntityContextFields([
                'entity_list_card_density',
                'entity_list_show_excerpt',
                'entity_list_excerpt_length',
                'entity_list_category_navigation',
                'entity_list_show_filter_summary',
                'entity_list_title_font',
                'entity_list_text_font',
                'entity_list_title_size',
                'entity_list_price_size',
                'entity_list_card_min_width',
                'entity_list_title_lines',
            ]),
        ],
        [
            'id' => 'archive',
            'label' => 'List / Archive Presentation',
            'priority' => 40,
            'description' => 'Canonical archive and article-list controls routed through entity presentation instead of theme shell settings.',
            'fields' => cmsEntityContextFields([
                'blog_layout',
                'blog_columns',
                'blog_gap',
                'blog_card_radius',
                'blog_image_height',
                'blog_image_ratio',
                'blog_card_border',
                'blog_card_shadow',
                'blog_featured_image',
                'blog_show_excerpt',
                'blog_show_author',
                'blog_show_date',
                'blog_show_readmore',
                'blog_readmore_text',
            ]),
        ],
        [
            'id' => 'detail',
            'label' => 'Detail / Article Presentation',
            'priority' => 30,
            'description' => 'Narrative width and meta visibility for canonical single, article, and detail routes.',
            'fields' => cmsEntityContextFields([
                'single_max_width',
                'single_show_author',
                'single_show_date',
                'single_show_categories',
                'single_show_tags',
                'single_show_nav',
            ]),
        ],
    ];
}

function cmsEnsureBuiltinEntityContextRegistry(): void
{
    static $registered = false;
    if ($registered) {
        return;
    }

    $registry = app()->entityContexts();

    foreach (cmsBuiltinEntityContextDefinitions() as $definition) {
        $registry->registerContext((string)$definition['id'], $definition, 'cms', (int)($definition['priority'] ?? 100));
    }

    foreach (cmsBuiltinEntityContextBindings() as $binding) {
        $registry->bindEntityType((string)$binding['entity_type'], $binding, 'cms', (int)($binding['priority'] ?? 100));
    }

    foreach (cmsBuiltinEntityContextCapabilityMetadata() as $metadata) {
        $registry->registerCapability((string)$metadata['id'], $metadata, 'cms', (int)($metadata['priority'] ?? 100));
    }

    $extraDefinitions = app()->hooks()->filter('cms.entity.contexts.register', []);
    if (is_array($extraDefinitions)) {
        foreach ($extraDefinitions as $definition) {
            if (!is_array($definition) || empty($definition['id'])) {
                continue;
            }
            $registry->registerContext((string)$definition['id'], $definition, 'cms', (int)($definition['priority'] ?? 100));
        }
    }

    $extraBindings = app()->hooks()->filter('cms.entity.context.bindings.register', []);
    if (is_array($extraBindings)) {
        foreach ($extraBindings as $binding) {
            if (!is_array($binding) || empty($binding['entity_type'])) {
                continue;
            }
            $registry->bindEntityType((string)$binding['entity_type'], $binding, 'cms', (int)($binding['priority'] ?? 100));
        }
    }

    $extraCapabilities = app()->hooks()->filter('cms.entity.context.capabilities.register', []);
    if (is_array($extraCapabilities)) {
        foreach ($extraCapabilities as $metadata) {
            if (!is_array($metadata) || empty($metadata['id'])) {
                continue;
            }
            $registry->registerCapability((string)$metadata['id'], $metadata, 'cms', (int)($metadata['priority'] ?? 100));
        }
    }

    $registered = true;
}

function cmsEntityContextRegistrySnapshot(): array
{
    cmsEnsureBuiltinEntityContextRegistry();
    return app()->entityContexts()->export();
}

function cmsResolveEntityContextForType(string $entityType, array $options = []): array
{
    cmsEnsureBuiltinEntityContextRegistry();

    $resolved = app()->entityContexts()->resolve($entityType, $options);
    $resolved['customizer_schema'] = app()->entityContexts()->buildCustomizerSchema(
        $resolved,
        cmsEntityContextBaseSchemaSections()
    );

    return $resolved;
}

function cmsBuildEntityCustomizerSchemaForType(string $entityType, array $options = []): array
{
    $resolved = cmsResolveEntityContextForType($entityType, $options);
    return is_array($resolved['customizer_schema'] ?? null) ? $resolved['customizer_schema'] : [];
}

function cmsEntityContextExampleSchemas(): array
{
    $content = cmsResolveEntityContextForType('page');
    $business = cmsResolveEntityContextForType('service');
    $guidance = cmsResolveEntityContextForType('course', [
        'profile' => ['base' => 'guidance'],
    ]);
    $hybrid = cmsResolveEntityContextForType('course', [
        'profile' => ['base' => 'guidance', 'extensions' => ['commerce']],
    ]);

    return [
        'content' => [
            'id' => 'content',
            'label' => 'Content',
            'description' => 'Editorial pages and posts inside the active shell without commerce-specific additions.',
            'resolved_context' => $content,
            'schema' => $content['customizer_schema'] ?? [],
        ],
        'business' => [
            'id' => 'business',
            'label' => 'Business',
            'description' => 'Service-style entities with inquiry, booking, and shared media controls.',
            'resolved_context' => $business,
            'schema' => $business['customizer_schema'] ?? [],
        ],
        'guidance' => [
            'id' => 'guidance',
            'label' => 'Guidance',
            'description' => 'Course-style entities with lesson sequencing and progress-aware list cards.',
            'resolved_context' => $guidance,
            'schema' => $guidance['customizer_schema'] ?? [],
        ],
        'hybrid' => [
            'id' => 'hybrid',
            'label' => 'Guidance + Commerce',
            'description' => 'A hybrid entity profile that layers commerce pricing and inventory over guidance content.',
            'resolved_context' => $hybrid,
            'schema' => $hybrid['customizer_schema'] ?? [],
        ],
    ];
}
