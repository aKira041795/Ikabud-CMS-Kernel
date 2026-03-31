<?php

declare(strict_types=1);

$_SERVER['HTTP_HOST'] = 'applicationos.test';
$_SERVER['REQUEST_URI'] = '/cms/search';

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../modules/cms/helpers.php';
require_once __DIR__ . '/../modules/cms/handlers.php';

ob_start();

$db = app()->db();
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

function captureOutput(callable $callback): string
{
    ob_start();
    try {
        $callback();
        return (string)ob_get_clean();
    } catch (Throwable $e) {
        ob_end_clean();
        throw $e;
    }
}

file_put_contents(STORAGE_PATH . '/logs/app.log', '');
file_put_contents(STORAGE_PATH . '/logs/error.log', '');

echo "\n=== CMS PUBLIC SEARCH ===\n";

$suffix = strtolower(substr(bin2hex(random_bytes(5)), 0, 10));
$needle = 'search-probe-' . $suffix;
$postSlug = 'cms-public-search-' . $suffix;
$contentId = 0;
$searchHtml = '';
$notFoundHtml = '';

try {
    $uuid = cmsUuid();
    $db->prepare(
        "INSERT INTO cms_content (uuid, title, slug, body, excerpt, type, status, author_id, published_at, created_at, updated_at)
         VALUES (:uuid, :title, :slug, :body, :excerpt, 'post', 'published', 1, NOW(), NOW(), NOW())"
    )->execute([
        ':uuid' => $uuid,
        ':title' => 'CMS Public Search ' . $suffix,
        ':slug' => $postSlug,
        ':body' => '<p>' . $needle . ' appears in the public search body.</p>',
        ':excerpt' => 'Excerpt for ' . $needle,
    ]);
    $contentId = (int)$db->lastInsertId();

    $_GET = ['q' => $needle];
    $_SERVER['REQUEST_URI'] = '/cms/search?q=' . rawurlencode($needle);
    http_response_code(200);

    $searchHtml = captureOutput(static function (): void {
        cmsPublicSearch();
    });

    $notFoundHtml = cmsPublicRenderNotFound();

    t('search page uses the submitted query instead of the request method', str_contains($searchHtml, 'Search: ' . $needle) && !str_contains($searchHtml, 'Search: GET'), $searchHtml);
    t('search results include the matching post', str_contains($searchHtml, 'CMS Public Search ' . $suffix), $searchHtml);
    t('public not-found template includes CMS search affordance', str_contains($notFoundHtml, 'Try a Search Instead') && str_contains($notFoundHtml, '/cms/search'), $notFoundHtml);

    $appLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/app.log'));
    $errorLogLines = array_values(array_filter(
        preg_split('/\R/', (string)@file_get_contents(STORAGE_PATH . '/logs/error.log')) ?: [],
        static fn(string $line): bool => trim($line) !== '' && !str_contains($line, 'Ikabud Cache:')
    ));
    $errorLog = implode("\n", $errorLogLines);
    t('no app.log critical errors', !str_contains($appLog, '[critical]'), $appLog !== '' ? substr($appLog, 0, 200) : '');
    t('no PHP errors in error.log', $errorLog === '', $errorLog !== '' ? substr($errorLog, 0, 200) : '');
} finally {
    $_GET = [];
    $_SERVER['REQUEST_URI'] = '/cms/search';

    if ($contentId > 0) {
        try {
            $db->prepare('DELETE FROM cms_content WHERE id = ?')->execute([$contentId]);
        } catch (Throwable $e) {
        }
    }
}

echo "\n════════════════════════════════════════════\n";
echo "  Results: {$pass} passed, {$fail} failed\n";
if ($fail > 0) {
    echo "\n  Failures:\n";
    foreach ($errors as $error) {
        echo "  - {$error}\n";
    }
    ob_end_flush();
    exit(1);
}

ob_end_flush();
exit(0);