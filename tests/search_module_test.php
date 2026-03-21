<?php
/**
 * Search Module Test
 * Run: php tests/search_module_test.php
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../modules/cms/helpers.php';
require_once __DIR__ . '/../modules/cms/handlers.php';
require_once __DIR__ . '/../modules/search/helpers.php';

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

$db = app()->db();

// Create published CMS post
$title = 'Search Test ' . bin2hex(random_bytes(3));
$slug = strtolower(str_replace(' ', '-', $title));
$stmt = $db->prepare(
    "INSERT INTO cms_content (uuid, title, slug, body, excerpt, type, status, author_id, comment_status, published_at, created_at)\n     VALUES (:uuid, :title, :slug, :body, :excerpt, 'post', 'published', 1, 'open', NOW(), NOW())"
);
$stmt->execute([
    ':uuid' => cmsUuid(),
    ':title' => $title,
    ':slug' => $slug,
    ':body' => '<p>This is searchable body text about croissants and baguettes.</p>',
    ':excerpt' => 'Searchable excerpt',
]);
$contentId = (int)$db->lastInsertId();

t('content created', $contentId > 0);

// Emit publish event to trigger indexing
if (function_exists('kernelEmitEvent')) {
    kernelEmitEvent('cms.content.published', [
        'content_id' => $contentId,
        'title' => $title,
        'slug' => $slug,
        'type' => 'post',
    ], 'cms');
}

// Query search
$res = app()->cap()->call('search.query@1', [
    'q' => 'croissants',
    'limit' => 10,
    'offset' => 0,
], ['caller_module' => 'cms']);

t('search.query ok', is_array($res) && !empty($res['ok']));
$rows = is_array($res) ? ($res['data'] ?? []) : [];
t('search.query returns results', is_array($rows) && count($rows) >= 1);

$found = false;
foreach ($rows as $r) {
    if (is_array($r) && (string)($r['entity_id'] ?? '') === (string)$contentId) {
        $found = true;
        break;
    }
}

t('created content is indexed', $found);

// Cleanup
$db->prepare("DELETE FROM cms_content WHERE id = :id")->execute([':id' => $contentId]);
searchIndexDelete('cms', 'post', (string)$contentId);

t('cleanup done', true);

// Log check
$appLog = @file_get_contents(STORAGE_PATH . '/logs/app.log') ?: '';
$errLog = @file_get_contents(STORAGE_PATH . '/logs/error.log') ?: '';

$appErrors = array_filter(explode("\n", $appLog), fn($l) => str_contains($l, '[error]') || str_contains($l, '[critical]'));
t('No app.log errors', empty($appErrors), implode('; ', $appErrors));

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
