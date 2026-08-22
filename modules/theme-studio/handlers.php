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
    $contracts = themeStudioThemeContracts($activeTheme);
    $manifest = $contracts['manifest'];
    $slots = is_array($contracts['slots'] ?? null) ? $contracts['slots'] : [];
    $customizerSections = is_array($contracts['customizer_schema']['sections'] ?? null) ? $contracts['customizer_schema']['sections'] : [];
    $tokenGroups = is_array($contracts['token_groups'] ?? null) ? $contracts['token_groups'] : [];
    $themeLabel = $manifest['label'] ?? $activeTheme ?? 'None';
    $isArk = $activeTheme === 'ark' || stripos((string)$themeLabel, 'ark') !== false || !empty($manifest['supported_surfaces']);

    echo cmsRender('modules/theme-studio/dashboard.disyl', array_merge(
        cmsAdminContext($user, 'theme-studio', [
            ['label' => 'Theme Studio', 'url' => CMS_ADMIN_PATH . '/theme-studio'],
        ]),
        [
            'page_title' => 'Theme Studio',
            'active_preset' => $activePreset,
            'presets' => $presets,
            'active_theme' => $activeTheme,
            'theme_label' => $themeLabel,
            'is_ark' => $isArk,
            'studio_enabled' => $settings['studio_enabled'] ?? '1',
            'theme_manifest' => $manifest,
            'theme_contracts' => $contracts,
            'supported_surfaces' => is_array($manifest['supported_surfaces'] ?? null) ? $manifest['supported_surfaces'] : [],
            'supported_slots' => is_array($manifest['supported_slots'] ?? null) ? $manifest['supported_slots'] : array_keys($slots),
            'slot_count' => count($slots !== [] ? $slots : (is_array($manifest['supported_slots'] ?? null) ? $manifest['supported_slots'] : [])),
            'token_count' => count(is_array($contracts['tokens'] ?? null) ? $contracts['tokens'] : []),
            'token_groups' => $tokenGroups,
            'customizer_sections' => $customizerSections,
            'customizer_section_count' => count($customizerSections),
            'has_renderer_registry' => !empty($contracts['renderer_registry']),
            'has_block_registry' => !empty($contracts['block_registry']),
            'has_entity_view_map' => !empty($contracts['entity_view_map']),
            'has_safety_policy' => !empty($contracts['safety_policy']),
        ]
    ));
}

