<?php

declare(strict_types=1);

function wmsAuthHintPresent(): bool
{
    $kernelJwtCookie = (string)config('app.jwt.cookie', 'token');

    return isset($_SERVER['HTTP_AUTHORIZATION'])
        || isset($_COOKIE[wmsCookieName()])
        || isset($_COOKIE[$kernelJwtCookie]);
}

function wmsAuthenticatedHomeRedirect(): ?string
{
    if (!wmsAuthHintPresent()) {
        return null;
    }

    $user = app()->user();
    if (!is_array($user)) {
        return null;
    }

    return kernelResolveAuthenticatedHomeRedirect($user, true) ?? '/wms';
}

function wmsPasswordResetTokenHash(string $token): string
{
    return hash('sha256', $token);
}

function wmsForgotPasswordRateLimitSnapshot(string $scope, string $value): array
{
    $normalized = strtolower(trim($value));
    if ($normalized === '') {
        $normalized = 'unknown';
    }

    $key = 'wms_forgot_password:' . $scope . ':' . sha1($normalized);
    $cached = app()->cache()->get('security_rate_limits', $key);
    if (!is_array($cached)) {
        return ['key' => $key, 'count' => 0];
    }

    return [
        'key' => $key,
        'count' => max(0, (int)($cached['count'] ?? 0)),
    ];
}

function wmsForgotPasswordRateLimitExceeded(string $ip, string $identity): bool
{
    $policy = kernel_password_reset_policy();
    $ipState = wmsForgotPasswordRateLimitSnapshot('ip', $ip !== '' ? $ip : 'unknown');
    if ((int)$ipState['count'] >= (int)$policy['forgot_rate_limit_ip_max']) {
        return true;
    }

    $identityState = wmsForgotPasswordRateLimitSnapshot('identity', $identity);
    return (int)$identityState['count'] >= (int)$policy['forgot_rate_limit_identity_max'];
}

