<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

function portalRenderPage(string $template, array $context = []): string
{
    return app()->render($template, array_merge([
        'brand_name' => 'Patient Portal',
        'csrf_token' => app()->csrfToken(),
        'csrf_field' => app()->csrfField(),
    ], $context));
}

function portalPageRoot(array $params = []): void
{
    if (portalCurrentSession()) {
        app()->redirect('/portal/dashboard');
        return;
    }
    app()->redirect('/portal/login');
}

function portalPageLogin(array $params = []): void
{
    if (portalCurrentSession()) {
        app()->redirect('/portal/dashboard');
        return;
    }

    echo portalRenderPage('modules/patient-portal/portal/login.disyl', [
        'page_title' => 'Sign in to your patient portal',
        'login_endpoint' => '/portal/login',
        'error_message' => '',
        'notice' => trim((string)(app()->input()['notice'] ?? '')),
    ]);
}

function portalAuthLogin(array $params = []): void
{
    header('Content-Type: application/json; charset=utf-8');
    app()->csrfEnforce();

    $input = app()->input();
    $email = portalNormalizeEmail((string)($input['email'] ?? ''));
    $password = (string)($input['password'] ?? '');

    if ($email === '' || $password === '') {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Email and password are required.']);
        return;
    }

    if (portalRecentFailedAttempts($email) >= 8) {
        http_response_code(429);
        portalRecordLoginAttempt($email, false);
        echo json_encode(['ok' => false, 'error' => 'Too many failed sign-in attempts. Please try again later.']);
        return;
    }

    $account = portalFetchAccountByEmail($email);
    if (!$account || (string)($account['status'] ?? '') !== 'active') {
        portalRecordLoginAttempt($email, false);
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Invalid email or password.']);
        return;
    }

    if (!password_verify($password, (string)($account['password_hash'] ?? ''))) {
        portalRecordLoginAttempt($email, false);
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Invalid email or password.']);
        return;
    }

    try {
        portalDb()->execute('UPDATE ehr_portal_accounts SET last_login_at = NOW() WHERE id = :id', [':id' => (int)$account['id']]);
    } catch (\Throwable $e) {
        write_log('portal last_login_at update failed: ' . $e->getMessage(), 'warn');
    }

    $token = portalIssueSessionToken($account);
    portalSetSessionCookie($token);
    app()->csrfRotate(true);
    portalRecordLoginAttempt($email, true);

    portalAuditRecord('ehr.portal.session.started', [
        'patient_id' => (int)$account['patient_id'],
        'new_data' => ['account_uuid' => (string)$account['account_uuid']],
    ]);

    echo json_encode(['ok' => true, 'redirect' => '/portal/dashboard']);
}

function portalAuthLogout(array $params = []): void
{
    app()->csrfEnforce();
    $session = portalCurrentSession();
    portalClearSessionCookie();
    if ($session) {
        portalAuditRecord('ehr.portal.session.ended', [
            'patient_id' => (int)$session['patient_id'],
            'new_data' => ['account_uuid' => (string)$session['account_uuid']],
        ]);
    }
    app()->redirect('/portal/login');
}

function portalPageDashboard(array $params = []): void
{
    $session = portalRequireSession();
    $patientId = (int)$session['patient_id'];
    $patient = portalPatientSummary($patientId);
    $appointments = portalPatientAppointments($patientId, 5);
    $results = portalPatientResults($patientId, 25);
    $prescriptions = portalPatientPrescriptions($patientId, 25);
    $documents = portalPatientDocuments($patientId, 25);
    $consent = portalActiveConsent($patientId, 'general');

    // Derive highlights
    $nextAppointment = null;
    $nowTs = time();
    foreach ($appointments as $appt) {
        $startTs = strtotime((string)($appt['scheduled_start'] ?? '')) ?: 0;
        if ($startTs > 0 && $startTs >= $nowTs && in_array((string)($appt['status'] ?? ''), ['scheduled', 'confirmed', 'checked_in', 'roomed', 'pending'], true)) {
            if ($nextAppointment === null || $startTs < (strtotime((string)$nextAppointment['scheduled_start']) ?: PHP_INT_MAX)) {
                $nextAppointment = $appt;
            }
        }
    }

    $newResults = 0;
    $abnormalResults = 0;
    foreach ($results as $r) {
        if (empty($r['acknowledged_at'])) {
            $newResults++;
        }
        if (!empty($r['abnormal_flag']) && (string)$r['abnormal_flag'] !== 'normal' && (string)$r['abnormal_flag'] !== 'N') {
            $abnormalResults++;
        }
    }

    $activeRx = 0;
    foreach ($prescriptions as $rx) {
        $status = strtolower((string)($rx['status'] ?? ''));
        if ($status === '' || $status === 'active' || $status === 'on-hold' || $status === 'in_progress') {
            $activeRx++;
        }
    }

    echo portalRenderPage('modules/patient-portal/portal/dashboard.disyl', [
        'page_title' => 'Your portal',
        'active_nav' => 'dashboard',
        'patient' => $patient,
        'session' => $session,
        'upcoming_appointments' => $appointments,
        'next_appointment' => $nextAppointment,
        'recent_results' => array_slice($results, 0, 4),
        'recent_prescriptions' => array_slice($prescriptions, 0, 4),
        'stats' => [
            'appointments_total' => count($appointments),
            'results_total' => count($results),
            'results_new' => $newResults,
            'results_abnormal' => $abnormalResults,
            'prescriptions_active' => $activeRx,
            'documents_total' => count($documents),
        ],
        'consent_active' => $consent !== null,
        'logout_endpoint' => '/portal/logout',
    ]);
}

