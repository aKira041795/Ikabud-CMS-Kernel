<?php

declare(strict_types=1);

/** Daily Ledger — per-branch Own production capability and wiring. */

ob_start();
require_once __DIR__ . '/../harness/TestHarness.php';
$h = new TestHarness('daily-ledger-own-production-capability', TestHarness::MODE_INTEGRATION, 'baronledger.test');
ob_end_clean();

$h->allowLogLines(
    'disyl.compile.phases',
    'disyl.render.breakdown',
    'kernel_state_cache: module_registry rebuilt',
    'kernel_state_cache: capability_map rebuilt',
    'kernel_state_cache: entity_presets rebuilt',
    'capability.call'
);

$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/helpers.php';
require_once $base . '/modules/daily-ledger/handlers-deliveries.php';
require_once $base . '/modules/daily-ledger/handlers.php';

app()->tenant()->setTenantId(207);
$ctx = modulePushContext('daily-ledger');
$db = $ctx->db();

$branchId = 99861;
$productId = 99861;
$date = dl_businessDate();

$handlers = (string)file_get_contents($base . '/modules/daily-ledger/handlers.php');
$offline = (string)file_get_contents($base . '/modules/daily-ledger/handlers-offline.php');
$settings = (string)file_get_contents($base . '/templates/modules/daily-ledger/admin/settings.disyl');
$ledger = (string)file_get_contents($base . '/templates/modules/daily-ledger/cashier/ledger.disyl');
$modal = (string)file_get_contents($base . '/templates/modules/daily-ledger/cashier/modal_patch.disyl');
$module = (string)file_get_contents($base . '/modules/daily-ledger/module.json');

$fnBody = static function (string $src, string $fn): string {
    $start = strpos($src, 'function ' . $fn . '(');
    if ($start === false) return '';
    $next = strpos($src, "\nfunction ", $start + 10);
    return substr($src, $start, ($next === false ? strlen($src) : $next) - $start);
};

$auditCount = static function () use ($db, $branchId): int {
    $stmt = $db->prepare("SELECT COUNT(*) FROM audit_logs WHERE module = 'daily-ledger' AND action = 'branch_flags_update' AND branch_id = ?");
    $stmt->execute([$branchId]);
    return (int)$stmt->fetchColumn();
};

$cleanup = static function () use ($db, $branchId, $productId): void {
    $db->prepare('DELETE FROM dl_daily_ledger WHERE branch_id = ? OR product_id = ?')->execute([$branchId, $productId]);
    $db->prepare("DELETE FROM audit_logs WHERE module = 'daily-ledger' AND branch_id = ?")->execute([$branchId]);
    $db->prepare('DELETE FROM dl_branch_products WHERE branch_id = ? OR product_id = ?')->execute([$branchId, $productId]);
    $db->prepare('DELETE FROM dl_products WHERE id = ?')->execute([$productId]);
    $db->prepare('DELETE FROM dl_branches WHERE id = ?')->execute([$branchId]);
};

