<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

function ehrPageLogin(array $params = []): void
{
    if (ehrRedirectAuthenticatedAuthUser()) {
        return;
    }

    echo app()->render('pages/login.disyl', ehrLoginPageContext([
        'login_page_url' => ehrBaseUrl() . '/ehr/login',
    ]));
}

function ehrForgotPasswordPage(array $params = []): void
{
    if (ehrRedirectAuthenticatedAuthUser()) {
        return;
    }

    echo app()->render('pages/forgot-password.disyl', ehrLoginPageContext([
        'page_title' => 'Forgot Password',
        'forgot_password_endpoint' => ehrBaseUrl() . '/api/v1/ehr/auth/forgot-password',
        'login_page_url' => ehrBaseUrl() . '/login',
    ]));
}

function ehrResetPasswordPage(array $params = []): void
{
    if (ehrRedirectAuthenticatedAuthUser()) {
        return;
    }

    $token = trim((string)($_GET['token'] ?? ''));

    echo app()->render('pages/reset-password.disyl', ehrLoginPageContext([
        'page_title' => 'Reset Password',
        'reset_password_endpoint' => ehrBaseUrl() . '/api/v1/ehr/auth/reset-password',
        'login_page_url' => ehrBaseUrl() . '/login',
        'reset_token' => $token,
        'token_valid' => ehrResetTokenIsValid($token),
    ]));
}

function ehrAuthLogin(array $params = []): void
{
    header('Content-Type: application/json; charset=utf-8');

    if (function_exists('kernelEmitLoginRateLimitJson')) {
        $rateLimit = kernelConsumeLoginRateLimit('ehr');
        if (!empty($rateLimit['limited'])) {
            kernelEmitLoginRateLimitJson($rateLimit);
            return;
        }
    }

    $input = app()->input();
    $username = trim((string)($input['username'] ?? ''));
    $password = (string)($input['password'] ?? '');

    if ($username === '' || $password === '') {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Username and password are required.']);
        return;
    }

    try {
        $auth = ehr_cap_kernel_auth_authenticate_1([
            'username' => '@ehr:' . $username,
            'password' => $password,
        ]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Authentication temporarily unavailable.']);
        return;
    }

    if (!is_array($auth) || !is_array($auth['user'] ?? null) || (($auth['source'] ?? '') !== 'ehr')) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Invalid username or password.']);
        return;
    }

    $user = $auth['user'];
    $payload = [
        'sub' => (string)($user['sub'] ?? ('ehr:' . (int)($user['id'] ?? 0))),
        'id' => (int)($user['id'] ?? 0),
        'username' => (string)($user['username'] ?? $username),
        'name' => (string)($user['full_name'] ?? $username),
        'email' => (string)($user['email'] ?? ''),
        'role' => (string)($user['role'] ?? 'admin'),
        'source' => 'ehr',
        'token_version' => (int)($user['token_version'] ?? 0),
    ];

    $tenantId = app()->tenant()->current();
    if ($tenantId !== null) {
        $payload['tenant_id'] = $tenantId;
    }

    $token = app()->jwt()->generate($payload);
    ehrSetAuthCookie($token, (int)config('app.jwt.expiration', 86400));
    app()->csrfRotate(true);

    $redirect = kernelResolveAuthenticatedHomeRedirect($payload, true) ?? '/admin/ehr';
    echo json_encode(['ok' => true, 'redirect' => $redirect]);
}

function ehrLogout(array $params = []): void
{
    app()->csrfEnforce();
    ehrClearAuthCookie();
    app()->redirect('/login');
}

