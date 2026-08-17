<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Services;

use Ikabud\Kernel\Crypto;
use Ikabud\Kernel\Database\MigrationRunner;
use PDO;
use Throwable;

/**
 * 4.4: Tenant Provisioning Service
 *
 * Consolidates the multi-step tenant provisioning workflow into a reusable service.
 * Steps: validate tenant → create DB → connect → run migrations → seed admin user.
 *
 * Used by `php ikabud tenant:provision` CLI and can be invoked from admin APIs.
 */
class TenantProvisioner
{
    private PDO $controlDb;
    private array $log = [];
    private array $errors = [];

    public function __construct(PDO $controlDb)
    {
        $this->controlDb = $controlDb;
    }

    /**
     * Full provisioning pipeline.
     *
     * @param int    $tenantId
     * @param array  $options  Keys: skip_db_create, admin_user, admin_pass, admin_name
     * @return array ['ok' => bool, 'log' => [...], 'errors' => [...], 'migrations' => int]
     */
    public function provision(int $tenantId, array $options = []): array
    {
        $this->log = [];
        $this->errors = [];
        $migrationCount = 0;

        try {
            // Step 1: Load tenant record
            $tenant = $this->loadTenant($tenantId);
            if ($tenant === null) {
                return $this->result(false, 0);
            }

            $tenantKey = (string)($tenant['tenant_key'] ?? '');
            $this->log('Provisioning tenant #' . $tenantId . ' (' . $tenantKey . ')');

            // Step 2: Resolve DB credentials
            $creds = $this->resolveDbCredentials($tenant);
            if ($creds === null) {
                return $this->result(false, 0);
            }

            $entryModule = trim((string)($tenant['entry_module_id'] ?? ''));
            if ($this->requiresSeededAdminCredentials($entryModule)) {
                $adminUser = trim((string)($options['admin_user'] ?? ''));
                $adminPass = trim((string)($options['admin_pass'] ?? ''));
                if ($adminUser === '' || $adminPass === '') {
                    $this->error('Entry-module tenant provisioning requires admin_user and admin_pass for ' . $entryModule . '.');
                    return $this->result(false, 0);
                }
            }

            // Step 3: Create database (unless skipped)
            if (empty($options['skip_db_create'])) {
                $ok = $this->createDatabase($creds);
                if (!$ok) {
                    return $this->result(false, 0);
                }
            } else {
                $this->log('Skipping database creation (skip_db_create)');
            }

            // Step 4: Connect to tenant DB
            $tenantPdo = $this->connectTenantDb($creds);
            if ($tenantPdo === null) {
                return $this->result(false, 0);
            }
            $this->log('Connected to tenant database');

            // Step 5: Set tenant context
            app()->tenant()->setTenantId($tenantId);

            // Step 6: Run module migrations
            $migrationCount = $this->runModuleMigrations($tenantPdo, $entryModule !== '' ? $entryModule : null);

            // Step 7: Run kernel migrations
            $kernelCount = $this->runKernelMigrations($tenantPdo, $entryModule !== '' ? $entryModule : null);
            $migrationCount += $kernelCount;

            // Step 8: Seed admin user
            $adminUser = trim((string)($options['admin_user'] ?? ''));
            $adminPass = trim((string)($options['admin_pass'] ?? ''));
            $adminName = trim((string)($options['admin_name'] ?? 'Admin'));
            if ($adminUser !== '' && $adminPass !== '') {
                $this->seedAdminUser($tenantPdo, $adminUser, $adminPass, $adminName, $entryModule);
            }

            $this->log('Provisioning complete for tenant #' . $tenantId);
            return $this->result(true, $migrationCount);

        } catch (Throwable $e) {
            $this->error('Provisioning failed: ' . $e->getMessage());
            return $this->result(false, $migrationCount);
        }
    }

