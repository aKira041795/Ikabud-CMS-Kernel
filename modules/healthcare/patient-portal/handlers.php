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

function portalAdminPageIndex(array $params = []): void
{
    if (!function_exists('ehrRequireAdmin') || !function_exists('ehrRender') || !function_exists('ehrAdminContext')) {
        http_response_code(500);
        echo 'EHR admin shell unavailable';
        return;
    }

    $user = ehrRequireAdmin();
    $rows = portalDb()->query(
        'SELECT a.id, a.account_uuid, a.patient_id, a.email, a.status, a.last_login_at, a.created_at, '
        . "p.first_name, p.last_name, p.patient_uuid "
        . 'FROM ehr_portal_accounts a LEFT JOIN ehr_patients p ON p.id = a.patient_id '
        . 'ORDER BY a.created_at DESC LIMIT 100'
    )->fetchAll(\PDO::FETCH_ASSOC) ?: [];

    $context = ehrAdminContext($user, 'ehr_patient_portal', [
        'page_title' => 'Patient Portal',
        'portal_accounts' => $rows,
        'provision_endpoint' => '/admin/ehr/portal/provision',
        'deactivate_endpoint' => '/admin/ehr/portal/deactivate',
    ]);

    echo ehrRender('modules/patient-portal/admin/index.disyl', $context);
}

function portalAdminProvision(array $params = []): void
{
    header('Content-Type: application/json; charset=utf-8');

    if (!function_exists('ehrRequireAdmin')) {
        http_response_code(500);
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

    $code = is_array($result) && !empty($result['ok']) ? 200 : 422;
    http_response_code($code);
    echo json_encode($result);
}

function portalAdminDeactivate(array $params = []): void
{
    header('Content-Type: application/json; charset=utf-8');

    if (!function_exists('ehrRequireAdmin')) {
        http_response_code(500);
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

    $code = is_array($result) && !empty($result['ok']) ? 200 : 422;
    http_response_code($code);
    echo json_encode($result);
}
