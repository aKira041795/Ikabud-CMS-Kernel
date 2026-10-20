<?php
/**
 * Compiled-eligibility cache must invalidate when a DEPENDENCY changes.
 *
 * Why this guard exists: isCompiledEligibleTemplate() persists a decision keyed
 * by the root template path + root mtime, but the decision is made by walking
 * {include}/{extends}. Editing only an included partial leaves the root mtime
 * unchanged, so the stale "eligible" entry sends the page down the compiled
 * path; TemplateCompiler then emits "// Unsupported tag" for the partial's
 * component and the content silently disappears.
 *
 * The test covers both directions and deletion:
 *   - positive -> negative: a plain partial is edited to add {ikb_badge}
 *   - negative -> positive: a component partial is edited back to plain (separate fixture)
 *   - a deleted dependency must not fatal
 *
 * Run: php tests/disyl_compiled_eligibility_stale_test.php
 */
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use Ikabud\Kernel\DiSyL\TemplateEngine;

$pass = 0;
$fail = 0;
$errors = [];

function t(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $errors;
    if ($ok) {
        $pass++;
        echo "  PASS {$label}\n";
        return;
    }
    $fail++;
    $errors[] = $label . ($detail !== '' ? ": {$detail}" : '');
    echo "  FAIL {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

function rrmdir(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $child = $path . '/' . $item;
        is_dir($child) ? rrmdir($child) : @unlink($child);
    }
    @rmdir($path);
}

function freshEngine(string $templates, string $cache): TemplateEngine
{
    $engine = new TemplateEngine($templates, $cache, false);
    $engine->enableCompiledMode(true);
    return $engine;
}

function eligible(TemplateEngine $engine, string $templatePath): bool
{
    $method = new ReflectionMethod(TemplateEngine::class, 'isCompiledEligibleTemplate');
    return (bool)$method->invoke($engine, $templatePath);
}

function rewriteAndBump(string $path, string $contents, int $futureSeconds): void
{
    file_put_contents($path, $contents);
    touch($path, time() + $futureSeconds);
    clearstatcache(true, $path);
}

$tmp = sys_get_temp_dir() . '/disyl-elig-stale-' . getmypid() . '-' . bin2hex(random_bytes(4));
$templates = $tmp . '/templates';
$cache = $tmp . '/cache';
@mkdir($templates, 0755, true);
@mkdir($cache, 0755, true);

$page = $templates . '/page.disyl';
$partial = $templates . '/partial.disyl';

try {
    // ── Positive -> negative: root unchanged, dependency gains a component ──
    file_put_contents($page, '<main>{include "partial"}</main>');
    file_put_contents($partial, '<p>Plain partial</p>');

    $engine1 = freshEngine($templates, $cache);
    $html1 = $engine1->render('page');
    t('phase 1: plain partial renders', str_contains($html1, 'Plain partial'), $html1);
    t('phase 1: plain graph is compiled-eligible', eligible($engine1, $page));

    $eligFiles = glob($cache . '/disyl-extends/elig_*.json') ?: [];
    t('phase 1: eligibility decision was persisted', count($eligFiles) === 1, count($eligFiles) . ' file(s)');

    rewriteAndBump($partial, '<div class="badge-host">{ikb_badge variant="success"}Boundary{/ikb_badge}</div>', 5);
    $engine2 = freshEngine($templates, $cache);
    $eligibility2 = eligible($engine2, $page);
    $html2 = $engine2->render('page');

    t('phase 2: edited graph is NOT compiled-eligible', $eligibility2 === false, 'stale decision = ' . ($eligibility2 ? 'eligible' : 'ineligible'));
    t('phase 2: component in the edited partial renders', str_contains($html2, 'Boundary') && str_contains($html2, '<span'), $html2);
    t('phase 2: no raw component syntax leaks', !str_contains($html2, '{ikb_badge'), $html2);

    // ── Reverting the component on the same fixture: the decision stays eligible ──
    rewriteAndBump($partial, '<p>Plain again</p>', 10);
    $engine3 = freshEngine($templates, $cache);
    $eligibility3 = eligible($engine3, $page);
    $html3 = $engine3->render('page');

    t('phase 3: plain partial renders after reverting the component', str_contains($html3, 'Plain again'), $html3);
    t('phase 3: graph is eligible after reverting the component', $eligibility3 === true, 'decision = ' . ($eligibility3 ? 'eligible' : 'ineligible'));

    // ── Negative -> positive: a PERSISTED ineligible decision must be rechecked ──
    $negPage = $templates . '/neg-page.disyl';
    $negPartial = $templates . '/neg-partial.disyl';
    file_put_contents($negPage, '<main>{include "neg-partial"}</main>');
    file_put_contents($negPartial, '<div>{ikb_badge variant="success"}Negative boundary{/ikb_badge}</div>');

    $negEngine1 = freshEngine($templates, $cache);
    $negHtml1 = $negEngine1->render('neg-page');
    t('negative phase 1: component partial renders', str_contains($negHtml1, 'Negative boundary'), $negHtml1);
    t('negative phase 1: component graph is ineligible', eligible($negEngine1, $negPage) === false);

    rewriteAndBump($negPartial, '<p>Negative plain</p>', 15);
    $negEngine2 = freshEngine($templates, $cache);
    $negEligibility = eligible($negEngine2, $negPage);
    $negHtml2 = $negEngine2->render('neg-page');
    t('negative phase 2: graph becomes eligible after the component is removed', $negEligibility === true, 'stale decision = ' . ($negEligibility ? 'eligible' : 'ineligible'));
    t('negative phase 2: plain partial renders', str_contains($negHtml2, 'Negative plain'), $negHtml2);

    // ── Deleted dependency: must not fatal, page wrapper still renders ──
    @unlink($partial);
    clearstatcache(true, $partial);
    $engine4 = freshEngine($templates, $cache);
    $caught = null;
    $html4 = '';
    try {
        $html4 = $engine4->render('page');
    } catch (Throwable $e) {
        $caught = $e;
    }
    t('phase 4: deleted dependency does not fatal', $caught === null, $caught ? get_class($caught) . ': ' . $caught->getMessage() : '');
    t('phase 4: page wrapper still renders', str_contains($html4, '<main>') && str_contains($html4, '</main>'), $html4);
} finally {
    rrmdir($tmp);
}

echo "\n" . ($fail === 0 ? 'OK' : 'FAILED') . ": {$pass} passed, {$fail} failed\n";
if ($fail !== 0) {
    foreach ($errors as $error) {
        echo "  - {$error}\n";
    }
    exit(1);
}
exit(0);
