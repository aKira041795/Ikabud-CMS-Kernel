#!/usr/bin/env php
<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$host = 'ehr.test';
foreach (array_slice($argv, 1) as $arg) {
    if (is_string($arg) && str_starts_with($arg, '--host=')) {
        $host = trim(substr($arg, strlen('--host=')));
    }
}

$_SERVER['HTTP_HOST'] = $host;
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/admin/ehr';

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';

foreach (['ehr', 'patient-registry', 'scheduling', 'encounters', 'clinical-notes', 'orders', 'results', 'prescriptions', 'documents', 'privacy-consent'] as $moduleId) {
    $modulePath = modulePathForId($moduleId);
    if ($modulePath && is_file($modulePath . '/helpers.php')) {
        require_once $modulePath . '/helpers.php';
    }
}

loadModuleRoutes([
    'GET' => [],
    'POST' => [],
    'PUT' => [],
    'DELETE' => [],
]);

function seedOut(string $message): void
{
    echo $message . PHP_EOL;
}

function seedFail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function seedDb(): mixed
{
    return app()->db();
}

function seedFetchOne(string $sql, array $params = []): ?array
{
    $stmt = seedDb()->prepare($sql);
    if ($stmt === false) {
        throw new RuntimeException('Failed to prepare query');
    }
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function seedFetchAll(string $sql, array $params = []): array
{
    $stmt = seedDb()->prepare($sql);
    if ($stmt === false) {
        throw new RuntimeException('Failed to prepare query');
    }
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : [];
}

function seedCapability(string $capabilityId, array $payload, string $callerModule): array
{
    $result = app()->cap()->call($capabilityId, $payload, ['caller_module' => $callerModule]);
    if (!is_array($result) || empty($result['ok'])) {
        $error = is_array($result) ? (string)($result['error'] ?? 'unknown capability error') : 'invalid capability result';
        throw new RuntimeException($capabilityId . ' failed: ' . $error);
    }

    return $result;
}

function seedAdminUser(): array
{
    $user = seedFetchOne('SELECT id, username, full_name, email, role, token_version FROM ehr_users WHERE is_active = 1 ORDER BY id ASC LIMIT 1');
    if (!$user) {
        seedFail('No active EHR admin user found for demo seeding.');
    }

    return $user;
}

function seedEnsurePatient(array $data, int $adminUserId): array
{
    $existing = seedFetchOne('SELECT id FROM ehr_patients WHERE email = :email LIMIT 1', [':email' => $data['email']]);
    if ($existing) {
        $view = seedCapability('ehr.patient.view@1', ['id' => (int)$existing['id']], 'patient-registry');
        return $view['patient'];
    }

    $created = seedCapability('ehr.patient.create@1', [
        'first_name' => $data['first_name'],
        'last_name' => $data['last_name'],
        'birth_date' => $data['birth_date'],
        'sex' => $data['sex'],
        'primary_phone' => $data['primary_phone'],
        'email' => $data['email'],
        'identifiers' => [
            [
                'type' => 'mrn',
                'value' => $data['identifier'],
                'issuing_authority' => 'EHR Demo Seed',
                'is_primary' => true,
                'status' => 'active',
            ],
        ],
        'actor_user_id' => $adminUserId,
    ], 'patient-registry');

    return $created['patient'];
}

function seedEnsureAppointment(array $data, int $adminUserId): array
{
    $existing = seedFetchOne(
        'SELECT id FROM ehr_appointments WHERE patient_id = :patient_id AND reason_for_visit = :reason_for_visit LIMIT 1',
        [':patient_id' => $data['patient_id'], ':reason_for_visit' => $data['reason_for_visit']]
    );
    if ($existing) {
        return scheduling_cap_ehr_appointment_view_1(['id' => (int)$existing['id']], 'ehr.appointment.view@1', 'scheduling')['appointment'];
    }

    $created = seedCapability('ehr.appointment.schedule@1', [
        'patient_id' => $data['patient_id'],
        'appointment_type' => $data['appointment_type'],
        'scheduled_start' => $data['scheduled_start'],
        'scheduled_end' => $data['scheduled_end'],
        'facility_id' => 1,
        'department_id' => 1,
        'location_id' => 1,
        'reason_for_visit' => $data['reason_for_visit'],
        'notes' => $data['notes'],
        'created_by_user_id' => $adminUserId,
    ], 'scheduling');

    return $created['appointment'];
}

function seedEnsureAppointmentStatus(array $appointment, array $statuses, int $adminUserId): array
{
    $current = $appointment;
    foreach ($statuses as $status) {
        if ((string)($current['status'] ?? '') === $status) {
            continue;
        }

        $transitioned = seedCapability('ehr.appointment.transition@1', [
            'id' => (int)$current['id'],
            'status' => $status,
            'attending_provider_id' => $adminUserId,
            'service_line' => 'ambulatory',
        ], 'scheduling');
        $current = $transitioned['appointment'];
    }

    return $current;
}

function seedEnsureEncounter(array $data, int $adminUserId): array
{
    $existing = seedFetchOne(
        'SELECT id FROM ehr_encounters WHERE patient_id = :patient_id AND reason_for_visit = :reason_for_visit LIMIT 1',
        [':patient_id' => $data['patient_id'], ':reason_for_visit' => $data['reason_for_visit']]
    );
    if ($existing) {
        return seedCapability('ehr.encounter.view@1', ['id' => (int)$existing['id']], 'encounters')['encounter'];
    }

    $created = seedCapability('ehr.encounter.create@1', [
        'patient_id' => $data['patient_id'],
        'encounter_type' => $data['encounter_type'],
        'service_line' => 'ambulatory',
        'status' => $data['status'],
        'facility_id' => 1,
        'department_id' => 1,
        'location_id' => 1,
        'attending_provider_id' => $adminUserId,
        'reason_for_visit' => $data['reason_for_visit'],
    ], 'encounters');

    return $created['encounter'];
}

function seedEnsureVitals(int $encounterId, array $payload, int $adminUserId): void
{
    $existing = seedFetchOne('SELECT id FROM ehr_vitals WHERE encounter_id = :encounter_id LIMIT 1', [':encounter_id' => $encounterId]);
    if ($existing) {
        return;
    }

    seedCapability('ehr.vitals.record@1', array_merge($payload, [
        'encounter_id' => $encounterId,
        'captured_by_user_id' => $adminUserId,
    ]), 'encounters');
}

function seedEnsureOrder(array $data): array
{
    $existing = seedFetchOne(
        'SELECT id FROM ehr_orders WHERE encounter_id = :encounter_id AND clinical_question = :clinical_question LIMIT 1',
        [':encounter_id' => $data['encounter_id'], ':clinical_question' => $data['clinical_question']]
    );
    if ($existing) {
        return seedCapability('ehr.order.view@1', ['id' => (int)$existing['id']], 'orders')['order'];
    }

    $created = seedCapability('ehr.order.create@1', $data, 'orders');
    return $created['order'];
}

function seedEnsureResult(int $orderId, int $orderItemId, array $payload, string $targetStatus, int $adminUserId): array
{
    $existing = seedFetchOne('SELECT id FROM ehr_lab_results WHERE order_item_id = :order_item_id LIMIT 1', [':order_item_id' => $orderItemId]);
    if ($existing) {
        $result = resFetchResult((int)$existing['id']);
    } else {
        $entered = seedCapability('ehr.result.enter@1', array_merge($payload, [
            'order_id' => $orderId,
            'order_item_id' => $orderItemId,
            'entered_by_user_id' => $adminUserId,
        ]), 'results');
        $result = $entered['result'];
    }

    $currentStatus = (string)($result['result_status'] ?? '');
    if ($targetStatus === 'verified' && $currentStatus === 'entered') {
        $result = seedCapability('ehr.result.verify@1', [
            'result_id' => (int)$result['id'],
            'verified_by_user_id' => $adminUserId,
        ], 'results')['result'];
        $currentStatus = (string)($result['result_status'] ?? '');
    }

    if ($targetStatus === 'released') {
        if ($currentStatus === 'entered') {
            $result = seedCapability('ehr.result.verify@1', [
                'result_id' => (int)$result['id'],
                'verified_by_user_id' => $adminUserId,
            ], 'results')['result'];
            $currentStatus = (string)($result['result_status'] ?? '');
        }
        if ($currentStatus === 'verified') {
            $result = seedCapability('ehr.result.release@1', [
                'result_id' => (int)$result['id'],
            ], 'results')['result'];
        }
    }

    return $result;
}

function seedEnsureNote(array $data, string $targetStatus, int $adminUserId): array
{
    $existing = seedFetchOne(
        'SELECT n.id FROM ehr_notes n '
        . 'JOIN ehr_note_versions nv ON nv.note_id = n.id '
        . 'WHERE n.encounter_id = :encounter_id AND nv.body_text LIKE :marker '
        . 'ORDER BY n.id DESC LIMIT 1',
        [':encounter_id' => $data['encounter_id'], ':marker' => '%' . $data['marker'] . '%']
    );

    if ($existing) {
        $note = cnFetchNote((int)$existing['id']);
    } else {
        $created = seedCapability('ehr.note.create@1', [
            'patient_id' => $data['patient_id'],
            'encounter_id' => $data['encounter_id'],
            'note_type' => $data['note_type'],
            'body_text' => $data['body_text'],
            'restricted_flag' => !empty($data['restricted_flag']),
            'actor_user_id' => $adminUserId,
        ], 'clinical-notes');
        $note = $created['note'];
    }

    $currentStatus = (string)($note['status'] ?? '');
    if (($targetStatus === 'signed' || $targetStatus === 'amended') && $currentStatus === 'draft') {
        $note = seedCapability('ehr.note.sign@1', [
            'note_id' => (int)$note['id'],
            'sign_reason' => 'EHR demo signature',
            'actor_user_id' => $adminUserId,
        ], 'clinical-notes')['note'];
        $currentStatus = (string)($note['status'] ?? '');
    }

    if ($targetStatus === 'amended' && ($currentStatus === 'signed' || $currentStatus === 'amended')) {
        $latestVersion = $note['versions'][count($note['versions']) - 1] ?? null;
        $bodyText = (string)($latestVersion['body_text'] ?? $data['body_text']);
        if (!str_contains($bodyText, '[amended]')) {
            $bodyText .= "\n\n[amended] Medication list reconciled.";
        }
        $note = seedCapability('ehr.note.amend@1', [
            'note_id' => (int)$note['id'],
            'amendment_reason' => 'EHR demo amendment',
            'body_text' => $bodyText,
            'actor_user_id' => $adminUserId,
        ], 'clinical-notes')['note'];
    }

    return $note;
}

function seedEnsurePrescription(array $data, bool $cancel, int $adminUserId): array
{
    $existing = seedFetchOne(
        'SELECT id FROM ehr_prescriptions WHERE encounter_id = :encounter_id AND medication_text = :medication_text LIMIT 1',
        [':encounter_id' => $data['encounter_id'], ':medication_text' => $data['medication_text']]
    );

    if ($existing) {
        $prescription = rxFetchPrescription((int)$existing['id']);
    } else {
        $prescription = seedCapability('ehr.prescription.issue@1', array_merge($data, [
            'prescriber_user_id' => $adminUserId,
        ]), 'prescriptions')['prescription'];
    }

    if ($cancel && (string)($prescription['status'] ?? '') === 'issued') {
        $prescription = seedCapability('ehr.prescription.cancel@1', [
            'prescription_id' => (int)$prescription['id'],
            'reason' => 'EHR demo cancellation',
        ], 'prescriptions')['prescription'];
    }

    return $prescription;
}

function seedEnsureDocument(array $data, int $adminUserId): array
{
    $existing = seedFetchOne('SELECT id FROM ehr_documents WHERE storage_key = :storage_key LIMIT 1', [':storage_key' => $data['storage_key']]);
    if ($existing) {
        $document = docFetchDocument((int)$existing['id']);
    } else {
        $document = seedCapability('ehr.document.upload@1', array_merge($data, [
            'uploaded_by_user_id' => $adminUserId,
        ]), 'documents')['document'];
    }

    seedCapability('ehr.document.restrict@1', [
        'document_id' => (int)$document['id'],
        'policy_type' => 'document',
        'sensitivity_level' => 'restricted',
        'consent_required_flag' => 1,
        'break_glass_only_flag' => 0,
        'active_flag' => 1,
    ], 'documents');

    return docFetchDocument((int)$document['id']) ?? $document;
}

function seedEnsureConsent(array $data, int $adminUserId): array
{
    $existing = seedFetchOne(
        'SELECT id FROM ehr_consents WHERE patient_id = :patient_id AND consent_type = :consent_type LIMIT 1',
        [':patient_id' => $data['patient_id'], ':consent_type' => $data['consent_type']]
    );
    if ($existing) {
        return pcFetchConsent((int)$existing['id']) ?? [];
    }

    return seedCapability('ehr.consent.record@1', array_merge($data, [
        'captured_by_user_id' => $adminUserId,
    ]), 'privacy-consent')['consent'];
}

function seedEnsureBreakGlass(array $data, int $adminUserId): array
{
    $existing = seedFetchOne(
        'SELECT id FROM ehr_break_glass_events WHERE patient_id = :patient_id AND reason_text = :reason_text LIMIT 1',
        [':patient_id' => $data['patient_id'], ':reason_text' => $data['reason']]
    );
    if ($existing) {
        return pcFetchBreakGlass((int)$existing['id']) ?? [];
    }

    return seedCapability('ehr.break_glass.request@1', array_merge($data, [
        'requested_by_user_id' => $adminUserId,
    ]), 'privacy-consent')['event'];
}

$enabledModules = array_keys(getEnabledModules());
if (!in_array('ehr', $enabledModules, true)) {
    seedFail('EHR is not enabled for host ' . $host);
}

$dbName = seedDb()->query('SELECT DATABASE()')->fetchColumn();
$adminUser = seedAdminUser();
$adminUserId = (int)$adminUser['id'];

seedOut('Seeding EHR demo data for host ' . $host . ' on database ' . (string)$dbName . '...');

$patientA = seedEnsurePatient([
    'first_name' => 'Mara',
    'last_name' => 'Santos',
    'birth_date' => '1992-03-14',
    'sex' => 'female',
    'primary_phone' => '09170000001',
    'email' => 'mara.santos+ehr-demo@local.test',
    'identifier' => 'EHR-DEMO-001',
], $adminUserId);

$patientB = seedEnsurePatient([
    'first_name' => 'Joel',
    'last_name' => 'Ramos',
    'birth_date' => '1986-11-08',
    'sex' => 'male',
    'primary_phone' => '09170000002',
    'email' => 'joel.ramos+ehr-demo@local.test',
    'identifier' => 'EHR-DEMO-002',
], $adminUserId);

$completedAppointment = seedEnsureAppointment([
    'patient_id' => (int)$patientA['id'],
    'appointment_type' => 'Annual Wellness Visit',
    'scheduled_start' => date('Y-m-d 08:30:00', strtotime('+1 day')),
    'scheduled_end' => date('Y-m-d 09:00:00', strtotime('+1 day')),
    'reason_for_visit' => 'EHR DEMO: Annual wellness visit',
    'notes' => 'EHR DEMO: completed appointment seed',
], $adminUserId);
$completedAppointment = seedEnsureAppointmentStatus($completedAppointment, ['checked-in', 'waiting', 'roomed', 'completed'], $adminUserId);

$scheduledAppointment = seedEnsureAppointment([
    'patient_id' => (int)$patientB['id'],
    'appointment_type' => 'Follow-up Consult',
    'scheduled_start' => date('Y-m-d 10:30:00', strtotime('+1 day')),
    'scheduled_end' => date('Y-m-d 11:00:00', strtotime('+1 day')),
    'reason_for_visit' => 'EHR DEMO: Follow-up consult',
    'notes' => 'EHR DEMO: scheduled appointment seed',
], $adminUserId);

$completedEncounterId = (int)($completedAppointment['encounter_id'] ?? 0);
if ($completedEncounterId <= 0) {
    seedFail('Completed demo appointment did not produce an encounter.');
}
$completedEncounter = seedCapability('ehr.encounter.view@1', ['id' => $completedEncounterId], 'encounters')['encounter'];

$openEncounter = seedEnsureEncounter([
    'patient_id' => (int)$patientB['id'],
    'encounter_type' => 'outpatient',
    'status' => 'open',
    'reason_for_visit' => 'EHR DEMO: Hypertension follow-up',
], $adminUserId);

seedEnsureVitals((int)$completedEncounter['id'], [
    'height_cm' => 162,
    'weight_kg' => 58,
    'temperature_c' => 36.7,
    'systolic_bp' => 118,
    'diastolic_bp' => 76,
    'pulse_bpm' => 74,
    'spo2' => 98,
    'pain_score' => 1,
], $adminUserId);

seedEnsureVitals((int)$openEncounter['id'], [
    'height_cm' => 171,
    'weight_kg' => 84,
    'temperature_c' => 37.1,
    'systolic_bp' => 142,
    'diastolic_bp' => 91,
    'pulse_bpm' => 88,
    'spo2' => 97,
    'pain_score' => 3,
], $adminUserId);

$order = seedEnsureOrder([
    'patient_id' => (int)$patientA['id'],
    'encounter_id' => (int)$completedEncounter['id'],
    'order_type' => 'lab',
    'priority' => 'urgent',
    'clinical_question' => 'EHR DEMO: Rule out infection and review metabolic panel',
    'destination_module' => 'results',
    'items' => [
        ['item_label' => 'Complete Blood Count', 'item_code' => 'CBC', 'specimen_type' => 'blood'],
        ['item_label' => 'Basic Metabolic Panel', 'item_code' => 'BMP', 'specimen_type' => 'blood'],
    ],
]);

$orderItems = is_array($order['items'] ?? null) ? $order['items'] : [];
if (count($orderItems) < 2) {
    seedFail('Demo order did not produce the expected order items.');
}

$releasedResult = seedEnsureResult((int)$order['id'], (int)$orderItems[0]['id'], [
    'value_text' => 'WBC mildly elevated',
    'reference_range_text' => '4.5 - 11.0',
    'abnormal_flag' => 'high',
], 'released', $adminUserId);

$enteredResult = seedEnsureResult((int)$order['id'], (int)$orderItems[1]['id'], [
    'value_numeric' => 138.4,
    'unit' => 'mmol/L',
    'reference_range_text' => '135 - 145',
], 'entered', $adminUserId);

$amendedNote = seedEnsureNote([
    'patient_id' => (int)$patientA['id'],
    'encounter_id' => (int)$completedEncounter['id'],
    'note_type' => 'progress-note',
    'body_text' => 'EHR DEMO NOTE: Patient tolerated exam well and initial counseling was completed.',
    'marker' => 'EHR DEMO NOTE:',
    'restricted_flag' => true,
], 'amended', $adminUserId);

$draftNote = seedEnsureNote([
    'patient_id' => (int)$patientB['id'],
    'encounter_id' => (int)$openEncounter['id'],
    'note_type' => 'follow-up-note',
    'body_text' => 'EHR DEMO DRAFT NOTE: Blood pressure follow-up and medication review pending.',
    'marker' => 'EHR DEMO DRAFT NOTE:',
    'restricted_flag' => false,
], 'draft', $adminUserId);

$activePrescription = seedEnsurePrescription([
    'patient_id' => (int)$patientA['id'],
    'encounter_id' => (int)$completedEncounter['id'],
    'medication_text' => 'Amoxicillin 500mg',
    'dose_text' => '500 mg',
    'route' => 'oral',
    'frequency' => 'TID',
    'duration_text' => '7 days',
    'quantity' => '21 capsules',
    'refills' => 0,
    'indication' => 'EHR DEMO: suspected bacterial infection',
], false, $adminUserId);

$canceledPrescription = seedEnsurePrescription([
    'patient_id' => (int)$patientB['id'],
    'encounter_id' => (int)$openEncounter['id'],
    'medication_text' => 'Lisinopril 10mg',
    'dose_text' => '10 mg',
    'route' => 'oral',
    'frequency' => 'OD',
    'duration_text' => '30 days',
    'quantity' => '30 tablets',
    'refills' => 1,
    'indication' => 'EHR DEMO: hypertension control',
], true, $adminUserId);

$document = seedEnsureDocument([
    'patient_id' => (int)$patientA['id'],
    'encounter_id' => (int)$completedEncounter['id'],
    'related_order_id' => (int)$order['id'],
    'related_result_id' => (int)$releasedResult['id'],
    'storage_key' => 'ehr-demo/discharge-summary-001.pdf',
    'document_type' => 'discharge-summary',
    'title' => 'EHR DEMO Discharge Summary',
    'mime_type' => 'application/pdf',
    'file_size' => 245760,
    'tags' => ['demo', 'discharge', 'follow-up'],
    'sensitivity_level' => 'standard',
    'consent_required_flag' => 0,
    'break_glass_only_flag' => 0,
], $adminUserId);

$consent = seedEnsureConsent([
    'patient_id' => (int)$patientA['id'],
    'consent_type' => 'demo-document-share',
    'status' => 'granted',
    'document_id' => (int)$document['id'],
    'scope' => ['document_id' => (int)$document['id'], 'purpose' => 'EHR demo testing'],
], $adminUserId);

$breakGlass = seedEnsureBreakGlass([
    'patient_id' => (int)$patientB['id'],
    'reason' => 'EHR DEMO: emergency access during triage',
    'object_type' => 'patient',
    'object_id' => (string)$patientB['id'],
    'duration_minutes' => 45,
    'request_context' => ['screen' => 'privacy-demo', 'reason' => 'triage'],
], $adminUserId);

seedOut('Seed complete.');
seedOut('Patients: #' . (int)$patientA['id'] . ' ' . $patientA['last_name'] . ', #' . (int)$patientB['id'] . ' ' . $patientB['last_name']);
seedOut('Appointments: completed #' . (int)$completedAppointment['id'] . ', scheduled #' . (int)$scheduledAppointment['id']);
seedOut('Encounters: completed-linked #' . (int)$completedEncounter['id'] . ', open #' . (int)$openEncounter['id']);
seedOut('Order #' . (int)$order['id'] . ' with ' . count($orderItems) . ' items');
seedOut('Results: released #' . (int)$releasedResult['id'] . ', entered #' . (int)$enteredResult['id']);
seedOut('Notes: amended #' . (int)$amendedNote['id'] . ', draft #' . (int)$draftNote['id']);
seedOut('Prescriptions: active #' . (int)$activePrescription['id'] . ', canceled #' . (int)$canceledPrescription['id']);
seedOut('Document #' . (int)$document['id'] . ', consent #' . (int)$consent['id'] . ', break-glass #' . (int)$breakGlass['id']);