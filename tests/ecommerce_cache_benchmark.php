<?php
declare(strict_types=1);

$_SERVER['HTTP_HOST'] = 'cmsnew.test';
$_SERVER['REQUEST_URI'] = '/ecommerce/shop';

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../modules/cms/helpers.php';
require_once __DIR__ . '/../modules/ecommerce/helpers.php';

$tid = app()->tenant()->current() ?? 0;
$GLOBALS['ec_cache_ttl_cached_t' . $tid] = true;
$GLOBALS['ec_cache_ttl_value_t' . $tid]  = 300;

$list = ecProductList(['status' => 'published', 'limit' => 3]);
echo "Total products: {$list['total']}\n";
foreach ($list['items'] as $p) {
    echo "  id={$p['id']} slug={$p['slug']}\n";
}

if (empty($list['items'])) {
    echo "No products found — skipping benchmarks.\n";
    exit(0);
}

$pid = (int)$list['items'][0]['id'];

// ecProductGet cold vs warm
ecCacheFlushAll();
$t1 = microtime(true);
for ($i = 0; $i < 10; $i++) {
    ecCacheResetRuntimeState();
    $GLOBALS['ec_cache_ttl_cached_t' . $tid] = true;
    $GLOBALS['ec_cache_ttl_value_t' . $tid]  = 300;
    ecCacheFlushAll();
    ecProductGet($pid, true);
}
$coldMs = (microtime(true) - $t1) * 1000 / 10;

ecProductGet($pid, true); // prime cache
$t2 = microtime(true);
for ($i = 0; $i < 10; $i++) {
    ecProductGet($pid, true);
}
$warmMs = (microtime(true) - $t2) * 1000 / 10;

echo "\necProductGet($pid) cold: " . round($coldMs, 1) . "ms avg\n";
echo "ecProductGet($pid) warm: " . round($warmMs, 1) . "ms avg\n";
echo "Speedup: " . round($coldMs / max($warmMs, 0.01), 1) . "x\n";

// ecProductList cold vs warm
ecCacheFlushAll();
$t3 = microtime(true);
for ($i = 0; $i < 10; $i++) {
    ecCacheResetRuntimeState();
    $GLOBALS['ec_cache_ttl_cached_t' . $tid] = true;
    $GLOBALS['ec_cache_ttl_value_t' . $tid]  = 300;
    ecCacheFlushAll();
    ecProductList(['status' => 'published', 'limit' => 12]);
}
$coldList = (microtime(true) - $t3) * 1000 / 10;

ecProductList(['status' => 'published', 'limit' => 12]); // prime
$t4 = microtime(true);
for ($i = 0; $i < 10; $i++) {
    ecProductList(['status' => 'published', 'limit' => 12]);
}
$warmList = (microtime(true) - $t4) * 1000 / 10;

echo "\necProductList cold: " . round($coldList, 1) . "ms avg\n";
echo "ecProductList warm: " . round($warmList, 1) . "ms avg\n";
echo "Speedup: " . round($coldList / max($warmList, 0.01), 1) . "x\n";

// ecProductGetBySlug cold vs warm
$slug = $list['items'][0]['slug'];
ecCacheFlushAll();
$t5 = microtime(true);
for ($i = 0; $i < 10; $i++) {
    ecCacheResetRuntimeState();
    $GLOBALS['ec_cache_ttl_cached_t' . $tid] = true;
    $GLOBALS['ec_cache_ttl_value_t' . $tid]  = 300;
    ecCacheFlushAll();
    ecProductGetBySlug($slug, true);
}
$coldSlug = (microtime(true) - $t5) * 1000 / 10;

ecProductGetBySlug($slug, true); // prime
$t6 = microtime(true);
for ($i = 0; $i < 10; $i++) {
    ecProductGetBySlug($slug, true);
}
$warmSlug = (microtime(true) - $t6) * 1000 / 10;

echo "\necProductGetBySlug($slug) cold: " . round($coldSlug, 1) . "ms avg\n";
echo "ecProductGetBySlug($slug) warm: " . round($warmSlug, 1) . "ms avg\n";
echo "Speedup: " . round($coldSlug / max($warmSlug, 0.01), 1) . "x\n";
