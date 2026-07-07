<?php

declare(strict_types=1);

/**
 * Theme Studio — Routes
 *
 * Admin routes for the Theme Studio companion module.
 * All handlers reference module-id:functionName format.
 */

return [
    'GET' => [
        '/admin/theme-studio' => 'theme-studio:handleStudioDashboard',
        '/admin/theme-studio/presets' => 'theme-studio:handlePresetList',
        '/admin/theme-studio/elements' => 'theme-studio:handleElementList',
        '/admin/theme-studio/tokens' => 'theme-studio:handleTokenEditor',
        '/admin/theme-studio/contracts' => 'theme-studio:handleContractExplorer',
        '/admin/theme-studio/contracts/{contractKey}' => 'theme-studio:handleContractEditor',
        '/admin/theme-studio/blocks' => 'theme-studio:handleBlockLibrary',
        '/admin/theme-studio/blocks/{category}/{type}' => 'theme-studio:handleBlockDefinitionEditor',
        // CMS-admin-prefixed routes (for CMS sidebar navigation)
        '/cms/admin/theme-studio' => 'theme-studio:handleStudioDashboard',
        '/cms/admin/theme-studio/tokens' => 'theme-studio:handleTokenEditor',
        '/cms/admin/theme-studio/presets' => 'theme-studio:handlePresetList',
        '/cms/admin/theme-studio/elements' => 'theme-studio:handleElementList',
        '/cms/admin/theme-studio/contracts' => 'theme-studio:handleContractExplorer',
        '/cms/admin/theme-studio/blocks' => 'theme-studio:handleBlockLibrary',
    ],
    'POST' => [
        '/api/v1/theme-studio/tokens/save' => 'theme-studio:apiSaveTokens',
        '/api/v1/theme-studio/tokens/reset' => 'theme-studio:apiResetTokens',
        '/api/v1/theme-studio/presets/save' => 'theme-studio:apiSavePreset',
        '/api/v1/theme-studio/presets/delete' => 'theme-studio:apiDeletePreset',
        '/api/v1/theme-studio/presets/apply' => 'theme-studio:apiApplyPreset',
        '/api/v1/theme-studio/presets/export' => 'theme-studio:apiExportPreset',
        '/api/v1/theme-studio/presets/import' => 'theme-studio:apiImportPreset',
        '/api/v1/theme-studio/elements/save' => 'theme-studio:apiSaveElement',
        '/api/v1/theme-studio/elements/delete' => 'theme-studio:apiDeleteElement',
        '/admin/theme-studio/contracts/{contractKey}/save' => 'theme-studio:handleContractSave',
        '/admin/theme-studio/blocks/{category}/{type}/save' => 'theme-studio:handleBlockDefinitionSave',
    ],
];