function wmsForgotPasswordRateLimitRecord(string $ip, string $identity): void
{
    $policy = kernel_password_reset_policy();
    $entries = [
        wmsForgotPasswordRateLimitSnapshot('ip', $ip !== '' ? $ip : 'unknown'),
        wmsForgotPasswordRateLimitSnapshot('identity', $identity),
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

function wmsResetPasswordRateLimitExceeded(string $ip): bool
{
    $policy = kernel_password_reset_policy();
    $key = 'wms_reset_password:ip:' . sha1($ip !== '' ? $ip : 'unknown');
    $cached = app()->cache()->get('security_rate_limits', $key);
    return is_array($cached) && (int)($cached['count'] ?? 0) >= (int)$policy['reset_rate_limit_ip_max'];
}

function wmsResetPasswordRateLimitRecord(string $ip): void
{
    $policy = kernel_password_reset_policy();
    $key = 'wms_reset_password:ip:' . sha1($ip !== '' ? $ip : 'unknown');
    $cached = app()->cache()->get('security_rate_limits', $key);
    $count = is_array($cached) ? max(0, (int)($cached['count'] ?? 0)) : 0;
    app()->cache()->set('security_rate_limits', $key, ['count' => $count + 1], (int)$policy['reset_rate_limit_window_seconds']);
}

function wmsResetTokenIsValid(string $token): bool
{
    if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) {
        return false;
    }

    try {
        $stmt = wmsDb()->prepare(
            'SELECT id
             FROM wms_password_resets
             WHERE token_hash = :hash
               AND used_at IS NULL
               AND expires_at > NOW()
             LIMIT 1'
        );
        $stmt->execute([':hash' => wmsPasswordResetTokenHash($token)]);
        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return false;
    }
}

function wmsForgotPasswordPage(array $params = []): void
{
    $redirect = wmsAuthenticatedHomeRedirect();
    if (is_string($redirect) && $redirect !== '') {
        app()->redirect($redirect);
        return;
    }

    echo app()->render('pages/forgot-password.disyl', wmsLoginPageContext([
        'page_title' => 'Forgot Password',
        'forgot_password_endpoint' => wmsBaseUrl() . '/api/v1/wms/auth/forgot-password',
        'login_page_url' => wmsBaseUrl() . '/wms/login',
    ]));
}

function wmsResetPasswordPage(array $params = []): void
{
    $redirect = wmsAuthenticatedHomeRedirect();
    if (is_string($redirect) && $redirect !== '') {
        app()->redirect($redirect);
        return;
    }

    $token = trim((string)($_GET['token'] ?? ''));

    echo app()->render('pages/reset-password.disyl', wmsLoginPageContext([
        'page_title' => 'Reset Password',
        'reset_password_endpoint' => wmsBaseUrl() . '/api/v1/wms/auth/reset-password',
        'login_page_url' => wmsBaseUrl() . '/wms/login',
        'reset_token' => $token,
        'token_valid' => wmsResetTokenIsValid($token),
    ]));
}

function wmsApiForgotPassword(array $params = []): void
{
    $policy = kernel_password_reset_policy();
    $ttlMinutes = max(1, (int)$policy['token_ttl_minutes']);
    $input = wmsInput();
    $identity = trim((string)($input['identity'] ?? ''));
    if ($identity === '') {
        wmsJsonError('Username or email is required.');
    }

    $requestIp = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    if (wmsForgotPasswordRateLimitExceeded($requestIp, $identity)) {
        wmsJsonError((string)$policy['forgot_rate_limit_message'], 429);
    }

    wmsForgotPasswordRateLimitRecord($requestIp, $identity);

    try {
        $stmt = wmsDb()->prepare(
            'SELECT id, username, email, full_name
             FROM wms_users
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
            $tokenHash = wmsPasswordResetTokenHash($rawToken);

            $clear = wmsDb()->prepare(
                'UPDATE wms_password_resets
                 SET used_at = NOW()
                 WHERE user_id = :user_id
                   AND used_at IS NULL'
            );
            $clear->execute([':user_id' => (int)$user['id']]);

            $insert = wmsDb()->prepare(
                'INSERT INTO wms_password_resets (user_id, token_hash, requester_ip, expires_at, created_at)
                 VALUES (:user_id, :token_hash, :requester_ip, DATE_ADD(NOW(), INTERVAL ' . $ttlMinutes . ' MINUTE), NOW())'
            );
            $insert->execute([
                ':user_id' => (int)$user['id'],
                ':token_hash' => $tokenHash,
                ':requester_ip' => $requestIp,
            ]);

            $email = trim((string)($user['email'] ?? ''));
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) && function_exists('buildEmailTemplate') && function_exists('sendEmail')) {
                $displayName = trim((string)($user['full_name'] ?? $user['username'] ?? 'there'));
                $resetUrl = wmsExternalBaseUrl() . '/wms/reset-password?token=' . urlencode($rawToken);
                $content = '<p style="margin:0 0 16px;color:#4b5563;font-size:16px;line-height:1.6;">Hi ' . htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') . ',</p>'
                    . '<p style="margin:0 0 16px;color:#4b5563;font-size:16px;line-height:1.6;">A request was made to reset your Warehouse Management System password.</p>'
                    . '<p style="margin:0 0 16px;color:#4b5563;font-size:16px;line-height:1.6;">This link expires in ' . $ttlMinutes . ' minutes. If you did not request this, you can safely ignore this email.</p>';
                $body = buildEmailTemplate('Reset Your WMS Password', $content, 'Reset Password', $resetUrl);
                $sent = sendEmail($email, 'WMS Password Reset', $body);
                if (!$sent) {
                    write_log('wms forgot-password email dispatch failed for user_id=' . (string)$user['id'], 'error');
                }
            }
        }

        wmsJsonOk(['message' => (string)$policy['forgot_success_message']]);
    } catch (Throwable $e) {
        write_log('wms forgot-password failed: ' . $e->getMessage(), 'error');
        wmsJsonError('Unable to process request right now.', 500);
    }
}

function wmsApiResetPassword(array $params = []): void
{
    $policy = kernel_password_reset_policy();
    $input = wmsInput();
    $token = trim((string)($input['token'] ?? ''));
    $password = (string)($input['password'] ?? '');
    $confirmPassword = (string)($input['confirm_password'] ?? '');

    if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) {
        wmsJsonError((string)$policy['invalid_token_message']);
    }

    if (strlen($password) < 8) {
        wmsJsonError('Password must be at least 8 characters.');
    }

    if ($password !== $confirmPassword) {
        wmsJsonError('Passwords do not match.');
    }

    $requestIp = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    if (wmsResetPasswordRateLimitExceeded($requestIp)) {
        wmsJsonError((string)$policy['reset_rate_limit_message'], 429);
    }

    wmsResetPasswordRateLimitRecord($requestIp);

    try {
        $tokenHash = wmsPasswordResetTokenHash($token);
        $stmt = wmsDb()->prepare(
            'SELECT pr.id AS reset_id, pr.user_id
             FROM wms_password_resets pr
             INNER JOIN wms_users wu ON wu.id = pr.user_id
             WHERE pr.token_hash = :token_hash
               AND pr.used_at IS NULL
               AND pr.expires_at > NOW()
               AND wu.is_active = 1
             LIMIT 1'
        );
        $stmt->execute([':token_hash' => $tokenHash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            wmsJsonError((string)$policy['invalid_token_message']);
        }

        $updateUser = wmsDb()->prepare(
            'UPDATE wms_users
             SET password_hash = :password_hash,
                 updated_at = NOW()
             WHERE id = :user_id'
        );
        $updateUser->execute([
            ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
            ':user_id' => (int)$row['user_id'],
        ]);

        $updateReset = wmsDb()->prepare(
            'UPDATE wms_password_resets
             SET used_at = NOW()
             WHERE user_id = :user_id
               AND used_at IS NULL'
        );
        $updateReset->execute([':user_id' => (int)$row['user_id']]);

        wmsJsonOk([
            'message' => (string)$policy['reset_success_message'],
            'redirect' => '/wms/login',
        ]);
    } catch (Throwable $e) {
        write_log('wms reset-password failed: ' . $e->getMessage(), 'error');
        wmsJsonError('Unable to reset password right now.', 500);
    }
}

