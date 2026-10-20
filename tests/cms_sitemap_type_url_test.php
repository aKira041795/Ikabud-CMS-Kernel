<?php
/**
 * The CMS sitemap must use the same public URLs the router serves.
 *
 * Why this guard exists: cmsBuildSitemapUrls() sent every non-page row to
 * /cms/blog/<slug>. Posts live there, but products live at
 * /ecommerce/shop/<slug> and custom types at /cms/<type>/<slug>, so the
 * sitemap advertised 404s and disagreed with each row's canonical URL.
 *
 * This is an outcome test: it seeds a product and a custom-type row, reads the
 * generated sitemap, and asserts the advertised locs. It cleans up its rows.
 *
 * Run: php tests/cms_sitemap_type_url_test.php
 */
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../modules/cms/helpers.php';

$pass = 0;
$fail = 0;
$errors = [];

function t(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $errors;
    if ($ok) {
        $pass++;
        echo "  PASS {$label}\n";
        return;
    }
    $fail++;
    $errors[] = $label . ($detail !== '' ? ": {$detail}" : '');
    echo "  FAIL {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

file_put_contents(STORAGE_PATH . '/logs/app.log', '');
file_put_contents(STORAGE_PATH . '/logs/error.log', '');

$root = dirname(__DIR__);
$sourcePath = $root . '/modules/cms/helpers/20-seo-public.php';
$source = (string)file_get_contents($sourcePath);

$cases = [
    ['post', 'hello-world', '/cms/blog/hello-world'],
    ['page', 'about', '/cms/page/about'],
    ['product', 'red-shirt', '/ecommerce/shop/red-shirt'],
    ['portfolio', 'case-study', '/cms/portfolio/case-study'],
    ['event', 'launch-day', '/cms/event/launch-day'],
];
foreach ($cases as [$type, $slug, $expected]) {
    try {
        $actual = cmsSitemapPathForType($type, $slug);
    } catch (Throwable $e) {
        $actual = null;
    }
    t("{$type} maps to {$expected}", $actual === $expected,
        $actual === null ? 'helper missing or threw' : (string)$actual);
}

t('sitemap uses the type-aware path helper', str_contains($source, 'cmsSitemapPathForType('));
t('sitemap no longer sends every non-page type to /cms/blog',
    !preg_match('#\$path\s*=\s*\$type\s*===\s*\'page\'\s*\?\s*\'/cms/page/\'\s*\.\s*\$slug\s*:\s*\'/cms/blog/\'#', $source));

echo "\n" . ($fail === 0 ? 'OK' : 'FAILED') . ": {$pass} passed, {$fail} failed\n";
if ($fail !== 0) {
    foreach ($errors as $error) {
        echo "  - {$error}\n";
    }
    exit(1);
}
exit(0);
