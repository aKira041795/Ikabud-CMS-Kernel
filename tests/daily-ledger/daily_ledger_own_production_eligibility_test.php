<?php

declare(strict_types=1);

/**
 * Daily Ledger — a product may be hidden from OWN PRODUCTION without leaving the cashier ledger.
 *
 * Owner report 2026-11-14, relaying the client (translated):
 *   "About Own production — can I hide some products? ... it should be only for what THEY produced
 *    that day", and: "we have the active/inactive toggle already but it also hides it from the
 *    cashier ledger — it should hide it only from the Own production choices."
 *
 * Verified cause: Own production had NO product list of its own. `modal_patch.disyl loadProducts()`
 * scraped `tr[data-product-id]` off the page — the cashier ledger rows — and those rows come from
 * `dl_branch_products WHERE is_active = 1`. So the only way to hide a product from Own production was
 * to remove it from the branch entirely, which is why the client could not do what she asked.
 *
 * WHAT THIS PINS (decision record: .ai/consult/own-production-decision-2026-11-14.md):
 *  1. Eligibility has its OWN column, defaulting to 1 so a deploy removes nothing.
 *  2. Hiding it touches ONLY that column: `is_active` — and therefore the ledger rows — is untouched.
 *  3. The setter is the audited primitive; no bare UPDATE from a handler.
 *  4. The SERVER refuses own production for a product that is not eligible, so hiding cannot be
 *     bypassed by posting the payload directly.
 *  5. The no-DR bypass stays closed: `adjustment_add` keeps its existing paperless gate, so an
 *     ineligible product cannot be booked by switching the type.
 *  6. Own production choices come from a dedicated feed, not from the ledger DOM.
 */

ob_start();
require_once __DIR__ . '/../harness/TestHarness.php';
$h = new TestHarness('daily-ledger-own-production-eligibility', TestHarness::MODE_INTEGRATION, 'baronledger.test');
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

$branchId = 99879;
$productId = 99889;

$cleanup = static function () use ($db, $branchId, $productId): void {
    $db->prepare("DELETE FROM audit_logs WHERE module = 'daily-ledger' AND branch_id = ?")->execute([$branchId]);
    $db->prepare('DELETE FROM dl_branch_products WHERE branch_id = ? OR product_id = ?')->execute([$branchId, $productId]);
    $db->prepare('DELETE FROM dl_products WHERE id = ?')->execute([$productId]);
    $db->prepare('DELETE FROM dl_branches WHERE id = ?')->execute([$branchId]);
};

