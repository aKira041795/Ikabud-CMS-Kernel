<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../kernel/EventTriggers.php';
require_once __DIR__ . '/../modules/users/helpers.php';

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
        $errors[] = $label . ($detail !== '' ? ": {$detail}" : '');
        echo "  ✗ {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

file_put_contents(STORAGE_PATH . '/logs/app.log', '');
file_put_contents(STORAGE_PATH . '/logs/error.log', '');

try {
    $db = app()->db();
    $db->prepare("DELETE FROM kernel_event_triggers WHERE capability_id = 'users.create@1' AND event_key = 'users.created'")->execute();
    $db->prepare("DELETE FROM kernel_events WHERE event_key = 'users.created'")->execute();
} catch (Throwable $e) {
}

kernelRegisterModuleEvents('users', [[
    'key' => 'users.created',
    'description' => 'User created',
    'available_vars' => ['id', 'username', 'email'],
]]);

$invalid = kernelTriggerSave('users', 'users.created', 'users.create@1', true, '{missing_var}', ['username' => 'clone', 'email' => 'clone@example.com', 'password' => 'password123']);
t('invalid trigger placeholder rejected', $invalid === false);

$valid = kernelTriggerSave('users', 'users.created', 'users.create@1', true, null, [
    'username' => 'copy_user',
    'email' => 'copy_user@example.com',
    'password' => 'password123',
]);
t('valid trigger saved', $valid === true);

try {
    $db = app()->db();
    $db->prepare("DELETE FROM kernel_event_triggers WHERE capability_id = 'users.create@1' AND event_key = 'users.created'")->execute();
} catch (Throwable $e) {
}

$appLog = @file_get_contents(STORAGE_PATH . '/logs/app.log') ?: '';
$errLog = @file_get_contents(STORAGE_PATH . '/logs/error.log') ?: '';

t('warning logged for invalid trigger', str_contains($appLog, 'kernelTriggerSave rejected invalid trigger'));
t('no PHP errors in error.log', trim($errLog) === '', trim($errLog));

echo "\n══════════════════════════════════════════════════\n";
echo "  PASS: {$pass}  FAIL: {$fail}\n";
echo "══════════════════════════════════════════════════\n";

if (!empty($errors)) {
    echo "\nFailed tests:\n";
    foreach ($errors as $e) {
        echo "  - {$e}\n";
    }
}

exit($fail > 0 ? 1 : 0);
