<?php

declare(strict_types=1);

function cmsForgotPasswordPage(array $params = []): void
{
    $u = cmsCtxUser();
    if (is_array($u) && (($u['source'] ?? '') === 'cms' || (($u['source'] ?? '') === 'kernel' && ($u['role'] ?? '') === 'admin'))) {
        cmsRedirect('/cms/admin');
        return;
    }

    echo cmsRender('modules/cms/pages/forgot-password.disyl', [
        'page_title' => 'Forgot Password',
    ]);
}

function cmsResetPasswordPage(array $params = []): void
{
    $u = cmsCtxUser();
    if (is_array($u) && (($u['source'] ?? '') === 'cms' || (($u['source'] ?? '') === 'kernel' && ($u['role'] ?? '') === 'admin'))) {
        cmsRedirect('/cms/admin');
        return;
    }

    $token = trim((string)($_GET['token'] ?? ''));
    $tokenValid = false;

    if ($token !== '' && preg_match('/^[a-f0-9]{64}$/', $token)) {
        try {
            $db = cmsDb();
            $stmt = $db->prepare(
                'SELECT id FROM cms_password_resets WHERE token_hash = :h AND used_at IS NULL AND expires_at > NOW() LIMIT 1'
            );
            $stmt->execute([':h' => hash('sha256', $token)]);
            $tokenValid = (bool)$stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $tokenValid = false;
        }
    }

    echo cmsRender('modules/cms/pages/reset-password.disyl', [
        'page_title' => 'Reset Password',
        'reset_token' => $token,
        'token_valid' => $tokenValid,
    ]);
}

function cmsApiForgotPassword(array $params = []): void
{
    header('Content-Type: application/json');

    $input = cmsInput();
    $identity = trim((string)($input['identity'] ?? ''));
    if ($identity === '') {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Username or email is required.']);
        exit;
    }

    try {
        $db = cmsDb();
        $stmt = $db->prepare(
            'SELECT id, username, email, display_name
             FROM cms_users
             WHERE (username = :i1 OR email = :i2) AND is_active = 1
             LIMIT 1'
        );
        $stmt->execute([':i1' => $identity, ':i2' => $identity]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (is_array($user)) {
            $rawToken = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $rawToken);
            $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');

            $ins = $db->prepare(
                'INSERT INTO cms_password_resets (user_id, token_hash, requester_ip, expires_at, created_at)
                 VALUES (:uid, :hash, :ip, DATE_ADD(NOW(), INTERVAL 60 MINUTE), NOW())'
            );
            $ins->execute([
                ':uid' => (int)$user['id'],
                ':hash' => $tokenHash,
                ':ip' => $ip,
            ]);

            $email = trim((string)($user['email'] ?? ''));
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $name = trim((string)($user['display_name'] ?? $user['username'] ?? 'there'));
                $baseUrl = cmsExternalBaseUrl();
                $resetUrl = $baseUrl . '/cms/reset-password?token=' . urlencode($rawToken);

                $content = '<p style="margin:0 0 16px;color:#4b5563;font-size:16px;line-height:1.6;">Hi ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . ',</p>'
                    . '<p style="margin:0 0 16px;color:#4b5563;font-size:16px;line-height:1.6;">A request was made to reset your CMS password.</p>'
                    . '<p style="margin:0 0 16px;color:#4b5563;font-size:16px;line-height:1.6;">This link expires in 60 minutes. If you did not request this, you can safely ignore this email.</p>';

                $body = buildEmailTemplate('Reset Your CMS Password', $content, 'Reset Password', $resetUrl);
                $sent = sendEmail($email, 'CMS Password Reset', $body);
                if (!$sent) {
                    write_log('cms forgot-password email dispatch failed for user_id=' . (string)$user['id'], 'error');
                }
            }
        }

        // Always return generic success to avoid account enumeration.
        echo json_encode([
            'ok' => true,
            'message' => 'If the account exists, a reset link has been sent.',
        ]);
        exit;
    } catch (Throwable $e) {
        write_log('cms forgot-password failed: ' . $e->getMessage(), 'error');
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Unable to process request right now.']);
        exit;
    }
}

