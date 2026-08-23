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
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start command: ' . implode(' ', $command));
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    return [$exitCode, (string)$stdout, (string)$stderr];
}

file_put_contents(BASE_PATH . '/storage/logs/app.log', '');
file_put_contents(BASE_PATH . '/storage/logs/error.log', '');

[$exitCode, $stdout, $stderr] = run_command([PHP_BINARY, BASE_PATH . '/tools/poc6-lifecycle-proof.php'], BASE_PATH);
t('harness exits 0', $exitCode === 0, 'exit=' . $exitCode . '; stdout=' . trim($stdout) . '; stderr=' . trim($stderr));

preg_match('/artifact=([^\s]+)/', $stdout, $m);
$artifactRelative = $m[1] ?? ('storage/poc6/proof-lifecycle-' . substr(trim((string)shell_exec('cd ' . escapeshellarg(BASE_PATH) . ' && git rev-parse HEAD')), 0, 12) . '.json');
$artifactPath = BASE_PATH . '/' . $artifactRelative;
t('artifact file exists', is_file($artifactPath), $artifactPath);

$artifact = is_file($artifactPath) ? json_decode((string)file_get_contents($artifactPath), true) : null;
t('artifact is valid json', is_array($artifact), json_last_error_msg());

$head = trim((string)shell_exec('cd ' . escapeshellarg(BASE_PATH) . ' && git rev-parse HEAD'));
t('fingerprint head matches git', is_array($artifact) && ($artifact['fingerprint']['head_sha'] ?? '') === $head, 'expected=' . $head . '; actual=' . (($artifact['fingerprint']['head_sha'] ?? '') ?: ''));

$steps = is_array($artifact) ? (array)($artifact['steps'] ?? []) : [];
$failedSteps = [];
$names = [];
foreach ($steps as $step) {
    $name = (string)($step['name'] ?? '');
    $names[] = $name;
    if (empty($step['passed'])) {
        $failedSteps[] = $name;
    }
}
t('every artifact step passed', $failedSteps === [], implode(', ', $failedSteps));

$joined = strtolower(implode(' ', $names));
t('forbidden-path control step present', str_contains($joined, 'control'));
t('forbidden-path base step present', str_contains($joined, 'base'));
t('forbidden-path context step present', str_contains($joined, 'context'));
t('forbidden-path spoof step present', str_contains($joined, 'spoof'));
t('retain-data step present', str_contains($joined, 'retain'));
t('purge-data step present', str_contains($joined, 'purge'));

$failedInstallStep = null;
foreach ($steps as $step) {
    if (($step['name'] ?? '') === 'failed_install_restoration_reports_best_effort_non_atomic') {
        $failedInstallStep = $step;
        break;
    }
}
$failedEvidence = json_encode($failedInstallStep['evidence'] ?? [], JSON_UNESCAPED_SLASHES);
t('failed-install restoration step present', is_array($failedInstallStep));
t('failed-install restoration reports best-effort/non-atomic', is_string($failedEvidence) && str_contains(strtolower($failedEvidence), 'best-effort') && str_contains(strtolower($failedEvidence), 'non-atomic'), (string)$failedEvidence);

$statusDoc = BASE_PATH . '/docs/poc6-lifecycle-status.md';
t('status doc exists', is_file($statusDoc), $statusDoc);
$statusBody = is_file($statusDoc) ? (string)file_get_contents($statusDoc) : '';
t('status doc references artifact', str_contains($statusBody, $artifactRelative), $statusBody);

$appLog = trim((string)file_get_contents(BASE_PATH . '/storage/logs/app.log'));
$errorLog = trim((string)file_get_contents(BASE_PATH . '/storage/logs/error.log'));
t('error log has no unexpected fatal entries', !preg_match('/Fatal error|Uncaught /i', $errorLog), $errorLog);
t('app log only contains expected denials or is empty', $appLog === '' || preg_match('/den(y|ial)|forbidden|caller_policy|default_deny/i', $appLog) === 1, $appLog);

echo "\n{$pass} passed, {$fail} failed\n";
if ($errors !== []) {
    echo "Failures:\n";
    foreach ($errors as $error) {
        echo ' - ' . $error . "\n";
    }
}

exit($fail === 0 ? 0 : 1);
