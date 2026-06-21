<?php

declare(strict_types=1);

function awUserHasUnsafeBootstrapPassword(array $row): bool
{
    $hash = (string)($row['password_hash'] ?? '');
    if ($hash === '') return false;
    $blocked = ['!attendance-wage-bootstrap-password-reset-required!'];
    foreach ($blocked as $b) { if ($hash === $b || password_verify($b, $hash)) return true; }
    return false;
}

function awPasswordResetTokenHash(string $token): string { return hash('sha256', $token); }

function awResetTokenIsValid(string $token): bool
{
    if ($token === '') return false;
    try {
        $stmt = aw_db()->prepare('SELECT id, expires_at, used_at FROM attendance_wage_password_resets WHERE token_hash = :h LIMIT 1');
        $stmt->execute([':h' => awPasswordResetTokenHash($token)]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($row) || $row['used_at'] !== null || strtotime($row['expires_at']) < time()) return false;
        return true;
    } catch (\Throwable $e) { return false; }
}

function awCookieName(): string { return 'attendance_wage_token'; }
function awBaseUrl(): string { return rtrim((string)(defined('BASE_URL') ? BASE_URL : ''), '/'); }

function awSetAuthCookie(string $token, int $expiresInSeconds = 86400): void
{
    if (headers_sent()) return;
    setcookie(awCookieName(), $token, [
        'expires' => time() + max(60, $expiresInSeconds),
        'path' => '/', 'httponly' => true, 'secure' => is_https(),
        'samesite' => config('cookie.samesite', 'Lax'),
    ]);
}

