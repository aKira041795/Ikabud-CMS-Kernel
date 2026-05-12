<?php

declare(strict_types=1);

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'applicationos.test';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/daily-ledger/login';

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../src/http/core-routes.php';
require_once __DIR__ . '/../modules/daily-ledger/helpers.php';
require_once __DIR__ . '/../modules/daily-ledger/handlers.php';

ob_start();

$policy = kernel_password_reset_policy();
$ttlMinutes = (int)$policy['token_ttl_minutes'];

$pass = 0;
$fail = 0;
$errors = [];

function dtReset(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $errors;

    if ($ok) {
        $pass++;
        echo "  [PASS] {$label}\n";
        return;
    }

    $fail++;
    $errors[] = $label . ($detail !== '' ? ': ' . $detail : '');
    echo "  [FAIL] {$label}" . ($detail !== '' ? " -- {$detail}" : '') . "\n";
}

function dailyLedgerRenderPage(callable $handler, string $method, string $uri, array $get = []): string
{
    $_SERVER['REQUEST_METHOD'] = strtoupper($method);
    $_SERVER['REQUEST_URI'] = $uri;
    $_GET = $get;
    $_POST = [];
    http_response_code(200);

    ob_start();
    moduleWithContext('daily-ledger', static function () use ($handler): void {
        $handler();
    });
    return (string)ob_get_clean();
}

function runDailyLedgerAuthJsonRequest(string $handlerName, string $requestUri, string $rawBody): array
{
    $patchedAppPath = sys_get_temp_dir() . '/ikabud-dl-auth-app-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.php';
    $runnerPath = sys_get_temp_dir() . '/ikabud-dl-auth-runner-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.php';

    $appSource = (string)file_get_contents(__DIR__ . '/../kernel/App.php');
    $replacement = "file_get_contents('data://text/plain," . rawurlencode($rawBody) . "')";
    $appSource = str_replace("file_get_contents('php://input')", $replacement, $appSource);
    file_put_contents($patchedAppPath, $appSource);

    $bootstrap = var_export(__DIR__ . '/../bootstrap.php', true);
    $moduleManager = var_export(__DIR__ . '/../src/helpers/module-manager.php', true);
    $coreRoutes = var_export(__DIR__ . '/../src/http/core-routes.php', true);
    $helpers = var_export(__DIR__ . '/../modules/daily-ledger/helpers.php', true);
    $handlers = var_export(__DIR__ . '/../modules/daily-ledger/handlers.php', true);
    $migrationRunner = var_export('\\Ikabud\\Kernel\\Database\\MigrationRunner', true);
    $patchedApp = var_export($patchedAppPath, true);
    $handlerExport = var_export($handlerName, true);
    $requestUriExport = var_export($requestUri, true);

    $runner = <<<PHP
<?php
require {$patchedApp};
require {$bootstrap};
require_once {$moduleManager};
require_once {$coreRoutes};
require_once {$helpers};
require_once {$handlers};

\$_SERVER['HTTP_HOST'] = 'applicationos.test';
\$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
\$_SERVER['REQUEST_METHOD'] = 'POST';
\$_SERVER['REQUEST_URI'] = {$requestUriExport};
\$_SERVER['CONTENT_TYPE'] = 'application/json';
\$_GET = [];
\$_POST = [];

\$migrationRunner = {$migrationRunner};
\$runner = new \$migrationRunner(app()->db());
\$runner->migrate('daily-ledger');
loadModuleRoutes(kernelCoreRoutes());
http_response_code(200);
ob_start();
register_shutdown_function(static function (): void {
    \$body = (string)ob_get_contents();
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    echo json_encode(['status' => (int)(http_response_code() ?: 200), 'body' => \$body], JSON_UNESCAPED_SLASHES);
});
moduleWithContext('daily-ledger', static function () {
    call_user_func({$handlerExport});
});
PHP;

    file_put_contents($runnerPath, $runner);

    $output = [];
    $exitCode = 0;
    exec('php ' . escapeshellarg($runnerPath) . ' 2>&1', $output, $exitCode);

    @unlink($runnerPath);
    @unlink($patchedAppPath);

    $decoded = json_decode(implode("\n", $output), true);
    if (!is_array($decoded)) {
        return [
            'status' => $exitCode === 0 ? 0 : $exitCode,
            'body' => implode("\n", $output),
            'json' => null,
            'exit_code' => $exitCode,
        ];
    }

    $body = (string)($decoded['body'] ?? '');
    $decoded['json'] = json_decode($body, true);
    $decoded['exit_code'] = $exitCode;
    return $decoded;
}

