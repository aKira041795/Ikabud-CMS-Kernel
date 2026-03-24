<?php
/**
 * Ikabud Application Kernel
 * 
 * Central application class that wires together all kernel components.
 * Provides a clean interface for the Ikabud Kernel System.
 * 
 * The kernel is fully self-contained — it never calls module functions directly.
 * All extension points use the Hooks system (filter/action pattern).
 * Modules register hook listeners during their bootstrap phase.
 * 
 * @package Ikabud\Kernel
 * @version 3.0.0
 */

namespace Ikabud\Kernel;

use Ikabud\Kernel\Capabilities\CapabilityBus;
use Ikabud\Kernel\Capabilities\CapabilityRegistry;
use Ikabud\Kernel\Database\MigrationRunner;
use Ikabud\Kernel\TenantResolver;
use Ikabud\Kernel\Database\ModuleDB;
use Ikabud\Kernel\DiSyL\TemplateEngine;
use Ikabud\Kernel\DiSyL\Reactive\HTMXRequest;
use PDO;

class App
{
    private static ?App $instance = null;
    
    private array $config = [];
    private ?PDO $db = null;
    private ?PDO $controlDb = null;
    /** @var array<int, PDO> */
    private array $tenantDbPool = [];
    private ?TemplateEngine $templateEngine = null;
    private ?JWT $jwt = null;
    private ?Cache $cache = null;
    private ?Hooks $hooks = null;
    private ?EventBus $events = null;
    private ?WorkflowRuntime $workflowRuntime = null;
    private ?TenantResolver $tenantResolver = null;
    private ?CapabilityRegistry $capabilityRegistry = null;
    private ?CapabilityBus $capabilityBus = null;
    private ?array $currentUser = null;
    private bool $booted = false;
    private ?array $cachedNavItems = null;
    private ?array $cachedGuiContext = null;
    
    public const KERNEL_VERSION = '3.1.0';
    public const KERNEL_CODENAME = 'clarity';

    /** @var int Maximum JSON input size in bytes (2 MB) */
    private const MAX_INPUT_SIZE = 2 * 1024 * 1024;
    
