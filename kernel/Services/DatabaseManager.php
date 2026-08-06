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
    /** @var array<string, int> */
    private array $runtimeCounters = [
        'primary_connects' => 0,
        'primary_validations' => 0,
        'primary_reconnects' => 0,
        'control_connects' => 0,
        'control_validations' => 0,
        'control_reconnects' => 0,
        'tenant_connects' => 0,
        'tenant_pool_hits' => 0,
        'tenant_pool_validations' => 0,
        'tenant_pool_evictions' => 0,
        'tenant_config_static_hits' => 0,
        'tenant_config_apcu_hits' => 0,
        'tenant_config_queries' => 0,
        'tenant_reconnects' => 0,
    ];

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

    private function incrementCounter(string $key): void
    {
        if (!array_key_exists($key, $this->runtimeCounters)) {
            $this->runtimeCounters[$key] = 0;
        }

        $this->runtimeCounters[$key]++;
    }

    private function connectionTimeoutSeconds(array $dbConfig): int
    {
        $configured = $dbConfig['timeout_seconds'] ?? ($dbConfig['options'][PDO::ATTR_TIMEOUT] ?? null);
        $timeout = is_numeric($configured) ? (int) $configured : 0;

        return max(0, $timeout);
    }

    private function connectionPersistent(array $dbConfig): bool
    {
        if (array_key_exists('persistent', $dbConfig)) {
            return (bool) $dbConfig['persistent'];
        }

        return (bool) ($dbConfig['options'][PDO::ATTR_PERSISTENT] ?? false);
    }

    /** @return array{enabled: bool, ca: string, cert: string, key: string, verify_server_cert: bool} */
    private function connectionSslConfig(array $dbConfig): array
    {
        $ssl = is_array($dbConfig['ssl'] ?? null) ? $dbConfig['ssl'] : [];

        return [
            'enabled' => (bool) ($ssl['enabled'] ?? false),
            'ca' => trim((string) ($ssl['ca'] ?? '')),
            'cert' => trim((string) ($ssl['cert'] ?? '')),
            'key' => trim((string) ($ssl['key'] ?? '')),
            'verify_server_cert' => (bool) ($ssl['verify_server_cert'] ?? true),
        ];
    }

    /** @return array<int, mixed> */
    private function normalizedPdoOptions(array $dbConfig): array
    {
        $options = is_array($dbConfig['options'] ?? null) ? $dbConfig['options'] : [];
        $charset = (string) ($dbConfig['charset'] ?? 'utf8mb4');
        $collation = (string) ($dbConfig['collation'] ?? 'utf8mb4_unicode_ci');

        $options[PDO::ATTR_ERRMODE] = PDO::ERRMODE_EXCEPTION;
        $options[PDO::ATTR_DEFAULT_FETCH_MODE] = PDO::FETCH_ASSOC;
        $options[PDO::ATTR_EMULATE_PREPARES] = false;
        $options[PDO::ATTR_STRINGIFY_FETCHES] = false;
        $options[PDO::ATTR_PERSISTENT] = $this->connectionPersistent($dbConfig);

        // Always use buffered queries to prevent "2014 Cannot execute queries" errors
        // when EventBus listeners or capability handlers leave result sets unconsumed.
        if (PdoMysql::available('ATTR_USE_BUFFERED_QUERY')) {
            $options[PdoMysql::attr('ATTR_USE_BUFFERED_QUERY')] = true;
        }

        $timeoutSeconds = $this->connectionTimeoutSeconds($dbConfig);
        if ($timeoutSeconds > 0) {
            $options[PDO::ATTR_TIMEOUT] = $timeoutSeconds;
        }

        if (($dbConfig['driver'] ?? 'mysql') === 'mysql' && PdoMysql::available('ATTR_INIT_COMMAND')) {
            $options[PdoMysql::attr('ATTR_INIT_COMMAND')] = "SET NAMES '" . $charset . "' COLLATE '" . $collation . "'";
        }

        $ssl = $this->connectionSslConfig($dbConfig);
        if (($dbConfig['driver'] ?? 'mysql') === 'mysql' && $ssl['enabled']) {
            if ($ssl['ca'] !== '' && PdoMysql::available('ATTR_SSL_CA')) {
                $options[PdoMysql::attr('ATTR_SSL_CA')] = $ssl['ca'];
            }
            if ($ssl['cert'] !== '' && PdoMysql::available('ATTR_SSL_CERT')) {
                $options[PdoMysql::attr('ATTR_SSL_CERT')] = $ssl['cert'];
            }
            if ($ssl['key'] !== '' && PdoMysql::available('ATTR_SSL_KEY')) {
                $options[PdoMysql::attr('ATTR_SSL_KEY')] = $ssl['key'];
            }
            if (PdoMysql::available('ATTR_SSL_VERIFY_SERVER_CERT')) {
                $options[PdoMysql::attr('ATTR_SSL_VERIFY_SERVER_CERT')] = $ssl['verify_server_cert'];
            }
        }

        return $options;
    }

    /** @return array<string, mixed> */
    private function connectionPolicySnapshot(array $dbConfig): array
    {
        $ssl = $this->connectionSslConfig($dbConfig);
        $apcuEnabled = function_exists('apcu_fetch') && function_exists('apcu_store') && (bool) ini_get('apc.enabled');

        return [
            'timeout_seconds' => $this->connectionTimeoutSeconds($dbConfig),
            'persistent' => $this->connectionPersistent($dbConfig),
            'emulate_prepares' => false,
            'stringify_fetches' => false,
            'encrypted_tenant_passwords_enforced' => filter_var($_ENV['ENFORCE_ENCRYPTED_DB_PASS'] ?? 'false', FILTER_VALIDATE_BOOLEAN),
            'tenant_config_cache_backend' => $apcuEnabled ? 'apcu+memory' : 'memory',
            'tenant_config_cache_ttl_seconds' => $this->tenantDbConnectionCacheTtl(),
            'ssl_enabled' => $ssl['enabled'],
            'ssl_verify_server_cert' => $ssl['enabled'] ? $ssl['verify_server_cert'] : false,
            'ssl_has_ca' => $ssl['ca'] !== '',
            'idle_validation_seconds' => $this->dbIdleValidationSeconds(),
        ];
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
            $this->incrementCounter('tenant_config_static_hits');
            return self::$tenantDbConnectionRowCache[$tenantId];
        }

        $apcuEnabled = function_exists('apcu_fetch') && function_exists('apcu_store') && (bool)ini_get('apc.enabled');
        $apcuKey = 'ikabud:tenant_db_conn:' . $tenantId;
        if ($apcuEnabled) {
            $cached = apcu_fetch($apcuKey, $success);
            if ($success) {
                $this->incrementCounter('tenant_config_apcu_hits');
                $row = is_array($cached) ? $cached : null;
                self::$tenantDbConnectionRowCache[$tenantId] = $row;
                return $row;
            }
        }

        $this->incrementCounter('tenant_config_queries');

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

        $this->incrementCounter('tenant_pool_hits');
        $pdo = $entry['pdo'];
        if (!$pdo->inTransaction() && $this->shouldValidateConnection((int)($entry['last_verified'] ?? 0))) {
            try {
                $pdo->query('SELECT 1');
                $this->tenantDbPool[$tenantId]['last_verified'] = time();
                $this->incrementCounter('tenant_pool_validations');
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
            $this->incrementCounter('tenant_pool_evictions');
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
                $this->incrementCounter('primary_validations');
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
            $pdoOptions = $this->normalizedPdoOptions($dbConfig);

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
                    $this->incrementCounter('primary_connects');
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
                $this->incrementCounter('control_validations');
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
                $this->normalizedPdoOptions($dbConfig)
            );
            $this->incrementCounter('control_connects');
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

            $dbConfig = array_merge($this->config['database'] ?? [], $dbConfig);

            $dsn = $this->buildDsn($dbConfig);
            $options = $this->normalizedPdoOptions($dbConfig);

            $pdoClass = '\\Ikabud\\Kernel\\Database\\KernelPDO';
            $pdo = new $pdoClass($dsn, $dbConfig['username'], $dbConfig['password'], $options);
            $this->incrementCounter('tenant_connects');
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
        $this->incrementCounter('primary_reconnects');
        $this->db = null;
        $this->dbTenantTarget = null;
        $this->dbLastVerified = null;
        return $this->db();
    }

    public function reconnectControlDb(): PDO
    {
        $this->incrementCounter('control_reconnects');
        $this->controlDb = null;
        $this->controlDbLastVerified = null;
        return $this->controlDb();
    }

    public function reconnectDbForTenant(int $tenantId): ?PDO
    {
        $this->incrementCounter('tenant_reconnects');
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

    public function runtimeSnapshot(): array
    {
        $requestTenantId = ($this->resolveRequestTenant)();
        $primaryPolicy = $this->connectionPolicySnapshot($this->config['database'] ?? []);
        $controlPolicy = $this->connectionPolicySnapshot($this->config['control_database'] ?? ($this->config['database'] ?? []));

        return [
            'request_tenant_id' => $requestTenantId,
            'primary_request_target' => $requestTenantId !== null && (int) $requestTenantId > 0 ? 'tenant' : 'primary',
            'primary_connection_target' => $this->dbTenantTarget !== null ? 'tenant' : 'primary',
            'tenant_pool' => $this->tenantDbPoolStats(),
            'tenant_config_cache' => [
                'backend' => $primaryPolicy['tenant_config_cache_backend'],
                'ttl_seconds' => $this->tenantDbConnectionCacheTtl(),
                'static_entries' => count(self::$tenantDbConnectionRowCache),
            ],
            'policy' => $primaryPolicy,
            'primary_policy' => $primaryPolicy,
            'control_policy' => $controlPolicy,
            'counters' => $this->runtimeCounters,
        ];
    }
}
