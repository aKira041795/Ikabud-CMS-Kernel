<?php

/**
 * Authority duplication census — declaration vs store.
 *
 * CHAIR-OWNED ACCEPTANCE, written BEFORE the feature, so a red baseline means the behaviour is absent
 * rather than that the test is missing.
 *
 * THE PROBLEM
 *
 * A capability's authority is expressed in four places that must agree, and nothing reconciles them:
 *
 *   1. `capabilities.exposes[]`      modules/.../module.json      what exists, plus mutation metadata
 *   2. `capabilities.routes`         modules/.../module.json      route -> capability
 *   3. the declared policy rows      ModuleInstallService:90       the role set the code intends
 *   4. the policy store              capability_authorization_policies   what the system actually permits
 *
 * Nine separate instances of "a declaration does not control what it appears to control" are recorded in
 * this programme. This slice covers the comparison between 3 and 4, where the sharpest one lives --
 * measured on tenant 54, 2026-09-20:
 *
 *   capability    : akira.shell.admin_page@1
 *   policy_version: 30   (= the store's ACTIVE version)
 *   widened field : caller_module      (<- NOT roles; the role sets are identical)
 *   stored/declared roles: admin,administrator,superadmin
 *
 * The shipped declaration asks for a broader caller scope than the live active row grants, so the code
 * can never obtain the authority it declares -- and until today that fact was one indistinguishable
 * warning among nine routine ones. A census would have surfaced it with nobody reading a log.
 *
 * PURE on purpose: the comparison is a function of two arrays, so this suite opens no database, has no
 * skip path, and cannot pass vacuously. Reading the real sources belongs to the command that feeds it.
 *
 * Interface fixed here, and the lane implements it:
 *
 *   Ikabud\Kernel\Workbench\Governance\AuthorityCensus::compare(
 *       array $declarations,   // list of declared rows
 *       array $activeRows      // list of stored rows, only the ACTIVE one matters per capability
 *   ): array
 *
 * Return shape:
 *   [
 *     'counts'  => ['matching'=>int, 'declared_absent'=>int, 'divergent'=>int, 'superseded'=>int],
 *     'findings'=> [ ['capability_id'=>string, 'class'=>string, 'direction'=>?string, 'fields'=>string[],
 *                     'declared_version'=>?int, 'active_version'=>?int], ... ],
 *   ]
 *
 * Row shape (both sides): ['capability_id'=>string, 'policy_version'=>int,
 *                          'allowed_roles'=>'admin,editor' (CSV), 'caller_module'=>?string]
 * A stored row additionally carries 'is_active' => 1 when it is the live version.
 */

declare(strict_types=1);

$class = 'Ikabud\\Kernel\\Workbench\\Governance\\AuthorityCensus';
$file = dirname(__DIR__) . '/kernel/Workbench/Governance/AuthorityCensus.php';
// Guarded on purpose: an absent class must be a CLEAN red baseline, not a fatal. `require_once` on a
// missing file crashes the probe, and a crashing probe is classified as a HARNESS fault -- which would
// refuse the run rather than fail it, hiding "the behaviour is absent" behind "the instrument broke".
if (is_file($file)) {
    require_once $file;
}

$passed = 0;
$failed = 0;
$check = static function (string $label, bool $ok, string $detail = '') use (&$passed, &$failed): void {
    $ok ? ++$passed : ++$failed;
    echo ($ok ? '  [PASS] ' : '  [FAIL] ') . $label . (!$ok && $detail !== '' ? " — {$detail}" : '') . "\n";
};

if (!is_callable([$class, 'compare'])) {
    $check('AuthorityCensus::compare() exists', false, 'not implemented yet — this is the red baseline');
    echo "\n=== summary ===\n";
    printf("  %d passed, %d failed\n", $passed, $failed);
    exit(1);
}

/** @param array<string,mixed> $report */
$find = static function (array $report, string $id): ?array {
    foreach ($report['findings'] ?? [] as $f) {
        if (($f['capability_id'] ?? '') === $id) {
            return $f;
        }
    }
    return null;
};

$declared = static fn(string $id, string $roles, ?string $caller = null, int $v = 30): array => [
    'capability_id' => $id, 'policy_version' => $v, 'allowed_roles' => $roles, 'caller_module' => $caller,
];
$stored = static fn(string $id, string $roles, ?string $caller = null, int $v = 30, int $active = 1): array => [
    'capability_id' => $id, 'policy_version' => $v, 'allowed_roles' => $roles,
    'caller_module' => $caller, 'is_active' => $active,
];

