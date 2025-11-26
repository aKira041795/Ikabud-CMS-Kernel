<?php
/**
 * Ikabud Kernel - Core Microkernel
 * 
 * GNU/Linux-inspired CMS Operating System
 * Boots first, supervises all CMS as userland processes
 * 
 * Performance optimizations (v1.1.0):
 * - Lazy manager initialization
 * - On-demand resource loading
 * - Reduced boot time overhead
 * 
 * @version 1.1.0
 * @author Ikabud Development Team
 */

namespace IkabudKernel\Core;

use PDO;
use Exception;

class Kernel
{
    const VERSION = '1.1.0';
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
    
    // Lazy-loaded managers (initialized on first access)
    private ?TransactionManager $transactionManager = null;
    private ?SecurityManager $securityManager = null;
    private ?SyscallHandlers $syscallHandlers = null;
    private ?HealthMonitor $healthMonitor = null;
    private ?ResourceManager $resourceManager = null;
    private ?Cache $cache = null;
    
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
     * Get Cache instance (lazy-loaded)
     */
    public function getCache(): Cache
    {
        if ($this->cache === null) {
            $this->cache = new Cache();
        }
        return $this->cache;
    }
    
    /**
     * Get ResourceManager instance (lazy-loaded)
     */
    public function getResourceManager(): ResourceManager
    {
        if ($this->resourceManager === null) {
            $this->resourceManager = new ResourceManager();
        }
        return $this->resourceManager;
    }
    
    /**
     * Get TransactionManager instance (lazy-loaded)
     */
    public function getTransactionManager(): TransactionManager
    {
        if ($this->transactionManager === null) {
            $this->transactionManager = new TransactionManager($this->db);
        }
        return $this->transactionManager;
    }
    
    /**
     * Get SecurityManager instance (lazy-loaded)
     */
    public function getSecurityManager(): SecurityManager
    {
        if ($this->securityManager === null) {
            $this->securityManager = new SecurityManager($this->db);
        }
        return $this->securityManager;
    }
    
    /**
     * Get HealthMonitor instance (lazy-loaded)
     */
    public function getHealthMonitor(): HealthMonitor
    {
        if ($this->healthMonitor === null) {
            $this->healthMonitor = new HealthMonitor(
                $this->db,
                $this->getResourceManager(),
                $this->getCache(),
                self::$bootStartTime
            );
        }
        return $this->healthMonitor;
    }
    
