<?php

declare(strict_types=1);

/**
 * Committed coverage for dl_deliveryRecordAuthorized().
 *
 * Baseline fix: 1f442aee ("an unresolved delivery branch side must not deny the
 * receipt"). 112 of 118 tenant-207 deliveries carry origin_id NULL and
 * resolved_origin_id NULL, so the pre-fix both-sides predicate refused the
 * receiving detail to every role — including the admin — while the delivery
 * list still showed the row. The fix decides on the resolvable branch side(s)
 * and fails closed when a branch-scoped record has no resolvable side at all.
 *
 * The predicate is a pure function over arrays, so its whole truth table is
 * enumerable. This suite pins all eight matrix cases, then drives the live HTTP
 * endpoint for a real NULL-origin delivery so the fix keeps its end-to-end
 * negative control as well:
 *   - unauthenticated           → 401
 *   - production_in_charge      → refused (the role's Daily Sheet allowlist)
 *   - admin                     → 200 with a non-empty `items` array
 *
 * Reverting "an unresolved side is not a restriction" flips case 1 to REFUSED;
 * reverting the fail-closed guard flips case 5 to AUTHORIZED. Both are RED.
 */

ob_start();
require_once __DIR__ . '/../harness/TestHarness.php';
$h = new TestHarness('daily-ledger-delivery-record-authz', TestHarness::MODE_INTEGRATION, 'baronledger.test');
ob_end_clean();

// The HTTP negative control intentionally issues one unauthenticated request,
// which the API logs. Rendering / module bootstrapping may also write timing
// lines during the run; allow only those, so any real warning still fails.
$h->allowLogLines(
    'disyl.compile.phases',
    'kernel_state_cache: module_registry rebuilt',
    'daily-ledger api auth missing token'
);

$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/helpers.php';
require_once $base . '/modules/daily-ledger/handlers-deliveries.php';
require_once $base . '/modules/daily-ledger/handlers.php';

$h->fingerprint('modules/daily-ledger/handlers-deliveries.php');

app()->tenant()->setTenantId(207);
$ctx = modulePushContext('daily-ledger');
if (!$ctx) {
    fwrite(STDERR, "daily-ledger module context unavailable\n");
    exit(1);
}
$db = $ctx->db();

// Real identities on the baronledger tenant: the admin can see every active
// branch, while production_in_charge 27 is scoped to branch 18.
$adminUser = [
    'id' => 1,
    'sub' => 'admin:1',
    'username' => 'Ledger-Admin',
    'role' => 'admin',
    'source' => 'daily-ledger',
];
$picUser = [
    'id' => 27,
    'sub' => 'production_in_charge:27',
    'username' => 'prod-rizal',
    'role' => 'production_in_charge',
    'source' => 'daily-ledger',
];

$adminBranches = dl_accessibleBranchIds($adminUser);
if ($adminBranches === []) {
    fwrite(STDERR, "admin has no accessible branches; cannot build the authz matrix\n");
    exit(1);
}
$accessibleOrigin = (int)$adminBranches[0];
$accessibleDestination = (int)($adminBranches[1] ?? $adminBranches[0]);
$inaccessibleBranch = 999999; // not an active tenant branch, so inaccessible to every role

$describe = static function (array $delivery, bool $expected, bool $actual): string {
    return json_encode([
        'delivery' => $delivery,
        'expected' => $expected ? 'AUTHORIZED' : 'REFUSED',
        'actual' => $actual ? 'AUTHORIZED' : 'REFUSED',
    ], JSON_UNESCAPED_SLASHES);
};

$assertCase = static function (string $label, bool $expected, array $user, array $delivery) use ($h, $describe): void {
    $actual = dl_deliveryRecordAuthorized($user, $delivery);
    $h->test($label, $actual === $expected, $describe($delivery, $expected, $actual));
};

