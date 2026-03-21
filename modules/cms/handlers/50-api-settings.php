<?php

declare(strict_types=1);

function cmsApiSettingsSave(array $params = []): void
{
    header('Content-Type: application/json');
    $user = cmsRequireCap('settings.manage');
    app()->csrfEnforce();

    $input = cmsInput();
    $settings = $input['settings'] ?? null;
    if (!is_array($settings)) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'settings object required']);
        exit;
    }

    // Validate and normalize input through the kernel-style validator
    $validated = cmsValidateSettings($settings);

    // Merge with existing saved settings (partial saves work)
    $old = readCmsSettings();
    $new = array_merge($old, $validated);

    // Validate active_theme slug exists before accepting it
    $newTheme = trim((string)($new['active_theme'] ?? ''));
    if ($newTheme !== '' && $newTheme !== 'default') {
        $available = cmsAvailableThemes();
        $validSlugs = array_column($available, 'slug');
        if (!in_array($newTheme, $validSlugs, true)) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Theme "' . $newTheme . '" does not exist']);
            exit;
        }
    }

    saveModuleSettings('cms', $new);
    cmsResetSettingsCache();
    cmsResetCacheRuntimeState();

    // Audit log the settings change
    if ($ctx = module('cms')) {
        $changedKeys = array_keys(array_diff_assoc($new, $old));
        $ctx->audit('cms.settings.save', null, 'cms_settings', null, 
            array_intersect_key($old, array_flip($changedKeys)),
            array_intersect_key($new, array_flip($changedKeys))
        );
    }

    // If active_theme changed, update the symlink
    $oldTheme = trim((string)($old['active_theme'] ?? ''));
    if ($oldTheme !== $newTheme) {
        $slug = ($newTheme === '' || $newTheme === 'default') ? null : $newTheme;
        cmsActivateThemeSymlink($slug);
        cmsResetThemeRuntimeCache();
    }

    // Flush all CMS cache on settings change (theme, TTL, etc.)
    cmsCacheFlushAll();
    cmsTemplateCacheFlush();
    if ($ctx = module('cms')) {
        $ctx->fireEvent('cms.settings.updated', $new);
    }

    echo json_encode(['ok' => true]);
    exit;
}

function cmsApiSettingsReset(array $params = []): void
{
    header('Content-Type: application/json');
    $user = cmsRequireCap('settings.manage');
    app()->csrfEnforce();

    $defaults = cmsSettingsDefaults();
    $previousSettings = readCmsSettings();
    saveModuleSettings('cms', $defaults);
    cmsResetSettingsCache();
    cmsResetCacheRuntimeState();

    // Audit log the reset
    if ($ctx = module('cms')) {
        $ctx->audit('cms.settings.reset', null, 'cms_settings', null,
            $previousSettings, $defaults
        );
    }

    // Reset theme to default
    cmsActivateThemeSymlink(null);
    cmsResetThemeRuntimeCache();

    // Flush all CMS cache
    cmsCacheFlushAll();
    cmsTemplateCacheFlush();
    if ($ctx = module('cms')) {
        $ctx->fireEvent('cms.settings.updated', $defaults);
    }

    echo json_encode(['ok' => true, 'settings' => $defaults]);
    exit;
}

// ═══════════════════════════════════════════════════════════════════════
// CATEGORY API HANDLERS
// ═══════════════════════════════════════════════════════════════════════
