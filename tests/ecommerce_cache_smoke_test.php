<?php
declare(strict_types=1);

$_SERVER['HTTP_HOST'] = 'cmsnew.test';
$_SERVER['REQUEST_URI'] = '/ecommerce/shop';

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../modules/cms/helpers.php';
require_once __DIR__ . '/../modules/ecommerce/helpers.php';

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

echo "\n§1  Cache function availability\n";
$funcs = ['ecCacheEnabled','ecCacheGet','ecCacheSet','ecCacheInvalidateProduct',
          'ecCacheKeyForProductList','ecCacheTagsForProduct','ecCacheTagsForListing',
          'ecCacheFlushAll','ecCacheInvalidateByTags','ecCacheResetRuntimeState'];
foreach ($funcs as $f) {
    t("{$f} exists", function_exists($f));
}

echo "\n§2  Cache key determinism\n";
$key = ecCacheKeyForProductList(['status' => 'published', 'limit' => 12]);
t('Cache key is a string', is_string($key) && $key !== '');
t('Key is stable for same filters', $key === ecCacheKeyForProductList(['status' => 'published', 'limit' => 12]));
t('Different filters produce different key', $key !== ecCacheKeyForProductList(['status' => 'draft', 'limit' => 12]));

echo "\n§3  Tag builders\n";
$tags = ecCacheTagsForProduct(42, 'test-product');
t('Product tags contain ec:product:42', in_array('ec:product:42', $tags, true));
t('Product tags contain ec:product:slug:test-product', in_array('ec:product:slug:test-product', $tags, true));
t('Product tags contain ec:type:product', in_array('ec:type:product', $tags, true));
$ltags = ecCacheTagsForListing(5, 2);
t('Listing tags contain ec:category:5', in_array('ec:category:5', $ltags, true));
t('Listing tags contain ec:store:2', in_array('ec:store:2', $ltags, true));

echo "\n§4  Cache set/get/invalidate round-trip\n";
// Force-enable via globals (settings may not be in DB)
$tid = app()->tenant()->current() ?? 0;
$GLOBALS['ec_cache_ttl_cached_t' . $tid] = true;
$GLOBALS['ec_cache_ttl_value_t' . $tid]  = 300;

$testData = ['id' => 99, 'title' => 'Test Product'];
$testKey  = 'ec:test:roundtrip';
$testTags = ['ec:test'];

ecCacheSet($testKey, $testData, $testTags);
$got = ecCacheGet($testKey);
t('Set then Get returns data', $got !== null && ($got['id'] ?? null) === 99);

$cleared = ecCacheInvalidateByTags($testTags);
$gotAfter = ecCacheGet($testKey);
t('Invalidate by tags clears entry', $gotAfter === null);

echo "\n§5  Flush all\n";
ecCacheSet('ec:test:flush1', ['a' => 1], ['ec:test']);
ecCacheSet('ec:test:flush2', ['b' => 2], ['ec:test']);
ecCacheFlushAll();
t('Flush clears first entry', ecCacheGet('ec:test:flush1') === null);
t('Flush clears second entry', ecCacheGet('ec:test:flush2') === null);

echo "\n§6  Runtime state reset\n";
ecCacheResetRuntimeState();
t('Reset clears TTL cache flag', empty($GLOBALS['ec_cache_ttl_cached_t' . $tid]));

// Log checks
echo "\n§7  Log checks\n";
$appLog = file_get_contents(__DIR__ . '/../storage/logs/app.log');
$errLog = file_get_contents(__DIR__ . '/../storage/logs/error.log');
t('no app.log critical errors', strpos($appLog, 'CRITICAL') === false);
t('no PHP fatals in error.log', strpos($errLog, 'Fatal error') === false);

echo "\n════════════════════════════════════════════\n";
echo "  Results: {$pass} passed, {$fail} failed\n";
echo "════════════════════════════════════════════\n";

exit($fail > 0 ? 1 : 0);
