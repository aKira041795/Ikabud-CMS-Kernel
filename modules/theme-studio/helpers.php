<?php

declare(strict_types=1);

/**
 * Theme Studio — Helpers
 *
 * Core business logic for token editing, preset management, and Theme Elements.
 * Auto-loaded when the module is enabled.
 */

use Ikabud\Kernel\Services\SlotRegistry;
use Ikabud\Kernel\DiSyL\ComponentRegistry;

// ── Capability Handlers ──────────────────────────────────────────

/**
 * Capability handler map — auto-discovered by the module manager.
 * Maps capability IDs to callables registered with the CapabilityBus.
 */
function theme_studio_capability_handlers(): array
{
    return [
        'theme.token.apply@1' => 'theme_studio_cap_apply_tokens_1',
    ];
}

/**
 * Apply token overrides to a rendering context.
 * Called by the CapabilityBus when theme.token.apply@1 is invoked.
 * Merges flat token overrides into the context for CSS custom property generation.
 */
function theme_studio_cap_apply_tokens_1(mixed $payload, string $capId = '', string $providerId = ''): array
{
    $tokens = is_array($payload) ? $payload : [];
    $tenantId = (int)($tokens['tenant_id'] ?? 0);
    $themeSlug = trim((string)($tokens['theme_slug'] ?? ''));

    if ($tenantId <= 0 || $themeSlug === '') {
        return ['ok' => false, 'error' => 'tenant_id and theme_slug required'];
    }

    $overrides = themeStudioTokenOverrides($tenantId, $themeSlug);

    // Check active preset for additional tokens
    $settings = getModuleSettings('theme-studio');
    $activePreset = trim((string)($settings['active_preset'] ?? ''));
    $presetTokens = [];
    if ($activePreset !== '') {
        $presets = themeStudioPresets();
        if (isset($presets[$activePreset]['data']['tokens'])) {
            $presetTokens = $presets[$activePreset]['data']['tokens'];
        }
    }

    return [
        'ok' => true,
        'tokens' => $overrides,
        'preset_tokens' => $presetTokens,
        'preset_slug' => $activePreset,
    ];
}

// ── Preset Management ────────────────────────────────────────────

/**
 * Get all built-in presets shipped with the module.
 * A preset changes tokens, layout defaults, and component variants — never business data.
 */
function themeStudioBuiltinPresets(): array
{
    return [
        'foundation' => [
            'label' => 'Foundation',
            'description' => 'Neutral default — works for any project type',
            'source' => 'builtin',
            'surface' => 'public',
            'data' => [
                'tokens' => [
                    'color.primary' => '#2563eb',
                    'color.surface' => '#ffffff',
                    'color.text' => '#0f172a',
                    'color.text_muted' => '#64748b',
                    'color.border' => '#e2e8f0',
                    'typography.font_family' => 'Inter, system-ui, sans-serif',
                    'typography.body_size' => '16px',
                    'spacing.md' => '1.25rem',
                    'radius.md' => '0.75rem',
                ],
                'layout' => [
                    'max_width' => '1200px',
                    'header_height' => '64px',
                    'sidebar_width' => '300px',
                ],
            ],
        ],
        'corporate' => [
            'label' => 'Corporate',
            'description' => 'Professional service/company sites — clean, trustworthy',
            'source' => 'builtin',
            'surface' => 'public',
            'data' => [
                'tokens' => [
                    'color.primary' => '#1d4ed8',
                    'color.surface' => '#ffffff',
                    'color.text' => '#172033',
                    'color.text_muted' => '#667085',
                    'color.border' => '#d0d5dd',
                    'typography.font_family' => 'Inter, system-ui, sans-serif',
                    'typography.body_size' => '16px',
                    'spacing.md' => '1.5rem',
                    'radius.md' => '0.5rem',
                ],
                'layout' => [
                    'max_width' => '1280px',
                    'header_height' => '72px',
                ],
            ],
        ],
        'school' => [
            'label' => 'School',
            'description' => 'Education and institutional sites',
            'source' => 'builtin',
            'surface' => 'public',
            'data' => [
                'tokens' => [
                    'color.primary' => '#059669',
                    'color.surface' => '#ffffff',
                    'color.text' => '#0f172a',
                    'color.text_muted' => '#475569',
                    'color.border' => '#cbd5e1',
                    'typography.font_family' => "'Open Sans', system-ui, sans-serif",
                    'typography.body_size' => '16px',
                    'spacing.md' => '1.25rem',
                    'radius.md' => '0.375rem',
                ],
                'layout' => [
                    'max_width' => '1200px',
                    'header_height' => '64px',
                ],
            ],
        ],
        'store' => [
            'label' => 'Store',
            'description' => 'Ecommerce storefront — product-first layout',
            'source' => 'builtin',
            'surface' => 'public',
            'data' => [
                'tokens' => [
                    'color.primary' => '#dc2626',
                    'color.surface' => '#ffffff',
                    'color.text' => '#111827',
                    'color.text_muted' => '#6b7280',
                    'color.border' => '#e5e7eb',
                    'typography.font_family' => 'Inter, system-ui, sans-serif',
                    'typography.body_size' => '16px',
                    'spacing.md' => '1rem',
                    'radius.md' => '0.375rem',
                ],
                'layout' => [
                    'max_width' => '1400px',
                    'header_height' => '64px',
                    'sidebar_width' => '280px',
                ],
            ],
        ],
        'editorial' => [
            'label' => 'Editorial',
            'description' => 'Blog, news, publishing — content-first reading experience',
            'source' => 'builtin',
            'surface' => 'public',
            'data' => [
                'tokens' => [
                    'color.primary' => '#7c3aed',
                    'color.surface' => '#ffffff',
                    'color.text' => '#1a1a2e',
                    'color.text_muted' => '#6b7280',
                    'color.border' => '#e2e8f0',
                    'typography.font_family' => "'Merriweather', Georgia, serif",
                    'typography.body_size' => '18px',
                    'spacing.md' => '1.5rem',
                    'radius.md' => '0.5rem',
                ],
                'layout' => [
                    'max_width' => '720px',
                    'header_height' => '56px',
                ],
            ],
        ],
    ];
}

/**
 * Get all registered presets (built-in + saved from DB).
 */
function themeStudioPresets(): array
{
    $presets = themeStudioBuiltinPresets();

    // Load saved presets from DB
    try {
        $db = module()->db();
        $stmt = $db->query(
            "SELECT slug, label, description, preset_data, source, surface, created_at
             FROM theme_studio_presets
             ORDER BY source ASC, label ASC"
        );
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (!isset($presets[$row['slug']])) {
                $data = json_decode((string)($row['preset_data'] ?? '{}'), true);
                $presets[$row['slug']] = [
                    'label' => $row['label'],
                    'description' => $row['description'] ?? '',
                    'source' => $row['source'] ?? 'custom',
                    'surface' => $row['surface'] ?? 'public',
                    'data' => is_array($data) ? $data : [],
                ];
            }
        }
    } catch (Throwable $e) {
        write_log('themeStudioPresets: failed to load from DB: ' . $e->getMessage(), 'warning');
    }

    return $presets;
}

/**
 * Save a custom preset to the database.
 */
function themeStudioSavePreset(string $slug, string $label, string $description, array $data, string $source = 'custom', string $surface = 'public'): bool
{
    try {
        $db = module()->db();
        $stmt = $db->prepare(
            "INSERT INTO theme_studio_presets (slug, label, description, preset_data, source, surface)
             VALUES (:slug, :label, :description, :data, :source, :surface)
             ON DUPLICATE KEY UPDATE
             label = VALUES(label),
             description = VALUES(description),
             preset_data = VALUES(preset_data),
             source = VALUES(source),
             surface = VALUES(surface)"
        );
        $stmt->execute([
            ':slug' => $slug,
            ':label' => $label,
            ':description' => $description,
            ':data' => json_encode($data),
            ':source' => $source,
            ':surface' => $surface,
        ]);
        return true;
    } catch (Throwable $e) {
        write_log('themeStudioSavePreset: failed: ' . $e->getMessage(), 'error');
        return false;
    }
}

/**
 * Delete a custom preset from the database.
 */
function themeStudioDeletePreset(string $slug): bool
{
    try {
        $db = module()->db();
        $stmt = $db->prepare("DELETE FROM theme_studio_presets WHERE slug = :slug AND source = 'custom'");
        $stmt->execute([':slug' => $slug]);
        return $stmt->rowCount() > 0;
    } catch (Throwable $e) {
        write_log('themeStudioDeletePreset: failed: ' . $e->getMessage(), 'error');
        return false;
    }
}

/**
 * Apply a preset — sets the active preset in module settings.
 */
