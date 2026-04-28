<?php

declare(strict_types=1);

function bakeshopApiSettingsSavePermissions(array $params = []): void
{
    bakeshopResponseGuard(static function (): void {
        bakeshopEnforceCsrf();
        bakeshopCurrentUser('bakeshop.manage');

        $input = bakeshopInput();
        $rolePermissions = bakeshopSaveRolePermissions($input['role_permissions'] ?? null);

        bakeshopJsonOk([
            'role_permissions' => $rolePermissions,
        ]);
    });
}

function bakeshopSaveDisplaySettings(array $input): array
{
    $storeName = bakeshopNormalizeStoreName($input['store_name'] ?? null);
    $storeDescription = bakeshopNormalizeStoreDescription($input['store_description'] ?? null);
    $storeLogoUrl = bakeshopNormalizeStoreLogoUrl($input['store_logo_url'] ?? null);
    $usageDecimalPlaces = bakeshopNormalizeUsageDecimalPlaces($input['usage_decimal_places'] ?? null);
    $printTemplate = bakeshopNormalizePrintTemplate($input['print_template'] ?? null);

    saveModuleSettings('bakeshop', [
        'store_name' => $storeName,
        'store_description' => $storeDescription,
        'store_logo_url' => $storeLogoUrl,
        'usage_decimal_places' => (string)$usageDecimalPlaces,
        'print_template' => $printTemplate,
    ]);

    return [
        'store_name' => $storeName,
        'store_description' => $storeDescription,
        'store_logo_url' => $storeLogoUrl,
        'usage_decimal_places' => $usageDecimalPlaces,
        'print_template' => $printTemplate,
    ];
}

function bakeshopApiSettingsSaveDisplay(array $params = []): void
{
    bakeshopResponseGuard(static function (): void {
        bakeshopEnforceCsrf();
        bakeshopCurrentUser('bakeshop.manage');

        $saved = bakeshopSaveDisplaySettings((array)bakeshopInput());

        bakeshopJsonOk($saved);
    });
}

function bakeshopApiSettingsUploadLogo(array $params = []): void
{
    bakeshopResponseGuard(static function (): void {
        bakeshopEnforceCsrf();
        bakeshopCurrentUser('bakeshop.manage');

        $uploaded = bakeshopStoreLogoUpload((array)(kernelUploadedFile('logo_file') ?? []));

        bakeshopJsonOk([
            'store_logo_url' => $uploaded['store_logo_url'],
            'width' => $uploaded['width'] ?? null,
            'height' => $uploaded['height'] ?? null,
            'normalized' => !empty($uploaded['normalized']),
            'message' => 'Store logo uploaded.',
        ]);
    });
}