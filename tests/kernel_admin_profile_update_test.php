<?php

declare(strict_types=1);

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'applicationos.test';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/api/v1/admin/profile/update';
$_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'POST';

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../src/http/core-routes.php';

$pass = 0;
$fail = 0;
$errors = [];

function kapDisplay(string $label, bool $ok, string $detail = ''): void
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

function kapRunProfileUpdateRequest(array $user, array $post, array $serverOverrides = []): array
{
    $runnerPath = sys_get_temp_dir() . '/ikabud-admin-profile-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.php';
    $bootstrap = var_export(__DIR__ . '/../bootstrap.php', true);
    $entrypoint = var_export(__DIR__ . '/../public/index.php', true);
    $userExport = var_export($user, true);
    $postExport = var_export($post, true);
    $serverExport = var_export(array_merge([
        'REQUEST_METHOD' => 'POST',
        'REQUEST_URI' => '/api/v1/admin/profile/update',
        'HTTP_HOST' => 'applicationos.test',
        'HTTP_ACCEPT' => 'application/json',
        'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
        'SCRIPT_NAME' => '/public/index.php',
        'PHP_SELF' => '/public/index.php',
    ], $serverOverrides), true);

    $script = implode("\n", [
        '<?php',
        'foreach (' . $serverExport . ' as $key => $value) { $_SERVER[(string) $key] = $value; }',
        '$_GET = [];',
        '$_POST = ' . $postExport . ';',
        '$_REQUEST = array_merge($_GET, $_POST);',
        'require ' . $bootstrap . ';',
        'if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }',
        'if (isset($_POST["_token"])) { $_SESSION["_csrf_token"] = (string) $_POST["_token"]; }',
        'app()->setUser(' . $userExport . ');',
        'register_shutdown_function(static function (): void { echo "\n__HEADERS__\n"; echo json_encode(headers_list(), JSON_UNESCAPED_SLASHES); echo "\n__SESSION__\n"; echo json_encode($_SESSION["_admin_profile_notice"] ?? null, JSON_UNESCAPED_SLASHES); });',
        'require ' . $entrypoint . ';',
        '',
    ]);

    file_put_contents($runnerPath, $script);

    $output = [];
    $exitCode = 0;
    exec('php ' . escapeshellarg($runnerPath) . ' 2>&1', $output, $exitCode);
    @unlink($runnerPath);

    $stdout = implode("\n", $output);
    $parts = explode("\n__HEADERS__\n", $stdout, 2);
    $body = $parts[0] ?? '';
    $metaParts = isset($parts[1]) ? explode("\n__SESSION__\n", $parts[1], 2) : [];
    $headers = isset($metaParts[0]) ? json_decode($metaParts[0], true) : [];
    if (!is_array($headers)) {
        $headers = [];
    }

    $sessionNotice = isset($metaParts[1]) ? json_decode($metaParts[1], true) : null;
    if (!is_array($sessionNotice)) {
        $sessionNotice = null;
    }

    $json = json_decode($body, true);
    if (!is_array($json)) {
        $json = null;
    }

    return [
        'exit_code' => $exitCode,
        'body' => $body,
        'json' => $json,
        'headers' => $headers,
        'session_notice' => $sessionNotice,
        'raw' => $stdout,
    ];
}

@file_put_contents(STORAGE_PATH . '/logs/app.log', '');
@file_put_contents(STORAGE_PATH . '/logs/error.log', '');

echo "\n=== KERNEL ADMIN PROFILE UPDATE TEST ===\n\n";

