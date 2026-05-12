<?php

declare(strict_types=1);

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'guidancemonitoring.test';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/admin/guidance/profile';
$_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$_SERVER['HTTP_ACCEPT'] = $_SERVER['HTTP_ACCEPT'] ?? 'application/json';

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';

ob_start();

$pass = 0;
$fail = 0;
$errors = [];
$resultLines = [];

function gtProfileEmail(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $errors, $resultLines;

    if ($ok) {
        $pass++;
        $resultLines[] = "  [PASS] {$label}\n";
        return;
    }

    $fail++;
    $errors[] = $label . ($detail !== '' ? ': ' . $detail : '');
    $resultLines[] = "  [FAIL] {$label}" . ($detail !== '' ? " -- {$detail}" : '') . "\n";
}

function guidanceLoadProfileTestHandlers(): void
{
    static $loaded = false;

    if ($loaded) {
        return;
    }

    $modules = discoverModules();
    $guidance = $modules['guidance'] ?? null;
    if (!is_array($guidance)) {
        throw new RuntimeException('Guidance module manifest not found.');
    }

    loadModuleHelpers($guidance);
    moduleWithContext('guidance', static function () use ($guidance): void {
        require_once (string)($guidance['_path'] ?? '') . '/handlers.php';
    });

    $loaded = true;
}

function guidanceProfileTestDb(): \Ikabud\Kernel\Contracts\ModuleDB
{
    return moduleWithContext('guidance', static fn() => guidanceDb());
}

function guidanceProfileTestUserRow(int $id, string $email, string $firstName, string $lastName, string $role): array
{
    return [
        'id' => $id,
        'email' => $email,
        'first_name' => $firstName,
        'last_name' => $lastName,
        'role' => $role,
    ];
}

function guidanceProfileTestSetUser(array $row): void
{
    app()->setUser([
        'id' => (int)($row['id'] ?? 0),
        'username' => (string)($row['email'] ?? ''),
        'name' => trim((string)($row['first_name'] ?? '') . ' ' . (string)($row['last_name'] ?? '')),
        'role' => (string)($row['role'] ?? ''),
        'source' => 'guidance',
    ]);
}

function guidanceRenderProfileForTest(array $row): string
{
    guidanceProfileTestSetUser($row);
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/admin/guidance/profile';
    unset($_SERVER['CONTENT_TYPE'], $_SERVER['HTTP_HX_REQUEST']);
    $_GET = [];
    $_POST = [];
    $_REQUEST = [];
    http_response_code(200);

    return (string)moduleWithContext('guidance', static function (): string {
        ob_start();
        pageGuidanceProfile();
        return (string)ob_get_clean();
    });
}

function guidanceSubmitOwnProfileForTest(array $row, array $post): array
{
    guidanceProfileTestSetUser($row);
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }

    $token = app()->csrfRotate();

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['REQUEST_URI'] = '/admin/guidance/api/profile';
    $_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
    $_SERVER['HTTP_ACCEPT'] = 'application/json';
    unset($_SERVER['HTTP_HX_REQUEST']);
    $_GET = [];
    $_POST = array_merge(['_token' => $token], $post);
    $_REQUEST = $_POST;
    http_response_code(200);

    $body = (string)moduleWithContext('guidance', static function (): string {
        ob_start();
        apiGuidanceUpdateOwnProfile();
        return (string)ob_get_clean();
    });

    return [
        'status' => (int)(http_response_code() ?: 200),
        'body' => $body,
        'json' => json_decode($body, true),
    ];
}

guidanceLoadProfileTestHandlers();

$db = guidanceProfileTestDb();
$stamp = bin2hex(random_bytes(4));
$adminEmail = 'guidance-profile-admin-' . $stamp . '@example.test';
$counselorEmail = 'guidance-profile-counselor-' . $stamp . '@example.test';
$updatedCounselorEmail = 'guidance-profile-counselor-new-' . $stamp . '@example.test';

$cleanupByEmail = $db->prepare('DELETE FROM gm_users WHERE email IN (?, ?, ?)');
$cleanupByEmail->execute([$adminEmail, $counselorEmail, $updatedCounselorEmail]);

$insertUser = $db->prepare(
    'INSERT INTO gm_users (email, password, first_name, last_name, role, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 1, NOW(), NOW())'
);

$insertUser->execute([$adminEmail, password_hash('ProfilePass123!', PASSWORD_DEFAULT), 'Profile', 'Admin', 'admin']);
$adminId = (int)$db->lastInsertId();

$insertUser->execute([$counselorEmail, password_hash('ProfilePass123!', PASSWORD_DEFAULT), 'Profile', 'Counselor', 'counselor']);
$counselorId = (int)$db->lastInsertId();

$cleanupById = $db->prepare('DELETE FROM gm_users WHERE id IN (?, ?)');
$emailLookup = $db->prepare('SELECT email FROM gm_users WHERE id = ? LIMIT 1');

try {
    $adminUser = guidanceProfileTestUserRow($adminId, $adminEmail, 'Profile', 'Admin', 'admin');
    $adminHtml = guidanceRenderProfileForTest($adminUser);
    gtProfileEmail(
        'admin own-profile email stays read-only',
        preg_match('/<input type="email" value="[^"]+" readonly/s', $adminHtml) === 1
            && !str_contains($adminHtml, 'name="email"'),
        'admin email field should remain readonly without a mutable name attribute'
    );

    $counselorUser = guidanceProfileTestUserRow($counselorId, $counselorEmail, 'Profile', 'Counselor', 'counselor');
    $counselorHtml = guidanceRenderProfileForTest($counselorUser);
    gtProfileEmail(
        'non-admin own-profile email is editable',
        preg_match('/<input type="email" name="email" value="[^"]+" required/s', $counselorHtml) === 1,
        'expected editable email input for counselor profile page'
    );

    $updateResponse = guidanceSubmitOwnProfileForTest($counselorUser, [
        'email' => $updatedCounselorEmail,
        'first_name' => 'Profile',
        'last_name' => 'Counselor',
    ]);
    gtProfileEmail(
        'non-admin own-profile email update succeeds',
        (int)($updateResponse['status'] ?? 0) === 200
            && (($updateResponse['json']['ok'] ?? false) === true),
        json_encode($updateResponse, JSON_UNESCAPED_SLASHES)
    );

    $emailLookup->execute([$counselorId]);
    $storedEmail = (string)($emailLookup->fetchColumn() ?: '');
    gtProfileEmail(
        'non-admin own-profile email update persists',
        $storedEmail === $updatedCounselorEmail,
        $storedEmail
    );
} finally {
    $_GET = [];
    $_POST = [];
    $_REQUEST = [];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/admin/guidance/profile';
    unset($_SERVER['CONTENT_TYPE'], $_SERVER['HTTP_HX_REQUEST']);
    $cleanupById->execute([$adminId, $counselorId]);
}

echo "\n" . str_repeat('-', 50) . "\n";
echo "\n=== GUIDANCE PROFILE EMAIL UPDATE TEST ===\n\n";
foreach ($resultLines as $line) {
    echo $line;
}
echo "  Result: {$pass} passed, {$fail} failed\n";
if ($errors !== []) {
    echo "\n  Failures:\n";
    foreach ($errors as $error) {
        echo "    - {$error}\n";
    }
}
echo "\n";

exit($fail > 0 ? 1 : 0);