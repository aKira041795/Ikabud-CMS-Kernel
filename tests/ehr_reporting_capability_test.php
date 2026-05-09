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

foreach (['ehr-core', 'patient-registry', 'encounters', 'scheduling', 'orders', 'results', 'reporting'] as $moduleId) {
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

function ehrReportingExecSqlFile(PDO $db, string $path): void
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

function ehrReportingDropIfExists(PDO $db, string $table): void
{
    $db->exec('DROP TABLE IF EXISTS `' . str_replace('`', '``', $table) . '`');
}

echo "=== EHR Reporting Capability ===\n\n";

$db = app()->db();
$db->exec('SET FOREIGN_KEY_CHECKS=0');
foreach (['audit_logs', 'ehr_lab_results', 'ehr_order_items', 'ehr_orders', 'ehr_appointments', 'ehr_vitals', 'ehr_encounters', 'ehr_patient_identifiers', 'ehr_patients'] as $table) {
    ehrReportingDropIfExists($db, $table);
}
$db->exec('SET FOREIGN_KEY_CHECKS=1');

register_shutdown_function(static function () use ($db): void {
    try {
        $db->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach (['audit_logs', 'ehr_lab_results', 'ehr_order_items', 'ehr_orders', 'ehr_appointments', 'ehr_vitals', 'ehr_encounters', 'ehr_patient_identifiers', 'ehr_patients'] as $table) {
            ehrReportingDropIfExists($db, $table);
        }
        $db->exec('SET FOREIGN_KEY_CHECKS=1');
    } catch (Throwable $ignored) {
    }
});

ehrReportingExecSqlFile($db, __DIR__ . '/../database/migrations/007_kernel_runtime_tables.sql');
ehrReportingExecSqlFile($db, __DIR__ . '/../database/migrations/018_audit_logs_actor_columns_ensure.sql');
ehrReportingExecSqlFile($db, modulePathForId('patient-registry') . '/database/migrations/001_initial.sql');
ehrReportingExecSqlFile($db, modulePathForId('encounters') . '/database/migrations/001_initial.sql');
ehrReportingExecSqlFile($db, modulePathForId('scheduling') . '/database/migrations/001_initial.sql');
ehrReportingExecSqlFile($db, modulePathForId('orders') . '/database/migrations/001_initial.sql');
ehrReportingExecSqlFile($db, modulePathForId('results') . '/database/migrations/001_initial.sql');
ehrReportingExecSqlFile($db, modulePathForId('results') . '/database/migrations/002_add_restricted_flag.sql');

loadModuleRoutes([]);

t('reporting summary capability registered', app()->capabilities()->has('ehr.reporting.summary@1'));

$patientResult = app()->cap()->call('ehr.patient.create@1', [
    'first_name' => 'Ada',
    'last_name' => 'Lovelace',
    'birth_date' => '1989-06-11',
    'sex' => 'female',
    'identifiers' => [
        ['type' => 'mrn', 'value' => 'MRN-8001', 'is_primary' => true],
    ],
], ['caller_module' => 'patient-registry']);

$patientId = (int)($patientResult['patient']['id'] ?? 0);
t('patient create succeeds', is_array($patientResult) && !empty($patientResult['ok']) && $patientId > 0, is_array($patientResult) ? json_encode($patientResult) : 'not array');

$appointmentCompleted = app()->cap()->call('ehr.appointment.schedule@1', [
    'patient_id' => $patientId,
    'appointment_type' => 'follow-up',
    'scheduled_start' => '2026-05-10 09:00:00',
    'scheduled_end' => '2026-05-10 09:30:00',
    'facility_id' => 2,
    'department_id' => 8,
], ['caller_module' => 'scheduling']);
$appointmentCompletedId = (int)($appointmentCompleted['appointment']['id'] ?? 0);

app()->cap()->call('ehr.appointment.transition@1', ['id' => $appointmentCompletedId, 'status' => 'checked-in'], ['caller_module' => 'scheduling']);
app()->cap()->call('ehr.appointment.transition@1', ['id' => $appointmentCompletedId, 'status' => 'waiting'], ['caller_module' => 'scheduling']);
$roomed = app()->cap()->call('ehr.appointment.transition@1', ['id' => $appointmentCompletedId, 'status' => 'roomed'], ['caller_module' => 'scheduling']);
$roomedEncounterId = (int)($roomed['appointment']['encounter_id'] ?? 0);
$completed = app()->cap()->call('ehr.appointment.transition@1', ['id' => $appointmentCompletedId, 'status' => 'completed'], ['caller_module' => 'scheduling']);
t('completed appointment workflow succeeds', is_array($completed) && !empty($completed['ok']) && $roomedEncounterId > 0, is_array($completed) ? json_encode($completed) : 'not array');

$appointmentNoShow = app()->cap()->call('ehr.appointment.schedule@1', [
    'patient_id' => $patientId,
    'appointment_type' => 'screening',
    'scheduled_start' => '2026-05-10 13:00:00',
    'facility_id' => 2,
    'department_id' => 8,
], ['caller_module' => 'scheduling']);
$appointmentNoShowId = (int)($appointmentNoShow['appointment']['id'] ?? 0);
$noShow = app()->cap()->call('ehr.appointment.transition@1', ['id' => $appointmentNoShowId, 'status' => 'no-show'], ['caller_module' => 'scheduling']);
t('no-show appointment workflow succeeds', is_array($noShow) && !empty($noShow['ok']), is_array($noShow) ? json_encode($noShow) : 'not array');

$appointmentScheduled = app()->cap()->call('ehr.appointment.schedule@1', [
    'patient_id' => $patientId,
    'appointment_type' => 'consult',
    'scheduled_start' => '2026-05-11 10:00:00',
    'facility_id' => 2,
    'department_id' => 8,
], ['caller_module' => 'scheduling']);
t('scheduled appointment succeeds', is_array($appointmentScheduled) && !empty($appointmentScheduled['ok']), is_array($appointmentScheduled) ? json_encode($appointmentScheduled) : 'not array');

$directEncounter = app()->cap()->call('ehr.encounter.create@1', [
    'patient_id' => $patientId,
    'encounter_type' => 'outpatient',
    'service_line' => 'ambulatory',
    'facility_id' => 2,
    'department_id' => 8,
    'reason_for_visit' => 'Operational reporting sample',
], ['caller_module' => 'encounters']);
$directEncounterId = (int)($directEncounter['encounter']['id'] ?? 0);
t('direct encounter succeeds', is_array($directEncounter) && !empty($directEncounter['ok']) && $directEncounterId > 0, is_array($directEncounter) ? json_encode($directEncounter) : 'not array');

$db->prepare('UPDATE ehr_encounters SET status = ?, start_at = ?, end_at = ? WHERE id = ?')->execute(['completed', '2026-05-10 09:15:00', '2026-05-10 09:45:00', $roomedEncounterId]);
$db->prepare('UPDATE ehr_encounters SET status = ?, start_at = ?, end_at = NULL WHERE id = ?')->execute(['open', '2026-05-10 14:00:00', $directEncounterId]);

$orderCreate = app()->cap()->call('ehr.order.create@1', [
    'patient_id' => $patientId,
    'encounter_id' => $directEncounterId,
    'order_type' => 'lab',
    'items' => [
        [
            'item_code' => 'CMP',
            'code_system' => 'local',
            'item_label' => 'Comprehensive Metabolic Panel',
        ]
    ]
], ['caller_module' => 'orders']);
$orderId = (int)($orderCreate['order']['id'] ?? 0);
$orderItemId = (int)($orderCreate['order']['items'][0]['id'] ?? 0);
t('order create succeeds', is_array($orderCreate) && !empty($orderCreate['ok']) && $orderId > 0 && $orderItemId > 0, is_array($orderCreate) ? json_encode($orderCreate) : 'not array');

$resultEnter = app()->cap()->call('ehr.result.enter@1', [
    'order_id' => $orderId,
    'order_item_id' => $orderItemId,
    'value_numeric' => 88.2,
    'unit' => 'mg/dL',
    'reference_range_text' => '70-100 mg/dL',
    'entered_by_user_id' => 801,
], ['caller_module' => 'results']);
$resultId = (int)($resultEnter['result']['id'] ?? 0);
app()->cap()->call('ehr.result.verify@1', ['result_id' => $resultId, 'verified_by_user_id' => 802], ['caller_module' => 'results']);
$resultRelease = app()->cap()->call('ehr.result.release@1', ['result_id' => $resultId], ['caller_module' => 'results']);
t('result release succeeds', is_array($resultRelease) && !empty($resultRelease['ok']) && $resultId > 0, is_array($resultRelease) ? json_encode($resultRelease) : 'not array');

$db->prepare('UPDATE ehr_lab_results SET observed_at = ?, verified_at = ?, released_at = ? WHERE id = ?')->execute([
    '2026-05-10 08:00:00',
    '2026-05-10 09:30:00',
    '2026-05-10 10:00:00',
    $resultId,
]);

$summary = app()->cap()->call('ehr.reporting.summary@1', [
    'facility_id' => 2,
    'department_id' => 8,
], ['caller_module' => 'reporting']);

$data = is_array($summary['summary'] ?? null) ? $summary['summary'] : [];
$appointmentFlow = is_array($data['appointment_flow'] ?? null) ? $data['appointment_flow'] : [];
$encounterVolume = is_array($data['encounter_volume'] ?? null) ? $data['encounter_volume'] : [];
$turnaround = is_array($data['turnaround_time'] ?? null) ? $data['turnaround_time'] : [];
$activity = is_array($data['user_activity'] ?? null) ? $data['user_activity'] : [];

t('reporting summary succeeds', is_array($summary) && !empty($summary['ok']), is_array($summary) ? json_encode($summary) : 'not array');
t('appointment flow total counted', (int)($appointmentFlow['total'] ?? 0) === 3, json_encode($appointmentFlow));
t('appointment flow statuses counted', (int)($appointmentFlow['by_status']['completed'] ?? 0) === 1 && (int)($appointmentFlow['by_status']['no-show'] ?? 0) === 1 && (int)($appointmentFlow['by_status']['scheduled'] ?? 0) === 1, json_encode($appointmentFlow));
t('encounter volume counted', (int)($encounterVolume['total'] ?? 0) === 2 && (int)($encounterVolume['completed_count'] ?? 0) === 1 && (int)($encounterVolume['open_count'] ?? 0) === 1, json_encode($encounterVolume));
t('turnaround minutes computed', (int)round((float)($turnaround['average_minutes'] ?? 0)) === 120 && (int)($turnaround['released_count'] ?? 0) === 1, json_encode($turnaround));

$topModules = is_array($activity['top_modules'] ?? null) ? $activity['top_modules'] : [];
$moduleNames = array_values(array_filter(array_map(static fn(array $row): string => (string)($row['module'] ?? ''), $topModules)));
t('user activity events counted', (int)($activity['total_events'] ?? 0) >= 6, json_encode($activity));
t('user activity includes scheduling and encounters', in_array('scheduling', $moduleNames, true) && in_array('encounters', $moduleNames, true), json_encode($activity));

echo "\n{$pass} passed, {$fail} failed\n";
if ($errors !== []) {
    echo "Failures:\n";
    foreach ($errors as $error) {
        echo "  - {$error}\n";
    }
}

exit($fail > 0 ? 1 : 0);