    /**
     * Get SyscallHandlers instance (lazy-loaded)
     */
    public function getSyscallHandlers(): SyscallHandlers
    {
        if ($this->syscallHandlers === null) {
            $this->syscallHandlers = new SyscallHandlers($this->db, $this->getCache());
        }
        return $this->syscallHandlers;
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
        
        // Security checks (lazy-load security manager only when needed)
        $securityManager = $kernel->getSecurityManager();
        
        // Check permission
        if (!$securityManager->checkPermission($name, $role)) {
            throw new Exception("Permission denied for syscall: {$name}");
        }
        
        // Check rate limit
        $identifier = $role ?? ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        if (!$securityManager->checkRateLimit($name, $identifier)) {
            throw new Exception("Rate limit exceeded for syscall: {$name}");
        }
        
        // Validate arguments
        $args = $securityManager->validateArgs($name, $args);
        
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
     * Execute transaction with optional context metadata (lazy-loads TransactionManager)
     */
    public static function transaction(callable $callback, array $context = []): mixed
    {
        $kernel = self::getInstance();
        $transactionManager = $kernel->getTransactionManager();
        
        $txId = uniqid('tx_', true);
        $transactionManager->begin($txId, $context);
        
        try {
            $result = $callback($txId, $transactionManager);
            $transactionManager->commit($txId);
            return $result;
        } catch (Exception $e) {
            $transactionManager->rollback($txId);
            throw $e;
        }
    }
    
    /**
     * Get health status (lazy-loads HealthMonitor)
     */
    public static function health(): array
    {
        $kernel = self::getInstance();
        return $kernel->getHealthMonitor()->check();
    }
    
    /**
     * Get quick health status (lazy-loads HealthMonitor)
     */
    public static function healthQuick(): array
    {
        $kernel = self::getInstance();
        return $kernel->getHealthMonitor()->getQuickStatus();
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
        
        // Managers are now lazy-loaded via getters
        // No eager initialization needed here
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
        // Syscall handlers are lazy-loaded - register closures that resolve on first call
        // This defers initialization until syscalls are actually used
        
        // Content syscalls
        self::registerSyscall('content.fetch', fn($args) => $this->getSyscallHandlers()->contentFetch($args));
        self::registerSyscall('content.create', fn($args) => $this->getSyscallHandlers()->contentCreate($args));
        self::registerSyscall('content.update', fn($args) => $this->getSyscallHandlers()->contentUpdate($args));
        self::registerSyscall('content.delete', fn($args) => $this->getSyscallHandlers()->contentDelete($args));
        
        // Database syscalls
        self::registerSyscall('db.query', fn($args) => $this->getSyscallHandlers()->dbQuery($args));
        self::registerSyscall('db.insert', fn($args) => $this->getSyscallHandlers()->dbInsert($args));
        
        // HTTP syscalls
        self::registerSyscall('http.get', fn($args) => $this->getSyscallHandlers()->httpGet($args));
        self::registerSyscall('http.post', fn($args) => $this->getSyscallHandlers()->httpPost($args));
        
        // Asset syscalls
        self::registerSyscall('asset.enqueue', fn($args) => $this->getSyscallHandlers()->assetEnqueue($args));
        
        // Theme syscalls
        self::registerSyscall('theme.render', fn($args) => $this->getSyscallHandlers()->themeRender($args));
        
        // Image optimization syscalls
        self::registerSyscall('image.optimize', fn($args) => $this->getSyscallHandlers()->imageOptimize($args));
        self::registerSyscall('image.responsive', fn($args) => $this->getSyscallHandlers()->imageResponsive($args));
        self::registerSyscall('image.picture', fn($args) => $this->getSyscallHandlers()->imagePictureTag($args));
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
        // Detect WordPress admin and get current host for cross-subdomain support
        $request_uri = $_SERVER['REQUEST_URI'] ?? '';
        $current_host = $_SERVER['HTTP_HOST'] ?? '';
        $is_wp_admin = strpos($request_uri, '/wp-admin/') !== false || 
                       strpos($request_uri, '/wp-login.php') !== false;
        
        // Get base domain for frame-ancestors (e.g., "brutus.test" from "backend.brutus.test")
        $host_parts = explode('.', $current_host);
        $base_domain = implode('.', array_slice($host_parts, -2)); // Last 2 parts
        
        // Set security headers
        if (!$is_wp_admin) {
            header('X-Frame-Options: SAMEORIGIN');
        }
        header('X-Content-Type-Options: nosniff');
        header('X-XSS-Protection: 1; mode=block');
        
        // More permissive CSP for CMS compatibility (allows CDNs, external resources, workers, mixed content)
        // Skip CSP entirely for WordPress admin to prevent customizer iframe issues
        if (!$is_wp_admin) {
            // Allow framing from same domain and subdomains (for WordPress customizer cross-subdomain support)
            $csp = "default-src 'self' https: http:; " .
                   "script-src 'self' 'unsafe-inline' 'unsafe-eval' https: http: blob:; " .
                   "style-src 'self' 'unsafe-inline' https: http:; " .
                   "img-src 'self' data: https: http:; " .
                   "font-src 'self' data: https: http:; " .
                   "connect-src 'self' https: http: wss: ws:; " .
                   "frame-src 'self' https: http:; " .
                   "frame-ancestors 'self' http://*." . $base_domain . " https://*." . $base_domain . "; " .
                   "worker-src 'self' blob:;";
            header("Content-Security-Policy: " . $csp);
        }
        
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
     * Initialize DiSyL Grammar v1.2.0 and Manifest system
     * Loads modular manifests with profiles, mount points, and namespaces
     */
    private function initializeDisylManifest(): void
    {
        static $initialized = false;
        
        if ($initialized) {
            return; // Already initialized in this process
        }
        
        try {
            // Initialize ComponentRegistry with core components
            if (class_exists('\\IkabudKernel\\Core\\DiSyL\\ComponentRegistry')) {
                \IkabudKernel\Core\DiSyL\ComponentRegistry::registerCoreComponents();
            }
            
            // Try ModularManifestLoader first (v0.4+)
            if (class_exists('\\IkabudKernel\\Core\\DiSyL\\ModularManifestLoader')) {
                // Detect CMS type
                $cmsType = 'WordPress'; // Default, can be detected dynamically
                
                // Initialize with profile (default: full)
                \IkabudKernel\Core\DiSyL\ModularManifestLoader::init('full', $cmsType);
                
                $version = \IkabudKernel\Core\DiSyL\ModularManifestLoader::getVersion();
                $profile = \IkabudKernel\Core\DiSyL\ModularManifestLoader::getCurrentProfile();
                $filterCount = count(\IkabudKernel\Core\DiSyL\ModularManifestLoader::getFilters());
                $manifestCount = count(\IkabudKernel\Core\DiSyL\ModularManifestLoader::getLoadedManifests());
                
                // Also log Grammar version
                $grammarVersion = class_exists('\\IkabudKernel\\Core\\DiSyL\\Grammar') 
                    ? \IkabudKernel\Core\DiSyL\Grammar::SCHEMA_VERSION 
                    : 'unknown';
                
                error_log(sprintf(
                    '[Ikabud] DiSyL v%s loaded: Grammar v%s, profile "%s", %d manifests, %d filters, CMS: %s',
                    $version,
                    $grammarVersion,
                    $profile,
                    $manifestCount,
                    $filterCount,
                    $cmsType
                ));
                
                $initialized = true;
            }
            // Fallback to legacy ManifestLoader (v0.2)
            elseif (class_exists('\\IkabudKernel\\Core\\DiSyL\\ManifestLoader')) {
                $manifest = \IkabudKernel\Core\DiSyL\ManifestLoader::load();
                
                $errors = \IkabudKernel\Core\DiSyL\ManifestLoader::validate();
                if (!empty($errors)) {
                    error_log('[Ikabud] DiSyL Manifest v' . ($manifest['version'] ?? '0.0.0') . ' validation errors: ' . implode(', ', $errors));
                } else {
                    $version = \IkabudKernel\Core\DiSyL\ManifestLoader::getVersion();
                    $supportedCMS = \IkabudKernel\Core\DiSyL\ManifestLoader::getSupportedCMS();
                    $filterCount = count(\IkabudKernel\Core\DiSyL\ManifestLoader::getFilters());
                    
                    error_log(sprintf(
                        '[Ikabud] DiSyL Manifest v%s loaded (legacy): %d CMS adapters, %d filters',
                        $version,
                        count($supportedCMS),
                        $filterCount
                    ));
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