    private function __construct() {}
    
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
     * Boot the application
     */
    public function boot(array $config = []): self
    {
        if ($this->booted) {
            return $this;
        }
        
        $this->config = $config;
        $this->hooks = Hooks::getInstance();
        $this->booted = true;

        // Register kernel core capability providers before modules boot.
        // Modules may depend on these contracts.
        $caps = $this->capabilities();

        $kernelCapabilityMeta = static function (string $capabilityId, array $extra = []): array {
            return array_merge($extra, [
                'origin' => [
                    'type' => 'kernel_boot',
                    'provider' => 'kernel',
                    'file' => 'kernel/App.php',
                    'capability' => $capabilityId,
                ],
            ]);
        };

        $caps->register('kernel.auth.user@1', 'kernel', function ($payload): ?array {
            return $this->user();
        }, 1000, ['first', 'pipeline'], $kernelCapabilityMeta('kernel.auth.user@1'));

        $caps->register('kernel.auth.require@1', 'kernel', function ($payload): array {
            $opts = is_array($payload) ? $payload : [];
            $roles = $opts['roles'] ?? null;
            if (is_array($roles) && !empty($roles)) {
                return $this->requireAnyRole(...array_values(array_map('strval', $roles)));
            }
            return $this->requireAuth();
        }, 1000, ['first'], $kernelCapabilityMeta('kernel.auth.require@1'));

        $caps->register('kernel.http.request_context@1', 'kernel', function ($payload): array {
            $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
            return [
                'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
                'path' => $path,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                'is_htmx' => $this->isHtmx(),
                'is_htmx_boosted' => $this->isHtmxBoosted(),
            ];
        }, 1000, ['first'], $kernelCapabilityMeta('kernel.http.request_context@1'));

        // kernel.audit.record@1 (first):
        // Payload: {module?: string, action: string, branch_id?: int, entity_type?: string, entity_id?: string, old_data?: mixed, new_data?: mixed, reason?: string}
        // Return: {ok: bool}
        $caps->register('kernel.audit.record@1', 'kernel', function ($payload): array {
            if (!is_array($payload)) {
                return ['ok' => false];
            }

            $module = (string)($payload['module'] ?? '_kernel');
            $action = (string)($payload['action'] ?? '');
            if ($action === '') {
                return ['ok' => false];
            }

            $branchId = isset($payload['branch_id']) ? (int)$payload['branch_id'] : null;
            $entityType = isset($payload['entity_type']) ? (string)$payload['entity_type'] : null;
            $entityId = isset($payload['entity_id']) ? (string)$payload['entity_id'] : null;
            $oldData = $payload['old_data'] ?? null;
            $newData = $payload['new_data'] ?? null;
            $reason = isset($payload['reason']) ? (string)$payload['reason'] : null;

            $user = $this->user();
            $source = (string)($user['source'] ?? '');
            // audit_logs.actor_user_id currently references users.id.
            // CMS users live in cms_users, so keep actor null for non-kernel sources.
            $actorId = ($user && $source !== 'cms') ? (int)($user['id'] ?? $user['sub'] ?? 0) : null;
            if ($actorId !== null && $actorId <= 0) {
                $actorId = null;
            }

            try {
                $stmt = $this->db()->prepare(
                    'INSERT INTO audit_logs (module, actor_user_id, branch_id, action, entity_type, entity_id, old_data, new_data) '
                    . 'VALUES (:module, :actor, :branch, :action, :etype, :eid, :old, :new)'
                );
                $stmt->execute([
                    ':module' => $module,
                    ':actor' => $actorId,
                    ':branch' => $branchId,
                    ':action' => $action,
                    ':etype' => $entityType,
                    ':eid' => $entityId,
                    ':old' => $oldData !== null ? json_encode($oldData) : null,
                    ':new' => $newData !== null ? json_encode($newData) : null,
                ]);
            } catch (\Throwable $e) {
                // Best-effort: do not fail the request.
                $this->log('Audit log write failed: ' . $e->getMessage(), 'error', ['module' => $module, 'action' => $action, 'reason' => $reason]);
                return ['ok' => false];
            }

            return ['ok' => true];
        }, 1000, ['first'], $kernelCapabilityMeta('kernel.audit.record@1', ['schema' => [
            'input' => [
                'type' => 'object',
                'required' => ['action'],
                'properties' => [
                    'module' => ['type' => 'string'],
                    'action' => ['type' => 'string'],
                    'branch_id' => ['type' => 'integer'],
                    'entity_type' => ['type' => 'string'],
                    'entity_id' => ['type' => 'string'],
                ],
            ],
            'output' => [
                'type' => 'object',
                'required' => ['ok'],
                'properties' => [
                    'ok' => ['type' => 'boolean'],
                ],
            ],
        ]]));

        // kernel.render.context@1 (first):
        // Payload: {template?: string}
        // Return: base render context (same shape as App::render builds before caller overrides)
        $caps->register('kernel.render.context@1', 'kernel', function ($payload): array {
            $template = '';
            if (is_array($payload) && isset($payload['template'])) {
                $template = (string)$payload['template'];
            }

            $appUrl = $this->config('app.url', '');
            $baseUrl = rtrim(parse_url($appUrl, PHP_URL_PATH) ?: '', '/');

            $user = $this->user();
            if ($user) {
                if (empty($user['first_name']) && !empty($user['name'])) {
                    $parts = explode(' ', $user['name'], 2);
                    $user['first_name'] = $parts[0];
                    $user['last_name'] = $parts[1] ?? '';
                }
            }

            if ($this->cachedNavItems === null) {
                $this->cachedNavItems = $this->hooks()->filter('kernel.nav_items', [], $user);
            }

            if ($this->cachedGuiContext === null) {
                $appName = $this->config('app.name', 'Baron Bakeshop');
                $parts = explode(' ', $appName, 2);

                $kernelDefaults = [
                    'app_name' => $appName, 'app_name_accent' => $parts[0], 'app_name_rest' => $parts[1] ?? '',
                    'color_bg' => '#f4f5f7', 'color_surface' => '#ffffff', 'color_border' => '#dfe3e8',
                    'color_text' => '#2d3748', 'color_text_muted' => '#5a6577', 'color_text_light' => '#8895a7',
                    'color_primary' => '#2563eb', 'color_primary_hover' => '#1d4ed8', 'color_primary_light' => '#dbeafe',
                    'color_success' => '#0d9f4f', 'color_success_light' => '#d4f5e0',
                    'color_warning' => '#c87e08', 'color_warning_light' => '#fef3c7',
                    'color_danger' => '#d42828', 'color_danger_light' => '#fee2e2',
                    'color_header_bg' => '#1e293b', 'color_header_text' => '#ffffff', 'color_header_accent' => '#60a5fa',
                    'font_family' => "'Inter', system-ui, sans-serif",
                    'font_url' => 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap',
                    'font_size_base' => '14px', 'font_size_small' => '12px',
                    'font_size_h1' => '24px', 'font_size_h2' => '18px', 'font_size_nav' => '13px',
                    'border_radius' => '8px', 'header_height' => '56px', 'nav_height' => '44px', 'max_width' => '1200px',
                    'css_overrides' => '',
                ];

                $guiFile = ($this->config('paths.storage', '') ?: (defined('STORAGE_PATH') ? STORAGE_PATH : '')) . '/gui-settings.json';
                if ($guiFile !== '/gui-settings.json' && is_file($guiFile)) {
                    $saved = json_decode((string) file_get_contents($guiFile), true);
                    if (is_array($saved)) {
                        $kernelDefaults = array_merge($kernelDefaults, $saved);
                    }
                }

                $this->cachedGuiContext = $this->hooks()->filter('kernel.gui_context', $kernelDefaults);
            }

            $baseContext = [
                'user' => $user,
                'is_htmx' => $this->isHtmx() && !$this->isHtmxBoosted(),
                'base_url' => $baseUrl,
                'app_url' => $appUrl,
                'cookie_name' => $this->config('app.cookie_name', 'guidance_token'),
                'csrf_token' => $this->csrfToken(),
                'csrf_field' => $this->csrfField(),
                'nav_items' => $this->cachedNavItems,
                'gui' => $this->cachedGuiContext,
            ];

            if ($template !== '') {
                $baseContext = $this->hooks()->filter('kernel.render_context', $baseContext, $template);
            }

            return $baseContext;
        }, 1000, ['first'], $kernelCapabilityMeta('kernel.render.context@1'));

        // kernel.auth.authenticate@1 (pipeline):
        // Each provider receives payload: ['username'=>..., 'password'=>...]
        // Return: ['user'=>array, 'source'=>string] or null to continue the chain.
        $caps->register('kernel.auth.authenticate@1', 'kernel', function ($payload): ?array {
            if (!is_array($payload)) {
                return null;
            }
            $username = trim((string)($payload['username'] ?? ''));
            $password = (string)($payload['password'] ?? '');
            if ($username === '' || $password === '') {
                return null;
            }

            try {
                $stmt = $this->db()->prepare(
                    "SELECT id, username, password_hash, full_name, role\n                     FROM users\n                     WHERE username = :username AND is_active = 1\n                     LIMIT 1"
                );
                $stmt->execute([':username' => $username]);
                $row = $stmt->fetch(\PDO::FETCH_ASSOC);
                if (is_array($row) && in_array(($row['role'] ?? null), ['admin', 'superadmin'], true) && password_verify($password, (string)$row['password_hash'])) {
                    return ['user' => $row, 'source' => 'kernel'];
                }
            } catch (\Throwable $e) {
                // Non-fatal: auth provider returns null and lets pipeline continue.
                return null;
            }

            return null;
        }, 1000, ['pipeline'], $kernelCapabilityMeta('kernel.auth.authenticate@1'));

        $workflow = $this->workflow();
        $caps->register('workflow.state.get@1', 'kernel', function ($payload) use ($workflow): array {
            return $workflow->stateGet($payload);
        }, 1000, ['first'], $kernelCapabilityMeta('workflow.state.get@1', ['policy' => $workflow->capabilityPolicy(), 'schema' => $workflow->stateSchema()]));

        $caps->register('workflow.transition@1', 'kernel', function ($payload) use ($workflow): array {
            return $workflow->transition($payload);
        }, 1000, ['first'], $kernelCapabilityMeta('workflow.transition@1', ['policy' => $workflow->capabilityPolicy(), 'schema' => $workflow->transitionSchema()]));

        if (function_exists('kernelRegisterModuleEvents')) {
            kernelRegisterModuleEvents('kernel', $workflow->declaredEvents());
        }

        $workflow->ensureCmsContentWorkflow();

        // Fire kernel.boot action so modules/extensions can register hooks
        $this->hooks->action('kernel.boot', $this);
        
        return $this;
    }
    
