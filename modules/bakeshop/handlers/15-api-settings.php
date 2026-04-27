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
    $usageDecimalPlaces = bakeshopNormalizeUsageDecimalPlaces($input['usage_decimal_places'] ?? null);
    $printTemplate = bakeshopNormalizePrintTemplate($input['print_template'] ?? null);

    saveModuleSettings('bakeshop', [
        'usage_decimal_places' => (string)$usageDecimalPlaces,
        'print_template' => $printTemplate,
    ]);

    return [
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