<?php

declare(strict_types=1);

function wmsPageLogin(): void
{
    // Already authenticated? Redirect to dashboard
    $redirect = wmsAuthenticatedHomeRedirect();
    if (is_string($redirect) && $redirect !== '') {
        app()->redirect($redirect);
        return;
    }

    echo wmsRender('modules/wms/pages/login.disyl', wmsLoginPageContext());
}

function wmsAuthenticatedHomeRedirect(): ?string
{
    $user = app()->user();
    if (!is_array($user)) return null;

    return app()->hooks()->filter('kernel.home_url', null, $user['role'] ?? '', $user)
        ?? '/wms';
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
        $prefix = '@wms:';
        $loginName = $username;
        if (!str_starts_with($username, $prefix)) {
            $loginName = '@wms:' . $username;
        }
        $auth = wms_cap_kernel_auth_authenticate_1([
            'username' => $loginName,
            'password' => $password,
        ]);
    } catch (\Throwable $e) {
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

    $redirect = '/wms';
    echo json_encode(['ok' => true, 'redirect' => $redirect]);
}

function wmsLogout(): void
{
    wmsClearAuthCookie();
    unset($_SESSION['wms_user']);
    app()->redirect('/wms/login');
}

function wmsForgotPasswordPage(): void
{
    $redirect = wmsAuthenticatedHomeRedirect();
    if (is_string($redirect) && $redirect !== '') {
        app()->redirect($redirect);
        return;
    }

    echo wmsRender('modules/wms/pages/forgot-password.disyl', wmsLoginPageContext([
        'page_title' => 'Forgot Password',
        'forgot_password_endpoint' => wmsBaseUrl() . '/api/v1/wms/auth/forgot-password',
        'login_page_url' => wmsBaseUrl() . '/wms/login',
    ]));
}

function wmsResetPasswordPage(): void
{
    $redirect = wmsAuthenticatedHomeRedirect();
    if (is_string($redirect) && $redirect !== '') {
        app()->redirect($redirect);
        return;
    }

    $token = trim((string)($_GET['token'] ?? ''));

    echo wmsRender('modules/wms/pages/reset-password.disyl', wmsLoginPageContext([
        'page_title' => 'Reset Password',
        'reset_password_endpoint' => wmsBaseUrl() . '/api/v1/wms/auth/reset-password',
        'login_page_url' => wmsBaseUrl() . '/wms/login',
        'reset_token' => $token,
        'token_valid' => wmsResetTokenIsValid($token),
    ]));
}

function wmsResetTokenIsValid(string $token): bool
{
    if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) return false;

    try {
        $stmt = wmsDb()->prepare(
            'SELECT id FROM wms_password_resets
             WHERE token_hash = :hash AND used_at IS NULL AND expires_at > NOW()
             LIMIT 1'
        );
        $stmt->execute([':hash' => wmsPasswordResetTokenHash($token)]);
        return (bool)$stmt->fetch(\PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        return false;
    }
}

