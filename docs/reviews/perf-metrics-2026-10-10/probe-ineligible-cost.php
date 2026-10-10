<?php
declare(strict_types=1);

/**
 * What do the 76 compiled-INELIGIBLE templates actually cost in production?
 *
 * This is the question the multiplier probe could not answer. That probe FORCED interpreted on
 * compiled-ELIGIBLE templates, so it measured a cost production never pays for those templates (they are
 * compiled, and stay compiled). The interpreted path only matters for templates the engine itself sends
 * there, and their cost is not uniform — section A of probe-interpreted-phases.php showed three of them at
 * 0.5-1.1 ms while the forced measurements ran to 2187 ms.
 *
 * Method:
 * - engine chooses its own pipeline (`enableCompiledMode(true)`, the production default). Whatever path
 *   that is, it is the one production takes — nothing is forced. The observed cache_path is printed so a
 *   reader can see which arm each row is.
 * - a FRESH engine per render, so the in-memory output cache cannot make render 2 look cheaper than
 *   render 1 for a reason that has nothing to do with the pipeline.
 * - cold AND warm reported. Cold (first render, includes any compile) and warm (steady state) are
 *   different costs and blending them would hide the thing being measured.
 * - INLINE <style> BYTES is reported because that is the suspected causal variable: the styles phase runs
 *   only when the rendered content contains a <style> block, and compileStyleBody() is quadratic in that
 *   body's size (proven in probe-interpreted-phases.php section B).
 *
 * Renders use a minimal context, so entity lists carry no rows. That UNDERSTATES data-driven cost and is
 * stated rather than hidden; the <style> volume, which is what the quadratic pass consumes, comes from the
 * layout and is therefore representative.
 *
 *   php docs/reviews/perf-metrics-2026-10-10/probe-ineligible-cost.php
 */

require __DIR__ . '/../../../bootstrap.php';

use Ikabud\Kernel\DiSyL\TemplateEngine;

$templateDir = defined('TEMPLATES_PATH') ? TEMPLATES_PATH : __DIR__ . '/../../../templates';
$cacheDir = rtrim(sys_get_temp_dir(), '/') . '/ikabud-ineligible-cost';
$logPath = (defined('STORAGE_PATH') ? STORAGE_PATH : __DIR__ . '/../../../storage') . '/logs/app.log';

$_ENV['APP_TIMING_LOGS'] = 'true';
$_ENV['APP_TIMING_THRESHOLD_MS'] = '0';

$context = [
    'page_title' => '__ineligible_cost__',
    'base_url' => function_exists('external_base_url') ? external_base_url() : '',
];

/** Resolve the ineligible set from the engine's own predicate, so the list cannot drift. */
$engine = new TemplateEngine($templateDir, $cacheDir, true);
$engine->enableCompiledMode(true);
$predicate = new ReflectionMethod(TemplateEngine::class, 'isCompiledEligibleTemplate');
$predicate->setAccessible(true);

$files = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($templateDir, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if ($f->isFile() && str_ends_with($f->getFilename(), '.disyl')) {
        $files[] = str_replace($templateDir . '/', '', $f->getPathname());
    }
}
sort($files);

$ineligible = [];
foreach ($files as $rel) {
    if (!$predicate->invoke($engine, $templateDir . '/' . $rel)) {
        $ineligible[] = $rel;
    }
}

printf('ineligible templates (engine predicate): %d%s%s', count($ineligible), PHP_EOL, PHP_EOL);

/**
 * One render on a fresh engine. Returns [ms, outBytes, styleBytes, cachePath, threw].
 */
