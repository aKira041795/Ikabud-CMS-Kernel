<?php

declare(strict_types=1);

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'cmsnew.test';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/admin/bakeshop/settings';

require __DIR__ . '/../bootstrap.php';

$pass = 0;
$fail = 0;
$errors = [];

function krt(string $label, bool $ok, string $detail = ''): void
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

$appLogPath = STORAGE_PATH . '/logs/app.log';
$errorLogPath = STORAGE_PATH . '/logs/error.log';
@file_put_contents($appLogPath, '');
@file_put_contents($errorLogPath, '');

echo "\n=== KERNEL DB RUNTIME SNAPSHOT TEST ===\n\n";

app()->reconnectDb();
app()->reconnectControlDb();
$snapshot = app()->dbRuntimeSnapshot();

$policy = is_array($snapshot['policy'] ?? null) ? $snapshot['policy'] : [];
$primaryPolicy = is_array($snapshot['primary_policy'] ?? null) ? $snapshot['primary_policy'] : [];
$controlPolicy = is_array($snapshot['control_policy'] ?? null) ? $snapshot['control_policy'] : [];
$counters = is_array($snapshot['counters'] ?? null) ? $snapshot['counters'] : [];
$tenantConfigCache = is_array($snapshot['tenant_config_cache'] ?? null) ? $snapshot['tenant_config_cache'] : [];

krt('runtime snapshot exposes policy payload', $policy !== [], json_encode($snapshot));
krt('runtime snapshot exposes primary and control policy payloads', $primaryPolicy !== [] && $controlPolicy !== [], json_encode($snapshot));
krt('runtime snapshot keeps policy alias aligned with primary policy', $policy === $primaryPolicy, json_encode(['policy' => $policy, 'primary_policy' => $primaryPolicy]));
krt('runtime snapshot keeps native prepares enforced', ($policy['emulate_prepares'] ?? true) === false, json_encode($policy));
krt('runtime snapshot keeps stringify fetches disabled', ($policy['stringify_fetches'] ?? true) === false, json_encode($policy));
krt('runtime snapshot exposes configured timeout', (int)($policy['timeout_seconds'] ?? 0) === (int)app()->config('database.timeout_seconds', 0), json_encode($policy));
krt('runtime snapshot exposes configured control timeout', (int)($controlPolicy['timeout_seconds'] ?? 0) === (int)app()->config('control_database.timeout_seconds', 0), json_encode($controlPolicy));
krt('runtime snapshot exposes tenant config cache backend and ttl', ($tenantConfigCache['backend'] ?? '') !== '' && (int)($tenantConfigCache['ttl_seconds'] ?? 0) >= 1, json_encode($tenantConfigCache));
krt('runtime snapshot counts primary reconnects', (int)($counters['primary_reconnects'] ?? 0) >= 1, json_encode($counters));
krt('runtime snapshot counts control reconnects', (int)($counters['control_reconnects'] ?? 0) >= 1, json_encode($counters));
krt('runtime snapshot counts primary and control connects', (int)($counters['primary_connects'] ?? 0) >= 1 && (int)($counters['control_connects'] ?? 0) >= 1, json_encode($counters));

$appLog = is_file($appLogPath) ? (string)file_get_contents($appLogPath) : '';
$errorLog = is_file($errorLogPath) ? (string)file_get_contents($errorLogPath) : '';
krt('no app.log errors', trim($appLog) === '', trim($appLog));
krt('no error.log errors', trim($errorLog) === '', trim($errorLog));

echo "\n──────────────────────────────────────────────────\n";
echo "  Result: {$pass} passed, {$fail} failed\n";

if ($fail > 0) {
    echo "\nFailures:\n";
    foreach ($errors as $error) {
        echo " - {$error}\n";
    }
    exit(1);
}

exit(0);