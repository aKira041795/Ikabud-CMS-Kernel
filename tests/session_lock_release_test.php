<?php
/**
 * Session lock release regression test.
 * Verifies the shared helper closes an active session lock and that the CMS
 * post-response handlers invoke it before finish_response_if_possible().
 * Run: php tests/session_lock_release_test.php
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

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

file_put_contents(STORAGE_PATH . '/logs/app.log', '');
file_put_contents(STORAGE_PATH . '/logs/error.log', '');

session_start();
$_SESSION['session_lock_release_test'] = 'ok';
$released = release_session_lock_if_active();

t('release_session_lock_if_active returns true for active session', $released === true);
t('release_session_lock_if_active closes the active session lock', session_status() === PHP_SESSION_NONE, (string)session_status());

$settingsHandler = file_get_contents(__DIR__ . '/../modules/cms/handlers/50-api-settings.php') ?: '';
$customizerHandler = file_get_contents(__DIR__ . '/../modules/cms/handlers/80-customizer.php') ?: '';

$settingsPattern = '/echo\s+\$response;\s*release_session_lock_if_active\(\);\s*finish_response_if_possible\(\);/s';
$customizerPattern = '/echo\s+\$response;\s*release_session_lock_if_active\(\);\s*finish_response_if_possible\(\);/s';

t('settings save handler releases session before background work', preg_match($settingsPattern, $settingsHandler) === 1);
t('customizer save handler releases session before background work', preg_match($customizerPattern, $customizerHandler) === 1);

$appLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/app.log'));
$errorLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/error.log'));
t('No app.log critical errors', !str_contains($appLog, '[critical]'), $appLog !== '' ? substr($appLog, 0, 200) : '');
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