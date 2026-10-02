<?php

declare(strict_types=1);

/**
 * The module fleet that EXISTS - and therefore the only honest denominator.
 *
 * The gate used to derive its denominator from the lines the certify run happened to
 * print (`$modules = $passCount + $failCount`). A module that failed to appear did not
 * become a FAIL; it left the denominator entirely. So the reported pass-rate went UP
 * when the fleet was incomplete, and enough missing modules could certify a materially
 * broken fleet at "100%". A gate must score the fleet that exists, not the subset that
 * turned up to be counted.
 *
 * The root mirrors the kernel's own discovery: `discoverModulesScanAll()` scans
 * `modulesPath()`. So this scans `modules/` only - manifests under `packages/` are not
 * part of the certified fleet (matching `module:certify --all`), and vendored
 * `node_modules` manifests are skipped.
 *
 * @return list<string>
 */
function ikabudModuleCertificationExpectedModuleIds(?string $projectRoot = null): array
{
    $root = ($projectRoot ?? dirname(__DIR__)) . '/modules';
    if (!is_dir($root)) {
        return [];
    }

    $ids = [];
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($files as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile() || $file->getFilename() !== 'module.json') {
            continue;
        }

        $path = $file->getPathname();
        if (str_contains($path, DIRECTORY_SEPARATOR . 'node_modules' . DIRECTORY_SEPARATOR)) {
            continue;
        }

        // Prefer the manifest's own id; fall back to the directory name so a malformed
        // manifest is still counted as expected rather than silently skipped.
        $manifest = json_decode((string) @file_get_contents($path), true);
        $id = is_array($manifest) && isset($manifest['id']) && is_string($manifest['id'])
            ? $manifest['id']
            : basename(dirname($path));

        if ($id !== '') {
            $ids[] = $id;
        }
    }

    $ids = array_values(array_unique($ids));
    sort($ids);

    return $ids;
}

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
 * @return array{command: string, output: string, exit_code: int, pass_count: int, fail_count: int, modules: int, pass_rate: float, failing_modules: list<string>, missing_modules: list<string>, unexpected_modules: list<string>, passed: bool}
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

    // The denominator is the fleet that exists, never the subset that reported.
    $expected = ikabudModuleCertificationExpectedModuleIds($projectRoot);
    $reported = array_values(array_unique(array_merge($parsed['pass'], $parsed['fail'])));
    $missing = array_values(array_diff($expected, $reported));
    $unexpected = array_values(array_diff($reported, $expected));
    $modules = count($expected);

    // Of the fleet that exists, how much passed. Reported modules that are not in the
    // fleet cannot inflate the rate.
    $expectedPassCount = count(array_intersect($parsed['pass'], $expected));
    $passRate = $modules > 0 ? $expectedPassCount / $modules : 0.0;

    // Every module that exists must report, and none may fail. There is deliberately no
    // numeric floor any more: with a denominator of the whole fleet and `fail=0`, a floor
    // below the fleet size can only ever excuse modules that are simply absent - which is
    // the defect it used to hide. If a module is ever legitimately exempt, it must be an
    // explicit named exemption here, so it is visible and reviewable, not a silent count.
    $passed = $exitCode === 0
        && $failCount === 0
        && $modules > 0
        && $missing === []
        && $unexpected === [];

    return [
        'command' => $command,
        'output' => $output,
        'exit_code' => $exitCode,
        'pass_count' => $passCount,
        'fail_count' => $failCount,
        'modules' => $modules,
        'pass_rate' => $passRate,
        'failing_modules' => array_slice($parsed['fail'], 0, 10),
        'missing_modules' => array_slice($missing, 0, 10),
        'unexpected_modules' => array_slice($unexpected, 0, 10),
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

    // Name the difference. "failing" alone cannot distinguish a module that failed from
    // one that never reported, and that distinction is the whole point of this gate.
    $detail = [];
    if ($result['failing_modules'] !== []) {
        $detail[] = 'failing=' . implode(',', $result['failing_modules']);
    }
    if ($result['missing_modules'] !== []) {
        $detail[] = 'missing=' . implode(',', $result['missing_modules']);
    }
    if ($result['unexpected_modules'] !== []) {
        $detail[] = 'unexpected=' . implode(',', $result['unexpected_modules']);
    }
    if ($detail === []) {
        $detail[] = 'failing=none';
    }

    return sprintf(
        'FAIL %s certify_exit=%d %s',
        $summary,
        $result['exit_code'],
        implode(' ', $detail)
    );
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $result = ikabudModuleCertificationGateRun();
    echo ikabudModuleCertificationGateFormat($result) . PHP_EOL;
    exit($result['passed'] ? 0 : 1);
}