$cleanup();
try {
    $db->prepare(
        'INSERT INTO dl_branches (id, code, name, is_active, is_commissary, paperless_dr_enabled, single_shift_mode, has_production)
         VALUES (?, ?, ?, 1, 1, 1, 0, 0)'
    )->execute([$branchId, 'PROD-99861', 'Production Capability Fixture']);
    $db->prepare('INSERT INTO dl_products (id, sku, name, current_price, is_active) VALUES (?, ?, ?, 1, 1)')
        ->execute([$productId, 'PROD-P-99861', 'Production Capability Product']);

    $h->section('live capability reader');
    $h->test(
        'a commissary has production capability even when its explicit flag is off',
        dl_branchHasProduction($branchId) === true,
        'is_commissary must preserve the existing Own production path'
    );

    $db->prepare('UPDATE dl_branches SET is_commissary = 0, has_production = 1 WHERE id = ?')->execute([$branchId]);
    $h->test(
        'a non-commissary with has_production enabled can produce',
        dl_branchHasProduction($branchId) === true,
        'retail branches that make stock would still be excluded'
    );

    $db->prepare('UPDATE dl_branches SET has_production = 0 WHERE id = ?')->execute([$branchId]);
    $h->test(
        'a non-commissary with has_production off cannot produce',
        dl_branchHasProduction($branchId) === false,
        'the reader must not fail open for a valid disabled row'
    );

    $h->section('shared branch flag applier');
    $applied = dl_applyBranchFlags($db, $branchId, ['has_production' => 1]);
    $written = (int)$db->query("SELECT has_production FROM dl_branches WHERE id = {$branchId}")->fetchColumn();
    $h->test(
        'has_production is accepted, written, and reported as changed',
        ($applied['ok'] ?? false) === true && ($applied['changed'] ?? false) === true && $written === 1,
        'the third switch did not reach the shared applier or branch column'
    );

    $latest = $db->prepare("SELECT old_data, new_data FROM audit_logs WHERE module = 'daily-ledger' AND action = 'branch_flags_update' AND branch_id = ? ORDER BY id DESC LIMIT 1");
    $latest->execute([$branchId]);
    $audit = $latest->fetch(PDO::FETCH_ASSOC) ?: [];
    $h->test(
        'the branch flag audit includes has_production in old and new JSON',
        str_contains((string)($audit['old_data'] ?? ''), 'has_production')
            && str_contains((string)($audit['new_data'] ?? ''), 'has_production'),
        'admins could not prove the capability value before and after the change'
    );

    $beforeNoop = $auditCount();
    $noop = dl_applyBranchFlags($db, $branchId, ['has_production' => 1]);
    $h->test(
        're-applying has_production is a no-op with no audit row',
        ($noop['ok'] ?? false) === true && ($noop['changed'] ?? true) === false && $auditCount() === $beforeNoop,
        'idempotent switch saves would create false audit changes'
    );

    $unknown = dl_applyBranchFlags($db, $branchId, ['production_guess' => 1]);
    $h->test(
        'unknown switch keys remain refused',
        ($unknown['ok'] ?? true) === false && str_contains((string)($unknown['error'] ?? ''), 'Unknown branch switch'),
        'a typo could report success without changing a capability'
    );

    $db->prepare('INSERT INTO dl_daily_ledger (branch_id, product_id, ledger_date, shift, beg_bal) VALUES (?, ?, ?, "AM", 0)')
        ->execute([$branchId, $productId, $date]);
    $blockedShift = dl_applyBranchFlags($db, $branchId, ['single_shift_mode' => 1]);
    $h->test(
        'the R7 guard still refuses a mid-day single_shift_mode change',
        ($blockedShift['ok'] ?? true) === false
            && (int)$db->query("SELECT single_shift_mode FROM dl_branches WHERE id = {$branchId}")->fetchColumn() === 0,
        'adding the production switch must not weaken the shift carry-forward guard'
    );

    $productionStill = dl_applyBranchFlags($db, $branchId, ['has_production' => 0]);
    $h->test(
        'has_production remains changeable after the first ledger row',
        ($productionStill['ok'] ?? false) === true
            && ($productionStill['changed'] ?? false) === true
            && (int)$db->query("SELECT has_production FROM dl_branches WHERE id = {$branchId}")->fetchColumn() === 0,
        'the R7 guard was incorrectly applied to Own production'
    );

    $h->section('source wiring');
    $onlineBody = $fnBody($handlers, 'apiSaveCashierWithdrawals');
    $h->test(
        'the online write has the OWN_PRODUCTION_DISABLED capability guard',
        str_contains($onlineBody, 'OWN_PRODUCTION_DISABLED') && str_contains($onlineBody, 'dl_branchHasProduction'),
        'add the 422 Own production guard before shift resolution in apiSaveCashierWithdrawals()'
    );
    $h->test(
        'offline replay enforces the same Own production capability',
        str_contains($offline, 'OWN_PRODUCTION_DISABLED') && str_contains($offline, 'dl_branchHasProduction'),
        'add the same branch capability refusal before offline shift resolution'
    );
    $h->test(
        'Settings renders the has_production branch toggle',
        str_contains($settings, 'data-branch-flag="has_production"'),
        'render the third toggle in Branch operating switches'
    );
    // Honest labels: a branch that cannot produce must not be told it can. The intro
    // clause hangs off the same server flag the type is withdrawn on, and the note
    // names the panel as it is titled on screen ("Branch operating switches").
    $gatePos = strpos($modal, 'x-show="window.BRANCH_HAS_PRODUCTION"');
    $clausePos = strpos($modal, 'or produced by the branch itself');
    $h->test(
        'the intro only advertises Own production on a branch that can produce',
        $gatePos !== false && $clausePos !== false && $gatePos < $clausePos,
        'gate the intro clause on BRANCH_HAS_PRODUCTION so an incapable branch is not promised a type it cannot pick'
    );
    $h->test(
        'the refusal names the Settings panel the admin actually sees',
        str_contains($modal, 'Branch operating switches')
            && str_contains($onlineBody, 'Branch operating switches')
            && str_contains($offline, 'Branch operating switches')
            && !str_contains($modal, 'Branch switches &gt;')
            && !str_contains($onlineBody, 'Branch switches >')
            && !str_contains($offline, 'Branch switches >'),
        'say Settings > Branch operating switches > Own production everywhere, matching the on-screen heading'
    );
    $h->test(
        'the cashier ledger handler passes branch_has_production',
        str_contains($fnBody($handlers, 'handleCashierLedger'), "'branch_has_production' => dl_branchHasProduction"),
        'pass the server capability into the cashier view data'
    );
    $h->test(
        'the ledger exposes BRANCH_HAS_PRODUCTION from the server value',
        str_contains($ledger, 'BRANCH_HAS_PRODUCTION') && str_contains($ledger, 'branch_has_production'),
        'publish the server flag beside BRANCH_ID and SHIFT'
    );
    $h->test(
        'the modal gates Own production on BRANCH_HAS_PRODUCTION and retains its option',
        str_contains($modal, 'window.BRANCH_HAS_PRODUCTION')
            && str_contains($modal, '<option value="own_production">'),
        'hide the choice with the server flag but keep the option when enabled so x-model renders it'
    );
    $h->test(
        'module.json registers migration 096',
        str_contains($module, 'database/migrations/096_branch_has_production.sql'),
        'append migration 096 to daily-ledger migrations'
    );
} finally {
    $cleanup();
}

$remaining = [
    'branches' => (int)$db->query("SELECT COUNT(*) FROM dl_branches WHERE id = {$branchId}")->fetchColumn(),
    'products' => (int)$db->query("SELECT COUNT(*) FROM dl_products WHERE id = {$productId}")->fetchColumn(),
    'ledger' => (int)$db->query("SELECT COUNT(*) FROM dl_daily_ledger WHERE branch_id = {$branchId} OR product_id = {$productId}")->fetchColumn(),
    'branch_products' => (int)$db->query("SELECT COUNT(*) FROM dl_branch_products WHERE branch_id = {$branchId} OR product_id = {$productId}")->fetchColumn(),
    'audit' => (int)$db->query("SELECT COUNT(*) FROM audit_logs WHERE module = 'daily-ledger' AND branch_id = {$branchId}")->fetchColumn(),
];
$h->test(
    'fixture cleanup pin: every private-id row is removed',
    array_sum($remaining) === 0,
    json_encode($remaining)
);

$h->done();
