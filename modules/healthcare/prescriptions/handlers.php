<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

function rxPageState(array $user, array $input = [], ?string $formError = null): array
{
    $rows = rxDb()->query(
        'SELECT id, prescription_uuid, patient_id, encounter_id, medication_text, dose_text, route, frequency, duration_text, quantity, refills, status, issued_at, canceled_at, cancellation_reason '
        . 'FROM ehr_prescriptions ORDER BY issued_at DESC, id DESC LIMIT 12'
    )->fetchAll(PDO::FETCH_ASSOC);

    $prescriptions = ehrHydrateRecordSummaries(is_array($rows) ? $rows : [], 'prescriptions');
    foreach ($prescriptions as &$rxRow) {
        if (function_exists('ehrStatusBadge')) {
            $rxRow['status_badge'] = ehrStatusBadge((string)($rxRow['status'] ?? ''), 'prescription');
        }
    }
    unset($rxRow);

    $patientSearch = app()->cap()->call('ehr.patient.search@1', ['limit' => 50], ['caller_module' => 'prescriptions']);
    $patientOptions = is_array($patientSearch) && !empty($patientSearch['ok']) && is_array($patientSearch['results'] ?? null)
        ? array_values($patientSearch['results']) : [];
    $encounterList = app()->cap()->call('ehr.encounter.list@1', ['limit' => 50], ['caller_module' => 'prescriptions']);
    $encounterOptions = is_array($encounterList) && !empty($encounterList['ok']) && is_array($encounterList['encounters'] ?? null)
        ? array_values($encounterList['encounters']) : [];

    return array_merge(
        ehrAdminContext($user, 'ehr_prescriptions', ['page_title' => 'Medications']),
        [
            'prescriptions' => $prescriptions,
            'result_count' => count($prescriptions),
            'patient_options' => $patientOptions,
            'encounter_options' => $encounterOptions,
            'form_error' => $formError !== null ? $formError : (trim((string)($input['error'] ?? '')) !== '' ? (string)$input['error'] : null),
            'form_notice' => trim((string)($input['notice'] ?? '')),
            'form_values' => [
                'patient_id' => (int)($input['patient_id'] ?? 0),
                'encounter_id' => (int)($input['encounter_id'] ?? 0),
                'medication_text' => (string)($input['medication_text'] ?? ''),
                'dose_text' => (string)($input['dose_text'] ?? ''),
                'route' => (string)($input['route'] ?? ''),
                'frequency' => (string)($input['frequency'] ?? ''),
            ],
        ]
    );
}

function rxPageIndex(array $params = []): void
{
    if (!function_exists('ehrRequireAdmin') || !function_exists('ehrRender') || !function_exists('ehrAdminContext') || !function_exists('ehrHydrateRecordSummaries')) {
        http_response_code(503);
        echo 'EHR admin runtime unavailable';
        return;
    }

    $user = ehrRequireAdmin();
    echo ehrRender('modules/prescriptions/admin/index.disyl', rxPageState($user, app()->input()));
}

function rxSavePrescription(array $params = []): void
{
    if (!function_exists('ehrRequireAdmin') || !function_exists('ehrRender') || !function_exists('ehrAdminContext') || !function_exists('ehrHydrateRecordSummaries')) {
        http_response_code(503);
        echo 'EHR admin runtime unavailable';
        return;
    }

    $user = ehrRequireAdmin();
    $input = app()->input();
    $payload = [
        'patient_id' => max(0, (int)($input['patient_id'] ?? 0)),
        'encounter_id' => max(0, (int)($input['encounter_id'] ?? 0)),
        'medication_text' => trim((string)($input['medication_text'] ?? '')),
        'status' => 'issued',
        'dose_text' => trim((string)($input['dose_text'] ?? '')),
        'route' => trim((string)($input['route'] ?? '')),
        'frequency' => trim((string)($input['frequency'] ?? '')),
        'prescriber_user_id' => (int)($user['id'] ?? 0),
    ];

    $result = app()->cap()->call('ehr.prescription.issue@1', $payload, ['caller_module' => 'prescriptions']);
    if (is_array($result) && !empty($result['ok']) && is_array($result['prescription'] ?? null)) {
        app()->redirect('/admin/ehr/prescriptions?notice=created');
        return;
    }

    $error = trim((string)($result['error'] ?? 'Unable to issue prescription.'));
    echo ehrRender('modules/prescriptions/admin/index.disyl', rxPageState($user, $input, $error));
}
function rxCancelPrescription(array $params = []): void
{
    if (!function_exists('ehrRequireAdmin')) {
        http_response_code(503);
        echo 'EHR admin runtime unavailable';
        return;
    }
    $user = ehrRequireAdmin();
    if (function_exists('csrfEnforce')) {
        csrfEnforce();
    }
    $input = app()->input();
    $prescriptionId = max(0, (int)($input['prescription_id'] ?? 0));
    $reason = trim((string)($input['reason'] ?? ''));
    if ($prescriptionId <= 0) {
        app()->redirect('/admin/ehr/prescriptions?error=' . urlencode('Prescription is required.'));
        return;
    }
    if ($reason === '') {
        app()->redirect('/admin/ehr/prescriptions?error=' . urlencode('Cancellation reason is required.'));
        return;
    }
    $result = app()->cap()->call('ehr.prescription.cancel@1', [
        'prescription_id' => $prescriptionId,
        'reason' => $reason,
        'actor_user_id' => (int)($user['id'] ?? 0),
    ], ['caller_module' => 'prescriptions']);
    if (is_array($result) && !empty($result['ok'])) {
        app()->redirect('/admin/ehr/prescriptions?notice=canceled');
        return;
    }
    $error = trim((string)($result['error'] ?? 'Unable to cancel prescription.'));
    app()->redirect('/admin/ehr/prescriptions?error=' . urlencode($error));
}

