<?php
declare(strict_types=1);

$registryPath = __DIR__ . '/../storage/modules.json';
$originalRegistry = is_file($registryPath) ? (string)file_get_contents($registryPath) : null;
$registry = [];
if ($originalRegistry !== null && $originalRegistry !== '') {
    $decoded = json_decode($originalRegistry, true);
    if (is_array($decoded)) {
        $registry = $decoded;
    }
}

foreach (['ehr-core', 'patient-registry', 'encounters', 'orders', 'results'] as $moduleId) {
    $entry = $registry[$moduleId] ?? [];
    if (!is_array($entry)) {
        $entry = [];
    }
    $entry['enabled'] = true;
    $registry[$moduleId] = $entry;
}

$dir = dirname($registryPath);
if (!is_dir($dir)) {
    @mkdir($dir, 0775, true);
}
file_put_contents($registryPath, json_encode($registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);

register_shutdown_function(static function () use ($registryPath, $originalRegistry): void {
    if ($originalRegistry === null) {
        @unlink($registryPath);
        return;
    }
    file_put_contents($registryPath, $originalRegistry, LOCK_EX);
});

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';

$pass = 0;
$fail = 0;
$errors = [];

function t(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $errors;
    if ($ok) {
        $pass++;
        echo "  ✓ {$label}\n";
        return;
    }

    $fail++;
    $errors[] = $label . ($detail !== '' ? ': ' . $detail : '');
    echo "  ✗ {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

function ehrOrderExecSqlFile(PDO $db, string $path): void
{
    $sql = trim((string)file_get_contents($path));
    if ($sql === '') {
        return;
    }
    $statements = preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: [];
    foreach ($statements as $statement) {
        $statement = trim($statement);
        if ($statement === '') {
            continue;
        }
        $db->exec($statement);
    }
}

function ehrOrderDropIfExists(PDO $db, string $table): void
{
    $db->exec('DROP TABLE IF EXISTS `' . str_replace('`', '``', $table) . '`');
}

echo "=== EHR Orders And Results Capability ===\n\n";

$db = app()->db();
$db->exec('SET FOREIGN_KEY_CHECKS=0');
foreach (['ehr_lab_results', 'ehr_order_items', 'ehr_orders', 'ehr_vitals', 'ehr_encounters', 'ehr_patient_identifiers', 'ehr_patients'] as $table) {
    ehrOrderDropIfExists($db, $table);
}
$db->exec('SET FOREIGN_KEY_CHECKS=1');

register_shutdown_function(static function () use ($db): void {
    try {
        $db->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach (['ehr_lab_results', 'ehr_order_items', 'ehr_orders', 'ehr_vitals', 'ehr_encounters', 'ehr_patient_identifiers', 'ehr_patients'] as $table) {
            ehrOrderDropIfExists($db, $table);
        }
        $db->exec('SET FOREIGN_KEY_CHECKS=1');
    } catch (Throwable $ignored) {
    }
});

ehrOrderExecSqlFile($db, __DIR__ . '/../database/migrations/007_kernel_runtime_tables.sql');
ehrOrderExecSqlFile($db, __DIR__ . '/../database/migrations/018_audit_logs_actor_columns_ensure.sql');
ehrOrderExecSqlFile($db, modulePathForId('patient-registry') . '/database/migrations/001_initial.sql');
ehrOrderExecSqlFile($db, modulePathForId('encounters') . '/database/migrations/001_initial.sql');
ehrOrderExecSqlFile($db, modulePathForId('orders') . '/database/migrations/001_initial.sql');
ehrOrderExecSqlFile($db, modulePathForId('results') . '/database/migrations/001_initial.sql');
ehrOrderExecSqlFile($db, modulePathForId('results') . '/database/migrations/002_add_restricted_flag.sql');

loadModuleRoutes([]);

t('order create capability registered', app()->capabilities()->has('ehr.order.create@1'));
t('result enter capability registered', app()->capabilities()->has('ehr.result.enter@1'));
t('result verify capability registered', app()->capabilities()->has('ehr.result.verify@1'));
t('result release capability registered', app()->capabilities()->has('ehr.result.release@1'));

$patientResult = app()->cap()->call('ehr.patient.create@1', [
    'first_name' => 'Grace',
    'last_name' => 'Hopper',
    'birth_date' => '1980-12-09',
    'sex' => 'female',
    'identifiers' => [
        ['type' => 'mrn', 'value' => 'MRN-2001', 'is_primary' => true],
    ],
], ['caller_module' => 'patient-registry']);

$patientId = (int)($patientResult['patient']['id'] ?? 0);
t('patient create succeeds', is_array($patientResult) && !empty($patientResult['ok']) && $patientId > 0, is_array($patientResult) ? json_encode($patientResult) : 'not array');

$encounterResult = app()->cap()->call('ehr.encounter.create@1', [
    'patient_id' => $patientId,
    'encounter_type' => 'outpatient',
    'reason_for_visit' => 'Diagnostic workup',
], ['caller_module' => 'scheduling']);

$encounterId = (int)($encounterResult['encounter']['id'] ?? 0);
t('encounter create succeeds', is_array($encounterResult) && !empty($encounterResult['ok']) && $encounterId > 0, is_array($encounterResult) ? json_encode($encounterResult) : 'not array');

$orderCreate = app()->cap()->call('ehr.order.create@1', [
    'patient_id' => $patientId,
    'encounter_id' => $encounterId,
    'order_type' => 'lab',
    'priority' => 'routine',
    'clinical_question' => 'Check fasting blood sugar',
    'items' => [
        [
            'item_code' => 'GLU-FAST',
            'code_system' => 'local',
            'item_label' => 'Fasting Blood Sugar',
            'specimen_type' => 'blood'
        ]
    ]
], ['caller_module' => 'orders']);

t('order create succeeds', is_array($orderCreate) && !empty($orderCreate['ok']), is_array($orderCreate) ? json_encode($orderCreate) : 'not array');
$orderId = (int)($orderCreate['order']['id'] ?? 0);
$orderItemId = (int)($orderCreate['order']['items'][0]['id'] ?? 0);
t('order has one item', count($orderCreate['order']['items'] ?? []) === 1);

$resultEnter = app()->cap()->call('ehr.result.enter@1', [
    'order_id' => $orderId,
    'order_item_id' => $orderItemId,
    'value_numeric' => 92.5,
    'unit' => 'mg/dL',
    'reference_range_text' => '70-100 mg/dL',
    'entered_by_user_id' => 701,
], ['caller_module' => 'results']);

t('result enter succeeds', is_array($resultEnter) && !empty($resultEnter['ok']), is_array($resultEnter) ? json_encode($resultEnter) : 'not array');
$resultId = (int)($resultEnter['result']['id'] ?? 0);
t('result starts entered', (string)($resultEnter['result']['result_status'] ?? '') === 'entered');

$resultVerify = app()->cap()->call('ehr.result.verify@1', [
    'result_id' => $resultId,
    'verified_by_user_id' => 702,
], ['caller_module' => 'results']);

t('result verify succeeds', is_array($resultVerify) && !empty($resultVerify['ok']), is_array($resultVerify) ? json_encode($resultVerify) : 'not array');
t('result becomes verified', (string)($resultVerify['result']['result_status'] ?? '') === 'verified');
t('verified timestamp set', !empty($resultVerify['result']['verified_at'] ?? null));

$resultRelease = app()->cap()->call('ehr.result.release@1', [
    'result_id' => $resultId,
], ['caller_module' => 'results']);

t('result release succeeds', is_array($resultRelease) && !empty($resultRelease['ok']), is_array($resultRelease) ? json_encode($resultRelease) : 'not array');
t('result becomes released', (string)($resultRelease['result']['result_status'] ?? '') === 'released');
t('released timestamp set', !empty($resultRelease['result']['released_at'] ?? null));

echo "\n{$pass} passed, {$fail} failed\n";
if ($errors !== []) {
    echo "Failures:\n";
    foreach ($errors as $error) {
        echo "  - {$error}\n";
    }
}

exit($fail > 0 ? 1 : 0);