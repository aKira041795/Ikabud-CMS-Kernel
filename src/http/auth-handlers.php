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
        $authResult = app()->cap()->call('kernel.auth.authenticate@1', [
            'username' => $username,
            'password' => $password,
        ], ['mode' => 'pipeline', 'strict_pipeline' => false]);

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
        $stmt = app()->db()->prepare(
            'SELECT rt.id, rt.user_id, rt.expires_at, rt.revoked,
                    u.username, u.full_name, u.role, u.is_active
             FROM refresh_tokens rt
             INNER JOIN users u ON u.id = rt.user_id
             WHERE rt.token_hash = :token_hash
             LIMIT 1'
        );
        $stmt->execute([':token_hash' => $tokenHash]);
        $rtRow = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Database error.']);
        exit;
    }

    if (!is_array($rtRow)) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Invalid refresh token.']);
        exit;
    }

    if ($rtRow['revoked'] || $rtRow['expires_at'] <= date('Y-m-d H:i:s') || !$rtRow['is_active']) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Refresh token expired or revoked.']);
        exit;
    }

    // Revoke old refresh token (rotation)
    $revokeStmt = app()->db()->prepare('UPDATE refresh_tokens SET revoked = 1 WHERE id = :id');
    $revokeStmt->execute([':id' => (int) $rtRow['id']]);

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
