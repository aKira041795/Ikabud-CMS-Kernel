<?php
declare(strict_types=1);

/**
 * Where does the interpreted render actually spend its time — and is compileStyleBody quadratic?
 *
 * probe-pipeline-multiplier.php measured the interpreted path at a median 243x the compiled path
 * (worst 958x) on the SAME document. A ratio that large is not explained by "the compiler is good"; it
 * points at a specific pathological pass, so this probe attributes the cost instead of assuming it.
 *
 * Section A renders real compiled-INELIGIBLE templates (the 76 from probe-eligibility-reasons.php,
 * which includes the primary {ikb_} entity-view engine and the public CMS pages) through the interpreted
 * path and reports the engine's own per-phase breakdown, read from `disyl.compile.phases`.
 *
 * Section B calls compileStyleBody() directly with a style body of doubling size. If the cost per KB
 * grows with size, the pass is super-linear and that — not the interpreter in general — is the defect.
 * Two body shapes are tested because the suspected mechanism is a forward scan that only happens when a
 * `{` is NOT followed by an identifier:
 *   shape "ident" -> `.r{n}{color:red}`   : `{` is immediately followed by an identifier
 *   shape "space" -> `.r{n}{ }`           : `{` is followed by whitespace, forcing a forward scan
 *   shape "newline" -> multi-line rule    : the realistic shape for hand-written CSS
 *
 *   php docs/reviews/perf-metrics-2026-10-10/probe-interpreted-phases.php
 */

require __DIR__ . '/../../../bootstrap.php';

use Ikabud\Kernel\DiSyL\TemplateEngine;

$templateDir = defined('TEMPLATES_PATH') ? TEMPLATES_PATH : __DIR__ . '/../../../templates';
$cacheDir = rtrim(sys_get_temp_dir(), '/') . '/ikabud-interpreted-phases';
$logPath = (defined('STORAGE_PATH') ? STORAGE_PATH : __DIR__ . '/../../../storage') . '/logs/app.log';

$_ENV['APP_TIMING_LOGS'] = 'true';
$_ENV['APP_TIMING_THRESHOLD_MS'] = '0';

echo '===== SECTION A: real interpreted templates, phase attribution =====' . PHP_EOL . PHP_EOL;

$targets = [
    'modules/cms/public/home.disyl',
    'modules/cms/public/page.disyl',
    'modules/guidance/pages/cases.disyl',
    'modules/guidance/pages/dashboard.disyl',
    'modules/cms/admin/content-list.disyl',
    'modules/attendance-wage/wage/employees/index.disyl',
    'modules/daily-ledger/cashier/ledger.disyl',
];

$context = [
    'page_title' => '__phases_probe__',
    'base_url' => function_exists('external_base_url') ? external_base_url() : '',
];

printf("%-46s %9s  %s\n", 'template', 'total_ms', 'phase breakdown (ms, descending)');
printf("%s\n", str_repeat('-', 118));

$phaseTotals = [];
foreach ($targets as $template) {
    $path = $templateDir . '/' . $template;
    if (!is_file($path)) {
        printf("%-46s %9s  %s\n", $template, '-', 'MISSING');
        continue;
    }

    $mark = @filesize($logPath) ?: 0;
    $engine = new TemplateEngine($templateDir, $cacheDir, true);
    $engine->enableCompiledMode(true); // compiled mode ON: this proves ineligibility routes it to interpreted

    $ms = null;
    $note = '';
    try {
        $t0 = microtime(true);
        $engine->render($template, $context);
        $ms = (microtime(true) - $t0) * 1000;
    } catch (Throwable $e) {
        $note = 'THREW: ' . substr($e->getMessage(), 0, 40);
    }

    $handle = @fopen($logPath, 'r');
    $new = '';
    if ($handle !== false) {
        fseek($handle, $mark);
        $new = (string)stream_get_contents($handle);
        fclose($handle);
    }

    if (!preg_match('/disyl\.compile\.phases (\{.*?\})\s*$/m', $new, $m)) {
        printf("%-46s %9s  %s\n", $template, $ms === null ? '-' : sprintf('%.1f', $ms), 'no phases line ' . $note);
        continue;
    }
    $phases = json_decode($m[1], true);
    if (!is_array($phases)) {
        printf("%-46s %9s  %s\n", $template, sprintf('%.1f', $ms ?? 0), 'unparseable phases');
        continue;
    }

    $total = (float)($phases['total_ms'] ?? 0);
    $parts = [];
    foreach ($phases as $k => $v) {
        if (is_numeric($v) && str_ends_with((string)$k, '_ms') && $k !== 'total_ms' && (float)$v > 0.05) {
            $parts[$k] = (float)$v;
            $phaseTotals[$k] = ($phaseTotals[$k] ?? 0) + (float)$v;
        }
    }
    arsort($parts);
    $shown = [];
    foreach (array_slice($parts, 0, 4, true) as $k => $v) {
        $pct = $total > 0 ? ($v / $total * 100) : 0;
        $shown[] = sprintf('%s=%.1f (%.0f%%)', rtrim($k, '_ms'), $v, $pct);
    }

    printf("%-46s %9.1f  %s%s\n", $template, $total, implode('  ', $shown), $note !== '' ? '  ' . $note : '');
}

