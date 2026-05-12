<?php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../src/helpers/module-migrations.php';

$pass = 0;
$fail = 0;

function t(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;

    if ($ok) {
        $pass++;
        echo "  ✓ {$label}\n";
        return;
    }

    $fail++;
    echo "  ✗ {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

$artifacts = tenantSafeKernelMigrationArtifacts();
$artifactNames = tenantSafeKernelMigrationFiles();
$wmsArtifacts = tenantSafeKernelMigrationArtifacts('wms');
$wmsArtifactNames = tenantSafeKernelMigrationFiles('wms');
$cmsArtifacts = tenantSafeKernelMigrationArtifacts('cms');

t('tenant-safe kernel migrations include users token version', isset($artifacts['015_users_token_version.sql']));
t('tenant-safe kernel migrations include kernel password resets', isset($artifacts['019_kernel_password_resets.sql']));
t(
    'tenant-safe kernel migration file list mirrors canonical artifacts',
    $artifactNames === array_values(array_filter(array_keys($artifacts), static fn(string $artifact): bool => is_file((string)($artifacts[$artifact] ?? '')))),
    'kernel sync catalogs drifted'
);
t('auth-owned WMS tenant-safe kernel migrations exclude users token version', !isset($wmsArtifacts['015_users_token_version.sql']));
t('auth-owned WMS tenant-safe kernel migrations exclude kernel password resets', !isset($wmsArtifacts['019_kernel_password_resets.sql']));
t('auth-owned WMS tenant-safe kernel migrations keep runtime tables', isset($wmsArtifacts['007_kernel_runtime_tables.sql']));
t(
    'auth-owned WMS tenant-safe file list mirrors filtered artifacts',
    $wmsArtifactNames === array_values(array_filter(array_keys($wmsArtifacts), static fn(string $artifact): bool => is_file((string)($wmsArtifacts[$artifact] ?? '')))),
    'wms kernel sync catalogs drifted'
);
t('auth-owned CMS tenant-safe kernel migrations exclude users token version', !isset($cmsArtifacts['015_users_token_version.sql']));
t('auth-owned CMS tenant-safe kernel migrations exclude kernel password resets', !isset($cmsArtifacts['019_kernel_password_resets.sql']));

if ($fail > 0) {
    exit(1);
}

echo "\nPassed: {$pass}, Failed: {$fail}\n";