$template = (string)file_get_contents(__DIR__ . '/../templates/pages/admin-profile.disyl');
$adminHandlersSource = (string)file_get_contents(__DIR__ . '/../src/http/admin-handlers.php');
$pageHandlersSource = (string)file_get_contents(__DIR__ . '/../src/http/page-handlers.php');
$kernelAppSource = (string)file_get_contents(__DIR__ . '/../kernel/App.php');
$routes = kernelCoreRoutes();
kapDisplay('profile form falls back to POST', str_contains($template, 'method="post"'));
kapDisplay('profile form falls back to profile update API', str_contains($template, 'action="{base_url}/api/v1/admin/profile/update"'));
kapDisplay('profile form forces JS submit to skip native JSON navigation', str_contains($template, 'onsubmit="submitProfile(event); return false;"'));
kapDisplay('profile form exposes email field', str_contains($template, 'type="email" id="email" name="email"'));
kapDisplay('profile update GET alias routes to admin profile page', ($routes['GET']['/api/v1/admin/profile/update'] ?? null) === 'pageAdminProfile');
kapDisplay('profile page handler redirects GET api alias to canonical profile page', str_contains($pageHandlersSource, "if (\$requestPath === '/api/v1/admin/profile/update')"));
kapDisplay('profile page handler consumes redirect notice flash', str_contains($pageHandlersSource, "\$_SESSION['_admin_profile_notice']"));
kapDisplay('profile page handler loads current kernel user email', str_contains($pageHandlersSource, "SELECT username, email, full_name, role"));
kapDisplay('profile handler updates admin and superadmin rows', str_contains($adminHandlersSource, "role IN (\\'admin\\', \\'superadmin\\')"));
kapDisplay(
    'profile handler persists email updates',
    str_contains($adminHandlersSource, "\$sql = 'UPDATE users SET full_name = :name';")
        && str_contains($adminHandlersSource, "\$sql .= ', email = :email';")
);
kapDisplay('profile handler redirects non-JSON form posts back to profile', str_contains($adminHandlersSource, "app()->redirect(\$redirect);"));
kapDisplay('profile handler stores email in refreshed auth payload', str_contains($adminHandlersSource, "\$newPayload['email'] = \$email;"));
kapDisplay('profile handler refreshes JWT token_version after password change', str_contains($adminHandlersSource, "\$newPayload['token_version'] = (int)(\$newPayload['token_version'] ?? 0) + 1;"));
kapDisplay('kernel auth payload includes email on login', str_contains($kernelAppSource, 'SELECT id, username, email, password_hash, full_name, role'));

$db = app()->db();
$userStmt = $db->prepare('SELECT id, username, email, full_name, password_hash, role, is_active, COALESCE(token_version, 0) AS token_version FROM users WHERE username = :username LIMIT 1');
$userStmt->execute([':username' => 'admin']);
$original = $userStmt->fetch(PDO::FETCH_ASSOC);

kapDisplay('kernel admin user exists', is_array($original), is_array($original) ? '' : 'missing users.admin row');

if (!is_array($original)) {
    exit(1);
}

$originalFullName = (string)($original['full_name'] ?? 'Administrator');
$originalEmailRaw = $original['email'] ?? null;
$originalEmail = is_string($originalEmailRaw) ? $originalEmailRaw : '';
$originalPasswordHash = (string)($original['password_hash'] ?? '');
$originalTokenVersion = (int)($original['token_version'] ?? 0);
$temporaryFullName = 'Kernel Profile QA';
$temporaryEmail = 'kernel-profile-qa@example.test';
$revertEmail = $originalEmail !== '' ? $originalEmail : 'kernel-profile-restore@example.test';
$temporaryPassword = 'KernelTemp123!';

