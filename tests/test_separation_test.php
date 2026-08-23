<?php
/**
 * Integration test for offline/canary test discovery separation.
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

$pass = 0;
$fail = 0;

function t(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  ✓ {$label}\n";
        return;
    }

    $fail++;
    echo "  ✗ {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

function run_discover(array $args): array
{
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/discover.php');
    foreach ($args as $arg) {
        $command .= ' ' . escapeshellarg($arg);
    }

    $output = [];
    $exitCode = 0;
    exec($command . ' 2>&1', $output, $exitCode);

    return [
        'command' => $command,
        'output' => implode("\n", $output),
        'lines' => $output,
        'exit' => $exitCode,
    ];
}

function discover_listed_files(string $output): array
{
    $files = [];
    foreach (preg_split('/\R/', $output) ?: [] as $line) {
        $trimmed = trim($line);
        if (str_starts_with($trimmed, 'tests/') && str_ends_with($trimmed, '_test.php')) {
            $files[] = $trimmed;
        }
    }
    return $files;
}

function is_canary_test_file(string $relativePath): bool
{
    $fullPath = dirname(__DIR__) . '/' . $relativePath;
    $base = basename($fullPath);
    if (str_ends_with($base, '_canary_test.php')) {
        return true;
    }

    $contents = @file_get_contents($fullPath);
    $needle = '@' . 'canary';
    return is_string($contents) && stripos($contents, $needle) !== false;
}

function expected_canary_files(): array
{
    $root = dirname(__DIR__) . '/tests';
    $skipDirs = ['harness', 'browser', 'ai', 'test_results', 'bench'];
    $files = [];

    foreach (glob($root . '/*_test.php') ?: [] as $file) {
        $base = basename($file);
        if (str_contains($base, '_seed_') || str_contains($base, '_interactive') || str_contains($base, '_helper')) {
            continue;
        }
        if (is_canary_test_file('tests/' . basename($file))) {
            $files[] = 'tests/' . basename($file);
        }
    }

    foreach (glob($root . '/*', GLOB_ONLYDIR) ?: [] as $subdir) {
        $dir = basename($subdir);
        if (in_array($dir, $skipDirs, true)) {
            continue;
        }
        foreach (glob($subdir . '/*_test.php') ?: [] as $file) {
            $base = basename($file);
            if (str_contains($base, '_seed_') || str_contains($base, '_interactive') || str_contains($base, '_helper')) {
                continue;
            }
            $relative = 'tests/' . $dir . '/' . basename($file);
            if (is_canary_test_file($relative)) {
                $files[] = $relative;
            }
        }
    }

    sort($files);
    return $files;
}

file_put_contents(__DIR__ . '/../storage/logs/app.log', '');
file_put_contents(__DIR__ . '/../storage/logs/error.log', '');

echo "\n=== Test Discovery Separation Test ===\n";

$offline = run_discover(['--list']);
$canary = run_discover(['--list', '--canary']);
$coreFilter = run_discover(['--filter=disyl_engine', '--list']);

$offlineFiles = discover_listed_files($offline['output']);
$canaryFiles = discover_listed_files($canary['output']);
$expectedCanaries = expected_canary_files();

t('offline default list exits 0', $offline['exit'] === 0, 'exit=' . $offline['exit']);
t('offline default does not list live_external_canary_test.php', !in_array('tests/live_external_canary_test.php', $offlineFiles, true));
t('offline default still lists disyl_engine_test.php', in_array('tests/disyl_engine_test.php', $offlineFiles, true));
t('offline default still discovers large core suite', count($offlineFiles) > 400, 'count=' . count($offlineFiles));

t('canary list exits 0', $canary['exit'] === 0, 'exit=' . $canary['exit']);
t('canary list includes exemplar', in_array('tests/live_external_canary_test.php', $canaryFiles, true), implode(', ', $canaryFiles));
t('canary list contains only canary files', count(array_filter($canaryFiles, fn(string $file): bool => !is_canary_test_file($file))) === 0, implode(', ', $canaryFiles));
t('canary list count matches canary file count', count($canaryFiles) === count($expectedCanaries) && count($canaryFiles) >= 1, 'listed=' . count($canaryFiles) . ', expected=' . count($expectedCanaries));

t('filter regression exits 0', $coreFilter['exit'] === 0, 'exit=' . $coreFilter['exit']);
t('filter regression still finds disyl_engine_test.php', str_contains($coreFilter['output'], 'tests/disyl_engine_test.php'));

echo "\n{$pass}/" . ($pass + $fail) . " passed\n";
exit($fail > 0 ? 1 : 0);
