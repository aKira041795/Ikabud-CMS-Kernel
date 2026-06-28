<?php

declare(strict_types=1);

/**
 * Theme Studio — Handlers
 *
 * Admin page and API handlers for the Theme Studio companion module.
 * Each handler follows the module-id:functionName convention.
 */

// ── Admin Pages ──────────────────────────────────────────────────

function handleStudioDashboard(array $params = []): void
{
    $user = cmsRequireCap('theme.customize@1');
    $settings = getModuleSettings('theme-studio');
    $activePreset = trim((string)($settings['active_preset'] ?? ''));
    $presets = themeStudioPresets();
    $activeTheme = function_exists('cmsActiveTheme') ? cmsActiveTheme() : null;
    $manifest = $activeTheme ? cmsThemeManifestForSlug($activeTheme) : [];

    echo cmsRender('modules/theme-studio/dashboard.disyl', array_merge(
        cmsAdminContext($user, 'theme-studio', [
            ['label' => 'Theme Studio', 'url' => '/admin/theme-studio'],
        ]),
        [
            'page_title' => 'Theme Studio',
            'active_preset' => $activePreset,
            'presets' => $presets,
            'active_theme' => $activeTheme,
            'theme_label' => $manifest['label'] ?? $activeTheme ?? 'None',
            'studio_enabled' => $settings['studio_enabled'] ?? '1',
        ]
    ));
}

function handlePresetList(array $params = []): void
{
    $user = cmsRequireCap('theme.presets@1');
    $presets = themeStudioPresets();
    $activePreset = trim((string)(getModuleSettings('theme-studio')['active_preset'] ?? ''));

    echo cmsRender('modules/theme-studio/presets.disyl', array_merge(
        cmsAdminContext($user, 'theme-studio', [
            ['label' => 'Theme Studio', 'url' => '/admin/theme-studio'],
            ['label' => 'Presets', 'url' => '/admin/theme-studio/presets'],
        ]),
        [
            'page_title' => 'Theme Presets',
            'presets' => $presets,
            'active_preset' => $activePreset,
        ]
    ));
}

function handleElementList(array $params = []): void
{
    $user = cmsRequireCap('theme.elements@1');
    $elements = themeStudioElements();

    echo cmsRender('modules/theme-studio/elements.disyl', array_merge(
        cmsAdminContext($user, 'theme-studio', [
            ['label' => 'Theme Studio', 'url' => '/admin/theme-studio'],
            ['label' => 'Elements', 'url' => '/admin/theme-studio/elements'],
        ]),
        [
            'page_title' => 'Theme Elements',
            'elements' => $elements,
        ]
    ));
}

function handleTokenEditor(array $params = []): void
{
    $user = cmsRequireCap('theme.tokens@1');
    $activeTheme = function_exists('cmsActiveTheme') ? cmsActiveTheme() : null;
    $manifest = $activeTheme ? cmsThemeManifestForSlug($activeTheme) : [];
    $tenantId = function_exists('cmsRuntimeTenantId') ? cmsRuntimeTenantId() : 0;
    $overrides = $tenantId > 0 && $activeTheme
        ? themeStudioTokenOverrides($tenantId, $activeTheme)
        : [];
    $flattenedTokens = function_exists('cmsThemeManifestTokens')
        ? cmsThemeManifestTokens($manifest)
        : [];

    echo cmsRender('modules/theme-studio/tokens.disyl', array_merge(
        cmsAdminContext($user, 'theme-studio', [
            ['label' => 'Theme Studio', 'url' => '/admin/theme-studio'],
            ['label' => 'Tokens', 'url' => '/admin/theme-studio/tokens'],
        ]),
        [
            'page_title' => 'Design Tokens',
            'active_theme' => $activeTheme,
            'theme_manifest' => $manifest,
            'token_overrides' => $overrides,
            'token_definitions' => $flattenedTokens,
        ]
    ));
}

// ── API Handlers ─────────────────────────────────────────────────

function apiSaveTokens(array $params = []): void
{
    $user = cmsRequireCap('theme.tokens@1');
    $tokens = is_array($params['tokens'] ?? null) ? $params['tokens'] : [];
    $tenantId = function_exists('cmsRuntimeTenantId') ? cmsRuntimeTenantId() : 0;
    $themeSlug = trim((string)($params['theme_slug'] ?? ''));

    if ($tenantId <= 0 || $themeSlug === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid tenant or theme']);
        return;
    }

    $ok = themeStudioSaveTokenOverrides($tenantId, $themeSlug, $tokens);
    echo json_encode(['ok' => $ok]);
}

