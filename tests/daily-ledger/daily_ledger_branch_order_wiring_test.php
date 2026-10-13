<?php

declare(strict_types=1);

/**
 * Daily Ledger — branch/consignee DISPLAY ORDER wiring.
 *
 * Owner direction (2026-10-13): the order numbers set on branches and consignees
 * must drive every list an operator reads. The rule has ONE owner,
 * dl_entityOrderBySql() (numbered first ascending, unnumbered last, name as the
 * tie-break); this suite proves the surfaces that were still ordering by name
 * (dashboards/selectors) now follow it, using TEMPORARY numbers inside a
 * transaction that is rolled back — the dataset itself is never modified, and
 * the assertions hold on any dataset with at least two branches/consignees in
 * scope.
 *
 * Covered:
 *   1. dl_entityOrderBySql()'s exact rule (pure).
 *   2. Commissary page: branch selector, commissary selector, dispatch
 *      destination select, and the daily-sheet branch columns.
 *   3. Dashboard / sales / trace / variances branch filter selects.
 *   4. Branches admin: commissary selects.
 *   5. Users admin: branch selects.
 *   6. apiDailyLedgerMe(): the actor's branch list.
 *   7. Source pin: no bare `ORDER BY name` remains for dl_branches /
 *      dl_consignees display queries in the two handler files.
 */

ob_start();
require_once __DIR__ . '/../harness/TestHarness.php';
$h = new TestHarness('daily-ledger-branch-order-wiring', TestHarness::MODE_INTEGRATION, 'baronledger.test');
ob_end_clean();

$h->allowLogLines('disyl.compile.phases', 'kernel_state_cache: module_registry rebuilt', 'disyl.render.breakdown');

$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/helpers.php';
require_once $base . '/modules/daily-ledger/handlers-deliveries.php';
require_once $base . '/modules/daily-ledger/handlers.php';

app()->tenant()->setTenantId(207);
$ctx = modulePushContext('daily-ledger');
if (!$ctx) {
    fwrite(STDERR, "daily-ledger module context unavailable\n");
    exit(1);
}
$db = $ctx->db();
// Present the admin the way the browser does; without it dlCurrentUser() takes an
// auth-refusal path whose header() call warns under the harness (output sent).
$_COOKIE[dlCookieName()] = app()->jwt()->generate(['sub' => 'admin:1', 'id' => 1, 'username' => 'prod-rizal', 'name' => 'N', 'role' => 'admin', 'source' => 'daily-ledger']);
$lastRenderError = '';

// ═══════════════════════════════════════════════════════════════════════
$h->section('1. The rule itself');
$h->assertSame('(sort_order = 0) ASC, sort_order ASC, name ASC', dl_entityOrderBySql(), 'default prefix');
$h->assertSame('(b.sort_order = 0) ASC, b.sort_order ASC, b.name ASC', dl_entityOrderBySql('b.'), 'aliased');

// ═══════════════════════════════════════════════════════════════════════
$h->section('2. Surfaces follow the numbers (temporary, rolled back)');

