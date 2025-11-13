<?php
/**
 * Ikabud Kernel - Core Microkernel
 * 
 * GNU/Linux-inspired CMS Operating System
 * Boots first, supervises all CMS as userland processes
 * 
 * @version 1.0.0
 * @author Ikabud Development Team
 */

namespace IkabudKernel\Core;

use PDO;
use Exception;

class Kernel
{
    const VERSION = '1.0.0';
    const BOOT_PHASES = 5;
    
    private static ?self $instance = null;
    private static bool $booted = false;
    private static string $bootId;
    private static float $bootStartTime;
    
    private PDO $db;
    private array $config = [];
    private array $syscalls = [];
    private array $processes = [];
    private array $bootLog = [];
    
    // New managers
    private ?TransactionManager $transactionManager = null;
    private ?SecurityManager $securityManager = null;
    private ?SyscallHandlers $syscallHandlers = null;
    private ?HealthMonitor $healthMonitor = null;
    
    /**
     * Get singleton instance
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Get database connection
     */
    public function getDatabase(): PDO
    {
        return $this->db;
    }
    
    /**
     * Check if kernel is booted
     */
    public static function isBooted(): bool
    {
        return self::$booted;
    }
    
    /**
     * Boot a CMS instance
     */
    public function bootInstance(string $instanceId, array $config): bool
    {
        $bootstrapper = new InstanceBootstrapper($this);
        return $bootstrapper->bootInstance($instanceId, $config);
    }
    
    /**
     * Register a process in the kernel
     * 
     * @param string $name Process name
     * @param string $type Process type (cms, service, daemon)
     * @param array $metadata Process metadata
     * @return int Process ID (PID)
     */
    public static function registerProcess(string $name, string $type, array $metadata = []): int
    {
        $kernel = self::getInstance();
        
        // Generate PID
        $pid = count($kernel->processes) + 1000;
        
        // Store process
        $kernel->processes[$name] = [
            'pid' => $pid,
            'name' => $name,
            'type' => $type,
            'metadata' => $metadata,
            'status' => 'registered',
            'registered_at' => time()
        ];
        
        // Store in database if available
        if (isset($kernel->db)) {
            try {
                $stmt = $kernel->db->prepare("
                    INSERT INTO kernel_processes (pid, name, type, metadata, status, registered_at)
                    VALUES (?, ?, ?, ?, 'registered', NOW())
                    ON DUPLICATE KEY UPDATE
                        status = 'registered',
                        metadata = VALUES(metadata)
                ");
                $stmt->execute([
                    $pid,
                    $name,
                    $type,
                    json_encode($metadata)
                ]);
            } catch (Exception $e) {
                // Table might not exist yet, that's OK
                error_log("Kernel: Could not register process in database: " . $e->getMessage());
            }
        }
        
        return $pid;
    }
    
    /**
     * Boot the kernel with 5-phase sequence
     */
    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }
        
        self::$bootStartTime = microtime(true);
        self::$bootId = uniqid('boot_', true);
        
        $kernel = self::getInstance();
        