function portalPageAppointments(array $params = []): void
{
    $session = portalRequireSession();
    $patient = portalPatientSummary((int)$session['patient_id']);
    $appointments = portalPatientAppointments((int)$session['patient_id'], 50);

    $now = time();
    $upcomingStatuses = ['scheduled', 'confirmed', 'checked_in', 'roomed', 'pending', ''];
    $cancelledStatuses = ['cancelled', 'canceled', 'no_show', 'noshow'];

    $upcoming = [];
    $past = [];
    $cancelled = [];
    $nextAppointment = null;
    $nextTs = PHP_INT_MAX;

    foreach ($appointments as $appt) {
        $status = strtolower((string)($appt['status'] ?? ''));
        $startTs = !empty($appt['scheduled_start']) ? strtotime((string)$appt['scheduled_start']) : false;
        if (in_array($status, $cancelledStatuses, true)) {
            $cancelled[] = $appt;
            continue;
        }
        if ($startTs !== false && $startTs >= $now && in_array($status, $upcomingStatuses, true)) {
            $upcoming[] = $appt;
            if ($startTs < $nextTs) {
                $nextTs = $startTs;
                $nextAppointment = $appt;
            }
        } else {
            $past[] = $appt;
        }
    }

    usort($upcoming, fn($a, $b) => strtotime((string)($a['scheduled_start'] ?? '')) <=> strtotime((string)($b['scheduled_start'] ?? '')));
    usort($past, fn($a, $b) => strtotime((string)($b['scheduled_start'] ?? '')) <=> strtotime((string)($a['scheduled_start'] ?? '')));
    usort($cancelled, fn($a, $b) => strtotime((string)($b['scheduled_start'] ?? '')) <=> strtotime((string)($a['scheduled_start'] ?? '')));

    $view = strtolower((string)($_GET['view'] ?? 'upcoming'));
    if (!in_array($view, ['upcoming', 'past', 'cancelled'], true)) {
        $view = 'upcoming';
    }
    $visible = $view === 'past' ? $past : ($view === 'cancelled' ? $cancelled : $upcoming);

    $selectedUuid = (string)($_GET['selected'] ?? '');
    $selected = null;
    if ($selectedUuid !== '') {
        foreach ($appointments as $appt) {
            if ((string)($appt['appointment_uuid'] ?? '') === $selectedUuid) {
                $selected = $appt;
                break;
            }
        }
    }

    echo portalRenderPage('modules/patient-portal/portal/appointments.disyl', [
        'page_title' => 'My appointments',
        'active_nav' => 'appointments',
        'patient' => $patient,
        'session' => $session,
        'appointments' => $appointments,
        'next_appointment' => $nextAppointment,
        'upcoming' => $upcoming,
        'past' => $past,
        'cancelled' => $cancelled,
        'visible' => $visible,
        'view' => $view,
        'selected' => $selected,
        'counts' => [
            'upcoming' => count($upcoming),
            'past' => count($past),
            'cancelled' => count($cancelled),
        ],
        'reschedule_endpoint' => '/portal/appointments/reschedule',
        'reschedule_notice' => isset($_GET['reschedule_notice']) ? 'Your reschedule request has been sent. The clinic will contact you shortly.' : '',
        'reschedule_error' => trim((string)($_GET['reschedule_error'] ?? '')),
        'logout_endpoint' => '/portal/logout',
    ]);
}