function ehrApiForgotPassword(array $params = []): void
{
    $policy = kernel_password_reset_policy();
    $ttlMinutes = max(1, (int)$policy['token_ttl_minutes']);
    $input = app()->input();
    $identity = trim((string)($input['identity'] ?? ''));
    if ($identity === '') {
        app()->json(['ok' => false, 'error' => 'Username or email is required.'], 422);
        return;
    }

    $requestIp = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    if (ehrForgotPasswordRateLimitExceeded($requestIp, $identity)) {
        app()->json(['ok' => false, 'error' => (string)$policy['forgot_rate_limit_message']], 429);
        return;
    }

    ehrForgotPasswordRateLimitRecord($requestIp, $identity);

    try {
        $stmt = ehrDb()->prepare(
            'SELECT id, username, email, full_name
             FROM ehr_users
             WHERE (username = :username OR email = :email)
               AND is_active = 1
             LIMIT 1'
        );
        $stmt->execute([
            ':username' => $identity,
            ':email' => $identity,
        ]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (is_array($user)) {
            $rawToken = bin2hex(random_bytes(32));
            $tokenHash = ehrPasswordResetTokenHash($rawToken);

            $clear = ehrDb()->prepare(
                'UPDATE ehr_password_resets
                 SET used_at = NOW()
                 WHERE user_id = :user_id
                   AND used_at IS NULL'
            );
            $clear->execute([':user_id' => (int)$user['id']]);

            $insert = ehrDb()->prepare(
                'INSERT INTO ehr_password_resets (user_id, token_hash, requester_ip, expires_at, created_at)
                 VALUES (:user_id, :token_hash, :requester_ip, DATE_ADD(NOW(), INTERVAL ' . $ttlMinutes . ' MINUTE), NOW())'
            );
            $insert->execute([
                ':user_id' => (int)$user['id'],
                ':token_hash' => $tokenHash,
                ':requester_ip' => $requestIp,
            ]);

            $email = trim((string)($user['email'] ?? ''));
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) && function_exists('buildEmailTemplate') && function_exists('sendEmail')) {
                $name = trim((string)($user['full_name'] ?? $user['username'] ?? 'there'));
                $resetUrl = ehrExternalBaseUrl() . '/reset-password?token=' . urlencode($rawToken);
                $content = '<p style="margin:0 0 16px;color:#4b5563;font-size:16px;line-height:1.6;">Hi ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . ',</p>'
                    . '<p style="margin:0 0 16px;color:#4b5563;font-size:16px;line-height:1.6;">A request was made to reset your ' . htmlspecialchars(ehrAppName(), ENT_QUOTES, 'UTF-8') . ' password.</p>'
                    . '<p style="margin:0 0 16px;color:#4b5563;font-size:16px;line-height:1.6;">This link expires in ' . $ttlMinutes . ' minutes. If you did not request this, you can safely ignore this email.</p>';
                $body = buildEmailTemplate('Reset Your EHR Password', $content, 'Reset Password', $resetUrl);
                $sent = sendEmail($email, ehrAppName() . ' Password Reset', $body);
                if (!$sent) {
                    write_log('ehr forgot-password email dispatch failed for user_id=' . (string)$user['id'], 'error');
                }
            }
        }

        app()->json([
            'ok' => true,
            'message' => (string)$policy['forgot_success_message'],
        ]);
    } catch (Throwable $e) {
        write_log('ehr forgot-password failed: ' . $e->getMessage(), 'error');
        app()->json(['ok' => false, 'error' => 'Unable to process request right now.'], 500);
    }
}

