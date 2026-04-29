<?php

declare(strict_types=1);

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'applicationos.test';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/cms/login';
$_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'GET';

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../src/helpers/email.php';
require_once __DIR__ . '/../modules/cms/helpers.php';
require_once __DIR__ . '/../modules/cms/handlers.php';

ob_start();

$pass = 0;
$fail = 0;
$errors = [];

function ctReset(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $errors;

    if ($ok) {
        $pass++;
        echo "  ✓ {$label}\n";
        return;
    }

    $fail++;
    $errors[] = $label . ($detail !== '' ? ': ' . $detail : '');
    echo "  ✗ {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

function runCmsAuthRequest(string $handlerName, string $requestUri, array $post): array
{
    $runnerPath = sys_get_temp_dir() . '/ikabud-cms-reset-runner-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.php';
    $statusPath = sys_get_temp_dir() . '/ikabud-cms-reset-status-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.txt';

    $bootstrap = var_export(__DIR__ . '/../bootstrap.php', true);
    $moduleManager = var_export(__DIR__ . '/../src/helpers/module-manager.php', true);
    $emailHelpers = var_export(__DIR__ . '/../src/helpers/email.php', true);
    $helpers = var_export(__DIR__ . '/../modules/cms/helpers.php', true);
    $handlers = var_export(__DIR__ . '/../modules/cms/handlers.php', true);
    $handlerExport = var_export($handlerName, true);
    $requestUriExport = var_export($requestUri, true);
    $postExport = var_export($post, true);
    $statusExport = var_export($statusPath, true);

    $runner = <<<PHP
<?php
require {$bootstrap};
require_once {$moduleManager};
require_once {$emailHelpers};
require_once {$helpers};
require_once {$handlers};

\$_SERVER['HTTP_HOST'] = 'applicationos.test';
\$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
\$_SERVER['REQUEST_METHOD'] = 'POST';
\$_SERVER['REQUEST_URI'] = {$requestUriExport};
\$_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
\$_GET = [];
\$_POST = {$postExport};

\$statusPath = {$statusExport};

register_shutdown_function(static function () use (\$statusPath): void {
    @file_put_contents(\$statusPath, (string)(http_response_code() ?: 200));
});

moduleWithContext('cms', static function (): void {
    call_user_func({$handlerExport});
});
PHP;

    file_put_contents($runnerPath, $runner);

    $output = [];
    $exitCode = 0;
    exec('php ' . escapeshellarg($runnerPath) . ' 2>&1', $output, $exitCode);

    $status = is_file($statusPath) ? (int)trim((string)file_get_contents($statusPath)) : 0;

    @unlink($runnerPath);
    @unlink($statusPath);

    $body = implode("\n", $output);
    return [
        'status' => $status,
        'body' => $body,
        'json' => json_decode($body, true),
        'exit_code' => $exitCode,
    ];
}

$appLogPath = STORAGE_PATH . '/logs/app.log';
$errorLogPath = STORAGE_PATH . '/logs/error.log';
@file_put_contents($appLogPath, '');
@file_put_contents($errorLogPath, '');
$appLogStart = is_file($appLogPath) ? max(0, (int)@filesize($appLogPath)) : 0;
$errorLogStart = is_file($errorLogPath) ? max(0, (int)@filesize($errorLogPath)) : 0;

echo "\n=== CMS PASSWORD RESET TEST ===\n\n";

$policy = kernel_password_reset_policy();
$ttlMinutes = (int)$policy['token_ttl_minutes'];
app()->cache()->clear('security_rate_limits');

$routes = require BASE_PATH . '/modules/cms/routes.php';

$db = cmsDb();
$runner = new \Ikabud\Kernel\Database\MigrationRunner(app()->db());
$runner->migrate('cms');

$username = 'cms-reset-' . bin2hex(random_bytes(4));
$email = $username . '@example.test';

$db->prepare('DELETE FROM cms_password_resets WHERE user_id IN (SELECT id FROM cms_users WHERE username = :u OR email = :e)')->execute([
    ':u' => $username,
    ':e' => $email,
]);
$db->prepare('DELETE FROM cms_users WHERE username = :u OR email = :e')->execute([
    ':u' => $username,
    ':e' => $email,
]);

$db->prepare(
    'INSERT INTO cms_users (username, email, password_hash, display_name, role, is_active, created_at)
     VALUES (:u, :e, :h, :d, :r, 1, NOW())'
)->execute([
    ':u' => $username,
    ':e' => $email,
    ':h' => password_hash('StartPass123', PASSWORD_DEFAULT),
    ':d' => 'CMS Reset User',
    ':r' => 'author',
]);
$userId = (int)$db->lastInsertId();

try {
    echo "── Routes ──\n";
    ctReset('forgot password page route declared', ($routes['GET']['/cms/forgot-password'] ?? '') === 'cms:cmsForgotPasswordPage');
    ctReset('reset password page route declared', ($routes['GET']['/cms/reset-password'] ?? '') === 'cms:cmsResetPasswordPage');
    ctReset('forgot password API route declared', ($routes['POST']['/api/v1/cms/auth/forgot-password'] ?? '') === 'cms:cmsApiForgotPassword');
    ctReset('reset password API route declared', ($routes['POST']['/api/v1/cms/auth/reset-password'] ?? '') === 'cms:cmsApiResetPassword');

    echo "\n── Pages ──\n";
    app()->setUser([]);
    ob_start();
    moduleWithContext('cms', static function (): void {
        cmsForgotPasswordPage();
    });
    $forgotHtml = (string)ob_get_clean();
    ctReset('forgot password page posts to canonical API', str_contains($forgotHtml, '/api/v1/cms/auth/forgot-password'));

    $validRawToken = bin2hex(random_bytes(32));
    $db->prepare(
        'INSERT INTO cms_password_resets (user_id, token_hash, requester_ip, expires_at, created_at) VALUES (:uid, :hash, :ip, DATE_ADD(NOW(), INTERVAL ' . $ttlMinutes . ' MINUTE), NOW())'
    )->execute([
        ':uid' => $userId,
        ':hash' => hash('sha256', $validRawToken),
        ':ip' => '127.0.0.1',
    ]);

    $_GET['token'] = $validRawToken;
    ob_start();
    moduleWithContext('cms', static function (): void {
        cmsResetPasswordPage();
    });
    $resetHtml = (string)ob_get_clean();
    unset($_GET['token']);
    ctReset('reset password page renders valid token form', str_contains($resetHtml, 'Reset Password') && str_contains($resetHtml, '/api/v1/cms/auth/reset-password') && str_contains($resetHtml, "tokenValid: '1'"));

    $_GET['token'] = 'invalid';
    ob_start();
    moduleWithContext('cms', static function (): void {
        cmsResetPasswordPage();
    });
    $invalidHtml = (string)ob_get_clean();
    unset($_GET['token']);
    ctReset('reset password page renders invalid token state', str_contains($invalidHtml, 'invalid or expired'));

    echo "\n── API ──\n";
    $db->prepare('DELETE FROM cms_password_resets WHERE user_id = :uid')->execute([':uid' => $userId]);
    $staleRawToken = bin2hex(random_bytes(32));
    $db->prepare(
        'INSERT INTO cms_password_resets (user_id, token_hash, requester_ip, expires_at, created_at) VALUES (:uid, :hash, :ip, DATE_ADD(NOW(), INTERVAL ' . $ttlMinutes . ' MINUTE), NOW())'
    )->execute([
        ':uid' => $userId,
        ':hash' => hash('sha256', $staleRawToken),
        ':ip' => '127.0.0.1',
    ]);

    $forgotResponse = runCmsAuthRequest('cmsApiForgotPassword', '/api/v1/cms/auth/forgot-password', ['identity' => $username]);
    ctReset('forgot password API exits cleanly', ($forgotResponse['exit_code'] ?? 1) === 0, json_encode($forgotResponse, JSON_UNESCAPED_SLASHES));
    ctReset('forgot password API returns generic success', (int)($forgotResponse['status'] ?? 0) === 200 && (($forgotResponse['json']['ok'] ?? false) === true) && (($forgotResponse['json']['message'] ?? '') === $policy['forgot_success_message']), json_encode($forgotResponse, JSON_UNESCAPED_SLASHES));

    $rowsStmt = $db->prepare(
        'SELECT token_hash, used_at, expires_at, created_at
         FROM cms_password_resets
         WHERE user_id = :uid
         ORDER BY id DESC
         LIMIT 2'
    );
    $rowsStmt->execute([':uid' => $userId]);
    $rows = $rowsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $latestRow = $rows[0] ?? [];
    $staleRow = $rows[1] ?? [];
    ctReset('forgot password API keeps only the newest token active', is_array($latestRow) && (($latestRow['used_at'] ?? null) === null) && is_array($staleRow) && !empty($staleRow['used_at']), json_encode($rows, JSON_UNESCAPED_SLASHES));
    ctReset('forgot password API stores hashed token values', strlen((string)($latestRow['token_hash'] ?? '')) === 64, json_encode($latestRow, JSON_UNESCAPED_SLASHES));
    $createdAt = strtotime((string)($latestRow['created_at'] ?? ''));
    $expiresAt = strtotime((string)($latestRow['expires_at'] ?? ''));
    $ttlSeconds = ($createdAt > 0 && $expiresAt > 0) ? ($expiresAt - $createdAt) : 0;
    ctReset('forgot password API uses the shared 30-minute TTL', $ttlSeconds >= 1700 && $ttlSeconds <= 1810, (string)$ttlSeconds);

    app()->cache()->clear('security_rate_limits');
    $staleResetResponse = runCmsAuthRequest('cmsApiResetPassword', '/api/v1/cms/auth/reset-password', [
        'token' => $staleRawToken,
        'password' => 'RenewedPass456',
        'confirm_password' => 'RenewedPass456',
    ]);
    ctReset('stale token is rejected after a newer request', (int)($staleResetResponse['status'] ?? 0) === 422 && (($staleResetResponse['json']['error'] ?? '') === $policy['invalid_token_message']), json_encode($staleResetResponse, JSON_UNESCAPED_SLASHES));
    app()->cache()->clear('security_rate_limits');

    $rawResetToken = bin2hex(random_bytes(32));
    $db->prepare('DELETE FROM cms_password_resets WHERE user_id = :uid')->execute([':uid' => $userId]);
    $db->prepare(
        'INSERT INTO cms_password_resets (user_id, token_hash, requester_ip, expires_at, created_at) VALUES (:uid, :hash, :ip, DATE_ADD(NOW(), INTERVAL ' . $ttlMinutes . ' MINUTE), NOW())'
    )->execute([
        ':uid' => $userId,
        ':hash' => hash('sha256', $rawResetToken),
        ':ip' => '127.0.0.1',
    ]);

    $resetResponse = runCmsAuthRequest('cmsApiResetPassword', '/api/v1/cms/auth/reset-password', [
        'token' => $rawResetToken,
        'password' => 'RenewedPass456',
        'confirm_password' => 'RenewedPass456',
    ]);
    ctReset('reset password API returns success', (int)($resetResponse['status'] ?? 0) === 200 && (($resetResponse['json']['ok'] ?? false) === true) && (($resetResponse['json']['message'] ?? '') === $policy['reset_success_message']), json_encode($resetResponse, JSON_UNESCAPED_SLASHES));

    $hashStmt = $db->prepare('SELECT password_hash FROM cms_users WHERE id = :uid LIMIT 1');
    $hashStmt->execute([':uid' => $userId]);
    $updatedHash = (string)($hashStmt->fetchColumn() ?: '');
    ctReset('reset password API updates the user password hash', $updatedHash !== '' && password_verify('RenewedPass456', $updatedHash));

    $usedStmt = $db->prepare('SELECT used_at FROM cms_password_resets WHERE user_id = :uid ORDER BY id DESC LIMIT 1');
    $usedStmt->execute([':uid' => $userId]);
    $usedAt = $usedStmt->fetchColumn();
    ctReset('reset password API marks the token used', is_string($usedAt) && trim($usedAt) !== '', (string)$usedAt);

    app()->cache()->clear('security_rate_limits');
    $reusedResponse = runCmsAuthRequest('cmsApiResetPassword', '/api/v1/cms/auth/reset-password', [
        'token' => $rawResetToken,
        'password' => 'AnotherPass456',
        'confirm_password' => 'AnotherPass456',
    ]);
    ctReset('reused token is rejected', (int)($reusedResponse['status'] ?? 0) === 422 && (($reusedResponse['json']['error'] ?? '') === $policy['invalid_token_message']), json_encode($reusedResponse, JSON_UNESCAPED_SLASHES));

    $expiredRawToken = bin2hex(random_bytes(32));
    $db->prepare(
        'INSERT INTO cms_password_resets (user_id, token_hash, requester_ip, expires_at, created_at) VALUES (:uid, :hash, :ip, DATE_SUB(NOW(), INTERVAL 5 MINUTE), DATE_SUB(NOW(), INTERVAL 35 MINUTE))'
    )->execute([
        ':uid' => $userId,
        ':hash' => hash('sha256', $expiredRawToken),
        ':ip' => '127.0.0.1',
    ]);
    app()->cache()->clear('security_rate_limits');
    $expiredResponse = runCmsAuthRequest('cmsApiResetPassword', '/api/v1/cms/auth/reset-password', [
        'token' => $expiredRawToken,
        'password' => 'ExpiredPass456',
        'confirm_password' => 'ExpiredPass456',
    ]);
    ctReset('expired token is rejected with the shared message', (int)($expiredResponse['status'] ?? 0) === 422 && (($expiredResponse['json']['error'] ?? '') === $policy['invalid_token_message']), json_encode($expiredResponse, JSON_UNESCAPED_SLASHES));
} finally {
    app()->setUser([]);
    $_GET = [];
    $_POST = [];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/cms/login';
    $_SERVER['CONTENT_TYPE'] = '';
    $db->prepare('DELETE FROM cms_password_resets WHERE user_id = :uid')->execute([':uid' => $userId]);
    $db->prepare('DELETE FROM cms_users WHERE id = :uid')->execute([':uid' => $userId]);
}

$appLogRaw = (string)@file_get_contents($appLogPath);
$errorLogRaw = (string)@file_get_contents($errorLogPath);
$appLog = trim($appLogStart > 0 ? (string)substr($appLogRaw, $appLogStart) : $appLogRaw);
$errorLog = trim($errorLogStart > 0 ? (string)substr($errorLogRaw, $errorLogStart) : $errorLogRaw);
ctReset('no app.log errors', $appLog === '' || !str_contains(strtolower($appLog), 'error'), $appLog);
ctReset('no error.log errors', $errorLog === '', $errorLog);

echo "\n" . str_repeat('─', 50) . "\n";
echo "  Result: {$pass} passed, {$fail} failed\n";
if ($errors !== []) {
    echo "\n  Failures:\n";
    foreach ($errors as $error) {
        echo "    • {$error}\n";
    }
}
echo "\n";

ob_end_flush();

exit($fail > 0 ? 1 : 0);