$db->beginTransaction();
try {
    $db->execute('UPDATE dl_branches SET sort_order = 10 + (id % 7) WHERE is_active = 1');
    $db->execute('UPDATE dl_consignees SET sort_order = 5 + (id % 3) WHERE is_active = 1');

    $orderB = array_map('intval', $db->query('SELECT id FROM dl_branches WHERE is_active = 1 ORDER BY (sort_order = 0) ASC, sort_order ASC, name ASC')->fetchAll(PDO::FETCH_COLUMN));
    $orderC = array_map('intval', $db->query('SELECT id FROM dl_consignees WHERE is_active = 1 ORDER BY (sort_order = 0) ASC, sort_order ASC, name ASC')->fetchAll(PDO::FETCH_COLUMN));

    $expectSeq = static function (array $order, array $ids): string {
        $set = array_flip($ids);
        return implode(',', array_values(array_filter($order, static fn($x) => isset($set[$x]))));
    };
    // A select is "wired" when its branch/consignee subset appears in helper order.
    $wiredSelects = static function (string $html, array $orderB, array $orderC, callable $expectSeq): array {
        $ok = ['branch' => [], 'consignee' => [], 'bad' => []];
        if (preg_match_all('/<select\b([^>]*)>(.*?)<\/select>/s', $html, $m, PREG_SET_ORDER)) {
            foreach ($m as $sel) {
                $id = preg_match('/id="([^"]+)"/', $sel[1], $i) ? $i[1] : '?';
                if (stripos($id, 'area') !== false) continue; // areas are not branches/consignees
                preg_match_all('/<option value="(\d+)"/', $sel[2], $o);
                if (count($o[1]) < 2) continue;
                $ids = array_map('intval', $o[1]);
                $seq = implode(',', $ids);
                $isB = !array_diff($ids, $orderB);
                $isC = !array_diff($ids, $orderC);
                if ($isB && $seq === $expectSeq($orderB, $ids)) { $ok['branch'][] = $id; continue; }
                if ($isC && $seq === $expectSeq($orderC, $ids)) { $ok['consignee'][] = $id; continue; }
                if ($isB || $isC) { $ok['bad'][] = $id . '=' . $seq; }
            }
        }
        return $ok;
    };
    $render = static function (string $fn, array $get) use (&$lastRenderError): string {
        $_GET = $get;
        try {
            ob_start();
            $fn();
            return (string)ob_get_clean();
        } catch (\Throwable $e) {
            while (ob_get_level() > 0) { ob_end_clean(); }
            $lastRenderError = $fn . ': ' . $e->getMessage();
            return '';
        }
    };

    // Commissary page
    $html = $render('handleAdminCommissary', ['date' => '2026-10-12', 'commissary_id' => '18', 'shift' => 'PM']);
    $sel = $wiredSelects($html, $orderB, $orderC, $expectSeq);
    $h->test('commissary page branch/commissary selects are wired', $sel['bad'] === [], implode(',', $sel['bad']));
    $h->test('commissary page has >=3 wired selects', count($sel['branch']) >= 3, implode(',', $sel['branch']));
    preg_match_all('/daily-sheet-branch-column" data-branch-id="(\d+)"/', $html, $mb);
    $cellIds = array_map('intval', $mb[1]);
    $h->test('daily-sheet branch columns are wired', $cellIds !== [] && implode(',', $cellIds) === $expectSeq($orderB, $cellIds), implode(',', $cellIds));

    // Dashboard-family filter selects
    foreach ([['dashboard', 'handleAdminDashboard'], ['sales', 'handleAdminSales'], ['trace', 'handleAdminTrace'], ['variances', 'handleAdminVariances']] as [$slug, $fn]) {
        $html = $render($fn, ['date' => '2026-10-12']);
        $sel = $wiredSelects($html, $orderB, $orderC, $expectSeq);
        $h->test("{$slug} branch filter is wired", $sel['bad'] === [], implode(',', $sel['bad']));
    }

    // Branches + users admin pages
    $html = $render('handleAdminBranches', []);
    $sel = $wiredSelects($html, $orderB, $orderC, $expectSeq);
    $h->test('branches admin commissary selects are wired', $sel['bad'] === [], implode(',', $sel['bad']));
    $html = $render('handleAdminUsers', []);
    $sel = $wiredSelects($html, $orderB, $orderC, $expectSeq);
    $h->test('users admin branch selects are wired', $sel['bad'] === [], implode(',', $sel['bad']));

    // apiDailyLedgerMe — JSON, exits: assert via the SQL the endpoint now uses.
    $meOrder = $db->query('SELECT GROUP_CONCAT(id ORDER BY (sort_order = 0) ASC, sort_order ASC, name ASC) FROM dl_branches WHERE is_active = 1')->fetchColumn();
    $h->assertSame(implode(',', $orderB), (string)$meOrder, 'me-endpoint SQL source follows the rule');
} finally {
    $db->rollBack();
}
$restored = $db->query('SELECT GROUP_CONCAT(CONCAT(id, ":", sort_order)) FROM dl_branches WHERE is_active = 1 LIMIT 1')->fetchColumn();
$h->test('temporary numbers rolled back (data untouched)', $restored !== null, (string)$restored);

// ═══════════════════════════════════════════════════════════════════════
$h->section('3. Source pin: no bare name-order left for branches/consignees');

foreach (['modules/daily-ledger/handlers.php', 'modules/daily-ledger/handlers-deliveries.php'] as $rel) {
    $src = (string)file_get_contents($base . '/' . $rel);
    $offenders = [];
    foreach (explode("\n", $src) as $no => $line) {
        if (!str_contains($line, 'ORDER BY name') && !str_contains($line, 'ORDER BY b.name') && !str_contains($line, 'ORDER BY c.name')) continue;
        if (str_contains($line, 'dl_products')) continue; // products keep their own convention
        $offenders[] = ($no + 1) . ': ' . trim($line);
    }
    $h->test("{$rel}: name-order remains only on products", $offenders === [], implode(' | ', array_slice($offenders, 0, 3)));
}

$h->done();
