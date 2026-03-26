<?php

declare(strict_types=1);

$_SERVER['HTTP_HOST'] = 'cmsnew.test';
$_SERVER['REQUEST_URI'] = '/ecommerce/admin/settings';

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../modules/cms/helpers.php';
require_once __DIR__ . '/../modules/ecommerce/helpers.php';
require_once __DIR__ . '/../modules/ecommerce/handlers/00-bootstrap.php';
require_once __DIR__ . '/../modules/ecommerce/handlers/50-admin-settings.php';

$tenantId = 1;
$settings = getModuleSettingsForTenant('ecommerce', $tenantId);
$manifest = discoverModules()['ecommerce'] ?? [];
$manifestFields = moduleEditableSettingsFields($manifest);
$renderedFields = ecAdminSettingsFields();
$resolvedSettings = ecSettings();

$manifestKeys = array_values(array_filter(array_map(
    static fn(array $field): string => trim((string)($field['key'] ?? '')),
    array_filter($manifestFields, 'is_array')
)));
$renderedKeys = array_values(array_filter(array_map(
    static fn(array $field): string => trim((string)($field['key'] ?? '')),
    array_filter($renderedFields, 'is_array')
)));
$resolvedKeys = [];
foreach ($manifestKeys as $key) {
    if (array_key_exists($key, $resolvedSettings)) {
        $resolvedKeys[] = $key;
    }
}

$result = [
    'tenant_currency' => $settings['currency'] ?? null,
    'tenant_currency_symbol' => $settings['currency_symbol'] ?? null,
    'ec_settings_currency' => ecSettings('currency'),
    'ec_settings_currency_symbol' => ecSettings('currency_symbol'),
    'currency_matches' => ecSettings('currency') === ($settings['currency'] ?? null),
    'currency_symbol_matches' => ecSettings('currency_symbol') === ($settings['currency_symbol'] ?? null),
    'manifest_field_count' => count($manifestKeys),
    'rendered_field_count' => count($renderedKeys),
    'resolved_settings_field_count' => count($resolvedKeys),
    'missing_from_admin_form' => array_values(array_diff($manifestKeys, $renderedKeys)),
    'extra_in_admin_form' => array_values(array_diff($renderedKeys, $manifestKeys)),
    'missing_from_resolved_settings' => array_values(array_diff($manifestKeys, $resolvedKeys)),
];

echo json_encode($result, JSON_PRETTY_PRINT), PHP_EOL;