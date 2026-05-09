<?php
declare(strict_types=1);

$registryPath = __DIR__ . '/../storage/modules.json';
$originalRegistry = is_file($registryPath) ? (string)file_get_contents($registryPath) : null;
$registry = [];
if ($originalRegistry !== null && $originalRegistry !== '') {
    $decoded = json_decode($originalRegistry, true);
    if (is_array($decoded)) {
        $registry = $decoded;
    }
}

$moduleIds = [
    'ehr',
    'ehr-core',
    'patient-registry',
    'encounters',
    'clinical-notes',
    'orders',
    'results',
    'prescriptions',
    'documents',
    'privacy-consent',
    'scheduling',
    'audit',
    'reporting',
    'billing-bridge',
    'cms',
];

foreach ($moduleIds as $moduleId) {
    $entry = $registry[$moduleId] ?? [];
    if (!is_array($entry)) {
        $entry = [];
    }
    $entry['enabled'] = true;
    $registry[$moduleId] = $entry;
}

$dir = dirname($registryPath);
if (!is_dir($dir)) {
    @mkdir($dir, 0775, true);
}
file_put_contents($registryPath, json_encode($registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);

register_shutdown_function(static function () use ($registryPath, $originalRegistry): void {
    if ($originalRegistry === null) {
        @unlink($registryPath);
        return;
    }

    file_put_contents($registryPath, $originalRegistry, LOCK_EX);
});

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-registry.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../src/helpers/module-migrations.php';
require_once __DIR__ . '/../src/http/tenant-entry-modules.php';

$pass = 0;
$fail = 0;
$errors = [];

function t(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $errors;
    if ($ok) {
        $pass++;
        echo "  ✓ {$label}\n";
        return;
    }

    $fail++;
    $errors[] = $label . ($detail !== '' ? ': ' . $detail : '');
    echo "  ✗ {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

echo "=== EHR Tenant Provisioning Plan ===\n\n";

$ehrPlan = tenantProvisionModulePlan('ehr');
$legacyEhrPlan = tenantProvisionModulePlan('ehr-core');
$cmsPlan = tenantProvisionModulePlan('cms');
$entryOptions = listTenantEntryModuleOptions();
$ehrOption = null;
foreach ($entryOptions as $option) {
    if ((string)($option['id'] ?? '') === 'ehr') {
        $ehrOption = $option;
        break;
    }
}

$expectedEhrModules = [
    'patient-registry',
    'encounters',
    'clinical-notes',
    'orders',
    'results',
    'prescriptions',
    'documents',
    'privacy-consent',
    'scheduling',
];

t('ehr plan includes module-owned auth surface', in_array('ehr', $ehrPlan, true), json_encode($ehrPlan));
t('ehr plan includes migratable EHR bundle modules', count(array_diff($expectedEhrModules, $ehrPlan)) === 0, json_encode($ehrPlan));
t('legacy ehr-core plan aliases to include ehr auth module', in_array('ehr', $legacyEhrPlan, true), json_encode($legacyEhrPlan));
t('ehr plan includes reporting bundle members when seeded', in_array('reporting', tenantProvisionEntryBundleModules('ehr'), true) && in_array('billing-bridge', tenantProvisionEntryBundleModules('ehr'), true), json_encode(tenantProvisionEntryBundleModules('ehr')));
t('cms plan still includes cms', in_array('cms', $cmsPlan, true), json_encode($cmsPlan));
t('cms plan does not pull ehr from nav hook declarations', !in_array('ehr', $cmsPlan, true), json_encode($cmsPlan));
t('tenant entry option exposes ehr as suite', is_array($ehrOption) && (string)($ehrOption['name'] ?? '') === 'EHR Suite', is_array($ehrOption) ? json_encode($ehrOption) : 'missing');
t('legacy ehr-core entry normalizes to ehr', (normalizeTenantEntryModuleId('ehr-core')['value'] ?? null) === 'ehr', json_encode(normalizeTenantEntryModuleId('ehr-core')));

echo "\n{$pass} passed, {$fail} failed\n";
if ($errors !== []) {
    echo "Failures:\n";
    foreach ($errors as $error) {
        echo "  - {$error}\n";
    }
}

exit($fail > 0 ? 1 : 0);