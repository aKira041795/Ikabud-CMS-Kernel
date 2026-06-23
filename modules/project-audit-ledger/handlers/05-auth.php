<?php

declare(strict_types=1);

/**
 * Page: Login
 */
function palPageLogin(): void
{
    $user = app()->user();
    if ($user !== null && is_array($user) && palIsModuleUser($user)) {
        app()->redirect('/admin/project-audit-ledger');
        return;
    }

    $context = palLoginPageContext([
        'error' => $_GET['error'] ?? '',
    ]);
    echo app()->render('pages/login.disyl', $context);
}

/**
 * Page: Forgot Password
 */
function palPageForgotPassword(): void
{
    echo app()->render('pages/forgot-password.disyl', palLoginPageContext([
        'page_title' => 'Forgot Password',
        'forgot_password_endpoint' => palBaseUrl() . '/api/v1/project-audit-ledger/auth/forgot-password',
    ]));
}

/**
 * Page: Reset Password
 */
function palPageResetPassword(): void
{
    $token = $_GET['token'] ?? '';
    $tokenValid = false;

    if ($token !== '') {
        $hash = hash('sha256', $token);
        try {
            $db = palDb();
            $stmt = $db->prepare(
                'SELECT id FROM pal_password_resets
                 WHERE token = :hash AND used_at IS NULL AND expires_at > NOW()
                 LIMIT 1'
            );
            $stmt->execute([':hash' => $hash]);
            $tokenValid = (bool)$stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            $tokenValid = false;
        }
    }

    echo app()->render('pages/reset-password.disyl', palLoginPageContext([
        'page_title' => 'Reset Password',
        'login_page_url' => palBaseUrl() . '/project-audit-ledger/login',
        'reset_password_endpoint' => palBaseUrl() . '/api/v1/project-audit-ledger/auth/reset-password',
        'reset_token' => $token,
        'token_valid' => $tokenValid,
    ]));
}

/**
 * Action: Login
 */
function palAuthLogin(): void
{
    // Accept both JSON and form-encoded requests
    $input = [];
    $contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
    if (str_contains($contentType, 'application/json')) {
        $raw = file_get_contents('php://input');
        $parsed = json_decode($raw, true);
        if (is_array($parsed)) {
            $input = $parsed;
        }
    }
    $username = $input['username'] ?? $_POST['username'] ?? '';
    $password = $input['password'] ?? $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $msg = 'Please enter username and password.';
        if (!empty($input)) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => $msg]);
            exit;
        }
        app()->redirect('/project-audit-ledger/login?error=' . urlencode($msg));
        return;
    }

    $db = palDb();
    $currentTenant = (int)(app()->tenant()->current() ?? 0);
    $stmt = $db->prepare(
        'SELECT id, tenant_id, username, email, password_hash, full_name, role, is_active, token_version
         FROM pal_users
         WHERE (username = :username OR email = :email) AND tenant_id = :tenant_id
         LIMIT 1'
    );
    $stmt->execute([':username' => $username, ':email' => $username, ':tenant_id' => $currentTenant]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row || !password_verify($password, $row['password_hash'])) {
        palAudit('pal.auth.login_failed', null, 'pal_users', null, null, ['username' => $username]);
        $msg = 'Invalid credentials.';
        if (!empty($input)) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => $msg]);
            exit;
        }
        app()->redirect('/project-audit-ledger/login?error=' . urlencode($msg));
        return;
    }

    if ((int)$row['is_active'] !== 1) {
        $msg = 'Account is disabled.';
        if (!empty($input)) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => $msg]);
            exit;
        }
        app()->redirect('/project-audit-ledger/login?error=' . urlencode($msg));
        return;
    }

    // Check for bootstrap placeholder password
    if ($row['password_hash'] === '!pal-bootstrap-password-reset-required!') {
        $redirectUrl = '/project-audit-ledger/reset-password?token=' . urlencode($row['username']);
        if (!empty($input)) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => true, 'redirect' => $redirectUrl]);
            exit;
        }
        app()->redirect($redirectUrl);
        return;
    }

    // Issue token
    $tokenVersion = (int)$row['token_version'];
    $payload = [
        'id' => (int)$row['id'],
        'tenant_id' => (int)$row['tenant_id'],
        'username' => $row['username'],
        'email' => $row['email'],
        'full_name' => $row['full_name'],
        'role' => $row['role'],
        'token_version' => $tokenVersion,
        'source' => 'module',
    ];

    $token = app()->jwt()->generate($payload);
    if ($token === null || $token === '') {
        $msg = 'Authentication service unavailable.';
        if (!empty($input)) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => $msg]);
            exit;
        }
        app()->redirect('/project-audit-ledger/login?error=' . urlencode($msg));
        return;
    }

    palSetAuthCookie($token);
    $_SESSION['pal_user'] = $payload;

    // Update last login
    $updateStmt = $db->prepare('UPDATE pal_users SET last_login_at = NOW() WHERE id = :id');
    $updateStmt->execute([':id' => $row['id']]);

    palAudit('pal.auth.login', (int)$row['id'], 'pal_users', (string)$row['id'], null, ['username' => $row['username']]);

    if (!empty($input)) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'redirect' => '/admin/project-audit-ledger']);
        exit;
    }
    app()->redirect('/admin/project-audit-ledger');
}