function portalPageResults(array $params = []): void
{
    $session = portalRequireSession();
    $patient = portalPatientSummary((int)$session['patient_id']);
    $results = portalPatientResults((int)$session['patient_id'], 50);

    echo portalRenderPage('modules/patient-portal/portal/results.disyl', [
        'page_title' => 'Your released results',
        'active_nav' => 'results',
        'patient' => $patient,
        'session' => $session,
        'results' => $results,
        'logout_endpoint' => '/portal/logout',
    ]);
}

function portalPagePrescriptions(array $params = []): void
{
    $session = portalRequireSession();
    $patient = portalPatientSummary((int)$session['patient_id']);
    $prescriptions = portalPatientPrescriptions((int)$session['patient_id'], 50);

    echo portalRenderPage('modules/patient-portal/portal/prescriptions.disyl', [
        'page_title' => 'Your prescriptions',
        'active_nav' => 'prescriptions',
        'patient' => $patient,
        'session' => $session,
        'prescriptions' => $prescriptions,
        'logout_endpoint' => '/portal/logout',
    ]);
}

function portalPageDocuments(array $params = []): void
{
    $session = portalRequireSession();
    $patient = portalPatientSummary((int)$session['patient_id']);
    $documents = portalPatientDocuments((int)$session['patient_id'], 50);

    echo portalRenderPage('modules/patient-portal/portal/documents.disyl', [
        'page_title' => 'Your documents',
        'active_nav' => 'documents',
        'patient' => $patient,
        'session' => $session,
        'documents' => $documents,
        'logout_endpoint' => '/portal/logout',
    ]);
}

function portalPageConsent(array $params = []): void
{
    $session = portalRequireSession();
    $patient = portalPatientSummary((int)$session['patient_id']);
    $active = portalActiveConsent((int)$session['patient_id'], 'general');

    echo portalRenderPage('modules/patient-portal/portal/consent.disyl', [
        'page_title' => 'Sharing & consent',
        'active_nav' => 'consent',
        'patient' => $patient,
        'session' => $session,
        'active_consent' => $active,
        'consent_endpoint' => '/portal/consent',
        'logout_endpoint' => '/portal/logout',
    ]);
}

function portalConsentRecord(array $params = []): void
{
    $session = portalRequireSession();
    app()->csrfEnforce();
    $input = app()->input();
    $action = strtolower(trim((string)($input['action'] ?? 'grant')));
    $status = $action === 'revoke' ? 'revoked' : 'granted';

    $result = portalRecordConsent((int)$session['patient_id'], 'general', $status, [
        'recorded_via' => 'patient-portal',
        'action' => $action,
    ]);

    if (is_array($result) && !empty($result['ok'])) {
        portalAuditRecord('ehr.consent.recorded', [
            'patient_id' => (int)$session['patient_id'],
            'new_data' => ['status' => $status, 'consent_type' => 'general'],
        ]);
    }

    app()->redirect('/portal/consent');
}

