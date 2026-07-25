<?php

declare(strict_types=1);

if (!function_exists('kernelHandleAuthLogin')) {
function kernelHandleAuthLogin(): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('X-Request-Id: ' . request_id());

    $loginRateLimit = kernelConsumeLoginRateLimit();
    if (!empty($loginRateLimit['limited'])) {
        kernelEmitLoginRateLimitJson($loginRateLimit);
        exit;
    }

    $input = app()->input();
    $username = trim((string) ($input['username'] ?? ''));
    $password = (string) ($input['password'] ?? '');
    $preferredSource = trim((string)($input['preferred_source'] ?? ''));

    if ($username === '' || $password === '') {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Username and password are required.']);
        exit;
    }

    $authRow = null;
    $authSource = null;

    // Capability-based authentication pipeline.
    // Providers return: ['user'=>array, 'source'=>string] or null.
    try {
        $authOptions = ['mode' => 'pipeline', 'strict_pipeline' => false];
        if ($preferredSource === 'kernel') {
            $authOptions['provider'] = 'kernel';
        }

        $authResult = app()->cap()->call('kernel.auth.authenticate@1', [
            'username' => $username,
            'password' => $password,
        ], $authOptions);

        if (is_array($authResult) && isset($authResult['user']) && is_array($authResult['user'])) {
            $authRow = $authResult['user'];
            $authSource = (string)($authResult['source'] ?? '');
        }
    } catch (\Ikabud\Kernel\Capabilities\CapabilityNotFoundException $e) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Invalid username or password.']);
        exit;
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Authentication temporarily unavailable.']);
        exit;
    }

    if (!is_array($authRow)) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Invalid username or password.']);
        exit;
    }

    $role = (string) ($authRow['role'] ?? '');
    $idInt = (int) ($authRow['id'] ?? 0);
    // Preserve module-provided subject to avoid ID collisions with kernel users.id
    // (e.g. daily-ledger cashiers/supervisors use sub like cashier:3 with id=0)
    $sub = (string)($authRow['sub'] ?? '');
    if ($sub === '') {
        $sub = $authSource === 'kernel' ? (string) $idInt : ($role . ':' . $idInt);
    }

    $payload = [
        'sub' => $sub,
        'id' => $idInt,
        'username' => $authRow['username'],
        'name' => $authRow['full_name'],
        'email' => $authRow['email'] ?? '',
        'role' => $role,
        'source' => $authSource,
        'token_version' => (int)($authRow['token_version'] ?? 0),
    ];

    // Bind JWT to current tenant when multi-tenancy is active
    $resolvedTid = app()->tenant()->current();
    if ($resolvedTid !== null) {
        $payload['tenant_id'] = $resolvedTid;
    }

    $token = app()->jwt()->generate($payload);
    $cookieName = config('app.cookie_name', 'app_token');
    $expiry = time() + (int) config('app.jwt.expiration', 86400);
    setcookie($cookieName, $token, [
        'expires' => $expiry,
        'path' => '/',
        'httponly' => true,
        'secure' => is_https(),
        'samesite' => config('cookie.samesite', 'Strict'),
    ]);
    app()->csrfRotate(true);

    // API clients (Accept: application/json) get token + refresh_token in body
    $accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
    if (str_contains($accept, 'application/json')) {
        $response = [
            'ok' => true,
            'token' => $token,
            'expires_in' => (int) config('app.jwt.expiration', 14400),
            'user' => [
                'id' => $idInt,
                'username' => (string) ($authRow['username'] ?? ''),
                'name' => (string) ($authRow['full_name'] ?? ''),
                'role' => $role,
            ],
        ];

        // Refresh tokens are kernel-user only. Module-authenticated users receive JWT only.
        if ($authSource === 'kernel') {
            $refreshToken = bin2hex(random_bytes(32));
            $refreshHash = hash('sha256', $refreshToken);
            $refreshExpiry = date('Y-m-d H:i:s', strtotime('+30 days'));
            try {
                $rtStmt = app()->db()->prepare(
                    'INSERT INTO refresh_tokens (user_id, token_hash, expires_at) VALUES (:user_id, :token_hash, :expires_at)'
                );
                $rtStmt->execute([
                    ':user_id' => $idInt,
                    ':token_hash' => $refreshHash,
                    ':expires_at' => $refreshExpiry,
                ]);
                $response['refresh_token'] = $refreshToken;
                $response['refresh_expires_in'] = 30 * 86400;
            } catch (Throwable $e) {
                // Non-fatal: login succeeds without refresh token
            }
        }
        echo json_encode($response);
        exit;
    }

    $loginRedirect = kernelResolveAuthenticatedHomeRedirect($payload, true) ?? '/';
    echo json_encode(['ok' => true, 'redirect' => $loginRedirect]);
    exit;
}
}

