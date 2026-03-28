<?php
/**
 * WordPress Importer URL Rewrite Regression Test
 * Verifies imported internal URLs are rewritten to the current site base URL.
 * Run: php tests/wordpress_importer_url_rewrite_test.php
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../modules/cms/helpers.php';
require_once __DIR__ . '/../modules/wordpress-importer/handlers/10-wordpress-importer.php';

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

$origHost = $_SERVER['HTTP_HOST'] ?? null;
$origHttps = $_SERVER['HTTPS'] ?? null;
$_SERVER['HTTP_HOST'] = 'target-import.test';
$_SERVER['HTTPS'] = 'on';

$currentBase = cmsExternalBaseUrl();

echo "\n=== BASE URL HELPERS ===\n";
t('normalized base URL lowercases host and trims trailing slash', wordpressImporterNormalizedBaseUrl('HTTPS://LegacySite.example/Blog/') === 'https://legacysite.example/Blog');
t('rewrite helper swaps old internal base for current base', wordpressImporterRewriteInternalUrls('Visit https://legacysite.example/blog/path', ['https://legacysite.example/blog']) === 'Visit ' . $currentBase . '/path');
t('rewrite helper leaves unrelated external URLs intact', wordpressImporterRewriteInternalUrls('https://cdn.example.com/asset.png', ['https://legacysite.example/blog']) === 'https://cdn.example.com/asset.png');

$wxr = <<<'XML'
<?xml version="1.0" encoding="UTF-8" ?>
<rss version="2.0"
    xmlns:excerpt="http://wordpress.org/export/1.2/excerpt/"
    xmlns:content="http://purl.org/rss/1.0/modules/content/"
    xmlns:wfw="http://wellformedweb.org/CommentAPI/"
    xmlns:dc="http://purl.org/dc/elements/1.1/"
    xmlns:wp="http://wordpress.org/export/1.2/">
    <channel>
        <title>Legacy Site</title>
        <link>https://legacysite.example/blog</link>
        <wp:base_site_url>https://legacysite.example</wp:base_site_url>
        <wp:base_blog_url>https://legacysite.example/blog</wp:base_blog_url>
        <wp:category>
            <wp:term_id>9</wp:term_id>
            <wp:category_nicename>news</wp:category_nicename>
            <wp:cat_name>News</wp:cat_name>
            <wp:category_description><![CDATA[Browse https://legacysite.example/blog/category/news]]></wp:category_description>
            <wp:category_parent></wp:category_parent>
        </wp:category>
        <item>
            <title>Hello Import</title>
            <link>https://legacysite.example/blog/hello-import</link>
            <pubDate>Thu, 27 Mar 2026 12:00:00 +0000</pubDate>
            <dc:creator><![CDATA[admin]]></dc:creator>
            <guid isPermaLink="false">https://legacysite.example/?p=10</guid>
            <description><![CDATA[Legacy excerpt https://legacysite.example/blog/hello-import]]></description>
            <content:encoded><![CDATA[<p><a href="https://legacysite.example/blog/hello-import">Read more</a></p>]]></content:encoded>
            <excerpt:encoded><![CDATA[Summary https://legacysite.example/blog/hello-import]]></excerpt:encoded>
            <wp:post_id>10</wp:post_id>
            <wp:post_date>2026-03-27 12:00:00</wp:post_date>
            <wp:post_date_gmt>2026-03-27 12:00:00</wp:post_date_gmt>
            <wp:post_name>hello-import</wp:post_name>
            <wp:status>publish</wp:status>
            <wp:post_type>post</wp:post_type>
            <category domain="category" nicename="news"><![CDATA[News]]></category>
        </item>
    </channel>
</rss>
XML;

echo "\n=== WXR PARSE REWRITE ===\n";
$parsed = wordpressImporterParseWxr($wxr);
$content = $parsed['content'][0] ?? [];
$category = $parsed['categories'][0] ?? [];

t('parsed one content row', count($parsed['content'] ?? []) === 1);
t('parsed one category row', count($parsed['categories'] ?? []) === 1);
t('content body rewrites legacy base URL', str_contains((string)($content['body'] ?? ''), $currentBase . '/hello-import'));
t('content excerpt rewrites legacy base URL', str_contains((string)($content['excerpt'] ?? ''), $currentBase . '/hello-import'));
t('category description rewrites legacy base URL', str_contains((string)($category['description'] ?? ''), $currentBase . '/category/news'));
t('parsed content no longer contains legacy blog URL', !str_contains((string)($content['body'] ?? ''), 'https://legacysite.example/blog'));

if ($origHost !== null) {
    $_SERVER['HTTP_HOST'] = $origHost;
} else {
    unset($_SERVER['HTTP_HOST']);
}

if ($origHttps !== null) {
    $_SERVER['HTTPS'] = $origHttps;
} else {
    unset($_SERVER['HTTPS']);
}

echo "\n=== LOG CHECK ===\n";
$appLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/app.log'));
$errorLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/error.log'));
t('No app.log errors', $appLog === '', $appLog !== '' ? substr($appLog, 0, 200) : '');
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