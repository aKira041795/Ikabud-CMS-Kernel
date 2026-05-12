<?php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
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

t('tenant-safe kernel migrations include users token version', isset($artifacts['015_users_token_version.sql']));
t('tenant-safe kernel migrations include kernel password resets', isset($artifacts['019_kernel_password_resets.sql']));
t(
    'tenant-safe kernel migration file list mirrors canonical artifacts',
    $artifactNames === array_values(array_filter(array_keys($artifacts), static fn(string $artifact): bool => is_file((string)($artifacts[$artifact] ?? '')))),
    'kernel sync catalogs drifted'
);

if ($fail > 0) {
    exit(1);
}

echo "\nPassed: {$pass}, Failed: {$fail}\n";