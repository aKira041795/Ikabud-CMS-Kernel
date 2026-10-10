<?php
declare(strict_types=1);

/**
 * Attribute scripts_ms: which pass inside compileScriptBody() actually costs?
 *
 * 4l removed the O(n^2) in processSetStatements() and anchored the tag probe, and the slowest template is
 * still modules/guidance/pages/settings.disyl at ~40 ms with scripts_ms = 63% of it. scripts_ms is the
 * WHOLE of step 4b, which internally runs: the brace-protection loop, processSetStatements(),
 * processControlStructures(), processIncludes() and processScriptVariables(). Timing it as one number does
 * not say which.
 *
 * This matters because the previous section proved compileStyleBody() quadratic and it turned out to run on
 * essentially nothing (styles_ms 0.06 ms). Assume nothing about which pass is expensive; measure it.
 *
 * Method: take the largest real <script> body from the rendered output, then time each private pass on it
 * via a closure bound to the engine, so no engine edit is needed and by-reference parameters behave as in
 * production. Median of N rounds, and the sum is compared against compileScriptBody() end-to-end to show
 * whether the parts account for the whole.
 *
 *   php docs/reviews/perf-metrics-2026-10-10/probe-script-pass-split.php modules/guidance/pages/settings.disyl
 */

require __DIR__ . '/../../../bootstrap.php';

use Ikabud\Kernel\DiSyL\TemplateEngine;

$templateDir = defined('TEMPLATES_PATH') ? TEMPLATES_PATH : __DIR__ . '/../../../templates';
$cacheDir = rtrim(sys_get_temp_dir(), '/') . '/ikabud-script-split';

$_ENV['APP_TIMING_LOGS'] = 'false'; // not measuring the log here

$template = $argv[1] ?? 'modules/guidance/pages/settings.disyl';

$engine = new TemplateEngine($templateDir, $cacheDir, true);
$engine->enableCompiledMode(true);