function ehrApiResetPassword(array $params = []): void
{
    $policy = kernel_password_reset_policy();
    $input = app()->input();
    $token = trim((string)($input['token'] ?? ''));
    $password = (string)($input['password'] ?? '');
    $confirmPassword = (string)($input['confirm_password'] ?? '');

    if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) {
        app()->json(['ok' => false, 'error' => (string)$policy['invalid_token_message']], 422);
        return;
    }
    if (strlen($password) < 8) {
        app()->json(['ok' => false, 'error' => 'Password must be at least 8 characters.'], 422);
        return;
    }
    if ($password !== $confirmPassword) {
        app()->json(['ok' => false, 'error' => 'Passwords do not match.'], 422);
        return;
    }

    $requestIp = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    if (ehrResetPasswordRateLimitExceeded($requestIp)) {
        app()->json(['ok' => false, 'error' => (string)$policy['reset_rate_limit_message']], 429);
        return;
    }

    ehrResetPasswordRateLimitRecord($requestIp);

    try {
        $tokenHash = ehrPasswordResetTokenHash($token);
        $stmt = ehrDb()->prepare(
            'SELECT pr.id AS reset_id, pr.user_id
             FROM ehr_password_resets pr
             INNER JOIN ehr_users eu ON eu.id = pr.user_id
             WHERE pr.token_hash = :token_hash
               AND pr.used_at IS NULL
               AND pr.expires_at > NOW()
               AND eu.is_active = 1
             LIMIT 1'
        );
        $stmt->execute([':token_hash' => $tokenHash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            app()->json(['ok' => false, 'error' => (string)$policy['invalid_token_message']], 422);
            return;
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $updateUser = ehrDb()->prepare(
            'UPDATE ehr_users
             SET password_hash = :password_hash,
                 token_version = COALESCE(token_version, 0) + 1,
                 updated_at = NOW()
             WHERE id = :user_id'
        );
        $updateUser->execute([
            ':password_hash' => $hash,
            ':user_id' => (int)$row['user_id'],
        ]);

        $updateReset = ehrDb()->prepare(
            'UPDATE ehr_password_resets
             SET used_at = NOW()
             WHERE user_id = :user_id
               AND used_at IS NULL'
        );
        $updateReset->execute([':user_id' => (int)$row['user_id']]);

        app()->json([
            'ok' => true,
            'message' => (string)$policy['reset_success_message'],
            'redirect' => '/login',
        ]);
    } catch (Throwable $e) {
        write_log('ehr reset-password failed: ' . $e->getMessage(), 'error');
        app()->json(['ok' => false, 'error' => 'Unable to reset password right now.'], 500);
    }
}

function ehrSettingsPage(array $params = []): void
{
    $user = ehrRequireAdmin();
    $settings = ehrModuleSettings();

    echo ehrRender('admin/settings.disyl', array_merge(
        ehrAdminContext($user, 'ehr_settings', [
            'page_title' => 'Branding & Access',
        ]),
        [
            'base_url' => ehrBaseUrl(),
            'settings' => [
                'app_name' => (string)($settings['app_name'] ?? ehrAppName()),
                'login_subtitle' => (string)($settings['login_subtitle'] ?? ehrLoginSubtitle()),
                'logo_url' => ehrLogoUrl(),
                'favicon_url' => ehrFaviconUrl(),
                'resolved_favicon_url' => ehrResolvedFaviconUrl(),
                'brand_initial' => ehrBrandInitial(),
            ],
            'forgot_password_url' => ehrBaseUrl() . '/forgot-password',
            'login_url' => ehrBaseUrl() . '/login',
        ]
    ));
}

function ehrDashboardPage(array $params = []): void
{
    $user = ehrRequireAdmin();
    $navItems = ehrAdminNavItems($user);
    $navGroups = ehrDashboardNavGroups($navItems);
    $summary = ehrDashboardSummary();

    echo ehrRender('admin/dashboard.disyl', array_merge(
        ehrAdminContext($user, 'ehr_dashboard', [
            'page_title' => 'Today at a Glance',
        ]),
        [
            'workspace_nav_items' => $navGroups['workspace'],
            'workspace_nav_groups' => $navGroups['workspace_groups'],
            'admin_nav_items' => $navGroups['administration'],
            'workspace_item_count' => count($navGroups['workspace']),
            'today_label' => date('l, F j, Y'),
            'today_iso' => date('Y-m-d'),
            'summary' => $summary,
            'patient_flow' => ehrDashboardPatientFlow($summary),
            'worklists' => ehrDashboardWorklists($summary),
            'quick_actions' => ehrDashboardQuickActions(),
        ]
    ));
}

function ehrApiUploadBrandingAsset(array $params = []): void
{
    ehrRequireAdmin();
    $assetType = strtolower(trim((string)($_POST['asset_type'] ?? '')));
    $file = kernelUploadedFile('asset_file');
    if (!is_array($file)) {
        app()->json(['ok' => false, 'error' => 'Upload a branding image first.'], 422);
        return;
    }

    try {
        $upload = ehrUploadBrandAsset($assetType, $file);
    } catch (InvalidArgumentException $e) {
        app()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        return;
    } catch (Throwable $e) {
        write_log('ehr branding upload failed: ' . $e->getMessage(), 'error');
        app()->json(['ok' => false, 'error' => 'Failed to upload branding asset.'], 500);
        return;
    }

    $settingKey = $assetType === 'favicon' ? 'favicon_url' : 'logo_url';
    if (!ehrPersistModuleSettings([$settingKey => $upload['asset_url']])) {
        app()->json(['ok' => false, 'error' => 'Branding asset uploaded but could not be persisted to settings.'], 500);
        return;
    }

    app()->json([
        'ok' => true,
        'asset_type' => $assetType,
        'asset_url' => $upload['asset_url'],
        'message' => ucfirst($assetType) . ' uploaded.',
    ]);
}

function ehrApiSaveSettings(array $params = []): void
{
    ehrRequireAdmin();
    $input = app()->input();

    $appNameInput = trim((string)($input['app_name'] ?? ''));
    $appName = $appNameInput !== '' ? (function_exists('mb_substr') ? mb_substr($appNameInput, 0, 120) : substr($appNameInput, 0, 120)) : 'EHR Suite';
    $loginSubtitleInput = trim((string)($input['login_subtitle'] ?? ''));
    $loginSubtitle = $loginSubtitleInput !== '' ? (function_exists('mb_substr') ? mb_substr($loginSubtitleInput, 0, 280) : substr($loginSubtitleInput, 0, 280)) : ehrLoginSubtitle();

    try {
        $logoUrl = ehrNormalizeBrandAssetUrl($input['logo_url'] ?? '', 'Logo URL');
        $faviconUrl = ehrNormalizeBrandAssetUrl($input['favicon_url'] ?? '', 'Favicon URL');
    } catch (InvalidArgumentException $e) {
        app()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        return;
    }

    $settingsToSave = [
        'app_name' => $appName,
        'login_subtitle' => $loginSubtitle,
        'logo_url' => $logoUrl,
        'favicon_url' => $faviconUrl,
    ];

    if (!ehrPersistModuleSettings($settingsToSave)) {
        app()->json(['ok' => false, 'error' => 'Failed to persist EHR settings.'], 500);
        return;
    }

    app()->json([
        'ok' => true,
        'message' => 'EHR settings updated.',
        'settings' => array_merge($settingsToSave, [
            'resolved_favicon_url' => $faviconUrl !== '' ? $faviconUrl : ehrDefaultFaviconUrl(),
            'brand_initial' => ehrBrandInitial(),
        ]),
    ]);
}

function ehrPatientContextSet(array $params = []): void
{
    ehrRequireAdmin();
    app()->csrfEnforce();
    $input = app()->input();
    $patientId = (int)($input['patient_id'] ?? 0);
    $encounterId = (int)($input['encounter_id'] ?? 0);
    if ($patientId <= 0) {
        app()->json(['ok' => false, 'error' => 'patient_id is required'], 422);
        return;
    }
    ehrSetPatientContext($patientId, $encounterId);
    $redirect = (string)($input['redirect'] ?? ('/admin/ehr/patients/' . $patientId . '/chart'));
    if (!str_starts_with($redirect, '/')) $redirect = '/admin/ehr';
    app()->redirect($redirect);
}

function ehrPatientContextClear(array $params = []): void
{
    ehrRequireAdmin();
    app()->csrfEnforce();
    ehrClearPatientContext();
    $back = (string)($_SERVER['HTTP_REFERER'] ?? '/admin/ehr');
    if (!str_starts_with($back, '/admin/ehr')) $back = '/admin/ehr';
    app()->redirect($back);
}

function ehrPatientChartPage(array $params = []): void
{
    $user = ehrRequireAdmin();
    $patientId = (int)($params['id'] ?? 0);
    if ($patientId <= 0) {
        http_response_code(404);
        echo 'Patient not found';
        return;
    }

    $patient = ehrSafeCap('ehr.patient.view@1', ['id' => $patientId]);
    $patientRow = is_array($patient['patient'] ?? null) ? $patient['patient'] : null;
    if (!$patientRow) {
        http_response_code(404);
        echo 'Patient not found';
        return;
    }

    // Make this the current working patient.
    ehrSetPatientContext($patientId, (int)(ehrCurrentPatientContext()['encounter_id'] ?? 0));

    // Recent visits
    $encList = ehrSafeCap('ehr.encounter.list@1', ['limit' => 25]);
    $encounters = is_array($encList['encounters'] ?? null) ? $encList['encounters'] : [];
    $recentVisits = array_values(array_filter($encounters, static fn($e) => is_array($e) && (int)($e['patient_id'] ?? 0) === $patientId));

    // Pull patient-scoped lists
    $notes = ehrSafeCap('ehr.note.list@1', ['patient_id' => $patientId, 'limit' => 5]);
    $orders = ehrSafeCap('ehr.order.list@1', ['patient_id' => $patientId, 'limit' => 5]);
    $results = ehrSafeCap('ehr.result.list@1', ['patient_id' => $patientId, 'limit' => 5]);
    $rx = ehrSafeCap('ehr.prescription.list@1', ['patient_id' => $patientId, 'limit' => 5]);
    $docs = ehrSafeCap('ehr.document.list@1', ['patient_id' => $patientId, 'limit' => 5]);
    $consent = ehrSafeCap('ehr.consent.active@1', ['patient_id' => $patientId, 'consent_type' => 'general']);

    $first = trim((string)($patientRow['first_name'] ?? ''));
    $last = trim((string)($patientRow['last_name'] ?? ''));
    $name = trim($first . ' ' . $last);
    if ($name === '') $name = 'Patient #' . $patientId;

    echo ehrRender('admin/patient_chart.disyl', array_merge(
        ehrAdminContext($user, 'ehr_patient_chart', [
            'page_title' => $name,
        ]),
        [
            'patient' => $patientRow,
            'patient_name' => $name,
            'recent_visits' => array_slice($recentVisits, 0, 6),
            'recent_visits_count' => count($recentVisits),
            'recent_notes' => is_array($notes['notes'] ?? null) ? $notes['notes'] : [],
            'recent_orders' => is_array($orders['orders'] ?? null) ? $orders['orders'] : [],
            'recent_results' => is_array($results['results'] ?? null) ? $results['results'] : [],
            'recent_medications' => is_array($rx['prescriptions'] ?? null) ? $rx['prescriptions'] : [],
            'recent_documents' => is_array($docs['documents'] ?? null) ? $docs['documents'] : [],
            'consent_active' => !empty($consent['active']),
            'tabs' => [
                ['key' => 'summary', 'label' => 'Summary', 'url' => '/admin/ehr/patients/' . $patientId . '/chart', 'active' => true],
                ['key' => 'visits', 'label' => 'Visits', 'url' => '/admin/ehr/encounters?patient_id=' . $patientId, 'active' => false],
                ['key' => 'notes', 'label' => 'Notes', 'url' => '/admin/ehr/notes?patient_id=' . $patientId, 'active' => false],
                ['key' => 'orders', 'label' => 'Orders', 'url' => '/admin/ehr/orders?patient_id=' . $patientId, 'active' => false],
                ['key' => 'results', 'label' => 'Results', 'url' => '/admin/ehr/results?patient_id=' . $patientId, 'active' => false],
                ['key' => 'medications', 'label' => 'Medications', 'url' => '/admin/ehr/prescriptions?patient_id=' . $patientId, 'active' => false],
                ['key' => 'documents', 'label' => 'Documents', 'url' => '/admin/ehr/documents?patient_id=' . $patientId, 'active' => false],
            ],
        ]
    ));
}

function ehrUsersListPage(array $params = []): void
{
    $user = ehrRequireAdmin();
    $input = app()->input();
    $notice = trim((string)($input['notice'] ?? ''));
    $error = trim((string)($input['error'] ?? ''));

    $rows = ehrDb()->query(
        'SELECT id, username, email, full_name, role, is_active, created_at, updated_at FROM ehr_users ORDER BY is_active DESC, full_name ASC, username ASC LIMIT 200'
    )->fetchAll(PDO::FETCH_ASSOC);
    $users = is_array($rows) ? $rows : [];

    $activeCount = 0;
    $inactiveCount = 0;
    foreach ($users as $u) {
        if ((int)($u['is_active'] ?? 0) === 1) {
            $activeCount++;
        } else {
            $inactiveCount++;
        }
    }

    echo ehrRender('admin/users.disyl', array_merge(
        ehrAdminContext($user, 'ehr_users', ['page_title' => 'Users']),
        [
            'base_url' => ehrBaseUrl(),
            'users' => $users,
            'user_count' => count($users),
            'active_count' => $activeCount,
            'inactive_count' => $inactiveCount,
            'current_user_id' => (int)($user['id'] ?? 0),
            'form_notice' => $notice,
            'form_error' => $error !== '' ? $error : null,
            'form_values' => [
                'username' => (string)($input['username'] ?? ''),
                'email' => (string)($input['email'] ?? ''),
                'full_name' => (string)($input['full_name'] ?? ''),
                'role' => (string)($input['role'] ?? 'admin'),
            ],
            'role_options' => ['admin', 'clinician', 'nurse', 'reception', 'billing'],
        ]
    ));
}

function ehrUsersCreate(array $params = []): void
{
    ehrRequireAdmin();
    if (function_exists('csrfEnforce')) {
        csrfEnforce();
    }
    $input = app()->input();
    $username = strtolower(trim((string)($input['username'] ?? '')));
    $email = strtolower(trim((string)($input['email'] ?? '')));
    $fullName = trim((string)($input['full_name'] ?? ''));
    $role = strtolower(trim((string)($input['role'] ?? 'admin')));
    $password = (string)($input['password'] ?? '');

    if ($username === '' || !preg_match('/^[a-z0-9._-]{3,64}$/', $username)) {
        app()->redirect('/admin/ehr/users?error=' . urlencode('Username must be 3-64 chars (a-z, 0-9, . _ -).'));
        return;
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        app()->redirect('/admin/ehr/users?error=' . urlencode('Valid email is required.'));
        return;
    }
    if (strlen($password) < 8) {
        app()->redirect('/admin/ehr/users?error=' . urlencode('Password must be at least 8 characters.'));
        return;
    }
    if ($role === '') {
        $role = 'admin';
    }

    $existsStmt = ehrDb()->prepare('SELECT id FROM ehr_users WHERE username = :u OR email = :e LIMIT 1');
    $existsStmt->execute([':u' => $username, ':e' => $email]);
    if ($existsStmt->fetch(PDO::FETCH_ASSOC)) {
        app()->redirect('/admin/ehr/users?error=' . urlencode('Username or email already exists.'));
        return;
    }

    $hash = password_hash($password, PASSWORD_BCRYPT);
    $stmt = ehrDb()->prepare(
        'INSERT INTO ehr_users (username, email, password_hash, full_name, role, is_active, token_version, created_at, updated_at) '
        . 'VALUES (:username, :email, :password_hash, :full_name, :role, 1, 0, NOW(), NOW())'
    );
    $stmt->execute([
        ':username' => $username,
        ':email' => $email,
        ':password_hash' => $hash,
        ':full_name' => $fullName,
        ':role' => $role,
    ]);

    app()->redirect('/admin/ehr/users?notice=created');
}

function ehrUsersToggleActive(array $params = []): void
{
    $actor = ehrRequireAdmin();
    if (function_exists('csrfEnforce')) {
        csrfEnforce();
    }
    $input = app()->input();
    $userId = max(0, (int)($input['user_id'] ?? 0));
    if ($userId <= 0) {
        app()->redirect('/admin/ehr/users?error=' . urlencode('User is required.'));
        return;
    }
    if ($userId === (int)($actor['id'] ?? 0)) {
        app()->redirect('/admin/ehr/users?error=' . urlencode('You cannot change your own status.'));
        return;
    }

    $stmt = ehrDb()->prepare('SELECT id, is_active FROM ehr_users WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        app()->redirect('/admin/ehr/users?error=' . urlencode('User not found.'));
        return;
    }

    $newActive = (int)$row['is_active'] === 1 ? 0 : 1;
    $update = ehrDb()->prepare(
        'UPDATE ehr_users SET is_active = :active, token_version = token_version + 1, updated_at = NOW() WHERE id = :id'
    );
    $update->execute([':active' => $newActive, ':id' => $userId]);

    app()->redirect('/admin/ehr/users?notice=' . ($newActive === 1 ? 'activated' : 'deactivated'));
}

function ehrUsersResetPassword(array $params = []): void
{
    $actor = ehrRequireAdmin();
    if (function_exists('csrfEnforce')) {
        csrfEnforce();
    }
    $input = app()->input();
    $userId = max(0, (int)($input['user_id'] ?? 0));
    $newPassword = (string)($input['new_password'] ?? '');
    if ($userId <= 0) {
        app()->redirect('/admin/ehr/users?error=' . urlencode('User is required.'));
        return;
    }
    if (strlen($newPassword) < 8) {
        app()->redirect('/admin/ehr/users?error=' . urlencode('New password must be at least 8 characters.'));
        return;
    }

    $hash = password_hash($newPassword, PASSWORD_BCRYPT);
    $stmt = ehrDb()->prepare(
        'UPDATE ehr_users SET password_hash = :hash, token_version = token_version + 1, updated_at = NOW() WHERE id = :id'
    );
    $stmt->execute([':hash' => $hash, ':id' => $userId]);

    app()->redirect('/admin/ehr/users?notice=password_reset');
}
