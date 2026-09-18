#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Daily Ledger — Business Overview browser-fixture settings restoration.
 *
 * Proves the browser fixture restores tenant module settings exactly: an
 * array-valued role_permissions keeps its array type, and a setting that was
 * absent before the fixture ran is removed rather than replaced with a
 * manifest default. Uses the real fixture CLI against tenant 207.
 */

require_once __DIR__ . '/../harness/TestHarness.php';

$h = new TestHarness('daily-ledger-overview-settings-restore', TestHarness::MODE_INTEGRATION, 'localhost');
$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/handlers.php';

app()->tenant()->setTenantId(207);
dlModuleSettings(true);

$fixture = __DIR__ . '/daily_ledger_overview_browser_fixture.php';
$stateFile = STORAGE_PATH . '/daily-ledger-overview-browser-state.json';
$keys = ['net_sales_deduction_percent', 'role_permissions'];

$deleteTenantSettings = static function (array $deleteKeys): void {
    if ($deleteKeys === []) {
        return;
    }
    \Ikabud\Kernel\Database\KernelPDO::kernelEscalationEnter();
    try {
        $stmt = app()->db()->prepare(
            'DELETE FROM ' . moduleTenantSettingsTable() . '
              WHERE tenant_id = ? AND module_id = ? AND setting_key = ?'
        );
        foreach ($deleteKeys as $key) {
            $stmt->execute([207, 'daily-ledger', (string)$key]);
        }
    } finally {
        \Ikabud\Kernel\Database\KernelPDO::kernelEscalationLeave();
        if (function_exists('invalidateTenantModuleSettingsCache')) {
            invalidateTenantModuleSettingsCache();
        }
    }
};

$snapshotRaw = static function () use ($keys): array {
    $raw = getModuleSettings('daily-ledger');
    $out = ['values' => [], 'absent' => []];
    foreach ($keys as $key) {
        if (array_key_exists($key, $raw)) {
            $out['values'][$key] = $raw[$key];
        } else {
            $out['absent'][] = $key;
        }
    }
    return $out;
};

$restoreRaw = static function (array $snapshot) use ($deleteTenantSettings): void {
    if (!empty($snapshot['values'])) {
        saveModuleSettings('daily-ledger', $snapshot['values']);
    }
    if (!empty($snapshot['absent'])) {
        $deleteTenantSettings($snapshot['absent']);
    }
    if (function_exists('invalidateTenantModuleSettingsCache')) {
        invalidateTenantModuleSettingsCache();
    }
    dlModuleSettings(true);
};

$runFixture = static function (string $mode) use ($fixture, $base): array {
    $output = [];
    $code = 0;
    exec('php ' . escapeshellarg($fixture) . ' ' . escapeshellarg($mode) . ' 2>&1', $output, $code);
    return [$code, implode("\n", $output)];
};

// The pre-test tenant state is restored in teardown no matter how the
// assertions below resolve.
$trueOriginal = $snapshotRaw();
$h->detail('true original settings: ' . json_encode($trueOriginal));

$h->section('Fixture settings restoration');

// Controlled baseline: an array-valued role_permissions plus an absent
// net_sales_deduction_percent. Removing the net key proves the fixture does
// not leave a default behind.
@unlink($stateFile);
saveModuleSettings('daily-ledger', [
    'role_permissions' => ['viewer' => ['pos.report'], 'admin' => ['ledger.override']],
]);
$deleteTenantSettings(['net_sales_deduction_percent']);
dlModuleSettings(true);
$baseline = $snapshotRaw();

$h->test('baseline role_permissions is array-valued', is_array($baseline['values']['role_permissions'] ?? null));
$h->test('baseline omits net_sales_deduction_percent', in_array('net_sales_deduction_percent', $baseline['absent'], true));

[$setupCode, $setupOutput] = $runFixture('setup');
$h->test('fixture setup exits 0', $setupCode === 0, trim($setupOutput));
if (function_exists('invalidateTenantModuleSettingsCache')) {
    invalidateTenantModuleSettingsCache();
}
dlModuleSettings(true);
$afterSetup = $snapshotRaw();
$h->test('fixture setup persists the net-sales test value', ($afterSetup['values']['net_sales_deduction_percent'] ?? null) === '10');

[$cleanupCode, $cleanupOutput] = $runFixture('cleanup');
$h->test('fixture cleanup exits 0', $cleanupCode === 0, trim($cleanupOutput));
if (function_exists('invalidateTenantModuleSettingsCache')) {
    invalidateTenantModuleSettingsCache();
}
dlModuleSettings(true);
$afterCleanup = $snapshotRaw();

$h->test('fixture cleanup restores role_permissions as an array', is_array($afterCleanup['values']['role_permissions'] ?? null));
$h->test('fixture cleanup removes the absent net-sales setting', in_array('net_sales_deduction_percent', $afterCleanup['absent'], true));
$h->test('fixture cleanup restores settings exactly (types + key absence)', $afterCleanup === $baseline, json_encode($afterCleanup));

$restoreRaw($trueOriginal);
$final = $snapshotRaw();
$h->test('test teardown restores the true original tenant settings', $final === $trueOriginal);

$h->done();
