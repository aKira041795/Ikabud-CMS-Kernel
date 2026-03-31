<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';

ob_start();

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

function clearLoginRateLimit(string $identifier): void
{
    app()->db()->prepare('DELETE FROM rate_limits WHERE identifier = :identifier AND action = :action')
        ->execute([':identifier' => $identifier, ':action' => 'login']);
}

file_put_contents(STORAGE_PATH . '/logs/app.log', '');
file_put_contents(STORAGE_PATH . '/logs/error.log', '');

$modules = discoverModules();
foreach (['guidance', 'daily-ledger'] as $moduleId) {
    if (isset($modules[$moduleId]) && is_array($modules[$moduleId])) {
        loadModuleHelpers($modules[$moduleId]);
    }
}

$originalServer = $_SERVER;

echo "\n=== SHARED LOGIN RATE LIMIT ===\n";

$_SERVER['REMOTE_ADDR'] = '198.51.100.44';
$kernelIdentifier = kernelLoginRateLimitIdentifier();
$dailyLedgerIdentifier = kernelLoginRateLimitIdentifier('daily-ledger');

clearLoginRateLimit($kernelIdentifier);
clearLoginRateLimit($dailyLedgerIdentifier);

t('module login rate limit identifiers are scoped by module', $kernelIdentifier !== $dailyLedgerIdentifier && str_contains($dailyLedgerIdentifier, 'module:daily-ledger'), $dailyLedgerIdentifier);

$limit = kernelLoginRateLimitMaxAttempts();
$lastDailyLedgerState = [];
for ($i = 0; $i < $limit; $i++) {
    $lastDailyLedgerState = kernelConsumeLoginRateLimit('daily-ledger');
}

t('shared login helper allows attempts up to the limit', empty($lastDailyLedgerState['limited']), json_encode($lastDailyLedgerState));

$blockedDailyLedgerState = kernelConsumeLoginRateLimit('daily-ledger');
t('shared login helper blocks module login after the limit', !empty($blockedDailyLedgerState['limited']) && (int)($blockedDailyLedgerState['retry_after'] ?? 0) > 0, json_encode($blockedDailyLedgerState));

$kernelState = kernelConsumeLoginRateLimit();
t('kernel login state is separate from module login state', empty($kernelState['limited']), json_encode($kernelState));

clearLoginRateLimit($kernelIdentifier);
clearLoginRateLimit($dailyLedgerIdentifier);

echo "\n=== MODULE LOGIN ROUTE GUARD ===\n";

$moduleHandlers = [
    'guidance' => 'guidance:guidanceAuthLogin',
    'daily-ledger' => 'daily-ledger:dailyLedgerAuthLogin',
];

foreach ($moduleHandlers as $moduleId => $handler) {
    $_SERVER = $originalServer;
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['REQUEST_URI'] = '/' . $moduleId . '/auth/login';
    $_SERVER['REMOTE_ADDR'] = '198.51.100.' . ($moduleId === 'guidance' ? '45' : '46');
    $_SERVER['HTTP_ACCEPT'] = 'application/json';
    http_response_code(200);

    $identifier = kernelLoginRateLimitIdentifier($moduleId);
    clearLoginRateLimit($identifier);
    for ($i = 0; $i < $limit; $i++) {
        kernelConsumeLoginRateLimit($moduleId);
    }

    ob_start();
    executeModuleHandler($handler);
    $output = (string)ob_get_clean();
    $status = http_response_code();

    t($moduleId . ' login route returns 429 when rate limited', $status === 429, 'status=' . $status . ' body=' . $output);
    t($moduleId . ' login route returns retry_after payload', str_contains($output, 'Too many login attempts') && str_contains($output, 'retry_after'), $output);

    clearLoginRateLimit($identifier);
}

$_SERVER = $originalServer;

echo "\n=== LOG CHECK ===\n";

$appLog = @file_get_contents(STORAGE_PATH . '/logs/app.log') ?: '';
$errLog = @file_get_contents(STORAGE_PATH . '/logs/error.log') ?: '';

$unexpectedAppErrors = array_values(array_filter(explode("\n", $appLog), static function (string $line): bool {
    if ($line === '') {
        return false;
    }

    if (str_contains($line, 'auth.login_rate_limited')) {
        return false;
    }

    return str_contains($line, '[error]') || str_contains($line, '[critical]');
}));

$errLines = array_values(array_filter(explode("\n", $errLog), static function (string $line): bool {
    return trim($line) !== '' && !str_contains($line, 'Ikabud Cache:');
}));

t('no unexpected app.log errors', empty($unexpectedAppErrors), implode('; ', array_slice($unexpectedAppErrors, 0, 3)));
t('no PHP errors in error.log', empty($errLines), implode('; ', array_slice($errLines, 0, 3)));

echo "\n══════════════════════════════════════════════════\n";
echo "  PASS: {$pass}  FAIL: {$fail}\n";
echo "══════════════════════════════════════════════════\n";

if ($errors !== []) {
    echo "\nFailed tests:\n";
    foreach ($errors as $error) {
        echo "  - {$error}\n";
    }
}

if (ob_get_level() > 0) {
    ob_end_flush();
}
