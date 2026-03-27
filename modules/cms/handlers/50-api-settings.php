<?php

declare(strict_types=1);

function cmsSettingsAuditNormalizeValue(mixed $value): mixed
{
    if (!is_array($value)) {
        return $value;
    }

    if (array_is_list($value)) {
        return array_map('cmsSettingsAuditNormalizeValue', $value);
    }

    ksort($value);
    foreach ($value as $key => $nestedValue) {
        $value[$key] = cmsSettingsAuditNormalizeValue($nestedValue);
    }

    return $value;
}

function cmsSettingsChangedKeys(array $old, array $new): array
{
    $keys = array_values(array_unique(array_merge(array_keys($old), array_keys($new))));
    $changed = [];

    foreach ($keys as $key) {
        $oldExists = array_key_exists($key, $old);
        $newExists = array_key_exists($key, $new);
        if ($oldExists !== $newExists) {
            $changed[] = $key;
            continue;
        }

        if (cmsSettingsAuditNormalizeValue($old[$key]) !== cmsSettingsAuditNormalizeValue($new[$key])) {
            $changed[] = $key;
        }
    }

    return $changed;
}

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

    $ctx = module('cms');

    // Audit log the settings change
    if ($ctx) {
        $changedKeys = cmsSettingsChangedKeys($old, $new);
        if ($changedKeys !== []) {
            $ctx->audit('cms.settings.save', null, 'cms_settings', null,
                array_intersect_key($old, array_flip($changedKeys)),
                array_intersect_key($new, array_flip($changedKeys))
            );
        }
    }

    $response = json_encode(['ok' => true]);
    echo $response;
    release_session_lock_if_active();
    finish_response_if_possible();

    // If active_theme changed, update the symlink after the response so the
    // UI does not wait on filesystem work.
    $oldTheme = trim((string)($old['active_theme'] ?? ''));
    $themeChanged = $oldTheme !== $newTheme;
    if ($oldTheme !== $newTheme) {
        $slug = ($newTheme === '' || $newTheme === 'default') ? null : $newTheme;
        cmsActivateThemeSymlink($slug);
        cmsResetThemeRuntimeCache();
        cmsTemplateCacheFlush();
    }

    // Settings changes invalidate admin views immediately; frontend/runtime cache
    // invalidation is handled centrally by the cms.settings.updated event listener.
    adminViewCacheInvalidate(['cms:admin']);
    if ($ctx) {
        $ctx->fireEvent('cms.settings.updated', $new);
    }
    exit;
}

function cmsApiSettingsReset(array $params = []): void
{
    header('Content-Type: application/json');
    $user = cmsRequireCap('settings.manage');
    app()->csrfEnforce();

    $defaults = cmsSettingsDefaults();
    $previousSettings = readCmsSettings();
    $previousTheme = trim((string)($previousSettings['active_theme'] ?? ''));
    $defaultTheme = trim((string)($defaults['active_theme'] ?? ''));
    saveModuleSettings('cms', $defaults);
    cmsResetSettingsCache();
    cmsResetCacheRuntimeState();

    $ctx = module('cms');

    // Audit log the reset
    if ($ctx) {
        $ctx->audit('cms.settings.reset', null, 'cms_settings', null,
            $previousSettings, $defaults
        );
    }

    $response = json_encode(['ok' => true, 'settings' => $defaults]);
    echo $response;
    release_session_lock_if_active();
    finish_response_if_possible();

    // Reset theme to default
    cmsActivateThemeSymlink(null);
    cmsResetThemeRuntimeCache();
    if ($previousTheme !== $defaultTheme) {
        cmsTemplateCacheFlush();
    }

    // Frontend/runtime cache invalidation is handled by the cms.settings.updated
    // event listener; only admin view cache needs immediate invalidation here.
    adminViewCacheInvalidate(['cms:admin']);
    if ($ctx) {
        $ctx->fireEvent('cms.settings.updated', $defaults);
    }
    exit;
}

// ═══════════════════════════════════════════════════════════════════════
// CATEGORY API HANDLERS
// ═══════════════════════════════════════════════════════════════════════