function handlePresetList(array $params = []): void
{
    $user = cmsRequireCap('theme.presets@1');
    $presets = themeStudioPresets();
    $activePreset = trim((string)(getModuleSettings('theme-studio')['active_preset'] ?? ''));

    echo cmsRender('modules/theme-studio/presets.disyl', array_merge(
        cmsAdminContext($user, 'theme-studio-presets', [
            ['label' => 'Theme Studio', 'url' => CMS_ADMIN_PATH . '/theme-studio'],
            ['label' => 'Presets', 'url' => CMS_ADMIN_PATH . '/theme-studio/presets'],
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
    $activeTheme = function_exists('cmsActiveTheme') ? cmsActiveTheme() : null;
    $contracts = themeStudioThemeContracts($activeTheme);
    $slots = is_array($contracts['slots'] ?? null) ? $contracts['slots'] : [];
    $components = themeStudioGovernedComponentOptions();

    echo cmsRender('modules/theme-studio/elements.disyl', array_merge(
        cmsAdminContext($user, 'theme-studio-elements', [
            ['label' => 'Theme Studio', 'url' => CMS_ADMIN_PATH . '/theme-studio'],
            ['label' => 'Elements', 'url' => CMS_ADMIN_PATH . '/theme-studio/elements'],
        ]),
        [
            'page_title' => 'Theme Elements',
            'elements' => $elements,
            'elements_json' => json_encode(array_combine(
                array_map(fn($el) => $el['id'] ?? 0, $elements),
                $elements
            ) ?: [], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'slot_items' => $slots,
            'governed_components' => $components,
        ]
    ));
}

function handleTokenEditor(array $params = []): void
{
    $user = cmsRequireCap('theme.tokens@1');
    $activeTheme = function_exists('cmsActiveTheme') ? cmsActiveTheme() : null;
    $contracts = themeStudioThemeContracts($activeTheme);
    $manifest = $contracts['manifest'];
    $tenantId = function_exists('cmsRuntimeTenantId') ? cmsRuntimeTenantId() : 0;
    $overrides = $tenantId > 0 && $activeTheme
        ? themeStudioTokenOverrides($tenantId, $activeTheme)
        : [];
    $flattenedTokens = is_array($contracts['tokens'] ?? null) ? $contracts['tokens'] : [];
    $tokenGroups = is_array($contracts['token_groups'] ?? null) ? $contracts['token_groups'] : [];
    $presetTokens = [];
    $activePreset = trim((string)(getModuleSettings('theme-studio')['active_preset'] ?? ''));
    if ($activePreset !== '') {
        $presets = themeStudioPresets();
        $presetTokens = is_array($presets[$activePreset]['data']['tokens'] ?? null) ? $presets[$activePreset]['data']['tokens'] : [];
    }
    $tokenGroupRows = themeStudioTokenGroupRows($flattenedTokens, $presetTokens, $overrides);

    echo cmsRender('modules/theme-studio/tokens.disyl', array_merge(
        cmsAdminContext($user, 'theme-studio-tokens', [
            ['label' => 'Theme Studio', 'url' => CMS_ADMIN_PATH . '/theme-studio'],
            ['label' => 'Tokens', 'url' => CMS_ADMIN_PATH . '/theme-studio/tokens'],
        ]),
        [
            'page_title' => 'Design Tokens',
            'active_theme' => $activeTheme,
            'theme_manifest' => $manifest,
            'token_overrides' => $overrides,
            'token_overrides_json' => json_encode($overrides, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'token_definitions' => $flattenedTokens,
            'token_groups' => $tokenGroups,
            'token_group_rows' => $tokenGroupRows,
            'preset_tokens' => $presetTokens,
            'active_preset' => $activePreset,
        ]
    ));
}

function handleContractExplorer(array $params = []): void
{
    $user = cmsRequireCap('theme.customize@1');
    $activeTheme = function_exists('cmsActiveTheme') ? cmsActiveTheme() : null;
    $contracts = themeStudioThemeContracts($activeTheme);
    $editableContracts = themeStudioEditableContractMap();

    echo cmsRender('modules/theme-studio/contracts.disyl', array_merge(
        cmsAdminContext($user, 'theme-studio', [
            ['label' => 'Theme Studio', 'url' => CMS_ADMIN_PATH . '/theme-studio'],
            ['label' => 'Contracts', 'url' => CMS_ADMIN_PATH . '/theme-studio/contracts'],
        ]),
        [
            'page_title' => 'Theme Contracts',
            'active_theme' => $activeTheme,
            'theme_contracts' => $contracts,
            'theme_manifest' => $contracts['manifest'],
            'slot_items' => is_array($contracts['slots'] ?? null) ? $contracts['slots'] : [],
            'customizer_sections' => is_array($contracts['customizer_schema']['sections'] ?? null) ? $contracts['customizer_schema']['sections'] : [],
            'token_groups' => is_array($contracts['token_groups'] ?? null) ? $contracts['token_groups'] : [],
            'token_group_rows' => themeStudioTokenGroupRows(is_array($contracts['tokens'] ?? null) ? $contracts['tokens'] : []),
            'renderer_registry' => is_array($contracts['renderer_registry'] ?? null) ? $contracts['renderer_registry'] : [],
            'block_registry' => is_array($contracts['block_registry'] ?? null) ? $contracts['block_registry'] : [],
            'entity_view_map' => is_array($contracts['entity_view_map'] ?? null) ? $contracts['entity_view_map'] : [],
            'page_composition_schema' => is_array($contracts['page_composition_schema'] ?? null) ? $contracts['page_composition_schema'] : [],
            'safety_policy' => is_array($contracts['safety_policy'] ?? null) ? $contracts['safety_policy'] : [],
            'editable_contracts' => array_map(function(array $contract, string $key) use ($contracts): array {
                $contract['_present'] = !empty($contracts[str_replace('-', '_', $key)]);
                return $contract;
            }, $editableContracts, array_keys($editableContracts)),
        ]
    ));
}

function handleContractEditor(array $params = []): void
{
    $user = cmsRequireCap('theme.customize@1');
    $activeTheme = function_exists('cmsActiveTheme') ? cmsActiveTheme() : null;
    $contractKey = trim((string)($params['contractKey'] ?? ''));
    $detail = themeStudioEditableContractDetail($activeTheme, $contractKey);

    if (empty($detail['registered'])) {
        http_response_code(404);
    }

    echo cmsRender('modules/theme-studio/contract-edit.disyl', array_merge(
        cmsAdminContext($user, 'theme-studio', [
            ['label' => 'Theme Studio', 'url' => CMS_ADMIN_PATH . '/theme-studio'],
            ['label' => 'Contracts', 'url' => CMS_ADMIN_PATH . '/theme-studio/contracts'],
            ['label' => !empty($detail['label']) ? (string)$detail['label'] : 'Contract Editor', 'url' => CMS_ADMIN_PATH . '/theme-studio/contracts/' . rawurlencode($contractKey)],
        ]),
        [
            'page_title' => 'Edit Theme Contract',
            'active_theme' => $activeTheme,
            'theme_manifest' => $activeTheme ? cmsThemeManifestForSlug($activeTheme) : [],
            'contract_detail' => $detail,
            'notice' => (string)cmsInput('notice', ''),
            'error' => (string)cmsInput('error', ''),
        ]
    ));
}

function handleBlockLibrary(array $params = []): void
{
    $user = cmsRequireCap('theme.customize@1');
    $activeTheme = function_exists('cmsActiveTheme') ? cmsActiveTheme() : null;
    $contracts = themeStudioThemeContracts($activeTheme);
    $blockCategories = themeStudioBlockRegistrySummary($activeTheme);
    $rendererRegistry = is_array($contracts['renderer_registry']['renderers'] ?? null) ? $contracts['renderer_registry']['renderers'] : [];

    echo cmsRender('modules/theme-studio/blocks.disyl', array_merge(
        cmsAdminContext($user, 'theme-studio', [
            ['label' => 'Theme Studio', 'url' => CMS_ADMIN_PATH . '/theme-studio'],
            ['label' => 'Theme Blocks', 'url' => CMS_ADMIN_PATH . '/theme-studio/blocks'],
        ]),
        [
            'page_title' => 'Theme Blocks',
            'active_theme' => $activeTheme,
            'theme_manifest' => $contracts['manifest'],
            'block_categories' => $blockCategories,
            'renderer_registry' => $rendererRegistry,
            'renderer_count' => count($rendererRegistry),
        ]
    ));
}

function handleBlockDefinitionEditor(array $params = []): void
{
    $user = cmsRequireCap('theme.customize@1');
    $activeTheme = function_exists('cmsActiveTheme') ? cmsActiveTheme() : null;
    $category = trim((string)($params['category'] ?? ''));
    $blockType = trim((string)($params['type'] ?? ''));
    $detail = themeStudioBlockDefinitionDetail($activeTheme, $category, $blockType);

    if (empty($detail['registered'])) {
        http_response_code(404);
    }

    echo cmsRender('modules/theme-studio/block-edit.disyl', array_merge(
        cmsAdminContext($user, 'theme-studio', [
            ['label' => 'Theme Studio', 'url' => CMS_ADMIN_PATH . '/theme-studio'],
            ['label' => 'Theme Blocks', 'url' => CMS_ADMIN_PATH . '/theme-studio/blocks'],
            ['label' => $blockType !== '' ? $blockType : 'Block Editor', 'url' => CMS_ADMIN_PATH . '/theme-studio/blocks/' . rawurlencode($category) . '/' . rawurlencode($blockType)],
        ]),
        [
            'page_title' => 'Edit Theme Block Definition',
            'active_theme' => $activeTheme,
            'theme_manifest' => $activeTheme ? cmsThemeManifestForSlug($activeTheme) : [],
            'block_detail' => $detail,
            'notice' => (string)cmsInput('notice', ''),
            'error' => (string)cmsInput('error', ''),
        ]
    ));
}

// ── API Handlers ─────────────────────────────────────────────────

function apiSaveTokens(array $params = []): void
{
    $user = cmsRequireCap('theme.tokens@1');
    app()->csrfEnforce();
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
    app()->csrfEnforce();
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
    app()->csrfEnforce();
    $slug = trim((string)($params['slug'] ?? ''));
    $label = trim((string)($params['label'] ?? $slug));
    $description = trim((string)($params['description'] ?? ''));
    $data = is_array($params['data'] ?? null) ? $params['data'] : [];

    if ($data === []) {
        $activeTheme = function_exists('cmsActiveTheme') ? cmsActiveTheme() : null;
        $tenantId = function_exists('cmsRuntimeTenantId') ? cmsRuntimeTenantId() : 0;
        $manifest = $activeTheme ? cmsThemeManifestForSlug($activeTheme) : [];
        $baseTokens = function_exists('cmsThemeManifestTokens') ? cmsThemeManifestTokens($manifest) : [];
        $overrides = ($tenantId > 0 && $activeTheme) ? themeStudioTokenOverrides($tenantId, $activeTheme) : [];
        $currentSettings = getModuleSettings('theme-studio');
        $data = [
            'tokens' => array_merge($baseTokens, $overrides),
            'layout' => [
                'active_theme' => $activeTheme,
                'studio_enabled' => $currentSettings['studio_enabled'] ?? '1',
            ],
        ];
    }

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
    app()->csrfEnforce();
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
    app()->csrfEnforce();
    $slug = trim((string)($params['slug'] ?? ''));

    if ($slug === '') {
        // Empty slug = clear active preset
        saveModuleSettings('theme-studio', ['active_preset' => '']);
        echo json_encode(['ok' => true, 'cleared' => true]);
        return;
    }

    $ok = themeStudioApplyPreset($slug);
    echo json_encode(['ok' => $ok]);
}

function apiExportPreset(array $params = []): void
{
    $user = cmsRequireCap('theme.presets@1');
    app()->csrfEnforce();
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
    app()->csrfEnforce();

    $body = file_get_contents('php://input');
    $payload = json_decode($body, true);
    if (!is_array($payload)) {
        $rawPayload = $params['payload'] ?? null;
        if (is_string($rawPayload) && trim($rawPayload) !== '') {
            $payload = json_decode($rawPayload, true);
        }
    }

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
    app()->csrfEnforce();

    $componentAttrs = $params['component_attrs'] ?? [];
    if (is_string($componentAttrs)) {
        $decoded = json_decode($componentAttrs, true);
        $componentAttrs = is_array($decoded) ? $decoded : [];
    }

    $displayConditions = $params['display_conditions'] ?? [];
    if (is_string($displayConditions)) {
        $decoded = json_decode($displayConditions, true);
        $displayConditions = is_array($decoded) ? $decoded : [];
    }

    $data = [
        'slug' => trim((string)($params['slug'] ?? '')),
        'label' => trim((string)($params['label'] ?? '')),
        'element_type' => trim((string)($params['element_type'] ?? 'hook')),
        'slot_name' => trim((string)($params['slot_name'] ?? '')),
        'component' => trim((string)($params['component'] ?? 'ikb_panel')),
        'component_attrs' => is_array($componentAttrs) ? $componentAttrs : [],
        'display_conditions' => is_array($displayConditions) ? $displayConditions : [],
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
    app()->csrfEnforce();
    $slug = trim((string)($params['slug'] ?? ''));

    if ($slug === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Slug required']);
        return;
    }

    $ok = themeStudioDeleteElement($slug);
    echo json_encode(['ok' => $ok]);
}

function handleBlockDefinitionSave(array $params = []): void
{
    $user = cmsRequireCap('theme.customize@1');
    $activeTheme = function_exists('cmsActiveTheme') ? cmsActiveTheme() : null;
    $category = trim((string)($params['category'] ?? ''));
    $blockType = trim((string)($params['type'] ?? ''));
    $redirect = CMS_ADMIN_PATH . '/theme-studio/blocks/' . rawurlencode($category) . '/' . rawurlencode($blockType);
    $editorMode = trim((string)cmsInput('editor_mode', 'structured'));

    $error = null;
    if ($activeTheme === null) {
        $ok = false;
        $error = 'No active theme is available for block editing.';
    } elseif ($editorMode === 'advanced') {
        $definitionJson = (string)cmsInput('definition_json', '');
        $ok = themeStudioSaveBlockDefinition($activeTheme, $category, $blockType, $definitionJson, $error);
    } else {
        $ok = themeStudioSaveStructuredBlockDefinition($activeTheme, $category, $blockType, cmsInput() ?? [], $error);
    }

    if (!$ok) {
        cmsRedirect($redirect . '?error=' . rawurlencode($error ?? 'Unable to save block definition.'));
        return;
    }

    cmsRedirect($redirect . '?notice=' . rawurlencode($editorMode === 'advanced' ? 'Block definition JSON saved.' : 'Structured block definition saved.'));
}

function handleContractSave(array $params = []): void
{
    $user = cmsRequireCap('theme.customize@1');
    $activeTheme = function_exists('cmsActiveTheme') ? cmsActiveTheme() : null;
    $contractKey = trim((string)($params['contractKey'] ?? ''));
    $redirect = CMS_ADMIN_PATH . '/theme-studio/contracts/' . rawurlencode($contractKey);
    $editorMode = trim((string)cmsInput('editor_mode', 'advanced'));

    $error = null;
    if ($activeTheme === null) {
        $ok = false;
        $error = 'No active theme is available for contract editing.';
    } elseif ($contractKey === 'renderer-registry' && $editorMode === 'structured') {
        $ok = themeStudioSaveStructuredRendererRegistry($activeTheme, cmsInput() ?? [], $error);
    } elseif ($contractKey === 'entity-view-map' && $editorMode === 'structured') {
        $ok = themeStudioSaveStructuredEntityViewMap($activeTheme, cmsInput() ?? [], $error);
    } elseif ($contractKey === 'block-registry' && $editorMode === 'structured') {
        $ok = themeStudioSaveStructuredBlockRegistry($activeTheme, cmsInput() ?? [], $error);
    } elseif ($contractKey === 'page-composition-schema' && $editorMode === 'structured') {
        $ok = themeStudioSaveStructuredPageCompositionSchema($activeTheme, cmsInput() ?? [], $error);
    } elseif ($contractKey === 'safety-policy' && $editorMode === 'structured') {
        $ok = themeStudioSaveStructuredSafetyPolicy($activeTheme, cmsInput() ?? [], $error);
    } else {
        $json = (string)cmsInput('contract_json', '');
        $ok = themeStudioSaveEditableContract($activeTheme, $contractKey, $json, $error);
    }

    if (!$ok) {
        cmsRedirect($redirect . '?error=' . rawurlencode($error ?? 'Unable to save theme contract.'));
        return;
    }

    cmsRedirect($redirect . '?notice=' . rawurlencode($editorMode === 'structured' ? 'Structured theme contract saved.' : 'Theme contract saved.'));
}
