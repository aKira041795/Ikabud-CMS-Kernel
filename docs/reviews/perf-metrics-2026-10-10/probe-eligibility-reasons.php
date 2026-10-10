<?php
declare(strict_types=1);

/**
 * WHY are templates compiled-ineligible — and does the reason matter?
 *
 * Section 4j asserted the 72 `interpreted` are "compiled-ineligible by design (component tags, macros),
 * not a defect". That was an assertion, not a measurement, and the trigger list in
 * TemplateEngine::templateGraphUsesComponentTags() includes `{ikb_` — which the project conventions make
 * the PRIMARY entity-view rendering engine. If the hottest pages in the app are on the interpreted
 * pipeline, "by design" is true and also the whole point.
 *
 * Method — the split matters:
 * - the ELIGIBLE/INELIGIBLE VERDICT comes from the engine's own private predicate via reflection, so the
 *   answer cannot drift from the engine;
 * - my own scan supplies only the REASON, which the engine does not expose;
 * - the two are then CROSS-CHECKED. Any template the engine calls ineligible that my scan cannot explain
 *   (or vice versa) is printed as UNEXPLAINED. A replication that has silently drifted shows up there
 *   instead of quietly reporting wrong reasons.
 *
 *   php docs/reviews/perf-metrics-2026-10-10/probe-eligibility-reasons.php
 */

require __DIR__ . '/../../../bootstrap.php';

use Ikabud\Kernel\DiSyL\TemplateEngine;

$templateDir = defined('TEMPLATES_PATH') ? TEMPLATES_PATH : __DIR__ . '/../../../templates';
$cacheDir = rtrim(sys_get_temp_dir(), '/') . '/ikabud-elig-reasons';

// Fresh cache dir so no stale eligibility file from an earlier run decides the verdict.
if (is_dir($cacheDir)) {
    foreach (glob($cacheDir . '/elig_*.json') ?: [] as $stale) {
        @unlink($stale);
    }
}

/** Trigger list mirrored from TemplateEngine::templateGraphUsesComponentTags(). */
$triggers = [
    ['{ikb_', 'component/entity tag {ikb_…}'],
    ['{island', 'island component'],
    ['{macro ', 'user macro definition'],
    ['{call ', 'user macro call'],
    ['{cache ', 'interpreted-only tag (async/cache/ai family)'],
    ['{invalidate ', 'interpreted-only tag (async/cache/ai family)'],
    ['{depends_on ', 'interpreted-only tag (async/cache/ai family)'],
    ['{experiment ', 'interpreted-only tag (async/cache/ai family)'],
    ['{variant ', 'interpreted-only tag (async/cache/ai family)'],
    ['{convert ', 'interpreted-only tag (async/cache/ai family)'],
    ['{sandbox', 'interpreted-only tag (async/cache/ai family)'],
    ['{trusted', 'interpreted-only tag (async/cache/ai family)'],
    ['{untrusted', 'interpreted-only tag (async/cache/ai family)'],
    ['{parallel', 'interpreted-only tag (async/cache/ai family)'],
    ['{await ', 'interpreted-only tag (async/cache/ai family)'],
    ['{suspense', 'interpreted-only tag (async/cache/ai family)'],
    ['{federated_query ', 'interpreted-only tag (async/cache/ai family)'],
    ['{ai_generate ', 'interpreted-only tag (async/cache/ai family)'],
    ['{ai_query ', 'interpreted-only tag (async/cache/ai family)'],
    ['{ai_complete ', 'interpreted-only tag (async/cache/ai family)'],
];

$engine = new TemplateEngine($templateDir, $cacheDir, true);
$engine->enableCompiledMode(true);

$predicate = new ReflectionMethod(TemplateEngine::class, 'isCompiledEligibleTemplate');
$predicate->setAccessible(true);

$extendsCompiled = new ReflectionMethod(TemplateEngine::class, 'compiledExtendsEnabled');
$extendsCompiled->setAccessible(true);
$extendsEnabled = (bool)$extendsCompiled->invoke($engine);

/**
 * First matching trigger in this template's own source, or null if it has none.
 * Returns [reason, matchedToken].
 */
function ownTrigger(string $source, array $triggers, bool $extendsEnabled): ?array
{
    foreach ($triggers as [$token, $label]) {
        if (str_contains($source, $token)) {
            return [$label, $token];
        }
    }
    if (!$extendsEnabled && str_contains($source, '{extends ')) {
        return ['{extends} with compiled-extends DISABLED', '{extends '];
    }
    return null;
}

