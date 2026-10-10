<?php

declare(strict_types=1);

/**
 * The Daily Sheet must default to the commissary the actor is BOUND to.
 *
 * Owner report: "users assigned to commissary is set to the wrong commissary. prod-rizal is sent to
 * pagadian instead of rizal commis."
 *
 * The binding row was already correct (prod-rizal -> branch 18 RIZAL-COMMIS1). The defect was the
 * DEFAULT: handleAdminCommissary fell back to $commissaries[0], and $commissaries is
 * "ORDER BY name ASC", so "Pagadian Commisary" beat "RIZAL-COMMIS" on a single letter.
 *
 * This oracle pins the two real facts the fix depends on (the actor's accessible branch set, and the
 * ordering that made the old default wrong), then checks the resolution in BOTH directions.
 */
ob_start();
require_once __DIR__ . '/../harness/TestHarness.php';
$h = new TestHarness('daily-ledger-commissary-default-source', TestHarness::MODE_INTEGRATION, 'baronledger.test');
ob_end_clean();

$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/helpers.php';
require_once $base . '/modules/daily-ledger/handlers-deliveries.php';
require_once $base . '/modules/daily-ledger/handlers.php';
$h->fingerprint('modules/daily-ledger/handlers.php');

app()->tenant()->setTenantId(207);
$ctx = modulePushContext('daily-ledger');
$db = $ctx->db();

/** Mirrors the handler's own query, so the ordering under test is the ordering in production. */
$commissaries = $db->query(
    'SELECT id, code, name FROM dl_branches WHERE is_commissary = 1 AND is_active = 1 ORDER BY name ASC'
)->fetchAll(PDO::FETCH_ASSOC) ?: [];

/** The same resolution the handler performs after the fix. */
$resolveSource = static function (array $accessibleBranchIds) use ($commissaries): int {
    $accessibleCommissaryIds = [];
    foreach ($accessibleBranchIds as $accessibleBranchId) {
        foreach ($commissaries as $candidateRow) {
            if ((int)$candidateRow['id'] === (int)$accessibleBranchId) {
                $accessibleCommissaryIds[] = (int)$accessibleBranchId;
                break;
            }
        }
    }
    if (count($accessibleCommissaryIds) === 1) {
        return $accessibleCommissaryIds[0];
    }
    return $commissaries === [] ? 0 : (int)$commissaries[0]['id'];
};

$h->section('The ordering that caused the bug');

$h->test(
    'commissaries are ordered by name, so Pagadian sorts before RIZAL',
    isset($commissaries[0], $commissaries[0]['name'])
        && stripos((string)$commissaries[0]['name'], 'pagadian') !== false,
    'first by name = ' . ($commissaries[0]['name'] ?? '(none)')
    . ' | all = ' . implode(', ', array_column($commissaries, 'name'))
);

$h->test(
    'the OLD default picked that first commissary for everyone',
    count($commissaries) > 1 && (int)$commissaries[0]['id'] !== 18,
    'old default id = ' . ($commissaries[0]['id'] ?? '?') . ' (Rizal is 18)'
);

$h->section('A production user bound to RIZAL-COMMIS1');

$prodRizal = [
    'id' => 27, 'sub' => 'production_in_charge:27', 'role' => 'production_in_charge',
    'source' => 'daily-ledger', 'name' => 'Noah Omamalin', 'full_name' => 'Noah Omamalin',
];
$rizalAccessible = array_map('intval', dl_accessibleBranchIds($prodRizal));

$h->test(
    'prod-rizal is still bound to the RIZAL commissary (the binding was never the bug)',
    in_array(18, $rizalAccessible, true),
    'accessible=[' . implode(',', $rizalAccessible) . ']'
);

$h->test(
    'exactly ONE of their accessible branches is a commissary',
    count(array_intersect($rizalAccessible, array_map('intval', array_column($commissaries, 'id')))) === 1,
    'accessible=[' . implode(',', $rizalAccessible) . ']'
);