echo 'ACCEPTANCE_AUTHZ_BRANCHES=' . json_encode([
    'admin' => $adminBranches,
    'accessible_origin' => $accessibleOrigin,
    'accessible_destination' => $accessibleDestination,
    'inaccessible' => $inaccessibleBranch,
], JSON_UNESCAPED_SLASHES) . "\n";

// ─── Matrix ────────────────────────────────────────────────────────────────
$h->section('matrix case 1 — unresolved origin + accessible destination');
$case1 = [
    'origin_type' => 'commissary',
    'origin_id' => null,
    'resolved_origin_id' => null,
    'destination_type' => 'branch',
    'destination_id' => $accessibleDestination,
];
$assertCase(
    'case 1: unresolved origin + accessible destination → AUTHORIZED (the 112/118 fixed bug)',
    true,
    $adminUser,
    $case1
);

$h->section('matrix case 2 — unresolved origin + inaccessible destination');
$case2 = [
    'origin_type' => 'commissary',
    'origin_id' => null,
    'resolved_origin_id' => null,
    'destination_type' => 'branch',
    'destination_id' => $inaccessibleBranch,
];
$assertCase(
    'case 2: unresolved origin + inaccessible destination → REFUSED (no widening)',
    false,
    $adminUser,
    $case2
);

$h->section('matrix case 3 — both sides resolved and accessible');
$case3 = [
    'origin_type' => 'commissary',
    'origin_id' => $accessibleOrigin,
    'resolved_origin_id' => $accessibleOrigin,
    'destination_type' => 'branch',
    'destination_id' => $accessibleDestination,
];
$assertCase(
    'case 3: both sides resolved and accessible → AUTHORIZED (prior behaviour retained)',
    true,
    $adminUser,
    $case3
);

$h->section('matrix case 4 — both sides resolved, one inaccessible');
$case4DestinationInaccessible = [
    'origin_type' => 'commissary',
    'origin_id' => $accessibleOrigin,
    'resolved_origin_id' => $accessibleOrigin,
    'destination_type' => 'branch',
    'destination_id' => $inaccessibleBranch,
];
$case4OriginInaccessible = [
    'origin_type' => 'commissary',
    'origin_id' => $inaccessibleBranch,
    'resolved_origin_id' => $inaccessibleBranch,
    'destination_type' => 'branch',
    'destination_id' => $accessibleDestination,
];
$actual4a = dl_deliveryRecordAuthorized($adminUser, $case4DestinationInaccessible);
$actual4b = dl_deliveryRecordAuthorized($adminUser, $case4OriginInaccessible);
$h->test(
    'case 4: both sides resolved, one inaccessible → REFUSED (both-sides rule still applies)',
    $actual4a === false && $actual4b === false,
    json_encode([
        'destination_inaccessible' => $describe($case4DestinationInaccessible, false, $actual4a),
        'origin_inaccessible' => $describe($case4OriginInaccessible, false, $actual4b),
    ], JSON_UNESCAPED_SLASHES)
);

$h->section('matrix case 5 — neither side resolvable, branch-scoped');
$case5NullDestination = [
    'origin_type' => 'commissary',
    'origin_id' => null,
    'resolved_origin_id' => null,
    'destination_type' => 'branch',
    'destination_id' => null,
];
$case5ZeroDestination = [
    'origin_type' => 'commissary',
    'origin_id' => null,
    'resolved_origin_id' => null,
    'destination_type' => 'branch',
    'destination_id' => 0,
];
$actual5a = dl_deliveryRecordAuthorized($adminUser, $case5NullDestination);
$actual5b = dl_deliveryRecordAuthorized($adminUser, $case5ZeroDestination);
$h->test(
    'case 5: neither side resolvable, branch-scoped → REFUSED (fail closed, not "anyone with the role")',
    $actual5a === false && $actual5b === false,
    json_encode([
        'null_destination' => $describe($case5NullDestination, false, $actual5a),
        'zero_destination' => $describe($case5ZeroDestination, false, $actual5b),
    ], JSON_UNESCAPED_SLASHES)
);

