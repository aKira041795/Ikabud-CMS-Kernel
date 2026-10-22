<?php

declare(strict_types=1);

/**
 * Daily Ledger — the dispatch modal follows the commissary it is bound to.
 *
 * OWNER REPORT (2026-10-22): "at admin, changing commissary does not change the branches
 * dropdown at the modal", and a production-in-charge user must always resolve to the
 * commissary branches their assignment names.
 *
 * Each assertion names what breaks if it regresses:
 * - the modal reading `branches` again: it is the FILTER list, which unions in branches
 *   that merely received a DR, and it includes commissaries themselves;
 * - an assigned operator falling back to "All": their dispatch list becomes the whole
 *   network instead of their hub;
 * - the selector not applying: the visible dropdown and the modal disagree, which is
 *   the exact symptom reported.
 */

ob_start();
require_once __DIR__ . '/../harness/TestHarness.php';
$h = new TestHarness('daily-ledger-dispatch-commissary-scope', TestHarness::MODE_INTEGRATION, 'baronledger.test');
ob_end_clean();

$h->allowLogLines(
    'disyl.compile.phases',
    'disyl.render.breakdown',
    'kernel_state_cache: module_registry rebuilt',
    'kernel_state_cache: capability_map rebuilt',
    'kernel_state_cache: entity_presets rebuilt'
);

$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/helpers.php';
require_once $base . '/modules/daily-ledger/handlers-deliveries.php';
require_once $base . '/modules/daily-ledger/handlers.php';

app()->tenant()->setTenantId(207);
$ctx = modulePushContext('daily-ledger');
$db = $ctx->db();

$h->section('modal source');

$tpl = (string)file_get_contents($base . '/templates/modules/daily-ledger/admin/commissary.disyl');
$h->test(
    'the destination list comes from dispatch_branches',
    str_contains($tpl, '{foreach dispatch_branches as br}'),
    'the modal would read the filter list, which includes non-assigned branches and commissaries'
);
$h->test(
    'the destination list no longer reads the filter list',
    !preg_match('/dispatch-dest-branch-id[\s\S]{0,700}\{foreach branches as br\}/', $tpl),
    'the modal would offer destinations this commissary does not supply'
);
$h->test(
    'the selector applies on change',
    str_contains($tpl, "onchange=\"this.form.elements['branch_id'].value = ''; this.form.submit();\""),
    'the visible commissary and the modal disagree until Apply is pressed - the reported symptom'
);

$h->section('handler');

$handlerFile = (string)file_get_contents($base . '/modules/daily-ledger/handlers.php');
$h->test(
    'dispatch_branches is passed to the view',
    str_contains($handlerFile, "'dispatch_branches' => \$dispatchBranches,"),
    'the template variable would be undefined and the modal would render empty'
);
$h->test(
    'it selects ownership, not delivery history',
    str_contains($handlerFile, "b.assigned_commissary_id = :cid'"),
    'branches that only received a DR would become destinations'
);
$h->test(
    'an assigned operator never falls back to All',
    str_contains($handlerFile, 'elseif ($narrowCommissarySelector && $availableCommissaryIds !== [])')
        && preg_match('/narrowCommissarySelector && \$availableCommissaryIds !== \[\]\) \{[\s\S]{0,400}?\$selectedCommissaryId = \$availableCommissaryIds\[0\];/', $handlerFile) === 1,
    'a production user would land on the network view and dispatch outside their hub'
);

$h->section('fixture: assignment resolves to one commissary');

$prods = $db->query(
    "SELECT id, username FROM dl_users WHERE role = 'production_in_charge' AND is_active = 1 AND deleted_at IS NULL"
)->fetchAll(PDO::FETCH_ASSOC);
$h->test('the fixture has production-in-charge users', $prods !== [], 'nothing to verify against');

$checked = 0;
$allSingle = true;
foreach ($prods as $p) {
    $ids = [];
    $s = $db->prepare(
        'SELECT b.id FROM dl_user_branches ub
           INNER JOIN dl_branches b ON b.id = ub.branch_id AND b.is_active = 1 AND b.is_commissary = 1
          WHERE ub.user_id = :u'
    );
    $s->execute([':u' => (int)$p['id']]);
    foreach ($s as $row) {
        $ids[] = (int)$row['id'];
    }
    $checked++;
    if (count($ids) !== 1) {
        $allSingle = false;
    }
}
$h->test(
    'each production-in-charge user is bound to exactly one commissary',
    $allSingle,
    'the fallback would be ambiguous, so "their commissary" could not be determined'
);

$h->section('fixture: an assigned operator resolves to their own hub');

$prodUser = $db->query(
    "SELECT * FROM dl_users WHERE role = 'production_in_charge' AND is_active = 1 AND deleted_at IS NULL ORDER BY id LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);
$h->test('a production-in-charge user row is available', is_array($prodUser));

if (is_array($prodUser)) {
    // Same helper the handler authorizes with, so this is the real available set.
    $accessible = dl_accessibleBranchIds($prodUser);
    $h->test('their accessible set is not empty', $accessible !== [], 'they would see an empty sheet');

    $accessibleCommissaries = [];
    if ($accessible !== []) {
        $inList = implode(',', array_map('intval', $accessible));
        foreach ($db->query("SELECT id FROM dl_branches WHERE is_commissary = 1 AND is_active = 1 AND id IN ({$inList})") as $row) {
            $accessibleCommissaries[] = (int)$row['id'];
        }
    }
    $h->test(
        'exactly one commissary is available to them',
        count($accessibleCommissaries) === 1,
        'the handler would have more than one hub to choose from'
    );

    // The guard resolves an unavailable request back to that single hub, so whatever the
    // URL asks for, the modal can only offer THEIR commissary's branches.
    $own = $accessibleCommissaries[0] ?? 0;
    $h->test('their hub is a real commissary', $own > 0);

    if ($own > 0) {
        $s = $db->prepare('SELECT COUNT(*) FROM dl_branches WHERE is_active = 1 AND is_commissary = 0 AND assigned_commissary_id = :cid');
        $s->execute([':cid' => $own]);
        $h->test(
            'that hub has destinations to offer',
            (int)$s->fetchColumn() > 0,
            'the modal would open with an empty destination list'
        );
    }
}

$h->section('fixture: destination lists follow the commissary');
$assigned = function (int $cid) use ($db): array {
    $s = $db->prepare(
        'SELECT id FROM dl_branches
          WHERE is_active = 1 AND is_commissary = 0 AND assigned_commissary_id = :cid ORDER BY id'
    );
    $s->execute([':cid' => $cid]);
    return array_map('intval', array_column($s->fetchAll(PDO::FETCH_ASSOC) ?: [], 'id'));
};
$c18 = $assigned(18);
$c21 = $assigned(21);
$h->test('commissary 18 has assigned destinations', $c18 !== []);
$h->test('commissary 21 has assigned destinations', $c21 !== []);
$h->test(
    'the two commissaries do not share a destination list',
    $c18 !== $c21,
    'a stale list would be indistinguishable from a correct one'
);
$h->test(
    'no branch assigned to two commissaries at once',
    array_intersect($c18, $c21) === [],
    'one branch would appear under both hubs'
);

$h->done();
