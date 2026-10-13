<?php

declare(strict_types=1);

/**
 * Daily Ledger — receive form default origin (supply-line preselection).
 *
 * The live "origin unresolved" receive worklist kept growing because the
 * routine receive form submitted origin_id empty; originless movements cannot
 * be credited automatically (dl_applyPostedDeliveryCommissaryLedger refuses to
 * guess the origin and parks the movement), so every receive without a hand-
 * picked source parked as an unresolved item. The bounded fix preselects the
 * branch's assigned supply line (server-built map) while keeping blank valid
 * for genuinely external goods — the server still never invents an origin.
 *
 * This suite pins, by behaviour:
 *
 *   AC1  dl_defaultSourceCommissaryMap maps a branch to its assigned commissary
 *        only when that assignment is an ACTIVE commissary. Inactive, non-
 *        commissary, self-assignment and unassigned branches yield no entry.
 *   AC2  the rendered ledger page carries exactly the map the DB justifies —
 *        no fabricated pair, no omitted pair (exact comparison).
 *   AC3  the real receiveModal() script: preselects on open, clears for branch
 *        transfers, restores for commissary, and switching type resets auto_dr.
 *   AC4  a submitted receive actually carries the defaulted origin (the point
 *        of the change: it resolves and credits instead of parking).
 *   AC5  an operator-cleared origin still submits as null and a branch with no
 *        mapped supply line stays blank — nothing is invented.
 *   AC6  the harness can fail: with the default removed from the template the
 *        same run reports no default and a null origin (mutation check).
 *
 * Tenant 207 (baron-001). Fixtures use the 996xx id range inside a transaction
 * that is always rolled back; the suite never asserts whole-tenant emptiness.
 */

ob_start();
require_once __DIR__ . '/../harness/TestHarness.php';
$h = new TestHarness('daily-ledger-receive-origin-default', TestHarness::MODE_INTEGRATION, 'baronledger.test');
ob_end_clean();

// Rendering the page compiles the DiSyL template and rebuilds the kernel module
// registry; both are expected instrumentation, not defects.
$h->allowLogLines('disyl.compile.phases', 'kernel_state_cache: module_registry rebuilt', 'disyl.render.breakdown');

$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/helpers.php';
require_once $base . '/modules/daily-ledger/handlers-deliveries.php';
require_once $base . '/modules/daily-ledger/handlers.php';

$h->fingerprint('modules/daily-ledger/helpers.php');
$h->fingerprint('modules/daily-ledger/handlers.php');
$h->fingerprint('templates/modules/daily-ledger/cashier/ledger.disyl');
$h->fingerprint('templates/modules/daily-ledger/cashier/receive_modal.disyl');
$h->fingerprint('tests/daily-ledger/receive_modal_default_origin_harness.js');

app()->tenant()->setTenantId(207);
$ctx = modulePushContext('daily-ledger');
if (!$ctx) {
    fwrite(STDERR, "daily-ledger module context unavailable\n");
    exit(1);
}
$db = $ctx->db();

// Present an admin the way the browser does; without it dlCurrentUser() takes an
// auth-refusal path whose header() call warns under the harness (output sent).
$_COOKIE[dlCookieName()] = app()->jwt()->generate([
    'sub' => 'admin:1',
    'id' => 1,
    'username' => 'prod-rizal',
    'name' => 'N',
    'role' => 'admin',
    'source' => 'daily-ledger',
]);
$lastRenderError = '';
$render = static function (string $fn, array $get) use (&$lastRenderError): string {
    $_GET = $get;
    try {
        ob_start();
        $fn();
        return (string)ob_get_clean();
    } catch (\Throwable $e) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        $lastRenderError = $fn . ': ' . $e->getMessage();
        return '';
    }
};

// 99611 = fed branch (assigned active commissary 99616)
// 99612 = assigned to INACTIVE commissary 99617
// 99613 = assigned to a non-commissary branch 99618
// 99614 = active commissary assigned to ITSELF
// 99615 = no assignment at all
$fixtureIds = [99611, 99612, 99613, 99614, 99615, 99616, 99617, 99618];
$cleanup = static function ($db) use ($fixtureIds): void {
    $in = implode(',', $fixtureIds);
    $db->execute("DELETE FROM dl_branches WHERE id IN ({$in})");
};
$cleanup($db);

