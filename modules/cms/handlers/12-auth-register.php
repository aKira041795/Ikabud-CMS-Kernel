<?php

declare(strict_types=1);

const CMS_REGISTER_VERIFICATION_PURPOSE = 'student_signup';

function cmsRegisterPage(array $params = []): void
{
    $u = cmsCtxUser();
    if (is_array($u) && (($u['source'] ?? '') === 'cms' || (($u['source'] ?? '') === 'kernel' && ($u['role'] ?? '') === 'admin'))) {
        cmsRedirect(kernelResolveAuthenticatedHomeRedirect($u, true) ?? '/cms/admin');
        return;
    }

    $redirect = cmsAuthRequestedRedirectPath();

    echo cmsRender('modules/cms/pages/register.disyl', [
        'page_title' => 'Create Account',
        'login_url' => cmsAuthPublicPageUrl('/cms/login', $redirect),
        'redirect_path' => $redirect,
    ]);
}

function cmsAuthRequestedRedirectPath(): string
{
    $redirect = trim((string)($_GET['redirect'] ?? ''));
    if ($redirect === '' || $redirect[0] !== '/' || str_starts_with($redirect, '//')) {
        return '';
    }

    return $redirect;
}

function cmsAuthPublicRedirectTarget(?string $redirect, string $fallback = '/cms/admin'): string
{
    $redirect = trim((string)$redirect);
    if ($redirect !== '' && $redirect[0] === '/' && !str_starts_with($redirect, '//')) {
        return $redirect;
    }

    return $fallback;
}

function cmsAuthPublicPageUrl(string $path, string $redirect = ''): string
{
    $path = $path !== '' ? $path : '/cms/login';
    if ($redirect === '') {
        return cmsExternalBaseUrl() . $path;
    }

    return cmsExternalBaseUrl() . $path . '?redirect=' . urlencode($redirect);
}

function cmsAuthRegistrationCompletionRedirect(string $redirect): string
{
    $redirect = cmsAuthPublicRedirectTarget($redirect, '/cms/admin');
    if (!str_contains($redirect, '/enroll')) {
        return $redirect;
    }

    $parts = parse_url($redirect);
    $path = trim((string)($parts['path'] ?? ''));
    if ($path === '' || $path[0] !== '/') {
        return $redirect;
    }

    $query = [];
    parse_str((string)($parts['query'] ?? ''), $query);
    $query['registered'] = '1';

    $rebuilt = $path;
    $queryString = http_build_query($query);
    if ($queryString !== '') {
        $rebuilt .= '?' . $queryString;
    }
    if (!empty($parts['fragment'])) {
        $rebuilt .= '#' . $parts['fragment'];
    }

    return $rebuilt;
}

function cmsAuthMaskedEmail(string $email): string
{
    $email = trim(strtolower($email));
    if ($email === '' || !str_contains($email, '@')) {
        return 'your email address';
    }

    [$local, $domain] = explode('@', $email, 2);
    $localLen = strlen($local);
    if ($localLen <= 2) {
        $local = str_repeat('*', $localLen);
    } else {
        $local = substr($local, 0, 1) . str_repeat('*', max(1, $localLen - 2)) . substr($local, -1);
    }

    return $local . '@' . $domain;
}

function cmsAuthVerificationRateLimitSnapshot(string $scope, string $value): array
{
    $normalized = strtolower(trim($value));
    if ($normalized === '') {
        $normalized = 'unknown';
    }

    $key = 'cms_auth_verification:' . $scope . ':' . sha1($normalized);
    $cached = app()->cache()->get('security_rate_limits', $key);
    if (!is_array($cached)) {
        return ['key' => $key, 'count' => 0];
    }

    return [
        'key' => $key,
        'count' => max(0, (int)($cached['count'] ?? 0)),
    ];
}