echo PHP_EOL . 'phase totals across the templates above:' . PHP_EOL;
arsort($phaseTotals);
foreach (array_slice($phaseTotals, 0, 8, true) as $k => $v) {
    printf("   %-22s %9.1f ms\n", rtrim((string)$k, '_ms'), $v);
}

echo PHP_EOL . PHP_EOL . '===== SECTION B: is compileStyleBody() super-linear? =====' . PHP_EOL . PHP_EOL;

$probeEngine = new TemplateEngine($templateDir, $cacheDir, true);
$method = new ReflectionMethod(TemplateEngine::class, 'compileStyleBody');
$method->setAccessible(true);

/**
 * Build a style body of roughly $bytes with a DiSyL construct present (so the guarded expensive path
 * runs) and $rule-braces in the requested shape.
 */
function buildBody(int $bytes, string $shape): string
{
    $head = '/* {$accent} */';       // a real DiSyL construct => bodyContainsDisylConstruct() is true
    $rules = '';
    $i = 0;
    while (strlen($head . $rules) < $bytes) {
        $i++;
        $rules .= match ($shape) {
            'ident'   => ".r{$i}{color:red}\n",
            'space'   => ".r{$i}{ }\n",
            'newline' => ".r{$i}{\n  color: red;\n  margin: 0;\n}\n",
            default   => ".r{$i}{ }\n",
        };
    }
    return $head . $rules;
}

printf("%-10s %10s %12s %12s %12s\n", 'shape', 'bytes', 'braces', 'median_ms', 'ms/KB');
printf("%s\n", str_repeat('-', 62));

foreach (['ident', 'space', 'newline'] as $shape) {
    $prevMsPerKb = null;
    foreach ([4000, 8000, 16000, 32000] as $targetBytes) {
        $body = buildBody($targetBytes, $shape);
        $actualBytes = strlen($body);
        $braces = substr_count($body, '{');

        $timings = [];
        for ($r = 0; $r < 3; $r++) {
            $t0 = microtime(true);
            try {
                $method->invoke($probeEngine, $body, ['accent' => '#fff']);
            } catch (Throwable $e) {
                $timings = [];
                break;
            }
            $timings[] = (microtime(true) - $t0) * 1000;
        }
        if ($timings === []) {
            printf("%-10s %10d %12d %12s %12s\n", $shape, $actualBytes, $braces, 'THREW', '-');
            continue;
        }
        sort($timings);
        $med = $timings[1];
        $msPerKb = $med / ($actualBytes / 1024);

        $growth = '';
        if ($prevMsPerKb !== null) {
            $growth = sprintf(' (%.2fx per doubling of ms/KB)', $msPerKb / $prevMsPerKb);
        }
        $prevMsPerKb = $msPerKb;

        printf("%-10s %10d %12d %12.2f %12.4f%s\n", $shape, $actualBytes, $braces, $med, $msPerKb, $growth);
    }
    echo PHP_EOL;
}

echo 'ms/KB flat across doublings => linear. ms/KB doubling => quadratic. That is the test.' . PHP_EOL;