function portalAppointmentRescheduleRequest(array $params = []): void
{
    $session = portalRequireSession();
    app()->csrfEnforce();
    $input = app()->input();

    $appointmentUuid = trim((string)($input['appointment_uuid'] ?? ''));
    $reason = trim((string)($input['reason'] ?? ''));
    $preferredWindow = trim((string)($input['preferred_window'] ?? ''));
    $contactMethod = strtolower(trim((string)($input['contact_method'] ?? 'phone')));
    if (!in_array($contactMethod, ['phone', 'email', 'sms'], true)) {
        $contactMethod = 'phone';
    }

    $patientId = (int)$session['patient_id'];
    $accountId = (int)($session['account_id'] ?? 0);

    $redirectBack = '/portal/appointments';
    if ($appointmentUuid !== '') {
        $redirectBack .= '?selected=' . rawurlencode($appointmentUuid) . '#appt-' . rawurlencode($appointmentUuid);
    }

    if ($appointmentUuid === '' || strlen($reason) < 3) {
        app()->redirect($redirectBack . (strpos($redirectBack, '?') === false ? '?' : '&') . 'reschedule_error=' . rawurlencode('Please describe why you need to reschedule.'));
        return;
    }

    $appointment = null;
    foreach (portalPatientAppointments($patientId, 50) as $appt) {
        if ((string)($appt['appointment_uuid'] ?? '') === $appointmentUuid) {
            $appointment = $appt;
            break;
        }
    }
    if ($appointment === null) {
        app()->redirect($redirectBack . (strpos($redirectBack, '?') === false ? '?' : '&') . 'reschedule_error=' . rawurlencode('Appointment not found.'));
        return;
    }

    portalDb()->execute(
        'INSERT INTO ehr_portal_reschedule_requests
            (account_id, patient_id, appointment_uuid, appointment_type, scheduled_start,
             preferred_window, contact_method, reason, requester_ip, created_at)
         VALUES
            (:account_id, :patient_id, :appointment_uuid, :appointment_type, :scheduled_start,
             :preferred_window, :contact_method, :reason, :requester_ip, NOW())',
        [
            ':account_id' => $accountId,
            ':patient_id' => $patientId,
            ':appointment_uuid' => $appointmentUuid,
            ':appointment_type' => substr((string)($appointment['appointment_type'] ?? ''), 0, 128),
            ':scheduled_start' => (string)($appointment['scheduled_start'] ?? null) ?: null,
            ':preferred_window' => substr($preferredWindow, 0, 64) ?: null,
            ':contact_method' => $contactMethod,
            ':reason' => substr($reason, 0, 4000),
            ':requester_ip' => substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64) ?: null,
        ]
    );

    portalAuditRecord('ehr.portal.appointment.reschedule_requested', [
        'patient_id' => $patientId,
        'new_data' => [
            'appointment_uuid' => $appointmentUuid,
            'contact_method' => $contactMethod,
            'preferred_window' => $preferredWindow,
        ],
    ]);

    app()->redirect($redirectBack . (strpos($redirectBack, '?') === false ? '?' : '&') . 'reschedule_notice=1');
}

function portalAdminPageIndex(array $params = []): void
{
    if (!function_exists('ehrRequireAdmin') || !function_exists('ehrRender') || !function_exists('ehrAdminContext')) {
        http_response_code(500);
        echo 'EHR admin shell unavailable';
        return;
    }

    $user = ehrRequireAdmin();
    $rows = portalDb()->query(
        'SELECT id, account_uuid, patient_id, email, status, last_login_at, created_at '
        . 'FROM ehr_portal_accounts ORDER BY created_at DESC LIMIT 100'
    )->fetchAll(\PDO::FETCH_ASSOC) ?: [];

    foreach ($rows as &$row) {
        $row['first_name'] = null;
        $row['last_name'] = null;
        $row['patient_uuid'] = null;
        try {
            $patientResult = app()->cap()->call('ehr.patient.view@1', [
                'patient_id' => (int)$row['patient_id'],
            ], ['caller_module' => 'patient-portal']);
            if (is_array($patientResult) && !empty($patientResult['ok']) && !empty($patientResult['patient'])) {
                $patient = $patientResult['patient'];
                $row['first_name'] = $patient['first_name'] ?? null;
                $row['last_name'] = $patient['last_name'] ?? null;
                $row['patient_uuid'] = $patient['patient_uuid'] ?? null;
            }
        } catch (\Throwable $e) {
            // ignore — display row without patient name
        }
    }
    unset($row);

    $input = app()->input();
    $pendingReschedule = (int)(portalDb()->query(
        "SELECT COUNT(*) AS c FROM ehr_portal_reschedule_requests WHERE status = 'pending'"
    )->fetch(\PDO::FETCH_ASSOC)['c'] ?? 0);

    $context = ehrAdminContext($user, 'ehr_patient_portal', [
        'page_title' => 'Patient Portal',
        'portal_accounts' => $rows,
        'provision_endpoint' => '/admin/ehr/portal/provision',
        'deactivate_endpoint' => '/admin/ehr/portal/deactivate',
        'update_endpoint' => '/admin/ehr/portal/update',
        'reset_password_endpoint' => '/admin/ehr/portal/reset-password',
        'reactivate_endpoint' => '/admin/ehr/portal/reactivate',
        'reschedule_inbox_url' => '/admin/ehr/portal/reschedule-requests',
        'reschedule_pending_count' => $pendingReschedule,
        'form_notice' => trim((string)($input['notice'] ?? '')),
        'form_error' => trim((string)($input['error'] ?? '')),
    ]);

    echo ehrRender('modules/patient-portal/admin/index.disyl', $context);
}

