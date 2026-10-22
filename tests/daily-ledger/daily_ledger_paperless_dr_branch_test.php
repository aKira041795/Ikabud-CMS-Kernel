<?php

declare(strict_types=1);

/**
 * Daily Ledger — per-branch "no paper DR" switch (client request 1, corrected 2026-10-22).
 *
 * OWNER CORRECTION (2026-10-22), which supersedes the first implementation:
 *   Paper DR is ALWAYS available. The per-branch switch does NOT gate paper DR.
 *   It gates the PAPERLESS stock-in path cashiers were using to bypass encoding a DR number:
 *   Stock Adjustment -> Add Stock (`adjustment_add`) and own production (`own_production`).
 *
 * Each assertion names what breaks if it regresses:
 * - gating the paper-DR endpoints takes away the workflow the client explicitly wanted kept;
 * - NOT gating `adjustment_add` / `own_production` leaves the bypass open - the exact abuse;
 * - gating the whole withdrawals endpoint would also block charge/pullout/used/correction;
 * - a default of 0 would change behaviour for every existing branch (NOT additive);
 * - a helper with no call site enforces nothing.
 */

ob_start();
require_once __DIR__ . '/../harness/TestHarness.php';
$h = new TestHarness('daily-ledger-paperless-dr-branch', TestHarness::MODE_INTEGRATION, 'baronledger.test');
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

$branchId = 15; // Fishport
app()->tenant()->setTenantId(207);
$ctx = modulePushContext('daily-ledger');
$db = $ctx->db();

$h->section('schema');

$colStmt = $db->query("SHOW COLUMNS FROM dl_branches LIKE 'paperless_dr_enabled'");
$col = $colStmt ? $colStmt->fetch(PDO::FETCH_ASSOC) : false;
$h->test(
    'dl_branches.paperless_dr_enabled exists',
    is_array($col),
    'the per-branch switch cannot be configured'
);
$h->test(
    'it defaults to 1 so every existing branch keeps today\'s behaviour',
    is_array($col) && (string)($col['Default'] ?? '') === '1',
    is_array($col) ? 'default=' . var_export($col['Default'] ?? null, true) : 'column missing'
);

// The old name said the OPPOSITE of what the switch does and cost one inverted
// implementation. A stale migration re-adding it would invite the same mistake.
$legacyColStmt = $db->query("SHOW COLUMNS FROM dl_branches LIKE 'paper_dr_enabled'");
$legacyCol = $legacyColStmt ? $legacyColStmt->fetch(PDO::FETCH_ASSOC) : false;
$h->test(
    'the legacy paper_dr_enabled column is gone',
    $legacyCol === false,
    'two columns with opposite meanings would be reintroduced'
);

$h->section('seam');

$hasFlag = function_exists('dl_branchPaperlessDrEnabled');
$hasGate = function_exists('dl_canAddStockWithoutPaperDr');
$h->test('dl_branchPaperlessDrEnabled() exists', $hasFlag, 'seam missing');
$h->test('dl_canAddStockWithoutPaperDr() exists', $hasGate, 'seam missing');

$original = null;
if (is_array($col) && $hasFlag && $hasGate) {
    $h->section('behaviour');

    $snap = $db->prepare('SELECT paperless_dr_enabled FROM dl_branches WHERE id = :id');
    $snap->execute([':id' => $branchId]);
    $original = $snap->fetchColumn();

    try {
        $db->prepare('UPDATE dl_branches SET paperless_dr_enabled = 1 WHERE id = :id')->execute([':id' => $branchId]);
        $h->test('switch ON: branch allows paperless stock-in', dl_branchPaperlessDrEnabled($branchId) === true);
        $h->test('switch ON: cashier may add stock without a paper DR', dl_canAddStockWithoutPaperDr(['role' => 'cashier'], $branchId) === true);

        $db->prepare('UPDATE dl_branches SET paperless_dr_enabled = 0 WHERE id = :id')->execute([':id' => $branchId]);
        $h->test('switch OFF: branch blocks paperless stock-in', dl_branchPaperlessDrEnabled($branchId) === false);
        $h->test(
            'switch OFF: cashier is blocked (the DR-number bypass is closed)',
            dl_canAddStockWithoutPaperDr(['role' => 'cashier'], $branchId) === false,
            'the exact abuse the client reported'
        );
        $h->test('switch OFF: admin bypasses', dl_canAddStockWithoutPaperDr(['role' => 'admin'], $branchId) === true);
        $h->test('switch OFF: supervisor bypasses', dl_canAddStockWithoutPaperDr(['role' => 'supervisor'], $branchId) === true);
    } finally {
        if ($original !== false && $original !== null) {
            $db->prepare('UPDATE dl_branches SET paperless_dr_enabled = :v WHERE id = :id')
                ->execute([':v' => (int)$original, ':id' => $branchId]);
        }
    }
}

$h->section('enforcement wiring');

$handlers = (string)@file_get_contents($base . '/modules/daily-ledger/handlers.php');
$deliveries = (string)@file_get_contents($base . '/modules/daily-ledger/handlers-deliveries.php');

$fnBody = static function (string $src, string $fn): string {
    $start = strpos($src, 'function ' . $fn . '(');
    if ($start === false) {
        return '';
    }
    $next = strpos($src, "\nfunction ", $start + 10);
    return substr($src, $start, ($next === false ? strlen($src) : $next) - $start);
};

$withdrawals = $fnBody($handlers, 'apiSaveCashierWithdrawals');
$h->test(
    'apiSaveCashierWithdrawals() consults the paperless switch',
    str_contains($withdrawals, 'dl_canAddStockWithoutPaperDr'),
    'the cashier Add Stock / own production bypass is unguarded'
);
$h->test(
    'it gates exactly adjustment_add and own_production',
    str_contains($withdrawals, "'adjustment_add'") && str_contains($withdrawals, "'own_production'"),
    'the wrong types would be blocked'
);
$h->test(
    'it refuses with a stable code',
    str_contains($withdrawals, 'PAPERLESS_DR_DISABLED')
);

foreach (['apiCreateCashierDispatch', 'apiReceivePaperDelivery'] as $fn) {
    $h->test(
        $fn . '() is NOT gated (paper DR stays always available)',
        !str_contains($fnBody($handlers, $fn), 'dl_canAddStockWithoutPaperDr')
    );
}
foreach (['apiGetDeliveryByDrForEdit', 'apiEditDeliveryByDr'] as $fn) {
    $h->test(
        $fn . '() is NOT gated (paper DR stays always available)',
        !str_contains($fnBody($deliveries, $fn), 'dl_canAddStockWithoutPaperDr')
    );
}

$h->done();
