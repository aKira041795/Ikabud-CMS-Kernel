<?php

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

require __DIR__ . '/../bootstrap.php';
require SRC_PATH . '/helpers/module-manager.php';

$helpersPath = BASE_PATH . '/modules/anti-spam/helpers.php';
if (!is_file($helpersPath)) {
    fwrite(STDERR, "anti-spam helpers not found at {$helpersPath}\n");
    exit(1);
}
require_once $helpersPath;

$options = [
    'tenant' => null,
    'dry-run' => false,
];

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run') {
        $options['dry-run'] = true;
        continue;
    }
    if (str_starts_with($arg, '--tenant=')) {
        $tenantId = (int) substr($arg, strlen('--tenant='));
        $options['tenant'] = $tenantId > 0 ? $tenantId : null;
    }
}

$legacy = antispamReadLegacySettings();
if ($legacy === []) {
    fwrite(STDOUT, "No legacy anti-spam settings found.\n");
    exit(0);
}

$normalized = [];
foreach ($legacy as $key => $value) {
    $settingKey = trim((string) $key);
    if ($settingKey === '' || str_starts_with($settingKey, '_')) {
        continue;
    }
    $normalized[$settingKey] = antispamNormalizeSettingValue($settingKey, $value);
}

if ($normalized === []) {
    fwrite(STDOUT, "Legacy anti-spam settings contained no syncable keys.\n");
    exit(0);
}

$isTenantMode = moduleTenantSettingsModeEnabled();
$tenantId = $options['tenant'];

if ($isTenantMode && ($tenantId === null || $tenantId <= 0)) {
    fwrite(STDERR, "Tenant mode is enabled. Re-run with --tenant=<id> to sync anti-spam settings for a tenant.\n");
    exit(1);
}

if ($options['dry-run']) {
    $target = $isTenantMode ? 'tenant ' . $tenantId : 'global module registry';
    fwrite(STDOUT, "Dry run: would sync anti-spam settings to {$target}:\n");
    foreach ($normalized as $key => $value) {
        fwrite(STDOUT, " - {$key}={$value}\n");
    }
    exit(0);
}

if ($isTenantMode) {
    $ok = saveTenantModuleSettingsForTenant('anti-spam', (int) $tenantId, $normalized);
    if (!$ok) {
        fwrite(STDERR, "Failed to sync anti-spam settings for tenant {$tenantId}.\n");
        exit(1);
    }
    fwrite(STDOUT, "Synced anti-spam settings for tenant {$tenantId}.\n");
} else {
    saveModuleSettings('anti-spam', $normalized);
    fwrite(STDOUT, "Synced anti-spam settings to the global module registry.\n");
}

foreach ($normalized as $key => $value) {
    fwrite(STDOUT, " - {$key}={$value}\n");
}