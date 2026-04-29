<?php

declare(strict_types=1);

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'cmsnew.test';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/bakeshop/login';

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../modules/bakeshop/helpers.php';
require_once __DIR__ . '/../modules/bakeshop/handlers.php';

$passwordResetPolicy = kernel_password_reset_policy();
$passwordResetTtlMinutes = (int)$passwordResetPolicy['token_ttl_minutes'];

$pass = 0;
$fail = 0;
$errors = [];

function btReset(string $label, bool $ok, string $detail = ''): void
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

function runBakeshopAuthJsonRequest(string $handlerName, string $requestUri, string $rawBody, string $setupCode = '', string $probeCode = 'return [];'): array
{
    $patchedAppPath = sys_get_temp_dir() . '/ikabud-bakeshop-auth-app-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.php';
    $runnerPath = sys_get_temp_dir() . '/ikabud-bakeshop-auth-runner-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.php';

    $appSource = (string)file_get_contents(__DIR__ . '/../kernel/App.php');
    $replacement = "file_get_contents('data://text/plain," . rawurlencode($rawBody) . "')";
    $appSource = str_replace("file_get_contents('php://input')", $replacement, $appSource);
    file_put_contents($patchedAppPath, $appSource);

    $bootstrap = var_export(__DIR__ . '/../bootstrap.php', true);
    $moduleManager = var_export(__DIR__ . '/../src/helpers/module-manager.php', true);
    $helpers = var_export(__DIR__ . '/../modules/bakeshop/helpers.php', true);
    $handlers = var_export(__DIR__ . '/../modules/bakeshop/handlers.php', true);
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
\$runner->migrate('bakeshop');
http_response_code(200);
ob_start();
call_user_func({$handlerExport});
\$body = (string)ob_get_clean();
\$probeCode = {$probeCodeExport};
\$probe = eval(\$probeCode);
if (!is_array(\$probe)) {
    \$probe = ['result' => \$probe];
}
echo json_encode(['status' => (int)(http_response_code() ?: 200), 'body' => \$body, 'probe' => \$probe], JSON_UNESCAPED_SLASHES);
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
$appLogStart = is_file($appLogPath) ? max(0, (int)@filesize($appLogPath)) : 0;
$errorLogStart = is_file($errorLogPath) ? max(0, (int)@filesize($errorLogPath)) : 0;

echo "\n=== BAKESHOP PASSWORD RESET TEST ===\n\n";

$db = app()->db();
$runner = new \Ikabud\Kernel\Database\MigrationRunner($db);
$runner->migrate('bakeshop');

$originalSettings = getModuleSettings('bakeshop');
$previousUser = app()->user();

$uiTestUsername = 'test-reset-user';
$apiTestUsername = 'test-reset-api-user';

$lookup = $db->prepare('SELECT id FROM bakeshop_users WHERE username = ? LIMIT 1');
$lookup->execute([$uiTestUsername]);
$existingId = (int)($lookup->fetchColumn() ?: 0);
if ($existingId > 0) {
    $db->prepare('DELETE FROM bakeshop_password_resets WHERE user_id = ?')->execute([$existingId]);
    $db->prepare('DELETE FROM bakeshop_users WHERE id = ?')->execute([$existingId]);
}

$created = $db->prepare(
    'INSERT INTO bakeshop_users (username, email, phone, password_hash, full_name, role, is_active, created_at, updated_at) VALUES (?, ?, NULL, ?, ?, ?, 1, NOW(), NOW())'
);
$created->execute([
    $uiTestUsername,
    'not-an-email',
    password_hash('startpass123', PASSWORD_DEFAULT),
    'Test Reset User',
    'supervisor',
]);
$userId = (int)$db->lastInsertId();

try {
    saveModuleSettings('bakeshop', [
        'store_name' => 'Sunrise Dough',
        'store_description' => 'Fresh bread, production planning, and delivery tracking for the bakery floor.',
        'store_logo_url' => '/uploads/test-store-logo.png',
        'usage_decimal_places' => $originalSettings['usage_decimal_places'] ?? '2',
        'print_template' => $originalSettings['print_template'] ?? 'standard',
        'role_permissions' => $originalSettings['role_permissions'] ?? '',
    ]);

    $loginHtml = app()->render('pages/login.disyl', bakeshopLoginPageContext());
    btReset('login render uses configured store name', str_contains($loginHtml, 'Sunrise Dough'));
    btReset('login render uses configured store description', str_contains($loginHtml, 'Fresh bread, production planning, and delivery tracking for the bakery floor.'));
    btReset('login render uses configured store logo', str_contains($loginHtml, '/uploads/test-store-logo.png'));
    btReset('login render exposes forgot password link', str_contains($loginHtml, '/bakeshop/forgot-password'));

    app()->setUser([]);
    ob_start();
    bakeshopForgotPasswordPage();
    $forgotPageHtml = (string)ob_get_clean();
    btReset('forgot password page uses configured store name', str_contains($forgotPageHtml, 'Sunrise Dough'));
    btReset('forgot password page posts to auth forgot-password API', str_contains($forgotPageHtml, '/api/v1/bakeshop/auth/forgot-password'));

    $db->prepare('DELETE FROM bakeshop_password_resets WHERE user_id = ?')->execute([$userId]);
    $rawToken = bin2hex(random_bytes(32));
    $db->prepare(
        'INSERT INTO bakeshop_password_resets (user_id, token_hash, requester_ip, expires_at, created_at) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ' . $passwordResetTtlMinutes . ' MINUTE), NOW())'
    )->execute([
        $userId,
        hash('sha256', $rawToken),
        '127.0.0.1',
    ]);

    $_GET['token'] = $rawToken;
    ob_start();
    bakeshopResetPasswordPage();
    $resetPageHtml = (string)ob_get_clean();
    unset($_GET['token']);
    btReset('reset password page renders valid-token form', str_contains($resetPageHtml, 'Reset Password') && !str_contains($resetPageHtml, 'invalid or expired'));
    btReset('reset password page uses configured store name', str_contains($resetPageHtml, 'Sunrise Dough'));

    $staleForgotToken = bin2hex(random_bytes(32));
    $forgotSetupCode = <<<'PHP'
$lookup = bakeshopDb()->prepare('SELECT id FROM bakeshop_users WHERE username = ? LIMIT 1');
$lookup->execute(['test-reset-api-user']);
$existingId = (int)($lookup->fetchColumn() ?: 0);
if ($existingId > 0) {
    bakeshopDb()->prepare('DELETE FROM bakeshop_password_resets WHERE user_id = ?')->execute([$existingId]);
    bakeshopDb()->prepare('DELETE FROM bakeshop_users WHERE id = ?')->execute([$existingId]);
}
$insert = bakeshopDb()->prepare('INSERT INTO bakeshop_users (username, email, phone, password_hash, full_name, role, is_active, created_at, updated_at) VALUES (?, ?, NULL, ?, ?, ?, 1, NOW(), NOW())');
$insert->execute(['test-reset-api-user', 'not-an-email', password_hash('startpass123', PASSWORD_DEFAULT), 'Test Reset API User', 'supervisor']);
$lookup->execute(['test-reset-api-user']);
$probeUserId = (int)($lookup->fetchColumn() ?: 0);
$staleInsert = bakeshopDb()->prepare('INSERT INTO bakeshop_password_resets (user_id, token_hash, requester_ip, expires_at, created_at) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL __TTL_MINUTES__ MINUTE), NOW())');
$staleInsert->execute([$probeUserId, hash('sha256', __STALE_TOKEN__), '127.0.0.1']);
PHP;
    $forgotSetupCode = str_replace('__TTL_MINUTES__', (string)$passwordResetTtlMinutes, $forgotSetupCode);
    $forgotSetupCode = str_replace('__STALE_TOKEN__', var_export($staleForgotToken, true), $forgotSetupCode);
    $forgotProbeCode = <<<'PHP'
$lookup = bakeshopDb()->prepare('SELECT id FROM bakeshop_users WHERE username = ? LIMIT 1');
$lookup->execute(['test-reset-api-user']);
$probeUserId = (int)($lookup->fetchColumn() ?: 0);
$stmt = bakeshopDb()->prepare('SELECT token_hash, used_at, expires_at, created_at FROM bakeshop_password_resets WHERE user_id = ? ORDER BY id DESC LIMIT 2');
$stmt->execute([$probeUserId]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
return [
    'user_id' => $probeUserId,
    'reset_rows' => $rows,
];
PHP;

    $forgotResponse = runBakeshopAuthJsonRequest(
        'bakeshopApiForgotPassword',
        '/api/v1/bakeshop/auth/forgot-password',
        json_encode(['identity' => $apiTestUsername], JSON_UNESCAPED_SLASHES),
        $forgotSetupCode,
        $forgotProbeCode
    );
    btReset('forgot password API exits cleanly', ($forgotResponse['exit_code'] ?? 1) === 0, json_encode($forgotResponse, JSON_UNESCAPED_SLASHES));
    btReset('forgot password API returns success', (int)($forgotResponse['status'] ?? 0) === 200 && (($forgotResponse['json']['ok'] ?? false) === true), json_encode($forgotResponse, JSON_UNESCAPED_SLASHES));

    $forgotRows = $forgotResponse['probe']['reset_rows'] ?? [];
    $forgotTokenRow = $forgotRows[0] ?? null;
    $staleRow = $forgotRows[1] ?? null;
    btReset('forgot password API creates a password reset row', is_array($forgotTokenRow) && strlen((string)($forgotTokenRow['token_hash'] ?? '')) === 64, json_encode($forgotRows, JSON_UNESCAPED_SLASHES));
    btReset('forgot password API leaves new token unused', is_array($forgotTokenRow) && (($forgotTokenRow['used_at'] ?? null) === null), json_encode($forgotRows, JSON_UNESCAPED_SLASHES));
    btReset('forgot password API invalidates prior unused tokens', is_array($staleRow) && !empty($staleRow['used_at']), json_encode($forgotRows, JSON_UNESCAPED_SLASHES));
    $createdAt = strtotime((string)($forgotTokenRow['created_at'] ?? ''));
    $expiresAt = strtotime((string)($forgotTokenRow['expires_at'] ?? ''));
    $ttlSeconds = ($createdAt > 0 && $expiresAt > 0) ? ($expiresAt - $createdAt) : 0;
    btReset('forgot password API uses the shared 30-minute TTL', $ttlSeconds >= 1700 && $ttlSeconds <= 1810, (string)$ttlSeconds);

    $staleResetResponse = runBakeshopAuthJsonRequest(
        'bakeshopApiResetPassword',
        '/api/v1/bakeshop/auth/reset-password',
        json_encode([
            'token' => $staleForgotToken,
            'password' => 'renewedpass456',
            'confirm_password' => 'renewedpass456',
        ], JSON_UNESCAPED_SLASHES),
        '',
        'return [];'
    );
    btReset('stale token is rejected after a newer request', (int)($staleResetResponse['status'] ?? 0) === 422 && (($staleResetResponse['json']['error'] ?? '') === $passwordResetPolicy['invalid_token_message']), json_encode($staleResetResponse, JSON_UNESCAPED_SLASHES));

    $resetRawToken = bin2hex(random_bytes(32));
    $resetRawTokenExport = var_export($resetRawToken, true);
    $resetSetupCode = <<<PHP



















































\$lookup = bakeshopDb()->prepare('SELECT id FROM bakeshop_users WHERE username = ? LIMIT 1');
\$lookup->execute(['test-reset-api-user']);
\$existingId = (int)(\$lookup->fetchColumn() ?: 0);
if (\$existingId > 0) {
    bakeshopDb()->prepare('DELETE FROM bakeshop_password_resets WHERE user_id = ?')->execute([\$existingId]);
    bakeshopDb()->prepare('DELETE FROM bakeshop_users WHERE id = ?')->execute([\$existingId]);
}
\$insert = bakeshopDb()->prepare('INSERT INTO bakeshop_users (username, email, phone, password_hash, full_name, role, is_active, created_at, updated_at) VALUES (?, ?, NULL, ?, ?, ?, 1, NOW(), NOW())');
\$insert->execute(['test-reset-api-user', 'not-an-email', password_hash('startpass123', PASSWORD_DEFAULT), 'Test Reset API User', 'supervisor']);
\$userId = (int)bakeshopDb()->lastInsertId();
\$resetInsert = bakeshopDb()->prepare('INSERT INTO bakeshop_password_resets (user_id, token_hash, requester_ip, expires_at, created_at) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL {$passwordResetTtlMinutes} MINUTE), NOW())');
\$resetInsert->execute([\$userId, hash('sha256', {$resetRawTokenExport}), '127.0.0.1']);
PHP;
    $resetProbeCode = <<<'PHP'
$lookup = bakeshopDb()->prepare('SELECT id FROM bakeshop_users WHERE username = ? LIMIT 1');
$lookup->execute(['test-reset-api-user']);
$probeUserId = (int)($lookup->fetchColumn() ?: 0);
$userStmt = bakeshopDb()->prepare('SELECT password_hash, COALESCE(token_version, 0) AS token_version FROM bakeshop_users WHERE id = ? LIMIT 1');
$userStmt->execute([$probeUserId]);
$updatedUser = $userStmt->fetch(PDO::FETCH_ASSOC);
$usedStmt = bakeshopDb()->prepare('SELECT used_at FROM bakeshop_password_resets WHERE user_id = ? ORDER BY id DESC LIMIT 1');
$usedStmt->execute([$probeUserId]);
$usedRow = $usedStmt->fetch(PDO::FETCH_ASSOC);
$auth = bakeshop_cap_kernel_auth_authenticate_1([
    'username' => '@bakeshop:test-reset-api-user',
    'password' => 'renewedpass456',
]);
return [
    'updated_user' => is_array($updatedUser) ? $updatedUser : null,
    'used_row' => is_array($usedRow) ? $usedRow : null,
    'auth' => $auth,
];
PHP;

    $resetResponse = runBakeshopAuthJsonRequest(
        'bakeshopApiResetPassword',
        '/api/v1/bakeshop/auth/reset-password',
        json_encode([
            'token' => $resetRawToken,
            'password' => 'renewedpass456',
            'confirm_password' => 'renewedpass456',
        ], JSON_UNESCAPED_SLASHES)
        ,
        $resetSetupCode,
        $resetProbeCode
    );
    btReset('reset password API exits cleanly', ($resetResponse['exit_code'] ?? 1) === 0, json_encode($resetResponse, JSON_UNESCAPED_SLASHES));
    btReset('reset password API returns success', (int)($resetResponse['status'] ?? 0) === 200 && (($resetResponse['json']['ok'] ?? false) === true), json_encode($resetResponse, JSON_UNESCAPED_SLASHES));

    $updatedUser = $resetResponse['probe']['updated_user'] ?? [];
    btReset('reset password API updates password hash', password_verify('renewedpass456', (string)($updatedUser['password_hash'] ?? '')), json_encode($updatedUser, JSON_UNESCAPED_SLASHES));
    btReset('reset password API bumps token version', (int)($updatedUser['token_version'] ?? 0) >= 1, json_encode($updatedUser, JSON_UNESCAPED_SLASHES));

    $usedRow = $resetResponse['probe']['used_row'] ?? [];
    btReset('reset password API marks token used', !empty($usedRow['used_at']), json_encode($usedRow, JSON_UNESCAPED_SLASHES));

    $auth = $resetResponse['probe']['auth'] ?? null;
    btReset('reset password API allows auth with new password', is_array($auth) && (($auth['source'] ?? '') === 'bakeshop'), json_encode($auth, JSON_UNESCAPED_SLASHES));

    $reusedResponse = runBakeshopAuthJsonRequest(
        'bakeshopApiResetPassword',
        '/api/v1/bakeshop/auth/reset-password',
        json_encode([
            'token' => $resetRawToken,
            'password' => 'renewedpass456',
            'confirm_password' => 'renewedpass456',
        ], JSON_UNESCAPED_SLASHES),
        '',
        'return [];'
    );
    btReset('reused token is rejected', (int)($reusedResponse['status'] ?? 0) === 422 && (($reusedResponse['json']['error'] ?? '') === $passwordResetPolicy['invalid_token_message']), json_encode($reusedResponse, JSON_UNESCAPED_SLASHES));
} finally {
    app()->setUser(is_array($previousUser) ? $previousUser : []);
    saveModuleSettings('bakeshop', $originalSettings);
    $cleanupLookup = $db->prepare('SELECT id FROM bakeshop_users WHERE username IN (?, ?)');
    $cleanupLookup->execute([$uiTestUsername, $apiTestUsername]);
    $cleanupIds = $cleanupLookup->fetchAll(PDO::FETCH_COLUMN) ?: [];
    foreach ($cleanupIds as $cleanupId) {
        $db->prepare('DELETE FROM bakeshop_password_resets WHERE user_id = ?')->execute([(int)$cleanupId]);
        $db->prepare('DELETE FROM bakeshop_users WHERE id = ?')->execute([(int)$cleanupId]);
    }
}

$appLogRaw = (string)@file_get_contents($appLogPath);
$errorLogRaw = (string)@file_get_contents($errorLogPath);
$appLog = trim($appLogStart > 0 ? (string)substr($appLogRaw, $appLogStart) : $appLogRaw);
$errorLog = trim($errorLogStart > 0 ? (string)substr($errorLogRaw, $errorLogStart) : $errorLogRaw);
btReset('no app.log errors', $appLog === '' || !str_contains(strtolower($appLog), 'error'), $appLog);
btReset('no error.log errors', $errorLog === '', $errorLog);

echo "\n" . str_repeat('─', 50) . "\n";
echo "  Result: {$pass} passed, {$fail} failed\n";
if ($errors !== []) {
    echo "\n  Failures:\n";
    foreach ($errors as $error) {
        echo "    • {$error}\n";
    }
}
echo "\n";

exit($fail > 0 ? 1 : 0);