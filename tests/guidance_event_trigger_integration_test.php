<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../kernel/EventTriggers.php';
require_once __DIR__ . '/../modules/guidance/helpers.php';

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

$captureFile = STORAGE_PATH . '/cache/guidance_trigger_capture.jsonl';
@unlink($captureFile);

function test_guidance_capture_capability(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    $file = STORAGE_PATH . '/cache/guidance_trigger_capture.jsonl';
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
    app()->capabilities()->register('test.guidance.capture@1', 'tests', 'test_guidance_capture_capability', 1, ['first']);
} catch (Throwable $e) {
}

$db = app()->db();
$db->prepare("DELETE FROM kernel_event_triggers WHERE capability_id = 'test.guidance.capture@1' AND event_key = 'guidance.booking.created'")
    ->execute();

$db->prepare(
    'INSERT INTO kernel_event_triggers '
    . '(module, event_key, capability_id, provider, is_enabled, priority, template, max_per_minute, retry_count, timeout_ms, meta, created_at, updated_at) '
    . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
)->execute([
    'guidance',
    'guidance.booking.created',
    'test.guidance.capture@1',
    'tests',
    1,
    1,
    null,
    null,
    0,
    5000,
    json_encode(['marker' => 'guidance.booking.created'], JSON_UNESCAPED_SLASHES),
]);

guidanceFireEvent('guidance.booking.created', [
    'appointment_id' => 4242,
    'student_name' => 'Trigger Test Student',
    'student_email' => 'trigger-test@example.com',
    'student_phone' => '09171234567',
    'trigger_ref_id' => '4242',
]);

$lines = is_file($captureFile) ? file($captureFile, FILE_IGNORE_NEW_LINES) : [];
$rows = [];
foreach ($lines as $line) {
    $decoded = json_decode((string)$line, true);
    if (is_array($decoded)) {
        $rows[] = $decoded;
    }
}

$payloads = array_map(static fn(array $row): array => is_array($row['payload'] ?? null) ? $row['payload'] : [], $rows);
$events = array_values(array_filter(array_map(static fn(array $payload): string => (string)($payload['trigger_event'] ?? ''), $payloads)));
$matchingPayload = null;
foreach ($payloads as $payload) {
    if (($payload['trigger_event'] ?? '') === 'guidance.booking.created') {
        $matchingPayload = $payload;
        break;
    }
}

t('guidance trigger capture file exists', is_file($captureFile));
t('guidance.booking.created trigger fired', in_array('guidance.booking.created', $events, true));
t(
    'guidance trigger payload carries appointment id',
    is_array($matchingPayload) && (string)($matchingPayload['appointment_id'] ?? '') === '4242',
    json_encode($matchingPayload, JSON_UNESCAPED_SLASHES)
);
t(
    'guidance trigger payload carries student email',
    is_array($matchingPayload) && (string)($matchingPayload['student_email'] ?? '') === 'trigger-test@example.com',
    json_encode($matchingPayload, JSON_UNESCAPED_SLASHES)
);

try {
    $db->prepare("DELETE FROM kernel_event_triggers WHERE capability_id = 'test.guidance.capture@1' AND event_key = 'guidance.booking.created'")
        ->execute();
} catch (Throwable $e) {
}

$appLog = @file_get_contents(STORAGE_PATH . '/logs/app.log') ?: '';
$errorLog = @file_get_contents(STORAGE_PATH . '/logs/error.log') ?: '';
$appErrors = array_filter(explode("\n", $appLog), static fn(string $line): bool => str_contains($line, '[critical]'));

t('guidance trigger test leaves app.log free of critical errors', empty($appErrors), implode('; ', $appErrors));
t('guidance trigger test leaves error.log empty', trim($errorLog) === '', trim($errorLog));

echo "\n══════════════════════════════════════════════════\n";
echo "  PASS: {$pass}  FAIL: {$fail}\n";
echo "══════════════════════════════════════════════════\n";

if ($errors !== []) {
    echo "\nFailed tests:\n";
    foreach ($errors as $error) {
        echo "  - {$error}\n";
    }
}

exit($fail > 0 ? 1 : 0);