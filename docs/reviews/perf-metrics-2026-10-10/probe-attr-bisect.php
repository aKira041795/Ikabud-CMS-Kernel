<?php
declare(strict_types=1);

/**
 * Narrowing hunt: is the compiled-render failure caused by the JS concatenation expression itself,
 * or by its surrounding document?
 *
 * Established already (probe-attr-repro.php): six isolated attribute forms ALL render fine on the
 * compiled path. So the trigger needs document context and cannot be shown from a small fixture.
 *
 * Method — an A/B inside ONE process, on the real document:
 *   original  : modules/cms/admin/theme-customizer.disyl                  -> expect compiled FAILURE
 *   modified  : same, with every  'rgba('+r+...+'(_mobileBgOpacity/100)+')'  JS concatenation
 *               replaced by a literal colour                                     -> ?
 * If the modified copy compiles, the expression (or its immediate text) is the trigger and the
 * minimal reproduction is that expression IN CONTEXT. If it still fails, the trigger is elsewhere.
 *
 * The modified copy is written as an UNTRACKED temp template inside the real templates dir, so
 * {extends} still resolves, and it is deleted at the end. The tracked template is never modified.
 *
 *   php docs/reviews/perf-metrics-2026-10-10/probe-attr-bisect.php
 */

require __DIR__ . '/../../../bootstrap.php';

use Ikabud\Kernel\DiSyL\TemplateEngine;

$templateDir = defined('TEMPLATES_PATH') ? TEMPLATES_PATH : __DIR__ . '/../../../templates';
$logPath = (defined('STORAGE_PATH') ? STORAGE_PATH : __DIR__ . '/../../../storage') . '/logs/app.log';

$original = 'modules/cms/admin/theme-customizer.disyl';
$tempName = 'modules/cms/admin/__attr_bisect_tmp.disyl';
$tempPath = $templateDir . '/' . $tempName;

// Forced: the repo's .env threshold of 10 would suppress the lines this probe reads.
$_ENV['APP_TIMING_LOGS'] = 'true';
$_ENV['APP_TIMING_THRESHOLD_MS'] = '0';

$NEEDLE = "'rgba('+r+','+g+','+b+','+(_mobileBgOpacity/100)+')'";
$REPLACEMENT = "'rgb(0,0,0)'";

$source = (string)file_get_contents($templateDir . '/' . $original);
$occurrences = substr_count($source, $NEEDLE);
$modified = str_replace($NEEDLE, $REPLACEMENT, $source);

echo "needle: " . $NEEDLE . PHP_EOL;
echo "occurrences replaced: {$occurrences}" . PHP_EOL;
echo "bytes: " . strlen($source) . " -> " . strlen($modified) . PHP_EOL . PHP_EOL;

if ($occurrences === 0) {
    echo "NEEDLE NOT FOUND - the expression was already changed; nothing to test." . PHP_EOL;
    exit(2);
}

/**
 * Render one template and report the pipeline the ENGINE logged for it.
 * Reading the log rather than inferring is the whole point: a compiled failure also emits
 * disyl.compile.phases after falling through, so a naive check calls it "interpreted".
 */
function renderAndClassify(TemplateEngine $engine, string $template, string $logPath): array
{
    $mark = @filesize($logPath) ?: 0;
    $started = hrtime(true);
    $threw = '';
    try {
        $engine->render($template, ['page_title' => '__attr_bisect__']);
    } catch (Throwable $e) {
        $threw = substr($e->getMessage(), 0, 70);
    }
    $wall = (hrtime(true) - $started) / 1e6;

    $handle = @fopen($logPath, 'r');
    $new = '';
    if ($handle !== false) {
        fseek($handle, $mark);
        $new = (string)stream_get_contents($handle);
        fclose($handle);
    }

    $pipeline = 'no timing line';
    if (str_contains($new, 'disyl.compile.fallback')) {
        $pipeline = 'compiled->FAILED->interpreted';
    } elseif (str_contains($new, '"cache_path":"compiled"')) {
        $pipeline = 'compiled';
    } elseif (str_contains($new, 'disyl.compile.phases')) {
        $pipeline = 'interpreted';
    }

    $reason = '';
    if (preg_match('/"reason":"([^"]{0,60})/', $new, $m)) {
        $reason = $m[1];
    }

    return ['pipeline' => $pipeline, 'wall' => $wall, 'threw' => $threw, 'reason' => $reason];
}

// ── Arm 1: the untouched original ───────────────────────────────────────────
$engineA = new TemplateEngine($templateDir, rtrim(sys_get_temp_dir(), '/') . '/ikabud-attr-bisect', true);
$engineA->enableCompiledMode(true);
$a = renderAndClassify($engineA, $original, $logPath);
printf("original  %-30s %8.2f ms  %s\n", $a['pipeline'], $a['wall'], $a['reason']);

// ── Arm 2: the same document with the JS concatenation replaced ─────────────
if (file_put_contents($tempPath, $modified) === false) {
    echo "could not write {$tempPath}" . PHP_EOL;
    exit(2);
}
$engineB = new TemplateEngine($templateDir, rtrim(sys_get_temp_dir(), '/') . '/ikabud-attr-bisect', true);
$engineB->enableCompiledMode(true);
$b = renderAndClassify($engineB, $tempName, $logPath);
printf("modified  %-30s %8.2f ms  %s\n", $b['pipeline'], $b['wall'], $b['reason']);

@unlink($tempPath);
echo PHP_EOL . "temp template removed: " . (is_file($tempPath) ? 'STILL PRESENT' : 'yes') . PHP_EOL;

echo PHP_EOL . '=== verdict ===' . PHP_EOL;
if (str_contains($a['pipeline'], 'FAILED') && $b['pipeline'] === 'compiled') {
    echo "The JS concatenation expression IS the trigger. Minimal reproduction: that expression" . PHP_EOL;
    echo "inside this document (isolated forms do NOT reproduce, so context is required)." . PHP_EOL;
} elseif (str_contains($a['pipeline'], 'FAILED')) {
    echo "The expression is NOT the trigger - the modified copy still fails. Narrow elsewhere," . PHP_EOL;
    echo "and note that the generated expression seen in the artifact was a symptom, not the cause." . PHP_EOL;
} else {
    echo "The ORIGINAL did not fail in this run, so the arms are not comparable. Do not draw a" . PHP_EOL;
    echo "conclusion from this: an experiment whose control arm does not reproduce proves nothing." . PHP_EOL;
}