function portalAdminPageRescheduleRequests(array $params = []): void
{
    if (!function_exists('ehrRequireAdmin') || !function_exists('ehrRender') || !function_exists('ehrAdminContext')) {
        http_response_code(500);
        echo 'EHR admin shell unavailable';
        return;
    }

    $user = ehrRequireAdmin();
    $input = app()->input();

    $statusFilter = strtolower(trim((string)($input['status'] ?? 'pending')));
    if (!in_array($statusFilter, ['pending', 'handled', 'dismissed', 'all'], true)) {
        $statusFilter = 'pending';
    }

    if ($statusFilter === 'all') {
        $stmt = portalDb()->query(
            'SELECT id, account_id, patient_id, appointment_uuid, appointment_type, scheduled_start,
                    preferred_window, contact_method, reason, status, requester_ip,
                    handled_at, handled_by, created_at
               FROM ehr_portal_reschedule_requests
              ORDER BY (status = "pending") DESC, created_at DESC
              LIMIT 200'
        );
    } else {
        $stmt = portalDb()->prepare(
            'SELECT id, account_id, patient_id, appointment_uuid, appointment_type, scheduled_start,
                    preferred_window, contact_method, reason, status, requester_ip,
                    handled_at, handled_by, created_at
               FROM ehr_portal_reschedule_requests
              WHERE status = :status
              ORDER BY created_at DESC
              LIMIT 200'
        );
        $stmt->execute([':status' => $statusFilter]);
    }
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

    foreach ($rows as &$row) {
        $row['first_name'] = null;
        $row['last_name'] = null;
        $row['patient_uuid'] = null;
        try {
            $patientResult = app()->cap()->call('ehr.patient.view@1', [
                'patient_id' => (int)$row['patient_id'],
            ], ['caller_module' => 'patient-portal']);
            if (is_array($patientResult) && !empty($patientResult['ok']) && !empty($patientResult['patient'])) {
                $patient = $patientResult['patient'];
                $row['first_name'] = $patient['first_name'] ?? null;
                $row['last_name'] = $patient['last_name'] ?? null;
                $row['patient_uuid'] = $patient['patient_uuid'] ?? null;
            }
        } catch (\Throwable $e) {
            // ignore
        }
    }
    unset($row);

    $counts = ['pending' => 0, 'handled' => 0, 'dismissed' => 0];
    $countStmt = portalDb()->query(
        'SELECT status, COUNT(*) AS c FROM ehr_portal_reschedule_requests GROUP BY status'
    );
    foreach ($countStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $c) {
        $counts[$c['status']] = (int)$c['c'];
    }

    $context = ehrAdminContext($user, 'ehr_patient_portal', [
        'page_title' => 'Reschedule Requests',
        'requests' => $rows,
        'status_filter' => $statusFilter,
        'count_pending' => $counts['pending'] ?? 0,
        'count_handled' => $counts['handled'] ?? 0,
        'count_dismissed' => $counts['dismissed'] ?? 0,
        'handle_endpoint' => '/admin/ehr/portal/reschedule-requests/handle',
        'back_url' => '/admin/ehr/portal',
        'form_notice' => trim((string)($input['notice'] ?? '')),
        'form_error' => trim((string)($input['error'] ?? '')),
    ]);

    echo ehrRender('modules/patient-portal/admin/reschedule_requests.disyl', $context);
}

function portalAdminRescheduleHandle(array $params = []): void
{
    if (!function_exists('ehrRequireAdmin')) {
        http_response_code(500);
        echo 'EHR admin shell unavailable';
        return;
    }

    app()->csrfEnforce();
    $user = ehrRequireAdmin();
    $input = app()->input();

    $id = (int)($input['id'] ?? 0);
    $action = strtolower(trim((string)($input['action'] ?? '')));
    $statusFilter = strtolower(trim((string)($input['status'] ?? 'pending')));
    if (!in_array($statusFilter, ['pending', 'handled', 'dismissed', 'all'], true)) {
        $statusFilter = 'pending';
    }
    $newStatus = $action === 'dismiss' ? 'dismissed' : ($action === 'handle' ? 'handled' : '');

    $redirect = '/admin/ehr/portal/reschedule-requests?status=' . rawurlencode($statusFilter);

    if ($id <= 0 || $newStatus === '') {
        app()->redirect($redirect . '&notice=handle_failed&error=' . rawurlencode('Invalid request.'));
        return;
    }

    $stmt = portalDb()->prepare(
        'SELECT id, patient_id, appointment_uuid, status FROM ehr_portal_reschedule_requests WHERE id = :id LIMIT 1'
    );
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    if (!$row) {
        app()->redirect($redirect . '&notice=handle_failed&error=' . rawurlencode('Request not found.'));
        return;
    }
    if ($row['status'] !== 'pending') {
        app()->redirect($redirect . '&notice=handle_failed&error=' . rawurlencode('Request already resolved.'));
        return;
    }

    $handledBy = trim((string)($user['email'] ?? $user['username'] ?? $user['id'] ?? 'admin'));
    portalDb()->execute(
        'UPDATE ehr_portal_reschedule_requests
            SET status = :status, handled_at = NOW(), handled_by = :handled_by
          WHERE id = :id',
        [
            ':status' => $newStatus,
            ':handled_by' => substr($handledBy, 0, 128),
            ':id' => $id,
        ]
    );

    portalAuditRecord('ehr.portal.appointment.reschedule_' . $newStatus, [
        'patient_id' => (int)$row['patient_id'],
        'new_data' => [
            'request_id' => $id,
            'appointment_uuid' => (string)$row['appointment_uuid'],
            'status' => $newStatus,
            'handled_by' => $handledBy,
        ],
    ]);

    app()->redirect($redirect . '&notice=' . rawurlencode($newStatus));
}

