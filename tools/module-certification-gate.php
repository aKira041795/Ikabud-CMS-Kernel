<?php

declare(strict_types=1);

const IKABUD_MODULE_CERTIFICATION_MIN_PASS_COUNT = 60;

/**
 * @return array{pass: list<string>, fail: list<string>, lines: int}
 */
function ikabudModuleCertificationParseReport(string $output): array
{
    $pass = [];
    $fail = [];
    $lines = preg_split('/\R/', $output) ?: [];

    foreach ($lines as $line) {
        $line = preg_replace('/\e\[[\d;]*m/', '', $line) ?? $line;
        if (!preg_match('/^\s*(?:[✓✗]+\s*)?(PASS|FAIL)\s+([a-z0-9-]+)\s+\(\d+\/\d+\)/u', $line, $matches)) {
            continue;
        }

        $status = $matches[1];
        $moduleId = $matches[2];
        if ($status === 'PASS') {
            $pass[] = $moduleId;
            continue;
        }

        $fail[] = $moduleId;
    }

    return [
        'pass' => $pass,
        'fail' => $fail,
        'lines' => count($lines),
    ];
}

/**
 * @return array{command: string, output: string, exit_code: int, pass_count: int, fail_count: int, modules: int, pass_rate: float, failing_modules: list<string>, passed: bool}
 */
function ikabudModuleCertificationGateRun(?string $projectRoot = null): array
{
    $projectRoot ??= dirname(__DIR__);
    $overrideOutputFile = getenv('IKABUD_MODULE_CERTIFICATION_GATE_OUTPUT_FILE');
    $overrideExitCode = getenv('IKABUD_MODULE_CERTIFICATION_GATE_EXIT_CODE');
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($projectRoot . '/ikabud') . ' module:certify --all';

    if (is_string($overrideOutputFile) && $overrideOutputFile !== '') {
        $output = (string) file_get_contents($overrideOutputFile);
        $exitCode = is_numeric($overrideExitCode) ? (int) $overrideExitCode : 0;
    } else {
        $lines = [];
        exec($command . ' 2>&1', $lines, $exitCode);
        $output = implode(PHP_EOL, $lines);
    }

    $parsed = ikabudModuleCertificationParseReport($output);
    $passCount = count($parsed['pass']);
    $failCount = count($parsed['fail']);
    $modules = $passCount + $failCount;
    $passRate = $modules > 0 ? $passCount / $modules : 0.0;
    $passed = $exitCode === 0
        && $failCount === 0
        && $passCount >= IKABUD_MODULE_CERTIFICATION_MIN_PASS_COUNT;

    return [
        'command' => $command,
        'output' => $output,
        'exit_code' => $exitCode,
        'pass_count' => $passCount,
        'fail_count' => $failCount,
        'modules' => $modules,
        'pass_rate' => $passRate,
        'failing_modules' => array_slice($parsed['fail'], 0, 10),
        'passed' => $passed,
    ];
}

function ikabudModuleCertificationGateFormat(array $result): string
{
    $summary = sprintf(
        'modules=%d pass=%d fail=%d rate=%.1f%%',
        $result['modules'],
        $result['pass_count'],
        $result['fail_count'],
        $result['pass_rate'] * 100
    );

    if ($result['passed']) {
        return 'PASS ' . $summary;
    }

    $failing = $result['failing_modules'] === [] ? 'none' : implode(',', $result['failing_modules']);
    return sprintf(
        'FAIL %s certify_exit=%d min_pass=%d failing=%s',
        $summary,
        $result['exit_code'],
        IKABUD_MODULE_CERTIFICATION_MIN_PASS_COUNT,
        $failing
    );
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $result = ikabudModuleCertificationGateRun();
    echo ikabudModuleCertificationGateFormat($result) . PHP_EOL;
    exit($result['passed'] ? 0 : 1);
}