echo "\n=== DAILY LEDGER PASSWORD RESET TEST ===\n\n";

$db = app()->db();
$runner = new \Ikabud\Kernel\Database\MigrationRunner($db);
$runner->migrate('daily-ledger');
loadModuleRoutes(kernelCoreRoutes());

$routes = require BASE_PATH . '/modules/daily-ledger/routes.php';

$suffix = bin2hex(random_bytes(4));
$username = 'dl-reset-' . $suffix;
$email = 'dl-reset-' . $suffix . '@example.test';

$cleanupResetStmt = $db->prepare('DELETE pr FROM dl_password_resets pr INNER JOIN dl_users du ON du.id = pr.user_id WHERE du.username = ? OR du.email = ?');
$cleanupUserStmt = $db->prepare('DELETE FROM dl_users WHERE username = ? OR email = ?');
$cleanupResetStmt->execute([$username, $email]);
$cleanupUserStmt->execute([$username, $email]);

$insertUser = $db->prepare(
    'INSERT INTO dl_users (username, email, password_hash, full_name, role, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 1, NOW(), NOW())'
);
$insertUser->execute([$username, $email, password_hash('startpass123', PASSWORD_DEFAULT), 'Daily Ledger Reset User', 'admin']);
$userId = (int)$db->lastInsertId();

