<?php
/**
 * Consignee feature-toggle acceptance probe (DRIVER).
 *
 * Owner, 2026-10-08: "and let's make a feature we can turn on/off, just like POS at admin settings".
 *
 * A GATE: it must FAIL on the unchanged tree, where no such setting exists.
 *
 * The rule this slice lives or dies by: **"off" disables the CAPABILITY, it never hides HISTORY.**
 * Tenant 207 already holds real consignee records, so a disable that blanks them would be a
 * data-integrity failure wearing a settings costume. Case E is the pin for that.
 *
 * The gate RESTORES the setting it toggles and asserts that it did — it runs against a live tenant.
 */
declare(strict_types=1);

$basePath = '/var/www/html/applicationostest';
// Tenant-scoped module settings (tenant_module_settings) are only used when an HTTP host is present
// (moduleTenantSettingsModeEnabled() is false in bare CLI). Without this, the parent persisted
// consignee_enabled to the GLOBAL registry while the child dispatch process read the TENANT store, so
// the child never saw the disable and case D false-passed on an unrelated "Reference only" refusal.
$_SERVER['HTTP_HOST'] = $_SERVER['SERVER_NAME'] = 'localhost';
require_once $basePath . '/src/helpers/cli-bootstrap.php';

$results = [];
function probe(string $label, bool $ok, string $detail = ''): void {
    global $results;
    $results[] = [$label, $ok];
    printf("  %s %s%s\n", $ok ? 'PASS' : 'FAIL', $label, $detail !== '' ? "  ($detail)" : '');
}

$app = kernelCliBootstrap($basePath);
$app->tenant()->setTenantId(207);
require_once $basePath . '/src/helpers/module-manager.php';
require_once $basePath . '/modules/daily-ledger/helpers.php';
require_once $basePath . '/modules/daily-ledger/handlers-deliveries.php';
require_once $basePath . '/modules/daily-ledger/handlers.php';
modulePushContext('daily-ledger');
$db = app()->dbForTenant(207);

echo "== consignee feature-toggle acceptance gate ==\n";

$KEY = 'consignee_enabled';
$TAG = 'S6GATE';
$CODE = $TAG . '-CONS-1';
$DR = $TAG . '-DR-1';