$h->section('matrix case 6 — non-branch-scoped record');
$case6 = [
    'origin_type' => 'supplier',
    'origin_id' => null,
    'resolved_origin_id' => null,
    'destination_type' => 'warehouse',
    'destination_id' => null,
];
$picActual6 = dl_deliveryRecordAuthorized($picUser, $case6);
$adminActual6 = dl_deliveryRecordAuthorized($adminUser, $case6);
$h->test(
    'case 6: non-branch-scoped record → non-admin REFUSED, admin AUTHORIZED',
    $picActual6 === false && $adminActual6 === true,
    json_encode([
        'non_admin' => $describe($case6, false, $picActual6),
        'admin' => $describe($case6, true, $adminActual6),
    ], JSON_UNESCAPED_SLASHES)
);

$h->section('matrix case 7 — destination_id 0/NULL with a resolvable accessible origin');
$case7NullDestination = [
    'origin_type' => 'commissary',
    'origin_id' => $accessibleOrigin,
    'resolved_origin_id' => null,
    'destination_type' => 'branch',
    'destination_id' => null,
];
$case7ZeroDestination = [
    'origin_type' => 'commissary',
    'origin_id' => $accessibleOrigin,
    'resolved_origin_id' => null,
    'destination_type' => 'branch',
    'destination_id' => 0,
];
$case7InaccessibleOrigin = [
    'origin_type' => 'commissary',
    'origin_id' => $inaccessibleBranch,
    'resolved_origin_id' => null,
    'destination_type' => 'branch',
    'destination_id' => null,
];
$actual7a = dl_deliveryRecordAuthorized($adminUser, $case7NullDestination);
$actual7b = dl_deliveryRecordAuthorized($adminUser, $case7ZeroDestination);
$actual7c = dl_deliveryRecordAuthorized($adminUser, $case7InaccessibleOrigin);
$h->test(
    'case 7: resolvable accessible origin decides when destination_id is 0/NULL',
    $actual7a === true && $actual7b === true && $actual7c === false,
    json_encode([
        'null_destination' => $describe($case7NullDestination, true, $actual7a),
        'zero_destination' => $describe($case7ZeroDestination, true, $actual7b),
        'inaccessible_origin' => $describe($case7InaccessibleOrigin, false, $actual7c),
    ], JSON_UNESCAPED_SLASHES)
);

$h->section('matrix case 8 — resolved_origin_id present but origin_id NULL');
$case8ResolvedAccessible = [
    'origin_type' => 'commissary',
    'origin_id' => null,
    'resolved_origin_id' => $accessibleOrigin,
    'destination_type' => 'branch',
    'destination_id' => $accessibleDestination,
];
$case8ResolvedInaccessible = [
    'origin_type' => 'commissary',
    'origin_id' => null,
    'resolved_origin_id' => $inaccessibleBranch,
    'destination_type' => 'branch',
    'destination_id' => $accessibleDestination,
];
$actual8a = dl_deliveryRecordAuthorized($adminUser, $case8ResolvedAccessible);
$actual8b = dl_deliveryRecordAuthorized($adminUser, $case8ResolvedInaccessible);
$h->test(
    'case 8: origin_id NULL resolves via resolved_origin_id (accessible passes, inaccessible refuses)',
    $actual8a === true && $actual8b === false,
    json_encode([
        'resolved_accessible' => $describe($case8ResolvedAccessible, true, $actual8a),
        'resolved_inaccessible' => $describe($case8ResolvedInaccessible, false, $actual8b),
    ], JSON_UNESCAPED_SLASHES)
);

// ─── HTTP negative control ──────────────────────────────────────────────────
$h->section('HTTP negative control — live receiving-detail endpoint');

