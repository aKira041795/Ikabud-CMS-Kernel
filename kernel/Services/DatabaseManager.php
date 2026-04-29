<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Services;

use PDO;
use Ikabud\Kernel\Crypto;

/**
 * Manages primary, control-plane, and per-tenant database connections for the kernel.
 *
 * Extracted from App to keep connection-pool logic separate from higher-level
 * application concerns. App holds a DatabaseManager instance and delegates all
 * DB methods through it; the external API (app()->db(), app()->dbForTenant(), …)
 * is unchanged.
 */
class DatabaseManager
{
    private const DB_IDLE_VALIDATION_SECONDS = 60;

    /** @var array<int, array<string, mixed>|null> */
    private static array $tenantDbConnectionRowCache = [];

    private ?PDO $db = null;
    private ?int $dbTenantTarget = null;
    private ?int $dbLastVerified = null;
    private ?PDO $controlDb = null;
    private ?int $controlDbLastVerified = null;
    /** @var array<int, array{pdo: PDO, last_used: float, last_verified: int}> */
    private array $tenantDbPool = [];

    /**
     * @param array<string,mixed>  $config               Full app config array.
     * @param \Closure             $logger               fn(string $msg, string $level, array $ctx): void
     * @param \Closure             $resolveRequestTenant fn(): ?int — tenant ID for the current HTTP request.
     * @param \Closure             $currentTenantId      fn(): ?int — tenant()->current() equivalent.
     */
    public function __construct(
        private readonly array $config,
        private readonly \Closure $logger,
        private readonly \Closure $resolveRequestTenant,
        private readonly \Closure $currentTenantId,
    ) {}

    // ── DSN helpers ──────────────────────────────────────────────────────────

    private function buildDsn(array $dbConfig): string
    {
        $dbName = (string)($dbConfig['database'] ?? '');

        // F17: Validate DB name to prevent DSN injection via manipulated tenant config.
        if ($dbName !== '' && !preg_match('/^[a-zA-Z0-9_]{1,64}$/', $dbName)) {
            throw new \InvalidArgumentException(
                'Tenant database name contains invalid characters. Only alphanumeric and underscore characters (up to 64) are allowed.'
            );
        }

        return sprintf(
            '%s:host=%s;port=%s;dbname=%s;charset=%s',
            $dbConfig['driver'] ?? 'mysql',
            $dbConfig['host'] ?? 'localhost',
            $dbConfig['port'] ?? '3306',
            $dbName,
            $dbConfig['charset'] ?? 'utf8mb4'
        );
    }

    // ── Pool lifecycle helpers ────────────────────────────────────────────────

    private function tenantDbPoolMax(): int
    {
        return max(1, (int)($this->config['app']['multi_tenant']['db_pool_max'] ?? 20));
    }

    private function tenantDbConnectionCacheTtl(): int
    {
        return max(1, (int)($_ENV['TENANT_DB_CONFIG_CACHE_TTL'] ?? 30));
    }

    private function dbIdleValidationSeconds(): int
    {
        return max(5, (int)($this->config['app']['database']['idle_validation_seconds'] ?? self::DB_IDLE_VALIDATION_SECONDS));
    }

    private function shouldValidateConnection(?int $lastVerified): bool
    {
        return $lastVerified === null || $lastVerified <= 0 || (time() - $lastVerified) >= $this->dbIdleValidationSeconds();
    }

    private function tenantDbFailureContext(int $tenantId, array $extra = []): array
    {
        $context = [
            'tenant_id' => $tenantId,
            'request_id' => function_exists('request_id') ? request_id() : null,
            'strategy' => (string)(($this->config['app']['multi_tenant']['strategy'] ?? '')),
        ];

        if (!empty($_SERVER['HTTP_HOST'])) {
            $context['host'] = (string)$_SERVER['HTTP_HOST'];
        }

        return array_merge($context, $extra);
    }