if (!function_exists('kernelHandleAuthRefresh')) {
function kernelHandleAuthRefresh(): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('X-Request-Id: ' . request_id());
    $input = app()->input();
    $refreshToken = trim((string) ($input['refresh_token'] ?? ''));

    if ($refreshToken === '') {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'refresh_token is required.']);
        exit;
    }

    $tokenHash = hash('sha256', $refreshToken);
    try {
        // Atomically revoke the presented token only if it is still valid.
        // This closes the TOCTOU race: if two concurrent requests arrive with
        // the same token only the one that wins the UPDATE (rowCount == 1)
        // proceeds; the loser sees 0 affected rows and gets a 401.
        $revokeStmt = app()->db()->prepare(
            'UPDATE refresh_tokens
             SET    revoked = 1
             WHERE  token_hash = :token_hash
               AND  revoked    = 0
               AND  expires_at > :now'
        );
        $revokeStmt->execute([
            ':token_hash' => $tokenHash,
            ':now'        => date('Y-m-d H:i:s'),
        ]);

        if ($revokeStmt->rowCount() === 0) {
            http_response_code(401);
            echo json_encode(['ok' => false, 'error' => 'Invalid refresh token.']);
            exit;
        }

        // Token was valid and is now revoked; fetch live user data.
        $stmt = app()->db()->prepare(
            'SELECT rt.user_id,
                    u.username, u.full_name, u.role, u.is_active
             FROM   refresh_tokens rt
             INNER JOIN users u ON u.id = rt.user_id
             WHERE  rt.token_hash = :token_hash
             LIMIT 1'
        );
        $stmt->execute([':token_hash' => $tokenHash]);
        $rtRow = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Database error.']);
        exit;
    }

    if (!is_array($rtRow) || !$rtRow['is_active']) {
        // Row missing (shouldn't happen) or account deactivated since the token
        // was issued.  Token is already revoked above so no cleanup needed.
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Refresh token expired or revoked.']);
        exit;
    }

    // Issue new JWT
    $payload = [
        'sub' => (string) $rtRow['user_id'],
        'id' => (int) $rtRow['user_id'],
        'username' => $rtRow['username'],
        'name' => $rtRow['full_name'],
        'role' => $rtRow['role'],
        'source' => 'kernel',
    ];

    // Bind JWT to current tenant when multi-tenancy is active
    $resolvedTid = app()->tenant()->current();
    if ($resolvedTid !== null) {
        $payload['tenant_id'] = $resolvedTid;
    }

    $newToken = app()->jwt()->generate($payload);

    // Issue new refresh token (rotation)
    $newRefreshToken = bin2hex(random_bytes(32));
    $newRefreshHash = hash('sha256', $newRefreshToken);
    $refreshExpiry = date('Y-m-d H:i:s', strtotime('+30 days'));
    $insertStmt = app()->db()->prepare(
        'INSERT INTO refresh_tokens (user_id, token_hash, expires_at) VALUES (:user_id, :token_hash, :expires_at)'
    );
    $insertStmt->execute([
        ':user_id' => (int) $rtRow['user_id'],
        ':token_hash' => $newRefreshHash,
        ':expires_at' => $refreshExpiry,
    ]);

    echo json_encode([
        'ok' => true,
        'token' => $newToken,
        'refresh_token' => $newRefreshToken,
        'expires_in' => (int) config('app.jwt.expiration', 14400),
        'refresh_expires_in' => 30 * 86400,
    ]);
    exit;
}
}

