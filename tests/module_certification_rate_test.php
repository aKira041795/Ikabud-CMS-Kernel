<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../tools/module-certification-gate.php';

file_put_contents(STORAGE_PATH . '/logs/app.log', '');
file_put_contents(STORAGE_PATH . '/logs/error.log', '');

$assertions = 0;
$failures = 0;

function t(string $label, bool $condition, string $detail = ''): void
{
    global $assertions, $failures;

    $assertions++;
    if ($condition) {
        echo "PASS: {$label}\n";
        return;
    }

    $failures++;
    echo "FAIL: {$label}";
    if ($detail !== '') {
        echo " — {$detail}";
    }
    echo "\n";
}

$projectRoot = dirname(__DIR__);
$certifyCommand = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($projectRoot . '/ikabud') . ' module:certify --all';
$certifyLines = [];
exec($certifyCommand . ' 2>&1', $certifyLines, $certifyExit);
$certifyOutput = implode(PHP_EOL, $certifyLines);
$parsed = ikabudModuleCertificationParseReport($certifyOutput);
$passCount = count($parsed['pass']);
$failCount = count($parsed['fail']);
$totalCount = $passCount + $failCount;
$passRate = $totalCount > 0 ? $passCount / $totalCount : 0.0;

$sampleParsed = ikabudModuleCertificationParseReport("  ✓ PASS  alpha-module  (1/1)\nFAIL beta-module (0/1)\nignored line");
t('parser counts sample PASS line', count($sampleParsed['pass']) === 1, json_encode($sampleParsed));
t('parser counts sample FAIL line', count($sampleParsed['fail']) === 1, json_encode($sampleParsed));

t('module:certify --all exits 0', $certifyExit === 0, 'exit=' . $certifyExit);
t('module:certify --all has zero FAIL lines', $failCount === 0, 'fail=' . $failCount);
t('module:certify --all has >= 60 PASS lines', $passCount >= IKABUD_MODULE_CERTIFICATION_MIN_PASS_COUNT, 'pass=' . $passCount);
t('module:certify --all pass rate is >= 90%', $passRate >= 0.90, 'rate=' . sprintf('%.1f%%', $passRate * 100));

$tmpFile = tempnam(sys_get_temp_dir(), 'module-certify-');
if ($tmpFile === false) {
    t('temporary file created for gate replay', false);
} else {
    file_put_contents($tmpFile, $certifyOutput);
    register_shutdown_function(static function () use ($tmpFile): void {
        @unlink($tmpFile);
    });

    $gateCommand = sprintf(
        'IKABUD_MODULE_CERTIFICATION_GATE_OUTPUT_FILE=%s IKABUD_MODULE_CERTIFICATION_GATE_EXIT_CODE=%d %s %s',
        escapeshellarg($tmpFile),
        $certifyExit,
        escapeshellarg(PHP_BINARY),
        escapeshellarg($projectRoot . '/tools/module-certification-gate.php')
    );
    $gateLines = [];
    exec($gateCommand . ' 2>&1', $gateLines, $gateExit);
    $gateOutput = implode(PHP_EOL, $gateLines);

    t('module certification gate exits 0', $gateExit === 0, 'exit=' . $gateExit . ' output=' . $gateOutput);
    t('module certification gate reports rate=', str_contains($gateOutput, 'rate='), $gateOutput);
}

$appLog = trim((string) @file_get_contents(STORAGE_PATH . '/logs/app.log'));
$errorLog = trim((string) @file_get_contents(STORAGE_PATH . '/logs/error.log'));
t('app.log remains empty', $appLog === '', $appLog !== '' ? substr($appLog, 0, 200) : '');
t('error.log remains empty', $errorLog === '', $errorLog !== '' ? substr($errorLog, 0, 200) : '');

echo sprintf("Counts: pass=%d fail=%d rate=%.1f%%\n", $passCount, $failCount, $passRate * 100);
echo "Assertions: {$assertions}\n";

exit($failures === 0 ? 0 : 1);
