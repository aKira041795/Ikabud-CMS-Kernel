<?php

/**
 * Extension point consumption census — a point is created by its first consumer.
 *
 * CHAIR-OWNED ACCEPTANCE, written BEFORE the feature, so a red baseline means the behaviour is absent
 * rather than that the test is missing.
 *
 * MEASURED on this tree, 2026-09-20:
 *
 *   `modules/cms-akira/cms-akira-core/module.json` declares FIVE extension points --
 *   cms.sidebar, cms.settings.sections, cms.content.processors, cms.editor.tools, cms.dashboard.widgets
 *
 *   and `grep '"contributes"'` across every module manifest returns NOTHING. Not three of five
 *   unconsumed: all five, with no consumer at all.
 *
 * An extension point with no consumer is speculative contract surface. It is published as an interface a
 * third party could build against, and nothing exercises it — so there is no evidence it works, no test
 * that would notice it breaking, and no way to tell "supported extension point" from "a name in a
 * manifest". That is the same defect as a declaration that does not control what it appears to control.
 *
 * This census REPORTS; it does not remove. Deleting declared contract surface changes what a third party
 * may build against, so that decision is the director's. What is missing today is not the deletion — it
 * is the ability to see the surface at all.
 *
 * TWO invariants, and the distinction matters:
 *
 *   * an ORPHAN contribution -- a module contributing to a point no manifest declares -- is a defect in
 *     code and fails. It means a contribution that can never be invoked, or a typo in a point name.
 *   * an UNCONSUMED declared point is REPORTED, not failed. A census that fails on it would be
 *     permanently red, and a guard that is always red is a guard nobody reads.
 *
 * PURE on purpose: the comparison is a function of two arrays, so this suite reads no manifests, opens no
 * database, has no skip path and cannot pass vacuously. The command that supplies real data is separate.
 *
 * Interface fixed here, and the lane implements it:
 *
 *   Ikabud\Kernel\Workbench\Governance\ExtensionPointCensus::report(
 *       array $declaredPoints,    // list of ['point' => string, 'module' => string]
 *       array $contributions      // list of ['point' => string, 'module' => string]
 *   ): array
 *
 * Return shape:
 *   [
 *     'counts'     => ['declared'=>int, 'consumed'=>int, 'unconsumed'=>int, 'orphan'=>int],
 *     'unconsumed' => [ ['point'=>string, 'declared_by'=>string], ... ],   // deterministic order
 *     'orphans'    => [ ['point'=>string, 'contributed_by'=>string], ... ],
 *   ]
 */

declare(strict_types=1);

$class = 'Ikabud\\Kernel\\Workbench\\Governance\\ExtensionPointCensus';
$file = dirname(__DIR__) . '/kernel/Workbench/Governance/ExtensionPointCensus.php';
// Guarded so an absent class is a CLEAN red baseline, not a fatal: a crashing probe is classified as a
// harness fault, which refuses the run instead of failing it.
if (is_file($file)) {
    require_once $file;
}

$passed = 0;
$failed = 0;
$check = static function (string $label, bool $ok, string $detail = '') use (&$passed, &$failed): void {
    $ok ? ++$passed : ++$failed;
    echo ($ok ? '  [PASS] ' : '  [FAIL] ') . $label . (!$ok && $detail !== '' ? " — {$detail}" : '') . "\n";
};

if (!is_callable([$class, 'report'])) {
    $check('ExtensionPointCensus::report() exists', false, 'not implemented yet — this is the red baseline');
    echo "\n=== summary ===\n";
    printf("  %d passed, %d failed\n", $passed, $failed);
    exit(1);
}

$declared = static fn(string $point, string $module = 'cms-akira-core'): array => ['point' => $point, 'module' => $module];
$contributed = static fn(string $point, string $module): array => ['point' => $point, 'module' => $module];