if (!function_exists('kernelHandleAuthLogout')) {
function kernelHandleAuthLogout(): void
{
    $logoutUser = app()->user();
    $logoutInput = app()->input();
    $presentedRefreshToken = trim((string)($logoutInput['refresh_token'] ?? ''));

    try {
        if (is_array($logoutUser) && (($logoutUser['source'] ?? 'kernel') === 'kernel')) {
            $logoutUserId = (int)($logoutUser['id'] ?? 0);
            if ($logoutUserId > 0) {
                $revokeStmt = app()->db()->prepare('UPDATE refresh_tokens SET revoked = 1 WHERE user_id = :user_id AND revoked = 0');
                $revokeStmt->execute([':user_id' => $logoutUserId]);
            }
        } elseif ($presentedRefreshToken !== '') {
            $revokeStmt = app()->db()->prepare('UPDATE refresh_tokens SET revoked = 1 WHERE token_hash = :token_hash');
            $revokeStmt->execute([':token_hash' => hash('sha256', $presentedRefreshToken)]);
        }
    } catch (Throwable $e) {
        write_log('authLogout refresh-token revoke failed: ' . $e->getMessage(), 'warning');
    }

    $cookieName = config('app.cookie_name', 'app_token');
    clearAuthCookie($cookieName);
    $logoutSource = is_array($logoutUser) ? trim((string)($logoutUser['source'] ?? '')) : '';
    if ($logoutSource !== '' && $logoutSource !== 'kernel') {
        $enabledModules = getEnabledModules();
        if (!empty($enabledModules[$logoutSource]['auth_cookie'])) {
            clearAuthCookie((string)$enabledModules[$logoutSource]['auth_cookie']);
        }
    }
    app()->csrfRotate(true);

    // API clients get JSON instead of redirect
    $accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
    if (str_contains($accept, 'application/json')) {
        header('Content-Type: application/json; charset=utf-8');
    header('X-Request-Id: ' . request_id());
        echo json_encode(['ok' => true]);
        exit;
    }

    // If logout was initiated from a module UI (e.g. CMS), send the user back
    // to that module's login page instead of the kernel OS login.
    $ref = strtolower((string)($_SERVER['HTTP_REFERER'] ?? ''));
    if ($ref !== '' && str_contains($ref, '/cms')) {
        app()->redirect('/cms/login');
    }

    app()->redirect('/login');
}
}

if (!function_exists('kernelForgotPasswordRateLimitSnapshot')) {
function kernelForgotPasswordRateLimitSnapshot(string $scope, string $value): array
{
    $normalized = strtolower(trim($value));
    if ($normalized === '') {
        $normalized = 'unknown';
    }

    $key = 'kernel_forgot_password:' . $scope . ':' . sha1($normalized);
    $cached = app()->cache()->get('security_rate_limits', $key);
    if (!is_array($cached)) {
        return ['key' => $key, 'count' => 0];
    }

    return [
        'key' => $key,
        'count' => max(0, (int)($cached['count'] ?? 0)),
    ];
}
}

if (!function_exists('kernelForgotPasswordRateLimitExceeded')) {
function kernelForgotPasswordRateLimitExceeded(string $ip, string $identity): bool
{
    $policy = kernel_password_reset_policy();
    $ipState = kernelForgotPasswordRateLimitSnapshot('ip', $ip !== '' ? $ip : 'unknown');
    if ((int)$ipState['count'] >= (int)$policy['forgot_rate_limit_ip_max']) {
        return true;
    }

    $identityState = kernelForgotPasswordRateLimitSnapshot('identity', $identity);
    return (int)$identityState['count'] >= (int)$policy['forgot_rate_limit_identity_max'];
}
}