function apiResetTokens(array $params = []): void
{
    $user = cmsRequireCap('theme.tokens@1');
    $tenantId = function_exists('cmsRuntimeTenantId') ? cmsRuntimeTenantId() : 0;
    $themeSlug = trim((string)($params['theme_slug'] ?? ''));

    if ($tenantId <= 0 || $themeSlug === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid tenant or theme']);
        return;
    }

    $ok = themeStudioResetTokenOverrides($tenantId, $themeSlug);
    echo json_encode(['ok' => $ok]);
}

function apiSavePreset(array $params = []): void
{
    $user = cmsRequireCap('theme.presets@1');
    $slug = trim((string)($params['slug'] ?? ''));
    $label = trim((string)($params['label'] ?? $slug));
    $description = trim((string)($params['description'] ?? ''));
    $data = is_array($params['data'] ?? null) ? $params['data'] : [];

    if ($slug === '' || empty($data)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Slug and data required']);
        return;
    }

    $ok = themeStudioSavePreset($slug, $label, $description, $data);
    echo json_encode(['ok' => $ok]);
}

function apiDeletePreset(array $params = []): void
{
    $user = cmsRequireCap('theme.presets@1');
    $slug = trim((string)($params['slug'] ?? ''));

    if ($slug === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Slug required']);
        return;
    }

    $ok = themeStudioDeletePreset($slug);
    echo json_encode(['ok' => $ok]);
}

function apiApplyPreset(array $params = []): void
{
    $user = cmsRequireCap('theme.presets@1');
    $slug = trim((string)($params['slug'] ?? ''));

    if ($slug === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Slug required']);
        return;
    }

    $ok = themeStudioApplyPreset($slug);
    echo json_encode(['ok' => $ok]);
}

function apiExportPreset(array $params = []): void
{
    $user = cmsRequireCap('theme.presets@1');
    $slug = trim((string)($params['slug'] ?? ''));
    $presets = themeStudioPresets();

    if ($slug === '' || !isset($presets[$slug])) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Preset not found']);
        return;
    }

    $preset = $presets[$slug];
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="theme-preset-' . $slug . '.json"');
    echo json_encode([
        'meta' => [
            'schema' => 'https://ikabud.dev/schemas/theme-preset-v1.json',
            'version' => '1.0',
            'exported_at' => date('c'),
        ],
        'preset' => [
            'slug' => $slug,
            'label' => $preset['label'],
            'description' => $preset['description'] ?? '',
            'data' => $preset['data'] ?? [],
        ],
    ]);
}

function apiImportPreset(array $params = []): void
{
    $user = cmsRequireCap('theme.presets@1');

    $body = file_get_contents('php://input');
    $payload = json_decode($body, true);

    if (!is_array($payload) || !isset($payload['preset'])) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid import format']);
        return;
    }

    $preset = $payload['preset'];
    $slug = trim((string)($preset['slug'] ?? ''));
    $label = trim((string)($preset['label'] ?? $slug));
    $description = trim((string)($preset['description'] ?? ''));
    $data = is_array($preset['data'] ?? null) ? $preset['data'] : [];

    if ($slug === '' || empty($data)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid preset data']);
        return;
    }

    $ok = themeStudioSavePreset($slug, $label, $description, $data, 'imported');
    echo json_encode(['ok' => $ok, 'slug' => $slug, 'label' => $label]);
}

function apiSaveElement(array $params = []): void
{
    $user = cmsRequireCap('theme.elements@1');

    $data = [
        'slug' => trim((string)($params['slug'] ?? '')),
        'label' => trim((string)($params['label'] ?? '')),
        'element_type' => trim((string)($params['element_type'] ?? 'hook')),
        'slot_name' => trim((string)($params['slot_name'] ?? '')),
        'component' => trim((string)($params['component'] ?? 'ikb_panel')),
        'component_attrs' => is_array($params['component_attrs'] ?? null) ? $params['component_attrs'] : [],
        'display_conditions' => is_array($params['display_conditions'] ?? null) ? $params['display_conditions'] : [],
        'priority' => (int)($params['priority'] ?? 10),
        'is_active' => !empty($params['is_active']),
    ];

    if ($data['slug'] === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Slug required']);
        return;
    }

    $ok = themeStudioSaveElement($data);
    echo json_encode(['ok' => $ok]);
}

function apiDeleteElement(array $params = []): void
{
    $user = cmsRequireCap('theme.elements@1');
    $slug = trim((string)($params['slug'] ?? ''));

    if ($slug === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Slug required']);
        return;
    }

    $ok = themeStudioDeleteElement($slug);
    echo json_encode(['ok' => $ok]);
}
