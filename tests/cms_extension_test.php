<?php
/**
 * CMS Module — Extension Points Test
 * Verifies all 6 CMS hooks fire correctly and return expected shapes.
 * Run: php tests/cms_extension_test.php
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../modules/cms/helpers.php';
require_once __DIR__ . '/../modules/cms/handlers.php';

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

// ── Clear logs ──────────────────────────────────────────────────────
file_put_contents(STORAGE_PATH . '/logs/app.log', '');
file_put_contents(STORAGE_PATH . '/logs/error.log', '');

$hooks = app()->hooks();

// ═══════════════════════════════════════════════════════════════════
// 1. cms.editor.block_types
// ═══════════════════════════════════════════════════════════════════
echo "\n=== cms.editor.block_types ===\n";

// With no listeners, returns empty array
$result = cmsGetExtensionBlockTypes();
t('block_types default is empty array', is_array($result) && empty($result));

// Register a listener
$hooks->on('cms.editor.block_types', function (array $blocks): array {
    $blocks[] = [
        'type'   => 'callout',
        'label'  => 'Callout',
        'icon'   => '📢',
        'fields' => [
            ['key' => 'text', 'default' => ''],
            ['key' => 'style', 'default' => 'info'],
        ],
    ];
    return $blocks;
}, 10);

$result = cmsGetExtensionBlockTypes();
t('block_types returns array', is_array($result));
t('block_types has 1 entry', count($result) === 1);
t('block_types entry type is callout', ($result[0]['type'] ?? '') === 'callout');
t('block_types entry has fields', is_array($result[0]['fields'] ?? null) && count($result[0]['fields']) === 2);

$hooks->off('cms.editor.block_types');

// ═══════════════════════════════════════════════════════════════════
// 2. cms.editor.sidebar_fields
// ═══════════════════════════════════════════════════════════════════
echo "\n=== cms.editor.sidebar_fields ===\n";

$result = cmsGetExtensionSidebarFields('post');
t('sidebar_fields default is empty array', is_array($result) && empty($result));

$hooks->on('cms.editor.sidebar_fields', function (array $fields, string $contentType): array {
    if ($contentType === 'post') {
        $fields[] = [
            'key'         => 'reading_time',
            'type'        => 'number',
            'label'       => 'Reading Time (min)',
            'placeholder' => '5',
        ];
    }
    return $fields;
}, 10);

$result = cmsGetExtensionSidebarFields('post');
t('sidebar_fields for post has 1 entry', count($result) === 1);
t('sidebar_fields key is reading_time', ($result[0]['key'] ?? '') === 'reading_time');
t('sidebar_fields type is number', ($result[0]['type'] ?? '') === 'number');

// page type should NOT get the field
$result2 = cmsGetExtensionSidebarFields('page');
t('sidebar_fields for page is empty', empty($result2));

$hooks->off('cms.editor.sidebar_fields');

// ═══════════════════════════════════════════════════════════════════
// 3. cms.admin.nav_items
// ═══════════════════════════════════════════════════════════════════
echo "\n=== cms.admin.nav_items ===\n";

$result = cmsGetExtensionNavItems();
t('nav_items default is empty array', is_array($result) && empty($result));

$hooks->on('cms.admin.nav_items', function (array $items): array {
    $items[] = [
        'label'      => 'Analytics',
        'url'        => '/cms/admin/analytics',
        'icon'       => '📊',
        'active_key' => 'analytics',
    ];
    return $items;
}, 10);

$result = cmsGetExtensionNavItems();
t('nav_items has 1 entry', count($result) === 1);
t('nav_items label is Analytics', ($result[0]['label'] ?? '') === 'Analytics');
t('nav_items url correct', ($result[0]['url'] ?? '') === '/cms/admin/analytics');
t('nav_items active_key correct', ($result[0]['active_key'] ?? '') === 'analytics');

$hooks->off('cms.admin.nav_items');

// ═══════════════════════════════════════════════════════════════════
// 4. cms.public.head
// ═══════════════════════════════════════════════════════════════════
echo "\n=== cms.public.head ===\n";

$result = cmsGetPublicHeadHtml([]);
t('public.head default is empty string', $result === '');

$hooks->on('cms.public.head', function (string $html, array $content): string {
    $title = $content['title'] ?? '';
    return $html . '<meta property="og:title" content="' . htmlspecialchars($title) . '">';
}, 10);

$result = cmsGetPublicHeadHtml(['title' => 'Test Post']);
t('public.head returns meta tag', str_contains($result, 'og:title'));
t('public.head contains post title', str_contains($result, 'Test Post'));

$hooks->off('cms.public.head');

// ═══════════════════════════════════════════════════════════════════
// 5. cms.public.render_content
// ═══════════════════════════════════════════════════════════════════
echo "\n=== cms.public.render_content ===\n";

$html = '<p>Hello world</p>';
$result = cmsFilterRenderedContent($html, []);
t('render_content passthrough with no listeners', $result === $html);

$hooks->on('cms.public.render_content', function (string $html, array $content): string {
    // Wrap all images in a lightbox class
    return str_replace('<img ', '<img class="lightbox" ', $html);
}, 10);

$imgHtml = '<p>Text</p><img src="test.jpg" alt="Test">';
$result = cmsFilterRenderedContent($imgHtml, []);
t('render_content transforms HTML', str_contains($result, 'class="lightbox"'));
t('render_content preserves other content', str_contains($result, '<p>Text</p>'));

$hooks->off('cms.public.render_content');

// ═══════════════════════════════════════════════════════════════════
// 6. cms.content.query_args
// ═══════════════════════════════════════════════════════════════════
echo "\n=== cms.content.query_args ===\n";

$args = ['per_page' => 10, 'order_by' => 'c.published_at DESC'];
$result = cmsFilterQueryArgs($args, 'post');
t('query_args passthrough with no listeners', $result === $args);

$hooks->on('cms.content.query_args', function (array $args, string $type): array {
    if ($type === 'post') {
        $args['per_page'] = 5;
    }
    return $args;
}, 10);

$result = cmsFilterQueryArgs(['per_page' => 10], 'post');
t('query_args modifies per_page for post', ($result['per_page'] ?? 0) === 5);

$result2 = cmsFilterQueryArgs(['per_page' => 10], 'page');
t('query_args leaves page type unchanged', ($result2['per_page'] ?? 0) === 10);

$hooks->off('cms.content.query_args');

// ═══════════════════════════════════════════════════════════════════
// 7. Error resilience — bad listener doesn't crash chain
// ═══════════════════════════════════════════════════════════════════
echo "\n=== ERROR RESILIENCE ===\n";

$hooks->on('cms.editor.block_types', function (array $blocks): array {
    throw new RuntimeException('Intentional test error');
}, 5);

$hooks->on('cms.editor.block_types', function (array $blocks): array {
    $blocks[] = ['type' => 'after_error', 'label' => 'After Error', 'fields' => []];
    return $blocks;
}, 15);

$result = cmsGetExtensionBlockTypes();
t('bad listener is skipped gracefully', is_array($result));
t('later listener still runs', count($result) >= 1 && ($result[0]['type'] ?? '') === 'after_error');

$hooks->off('cms.editor.block_types');

// ═══════════════════════════════════════════════════════════════════
// 8. cmsAdminContext includes ext_nav_items
// ═══════════════════════════════════════════════════════════════════
echo "\n=== cmsAdminContext ===\n";

$ctx = cmsAdminContext(['full_name' => 'Test User', 'role' => 'editor', 'source' => 'cms'], 'posts');
t('admin context has ext_nav_items key', array_key_exists('ext_nav_items', $ctx));
t('admin context ext_nav_items is array', is_array($ctx['ext_nav_items']));

// ═══════════════════════════════════════════════════════════════════
// LOG CHECK
// ═══════════════════════════════════════════════════════════════════
echo "\n=== LOG CHECK ===\n";

$appLog = @file_get_contents(STORAGE_PATH . '/logs/app.log') ?: '';
$errLog = @file_get_contents(STORAGE_PATH . '/logs/error.log') ?: '';

// The intentional error test will produce a log entry — that's expected
$appLogLines = array_filter(explode("\n", $appLog), fn($l) => trim($l) !== '');
$unexpectedErrors = [];
foreach ($appLogLines as $line) {
    // Only flag if it's NOT our intentional test error
    if (str_contains($line, '[error]') && !str_contains($line, 'Intentional test error')) {
        $unexpectedErrors[] = $line;
    }
}
t('No unexpected app.log errors', empty($unexpectedErrors), implode('; ', $unexpectedErrors));
t('No PHP errors in error.log', trim($errLog) === '', substr($errLog, 0, 200));

// ═══════════════════════════════════════════════════════════════════
// SUMMARY
// ═══════════════════════════════════════════════════════════════════
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