if (!function_exists('kernelForgotPasswordRateLimitRecord')) {
function kernelForgotPasswordRateLimitRecord(string $ip, string $identity): void
{
    $policy = kernel_password_reset_policy();
    $entries = [
        kernelForgotPasswordRateLimitSnapshot('ip', $ip !== '' ? $ip : 'unknown'),
        kernelForgotPasswordRateLimitSnapshot('identity', $identity),
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
}

if (!function_exists('kernelResetPasswordRateLimitSnapshot')) {
function kernelResetPasswordRateLimitSnapshot(string $ip): array
{
    $key = 'kernel_reset_password:ip:' . sha1($ip !== '' ? $ip : 'unknown');
    $cached = app()->cache()->get('security_rate_limits', $key);
    if (!is_array($cached)) {
        return ['key' => $key, 'count' => 0];
    }

    return [
        'key' => $key,
        'count' => max(0, (int)($cached['count'] ?? 0)),
    ];
}
}

if (!function_exists('kernelResetPasswordRateLimitExceeded')) {
function kernelResetPasswordRateLimitExceeded(string $ip): bool
{
    $policy = kernel_password_reset_policy();
    $state = kernelResetPasswordRateLimitSnapshot($ip);
    return (int)$state['count'] >= (int)$policy['reset_rate_limit_ip_max'];
}
}

if (!function_exists('kernelResetPasswordRateLimitRecord')) {
function kernelResetPasswordRateLimitRecord(string $ip): void
{
    $policy = kernel_password_reset_policy();
    $state = kernelResetPasswordRateLimitSnapshot($ip);
    app()->cache()->set(
        'security_rate_limits',
        (string)$state['key'],
        ['count' => ((int)($state['count'] ?? 0)) + 1],
        (int)$policy['reset_rate_limit_window_seconds']
    );
}
}

if (!function_exists('kernelAuthExperienceContext')) {
function kernelAuthExperienceContext(array $overrides = []): array
{
    $context = function_exists('kernelResolveEntryModuleLoginContext')
        ? kernelResolveEntryModuleLoginContext($overrides)
        : array_merge(['page_title' => 'Sign In'], $overrides);

    $brandText = trim((string)($context['login_brand_text'] ?? ''));
    if ($brandText === '') {
        $brandText = trim(strip_tags((string)($context['login_brand_html'] ?? '')));
    }
    if ($brandText === '' && is_array($context['gui'] ?? null)) {
        $brandText = trim((string)($context['gui']['app_name'] ?? ''));
    }
    if ($brandText === '') {
        $brandText = 'APPLICATION KERNEL OS';
    }

    $context['login_brand_text'] = $brandText;
    $context['login_page_url'] = $context['login_page_url'] ?? (external_base_url() . '/login');
    $context['forgot_password_endpoint'] = $context['forgot_password_endpoint'] ?? (external_base_url() . '/api/v1/auth/forgot-password');
    $context['reset_password_endpoint'] = $context['reset_password_endpoint'] ?? (external_base_url() . '/api/v1/auth/reset-password');

    return $context;
}
}

if (!function_exists('kernelHandleAuthForgotPasswordPage')) {
function kernelHandleAuthForgotPasswordPage(): void
{
    $entryModuleId = function_exists('kernelCurrentEntryModuleId')
        ? kernelResolveEntryModuleAlias(kernelCurrentEntryModuleId())
        : 'kernel';
    if ($entryModuleId === 'ehr') {
        app()->redirect('/ehr/forgot-password');
        return;
    }

    $user = app()->user();
    if (is_array($user)) {
        $loginHome = kernelResolveAuthenticatedHomeRedirect($user, true) ?? '/';
        app()->redirect($loginHome);
        return;
    }

    echo app()->render('pages/forgot-password.disyl', kernelAuthExperienceContext([
        'page_title' => 'Forgot Password',
    ]));
}
}

if (!function_exists('kernelHandleAuthResetPasswordPage')) {
function kernelHandleAuthResetPasswordPage(): void
{
    $entryModuleId = function_exists('kernelCurrentEntryModuleId')
        ? kernelResolveEntryModuleAlias(kernelCurrentEntryModuleId())
        : 'kernel';
    if ($entryModuleId === 'ehr') {
        $target = '/ehr/reset-password';
        $token = trim((string)($_GET['token'] ?? ''));
        if ($token !== '') {
            $target .= '?token=' . urlencode($token);
        }
        app()->redirect($target);
        return;
    }

    $user = app()->user();
    if (is_array($user)) {
        $loginHome = kernelResolveAuthenticatedHomeRedirect($user, true) ?? '/';
        app()->redirect($loginHome);
        return;
    }

    $token = trim((string)($_GET['token'] ?? ''));
    $tokenValid = false;
    if ($token !== '' && preg_match('/^[a-f0-9]{64}$/', $token)) {
        try {
            $stmt = app()->db()->prepare(
                'SELECT id FROM kernel_password_resets WHERE token_hash = :hash AND used_at IS NULL AND expires_at > NOW() LIMIT 1'
            );
            $stmt->execute([':hash' => hash('sha256', $token)]);
            $tokenValid = (bool)$stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $tokenValid = false;
        }
    }

    echo app()->render('pages/reset-password.disyl', kernelAuthExperienceContext([
        'page_title' => 'Reset Password',
        'reset_token' => $token,
        'token_valid' => $tokenValid,
    ]));
}
}

if (!function_exists('kernelHandleAuthForgotPassword')) {
function kernelHandleAuthForgotPassword(): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('X-Request-Id: ' . request_id());

    $policy = kernel_password_reset_policy();
    $ttlMinutes = max(1, (int)$policy['token_ttl_minutes']);
    $input = app()->input();
    $identity = trim((string)($input['identity'] ?? ''));
    if ($identity === '') {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Username or email is required.']);
        exit;
    }

    $requestIp = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    if (kernelForgotPasswordRateLimitExceeded($requestIp, $identity)) {
        http_response_code(429);
        echo json_encode(['ok' => false, 'error' => (string)$policy['forgot_rate_limit_message']]);
        exit;
    }
    kernelForgotPasswordRateLimitRecord($requestIp, $identity);

    try {
        $db = app()->db();
        $hasEmailColumn = kernelUsersHasEmailColumn($db);
        $stmt = $db->prepare(
            $hasEmailColumn
                ? 'SELECT id, username, email, full_name, token_version
             FROM users
             WHERE (username = :identity_username OR email = :identity_email) AND is_active = 1
             LIMIT 1'
                : 'SELECT id, username, full_name, token_version
             FROM users
             WHERE username = :identity_username AND is_active = 1
             LIMIT 1'
        );
        $bind = [':identity_username' => $identity];
        if ($hasEmailColumn) {
            $bind[':identity_email'] = $identity;
        }
        $stmt->execute($bind);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($user) && !$hasEmailColumn) {
            $user['email'] = '';
        }

        if (is_array($user)) {
            $rawToken = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $rawToken);

            $clear = $db->prepare(
                'UPDATE kernel_password_resets
                 SET used_at = NOW()
                 WHERE user_id = :user_id
                   AND used_at IS NULL'
            );
            $clear->execute([':user_id' => (int)$user['id']]);

            $insert = $db->prepare(
                'INSERT INTO kernel_password_resets (user_id, token_hash, requester_ip, expires_at, created_at)
                 VALUES (:user_id, :token_hash, :requester_ip, DATE_ADD(NOW(), INTERVAL ' . $ttlMinutes . ' MINUTE), NOW())'
            );
            $insert->execute([
                ':user_id' => (int)$user['id'],
                ':token_hash' => $tokenHash,
                ':requester_ip' => $requestIp,
            ]);

            $email = trim((string)($user['email'] ?? ''));
            $resetUrl = external_base_url() . '/reset-password?token=' . urlencode($rawToken);

            // Always log the reset URL for dev fallback (email may not be configured)
            write_log('kernel password reset requested', 'info', [
                'user_id' => (int)$user['id'],
                'username' => $user['username'] ?? '',
                'reset_url' => $resetUrl,
            ]);

            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $context = kernelAuthExperienceContext();
                $brandText = (string)($context['login_brand_text'] ?? 'APPLICATION KERNEL OS');
                $name = trim((string)($user['full_name'] ?? $user['username'] ?? 'there'));
                $content = '<p style="margin:0 0 16px;color:#4b5563;font-size:16px;line-height:1.6;">Hi ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . ',</p>'
                    . '<p style="margin:0 0 16px;color:#4b5563;font-size:16px;line-height:1.6;">A request was made to reset your ' . htmlspecialchars($brandText, ENT_QUOTES, 'UTF-8') . ' password.</p>'
                    . '<p style="margin:0 0 16px;color:#4b5563;font-size:16px;line-height:1.6;">This link expires in ' . $ttlMinutes . ' minutes. If you did not request this, you can safely ignore this email.</p>';
                $body = buildEmailTemplate('Reset Your ' . $brandText . ' Password', $content, 'Reset Password', $resetUrl);
                $sent = sendEmail($email, $brandText . ' Password Reset', $body);
                if (!$sent) {
                    write_log('kernel forgot-password email dispatch failed for user_id=' . (string)$user['id'], 'error');
                }
            }
        }

        echo json_encode([
            'ok' => true,
            'message' => (string)$policy['forgot_success_message'],
        ]);
        exit;
    } catch (Throwable $e) {
        write_log('kernel forgot-password failed: ' . $e->getMessage(), 'error');
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Unable to process request right now.']);
        exit;
    }
}
}

