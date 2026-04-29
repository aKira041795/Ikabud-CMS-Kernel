<?php

declare(strict_types=1);

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'applicationos.test';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/guidance/login';
$_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'GET';

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';

ob_start();

$pass = 0;
$fail = 0;
$errors = [];

function gtReset(string $label, bool $ok, string $detail = ''): void
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

function guidanceRunRequest(callable $handler, string $method, string $uri, array $get = [], array $post = []): array
{
    $_SERVER['REQUEST_METHOD'] = strtoupper($method);
    $_SERVER['REQUEST_URI'] = $uri;
    $_SERVER['CONTENT_TYPE'] = $post === [] ? '' : 'application/x-www-form-urlencoded';
    $_GET = $get;
    $_POST = $post;
    http_response_code(200);

    ob_start();
    moduleWithContext('guidance', static function () use ($handler): void {
        $handler();
    });
    $body = (string)ob_get_clean();

    return [
        'status' => (int)(http_response_code() ?: 200),
        'body' => $body,
        'json' => json_decode($body, true),
    ];
}

$appLogPath = STORAGE_PATH . '/logs/app.log';
$errorLogPath = STORAGE_PATH . '/logs/error.log';
@file_put_contents($appLogPath, '');
@file_put_contents($errorLogPath, '');
$appLogStart = is_file($appLogPath) ? max(0, (int)@filesize($appLogPath)) : 0;
$errorLogStart = is_file($errorLogPath) ? max(0, (int)@filesize($errorLogPath)) : 0;

echo "\n=== GUIDANCE PASSWORD RESET TEST ===\n\n";

$modules = discoverModules();
$guidance = $modules['guidance'] ?? null;
if (!is_array($guidance)) {
    fwrite(STDERR, "Guidance module manifest not found.\n");
    exit(1);
}

loadModuleHelpers($guidance);
moduleWithContext('guidance', static function () use ($guidance): void {
    require_once (string)($guidance['_path'] ?? '') . '/handlers.php';
});

$routes = require BASE_PATH . '/modules/guidance/routes.php';

$db = app()->db();
$runner = new \Ikabud\Kernel\Database\MigrationRunner($db);
$runner->migrate('guidance');

$uiEmail = 'guidance-reset-ui-' . bin2hex(random_bytes(4)) . '@example.test';
$apiEmail = 'guidance-reset-api-' . bin2hex(random_bytes(4)) . '@example.test';

$cleanupEmailStmt = $db->prepare('DELETE FROM gm_password_resets WHERE email IN (?, ?)');
$cleanupUserStmt = $db->prepare('DELETE FROM gm_users WHERE email IN (?, ?)');
$cleanupRateStmt = $db->prepare("DELETE FROM gm_rate_limits WHERE rate_key LIKE 'guidance_forgot:%' OR rate_key LIKE 'guidance_reset:%'");

$cleanupEmailStmt->execute([$uiEmail, $apiEmail]);
$cleanupUserStmt->execute([$uiEmail, $apiEmail]);
$cleanupRateStmt->execute();

$insertUser = $db->prepare(
    'INSERT INTO gm_users (email, password, first_name, last_name, role, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 1, NOW(), NOW())'
);
$insertUser->execute([$uiEmail, password_hash('startpass123', PASSWORD_DEFAULT), 'Guidance', 'UI', 'counselor']);
$insertUser->execute([$apiEmail, password_hash('startpass123', PASSWORD_DEFAULT), 'Guidance', 'API', 'counselor']);