function cmsAuthVerificationRateLimitExceeded(string $ip, string $email, string $action): bool
{
    $ipState = cmsAuthVerificationRateLimitSnapshot($action . ':ip', $ip !== '' ? $ip : 'unknown');
    if ((int)$ipState['count'] >= 10) {
        return true;
    }

    $emailState = cmsAuthVerificationRateLimitSnapshot($action . ':email', $email);
    return (int)$emailState['count'] >= ($action === 'issue' ? 3 : 10);
}

function cmsAuthVerificationRateLimitRecord(string $ip, string $email, string $action): void
{
    $entries = [
        cmsAuthVerificationRateLimitSnapshot($action . ':ip', $ip !== '' ? $ip : 'unknown'),
        cmsAuthVerificationRateLimitSnapshot($action . ':email', $email),
    ];

    foreach ($entries as $entry) {
        app()->cache()->set(
            'security_rate_limits',
            (string)$entry['key'],
            ['count' => ((int)($entry['count'] ?? 0)) + 1],
            900
        );
    }
}

function cmsAuthIssueSession(array $user): void
{
    $payload = [
        'sub' => 'cms:' . (int)($user['id'] ?? 0),
        'id' => (int)($user['id'] ?? 0),
        'username' => (string)($user['username'] ?? ''),
        'name' => (string)($user['display_name'] ?? ''),
        'email' => (string)($user['email'] ?? ''),
        'role' => (string)($user['role'] ?? 'subscriber'),
        'source' => 'cms',
        'token_version' => 0,
    ];

    $resolvedTid = app()->tenant()->current();
    if ($resolvedTid !== null) {
        $payload['tenant_id'] = $resolvedTid;
    }

    $token = app()->jwt()->generate($payload);
    $cookieName = config('app.cookie_name', 'app_token');
    $expiry = time() + (int)config('app.jwt.expiration', 86400);

    setcookie($cookieName, $token, [
        'expires' => $expiry,
        'path' => '/',
        'httponly' => true,
        'secure' => is_https(),
        'samesite' => config('cookie.samesite', 'Strict'),
    ]);

    app()->csrfRotate(true);
}

