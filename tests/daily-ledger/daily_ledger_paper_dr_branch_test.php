<?php

declare(strict_types=1);

/**
 * Daily Ledger — per-branch "No paper DR" switch (client request 1, 2026-10-22).
 *
 * Contract: .ai/daily-ledger-client-batch-2026-10-22.contract.md
 *
 * Owner decisions locked:
 *   - the switch is PER BRANCH (`dl_branches.paper_dr_enabled`, default 1 = today's behaviour)
 *   - admin AND supervisor always bypass it
 *   - cashiers lose the whole paper-DR surface when it is off (dispatch-from-paper,
 *     receive-paper-dr, delivery-detail prefetch, delivery-edit)
 *
 * Each assertion names what breaks if it regresses:
 * - a missing column means the feature cannot be configured at all;
 * - a default of 0 would silently disable paper DR for every existing branch (NOT additive);
 * - letting a cashier through when the flag is off is the exact business rule the client asked for
 *   (branches working fully digital must not be able to post a delivery from a paper DR);
 * - blocking an admin/supervisor would take away the exception path the client explicitly kept;
 * - a helper that exists but is never called from the four endpoints enforces nothing, which is the
 *   "hidden button is not a control" failure mode.
 *
 * Runs against the live-shaped tenant 207 (`baronledger.test` → `baronledger`). The branch flag is
 * snapshotted and restored in finally, so a failed run cannot leave a branch misconfigured.
 */

ob_start();
require_once __DIR__ . '/../harness/TestHarness.php';
$h = new TestHarness('daily-ledger-paper-dr-branch', TestHarness::MODE_INTEGRATION, 'baronledger.test');
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

$h->section('schema');

$branchId = 15; // Fishport — a de-facto single-shift branch, safe to probe
app()->tenant()->setTenantId(207);
$ctx = modulePushContext('daily-ledger');
$db = $ctx->db();

// Module context forbids information_schema (ModuleDB.php:392); SHOW COLUMNS is the allowed probe.
$colStmt = $db->query("SHOW COLUMNS FROM dl_branches LIKE 'paper_dr_enabled'");
$col = $colStmt ? $colStmt->fetch(PDO::FETCH_ASSOC) : false;

$h->test(
    'dl_branches.paper_dr_enabled exists',
    is_array($col),
    'column missing — the per-branch switch cannot be configured'
);
$h->test(
    'paper_dr_enabled defaults to 1 (additive: existing branches keep paper DR)',
    is_array($col) && (string)($col['Default'] ?? '') === '1',
    is_array($col) ? 'default=' . var_export($col['Default'] ?? null, true) : 'column missing'
);

$h->section('seam');

$hasFlagHelper = function_exists('dl_branchPaperDrEnabled');
$hasGate = function_exists('dl_canUsePaperDr');
$h->test('dl_branchPaperDrEnabled() exists', $hasFlagHelper, 'contract R1 seam missing');
$h->test('dl_canUsePaperDr() exists', $hasGate, 'contract R1 seam missing');

$original = null;
if (is_array($col) && $hasFlagHelper && $hasGate) {
    $h->section('behaviour');

    $snap = $db->prepare('SELECT paper_dr_enabled FROM dl_branches WHERE id = :id');
    $snap->execute([':id' => $branchId]);
    $original = $snap->fetchColumn();

    try {
        // Default state must be "enabled".
        $db->prepare('UPDATE dl_branches SET paper_dr_enabled = 1 WHERE id = :id')->execute([':id' => $branchId]);
        $h->test(
            'flag ON: branch reports paper DR enabled',
            dl_branchPaperDrEnabled($branchId) === true,
            'a branch with the flag on must behave exactly as before'
        );
        $h->test(
            'flag ON: cashier may use paper DR',
            dl_canUsePaperDr(['role' => 'cashier', 'id' => 1], $branchId) === true,
            'default-on must not restrict cashiers'
        );

        $db->prepare('UPDATE dl_branches SET paper_dr_enabled = 0 WHERE id = :id')->execute([':id' => $branchId]);

        $h->test(
            'flag OFF: branch reports paper DR disabled',
            dl_branchPaperDrEnabled($branchId) === false,
            'the per-branch switch has no effect'
        );
        $h->test(
            'flag OFF: cashier is blocked',
            dl_canUsePaperDr(['role' => 'cashier', 'id' => 1], $branchId) === false,
            'a digital-only branch could still post a delivery from a paper DR'
        );
        $h->test(
            'flag OFF: admin bypasses',
            dl_canUsePaperDr(['role' => 'admin', 'id' => 2], $branchId) === true,
            'client decision 2 — admin keeps paper DR everywhere'
        );
        $h->test(
            'flag OFF: supervisor bypasses',
            dl_canUsePaperDr(['role' => 'supervisor', 'id' => 3], $branchId) === true,
            'client decision 2 — supervisor keeps paper DR everywhere'
        );
    } finally {
        if ($original !== false && $original !== null) {
            $db->prepare('UPDATE dl_branches SET paper_dr_enabled = :v WHERE id = :id')
                ->execute([':v' => (int)$original, ':id' => $branchId]);
        }
    }
}

$h->section('enforcement wiring');

// The four paper-DR cashier endpoints are split across two files:
//   handlers.php           -> apiCreateCashierDispatch, apiReceivePaperDelivery
//   handlers-deliveries.php -> apiGetDeliveryByDrForEdit, apiEditDeliveryByDr
// Checking one file only would make these assertions unsatisfiable.
$sources = [];
foreach (['handlers.php', 'handlers-deliveries.php'] as $rel) {
    $sources[$rel] = (string)@file_get_contents($base . '/modules/daily-ledger/' . $rel);
}

/**
 * True when $fn's body calls $needle before the next top-level function starts, in either file.
 */
$callsGuardInside = static function (string $fn, string $needle) use ($sources): bool {
    foreach ($sources as $src) {
        $start = strpos($src, 'function ' . $fn . '(');
        if ($start === false) {
            continue;
        }
        $next = strpos($src, "\nfunction ", $start + 10);
        $body = substr($src, $start, ($next === false ? strlen($src) : $next) - $start);
        if (str_contains($body, $needle)) {
            return true;
        }
    }
    return false;
};

foreach ([
    'apiCreateCashierDispatch' => 'dispatch from paper DR',
    'apiReceivePaperDelivery' => 'capture and receive from paper DR',
    'apiGetDeliveryByDrForEdit' => 'edit-by-DR prefetch',
    'apiEditDeliveryByDr' => 'edit by DR',
] as $fn => $label) {
    $h->test(
        $fn . '() is gated by dl_canUsePaperDr — ' . $label,
        $callsGuardInside($fn, 'dl_canUsePaperDr'),
        'helper exists but this endpoint ignores it — a hidden button is not a control'
    );
}

$h->done();