function cmsApiResetPassword(array $params = []): void
{
    header('Content-Type: application/json');

    $input = cmsInput();
    $token = trim((string)($input['token'] ?? ''));
    $password = (string)($input['password'] ?? '');
    $confirm = (string)($input['confirm_password'] ?? '');

    if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Invalid reset token.']);
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

    try {
        $db = cmsDb();
        $tokenHash = hash('sha256', $token);

        $stmt = $db->prepare(
            'SELECT pr.id AS reset_id, pr.user_id
             FROM cms_password_resets pr
             INNER JOIN cms_users u ON u.id = pr.user_id
             WHERE pr.token_hash = :h
               AND pr.used_at IS NULL
               AND pr.expires_at > NOW()
               AND u.is_active = 1
             LIMIT 1'
        );
        $stmt->execute([':h' => $tokenHash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Reset link is invalid or expired.']);
            exit;
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);

        $updUser = $db->prepare('UPDATE cms_users SET password_hash = :ph WHERE id = :uid');
        $updUser->execute([
            ':ph' => $hash,
            ':uid' => (int)$row['user_id'],
        ]);

        $updReset = $db->prepare('UPDATE cms_password_resets SET used_at = NOW() WHERE id = :id');
        $updReset->execute([':id' => (int)$row['reset_id']]);

        echo json_encode([
            'ok' => true,
            'message' => 'Password reset successful. You can now sign in.',
            'redirect' => '/cms/login',
        ]);
        exit;
    } catch (Throwable $e) {
        write_log('cms reset-password failed: ' . $e->getMessage(), 'error');
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Unable to reset password right now.']);
        exit;
    }
}

function cmsApiTestResetEmail(array $params = []): void
{
    header('Content-Type: application/json');
    cmsRequireCap('settings.manage');
    app()->csrfEnforce();

    $user = cmsCtxUser();
    if (!is_array($user)) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Auth required']);
        exit;
    }

    $userId = (int)($user['id'] ?? 0);
    if ($userId <= 0) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Unable to resolve current admin user.']);
        exit;
    }

    try {
        $db = cmsDb();
        $stmt = $db->prepare('SELECT username, email, display_name, is_active FROM cms_users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $userId]);
        $dbUser = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($dbUser) || (int)($dbUser['is_active'] ?? 0) !== 1) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Current admin account is not active.']);
            exit;
        }

        $email = trim((string)($dbUser['email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Current admin email is missing or invalid.']);
            exit;
        }

        $rawToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');

        $ins = $db->prepare(
            'INSERT INTO cms_password_resets (user_id, token_hash, requester_ip, expires_at, created_at)
             VALUES (:uid, :hash, :ip, DATE_ADD(NOW(), INTERVAL 60 MINUTE), NOW())'
        );
        $ins->execute([
            ':uid' => $userId,
            ':hash' => $tokenHash,
            ':ip' => $ip,
        ]);

        $name = trim((string)($dbUser['display_name'] ?? $dbUser['username'] ?? 'there'));
        $baseUrl = cmsExternalBaseUrl();
        $resetUrl = $baseUrl . '/cms/reset-password?token=' . urlencode($rawToken);

        $content = '<p style="margin:0 0 16px;color:#4b5563;font-size:16px;line-height:1.6;">Hi ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . ',</p>'
            . '<p style="margin:0 0 16px;color:#4b5563;font-size:16px;line-height:1.6;">This is a test reset-password email from CMS admin.</p>'
            . '<p style="margin:0 0 16px;color:#4b5563;font-size:16px;line-height:1.6;">If this arrives, SMTP wiring for password reset is working.</p>';

        $body = buildEmailTemplate('Test CMS Password Reset Email', $content, 'Open Reset Link', $resetUrl);
        $sent = sendEmail($email, 'CMS Password Reset (Test)', $body);

        if (!$sent) {
            write_log('cms test-reset-email dispatch failed for user_id=' . (string)$userId, 'error');
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Email send failed. Check logs.']);
            exit;
        }

        write_log('cms test-reset-email sent to ' . $email . ' for user_id=' . (string)$userId, 'info');
        echo json_encode([
            'ok' => true,
            'message' => 'Test reset email sent to ' . $email,
            'to' => $email,
        ]);
        exit;
    } catch (Throwable $e) {
        write_log('cms test-reset-email failed: ' . $e->getMessage(), 'error');
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Unable to send test reset email right now.']);
        exit;
    }
}