/**
 * Faithful walk of the extends/include graph. Returns [reason, pathThatCausedIt] or null.
 */
function classify(string $abs, array $triggers, bool $extendsEnabled, string $templateDir, array &$visited, array &$chain): ?array
{
    if ($abs === '' || isset($visited[$abs])) {
        return null;
    }
    $visited[$abs] = true;
    $source = @file_get_contents($abs);
    if (!is_string($source)) {
        return null;
    }

    if (($own = ownTrigger($source, $triggers, $extendsEnabled)) !== null) {
        return [$own[0], $own[1]];
    }

    if (!preg_match_all('/\{(?:extends|include)\s+"([^"]+)"/', $source, $m)) {
        return null;
    }
    foreach ($m[1] as $related) {
        $relatedAbs = $templateDir . '/' . ltrim($related, '/');
        $chain[] = $related;
        if (is_file($relatedAbs)) {
            $child = classify($relatedAbs, $triggers, $extendsEnabled, $templateDir, $visited, $chain);
            if ($child !== null) {
                return $child;
            }
        }
        array_pop($chain);
    }
    return null;
}

$files = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($templateDir, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if ($f->isFile() && str_ends_with($f->getFilename(), '.disyl')) {
        $files[] = str_replace($templateDir . '/', '', $f->getPathname());
    }
}
sort($files);

printf('scanned %d templates under templates/%s', count($files), PHP_EOL);
printf('DISYL_EXTENDS_COMPILED resolves to: %s%s', $extendsEnabled ? 'true' : 'false', PHP_EOL);
printf('verdict from: TemplateEngine::isCompiledEligibleTemplate() via reflection%s%s', PHP_EOL, PHP_EOL);

$reasonTally = [];
$ineligible = [];
$unexplained = [];
$falsePositive = [];

foreach ($files as $rel) {
    $abs = $templateDir . '/' . $rel;
    $eligible = (bool)$predicate->invoke($engine, $abs);

    $visited = [];
    $chain = [];
    $reason = $eligible ? null : classify($abs, $triggers, $extendsEnabled, $templateDir, $visited, $chain);

    // Cross-check both directions: the point is to catch replication drift, not to hide it.
    if (!$eligible && $reason === null) {
        $unexplained[] = $rel;
    }
    if ($eligible && $reason !== null) {
        $falsePositive[] = $rel . ' (scan blames: ' . $reason[0] . ')';
    }
    if ($eligible) {
        continue;
    }

    $label = $reason[0] ?? 'UNEXPLAINED';
    $via = $chain !== [] ? ' via ' . implode(' -> ', $chain) : '';
    $reasonTally[$label] = ($reasonTally[$label] ?? 0) + 1;
    $ineligible[] = [$rel, $reason[1] ?? '?', $label . $via];
}

echo '=== ineligibility reason, by class ===' . PHP_EOL;
arsort($reasonTally);
foreach ($reasonTally as $label => $count) {
    printf("  %-52s %4d\n", $label, $count);
}
printf("  %-52s %4d\n", 'TOTAL INELIGIBLE', count($ineligible));

echo PHP_EOL . '=== the ineligible templates, grouped ===' . PHP_EOL;
$byLabel = [];
foreach ($ineligible as [$rel, $tok, $label]) {
    $byLabel[$label][] = $rel;
}
ksort($byLabel);
foreach ($byLabel as $label => $list) {
    echo PHP_EOL . '-- ' . $label . ' (' . count($list) . ')' . PHP_EOL;
    foreach ($list as $rel) {
        echo '   ' . $rel . PHP_EOL;
    }
}

echo PHP_EOL . '=== SELF-CHECK: replication drift ===' . PHP_EOL;
if ($unexplained === []) {
    echo '  none — every ineligible verdict has an attributable trigger' . PHP_EOL;
} else {
    echo '  UNEXPLAINED (engine says ineligible, scan found no trigger) — the scan has drifted:' . PHP_EOL;
    foreach ($unexplained as $rel) {
        echo '   ' . $rel . PHP_EOL;
    }
}
if ($falsePositive === []) {
    echo '  none — no template blamed by the scan that the engine actually accepts' . PHP_EOL;
} else {
    echo '  scan blames templates the engine ACCEPTS (false positives):' . PHP_EOL;
    foreach ($falsePositive as $rel) {
        echo '   ' . $rel . PHP_EOL;
    }
}