try {
    echo "── Routes ──\n";
    gtReset('forgot password page route declared', ($routes['GET']['/guidance/forgot-password'] ?? '') === 'guidance:pageGuidanceForgotPassword');
    gtReset('reset password page route declared', ($routes['GET']['/guidance/reset-password'] ?? '') === 'guidance:pageGuidanceResetPassword');
    gtReset('canonical forgot password API route declared', ($routes['POST']['/api/v1/guidance/auth/forgot-password'] ?? '') === 'guidance:apiGuidanceForgotPassword');
    gtReset('canonical reset password API route declared', ($routes['POST']['/api/v1/guidance/auth/reset-password'] ?? '') === 'guidance:apiGuidanceResetPassword');
    gtReset('legacy forgot password API alias retained', ($routes['POST']['/guidance/api/auth/forgot-password'] ?? '') === 'guidance:apiGuidanceForgotPassword');
    gtReset('legacy reset password API alias retained', ($routes['POST']['/guidance/api/auth/reset-password'] ?? '') === 'guidance:apiGuidanceResetPassword');

    echo "\n── Templates ──\n";
    $loginHtml = guidanceRunRequest(static function (): void {
        pageGuidanceLogin();
    }, 'GET', '/guidance/login')['body'];
    gtReset('login page renders forgot password link', str_contains($loginHtml, '/guidance/forgot-password'));

    $forgotPageHtml = guidanceRunRequest(static function (): void {
        pageGuidanceForgotPassword();
    }, 'GET', '/guidance/forgot-password')['body'];
    gtReset('forgot password page posts to canonical API', str_contains($forgotPageHtml, '/api/v1/guidance/auth/forgot-password'));
    gtReset('forgot password page links back to login', str_contains($forgotPageHtml, '/guidance/login'));

    $validToken = bin2hex(random_bytes(32));
    $db->prepare(
        'INSERT INTO gm_password_resets (email, token, expires_at, created_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 60 MINUTE), NOW())'
    )->execute([$uiEmail, hash('sha256', $validToken)]);

    $resetPageHtml = guidanceRunRequest(static function (): void {
        pageGuidanceResetPassword();
    }, 'GET', '/guidance/reset-password?token=' . $validToken, ['token' => $validToken])['body'];
    gtReset('reset password page renders valid token form', str_contains($resetPageHtml, 'Reset Password') && !str_contains($resetPageHtml, 'invalid or expired'));
    gtReset('reset password page posts to canonical API', str_contains($resetPageHtml, '/api/v1/guidance/auth/reset-password'));

    $invalidResetHtml = guidanceRunRequest(static function (): void {
        pageGuidanceResetPassword();
    }, 'GET', '/guidance/reset-password?token=invalid', ['token' => 'invalid'])['body'];
    gtReset('reset password page shows invalid token recovery state', str_contains($invalidResetHtml, 'invalid or expired'));

    echo "\n── API ──\n";
    $forgotResponse = guidanceRunRequest(static function (): void {
        apiGuidanceForgotPassword();
    }, 'POST', '/api/v1/guidance/auth/forgot-password', [], ['email' => $apiEmail]);
    gtReset('forgot password API returns success', (int)($forgotResponse['status'] ?? 0) === 200 && (($forgotResponse['json']['ok'] ?? false) === true), json_encode($forgotResponse, JSON_UNESCAPED_SLASHES));

    $tokenRowStmt = $db->prepare('SELECT token, used_at FROM gm_password_resets WHERE email = ? ORDER BY id DESC LIMIT 1');
    $tokenRowStmt->execute([$apiEmail]);
    $forgotRow = $tokenRowStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    gtReset('forgot password API creates hashed token row', strlen((string)($forgotRow['token'] ?? '')) === 64, json_encode($forgotRow, JSON_UNESCAPED_SLASHES));
    gtReset('forgot password API leaves token unused', ($forgotRow['used_at'] ?? null) === null, json_encode($forgotRow, JSON_UNESCAPED_SLASHES));

    $resetToken = bin2hex(random_bytes(32));
    $db->prepare('DELETE FROM gm_password_resets WHERE email = ?')->execute([$apiEmail]);
    $db->prepare(
        'INSERT INTO gm_password_resets (email, token, expires_at, created_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 60 MINUTE), NOW())'
    )->execute([$apiEmail, hash('sha256', $resetToken)]);
    $cleanupRateStmt->execute();

    $resetResponse = guidanceRunRequest(static function (): void {
        apiGuidanceResetPassword();
    }, 'POST', '/api/v1/guidance/auth/reset-password', [], [
        'token' => $resetToken,
        'password' => 'renewedpass456',
        'confirm_password' => 'renewedpass456',
    ]);
    gtReset('reset password API returns success', (int)($resetResponse['status'] ?? 0) === 200 && (($resetResponse['json']['ok'] ?? false) === true), json_encode($resetResponse, JSON_UNESCAPED_SLASHES));
    gtReset('reset password API returns login redirect', ($resetResponse['json']['redirect'] ?? '') === '/guidance/login', json_encode($resetResponse, JSON_UNESCAPED_SLASHES));

    $userStmt = $db->prepare('SELECT password FROM gm_users WHERE email = ? LIMIT 1');
    $userStmt->execute([$apiEmail]);
    $updatedHash = (string)($userStmt->fetchColumn() ?: '');
    gtReset('reset password API updates password hash', $updatedHash !== '' && password_verify('renewedpass456', $updatedHash));

    $usedStmt = $db->prepare('SELECT used_at FROM gm_password_resets WHERE email = ? ORDER BY id DESC LIMIT 1');
    $usedStmt->execute([$apiEmail]);
    $usedAt = $usedStmt->fetchColumn();
    gtReset('reset password API marks token used', is_string($usedAt) && trim($usedAt) !== '', (string)$usedAt);

    $auth = guidance_cap_kernel_auth_authenticate_1([
        'username' => '@guidance:' . $apiEmail,
        'password' => 'renewedpass456',
    ]);
    gtReset('reset password API allows auth with new password', is_array($auth) && (($auth['source'] ?? '') === 'guidance'), json_encode($auth, JSON_UNESCAPED_SLASHES));
} finally {
    $_GET = [];
    $_POST = [];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/guidance/login';
    $_SERVER['CONTENT_TYPE'] = '';

    $cleanupEmailStmt->execute([$uiEmail, $apiEmail]);
    $cleanupUserStmt->execute([$uiEmail, $apiEmail]);
    $cleanupRateStmt->execute();
}

$appLogRaw = (string)@file_get_contents($appLogPath);
$errorLogRaw = (string)@file_get_contents($errorLogPath);
$appLog = trim($appLogStart > 0 ? (string)substr($appLogRaw, $appLogStart) : $appLogRaw);
$errorLog = trim($errorLogStart > 0 ? (string)substr($errorLogRaw, $errorLogStart) : $errorLogRaw);
gtReset('no app.log errors', $appLog === '' || !str_contains(strtolower($appLog), 'error'), $appLog);
gtReset('no error.log errors', $errorLog === '', $errorLog);

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