echo "=== the plain cases ===\n";
$report = $class::report(
    [$declared('cms.sidebar', 'cms-akira-core'), $declared('cms.dashboard.widgets', 'cms-akira-core')],
    [$contributed('cms.sidebar', 'cms-akira-shell')]
);
$check(
    'a declared point with a consumer is CONSUMED',
    ($report['counts']['consumed'] ?? -1) === 1,
    json_encode($report['counts'] ?? [])
);
$check(
    'a declared point with no consumer is listed as UNCONSUMED with who declared it',
    ($report['counts']['unconsumed'] ?? -1) === 1
    && ($report['unconsumed'][0]['point'] ?? '') === 'cms.dashboard.widgets'
    && ($report['unconsumed'][0]['declared_by'] ?? '') === 'cms-akira-core',
    json_encode($report['unconsumed'] ?? [])
);
$check(
    'declared = consumed + unconsumed, so the inventory is auditable',
    ($report['counts']['declared'] ?? -1) === 2
    && ($report['counts']['unconsumed'] ?? -1) === (($report['counts']['declared'] ?? 0) - ($report['counts']['consumed'] ?? 0))
);

echo "\n=== the defect that FAILS: a contribution to a point nobody declares ===\n";
$report = $class::report(
    [$declared('cms.sidebar', 'cms-akira-core')],
    [$contributed('cms.sidebar', 'cms-akira-shell'), $contributed('cms.sidebar.typo', 'cms-akira-builder')]
);
$check(
    'a contribution naming an undeclared point is an ORPHAN',
    ($report['counts']['orphan'] ?? -1) === 1
    && ($report['orphans'][0]['point'] ?? '') === 'cms.sidebar.typo'
    && ($report['orphans'][0]['contributed_by'] ?? '') === 'cms-akira-builder',
    json_encode($report['orphans'] ?? [])
);
$check(
    'and an orphan does NOT count as consumption of anything',
    ($report['counts']['consumed'] ?? -1) === 1 && ($report['counts']['unconsumed'] ?? -1) === 0,
    json_encode($report['counts'] ?? [])
);

echo "\n=== the measured state of this tree, as a census must be able to express ===\n";
$five = [
    $declared('cms.sidebar'), $declared('cms.settings.sections'), $declared('cms.content.processors'),
    $declared('cms.editor.tools'), $declared('cms.dashboard.widgets'),
];
$report = $class::report($five, []);
$check(
    'five declared points and no contributions reports five unconsumed and zero consumed',
    ($report['counts']['declared'] ?? -1) === 5
    && ($report['counts']['consumed'] ?? -1) === 0
    && ($report['counts']['unconsumed'] ?? -1) === 5
    && ($report['counts']['orphan'] ?? -1) === 0,
    json_encode($report['counts'] ?? [])
);
$check(
    'unconsumed points are ordered deterministically, so two runs are comparable',
    array_column($report['unconsumed'], 'point') === [
        'cms.content.processors', 'cms.dashboard.widgets', 'cms.editor.tools',
        'cms.settings.sections', 'cms.sidebar',
    ],
    json_encode(array_column($report['unconsumed'] ?? [], 'point'))
);

echo "\n=== it is a report: counts per class, and nothing is mutated ===\n";
$declaredList = [$declared('cms.sidebar')];
$contribList = [$contributed('cms.sidebar', 'cms-akira-shell')];
$before = json_encode([$declaredList, $contribList]);
$report = $class::report($declaredList, $contribList);
$check('the inputs are unchanged — the census writes nothing', json_encode([$declaredList, $contribList]) === $before);
$check(
    'every class is counted, so the surface is measurable over time',
    array_keys($report['counts'] ?? []) === ['declared', 'consumed', 'unconsumed', 'orphan'],
    json_encode(array_keys($report['counts'] ?? []))
);
$check(
    'an empty surface reports zeroes rather than erroring',
    $class::report([], [])['counts'] === ['declared' => 0, 'consumed' => 0, 'unconsumed' => 0, 'orphan' => 0]
);

echo "\n=== summary ===\n";
printf("  %d passed, %d failed\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