function cmsAuthVerificationLookup(string $rawToken): ?array
{
    if ($rawToken === '' || !preg_match('/^[a-f0-9]{64}$/', $rawToken)) {
        return null;
    }

    $stmt = cmsDb()->prepare(
        'SELECT id, purpose, email, token_hash, code_hash, payload_json, requester_ip, attempts, expires_at, verified_at
         FROM cms_auth_verifications
         WHERE token_hash = :token_hash
           AND purpose = :purpose
           AND verified_at IS NULL
         LIMIT 1'
    );
    $stmt->execute([
        ':token_hash' => hash('sha256', $rawToken),
        ':purpose' => CMS_REGISTER_VERIFICATION_PURPOSE,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

function cmsAuthSendRegistrationCode(string $email, string $displayName, string $code, string $redirect): bool
{
    $baseUrl = cmsExternalBaseUrl();
    $resumeCopy = $redirect !== ''
        ? 'After approval, we will return the student to ' . htmlspecialchars($redirect, ENT_QUOTES, 'UTF-8') . ' so enrollment can continue.'
        : 'After approval, the student can continue into the CMS and Moodle enrollment flow.';

    $content = '<p style="margin:0 0 16px;color:#4b5563;font-size:16px;line-height:1.6;">Hi ' . htmlspecialchars($displayName !== '' ? $displayName : 'there', ENT_QUOTES, 'UTF-8') . ',</p>'
        . '<p style="margin:0 0 16px;color:#4b5563;font-size:16px;line-height:1.6;">Use this 6-digit verification code to finish creating your student account.</p>'
        . '<div style="margin:0 0 20px;padding:18px 20px;border-radius:12px;background:#eff6ff;border:1px solid #bfdbfe;text-align:center;">'
        . '<div style="color:#1d4ed8;font-size:12px;letter-spacing:0.16em;text-transform:uppercase;font-weight:700;margin-bottom:8px;">Verification Code</div>'
        . '<div style="font-size:32px;line-height:1;font-weight:800;letter-spacing:0.28em;color:#0f172a;">' . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '</div>'
        . '</div>'
        . '<p style="margin:0 0 12px;color:#4b5563;font-size:16px;line-height:1.6;">This code expires in 15 minutes.</p>'
        . '<p style="margin:0;color:#4b5563;font-size:16px;line-height:1.6;">' . $resumeCopy . '</p>';

    return sendEmail($email, 'Your CMS Student Verification Code', buildEmailTemplate('Verify Your Student Account', $content, 'Open Sign In', $baseUrl . '/cms/login'));
}

function cmsAuthGenerateUniqueUsername(string $displayName, string $email): string
{
    $seed = $displayName !== '' ? $displayName : strstr($email, '@', true);
    $seed = strtolower(trim((string)$seed));
    $seed = preg_replace('/[^a-z0-9]+/', '-', $seed) ?? '';
    $seed = trim($seed, '-');
    if ($seed === '') {
        $seed = 'student';
    }

    $db = cmsDb();
    $candidate = substr($seed, 0, 40);
    $suffix = 1;

    while (true) {
        $stmt = $db->prepare('SELECT id FROM cms_users WHERE username = :username LIMIT 1');
        $stmt->execute([':username' => $candidate]);
        if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
            return $candidate;
        }

        $suffix++;
        $candidate = substr($seed, 0, max(1, 40 - strlen((string)$suffix) - 1)) . '-' . $suffix;
    }
}

function cmsApiRegister(array $params = []): void
{
    header('Content-Type: application/json');

    $input = cmsInput();
    $displayName = trim((string)($input['display_name'] ?? ''));
    $email = strtolower(trim((string)($input['email'] ?? '')));
    $password = (string)($input['password'] ?? '');
    $confirm = (string)($input['confirm_password'] ?? '');
    $redirect = cmsAuthPublicRedirectTarget((string)($input['redirect'] ?? ''), '/cms/admin');

    if ($displayName === '' || $email === '' || $password === '') {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Full name, email, and password are required.']);
        exit;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Enter a valid email address.']);
        exit;
    }

    if (strlen($password) < 8) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Password must be at least 8 characters.']);
        exit;
    }

    if ($password !== $confirm) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Passwords do not match.']);
        exit;
    }

    $requestIp = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    if (cmsAuthVerificationRateLimitExceeded($requestIp, $email, 'issue')) {
        http_response_code(429);
        echo json_encode(['ok' => false, 'error' => 'Too many verification attempts. Please wait and try again.']);
        exit;
    }
    cmsAuthVerificationRateLimitRecord($requestIp, $email, 'issue');

    try {
        $db = cmsDb();
        $check = $db->prepare('SELECT id FROM cms_users WHERE email = :email LIMIT 1');
        $check->execute([':email' => $email]);
        if ($check->fetch(PDO::FETCH_ASSOC)) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'An account with that email already exists. Please sign in instead.']);
            exit;
        }

        $db->prepare('DELETE FROM cms_auth_verifications WHERE purpose = :purpose AND email = :email')->execute([
            ':purpose' => CMS_REGISTER_VERIFICATION_PURPOSE,
            ':email' => $email,
        ]);

        $rawToken = bin2hex(random_bytes(32));
        $code = (string)random_int(100000, 999999);
        $payload = json_encode([
            'display_name' => $displayName,
            'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'redirect' => $redirect,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $insert = $db->prepare(
            'INSERT INTO cms_auth_verifications (purpose, email, token_hash, code_hash, payload_json, requester_ip, expires_at, created_at)
             VALUES (:purpose, :email, :token_hash, :code_hash, :payload_json, :requester_ip, DATE_ADD(NOW(), INTERVAL 15 MINUTE), NOW())'
        );
        $insert->execute([
            ':purpose' => CMS_REGISTER_VERIFICATION_PURPOSE,
            ':email' => $email,
            ':token_hash' => hash('sha256', $rawToken),
            ':code_hash' => hash('sha256', $code),
            ':payload_json' => $payload,
            ':requester_ip' => $requestIp,
        ]);

        if (!cmsAuthSendRegistrationCode($email, $displayName, $code, $redirect)) {
            $db->prepare('DELETE FROM cms_auth_verifications WHERE token_hash = :token_hash')->execute([
                ':token_hash' => hash('sha256', $rawToken),
            ]);
            write_log('cms register verification email dispatch failed for email=' . $email, 'error');
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'We could not send the verification code right now.']);
            exit;
        }

        echo json_encode([
            'ok' => true,
            'requires_verification' => true,
            'ticket' => $rawToken,
            'masked_email' => cmsAuthMaskedEmail($email),
            'message' => 'We sent a verification code to ' . cmsAuthMaskedEmail($email) . '.',
        ]);
        exit;
    } catch (Throwable $e) {
        write_log('cms register failed: ' . $e->getMessage(), 'error');
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Unable to start registration right now.']);
        exit;
    }
}

