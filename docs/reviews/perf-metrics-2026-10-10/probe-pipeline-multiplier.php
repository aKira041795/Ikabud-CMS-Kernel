<?php
declare(strict_types=1);

/**
 * What does the interpreted pipeline actually COST, on the same document?
 *
 * 4j asserted the 76 compiled-ineligible templates sit "on an ~10x slower path". That 10x is quoted from
 * a comment in TemplateCompiler.php ("Compiled templates are 10-50x faster than interpreted rendering"),
 * i.e. it is a claim about the compiler, not a measurement of THESE templates. Sizing the remaining perf
 * question needs the real multiplier.
 *
 * Method — the comparison is SAME TEMPLATE, BOTH PIPELINES, so template size and tag density cancel and
 * only the pipeline differs:
 * - both arms render the identical document with an identical context;
 * - alternating order per round, so a drifting host cannot favour one arm;
 * - MEDIAN and RANGE reported, never a single reading — this host moves 10-30% between samples;
 * - every render is checked against its own log line, and a render that took the WRONG pipeline is
 *   discarded rather than averaged in. That check matters here: a compiled-ineligible template silently
 *   ignores the compiled arm, and a fallback silently substitutes interpreted. Either one would make the
 *   ratio a comparison of interpreted against interpreted.
 * - round 0 is discarded as warm-up, so what is reported is steady state, not compilation.
 *
 *   php docs/reviews/perf-metrics-2026-10-10/probe-pipeline-multiplier.php
 */

require __DIR__ . '/../../../bootstrap.php';

use Ikabud\Kernel\DiSyL\TemplateEngine;

$templateDir = defined('TEMPLATES_PATH') ? TEMPLATES_PATH : __DIR__ . '/../../../templates';
$cacheDir = rtrim(sys_get_temp_dir(), '/') . '/ikabud-pipeline-multiplier';
$logPath = (defined('STORAGE_PATH') ? STORAGE_PATH : __DIR__ . '/../../../storage') . '/logs/app.log';

$_ENV['APP_TIMING_LOGS'] = 'true';
$_ENV['APP_TIMING_THRESHOLD_MS'] = '0';

// Compiled-eligible templates of increasing size, so the multiplier can be seen across scale.
$targets = [
    'modules/cms/public/404.disyl',
    'pages/admin-kernel-triggers.disyl',
    'modules/dc-cafe/pos/index.disyl',
    'modules/daily-ledger/cashier/ledger.disyl',
    'modules/daily-ledger/admin/commissary.disyl',
    'modules/cms/admin/content-editor.disyl',
    'modules/bakeshop/pages/supervisor.disyl',
    'modules/cms/admin/theme-customizer.disyl',
];

$context = [
    'page_title' => '__pipeline_multiplier__',
    'base_url' => function_exists('external_base_url') ? external_base_url() : '',
];

$ROUNDS = 6;

/**
 * One render on a FRESH engine, so no in-memory cache can short-circuit it.
 * Returns [ms, observed_cache_path] or [null, 'THREW'].
 */
function renderOnce(string $templateDir, string $cacheDir, string $template, array $context, string $logPath, bool $compiled): array
{
    $mark = @filesize($logPath) ?: 0;

    $engine = new TemplateEngine($templateDir, $cacheDir, true);
    $engine->enableCompiledMode($compiled);

    $ms = null;
    try {
        $t0 = microtime(true);
        $engine->render($template, $context);
        $ms = (microtime(true) - $t0) * 1000;
    } catch (Throwable $e) {
        return [null, 'THREW'];
    }

    $handle = @fopen($logPath, 'r');
    $observed = 'no log line';
    if ($handle !== false) {
        fseek($handle, $mark);
        $new = (string)stream_get_contents($handle);
        fclose($handle);
        if (str_contains($new, 'disyl.compile.fallback')) {
            $observed = 'FALLBACK';
        } elseif (preg_match('/"cache_path":"([a-z_]+)"/', $new, $m)) {
            $observed = $m[1];
        } elseif (str_contains($new, 'disyl.compile.phases')) {
            $observed = 'interpreted';
        }
    }

    return [$ms, $observed];
}

