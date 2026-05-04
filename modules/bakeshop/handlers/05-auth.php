<?php

declare(strict_types=1);

function bakeshopLoginRateLimitState(?int $maxAttempts = null, ?int $windowSeconds = null): array
{
    return kernelConsumeLoginRateLimit('bakeshop', $maxAttempts, $windowSeconds);
}

function bakeshopAuthHintPresent(): bool
{
    $kernelJwtCookie = (string)config('app.jwt.cookie', 'token');

    return isset($_SERVER['HTTP_AUTHORIZATION'])
        || isset($_COOKIE[bakeshopCookieName()])
        || isset($_COOKIE[$kernelJwtCookie]);
}

function bakeshopRedirectAuthenticatedAuthUser(): bool
{
    if (!bakeshopAuthHintPresent()) {
        return false;
    }

    $user = app()->user();
    if (!is_array($user) || (!bakeshopIsModuleUser($user) && !bakeshopIsKernelSuperadmin($user))) {
        return false;
    }

    $home = kernelResolveAuthenticatedHomeRedirect($user, true) ?? '/admin/bakeshop';
    app()->redirect($home);
    return true;
}

function bakeshopPasswordResetTokenHash(string $token): string
{
    return hash('sha256', $token);
}

function bakeshopForgotPasswordRateLimitSnapshot(string $scope, string $value): array
{
    $normalized = strtolower(trim($value));
    if ($normalized === '') {
        $normalized = 'unknown';
    }

    $key = 'bakeshop_forgot_password:' . $scope . ':' . sha1($normalized);
    $cached = app()->cache()->get('security_rate_limits', $key);
    if (!is_array($cached)) {
        return ['key' => $key, 'count' => 0];
    }

    return [
        'key' => $key,
        'count' => max(0, (int)($cached['count'] ?? 0)),
    ];
}

function bakeshopForgotPasswordRateLimitExceeded(string $ip, string $identity): bool
{
    $policy = kernel_password_reset_policy();
    $ipState = bakeshopForgotPasswordRateLimitSnapshot('ip', $ip !== '' ? $ip : 'unknown');
    if ((int)$ipState['count'] >= (int)$policy['forgot_rate_limit_ip_max']) {
        return true;
    }

    $identityState = bakeshopForgotPasswordRateLimitSnapshot('identity', $identity);
    return (int)$identityState['count'] >= (int)$policy['forgot_rate_limit_identity_max'];
}

function bakeshopForgotPasswordRateLimitRecord(string $ip, string $identity): void
{
    $policy = kernel_password_reset_policy();
    $entries = [
        bakeshopForgotPasswordRateLimitSnapshot('ip', $ip !== '' ? $ip : 'unknown'),
        bakeshopForgotPasswordRateLimitSnapshot('identity', $identity),
    ];

    foreach ($entries as $entry) {
        app()->cache()->set(
            'security_rate_limits',
            (string)$entry['key'],
            ['count' => ((int)($entry['count'] ?? 0)) + 1],
            (int)$policy['forgot_rate_limit_window_seconds']
        );
    }
}

function bakeshopResetPasswordRateLimitExceeded(string $ip): bool
{
    $policy = kernel_password_reset_policy();
    $key = 'bakeshop_reset_password:ip:' . sha1($ip !== '' ? $ip : 'unknown');
    $cached = app()->cache()->get('security_rate_limits', $key);
    return is_array($cached) && (int)($cached['count'] ?? 0) >= (int)$policy['reset_rate_limit_ip_max'];
}

function bakeshopResetPasswordRateLimitRecord(string $ip): void
{
    $policy = kernel_password_reset_policy();
    $key = 'bakeshop_reset_password:ip:' . sha1($ip !== '' ? $ip : 'unknown');
    $cached = app()->cache()->get('security_rate_limits', $key);
    $count = is_array($cached) ? max(0, (int)($cached['count'] ?? 0)) : 0;
    app()->cache()->set('security_rate_limits', $key, ['count' => $count + 1], (int)$policy['reset_rate_limit_window_seconds']);
}

function bakeshopResetTokenIsValid(string $token): bool
{
    if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) {
        return false;
    }

    try {
        $stmt = bakeshopDb()->prepare(
            'SELECT id
             FROM bakeshop_password_resets
             WHERE token_hash = :hash
               AND used_at IS NULL
               AND expires_at > NOW()
             LIMIT 1'
        );
        $stmt->execute([':hash' => bakeshopPasswordResetTokenHash($token)]);
        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return false;
    }
}