if (!function_exists('kernelHandleAuthResetPassword')) {
function kernelHandleAuthResetPassword(): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('X-Request-Id: ' . request_id());

    $policy = kernel_password_reset_policy();
    $input = app()->input();
    $token = trim((string)($input['token'] ?? ''));
    $password = (string)($input['password'] ?? '');
    $confirmPassword = (string)($input['confirm_password'] ?? '');

    if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => (string)$policy['invalid_token_message']]);
        exit;
    }
    if (strlen($password) < 8) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Password must be at least 8 characters.']);
        exit;
    }
    if ($password !== $confirmPassword) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Passwords do not match.']);
        exit;
    }

    $requestIp = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    if (kernelResetPasswordRateLimitExceeded($requestIp)) {
        http_response_code(429);
        echo json_encode(['ok' => false, 'error' => (string)$policy['reset_rate_limit_message']]);
        exit;
    }
    kernelResetPasswordRateLimitRecord($requestIp);

    try {
        $db = app()->db();
        $stmt = $db->prepare(
            'SELECT pr.id AS reset_id, pr.user_id
             FROM kernel_password_resets pr
             INNER JOIN users u ON u.id = pr.user_id
             WHERE pr.token_hash = :token_hash
               AND pr.used_at IS NULL
               AND pr.expires_at > NOW()
               AND u.is_active = 1
             LIMIT 1'
        );
        $stmt->execute([':token_hash' => hash('sha256', $token)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => (string)$policy['invalid_token_message']]);
            exit;
        }

        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $updateUser = $db->prepare(
            'UPDATE users
             SET password_hash = :password_hash,
                 token_version = COALESCE(token_version, 0) + 1
             WHERE id = :user_id'
        );
        $updateUser->execute([
            ':password_hash' => $passwordHash,
            ':user_id' => (int)$row['user_id'],
        ]);

        $updateReset = $db->prepare(
            'UPDATE kernel_password_resets
             SET used_at = NOW()
             WHERE user_id = :user_id
               AND used_at IS NULL'
        );
        $updateReset->execute([':user_id' => (int)$row['user_id']]);

        echo json_encode([
            'ok' => true,
            'message' => (string)$policy['reset_success_message'],
            'redirect' => '/login',
        ]);
        exit;
    } catch (Throwable $e) {
        write_log('kernel reset-password failed: ' . $e->getMessage(), 'error');
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Unable to reset password right now.']);
        exit;
    }
}
}

