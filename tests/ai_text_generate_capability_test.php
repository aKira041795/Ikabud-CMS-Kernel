<?php
/**
 * AI text generation capability test (offline-safe)
 * Run: php tests/ai_text_generate_capability_test.php
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../modules/ai/helpers.php';

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
        $errors[] = $label . ($detail ? ": {$detail}" : '');
        echo "  ✗ {$label}" . ($detail ? " — {$detail}" : '') . "\n";
    }
}

file_put_contents(STORAGE_PATH . '/logs/app.log', '');
file_put_contents(STORAGE_PATH . '/logs/error.log', '');

$res = app()->cap()->call('ai.text.generate@1', [
    'messages' => [
        ['role' => 'system', 'content' => 'Return plain text OK'],
        ['role' => 'user', 'content' => 'Say OK'],
    ],
    'temperature' => 0.2,
    'json' => false,
], ['caller_module' => 'cms', 'caller_user' => ['id'=>1,'role'=>'superadmin','source'=>'cms']]);

// We don't require ok=true because this may be offline (no API key).
// We do require that the capability handler is registered and returns an array with ok/error.
t('ai.text.generate returns array', is_array($res));
t('ai.text.generate has ok field', is_array($res) && array_key_exists('ok', $res));
if (empty($res['ok'])) {
    t('ai.text.generate has error when not ok', array_key_exists('error', $res) && trim((string)$res['error']) !== '');
} else {
    t('ai.text.generate has content when ok', array_key_exists('content', $res) && trim((string)$res['content']) !== '');
}

$appLog = @file_get_contents(STORAGE_PATH . '/logs/app.log') ?: '';
$errLog = @file_get_contents(STORAGE_PATH . '/logs/error.log') ?: '';

$appErrors = array_filter(explode("\n", $appLog), fn($l) => str_contains($l, '[critical]'));
t('No app.log critical errors', empty($appErrors), implode('; ', $appErrors));

$errLines = array_filter(explode("\n", $errLog), function ($l) {
    $l = trim($l);
    if ($l === '') return false;
    if (str_contains($l, 'Ikabud Cache:')) return false;
    return true;
});

t('No PHP errors in error.log', empty($errLines), implode('; ', array_slice($errLines, 0, 3)));

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