function awJson(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

function awRedirect(string $url): void { header('Location: ' . $url); exit; }

// ── Pages ──

function attendancePageLogin(): void { echo app()->render('modules/attendance-wage/auth/login'); }
function attendancePageForgotPassword(): void { echo app()->render('modules/attendance-wage/auth/forgot-password'); }
function attendancePageResetPassword(): void
{
    $token = trim((string)($_GET['token'] ?? ''));
    if ($token === '' || !awResetTokenIsValid($token)) awRedirect(awBaseUrl() . '/attendance-wage/forgot-password?error=invalid_token');
    echo app()->render('modules/attendance-wage/auth/reset-password', ['reset_token' => $token]);
}

// ── POST: Login ──

function attendanceAuthLogin(): void
{
    app()->csrfEnforce();

    // Rate limiting
    $rateLimit = kernelConsumeLoginRateLimit('attendance-wage');
    if ($rateLimit['blocked'] ?? false) {
        awRedirect(awBaseUrl() . '/attendance-wage/login?error=too_many_attempts');
    }

    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    if ($username === '' || $password === '') awRedirect(awBaseUrl() . '/attendance-wage/login?error=missing_fields');
    $result = aw_cap_kernel_auth_authenticate_1(['username' => '@attendance-wage:' . $username, 'password' => $password]);
    $user = is_array($result) ? ($result['user'] ?? null) : null;
    if (!is_array($user) || (($result['source'] ?? '') !== 'attendance-wage')) awRedirect(awBaseUrl() . '/attendance-wage/login?error=invalid_credentials');
    $expiry = (int)config('jwt.expiration', 86400);
    $token = app()->jwt()->generate(['sub' => $user['sub'], 'role' => $user['role'] ?? 'employee', 'source' => $result['source'] ?? 'attendance-wage', 'exp' => time() + $expiry]);
    awSetAuthCookie($token, $expiry);
    awRedirect(awBaseUrl() . '/admin/wage');
}

// ── POST: Forgot Password ──

function attendanceAuthForgotPassword(): void
{
    app()->csrfEnforce();

    // Rate limiting: max 3 forgot-password requests per 15 minutes
    $rlId = 'attendance-wage:forgot:' . ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
    $rateLimit = kernelRateLimit($rlId, 3, 900);
    if ($rateLimit['blocked'] ?? false) {
        awRedirect(awBaseUrl() . '/attendance-wage/forgot-password?error=too_many_attempts');
    }

    $email = trim((string)($_POST['email'] ?? ''));
    if ($email === '') awRedirect(awBaseUrl() . '/attendance-wage/forgot-password?error=missing_fields');
    try {
        $stmt = aw_db()->prepare('SELECT id, full_name FROM attendance_wage_users WHERE email = :email AND is_active = 1 LIMIT 1');
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (is_array($user)) {
            $token = bin2hex(random_bytes(32));
            $stmt = aw_db()->prepare('INSERT INTO attendance_wage_password_resets (user_id, token_hash, requester_ip, expires_at) VALUES (:uid, :hash, :ip, :exp)');
            $stmt->execute([':uid' => $user['id'], ':hash' => awPasswordResetTokenHash($token), ':ip' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1', ':exp' => date('Y-m-d H:i:s', strtotime('+1 hour'))]);
            write_log('Password reset for ' . $email . ': ' . awBaseUrl() . '/attendance-wage/reset-password?token=' . urlencode($token), 'info');
        }
        awRedirect(awBaseUrl() . '/attendance-wage/forgot-password?message=sent');
    } catch (\Throwable $e) { awRedirect(awBaseUrl() . '/attendance-wage/forgot-password?error=system'); }
}

function awInput(): array
{
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (str_contains($contentType, 'application/json')) {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }
    return $_POST;
}

// ── POST: Forgot Password (API endpoint — returns JSON) ──

function attendanceApiForgotPassword(): void
{
    app()->csrfEnforce();
    $input = awInput();
    $email = trim((string)($input['email'] ?? ''));
    if ($email === '') awJson(['ok' => false, 'error' => 'Email is required'], 422);
    try {
        $stmt = aw_db()->prepare('SELECT id, username, email, full_name FROM attendance_wage_users WHERE email = :email AND is_active = 1 LIMIT 1');
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($user)) awJson(['ok' => true, 'message' => 'If the email exists, a reset link has been sent.']);
        $token = bin2hex(random_bytes(32));
        $stmt = aw_db()->prepare('INSERT INTO attendance_wage_password_resets (user_id, token_hash, requester_ip, expires_at) VALUES (:uid, :hash, :ip, :exp)');
        $stmt->execute([':uid' => $user['id'], ':hash' => awPasswordResetTokenHash($token), ':ip' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1', ':exp' => date('Y-m-d H:i:s', strtotime('+1 hour'))]);

        // Send reset email
        $userEmail = trim((string)($user['email'] ?? ''));
        if ($userEmail !== '' && filter_var($userEmail, FILTER_VALIDATE_EMAIL)) {
            $name = trim((string)($user['full_name'] ?? $user['username'] ?? 'there'));
            $resetUrl = awBaseUrl() . '/attendance-wage/reset-password?token=' . urlencode($token);
            $content = '<p style="margin:0 0 16px;color:#4b5563;font-size:16px;line-height:1.6;">Hi ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . ',</p>'
                . '<p style="margin:0 0 16px;color:#4b5563;font-size:16px;line-height:1.6;">A request was made to reset your Attendance &amp; Wage password.</p>'
                . '<p style="margin:0 0 16px;color:#4b5563;font-size:16px;line-height:1.6;">This link expires in 1 hour. If you did not request this, you can safely ignore this email.</p>';
            $body = buildEmailTemplate('Reset Your ZAP Password', $content, 'Reset Password', $resetUrl);
            $sent = sendEmail($userEmail, 'ZAP Attendance & Wage — Password Reset', $body);
            if (!$sent) {
                write_log('attendance-wage forgot-password email dispatch failed for user_id=' . $user['id'], 'error');
            }
        }

        awJson(['ok' => true, 'message' => 'If the email exists, a reset link has been sent.']);
    } catch (\Throwable $e) { awJson(['ok' => false, 'error' => 'An error occurred.'], 500); }
}

// ── POST: Reset Password ──

function attendanceApiResetPassword(): void
{
    app()->csrfEnforce();
    $input = awInput();
    $token = trim((string)($input['token'] ?? ''));
    $password = (string)($input['password'] ?? '');
    $confirm = (string)($input['password_confirm'] ?? '');
    if ($token === '' || $password === '' || $confirm === '') awJson(['ok' => false, 'error' => 'All fields are required'], 422);
    if ($password !== $confirm) awJson(['ok' => false, 'error' => 'Passwords do not match'], 422);
    if (strlen($password) < 8) awJson(['ok' => false, 'error' => 'Password must be at least 8 characters'], 422);
    if (!awResetTokenIsValid($token)) awJson(['ok' => false, 'error' => 'Invalid or expired reset token'], 422);
    try {
        $hash = awPasswordResetTokenHash($token);
        $db = aw_db(); $db->beginTransaction();
        $stmt = $db->prepare('SELECT pr.user_id FROM attendance_wage_password_resets pr WHERE pr.token_hash = :h AND pr.used_at IS NULL AND pr.expires_at > NOW() LIMIT 1');
        $stmt->execute([':h' => $hash]); $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($row)) { $db->rollBack(); awJson(['ok' => false, 'error' => 'Invalid or expired reset token'], 422); }
        $db->prepare('UPDATE attendance_wage_users SET password_hash = :ph WHERE id = :uid')->execute([':ph' => password_hash($password, PASSWORD_BCRYPT), ':uid' => (int)$row['user_id']]);
        $db->prepare('UPDATE attendance_wage_password_resets SET used_at = NOW() WHERE token_hash = :h')->execute([':h' => $hash]);
        $db->commit();
        awJson(['ok' => true, 'message' => 'Password reset successful.']);
    } catch (\Throwable $e) {
        if (isset($db) && $db->inTransaction()) $db->rollBack();
        awJson(['ok' => false, 'error' => 'An error occurred.'], 500);
    }
}

// ── Logout ──

function attendanceLogout(): void
{
    if (!headers_sent()) {
        setcookie(awCookieName(), '', ['expires' => time() - 3600, 'path' => '/', 'httponly' => true, 'secure' => is_https(), 'samesite' => config('cookie.samesite', 'Lax')]);
    }
    awRedirect(awBaseUrl() . '/attendance-wage/login');
}