function wmsApiForgotPassword(): void
{
    $policy = kernel_password_reset_policy();
    $ttlMinutes = max(1, (int)$policy['token_ttl_minutes']);
    $input = wmsInput();
    $identity = trim((string)($input['identity'] ?? ''));
    if ($identity === '') {
        wmsJsonError('Username or email is required.');
    }

    $requestIp = (string)($_SERVER['REMOTE_ADDR'] ?? '');

    try {
        $stmt = wmsDb()->prepare(
            'SELECT id, username, email, full_name
             FROM wms_users
             WHERE (username = :username OR email = :email) AND is_active = 1
             LIMIT 1'
        );
        $stmt->execute([':username' => $identity, ':email' => $identity]);
        $user = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (is_array($user)) {
            $rawToken = bin2hex(random_bytes(32));
            $tokenHash = wmsPasswordResetTokenHash($rawToken);

            $clear = wmsDb()->prepare(
                'UPDATE wms_password_resets SET used_at = NOW() WHERE user_id = :uid AND used_at IS NULL'
            );
            $clear->execute([':uid' => (int)$user['id']]);

            $insert = wmsDb()->prepare(
                'INSERT INTO wms_password_resets (user_id, token_hash, requester_ip, expires_at, created_at)
                 VALUES (:uid, :hash, :ip, DATE_ADD(NOW(), INTERVAL ' . $ttlMinutes . ' MINUTE), NOW())'
            );
            $insert->execute([
                ':uid' => (int)$user['id'],
                ':hash' => $tokenHash,
                ':ip' => $requestIp,
            ]);

            $email = trim((string)($user['email'] ?? ''));
            if ($email !== '' && filter_var($email, \FILTER_VALIDATE_EMAIL)
                && function_exists('buildEmailTemplate') && function_exists('sendEmail')
            ) {
                $displayName = trim((string)($user['full_name'] ?? $user['username'] ?? 'there'));
                $resetUrl = wmsExternalBaseUrl() . '/wms/reset-password?token=' . urlencode($rawToken);
                $content = '<p style="margin:0 0 16px;color:#4b5563;font-size:16px;line-height:1.6;">Hi ' . htmlspecialchars($displayName, \ENT_QUOTES, 'UTF-8') . ',</p>'
                    . '<p style="margin:0 0 16px;color:#4b5563;font-size:16px;line-height:1.6;">A request was made to reset your WMS password.</p>'
                    . '<p style="margin:0 0 16px;color:#4b5563;font-size:16px;line-height:1.6;">This link expires in ' . $ttlMinutes . ' minutes.</p>';
                $body = buildEmailTemplate('Reset Your WMS Password', $content, 'Reset Password', $resetUrl);
                sendEmail($email, 'WMS Password Reset', $body);
            }
        }

        $msg = (string)$policy['forgot_success_message'];
        wmsJsonOk(['message' => $msg ?: 'If an account exists, a reset link has been sent.']);
    } catch (\Throwable $e) {
        if (function_exists('write_log')) {
            write_log('wms forgot-password failed: ' . $e->getMessage(), 'error');
        }
        wmsJsonError('Unable to process request right now.', 500);
    }
}

function wmsApiResetPassword(): void
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

    try {
        $tokenHash = wmsPasswordResetTokenHash($token);
        $stmt = wmsDb()->prepare(
            'SELECT pr.id AS reset_id, pr.user_id
             FROM wms_password_resets pr
             INNER JOIN wms_users u ON u.id = pr.user_id
             WHERE pr.token_hash = :hash AND pr.used_at IS NULL
               AND pr.expires_at > NOW() AND u.is_active = 1
             LIMIT 1'
        );
        $stmt->execute([':hash' => $tokenHash]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            wmsJsonError((string)$policy['invalid_token_message']);
        }

        $hash = password_hash($password, \PASSWORD_DEFAULT);
        wmsDb()->prepare('UPDATE wms_users SET password_hash = :hash, updated_at = NOW() WHERE id = :id')
            ->execute([':hash' => $hash, ':id' => (int)$row['user_id']]);
        wmsDb()->prepare('UPDATE wms_password_resets SET used_at = NOW() WHERE id = :id')
            ->execute([':id' => (int)$row['reset_id']]);

        wmsJsonOk(['message' => 'Password has been reset. You can now sign in.']);
    } catch (\Throwable $e) {
        if (function_exists('write_log')) {
            write_log('wms reset-password failed: ' . $e->getMessage(), 'error');
        }
        wmsJsonError('Unable to process request right now.', 500);
    }
}

// ── Dashboard Page ──

function wmsPageDashboard(): void
{
    $user = wmsCurrentUser();
    $settings = wmsSettings();
    $baseUrl = wmsBaseUrl();
    $wid = (int)($settings['default_warehouse_id'] ?? 0);

    // KPIs
    $totalProducts = (int)wmsDb()->query('SELECT COUNT(*) FROM wms_products WHERE is_active = 1')->fetchColumn();
    $totalStockItems = (int)wmsDb()->query('SELECT COUNT(*) FROM wms_stock WHERE qty_on_hand > 0')->fetchColumn();
    $totalStockQty = (float)wmsDb()->query('SELECT COALESCE(SUM(qty_on_hand), 0) FROM wms_stock')->fetchColumn();
    $lowStockCount = (int)wmsDb()->query('SELECT COUNT(*) FROM wms_stock s JOIN wms_products p ON p.id = s.product_id WHERE s.qty_on_hand <= COALESCE(p.reorder_point, 0) AND s.qty_on_hand > 0')->fetchColumn();
    $pendingDeliveries = (int)wmsDb()->query("SELECT COUNT(*) FROM wms_deliveries WHERE status IN ('expected', 'in_transit')")->fetchColumn();
    $pendingOrders = (int)wmsDb()->query("SELECT COUNT(*) FROM wms_orders WHERE status IN ('pending', 'picking')")->fetchColumn();
    $openTasks = (int)wmsDb()->query("SELECT COUNT(*) FROM wms_tasks WHERE status IN ('open', 'assigned', 'in_progress')")->fetchColumn();
    $recentMovements = wmsDb()->query('SELECT m.*, p.name AS product_name FROM wms_stock_movements m JOIN wms_products p ON p.id = m.product_id ORDER BY m.created_at DESC LIMIT 10')->fetchAll(\PDO::FETCH_ASSOC);

    wmsRenderTemplate('dashboard', [
        'total_products' => $totalProducts,
        'total_stock_items' => $totalStockItems,
        'total_stock_qty' => $totalStockQty,
        'low_stock_count' => $lowStockCount,
        'pending_deliveries' => $pendingDeliveries,
        'pending_orders' => $pendingOrders,
        'open_tasks' => $openTasks,
        'recent_movements' => $recentMovements,
        'page_title' => 'Dashboard — WMS',
    ]);
}

