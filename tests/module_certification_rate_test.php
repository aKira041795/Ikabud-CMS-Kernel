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

/**
 * Replay the gate against a synthetic certify report.
 *
 * @return array{0: int, 1: string}
 */
function runGateOnReport(string $projectRoot, string $report, int $certifyExit): array
{
    $tmp = tempnam(sys_get_temp_dir(), 'module-certify-');
    if ($tmp === false) {
        return [255, 'could not create fixture file'];
    }
    file_put_contents($tmp, $report);

    $command = sprintf(
        'IKABUD_MODULE_CERTIFICATION_GATE_OUTPUT_FILE=%s IKABUD_MODULE_CERTIFICATION_GATE_EXIT_CODE=%d %s %s',
        escapeshellarg($tmp),
        $certifyExit,
        escapeshellarg(PHP_BINARY),
        escapeshellarg($projectRoot . '/tools/module-certification-gate.php')
    );
    $lines = [];
    exec($command . ' 2>&1', $lines, $exit);
    @unlink($tmp);

    return [$exit, implode(PHP_EOL, $lines)];
}

$projectRoot = dirname(__DIR__);
$certifyCommand = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($projectRoot . '/ikabud') . ' module:certify --all';
$certifyLines = [];
exec($certifyCommand . ' 2>&1', $certifyLines, $certifyExit);
$certifyOutput = implode(PHP_EOL, $certifyLines);
$parsed = ikabudModuleCertificationParseReport($certifyOutput);
$passCount = count($parsed['pass']);
$failCount = count($parsed['fail']);

// The fleet that EXISTS is the denominator - never the lines that happened to be printed.
$expectedIds = ikabudModuleCertificationExpectedModuleIds($projectRoot);
$reportedIds = array_values(array_unique(array_merge($parsed['pass'], $parsed['fail'])));
$missingIds = array_values(array_diff($expectedIds, $reportedIds));
$unexpectedIds = array_values(array_diff($reportedIds, $expectedIds));
$fleetSize = count($expectedIds);
$passRate = $fleetSize > 0 ? count(array_intersect($parsed['pass'], $expectedIds)) / $fleetSize : 0.0;

$sampleParsed = ikabudModuleCertificationParseReport("  ✓ PASS  alpha-module  (1/1)\nFAIL beta-module (0/1)\nignored line");
t('parser counts sample PASS line', count($sampleParsed['pass']) === 1, json_encode($sampleParsed));
t('parser counts sample FAIL line', count($sampleParsed['fail']) === 1, json_encode($sampleParsed));

t('fleet discovery found modules on disk', $fleetSize > 0, 'fleet=' . $fleetSize);
t('module:certify --all exits 0', $certifyExit === 0, 'exit=' . $certifyExit);
t('module:certify --all has zero FAIL lines', $failCount === 0, 'fail=' . $failCount);
t('every module on disk reported', $missingIds === [], 'missing=' . implode(',', $missingIds));
t('no module reported that is absent from disk', $unexpectedIds === [], 'unexpected=' . implode(',', $unexpectedIds));
t('pass rate is 100% of the fleet that exists', $passRate >= 1.0, 'rate=' . sprintf('%.1f%%', $passRate * 100));

[$gateExit, $gateOutput] = runGateOnReport($projectRoot, $certifyOutput, $certifyExit);
t('gate accepts the real certify report', $gateExit === 0, 'exit=' . $gateExit . ' output=' . $gateOutput);
t('gate reports rate=', str_contains($gateOutput, 'rate='), $gateOutput);

// ── MUST-REFUSE: the defect this gate existed to miss ────────────────────────────────
// A module that never appears must not quietly leave the denominator. The old rule
// derived it from the printed lines, so an incomplete fleet scored HIGHER, not lower.
$victimId = $parsed['pass'][0] ?? '';
// Strip ANSI for the derived fixtures. The real line is `\e[32m\xe2\x9c\x93 PASS\e[0m  <id>  (n/m)`
// - an escape reset sits BETWEEN `PASS` and the id, so a naive `PASS\s+<id>` never matches it.
$plainOutput = preg_replace('/\e\[[\d;]*m/', '', $certifyOutput) ?? $certifyOutput;
$allLines = preg_split('/\R/', $plainOutput) ?: [];
$oneMissing = implode(PHP_EOL, array_values(array_filter(
    $allLines,
    static fn (string $line): bool => !preg_match('/PASS\s+' . preg_quote($victimId, '/') . '\s+\(/', $line)
)));
t('fixture really dropped one module', substr_count($oneMissing, 'PASS') === $passCount - 1, 'victim=' . $victimId);

[$exitMissing, $outMissing] = runGateOnReport($projectRoot, $oneMissing, 0);
t('gate REFUSES a report missing one module', $exitMissing !== 0, 'exit=' . $exitMissing . ' output=' . $outMissing);
t('the refusal names the missing module', str_contains($outMissing, 'missing=' . $victimId), $outMissing);

// Exactly the old numeric floor: 60 of the fleet, which used to be certified as "100%".
$firstSixty = implode(PHP_EOL, array_slice($allLines, 0, 60));
[$exitSixty, $outSixty] = runGateOnReport($projectRoot, $firstSixty, 0);
t('gate REFUSES the old 60-module result', $exitSixty !== 0, 'exit=' . $exitSixty . ' output=' . $outSixty);

// A module in the report that does not exist on disk is also a mismatch, not a free pass.
$phantom = $plainOutput . PHP_EOL . '  ✓ PASS  phantom-module-that-does-not-exist  (13/13)';
[$exitPhantom, $outPhantom] = runGateOnReport($projectRoot, $phantom, 0);
t('gate REFUSES a phantom module in the report', $exitPhantom !== 0, 'exit=' . $exitPhantom . ' output=' . $outPhantom);

$exitNonZero = runGateOnReport($projectRoot, $certifyOutput, 7)[0];
t('gate REFUSES a non-zero certify exit', $exitNonZero !== 0, 'exit=' . $exitNonZero);


$appLog = trim((string) @file_get_contents(STORAGE_PATH . '/logs/app.log'));
$errorLog = trim((string) @file_get_contents(STORAGE_PATH . '/logs/error.log'));
t('app.log remains empty', $appLog === '', $appLog !== '' ? substr($appLog, 0, 200) : '');
t('error.log remains empty', $errorLog === '', $errorLog !== '' ? substr($errorLog, 0, 200) : '');

echo sprintf("Counts: pass=%d fail=%d rate=%.1f%%\n", $passCount, $failCount, $passRate * 100);
echo "Assertions: {$assertions}\n";

exit($failures === 0 ? 0 : 1);