function renderFresh(string $templateDir, string $cacheDir, string $template, array $context, string $logPath): array
{
    $mark = @filesize($logPath) ?: 0;
    $engine = new TemplateEngine($templateDir, $cacheDir, true);
    $engine->enableCompiledMode(true);

    $out = '';
    $ms = null;
    $threw = false;
    try {
        $t0 = microtime(true);
        $out = $engine->render($template, $context);
        $ms = (microtime(true) - $t0) * 1000;
    } catch (Throwable $e) {
        $threw = true;
        return [null, 0, 0, 'THREW: ' . substr($e->getMessage(), 0, 28), true];
    }

    $handle = @fopen($logPath, 'r');
    $cachePath = 'none';
    if ($handle !== false) {
        fseek($handle, $mark);
        $new = (string)stream_get_contents($handle);
        fclose($handle);
        if (str_contains($new, 'disyl.compile.fallback')) {
            $cachePath = 'FALLBACK';
        } elseif (preg_match('/"cache_path":"([a-z_]+)"/', $new, $m)) {
            $cachePath = $m[1];
        }
    }

    $styleBytes = 0;
    if (preg_match_all('/<style\b[^>]*>(.*?)<\/style>/si', $out, $sm)) {
        foreach ($sm[1] as $body) {
            $styleBytes += strlen($body);
        }
    }

    return [$ms, strlen($out), $styleBytes, $cachePath, $threw];
}

$rows = [];
foreach ($ineligible as $rel) {
    [$cold, $ob, $sb, $cp, $threw] = renderFresh($templateDir, $cacheDir, $rel, $context, $logPath);
    if ($threw) {
        $rows[] = [$rel, null, null, 0, 0, $cp, true];
        continue;
    }
    // Second render on another fresh engine = warm, same pipeline.
    [$warm] = renderFresh($templateDir, $cacheDir, $rel, $context, $logPath);
    $rows[] = [$rel, $cold, $warm, $ob, $sb, $cp, false];
}

usort($rows, fn($a, $b) => ($b[2] ?? -1) <=> ($a[2] ?? -1));

printf("%-52s %9s %9s %9s %9s  %s\n", 'template', 'cold_ms', 'warm_ms', 'out_bytes', 'css_bytes', 'pipeline');
printf("%s\n", str_repeat('-', 112));

$slow = 0;
foreach ($rows as [$rel, $cold, $warm, $ob, $sb, $cp, $threw]) {
    if ($threw) {
        printf("%-52s %9s %9s %9s %9s  %s\n", $rel, '-', '-', '-', '-', $cp);
        continue;
    }
    if (($warm ?? 0) >= 20) {
        $slow++;
    }
    printf(
        "%-52s %9.2f %9.2f %9d %9d  %s\n",
        $rel,
        $cold ?? 0,
        $warm ?? 0,
        $ob,
        $sb,
        $cp
    );
}

echo PHP_EOL . '=== summary ===' . PHP_EOL;
$threw = count(array_filter($rows, fn($r) => $r[6]));
$warmVals = array_values(array_filter(array_column($rows, 2), fn($v) => $v !== null));
sort($warmVals);
if ($warmVals !== []) {
    $n = count($warmVals);
    printf("  rendered: %d of %d (rest threw on minimal context)%s", $n, count($rows), PHP_EOL);
    printf("  warm ms  : min %.2f  median %.2f  max %.2f%s", $warmVals[0], $warmVals[intdiv($n, 2)], end($warmVals), PHP_EOL);
    printf("  warm >= 20 ms : %d of %d%s", $slow, $n, PHP_EOL);
}
printf("  threw: %d%s", $threw, PHP_EOL);

// Correlation between inline style volume and warm cost — the suspected cause, stated as a number.
$pairs = [];
foreach ($rows as [$rel, $cold, $warm, $ob, $sb, $cp, $threw]) {
    if (!$threw && $sb > 0 && $warm !== null) {
        $pairs[] = [$rel, $sb, $warm];
    }
}
if ($pairs !== []) {
    echo PHP_EOL . '=== templates carrying an inline <style> body (the quadratic pass pays here) ===' . PHP_EOL;
    usort($pairs, fn($a, $b) => $b[1] <=> $a[1]);
    printf("%-52s %9s %9s %10s\n", 'template', 'css_bytes', 'warm_ms', 'ms/KB css');
    foreach ($pairs as [$rel, $sb, $warm]) {
        printf("%-52s %9d %9.2f %10.3f\n", $rel, $sb, $warm, $warm / ($sb / 1024));
    }
} else {
    echo PHP_EOL . 'No ineligible template carried an inline <style> body — the styles phase never ran,' . PHP_EOL;
    echo 'which is why the forced-interpreted multiplier does not transfer to these templates.' . PHP_EOL;
}
