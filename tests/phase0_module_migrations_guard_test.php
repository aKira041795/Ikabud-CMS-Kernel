<?php
/**
 * Phase 0 — module-migrations.php declaration guard.
 *
 * The helper file is sometimes loaded more than once (different include paths,
 * tooling require + bootstrap require_once). Declaring
 * tenantRejectBaseDbConnection() without a function_exists() guard turns the
 * second load into a hard fatal.
 *
 * Run: php tests/phase0_module_migrations_guard_test.php
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

$pass = 0;
$fail = 0;

function t(string $label, bool $ok): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  ✓ {$label}\n";
    } else {
        $fail++;
        echo "  ✗ {$label}\n";
    }
}

echo "=== Phase 0: module-migrations guard ===\n";

require_once __DIR__ . '/../src/helpers/module-migrations.php';

$source = (string) file_get_contents(__DIR__ . '/../src/helpers/module-migrations.php');

t(
    'tenantRejectBaseDbConnection is guarded by function_exists',
    preg_match(
        "/if\\s*\\(\\s*!function_exists\\(\\s*'tenantRejectBaseDbConnection'\\s*\\)\\s*\\)\\s*\\{/",
        $source
    ) === 1
);

t('tenantRejectBaseDbConnection is defined after load', function_exists('tenantRejectBaseDbConnection'));

t('guard is a single wrapper around the declaration', substr_count(
    $source,
    "function_exists('tenantRejectBaseDbConnection')"
) === 1);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
