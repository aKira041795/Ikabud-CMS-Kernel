<?php
declare(strict_types=1);

/**
 * How widespread is the DiSyL compiled-path fallback?
 *
 * Why: modules/cms/admin/theme-customizer.disyl throws on the compiled path and silently falls back to
 * the interpreted pipeline (~825-988 ms cold vs ~95 ms for the same document compiling). That was found
 * by measuring NINE hand-picked templates. Nine is not a survey, and if other templates fall back the
 * blast radius is much larger than one admin page.
 *
 * Method: render EVERY .disyl under templates/ once through the compiled path and classify the pipeline
 * from the log line that render emitted. A compiled FAILURE is checked FIRST, because it logs
 * disyl.compile.fallback and then emits disyl.compile.phases from the interpreted fallback - a naive
 * "did phases appear" check calls that a plain interpreted render, which is exactly how this bug stayed
 * hidden.
 *
 * Limits, stated so the numbers are not over-read:
 * - Templates are rendered with a minimal context. Admin templates whose real context comes from handlers
 *   may throw for reasons unrelated to the compiler; those are reported separately as THREW and are NOT
 *   counted as fallbacks. A fallback, by contrast, is a compiler-level event: the compiled class was
 *   produced and then failed at execution, so it does not depend on the context being complete.
 * - One cold render per template, so first-compilation cost is included. That is deliberate: it is the
 *   cold path that shows fallbacks.
 *
 *   php docs/reviews/perf-metrics-2026-10-10/probe-fallback-sweep.php
 */

require __DIR__ . '/../../../bootstrap.php';

use Ikabud\Kernel\DiSyL\TemplateEngine;

$templateDir = defined('TEMPLATES_PATH') ? TEMPLATES_PATH : __DIR__ . '/../../../templates';
$cacheDir = rtrim(sys_get_temp_dir(), '/') . '/ikabud-fallback-sweep';
$logPath = (defined('STORAGE_PATH') ? STORAGE_PATH : __DIR__ . '/../../../storage') . '/logs/app.log';

$_ENV['APP_TIMING_LOGS'] = 'true';
$_ENV['APP_TIMING_THRESHOLD_MS'] = '0';

// Collect every template, excluding nothing: partials can be rendered standalone and a fallback in one
// still indicates a compiler problem.
$files = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($templateDir, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if ($f->isFile() && str_ends_with($f->getFilename(), '.disyl')) {
        $files[] = str_replace($templateDir . '/', '', $f->getPathname());
    }
}
sort($files);

echo 'sweeping ' . count($files) . ' templates through the compiled path' . PHP_EOL . PHP_EOL;

$tally = ['compiled' => 0, 'fallback' => 0, 'interpreted' => 0, 'no timing line' => 0, 'THREW' => 0];
$fallbacks = [];
$noLine = [];

foreach ($files as $template) {
    $mark = @filesize($logPath) ?: 0;

    $engine = new TemplateEngine($templateDir, $cacheDir, true);
    $engine->enableCompiledMode(true);

    $threw = false;
    try {
        $engine->render($template, ['page_title' => '__sweep__']);
    } catch (Throwable $e) {
        $threw = true;
    }

    $handle = @fopen($logPath, 'r');
    $new = '';
    if ($handle !== false) {
        fseek($handle, $mark);
        $new = (string)stream_get_contents($handle);
        fclose($handle);
    }

    // Fallback FIRST: a failed compiled render also emits phases afterwards.
    if (str_contains($new, 'disyl.compile.fallback')) {
        $tally['fallback']++;
        $reason = '';
        if (preg_match('/"reason":"([^"]{0,70})/', $new, $m)) {
            $reason = $m[1];
        }
        $fallbacks[] = [$template, $reason];
    } elseif ($threw) {
        $tally['THREW']++;
    } elseif (str_contains($new, '"cache_path":"compiled"')) {
        $tally['compiled']++;
    } elseif (str_contains($new, 'disyl.compile.phases')) {
        $tally['interpreted']++;
    } else {
        $tally['no timing line']++;
        $noLine[] = $template;
    }
}

echo '=== pipeline tally ===' . PHP_EOL;
foreach ($tally as $k => $v) {
    printf("  %-16s %4d\n", $k, $v);
}

echo PHP_EOL . '=== templates that FELL BACK (the defect class) ===' . PHP_EOL;
if ($fallbacks === []) {
    echo '  none' . PHP_EOL;
}
foreach ($fallbacks as [$t, $r]) {
    printf("  %-58s %s\n", $t, $r);
}

if ($noLine !== []) {
    echo PHP_EOL . '=== rendered but emitted NO timing line (neither path logged) ===' . PHP_EOL;
    foreach (array_slice($noLine, 0, 12) as $t) {
        echo '  ' . $t . PHP_EOL;
    }
    if (count($noLine) > 12) {
        echo '  ... and ' . (count($noLine) - 12) . ' more' . PHP_EOL;
    }
}

echo PHP_EOL . 'A fallback is a compiler-level event: the compiled class was produced and then failed at' . PHP_EOL;
echo 'execution, so it does not depend on the context being complete. THREW is context-related and is' . PHP_EOL;
echo 'counted separately rather than folded in.' . PHP_EOL;
