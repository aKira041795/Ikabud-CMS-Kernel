<?php
declare(strict_types=1);

/**
 * One template, everything: which pipeline, how many ms, how much inline <style>, and the engine's own
 * phase breakdown.
 *
 * Exists because two probes disagreed about modules/cms/public/home.disyl (0.5 ms in
 * probe-interpreted-phases.php section A vs 22.21 ms in probe-ineligible-cost.php) and a disagreement
 * between instruments has to be resolved before either number is used. Guessing at the cause is how a
 * wrong conclusion gets written down.
 *
 *   php docs/reviews/perf-metrics-2026-10-10/probe-one-template.php modules/cms/public/home.disyl [renders]
 */

require __DIR__ . '/../../../bootstrap.php';

use Ikabud\Kernel\DiSyL\TemplateEngine;

$templateDir = defined('TEMPLATES_PATH') ? TEMPLATES_PATH : __DIR__ . '/../../../templates';
$cacheDir = rtrim(sys_get_temp_dir(), '/') . '/ikabud-one-template';
$logPath = (defined('STORAGE_PATH') ? STORAGE_PATH : __DIR__ . '/../../../storage') . '/logs/app.log';

$_ENV['APP_TIMING_LOGS'] = 'true';
$_ENV['APP_TIMING_THRESHOLD_MS'] = '0';

$template = $argv[1] ?? 'modules/cms/public/home.disyl';
$renders = (int)($argv[2] ?? 2);

$context = [
    'page_title' => '__one_template__',
    'base_url' => function_exists('external_base_url') ? external_base_url() : '',
];

/** All phases lines in a log segment, decoded. */
function allPhases(string $log): array
{
    if (!preg_match_all('/disyl\.compile\.phases (\{[^\n]*\})/', $log, $m)) {
        return [];
    }
    $out = [];
    foreach ($m[1] as $json) {
        $decoded = json_decode($json, true);
        if (is_array($decoded)) {
            $out[] = $decoded;
        }
    }
    return $out;
}

/**
 * The phases line belonging to the rendered document: the one whose content_bytes equals the bytes
 * returned. A render logs one line per nested include/layout, so "the first line" is an inner partial.
 */
function topLevelPhases(array $all, int $outBytes): array
{
    foreach ($all as $p) {
        if ((int)($p['content_bytes'] ?? -1) === $outBytes) {
            return [$p, 'matched content_bytes'];
        }
    }
    $best = [];
    foreach ($all as $p) {
        if ($best === [] || (int)($p['content_bytes'] ?? 0) > (int)($best['content_bytes'] ?? 0)) {
            $best = $p;
        }
    }
    return [$best, $all === [] ? 'no phases line' : 'NO content_bytes match - showing largest'];
}

printf('template: %s%s', $template, PHP_EOL);
printf('context keys: %s%s%s', implode(', ', array_keys($context)), PHP_EOL, PHP_EOL);
printf("%-4s %9s %10s %10s %12s  %s\n", 'rnd', 'wall_ms', 'out_bytes', 'css_bytes', 'total_ms', 'cache_path');
printf("%s\n", str_repeat('-', 82));

$lastOut = '';
$lastLog = '';

for ($r = 1; $r <= $renders; $r++) {
    $mark = @filesize($logPath) ?: 0;

    // Fresh engine every round: no in-memory output cache can carry state between rounds.
    $engine = new TemplateEngine($templateDir, $cacheDir, true);
    $engine->enableCompiledMode(true);

    try {
        $t0 = microtime(true);
        $out = $engine->render($template, $context);
        $ms = (microtime(true) - $t0) * 1000;
    } catch (Throwable $e) {
        printf("%-4d %9s  THREW %s%s", $r, '-', substr($e->getMessage(), 0, 60), PHP_EOL);
        continue;
    }

    $handle = @fopen($logPath, 'r');
    $new = '';
    if ($handle !== false) {
        fseek($handle, $mark);
        $new = (string)stream_get_contents($handle);
        fclose($handle);
    }

    $cachePath = 'none';
    if (str_contains($new, 'disyl.compile.fallback')) {
        $cachePath = 'FALLBACK';
    } elseif (preg_match('/"cache_path":"([a-z_]+)"/', $new, $m)) {
        $cachePath = $m[1];
    }

    $cssBytes = 0;
    if (preg_match_all('/<style\b[^>]*>(.*?)<\/style>/si', $out, $sm)) {
        foreach ($sm[1] as $body) {
            $cssBytes += strlen($body);
        }
    }

    [$top, $how] = topLevelPhases(allPhases($new), strlen($out));
    $total = isset($top['total_ms']) ? sprintf('%.2f', (float)$top['total_ms']) : '-';

    printf(
        "%-4d %9.2f %10d %10d %12s  %s%s\n",
        $r,
        $ms,
        strlen($out),
        $cssBytes,
        $total,
        $cachePath,
        $how === 'matched content_bytes' ? '' : '  [' . $how . ']'
    );

    $lastOut = $out;
    $lastLog = $new;
}

if ($lastOut === '') {
    echo 'nothing rendered' . PHP_EOL;
    exit(0);
}

echo PHP_EOL . '=== TOP-LEVEL phase breakdown (final round) ===' . PHP_EOL;
[$top, $how] = topLevelPhases(allPhases($lastLog), strlen($lastOut));
if ($top === []) {
    echo '  no phases line - the compiled path does not emit one' . PHP_EOL;
} else {
    echo '  selected by: ' . $how . '  (content_bytes=' . ($top['content_bytes'] ?? '?')
        . ', output=' . strlen($lastOut) . ')' . PHP_EOL . PHP_EOL;
    arsort($top);
    $total = (float)($top['total_ms'] ?? 0);
    foreach ($top as $k => $v) {
        if (!is_numeric($v)) {
            continue;
        }
        printf("   %-20s %9.2f  %5.1f%%\n", $k, (float)$v, $total > 0 ? ((float)$v / $total * 100) : 0);
    }
}

// Nested includes log their own phases line. Kept visible so a partial's cost is never read as the page's.
$others = [];
foreach (allPhases($lastLog) as $p) {
    if ((int)($p['content_bytes'] ?? -1) !== strlen($lastOut)) {
        $others[] = $p;
    }
}
if ($others !== []) {
    echo PHP_EOL . '=== nested phases lines in the same render (NOT the page) ===' . PHP_EOL;
    foreach ($others as $p) {
        printf(
            "   content_bytes=%-8d total_ms=%-8.2f %s\n",
            (int)($p['content_bytes'] ?? 0),
            (float)($p['total_ms'] ?? 0),
            array_key_exists('unattributed_ms', $p) ? 'unattributed=' . $p['unattributed_ms'] : ''
        );
    }
}

$inlineCss = 0;
if (preg_match_all('/<style\b[^>]*>(.*?)<\/style>/si', $lastOut, $sm2)) {
    $inlineCss = array_sum(array_map('strlen', $sm2[1]));
}
echo PHP_EOL . 'style blocks in output: ' . preg_match_all('/<style\b/i', $lastOut) . PHP_EOL;
echo 'output bytes: ' . strlen($lastOut) . ' | inline css bytes: ' . $inlineCss . PHP_EOL;

if (str_contains($lastLog, 'disyl.compile.fallback')) {
    echo PHP_EOL . 'FALLBACK line present in final round' . PHP_EOL;
}