function wmsNavItems(string $role): array
{
    $all = [
        ['label' => 'Dashboard',  'path' => '/wms',              'icon' => '📊'],
        ['label' => 'Receiving',  'path' => '/wms/receiving',     'icon' => '📥'],
        ['label' => 'Picking',    'path' => '/wms/picking',       'icon' => '📦'],
        ['label' => 'Inventory',  'path' => '/wms/inventory',     'icon' => '🏭'],
        ['label' => 'Suppliers',  'path' => '/wms/suppliers',     'icon' => '🚚'],
        ['label' => 'Returns',    'path' => '/wms/returns',       'icon' => '🔄'],
        ['label' => 'Tasks',      'path' => '/wms/tasks',         'icon' => '✅'],
        ['label' => 'Scanner',    'path' => '/wms/scanner',       'icon' => '📷'],
    ];

    if ($role === 'admin') {
        $all[] = ['label' => 'Settings', 'path' => '/wms/settings', 'icon' => '⚙️'];
        $all[] = ['label' => 'Users',    'path' => '/wms/users',    'icon' => '👥'];
    }

    return $all;
}

// ── Admin: Force reset user password ──

function wmsApiAdminResetPassword(): void
{
    $user = wmsCurrentUser(['admin']);
    $input = wmsInput();
    $userId = (int)($input['user_id'] ?? 0);
    $password = trim((string)($input['password'] ?? ''));

    if ($userId <= 0 || $password === '' || strlen($password) < 4) {
        wmsJsonError('Valid user_id and password (min 4 chars) are required.');
    }

    $existing = wmsDb()->query('SELECT id FROM wms_users WHERE id = :id', [':id' => $userId])->fetch(\PDO::FETCH_ASSOC);
    if (!$existing) wmsJsonError('User not found.', 404);

    $hash = password_hash($password, \PASSWORD_DEFAULT);
    wmsDb()->execute('UPDATE wms_users SET password_hash = :hash, updated_at = NOW() WHERE id = :id', [
        ':hash' => $hash, ':id' => $userId,
    ]);
    wmsJsonOk(['user_id' => $userId, 'message' => 'Password reset successfully.']);
}

// ── Admin: Toggle user active status ──

function wmsApiUserToggle(): void
{
    $user = wmsCurrentUser(['admin']);
    $userId = (int)(wmsInput()['user_id'] ?? 0);
    if ($userId <= 0) wmsJsonError('user_id is required.');

    $existing = wmsDb()->query('SELECT id, is_active, role FROM wms_users WHERE id = :id', [':id' => $userId])->fetch(\PDO::FETCH_ASSOC);
    if (!$existing) wmsJsonError('User not found.', 404);
    if ($existing['role'] === 'admin') {
        $adminCount = (int)wmsDb()->query('SELECT COUNT(*) FROM wms_users WHERE role = \'admin\' AND is_active = 1')->fetchColumn();
        if ($adminCount <= 1 && (int)$existing['is_active'] === 1) {
            wmsJsonError('Cannot deactivate the last active admin.');
        }
    }

    $newActive = (int)$existing['is_active'] ? 0 : 1;
    wmsDb()->execute('UPDATE wms_users SET is_active = :active, updated_at = NOW() WHERE id = :id', [
        ':active' => $newActive, ':id' => $userId,
    ]);
    wmsJsonOk(['user_id' => $userId, 'is_active' => (bool)$newActive]);
}
