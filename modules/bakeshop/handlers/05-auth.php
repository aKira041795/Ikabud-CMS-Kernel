<?php

declare(strict_types=1);

function bakeshopLoginRateLimitState(?int $maxAttempts = null, ?int $windowSeconds = null): array
{
    $maxAttempts = max(1, (int)($maxAttempts ?? kernelLoginRateLimitMaxAttempts()));
    $windowSeconds = max(1, (int)($windowSeconds ?? kernelLoginRateLimitWindowSeconds()));
    $identifier = kernelLoginRateLimitIdentifier('bakeshop');
    $action = 'login';

    try {
        $tenantId = app()->tenant()->current();
        $db = $tenantId !== null ? app()->dbForTenant((int)$tenantId) : app()->db();
        if (!$db instanceof PDO) {
            throw new RuntimeException('Unable to resolve bakeshop login rate limit database.');
        }

        $cutoff = date('Y-m-d H:i:s', time() - $windowSeconds);
        \Ikabud\Kernel\Database\KernelPDO::kernelEscalationEnter();
        try {
            $db->prepare(
                'INSERT INTO rate_limits (identifier, action, attempts, window_start)
                 VALUES (:id, :action, 1, CURRENT_TIMESTAMP)
                 ON DUPLICATE KEY UPDATE
                     attempts = IF(window_start >= :cutoff, attempts + 1, 1),
                     window_start = IF(window_start >= :cutoff2, window_start, CURRENT_TIMESTAMP)'
            )->execute([
                ':id' => $identifier,
                ':action' => $action,
                ':cutoff' => $cutoff,
                ':cutoff2' => $cutoff,
            ]);

            $statement = $db->prepare(
                'SELECT attempts, window_start FROM rate_limits WHERE identifier = :id AND action = :action LIMIT 1'
            );
            $statement->execute([':id' => $identifier, ':action' => $action]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
        } finally {
            \Ikabud\Kernel\Database\KernelPDO::kernelEscalationLeave();
        }

        if (is_array($row) && ($row['window_start'] ?? '') >= $cutoff && (int)($row['attempts'] ?? 0) > $maxAttempts) {
            return [
                'limited' => true,
                'retry_after' => max(1, $windowSeconds - (time() - strtotime((string)$row['window_start']))),
                'identifier' => $identifier,
                'module_id' => 'bakeshop',
                'action' => $action,
                'max_attempts' => $maxAttempts,
                'window_seconds' => $windowSeconds,
                'enforced' => true,
            ];
        }
    } catch (Throwable $ignored) {
        return [
            'limited' => false,
            'retry_after' => 0,
            'identifier' => $identifier,
            'module_id' => 'bakeshop',
            'action' => $action,
            'max_attempts' => $maxAttempts,
            'window_seconds' => $windowSeconds,
            'enforced' => false,
        ];
    }

    return [
        'limited' => false,
        'retry_after' => 0,
        'identifier' => $identifier,
        'module_id' => 'bakeshop',
        'action' => $action,
        'max_attempts' => $maxAttempts,
        'window_seconds' => $windowSeconds,
        'enforced' => true,
    ];
}

function bakeshopPageLogin(array $params = []): void
{
    $kernelJwtCookie = (string)config('app.jwt.cookie', 'token');
    $hasAuthHint = isset($_SERVER['HTTP_AUTHORIZATION'])
        || isset($_COOKIE[bakeshopCookieName()])
        || isset($_COOKIE[$kernelJwtCookie]);

    if ($hasAuthHint) {
        $user = app()->user();
        if (is_array($user) && (bakeshopIsModuleUser($user) || bakeshopIsKernelSuperadmin($user))) {
            $home = kernelResolveAuthenticatedHomeRedirect($user, true) ?? '/admin/bakeshop';
            app()->redirect($home);
            return;
        }
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