try {
    dtReset('forgot password page route declared', ($routes['GET']['/daily-ledger/forgot-password'] ?? '') === 'daily-ledger:pageDailyLedgerForgotPassword');
    dtReset('reset password page route declared', ($routes['GET']['/daily-ledger/reset-password'] ?? '') === 'daily-ledger:pageDailyLedgerResetPassword');
    dtReset('forgot password API route declared', ($routes['POST']['/daily-ledger/api/v1/auth/forgot-password'] ?? '') === 'daily-ledger:dailyLedgerForgotPassword');
    dtReset('reset password API route declared', ($routes['POST']['/daily-ledger/api/v1/auth/reset-password'] ?? '') === 'daily-ledger:dailyLedgerResetPassword');

    $loginHtml = dailyLedgerRenderPage(static function (): void {
        pageDailyLedgerLogin();
    }, 'GET', '/daily-ledger/login');
    dtReset('login page renders username-or-email label', str_contains($loginHtml, 'Username or Email'));
    dtReset('login page renders forgot-password link', str_contains($loginHtml, '/daily-ledger/forgot-password'));

    $forgotHtml = dailyLedgerRenderPage(static function (): void {
        pageDailyLedgerForgotPassword();
    }, 'GET', '/daily-ledger/forgot-password');
    dtReset('forgot password page posts to Daily Ledger API', str_contains($forgotHtml, '/daily-ledger/api/v1/auth/forgot-password'));
    dtReset('forgot password page accepts username or email copy', str_contains($forgotHtml, 'Username or Email'));

    $rawToken = bin2hex(random_bytes(32));
    $db->prepare('DELETE FROM dl_password_resets WHERE user_id = ?')->execute([$userId]);
    $db->prepare(
        'INSERT INTO dl_password_resets (user_id, token_hash, requester_ip, expires_at, created_at) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ' . $ttlMinutes . ' MINUTE), NOW())'
    )->execute([$userId, hash('sha256', $rawToken), '127.0.0.1']);

    $resetHtml = dailyLedgerRenderPage(static function (): void {
        pageDailyLedgerResetPassword();
    }, 'GET', '/daily-ledger/reset-password?token=' . $rawToken, ['token' => $rawToken]);
    dtReset('reset password page renders valid token form', str_contains($resetHtml, 'Reset Password') && !str_contains($resetHtml, 'invalid or expired'));

    $usernameAuth = app()->cap()->call('kernel.auth.authenticate@1', [
        'username' => '@daily-ledger:' . $username,
        'password' => 'startpass123',
    ], ['mode' => 'pipeline']);
    dtReset(
        'Daily Ledger login accepts username',
        is_array($usernameAuth)
            && ($usernameAuth['source'] ?? '') === 'daily-ledger'
            && is_array($usernameAuth['user'] ?? null)
            && (($usernameAuth['user']['username'] ?? '') === $username),
        json_encode($usernameAuth, JSON_UNESCAPED_SLASHES)
    );

    $emailAuth = app()->cap()->call('kernel.auth.authenticate@1', [
        'username' => '@daily-ledger:' . $email,
        'password' => 'startpass123',
    ], ['mode' => 'pipeline']);
    dtReset(
        'Daily Ledger login accepts email',
        is_array($emailAuth)
            && ($emailAuth['source'] ?? '') === 'daily-ledger'
            && is_array($emailAuth['user'] ?? null)
            && (($emailAuth['user']['username'] ?? '') === $username),
        json_encode($emailAuth, JSON_UNESCAPED_SLASHES)
    );

    $db->prepare('DELETE FROM dl_password_resets WHERE user_id = ?')->execute([$userId]);
    $forgotResponse = runDailyLedgerAuthJsonRequest(
        'dailyLedgerForgotPassword',
        '/daily-ledger/api/v1/auth/forgot-password',
        json_encode(['identity' => $username], JSON_UNESCAPED_SLASHES)
    );
    dtReset(
        'forgot password API returns generic success',
        (int)($forgotResponse['status'] ?? 0) === 200
            && (($forgotResponse['json']['ok'] ?? false) === true)
            && (($forgotResponse['json']['message'] ?? '') === $policy['forgot_success_message']),
        json_encode($forgotResponse, JSON_UNESCAPED_SLASHES)
    );

    $resetRowStmt = $db->prepare('SELECT token_hash, used_at, expires_at, created_at FROM dl_password_resets WHERE user_id = ? ORDER BY id DESC LIMIT 1');
    $resetRowStmt->execute([$userId]);
    $forgotRow = $resetRowStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    dtReset('forgot password API creates hashed token row', strlen((string)($forgotRow['token_hash'] ?? '')) === 64, json_encode($forgotRow, JSON_UNESCAPED_SLASHES));

    $resetToken = bin2hex(random_bytes(32));
    $db->prepare('DELETE FROM dl_password_resets WHERE user_id = ?')->execute([$userId]);
    $db->prepare(
        'INSERT INTO dl_password_resets (user_id, token_hash, requester_ip, expires_at, created_at) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ' . $ttlMinutes . ' MINUTE), NOW())'
    )->execute([$userId, hash('sha256', $resetToken), '127.0.0.1']);

    $resetResponse = runDailyLedgerAuthJsonRequest(
        'dailyLedgerResetPassword',
        '/daily-ledger/api/v1/auth/reset-password',
        json_encode([
            'token' => $resetToken,
            'password' => 'renewedpass456',
            'confirm_password' => 'renewedpass456',
        ], JSON_UNESCAPED_SLASHES)
    );
    dtReset(
        'reset password API returns success',
        (int)($resetResponse['status'] ?? 0) === 200 && (($resetResponse['json']['ok'] ?? false) === true),
        json_encode($resetResponse, JSON_UNESCAPED_SLASHES)
    );
    dtReset('reset password API returns login redirect', ($resetResponse['json']['redirect'] ?? '') === '/daily-ledger/login', json_encode($resetResponse, JSON_UNESCAPED_SLASHES));

    $userStmt = $db->prepare('SELECT password_hash FROM dl_users WHERE id = ? LIMIT 1');
    $userStmt->execute([$userId]);
    $updatedHash = (string)($userStmt->fetchColumn() ?: '');
    dtReset('reset password API updates password hash', $updatedHash !== '' && password_verify('renewedpass456', $updatedHash));

    $usedStmt = $db->prepare('SELECT used_at FROM dl_password_resets WHERE user_id = ? ORDER BY id DESC LIMIT 1');
    $usedStmt->execute([$userId]);
    $usedAt = $usedStmt->fetchColumn();
    dtReset('reset password API marks token used', is_string($usedAt) && trim($usedAt) !== '', (string)$usedAt);

    $newPasswordAuth = app()->cap()->call('kernel.auth.authenticate@1', [
        'username' => '@daily-ledger:' . $email,
        'password' => 'renewedpass456',
    ], ['mode' => 'pipeline']);
    dtReset(
        'reset password API allows auth with new password',
        is_array($newPasswordAuth)
            && ($newPasswordAuth['source'] ?? '') === 'daily-ledger'
            && is_array($newPasswordAuth['user'] ?? null),
        json_encode($newPasswordAuth, JSON_UNESCAPED_SLASHES)
    );
} finally {
    $_GET = [];
    $_POST = [];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/daily-ledger/login';
    $_SERVER['CONTENT_TYPE'] = '';

    $cleanupResetStmt->execute([$username, $email]);
    $cleanupUserStmt->execute([$username, $email]);
}

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