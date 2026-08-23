<?php
declare(strict_types=1);

ob_start();
require __DIR__ . '/../bootstrap.php';
ob_end_clean();

$pass = 0;
$fail = 0;
function t(string $label, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  ✅ {$label}\n";
        return;
    }
    $fail++;
    echo "  ❌ {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

file_put_contents(__DIR__ . '/../storage/logs/app.log', '');
file_put_contents(__DIR__ . '/../storage/logs/error.log', '');

echo "\n=== Architecture Check Baseline ===\n";

function runCli(array $args, ?array &$output = null): int {
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../ikabud');
    foreach ($args as $arg) {
        $cmd .= ' ' . escapeshellarg($arg);
    }
    $lines = [];
    exec($cmd . ' 2>&1', $lines, $exit);
    $output = $lines;
    return $exit;
}

function discoverBaselineProbeCandidate(): array {
    $modules = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(__DIR__ . '/../modules', FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $fileInfo) {
        if (!$fileInfo->isFile() || $fileInfo->getFilename() !== 'module.json') {
            continue;
        }
        $path = (string)$fileInfo->getPathname();
        $manifest = json_decode((string)file_get_contents($path), true);
        if (!is_array($manifest)) {
            continue;
        }
        if (!preg_match('#(?:^|/)modules/(?:.+/)?([^/]+)/module\.json$#', str_replace('\\', '/', $path), $m)) {
            continue;
        }
        $moduleId = (string)$m[1];
        $modules[$moduleId] = [
            'path' => dirname($path),
            'owns' => array_values(array_filter((array)($manifest['owns_tables'] ?? []), 'is_string')),
            'co_owns' => array_values(array_filter((array)($manifest['co_owns_tables'] ?? []), 'is_string')),
            'reads' => array_values(array_filter((array)($manifest['reads_tables'] ?? []), 'is_string')),
        ];
    }

    foreach ($modules as $ownerId => $owner) {
        foreach ($owner['owns'] as $table) {
            foreach ($modules as $moduleId => $module) {
                if ($moduleId === $ownerId) {
                    continue;
                }
                $allowed = array_merge($module['owns'], $module['co_owns'], $module['reads']);
                if (in_array($table, $allowed, true)) {
                    continue;
                }
                return [$moduleId, $module['path'], $table, $ownerId];
            }
        }
    }

    throw new RuntimeException('No baseline probe candidate found');
}

$cleanBaseline = sys_get_temp_dir() . '/architecture-check-baseline-clean-' . getmypid() . '.json';
$strictBaseline = sys_get_temp_dir() . '/architecture-check-baseline-strict-' . getmypid() . '.json';
@unlink($cleanBaseline);
@unlink($strictBaseline);

[$targetModuleId, $targetModulePath, $foreignTable, $ownerModuleId] = discoverBaselineProbeCandidate();
$tempFile = $targetModulePath . '/architecture_check_baseline_probe_' . getmypid() . '.php';

try {
    $out = [];
    $exit = runCli(['architecture:check', '--baseline=' . $cleanBaseline], $out);
    t('baseline write exits 0', $exit === 0, 'exit=' . $exit);
    t('baseline file exists', is_file($cleanBaseline), $cleanBaseline);
    $decoded = json_decode((string)@file_get_contents($cleanBaseline), true);
    t('baseline file contains valid JSON', is_array($decoded), json_last_error_msg());

    $exit = runCli(['architecture:check', '--baseline=' . $cleanBaseline, '--fail-on-new'], $out);
    t('clean baseline gate exits 0', $exit === 0, 'exit=' . $exit . '; output=' . implode(" | ", $out));

    $probePhp = "<?php\n" . '$sql = "SELECT * FROM ' . $foreignTable . '";' . "\n";
    file_put_contents($tempFile, $probePhp);

    $exit = runCli(['architecture:check', '--baseline=' . $cleanBaseline, '--fail-on-new'], $out);
    t('new violation fails fail-on-new gate', $exit !== 0, 'exit=' . $exit . '; output=' . implode(" | ", $out));

    $exit = runCli(['architecture:check', '--baseline=' . $strictBaseline], $out);
    t('baseline write with current finding exits 0', $exit === 0, 'exit=' . $exit);

    $exit = runCli(['architecture:check', '--baseline=' . $strictBaseline, '--fail-on-new'], $out);
    t('baseline gate passes when finding is already baselined', $exit === 0, 'exit=' . $exit . '; output=' . implode(" | ", $out));

    $exit = runCli(['architecture:check', '--baseline=' . $strictBaseline, '--fail-on-new', '--strict'], $out);
    t('strict mode fails when any finding exists', $exit !== 0, 'exit=' . $exit . '; output=' . implode(" | ", $out));
} finally {
    if (is_file($tempFile)) {
        unlink($tempFile);
    }
    @unlink($cleanBaseline);
    @unlink($strictBaseline);
}

$appLog = (string)file_get_contents(__DIR__ . '/../storage/logs/app.log');
$errorLog = (string)file_get_contents(__DIR__ . '/../storage/logs/error.log');
t('app.log has no critical entries', !str_contains($appLog, '[critical]'), trim($appLog));
t('error.log is empty', trim($errorLog) === '', trim($errorLog));

echo "\n── Results: {$pass} passed, {$fail} failed ──\n";
exit($fail > 0 ? 1 : 0);