    /**
     * Get the hook system
     */
    public function hooks(): Hooks
    {
        if ($this->hooks === null) {
            $this->hooks = Hooks::getInstance();
        }
        return $this->hooks;
    }
    
    /**
     * Get the event bus (inter-module communication)
     */
    public function events(): EventBus
    {
        if ($this->events === null) {
            $this->events = EventBus::getInstance();
        }
        return $this->events;
    }

    public function workflow(): WorkflowRuntime
    {
        if ($this->workflowRuntime === null) {
            $this->workflowRuntime = new WorkflowRuntime($this);
        }
        return $this->workflowRuntime;
    }

    /**
     * Get the capability registry (multi-provider contract registry).
     */
    public function capabilities(): CapabilityRegistry
    {
        if ($this->capabilityRegistry === null) {
            $this->capabilityRegistry = new CapabilityRegistry();
        }
        return $this->capabilityRegistry;
    }

    /**
     * Get the capability bus (synchronous contract invocation).
     */
    public function cap(): CapabilityBus
    {
        if ($this->capabilityBus === null) {
            $this->capabilityBus = new CapabilityBus($this->capabilities());
        }
        return $this->capabilityBus;
    }

    /**
     * Kernel platform identity — single source of truth for version, codename, and runtime posture.
     * Used by health API, platform API, CLI, and admin dashboard.
     */
    public function platformIdentity(): array
    {
        $capCount = count($this->capabilities()->capabilityIds());
        $providerCount = 0;
        foreach ($this->capabilities()->capabilityIds() as $cid) {
            $providerCount += count($this->capabilities()->providers($cid));
        }

        $schemaMode = 'warn';
        try {
            $schemaMode = $this->config('app.capabilities.schema_validation_mode', 'warn');
        } catch (\Throwable $e) {
        }

        $multiTenant = false;
        try {
            $multiTenant = (bool)$this->config('app.multi_tenant.enabled', false);
        } catch (\Throwable $e) {
        }

        return [
            'kernel' => [
                'version' => self::KERNEL_VERSION,
                'codename' => self::KERNEL_CODENAME,
                'php_version' => PHP_VERSION,
            ],
            'app' => [
                'name' => $this->config('app.name', 'Ikabud'),
                'version' => $this->config('app.version', '0.0.0'),
                'env' => $this->config('app.env', 'production'),
                'debug' => (bool)$this->config('app.debug', false),
            ],
            'runtime' => [
                'capabilities_count' => $capCount,
                'providers_count' => $providerCount,
                'schema_enforcement_mode' => $schemaMode,
                'multi_tenant_enabled' => $multiTenant,
            ],
        ];
    }

