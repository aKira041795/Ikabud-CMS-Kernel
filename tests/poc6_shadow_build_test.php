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
        echo "PASS: {$label}\n";
        return;
    }

    $fail++;
    $errors[] = $label . ($detail !== '' ? ': ' . $detail : '');
    echo 'FAIL: ' . $label . ($detail !== '' ? ' — ' . $detail : '') . "\n";
}

function run_command(array $command, string $cwd): array
{
    $process = proc_open(
        $command,
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $cwd
    );

    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start command: ' . implode(' ', $command));
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    return [$exitCode, (string) $stdout, (string) $stderr];
}

function remove_tree(string $root): void
{
    $root = rtrim($root, DIRECTORY_SEPARATOR);
    if ($root === '' || !is_dir($root)) {
        return;
    }

    $tempDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR);
    if (!str_starts_with(str_replace('\\', '/', $root), str_replace('\\', '/', $tempDir) . '/')) {
        throw new RuntimeException('Refusing to remove non-temp path: ' . $root);
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $item) {
        $pathname = $item->getPathname();
        if ($item->isDir() && !$item->isLink()) {
            rmdir($pathname);
            continue;
        }
        unlink($pathname);
    }

    rmdir($root);
}

file_put_contents(BASE_PATH . '/storage/logs/app.log', '');
file_put_contents(BASE_PATH . '/storage/logs/error.log', '');

$artifact = null;
$artifactPath = null;
$shadowRoot = null;
$captureRoot = null;
$tempRoot = null;

try {
    [$exitCode, $stdout, $stderr] = run_command([PHP_BINARY, BASE_PATH . '/tools/poc6-shadow-build.php'], BASE_PATH);
    t('harness exits 0', $exitCode === 0, 'exit=' . $exitCode . '; stdout=' . trim($stdout) . '; stderr=' . trim($stderr));

    preg_match('/artifact=([^\s]+)/', $stdout, $matches);
    $artifactRelative = $matches[1] ?? '';
    $artifactPath = $artifactRelative !== '' ? BASE_PATH . '/' . $artifactRelative : null;
    t('artifact path reported', $artifactPath !== null && $artifactPath !== '', trim($stdout));
    t('artifact file exists', $artifactPath !== null && is_file($artifactPath), (string) $artifactPath);

    $artifact = $artifactPath !== null && is_file($artifactPath)
        ? json_decode((string) file_get_contents($artifactPath), true)
        : null;
    t('artifact is valid json', is_array($artifact), json_last_error_msg());

    $head = trim((string) shell_exec('cd ' . escapeshellarg(BASE_PATH) . ' && git rev-parse HEAD'));
    t('fingerprint head matches git', is_array($artifact) && ($artifact['fingerprint']['head_sha'] ?? '') === $head, 'expected=' . $head . '; actual=' . (($artifact['fingerprint']['head_sha'] ?? '') ?: ''));
    t('included list recorded', is_array($artifact) && isset($artifact['fingerprint']['included']) && is_array($artifact['fingerprint']['included']));
    t('excluded list recorded', is_array($artifact) && isset($artifact['fingerprint']['excluded']) && is_array($artifact['fingerprint']['excluded']));
    t('excluded contains tests/', is_array($artifact) && in_array('tests/', $artifact['fingerprint']['excluded'] ?? [], true));
    t('excluded contains .ai/', is_array($artifact) && in_array('.ai/', $artifact['fingerprint']['excluded'] ?? [], true));

    $shadowRoot = is_array($artifact) ? ($artifact['module']['shadow_root'] ?? null) : null;
    $captureRoot = is_array($artifact) ? ($artifact['capture_root'] ?? null) : null;
    $tempRoot = is_string($shadowRoot) ? dirname($shadowRoot) : null;

    $moduleFiles = is_array($artifact) ? (array) ($artifact['module']['files'] ?? []) : [];
    t('golden module module.json exists in shadow', isset($moduleFiles[0]) && is_file((string) $moduleFiles[0]), isset($moduleFiles[0]) ? (string) $moduleFiles[0] : 'missing module files');

    $certifyPassed = false;
    if (is_array($artifact)) {
        foreach ((array) ($artifact['build_steps'] ?? []) as $step) {
            if (($step['step'] ?? '') === 'module.certify' && !empty($step['passed'])) {
                $certifyPassed = true;
                break;
            }
        }
    }
    t('module.certify step passed', $certifyPassed);

    $statusDoc = BASE_PATH . '/docs/poc6-shadow-build-status.md';
    t('status doc exists', is_file($statusDoc), $statusDoc);
    $statusDocBody = is_file($statusDoc) ? (string) file_get_contents($statusDoc) : '';
    t('status doc references artifact', $artifactRelative !== '' && str_contains($statusDocBody, $artifactRelative), $statusDocBody);
} finally {
    foreach (array_filter([$tempRoot, $captureRoot, $shadowRoot], 'is_string') as $path) {
        if (is_dir($path)) {
            remove_tree($path === $tempRoot ? $path : dirname($path));
            break;
        }
    }
}

$appLog = trim((string) file_get_contents(BASE_PATH . '/storage/logs/app.log'));
$errorLog = trim((string) file_get_contents(BASE_PATH . '/storage/logs/error.log'));
t('app log has no critical entries', !str_contains($appLog, '[critical]'), $appLog);
t('error log is empty', $errorLog === '', $errorLog);

echo "\n{$pass} passed, {$fail} failed\n";
if ($errors !== []) {
    echo "Failures:\n";
    foreach ($errors as $error) {
        echo ' - ' . $error . "\n";
    }
}

exit($fail === 0 ? 0 : 1);
