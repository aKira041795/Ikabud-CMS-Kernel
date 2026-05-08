<?php
/**
 * Test: CMS entity_list cache uses granular tags AND ecommerce write-side
 * invalidates them via the cmsCacheInvalidateEntityList() boundary helper.
 */
require __DIR__ . '/../bootstrap.php';

// Load CMS + ecommerce modules so helper functions are present.
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../modules/cms/helpers.php';
require_once __DIR__ . '/../modules/cms/handlers.php';
require_once __DIR__ . '/../modules/ecommerce/helpers.php';

$pass = $fail = 0;
$lines = [];
function check(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail, $lines;
    $lines[] = ($ok ? '  PASS  ' : '  FAIL  ') . $name . ($detail ? ' — ' . $detail : '');
    $ok ? $pass++ : $fail++;
}

// Clear logs at start so log check is clean.
@file_put_contents(__DIR__ . '/../storage/logs/app.log', '');
@file_put_contents(__DIR__ . '/../storage/logs/error.log', '');

// 1. Helper exists with right signature.
check('cmsCacheInvalidateEntityList exists', function_exists('cmsCacheInvalidateEntityList'));

// 2. Empty type → 0, no errors.
$n0 = cmsCacheInvalidateEntityList('');
check('empty type returns 0', $n0 === 0);

// 3. Round-trip: set a fake entity_list cache entry, invalidate via helper, confirm gone.
if (function_exists('cmsCacheSet') && function_exists('cmsCacheGet')) {
    $key = 'cms:entity_list:test:roundtrip:' . uniqid();
    cmsCacheSet($key, ['html' => 'sentinel-' . $key], ['cms:entity_list:product']);
    $before = cmsCacheGet($key);
    check('cache set then get round-trip', is_array($before) && ($before['html'] ?? null) === 'sentinel-' . $key);
    cmsCacheInvalidateEntityList('product');
    $after = cmsCacheGet($key);
    check('helper invalidates by tag', $after === null);
}

// 4. Category-scoped tag invalidation.
if (function_exists('cmsCacheSet') && function_exists('cmsCacheGet')) {
    $keyA = 'cms:entity_list:test:catA:' . uniqid();
    $keyB = 'cms:entity_list:test:catB:' . uniqid();
    cmsCacheSet($keyA, ['html' => 'A'], ['cms:entity_list:product:cat:42']);
    cmsCacheSet($keyB, ['html' => 'B'], ['cms:entity_list:product:cat:99']);
    cmsCacheInvalidateEntityList('product', 42);
    $a = cmsCacheGet($keyA);
    $b = cmsCacheGet($keyB);
    // cat:42 entry must be gone; the helper also flushes 'cms:entity_list:product'
    // (broad), which means cat:99 is ALSO flushed because it shares that tag.
    // That's the documented behavior — broad type-level tag is always included.
    check('category-scoped + broad tag invalidates target', $a === null);
}

// 5. Cross-boundary: ecCacheInvalidateProduct triggers cms helper.
if (function_exists('cmsCacheSet') && function_exists('cmsCacheGet')
    && function_exists('ecCacheInvalidateProduct')) {
    $key = 'cms:entity_list:test:cross:' . uniqid();
    cmsCacheSet($key, ['html' => 'cross'], ['cms:entity_list:product']);
    ecCacheInvalidateProduct(0); // 0 → no product-specific tag, but still hits broad tags
    $after = cmsCacheGet($key);
    check('ecCacheInvalidateProduct flushes cms entity_list', $after === null);
}

// 6. Cross-boundary: ecCacheInvalidateCategory flushes cat-scoped tag.
if (function_exists('cmsCacheSet') && function_exists('cmsCacheGet')
    && function_exists('ecCacheInvalidateCategory')) {
    $key = 'cms:entity_list:test:cross_cat:' . uniqid();
    cmsCacheSet($key, ['html' => 'cross_cat'], ['cms:entity_list:product:cat:7']);
    ecCacheInvalidateCategory(7);
    $after = cmsCacheGet($key);
    check('ecCacheInvalidateCategory flushes cat-scoped entry', $after === null);
}

// 7. Log check.
$log = @file_get_contents(__DIR__ . '/../storage/logs/app.log') ?: '';
$err = @file_get_contents(__DIR__ . '/../storage/logs/error.log') ?: '';
check('no app.log warnings/errors', !preg_match('/\] \[(warning|error|critical)\]/i', $log));
check('no PHP errors in error.log', trim($err) === '');

echo "\n";
foreach ($lines as $l) echo $l . "\n";
echo "\n================================================================\n";
echo "Total: " . ($pass + $fail) . "  PASS: $pass  FAIL: $fail\n";
exit($fail === 0 ? 0 : 1);
