<?php
declare(strict_types=1);

/**
 * Probe: does DiSyL render cost track template size/complexity?
 *
 * Why this exists: the instrumented compiled path measured a light themed page at 2.49 ms
 * (modules/cms/public/404.disyl) and a probe page at 3.19 ms. Two light templates do not establish
 * whether the RENDER is a meaningful share of a page, and heavy templates are where render cost would
 * live. This renders the heaviest real templates in the repo and reports what each costs.
 *
 * It also reports which pipeline each template took, read from the log rather than assumed:
 *   cache_path=compiled        -> the compiled path (instrumented 2026-10-10)
 *   disyl.compile.phases       -> the interpreted path ran instead (compiled-ineligible)
 * Those are different costs and must not be blended.
 *
 * Method notes:
 * - A FRESH TemplateEngine per template, so the per-request in-memory output cache cannot
 *   short-circuit a later render and understate its cost.
 * - Threshold forced to 0 in-process, since this repo's .env sets 10 and would silently suppress
 *   renders under 10 ms.
 * - Renders with a minimal context. These are admin templates whose real context comes from handlers,
 *   so a throw is expected for some: that is reported, not swallowed. The point is cost scaling, and
 *   a template that cannot render at all is not a cost measurement.
 * - Read-only: renders and reads the log. Writes nothing but the log the app already writes.
 *
 *   php docs/reviews/perf-metrics-2026-10-10/probe-template-render-cost.php
 */

require __DIR__ . '/../../../bootstrap.php';

use Ikabud\Kernel\DiSyL\TemplateEngine;

$templateDir = defined('TEMPLATES_PATH') ? TEMPLATES_PATH : __DIR__ . '/../../../templates';
$cacheDir = rtrim(sys_get_temp_dir(), '/') . '/ikabud-template-cost-probe';
$logPath = (defined('STORAGE_PATH') ? STORAGE_PATH : __DIR__ . '/../../../storage') . '/logs/app.log';

// Forced: the repo's .env threshold of 10 would suppress everything measured here.
$_ENV['APP_TIMING_LOGS'] = 'true';
$_ENV['APP_TIMING_THRESHOLD_MS'] = '0';

// The known light baseline first, then heaviest-by-size, then the most tag-dense.
$targets = [
    'modules/cms/public/404.disyl',                 // known baseline: 2.49 ms via HTTP
    'pages/_perf-probe.disyl',                      // known baseline: 3.19 ms via HTTP
    'pages/admin-kernel-triggers.disyl',
    'modules/dc-cafe/pos/index.disyl',
    'modules/daily-ledger/cashier/ledger.disyl',
    'modules/cms/admin/content-editor.disyl',
    'modules/daily-ledger/admin/commissary.disyl',  // most tag-dense: 167
    'modules/bakeshop/pages/supervisor.disyl',
    'modules/cms/admin/theme-customizer.disyl',     // largest: 371 KB
];

$context = [
    'page_title' => '__cost_probe__',
    'base_url' => function_exists('external_base_url') ? external_base_url() : '',
];