function bakeshopForgotPasswordPage(array $params = []): void
{
    if (bakeshopRedirectAuthenticatedAuthUser()) {
        return;
    }

    echo bakeshopRender('pages/forgot-password.disyl', bakeshopLoginPageContext([
        'page_title' => 'Forgot Password',
        'forgot_password_endpoint' => bakeshopBaseUrl() . '/api/v1/bakeshop/auth/forgot-password',
        'login_page_url' => bakeshopBaseUrl() . '/bakeshop/login',
    ]));
}

function bakeshopResetPasswordPage(array $params = []): void
{
    if (bakeshopRedirectAuthenticatedAuthUser()) {
        return;
    }

    $token = trim((string)($_GET['token'] ?? ''));

    echo bakeshopRender('pages/reset-password.disyl', bakeshopLoginPageContext([
        'page_title' => 'Reset Password',
        'reset_password_endpoint' => bakeshopBaseUrl() . '/api/v1/bakeshop/auth/reset-password',
        'login_page_url' => bakeshopBaseUrl() . '/bakeshop/login',
        'reset_token' => $token,
        'token_valid' => bakeshopResetTokenIsValid($token),
    ]));
}

function bakeshopApiForgotPassword(array $params = []): void
{
    $policy = kernel_password_reset_policy();
    $ttlMinutes = max(1, (int)$policy['token_ttl_minutes']);
    $input = bakeshopInput();
    $identity = trim((string)($input['identity'] ?? ''));
    if ($identity === '') {
        bakeshopJsonError('Username or email is required.');
        return;
    }

    $requestIp = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    if (bakeshopForgotPasswordRateLimitExceeded($requestIp, $identity)) {
        bakeshopJsonError((string)$policy['forgot_rate_limit_message'], 429);
        return;
    }

    bakeshopForgotPasswordRateLimitRecord($requestIp, $identity);

    try {
        $stmt = bakeshopDb()->prepare(
            'SELECT id, username, email, full_name
             FROM bakeshop_users
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
            $tokenHash = bakeshopPasswordResetTokenHash($rawToken);

            $clear = bakeshopDb()->prepare(
                'UPDATE bakeshop_password_resets
                 SET used_at = NOW()
                 WHERE user_id = :user_id
                   AND used_at IS NULL'
            );
            $clear->execute([':user_id' => (int)$user['id']]);

            $insert = bakeshopDb()->prepare(
                'INSERT INTO bakeshop_password_resets (user_id, token_hash, requester_ip, expires_at, created_at)
                 VALUES (:user_id, :token_hash, :requester_ip, DATE_ADD(NOW(), INTERVAL ' . $ttlMinutes . ' MINUTE), NOW())'
            );
            $insert->execute([
                ':user_id' => (int)$user['id'],
                ':token_hash' => $tokenHash,
                ':requester_ip' => $requestIp,
            ]);

            $email = trim((string)($user['email'] ?? ''));
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) && function_exists('buildEmailTemplate') && function_exists('sendEmail')) {
                $brandSettings = bakeshopBrandSettings();
                $storeName = (string)($brandSettings['store_name'] ?? 'Bakeshop');
                $name = trim((string)($user['full_name'] ?? $user['username'] ?? 'there'));
                $resetUrl = bakeshopExternalBaseUrl() . '/bakeshop/reset-password?token=' . urlencode($rawToken);
                $content = '<p style="margin:0 0 16px;color:#4b5563;font-size:16px;line-height:1.6;">Hi ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . ',</p>'
                    . '<p style="margin:0 0 16px;color:#4b5563;font-size:16px;line-height:1.6;">A request was made to reset your ' . htmlspecialchars($storeName, ENT_QUOTES, 'UTF-8') . ' password.</p>'
                    . '<p style="margin:0 0 16px;color:#4b5563;font-size:16px;line-height:1.6;">This link expires in ' . $ttlMinutes . ' minutes. If you did not request this, you can safely ignore this email.</p>';
                $body = buildEmailTemplate('Reset Your Bakeshop Password', $content, 'Reset Password', $resetUrl);
                $sent = sendEmail($email, 'Bakeshop Password Reset', $body);
                if (!$sent) {
                    write_log('bakeshop forgot-password email dispatch failed for user_id=' . (string)$user['id'], 'error');
                }
            }
        }

        bakeshopJson([
            'ok' => true,
            'message' => (string)$policy['forgot_success_message'],
        ]);
    } catch (Throwable $e) {
        write_log('bakeshop forgot-password failed: ' . $e->getMessage(), 'error');
        bakeshopJsonError('Unable to process request right now.', 500);
    }
}