function rxRequestRefill(array $params = []): void
{
    if (!function_exists('ehrRequireAdmin')) {
        http_response_code(503);
        echo 'EHR admin runtime unavailable';
        return;
    }
    $user = ehrRequireAdmin();
    if (function_exists('csrfEnforce')) {
        csrfEnforce();
    }
    $input = app()->input();
    $prescriptionId = max(0, (int)($input['prescription_id'] ?? 0));
    $reason = trim((string)($input['reason'] ?? ''));
    if ($prescriptionId <= 0) {
        app()->redirect('/admin/ehr/prescriptions?error=' . urlencode('Prescription is required.'));
        return;
    }
    $result = app()->cap()->call('ehr.prescription.request_refill@1', [
        'prescription_id' => $prescriptionId,
        'reason' => $reason,
        'actor_user_id' => (int)($user['id'] ?? 0),
    ], ['caller_module' => 'prescriptions']);
    if (is_array($result) && !empty($result['ok'])) {
        app()->redirect('/admin/ehr/prescriptions?notice=refill_requested');
        return;
    }
    $error = trim((string)($result['error'] ?? 'Unable to request refill.'));
    app()->redirect('/admin/ehr/prescriptions?error=' . urlencode($error));
}

function rxPrint(array $params = []): void
{
    if (!function_exists('ehrRequireAdmin')) { http_response_code(503); echo 'EHR admin runtime unavailable'; return; }
    ehrRequireAdmin();
    $rxId = (int)($params['id'] ?? 0);
    if ($rxId <= 0) { http_response_code(404); echo 'Prescription not found'; return; }
    $rx = rxDb()->query(
        'SELECT id, prescription_uuid, patient_id, encounter_id, medication_text, dose_text, route, frequency, duration_text, quantity, refills, status, issued_at, prescriber_user_id '
        . 'FROM ehr_prescriptions WHERE id = :id LIMIT 1',
        [':id' => $rxId]
    )->fetch(\PDO::FETCH_ASSOC);
    if (!is_array($rx)) { http_response_code(404); echo 'Prescription not found'; return; }
    $patient = function_exists('ehrPatientSummary') ? ehrPatientSummary((int)($rx['patient_id'] ?? 0), 'prescriptions') : [];
    $orgName = 'Clinic';
    if (function_exists('ehrSettings')) {
        $s = ehrSettings();
        $orgName = (string)($s['app_name'] ?? $s['legal_name'] ?? $orgName);
    }
    $h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $patientName = trim((string)($patient['last_name'] ?? '') . ', ' . (string)($patient['first_name'] ?? ''));
    if ($patientName === ', ') { $patientName = 'Unknown patient'; }
    while (ob_get_level() > 0) { @ob_end_clean(); }
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Prescription ' . $h($rx['prescription_uuid'] ?? '') . '</title>';
    echo '<style>body{font-family:Georgia,serif;color:#0f172a;margin:40px;max-width:720px}h1{font-size:20px;margin:0}h2{font-size:14px;letter-spacing:.18em;text-transform:uppercase;color:#64748b;margin-top:24px}table{width:100%;border-collapse:collapse;margin-top:8px}th,td{padding:6px 8px;border:1px solid #e2e8f0;text-align:left;font-size:13px}.rx{font-family:Times,serif;font-size:48px;font-weight:bold;line-height:1;color:#0f172a}.signature{margin-top:48px;border-top:1px solid #0f172a;padding-top:6px;font-size:12px;color:#475569;width:280px}.no-print{margin-bottom:24px}@media print{.no-print{display:none}}</style>';
    echo '</head><body>';
    echo '<div class="no-print"><button onclick="window.print()" style="padding:8px 16px;background:#0d9488;color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:13px">Print / Save as PDF</button></div>';
    echo '<header style="display:flex;justify-content:space-between;align-items:flex-start"><div><h1>' . $h($orgName) . '</h1><div style="font-size:12px;color:#64748b">Prescription ' . $h($rx['prescription_uuid'] ?? '') . '</div></div><div class="rx">℞</div></header>';
    echo '<h2>Patient</h2><table><tr><th style="width:30%">Name</th><td>' . $h($patientName) . '</td></tr>';
    if (!empty($patient['birth_date'])) { echo '<tr><th>Birth date</th><td>' . $h($patient['birth_date']) . '</td></tr>'; }
    echo '<tr><th>Patient ID</th><td>' . $h($rx['patient_id']) . '</td></tr></table>';
    echo '<h2>Medication</h2><table>';
    echo '<tr><th style="width:30%">Drug</th><td>' . $h($rx['medication_text']) . '</td></tr>';
    echo '<tr><th>Dose</th><td>' . $h($rx['dose_text']) . '</td></tr>';
    echo '<tr><th>Route</th><td>' . $h($rx['route']) . '</td></tr>';
    echo '<tr><th>Frequency</th><td>' . $h($rx['frequency']) . '</td></tr>';
    echo '<tr><th>Duration</th><td>' . $h($rx['duration_text']) . '</td></tr>';
    echo '<tr><th>Quantity</th><td>' . $h($rx['quantity']) . '</td></tr>';
    echo '<tr><th>Refills</th><td>' . $h($rx['refills']) . '</td></tr>';
    echo '<tr><th>Status</th><td>' . $h($rx['status']) . '</td></tr>';
    echo '<tr><th>Issued</th><td>' . $h($rx['issued_at']) . '</td></tr>';
    echo '</table>';
    echo '<div class="signature">Prescriber signature (user #' . $h($rx['prescriber_user_id']) . ')</div>';
    echo '</body></html>';
}