    /**
     * Kernel-owned glossary — plain-English descriptions for platform concepts.
     * Returns a map of technical IDs to human-readable descriptions.
     * Modules extend this via the 'kernel.glossary' filter hook.
     */
    public function glossary(): array
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }

        $glossary = [
            // Kernel capabilities
            'kernel.auth.user@1' => [
                'label' => 'Get Current User',
                'description' => 'Returns the currently logged-in user, if any.',
                'category' => 'Authentication',
            ],
            'kernel.auth.require@1' => [
                'label' => 'Require Login',
                'description' => 'Ensures the user is logged in. Returns user data or blocks the request.',
                'category' => 'Authentication',
            ],
            'kernel.auth.authenticate@1' => [
                'label' => 'Login Verification',
                'description' => 'Checks username and password. Multiple modules can provide login (kernel, CMS, daily-ledger).',
                'category' => 'Authentication',
            ],
            'kernel.audit.record@1' => [
                'label' => 'Record Activity',
                'description' => 'Saves an audit log entry — who did what, when, and to which record.',
                'category' => 'Audit & Logging',
            ],
            'kernel.render.context@1' => [
                'label' => 'Page Context Builder',
                'description' => 'Builds the data needed to render a page — user info, navigation, theme settings.',
                'category' => 'Rendering',
            ],
            'kernel.http.request_context@1' => [
                'label' => 'Request Info',
                'description' => 'Returns details about the current web request — URL, method, IP address.',
                'category' => 'System',
            ],
            'workflow.state.get@1' => [
                'label' => 'Get Workflow Status',
                'description' => 'Checks what stage a record is at in its workflow (e.g., draft, in review, published).',
                'category' => 'Workflow',
            ],
            'workflow.transition@1' => [
                'label' => 'Move Workflow Forward',
                'description' => 'Advances a record to the next stage in its workflow (e.g., submit for review, approve, publish).',
                'category' => 'Workflow',
            ],
            // Common events
            'cms.content.created' => [
                'label' => 'Content Created',
                'description' => 'A new page or post was created in the CMS.',
                'category' => 'Content',
            ],
            'cms.content.published' => [
                'label' => 'Content Published',
                'description' => 'A page or post was published and is now visible to the public.',
                'category' => 'Content',
            ],
            'cms.content.updated' => [
                'label' => 'Content Updated',
                'description' => 'An existing page or post was modified.',
                'category' => 'Content',
            ],
            'cms.content.deleted' => [
                'label' => 'Content Deleted',
                'description' => 'A page or post was moved to trash.',
                'category' => 'Content',
            ],
            'workflow.transitioned' => [
                'label' => 'Workflow Stage Changed',
                'description' => 'A record moved from one workflow stage to another (e.g., draft → review).',
                'category' => 'Workflow',
            ],
            // Platform terms
            '_term.capability' => [
                'label' => 'Service',
                'description' => 'A reusable function that modules can call — like "send SMS" or "record activity log".',
                'category' => 'Platform Concepts',
            ],
            '_term.event' => [
                'label' => 'Event',
                'description' => 'A notification that something happened — like "content was published" or "user logged in".',
                'category' => 'Platform Concepts',
            ],
            '_term.trigger' => [
                'label' => 'Automatic Action',
                'description' => 'A rule that says "when this event happens, automatically call this service" — like auto-sending an SMS when content is published.',
                'category' => 'Platform Concepts',
            ],
            '_term.provider' => [
                'label' => 'Service Provider',
                'description' => 'The module or system that actually handles a service. Multiple providers can offer the same service.',
                'category' => 'Platform Concepts',
            ],
            '_term.schema_mode.warn' => [
                'label' => 'Check but Allow',
                'description' => 'The system checks if data is correct but lets it through even if there are issues. Problems are logged.',
                'category' => 'Platform Concepts',
            ],
            '_term.schema_mode.enforce' => [
                'label' => 'Check and Block',
                'description' => 'The system checks if data is correct and blocks the request if there are issues.',
                'category' => 'Platform Concepts',
            ],
            '_term.breaker' => [
                'label' => 'Circuit Breaker',
                'description' => 'A safety switch that temporarily pauses a failing service to prevent cascading problems.',
                'category' => 'Platform Concepts',
            ],
            '_term.correlation_id' => [
                'label' => 'Trace ID',
                'description' => 'A unique identifier that links together all the steps in an automated action chain, making it possible to trace what happened.',
                'category' => 'Platform Concepts',
            ],
        ];

        // Let modules extend the glossary with their own descriptions
        $glossary = $this->hooks()->filter('kernel.glossary', $glossary);

        $cached = $glossary;
        return $cached;
    }

    /**
     * Get the tenant resolver
     */
    public function tenant(): TenantResolver
    {
        if ($this->tenantResolver === null) {
            // Tenant settings live under the 'app' key (config/app.php).
            $this->tenantResolver = TenantResolver::getInstance($this->config['app'] ?? []);
        }
        return $this->tenantResolver;
    }

    /**
     * Get configuration value
     */
    public function config(string $key, $default = null)
    {
        $keys = explode('.', $key);
        $value = $this->config;
        
        foreach ($keys as $k) {
            if (!isset($value[$k])) {
                return $default;
            }
            $value = $value[$k];
        }
        
        return $value;
    }

    /**
     * Build a PDO DSN from a database config array.
     */
    private function buildDsn(array $dbConfig): string
    {
        return sprintf(
            '%s:host=%s;port=%s;dbname=%s;charset=%s',
            $dbConfig['driver'] ?? 'mysql',
            $dbConfig['host'] ?? 'localhost',
            $dbConfig['port'] ?? '3306',
            $dbConfig['database'] ?? '',
            $dbConfig['charset'] ?? 'utf8mb4'
        );
    }

    /**
     * Resolve the tenant database connection config from the control plane.
     * Returns null when multi-tenancy is disabled or tenant cannot be resolved.
     */
    private function resolveTenantDatabaseConfig(): ?array
    {
        $mt = $this->config['app']['multi_tenant'] ?? [];
        if (empty($mt['enabled'])) {
            return null;
        }

        $tenantId = $this->tenant()->resolve($this->user());
        if ($tenantId === null || $tenantId <= 0) {
            return null;
        }

        // Only the control-plane strategies should trigger DB switching.
        $strategy = (string)($mt['strategy'] ?? '');
        if ($strategy !== 'control_host' && $strategy !== 'control' && $strategy !== 'auto') {
            return null;
        }

        try {
            $stmt = $this->controlDb()->prepare(
                'SELECT db_driver, db_host, db_port, db_name, db_user, db_pass, db_charset, '
                . 'db_pass_ciphertext, db_pass_iv, db_pass_tag '
                . 'FROM kernel_tenant_db_connections WHERE tenant_id = :tid LIMIT 1'
            );
            $stmt->execute([':tid' => $tenantId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!is_array($row) || empty($row['db_host']) || empty($row['db_name']) || empty($row['db_user'])) {
                return null;
            }

            $password = (string)($row['db_pass'] ?? '');
            $cipher = (string)($row['db_pass_ciphertext'] ?? '');
            $iv = (string)($row['db_pass_iv'] ?? '');
            $tag = (string)($row['db_pass_tag'] ?? '');
            if ($cipher !== '' && $iv !== '' && $tag !== '') {
                $crypto = new Crypto();
                $password = $crypto->decryptString($cipher, $iv, $tag);
            }

            return [
                'driver' => (string)($row['db_driver'] ?? 'mysql'),
                'host' => (string)($row['db_host'] ?? 'localhost'),
                'port' => (string)($row['db_port'] ?? '3306'),
                'database' => (string)($row['db_name'] ?? ''),
                'username' => (string)($row['db_user'] ?? ''),
                'password' => $password,
                'charset' => (string)($row['db_charset'] ?? 'utf8mb4'),
                // keep options from the base database config
                'options' => ($this->config['database']['options'] ?? null),
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }
    
    /**
     * Get database connection (lazy loaded)
     */
    public function db(): PDO
    {
        if ($this->db === null) {
            $dbConfig = $this->config['database'] ?? [];

            $tenantDbConfig = $this->resolveTenantDatabaseConfig();
            if (is_array($tenantDbConfig)) {
                $dbConfig = array_merge($dbConfig, $tenantDbConfig);
            }

            $dsn = $this->buildDsn($dbConfig);
            
            $pdoClass = '\\Ikabud\\Kernel\\Database\\KernelPDO';
            $this->db = new $pdoClass(
                $dsn,
                $dbConfig['username'] ?? '',
                $dbConfig['password'] ?? '',
                $dbConfig['options'] ?? [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
        }
        
        return $this->db;
    }

    /**
     * Get control-plane database connection (lazy loaded).
     * This DB is used for tenant registry lookups and provisioning state.
     */
    public function controlDb(): PDO
    {
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
                ]
            );
        }

        return $this->controlDb;
    }

    /**
     * Get a database connection for a specific tenant by ID.
     * Looks up credentials from kernel_tenant_db_connections in the control DB.
     * If the tenant matches the current request's tenant, returns the normal db().
     * Connections are cached per tenant_id for the request lifetime.
     */
    public function dbForTenant(int $tenantId): ?PDO
    {
        // If this tenant matches the current request's resolved tenant, reuse app()->db()
        $currentTid = $this->tenant()->current();
        if (PHP_SAPI !== 'cli' && $currentTid !== null && (int)$currentTid === $tenantId) {
            return $this->db();
        }

        // Return cached connection if available
        if (isset($this->tenantDbPool[$tenantId])) {
            return $this->tenantDbPool[$tenantId];
        }

        try {
            $stmt = $this->controlDb()->prepare(
                'SELECT db_driver, db_host, db_port, db_name, db_user, db_pass, db_charset, '
                . 'db_pass_ciphertext, db_pass_iv, db_pass_tag '
                . 'FROM kernel_tenant_db_connections WHERE tenant_id = :tid LIMIT 1'
            );
            $stmt->execute([':tid' => $tenantId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!is_array($row) || empty($row['db_host']) || empty($row['db_name']) || empty($row['db_user'])) {
                return null;
            }

            $password = (string)($row['db_pass'] ?? '');
            $cipher = (string)($row['db_pass_ciphertext'] ?? '');
            $iv = (string)($row['db_pass_iv'] ?? '');
            $tag = (string)($row['db_pass_tag'] ?? '');
            if ($cipher !== '' && $iv !== '' && $tag !== '') {
                $crypto = new Crypto();
                $password = $crypto->decryptString($cipher, $iv, $tag);
            }

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
            ];

            $pdoClass = '\\Ikabud\\Kernel\\Database\\KernelPDO';
            $pdo = new $pdoClass($dsn, $dbConfig['username'], $dbConfig['password'], $options);
            $this->tenantDbPool[$tenantId] = $pdo;
            return $pdo;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Get DiSyL template engine (lazy loaded)
     */
    public function templates(): TemplateEngine
    {
        if ($this->templateEngine === null) {
            $this->templateEngine = new TemplateEngine(
                $this->config('paths.templates', TEMPLATES_PATH),
                $this->config('paths.cache', STORAGE_PATH . '/cache/disyl'),
                !$this->config('app.debug', false)
            );
            
            $this->templateEngine->setGlobals([
                'app_name' => $this->config('app.name', 'Ikabud System'),
                'app_url' => $this->config('app.url', '/guidance'),
                'app_version' => $this->config('app.version', '1.0.0'),
                'hour' => (int) date('G'),
            ]);
        }
        
        return $this->templateEngine;
    }
    
    /**
     * Get JWT handler (lazy loaded)
     */
    public function jwt(): JWT
    {
        if ($this->jwt === null) {
            $this->jwt = new JWT(
                $this->config('app.jwt.secret'),
                $this->config('app.jwt.expiration', 86400)
            );
        }
        
        return $this->jwt;
    }
    
    /**
     * Get cache handler (lazy loaded)
     */
    public function cache(): Cache
    {
        if ($this->cache === null) {
            $this->cache = new Cache(
                $this->config('paths.cache', STORAGE_PATH . '/cache')
            );
        }
        
        return $this->cache;
    }
    
    /**
     * Generate a CSRF token (kernel-level, no external dependency).
     * Stored in the session; created once per session.
     */
    public function csrfToken(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE && !headers_sent()) {
            session_start();
        }
        if (empty($_SESSION['_csrf_token'])) {
            $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf_token'];
    }

    /**
     * Generate a CSRF hidden input field.
     */
    public function csrfField(): string
    {
        return '<input type="hidden" name="_token" value="' . htmlspecialchars($this->csrfToken(), ENT_QUOTES, 'UTF-8') . '">';
    }

    /**
     * Enforce CSRF token validation on the current request.
     * Call this at the top of any state-mutating handler.
     */
    public function csrfEnforce(): void
    {
        $input = $this->input();
        $token = $input['_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if (!is_string($token) || $token === '' || !hash_equals($this->csrfToken(), $token)) {
            $this->json(['ok' => false, 'error' => 'Invalid CSRF token'], 419);
        }
    }

    /**
     * Render a DiSyL template.
     * 
     * The kernel builds a base context from its own state, then lets any
     * registered hook listeners enrich it (navigation, GUI overrides, etc.).
     * Zero function_exists() calls — all extension is via the Hooks system.
     * 
     * Well-known hooks fired during render:
     *   'kernel.nav_items'      (filter)  array $items, ?array $user
     *   'kernel.gui_context'    (filter)  array $guiDefaults
     *   'kernel.render_context' (filter)  array $context, string $template
     */
    public function render(string $template, array $context = []): string
    {
        $appUrl = $this->config('app.url', '');
        $baseUrl = rtrim(parse_url($appUrl, PHP_URL_PATH) ?: '', '/');
        
        $user = $this->user();
        if ($user) {
            if (empty($user['first_name']) && !empty($user['name'])) {
                $parts = explode(' ', $user['name'], 2);
                $user['first_name'] = $parts[0];
                $user['last_name'] = $parts[1] ?? '';
            }
        }
        
        // ── Navigation (cached per-request) ────────────────────────
        // The kernel provides an empty array; modules add their items via hook.
        if ($this->cachedNavItems === null) {
            $this->cachedNavItems = $this->hooks()->filter('kernel.nav_items', [], $user);
        }
        
        // ── GUI / theme context (cached per-request) ───────────────
        // Kernel owns the full default set. Modules may overlay via hook.
        if ($this->cachedGuiContext === null) {
            $appName = $this->config('app.name', 'Baron Bakeshop');
            $parts = explode(' ', $appName, 2);

            $kernelDefaults = [
                'app_name' => $appName, 'app_name_accent' => $parts[0], 'app_name_rest' => $parts[1] ?? '',
                'color_bg' => '#f4f5f7', 'color_surface' => '#ffffff', 'color_border' => '#dfe3e8',
                'color_text' => '#2d3748', 'color_text_muted' => '#5a6577', 'color_text_light' => '#8895a7',
                'color_primary' => '#2563eb', 'color_primary_hover' => '#1d4ed8', 'color_primary_light' => '#dbeafe',
                'color_success' => '#0d9f4f', 'color_success_light' => '#d4f5e0',
                'color_warning' => '#c87e08', 'color_warning_light' => '#fef3c7',
                'color_danger' => '#d42828', 'color_danger_light' => '#fee2e2',
                'color_header_bg' => '#1e293b', 'color_header_text' => '#ffffff', 'color_header_accent' => '#60a5fa',
                'font_family' => "'Inter', system-ui, sans-serif",
                'font_url' => 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap',
                'font_size_base' => '14px', 'font_size_small' => '12px',
                'font_size_h1' => '24px', 'font_size_h2' => '18px', 'font_size_nav' => '13px',
                'border_radius' => '8px', 'header_height' => '56px', 'nav_height' => '44px', 'max_width' => '1200px',
                'css_overrides' => '',
            ];

            // Layer 1: honour saved settings JSON (survives module disable)
            $guiFile = ($this->config('paths.storage', '') ?: (defined('STORAGE_PATH') ? STORAGE_PATH : '')) . '/gui-settings.json';
            if ($guiFile !== '/gui-settings.json' && is_file($guiFile)) {
                $saved = json_decode((string) file_get_contents($guiFile), true);
                if (is_array($saved)) {
                    $kernelDefaults = array_merge($kernelDefaults, $saved);
                }
            }

            // Layer 2: hook — modules may override/enrich (e.g. gui-settings adds css_overrides)
            $this->cachedGuiContext = $this->hooks()->filter('kernel.gui_context', $kernelDefaults);
        }
        
        // ── Build render context ───────────────────────────────────
        $baseContext = [
            'user' => $user,
            'is_htmx' => $this->isHtmx() && !$this->isHtmxBoosted(),
            'base_url' => $baseUrl,
            'app_url' => $appUrl,
            'cookie_name' => $this->config('app.cookie_name', 'guidance_token'),
            'csrf_token' => $this->csrfToken(),
            'csrf_field' => $this->csrfField(),
            'nav_items' => $this->cachedNavItems,
            'gui' => $this->cachedGuiContext,
        ];

        // Hook: let modules inject extra context vars
        $baseContext = $this->hooks()->filter('kernel.render_context', $baseContext, $template);

        // Caller context overrides base (so handlers can override any key)
        $context = array_merge($baseContext, $context);
        
        return $this->templates()->render($template, $context);
    }
    
    /**
     * Check if current request is HTMX
     */
    public function isHtmx(): bool
    {
        return isset($_SERVER['HTTP_HX_REQUEST']) && $_SERVER['HTTP_HX_REQUEST'] === 'true';
    }

    /**
     * Check if current request is an HTMX boosted navigation (hx-boost).
     * Boosted requests replace the full <body> so they need the complete layout.
     */
    public function isHtmxBoosted(): bool
    {
        return !empty($_SERVER['HTTP_HX_BOOSTED']);
    }
    
    /**
     * Get HTMX request details
     */
    public function htmx(): array
    {
        return [
            'request' => $_SERVER['HTTP_HX_REQUEST'] ?? false,
            'trigger' => $_SERVER['HTTP_HX_TRIGGER'] ?? null,
            'trigger_name' => $_SERVER['HTTP_HX_TRIGGER_NAME'] ?? null,
            'target' => $_SERVER['HTTP_HX_TARGET'] ?? null,
            'current_url' => $_SERVER['HTTP_HX_CURRENT_URL'] ?? null,
            'boosted' => isset($_SERVER['HTTP_HX_BOOSTED']),
        ];
    }
    
    /**
     * Send HTMX response headers
     */
    public function htmxResponse(array $headers = []): void
    {
        foreach ($headers as $key => $value) {
            $headerName = 'HX-' . ucfirst($key);
            header("{$headerName}: {$value}");
        }
    }
    
    /**
     * Get current authenticated user
     */
    public function user(): ?array
    {
        if ($this->currentUser !== null) {
            return $this->currentUser;
        }

        // Try cookies first (for page requests). The kernel has a default cookie_name,
        // but modules may also declare their own auth cookies. We allow modules to
        // supply additional cookie names via a hook to keep auth routing stable.
        $cookieName = $this->config('app.cookie_name', 'guidance_token');
        $cookieCandidates = [$cookieName];
        try {
            $extra = $this->hooks()->filter('kernel.auth_cookie_names', [], $cookieName);
            if (is_array($extra)) {
                foreach ($extra as $c) {
                    if (is_string($c) && $c !== '' && !in_array($c, $cookieCandidates, true)) {
                        $cookieCandidates[] = $c;
                    }
                }
            }
        } catch (\Throwable $ignored) {
        }

        $token = null;
        foreach ($cookieCandidates as $cName) {
            $candidate = $_COOKIE[$cName] ?? null;
            if (is_string($candidate) && $candidate !== '') {
                $token = $candidate;
                break;
            }
        }
        
        // Then try Authorization header (for API requests)
        if (!$token) {
            $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
            if (preg_match('/Bearer\s+(.+)$/i', $authHeader, $matches)) {
                $token = $matches[1];
            }
        }
        
        if (!$token) {
            return null;
        }
        
        try {
            $this->currentUser = $this->jwt()->verify($token);

            // Multi-tenant JWT cross-validation: reject tokens issued for a
            // different tenant.  Skipped when multi-tenancy is disabled.
            if ($this->currentUser !== null && ($this->config['app']['multi_tenant']['enabled'] ?? false)) {
                $jwtTid = $this->currentUser['tenant_id'] ?? null;
                $curTid = $this->tenant()->current();
                if ($jwtTid !== null && $curTid !== null && (int) $jwtTid !== $curTid) {
                    $this->currentUser = null;
                    return null;
                }
            }

            return $this->currentUser;
        } catch (\Exception $e) {
            return null;
        }
    }
    
    /**
     * Set current user (after login)
     */
    public function setUser(array $user): void
    {
        $this->currentUser = $user;
    }
    
    /**
     * Check if user is authenticated
     */
    public function isAuthenticated(): bool
    {
        return $this->user() !== null;
    }
    
    /**
     * Check if user has role
     */
    public function hasRole(string $role): bool
    {
        $user = $this->user();
        return $user && ($user['role'] ?? '') === $role;
    }
    
    /**
     * Require authentication, redirect if not
     */
    public function requireAuth(): array
    {
        $user = $this->user();
        
        if (!$user) {
            $this->redirect('/login');
        }
        
        return $user;
    }
    
    /**
     * Require specific role
     */
    public function requireRole(string $role): array
    {
        $user = $this->requireAuth();
        
        if (($user['role'] ?? '') !== $role) {
            if ($this->isHtmx()) {
                http_response_code(403);
                echo '<div class="p-4 text-red-600">Access denied</div>';
                exit;
            }
            $this->redirect('/');
        }
        
        return $user;
    }
    
    /**
     * Require any of the specified roles
     */
    public function requireAnyRole(string ...$roles): array
    {
        $user = $this->requireAuth();
        
        if (!in_array($user['role'] ?? '', $roles, true)) {
            if ($this->isHtmx()) {
                http_response_code(403);
                echo '<div class="p-4 text-red-600">Access denied</div>';
                exit;
            }
            $this->redirect('/');
        }
        
        return $user;
    }
    
    /**
     * Send JSON response
     */
    public function json(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    /**
     * Send HTML response
     */
    public function html(string $content, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        echo $content;
        exit;
    }
    
    /**
     * Redirect
     */
    public function redirect(string $url, int $status = 302): void
    {
        if ($url === '') {
            $url = '/';
        }

        // Auto-prefix with base path for relative URLs
        if ($url[0] === '/' && strpos($url, '//') !== 0) {
            $appUrl = $this->config('app.url', '');
            $basePath = rtrim(parse_url($appUrl, PHP_URL_PATH) ?: '', '/');
            if ($basePath && strpos($url, $basePath) !== 0) {
                $url = $basePath . $url;
            }
        }
        
        if ($this->isHtmx()) {
            header("HX-Redirect: {$url}");
        } else {
            header("Location: {$url}", true, $status);
        }
        exit;
    }
    
    /**
     * Get request input (hardened).
     * 
     * Security measures:
     * - JSON body size capped at MAX_INPUT_SIZE (2 MB)
     * - Null bytes stripped from all string values (path traversal defence)
     * - Deeply nested JSON capped at 64 levels (hash collision DoS defence)
     */
    public function input(?string $key = null, $default = null)
    {
        static $input = null;
        
        if ($input === null) {
            $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
            
            if (strpos($contentType, 'application/json') !== false) {
                $raw = file_get_contents('php://input');
                if ($raw === false || strlen($raw) > self::MAX_INPUT_SIZE) {
                    $input = []; // reject oversized payloads silently
                } else {
                    $decoded = json_decode($raw, true, 64);
                    if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
                        // JSON parse failed — return structured error so callers
                        // (especially the page-builder save handler) never
                        // silently overwrite real data with an empty document.
                        $input = ['_json_error' => json_last_error_msg()];
                    } else {
                        $input = $decoded ?? [];
                    }
                }
            } elseif (in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['PUT', 'PATCH', 'DELETE'])) {
                $raw = file_get_contents('php://input');
                if ($raw !== false && strlen($raw) <= self::MAX_INPUT_SIZE) {
                    parse_str($raw, $parsed);
                    $input = array_merge($_GET, $parsed);
                } else {
                    $input = $_GET;
                }
            } else {
                $input = array_merge($_GET, $_POST);
            }

            // Strip null bytes from all string values (prevents path traversal)
            $input = self::sanitizeInput($input);
        }
        
        if ($key === null) {
            return $input;
        }
        
        return $input[$key] ?? $default;
    }

    /**
     * Recursively sanitize input: strip null bytes, enforce depth limit.
     *
     * Note: depth must be high enough to accommodate deeply nested structures
     * such as page-builder documents (document → section → container → slideshow
     * → props → slides[] → slide → image_url can easily reach depth 11+).
     */
    private static function sanitizeInput(mixed $data, int $depth = 0): mixed
    {
        if ($depth > 32) {
            return null; // too deep — discard
        }
        if (is_string($data)) {
            return str_replace("\0", '', $data);
        }
        if (is_array($data)) {
            $clean = [];
            foreach ($data as $k => $v) {
                $cleanKey = is_string($k) ? str_replace("\0", '', $k) : $k;
                $clean[$cleanKey] = self::sanitizeInput($v, $depth + 1);
            }
            return $clean;
        }
        return $data;
    }
    
    /**
     * Log message
     */
    public function log(string $message, string $level = 'info', array $context = []): void
    {
        // Automatically include request context for error-level logs
        if (in_array($level, ['error', 'critical']) && empty($context['url'])) {
            $context['url'] = $_SERVER['REQUEST_URI'] ?? 'cli';
            $context['method'] = $_SERVER['REQUEST_METHOD'] ?? 'cli';
        }
        
        write_log($message, $level, $context);
    }
}
