<?php
/**
 * One module has exactly ONE lifecycle root, and the kernel admin must see it.
 *
 * WHY THIS EXISTS (2026-10-31): kernelHandlePageAdminModules() iterates
 * discoverModules() with no filter, so the kernel admin lists all 70 installed
 * modules and grows with every extension. The owner's direction: lifecycle
 * (install / configure / control / uninstall) belongs to the PARENT module;
 * the kernel governs roots.
 *
 * The chair's verdict (`.ai/consult/kernel-ownership-capability.md`) was to
 * derive ownership from the manifest `extends` chain and to REJECT a
 * manifest-authored `managed_by`. Claude's red-team
 * (`.ai/consult/claude-redteam-kernel-ownership.md`) found the blind spot: a
 * third-party extension installed from a ZIP ships a creator-authored manifest
 * that cannot name a host it has never heard of, so derivation alone leaves it a
 * root and the bloat returns.
 *
 * The settled contract, asserted here:
 *   - `extends` is the direct lifecycle parent when the manifest declares one;
 *   - otherwise a kernel-written install-provenance record (installed_by) is the
 *     parent, because the installing host is the only party that knows;
 *   - a module declaring one parent and recorded under another is UNRESOLVED
 *     (never a silent winner), as are missing parents and cycles;
 *   - `suite` is product grouping, NOT authority - four cms-akira profile
 *     modules declare suite without extends and must stay roots;
 *   - consuming a capability never implies ownership;
 *   - the kernel admin lists roots, and reports unresolved modules instead of
 *     hiding them.
 *
 * Run: php tests/module_ownership_resolver_test.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);

ob_start();
require $root . '/bootstrap.php';
require_once BASE_PATH . '/src/helpers/module-manager.php';
ob_end_clean();

$passed = 0;
$failed = 0;
function morAssert(string $label, bool $condition, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        echo "PASS: {$label}\n";
        $passed++;
        return;
    }
    echo "FAIL: {$label}" . ($detail !== '' ? " ({$detail})" : '') . "\n";
    $failed++;
}

// ── Contract surface ───────────────────────────────────────────────────────
foreach (['moduleOwnershipResolveAll', 'moduleOwnershipRoot', 'moduleOwnershipChildren', 'moduleOwnershipProvenance', 'moduleOwnershipRecordInstall'] as $fn) {
    morAssert("{$fn}() exists", function_exists($fn));
}
if (!function_exists('moduleOwnershipResolveAll')) {
    echo "\nResult: {$passed} passed, {$failed} failed\n";
    exit(1);
}

// ── Fixture fleet: every path through the resolver ─────────────────────────
$fleet = [
    // A plain root, and a chain beneath it.
    'root-a'        => ['id' => 'root-a', 'name' => 'Root A', 'version' => '1.0.0', 'kind' => 'product-core', 'suite' => 'alpha'],
    'child-b'       => ['id' => 'child-b', 'name' => 'Child B', 'version' => '1.0.0', 'kind' => 'extension', 'extends' => 'root-a', 'suite' => 'alpha'],
    'grandchild-c'  => ['id' => 'grandchild-c', 'name' => 'Grandchild C', 'version' => '1.0.0', 'kind' => 'extension', 'extends' => 'child-b', 'suite' => 'alpha'],
    // A profile: grouped by suite, but NOT owned - suite is not authority.
    'profile-p'     => ['id' => 'profile-p', 'name' => 'Profile P', 'version' => '1.0.0', 'kind' => 'profile', 'suite' => 'alpha'],
    // The CMS case: a ZIP-installed extension with no `extends`, recorded at install.
    'cms'           => ['id' => 'cms', 'name' => 'CMS', 'version' => '1.0.0'],
    'zip-ext'       => ['id' => 'zip-ext', 'name' => 'ZIP Extension', 'version' => '2.0.0'],
    // Demonstrates that a capability relationship is not ownership.
    'consumer-z'    => ['id' => 'consumer-z', 'name' => 'Consumer', 'version' => '1.0.0',
                        'capabilities' => ['depends' => ['cms.content.get@1']]],
    // Broken ownership, each for a different reason.
    'conflict-d'    => ['id' => 'conflict-d', 'name' => 'Conflict D', 'version' => '1.0.0', 'extends' => 'root-a'],
    'ghost-e'       => ['id' => 'ghost-e', 'name' => 'Ghost E', 'version' => '1.0.0', 'extends' => 'no-such-parent'],
    'cycle-f'       => ['id' => 'cycle-f', 'name' => 'Cycle F', 'version' => '1.0.0', 'extends' => 'cycle-g'],
    'cycle-g'       => ['id' => 'cycle-g', 'name' => 'Cycle G', 'version' => '1.0.0', 'extends' => 'cycle-f'],
];

$provenance = [
    'zip-ext'    => ['installed_by' => 'cms', 'installed_at' => '2026-10-31 09:00:00'],
    'conflict-d' => ['installed_by' => 'root-a', 'installed_at' => '2026-10-31 09:00:00'], // agrees
    'ghost-e2'   => ['installed_by' => 'cms'], // recorded but not installed: must not create a root
];

$resolved = moduleOwnershipResolveAll($fleet, $provenance);

morAssert('resolver returns roots', isset($resolved['roots']) && is_array($resolved['roots']));
morAssert('resolver returns unresolved', isset($resolved['unresolved']) && is_array($resolved['unresolved']));

$roots = $resolved['roots'];
$unresolved = $resolved['unresolved'];
$unresolvedIds = array_map(static fn(array $u): string => (string) $u['id'], $unresolved);
$unresolvedReasons = [];
foreach ($unresolved as $entry) {
    $unresolvedReasons[(string) $entry['id']] = (string) ($entry['reason_code'] ?? '');
}

// ── Roots are what has no lifecycle parent ─────────────────────────────────
morAssert('root-a is a root', isset($roots['root-a']));
morAssert('cms is a root', isset($roots['cms']));
morAssert('child-b is NOT a root', !isset($roots['child-b']));
morAssert('grandchild-c is NOT a root', !isset($roots['grandchild-c']));
morAssert('a profile declaring only suite stays a root', isset($roots['profile-p']), 'suite must not imply ownership');
morAssert('a capability consumer stays a root', isset($roots['consumer-z']), 'consumption must not imply ownership');

// ── The ZIP-installed extension is owned by its installer ──────────────────
morAssert('an extension with no extends is owned by its recorded installer', !isset($roots['zip-ext']));
morAssert('the installer is its lifecycle root', moduleOwnershipRoot('zip-ext', $fleet, $provenance) === 'cms', (string) moduleOwnershipRoot('zip-ext', $fleet, $provenance));
morAssert('the installer lists it as a child', in_array('zip-ext', array_column(moduleOwnershipChildren('cms', $fleet, $provenance), 'id'), true));

// ── Descendants collapse to their ultimate root ────────────────────────────
morAssert('root resolves through one level', moduleOwnershipRoot('child-b', $fleet, $provenance) === 'root-a');
morAssert('root resolves through a chain', moduleOwnershipRoot('grandchild-c', $fleet, $provenance) === 'root-a', (string) moduleOwnershipRoot('grandchild-c', $fleet, $provenance));
morAssert('a root resolves to itself', moduleOwnershipRoot('root-a', $fleet, $provenance) === 'root-a');

$rootAChildren = moduleOwnershipChildren('root-a', $fleet, $provenance);
$rootAChildIds = array_column($rootAChildren, 'id');
morAssert('children include the direct child', in_array('child-b', $rootAChildIds, true));
morAssert('children include the grandchild', in_array('grandchild-c', $rootAChildIds, true));
foreach ($rootAChildren as $child) {
    if ($child['id'] === 'grandchild-c') {
        morAssert('a grandchild reports its DIRECT parent', (string) ($child['direct_parent'] ?? '') === 'child-b', (string) ($child['direct_parent'] ?? ''));
    }
}

// ── Broken ownership is visible, never silently resolved ──────────────────
morAssert('a self-contradicting owner is unresolved', in_array('conflict-d', $unresolvedIds, true));
morAssert('a missing parent is unresolved', in_array('ghost-e', $unresolvedIds, true));
morAssert('a missing parent reports parent_missing', ($unresolvedReasons['ghost-e'] ?? '') === 'parent_missing', ($unresolvedReasons['ghost-e'] ?? '(none)'));
morAssert('a cycle is unresolved', in_array('cycle-f', $unresolvedIds, true) && in_array('cycle-g', $unresolvedIds, true));
morAssert('a cycle reports ownership_cycle', ($unresolvedReasons['cycle-f'] ?? '') === 'ownership_cycle', ($unresolvedReasons['cycle-f'] ?? '(none)'));
morAssert('unresolved modules are NOT listed as roots', !isset($roots['ghost-e']) && !isset($roots['cycle-f']));

$everyId = array_keys($roots);
foreach ($roots as $rootId => $entry) {
    foreach (moduleOwnershipChildren((string) $rootId, $fleet, $provenance) as $child) {
        $everyId[] = (string) $child['id'];
    }
}
morAssert('no module appears under two roots', count($everyId) === count(array_unique($everyId)), implode(',', array_diff_assoc($everyId, array_unique($everyId))));

// ── A record for a module that is not installed is ignored ────────────────
morAssert('provenance for an absent module does not create a root', !isset($roots['ghost-e2']));

// ── Fleet accounting: every installed module is accounted for ─────────────
$accounted = $everyId;
sort($accounted);
$expected = array_keys($fleet);
sort($expected);
morAssert(
    'every installed module is either a root, a child, or unresolved',
    count(array_diff($expected, $accounted)) === count($unresolvedIds),
    'unaccounted: ' . implode(',', array_diff($expected, $accounted))
);

// ── The real fleet: the shape the owner will see ──────────────────────────
$real = moduleOwnershipResolveAll();
$realRoots = array_keys($real['roots']);
$realUnresolved = count($real['unresolved']);
$totalModules = count(discoverModules());
morAssert('the real fleet resolves', isset($real['roots']) && is_array($real['roots']));
morAssert(
    'the real fleet has fewer rows than modules',
    count($realRoots) < $totalModules,
    count($realRoots) . ' roots vs ' . $totalModules . ' modules'
);
morAssert(
    'cms-akira extensions collapse under their core',
    in_array('cms-akira-seo', array_column(moduleOwnershipChildren('cms-akira-core'), 'id'), true),
    'expected cms-akira-seo under cms-akira-core'
);
morAssert('the real fleet has no unresolved ownership', $realUnresolved === 0, $realUnresolved . ' unresolved');

// ── The kernel admin projection reflects the resolver ─────────────────────
$pageHandler = (string) @file_get_contents(BASE_PATH . '/src/http/page-handlers.php');
$adminTemplate = (string) @file_get_contents(BASE_PATH . '/templates/pages/admin-modules.disyl');
morAssert('kernel admin page uses the ownership resolver', str_contains($pageHandler, 'moduleOwnershipResolveAll'));
morAssert('kernel admin page offers a read-only children view', str_contains($adminTemplate, 'ownership'));
morAssert('kernel admin page surfaces unresolved ownership', str_contains($adminTemplate, 'Unresolved'));

echo "\nResult: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
