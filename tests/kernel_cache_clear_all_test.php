<?php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

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

function removeTree(string $path): void
{
    if (!is_dir($path)) {
        if (is_file($path) || is_link($path)) {
            @unlink($path);
        }
        return;
    }

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($items as $item) {
        if ($item->isDir() && !$item->isLink()) {
            @rmdir($item->getPathname());
            continue;
        }

        @unlink($item->getPathname());
    }

    @rmdir($path);
}

echo "\n=== KERNEL CACHE CLEAR ALL ===\n";

$cacheRoot = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'ikabud_cache_clear_all_' . bin2hex(random_bytes(6));
@mkdir($cacheRoot, 0775, true);

$errorLog = tempnam(sys_get_temp_dir(), 'ikabud_cache_log_');
if ($errorLog === false) {
    throw new RuntimeException('Failed to create temporary error log file.');
}

$previousErrorLog = ini_get('error_log');
ini_set('error_log', $errorLog);

$cache = new \Ikabud\Kernel\Cache($cacheRoot);

register_shutdown_function(static function () use ($cacheRoot, $errorLog, $previousErrorLog): void {
    removeTree($cacheRoot);
    if (is_string($previousErrorLog) && $previousErrorLog !== '') {
        ini_set('error_log', $previousErrorLog);
    }
    @unlink($errorLog);
});

$instanceDir = $cacheRoot . '/cms_t1';
$nestedDir = $cacheRoot . '/cms/runtime';
$disylDir = $cacheRoot . '/disyl';

@mkdir($instanceDir, 0775, true);
@mkdir($nestedDir, 0775, true);
@mkdir($disylDir, 0775, true);

file_put_contents($instanceDir . '/page.cache', 'page-cache');
file_put_contents($instanceDir . '/.tag_' . md5('cms:home') . '.idx', serialize(['/home']));
file_put_contents($nestedDir . '/fragment.cache', 'fragment-cache');
file_put_contents($cacheRoot . '/capability_metrics.json', '{}');
file_put_contents($cacheRoot . '/capability_breakers.json', '{}');
file_put_contents($cacheRoot . '/.cache_stats.json', '{}');
file_put_contents($disylDir . '/Template_Test_123.php', '<?php return true;');
file_put_contents($disylDir . '/.manifest.json', '{}');
file_put_contents($disylDir . '/.gitkeep', '');

$result = $cache->clearAll();
$loggedLines = file_exists($errorLog) ? trim((string) file_get_contents($errorLog)) : '';

$remainingFiles = [];
$items = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($cacheRoot, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::LEAVES_ONLY
);
foreach ($items as $item) {
    $remainingFiles[] = str_replace($cacheRoot . '/', '', $item->getPathname());
}
sort($remainingFiles);

t('clearAll deletes mixed cache file artifacts recursively', (int)($result['cleared'] ?? 0) === 8, json_encode($result));
t('clearAll does not report deletion errors for removable files', empty($result['errors']), json_encode($result['errors'] ?? []));
t('clearAll removes top-level JSON cache artifacts', !is_file($cacheRoot . '/capability_metrics.json') && !is_file($cacheRoot . '/capability_breakers.json') && !is_file($cacheRoot . '/.cache_stats.json'));
t('clearAll removes instance cache files and tag indexes', !is_file($instanceDir . '/page.cache') && !glob($instanceDir . '/.tag_*.idx'));
t('clearAll removes nested cache directories after purging their files', !is_dir($cacheRoot . '/cms') && !is_dir($nestedDir));
t('clearAll removes template compiler output but preserves keep files', !is_file($disylDir . '/Template_Test_123.php') && !is_file($disylDir . '/.manifest.json') && is_file($disylDir . '/.gitkeep'), json_encode($remainingFiles));
t('clearAll leaves only preserved keep files behind', $remainingFiles === ['disyl/.gitkeep'], json_encode($remainingFiles));
t('clearAll does not emit invalidation notices by default', $loggedLines === '', $loggedLines);

file_put_contents($instanceDir . '/page.cache', 'page-cache');
file_put_contents($instanceDir . '/.tag_' . md5('cms:home') . '.idx', serialize(['/home']));
file_put_contents($errorLog, '');

$verboseCache = new \Ikabud\Kernel\Cache($cacheRoot, 0, true);
$verboseCache->clearAll();
$verboseLog = file_exists($errorLog) ? trim((string) file_get_contents($errorLog)) : '';
t('clearAll can emit invalidation notices when explicitly enabled', str_contains($verboseLog, 'Ikabud Cache: Cleared'), $verboseLog);

echo "\n";
echo $fail === 0
    ? "PASS: {$pass} assertions\n"
    : "FAIL: {$fail} failed, {$pass} passed\n";

if ($fail > 0) {
    exit(1);
}