/** Everything a disable must not disturb. */
function historyHash($db): string {
    $d = $db->query('SELECT id, destination_type, consignee_id, dr_number, status, provenance_status FROM dl_deliveries ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $l = $db->query('SELECT consignee_id, product_id, ledger_date, shift, addtl, withdraw FROM dl_consignee_ledger ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $c = $db->query('SELECT id, code, name, is_active FROM dl_consignees ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    return hash('sha256', json_encode([$d, $l, $c]));
}

/** Attempt a real consignee dispatch; returns [httpStatus, decodedBody]. */
function tryConsigneeDispatch(string $basePath, int $consigneeId, string $dr, int $product, string $date): array {
    $payload = [
        'branch_id' => 8, 'shift' => 'AM', 'delivery_date' => $date, 'receiving_shift' => 'AM',
        'dr_number' => $dr, 'destination_type' => 'consignee', 'destination_id' => null,
        'consignee_id' => $consigneeId,
        'items' => [['product_id' => $product, 'quantity' => 1, 'unit' => 'pcs']],
    ];
    $tmp = tempnam(sys_get_temp_dir(), 's6gate');
    file_put_contents($tmp, json_encode($payload, JSON_THROW_ON_ERROR));
    $out = (string)shell_exec(sprintf('php %s %s 2>/dev/null', escapeshellarg($basePath . '/tools/lane-consignee-toggle-child.php'), escapeshellarg($tmp)));
    @unlink($tmp);
    if (!preg_match('/__HTTP_STATUS__=(\d+)/', $out, $m)) { return [0, null]; }  // fail closed
    $body = trim((string)preg_replace('/\n?__HTTP_STATUS__=\d+\s*$/', '', $out));
    return [(int)$m[1], json_decode($body, true)];
}

$original = dlModuleSettings()[$KEY] ?? null;

// ---- fixture: one consignee, so a dispatch has somewhere to go
$db->exec('DELETE FROM dl_consignees WHERE code = ' . $db->quote($CODE));
$db->prepare('INSERT INTO dl_consignees (code, name, assigned_commissary_id, is_active) VALUES (?,?,?,1)')
   ->execute([$CODE, 'S6 Gate Consignee', 18]);
$consigneeId = (int)$db->lastInsertId();

// Baseline is taken AFTER the fixture exists. Taking it before meant the pin measured the gate's OWN
// fixture insert and reported CHANGED no matter what — a permanent false red (measured 2026-10-08).
$historyBefore = historyHash($db);

$product = (int)$db->query('SELECT product_id FROM dl_daily_ledger WHERE branch_id = 8 AND ledger_date = "2026-10-07" AND (beg_bal + addtl - withdraw) > 20 ORDER BY (beg_bal + addtl - withdraw) DESC LIMIT 1')->fetchColumn();

// A. exists and defaults to ENABLED (a live capability must not default to off)
$defaults = dlSettingsDefaults();
$hasKey = array_key_exists($KEY, $defaults);
probe('A the setting exists and defaults to ENABLED', $hasKey && (bool)$defaults[$KEY] === true,
      'present=' . (int)$hasKey . ' default=' . var_export($defaults[$KEY] ?? null, true));

// B. round-trips through the real persistence path
$rt = false;
if ($hasKey) {
    $rt = dlPersistModuleSettings([$KEY => false]) && (dlModuleSettings(true)[$KEY] ?? true) === false;
}
probe('B the setting round-trips through dlPersistModuleSettings', $rt);

// C. an unrecognised value must coerce to the DEFAULT (enabled), never silently disable a live feature
$coerced = 'n/a';
if ($hasKey) {
    dlPersistModuleSettings([$KEY => 'definitely-not-a-boolean']);
    $coerced = dlModuleSettings(true)[$KEY] ?? null;
}
probe('C a garbage value coerces to the default (enabled), never to disabled',
      $hasKey && $coerced !== false && $coerced !== 'definitely-not-a-boolean',
      'readback=' . var_export($coerced, true));

// D. DISABLED must actually refuse a new consignee dispatch (a hidden button is not a guard)
if ($hasKey && $product > 0) {
    dlPersistModuleSettings([$KEY => false]);
    dlModuleSettings(true);
    [$http, $json] = tryConsigneeDispatch($basePath, $consigneeId, $DR, $product, '2026-10-07');
    $error = (string)($json['error'] ?? '');
    $refused = ($json['ok'] ?? null) === false
        && stripos($error, 'consignee') !== false
        && stripos($error, 'disabled') !== false;
    probe('D with the feature DISABLED the dispatch API refuses a consignee destination and names the feature', $refused,
          'http=' . $http . ' err=' . ($error !== '' ? $error : '-'));
} else {
    probe('D with the feature DISABLED the dispatch API refuses a consignee destination', false,
          'cannot attempt: ' . ($hasKey ? 'no stocked product at branch 8 on 2026-10-07' : 'no setting'));
}

// E. PIN - disabling must not disturb one byte of recorded history
$historyAfterDisable = historyHash($db);
probe('E pin: disabling the feature leaves all recorded consignee history untouched',
      $historyAfterDisable === $historyBefore,
      $historyAfterDisable === $historyBefore ? 'identical' : 'CHANGED');

// ---- restore + cleanup, and prove the restore
if ($hasKey && $original !== null) { dlPersistModuleSettings([$KEY => (bool)$original]); }
$restored = $hasKey ? (dlModuleSettings(true)[$KEY] ?? null) : $original;
probe('F pin: the gate restored the previous setting', $hasKey ? ($restored === $original) : true,
      'now=' . var_export($restored, true) . ' was=' . var_export($original, true));

$db->exec('DELETE FROM dl_consignees WHERE code = ' . $db->quote($CODE));
$leaked = (int)$db->query("SELECT COUNT(*) FROM dl_consignees WHERE code LIKE '" . $TAG . "%'")->fetchColumn();
probe('G pin: fixture cleaned up', $leaked === 0, "leaked=$leaked");

$failed = array_values(array_filter($results, static fn(array $r): bool => !$r[1]));
if ($failed === []) {
    echo "PASS: the consignee feature can be switched off, and switching it off destroys nothing\n";
    exit(0);
}
echo 'FAILED: ' . implode(' | ', array_map(static fn(array $r): string => $r[0], $failed)) . "\n";
exit(1);