echo "=== a census that flags everything is the same defect as a warning nobody reads ===\n";
$report = $class::compare(
    [$declared('akira.post.create@1', 'admin,administrator,superadmin')],
    [$stored('akira.post.create@1', 'admin,administrator,superadmin')]
);
$check(
    'an identical declaration and active row is MATCHING, not divergent',
    ($report['counts']['divergent'] ?? -1) === 0
    && ($report['counts']['matching'] ?? -1) === 1,
    json_encode($report['counts'] ?? [])
);
$check(
    'and role ORDER does not create a finding — the sets are equal',
    ($report['counts']['divergent'] ?? -1) === 0,
    json_encode($report['counts'] ?? [])
);

echo "\n=== the sharpest real case: the declaration asks for more ===\n";
// The measured decision-115 shape: identical roles, broader declared caller scope.
$report = $class::compare(
    [$declared('akira.shell.admin_page@1', 'admin,administrator,superadmin', null)],
    [$stored('akira.shell.admin_page@1', 'admin,administrator,superadmin', 'cms-akira-shell')]
);
$finding = $find($report, 'akira.shell.admin_page@1');
$check(
    'a broader declared caller_module is DIVERGENT with direction widening',
    ($finding['class'] ?? '') === 'divergent' && ($finding['direction'] ?? '') === 'widening',
    json_encode($finding)
);
$check(
    'and it names caller_module as the differing field, not roles',
    ($finding['fields'] ?? []) === ['caller_module'],
    json_encode($finding['fields'] ?? [])
);
$check(
    'the active version is reported so the divergence is locatable',
    ($finding['active_version'] ?? null) === 30
);

echo "\n=== the other direction is a different finding, not the same one ===\n";
$report = $class::compare(
    [$declared('akira.post.create@1', 'admin')],
    [$stored('akira.post.create@1', 'admin,administrator,superadmin')]
);
$finding = $find($report, 'akira.post.create@1');
$check(
    'a declaration narrower than the store is DIVERGENT with direction narrowing',
    ($finding['class'] ?? '') === 'divergent' && ($finding['direction'] ?? '') === 'narrowing',
    json_encode($finding)
);

echo "\n=== a superseded declaration is not a live divergence ===\n";
// The beace5c defect: the seed compares against a version the store has moved past. Reporting that as a
// live divergence would recreate the very noise this census exists to replace.
$report = $class::compare(
    [$declared('akira.builder.create@1', 'admin,administrator,superadmin', null, 1)],
    [$stored('akira.builder.create@1', 'admin', null, 30)]
);
$finding = $find($report, 'akira.builder.create@1');
$check(
    'a declaration at a SUPERSEDED version is classed superseded',
    ($finding['class'] ?? '') === 'superseded',
    json_encode($finding)
);
$check(
    'and it is NOT counted as divergent',
    ($report['counts']['divergent'] ?? -1) === 0 && ($report['counts']['superseded'] ?? -1) === 1,
    json_encode($report['counts'] ?? [])
);

echo "\n=== a declared capability the store has never heard of ===\n";
$report = $class::compare(
    [$declared('akira.brand.new@1', 'admin')],
    []
);
$finding = $find($report, 'akira.brand.new@1');
$check(
    'a declaration with no stored row is DECLARED_ABSENT',
    ($finding['class'] ?? '') === 'declared_absent' && ($report['counts']['declared_absent'] ?? -1) === 1,
    json_encode($finding)
);

echo "\n=== it is a report: counts per class, and nothing is mutated ===\n";
$declarations = [$declared('a@1', 'admin')];
$rows = [$stored('a@1', 'admin')];
$before = json_encode([$declarations, $rows]);
$report = $class::compare($declarations, $rows);
$check('the inputs are unchanged after the call — the census writes nothing', json_encode([$declarations, $rows]) === $before);
$check(
    'every finding class is counted, so divergence is measurable over time',
    is_array($report['counts'] ?? null)
    && array_keys($report['counts']) === ['matching', 'declared_absent', 'divergent', 'superseded'],
    json_encode(array_keys($report['counts'] ?? []))
);
$check(
    'a matching capability is still reported as matching, so the total is auditable',
    ($find($report, 'a@1')['class'] ?? '') === 'matching'
);

echo "\n=== summary ===\n";
printf("  %d passed, %d failed\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