    /**
     * Validate that a tenant can be provisioned (dry-run check).
     */
    public function validate(int $tenantId): array
    {
        $issues = [];

        $tenant = $this->loadTenant($tenantId);
        if ($tenant === null) {
            $issues[] = 'Tenant not found';
            return ['ok' => false, 'issues' => $issues];
        }

        $creds = $this->resolveDbCredentials($tenant);
        if ($creds === null) {
            $issues[] = 'No DB connection configured';
        }

        if (trim((string)($tenant['entry_module_id'] ?? '')) === '') {
            $issues[] = 'No entry module configured';
        }

        return ['ok' => empty($issues), 'issues' => $issues, 'tenant_key' => (string)($tenant['tenant_key'] ?? '')];
    }

    // ── Internal steps ──────────────────────────────────────────

    private function loadTenant(int $tenantId): ?array
    {
        $stmt = $this->controlDb->prepare(
            'SELECT t.id, t.tenant_key, t.status, t.entry_module_id, '
            . 'c.db_driver, c.db_host, c.db_port, c.db_name, c.db_user, c.db_pass, c.db_charset, '
            . 'c.db_pass_ciphertext, c.db_pass_iv, c.db_pass_tag '
            . 'FROM kernel_tenants t '
            . 'LEFT JOIN kernel_tenant_db_connections c ON c.tenant_id = t.id '
            . 'WHERE t.id = :tid LIMIT 1'
        );
        $stmt->execute([':tid' => $tenantId]);
        $tenant = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($tenant)) {
            $this->error('Tenant not found: ' . $tenantId);
            return null;
        }
        return $tenant;
    }

    private function resolveDbCredentials(array $tenant): ?array
    {
        $host = (string)($tenant['db_host'] ?? '');
        $port = (string)($tenant['db_port'] ?? '3306');
        $name = (string)($tenant['db_name'] ?? '');
        $user = (string)($tenant['db_user'] ?? '');
        $charset = (string)($tenant['db_charset'] ?? 'utf8mb4');

        if ($host === '' || $name === '' || $user === '') {
            $this->error('Incomplete DB connection: host/name/user required');
            return null;
        }

        // Decrypt password
        $pass = (string)($tenant['db_pass'] ?? '');
        $cipher = (string)($tenant['db_pass_ciphertext'] ?? '');
        $iv = (string)($tenant['db_pass_iv'] ?? '');
        $tag = (string)($tenant['db_pass_tag'] ?? '');
        if ($cipher !== '' && $iv !== '' && $tag !== '') {
            $pass = (new Crypto())->decryptString($cipher, $iv, $tag);
        }

        return compact('host', 'port', 'name', 'user', 'pass', 'charset');
    }

    private function createDatabase(array $creds): bool
    {
        try {
            $dsn = 'mysql:host=' . $creds['host'] . ';port=' . $creds['port'] . ';charset=' . $creds['charset'];
            $pdo = new PDO($dsn, $creds['user'], $creds['pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
            $safeName = preg_replace('/[^a-zA-Z0-9_]/', '', $creds['name']);
            $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . $safeName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $this->log('Database created (or already exists)');
            return true;
        } catch (Throwable $e) {
            $this->error('Could not create database: ' . $e->getMessage());
            return false;
        }
    }

    private function connectTenantDb(array $creds): ?PDO
    {
        try {
            $dsn = 'mysql:host=' . $creds['host'] . ';port=' . $creds['port']
                . ';dbname=' . $creds['name'] . ';charset=' . $creds['charset'];
            return new PDO($dsn, $creds['user'], $creds['pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PdoMysql::attr('ATTR_INIT_COMMAND') => "SET NAMES 'utf8mb4' COLLATE 'utf8mb4_unicode_ci'",
            ]);
        } catch (Throwable $e) {
            $this->error('Cannot connect to tenant DB: ' . $e->getMessage());
            return null;
        }
    }

    private function runModuleMigrations(PDO $tenantPdo, ?string $entryModule): int
    {
        $runner = new MigrationRunner($tenantPdo);
        $plan = tenantProvisionModulePlan($entryModule);
        $total = 0;

        foreach ($plan as $moduleId) {
            $result = $runner->migrate($moduleId);
            if (!empty($result)) {
                $this->log("Migrated $moduleId: " . count($result) . ' file(s)');
                $total += count($result);
            }
        }

        $this->log("Module migrations: $total total");
        return $total;
    }

    private function runKernelMigrations(PDO $tenantPdo, ?string $entryModule): int
    {
        $applied = tenantSyncKernelMigrations($tenantPdo, null, $entryModule);
        if (!empty($applied)) {
            $this->log('Kernel migrations: ' . count($applied) . ' applied');
        }
        return count($applied);
    }

    private function seedAdminUser(PDO $tenantPdo, string $user, string $pass, string $name, string $entryModule): void
    {
        // Manifest-driven path: if the entry module declares auth_owned, seed
        // into its declared users_table using the declared columns/role.
        $spec = function_exists('kernelAuthOwnedSpecForModule')
            ? kernelAuthOwnedSpecForModule($entryModule)
            : null;

        if (is_array($spec)) {
            $this->seedAdminUserFromAuthOwnedSpec($tenantPdo, $spec, $user, $pass, $name);
            return;
        }

        // Legacy fallbacks for entry modules that have not yet declared
        // auth_owned (cms-via-users, plain `users` table). These remain in
        // place so existing tenants keep provisioning cleanly.
        $table = match ($entryModule) {
            'cms' => 'cms_users',
            default => 'users',
        };

        try {
            $tableCheck = $tenantPdo->query("SHOW TABLES LIKE '{$table}'")->fetchColumn();
            if ($tableCheck === false) {
                $this->log("Table '$table' does not exist — skipping user seed");
                return;
            }

            $exists = $tenantPdo->prepare("SELECT id FROM `{$table}` WHERE username = :u LIMIT 1");
            $exists->execute([':u' => $user]);
            if ($exists->fetch()) {
                $this->log("User '$user' already exists in $table");
                return;
            }

            $hash = password_hash($pass, PASSWORD_BCRYPT);
            if ($table === 'cms_users') {
                $stmt = $tenantPdo->prepare(
                    "INSERT INTO `cms_users` (username, email, password_hash, display_name, role, is_active) "
                    . "VALUES (:u, :e, :p, :n, 'administrator', 1)"
                );
                $stmt->execute([':u' => $user, ':e' => $user . '@localhost', ':p' => $hash, ':n' => $name]);
            } else {
                $stmt = $tenantPdo->prepare(
                    "INSERT INTO `users` (username, password, full_name, role, status) "
                    . "VALUES (:u, :p, :n, 'admin', 'active')"
                );
                $stmt->execute([':u' => $user, ':p' => $hash, ':n' => $name]);
            }

            $this->log("Admin user '$user' seeded in $table");
        } catch (Throwable $e) {
            $this->error('User seed failed: ' . $e->getMessage());
        }
    }

    /**
     * Seed an admin user into a module's auth_owned users table using the
     * normalized manifest spec. Idempotent: re-running for the same username
     * is a no-op.
     */
    private function seedAdminUserFromAuthOwnedSpec(PDO $tenantPdo, array $spec, string $user, string $pass, string $name): void
    {
        $table = (string)$spec['users_table'];
        try {
            $tableCheck = $tenantPdo->query("SHOW TABLES LIKE '{$table}'")->fetchColumn();
            if ($tableCheck === false) {
                $this->log("Table '$table' does not exist — skipping user seed");
                return;
            }

            $usernameCol = (string)$spec['username_column'];
            $emailCol    = (string)$spec['email_column'];
            $pwdCol      = (string)$spec['password_column'];
            $nameCol     = (string)$spec['name_column'];
            $activeCol   = (string)$spec['active_column'];
            $role        = (string)$spec['default_admin_role'];

            // Idempotency: lookup by username column (or email if no separate
            // username — e.g. gm_users where username_column == email_column).
            $exists = $tenantPdo->prepare(
                "SELECT id FROM `{$table}` WHERE `{$usernameCol}` = :u LIMIT 1"
            );
            $exists->execute([':u' => $user]);
            if ($exists->fetch()) {
                $this->log("User '$user' already exists in $table");
                return;
            }

            $hash = password_hash($pass, PASSWORD_BCRYPT);

            // Build a column list that respects which columns the table actually
            // has. The manifest declares the canonical names, but a few legacy
            // tables omit `email`, so keep the username/email columns identical
            // when the manifest pins them to the same name.
            $cols = ['`' . $usernameCol . '`'];
            $vals = [':u'];
            $params = [':u' => $user];

            // Tenant-scoped users tables (e.g. pal_users) must be seeded with
            // the provisioned tenant's real id — not a hardcoded placeholder —
            // otherwise auth lookups (username + tenant_id) can never match.
            $tenantIdColumn = trim((string)($spec['tenant_id_column'] ?? ''));
            if ($tenantIdColumn !== '') {
                $provisionedTenantId = (int)(app()->tenant()->current() ?? 0);
                if ($provisionedTenantId > 0) {
                    $cols[] = '`' . $tenantIdColumn . '`';
                    $vals[] = ':tid';
                    $params[':tid'] = $provisionedTenantId;
                }
            }

            if ($emailCol !== '' && $emailCol !== $usernameCol) {
                $cols[] = '`' . $emailCol . '`';
                $vals[] = ':e';
                $params[':e'] = $user . '@localhost';
            }

            $cols[] = '`' . $pwdCol . '`';
            $vals[] = ':p';
            $params[':p'] = $hash;

            if ($nameCol !== '') {
                $cols[] = '`' . $nameCol . '`';
                $vals[] = ':n';
                $params[':n'] = $name;
            }

            $cols[] = '`role`';
            $vals[] = ':r';
            $params[':r'] = $role;

            if ($activeCol !== '') {
                $cols[] = '`' . $activeCol . '`';
                $vals[] = '1';
            }

            $sql = 'INSERT INTO `' . $table . '` (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $vals) . ')';
            $tenantPdo->prepare($sql)->execute($params);

            $this->log("Admin user '$user' seeded in $table (auth_owned spec for module {$spec['module_id']})");
        } catch (Throwable $e) {
            $this->error('User seed failed for ' . $table . ': ' . $e->getMessage());
        }
    }

    private function requiresSeededAdminCredentials(string $entryModule): bool
    {
        // Manifest-driven: a module opts into the named-admin requirement by
        // setting auth_owned.requires_named_admin_on_provision = true.
        if (function_exists('kernelAuthOwnedSpecForModule')) {
            $spec = kernelAuthOwnedSpecForModule($entryModule);
            if (is_array($spec) && !empty($spec['requires_named_admin_on_provision'])) {
                return true;
            }
        }

        // Backstop: keep bakeshop hardcoded so the kernel default-deny stays
        // intact even if the module manifest is missing on disk for any reason.
        return in_array($entryModule, ['bakeshop'], true);
    }

    // ── Logging helpers ─────────────────────────────────────────

    private function log(string $msg): void
    {
        $this->log[] = $msg;
    }

    private function error(string $msg): void
    {
        $this->errors[] = $msg;
    }

    private function result(bool $ok, int $migrations): array
    {
        return [
            'ok' => $ok,
            'log' => $this->log,
            'errors' => $this->errors,
            'migrations' => $migrations,
        ];
    }

    /**
     * Get accumulated log messages.
     */
    public function getLog(): array
    {
        return $this->log;
    }

    /**
     * Get accumulated errors.
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