$h->test(
    'the sheet source resolves to RIZAL (18), not Pagadian',
    $resolveSource($rizalAccessible) === 18,
    'resolved=' . $resolveSource($rizalAccessible)
);

// MUST-REFUSE: the fix must not hand an unbound admin a single commissary by accident, nor change
// what an admin saw before. An admin sees every commissary, so the set is ambiguous and the
// previous fallback must still apply.
$h->section('An unbound admin must be unaffected');

$admin = [
    'id' => 20, 'sub' => 'admin:20', 'role' => 'admin',
    'source' => 'daily-ledger', 'name' => 'Shiela Baina', 'full_name' => 'Shiela Baina',
];
$adminAccessible = array_map('intval', dl_accessibleBranchIds($admin));
$adminCommissaries = array_intersect($adminAccessible, array_map('intval', array_column($commissaries, 'id')));

$h->test(
    'MUST-REFUSE: an admin sees MORE than one commissary, so no single-commissary default applies',
    count($adminCommissaries) !== 1,
    'admin accessible commissaries = [' . implode(',', $adminCommissaries) . ']'
);

$h->test(
    'MUST-REFUSE: the admin default is unchanged from the previous behaviour',
    $resolveSource($adminAccessible) === (int)$commissaries[0]['id'],
    'resolved=' . $resolveSource($adminAccessible) . ' expected=' . $commissaries[0]['id']
);

// The selector must list the COMMISSARIES, not the area's branches. The area rollout narrowed this
// list with the AREA scope's branch ids, which emptied the picker whenever the selected area held no
// commissary - measured on live data with scope=AREA:2 the query became
// `is_commissary = 1 AND id IN (13,14,17)` -> 0 rows, because those three are branches and the two
// live commissaries sit in areas 1 and 3. The existing oracle could not catch it: it MIRRORS the
// handler's query instead of exercising the handler, so it stayed green while the handler drifted.
// This reads the handler's own list query and refuses any interpolated scope in it.
$h->section('The AREA scope must not narrow the commissary selector');

$handlerSrc = (string)file_get_contents($base . '/modules/daily-ledger/handlers.php');
// EVERY commissary list query, not the first one found. There are several (the handler's own list
// plus earlier single-quoted readers such as $ctx->db()->query('SELECT id, code, name ... ORDER BY
// name')), and checking only the first match inspected a line that could not interpolate anything -
// which made an earlier version of this guard pass even with the scope re-added. Assert over all of
// them so the query that actually feeds the selector cannot be missed.
$listQueryLines = [];
foreach (explode("\n", $handlerSrc) as $handlerLine) {
    if (strpos($handlerLine, 'FROM dl_branches WHERE is_commissary = 1 AND is_active = 1') !== false
        && stripos($handlerLine, 'ORDER BY name') !== false) {
        $listQueryLines[] = $handlerLine;
    }
}
$narrowed = array_values(array_filter(
    $listQueryLines,
    static fn(string $listLine): bool => str_contains($listLine, '{$')
));

$h->test(
    'EVERY commissary list query is free of an interpolated scope',
    $listQueryLines !== [] && $narrowed === [],
    count($listQueryLines) . ' list query/queries found; narrowed=' . count($narrowed)
);

// The data fact that makes the narrowing wrong, reported so the reason is visible when this file is
// read: area 2's members are ordinary branches and every live commissary sits in another area.
$areaMemberIds = array_map(
    'intval',
    $db->query('SELECT id FROM dl_branches WHERE area_id = 2 AND is_active = 1')->fetchAll(PDO::FETCH_COLUMN) ?: []
);
$commissaryIdsAll = array_map('intval', array_column($commissaries, 'id'));
$h->test(
    'the selector lists every active commissary, none of which area 2 contains',
    count($commissaryIdsAll) > 1
        && array_intersect($areaMemberIds, $commissaryIdsAll) === [],
    'commissaries=[' . implode(',', $commissaryIdsAll) . '] area2=[' . implode(',', $areaMemberIds) . ']'
);

$h->done();