/**
 * Action: Logout
 */
function palAuthLogout(): void
{
    palClearAuthCookie();
    unset($_SESSION['pal_user']);
    app()->redirect('/project-audit-ledger/login');
}

/**
 * Action: Forgot Password
 */
function palAuthForgotPassword(): void
{
    // Read JSON input (shared kernel template sends JSON via JS fetch)
    $input = [];
    $raw = file_get_contents('php://input');
    $parsed = json_decode($raw, true);
    if (is_array($parsed)) {
        $input = $parsed;
    }
    $identity = $input['identity'] ?? $_POST['identity'] ?? $_POST['email'] ?? '';

    header('Content-Type: application/json');

    if ($identity === '') {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Please enter your username or email.']);
        return;
    }

    // Find user by email or username
    $db = palDb();
    $stmt = $db->prepare('SELECT id, username, email, full_name FROM pal_users WHERE (email = :email OR username = :username) AND is_active = 1 LIMIT 1');
    $stmt->execute([':email' => $identity, ':username' => $identity]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!is_array($user)) {
        echo json_encode(['ok' => true, 'message' => 'If the account exists, a reset link has been sent.']);
        return;
    }

    try {
        // Generate token and store hash
        $token = bin2hex(random_bytes(32));
        $hash = hash('sha256', $token);

        $ins = $db->prepare('INSERT INTO pal_password_resets (tenant_id, user_id, token, expires_at) VALUES (:tid, :uid, :hash, :exp)');
        $ins->execute([
            ':tid' => (int)(app()->tenant()->current() ?? 0),
            ':uid' => (int)$user['id'],
            ':hash' => $hash,
            ':exp' => date('Y-m-d H:i:s', strtotime('+1 hour')),
        ]);

        // Send reset email
        $userEmail = trim((string)($user['email'] ?? ''));
        $resetUrl = palBaseUrl() . '/project-audit-ledger/reset-password?token=' . urlencode($token);
        write_log('Password reset for ' . $identity . ': ' . $resetUrl, 'info');
        if ($userEmail !== '' && filter_var($userEmail, FILTER_VALIDATE_EMAIL)) {
            $name = trim((string)($user['full_name'] ?? $user['username'] ?? 'there'));
            $resetUrl = palBaseUrl() . '/project-audit-ledger/reset-password?token=' . urlencode($token);
            $content = '<p style="margin:0 0 16px;color:#4b5563;font-size:16px;line-height:1.6;">Hi ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . ',</p>'
                . '<p style="margin:0 0 16px;color:#4b5563;font-size:16px;line-height:1.6;">A password reset was requested for your Project Audit Ledger account.</p>'
                . '<p style="margin:0 0 16px;color:#4b5563;font-size:16px;line-height:1.6;">Click the button below to reset your password. This link expires in 1 hour.</p>';
            $body = buildEmailTemplate('Reset Your Password', $content, 'Reset Password', $resetUrl);
            $sent = sendEmail($userEmail, 'Project Audit Ledger — Password Reset', $body);
            if (!$sent) {
                write_log('pal forgot-password email failed for user_id=' . $user['id'], 'error');
            }
        }

        palAudit('pal.auth.forgot_password', null, 'pal_users', (string)$user['id'], null, ['identity' => $identity]);
    } catch (Throwable $e) {
        write_log('pal forgot-password error: ' . $e->getMessage(), 'error');
    }

    echo json_encode(['ok' => true, 'message' => 'If the account exists, a reset link has been sent.']);
}

/**
 * Action: Reset Password
 */
function palAuthResetPassword(): void
{
    header('Content-Type: application/json');

    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true) ?: $_POST;

    $token = trim((string)($input['token'] ?? ''));
    $password = (string)($input['password'] ?? '');
    $confirm = (string)($input['confirm_password'] ?? '');

    if ($token === '' || $password === '' || $confirm === '') {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'All fields are required.']);
        return;
    }

    if ($password !== $confirm) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Passwords do not match.']);
        return;
    }

    if (strlen($password) < 8) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Password must be at least 8 characters.']);
        return;
    }

    $hash = hash('sha256', $token);
    $db = palDb();

    try {
        $db->beginTransaction();

        // Find valid reset record
        $stmt = $db->prepare(
            'SELECT pr.user_id FROM pal_password_resets pr
             WHERE pr.token = :hash AND pr.used_at IS NULL AND pr.expires_at > NOW()
             LIMIT 1'
        );
        $stmt->execute([':hash' => $hash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            $db->rollBack();
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Invalid or expired reset token.']);
            return;
        }

        // Update password
        $newHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        $db->prepare('UPDATE pal_users SET password_hash = :ph, token_version = token_version + 1 WHERE id = :uid')
           ->execute([':ph' => $newHash, ':uid' => (int)$row['user_id']]);

        // Mark token as used
        $db->prepare('UPDATE pal_password_resets SET used_at = NOW() WHERE token = :hash')
           ->execute([':hash' => $hash]);

        $db->commit();

        palAudit('pal.auth.password_reset', (int)$row['user_id'], 'pal_users', (string)$row['user_id'], null, []);

        echo json_encode(['ok' => true, 'message' => 'Password reset successful. Redirecting to login...']);
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        write_log('pal reset-password error: ' . $e->getMessage(), 'error');
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'An error occurred.']);
    }
}
