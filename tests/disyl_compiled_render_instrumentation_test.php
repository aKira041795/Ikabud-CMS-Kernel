<?php

declare(strict_types=1);

/**
 * Does the COMPILED render path emit any timing at all?
 *
 * The defect this pins: TemplateEngine::render() takes the compiled branch at lines 421-484 and
 * returns at :484 without calling compile(). Both existing timing lines sit on the OTHER paths —
 * disyl.render.breakdown from the APCu output-hit site (:400) and the interpreted site (:540), and
 * disyl.compile.phases from the end of compile() (:935). So when the compiled path serves a render,
 * nothing is logged. Compiled mode is the default (DISYL_COMPILED_MODE=true, "compiled mode is the
 * default (v4.7+)" at :415), so the default path is the unmeasured one.
 *
 * THIS TEST IS DESIGNED TO FAIL BEFORE THE INSTRUMENT EXISTS. Run it on the unfixed tree and it must
 * report FAIL - if it passes there, it is asserting something that was already true and proves
 * nothing about the change.
 *
 * It also carries a vacuity guard, because "no compiled line" has two very different causes:
 *   1. the compiled path ran and is uninstrumented  <- the defect
 *   2. the template was interpreted, so the compiled path never ran  <- not a defect, wrong fixture
 * A test that cannot tell those apart would report a false pass the moment the fixture stopped being
 * compiled-eligible. So the compiled-eligibility of the fixture is asserted through the log itself:
 * a disyl.compile.phases line means the interpreted pipeline ran, and that fails the test with a
 * distinct reason rather than passing quietly.
 *
 * Environment is forced in-process; .env is not touched. log_timing()/timing_logs_enabled() read
 * $_ENV per call, and .env already sets DISYL_SHARED_OUTPUT_TTL=0, which keeps the APCu output-cache
 * branch (and its log line) out of the picture so the three paths cannot be confused.
 */

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/http/perf-probe.php';

$pass = 0;
$fail = 0;

function compiledRenderAssert(string $name, bool $condition, string $detail = ''): void
{
    global $pass, $fail;
    echo ($condition ? 'PASS' : 'FAIL') . ': ' . $name . ($detail !== '' ? ' — ' . $detail : '') . PHP_EOL;
    $condition ? $pass++ : $fail++;
}

$logPath = (defined('STORAGE_PATH') ? STORAGE_PATH : __DIR__ . '/../storage') . '/logs/app.log';
$fixture = 'pages/_perf-probe.disyl';
$fixturePath = (defined('TEMPLATES_PATH') ? TEMPLATES_PATH : __DIR__ . '/../templates') . '/' . $fixture;

// Forced, so the measurement does not depend on the operator's .env threshold.
$_ENV['APP_TIMING_LOGS'] = 'true';
$_ENV['APP_TIMING_THRESHOLD_MS'] = '0';

echo 'fixture: ' . $fixture . PHP_EOL;
echo 'compiled mode env: DISYL_COMPILED_MODE=' . ($_ENV['DISYL_COMPILED_MODE'] ?? '(unset)')
    . ' DISYL_EXTENDS_COMPILED=' . ($_ENV['DISYL_EXTENDS_COMPILED'] ?? '(unset)') . PHP_EOL;

if (!is_file($fixturePath)) {
    compiledRenderAssert('fixture template exists', false, $fixturePath . ' not found');
    echo 'Total: ' . ($pass + $fail) . ' PASS: ' . $pass . ' FAIL: ' . $fail . PHP_EOL;
    exit(1);
}

// Start from an empty log so every line counted below was produced by this test.
@file_put_contents($logPath, '');

// Render twice: the first may compile into the cache, the second is the warm case. Instrumentation
// must fire on both, so the assertion is on the warm one and the cool one is reported too.
$rendered = 0;
for ($i = 0; $i < 2; $i++) {
    try {
        $result = kernelPerfProbeDisylRender();
        if (!empty($result['ok'])) {
            $rendered++;
        }
    } catch (Throwable $e) {
        compiledRenderAssert('render completed', false, $e->getMessage());
    }
}

$log = (string)@file_get_contents($logPath);

$compiledLines = 0;
$interpretedPhaseLines = 0;
$templateNeedle = '"template":"' . $fixture . '"';
foreach (preg_split('/\r?\n/', $log) ?: [] as $line) {
    if ($line === '') {
        continue;
    }
    // Matching the template as well as the message: an unrelated compiled render elsewhere in the
    // process must not be able to satisfy this assertion.
    if (str_contains($line, 'disyl.render.breakdown')
        && str_contains($line, '"cache_path":"compiled"')
        && str_contains($line, $templateNeedle)) {
        $compiledLines++;
    }
    if (str_contains($line, 'disyl.compile.phases')) {
        $interpretedPhaseLines++;
    }
}

compiledRenderAssert('the fixture rendered twice', $rendered === 2, "rendered={$rendered} of 2");

// Vacuity guard: if the interpreted pipeline ran, this fixture is not compiled-eligible and the
// compiled-instrumentation assertion below would be testing a path that never executed.
compiledRenderAssert(
    'fixture is compiled-eligible (interpreted pipeline did NOT run)',
    $interpretedPhaseLines === 0,
    $interpretedPhaseLines === 0
        ? 'no disyl.compile.phases, so the compiled path is the one under test'
        : "saw {$interpretedPhaseLines} disyl.compile.phases line(s) — fixture is on the interpreted pipeline, so this test cannot judge the compiled path"
);

// The assertion this whole file exists for. EXACTLY one line per render, not 'at least one': a
// mutation that logged only on the first render would still satisfy a > 0 check while leaving the
// warm path unmeasured, which is the case that matters most on a warm production host.
compiledRenderAssert(
    'both compiled renders emit disyl.render.breakdown with cache_path=compiled',
    $compiledLines === 2,
    $compiledLines === 0
        ? 'RENDERED BUT UNMEASURED: the compiled path produced no timing line (this is the defect the test must fail on)'
        : "{$compiledLines} line(s) for 2 renders"
);

echo PHP_EOL . 'log lines total: ' . count(array_filter(preg_split('/\r?\n/', $log) ?: [])) . PHP_EOL;
echo 'Total: ' . ($pass + $fail) . ' PASS: ' . $pass . ' FAIL: ' . $fail . PHP_EOL;
exit($fail === 0 ? 0 : 1);
