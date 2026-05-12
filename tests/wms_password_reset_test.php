<?php

declare(strict_types=1);

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'cmsnew.test';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/wms/login';

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../src/http/core-routes.php';
require_once __DIR__ . '/../modules/wms/helpers.php';
require_once __DIR__ . '/../modules/wms/handlers.php';

$passwordResetPolicy = kernel_password_reset_policy();
$passwordResetTtlMinutes = (int)$passwordResetPolicy['token_ttl_minutes'];

$pass = 0;
$fail = 0;
$errors = [];

function wtReset(string $label, bool $ok, string $detail = ''): void
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

function runWmsAuthJsonRequest(string $handlerName, string $requestUri, string $rawBody, string $setupCode = '', string $probeCode = 'return [];'): array
{
    $patchedAppPath = sys_get_temp_dir() . '/ikabud-wms-auth-app-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.php';
    $runnerPath = sys_get_temp_dir() . '/ikabud-wms-auth-runner-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.php';

    $appSource = (string)file_get_contents(__DIR__ . '/../kernel/App.php');
    $replacement = "file_get_contents('data://text/plain," . rawurlencode($rawBody) . "')";
    $appSource = str_replace("file_get_contents('php://input')", $replacement, $appSource);
    file_put_contents($patchedAppPath, $appSource);

    $bootstrap = var_export(__DIR__ . '/../bootstrap.php', true);
    $moduleManager = var_export(__DIR__ . '/../src/helpers/module-manager.php', true);
    $coreRoutes = var_export(__DIR__ . '/../src/http/core-routes.php', true);
    $helpers = var_export(__DIR__ . '/../modules/wms/helpers.php', true);
    $handlers = var_export(__DIR__ . '/../modules/wms/handlers.php', true);
    $migrationRunner = var_export('\\Ikabud\\Kernel\\Database\\MigrationRunner', true);
    $patchedApp = var_export($patchedAppPath, true);
    $handlerExport = var_export($handlerName, true);
    $requestUriExport = var_export($requestUri, true);
    $setupCodeExport = var_export($setupCode, true);
    $probeCodeExport = var_export($probeCode, true);

    $runner = <<<PHP
<?php
require {$patchedApp};
require {$bootstrap};
require_once {$moduleManager};
require_once {$coreRoutes};
require_once {$helpers};
require_once {$handlers};























\$_SERVER['HTTP_HOST'] = 'cmsnew.test';
\$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
\$_SERVER['REQUEST_METHOD'] = 'POST';
\$_SERVER['REQUEST_URI'] = {$requestUriExport};
\$_SERVER['CONTENT_TYPE'] = 'application/json';
\$_GET = [];
\$_POST = [];

\$setupCode = {$setupCodeExport};
if (\$setupCode !== '') {
    eval(\$setupCode);
}































\$migrationRunner = {$migrationRunner};
\$runner = new \$migrationRunner(app()->db());
\$runner->migrate('wms');
\$baseRoutes = kernelCoreRoutes();
loadModuleRoutes(\$baseRoutes);
\$probeCode = {$probeCodeExport};
http_response_code(200);
ob_start();
register_shutdown_function(static function () use (\$probeCode): void {
    \$body = (string)ob_get_contents();
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    \$probe = eval(\$probeCode);
    if (!is_array(\$probe)) {
        \$probe = ['result' => \$probe];
    }
    echo json_encode(['status' => (int)(http_response_code() ?: 200), 'body' => \$body, 'probe' => \$probe], JSON_UNESCAPED_SLASHES);
});
call_user_func({$handlerExport});
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

$appLogPath = STORAGE_PATH . '/logs/app.log';
$errorLogPath = STORAGE_PATH . '/logs/error.log';
@file_put_contents($appLogPath, '');
@file_put_contents($errorLogPath, '');

echo "\n=== WMS PASSWORD RESET TEST ===\n\n";

$kernelDb = app()->db();
$db = wmsDb();
$runner = new \Ikabud\Kernel\Database\MigrationRunner($kernelDb);
$runner->migrate('wms');
loadModuleRoutes(kernelCoreRoutes());

$testUsername = 'wms-reset-user';
$testEmail = 'wms-reset-user@example.test';
$lookup = $db->prepare('SELECT id FROM wms_users WHERE username = ? LIMIT 1');
$lookup->execute([$testUsername]);
$existingId = (int)($lookup->fetchColumn() ?: 0);
if ($existingId > 0) {
    $db->prepare('DELETE FROM wms_password_resets WHERE user_id = ?')->execute([$existingId]);
    $db->prepare('DELETE FROM wms_users WHERE id = ?')->execute([$existingId]);
}

$created = $db->prepare(
    'INSERT INTO wms_users (username, email, phone, password_hash, full_name, role, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, 1, NOW(), NOW())'
);
$created->execute([
    $testUsername,
    $testEmail,
    '+639171234567',
    password_hash('startpass123', PASSWORD_DEFAULT),
    'WMS Reset User',
    'supervisor',
]);
$userId = (int)$db->lastInsertId();

try {
    $loginHtml = app()->render('pages/login.disyl', wmsLoginPageContext());
    wtReset('login render exposes forgot password link', str_contains($loginHtml, '/wms/forgot-password'));

    app()->setUser([]);
    ob_start();
    wmsForgotPasswordPage();
    $forgotPageHtml = (string)ob_get_clean();
    wtReset('forgot password page posts to WMS auth forgot-password API', str_contains($forgotPageHtml, '/api/v1/wms/auth/forgot-password'));
    wtReset('forgot password page links back to login', str_contains($forgotPageHtml, '/wms/login'));

    $db->prepare('DELETE FROM wms_password_resets WHERE user_id = ?')->execute([$userId]);
    $rawToken = bin2hex(random_bytes(32));
    $db->prepare(
        'INSERT INTO wms_password_resets (user_id, token_hash, requester_ip, expires_at, created_at) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ' . $passwordResetTtlMinutes . ' MINUTE), NOW())'
    )->execute([
        $userId,
        hash('sha256', $rawToken),
        '127.0.0.1',
    ]);

    $_GET['token'] = $rawToken;
    ob_start();
    wmsResetPasswordPage();
    $resetPageHtml = (string)ob_get_clean();
    unset($_GET['token']);
    wtReset('reset password page renders valid-token form', str_contains($resetPageHtml, 'Reset Password') && !str_contains($resetPageHtml, 'invalid or expired'));

    $accountHtml = wmsRender('admin/account.disyl', wmsAdminContext(
        ['id' => $userId, 'role' => 'supervisor', 'source' => 'wms', 'name' => 'WMS Reset User'],
        'account',
        [
            'page_title' => 'My Account',
            'account' => [
                'full_name' => 'WMS Reset User',
                'username' => $testUsername,
                'email' => $testEmail,
                'phone' => '+639171234567',
                'role' => 'supervisor',
                'created_at' => '2026-05-12 00:00:00',
            ],
        ]
    ));
    wtReset('account page shows email field in profile', str_contains($accountHtml, 'Email') && str_contains($accountHtml, $testEmail));

    $usernameAuth = app()->cap()->call('kernel.auth.authenticate@1', [
        'username' => '@wms:' . $testUsername,
        'password' => 'startpass123',
    ], ['mode' => 'pipeline']);
    wtReset(
        'WMS login accepts username',
        is_array($usernameAuth)
            && ($usernameAuth['source'] ?? '') === 'wms'
            && is_array($usernameAuth['user'] ?? null)
            && (($usernameAuth['user']['username'] ?? '') === $testUsername),
        json_encode($usernameAuth, JSON_UNESCAPED_SLASHES)
    );

    $emailAuth = app()->cap()->call('kernel.auth.authenticate@1', [
        'username' => '@wms:' . $testEmail,
        'password' => 'startpass123',
    ], ['mode' => 'pipeline']);
    wtReset(
        'WMS login accepts email',
        is_array($emailAuth)
            && ($emailAuth['source'] ?? '') === 'wms'
            && is_array($emailAuth['user'] ?? null)
            && (($emailAuth['user']['email'] ?? '') === $testEmail),
        json_encode($emailAuth, JSON_UNESCAPED_SLASHES)
    );

    $forgotSetupCode = <<<'PHP'
$lookup = wmsDb()->prepare('SELECT id FROM wms_users WHERE username = ? LIMIT 1');
$lookup->execute(['wms-reset-api-user']);
$existingId = (int)($lookup->fetchColumn() ?: 0);
if ($existingId > 0) {
    wmsDb()->prepare('DELETE FROM wms_password_resets WHERE user_id = ?')->execute([$existingId]);
    wmsDb()->prepare('DELETE FROM wms_users WHERE id = ?')->execute([$existingId]);
}
$insert = wmsDb()->prepare('INSERT INTO wms_users (username, email, phone, password_hash, full_name, role, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, 1, NOW(), NOW())');
$insert->execute(['wms-reset-api-user', 'wms-reset-api-user@example.test', '+639171234568', password_hash('startpass123', PASSWORD_DEFAULT), 'WMS Reset API User', 'admin']);
PHP;

    $forgotProbeCode = <<<'PHP'
$lookup = wmsDb()->prepare('SELECT id FROM wms_users WHERE username = ? LIMIT 1');
$lookup->execute(['wms-reset-api-user']);
$probeUserId = (int)($lookup->fetchColumn() ?: 0);
$stmt = wmsDb()->prepare('SELECT token_hash, used_at, expires_at, created_at FROM wms_password_resets WHERE user_id = ? ORDER BY id DESC LIMIT 1');
$stmt->execute([$probeUserId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
return [
    'user_id' => $probeUserId,
    'reset_row' => $row,
];
PHP;

    $forgotResponse = runWmsAuthJsonRequest(
        'wmsApiForgotPassword',
        '/api/v1/wms/auth/forgot-password',
        json_encode(['identity' => 'wms-reset-api-user@example.test'], JSON_UNESCAPED_SLASHES),
        $forgotSetupCode,
        $forgotProbeCode
    );
    wtReset('forgot password API exits cleanly', ($forgotResponse['exit_code'] ?? 1) === 0, json_encode($forgotResponse, JSON_UNESCAPED_SLASHES));
    wtReset('forgot password API returns generic success', (int)($forgotResponse['status'] ?? 0) === 200 && (($forgotResponse['json']['ok'] ?? false) === true), json_encode($forgotResponse, JSON_UNESCAPED_SLASHES));

    $forgotTokenRow = $forgotResponse['probe']['reset_row'] ?? null;
    wtReset('forgot password API creates a password reset row', is_array($forgotTokenRow) && strlen((string)($forgotTokenRow['token_hash'] ?? '')) === 64, json_encode($forgotResponse, JSON_UNESCAPED_SLASHES));
    wtReset('forgot password API leaves new token unused', is_array($forgotTokenRow) && (($forgotTokenRow['used_at'] ?? null) === null), json_encode($forgotResponse, JSON_UNESCAPED_SLASHES));
    $createdAt = strtotime((string)($forgotTokenRow['created_at'] ?? ''));
    $expiresAt = strtotime((string)($forgotTokenRow['expires_at'] ?? ''));
    $ttlSeconds = ($createdAt > 0 && $expiresAt > 0) ? ($expiresAt - $createdAt) : 0;
    wtReset('forgot password API uses the shared 30-minute TTL', $ttlSeconds >= 1700 && $ttlSeconds <= 1810, (string)$ttlSeconds);

    $resetToken = bin2hex(random_bytes(32));
    $resetTokenExport = var_export($resetToken, true);
    $resetSetupCode = <<<'PHP'
$lookup = wmsDb()->prepare('SELECT id FROM wms_users WHERE username = ? LIMIT 1');
$lookup->execute(['wms-reset-api-user']);
$existingId = (int)($lookup->fetchColumn() ?: 0);
if ($existingId > 0) {
    wmsDb()->prepare('DELETE FROM wms_password_resets WHERE user_id = ?')->execute([$existingId]);
    wmsDb()->prepare('DELETE FROM wms_users WHERE id = ?')->execute([$existingId]);
}
$insert = wmsDb()->prepare('INSERT INTO wms_users (username, email, phone, password_hash, full_name, role, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, 1, NOW(), NOW())');
$insert->execute(['wms-reset-api-user', 'wms-reset-api-user@example.test', '+639171234569', password_hash('startpass123', PASSWORD_DEFAULT), 'WMS Reset API User', 'admin']);
$lookup->execute(['wms-reset-api-user']);
$probeUserId = (int)($lookup->fetchColumn() ?: 0);
$token = __RESET_TOKEN__;
$insertReset = wmsDb()->prepare('INSERT INTO wms_password_resets (user_id, token_hash, requester_ip, expires_at, created_at) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL __TTL_MINUTES__ MINUTE), NOW())');
$insertReset->execute([$probeUserId, hash('sha256', $token), '127.0.0.1']);
PHP;
    $resetSetupCode = str_replace('__RESET_TOKEN__', $resetTokenExport, $resetSetupCode);
    $resetSetupCode = str_replace('__TTL_MINUTES__', (string)$passwordResetTtlMinutes, $resetSetupCode);

    $resetProbeCode = <<<'PHP'
$lookup = wmsDb()->prepare('SELECT id, password_hash FROM wms_users WHERE username = ? LIMIT 1');
$lookup->execute(['wms-reset-api-user']);
$userRow = $lookup->fetch(PDO::FETCH_ASSOC) ?: [];
$stmt = wmsDb()->prepare('SELECT used_at FROM wms_password_resets WHERE user_id = ? ORDER BY id DESC LIMIT 1');
$stmt->execute([(int)($userRow['id'] ?? 0)]);
$resetRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
return [
    'user_row' => $userRow,
    'reset_row' => $resetRow,
];
PHP;

    $resetResponse = runWmsAuthJsonRequest(
        'wmsApiResetPassword',
        '/api/v1/wms/auth/reset-password',
        json_encode([
            'token' => $resetToken,
            'password' => 'renewedpass456',
            'confirm_password' => 'renewedpass456',
        ], JSON_UNESCAPED_SLASHES),
        $resetSetupCode,
        $resetProbeCode
    );
    wtReset('reset password API exits cleanly', ($resetResponse['exit_code'] ?? 1) === 0, json_encode($resetResponse, JSON_UNESCAPED_SLASHES));
    wtReset('reset password API returns success', (int)($resetResponse['status'] ?? 0) === 200 && (($resetResponse['json']['ok'] ?? false) === true), json_encode($resetResponse, JSON_UNESCAPED_SLASHES));
    $resetUserRow = $resetResponse['probe']['user_row'] ?? null;
    $resetTokenRow = $resetResponse['probe']['reset_row'] ?? null;
    wtReset('reset password API updates the stored hash', is_array($resetUserRow) && password_verify('renewedpass456', (string)($resetUserRow['password_hash'] ?? '')), json_encode($resetResponse, JSON_UNESCAPED_SLASHES));
    wtReset('reset password API marks the token used', is_array($resetTokenRow) && !empty($resetTokenRow['used_at']), json_encode($resetResponse, JSON_UNESCAPED_SLASHES));
} catch (Throwable $e) {
    wtReset('unexpected exception', false, $e->getMessage());
} finally {
    if (isset($userId) && $userId > 0) {
        $db->prepare('DELETE FROM wms_password_resets WHERE user_id = ?')->execute([$userId]);
        $db->prepare('DELETE FROM wms_users WHERE id = ?')->execute([$userId]);
    }
}

$appLog = @file_get_contents($appLogPath) ?: '';
$errorLog = @file_get_contents($errorLogPath) ?: '';
$criticalLines = array_values(array_filter(explode("\n", $appLog), static fn (string $line): bool => str_contains($line, '[critical]')));
$errorLines = array_values(array_filter(explode("\n", $errorLog), static fn (string $line): bool => trim($line) !== ''));

wtReset('no app.log critical errors', empty($criticalLines), implode('; ', $criticalLines));
wtReset('no PHP errors in error.log', empty($errorLines), implode('; ', $errorLines));

echo "\nSummary: {$pass} passed, {$fail} failed\n";

if ($errors !== []) {
    echo implode("\n", $errors) . "\n";
}

exit($fail > 0 ? 1 : 0);