<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
if (!function_exists('getModuleSettings')) {
    require_once __DIR__ . '/../src/helpers/module-manager.php';
}
require_once __DIR__ . '/../modules/cms/helpers.php';

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

echo "Phase 3: Per-Content Template Selection\n";

// 1) cmsGetContentTemplates returns at least default
$templates = cmsGetContentTemplates('post');
t('default template present', count($templates) >= 1);
$defaultFound = false;
foreach ($templates as $tpl) {
    if (($tpl['slug'] ?? '') === 'default') { $defaultFound = true; break; }
}
t('default template has slug "default"', $defaultFound);

// 2) cmsResolveContentTemplate with no _template returns default
$resolved = cmsResolveContentTemplate('public/single.disyl', [], 'post');
t('no _template resolves to default', str_contains($resolved, 'single.disyl'));

// 3) cmsResolveContentTemplate with _template=default returns default
$resolved2 = cmsResolveContentTemplate('public/single.disyl', ['_template' => 'default'], 'post');
t('_template=default resolves to default', str_contains($resolved2, 'single.disyl'));

// 4) cmsResolveContentTemplate with unknown slug falls back to default
$resolved3 = cmsResolveContentTemplate('public/single.disyl', ['_template' => 'nonexistent-slug'], 'post');
t('unknown slug falls back to default', str_contains($resolved3, 'single.disyl'));

// 5) Hook-based template registration
app()->hooks()->on('cms.content.templates', function (array $templates, string $contentType) {
    $templates[] = [
        'slug' => 'wide',
        'label' => 'Full Width',
        'types' => ['post', 'page'],
        'path' => 'modules/cms/public/single-wide.disyl',
    ];
    return $templates;
}, 10);

$templates2 = cmsGetContentTemplates('post');
$wideFound = false;
foreach ($templates2 as $tpl) {
    if (($tpl['slug'] ?? '') === 'wide') { $wideFound = true; break; }
}
t('hook registers custom template', $wideFound);
t('hook template count increased', count($templates2) > count($templates));

// 6) cmsResolveContentTemplate resolves hook-registered template
$resolved4 = cmsResolveContentTemplate('public/single.disyl', ['_template' => 'wide'], 'post');
t('hook template resolves to registered path', $resolved4 === 'modules/cms/public/single-wide.disyl');

// 7) Template filtering by content type
app()->hooks()->on('cms.content.templates', function (array $templates, string $contentType) {
    $templates[] = [
        'slug' => 'post-only',
        'label' => 'Post Only Layout',
        'types' => ['post'],
        'path' => 'modules/cms/public/post-only.disyl',
    ];
    return $templates;
}, 10);

$postTemplates = cmsGetContentTemplates('post');
$pageTemplates = cmsGetContentTemplates('page');
$postOnlyInPost = false;
$postOnlyInPage = false;
foreach ($postTemplates as $tpl) {
    if (($tpl['slug'] ?? '') === 'post-only') { $postOnlyInPost = true; break; }
}
foreach ($pageTemplates as $tpl) {
    if (($tpl['slug'] ?? '') === 'post-only') { $postOnlyInPage = true; break; }
}
t('type-specific template in post list', $postOnlyInPost);
t('type-specific template NOT in page list', !$postOnlyInPage);

// 8) _template saved/loaded via meta
$contentId = 0;
$savedTemplate = '';
try {
    $db = app()->db();
    $uuid = bin2hex(random_bytes(16));
    // Clean up any leftover from previous runs
    $db->exec("DELETE FROM cms_content WHERE slug LIKE 'test-tpl-%'");
    $tplSlug = 'test-tpl-' . substr(bin2hex(random_bytes(4)), 0, 8);
    $db->prepare(
        "INSERT INTO cms_content (uuid, title, slug, body, type, status, author_id, created_at) VALUES (?, 'Template Test', ?, '', 'post', 'draft', 1, NOW())"
    )->execute([$uuid, $tplSlug]);
    $contentId = (int)$db->lastInsertId();

    $db->prepare("INSERT INTO cms_content_meta (content_id, meta_key, meta_value) VALUES (?, '_template', 'wide') ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)")->execute([$contentId]);
    $metaStmt = $db->prepare("SELECT meta_value FROM cms_content_meta WHERE content_id = ? AND meta_key = '_template'");
    $metaStmt->execute([$contentId]);
    $savedTemplate = (string)$metaStmt->fetchColumn();
} catch (Throwable $e) {}
t('test content created', $contentId > 0);
t('_template persisted in meta', $savedTemplate === 'wide');

// Cleanup
try { $db->prepare("DELETE FROM cms_content WHERE id = ?")->execute([$contentId]); } catch (Throwable $e) {}

$appLog = @file_get_contents(STORAGE_PATH . '/logs/app.log') ?: '';
$errLog = @file_get_contents(STORAGE_PATH . '/logs/error.log') ?: '';
$criticals = array_filter(explode("\n", $appLog), fn($l) => str_contains($l, '[critical]'));
t('no app.log critical errors', empty($criticals), implode('; ', $criticals));
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
