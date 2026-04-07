<?php

declare(strict_types=1);

function wmsPageLogin(array $params = []): void
{
    $user = app()->user();
    if (is_array($user)) {
        $home = kernelResolveAuthenticatedHomeRedirect($user, true) ?? '/wms';
        app()->redirect($home);
        return;
    }

    echo app()->render('pages/login.disyl', wmsLoginPageContext());
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
        echo json_encode(['ok' => false, 'error' => 'Username and password are required.']);
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
