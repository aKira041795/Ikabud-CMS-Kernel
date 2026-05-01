<?php
// Extracted Auth Handlers

function pageGuidanceLogin(): void {
    if ((function_exists('guidanceUserFromCookie') && guidanceUserFromCookie()) || app()->isAuthenticated()) {
        guidanceRedirect('/admin/guidance');
    }
    echo guidanceRender('modules/guidance/pages/login.disyl', [
        'hide_sidebar' => true,
        'page_title' => 'Guidance Sign In',
        'base_url' => '/guidance',
        'forgot_password_endpoint' => '/api/v1/guidance/auth/forgot-password',
    ]);
}

function guidanceAuthLogin(): void {
    try {
        $email = guidanceInput('email');
        $password = guidanceInput('password');
        
        if (!$email || !$password) {
            throw new Exception("Email and password are required.");
        }

        // Rate limiting check
        $ip = clientIp();
        if (!rateLimit('login_' . $ip, 5, 300)) {
            throw new Exception("Too many login attempts. Please try again later.");
        }

        // Authenticate via kernel capability pipeline
        $res = app()->cap()->invoke('kernel.auth.authenticate@1', [
            'username' => '@guidance:' . $email,
            'password' => $password
        ]);
        
        $user = null;
        foreach ($res as $result) {
            if (is_array($result) && isset($result['user'])) {
                $user = $result['user'];
                break;
            }
        }
        
        if (!$user) {
            throw new Exception("Invalid email or password.");
        }
        
        // 2FA check (Pro Tier feature, gracefully degrade to normal login if Free)
        if (guidanceIsPro() && guidanceGetSetting('two_fa_login') === '1') {
            // Need to trigger OTP
            app()->json([
                'requires_otp' => true,
                'email' => $email,
                'message' => 'Please enter the verification code sent to your email.'
            ]);
            return;
        }

        guidanceSetAuthCookie($user);
        app()->json(['success' => true, 'redirect' => '/admin/guidance']);
        
    } catch (Throwable $e) {
        app()->json(['error' => $e->getMessage()], 400);
    }
}

function guidanceLogout(): void {
    guidanceClearAuthCookie();
    guidanceRedirect('/admin/guidance/login');
}

// Password Reset Helpers
function guidancePasswordResetTokenHash(string $token): string {
    return hash('sha256', $token);
}

function guidanceIssuePasswordResetToken(string $email, int $ttlSeconds = 3600): string {
    $token = bin2hex(random_bytes(32));
    $db = guidanceDb();
    $db->prepare('UPDATE gm_password_resets SET used_at = NOW() WHERE email = ? AND used_at IS NULL')->execute([$email]);
    $db->prepare(
        'INSERT INTO gm_password_resets (email, token, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))'
    )->execute([$email, guidancePasswordResetTokenHash($token), $ttlSeconds]);
    return $token;
}

function guidanceFindActivePasswordReset(string $token): ?array {
    $stmt = guidanceDb()->prepare(
        'SELECT * FROM gm_password_resets WHERE token = ? AND used_at IS NULL AND expires_at > NOW() ORDER BY created_at DESC LIMIT 1'
    );
    $stmt->execute([guidancePasswordResetTokenHash($token)]);
    $reset = $stmt->fetch(PDO::FETCH_ASSOC);
    return $reset ?: null;
}

function guidanceMarkPasswordResetUsed(int|string $id): void {
    guidanceDb()->prepare('UPDATE gm_password_resets SET used_at = NOW() WHERE id = ?')->execute([$id]);
}

function pageGuidanceForgotPassword(): void {
    if ((function_exists('guidanceUserFromCookie') && guidanceUserFromCookie()) || app()->isAuthenticated()) {
        guidanceRedirect('/admin/guidance');
    }
    echo guidanceRender('modules/guidance/pages/forgot-password.disyl', [
        'hide_sidebar' => true,
        'page_title' => 'Forgot Password',
        'base_url' => '/guidance',
    ]);
}

