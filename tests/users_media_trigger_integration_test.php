<?php
/**
 * Users/Media module integration with kernel triggers.
 *
 * Verifies:
 * - users.created event emitted by users.create@1 triggers capability trigger
 * - media.uploaded and media.deleted events emitted by media.* capabilities trigger capability triggers
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../kernel/EventTriggers.php';
require_once __DIR__ . '/../modules/cms/helpers.php';
require_once __DIR__ . '/../modules/users/helpers.php';
require_once __DIR__ . '/../modules/media/helpers.php';

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

$triggerFile = STORAGE_PATH . '/cache/trigger_capture.jsonl';
@unlink($triggerFile);

function test_capture_capability(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    $file = STORAGE_PATH . '/cache/trigger_capture.jsonl';
    $row = [
        'capability_id' => $capabilityId,
        'provider_id' => $providerId,
        'payload' => $payload,
        'ts' => microtime(true),
    ];
    @file_put_contents($file, json_encode($row, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
    return ['ok' => true];
}

try {
    app()->capabilities()->register('test.capture@1', 'tests', 'test_capture_capability', 1, ['first']);
} catch (Throwable $e) {
}

$db = app()->db();

// Ensure clean triggers for this test
$db->prepare("DELETE FROM kernel_event_triggers WHERE capability_id = 'test.capture@1' AND event_key IN ('users.created','media.uploaded','media.deleted')")
   ->execute();

// Register triggers
$okUsers = kernelTriggerSave('users', 'users.created', 'test.capture@1', true, null, ['marker' => 'users'], null, 1, null, 0, 5000, null);
t('kernelTriggerSave users.created', $okUsers);

$okUp = kernelTriggerSave('media', 'media.uploaded', 'test.capture@1', true, null, ['marker' => 'media.uploaded'], null, 1, null, 0, 5000, null);
t('kernelTriggerSave media.uploaded', $okUp);

$okDel = kernelTriggerSave('media', 'media.deleted', 'test.capture@1', true, null, ['marker' => 'media.deleted'], null, 1, null, 0, 5000, null);
t('kernelTriggerSave media.deleted', $okDel);

// 1) Create a user via capability (should emit users.created)
$username = 'u' . bin2hex(random_bytes(4));
$email = $username . '@example.com';
$uRes = app()->cap()->call('users.create@1', [
    'username' => $username,
    'email' => $email,
    'password' => 'password123',
    'display_name' => 'Test User',
    'role' => 'subscriber',
], ['caller_module' => 'cms']);

t('users.create@1 ok', !empty($uRes['ok']), is_array($uRes) ? (string)($uRes['error'] ?? '') : '');
$userId = (int)($uRes['id'] ?? 0);
t('users.create@1 returns id', $userId > 0);

// 2) Upload media via capability (should emit media.uploaded)
$payload = [
    'original_name' => 'test.txt',
    'mime_type' => 'text/plain',
    'file_size' => 5,
    'uploaded_by' => 1,
    'contents_base64' => base64_encode('hello'),
];
$mRes = app()->cap()->call('media.upload@1', $payload, ['caller_module' => 'cms']);

t('media.upload@1 ok', !empty($mRes['ok']), is_array($mRes) ? (string)($mRes['error'] ?? '') : '');
$mediaId = (int)($mRes['id'] ?? 0);
t('media.upload@1 returns id', $mediaId > 0);

// 3) Delete media via capability (should emit media.deleted)
$dRes = app()->cap()->call('media.delete@1', ['id' => $mediaId], ['caller_module' => 'cms']);
t('media.delete@1 ok', !empty($dRes['ok']), is_array($dRes) ? (string)($dRes['error'] ?? '') : '');

// Validate capture file has all trigger events
$lines = is_file($triggerFile) ? file($triggerFile, FILE_IGNORE_NEW_LINES) : [];
$events = [];
foreach ($lines as $ln) {
    $row = json_decode((string)$ln, true);
    if (!is_array($row)) continue;
    $p = $row['payload'] ?? null;
    if (!is_array($p)) continue;
    $events[] = (string)($p['trigger_event'] ?? '');
}

t('trigger capture file exists', is_file($triggerFile));
t('users.created trigger fired', in_array('users.created', $events, true));
t('media.uploaded trigger fired', in_array('media.uploaded', $events, true));
t('media.deleted trigger fired', in_array('media.deleted', $events, true));

// Cleanup: remove created user and triggers
try {
    $db->prepare('DELETE FROM cms_users WHERE id = :id')->execute([':id' => $userId]);
} catch (Throwable $e) {
}
try {
    $db->prepare("DELETE FROM kernel_event_triggers WHERE capability_id = 'test.capture@1' AND event_key IN ('users.created','media.uploaded','media.deleted')")
       ->execute();
} catch (Throwable $e) {
}

$appLog = @file_get_contents(STORAGE_PATH . '/logs/app.log') ?: '';
$errLog = @file_get_contents(STORAGE_PATH . '/logs/error.log') ?: '';

$appErrors = array_filter(explode("\n", $appLog), fn($l) => str_contains($l, '[critical]'));
t('No app.log critical errors', empty($appErrors), implode('; ', $appErrors));

t('No PHP errors in error.log', trim($errLog) === '', trim($errLog));

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
