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

foreach (['ehr-core', 'patient-registry', 'encounters', 'scheduling'] as $moduleId) {
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

function ehrSchedExecSqlFile(PDO $db, string $path): void
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

function ehrSchedDropIfExists(PDO $db, string $table): void
{
    $db->exec('DROP TABLE IF EXISTS `' . str_replace('`', '``', $table) . '`');
}

echo "=== EHR Scheduling Capability ===\n\n";

$db = app()->db();
$db->exec('SET FOREIGN_KEY_CHECKS=0');
foreach (['ehr_appointments', 'ehr_vitals', 'ehr_encounters', 'ehr_patient_identifiers', 'ehr_patients'] as $table) {
    ehrSchedDropIfExists($db, $table);
}
$db->exec('SET FOREIGN_KEY_CHECKS=1');

register_shutdown_function(static function () use ($db): void {
    try {
        $db->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach (['ehr_appointments', 'ehr_vitals', 'ehr_encounters', 'ehr_patient_identifiers', 'ehr_patients'] as $table) {
            ehrSchedDropIfExists($db, $table);
        }
        $db->exec('SET FOREIGN_KEY_CHECKS=1');
    } catch (Throwable $ignored) {
    }
});

ehrSchedExecSqlFile($db, modulePathForId('patient-registry') . '/database/migrations/001_initial.sql');
ehrSchedExecSqlFile($db, modulePathForId('encounters') . '/database/migrations/001_initial.sql');
ehrSchedExecSqlFile($db, modulePathForId('scheduling') . '/database/migrations/001_initial.sql');

loadModuleRoutes([]);

t('appointment schedule capability registered', app()->capabilities()->has('ehr.appointment.schedule@1'));
t('appointment list capability registered', app()->capabilities()->has('ehr.appointment.list@1'));
t('appointment transition capability registered', app()->capabilities()->has('ehr.appointment.transition@1'));

$patientResult = app()->cap()->call('ehr.patient.create@1', [
    'first_name' => 'Clara',
    'last_name' => 'Barton',
    'birth_date' => '1987-03-18',
    'sex' => 'female',
    'identifiers' => [
        ['type' => 'mrn', 'value' => 'MRN-6001', 'is_primary' => true],
    ],
], ['caller_module' => 'patient-registry']);

$patientId = (int)($patientResult['patient']['id'] ?? 0);
t('patient create succeeds', is_array($patientResult) && !empty($patientResult['ok']) && $patientId > 0, is_array($patientResult) ? json_encode($patientResult) : 'not array');

$appointmentResult = app()->cap()->call('ehr.appointment.schedule@1', [
    'patient_id' => $patientId,
    'appointment_type' => 'follow-up',
    'scheduled_start' => '2026-05-10 09:00:00',
    'scheduled_end' => '2026-05-10 09:30:00',
    'facility_id' => 2,
    'department_id' => 8,
    'location_id' => 12,
    'reason_for_visit' => 'Blood pressure review',
    'created_by_user_id' => 501,
], ['caller_module' => 'scheduling']);

t('appointment schedule succeeds', is_array($appointmentResult) && !empty($appointmentResult['ok']), is_array($appointmentResult) ? json_encode($appointmentResult) : 'not array');
$appointmentId = (int)($appointmentResult['appointment']['id'] ?? 0);
t('appointment starts scheduled', (string)($appointmentResult['appointment']['status'] ?? '') === 'scheduled');

$listScheduled = app()->cap()->call('ehr.appointment.list@1', [
    'status' => 'scheduled',
    'scheduled_date' => '2026-05-10',
], ['caller_module' => 'scheduling']);

$listed = is_array($listScheduled['appointments'] ?? null) ? $listScheduled['appointments'] : [];
t('appointment list returns scheduled item', is_array($listScheduled) && !empty($listScheduled['ok']) && count($listed) === 1, is_array($listScheduled) ? json_encode($listScheduled) : 'not array');
t('appointment list includes patient summary only', (($listed[0]['patient_summary']['last_name'] ?? '') === 'Barton') && empty($listed[0]['patient_summary']['identifiers'] ?? []));

$checkedIn = app()->cap()->call('ehr.appointment.transition@1', [
    'id' => $appointmentId,
    'status' => 'checked-in',
], ['caller_module' => 'scheduling']);
t('checked-in transition succeeds', is_array($checkedIn) && !empty($checkedIn['ok']) && (string)($checkedIn['appointment']['status'] ?? '') === 'checked-in', is_array($checkedIn) ? json_encode($checkedIn) : 'not array');

$waiting = app()->cap()->call('ehr.appointment.transition@1', [
    'id' => $appointmentId,
    'status' => 'waiting',
], ['caller_module' => 'scheduling']);
t('waiting transition succeeds', is_array($waiting) && !empty($waiting['ok']) && (string)($waiting['appointment']['status'] ?? '') === 'waiting', is_array($waiting) ? json_encode($waiting) : 'not array');

$roomed = app()->cap()->call('ehr.appointment.transition@1', [
    'id' => $appointmentId,
    'status' => 'roomed',
    'attending_provider_id' => 702,
], ['caller_module' => 'scheduling']);

$encounterId = (int)($roomed['appointment']['encounter_id'] ?? 0);
t('roomed transition succeeds', is_array($roomed) && !empty($roomed['ok']) && (string)($roomed['appointment']['status'] ?? '') === 'roomed', is_array($roomed) ? json_encode($roomed) : 'not array');
t('roomed transition creates encounter', $encounterId > 0);

$encounterView = app()->cap()->call('ehr.encounter.view@1', ['id' => $encounterId], ['caller_module' => 'scheduling']);
t('encounter view succeeds after rooming', is_array($encounterView) && !empty($encounterView['ok']) && (int)($encounterView['encounter']['patient_id'] ?? 0) === $patientId, is_array($encounterView) ? json_encode($encounterView) : 'not array');

$completed = app()->cap()->call('ehr.appointment.transition@1', [
    'id' => $appointmentId,
    'status' => 'completed',
], ['caller_module' => 'scheduling']);
t('completed transition succeeds', is_array($completed) && !empty($completed['ok']) && (string)($completed['appointment']['status'] ?? '') === 'completed', is_array($completed) ? json_encode($completed) : 'not array');

$followUp = app()->cap()->call('ehr.appointment.schedule@1', [
    'patient_id' => $patientId,
    'appointment_type' => 'screening',
    'scheduled_start' => '2026-05-11 10:00:00',
], ['caller_module' => 'scheduling']);

$secondAppointmentId = (int)($followUp['appointment']['id'] ?? 0);
$noShow = app()->cap()->call('ehr.appointment.transition@1', [
    'id' => $secondAppointmentId,
    'status' => 'no-show',
], ['caller_module' => 'scheduling']);
t('no-show transition succeeds', is_array($noShow) && !empty($noShow['ok']) && (string)($noShow['appointment']['status'] ?? '') === 'no-show', is_array($noShow) ? json_encode($noShow) : 'not array');

echo "\n{$pass} passed, {$fail} failed\n";
if ($errors !== []) {
    echo "Failures:\n";
    foreach ($errors as $error) {
        echo "  - {$error}\n";
    }
}

exit($fail > 0 ? 1 : 0);