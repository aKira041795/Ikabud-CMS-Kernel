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

foreach (['ehr-core', 'patient-registry', 'encounters', 'prescriptions'] as $moduleId) {
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

function ehrRxExecSqlFile(PDO $db, string $path): void
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

function ehrRxDropIfExists(PDO $db, string $table): void
{
    $db->exec('DROP TABLE IF EXISTS `' . str_replace('`', '``', $table) . '`');
}

echo "=== EHR Prescriptions Capability ===\n\n";

$db = app()->db();
$db->exec('SET FOREIGN_KEY_CHECKS=0');
foreach (['ehr_prescriptions', 'ehr_vitals', 'ehr_encounters', 'ehr_patient_identifiers', 'ehr_patients'] as $table) {
    ehrRxDropIfExists($db, $table);
}
$db->exec('SET FOREIGN_KEY_CHECKS=1');

register_shutdown_function(static function () use ($db): void {
    try {
        $db->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach (['ehr_prescriptions', 'ehr_vitals', 'ehr_encounters', 'ehr_patient_identifiers', 'ehr_patients'] as $table) {
            ehrRxDropIfExists($db, $table);
        }
        $db->exec('SET FOREIGN_KEY_CHECKS=1');
    } catch (Throwable $ignored) {
    }
});

ehrRxExecSqlFile($db, modulePathForId('patient-registry') . '/database/migrations/001_initial.sql');
ehrRxExecSqlFile($db, modulePathForId('encounters') . '/database/migrations/001_initial.sql');
ehrRxExecSqlFile($db, modulePathForId('prescriptions') . '/database/migrations/001_initial.sql');

loadModuleRoutes([]);

t('prescription issue capability registered', app()->capabilities()->has('ehr.prescription.issue@1'));
t('prescription view capability registered', app()->capabilities()->has('ehr.prescription.view@1'));
t('prescription cancel capability registered', app()->capabilities()->has('ehr.prescription.cancel@1'));

$patientResult = app()->cap()->call('ehr.patient.create@1', [
    'first_name' => 'Rosalind',
    'last_name' => 'Franklin',
    'birth_date' => '1988-07-25',
    'sex' => 'female',
    'identifiers' => [
        ['type' => 'mrn', 'value' => 'MRN-3001', 'is_primary' => true],
    ],
], ['caller_module' => 'patient-registry']);

$patientId = (int)($patientResult['patient']['id'] ?? 0);
t('patient create succeeds', is_array($patientResult) && !empty($patientResult['ok']) && $patientId > 0, is_array($patientResult) ? json_encode($patientResult) : 'not array');

$encounterResult = app()->cap()->call('ehr.encounter.create@1', [
    'patient_id' => $patientId,
    'encounter_type' => 'outpatient',
    'reason_for_visit' => 'Acute respiratory infection',
], ['caller_module' => 'scheduling']);

$encounterId = (int)($encounterResult['encounter']['id'] ?? 0);
t('encounter create succeeds', is_array($encounterResult) && !empty($encounterResult['ok']) && $encounterId > 0, is_array($encounterResult) ? json_encode($encounterResult) : 'not array');

$issueResult = app()->cap()->call('ehr.prescription.issue@1', [
    'patient_id' => $patientId,
    'encounter_id' => $encounterId,
    'medication_text' => 'Amoxicillin 500 mg capsule',
    'dose_text' => '500 mg',
    'route' => 'oral',
    'frequency' => 'three times daily',
    'duration_text' => '7 days',
    'quantity' => '21 capsules',
    'refills' => 0,
    'indication' => 'Respiratory infection',
    'prescriber_user_id' => 801,
], ['caller_module' => 'prescriptions']);

t('prescription issue succeeds', is_array($issueResult) && !empty($issueResult['ok']), is_array($issueResult) ? json_encode($issueResult) : 'not array');
$prescriptionId = (int)($issueResult['prescription']['id'] ?? 0);
t('prescription starts issued', (string)($issueResult['prescription']['status'] ?? '') === 'issued');
t('issued timestamp set', !empty($issueResult['prescription']['issued_at'] ?? null));

$viewResult = app()->cap()->call('ehr.prescription.view@1', ['id' => $prescriptionId], ['caller_module' => 'prescriptions']);
t('prescription view succeeds', is_array($viewResult) && !empty($viewResult['ok']) && (int)($viewResult['prescription']['id'] ?? 0) === $prescriptionId);

$cancelResult = app()->cap()->call('ehr.prescription.cancel@1', [
    'prescription_id' => $prescriptionId,
    'reason' => 'Medication changed after assessment update',
], ['caller_module' => 'prescriptions']);

t('prescription cancel succeeds', is_array($cancelResult) && !empty($cancelResult['ok']), is_array($cancelResult) ? json_encode($cancelResult) : 'not array');
t('prescription becomes canceled', (string)($cancelResult['prescription']['status'] ?? '') === 'canceled');
t('cancellation reason persisted', (string)($cancelResult['prescription']['cancellation_reason'] ?? '') === 'Medication changed after assessment update');
t('canceled timestamp set', !empty($cancelResult['prescription']['canceled_at'] ?? null));

$cancelAgain = app()->cap()->call('ehr.prescription.cancel@1', [
    'prescription_id' => $prescriptionId,
    'reason' => 'Second cancel should fail',
], ['caller_module' => 'prescriptions']);

t('second cancel is rejected', is_array($cancelAgain) && empty($cancelAgain['ok']) && str_contains((string)($cancelAgain['error'] ?? ''), 'Only issued prescriptions can be canceled'), is_array($cancelAgain) ? json_encode($cancelAgain) : 'not array');

echo "\n{$pass} passed, {$fail} failed\n";
if ($errors !== []) {
    echo "Failures:\n";
    foreach ($errors as $error) {
        echo "  - {$error}\n";
    }
}

exit($fail > 0 ? 1 : 0);