    private function tenantDbPasswordFromRow(array $row, int $tenantId): string
    {
        $password = (string)($row['db_pass'] ?? '');
        $cipher = (string)($row['db_pass_ciphertext'] ?? '');
        $iv = (string)($row['db_pass_iv'] ?? '');
        $tag = (string)($row['db_pass_tag'] ?? '');
        if ($cipher === '' || $iv === '' || $tag === '') {
            // F6: Log a critical warning when tenant DB credentials are stored in plaintext.
            if ($password !== '') {
                ($this->logger)(
                    'Tenant DB credentials stored in plaintext. Migrate to encrypted storage via the superadmin tenant settings.',
                    'critical',
                    ['tenant_id' => $tenantId]
                );
            }
            // When ENFORCE_ENCRYPTED_DB_PASS is enabled, refuse to connect using
            // plaintext credentials. This is a fail-closed security hardening measure.
            $enforceEncrypted = filter_var(
                $_ENV['ENFORCE_ENCRYPTED_DB_PASS'] ?? 'false',
                FILTER_VALIDATE_BOOLEAN
            );
            if ($enforceEncrypted && $password !== '') {
                throw new \RuntimeException(
                    'Tenant ' . $tenantId . ' has plaintext DB credentials but ENFORCE_ENCRYPTED_DB_PASS is enabled. '
                    . 'Encrypt credentials via the superadmin tenant settings before connecting.'
                );
            }
            return $password;
        }

        try {
            $crypto = new Crypto();
            return $crypto->decryptString($cipher, $iv, $tag);
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                'Tenant DB credential decryption failed. Verify CONTROL_DB_ENC_KEY matches the key used to save tenant '
                . $tenantId
                . ' credentials, or re-save the tenant DB password to re-encrypt it.',
                0,
                $e
            );
        }
    }

    /** @return array<string,mixed>|null */
    private function fetchTenantDbConnectionRow(int $tenantId): ?array
    {
        if (array_key_exists($tenantId, self::$tenantDbConnectionRowCache)) {
            return self::$tenantDbConnectionRowCache[$tenantId];
        }

        $apcuEnabled = function_exists('apcu_fetch') && function_exists('apcu_store') && (bool)ini_get('apc.enabled');
        $apcuKey = 'ikabud:tenant_db_conn:' . $tenantId;
        if ($apcuEnabled) {
            $cached = apcu_fetch($apcuKey, $success);
            if ($success) {
                $row = is_array($cached) ? $cached : null;
                self::$tenantDbConnectionRowCache[$tenantId] = $row;
                return $row;
            }
        }

        $stmt = $this->controlDb()->prepare(
            'SELECT db_driver, db_host, db_port, db_name, db_user, db_pass, db_charset, '
            . 'db_pass_ciphertext, db_pass_iv, db_pass_tag '
            . 'FROM kernel_tenant_db_connections WHERE tenant_id = :tid LIMIT 1'
        );
        $stmt->execute([':tid' => $tenantId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $resolved = is_array($row) ? $row : null;

        self::$tenantDbConnectionRowCache[$tenantId] = $resolved;
        if ($apcuEnabled) {
            apcu_store($apcuKey, $resolved, $this->tenantDbConnectionCacheTtl());
        }

        return $resolved;
    }

    private function touchTenantDbPoolEntry(int $tenantId): ?PDO
    {
        $entry = $this->tenantDbPool[$tenantId] ?? null;
        if (!is_array($entry) || !($entry['pdo'] ?? null) instanceof PDO) {
            return null;
        }

        $pdo = $entry['pdo'];
        if (!$pdo->inTransaction() && $this->shouldValidateConnection((int)($entry['last_verified'] ?? 0))) {
            try {
                $pdo->query('SELECT 1');
                $this->tenantDbPool[$tenantId]['last_verified'] = time();
            } catch (\Throwable $e) {
                unset($this->tenantDbPool[$tenantId]);
                ($this->logger)(
                    'Tenant DB pool validation failed: ' . $e->getMessage(),
                    'warning',
                    $this->tenantDbFailureContext($tenantId, ['exception' => get_class($e)])
                );
                return null;
            }
        }

        $this->tenantDbPool[$tenantId]['last_used'] = microtime(true);
        return $pdo;
    }

    private function trimTenantDbPool(?int $preserveTenantId = null): void
    {
        if (count($this->tenantDbPool) < $this->tenantDbPoolMax()) {
            return;
        }

        $oldestTenantId = null;
        $oldestLastUsed = null;
        foreach ($this->tenantDbPool as $tenantId => $entry) {
            if ($preserveTenantId !== null && $tenantId === $preserveTenantId) {
                continue;
            }

            $lastUsed = (float)($entry['last_used'] ?? 0.0);
            if ($oldestTenantId === null || $lastUsed < (float)$oldestLastUsed) {
                $oldestTenantId = (int)$tenantId;
                $oldestLastUsed = $lastUsed;
            }
        }

        if ($oldestTenantId !== null) {
            unset($this->tenantDbPool[$oldestTenantId]);
        }
    }

    // ── Tenant DB config resolution ───────────────────────────────────────────

    private function resolveTenantDatabaseConfig(): ?array
    {
        $tenantId = ($this->resolveRequestTenant)();
        if ($tenantId === null) {
            return null;
        }

        \Ikabud\Kernel\Database\KernelPDO::kernelEscalationEnter();
        try {
            $row = $this->fetchTenantDbConnectionRow((int)$tenantId);
            if (!is_array($row) || empty($row['db_host']) || empty($row['db_name']) || empty($row['db_user'])) {
                throw new \UnexpectedValueException('Tenant database configuration is missing or incomplete for tenant ' . $tenantId);
            }

            $password = $this->tenantDbPasswordFromRow($row, $tenantId);

            return [
                'driver' => (string)($row['db_driver'] ?? 'mysql'),
                'host' => (string)($row['db_host'] ?? 'localhost'),
                'port' => (string)($row['db_port'] ?? '3306'),
                'database' => (string)($row['db_name'] ?? ''),
                'username' => (string)($row['db_user'] ?? ''),
                'password' => $password,
                'charset' => (string)($row['db_charset'] ?? 'utf8mb4'),
                'options' => ($this->config['database']['options'] ?? null),
            ];
        } catch (\Throwable $e) {
            ($this->logger)(
                'Tenant DB resolution failed: ' . $e->getMessage(),
                'error',
                $this->tenantDbFailureContext($tenantId, ['exception' => get_class($e)])
            );
            throw new \RuntimeException('Tenant database configuration could not be resolved for tenant ' . $tenantId, 0, $e);
        } finally {
            \Ikabud\Kernel\Database\KernelPDO::kernelEscalationLeave();
        }
    }

    // ── Public DB connection API ──────────────────────────────────────────────

    public function db(): PDO
    {
        $tenantTarget = ($this->resolveRequestTenant)();

        if ($this->db instanceof PDO) {
            if (!$this->db->inTransaction() && $tenantTarget !== $this->dbTenantTarget) {
                $this->db = null;
                $this->dbLastVerified = null;
            }
        }

        if ($this->db instanceof PDO) {
            if ($this->db->inTransaction() || !$this->shouldValidateConnection($this->dbLastVerified)) {
                return $this->db;
            }

            try {
                $this->db->query('SELECT 1');
                $this->dbLastVerified = time();
                return $this->db;
            } catch (\Throwable $e) {
                ($this->logger)('Primary DB validation failed: ' . $e->getMessage(), 'warning', [
                    'exception' => get_class($e),
                ]);
                $this->db = null;
                $this->dbTenantTarget = null;
                $this->dbLastVerified = null;
            }
        }

        if ($this->db === null) {
            $dbConfig = $this->config['database'] ?? [];

            $tenantDbConfig = $tenantTarget !== null ? $this->resolveTenantDatabaseConfig() : null;
            if (is_array($tenantDbConfig)) {
                $dbConfig = array_merge($dbConfig, $tenantDbConfig);
            }

            $dsn = $this->buildDsn($dbConfig);
            $pdoClass = '\\Ikabud\\Kernel\\Database\\KernelPDO';
            $pdoOptions = $dbConfig['options'] ?? [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES 'utf8mb4' COLLATE 'utf8mb4_unicode_ci'",
            ];

            // On shared hosting (e.g. Bluehost) max_user_connections can be hit
            // briefly under traffic spikes.  Retry up to 3 times with a short
            // exponential back-off (50ms → 100ms → 200ms) before re-throwing.
            $maxAttempts = 3;
            $attempt = 0;
            $lastEx = null;
            while ($attempt < $maxAttempts) {
                try {
                    $this->db = new $pdoClass(
                        $dsn,
                        $dbConfig['username'] ?? '',
                        $dbConfig['password'] ?? '',
                        $pdoOptions
                    );
                    $this->dbTenantTarget = $tenantTarget;
                    $this->dbLastVerified = time();
                    break; // success
                } catch (\Throwable $e) {
                    $lastEx = $e;
                    $attempt++;
                    // Only retry on max_user_connections (SQLSTATE HY000 code 1203) or
                    // connection-related transient errors; rethrow all others immediately.
                    $code = (int)$e->getCode();
                    $msg  = $e->getMessage();
                    $isTransient = $code === 1203
                        || str_contains($msg, 'max_user_connections')
                        || str_contains($msg, 'Too many connections');
                    if (!$isTransient || $attempt >= $maxAttempts) {
                        throw $e;
                    }
                    ($this->logger)(
                        'DB connection attempt ' . $attempt . ' failed (transient): ' . $msg,
                        'warning',
                        ['attempt' => $attempt, 'sqlstate' => $code]
                    );
                    // Exponential back-off: 50ms, 100ms, 200ms …
                    usleep(50000 * (1 << ($attempt - 1)));
                }
            }
        }

        return $this->db;
    }

    public function controlDb(): PDO
    {
        if ($this->controlDb instanceof PDO) {
            if ($this->controlDb->inTransaction() || !$this->shouldValidateConnection($this->controlDbLastVerified)) {
                return $this->controlDb;
            }

            try {
                $this->controlDb->query('SELECT 1');
                $this->controlDbLastVerified = time();
                return $this->controlDb;
            } catch (\Throwable $e) {
                ($this->logger)('Control DB validation failed: ' . $e->getMessage(), 'warning', [
                    'exception' => get_class($e),
                ]);
                $this->controlDb = null;
                $this->controlDbLastVerified = null;
            }
        }

        if ($this->controlDb === null) {
            $dbConfig = $this->config['control_database'] ?? ($this->config['database'] ?? []);
            $dsn = $this->buildDsn($dbConfig);

            $pdoClass = '\\Ikabud\\Kernel\\Database\\KernelPDO';
            $this->controlDb = new $pdoClass(
                $dsn,
                $dbConfig['username'] ?? '',
                $dbConfig['password'] ?? '',
                $dbConfig['options'] ?? [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES 'utf8mb4' COLLATE 'utf8mb4_unicode_ci'",
                ]
            );
            $this->controlDbLastVerified = time();
        }

        return $this->controlDb;
    }

    public function dbForTenant(int $tenantId): ?PDO
    {
        $currentTid = ($this->currentTenantId)();
        if (PHP_SAPI !== 'cli' && $currentTid !== null && (int)$currentTid === $tenantId) {
            return $this->db();
        }

        $pooled = $this->touchTenantDbPoolEntry($tenantId);
        if ($pooled instanceof PDO) {
            return $pooled;
        }

        \Ikabud\Kernel\Database\KernelPDO::kernelEscalationEnter();
        try {
            $row = $this->fetchTenantDbConnectionRow((int)$tenantId);
            if (!is_array($row) || empty($row['db_host']) || empty($row['db_name']) || empty($row['db_user'])) {
                return null;
            }

            $password = $this->tenantDbPasswordFromRow($row, $tenantId);

            $dbConfig = [
                'driver' => (string)($row['db_driver'] ?? 'mysql'),
                'host' => (string)($row['db_host'] ?? 'localhost'),
                'port' => (string)($row['db_port'] ?? '3306'),
                'database' => (string)($row['db_name'] ?? ''),
                'username' => (string)($row['db_user'] ?? ''),
                'password' => $password,
                'charset' => (string)($row['db_charset'] ?? 'utf8mb4'),
            ];

            $dsn = $this->buildDsn($dbConfig);
            $options = $this->config['database']['options'] ?? [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => false,
                \PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES 'utf8mb4' COLLATE 'utf8mb4_unicode_ci'",
            ];

            $pdoClass = '\\Ikabud\\Kernel\\Database\\KernelPDO';
            $pdo = new $pdoClass($dsn, $dbConfig['username'], $dbConfig['password'], $options);
            $this->trimTenantDbPool($tenantId);
            $this->tenantDbPool[$tenantId] = [
                'pdo' => $pdo,
                'last_used' => microtime(true),
                'last_verified' => time(),
            ];
            return $pdo;
        } catch (\Throwable $e) {
            ($this->logger)(
                'Tenant DB connection initialization failed: ' . $e->getMessage(),
                'error',
                $this->tenantDbFailureContext($tenantId, ['exception' => get_class($e)])
            );
            return null;
        } finally {
            \Ikabud\Kernel\Database\KernelPDO::kernelEscalationLeave();
        }
    }

    public function reconnectDb(): PDO
    {
        $this->db = null;
        $this->dbTenantTarget = null;
        $this->dbLastVerified = null;
        return $this->db();
    }

    public function reconnectControlDb(): PDO
    {
        $this->controlDb = null;
        $this->controlDbLastVerified = null;
        return $this->controlDb();
    }

    public function reconnectDbForTenant(int $tenantId): ?PDO
    {
        $currentTid = ($this->currentTenantId)();
        if (PHP_SAPI !== 'cli' && $currentTid !== null && (int)$currentTid === $tenantId) {
            $this->db = null;
            $this->dbTenantTarget = null;
        }
        unset($this->tenantDbPool[$tenantId]);
        unset(self::$tenantDbConnectionRowCache[$tenantId]);

        $apcuEnabled = function_exists('apcu_delete') && (bool)ini_get('apc.enabled');
        if ($apcuEnabled) {
            apcu_delete('ikabud:tenant_db_conn:' . $tenantId);
        }

        return $this->dbForTenant($tenantId);
    }

    public function tenantDbPoolStats(): array
    {
        return [
            'active' => count($this->tenantDbPool),
            'max' => $this->tenantDbPoolMax(),
            'tenant_ids' => array_values(array_map('intval', array_keys($this->tenantDbPool))),
        ];
    }
}