function portalAdminProvision(array $params = []): void
{
    if (!function_exists('ehrRequireAdmin')) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'EHR admin shell unavailable']);
        return;
    }

    app()->csrfEnforce();
    $user = ehrRequireAdmin();
    $input = app()->input();

    $result = app()->cap()->call('ehr.portal.account.provision@1', [
        'patient_id' => (int)($input['patient_id'] ?? 0),
        'email' => (string)($input['email'] ?? ''),
        'password' => (string)($input['password'] ?? ''),
        'provisioned_by_user_id' => (int)($user['id'] ?? 0),
    ], ['caller_module' => 'patient-portal']);

    portalAdminRespond($input, $result, 'provisioned', 'provision_failed');
}

function portalAdminDeactivate(array $params = []): void
{
    if (!function_exists('ehrRequireAdmin')) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'EHR admin shell unavailable']);
        return;
    }

    app()->csrfEnforce();
    $user = ehrRequireAdmin();
    $input = app()->input();

    $result = app()->cap()->call('ehr.portal.account.deactivate@1', [
        'patient_id' => (int)($input['patient_id'] ?? 0),
        'reason' => (string)($input['reason'] ?? ''),
        'actor_user_id' => (int)($user['id'] ?? 0),
    ], ['caller_module' => 'patient-portal']);

    portalAdminRespond($input, $result, 'deactivated', 'deactivate_failed');
}

function portalAdminUpdate(array $params = []): void
{
    if (!function_exists('ehrRequireAdmin')) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'EHR admin shell unavailable']);
        return;
    }

    app()->csrfEnforce();
    $user = ehrRequireAdmin();
    $input = app()->input();

    $result = app()->cap()->call('ehr.portal.account.update@1', [
        'patient_id' => (int)($input['patient_id'] ?? 0),
        'email' => (string)($input['email'] ?? ''),
        'actor_user_id' => (int)($user['id'] ?? 0),
    ], ['caller_module' => 'patient-portal']);

    portalAdminRespond($input, $result, 'updated', 'update_failed');
}

function portalAdminResetPassword(array $params = []): void
{
    if (!function_exists('ehrRequireAdmin')) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'EHR admin shell unavailable']);
        return;
    }

    app()->csrfEnforce();
    $user = ehrRequireAdmin();
    $input = app()->input();

    $result = app()->cap()->call('ehr.portal.account.reset_password@1', [
        'patient_id' => (int)($input['patient_id'] ?? 0),
        'password' => (string)($input['password'] ?? ''),
        'actor_user_id' => (int)($user['id'] ?? 0),
    ], ['caller_module' => 'patient-portal']);

    portalAdminRespond($input, $result, 'password_reset', 'password_reset_failed');
}

function portalAdminReactivate(array $params = []): void
{
    if (!function_exists('ehrRequireAdmin')) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'EHR admin shell unavailable']);
        return;
    }

    app()->csrfEnforce();
    $user = ehrRequireAdmin();
    $input = app()->input();

    $result = app()->cap()->call('ehr.portal.account.reactivate@1', [
        'patient_id' => (int)($input['patient_id'] ?? 0),
        'actor_user_id' => (int)($user['id'] ?? 0),
    ], ['caller_module' => 'patient-portal']);

    portalAdminRespond($input, $result, 'reactivated', 'reactivate_failed');
}