function themeStudioApplyPreset(string $slug): bool
{
    $presets = themeStudioPresets();
    if (!isset($presets[$slug])) {
        return false;
    }
    saveModuleSettings('theme-studio', ['active_preset' => $slug]);
    return true;
}

// ── Token Overrides ──────────────────────────────────────────────

/**
 * Get token overrides for a tenant + theme combination.
 */
function themeStudioTokenOverrides(int $tenantId, string $themeSlug): array
{
    try {
        $db = module()->db();
        $stmt = $db->prepare(
            "SELECT token_key, token_value FROM theme_studio_token_overrides
             WHERE tenant_id = :tid AND theme_slug = :theme
             ORDER BY token_key ASC"
        );
        $stmt->execute([':tid' => $tenantId, ':theme' => $themeSlug]);
        $overrides = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $overrides[$row['token_key']] = $row['token_value'];
        }
        return $overrides;
    } catch (Throwable $e) {
        write_log('themeStudioTokenOverrides: failed: ' . $e->getMessage(), 'warning');
        return [];
    }
}

/**
 * Save token overrides for a tenant + theme combination.
 */
function themeStudioSaveTokenOverrides(int $tenantId, string $themeSlug, array $tokens): bool
{
    try {
        $db = module()->db();
        $db->beginTransaction();

        // Clear existing overrides for this tenant + theme
        $stmt = $db->prepare(
            "DELETE FROM theme_studio_token_overrides WHERE tenant_id = :tid AND theme_slug = :theme"
        );
        $stmt->execute([':tid' => $tenantId, ':theme' => $themeSlug]);

        // Insert new overrides
        $insert = $db->prepare(
            "INSERT INTO theme_studio_token_overrides (tenant_id, theme_slug, token_key, token_value)
             VALUES (:tid, :theme, :key, :val)"
        );
        foreach ($tokens as $key => $value) {
            $insert->execute([
                ':tid' => $tenantId,
                ':theme' => $themeSlug,
                ':key' => $key,
                ':val' => $value,
            ]);
        }

        $db->commit();
        return true;
    } catch (Throwable $e) {
        if ($db->inTransaction()) { $db->rollBack(); }
        write_log('themeStudioSaveTokenOverrides: failed: ' . $e->getMessage(), 'error');
        return false;
    }
}

/**
 * Reset all token overrides for a tenant + theme combination.
 */
function themeStudioResetTokenOverrides(int $tenantId, string $themeSlug): bool
{
    try {
        $db = module()->db();
        $stmt = $db->prepare(
            "DELETE FROM theme_studio_token_overrides WHERE tenant_id = :tid AND theme_slug = :theme"
        );
        $stmt->execute([':tid' => $tenantId, ':theme' => $themeSlug]);
        return true;
    } catch (Throwable $e) {
        write_log('themeStudioResetTokenOverrides: failed: ' . $e->getMessage(), 'error');
        return false;
    }
}

// ── Theme Elements ───────────────────────────────────────────────

/**
 * Get all registered Theme Elements.
 */