$rows = [];
foreach ($targets as $template) {
    $path = $templateDir . '/' . $template;
    if (!is_file($path)) {
        $rows[] = [$template, 0, 0, 'MISSING', '-', '-', '-', '-', '-', ''];
        continue;
    }

    $bytes = (int)filesize($path);
    $tags = (int)preg_match_all('/\{(?:if|foreach|for|each|switch|set|include|extends|block) /', (string)file_get_contents($path));

    // ONE engine, rendered TWICE. The first render pays compiled-mode boot and the eligibility walk;
    // the second is the steady-state cost of the same template. Reporting only the first would
    // attribute a per-request one-off to every template measured.
    $engine = new TemplateEngine($templateDir, $cacheDir, true);
    $engine->enableCompiledMode(true);

    $status = 'ok';
    $result = [];

    foreach (['cold', 'warm'] as $pass) {
        $mark = @filesize($logPath) ?: 0;
        $started = hrtime(true);
        try {
            $html = $engine->render($template, $context);
            $result[$pass] = [
                'wall' => (hrtime(true) - $started) / 1e6,
                'out' => strlen($html),
                'pipeline' => '-',
                'engine_ms' => '-',
                'reason' => '',
            ];
        } catch (Throwable $e) {
            $status = 'THREW: ' . substr($e->getMessage(), 0, 40);
            $result[$pass] = ['wall' => (hrtime(true) - $started) / 1e6, 'out' => 0, 'pipeline' => '-', 'engine_ms' => '-', 'reason' => ''];
            break;
        }

        // Which pipeline ran and what the ENGINE says it cost, read from the lines THIS render
        // emitted rather than inferred from the wall clock.
        $handle = @fopen($logPath, 'r');
        $newLines = '';
        if ($handle !== false) {
            fseek($handle, $mark);
            $newLines = (string)stream_get_contents($handle);
            fclose($handle);
        }
        // ORDER MATTERS, and getting it wrong here hid a real defect on the first run of this probe.
        // A compiled FAILURE logs disyl.compile.fallback and then falls through to the interpreted
        // path, which emits disyl.compile.phases - so testing for the compiled marker, then phases,
        // labels a failure as a plain "interpreted" render. That is how a compiled-path bug turned
        // into what looked like a deliberate pipeline choice. Fallback is checked first.
        if (str_contains($newLines, 'disyl.compile.fallback')) {
            $result[$pass]['pipeline'] = 'compiled->FAILED->interp';
            if (preg_match('/"reason":"([^"]{0,54})/', $newLines, $m)) {
                $result[$pass]['reason'] = $m[1];
            }
            if (preg_match('/"total_ms":([0-9.]+)/', $newLines, $m)) {
                $result[$pass]['engine_ms'] = $m[1];
            }
        } elseif (str_contains($newLines, '"cache_path":"compiled"')) {
            $result[$pass]['pipeline'] = 'compiled';
            if (preg_match('/"cache_path":"compiled".*?"duration_ms":([0-9.]+)/s', $newLines, $m)
                || preg_match('/"duration_ms":([0-9.]+),"request_id"/s', $newLines, $m)) {
                $result[$pass]['engine_ms'] = $m[1];
            }
        } elseif (str_contains($newLines, 'disyl.compile.phases')) {
            $result[$pass]['pipeline'] = 'interpreted';
            if (preg_match('/"total_ms":([0-9.]+)/', $newLines, $m)) {
                $result[$pass]['engine_ms'] = $m[1];
            }
        }
    }

    $cold = $result['cold'];
    $warm = $result['warm'] ?? $cold;
    $rows[] = [
        $template,
        $bytes,
        $tags,
        $status,
        $cold['pipeline'],
        number_format($cold['wall'], 2),
        number_format($warm['wall'], 2),
        (string)$warm['engine_ms'],
        $warm['out'] ?: '-',
        (string)($cold['reason'] ?? ''),
    ];
}

echo "DiSyL render cost by template — PHP " . PHP_VERSION . PHP_EOL;
echo "cold = first render on a fresh engine (pays compiled boot + eligibility walk)" . PHP_EOL;
echo "warm = second render, same engine (steady state)" . PHP_EOL;
echo "engine_ms = what the ENGINE reported for the warm render (log duration_ms/total_ms), i.e. the\n";
echo "work strictly inside the measured path — compare against warm wall to see what it EXCLUDES.\n";
echo "'compiled->FAILED->interp' means the compiled render THREW and fell back: not a pipeline choice.\n\n";
echo "template                                     bytes  tags  pipeline                 cold_ms   warm_ms  engine_ms  out_bytes  status\n";
echo str_repeat('-', 140) . PHP_EOL;
foreach ($rows as [$t, $b, $g, $s, $p, $c, $w, $e, $o, $r]) {
    printf("%-44s %7s %5s  %-23s %8s %9s %10s %10s  %s\n", $t, number_format($b), $g ?: '-', $p, $c, $w, $e, $o, $s);
    if ($r !== '') {
        echo '    fallback reason: ' . $r . PHP_EOL;
    }
}
