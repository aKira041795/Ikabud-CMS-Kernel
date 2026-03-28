<?php
/**
 * Media upload validation regression test.
 * Verifies the capability upload path reuses CMS validation rules for MIME,
 * dangerous signatures, and max_upload_mb enforcement.
 * Run: php tests/media_upload_validation_test.php
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../modules/cms/helpers.php';
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
        return;
    }

    $fail++;
    $errors[] = $label . ($detail !== '' ? ': ' . $detail : '');
    echo "  ✗ {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

file_put_contents(STORAGE_PATH . '/logs/app.log', '');
file_put_contents(STORAGE_PATH . '/logs/error.log', '');

$db = app()->db();
$uploaderId = (int)$db->query('SELECT id FROM cms_users ORDER BY id ASC LIMIT 1')->fetchColumn();
$oldSettings = getModuleSettings('cms');
$validationSettings = $oldSettings;
$validationSettings['max_upload_mb'] = '1';
saveModuleSettings('cms', $validationSettings);

$uploadedMediaIds = [];

try {
    t('test uploader exists', $uploaderId > 0);

    $valid = mediaUpload([
        'original_name' => 'spoofed-image.png',
        'mime_type' => 'image/png',
        'file_size' => 5,
        'uploaded_by' => $uploaderId,
        'contents_base64' => base64_encode('hello'),
    ]);

    t('capability upload accepts allowed text payload', !empty($valid['ok']), (string)($valid['error'] ?? ''));
    $validId = (int)($valid['id'] ?? 0);
    $uploadedMediaIds[] = $validId;
    t('capability upload returns media id', $validId > 0);
    t('capability upload stores detected MIME, not claimed payload MIME', (string)($valid['mime_type'] ?? '') === 'text/plain', json_encode($valid));

    $storedMime = '';
    if ($validId > 0) {
        $stmt = $db->prepare('SELECT mime_type FROM cms_media WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $validId]);
        $storedMime = (string)$stmt->fetchColumn();
    }
    t('database row stores detected MIME', $storedMime === 'text/plain', $storedMime);

    $danger = mediaUpload([
        'original_name' => 'dangerous.svg',
        'mime_type' => 'image/svg+xml',
        'file_size' => 96,
        'uploaded_by' => $uploaderId,
        'contents_base64' => base64_encode('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script><rect width="10" height="10"/></svg>'),
    ]);
    t('dangerous signature payload is rejected', empty($danger['ok']), json_encode($danger));
    t('dangerous signature payload returns validation message', str_contains((string)($danger['error'] ?? ''), 'dangerous content'), (string)($danger['error'] ?? ''));

    $oversizeRaw = str_repeat('A', (1024 * 1024) + 1);
    $oversize = mediaUpload([
        'original_name' => 'oversize.txt',
        'mime_type' => 'text/plain',
        'file_size' => strlen($oversizeRaw),
        'uploaded_by' => $uploaderId,
        'contents_base64' => base64_encode($oversizeRaw),
    ]);
    t('oversize payload is rejected using max_upload_mb', empty($oversize['ok']), json_encode($oversize));
    t('oversize payload returns settings-based limit message', (string)($oversize['error'] ?? '') === 'File exceeds 1MB limit', (string)($oversize['error'] ?? ''));
} finally {
    foreach ($uploadedMediaIds as $mediaId) {
        if ($mediaId > 0) {
            mediaDelete($mediaId);
        }
    }

    saveModuleSettings('cms', $oldSettings);
}

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