try {
    $out = $engine->render($template, ['page_title' => '__script_split__']);
} catch (Throwable $e) {
    fwrite(STDERR, 'render threw: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

// Largest <script> body in the rendered output — representative of what step 4b processed.
$bodies = [];
if (preg_match_all('/<script\b[^>]*>(.*?)<\/script>/si', $out, $m)) {
    foreach ($m[1] as $b) {
        $bodies[] = $b;
    }
}
if ($bodies === []) {
    fwrite(STDERR, "no <script> body in the output of {$template}" . PHP_EOL);
    exit(1);
}
usort($bodies, fn($a, $b) => strlen($b) <=> strlen($a));
$body = $bodies[0];

printf('template : %s%s', $template, PHP_EOL);
printf('script bodies in output: %d; largest taken: %d bytes (of which braces: %d)%s%s',
    count($bodies), strlen($body), substr_count($body, '{') + substr_count($body, '}'), PHP_EOL, PHP_EOL);

/** Bind a private method so by-ref params work as they do in production. */
function binder(TemplateEngine $engine, string $method): Closure
{
    return Closure::bind(
        function (string $content, array &$ctx) use ($method): string {
            return $this->{$method}($content, $ctx);
        },
        $engine,
        TemplateEngine::class
    );
}

$passes = [
    'processSetStatements'      => binder($engine, 'processSetStatements'),
    'processControlStructures'  => binder($engine, 'processControlStructures'),
    // Needs script context set first, exactly as compileScriptBody does — calling it without that threw
    // and left the largest pass in the method unmeasured, which is how ~4 ms stayed unattributed.
    'processScriptVariables'    => Closure::bind(function (string $c, array &$ctx): string {
        $this->setScriptContext(true);
        try {
            return $this->processScriptVariables($c, $ctx);
        } finally {
            $this->setScriptContext(false);
        }
    }, $engine, TemplateEngine::class),
    'compileScriptBody (whole)' => null, // handled separately below
];

$rounds = 5;
$results = [];

foreach ($passes as $name => $fn) {
    $times = [];
    for ($r = 0; $r < $rounds; $r++) {
        $ctx = ['page_title' => '__script_split__'];
        if ($name === 'compileScriptBody (whole)') {
            $bound = Closure::bind(function (string $b, array &$c): string {
                return $this->compileScriptBody($b, $c);
            }, $engine, TemplateEngine::class);
            $t0 = microtime(true);
            try {
                $bound($body, $ctx);
            } catch (Throwable $e) {
                $times = [];
                break;
            }
            $times[] = (microtime(true) - $t0) * 1000;
            continue;
        }
        $t0 = microtime(true);
        try {
            $fn($body, $ctx);
        } catch (Throwable $e) {
            $times = [];
            break;
        }
        $times[] = (microtime(true) - $t0) * 1000;
    }
    if ($times === []) {
        $results[$name] = null;
        continue;
    }
    sort($times);
    $results[$name] = $times[intdiv(count($times), 2)];
}

printf("%-32s %12s\n", 'pass', 'median_ms');
printf("%s\n", str_repeat('-', 46));
$sum = 0.0;
foreach ($results as $name => $ms) {
    if ($ms === null) {
        printf("%-32s %12s\n", $name, 'THREW');
        continue;
    }
    printf("%-32s %12.2f\n", $name, $ms);
    if ($name !== 'compileScriptBody (whole)') {
        $sum += $ms;
    }
}

$whole = $results['compileScriptBody (whole)'] ?? null;
if ($whole !== null) {
    printf("%s\n", str_repeat('-', 46));
    printf("%-32s %12.2f\n", 'named passes, summed', $sum);
    printf("%-32s %12.2f   <- brace loop + guard + literals + marker restore\n", 'unexplained in the whole', $whole - $sum);
}

/**
 * The "unexplained" bucket is not automatically the brace loop. After rewriting that loop the
 * unexplained remainder barely moved, which means something else in the method owns the time — so the
 * other stages are timed individually instead of being inferred. The candidates that touch the whole body:
 * the guard (bodyContainsDisylConstruct runs several whole-body regexes) and the marker restore
 * (str_replace with an array of ~one entry per brace, which is not a single-pass operation).
 */
echo PHP_EOL . '=== the whole-body stages of compileScriptBody, timed individually ===' . PHP_EOL . PHP_EOL;

/** Median ms of a callable over N rounds. */
$medianOf = static function (callable $fn, int $rounds = 5): ?float {
    $times = [];
    for ($r = 0; $r < $rounds; $r++) {
        $t0 = microtime(true);
        try {
            $fn();
        } catch (Throwable $e) {
            return null;
        }
        $times[] = (microtime(true) - $t0) * 1000;
    }
    sort($times);
    return $times[intdiv(count($times), 2)];
};

$guard = Closure::bind(function (string $b): bool {
    return $this->bodyContainsDisylConstruct($b);
}, $engine, TemplateEngine::class);

$rows = [];

$rows['bodyContainsDisylConstruct'] = $medianOf(fn() => $guard($body));

// Marker restore: one marker pair per brace, which is the shape this method actually produces.
$braceCount = substr_count($body, '{') + substr_count($body, '}');
$markers = [];
$values = [];
for ($j = 0; $j < $braceCount; $j++) {
    $markers[] = "___JSCURLY_OPEN_{$j}___";
    $values[] = '{';
}
$rows["str_replace($braceCount markers)"] = $medianOf(fn() => str_replace($markers, $values, $body));

// The literal pass regex over the whole body.
$rows["preg_replace_callback({literal})"] = $medianOf(function () use ($body) {
    preg_replace_callback('/\{literal\}(.*?)\{\/literal\}/s', fn($m) => $m[1], $body);
});

printf("%-34s %12s %10s\n", 'stage', 'median_ms', 'share of whole');
printf("%s\n", str_repeat('-', 60));
foreach ($rows as $name => $ms) {
    if ($ms === null) {
        printf("%-34s %12s %10s\n", $name, 'THREW', '-');
        continue;
    }
    printf("%-34s %12.2f %9.1f%%\n", $name, $ms, $whole > 0 ? ($ms / $whole * 100) : 0);
}
printf("%-34s %12.2f\n", 'compileScriptBody (whole)', $whole);

echo PHP_EOL . 'The largest row is the target. Every stage is measured; none is assumed.' . PHP_EOL;