        try {
            // Phase 1: Kernel-Level Dependencies
            $kernel->bootPhase1_KernelDependencies();
            
            // Phase 2: Shared Core Loading
            $kernel->bootPhase2_SharedCores();
            
            // Phase 3: Instance Configuration
            $kernel->bootPhase3_InstanceConfig();
            
            // Phase 4: CMS Runtime Bootstrap
            $kernel->bootPhase4_CMSRuntime();
            
            // Phase 5: Theme & Plugin Loading
            $kernel->bootPhase5_Extensions();
            
            self::$booted = true;
            
            $kernel->logBoot('complete', 'Kernel boot completed successfully');
            
        } catch (Exception $e) {
            $kernel->logBoot('failed', 'Kernel boot failed: ' . $e->getMessage());
            throw new Exception("Kernel boot failed: " . $e->getMessage());
        }
    }
    
    /**
     * Phase 1: Initialize kernel-level dependencies
     */
    private function bootPhase1_KernelDependencies(): void
    {
        $phaseStart = microtime(true);
        
        try {
            // 1.1 Load environment configuration
            $this->loadEnvironment();
            
            // 1.2 Initialize database connection
            $this->initializeDatabase();
            
            // Now we can log to database
            $this->logPhase(1, 'Kernel-Level Dependencies', 'started');
            
            // 1.3 Load kernel configuration
            $this->loadKernelConfig();
            
            // 1.4 Register core syscalls
            $this->registerCoreSyscalls();
            
            // 1.5 Initialize error handling
            $this->initializeErrorHandling();
            
            // 1.6 Initialize security sandbox
            $this->initializeSecuritySandbox();
            
            // 1.7 Initialize DiSyL Manifest
            $this->initializeDisylManifest();
            
            $duration = (microtime(true) - $phaseStart) * 1000;
            $this->logPhase(1, 'Kernel-Level Dependencies', 'completed', $duration);
            
        } catch (Exception $e) {
            if (isset($this->db)) {
                $this->logPhase(1, 'Kernel-Level Dependencies', 'failed', 0, $e->getMessage());
            }
            throw $e;
        }
    }
    
    /**
     * Phase 2: Load shared CMS cores (isolated)
     */
    private function bootPhase2_SharedCores(): void
    {
        $phaseStart = microtime(true);
        $this->logPhase(2, 'Shared Core Loading', 'started');
        
        try {
            // 2.1 Detect available CMS cores
            $cores = $this->detectSharedCores();
            
            // 2.2 Prepare isolation environment
            $this->prepareIsolationEnvironment();
            
            // 2.3 Load cores without initialization
            foreach ($cores as $cmsType => $corePath) {
                $this->loadSharedCore($cmsType, $corePath);
            }
            
            $duration = (microtime(true) - $phaseStart) * 1000;
            $this->logPhase(2, 'Shared Core Loading', 'completed', $duration);
            
        } catch (Exception $e) {
            $this->logPhase(2, 'Shared Core Loading', 'failed', 0, $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Phase 3: Configure instances
     */
    private function bootPhase3_InstanceConfig(): void
    {
        $phaseStart = microtime(true);
        $this->logPhase(3, 'Instance Configuration', 'started');
        
        try {
            // 3.1 Load active instances from database
            $instances = $this->loadActiveInstances();
            
            // 3.2 Configure routing for each instance
            foreach ($instances as $instance) {
                $this->configureInstanceRouting($instance);
            }
            
            // 3.3 Set up isolated database connections
            $this->setupInstanceDatabases($instances);
            
            $duration = (microtime(true) - $phaseStart) * 1000;
            $this->logPhase(3, 'Instance Configuration', 'completed', $duration);
            
        } catch (Exception $e) {
            $this->logPhase(3, 'Instance Configuration', 'failed', 0, $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Phase 4: Bootstrap CMS runtime
     */
    private function bootPhase4_CMSRuntime(): void
    {
        $phaseStart = microtime(true);
        $this->logPhase(4, 'CMS Runtime Bootstrap', 'started');
        
        try {
            // 4.1 Determine which CMS to boot based on request
            $requestedCMS = $this->determineRequestedCMS();
            
            // 4.2 Boot CMS runtime (if needed)
            if ($requestedCMS) {
                $this->bootCMSRuntime($requestedCMS);
            }
            
            $duration = (microtime(true) - $phaseStart) * 1000;
            $this->logPhase(4, 'CMS Runtime Bootstrap', 'completed', $duration);
            
        } catch (Exception $e) {
            $this->logPhase(4, 'CMS Runtime Bootstrap', 'failed', 0, $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Phase 5: Load themes and plugins
     */
    private function bootPhase5_Extensions(): void
    {
        $phaseStart = microtime(true);
        $this->logPhase(5, 'Theme & Plugin Loading', 'started');
        
        try {
            // 5.1 Load active theme
            $this->loadActiveTheme();
            
            // 5.2 Load instance-specific plugins
            $this->loadInstancePlugins();
            
            // 5.3 Initialize DSL system
            $this->initializeDSL();
            
            $duration = (microtime(true) - $phaseStart) * 1000;
            $this->logPhase(5, 'Theme & Plugin Loading', 'completed', $duration);
            
        } catch (Exception $e) {
            $this->logPhase(5, 'Theme & Plugin Loading', 'failed', 0, $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Execute a syscall with security checks
     */
    public static function syscall(string $name, array $args = [], ?string $role = null): mixed
    {
        $kernel = self::getInstance();
        
        if (!isset($kernel->syscalls[$name])) {
            throw new Exception("Syscall not found: {$name}");
        }
        
        // Security checks
        if ($kernel->securityManager) {
            // Check permission
            if (!$kernel->securityManager->checkPermission($name, $role)) {
                throw new Exception("Permission denied for syscall: {$name}");
            }
            
            // Check rate limit
            $identifier = $role ?? ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
            if (!$kernel->securityManager->checkRateLimit($name, $identifier)) {
                throw new Exception("Rate limit exceeded for syscall: {$name}");
            }
            
            // Validate arguments
            $args = $kernel->securityManager->validateArgs($name, $args);
        }
        
        $startTime = microtime(true);
        $memoryBefore = memory_get_usage();
        
        try {
            $result = call_user_func($kernel->syscalls[$name], $args);
            
            $executionTime = (microtime(true) - $startTime) * 1000;
            $memoryDelta = memory_get_usage() - $memoryBefore;
            
            // Log syscall if enabled
            if ($kernel->config['syscall_logging'] ?? false) {
                $kernel->logSyscall($name, $args, $result, $executionTime, $memoryDelta, 'success');
            }
            
            return $result;
            
        } catch (Exception $e) {
            $executionTime = (microtime(true) - $startTime) * 1000;
            $kernel->logSyscall($name, $args, null, $executionTime, 0, 'error', $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Register a syscall handler
     */
    public static function registerSyscall(string $name, callable $handler): void
    {
        $kernel = self::getInstance();
        $kernel->syscalls[$name] = $handler;
    }
    
    /**
     * Check if syscall exists
     */
    public static function hasSyscall(string $name): bool
    {
        $kernel = self::getInstance();
        return isset($kernel->syscalls[$name]);
    }
    
    /**
     * Execute transaction with optional context metadata
     */
    public static function transaction(callable $callback, array $context = []): mixed
    {
        $kernel = self::getInstance();
        
        if (!$kernel->transactionManager) {
            throw new Exception("TransactionManager not initialized");
        }
        
        $txId = uniqid('tx_', true);
        $kernel->transactionManager->begin($txId, $context);
        
        try {
            $result = $callback($txId, $kernel->transactionManager);
            $kernel->transactionManager->commit($txId);
            return $result;
        } catch (Exception $e) {
            $kernel->transactionManager->rollback($txId);
            throw $e;
        }
    }
    
    /**
     * Get health status
     */
    public static function health(): array
    {
        $kernel = self::getInstance();
        
        if (!$kernel->healthMonitor) {
            return ['status' => 'unknown', 'error' => 'HealthMonitor not initialized'];
        }
        
        return $kernel->healthMonitor->check();
    }
    
    /**
     * Get quick health status
     */
    public static function healthQuick(): array
    {
        $kernel = self::getInstance();
        
        if (!$kernel->healthMonitor) {
            return ['status' => 'unknown'];
        }
        
        return $kernel->healthMonitor->getQuickStatus();
    }
    
    /**
     * Get kernel statistics
     */
    public static function getStats(): array
    {
        $kernel = self::getInstance();
        
        return [
            'version' => self::VERSION,
            'booted' => self::$booted,
            'boot_id' => self::$bootId ?? null,
            'uptime' => self::$booted ? microtime(true) - self::$bootStartTime : 0,
            'syscalls_registered' => count($kernel->syscalls),
            'processes_running' => count($kernel->processes),
            'memory_usage' => memory_get_usage(),
            'memory_peak' => memory_get_peak_usage(),
        ];
    }
    
    // ========================================================================
    // PRIVATE HELPER METHODS
    // ========================================================================
    
    private function loadEnvironment(): void
    {
        $envFile = __DIR__ . '/../.env';
        if (!file_exists($envFile)) {
            throw new Exception('.env file not found');
        }
        
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos(trim($line), '#') === 0) continue;
            
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            
            // Remove quotes
            $value = trim($value, '"\'');
            
            $_ENV[$key] = $value;
            putenv("{$key}={$value}");
        }
    }
    
    private function initializeDatabase(): void
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            $_ENV['DB_HOST'],
            $_ENV['DB_PORT'],
            $_ENV['DB_DATABASE'],
            $_ENV['DB_CHARSET'] ?? 'utf8mb4'
        );
        
        $this->db = new PDO($dsn, $_ENV['DB_USERNAME'], $_ENV['DB_PASSWORD'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        
        // Initialize managers that depend on database
        $this->transactionManager = new TransactionManager($this->db);
        $this->securityManager = new SecurityManager($this->db);
    }
    
    private function loadKernelConfig(): void
    {
        $stmt = $this->db->query("SELECT `key`, `value`, `type` FROM kernel_config");
        $configs = $stmt->fetchAll();
        
        foreach ($configs as $config) {
            $value = $config['value'];
            
            // Type casting
            switch ($config['type']) {
                case 'integer':
                    $value = (int) $value;
                    break;
                case 'boolean':
                    $value = filter_var($value, FILTER_VALIDATE_BOOLEAN);
                    break;
                case 'json':
                case 'array':
                    $value = json_decode($value, true);
                    break;
            }
            
            $this->config[$config['key']] = $value;
        }
    }
    
    private function registerCoreSyscalls(): void
    {
        // Initialize syscall handlers
        $cache = new Cache();
        $this->syscallHandlers = new SyscallHandlers($this->db, $cache);
        
        // Initialize health monitor
        $resourceManager = new ResourceManager();
        $this->healthMonitor = new HealthMonitor($this->db, $resourceManager, $cache, self::$bootStartTime);
        
        // Content syscalls
        self::registerSyscall('content.fetch', [$this->syscallHandlers, 'contentFetch']);
        self::registerSyscall('content.create', [$this->syscallHandlers, 'contentCreate']);
        self::registerSyscall('content.update', [$this->syscallHandlers, 'contentUpdate']);
        self::registerSyscall('content.delete', [$this->syscallHandlers, 'contentDelete']);
        
        // Database syscalls
        self::registerSyscall('db.query', [$this->syscallHandlers, 'dbQuery']);
        self::registerSyscall('db.insert', [$this->syscallHandlers, 'dbInsert']);
        
        // HTTP syscalls
        self::registerSyscall('http.get', [$this->syscallHandlers, 'httpGet']);
        self::registerSyscall('http.post', [$this->syscallHandlers, 'httpPost']);
        
        // Asset syscalls
        self::registerSyscall('asset.enqueue', [$this->syscallHandlers, 'assetEnqueue']);
        
        // Theme syscalls
        self::registerSyscall('theme.render', [$this->syscallHandlers, 'themeRender']);
        
        // Image optimization syscalls
        self::registerSyscall('image.optimize', [$this->syscallHandlers, 'imageOptimize']);
        self::registerSyscall('image.responsive', [$this->syscallHandlers, 'imageResponsive']);
        self::registerSyscall('image.picture', [$this->syscallHandlers, 'imagePictureTag']);
    }
    
    private function initializeErrorHandling(): void
    {
        error_reporting(E_ALL);
        ini_set('display_errors', $_ENV['APP_DEBUG'] === 'true' ? '1' : '0');
        ini_set('log_errors', '1');
        ini_set('error_log', __DIR__ . '/../storage/logs/kernel.log');
    }
    
    private function initializeSecuritySandbox(): void
    {
        // Set security headers
        header('X-Frame-Options: SAMEORIGIN');
        header('X-Content-Type-Options: nosniff');
        header('X-XSS-Protection: 1; mode=block');
        // More permissive CSP for CMS compatibility (allows CDNs, external resources, workers, mixed content)
        header("Content-Security-Policy: default-src 'self' https: http:; script-src 'self' 'unsafe-inline' 'unsafe-eval' https: http: blob:; style-src 'self' 'unsafe-inline' https: http:; img-src 'self' data: https: http:; font-src 'self' data: https: http:; connect-src 'self' https: http: wss: ws:; frame-src 'self' https: http:; worker-src 'self' blob:;");
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
        
        // Set PHP security settings
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_secure', '1');
        ini_set('session.cookie_samesite', 'Strict');
        
        // Disable only truly dangerous functions (keep CMS-needed ones like exec, curl_exec)
        // Note: Many CMS plugins need exec for image processing, curl_exec for HTTP requests
        if (function_exists('ini_set')) {
            @ini_set('disable_functions', 'passthru,shell_exec,system,proc_open,popen,show_source');
        }
    }
    
    private function detectSharedCores(): array
    {
        $coresPath = __DIR__ . '/../shared-cores';
        $cores = [];
        
        if (is_dir($coresPath)) {
            $dirs = scandir($coresPath);
            foreach ($dirs as $dir) {
                if ($dir === '.' || $dir === '..') continue;
                $cores[$dir] = $coresPath . '/' . $dir;
            }
        }
        
        return $cores;
    }
    
    private function prepareIsolationEnvironment(): void
    {
        // Start output buffering for isolation
        ob_start();
    }
    
    private function loadSharedCore(string $cmsType, string $corePath): void
    {
        // Core loading logic will be implemented per CMS type
        $this->bootLog[] = "Loaded shared core: {$cmsType}";
    }
    
    private function loadActiveInstances(): array
    {
        $stmt = $this->db->query("SELECT * FROM instances WHERE status = 'active'");
        return $stmt->fetchAll();
    }
    
    private function configureInstanceRouting(array $instance): void
    {
        // Routing configuration logic
    }
    
    private function setupInstanceDatabases(array $instances): void
    {
        // Database connection pooling logic
    }
    
    private function determineRequestedCMS(): ?string
    {
        // Route matching logic
        return null;
    }
    
    private function bootCMSRuntime(?string $cmsType): void
    {
        // CMS runtime boot logic
    }
    
    private function loadActiveTheme(): void
    {
        // Theme loading logic
    }
    
    private function loadInstancePlugins(): void
    {
        // Plugin loading logic
    }
    
    private function initializeDSL(): void
    {
        // DSL system initialization
    }
    
    private function logPhase(int $phase, string $name, string $status, float $duration = 0, ?string $error = null): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO kernel_boot_log 
            (boot_id, phase, phase_name, status, duration_ms, memory_before, memory_after, error_message)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            self::$bootId,
            $phase,
            $name,
            $status,
            $duration,
            memory_get_usage(),
            memory_get_usage(),
            $error
        ]);
    }
    
    private function logBoot(string $status, string $message): void
    {
        $totalTime = (microtime(true) - self::$bootStartTime) * 1000;
        error_log(sprintf(
            "IKABUD KERNEL [%s]: %s (%.2fms)",
            strtoupper($status),
            $message,
            $totalTime
        ));
    }
    
    /**
     * Initialize DiSyL Manifest v0.2
     * Loads manifest with caching, inheritance resolution, and validation
     */
    private function initializeDisylManifest(): void
    {
        static $initialized = false;
        
        if ($initialized) {
            return; // Already initialized in this process
        }
        
        try {
            // Load DiSyL manifest v0.2 (with caching and inheritance)
            if (class_exists('\\IkabudKernel\\Core\\DiSyL\\ManifestLoader')) {
                $manifest = \IkabudKernel\Core\DiSyL\ManifestLoader::load();
                
                // Only validate and log once per process
                if (!$initialized) {
                    // Validate manifest structure
                    $errors = \IkabudKernel\Core\DiSyL\ManifestLoader::validate();
                    if (!empty($errors)) {
                        error_log('[Ikabud] DiSyL Manifest v' . ($manifest['version'] ?? '0.0.0') . ' validation errors: ' . implode(', ', $errors));
                    } else {
                        // Log successful initialization with version and features
                        $version = \IkabudKernel\Core\DiSyL\ManifestLoader::getVersion();
                        $supportedCMS = \IkabudKernel\Core\DiSyL\ManifestLoader::getSupportedCMS();
                        $filterCount = count(\IkabudKernel\Core\DiSyL\ManifestLoader::getFilters());
                        
                        error_log(sprintf(
                            '[Ikabud] DiSyL Manifest v%s loaded: %d CMS adapters, %d filters, caching %s',
                            $version,
                            count($supportedCMS),
                            $filterCount,
                            ($manifest['cache']['enabled'] ?? false) ? 'enabled' : 'disabled'
                        ));
                    }
                }
                
                $initialized = true;
            }
        } catch (\Exception $e) {
            error_log('[Ikabud] DiSyL Manifest initialization failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Initialize CMS-specific integrations
     * Called after CMS is loaded
     */
    public static function initCMSIntegrations(string $cmsType): void
    {
        // Check if CMS is supported via manifest
        if (class_exists('\\IkabudKernel\\Core\\DiSyL\\ManifestLoader')) {
            if (!\IkabudKernel\Core\DiSyL\ManifestLoader::isCMSSupported($cmsType)) {
                error_log("[Ikabud] DiSyL does not support CMS type: {$cmsType}");
                return;
            }
            
            // Get CMS hooks from manifest
            $hooks = \IkabudKernel\Core\DiSyL\ManifestLoader::getCMSHooks($cmsType);
            
            // Execute init hook if defined
            if (isset($hooks['init']) && is_callable($hooks['init'])) {
                call_user_func($hooks['init']);
            }
        }
        
        // Fallback to hardcoded initialization
        if ($cmsType === 'wordpress') {
            if (class_exists('\\IkabudKernel\\Core\\DiSyL\\KernelIntegration')) {
                \IkabudKernel\Core\DiSyL\KernelIntegration::initWordPress();
            }
        }
    }
    
    private function logSyscall(string $name, array $args, $result, float $time, int $memory, string $status, ?string $error = null): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO kernel_syscalls 
            (pid, syscall_name, syscall_args, syscall_result, execution_time, memory_delta, status, error_message)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            null, // PID will be determined from context
            $name,
            json_encode($args),
            json_encode($result),
            $time,
            $memory,
            $status,
            $error
        ]);
    }
}