function cmsApiRegisterResend(array $params = []): void
{
    header('Content-Type: application/json');

    $input = cmsInput();
    $ticket = trim((string)($input['ticket'] ?? ''));
    $verification = cmsAuthVerificationLookup($ticket);
    if (!is_array($verification)) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Registration verification has expired. Please start again.']);
        exit;
    }

    if (strtotime((string)($verification['expires_at'] ?? '')) <= time()) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Verification code expired. Please start again.']);
        exit;
    }

    $email = strtolower(trim((string)($verification['email'] ?? '')));
    $requestIp = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    if (cmsAuthVerificationRateLimitExceeded($requestIp, $email, 'resend')) {
        http_response_code(429);
        echo json_encode(['ok' => false, 'error' => 'Too many resend attempts. Please wait and try again.']);
        exit;
    }
    cmsAuthVerificationRateLimitRecord($requestIp, $email, 'resend');

    $payload = json_decode((string)($verification['payload_json'] ?? ''), true);
    $displayName = is_array($payload) ? trim((string)($payload['display_name'] ?? '')) : '';
    $redirect = is_array($payload) ? cmsAuthPublicRedirectTarget((string)($payload['redirect'] ?? ''), '/cms/admin') : '/cms/admin';
    $code = (string)random_int(100000, 999999);

    try {
        $update = cmsDb()->prepare(
            'UPDATE cms_auth_verifications
             SET code_hash = :code_hash,
                 requester_ip = :requester_ip,
                 attempts = 0,
                 expires_at = DATE_ADD(NOW(), INTERVAL 15 MINUTE)
             WHERE id = :id'
        );
        $update->execute([
            ':code_hash' => hash('sha256', $code),
            ':requester_ip' => $requestIp,
            ':id' => (int)$verification['id'],
        ]);

        if (!cmsAuthSendRegistrationCode($email, $displayName, $code, $redirect)) {
            write_log('cms register resend email dispatch failed for email=' . $email, 'error');
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'We could not resend the verification code right now.']);
            exit;
        }

        echo json_encode([
            'ok' => true,
            'requires_verification' => true,
            'ticket' => $ticket,
            'masked_email' => cmsAuthMaskedEmail($email),
            'message' => 'A new verification code was sent to ' . cmsAuthMaskedEmail($email) . '.',
        ]);
        exit;
    } catch (Throwable $e) {
        write_log('cms register resend failed: ' . $e->getMessage(), 'error');
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Unable to resend the verification code right now.']);
        exit;
    }
}

