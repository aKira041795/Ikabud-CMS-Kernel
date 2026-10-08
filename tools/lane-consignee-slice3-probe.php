<?php
/**
 * Slice 3 acceptance probe (DRIVER) — consignee administration.
 *
 * The owner, 2026-10-08: *"what i'd like at the add consignee/edit added: 1. Area 2. Address
 * 3. Price group"*.
 *
 * A GATE: it must FAIL on the unchanged tree, where `dl_consignees` has only
 * id/code/name/assigned_commissary_id/is_active/created_at/updated_at and `dl_consignee_products`
 * does not exist.
 *
 * Asserts OUTCOMES, not mechanisms: the columns exist, and a real save through the real handler
 * persists what the operator typed.
 */
declare(strict_types=1);

$basePath = '/var/www/html/applicationostest';
require_once $basePath . '/src/helpers/cli-bootstrap.php';
$app = kernelCliBootstrap($basePath);
$app->tenant()->setTenantId(207);
require_once $basePath . '/src/helpers/module-manager.php';
require_once $basePath . '/modules/daily-ledger/helpers.php';
require_once $basePath . '/modules/daily-ledger/handlers-deliveries.php';
require_once $basePath . '/modules/daily-ledger/handlers.php';
modulePushContext('daily-ledger');
$db = app()->dbForTenant(207);

$results = [];
function probe(string $label, bool $ok, string $detail = ''): void {
    global $results;
    $results[] = [$label, $ok];
    printf("  %s %s%s\n", $ok ? 'PASS' : 'FAIL', $label, $detail !== '' ? "  ($detail)" : '');
}
function columnExists($db, string $table, string $col): bool {
    try {
        $s = $db->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $s->execute([$table, $col]);
        return (int)$s->fetchColumn() > 0;
    } catch (\Throwable $e) { return false; }
}

echo "== slice 3 acceptance gate (consignee administration) ==\n";

// A. the three new consignee columns exist
$cols = ['area' => columnExists($db, 'dl_consignees', 'area'),
         'address' => columnExists($db, 'dl_consignees', 'address'),
         'price_group_id' => columnExists($db, 'dl_consignees', 'price_group_id')];
probe('A dl_consignees has area, address and price_group_id',
      $cols['area'] && $cols['address'] && $cols['price_group_id'],
      implode(' ', array_map(static fn($k, $v) => $k . '=' . (int)$v, array_keys($cols), $cols)));

// B. the consignee-product assignment table exists
$hasProducts = false;
try {
    $db->query('SELECT 1 FROM dl_consignee_products LIMIT 1');
    $hasProducts = true;
} catch (\Throwable $e) { $hasProducts = false; }
probe('B dl_consignee_products exists (Show in Consignees is backed)', $hasProducts);

// C. a real save through the real handler persists what the operator typed
$TAG = 'S3GATE';
$CODE = $TAG . '-CONS-1';
$CODE2 = $TAG . '-CONS-2';
foreach ([$CODE, $CODE2] as $c) {
    $id = (int)$db->query('SELECT id FROM dl_consignees WHERE code = ' . $db->quote($c))->fetchColumn();
    if ($id > 0) { $db->exec("DELETE FROM dl_consignees WHERE id = $id"); }
}

/** Find a non-default price group so "it saved" cannot be confused with "it defaulted". */
$pgId = 0;
try {
    $pgId = (int)$db->query('SELECT id FROM dl_price_groups WHERE is_default = 0 ORDER BY id LIMIT 1')->fetchColumn();
} catch (\Throwable $e) { $pgId = 0; }
if ($pgId <= 0) {
    $pgId = (int)$db->query('SELECT id FROM dl_price_groups ORDER BY id DESC LIMIT 1')->fetchColumn();
}

$payload = ['consignee_id' => 0, 'code' => $CODE, 'name' => 'S3 Gate Consignee',
            'assigned_commissary_id' => 18, 'is_active' => 1,
            'area' => 'Dipolog', 'address' => '456 Gate Street', 'price_group_id' => $pgId];
$tmp = tempnam(sys_get_temp_dir(), 's3gate');
file_put_contents($tmp, json_encode($payload, JSON_THROW_ON_ERROR));
$out = (string)shell_exec(sprintf('php %s %s 2>/dev/null', escapeshellarg($basePath . '/tools/lane-consignee-slice3-child.php'), escapeshellarg($tmp)));
@unlink($tmp);
$status = preg_match('/__HTTP_STATUS__=(\d+)/', $out, $m) ? (int)$m[1] : 0;
$body = trim((string)preg_replace('/\n?__HTTP_STATUS__=\d+\s*$/', '', $out));
$json = json_decode($body, true);

$row = null;
try {
    $s = $db->prepare('SELECT area, address, price_group_id FROM dl_consignees WHERE code = ?');
    $s->execute([$CODE]);
    $row = $s->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (\Throwable $e) { $row = null; }

$saved = $row !== null
    && (string)($row['area'] ?? '') === 'Dipolog'
    && (string)($row['address'] ?? '') === '456 Gate Street'
    && (int)($row['price_group_id'] ?? 0) === $pgId;
probe('C saving a consignee persists area, address and price group',
      ($json['ok'] ?? false) === true && $saved,
      'http=' . $status . ' err=' . (string)($json['error'] ?? '-') . ' row=' . json_encode($row));

// D. a null price group is still allowed (the live Lee Plaza row must stay valid)
$tmp2 = tempnam(sys_get_temp_dir(), 's3gate');
file_put_contents($tmp2, json_encode(['consignee_id' => 0, 'code' => $CODE2, 'name' => 'S3 Gate No Price',
    'assigned_commissary_id' => 18, 'is_active' => 1, 'area' => '', 'address' => ''], JSON_THROW_ON_ERROR));
$out2 = (string)shell_exec(sprintf('php %s %s 2>/dev/null', escapeshellarg($basePath . '/tools/lane-consignee-slice3-child.php'), escapeshellarg($tmp2)));
@unlink($tmp2);
$json2 = json_decode(trim((string)preg_replace('/\n?__HTTP_STATUS__=\d+\s*$/', '', $out2)), true);
probe('D pin: price group stays optional (existing rows remain valid)', ($json2['ok'] ?? false) === true,
      'err=' . (string)($json2['error'] ?? '-'));

// cleanup
foreach ([$CODE, $CODE2] as $c) {
    $id = (int)$db->query('SELECT id FROM dl_consignees WHERE code = ' . $db->quote($c))->fetchColumn();
    if ($id > 0) { $db->exec("DELETE FROM dl_consignees WHERE id = $id"); }
}
$leaked = (int)$db->query("SELECT COUNT(*) FROM dl_consignees WHERE code LIKE '" . $TAG . "%'")->fetchColumn();
probe('E pin: fixture cleaned up', $leaked === 0, "leaked=$leaked");

$failed = array_values(array_filter($results, static fn(array $r): bool => !$r[1]));
if ($failed === []) {
    echo "PASS: a consignee carries area, address and price group, and Show in Consignees is backed\n";
    exit(0);
}
echo 'FAILED: ' . implode(' | ', array_map(static fn(array $r): string => $r[0], $failed)) . "\n";
exit(1);
