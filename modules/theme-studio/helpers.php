<?php

declare(strict_types=1);

/**
 * Theme Studio — Helpers
 *
 * Core business logic for token editing, preset management, and Theme Elements.
 * Auto-loaded when the module is enabled.
 */

use Ikabud\Kernel\Services\SlotRegistry;

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

/**
 * Render a Theme Studio admin page.
 */
function themeStudioRender(string $template, array $context = []): string
{
    $adminContext = [
        'page_title' => 'Theme Studio',
        'studio_enabled' => getModuleSettings('theme-studio')['studio_enabled'] ?? '1',
        'active_theme' => function_exists('cmsActiveTheme') ? cmsActiveTheme() : null,
        'available_themes' => function_exists('cmsAvailableThemes') ? cmsAvailableThemes() : [],
    ];

    if (function_exists('cmsAdminContext')) {
        $user = function_exists('cmsCtxUser') ? cmsCtxUser() : [];
        $adminContext = array_merge(
            cmsAdminContext($user, 'theme-studio', []),
            $adminContext
        );
    }

    return cmsRender($template, array_merge($adminContext, $context));
}
