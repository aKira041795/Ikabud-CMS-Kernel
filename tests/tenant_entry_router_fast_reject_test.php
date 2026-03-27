<?php
/**
 * TenantEntryRouter fast-reject regression test.
 * Verifies common probe paths are blocked before tenant entry-module rewrites.
 * Run: php tests/tenant_entry_router_fast_reject_test.php
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../kernel/Http/TenantEntryRouter.php';

$pass = 0;
$fail = 0;
$errors = [];

function t(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $errors;
    if ($ok) {
        $pass++;
        echo "  ✓ {$label}\n";
    } else {
        $fail++;
        $errors[] = $label . ($detail !== '' ? ': ' . $detail : '');
        echo "  ✗ {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

file_put_contents(STORAGE_PATH . '/logs/app.log', '');
file_put_contents(STORAGE_PATH . '/logs/error.log', '');

$router = new \Ikabud\Kernel\Http\TenantEntryRouter();
$method = new ReflectionMethod(\Ikabud\Kernel\Http\TenantEntryRouter::class, 'shouldFastReject');
$method->setAccessible(true);

echo "\n=== BLOCKED PROBES ===\n";
t('wp-login.php is fast rejected', $method->invoke($router, '/wp-login.php') === true);
t('wp-admin segment is fast rejected', $method->invoke($router, '/wp-admin/install.php') === true);
t('.git traversal is fast rejected', $method->invoke($router, '/foo/.git/config') === true);
t('backup extension is fast rejected', $method->invoke($router, '/dump.sql') === true);
t('trace.axd is fast rejected', $method->invoke($router, '/trace.axd') === true);

echo "\n=== ALLOWLISTED PATHS ===\n";
t('root path is not fast rejected', $method->invoke($router, '/') === false);
t('robots.txt is not fast rejected', $method->invoke($router, '/robots.txt') === false);
t('favicon.ico is not fast rejected', $method->invoke($router, '/favicon.ico') === false);
t('apple touch icon is not fast rejected', $method->invoke($router, '/apple-touch-icon.png') === false);
t('well-known paths are not fast rejected', $method->invoke($router, '/.well-known/security.txt') === false);

echo "\n=== LOG CHECK ===\n";
$appLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/app.log'));
$errorLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/error.log'));
t('No app.log errors', $appLog === '', $appLog !== '' ? substr($appLog, 0, 200) : '');
t('No PHP errors in error.log', $errorLog === '', $errorLog !== '' ? substr($errorLog, 0, 200) : '');

echo "\n════════════════════════════════════════════\n";
echo "  Results: {$pass} passed, {$fail} failed\n";
if ($fail > 0) {
    echo "\n  Failures:\n";
    foreach ($errors as $error) {
        echo "    ✗ {$error}\n";
    }
}
echo "════════════════════════════════════════════\n\n";

exit($fail > 0 ? 1 : 0);