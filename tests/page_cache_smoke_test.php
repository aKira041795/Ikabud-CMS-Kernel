<?php
declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────
// Page-Level Cache — Smoke Test
//
// Validates: function availability, eligibility checks, cache key
// determinism, set/get/serve round-trip, per-module invalidation,
// full flush, and interaction with CMS/ecommerce invalidation hooks.
// ─────────────────────────────────────────────────────────────────────────

$_SERVER['HTTP_HOST'] = 'cmsnew.test';
$_SERVER['REQUEST_URI'] = '/cms/blog';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['QUERY_STRING'] = '';

// Clear logs before test
$logDir = __DIR__ . '/../storage/logs';
if (is_file($logDir . '/app.log'))   { file_put_contents($logDir . '/app.log', ''); }
if (is_file($logDir . '/error.log')) { file_put_contents($logDir . '/error.log', ''); }

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../src/helpers/page-cache.php';
require_once __DIR__ . '/../modules/cms/helpers.php';
require_once __DIR__ . '/../modules/ecommerce/helpers.php';

// Ensure the page cache directory is writable for CLI tests.
// When Apache (www-data) creates the directory first, the CLI user
// cannot write to it.  Use a dedicated test subdirectory instead.
$pcInstance = pageCacheInstance();
$pcDir = STORAGE_PATH . '/cache/' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $pcInstance);
if (is_dir($pcDir) && !is_writable($pcDir)) {
    // Cannot write to the www-data-owned directory.  Swap the kernel
    // cache to a fresh temp-based directory so set/get work in CLI.
    $testCacheDir = sys_get_temp_dir() . '/pagecache_smoke_' . getmypid();
    @mkdir($testCacheDir, 0755, true);
    // Re-create the kernel cache with the temp directory
    $cacheRef = new ReflectionProperty(app(), 'cache');
    $cacheRef->setAccessible(true);
    $cacheRef->setValue(app(), new \Ikabud\Kernel\Cache(
        $testCacheDir,
        0,
        false
    ));
    register_shutdown_function(function () use ($testCacheDir) {
        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($testCacheDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iter as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($testCacheDir);
    });
}

$pass = 0;
$fail = 0;
$errors = [];

function t(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $errors;
    if ($ok) { $pass++; echo "  ✓ {$label}\n"; return; }
    $fail++; $errors[] = $label . ($detail !== '' ? ': ' . $detail : '');
    echo "  ✗ {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

// ── §1 Function availability ─────────────────────────────────────────

echo "\n§1  Page cache function availability\n";
$funcs = [
    'pageCacheInstance',
    'pageCacheTtl',
    'pageCacheShouldCache',
    'pageCacheKey',
    'pageCacheTags',
    'pageCacheGet',
    'pageCacheSet',
    'pageCacheServe',
    'pageCacheInvalidateModule',
    'pageCacheInvalidateUrl',
    'pageCacheFlushAll',
];
foreach ($funcs as $f) {
    t("{$f} exists", function_exists($f));
}

// ── §2 Instance and TTL ──────────────────────────────────────────────

echo "\n§2  Instance and TTL\n";
$instance = pageCacheInstance();
t('Instance contains pagecache', str_contains($instance, 'pagecache'));
t('Instance is tenant-scoped', str_contains($instance, '_t'));
$ttl = pageCacheTtl();
t('TTL is positive', $ttl > 0);
t('TTL is 60 (default)', $ttl === 60);

// ── §3 Eligibility checks ───────────────────────────────────────────

echo "\n§3  Eligibility checks\n";
// Clear any auth cookies so shouldCache works
unset($_COOKIE);
$_COOKIE = [];

t('Public CMS blog is cacheable', pageCacheShouldCache('/cms/blog'));
t('Public CMS page is cacheable', pageCacheShouldCache('/cms/page/about'));
t('Public shop is cacheable', pageCacheShouldCache('/ecommerce/shop'));
t('Public product is cacheable', pageCacheShouldCache('/ecommerce/shop/test-product'));
t('Shop category is cacheable', pageCacheShouldCache('/ecommerce/shop/category/shoes'));

t('Cart is NOT cacheable', !pageCacheShouldCache('/ecommerce/cart'));
t('Checkout is NOT cacheable', !pageCacheShouldCache('/ecommerce/checkout'));
t('My orders is NOT cacheable', !pageCacheShouldCache('/ecommerce/my-orders'));
t('My wishlist is NOT cacheable', !pageCacheShouldCache('/ecommerce/my-wishlist'));
t('Compare is NOT cacheable', !pageCacheShouldCache('/ecommerce/compare'));
t('API is NOT cacheable', !pageCacheShouldCache('/api/v1/ecommerce/products'));
t('CMS admin is NOT cacheable', !pageCacheShouldCache('/cms/admin'));
t('CMS login is NOT cacheable', !pageCacheShouldCache('/cms/login'));
t('EC admin is NOT cacheable', !pageCacheShouldCache('/ecommerce/admin'));
t('Superadmin is NOT cacheable', !pageCacheShouldCache('/superadmin/settings'));
t('Login is NOT cacheable', !pageCacheShouldCache('/login'));

// POST request
$_SERVER['REQUEST_METHOD'] = 'POST';
t('POST is NOT cacheable', !pageCacheShouldCache('/cms/blog'));
$_SERVER['REQUEST_METHOD'] = 'GET'; // restore

// ── §4 Cache key determinism ─────────────────────────────────────────

echo "\n§4  Cache key determinism\n";
$_SERVER['QUERY_STRING'] = '';
$key1 = pageCacheKey('/cms/blog');
$key2 = pageCacheKey('/cms/blog');
t('Same URI produces same key', $key1 === $key2);

$key3 = pageCacheKey('/ecommerce/shop');
t('Different URI produces different key', $key1 !== $key3);

$_SERVER['QUERY_STRING'] = 'page=2&cat=3';
$key4 = pageCacheKey('/ecommerce/shop');
$_SERVER['QUERY_STRING'] = 'cat=3&page=2';
$key5 = pageCacheKey('/ecommerce/shop');
t('Query param order does not affect key', $key4 === $key5);

$_SERVER['QUERY_STRING'] = 'page=1';
$key6 = pageCacheKey('/ecommerce/shop');
t('Different query params produce different key', $key4 !== $key6);
$_SERVER['QUERY_STRING'] = '';

// ── §5 Cache tags ────────────────────────────────────────────────────

echo "\n§5  Cache tags\n";
$tags = pageCacheTags('/cms/blog', 'cms');
t('Tags contain pagecache:all', in_array('pagecache:all', $tags, true));
t('Tags contain pagecache:module:cms', in_array('pagecache:module:cms', $tags, true));
$uriTag = 'pagecache:uri:' . md5('/cms/blog');
t('Tags contain URI hash', in_array($uriTag, $tags, true));

$ecTags = pageCacheTags('/ecommerce/shop', 'ecommerce');
t('EC tags contain pagecache:module:ecommerce', in_array('pagecache:module:ecommerce', $ecTags, true));

// ── §6 Set / Get round-trip ──────────────────────────────────────────

echo "\n§6  Set / Get round-trip\n";

// Flush to start clean
pageCacheFlushAll();

$testHtml = '<!DOCTYPE html><html><body><h1>Test Page</h1><p>This is a cached page for testing purposes.</p></body></html>';
$testUri = '/cms/test-page-cache-roundtrip';

pageCacheSet($testUri, $testHtml, 'cms', 200);
$got = pageCacheGet($testUri);
t('Set then Get returns data', $got !== null);
t('HTML is preserved', ($got['html'] ?? '') === $testHtml);
t('ETag is generated', !empty($got['etag']));
t('Module is stored', ($got['module'] ?? '') === 'cms');
t('Status is stored', ($got['status'] ?? 0) === 200);

// Non-200 should not be cached
pageCacheSet('/cms/test-error', '<h1>Error</h1>', 'cms', 500);
$gotErr = pageCacheGet('/cms/test-error');
t('Non-200 response is NOT cached', $gotErr === null);

// Tiny response should not be cached
pageCacheSet('/cms/test-tiny', 'hi', 'cms', 200);
$gotTiny = pageCacheGet('/cms/test-tiny');
t('Tiny response (< 100 bytes) is NOT cached', $gotTiny === null);

// ── §7 Per-module invalidation ───────────────────────────────────────

echo "\n§7  Per-module invalidation\n";

pageCacheSet('/cms/blog', $testHtml . ' blog', 'cms', 200);
pageCacheSet('/ecommerce/shop', $testHtml . ' shop', 'ecommerce', 200);

$cmsBefore = pageCacheGet('/cms/blog');
$ecBefore = pageCacheGet('/ecommerce/shop');
t('CMS page cached before invalidation', $cmsBefore !== null);
t('EC page cached before invalidation', $ecBefore !== null);

pageCacheInvalidateModule('cms');
$cmsAfter = pageCacheGet('/cms/blog');
$ecAfter = pageCacheGet('/ecommerce/shop');
t('CMS page cleared after CMS invalidation', $cmsAfter === null);
t('EC page survives CMS invalidation', $ecAfter !== null);

pageCacheInvalidateModule('ecommerce');
$ecAfter2 = pageCacheGet('/ecommerce/shop');
t('EC page cleared after EC invalidation', $ecAfter2 === null);

// ── §8 URL-specific invalidation ─────────────────────────────────────

echo "\n§8  URL-specific invalidation\n";

pageCacheSet('/cms/blog', $testHtml . ' blog', 'cms', 200);
pageCacheSet('/cms/page/about', $testHtml . ' about', 'cms', 200);

pageCacheInvalidateUrl('/cms/blog');
$blogAfter = pageCacheGet('/cms/blog');
$aboutAfter = pageCacheGet('/cms/page/about');
t('Target URL is cleared', $blogAfter === null);
t('Other URL survives', $aboutAfter !== null);

// ── §9 Full flush ────────────────────────────────────────────────────

echo "\n§9  Full flush\n";

pageCacheSet('/cms/blog', $testHtml, 'cms', 200);
pageCacheSet('/ecommerce/shop', $testHtml, 'ecommerce', 200);
pageCacheSet('/cms/page/contact', $testHtml, 'cms', 200);

pageCacheFlushAll();
t('CMS blog flushed', pageCacheGet('/cms/blog') === null);
t('EC shop flushed', pageCacheGet('/ecommerce/shop') === null);
t('CMS page flushed', pageCacheGet('/cms/page/contact') === null);

// ── §10 Cross-module invalidation hooks ──────────────────────────────

echo "\n§10 Cross-module invalidation hooks\n";

// Cache some pages
pageCacheSet('/ecommerce/shop', $testHtml . ' shop', 'ecommerce', 200);
pageCacheSet('/ecommerce/shop/test-product', $testHtml . ' product', 'ecommerce', 200);

// ecCacheInvalidateProduct should also flush ecommerce page cache
ecCacheInvalidateProduct(1, 'test-product');
$shopAfter = pageCacheGet('/ecommerce/shop');
$productAfter = pageCacheGet('/ecommerce/shop/test-product');
t('ecCacheInvalidateProduct flushes EC page cache', $shopAfter === null && $productAfter === null);

// Cache again and test category invalidation
pageCacheSet('/ecommerce/shop', $testHtml . ' shop', 'ecommerce', 200);
ecCacheInvalidateCategory(1);
$shopAfterCat = pageCacheGet('/ecommerce/shop');
t('ecCacheInvalidateCategory flushes EC page cache', $shopAfterCat === null);

// Cache CMS pages and test cmsCacheFlushAll
pageCacheSet('/cms/blog', $testHtml . ' blog', 'cms', 200);
pageCacheSet('/cms/page/about', $testHtml . ' about', 'cms', 200);
cmsCacheFlushAll();
$cmsBlogAfter = pageCacheGet('/cms/blog');
$cmsAboutAfter = pageCacheGet('/cms/page/about');
t('cmsCacheFlushAll flushes CMS page cache', $cmsBlogAfter === null && $cmsAboutAfter === null);

// ── §11 Log checks ──────────────────────────────────────────────────

echo "\n§11 Log checks\n";
$appLog = (string)@file_get_contents($logDir . '/app.log');
$errLog = (string)@file_get_contents($logDir . '/error.log');
t('No app.log critical errors', stripos($appLog, 'CRITICAL') === false);
t('No error.log fatal errors', stripos($errLog, 'Fatal error') === false);

// ── Summary ──────────────────────────────────────────────────────────

echo "\n" . str_repeat('─', 60) . "\n";
echo "Page cache smoke test: {$pass} passed, {$fail} failed\n";
if ($fail > 0) {
    echo "\nFailed:\n";
    foreach ($errors as $e) {
        echo "  • {$e}\n";
    }
}
echo "\n";
exit($fail > 0 ? 1 : 0);