if (!function_exists('kernelHandleApiMe')) {
function kernelHandleApiMe(): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('X-Request-Id: ' . request_id());
    $user = app()->user();
    if (!$user) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Authentication required.']);
        exit;
    }

    $meRole = (string) ($user['role'] ?? '');
    echo json_encode([
        'ok' => true,
        'user' => [
            'id' => (int) ($user['id'] ?? 0),
            'username' => (string) ($user['username'] ?? ''),
            'name' => (string) ($user['name'] ?? ''),
            'role' => $meRole,
        ],
    ]);
    exit;
}
}

if (!function_exists('kernelHandleApiAuditLog')) {
function kernelHandleApiAuditLog(): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('X-Request-Id: ' . request_id());
    $user = app()->user();
    if (!$user) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Authentication required.']);
        exit;
    }

    // Only kernel admin or superadmin can view audit log
    $auditRole = (string) ($user['role'] ?? '');
    if (!in_array($auditRole, ['admin', 'superadmin'], true)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Only admin and superadmin can view audit logs.']);
        exit;
    }

    $auditInput = app()->input();
    $auditWhere = ['1=1'];
    $auditBind = [];

    // Filter: module
    if (!empty($auditInput['module'])) {
        $auditWhere[] = 'a.module = :module';
        $auditBind[':module'] = (string) $auditInput['module'];
    }
    // Filter: branch_id
    if (!empty($auditInput['branch_id'])) {
        $auditWhere[] = 'a.branch_id = :branch_id';
        $auditBind[':branch_id'] = (int) $auditInput['branch_id'];
    }
    // Filter: actor_id
    if (!empty($auditInput['actor_id'])) {
        $auditWhere[] = 'a.actor_user_id = :actor_id';
        $auditBind[':actor_id'] = (int) $auditInput['actor_id'];
    }
    // Filter: date_from
    if (!empty($auditInput['date_from'])) {
        $auditWhere[] = 'a.created_at >= :date_from';
        $auditBind[':date_from'] = (string) $auditInput['date_from'] . ' 00:00:00';
    }
    // Filter: date_to
    if (!empty($auditInput['date_to'])) {
        $auditWhere[] = 'a.created_at <= :date_to';
        $auditBind[':date_to'] = (string) $auditInput['date_to'] . ' 23:59:59';
    }

    $auditLimit = max(1, min(500, (int) ($auditInput['limit'] ?? 50)));
    $auditOffset = max(0, (int) ($auditInput['offset'] ?? 0));

    $auditSql = 'SELECT a.id, a.module, a.actor_user_id, u.username AS actor_username,
                        a.branch_id, a.action, a.entity_type, a.entity_id,
                        a.old_data, a.new_data, a.metadata_json, a.created_at
                 FROM audit_logs a
                 LEFT JOIN users u ON u.id = a.actor_user_id
                 WHERE ' . implode(' AND ', $auditWhere) . '
                 ORDER BY a.created_at DESC
                 LIMIT ' . $auditLimit . ' OFFSET ' . $auditOffset;

    try {
        $auditStmt = app()->db()->prepare($auditSql);
        $auditStmt->execute($auditBind);
        $auditRows = $auditStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Decode JSON fields
        foreach ($auditRows as &$aRow) {
            $aRow['old_data'] = $aRow['old_data'] ? json_decode($aRow['old_data'], true) : null;
            $aRow['new_data'] = $aRow['new_data'] ? json_decode($aRow['new_data'], true) : null;
            $aRow['metadata'] = $aRow['metadata_json'] ? json_decode($aRow['metadata_json'], true) : null;
            unset($aRow['metadata_json']);
        }
        unset($aRow);

        // Count total for pagination
        $countSql = 'SELECT COUNT(*) FROM audit_logs a WHERE ' . implode(' AND ', $auditWhere);
        $countStmt = app()->db()->prepare($countSql);
        $countStmt->execute($auditBind);
        $auditTotal = (int) $countStmt->fetchColumn();

        echo json_encode([
            'ok' => true,
            'entries' => $auditRows,
            'pagination' => [
                'total' => $auditTotal,
                'limit' => $auditLimit,
                'offset' => $auditOffset,
                'has_more' => ($auditOffset + $auditLimit) < $auditTotal,
            ],
        ]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Failed to query audit logs.']);
    }
    exit;
}
}
