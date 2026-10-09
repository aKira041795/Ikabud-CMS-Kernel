<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$cacheDir = sys_get_temp_dir() . '/kernel_table_ensure_cache_test_' . getmypid();

if (($argv[1] ?? '') === '--worker') {
    require $root . '/bootstrap.php';
    require_once $root . '/src/helpers/module-manager.php';

    $ddl = 0;
    app()->events()->listen('kernel.database.query.after', static function (array $payload) use (&$ddl): void {
        if (preg_match('/^\s*CREATE\s+TABLE/i', (string)($payload['sql'] ?? '')) === 1) {
            $ddl++;
        }
    }, 10, '');

    $ok = moduleControlPlaneEnsureCatalogTables(($argv[2] ?? '') === 'force');
    echo json_encode(['ok' => $ok, 'ddl' => $ddl]) . PHP_EOL;
    exit($ok ? 0 : 1);
}

$pass = 0;
$fail = 0;
$assert = static function (string $name, bool $condition, string $detail = '') use (&$pass, &$fail): void {
    echo ($condition ? 'PASS' : 'FAIL') . ': ' . $name . ($detail !== '' ? ' — ' . $detail : '') . PHP_EOL;
    $condition ? $pass++ : $fail++;
};
$remove = static function (string $path) use (&$remove): void {
    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $remove($path . '/' . $entry);
            }
        }
        @rmdir($path);
    } elseif (file_exists($path) || is_link($path)) {
        @unlink($path);
    }
};
$run = static function (string $dir, bool $force = false) use ($root): array {
    $command = [PHP_BINARY, __FILE__, '--worker', $force ? 'force' : 'normal'];
    $environment = array_merge($_ENV, ['KERNEL_TABLE_ENSURE_CACHE_DIR' => $dir]);
    $pipes = [];
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $environment);
    if (!is_resource($process)) {
        return ['exit' => 255, 'ddl' => -1, 'output' => 'proc_open failed'];
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    $lines = array_values(array_filter(explode("\n", trim((string)$stdout))));
    $decoded = json_decode((string)end($lines), true);
    return ['exit' => $exit, 'ddl' => (int)($decoded['ddl'] ?? -1), 'output' => trim($stdout . $stderr)];
};

$remove($cacheDir);
$cold = $run($cacheDir);
$warm = $run($cacheDir);
$assert('process 1 cold flag emits DDL', $cold['exit'] === 0 && $cold['ddl'] > 0, json_encode($cold));
$assert('process 2 warm flag emits zero DDL', $warm['exit'] === 0 && $warm['ddl'] === 0, json_encode($warm));

$remove($cacheDir);
$mustRefuseCold = $run($cacheDir);
$assert('cleared genuinely cold flag cannot skip DDL', $mustRefuseCold['ddl'] > 0, json_encode($mustRefuseCold));
$forced = $run($cacheDir, true);
$assert('force bypasses a warm flag', $forced['ddl'] > 0, json_encode($forced));

$remove($cacheDir);
file_put_contents($cacheDir, 'not a directory');
$unavailable = $run($cacheDir);
$assert('unavailable flag store fails safe by running DDL', $unavailable['ddl'] > 0, json_encode($unavailable));
$remove($cacheDir);

echo 'DDL counts: process-1=' . $cold['ddl'] . ' process-2=' . $warm['ddl']
    . ' cold-reset=' . $mustRefuseCold['ddl'] . ' forced=' . $forced['ddl']
    . ' unavailable=' . $unavailable['ddl'] . PHP_EOL;
echo 'Total: ' . ($pass + $fail) . " PASS: {$pass} FAIL: {$fail}" . PHP_EOL;
exit($fail === 0 ? 0 : 1);
