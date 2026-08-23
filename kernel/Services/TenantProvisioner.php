<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Services;

use Ikabud\Kernel\Crypto;
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
    private array $migrationDetails = [];

    public function __construct(PDO $controlDb)
    {
        $this->controlDb = $controlDb;
    }

    /**
     * Full provisioning pipeline (CAS state machine).
     *
     * State transitions: pending → provisioning → active. Any failure returns
     * the tenant to 'pending' with an operator-visible error. Activation is the
     * FINAL control-DB write, performed only after a successful dedicated-DB
     * migration + seed + verification. A per-tenant advisory lock/lease prevents
     * concurrent upsert/provision.
     *
     * @param int   $tenantId
     * @param array $options Keys: skip_db_create, admin_user, admin_pass, admin_name
     * @return array ['ok' => bool, 'log' => [...], 'errors' => [...], 'migrations' => int]
     */
    public function provision(int $tenantId, array $options = []): array
    {
        $this->log = [];
        $this->errors = [];
        $this->migrationDetails = [];
        $migrationCount = 0;

        try {
            // Step 1: Load tenant record
            $tenant = $this->loadTenant($tenantId);
            if ($tenant === null) {
                return $this->result(false, 0);
            }

            $tenantKey = (string)($tenant['tenant_key'] ?? '');
            $this->log('Provisioning tenant #' . $tenantId . ' (' . $tenantKey . ')');

            // CAS lock/lease: block concurrent provisioning of the same tenant.
            $lockName = 'ikabud_provision_' . $tenantId;
            $lockStmt = $this->controlDb->prepare('SELECT GET_LOCK(?, ?)');
            $lockStmt->execute([$lockName, 10]);
            $lockAcquired = (int)$lockStmt->fetchColumn();
            $lockStmt->closeCursor();

            if ($lockAcquired !== 1) {
                $this->error('Could not acquire provisioning lock for tenant #' . $tenantId . ' — another provisioning may be running.');
                return $this->result(false, 0);
            }

            try {
                return $this->provisionUnlocked($tenantId, $tenant, $options);
            } finally {
                $rel = $this->controlDb->query('SELECT RELEASE_LOCK(' . $this->controlDb->quote($lockName) . ')');
                if ($rel) {
                    $rel->fetchColumn();
                    $rel->closeCursor();
                }
            }
        } catch (Throwable $e) {
            $this->error('Provisioning failed: ' . $e->getMessage());
            $this->setTenantStatus($tenantId, 'provisioning', 'pending');
            return $this->result(false, $migrationCount);
        }
    }

    /**
     * Provisioning pipeline body (caller holds the advisory lock).
     */
    private function provisionUnlocked(int $tenantId, array $tenant, array $options): array
    {
        $entryModule = trim((string)($tenant['entry_module_id'] ?? ''));
        $initialStatus = trim((string)($tenant['status'] ?? ''));
        $migrationCount = 0;

        // CAS: enter 'provisioning' before any migration work.
        if (!$this->setTenantStatus($tenantId, $initialStatus !== '' ? $initialStatus : 'pending', 'provisioning')) {
            $this->error('Failed to enter provisioning state for tenant #' . $tenantId . '.');
            return $this->result(false, 0);
        }

        // Step 2: Resolve DB credentials + reject base-DB connections.
        $creds = $this->resolveDbCredentials($tenant);
        if ($creds === null) {
            $this->setTenantStatus($tenantId, 'provisioning', 'pending');
            return $this->result(false, 0);
        }
        if (function_exists('tenantRejectBaseDbConnection')) {
            $isolation = tenantRejectBaseDbConnection([
                'driver' => (string)($creds['driver'] ?? 'mysql'),
                'host' => $creds['host'],
                'port' => $creds['port'],
                'db_name' => $creds['name'],
            ]);
            if (empty($isolation['ok'])) {
                $this->error((string)($isolation['error'] ?? 'Base DB connection rejected'));
                $this->setTenantStatus($tenantId, 'provisioning', 'pending');
                return $this->result(false, 0);
            }
        }

        // Canonical manifest-driven auth_owned spec (shared by credential
        // requirement, seeding, and verification).
        $spec = $entryModule !== '' ? $this->resolveAuthOwnedSpec($entryModule) : null;

        // Validate default_admin_role ∈ admin_roles BEFORE any migration work.
        if (is_array($spec)) {
            $defaultRole = (string)($spec['default_admin_role'] ?? '');
            $adminRoles = is_array($spec['admin_roles'] ?? null) ? $spec['admin_roles'] : [];
            if (!in_array($defaultRole, $adminRoles, true)) {
                $this->error('default_admin_role must be one of admin_roles for module ' . $entryModule . '.');
                $this->setTenantStatus($tenantId, 'provisioning', 'pending');
                return $this->result(false, 0);
            }
        }

        // Seed-credentials contract: named-admin modules require user+pass.
        $adminUser = trim((string)($options['admin_user'] ?? ''));
        $adminPass = trim((string)($options['admin_pass'] ?? ''));
        $adminName = trim((string)($options['admin_name'] ?? 'Admin'));
        if ($this->requiresSeededAdminCredentials($entryModule, $spec)) {
            if ($adminUser === '' || $adminPass === '') {
                $this->error('Entry-module tenant provisioning requires admin_user and admin_pass for ' . $entryModule . '.');
                $this->setTenantStatus($tenantId, 'provisioning', 'pending');
                return $this->result(false, 0);
            }
        }

        // Step 3: Create database (unless skipped)
        if (empty($options['skip_db_create'])) {
            if (!$this->createDatabase($creds)) {
                $this->setTenantStatus($tenantId, 'provisioning', 'pending');
                return $this->result(false, 0);
            }
        } else {
            $this->log('Skipping database creation (skip_db_create)');
        }

        // Step 4: Connect to tenant DB
        $tenantPdo = $this->connectTenantDb($creds);
        if ($tenantPdo === null) {
            $this->setTenantStatus($tenantId, 'provisioning', 'pending');
            return $this->result(false, 0);
        }
        $this->log('Connected to tenant database');

        // Step 5: Set tenant context
        app()->tenant()->setTenantId($tenantId);

        // Step 6: Run the shared guarded migration coordinator.
        $coordinated = $this->runCoordinatedMigrations($tenantPdo, $tenantId, $entryModule !== '' ? $entryModule : null);
        $this->migrationDetails = $coordinated['details'];
        $migrationCount += $coordinated['count'];

        // Step 8: Seed admin user (fail-fast when required).
        if ($adminUser !== '' && $adminPass !== '') {
            $seeded = $this->seedAdminUser($tenantPdo, $adminUser, $adminPass, $adminName, $entryModule, $spec);
            if (empty($seeded['ok'])) {
                $this->error((string)($seeded['error'] ?? 'Admin seed failed'));
                $this->setTenantStatus($tenantId, 'provisioning', 'pending');
                return $this->result(false, $migrationCount);
            }
        }

        // Step 9: Verify, then activate (activation is the final control-DB write).
        $verify = $this->verifyProvisionedTenant($tenantPdo, $entryModule, $spec);
        if (empty($verify['ok'])) {
            $this->error((string)($verify['error'] ?? 'Tenant verification failed'));
            $this->setTenantStatus($tenantId, 'provisioning', 'pending');
            return $this->result(false, $migrationCount);
        }

        tenantSetModuleActivationState($tenantPdo, $tenantId, (array)($this->migrationDetails['plan'] ?? []), true);
        $this->setTenantStatus($tenantId, 'provisioning', 'active');
        $this->log('Provisioning complete for tenant #' . $tenantId);
        return $this->result(true, $migrationCount);
    }

    /**
     * Set the control-plane tenant status (CAS transition).
     */
    private function setTenantStatus(int $tenantId, string $expectedStatus, string $status): bool
    {
        try {
            $updated = tenantCasStatus($this->controlDb, $tenantId, $expectedStatus, $status);
            if ($updated) {
                $this->log("Tenant status {$expectedStatus} -> {$status}");
                return true;
            }
            $this->error('Failed tenant status CAS ' . $expectedStatus . ' -> ' . $status . ' for tenant #' . $tenantId . '.');
        } catch (Throwable $e) {
            $this->error('Failed to update tenant status to ' . $status . ': ' . $e->getMessage());
        }

        return false;
    }

    /**
     * Resolve the canonical on-disk auth_owned spec for a module.
     *
     * @return array<string, mixed>|null
     */
    private function resolveAuthOwnedSpec(string $entryModule): ?array
    {
        if (function_exists('kernelAuthOwnedSpecFromDisk')) {
            return kernelAuthOwnedSpecFromDisk($entryModule);
        }
        if (function_exists('kernelAuthOwnedSpecForModule')) {
            return kernelAuthOwnedSpecForModule($entryModule);
        }
        return null;
    }

    /**
     * Verify a provisioned tenant: required kernel tables + admin seeded into
     * the declared users_table when a named admin was provided.
     *
     * @param array<string, mixed>|null $spec
     * @return array{ok: bool, error?: string}
     */
    private function verifyProvisionedTenant(PDO $tenantPdo, string $entryModule, ?array $spec): array
    {
        foreach (['tenant_module_settings', 'audit_logs'] as $requiredTable) {
            if (!function_exists('tenantDatabaseHasTable')) {
                break;
            }
            if (!tenantDatabaseHasTable($tenantPdo, $requiredTable)) {
                return ['ok' => false, 'error' => "Required tenant table '{$requiredTable}' is missing after provisioning"];
            }
        }

        if (isset($GLOBALS['tenant_provision_verify_override']) && is_callable($GLOBALS['tenant_provision_verify_override'])) {
            $override = $GLOBALS['tenant_provision_verify_override'];
            $result = $override($tenantPdo, $entryModule, $spec);
            if (is_array($result)) {
                return $result;
            }
        }

        return ['ok' => true];
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

    private function runCoordinatedMigrations(PDO $tenantPdo, int $tenantId, ?string $entryModule): array
    {
        $result = tenantRunCoordinatedProvisionMigrations($tenantPdo, $entryModule, null, null, $tenantId);
        $kernelCount = count($result['kernel'] ?? []);
        if ($kernelCount > 0) {
            $this->log('Kernel migrations: ' . $kernelCount . ' applied');
        }

        $moduleCount = 0;
        foreach (($result['modules'] ?? []) as $moduleId => $files) {
            if (!is_array($files) || $files === []) {
                continue;
            }
            $this->log('Migrated ' . $moduleId . ': ' . count($files) . ' file(s)');
            $moduleCount += count($files);
        }

        $this->log('Module migrations: ' . $moduleCount . ' total');

        return [
            'count' => $kernelCount + $moduleCount,
            'details' => $result,
        ];
    }

    /**
     * Seed the admin user (fail-fast).
     *
     * Uses the canonical on-disk auth_owned spec when present; falls back to the
     * legacy `users`/`cms_users` path for modules that have not declared
     * auth_owned. Returns ['ok' => bool, 'error' => string|null]. A required
     * named-admin seed that fails (missing users_table, invalid manifest, absent
     * tenant id, or DB error) fails provisioning — no silent skip.
     *
     * @param array<string, mixed>|null $spec Canonical auth_owned spec
     * @return array{ok: bool, error?: string}
     */
    private function seedAdminUser(PDO $tenantPdo, string $user, string $pass, string $name, string $entryModule, ?array $spec = null): array
    {
        if (is_array($spec)) {
            return $this->seedAdminUserFromAuthOwnedSpec($tenantPdo, $spec, $user, $pass, $name);
        }

        // Legacy fallbacks for entry modules that have not yet declared
        // auth_owned (cms-via-users, plain `users` table). These remain in
        // place so existing tenants keep provisioning cleanly.
        $table = match ($entryModule) {
            'cms' => 'cms_users',
            default => 'users',
        };

        try {
            $tableCheck = $tenantPdo->query('SHOW TABLES LIKE ' . $tenantPdo->quote($table))->fetchColumn();
            if ($tableCheck === false) {
                // Required named-admin seed failure: the declared users_table is
                // missing → fail provisioning rather than silently skip.
                return ['ok' => false, 'error' => "Required users_table '{$table}' does not exist — admin seed failed"];
            }

            $exists = $tenantPdo->prepare("SELECT id FROM `{$table}` WHERE username = :u LIMIT 1");
            $exists->execute([':u' => $user]);
            if ($exists->fetch()) {
                $this->log("User '$user' already exists in $table");
                return ['ok' => true];
            }

            $hash = password_hash($pass, PASSWORD_BCRYPT);
            if ($table === 'cms_users') {
                $stmt = $tenantPdo->prepare(
                    "INSERT INTO `cms_users` (username, email, password_hash, display_name, role, is_active) "
                    . "VALUES (:u, :e, :p, :n, 'administrator', 1)"
                );
                $stmt->execute([':u' => $user, ':e' => $user . '@localhost', ':p' => $hash, ':n' => $name]);
            } else {
                // Legacy kernel-users schema: password_hash/is_active (NOT the
                // legacy password/status), consistent with tenantEnsureKernelUserTable().
                $stmt = $tenantPdo->prepare(
                    "INSERT INTO `users` (username, password_hash, full_name, role, is_active) "
                    . "VALUES (:u, :p, :n, 'admin', 1)"
                );
                $stmt->execute([':u' => $user, ':p' => $hash, ':n' => $name]);
            }

            $this->log("Admin user '$user' seeded in $table");
            return ['ok' => true];
        } catch (Throwable $e) {
            $this->error('User seed failed: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'User seed failed for ' . $table . ': ' . $e->getMessage()];
        }
    }

    /**
     * Seed an admin user into a module's auth_owned users table using the
     * normalized manifest spec (fail-fast). Honors declared id/role/active/
     * tenant_id columns in BOTH the idempotency lookup and the insert, and
     * uses the declared role_column (not a hardcoded 'role').
     *
     * @param array<string, mixed> $spec Canonical auth_owned spec
     * @return array{ok: bool, error?: string}
     */
    private function seedAdminUserFromAuthOwnedSpec(PDO $tenantPdo, array $spec, string $user, string $pass, string $name): array
    {
        $table = (string)$spec['users_table'];
        try {
            $tableCheck = $tenantPdo->query('SHOW TABLES LIKE ' . $tenantPdo->quote($table))->fetchColumn();
            if ($tableCheck === false) {
                // Required named-admin seed failure: users_table missing.
                return ['ok' => false, 'error' => "Required users_table '{$table}' does not exist — admin seed failed"];
            }

            $idCol       = trim((string)($spec['id_column'] ?? 'id'));
            $usernameCol = (string)$spec['username_column'];
            $emailCol    = (string)$spec['email_column'];
            $pwdCol      = (string)$spec['password_column'];
            $nameCol     = (string)$spec['name_column'];
            $activeCol   = (string)$spec['active_column'];
            $roleCol     = trim((string)($spec['role_column'] ?? 'role'));
            $role        = (string)$spec['default_admin_role'];
            $adminRoles  = is_array($spec['admin_roles'] ?? null) ? $spec['admin_roles'] : [];
            if (!in_array($role, $adminRoles, true)) {
                return ['ok' => false, 'error' => "default_admin_role '{$role}' is not in admin_roles for module {$spec['module_id']}"];
            }

            // Idempotency lookup uses the declared id_column and tenant_id
            // column (when tenant-scoped) — same columns as the insert.
            $lookupCols = [$usernameCol];
            $lookupWhere = "`{$usernameCol}` = :u";
            $lookupParams = [':u' => $user];
            $tenantIdColumn = trim((string)($spec['tenant_id_column'] ?? ''));
            if ($tenantIdColumn !== '') {
                $provisionedTenantId = (int)(app()->tenant()->current() ?? 0);
                if ($provisionedTenantId <= 0) {
                    return ['ok' => false, 'error' => "Tenant id is absent — cannot seed tenant-scoped users_table '{$table}'"];
                }
                $lookupCols[] = $tenantIdColumn;
                $lookupWhere .= " AND `{$tenantIdColumn}` = :tid";
                $lookupParams[':tid'] = $provisionedTenantId;
            }

            $exists = $tenantPdo->prepare('SELECT `' . $idCol . '` FROM `' . $table . '` WHERE ' . $lookupWhere . ' LIMIT 1');
            $exists->execute($lookupParams);
            if ($exists->fetch()) {
                $this->log("User '$user' already exists in $table");
                return ['ok' => true];
            }

            $hash = password_hash($pass, PASSWORD_BCRYPT);

            // Build the insert column list.
            $cols = ['`' . $usernameCol . '`'];
            $vals = [':u'];
            $params = [':u' => $user];

            if ($tenantIdColumn !== '') {
                $provisionedTenantId = (int)(app()->tenant()->current() ?? 0);
                $cols[] = '`' . $tenantIdColumn . '`';
                $vals[] = ':tid';
                $params[':tid'] = $provisionedTenantId;
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

            $cols[] = '`' . $roleCol . '`';
            $vals[] = ':r';
            $params[':r'] = $role;

            if ($activeCol !== '') {
                $cols[] = '`' . $activeCol . '`';
                $vals[] = '1';
            }

            $sql = 'INSERT INTO `' . $table . '` (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $vals) . ')';
            $tenantPdo->prepare($sql)->execute($params);

            $this->log("Admin user '$user' seeded in $table (auth_owned spec for module {$spec['module_id']})");
            return ['ok' => true];
        } catch (Throwable $e) {
            $this->error('User seed failed for ' . $table . ': ' . $e->getMessage());
            return ['ok' => false, 'error' => 'User seed failed for ' . $table . ': ' . $e->getMessage()];
        }
    }

    /**
     * Whether the entry module requires explicit named-admin credentials during
     * provisioning. Shares the SAME canonical on-disk manifest resolver as
     * seeding + verification.
     *
     * @param array<string, mixed>|null $spec Canonical auth_owned spec
     */
    private function requiresSeededAdminCredentials(string $entryModule, ?array $spec = null): bool
    {
        if ($spec === null && $entryModule !== '') {
            $spec = $this->resolveAuthOwnedSpec($entryModule);
        }

        if (is_array($spec) && !empty($spec['requires_named_admin_on_provision'])) {
            return true;
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
            'migration_details' => $this->migrationDetails,
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
