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

foreach (['ehr-core', 'patient-registry', 'encounters', 'scheduling', 'orders', 'prescriptions', 'billing-bridge'] as $moduleId) {
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

function ehrBillingExecSqlFile(PDO $db, string $path): void
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

function ehrBillingDropIfExists(PDO $db, string $table): void
{
    $db->exec('DROP TABLE IF EXISTS `' . str_replace('`', '``', $table) . '`');
}

echo "=== EHR Billing Bridge Capability ===\n\n";

$db = app()->db();
$db->exec('SET FOREIGN_KEY_CHECKS=0');
foreach (['audit_logs', 'ehr_prescriptions', 'ehr_order_items', 'ehr_orders', 'ehr_appointments', 'ehr_vitals', 'ehr_encounters', 'ehr_patient_identifiers', 'ehr_patients'] as $table) {
    ehrBillingDropIfExists($db, $table);
}
$db->exec('SET FOREIGN_KEY_CHECKS=1');

register_shutdown_function(static function () use ($db): void {
    try {
        $db->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach (['audit_logs', 'ehr_prescriptions', 'ehr_order_items', 'ehr_orders', 'ehr_appointments', 'ehr_vitals', 'ehr_encounters', 'ehr_patient_identifiers', 'ehr_patients'] as $table) {
            ehrBillingDropIfExists($db, $table);
        }
        $db->exec('SET FOREIGN_KEY_CHECKS=1');
    } catch (Throwable $ignored) {
    }
});

ehrBillingExecSqlFile($db, __DIR__ . '/../database/migrations/007_kernel_runtime_tables.sql');
ehrBillingExecSqlFile($db, __DIR__ . '/../database/migrations/018_audit_logs_actor_columns_ensure.sql');
ehrBillingExecSqlFile($db, modulePathForId('patient-registry') . '/database/migrations/001_initial.sql');
ehrBillingExecSqlFile($db, modulePathForId('encounters') . '/database/migrations/001_initial.sql');
ehrBillingExecSqlFile($db, modulePathForId('scheduling') . '/database/migrations/001_initial.sql');
ehrBillingExecSqlFile($db, modulePathForId('orders') . '/database/migrations/001_initial.sql');
ehrBillingExecSqlFile($db, modulePathForId('prescriptions') . '/database/migrations/001_initial.sql');

loadModuleRoutes([]);

t('billing candidate capability registered', app()->capabilities()->has('ehr.billing.charge_candidates@1'));

$patientResult = app()->cap()->call('ehr.patient.create@1', [
    'first_name' => 'Katherine',
    'last_name' => 'Johnson',
    'birth_date' => '1985-08-26',
    'sex' => 'female',
    'identifiers' => [
        ['type' => 'mrn', 'value' => 'MRN-9001', 'is_primary' => true],
    ],
], ['caller_module' => 'patient-registry']);

$patientId = (int)($patientResult['patient']['id'] ?? 0);
t('patient create succeeds', is_array($patientResult) && !empty($patientResult['ok']) && $patientId > 0, is_array($patientResult) ? json_encode($patientResult) : 'not array');

$appointment = app()->cap()->call('ehr.appointment.schedule@1', [
    'patient_id' => $patientId,
    'appointment_type' => 'consult',
    'scheduled_start' => '2026-05-10 09:00:00',
], ['caller_module' => 'scheduling']);
$appointmentId = (int)($appointment['appointment']['id'] ?? 0);
app()->cap()->call('ehr.appointment.transition@1', ['id' => $appointmentId, 'status' => 'checked-in'], ['caller_module' => 'scheduling']);
app()->cap()->call('ehr.appointment.transition@1', ['id' => $appointmentId, 'status' => 'waiting'], ['caller_module' => 'scheduling']);
$roomed = app()->cap()->call('ehr.appointment.transition@1', ['id' => $appointmentId, 'status' => 'roomed'], ['caller_module' => 'scheduling']);
$encounterId = (int)($roomed['appointment']['encounter_id'] ?? 0);
$completed = app()->cap()->call('ehr.appointment.transition@1', ['id' => $appointmentId, 'status' => 'completed'], ['caller_module' => 'scheduling']);
t('completed consultation workflow succeeds', is_array($completed) && !empty($completed['ok']) && $encounterId > 0, is_array($completed) ? json_encode($completed) : 'not array');

$orderCreate = app()->cap()->call('ehr.order.create@1', [
    'patient_id' => $patientId,
    'encounter_id' => $encounterId,
    'order_type' => 'lab',
    'items' => [
        ['item_code' => 'CBC', 'item_label' => 'Complete Blood Count'],
        ['item_code' => 'CMP', 'item_label' => 'Comprehensive Metabolic Panel'],
    ],
], ['caller_module' => 'orders']);
t('order create succeeds', is_array($orderCreate) && !empty($orderCreate['ok']), is_array($orderCreate) ? json_encode($orderCreate) : 'not array');

$prescription = app()->cap()->call('ehr.prescription.issue@1', [
    'patient_id' => $patientId,
    'encounter_id' => $encounterId,
    'medication_text' => 'Lisinopril 10 mg tablet',
    'dose_text' => '10 mg',
    'frequency' => 'daily',
], ['caller_module' => 'prescriptions']);
t('prescription issue succeeds', is_array($prescription) && !empty($prescription['ok']), is_array($prescription) ? json_encode($prescription) : 'not array');

$candidates = app()->cap()->call('ehr.billing.charge_candidates@1', [
    'patient_id' => $patientId,
    'limit' => 20,
], ['caller_module' => 'billing-bridge']);

$rows = is_array($candidates['candidates'] ?? null) ? $candidates['candidates'] : [];
$types = array_values(array_map(static fn(array $row): string => (string)($row['candidate_type'] ?? ''), $rows));
$orderCandidate = null;
foreach ($rows as $row) {
    if (($row['candidate_type'] ?? '') === 'order') {
        $orderCandidate = $row;
        break;
    }
}

t('billing candidate generation succeeds', is_array($candidates) && !empty($candidates['ok']) && count($rows) === 3, is_array($candidates) ? json_encode($candidates) : 'not array');
t('consultation candidate present', in_array('consultation', $types, true), json_encode($rows));
t('order candidate present', in_array('order', $types, true), json_encode($rows));
t('prescription candidate present', in_array('prescription', $types, true), json_encode($rows));
t('order candidate preserves item count', (int)($orderCandidate['quantity'] ?? 0) === 2, json_encode($orderCandidate ?? []));
t('all candidates bound to patient and encounter', array_reduce($rows, static fn(bool $carry, array $row): bool => $carry && (int)($row['patient_id'] ?? 0) === $patientId && (int)($row['encounter_id'] ?? 0) === $encounterId, true), json_encode($rows));

echo "\n{$pass} passed, {$fail} failed\n";
if ($errors !== []) {
    echo "Failures:\n";
    foreach ($errors as $error) {
        echo "  - {$error}\n";
    }
}

exit($fail > 0 ? 1 : 0);