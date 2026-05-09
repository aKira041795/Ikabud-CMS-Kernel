<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

function ehrCoreSettingsPage(array $params = []): void
{
    if (!function_exists('cmsRequireRole') || !function_exists('cmsRender') || !function_exists('cmsAdminContext')) {
        http_response_code(503);
        echo 'CMS admin runtime unavailable';
        return;
    }

    $user = cmsRequireRole('administrator');
    $settings = ehcModuleSettings();

    echo cmsRender('modules/ehr-core/admin/settings.disyl', array_merge(
        cmsAdminContext($user, 'ehr_settings', [
            ['label' => 'EHR Settings', 'url' => ''],
        ]),
        [
            'page_title' => 'EHR Settings',
            'settings' => [
                'app_name' => (string)($settings['app_name'] ?? ehcAppName()),
                'login_subtitle' => (string)($settings['login_subtitle'] ?? ehcLoginSubtitle()),
                'logo_url' => ehcLogoUrl(),
                'favicon_url' => ehcFaviconUrl(),
                'resolved_favicon_url' => ehcResolvedFaviconUrl(),
                'brand_initial' => ehcBrandInitial(),
            ],
            'forgot_password_url' => external_base_url() . '/forgot-password',
            'login_url' => external_base_url() . '/login',
        ]
    ));
}

function ehrCoreApiUploadBrandingAsset(array $params = []): void
{
    header('Content-Type: application/json; charset=utf-8');

    if (!function_exists('cmsRequireRole')) {
        app()->json(['ok' => false, 'error' => 'CMS admin runtime unavailable'], 503);
        return;
    }

    cmsRequireRole('administrator');
    $assetType = strtolower(trim((string)($_POST['asset_type'] ?? '')));
    $file = kernelUploadedFile('asset_file');
    if (!is_array($file)) {
        app()->json(['ok' => false, 'error' => 'Upload a branding image first.'], 422);
        return;
    }

    try {
        $upload = ehcUploadBrandAsset($assetType, $file);
    } catch (InvalidArgumentException $e) {
        app()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        return;
    } catch (Throwable $e) {
        write_log('ehr-core branding upload failed: ' . $e->getMessage(), 'error');
        app()->json(['ok' => false, 'error' => 'Failed to upload branding asset.'], 500);
        return;
    }

    $settingKey = $assetType === 'favicon' ? 'favicon_url' : 'logo_url';
    if (!ehcPersistModuleSettings([$settingKey => $upload['asset_url']])) {
        app()->json(['ok' => false, 'error' => 'Branding asset uploaded but could not be persisted to settings.'], 500);
        return;
    }

    ehcAudit('ehr-core', 'branding_asset_uploaded', 'module_settings', 'ehr-core', [
        'asset_type' => $assetType,
        'asset_url' => $upload['asset_url'],
    ]);

    app()->json([
        'ok' => true,
        'asset_type' => $assetType,
        'asset_url' => $upload['asset_url'],
        'message' => ucfirst($assetType) . ' uploaded.',
    ]);
}

function ehrCoreApiSaveSettings(array $params = []): void
{
    header('Content-Type: application/json; charset=utf-8');

    if (!function_exists('cmsRequireRole')) {
        app()->json(['ok' => false, 'error' => 'CMS admin runtime unavailable'], 503);
        return;
    }

    cmsRequireRole('administrator');
    $input = app()->input();

    $appNameInput = trim((string)($input['app_name'] ?? ''));
    $appName = $appNameInput !== '' ? mb_substr($appNameInput, 0, 120) : 'EHR Suite';
    $loginSubtitle = trim((string)($input['login_subtitle'] ?? ''));
    $loginSubtitle = $loginSubtitle !== '' ? mb_substr($loginSubtitle, 0, 280) : ehcLoginSubtitle();

    try {
        $logoUrl = ehcNormalizeBrandAssetUrl($input['logo_url'] ?? '', 'Logo URL');
        $faviconUrl = ehcNormalizeBrandAssetUrl($input['favicon_url'] ?? '', 'Favicon URL');
    } catch (InvalidArgumentException $e) {
        app()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        return;
    }

    $settingsToSave = [
        'app_name' => $appName,
        'login_subtitle' => $loginSubtitle,
        'logo_url' => $logoUrl,
        'favicon_url' => $faviconUrl,
    ];

    if (!ehcPersistModuleSettings($settingsToSave)) {
        app()->json(['ok' => false, 'error' => 'Failed to persist EHR settings.'], 500);
        return;
    }

    ehcAudit('ehr-core', 'settings_updated', 'module_settings', 'ehr-core', $settingsToSave);

    app()->json([
        'ok' => true,
        'message' => 'EHR settings updated.',
        'settings' => array_merge($settingsToSave, [
            'resolved_favicon_url' => $faviconUrl !== '' ? $faviconUrl : ehcDefaultFaviconUrl(),
            'brand_initial' => ehcBrandInitial(),
        ]),
    ]);
}