function portalAdminRespond(array $input, mixed $result, string $okNotice, string $errNotice): void
{
    $accept = (string)($_SERVER['HTTP_ACCEPT'] ?? '');
    $wantsJson = str_contains($accept, 'application/json') && !str_contains($accept, 'text/html');
    $ok = is_array($result) && !empty($result['ok']);

    if ($wantsJson) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($ok ? 200 : 422);
        echo json_encode($result);
        return;
    }

    $redirect = trim((string)($input['redirect'] ?? ''));
    if ($redirect === '' || !preg_match('#^/admin/ehr(/|$)#', $redirect)) {
        $redirect = '/admin/ehr/portal';
    }
    $sep = str_contains($redirect, '?') ? '&' : '?';
    $notice = $ok ? $okNotice : $errNotice;
    $target = $redirect . $sep . 'notice=' . rawurlencode($notice);
    if (!$ok) {
        $err = is_array($result) ? trim((string)($result['error'] ?? '')) : '';
        if ($err !== '') {
            $target .= '&error=' . rawurlencode($err);
        }
    }
    app()->redirect($target);
}

function portalPageForgotPassword(array $params = []): void
{
    if (portalCurrentSession()) {
        app()->redirect('/portal/dashboard');
        return;
    }
    $input = app()->input();
    echo portalRenderPage('modules/patient-portal/portal/forgot_password.disyl', [
        'page_title' => 'Reset your patient portal password',
        'form_endpoint' => '/portal/forgot-password',
        'login_url' => '/portal/login',
        'notice' => trim((string)($input['notice'] ?? '')),
        'error_message' => trim((string)($input['error'] ?? '')),
    ]);
}

function portalForgotPasswordRequest(array $params = []): void
{
    app()->csrfEnforce();
    $input = app()->input();
    $email = portalNormalizeEmail((string)($input['email'] ?? ''));
    $requesterIp = (string)($_SERVER['REMOTE_ADDR'] ?? '');

    // Always rate-limit by email; treat success and unknown-email the same publicly.
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        app()->redirect('/portal/forgot-password?error=' . urlencode('A valid email is required.'));
        return;
    }
    if (portalRecentFailedAttempts($email) >= 8) {
        app()->redirect('/portal/forgot-password?error=' . urlencode('Too many requests. Please try again later.'));
        return;
    }

    $account = portalFetchAccountByEmail($email);
    if ($account && (string)($account['status'] ?? '') === 'active') {
        try {
            $token = portalCreatePasswordResetToken((int)$account['id'], $requesterIp);
            $resetLink = '/portal/reset-password?token=' . urlencode($token);
            portalAuditRecord('ehr.portal.password_reset.requested', [
                'patient_id' => (int)$account['patient_id'],
                'new_data' => ['account_uuid' => (string)$account['account_uuid']],
            ]);
            // Out-of-band delivery (email) is owned by an integration. Log the link so admins
            // can recover it from app.log if email delivery is not configured yet.
            write_log('Portal password reset link issued for ' . $email . ' -> ' . $resetLink, 'info');
        } catch (\Throwable $e) {
            write_log('portal password reset issuance failed: ' . $e->getMessage(), 'warn');
        }
    }
    portalRecordLoginAttempt($email, false);

    app()->redirect('/portal/forgot-password?notice=sent');
}

function portalPageResetPassword(array $params = []): void
{
    if (portalCurrentSession()) {
        app()->redirect('/portal/dashboard');
        return;
    }
    $input = app()->input();
    $token = trim((string)($input['token'] ?? ''));
    $error = trim((string)($input['error'] ?? ''));

    $valid = false;
    if ($token !== '') {
        $row = portalConsumePasswordResetToken($token);
        $valid = $row !== null;
        if (!$valid && $error === '') {
            $error = 'This reset link is invalid or has expired.';
        }
    }

    echo portalRenderPage('modules/patient-portal/portal/reset_password.disyl', [
        'page_title' => 'Choose a new password',
        'form_endpoint' => '/portal/reset-password',
        'login_url' => '/portal/login',
        'token' => $token,
        'token_valid' => $valid,
        'error_message' => $error,
    ]);
}

