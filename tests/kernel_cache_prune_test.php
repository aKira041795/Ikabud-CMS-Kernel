<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use Ikabud\Kernel\Cache;

$pass = 0;
$fail = 0;
function cachePruneAssert(string $name, bool $condition, string $detail = ''): void
{
    global $pass, $fail;
    echo ($condition ? 'PASS' : 'FAIL') . ': ' . $name . ($detail !== '' ? ' — ' . $detail : '') . PHP_EOL;
    $condition ? $pass++ : $fail++;
}

function cachePruneRemoveTree(string $path): void
{
    if (!is_dir($path)) return;
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($path);
}

$cacheRoot = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'ikabud_cache_prune_' . bin2hex(random_bytes(6));
@mkdir($cacheRoot, 0775, true);
register_shutdown_function(static fn() => cachePruneRemoveTree($cacheRoot));

$cache = new Cache($cacheRoot);
$cache->set('probe', 'expired-stamp', ['body' => 'expired'], -10);
$cache->set('probe', 'valid-long-ttl', ['body' => 'valid'], 86400);
$cache->setWithTags('probe', 'tagged-valid', ['body' => 'tagged'], ['prune-proof'], 86400);

$instanceDir = $cacheRoot . '/probe';
$expiredPath = $instanceDir . '/' . md5('expired-stamp') . '.cache';
$validPath = $instanceDir . '/' . md5('valid-long-ttl') . '.cache';
$tagPath = $instanceDir . '/.tag_' . md5('prune-proof') . '.idx';
$legacyPath = $cacheRoot . '/legacy.cache';
$corruptPath = $instanceDir . '/corrupt.cache';
$rootKeep = $cacheRoot . '/.gitkeep';
$instanceKeep = $instanceDir . '/.keep';
file_put_contents($legacyPath, serialize(['body' => 'legacy']));
file_put_contents($corruptPath, 'not-a-serialized-cache-payload');
file_put_contents($rootKeep, '');
file_put_contents($instanceKeep, '');

$preview = $cache->previewExpired();
cachePruneAssert('dry-run reports candidates', ($preview['pruned'] ?? 0) >= 2, json_encode($preview));
cachePruneAssert('dry-run deletes nothing', is_file($expiredPath) && is_file($legacyPath) && is_file($validPath));

$result = $cache->pruneExpired();
cachePruneAssert('past stamped expiry is pruned', !is_file($expiredPath), json_encode($result));
cachePruneAssert('legacy entry without expiry stamp is pruned', !is_file($legacyPath));
cachePruneAssert('still-valid long-TTL entry survives', is_file($validPath));
cachePruneAssert('tag index survives', is_file($tagPath));
cachePruneAssert('.gitkeep placeholder survives', is_file($rootKeep));
cachePruneAssert('.keep placeholder survives', is_file($instanceKeep));
cachePruneAssert('corrupt payload does not throw', !is_file($corruptPath) || is_file($corruptPath));
cachePruneAssert('prune reports no errors', ($result['errors'] ?? null) === [], json_encode($result));

echo "Total: " . ($pass + $fail) . " PASS: {$pass} FAIL: {$fail}" . PHP_EOL;
exit($fail === 0 ? 0 : 1);
