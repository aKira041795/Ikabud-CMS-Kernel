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
    $patient = portalPatientSummary((int)$session['patient_id']);
    $appointments = portalPatientAppointments((int)$session['patient_id'], 5);

    echo portalRenderPage('modules/patient-portal/portal/dashboard.disyl', [
        'page_title' => 'Your portal',
        'patient' => $patient,
        'session' => $session,
        'upcoming_appointments' => $appointments,
        'logout_endpoint' => '/portal/logout',
    ]);
}

function portalPageAppointments(array $params = []): void
{
    $session = portalRequireSession();
    $patient = portalPatientSummary((int)$session['patient_id']);
    $appointments = portalPatientAppointments((int)$session['patient_id'], 50);

    echo portalRenderPage('modules/patient-portal/portal/appointments.disyl', [
        'page_title' => 'Your appointments',
        'patient' => $patient,
        'session' => $session,
        'appointments' => $appointments,
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
    $context = ehrAdminContext($user, 'ehr_patient_portal', [
        'page_title' => 'Patient Portal',
        'portal_accounts' => $rows,
        'provision_endpoint' => '/admin/ehr/portal/provision',
        'deactivate_endpoint' => '/admin/ehr/portal/deactivate',
        'update_endpoint' => '/admin/ehr/portal/update',
        'reset_password_endpoint' => '/admin/ehr/portal/reset-password',
        'reactivate_endpoint' => '/admin/ehr/portal/reactivate',
        'form_notice' => trim((string)($input['notice'] ?? '')),
        'form_error' => trim((string)($input['error'] ?? '')),
    ]);

    echo ehrRender('modules/patient-portal/admin/index.disyl', $context);
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
