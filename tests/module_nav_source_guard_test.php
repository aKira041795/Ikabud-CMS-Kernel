<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';

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

function navUrls(array $items): array
{
    return array_values(array_map(static fn(array $item): string => (string)($item['url'] ?? ''), $items));
}

echo "\n=== MODULE NAV SOURCE GUARD ===\n\n";

$previousUser = app()->user();

app()->setUser([
    'id' => 1,
    'role' => 'superadmin',
    'source' => 'kernel',
]);
$kernelSuperadminNav = getModuleNavItems();
$kernelSuperadminUrls = navUrls($kernelSuperadminNav);
t('kernel superadmin gets feature settings nav', in_array('/superadmin/settings', $kernelSuperadminUrls, true), implode(', ', $kernelSuperadminUrls));
t('kernel superadmin nav stays kernel-owned', count(array_filter($kernelSuperadminNav, static fn(array $item): bool => (string)($item['module'] ?? '') !== '_kernel')) === 0);

app()->setUser([
    'id' => 2,
    'role' => 'superadmin',
    'source' => 'cms',
]);
$cmsSuperadminNav = getModuleNavItems();
$cmsSuperadminUrls = navUrls($cmsSuperadminNav);
t('module superadmin does not inherit kernel superadmin nav', !in_array('/superadmin/settings', $cmsSuperadminUrls, true), implode(', ', $cmsSuperadminUrls));
t('explicit role lookup also honors current non-kernel source', !in_array('/superadmin/settings', navUrls(getModuleNavItems('superadmin')), true));

app()->setUser([
    'id' => 3,
    'role' => 'admin',
    'source' => 'bakeshop',
]);
$moduleAdminUrls = navUrls(getModuleNavItems());
t('module admin does not inherit kernel admin nav', !in_array('/admin/platform', $moduleAdminUrls, true), implode(', ', $moduleAdminUrls));

app()->setUser([
    'id' => 4,
    'role' => 'admin',
    'source' => 'kernel',
]);
$kernelAdminUrls = navUrls(getModuleNavItems());
t('kernel admin still gets platform nav', in_array('/admin/platform', $kernelAdminUrls, true), implode(', ', $kernelAdminUrls));

app()->setUser(is_array($previousUser) ? $previousUser : []);

echo "\n══════════════════════════════════════════════════\n";
echo "  PASS: {$pass}  FAIL: {$fail}\n";
echo "══════════════════════════════════════════════════\n";

if ($errors !== []) {
    echo "\nFailed tests:\n";
    foreach ($errors as $error) {
        echo "  - {$error}\n";
    }
    exit(1);
}

exit(0);