$liveDelivery = $db->query(
    "SELECT d.id
       FROM dl_deliveries d
      WHERE d.origin_id IS NULL
        AND d.resolved_origin_id IS NULL
        AND d.destination_type = 'branch'
        AND EXISTS (
              SELECT 1
                FROM dl_branch_receivings br
                INNER JOIN dl_branch_receiving_items ri ON ri.receiving_id = br.id
               WHERE br.delivery_id = d.id
                 AND br.status <> 'voided'
        )
      ORDER BY d.id DESC
      LIMIT 1"
)->fetch(PDO::FETCH_ASSOC) ?: null;

$httpGet = static function (string $url, ?string $cookieToken): array {
    $headers = ['Accept: application/json'];
    if ($cookieToken !== null) {
        $headers[] = 'Cookie: daily_ledger_token=' . $cookieToken;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $error = curl_error($ch);
    $body = is_string($raw) ? substr($raw, $headerSize) : '';
    return ['status' => $status, 'body' => $body, 'error' => $error];
};

$httpBase = rtrim((string)(getenv('TEST_BASE_URL') ?: 'http://baronledger.test'), '/');
$reachable = $httpGet($httpBase . '/', null);

if (!$liveDelivery) {
    $h->skip('HTTP negative control', 'no NULL-origin delivery with receiving items on tenant 207; matrix coverage still applies.');
} elseif (($reachable['status'] ?? 0) === 0) {
    $h->skip('HTTP negative control', 'no live web server reachable at ' . $httpBase . ' (' . $reachable['error'] . ').');
} else {
    $deliveryId = (int)$liveDelivery['id'];
    $url = $httpBase . '/daily-ledger/api/v1/deliveries/receiving-detail?delivery_id=' . $deliveryId;

    $adminToken = app()->jwt()->generate([
        'sub' => 'admin:1',
        'id' => 1,
        'username' => 'Ledger-Admin',
        'name' => 'Ledger Admin',
        'role' => 'admin',
        'source' => 'daily-ledger',
    ]);
    $picToken = app()->jwt()->generate([
        'sub' => 'production_in_charge:27',
        'id' => 27,
        'username' => 'prod-rizal',
        'name' => 'Prod Rizal',
        'role' => 'production_in_charge',
        'source' => 'daily-ledger',
    ]);

    echo 'ACCEPTANCE_HTTP_DELIVERY=' . json_encode(['delivery_id' => $deliveryId, 'url' => $url], JSON_UNESCAPED_SLASHES) . "\n";

    $unauth = $httpGet($url, null);
    echo 'ACCEPTANCE_HTTP_UNAUTH=' . json_encode($unauth, JSON_UNESCAPED_SLASHES) . "\n";
    $h->test(
        'HTTP: unauthenticated receiving-detail is refused (401)',
        $unauth['status'] === 401,
        json_encode($unauth, JSON_UNESCAPED_SLASHES)
    );

    $pic = $httpGet($url, $picToken);
    echo 'ACCEPTANCE_HTTP_PIC=' . json_encode($pic, JSON_UNESCAPED_SLASHES) . "\n";
    $h->test(
        'HTTP: production_in_charge receiving-detail is refused (403)',
        $pic['status'] === 403,
        json_encode($pic, JSON_UNESCAPED_SLASHES)
    );

    $admin = $httpGet($url, $adminToken);
    $adminBody = json_decode($admin['body'], true);
    $adminItems = is_array($adminBody['items'] ?? null) ? count($adminBody['items']) : 0;
    echo 'ACCEPTANCE_HTTP_ADMIN=' . json_encode([
        'status' => $admin['status'],
        'items' => $adminItems,
        'body' => $admin['body'],
    ], JSON_UNESCAPED_SLASHES) . "\n";
    $h->test(
        'HTTP: admin receiving-detail is 200 with non-empty items',
        $admin['status'] === 200 && ($adminBody['ok'] ?? false) === true && $adminItems > 0,
        json_encode(['status' => $admin['status'], 'items' => $adminItems], JSON_UNESCAPED_SLASHES)
    );
}

$h->done();