function bakeshopApiResetPassword(array $params = []): void
{
    $policy = kernel_password_reset_policy();
    $input = bakeshopInput();
    $token = trim((string)($input['token'] ?? ''));
    $password = (string)($input['password'] ?? '');
    $confirmPassword = (string)($input['confirm_password'] ?? '');

    if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) {
        bakeshopJsonError((string)$policy['invalid_token_message']);
        return;
    }

    if (strlen($password) < 8) {
        bakeshopJsonError('Password must be at least 8 characters.');
        return;
    }

    if ($password !== $confirmPassword) {
        bakeshopJsonError('Passwords do not match.');
        return;
    }

    $requestIp = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    if (bakeshopResetPasswordRateLimitExceeded($requestIp)) {
        bakeshopJsonError((string)$policy['reset_rate_limit_message'], 429);
        return;
    }

    bakeshopResetPasswordRateLimitRecord($requestIp);

    try {
        $tokenHash = bakeshopPasswordResetTokenHash($token);
        $stmt = bakeshopDb()->prepare(
            'SELECT pr.id AS reset_id, pr.user_id
             FROM bakeshop_password_resets pr
             INNER JOIN bakeshop_users bu ON bu.id = pr.user_id
             WHERE pr.token_hash = :token_hash
               AND pr.used_at IS NULL
               AND pr.expires_at > NOW()
               AND bu.is_active = 1
             LIMIT 1'
        );
        $stmt->execute([':token_hash' => $tokenHash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            bakeshopJsonError((string)$policy['invalid_token_message']);
            return;
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $updateUserSql = 'UPDATE bakeshop_users SET password_hash = :password_hash, updated_at = NOW()';
        if (bakeshopSupportsTokenVersion()) {
            $updateUserSql .= ', token_version = COALESCE(token_version, 0) + 1';
        }
        $updateUserSql .= ' WHERE id = :user_id';

        $updateUser = bakeshopDb()->prepare($updateUserSql);
        $updateUser->execute([
            ':password_hash' => $hash,
            ':user_id' => (int)$row['user_id'],
        ]);

        $updateReset = bakeshopDb()->prepare(
            'UPDATE bakeshop_password_resets
             SET used_at = NOW()
             WHERE user_id = :user_id
               AND used_at IS NULL'
        );
        $updateReset->execute([':user_id' => (int)$row['user_id']]);

        bakeshopJson([
            'ok' => true,
            'message' => (string)$policy['reset_success_message'],
            'redirect' => '/bakeshop/login',
        ]);
    } catch (Throwable $e) {
        write_log('bakeshop reset-password failed: ' . $e->getMessage(), 'error');
        bakeshopJsonError('Unable to reset password right now.', 500);
    }
}

function bakeshopPageLogin(array $params = []): void
{
    if (bakeshopRedirectAuthenticatedAuthUser()) {
        return;
    }

    echo app()->render('pages/login.disyl', bakeshopLoginPageContext());
}

function bakeshopAuthLogin(array $params = []): void
{
    header('Content-Type: application/json; charset=utf-8');

    if (function_exists('kernelEmitLoginRateLimitJson')) {
        $rateLimit = bakeshopLoginRateLimitState();
        if (!empty($rateLimit['limited'])) {
            kernelEmitLoginRateLimitJson($rateLimit);
            return;
        }
    }

    $input = bakeshopInput();
    $username = trim((string)($input['username'] ?? ''));
    $password = (string)($input['password'] ?? '');

    if ($username === '' || $password === '') {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Username and password are required.']);
        return;
    }

    try {
        $auth = bakeshop_cap_kernel_auth_authenticate_1([
            'username' => '@bakeshop:' . $username,
            'password' => $password,
        ]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Authentication temporarily unavailable.']);
        return;
    }

    if (!is_array($auth) || !is_array($auth['user'] ?? null) || (($auth['source'] ?? '') !== 'bakeshop')) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Invalid username or password.']);
        return;
    }

    $user = $auth['user'];
    $payload = [
        'sub' => (string)($user['sub'] ?? ('bakeshop:' . (int)($user['id'] ?? 0))),
        'id' => (int)($user['id'] ?? 0),
        'username' => (string)($user['username'] ?? $username),
        'name' => (string)($user['full_name'] ?? $username),
        'email' => (string)($user['email'] ?? ''),
        'role' => (string)($user['role'] ?? 'supervisor'),
        'source' => 'bakeshop',
        'token_version' => (int)($user['token_version'] ?? 0),
    ];

    $tenantId = app()->tenant()->current();
    if ($tenantId !== null) {
        $payload['tenant_id'] = $tenantId;
    }

    $token = app()->jwt()->generate($payload);
    bakeshopSetAuthCookie($token, (int)config('app.jwt.expiration', 86400));
    app()->csrfRotate(true);

    $redirect = kernelResolveAuthenticatedHomeRedirect($payload, true) ?? '/admin/bakeshop';
    echo json_encode(['ok' => true, 'redirect' => $redirect]);
}

function bakeshopLogout(array $params = []): void
{
    app()->csrfEnforce();
    bakeshopClearAuthCookie();
    app()->redirect('/login');
}