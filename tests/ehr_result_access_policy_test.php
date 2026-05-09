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

foreach (['ehr-core', 'patient-registry', 'encounters', 'orders', 'results', 'privacy-consent'] as $moduleId) {
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

function ehrResultAccessExecSqlFile(PDO $db, string $path): void
{
    $sql = trim((string)file_get_contents($path));
    if ($sql === '') {
        return;
    }
    $sql = preg_replace('/^--.*$/m', '', $sql) ?? $sql;
    $statements = preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: [];
    foreach ($statements as $statement) {
        $statement = trim($statement);
        if ($statement === '') {
            continue;
        }
        $db->exec($statement);
    }
}

function ehrResultAccessDropIfExists(PDO $db, string $table): void
{
    $db->exec('DROP TABLE IF EXISTS `' . str_replace('`', '``', $table) . '`');
}

echo "=== EHR Result Access Policy ===\n\n";

$db = app()->db();
$db->exec('SET FOREIGN_KEY_CHECKS=0');
foreach (['audit_logs', 'ehr_break_glass_events', 'ehr_consents', 'ehr_lab_results', 'ehr_order_items', 'ehr_orders', 'ehr_vitals', 'ehr_encounters', 'ehr_patient_identifiers', 'ehr_patients'] as $table) {
    ehrResultAccessDropIfExists($db, $table);
}
$db->exec('SET FOREIGN_KEY_CHECKS=1');

register_shutdown_function(static function () use ($db): void {
    try {
        $db->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach (['audit_logs', 'ehr_break_glass_events', 'ehr_consents', 'ehr_lab_results', 'ehr_order_items', 'ehr_orders', 'ehr_vitals', 'ehr_encounters', 'ehr_patient_identifiers', 'ehr_patients'] as $table) {
            ehrResultAccessDropIfExists($db, $table);
        }
        $db->exec('SET FOREIGN_KEY_CHECKS=1');
    } catch (Throwable $ignored) {
    }
});

ehrResultAccessExecSqlFile($db, __DIR__ . '/../database/migrations/007_kernel_runtime_tables.sql');
ehrResultAccessExecSqlFile($db, __DIR__ . '/../database/migrations/018_audit_logs_actor_columns_ensure.sql');
ehrResultAccessExecSqlFile($db, modulePathForId('patient-registry') . '/database/migrations/001_initial.sql');
ehrResultAccessExecSqlFile($db, modulePathForId('encounters') . '/database/migrations/001_initial.sql');
ehrResultAccessExecSqlFile($db, modulePathForId('orders') . '/database/migrations/001_initial.sql');
ehrResultAccessExecSqlFile($db, modulePathForId('results') . '/database/migrations/001_initial.sql');
ehrResultAccessExecSqlFile($db, modulePathForId('results') . '/database/migrations/002_add_restricted_flag.sql');
ehrResultAccessExecSqlFile($db, modulePathForId('privacy-consent') . '/database/migrations/001_initial.sql');

loadModuleRoutes([]);

function createRestrictedResultForPatient(string $mrn, string $firstName, string $lastName): array
{
    $patientResult = app()->cap()->call('ehr.patient.create@1', [
        'first_name' => $firstName,
        'last_name' => $lastName,
        'birth_date' => '1987-03-09',
        'sex' => 'female',
        'identifiers' => [
            ['type' => 'mrn', 'value' => $mrn, 'is_primary' => true],
        ],
    ], ['caller_module' => 'patient-registry']);
    $patientId = (int)($patientResult['patient']['id'] ?? 0);

    $encounterResult = app()->cap()->call('ehr.encounter.create@1', [
        'patient_id' => $patientId,
        'encounter_type' => 'outpatient',
        'reason_for_visit' => 'Restricted result review',
    ], ['caller_module' => 'encounters']);
    $encounterId = (int)($encounterResult['encounter']['id'] ?? 0);

    $orderCreate = app()->cap()->call('ehr.order.create@1', [
        'patient_id' => $patientId,
        'encounter_id' => $encounterId,
        'order_type' => 'lab',
        'items' => [
            [
                'item_code' => 'CBC',
                'code_system' => 'local',
                'item_label' => 'CBC',
            ],
        ],
    ], ['caller_module' => 'orders']);
    $orderId = (int)($orderCreate['order']['id'] ?? 0);
    $orderItemId = (int)($orderCreate['order']['items'][0]['id'] ?? 0);

    $resultEnter = app()->cap()->call('ehr.result.enter@1', [
        'order_id' => $orderId,
        'order_item_id' => $orderItemId,
        'value_numeric' => 4.3,
        'unit' => 'x10^9/L',
        'restricted_flag' => true,
        'entered_by_user_id' => 1801,
    ], ['caller_module' => 'results']);

    return [
        'patient' => $patientResult,
        'encounter' => $encounterResult,
        'order' => $orderCreate,
        'result' => $resultEnter,
        'patient_id' => $patientId,
        'result_id' => (int)($resultEnter['result']['id'] ?? 0),
    ];
}

$consentCase = createRestrictedResultForPatient('MRN-9401', 'Mae', 'Jemison');
t('restricted result create succeeds', !empty($consentCase['result']['ok']) && $consentCase['result_id'] > 0, json_encode($consentCase['result']));

$deniedConsent = app()->cap()->call('ehr.result.view@1', ['id' => $consentCase['result_id']], ['caller_module' => 'results']);
t('restricted result denied without override', is_array($deniedConsent) && empty($deniedConsent['ok']) && ($deniedConsent['reason'] ?? '') === 'restricted_result', is_array($deniedConsent) ? json_encode($deniedConsent) : 'not array');

$consentRecord = app()->cap()->call('ehr.consent.record@1', [
    'patient_id' => $consentCase['patient_id'],
    'consent_type' => 'restricted-record-access',
    'status' => 'granted',
], ['caller_module' => 'privacy-consent']);
t('result consent recorded', is_array($consentRecord) && !empty($consentRecord['ok']), is_array($consentRecord) ? json_encode($consentRecord) : 'not array');

$allowedConsent = app()->cap()->call('ehr.result.view@1', ['id' => $consentCase['result_id']], ['caller_module' => 'results']);
t('restricted result allowed with consent', is_array($allowedConsent) && !empty($allowedConsent['ok']), is_array($allowedConsent) ? json_encode($allowedConsent) : 'not array');

$breakGlassCase = createRestrictedResultForPatient('MRN-9402', 'Rebecca', 'Lee');
t('second restricted result create succeeds', !empty($breakGlassCase['result']['ok']) && $breakGlassCase['result_id'] > 0, json_encode($breakGlassCase['result']));

$deniedBreakGlass = app()->cap()->call('ehr.result.view@1', ['id' => $breakGlassCase['result_id']], ['caller_module' => 'results']);
t('second restricted result denied without override', is_array($deniedBreakGlass) && empty($deniedBreakGlass['ok']) && ($deniedBreakGlass['reason'] ?? '') === 'restricted_result', is_array($deniedBreakGlass) ? json_encode($deniedBreakGlass) : 'not array');

$breakGlass = app()->cap()->call('ehr.break_glass.request@1', [
    'patient_id' => $breakGlassCase['patient_id'],
    'object_type' => 'result',
    'object_id' => (string)$breakGlassCase['result_id'],
    'reason' => 'Critical after-hours review',
], ['caller_module' => 'privacy-consent']);
t('result break-glass request succeeds', is_array($breakGlass) && !empty($breakGlass['ok']), is_array($breakGlass) ? json_encode($breakGlass) : 'not array');

$allowedBreakGlass = app()->cap()->call('ehr.result.view@1', ['id' => $breakGlassCase['result_id']], ['caller_module' => 'results']);
t('restricted result allowed with break-glass', is_array($allowedBreakGlass) && !empty($allowedBreakGlass['ok']), is_array($allowedBreakGlass) ? json_encode($allowedBreakGlass) : 'not array');

$auditStmt = $db->prepare('SELECT action, COUNT(*) AS aggregate_count FROM audit_logs WHERE entity_type = :entity_type GROUP BY action');
$auditStmt->execute([':entity_type' => 'ehr_lab_result']);
$auditActions = $auditStmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
t('result denials audited', (int)($auditActions['ehr.result.access_denied'] ?? 0) === 2, json_encode($auditActions));
t('result views audited', (int)($auditActions['ehr.result.viewed'] ?? 0) === 2, json_encode($auditActions));

echo "\n{$pass} passed, {$fail} failed\n";
if ($errors !== []) {
    echo "Failures:\n";
    foreach ($errors as $error) {
        echo "  - {$error}\n";
    }
}

exit($fail > 0 ? 1 : 0);