try {
    $csrfToken = app()->csrfRotate();
    $updateResponse = kapRunProfileUpdateRequest([
        'id' => (int)$original['id'],
        'username' => (string)$original['username'],
        'name' => $originalFullName,
        'full_name' => $originalFullName,
        'role' => (string)$original['role'],
        'source' => 'kernel',
        'token_version' => $originalTokenVersion,
    ], [
        '_token' => $csrfToken,
        'full_name' => $temporaryFullName,
        'email' => $temporaryEmail,
        'password' => $temporaryPassword,
    ]);

    kapDisplay('profile update request exits cleanly', $updateResponse['exit_code'] === 0, 'exit=' . $updateResponse['exit_code']);
    kapDisplay('profile update returns ok=true', !empty(($updateResponse['json'] ?? [])['ok']), $updateResponse['raw']);

    $htmlFallbackResponse = kapRunProfileUpdateRequest([
        'id' => (int)$original['id'],
        'username' => (string)$original['username'],
        'name' => $temporaryFullName,
        'full_name' => $temporaryFullName,
        'role' => (string)$original['role'],
        'source' => 'kernel',
        'token_version' => $originalTokenVersion + 1,
        'email' => $temporaryEmail,
    ], [
        '_token' => app()->csrfRotate(),
        'full_name' => $temporaryFullName,
        'email' => $temporaryEmail,
    ], [
        'HTTP_ACCEPT' => 'text/html,application/xhtml+xml',
    ]);
    $fallbackNotice = $htmlFallbackResponse['session_notice'] ?? null;
    kapDisplay(
        'profile form fallback redirects back to admin profile page',
        is_array($fallbackNotice)
            && ($fallbackNotice['type'] ?? '') === 'success'
            && ($fallbackNotice['message'] ?? '') === 'Profile updated successfully.',
        json_encode($htmlFallbackResponse, JSON_UNESCAPED_SLASHES)
    );

    $userStmt->execute([':username' => 'admin']);
    $updated = $userStmt->fetch(PDO::FETCH_ASSOC);
    kapDisplay('full_name persisted from profile update', is_array($updated) && ($updated['full_name'] ?? '') === $temporaryFullName, json_encode($updated, JSON_UNESCAPED_SLASHES));
    kapDisplay('email persisted from profile update', is_array($updated) && ($updated['email'] ?? '') === $temporaryEmail, json_encode($updated, JSON_UNESCAPED_SLASHES));
    kapDisplay('password hash updated from profile update', is_array($updated) && password_verify($temporaryPassword, (string)($updated['password_hash'] ?? '')), json_encode($updated, JSON_UNESCAPED_SLASHES));
    kapDisplay('token_version increments on password change', is_array($updated) && (int)($updated['token_version'] ?? -1) === $originalTokenVersion + 1, json_encode($updated, JSON_UNESCAPED_SLASHES));

    $revertToken = app()->csrfRotate();
    $revertResponse = kapRunProfileUpdateRequest([
        'id' => (int)$original['id'],
        'username' => (string)$original['username'],
        'name' => $temporaryFullName,
        'full_name' => $temporaryFullName,
        'role' => (string)$original['role'],
        'source' => 'kernel',
        'token_version' => $originalTokenVersion + 1,
    ], [
        '_token' => $revertToken,
        'full_name' => $originalFullName,
        'email' => $revertEmail,
        'password' => 'admin123',
    ]);

    kapDisplay('profile revert request returns ok=true', !empty(($revertResponse['json'] ?? [])['ok']), $revertResponse['raw']);

    $userStmt->execute([':username' => 'admin']);
    $reverted = $userStmt->fetch(PDO::FETCH_ASSOC);
    kapDisplay('full_name reverts after profile revert', is_array($reverted) && ($reverted['full_name'] ?? '') === $originalFullName, json_encode($reverted, JSON_UNESCAPED_SLASHES));
    kapDisplay('email reverts after profile revert', is_array($reverted) && ($reverted['email'] ?? '') === $revertEmail, json_encode($reverted, JSON_UNESCAPED_SLASHES));
    kapDisplay('original password verifies after revert', is_array($reverted) && password_verify('admin123', (string)($reverted['password_hash'] ?? '')), json_encode($reverted, JSON_UNESCAPED_SLASHES));
} finally {
    $restoreStmt = $db->prepare('UPDATE users SET full_name = :name, email = :email, password_hash = :password_hash, token_version = :token_version WHERE id = :id');
    $restoreStmt->bindValue(':name', $originalFullName, PDO::PARAM_STR);
    $restoreStmt->bindValue(':email', $originalEmailRaw, $originalEmailRaw === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $restoreStmt->bindValue(':password_hash', $originalPasswordHash, PDO::PARAM_STR);
    $restoreStmt->bindValue(':token_version', $originalTokenVersion, PDO::PARAM_INT);
    $restoreStmt->bindValue(':id', (int)$original['id'], PDO::PARAM_INT);
    $restoreStmt->execute();
}

$appLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/app.log'));
$errorLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/error.log'));
kapDisplay('no app.log errors during profile update test', $appLog === '' || !str_contains(strtolower($appLog), '[error]'), $appLog);
kapDisplay('no error.log errors during profile update test', $errorLog === '', $errorLog);

echo "\n" . str_repeat('-', 50) . "\n";
echo "  Result: {$pass} passed, {$fail} failed\n";
if ($errors !== []) {
    echo "\n  Failures:\n";
    foreach ($errors as $error) {
        echo "    - {$error}\n";
    }
}
echo "\n";

exit($fail > 0 ? 1 : 0);