function pageGuidanceResetPassword(): void {
    if ((function_exists('guidanceUserFromCookie') && guidanceUserFromCookie()) || app()->isAuthenticated()) {
        guidanceRedirect('/admin/guidance');
    }
    $token = guidanceInput('token', '');
    echo guidanceRender('modules/guidance/pages/reset-password.disyl', [
        'hide_sidebar' => true,
        'page_title' => 'Reset Password',
        'base_url' => '/guidance',
        'reset_token' => $token,
    ]);
}

function apiGuidanceForgotPassword(): void {
    try {
        $email = trim(guidanceInput('email', ''));
        if (empty($email)) {
            throw new Exception("Email is required");
        }

        $ip = clientIp();
        if (!rateLimit('guidance_forgot_' . $ip, 3, 900)) {
            throw new Exception("Too many reset requests. Please try again later.");
        }

        $successMsg = 'If an account with that email exists, a password reset link has been sent.';
        
        $stmt = guidanceDb()->prepare("SELECT id, first_name FROM gm_users WHERE email = ? AND deleted_at IS NULL AND is_active = 1 LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        
        if (!$user) {
            app()->json(['success' => true, 'message' => $successMsg]);
            return;
        }
        
        $token = guidanceIssuePasswordResetToken($email, 3600);
        $appUrl = rtrim(config('app.url', ''), '/');
        // Let's use the current host if possible or just relative base + protocol
        $scheme = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $resetUrl = $scheme . "://" . $host . '/guidance/reset-password?token=' . $token;
        
        if (function_exists('sendEmail') && function_exists('buildEmailTemplate')) {
            $content = "
            <p style=\"margin: 0 0 20px; color: #4b5563; font-size: 16px;\">
                Someone requested a password reset for your Guidance Monitoring System account.
            </p>
            <p style=\"margin: 0 0 20px; color: #4b5563; font-size: 16px;\">
                If you did not request this, you can ignore this email.
                This link expires in 1 hour.
            </p>";
            $body = buildEmailTemplate(
                'Reset Your Password',
                $content,
                'Reset Password',
                $resetUrl
            );
            sendEmail($email, 'Password Reset Request', $body);
        } else {
            // fallback if mailer is missing
            error_log("Cannot send password reset email: Mailer helpers missing.");
        }
        
        app()->json(['success' => true, 'message' => $successMsg]);
    } catch (Throwable $e) {
        app()->json(['error' => $e->getMessage()], 400);
    }
}

function apiGuidanceResetPassword(): void {
    try {
        $token = guidanceInput('token', '');
        $password = guidanceInput('password', '');
        $confirm = guidanceInput('password_confirm', '');
        
        if (empty($token)) throw new Exception('Invalid or missing token.');
        if (empty($password)) throw new Exception('Password cannot be empty.');
        if (strlen($password) < 6) throw new Exception('Password must be at least 6 characters.');
        if ($password !== $confirm) throw new Exception('Passwords do not match.');

        $ip = clientIp();
        if (!rateLimit('guidance_reset_' . $ip, 5, 900)) {
            throw new Exception('Too many attempts. Please try again later.');
        }

        $resetData = guidanceFindActivePasswordReset($token);
        if (!$resetData) {
            throw new Exception('Invalid or expired reset token. Please request a new one.');
        }

        $email = $resetData['email'];
        
        // Find user to get ID and update kernel credential if attached
        $stmt = guidanceDb()->prepare("SELECT id FROM gm_users WHERE email = ? AND deleted_at IS NULL AND is_active = 1 LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        if (!$user) {
            throw new Exception('Account not found or inactive.');
        }
        
        $hash = password_hash($password, PASSWORD_DEFAULT);
        guidanceDb()->prepare('UPDATE gm_users SET password = ?, updated_at = NOW() WHERE id = ?')
                   ->execute([$hash, $user['id']]);
                   
        // also try kernel update if we can
        try {
            app()->cap()->invoke('kernel.auth.updatePassword', [
                'username' => '@guidance:' . $email,
                'password' => $password
            ]);
        } catch (Throwable $e) {
            // Ignore error here
        }

        guidanceMarkPasswordResetUsed($resetData['id']);
        
        app()->json([
            'success' => true,
            'message' => 'Password reset successfully. You can now log in.'
        ]);
        
    } catch (Throwable $e) {
        app()->json(['error' => $e->getMessage()], 400);
    }
}