function portalResetPassword(array $params = []): void
{
    app()->csrfEnforce();
    $input = app()->input();
    $token = trim((string)($input['token'] ?? ''));
    $password = (string)($input['password'] ?? '');
    $confirm = (string)($input['password_confirm'] ?? '');

    if ($token === '') {
        app()->redirect('/portal/reset-password?error=' . urlencode('Reset link is missing.'));
        return;
    }
    if (strlen($password) < 10) {
        app()->redirect('/portal/reset-password?token=' . urlencode($token) . '&error=' . urlencode('Password must be at least 10 characters.'));
        return;
    }
    if ($password !== $confirm) {
        app()->redirect('/portal/reset-password?token=' . urlencode($token) . '&error=' . urlencode('Passwords do not match.'));
        return;
    }

    $row = portalConsumePasswordResetToken($token);
    if (!$row) {
        app()->redirect('/portal/reset-password?error=' . urlencode('This reset link is invalid or has expired.'));
        return;
    }

    $accountId = (int)$row['account_id'];
    $account = portalFetchAccountById($accountId);
    if (!$account || (string)($account['status'] ?? '') !== 'active') {
        app()->redirect('/portal/reset-password?error=' . urlencode('Account is not available.'));
        return;
    }

    portalUpdateAccountPassword($accountId, $password);
    portalMarkPasswordResetUsed((int)$row['id']);
    portalAuditRecord('ehr.portal.password_reset.completed', [
        'patient_id' => (int)$account['patient_id'],
        'new_data' => ['account_uuid' => (string)$account['account_uuid']],
    ]);

    app()->redirect('/portal/login?notice=password_reset');
}

function portalPageRegister(array $params = []): void
{
    if (portalCurrentSession()) {
        app()->redirect('/portal/dashboard');
        return;
    }
    $input = app()->input();
    echo portalRenderPage('modules/patient-portal/portal/register.disyl', [
        'page_title' => 'Activate your patient portal',
        'form_endpoint' => '/portal/register',
        'login_url' => '/portal/login',
        'notice' => trim((string)($input['notice'] ?? '')),
        'error_message' => trim((string)($input['error'] ?? '')),
        'form_values' => [
            'email' => (string)($input['email'] ?? ''),
            'last_name' => (string)($input['last_name'] ?? ''),
            'birth_date' => (string)($input['birth_date'] ?? ''),
        ],
    ]);
}

function portalRegister(array $params = []): void
{
    app()->csrfEnforce();
    $input = app()->input();
    $email = portalNormalizeEmail((string)($input['email'] ?? ''));
    $lastName = trim((string)($input['last_name'] ?? ''));
    $birthDate = trim((string)($input['birth_date'] ?? ''));
    $password = (string)($input['password'] ?? '');
    $confirm = (string)($input['password_confirm'] ?? '');

    $back = function (string $error) use ($email, $lastName, $birthDate): void {
        $qs = http_build_query([
            'error' => $error,
            'email' => $email,
            'last_name' => $lastName,
            'birth_date' => $birthDate,
        ]);
        app()->redirect('/portal/register?' . $qs);
    };

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $back('A valid email is required.');
        return;
    }
    if ($lastName === '' || $birthDate === '') {
        $back('Last name and date of birth are required.');
        return;
    }
    if (strlen($password) < 10) {
        $back('Password must be at least 10 characters.');
        return;
    }
    if ($password !== $confirm) {
        $back('Passwords do not match.');
        return;
    }
    if (portalRecentFailedAttempts($email) >= 8) {
        $back('Too many attempts. Please try again later.');
        return;
    }

    if (portalFetchAccountByEmail($email)) {
        portalRecordLoginAttempt($email, false);
        // Same response as success to avoid email enumeration
        app()->redirect('/portal/register?notice=submitted');
        return;
    }

    $patient = portalFindPatientForRegistration($email, $lastName, $birthDate);
    if (!$patient) {
        portalRecordLoginAttempt($email, false);
        // Same neutral response to avoid leaking whether a patient exists
        app()->redirect('/portal/register?notice=submitted');
        return;
    }

    $patientId = (int)($patient['id'] ?? 0);
    if ($patientId <= 0 || portalFetchAccountByPatientId($patientId)) {
        portalRecordLoginAttempt($email, false);
        app()->redirect('/portal/register?notice=submitted');
        return;
    }

    $result = app()->cap()->call(
        'ehr.portal.account.provision@1',
        [
            'patient_id' => $patientId,
            'email' => $email,
            'password' => $password,
            'provisioned_by_user_id' => 0,
        ],
        ['caller_module' => 'patient-portal']
    );

    if (!is_array($result) || empty($result['ok'])) {
        write_log('portal self-register provision failed: ' . (string)($result['error'] ?? 'unknown'), 'warn');
        $back('Registration could not be completed. Please contact your clinic.');
        return;
    }

    app()->redirect('/portal/login?notice=registered');
}
