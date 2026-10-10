<?php
/**
 * Ensure a CMS admin account exists for a host's tenant.
 *
 * WHY THIS EXISTS: the browser render-parity gate needs a logged-in CMS admin
 * for the tenant under test. database/seeds/browser_environment.php creates one,
 * but it hardcodes `akiracms.test` and does a great deal else besides (module
 * enabling, daily-ledger fixtures, rate-limit clearing), so pointing it at a
 * different host means either editing a shared fixture or dragging unrelated
 * setup along. This does the one thing, for any host.
 *
 * Idempotent: it refreshes the account if it exists and creates it if it does
 * not, so re-running is always safe.
 *
 * Usage:
 *   php tools/cms-ensure-admin.php --host=cmsnew.test
 *   php tools/cms-ensure-admin.php --host=example.test --username=qa --password-env=CMS_QA_PASSWORD
 *
 * Credentials: the password comes from --password-env=VAR (preferred; keeps it
 * out of the process list and shell history) or from --password=…, or defaults
 * to the repository's existing browser-fixture credential — the same one
 * database/seeds/browser_environment.php uses, so no new secret is introduced.
 * The password is never printed. THIS IS A LOCAL DEVELOPMENT FIXTURE ACCOUNT:
 * do not create it on a public host, and treat the default as compromised by
 * publication like any other committed test credential.
 */
declare(strict_types=1);

chdir(dirname(__DIR__));
require_once 'bootstrap.php';
require_once 'src/helpers/module-manager.php';

const DEFAULT_FIXTURE_PASSWORD = 'Admin123!';

/** Read --key=value options. */
function cmsEnsureAdminOption(array $argv, string $key, ?string $default = null): ?string
{
    $needle = '--' . $key . '=';
    foreach ($argv as $arg) {
        if (str_starts_with($arg, $needle)) {
            return substr($arg, strlen($needle));
        }
    }
    return $default;
}

$host = cmsEnsureAdminOption($argv, 'host');
if ($host === null || trim($host) === '') {
    fwrite(STDERR, "Usage: php tools/cms-ensure-admin.php --host=<domain> [--username=admin] [--email=…] [--password-env=VAR]\n");
    exit(1);
}
$host = trim($host);
$username = trim((string) cmsEnsureAdminOption($argv, 'username', 'admin'));
$displayName = trim((string) cmsEnsureAdminOption($argv, 'display-name', 'Browser CMS Admin'));
$email = trim((string) cmsEnsureAdminOption($argv, 'email', 'admin@' . $host));
$role = trim((string) cmsEnsureAdminOption($argv, 'role', 'administrator'));

$passwordEnv = cmsEnsureAdminOption($argv, 'password-env');
$passwordArg = cmsEnsureAdminOption($argv, 'password');
if ($passwordEnv !== null) {
    $password = (string) ($_ENV[$passwordEnv] ?? getenv($passwordEnv) ?: '');
    if ($password === '') {
        fwrite(STDERR, "--password-env={$passwordEnv} is set but the variable is empty\n");
        exit(1);
    }
    $passwordSource = "environment variable {$passwordEnv}";
} elseif ($passwordArg !== null) {
    $password = $passwordArg;
    $passwordSource = 'the --password argument (visible in the process list — prefer --password-env)';
} else {
    $password = DEFAULT_FIXTURE_PASSWORD;
    $passwordSource = 'the repository browser-fixture default';
}

$record = \Ikabud\Kernel\TenantResolver::lookupControlHostRecord($host);
$tenantId = is_array($record) && isset($record['tenant_id']) ? (int) $record['tenant_id'] : 0;
if ($tenantId <= 0) {
    fwrite(STDERR, "No tenant resolves for host '{$host}'. Known hosts: run `php ikabud tenant:list`.\n");
    exit(1);
}

$db = app()->dbForTenant($tenantId);
if (!$db instanceof \PDO) {
    fwrite(STDERR, "Tenant #{$tenantId} has no database connection.\n");
    exit(1);
}

$hash = password_hash($password, PASSWORD_DEFAULT);

$find = $db->prepare('SELECT id FROM cms_users WHERE username = :username LIMIT 1');
$find->execute([':username' => $username]);
$existingId = (int) ($find->fetchColumn() ?: 0);

if ($existingId > 0) {
    $db->prepare(
        'UPDATE cms_users
            SET email = :email,
                password_hash = :password_hash,
                display_name = :display_name,
                role = :role,
                is_active = 1
          WHERE id = :id'
    )->execute([
        ':email' => $email,
        ':password_hash' => $hash,
        ':display_name' => $displayName,
        ':role' => $role,
        ':id' => $existingId,
    ]);
    $userId = $existingId;
    $action = 'refreshed';
} else {
    $db->prepare(
        'INSERT INTO cms_users (username, email, password_hash, display_name, role, is_active, created_at)
         VALUES (:username, :email, :password_hash, :display_name, :role, 1, NOW())'
    )->execute([
        ':username' => $username,
        ':email' => $email,
        ':password_hash' => $hash,
        ':display_name' => $displayName,
        ':role' => $role,
    ]);
    $userId = (int) $db->lastInsertId();
    $action = 'created';
}

printf("cms admin %s: host=%s tenant=%d user=#%d username=%s role=%s\n", $action, $host, $tenantId, $userId, $username, $role);
printf("password source: %s (not printed)\n", $passwordSource);
printf("login at: http://%s/cms/login\n", $host);