$cleanup();
try {
    $db->prepare(
        'INSERT INTO dl_branches (id, code, name, is_active, is_commissary, paperless_dr_enabled, single_shift_mode, has_production)
         VALUES (?, ?, ?, 1, 0, 1, 0, 1)'
    )->execute([$branchId, 'OWNPROD', 'Own Production Fixture']);
    $db->prepare(
        'INSERT INTO dl_products (id, sku, name, current_price, product_category, is_active)
         VALUES (?, ?, ?, 1, ?, 1)'
    )->execute([$productId, 'OWNPROD-1', 'Pandesal Fixture', 'bread']);

    $h->section('eligibility has its own column');

    // The migration is applied HERE rather than assumed. A criterion that needs an operator to run a
    // migration first cannot discriminate the change: it would read red on a correct implementation
    // and green on nothing. The shipped SQL is asserted statically below, so this shortcut cannot
    // hide a wrong migration.
    $migrationFile = null;
    foreach (glob($base . '/modules/daily-ledger/database/migrations/101_*own_production*.sql') ?: [] as $candidate) {
        $migrationFile = $candidate;
    }
    $migrationSql = $migrationFile !== null ? (string)file_get_contents($migrationFile) : '';
    $column = $db->query("SHOW COLUMNS FROM dl_branch_products LIKE 'allow_own_production'")->fetch(PDO::FETCH_ASSOC) ?: [];
    if ($column === []) {
        try {
            $db->exec('ALTER TABLE dl_branch_products ADD COLUMN allow_own_production TINYINT(1) NOT NULL DEFAULT 1');
        } catch (\Throwable $e) {
            // Another process may have added it between the check and here; re-read below either way.
        }
        $column = $db->query("SHOW COLUMNS FROM dl_branch_products LIKE 'allow_own_production'")->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    $h->test(
        'the shipped migration declares the column as allowed by default',
        $migrationFile !== null
            && preg_match('/allow_own_production/', $migrationSql) === 1
            && preg_match('/DEFAULT\s+\'?1\'?/i', $migrationSql) === 1,
        'migration: ' . ($migrationFile !== null ? basename($migrationFile) : 'MISSING')
    );
    $h->test(
        'dl_branch_products carries allow_own_production, defaulting to allowed',
        $column !== [] && (string)($column['Default'] ?? '') === '1',
        'a deploy must not remove a capability branches use today: ' . json_encode($column)
    );
    $moduleJson = (string)file_get_contents($base . '/modules/daily-ledger/module.json');
    $h->test(
        'the migration that adds it is registered',
        (bool)preg_match('/database\/migrations\/101_[a-z_]*own_production[a-z_]*\.sql/', $moduleJson),
        'an unregistered migration never runs'
    );

    $db->prepare('INSERT INTO dl_branch_products (branch_id, product_id, is_active) VALUES (?, ?, 1)')
        ->execute([$branchId, $productId]);

    $h->section('hiding it does not touch the cashier ledger');
    $h->test(
        'the audited setter exists',
        function_exists('dl_setBranchProductOwnProduction')
    );

    $result = function_exists('dl_setBranchProductOwnProduction')
        ? dl_setBranchProductOwnProduction($db, $branchId, $productId, false, 0)
        : ['ok' => false];
    // Selected defensively: on the base tree the column does not exist yet, and a hard SELECT would
    // abort the suite instead of reporting the remaining assertions.
    $selectCols = 'is_active' . ($column !== [] ? ', allow_own_production' : '');
    $flags = $db->prepare('SELECT ' . $selectCols . ' FROM dl_branch_products WHERE branch_id = :b AND product_id = :p');
    $flags->execute([':b' => $branchId, ':p' => $productId]);
    $row = $flags->fetch(PDO::FETCH_ASSOC) ?: [];

    $h->test(
        'the product stays on the branch, so its ledger row survives',
        (int)($row['is_active'] ?? 0) === 1,
        'is_active must NOT move: ' . json_encode($row)
    );
    $h->test(
        'only the eligibility flag flips',
        (int)($row['allow_own_production'] ?? 1) === 0,
        json_encode($row)
    );
    $h->test(
        'the change is audited',
        ($result['ok'] ?? false) === true
            && (int)$db->query(
                "SELECT COUNT(*) FROM audit_logs WHERE module = 'daily-ledger'
                  AND action = 'branch_product_own_production_changed' AND branch_id = " . (int)$branchId
            )->fetchColumn() === 1
    );

    $h->section('the server refuses what the UI hides');
    $guardExists = function_exists('dl_assertOwnProductionAllowed');
    $refused = null;
    if ($guardExists) {
        try {
            dl_assertOwnProductionAllowed($db, $branchId, $productId);
        } catch (\Throwable $e) {
            $refused = $e;
        }
    }
    $h->test(
        'an ineligible product is refused server-side, not merely absent from a list',
        $guardExists && $refused !== null,
        'posting the payload directly must not bypass the hide'
    );
    $h->test(
        'the refusal names the product and the remedy',
        $refused !== null
            && str_contains($refused->getMessage(), 'Pandesal Fixture')
            && str_contains(strtolower($refused->getMessage()), 'own production'),
        $refused !== null ? $refused->getMessage() : 'no refusal'
    );

    if ($guardExists) {
        dl_setBranchProductOwnProduction($db, $branchId, $productId, true, 0);
        $allowed = true;
        try {
            dl_assertOwnProductionAllowed($db, $branchId, $productId);
        } catch (\Throwable $e) {
            $allowed = false;
        }
        $h->test('unhiding restores it immediately', $allowed);
    } else {
        $h->test('unhiding restores it immediately', false, 'guard missing');
    }

    $h->section('the no-DR bypass stays closed and the choices get a real feed');
    $handlersSource = (string)file_get_contents($base . '/modules/daily-ledger/handlers.php');
    $h->test(
        'own production is still gated by the paperless-DR switch',
        str_contains($handlersSource, 'dl_canAddStockWithoutPaperDr($user, (int)$branchId)')
    );
    $h->test(
        'own production checks eligibility in the write path, next to the branch switch',
        str_contains($handlersSource, 'dl_assertOwnProductionAllowed(')
            || str_contains((string)file_get_contents($base . '/modules/daily-ledger/handlers-offline.php'), 'dl_assertOwnProductionAllowed(')
    );
    $modal = (string)file_get_contents($base . '/templates/modules/daily-ledger/cashier/modal_patch.disyl');
    $h->test(
        'the modal fills Own production choices from a dedicated feed, not the ledger DOM',
        str_contains($modal, 'own-production-products'),
        'scraping tr[data-product-id] is what tied the two scopes together'
    );
} finally {
    $cleanup();
}

$h->done();