function wmsPageLogin(array $params = []): void
{
    $loginStartedAt = microtime(true);
    $tenantId = app()->tenant()->current();

    $home = wmsAuthenticatedHomeRedirect();
    if (is_string($home) && $home !== '') {
        log_timing('wms.login.path', $loginStartedAt, [
            'phase' => 'redirect_authenticated',
            'tenant_id' => $tenantId,
            'cache_hit' => false,
        ]);
        app()->redirect($home);
        return;
    }

    // Cache key: fixed identifier for login page (same across all tenants for this module)
    $cacheKey = 'wms:login:html';

    if (extension_loaded('apcu') && apcu_enabled()) {
        $cachedHtml = apcu_fetch($cacheKey);
        if (is_string($cachedHtml) && $cachedHtml !== '') {
            log_timing('wms.login.path', $loginStartedAt, [
                'phase' => 'cache_hit',
                'tenant_id' => $tenantId,
                'cache_hit' => true,
                'cache_key' => $cacheKey,
            ]);
            echo $cachedHtml;
            return;
        }
    }

    $ctxBuildStart = microtime(true);
    $wmsCtx = wmsLoginPageContext();
    $ctxBuildMs = round((microtime(true) - $ctxBuildStart) * 1000, 2);

    $renderStart = microtime(true);
    $html = app()->render('pages/login.disyl', $wmsCtx);
    $renderMs = round((microtime(true) - $renderStart) * 1000, 2);
    if (extension_loaded('apcu') && apcu_enabled()) {
        apcu_store($cacheKey, $html, 60);  // 60-second TTL for higher hit rate under concurrency
    }

    log_timing('wms.login.path', $loginStartedAt, [
        'phase' => 'render',
        'tenant_id' => $tenantId,
        'cache_hit' => false,
        'cache_key' => $cacheKey,
        'ctx_build_ms' => $ctxBuildMs,
        'render_ms' => $renderMs,
        'html_bytes' => strlen($html),
    ]);

    echo $html;
}

function wmsAuthLogin(): void
{
    header('Content-Type: application/json; charset=utf-8');

    if (function_exists('kernelConsumeLoginRateLimit')) {
        $rateLimit = kernelConsumeLoginRateLimit('wms');
        if (!empty($rateLimit['limited'])) {
            kernelEmitLoginRateLimitJson($rateLimit);
            return;
        }
    }

    $input = wmsInput();
    $username = trim((string)($input['username'] ?? ''));
    $password = (string)($input['password'] ?? '');

    if ($username === '' || $password === '') {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Username or email and password are required.']);
        return;
    }

    try {
        $auth = app()->cap()->call('kernel.auth.authenticate@1', [
            'username' => '@wms:' . $username,
            'password' => $password,
        ], ['mode' => 'pipeline']);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Authentication temporarily unavailable.']);
        return;
    }

    if (!is_array($auth) || !is_array($auth['user'] ?? null) || (($auth['source'] ?? '') !== 'wms')) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Invalid username or password.']);
        return;
    }

    $user = $auth['user'];
    $payload = [
        'sub' => (string)($user['sub'] ?? ('wms:' . (int)($user['id'] ?? 0))),
        'id' => (int)($user['id'] ?? 0),
        'username' => (string)($user['username'] ?? $username),
        'name' => (string)($user['full_name'] ?? $username),
        'email' => (string)($user['email'] ?? ''),
        'role' => (string)($user['role'] ?? 'viewer'),
        'source' => 'wms',
    ];

    $tenantId = app()->tenant()->current();
    if ($tenantId !== null) {
        $payload['tenant_id'] = $tenantId;
    }

    $token = app()->jwt()->generate($payload);
    wmsSetAuthCookie($token, (int)config('app.jwt.expiration', 86400));

    $redirect = kernelResolveAuthenticatedHomeRedirect($payload, true) ?? '/wms';
    echo json_encode(['ok' => true, 'redirect' => $redirect]);
}

function wmsLogout(array $params = []): void
{
    wmsClearAuthCookie();
    app()->redirect('/wms/login');
}