function themeStudioElements(): array
{
    try {
        $db = module()->db();
        $stmt = $db->query(
            "SELECT id, slug, label, element_type, slot_name, component, component_attrs,
                    display_conditions, priority, is_active, created_at, updated_at
             FROM theme_studio_elements
             ORDER BY priority ASC, label ASC"
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        write_log('themeStudioElements: failed: ' . $e->getMessage(), 'warning');
        return [];
    }
}

/**
 * Save a Theme Element.
 */
function themeStudioSaveElement(array $data): bool
{
    try {
        $db = module()->db();
        $slug = $data['slug'] ?? '';
        $label = $data['label'] ?? $slug;
        $elementType = $data['element_type'] ?? 'hook';
        $slotName = $data['slot_name'] ?? null;
        $component = $data['component'] ?? 'ikb_panel';
        $componentAttrs = isset($data['component_attrs']) ? json_encode($data['component_attrs']) : null;
        $conditions = isset($data['display_conditions']) ? json_encode($data['display_conditions']) : null;
        $priority = (int)($data['priority'] ?? 10);
        $isActive = !empty($data['is_active']) ? 1 : 0;

        $stmt = $db->prepare(
            "INSERT INTO theme_studio_elements (slug, label, element_type, slot_name, component, component_attrs, display_conditions, priority, is_active)
             VALUES (:slug, :label, :type, :slot, :component, :attrs, :conditions, :priority, :active)
             ON DUPLICATE KEY UPDATE
             label = VALUES(label),
             element_type = VALUES(element_type),
             slot_name = VALUES(slot_name),
             component = VALUES(component),
             component_attrs = VALUES(component_attrs),
             display_conditions = VALUES(display_conditions),
             priority = VALUES(priority),
             is_active = VALUES(is_active)"
        );
        $stmt->execute([
            ':slug' => $slug,
            ':label' => $label,
            ':type' => $elementType,
            ':slot' => $slotName,
            ':component' => $component,
            ':attrs' => $componentAttrs,
            ':conditions' => $conditions,
            ':priority' => $priority,
            ':active' => $isActive,
        ]);
        return true;
    } catch (Throwable $e) {
        write_log('themeStudioSaveElement: failed: ' . $e->getMessage(), 'error');
        return false;
    }
}

/**
 * Delete a Theme Element.
 */
function themeStudioDeleteElement(string $slug): bool
{
    try {
        $db = module()->db();
        $stmt = $db->prepare("DELETE FROM theme_studio_elements WHERE slug = :slug");
        $stmt->execute([':slug' => $slug]);
        return $stmt->rowCount() > 0;
    } catch (Throwable $e) {
        write_log('themeStudioDeleteElement: failed: ' . $e->getMessage(), 'error');
        return false;
    }
}

/**
 * Register active Theme Elements as SlotRegistry contributions.
 * Called during module bootstrap to wire saved elements into governed slots.
 */
function themeStudioRegisterElementContributions(): void
{
    try {
        $elements = themeStudioElements();
        foreach ($elements as $el) {
            if (empty($el['is_active']) || empty($el['slot_name'])) {
                continue;
            }

            $attrs = [];
            if (!empty($el['component_attrs'])) {
                $decoded = json_decode((string)$el['component_attrs'], true);
                $attrs = is_array($decoded) ? $decoded : [];
            }

            $conditions = [];
            if (!empty($el['display_conditions'])) {
                $decoded = json_decode((string)$el['display_conditions'], true);
                $conditions = is_array($decoded) ? $decoded : [];
            }

            SlotRegistry::register((string)$el['slot_name'], [
                'id' => 'theme-studio:' . $el['slug'],
                'component' => (string)($el['component'] ?? 'ikb_panel'),
                'attrs' => $attrs,
                'priority' => (int)($el['priority'] ?? 10),
                'conditions' => $conditions,
            ]);
        }
    } catch (Throwable $e) {
        write_log('themeStudioRegisterElementContributions: failed: ' . $e->getMessage(), 'warning');
    }
}

// ── Admin Helpers ────────────────────────────────────────────────

function themeStudioReadThemeJson(string $themeSlug, string $fileName): array
{
    $themeSlug = trim($themeSlug);
    if ($themeSlug === '' || !function_exists('cmsThemeExists') || !cmsThemeExists($themeSlug)) {
        return [];
    }

    $path = cmsThemesPath() . '/' . $themeSlug . '/' . ltrim($fileName, '/');
    if (!is_file($path)) {
        return [];
    }

    $decoded = kernelReadJsonFile($path);
    return is_array($decoded) ? $decoded : [];
}

function themeStudioResolveThemeJsonPath(string $themeSlug, string $fileName): ?string
{
    $themeSlug = trim($themeSlug);
    if ($themeSlug === '' || !function_exists('cmsThemeExists') || !cmsThemeExists($themeSlug)) {
        return null;
    }

    return cmsThemesPath() . '/' . $themeSlug . '/' . ltrim($fileName, '/');
}

function themeStudioEditableContractMap(): array
{
    return [
        'renderer-registry' => [
            'label' => 'Renderer Registry',
            'file' => 'renderer-registry.json',
            'description' => 'Maps ARK block types to governed renderer templates or components.',
        ],
        'block-registry' => [
            'label' => 'Block Registry',
            'file' => 'block-registry.json',
            'description' => 'Declares the category-level ARK block inventory for builder clients.',
        ],
        'entity-view-map' => [
            'label' => 'Entity View Map',
            'file' => 'entity-view-map.json',
            'description' => 'Defines ARK capability-bridge presentation contracts across modules.',
        ],
        'page-composition-schema' => [
            'label' => 'Page Composition Schema',
            'file' => 'page-composition.schema.json',
            'description' => 'Defines the ARK builder document envelope and node contract future page builders must serialize.',
        ],
        'safety-policy' => [
            'label' => 'Safety Policy',
            'file' => 'safety-policy.json',
            'description' => 'Defines what theme authors may render, bridge, or mark as raw output.',
        ],
    ];
}

function themeStudioEditableContractDetail(?string $themeSlug, string $contractKey): array
{
    $themeSlug = trim((string)($themeSlug ?? (function_exists('cmsActiveTheme') ? cmsActiveTheme() : '')));
    $contracts = themeStudioEditableContractMap();
    $meta = $contracts[$contractKey] ?? null;
    if (!is_array($meta)) {
        return [
            'theme_slug' => $themeSlug,
            'key' => $contractKey,
            'registered' => false,
            'label' => $contractKey,
            'file' => null,
            'path' => null,
            'description' => '',
            'exists' => false,
            'data' => [],
            'json' => '{}',
        ];
    }

    $file = (string)$meta['file'];
    $data = themeStudioReadThemeJson($themeSlug, $file);
    $path = themeStudioResolveThemeJsonPath($themeSlug, $file);

    return [
        'theme_slug' => $themeSlug,
        'key' => $contractKey,
        'registered' => true,
        'label' => (string)$meta['label'],
        'file' => $file,
        'path' => $path,
        'description' => (string)($meta['description'] ?? ''),
        'exists' => $data !== [],
        'data' => $data,
        'form' => themeStudioEditableContractFormModel($contractKey, $data),
        'json' => $data !== []
            ? (string)json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : '{}',
    ];
}

function themeStudioEditableContractFormModel(string $contractKey, array $data): array
{
    if ($contractKey === 'renderer-registry') {
        return themeStudioRendererRegistryFormModel($data);
    }

    if ($contractKey === 'entity-view-map') {
        return themeStudioEntityViewMapFormModel($data);
    }

    if ($contractKey === 'block-registry') {
        return themeStudioBlockRegistryFormModel($data);
    }

    if ($contractKey === 'page-composition-schema') {
        return themeStudioPageCompositionSchemaFormModel($data);
    }

    if ($contractKey === 'safety-policy') {
        return themeStudioSafetyPolicyFormModel($data);
    }

    return [];
}

function themeStudioRendererRegistryFormModel(array $data): array
{
    $renderers = is_array($data['renderers'] ?? null) ? $data['renderers'] : [];
    $rows = [];
    foreach ($renderers as $rendererName => $definition) {
        if (!is_array($definition)) {
            continue;
        }
        $extra = $definition;
        unset($extra['template'], $extra['renders_as_component'], $extra['controls'], $extra['context_keys']);

        $rows[] = [
            'name' => (string)$rendererName,
            'template' => (string)($definition['template'] ?? ''),
            'renders_as_component' => (string)($definition['renders_as_component'] ?? ''),
            'controls_text' => implode("\n", array_map('strval', is_array($definition['controls'] ?? null) ? $definition['controls'] : [])),
            'context_keys_text' => implode("\n", array_map('strval', is_array($definition['context_keys'] ?? null) ? $definition['context_keys'] : [])),
            'extra_json' => $extra !== []
                ? (string)json_encode($extra, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                : '{}',
        ];
    }

    if ($rows === []) {
        $rows[] = [
            'name' => '',
            'template' => '',
            'renders_as_component' => '',
            'controls_text' => '',
            'context_keys_text' => '',
            'extra_json' => '{}',
        ];
    }

    return [
        'version' => (string)($data['version'] ?? '2.0.0'),
        'description' => (string)($data['description'] ?? ''),
        'renderer_rows' => $rows,
        'renderer_rows_json' => (string)json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ];
}

function themeStudioEntityViewMapFormModel(array $data): array
{
    $entityViews = is_array($data['entity_views'] ?? null) ? $data['entity_views'] : [];
    $rows = [];
    foreach ($entityViews as $entityType => $views) {
        if (!is_array($views)) {
            continue;
        }
        foreach ($views as $viewName => $definition) {
            if (!is_array($definition)) {
                continue;
            }
            $extra = $definition;
            unset($extra['fields'], $extra['actions'], $extra['block']);
            $rows[] = [
                'entity_type' => (string)$entityType,
                'view_name' => (string)$viewName,
                'fields_text' => implode("\n", array_map('strval', is_array($definition['fields'] ?? null) ? $definition['fields'] : [])),
                'actions_text' => implode("\n", array_map('strval', is_array($definition['actions'] ?? null) ? $definition['actions'] : [])),
                'block' => (string)($definition['block'] ?? ''),
                'extra_json' => $extra !== []
                    ? (string)json_encode($extra, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                    : '{}',
            ];
        }
    }

    if ($rows === []) {
        $rows[] = [
            'entity_type' => '',
            'view_name' => '',
            'fields_text' => '',
            'actions_text' => '',
            'block' => '',
            'extra_json' => '{}',
        ];
    }

    return [
        'version' => (string)($data['version'] ?? '2.0.0'),
        'description' => (string)($data['description'] ?? ''),
        'entity_view_rows' => $rows,
        'entity_view_rows_json' => (string)json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ];
}

function themeStudioBlockRegistryFormModel(array $data): array
{
    $categories = is_array($data['categories'] ?? null) ? $data['categories'] : [];
    $rows = [];
    foreach ($categories as $categoryName => $blockTypes) {
        if (!is_array($blockTypes)) {
            continue;
        }
        $rows[] = [
            'category_name' => (string)$categoryName,
            'block_types_text' => implode("\n", array_map('strval', $blockTypes)),
        ];
    }

    if ($rows === []) {
        $rows[] = [
            'category_name' => '',
            'block_types_text' => '',
        ];
    }

    $extra = $data;
    unset($extra['version'], $extra['description'], $extra['categories']);

    return [
        'version' => (string)($data['version'] ?? '2.0.0'),
        'description' => (string)($data['description'] ?? ''),
        'block_registry_rows' => $rows,
        'block_registry_rows_json' => (string)json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'extra_registry_json' => $extra !== []
            ? (string)json_encode($extra, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : '{}',
    ];
}

function themeStudioPageCompositionSchemaFormModel(array $data): array
{
    $documentEnvelope = is_array($data['document_envelope'] ?? null) ? $data['document_envelope'] : [];
    $rootNode = is_array($data['root_node'] ?? null) ? $data['root_node'] : [];
    $nodeContract = is_array($data['node_contract'] ?? null) ? $data['node_contract'] : [];
    $compatibility = is_array($data['compatibility'] ?? null) ? $data['compatibility'] : [];

    $topExtra = $data;
    unset($topExtra['version'], $topExtra['description'], $topExtra['document_envelope'], $topExtra['root_node'], $topExtra['allowed_top_level_children'], $topExtra['node_contract'], $topExtra['compatibility']);

    $documentEnvelopeExtra = $documentEnvelope;
    unset($documentEnvelopeExtra['required_keys'], $documentEnvelopeExtra['schema_version_default']);

    $rootNodeExtra = $rootNode;
    unset($rootNodeExtra['type'], $rootNodeExtra['required_keys'], $rootNodeExtra['children_key']);

    $nodeContractExtra = $nodeContract;
    unset($nodeContractExtra['required_keys'], $nodeContractExtra['props_must_be_object'], $nodeContractExtra['style_must_be_object'], $nodeContractExtra['children_must_be_array'], $nodeContractExtra['meta_must_be_object']);

    $compatibilityExtra = $compatibility;
    unset($compatibilityExtra['cms_builder_schema_version'], $compatibilityExtra['normalizer'], $compatibilityExtra['default_document_factory']);

    return [
        'version' => (string)($data['version'] ?? '1.0.0'),
        'description' => (string)($data['description'] ?? ''),
        'envelope_required_keys_text' => implode("\n", array_map('strval', is_array($documentEnvelope['required_keys'] ?? null) ? $documentEnvelope['required_keys'] : [])),
        'envelope_schema_version_default' => (string)($documentEnvelope['schema_version_default'] ?? ''),
        'envelope_extra_json' => $documentEnvelopeExtra !== []
            ? (string)json_encode($documentEnvelopeExtra, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : '{}',
        'root_type' => (string)($rootNode['type'] ?? 'document'),
        'root_required_keys_text' => implode("\n", array_map('strval', is_array($rootNode['required_keys'] ?? null) ? $rootNode['required_keys'] : [])),
        'root_children_key' => (string)($rootNode['children_key'] ?? 'children'),
        'root_extra_json' => $rootNodeExtra !== []
            ? (string)json_encode($rootNodeExtra, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : '{}',
        'allowed_top_level_children_text' => implode("\n", array_map('strval', is_array($data['allowed_top_level_children'] ?? null) ? $data['allowed_top_level_children'] : [])),
        'node_required_keys_text' => implode("\n", array_map('strval', is_array($nodeContract['required_keys'] ?? null) ? $nodeContract['required_keys'] : [])),
        'props_must_be_object' => !empty($nodeContract['props_must_be_object']),
        'style_must_be_object' => !empty($nodeContract['style_must_be_object']),
        'children_must_be_array' => !empty($nodeContract['children_must_be_array']),
        'meta_must_be_object' => !empty($nodeContract['meta_must_be_object']),
        'node_contract_extra_json' => $nodeContractExtra !== []
            ? (string)json_encode($nodeContractExtra, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : '{}',
        'cms_builder_schema_version' => (string)($compatibility['cms_builder_schema_version'] ?? ''),
        'normalizer' => (string)($compatibility['normalizer'] ?? ''),
        'default_document_factory' => (string)($compatibility['default_document_factory'] ?? ''),
        'compatibility_extra_json' => $compatibilityExtra !== []
            ? (string)json_encode($compatibilityExtra, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : '{}',
        'extra_schema_json' => $topExtra !== []
            ? (string)json_encode($topExtra, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : '{}',
    ];
}

function themeStudioSafetyPolicyFormModel(array $data): array
{
    $policy = is_array($data['policy'] ?? null) ? $data['policy'] : [];
    $rawOutput = is_array($policy['raw_output'] ?? null) ? $policy['raw_output'] : [];
    $extra = $policy;
    unset($extra['raw_output'], $extra['allowed_context_sources'], $extra['blocked_patterns'], $extra['allowed_js_bridges'], $extra['csp_note']);

    return [
        'version' => (string)($data['version'] ?? '1.0.0'),
        'allowed_raw_keys_text' => implode("\n", array_map('strval', is_array($rawOutput['allowed_keys'] ?? null) ? $rawOutput['allowed_keys'] : [])),
        'requires_capability_text' => implode("\n", array_map('strval', is_array($rawOutput['requires_capability'] ?? null) ? $rawOutput['requires_capability'] : [])),
        'raw_output_note' => (string)($rawOutput['note'] ?? ''),
        'allowed_context_sources_text' => implode("\n", array_map('strval', is_array($policy['allowed_context_sources'] ?? null) ? $policy['allowed_context_sources'] : [])),
        'blocked_patterns_text' => implode("\n", array_map('strval', is_array($policy['blocked_patterns'] ?? null) ? $policy['blocked_patterns'] : [])),
        'allowed_js_bridges_text' => implode("\n", array_map('strval', is_array($policy['allowed_js_bridges'] ?? null) ? $policy['allowed_js_bridges'] : [])),
        'csp_note' => (string)($policy['csp_note'] ?? ''),
        'extra_policy_json' => $extra !== []
            ? (string)json_encode($extra, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : '{}',
    ];
}

function themeStudioSaveEditableContract(string $themeSlug, string $contractKey, string $json, ?string &$error = null): bool
{
    $detail = themeStudioEditableContractDetail($themeSlug, $contractKey);
    if (empty($detail['registered'])) {
        $error = 'Unknown contract key.';
        return false;
    }

    $json = trim($json);
    if ($json === '') {
        $error = 'Contract JSON is required.';
        return false;
    }

    try {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        $error = 'Invalid JSON: ' . $e->getMessage();
        return false;
    }

    if (!is_array($decoded)) {
        $error = 'Contract JSON must decode to an object.';
        return false;
    }

    return themeStudioWriteEditableContractArray($themeSlug, $contractKey, $decoded, $error);
}

function themeStudioSaveStructuredRendererRegistry(string $themeSlug, array $input, ?string &$error = null): bool
{
    $detail = themeStudioEditableContractDetail($themeSlug, 'renderer-registry');
    if (empty($detail['registered'])) {
        $error = 'Unknown contract key.';
        return false;
    }

    $version = trim((string)($input['version'] ?? '2.0.0'));
    $description = trim((string)($input['description'] ?? ''));
    if ($version === '') {
        $error = 'Renderer registry version is required.';
        return false;
    }

    $existing = is_array($detail['data'] ?? null) ? $detail['data'] : [];
    $registry = $existing;
    $registry['version'] = $version;
    $registry['description'] = $description;
    $registry['renderers'] = themeStudioStructuredRendererRowsFromInput($input, $error);
    if ($registry['renderers'] === null) {
        return false;
    }

    return themeStudioWriteEditableContractArray($themeSlug, 'renderer-registry', $registry, $error);
}

function themeStudioSaveStructuredEntityViewMap(string $themeSlug, array $input, ?string &$error = null): bool
{
    $detail = themeStudioEditableContractDetail($themeSlug, 'entity-view-map');
    if (empty($detail['registered'])) {
        $error = 'Unknown contract key.';
        return false;
    }

    $version = trim((string)($input['version'] ?? '2.0.0'));
    $description = trim((string)($input['description'] ?? ''));
    if ($version === '') {
        $error = 'Entity view map version is required.';
        return false;
    }

    $existing = is_array($detail['data'] ?? null) ? $detail['data'] : [];
    $map = $existing;
    $map['version'] = $version;
    $map['description'] = $description;
    $map['entity_views'] = themeStudioStructuredEntityViewRowsFromInput($input, $error);
    if ($map['entity_views'] === null) {
        return false;
    }

    return themeStudioWriteEditableContractArray($themeSlug, 'entity-view-map', $map, $error);
}

function themeStudioSaveStructuredBlockRegistry(string $themeSlug, array $input, ?string &$error = null): bool
{
    $detail = themeStudioEditableContractDetail($themeSlug, 'block-registry');
    if (empty($detail['registered'])) {
        $error = 'Unknown contract key.';
        return false;
    }

    $version = trim((string)($input['version'] ?? '2.0.0'));
    $description = trim((string)($input['description'] ?? ''));
    if ($version === '') {
        $error = 'Block registry version is required.';
        return false;
    }

    $extraJson = trim((string)($input['extra_registry_json'] ?? '{}'));
    try {
        $extra = json_decode($extraJson === '' ? '{}' : $extraJson, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        $error = 'Block registry extra JSON is invalid: ' . $e->getMessage();
        return false;
    }
    if (!is_array($extra)) {
        $error = 'Block registry extra JSON must decode to an object.';
        return false;
    }

    $registry = $extra;
    $registry['version'] = $version;
    $registry['description'] = $description;
    $registry['categories'] = themeStudioStructuredBlockRegistryRowsFromInput($input, $error);
    if ($registry['categories'] === null) {
        return false;
    }

    return themeStudioWriteEditableContractArray($themeSlug, 'block-registry', $registry, $error);
}

function themeStudioSaveStructuredPageCompositionSchema(string $themeSlug, array $input, ?string &$error = null): bool
{
    $detail = themeStudioEditableContractDetail($themeSlug, 'page-composition-schema');
    if (empty($detail['registered'])) {
        $error = 'Unknown contract key.';
        return false;
    }

    $version = trim((string)($input['version'] ?? '1.0.0'));
    $description = trim((string)($input['description'] ?? ''));
    if ($version === '') {
        $error = 'Page composition schema version is required.';
        return false;
    }

    $topLevelExtra = themeStudioDecodeStructuredContractObject($input['extra_schema_json'] ?? '{}', 'Page composition schema extra JSON', $error);
    if ($topLevelExtra === null) {
        return false;
    }
    $documentEnvelopeExtra = themeStudioDecodeStructuredContractObject($input['envelope_extra_json'] ?? '{}', 'Document envelope extra JSON', $error);
    if ($documentEnvelopeExtra === null) {
        return false;
    }
    $rootNodeExtra = themeStudioDecodeStructuredContractObject($input['root_extra_json'] ?? '{}', 'Root node extra JSON', $error);
    if ($rootNodeExtra === null) {
        return false;
    }
    $nodeContractExtra = themeStudioDecodeStructuredContractObject($input['node_contract_extra_json'] ?? '{}', 'Node contract extra JSON', $error);
    if ($nodeContractExtra === null) {
        return false;
    }
    $compatibilityExtra = themeStudioDecodeStructuredContractObject($input['compatibility_extra_json'] ?? '{}', 'Compatibility extra JSON', $error);
    if ($compatibilityExtra === null) {
        return false;
    }

    $documentEnvelope = $documentEnvelopeExtra;
    $documentEnvelope['required_keys'] = themeStudioNormalizeStringList($input['envelope_required_keys_text'] ?? '');
    $documentEnvelope['schema_version_default'] = trim((string)($input['envelope_schema_version_default'] ?? ''));

    $rootNode = $rootNodeExtra;
    $rootNode['type'] = trim((string)($input['root_type'] ?? 'document'));
    $rootNode['required_keys'] = themeStudioNormalizeStringList($input['root_required_keys_text'] ?? '');
    $rootNode['children_key'] = trim((string)($input['root_children_key'] ?? 'children'));

    $nodeContract = $nodeContractExtra;
    $nodeContract['required_keys'] = themeStudioNormalizeStringList($input['node_required_keys_text'] ?? '');
    $nodeContract['props_must_be_object'] = themeStudioInputBoolean($input['props_must_be_object'] ?? '0');
    $nodeContract['style_must_be_object'] = themeStudioInputBoolean($input['style_must_be_object'] ?? '0');
    $nodeContract['children_must_be_array'] = themeStudioInputBoolean($input['children_must_be_array'] ?? '0');
    $nodeContract['meta_must_be_object'] = themeStudioInputBoolean($input['meta_must_be_object'] ?? '0');

    $compatibility = $compatibilityExtra;
    $compatibility['cms_builder_schema_version'] = trim((string)($input['cms_builder_schema_version'] ?? ''));
    $compatibility['normalizer'] = trim((string)($input['normalizer'] ?? ''));
    $compatibility['default_document_factory'] = trim((string)($input['default_document_factory'] ?? ''));

    $document = $topLevelExtra;
    $document['version'] = $version;
    $document['description'] = $description;
    $document['document_envelope'] = $documentEnvelope;
    $document['root_node'] = $rootNode;
    $document['allowed_top_level_children'] = themeStudioNormalizeStringList($input['allowed_top_level_children_text'] ?? '');
    $document['node_contract'] = $nodeContract;
    $document['compatibility'] = $compatibility;

    return themeStudioWriteEditableContractArray($themeSlug, 'page-composition-schema', $document, $error);
}

function themeStudioSaveStructuredSafetyPolicy(string $themeSlug, array $input, ?string &$error = null): bool
{
    $detail = themeStudioEditableContractDetail($themeSlug, 'safety-policy');
    if (empty($detail['registered'])) {
        $error = 'Unknown contract key.';
        return false;
    }

    $version = trim((string)($input['version'] ?? '1.0.0'));
    if ($version === '') {
        $error = 'Safety policy version is required.';
        return false;
    }

    $extraJson = trim((string)($input['extra_policy_json'] ?? '{}'));
    try {
        $extra = json_decode($extraJson === '' ? '{}' : $extraJson, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        $error = 'Safety policy extra JSON is invalid: ' . $e->getMessage();
        return false;
    }
    if (!is_array($extra)) {
        $error = 'Safety policy extra JSON must decode to an object.';
        return false;
    }

    $policy = $extra;
    $policy['raw_output'] = [
        'allowed_keys' => themeStudioNormalizeStringList($input['allowed_raw_keys_text'] ?? ''),
        'requires_capability' => themeStudioNormalizeStringList($input['requires_capability_text'] ?? ''),
        'note' => trim((string)($input['raw_output_note'] ?? '')),
    ];
    $policy['allowed_context_sources'] = themeStudioNormalizeStringList($input['allowed_context_sources_text'] ?? '');
    $policy['blocked_patterns'] = themeStudioNormalizeStringList($input['blocked_patterns_text'] ?? '');
    $policy['allowed_js_bridges'] = themeStudioNormalizeStringList($input['allowed_js_bridges_text'] ?? '');
    $policy['csp_note'] = trim((string)($input['csp_note'] ?? ''));

    $document = [
        'version' => $version,
        'policy' => $policy,
    ];

    return themeStudioWriteEditableContractArray($themeSlug, 'safety-policy', $document, $error);
}

function themeStudioStructuredRendererRowsFromInput(array $input, ?string &$error = null): ?array
{
    $names = is_array($input['renderer_name'] ?? null) ? $input['renderer_name'] : [];
    $templates = is_array($input['renderer_template'] ?? null) ? $input['renderer_template'] : [];
    $components = is_array($input['renderer_component'] ?? null) ? $input['renderer_component'] : [];
    $controls = is_array($input['renderer_controls'] ?? null) ? $input['renderer_controls'] : [];
    $contextKeys = is_array($input['renderer_context_keys'] ?? null) ? $input['renderer_context_keys'] : [];
    $extras = is_array($input['renderer_extra_json'] ?? null) ? $input['renderer_extra_json'] : [];

    $count = max(count($names), count($templates), count($components), count($controls), count($contextKeys), count($extras));
    $renderers = [];

    for ($index = 0; $index < $count; $index++) {
        $name = trim((string)($names[$index] ?? ''));
        $template = trim((string)($templates[$index] ?? ''));
        $component = trim((string)($components[$index] ?? ''));
        $controlsText = (string)($controls[$index] ?? '');
        $contextKeysText = (string)($contextKeys[$index] ?? '');
        $extraJson = trim((string)($extras[$index] ?? '{}'));

        $rowIsEmpty = $name === '' && $template === '' && $component === '' && trim($controlsText) === '' && trim($contextKeysText) === '' && ($extraJson === '' || $extraJson === '{}');
        if ($rowIsEmpty) {
            continue;
        }

        if ($name === '') {
            $error = 'Each renderer row must have a renderer name.';
            return null;
        }
        if (isset($renderers[$name])) {
            $error = "Duplicate renderer name '{$name}' is not allowed.";
            return null;
        }
        if ($template === '' && $component === '') {
            $error = "Renderer '{$name}' must declare either a template or a governed component target.";
            return null;
        }
        if ($template !== '' && $component !== '') {
            $error = "Renderer '{$name}' must not declare both a template and a governed component target.";
            return null;
        }

        try {
            $extra = json_decode($extraJson === '' ? '{}' : $extraJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $error = "Renderer '{$name}' has invalid extra JSON: " . $e->getMessage();
            return null;
        }
        if (!is_array($extra)) {
            $error = "Renderer '{$name}' extra JSON must decode to an object.";
            return null;
        }

        $definition = $extra;
        if ($template !== '') {
            $definition['template'] = $template;
            unset($definition['renders_as_component']);
        } else {
            $definition['renders_as_component'] = $component;
            unset($definition['template']);
        }
        $definition['controls'] = themeStudioNormalizeStringList($controlsText);
        $definition['context_keys'] = themeStudioNormalizeStringList($contextKeysText);
        $renderers[$name] = $definition;
    }

    return $renderers;
}

function themeStudioStructuredEntityViewRowsFromInput(array $input, ?string &$error = null): ?array
{
    $entityTypes = is_array($input['entity_type'] ?? null) ? $input['entity_type'] : [];
    $viewNames = is_array($input['view_name'] ?? null) ? $input['view_name'] : [];
    $fields = is_array($input['view_fields'] ?? null) ? $input['view_fields'] : [];
    $actions = is_array($input['view_actions'] ?? null) ? $input['view_actions'] : [];
    $blocks = is_array($input['view_block'] ?? null) ? $input['view_block'] : [];
    $extras = is_array($input['view_extra_json'] ?? null) ? $input['view_extra_json'] : [];

    $count = max(count($entityTypes), count($viewNames), count($fields), count($actions), count($blocks), count($extras));
    $entityViews = [];

    for ($index = 0; $index < $count; $index++) {
        $entityType = trim((string)($entityTypes[$index] ?? ''));
        $viewName = trim((string)($viewNames[$index] ?? ''));
        $fieldsText = (string)($fields[$index] ?? '');
        $actionsText = (string)($actions[$index] ?? '');
        $block = trim((string)($blocks[$index] ?? ''));
        $extraJson = trim((string)($extras[$index] ?? '{}'));

        $rowIsEmpty = $entityType === '' && $viewName === '' && trim($fieldsText) === '' && trim($actionsText) === '' && $block === '' && ($extraJson === '' || $extraJson === '{}');
        if ($rowIsEmpty) {
            continue;
        }

        if ($entityType === '') {
            $error = 'Each entity view row must have an entity type.';
            return null;
        }
        if ($viewName === '') {
            $error = "Entity type '{$entityType}' is missing a view name.";
            return null;
        }
        if (isset($entityViews[$entityType][$viewName])) {
            $error = "Duplicate entity view row '{$entityType}.{$viewName}' is not allowed.";
            return null;
        }

        try {
            $extra = json_decode($extraJson === '' ? '{}' : $extraJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $error = "Entity view '{$entityType}.{$viewName}' has invalid extra JSON: " . $e->getMessage();
            return null;
        }
        if (!is_array($extra)) {
            $error = "Entity view '{$entityType}.{$viewName}' extra JSON must decode to an object.";
            return null;
        }

        $definition = $extra;
        $definition['fields'] = themeStudioNormalizeStringList($fieldsText);
        $definition['actions'] = themeStudioNormalizeStringList($actionsText);
        if ($block !== '') {
            $definition['block'] = $block;
        } else {
            unset($definition['block']);
        }

        if (!isset($entityViews[$entityType])) {
            $entityViews[$entityType] = [];
        }
        $entityViews[$entityType][$viewName] = $definition;
    }

    return $entityViews;
}

function themeStudioStructuredBlockRegistryRowsFromInput(array $input, ?string &$error = null): ?array
{
    $categoryNames = is_array($input['category_name'] ?? null) ? $input['category_name'] : [];
    $blockTypes = is_array($input['category_block_types'] ?? null) ? $input['category_block_types'] : [];

    $count = max(count($categoryNames), count($blockTypes));
    $categories = [];

    for ($index = 0; $index < $count; $index++) {
        $categoryName = trim((string)($categoryNames[$index] ?? ''));
        $blockTypesText = (string)($blockTypes[$index] ?? '');

        $rowIsEmpty = $categoryName === '' && trim($blockTypesText) === '';
        if ($rowIsEmpty) {
            continue;
        }

        if ($categoryName === '') {
            $error = 'Each block registry row must have a category name.';
            return null;
        }
        if (isset($categories[$categoryName])) {
            $error = "Duplicate block registry category '{$categoryName}' is not allowed.";
            return null;
        }

        $normalizedTypes = themeStudioNormalizeStringList($blockTypesText);
        if ($normalizedTypes === []) {
            $error = "Block registry category '{$categoryName}' must include at least one block type.";
            return null;
        }

        $categories[$categoryName] = array_values(array_unique($normalizedTypes));
    }

    return $categories;
}

function themeStudioDecodeStructuredContractObject(mixed $json, string $label, ?string &$error = null): ?array
{
    $json = trim((string)$json);
    try {
        $decoded = json_decode($json === '' ? '{}' : $json, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        $error = $label . ' is invalid: ' . $e->getMessage();
        return null;
    }
    if (!is_array($decoded)) {
        $error = $label . ' must decode to an object.';
        return null;
    }

    return $decoded;
}

function themeStudioInputBoolean(mixed $value): bool
{
    return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
}

function themeStudioWriteEditableContractArray(string $themeSlug, string $contractKey, array $data, ?string &$error = null): bool
{
    $detail = themeStudioEditableContractDetail($themeSlug, $contractKey);
    $path = $detail['path'] ?? null;
    if (!is_string($path) || $path === '') {
        $error = 'Unable to resolve contract path for the active theme.';
        return false;
    }

    $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($encoded)) {
        $error = 'Failed to encode contract JSON.';
        return false;
    }

    $ok = file_put_contents($path, $encoded . PHP_EOL);
    if ($ok === false) {
        $error = 'Failed to write contract file.';
        return false;
    }

    return true;
}

function themeStudioGroupTokenDefinitions(array $tokens): array
{
    $groups = [
        'colors' => [],
        'typography' => [],
        'spacing' => [],
        'radius' => [],
        'shadow' => [],
        'layout' => [],
        'animation' => [],
        'z_index' => [],
        'components' => [],
        'misc' => [],
    ];

    foreach ($tokens as $key => $value) {
        $normalized = strtolower(trim((string)$key));
        if ($normalized === '') {
            continue;
        }

        $target = 'misc';
        if (str_contains($normalized, 'color') || str_contains($normalized, 'accent') || str_contains($normalized, 'surface') || str_contains($normalized, 'border') || str_contains($normalized, 'text')) {
            $target = 'colors';
        } elseif (str_contains($normalized, 'font') || str_contains($normalized, 'type') || str_contains($normalized, 'line-height') || str_contains($normalized, 'heading')) {
            $target = 'typography';
        } elseif (str_contains($normalized, 'spacing') || preg_match('/(^|[._-])(gap|padding|margin)([._-]|$)/', $normalized) === 1) {
            $target = 'spacing';
        } elseif (str_contains($normalized, 'radius')) {
            $target = 'radius';
        } elseif (str_contains($normalized, 'shadow')) {
            $target = 'shadow';
        } elseif (str_contains($normalized, 'layout') || str_contains($normalized, 'width') || str_contains($normalized, 'container') || str_contains($normalized, 'sidebar') || str_contains($normalized, 'header-height') || str_contains($normalized, 'gutter')) {
            $target = 'layout';
        } elseif (str_contains($normalized, 'duration') || str_contains($normalized, 'easing') || str_contains($normalized, 'transition') || str_contains($normalized, 'motion') || str_contains($normalized, 'animation')) {
            $target = 'animation';
        } elseif (str_contains($normalized, 'z-index') || str_contains($normalized, 'zindex') || preg_match('/(^|[._-])z([._-]|$)/', $normalized) === 1) {
            $target = 'z_index';
        } elseif (str_contains($normalized, 'button') || str_contains($normalized, 'input') || str_contains($normalized, 'badge') || str_contains($normalized, 'card') || str_contains($normalized, 'component')) {
            $target = 'components';
        }

        $groups[$target][$key] = $value;
    }

    return array_filter($groups, static fn (array $group): bool => $group !== []);
}

function themeStudioThemeContracts(?string $themeSlug = null): array
{
    $themeSlug = trim((string)($themeSlug ?? (function_exists('cmsActiveTheme') ? cmsActiveTheme() : '')));
    if ($themeSlug === '') {
        return [
            'theme_slug' => null,
            'manifest' => [],
            'tokens' => [],
            'token_groups' => [],
            'slots' => [],
            'customizer_schema' => [],
            'renderer_registry' => [],
            'block_registry' => [],
            'entity_view_map' => [],
            'page_composition_schema' => [],
            'safety_policy' => [],
        ];
    }

    $manifest = function_exists('cmsThemeManifestForSlug') ? cmsThemeManifestForSlug($themeSlug) : [];
    $tokens = function_exists('cmsThemeManifestTokens') ? cmsThemeManifestTokens($manifest) : [];
    $slots = themeStudioReadThemeJson($themeSlug, 'slots.json');
    $customizerSchema = themeStudioReadThemeJson($themeSlug, 'customizer.schema.json');
    $rendererRegistry = themeStudioReadThemeJson($themeSlug, 'renderer-registry.json');
    $blockRegistry = themeStudioReadThemeJson($themeSlug, 'block-registry.json');
    $entityViewMap = themeStudioReadThemeJson($themeSlug, 'entity-view-map.json');
    $pageCompositionSchema = themeStudioReadThemeJson($themeSlug, 'page-composition.schema.json');
    $safetyPolicy = themeStudioReadThemeJson($themeSlug, 'safety-policy.json');

    return [
        'theme_slug' => $themeSlug,
        'manifest' => $manifest,
        'tokens' => $tokens,
        'token_groups' => themeStudioGroupTokenDefinitions($tokens),
        'slots' => $slots,
        'customizer_schema' => $customizerSchema,
        'renderer_registry' => $rendererRegistry,
        'block_registry' => $blockRegistry,
        'entity_view_map' => $entityViewMap,
        'page_composition_schema' => $pageCompositionSchema,
        'safety_policy' => $safetyPolicy,
    ];
}

function themeStudioTokenGroupRows(array $definitions, array $presetTokens = [], array $overrides = []): array
{
    $grouped = themeStudioGroupTokenDefinitions($definitions);
    $rows = [];

    foreach ($grouped as $groupName => $items) {
        $groupRows = [];
        foreach ($items as $tokenKey => $defaultValue) {
            $presetValue = array_key_exists($tokenKey, $presetTokens) ? (string)$presetTokens[$tokenKey] : null;
            $overrideValue = array_key_exists($tokenKey, $overrides) ? (string)$overrides[$tokenKey] : null;
            $currentValue = $overrideValue ?? $presetValue ?? (string)$defaultValue;
            $normalizedKey = strtolower((string)$tokenKey);
            $isColor = str_contains($normalizedKey, 'color') || preg_match('/^--[a-z0-9._-]*(primary|secondary|accent|surface|text|border|success|warning|danger|info)/', $normalizedKey) === 1;

            $groupRows[] = [
                'key' => (string)$tokenKey,
                'default_value' => (string)$defaultValue,
                'preset_value' => $presetValue,
                'override_value' => $overrideValue,
                'current_value' => $currentValue,
                'is_color' => $isColor,
            ];
        }

        $rows[] = [
            'name' => (string)$groupName,
            'label' => ucwords(str_replace('_', ' ', (string)$groupName)),
            'items' => $groupRows,
        ];
    }

    return $rows;
}

function themeStudioGovernedComponentOptions(): array
{
    if (class_exists(ComponentRegistry::class) && method_exists(ComponentRegistry::class, 'list')) {
        $components = ComponentRegistry::list();
        usort($components, static function (array $left, array $right): int {
            return strcmp((string)($left['name'] ?? ''), (string)($right['name'] ?? ''));
        });
        return $components;
    }

    return [];
}

function themeStudioBlockRegistrySummary(?string $themeSlug = null): array
{
    $themeSlug = trim((string)($themeSlug ?? (function_exists('cmsActiveTheme') ? cmsActiveTheme() : '')));
    $registry = themeStudioReadThemeJson($themeSlug, 'block-registry.json');
    $categories = is_array($registry['categories'] ?? null) ? $registry['categories'] : [];
    $summary = [];

    foreach ($categories as $categoryName => $blockTypes) {
        if (!is_array($blockTypes)) {
            continue;
        }

        $items = [];
        foreach ($blockTypes as $blockType) {
            $blockType = trim((string)$blockType);
            if ($blockType === '') {
                continue;
            }

            $filePath = themeStudioResolveBlockDefinitionPath($themeSlug, $categoryName, $blockType);
            $definition = $filePath !== null ? themeStudioReadThemeJson($themeSlug, 'block-definitions/' . $categoryName . '/' . $blockType . '.json') : [];
            $items[] = [
                'type' => $blockType,
                'category' => (string)$categoryName,
                'exists' => $definition !== [],
                'file' => $filePath,
                'definition' => $definition,
                'control_count' => is_array($definition['controls'] ?? null) ? count($definition['controls']) : 0,
                'allowed_parent_count' => is_array($definition['allowed_parents'] ?? null) ? count($definition['allowed_parents']) : 0,
                'allowed_child_count' => is_array($definition['allowed_children'] ?? null) ? count($definition['allowed_children']) : 0,
            ];
        }

        $summary[] = [
            'name' => (string)$categoryName,
            'label' => ucwords(str_replace('_', ' ', (string)$categoryName)),
            'count' => count($items),
            'implemented_count' => count(array_filter($items, static fn (array $item): bool => !empty($item['exists']))),
            'items' => $items,
        ];
    }

    return $summary;
}

function themeStudioBlockDefinitionDetail(?string $themeSlug, string $categoryName, string $blockType): array
{
    $themeSlug = trim((string)($themeSlug ?? (function_exists('cmsActiveTheme') ? cmsActiveTheme() : '')));
    $categoryName = trim($categoryName);
    $blockType = trim($blockType);
    $registry = themeStudioReadThemeJson($themeSlug, 'block-registry.json');
    $categories = is_array($registry['categories'] ?? null) ? $registry['categories'] : [];
    $registeredTypes = is_array($categories[$categoryName] ?? null) ? $categories[$categoryName] : [];
    $registered = in_array($blockType, array_map('strval', $registeredTypes), true);
    $definition = $registered ? themeStudioReadThemeJson($themeSlug, 'block-definitions/' . $categoryName . '/' . $blockType . '.json') : [];
    $path = $registered ? themeStudioResolveBlockDefinitionTargetPath($themeSlug, $categoryName, $blockType) : null;

    return [
        'theme_slug' => $themeSlug,
        'category' => $categoryName,
        'type' => $blockType,
        'registered' => $registered,
        'path' => $path,
        'exists' => $definition !== [],
        'definition' => $definition,
        'form' => themeStudioBlockDefinitionFormModel($categoryName, $blockType, $definition),
        'definition_json' => $definition !== []
            ? (string)json_encode($definition, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : (string)json_encode([
                'type' => $blockType,
                'label' => ucwords(str_replace(['_', '-'], ' ', $blockType)),
                'category' => $categoryName,
                'controls' => new stdClass(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ];
}

function themeStudioBlockDefinitionFormModel(string $categoryName, string $blockType, array $definition): array
{
    $controls = is_array($definition['controls'] ?? null) ? $definition['controls'] : [];
    $rows = [];
    foreach ($controls as $controlName => $controlDefinition) {
        if (!is_array($controlDefinition)) {
            continue;
        }
        $rows[] = themeStudioBlockControlRow((string)$controlName, $controlDefinition);
    }

    if ($rows === []) {
        $rows[] = themeStudioBlockControlRow('', []);
    }

    return [
        'label' => (string)($definition['label'] ?? ucwords(str_replace(['_', '-'], ' ', $blockType))),
        'icon' => (string)($definition['icon'] ?? ''),
        'renders_with' => (string)($definition['renders_with'] ?? ''),
        'preview_thumbnail' => (string)($definition['preview_thumbnail'] ?? ''),
        'max_children' => array_key_exists('max_children', $definition) ? (string)$definition['max_children'] : '',
        'allowed_parents_text' => implode("\n", array_map('strval', is_array($definition['allowed_parents'] ?? null) ? $definition['allowed_parents'] : [])),
        'allowed_children_text' => implode("\n", array_map('strval', is_array($definition['allowed_children'] ?? null) ? $definition['allowed_children'] : [])),
        'controls_rows' => $rows,
        'controls_rows_json' => (string)json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'type' => $blockType,
        'category' => $categoryName,
    ];
}

function themeStudioBlockControlRow(string $controlName, array $controlDefinition): array
{
    $extra = $controlDefinition;
    unset($extra['type'], $extra['label'], $extra['required'], $extra['default'], $extra['options'], $extra['placeholder'], $extra['max_length']);

    $defaultValue = $controlDefinition['default'] ?? '';
    if (is_array($defaultValue)) {
        $extra['default'] = $defaultValue;
        $defaultValue = '';
    }

    $options = $controlDefinition['options'] ?? [];
    $optionsText = '';
    if (is_array($options)) {
        $optionsText = implode(', ', array_map('strval', $options));
    } elseif (is_string($options)) {
        $optionsText = $options;
    }

    return [
        'name' => $controlName,
        'type' => (string)($controlDefinition['type'] ?? 'text'),
        'label' => (string)($controlDefinition['label'] ?? ''),
        'required' => !empty($controlDefinition['required']) ? '1' : '0',
        'default_value' => is_scalar($defaultValue) ? (string)$defaultValue : '',
        'options_text' => $optionsText,
        'placeholder' => (string)($controlDefinition['placeholder'] ?? ''),
        'max_length' => array_key_exists('max_length', $controlDefinition) ? (string)$controlDefinition['max_length'] : '',
        'extra_json' => $extra !== []
            ? (string)json_encode($extra, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : '{}',
    ];
}

function themeStudioSaveBlockDefinition(string $themeSlug, string $categoryName, string $blockType, string $definitionJson, ?string &$error = null): bool
{
    $detail = themeStudioBlockDefinitionDetail($themeSlug, $categoryName, $blockType);
    if (empty($detail['registered'])) {
        $error = 'Block type is not registered in the active ARK block registry.';
        return false;
    }

    $definitionJson = trim($definitionJson);
    if ($definitionJson === '') {
        $error = 'Definition JSON is required.';
        return false;
    }

    try {
        $decoded = json_decode($definitionJson, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        $error = 'Invalid JSON: ' . $e->getMessage();
        return false;
    }

    if (!is_array($decoded) || $decoded === []) {
        $error = 'Definition JSON must decode to an object.';
        return false;
    }

    $decoded['type'] = $blockType;
    $decoded['category'] = $categoryName;
    if (!isset($decoded['controls']) || !is_array($decoded['controls'])) {
        $decoded['controls'] = [];
    }

    $path = themeStudioResolveBlockDefinitionTargetPath($themeSlug, $categoryName, $blockType);
    if ($path === null) {
        $error = 'Unable to resolve block definition path for the active theme.';
        return false;
    }

    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        $error = 'Unable to create block definition directory.';
        return false;
    }

    $encoded = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($encoded)) {
        $error = 'Failed to encode definition JSON.';
        return false;
    }

    $ok = file_put_contents($path, $encoded . PHP_EOL);
    if ($ok === false) {
        $error = 'Failed to write block definition file.';
        return false;
    }

    return true;
}

function themeStudioSaveStructuredBlockDefinition(string $themeSlug, string $categoryName, string $blockType, array $input, ?string &$error = null): bool
{
    $detail = themeStudioBlockDefinitionDetail($themeSlug, $categoryName, $blockType);
    if (empty($detail['registered'])) {
        $error = 'Block type is not registered in the active ARK block registry.';
        return false;
    }

    $existing = is_array($detail['definition'] ?? null) ? $detail['definition'] : [];
    $label = trim((string)($input['label'] ?? ''));
    $rendersWith = trim((string)($input['renders_with'] ?? ''));
    if ($label === '') {
        $error = 'Block label is required.';
        return false;
    }
    if ($rendersWith === '') {
        $error = 'Render target is required.';
        return false;
    }

    $definition = $existing;
    $definition['type'] = $blockType;
    $definition['category'] = $categoryName;
    $definition['label'] = $label;
    $definition['icon'] = trim((string)($input['icon'] ?? ''));
    $definition['renders_with'] = $rendersWith;

    $previewThumbnail = trim((string)($input['preview_thumbnail'] ?? ''));
    if ($previewThumbnail !== '') {
        $definition['preview_thumbnail'] = $previewThumbnail;
    } else {
        unset($definition['preview_thumbnail']);
    }

    $maxChildren = trim((string)($input['max_children'] ?? ''));
    if ($maxChildren !== '') {
        if (!is_numeric($maxChildren)) {
            $error = 'Max children must be numeric when provided.';
            return false;
        }
        $definition['max_children'] = (int)$maxChildren;
    } else {
        unset($definition['max_children']);
    }

    $definition['allowed_parents'] = themeStudioNormalizeStringList($input['allowed_parents_text'] ?? '');
    $definition['allowed_children'] = themeStudioNormalizeStringList($input['allowed_children_text'] ?? '');
    $definition['controls'] = themeStudioStructuredControlsFromInput($input, $error);
    if ($definition['controls'] === null) {
        return false;
    }

    return themeStudioWriteBlockDefinitionArray($themeSlug, $categoryName, $blockType, $definition, $error);
}

function themeStudioStructuredControlsFromInput(array $input, ?string &$error = null): ?array
{
    $names = is_array($input['control_name'] ?? null) ? $input['control_name'] : [];
    $types = is_array($input['control_type'] ?? null) ? $input['control_type'] : [];
    $labels = is_array($input['control_label'] ?? null) ? $input['control_label'] : [];
    $requiredFlags = is_array($input['control_required'] ?? null) ? $input['control_required'] : [];
    $defaults = is_array($input['control_default'] ?? null) ? $input['control_default'] : [];
    $options = is_array($input['control_options'] ?? null) ? $input['control_options'] : [];
    $placeholders = is_array($input['control_placeholder'] ?? null) ? $input['control_placeholder'] : [];
    $maxLengths = is_array($input['control_max_length'] ?? null) ? $input['control_max_length'] : [];
    $extras = is_array($input['control_extra_json'] ?? null) ? $input['control_extra_json'] : [];

    $count = max(count($names), count($types), count($labels), count($requiredFlags), count($defaults), count($options), count($placeholders), count($maxLengths), count($extras));
    $controls = [];

    for ($index = 0; $index < $count; $index++) {
        $name = trim((string)($names[$index] ?? ''));
        $type = trim((string)($types[$index] ?? ''));
        $label = trim((string)($labels[$index] ?? ''));
        $required = trim((string)($requiredFlags[$index] ?? '0')) === '1';
        $default = (string)($defaults[$index] ?? '');
        $optionsText = (string)($options[$index] ?? '');
        $placeholder = trim((string)($placeholders[$index] ?? ''));
        $maxLength = trim((string)($maxLengths[$index] ?? ''));
        $extraJson = trim((string)($extras[$index] ?? '{}'));

        $rowIsEmpty = $name === '' && $type === '' && $label === '' && trim($default) === '' && trim($optionsText) === '' && $placeholder === '' && $maxLength === '' && ($extraJson === '' || $extraJson === '{}');
        if ($rowIsEmpty) {
            continue;
        }

        if ($name === '') {
            $error = 'Each structured control row must have a control name.';
            return null;
        }
        if ($type === '') {
            $error = "Control '{$name}' is missing a control type.";
            return null;
        }
        if (isset($controls[$name])) {
            $error = "Duplicate control name '{$name}' is not allowed.";
            return null;
        }

        try {
            $extra = json_decode($extraJson === '' ? '{}' : $extraJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $error = "Control '{$name}' has invalid extra JSON: " . $e->getMessage();
            return null;
        }
        if (!is_array($extra)) {
            $error = "Control '{$name}' extra JSON must decode to an object.";
            return null;
        }

        $control = $extra;
        $control['type'] = $type;
        if ($label !== '') {
            $control['label'] = $label;
        }
        if ($required) {
            $control['required'] = true;
        } else {
            unset($control['required']);
        }
        if (trim($default) !== '') {
            $control['default'] = themeStudioCastControlDefaultValue($default, $type);
        } elseif (array_key_exists('default', $control) && $control['default'] === '') {
            unset($control['default']);
        }

        $parsedOptions = themeStudioNormalizeStringList($optionsText);
        if ($parsedOptions !== []) {
            $control['options'] = $parsedOptions;
        } else {
            unset($control['options']);
        }

        if ($placeholder !== '') {
            $control['placeholder'] = $placeholder;
        } else {
            unset($control['placeholder']);
        }

        if ($maxLength !== '') {
            if (!ctype_digit(ltrim($maxLength, '-'))) {
                $error = "Control '{$name}' max length must be an integer.";
                return null;
            }
            $control['max_length'] = (int)$maxLength;
        } else {
            unset($control['max_length']);
        }

        $controls[$name] = $control;
    }

    return $controls;
}

function themeStudioCastControlDefaultValue(string $value, string $type): mixed
{
    $value = trim($value);
    if ($type === 'number' && is_numeric($value)) {
        return str_contains($value, '.') ? (float)$value : (int)$value;
    }
    if ($type === 'checkbox' || $type === 'boolean') {
        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    return $value;
}

function themeStudioNormalizeStringList(mixed $value): array
{
    if (is_array($value)) {
        $items = $value;
    } else {
        $normalized = str_replace(["\r\n", "\r"], "\n", (string)$value);
        $normalized = str_replace(',', "\n", $normalized);
        $items = explode("\n", $normalized);
    }

    $items = array_map(static fn ($item): string => trim((string)$item), $items);
    $items = array_values(array_filter($items, static fn (string $item): bool => $item !== ''));
    return array_values(array_unique($items));
}

function themeStudioWriteBlockDefinitionArray(string $themeSlug, string $categoryName, string $blockType, array $definition, ?string &$error = null): bool
{
    $path = themeStudioResolveBlockDefinitionTargetPath($themeSlug, $categoryName, $blockType);
    if ($path === null) {
        $error = 'Unable to resolve block definition path for the active theme.';
        return false;
    }

    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        $error = 'Unable to create block definition directory.';
        return false;
    }

    $encoded = json_encode($definition, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($encoded)) {
        $error = 'Failed to encode definition JSON.';
        return false;
    }

    $ok = file_put_contents($path, $encoded . PHP_EOL);
    if ($ok === false) {
        $error = 'Failed to write block definition file.';
        return false;
    }

    return true;
}

function themeStudioResolveBlockDefinitionPath(string $themeSlug, string $categoryName, string $blockType): ?string
{
    $path = themeStudioResolveBlockDefinitionTargetPath($themeSlug, $categoryName, $blockType);
    if ($path === null) {
        return null;
    }

    return is_file($path) ? $path : null;
}

function themeStudioResolveBlockDefinitionTargetPath(string $themeSlug, string $categoryName, string $blockType): ?string
{
    return themeStudioResolveThemeJsonPath(
        $themeSlug,
        'block-definitions/' . trim($categoryName) . '/' . trim($blockType) . '.json'
    );
}


// ── CMS Admin Sidebar Registration ──
// Registers Theme Studio nav items in the CMS admin sidebar via hook.
app()->hooks()->on('cms.admin.nav_items', function (array $items): array {
    $baseUrl = rtrim((string)(defined('BASE_URL') ? BASE_URL : ''), '/');

    $items[] = [
        'label'    => 'Theme Studio',
        'section'  => true,
        'children' => [
            ['label' => 'Dashboard',      'url' => $baseUrl . '/admin/theme-studio',              'icon' => 'paint-brush',  'active_key' => 'theme-studio'],
            ['label' => 'Design Tokens',  'url' => $baseUrl . '/admin/theme-studio/tokens',       'icon' => 'palette',      'active_key' => 'theme-studio-tokens'],
            ['label' => 'Presets',        'url' => $baseUrl . '/admin/theme-studio/presets',      'icon' => 'clone',        'active_key' => 'theme-studio-presets'],
            ['label' => 'Elements',       'url' => $baseUrl . '/admin/theme-studio/elements',     'icon' => 'puzzle-piece', 'active_key' => 'theme-studio-elements'],
            ['label' => 'Contracts',      'url' => $baseUrl . '/admin/theme-studio/contracts',    'icon' => 'view-columns', 'active_key' => 'theme-studio-contracts'],
            ['label' => 'Blocks',         'url' => $baseUrl . '/admin/theme-studio/blocks',       'icon' => 'collection',   'active_key' => 'theme-studio-blocks'],
        ],
    ];
    return $items;
}, 25);