try {
    $db->beginTransaction();

    $seed = static function ($db, int $id, string $code, int $isCommissary, int $isActive, ?int $assigned) : void {
        $db->execute(
            'INSERT INTO dl_branches (id, code, name, address, default_supply_mode, is_commissary, is_active, assigned_commissary_id)
             VALUES (:id, :code, :name, :addr, :mode, :ic, :ia, :ac)',
            [
                ':id' => $id,
                ':code' => $code,
                ':name' => 'Test ' . $code,
                ':addr' => 'Test',
                ':mode' => $isCommissary === 1 ? 'self_managed' : 'commissary_supplied',
                ':ic' => $isCommissary,
                ':ia' => $isActive,
                ':ac' => $assigned,
            ]
        );
    };
    $seed($db, 99611, 'T-FED', 0, 1, 99616);
    $seed($db, 99612, 'T-INACT', 0, 1, 99617);
    $seed($db, 99613, 'T-NONCOMM', 0, 1, 99618);
    $seed($db, 99614, 'T-SELF', 1, 1, 99614);
    $seed($db, 99615, 'T-NONE', 0, 1, null);
    // Supply-line targets: one active commissary, one inactive, one plain branch.
    $seed($db, 99616, 'T-SRC-ACT', 1, 1, null);
    $seed($db, 99617, 'T-SRC-OFF', 1, 0, null);
    $seed($db, 99618, 'T-SRC-PLAIN', 0, 1, null);

    // ═══════════════════════════════════════════════════════════════════
    $h->section('1. The rule: only an active assigned commissary is a default');

    $h->assertSame([99611 => 99616], dl_defaultSourceCommissaryMap($db, [99611]), 'fed branch maps to its commissary');
    $h->assertSame([], dl_defaultSourceCommissaryMap($db, [99612]), 'inactive commissary is not a supply line');
    $h->assertSame([], dl_defaultSourceCommissaryMap($db, [99613]), 'plain branch is not a supply line');
    $h->assertSame([], dl_defaultSourceCommissaryMap($db, [99614]), 'self-assignment is not a supply line');
    $h->assertSame([], dl_defaultSourceCommissaryMap($db, [99615]), 'no assignment means no default');
    $h->assertSame([99611 => 99616], dl_defaultSourceCommissaryMap($db, [99611, 99612, 99613, 99614, 99615]), 'mixed set maps only the fed branch');
    $h->assertSame([], dl_defaultSourceCommissaryMap($db, []), 'empty input maps nothing');

    $h->assertSame(99616, dl_defaultSourceCommissaryId($db, 99611), 'single-branch wrapper resolves');
    $h->assertSame(0, dl_defaultSourceCommissaryId($db, 99615), 'single-branch wrapper: 0 when unassigned');
    $h->assertSame(0, dl_defaultSourceCommissaryId($db, 0), 'single-branch wrapper: 0 for non-ids');
    $h->assertSame(0, dl_defaultSourceCommissaryId($db, -5), 'single-branch wrapper: 0 for negative ids');

    // ═══════════════════════════════════════════════════════════════════
    $h->section('2. The rendered ledger page carries exactly that map');

    $activeIds = array_map('intval', $db->query('SELECT id FROM dl_branches WHERE is_active = 1')->fetchAll(PDO::FETCH_COLUMN));
    $expected = dl_defaultSourceCommissaryMap($db, $activeIds);
    ksort($expected);

    $html = $render('handleCashierLedger', ['branch_id' => '9']);
    $h->test('cashier ledger page renders', $html !== '' && $lastRenderError === '', $lastRenderError);

    $map = [];
    if (preg_match('/window\.DL_DEFAULT_SOURCE_MAP = (\{.*?\}|\[\]);/s', $html, $m)) {
        $decoded = json_decode($m[1], true);
        if (is_array($decoded)) {
            foreach ($decoded as $bid => $sid) {
                $map[(int)$bid] = (int)$sid;
            }
        }
        ksort($map);
        $h->assertSame($expected, $map, 'rendered map equals the DB-justified map (no fabrication, no omission)');
    } else {
        $h->test('window.DL_DEFAULT_SOURCE_MAP is emitted', false, 'pattern not found in rendered page');
    }
    $h->assertSame(99616, $map[99611] ?? 0, 'fixture fed branch appears in the rendered map');
    $h->test('no fabricated entry for the unassigned branch', !isset($map[99615]), 'branch 99615 present: ' . ($map[99615] ?? 'n/a'));
    $h->test('no fabricated entry for the inactive commissary branch', !isset($map[99612]), 'branch 99612 present: ' . ($map[99612] ?? 'n/a'));

    $h->test(
        'modal preselects via defaultOriginId() on open',
        str_contains($html, 'origin_id: this.defaultOriginId()'),
        'resetPaperForm default missing from rendered page'
    );
    $h->test(
        'origin-type change routes through onOriginTypeChange()',
        str_contains($html, '@change="onOriginTypeChange()"') && str_contains($html, 'onOriginTypeChange() {'),
        'change handler missing from rendered page'
    );
    $h->test(
        'blank origin remains an explicit choice',
        str_contains($html, '-- Optional --'),
        'blank option removed — external goods would be unrepresentable'
    );

    $db->rollBack();
} finally {
    $cleanup($db);
}
$left = (int)$db->query('SELECT COUNT(*) FROM dl_branches WHERE id IN (' . implode(',', $fixtureIds) . ')')->fetchColumn();
$h->assertSame(0, $left, 'fixtures removed (data untouched)');

