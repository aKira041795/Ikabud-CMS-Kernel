<?php
/**
 * CMS Module — SEO + Sitemap Test
 * Verifies default SEO head generation and sitemap XML output.
 * Run: php tests/cms_seo_sitemap_test.php
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

// ═══════════════════════════════════════════════════════════════════
// 1. Default SEO head generation
// ═══════════════════════════════════════════════════════════════════
echo "\n=== DEFAULT SEO HEAD ===\n";

$content = [
    'type' => 'post',
    'slug' => 'hello-world',
    'title' => 'Hello World',
    'excerpt' => 'This is an excerpt for search engines.',
    'featured_image' => '2026/03/test.jpg',
    'meta' => [
        'seo_title' => 'Custom SEO Title',
        'seo_description' => 'Custom SEO description goes here.',
    ],
];

$html = cmsDefaultSeoHeadHtml($content);

t('includes meta title', str_contains($html, 'meta name="title"'));
t('includes og:title', str_contains($html, 'property="og:title"'));
t('includes description', str_contains($html, 'meta name="description"'));
t('includes canonical', str_contains($html, 'rel="canonical"'));
t('includes og:image', str_contains($html, 'property="og:image"'));
t('uses seo_title', str_contains($html, 'Custom SEO Title'));
t('uses seo_description', str_contains($html, 'Custom SEO description'));

// ═══════════════════════════════════════════════════════════════════
// 2. Hook integration baseline
// ═══════════════════════════════════════════════════════════════════
echo "\n=== cmsGetPublicHeadHtml BASELINE ===\n";

$head = cmsGetPublicHeadHtml($content);
t('cmsGetPublicHeadHtml returns string', is_string($head));
t('cmsGetPublicHeadHtml contains baseline SEO', str_contains($head, 'Custom SEO Title'));

// ═══════════════════════════════════════════════════════════════════
// 3. Sitemap builder
// ═══════════════════════════════════════════════════════════════════
echo "\n=== SITEMAP XML ===\n";

$xml = cmsBuildSitemapXml();
t('sitemap starts with xml declaration', str_contains($xml, '<?xml'));
t('sitemap contains urlset', str_contains($xml, '<urlset'));
t('sitemap contains at least 1 url', str_contains($xml, '<url>'));
t('sitemap contains /cms loc', str_contains($xml, '/cms'));

// ═══════════════════════════════════════════════════════════════════
// LOG CHECK
// ═══════════════════════════════════════════════════════════════════
echo "\n=== LOG CHECK ===\n";

$appLog = @file_get_contents(STORAGE_PATH . '/logs/app.log') ?: '';
$errLog = @file_get_contents(STORAGE_PATH . '/logs/error.log') ?: '';

$appErrors = array_filter(explode("\n", $appLog), fn($l) => str_contains($l, '[error]'));
t('No app.log errors', empty($appErrors), implode('; ', $appErrors));

// Filter out kernel cache info lines — only flag real PHP errors
$errLines = array_filter(explode("\n", $errLog), function ($l) {
    $l = trim($l);
    if ($l === '') return false;
    if (str_contains($l, 'Ikabud Cache:')) return false;
    return true;
});

t('No PHP errors in error.log', empty($errLines), implode('; ', array_slice($errLines, 0, 3)));

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