function median(array $xs): float
{
    sort($xs);
    $n = count($xs);
    if ($n === 0) {
        return 0.0;
    }
    $mid = intdiv($n, 2);
    return $n % 2 === 1 ? $xs[$mid] : ($xs[$mid - 1] + $xs[$mid]) / 2;
}

printf('multiplier probe: same template, both pipelines, %d rounds (round 0 discarded)%s%s', $ROUNDS, PHP_EOL, PHP_EOL);
printf("%-44s %9s %9s %9s %8s  %s\n", 'template', 'comp_ms', 'int_ms', 'ratio', 'ms_delta', 'pipeline check');
printf("%s\n", str_repeat('-', 110));

$rows = [];
foreach ($targets as $template) {
    $path = $templateDir . '/' . $template;
    if (!is_file($path)) {
        printf("%-44s %s\n", $template, 'MISSING');
        continue;
    }

    $compiledMs = [];
    $interpretedMs = [];
    $observed = [];

    for ($r = 0; $r < $ROUNDS; $r++) {
        // Alternate which arm runs first each round: a monotonic host drift then hits both equally.
        $order = ($r % 2 === 0) ? [true, false] : [false, true];
        foreach ($order as $compiled) {
            [$ms, $cachePath] = renderOnce($templateDir, $cacheDir, $template, $context, $logPath, $compiled);
            $expected = $compiled ? 'compiled' : 'interpreted';
            $observed[$expected][$cachePath] = ($observed[$expected][$cachePath] ?? 0) + 1;

            if ($r === 0 || $ms === null) {
                continue; // warm-up, or a render that threw
            }
            if ($cachePath !== $expected && !($expected === 'interpreted' && $cachePath === 'interpreted_cached')) {
                continue; // took the wrong pipeline — averaging it in would corrupt the ratio
            }
            if ($compiled) {
                $compiledMs[] = $ms;
            } else {
                $interpretedMs[] = $ms;
            }
        }
    }

    $c = median($compiledMs);
    $i = median($interpretedMs);
    $ratio = $c > 0 ? $i / $c : 0;

    $check = [];
    foreach ($observed as $arm => $paths) {
        $check[] = $arm . ':' . implode('/', array_keys($paths));
    }

    printf(
        "%-44s %9.2f %9.2f %8.2fx %8.2f  %s\n",
        $template,
        $c,
        $i,
        $ratio,
        $i - $c,
        implode('  ', $check)
    );
    $rows[] = [$template, $c, $i, $ratio, $i - $c];

    if ($compiledMs !== []) {
        printf("%-44s   compiled range %.2f-%.2f ms over %d | interpreted range %.2f-%.2f ms over %d%s",
            '', min($compiledMs), max($compiledMs), count($compiledMs),
            min($interpretedMs), max($interpretedMs), count($interpretedMs), PHP_EOL);
    }
}

echo PHP_EOL . '=== summary ===' . PHP_EOL;
if ($rows !== []) {
    $ratios = array_column($rows, 3);
    $deltas = array_column($rows, 4);
    printf("  median ratio across templates : %.2fx%s", median($ratios), PHP_EOL);
    printf("  loudest                       : %.2fx (%s)%s", max($ratios), $rows[array_search(max($ratios), $ratios)][0], PHP_EOL);
    printf("  largest absolute delta        : +%.2f ms (%s)%s", max($deltas), $rows[array_search(max($deltas), $deltas)][0], PHP_EOL);
}

echo PHP_EOL . 'A render whose observed pipeline did not match its arm was DISCARDED, not averaged in.' . PHP_EOL;
echo 'The "pipeline check" column is the evidence that both arms ran the pipeline they claimed.' . PHP_EOL;