// ═══════════════════════════════════════════════════════════════════════
$h->section('3. Behaviour: what the real receiveModal() script submits');

$template = $base . '/templates/modules/daily-ledger/cashier/receive_modal.disyl';
$harness = $base . '/tests/daily-ledger/receive_modal_default_origin_harness.js';
$node = trim((string)shell_exec('command -v node 2>/dev/null'));
$runHarness = static function (string $node, string $harness, string $template): array {
    $cmd = escapeshellarg($node) . ' ' . escapeshellarg($harness) . ' ' . escapeshellarg($template) . ' 2>&1';
    $decoded = json_decode(trim((string)shell_exec($cmd)), true);
    return is_array($decoded) ? $decoded : ['__raw' => trim((string)shell_exec($cmd))];
};

if ($node === '' || !is_file($template) || !is_file($harness)) {
    $h->test('node + template + harness available', false, "node={$node}");
} else {
    $r = $runHarness($node, $harness, $template);
    $h->assertSame('99599', (string)($r['defaultAfterReset'] ?? ''), 'mapped branch preselects its supply line on open');
    $h->assertSame('', (string)($r['afterSwitchToBranch'] ?? 'x'), 'switching to branch clears the origin (sender is chosen)');
    $h->assertSame('99599', (string)($r['afterSwitchBackToCommissary'] ?? ''), 'switching back to commissary restores the default');
    $h->assertSame(true, (bool)($r['autoDrClearedBySwitch'] ?? false), 'type switch resets the auto-DR claim');
    $h->assertSame('', (string)($r['noMapDefault'] ?? 'x'), 'unmapped branch stays blank — nothing invented');
    $h->assertSame('99599', (string)($r['defaultedPayload']['origin_id'] ?? ''), 'submitted receive carries the defaulted origin');
    $h->test(
        'operator-cleared origin still submits as null',
        array_key_exists('clearedPayload', $r) && $r['clearedPayload']['origin_id'] === null,
        json_encode($r['clearedPayload'] ?? null)
    );

    // Mutation check: with the defaulting logic removed the same run must
    // report no default and a null origin — proving the assertions can fail.
    $mutated = tempnam(sys_get_temp_dir(), 'dl_origin_mut_') . '.disyl';
    try {
        file_put_contents(
            $mutated,
            str_replace(
                "return (id && String(id) !== '0') ? String(id) : '';",
                "return '';",
                (string)file_get_contents($template)
            )
        );
        $m = $runHarness($node, $harness, $mutated);
        $h->test(
            'harness fails when the default is removed (mutation check)',
            (string)($m['defaultAfterReset'] ?? '') !== '99599'
                && array_key_exists('defaultedPayload', $m)
                && $m['defaultedPayload']['origin_id'] === null,
            json_encode(['defaultAfterReset' => $m['defaultAfterReset'] ?? null, 'payload_origin' => $m['defaultedPayload']['origin_id'] ?? null])
        );
    } finally {
        @unlink($mutated);
    }
}

$h->done();