function cmsApiRegisterVerify(array $params = []): void
{
    header('Content-Type: application/json');

    $input = cmsInput();
    $ticket = trim((string)($input['ticket'] ?? ''));
    $code = preg_replace('/\D+/', '', (string)($input['code'] ?? '')) ?? '';
    if ($code === '') {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Verification code is required.']);
        exit;
    }

    $verification = cmsAuthVerificationLookup($ticket);
    if (!is_array($verification)) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Registration verification has expired. Please start again.']);
        exit;
    }

    if (strtotime((string)($verification['expires_at'] ?? '')) <= time()) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Verification code expired. Please start again.']);
        exit;
    }

    $email = strtolower(trim((string)($verification['email'] ?? '')));
    $requestIp = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    if (cmsAuthVerificationRateLimitExceeded($requestIp, $email, 'verify')) {
        http_response_code(429);
        echo json_encode(['ok' => false, 'error' => 'Too many verification attempts. Please wait and try again.']);
        exit;
    }
    cmsAuthVerificationRateLimitRecord($requestIp, $email, 'verify');

    if (!hash_equals((string)($verification['code_hash'] ?? ''), hash('sha256', $code))) {
        cmsDb()->prepare('UPDATE cms_auth_verifications SET attempts = attempts + 1 WHERE id = :id')->execute([':id' => (int)$verification['id']]);
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Invalid verification code.']);
        exit;
    }

    $payload = json_decode((string)($verification['payload_json'] ?? ''), true);
    if (!is_array($payload)) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Registration details are no longer available. Please start again.']);
        exit;
    }

    $redirect = cmsAuthRegistrationCompletionRedirect((string)($payload['redirect'] ?? ''));

    try {
        $db = cmsDb();
        $db->beginTransaction();

        $check = $db->prepare('SELECT id FROM cms_users WHERE email = :email LIMIT 1');
        $check->execute([':email' => $email]);
        if ($check->fetch(PDO::FETCH_ASSOC)) {
            $db->rollBack();
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'An account with that email already exists. Please sign in instead.']);
            exit;
        }

        $displayName = trim((string)($payload['display_name'] ?? ''));
        $username = cmsAuthGenerateUniqueUsername($displayName, $email);
        $insert = $db->prepare(
            'INSERT INTO cms_users (username, email, password_hash, display_name, role, is_active, created_at)
             VALUES (:username, :email, :password_hash, :display_name, :role, 1, NOW())'
        );
        $insert->execute([
            ':username' => $username,
            ':email' => $email,
            ':password_hash' => (string)($payload['password_hash'] ?? ''),
            ':display_name' => $displayName !== '' ? $displayName : $username,
            ':role' => 'subscriber',
        ]);
        $newId = (int)$db->lastInsertId();

        $db->prepare('UPDATE cms_auth_verifications SET verified_at = NOW() WHERE id = :id')->execute([':id' => (int)$verification['id']]);
        $db->commit();

        if ($ctx = module('cms')) {
            $ctx->fireEvent('cms.user.created', [
                'user_id' => $newId,
                'username' => $username,
                'role' => 'subscriber',
            ]);
        }

        if (function_exists('cmsAssignUserService') && preg_match('#^/cms/course/\d+/(enroll|launch)(?:\?|$)#', $redirect) === 1) {
            cmsAssignUserService($newId, 'elearning', true, ['origin' => 'cms_register_redirect']);
        }

        cmsAuthIssueSession([
            'id' => $newId,
            'username' => $username,
            'email' => $email,
            'display_name' => $displayName !== '' ? $displayName : $username,
            'role' => 'subscriber',
        ]);

        echo json_encode([
            'ok' => true,
            'message' => 'Account verified. You can continue enrollment now.',
            'redirect' => $redirect,
        ]);
        exit;
    } catch (Throwable $e) {
        if (cmsDb()->inTransaction()) {
            cmsDb()->rollBack();
        }
        write_log('cms register verify failed: ' . $e->getMessage(), 'error');
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Unable to complete registration right now.']);
        exit;
    }
}