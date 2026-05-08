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
 * @version 4.0.0
 */

namespace Ikabud\Kernel;

use Ikabud\Kernel\Capabilities\CapabilityBus;
use Ikabud\Kernel\Capabilities\CapabilityRegistry;
use Ikabud\Kernel\Database\KernelPDO;
use Ikabud\Kernel\Database\MigrationRunner;
use Ikabud\Kernel\EntityContext\ContextRegistry;
use Ikabud\Kernel\EntityAuthority\EntityAuthorityRegistry;
use Ikabud\Kernel\EntityAuthority\SyncContractRegistry;

use Ikabud\Kernel\TenantResolver;
use Ikabud\Kernel\Database\ModuleDB;
use Ikabud\Kernel\DiSyL\TemplateEngine;
use Ikabud\Kernel\DiSyL\Reactive\HTMXRequest;
use PDO;

final class App
{
    private static ?App $instance = null;

    private array $config = [];
    private ?Services\DatabaseManager $databaseManager = null;
    private ?TemplateEngine $templateEngine = null;
    private ?JWT $jwt = null;
    private ?Cache $cache = null;
    private ?Hooks $hooks = null;
    private ?EventBus $events = null;
    private ?WorkflowRuntime $workflowRuntime = null;
    private ?TenantResolver $tenantResolver = null;
    private ?CapabilityRegistry $capabilityRegistry = null;
    private ?CapabilityBus $capabilityBus = null;
    private ?ContextRegistry $entityContextRegistry = null;
    private ?EntityAuthorityRegistry $entityAuthorityRegistry = null;
    private ?SyncContractRegistry $syncContractRegistry = null;
    private ?IntegrationBridge $integrationBridge = null;
    private ?TriggerService $triggerService = null;

    /**
     * Module-declared source→user-table mapping.
     * Seeded with built-in defaults; modules extend via registerAuthTable().
     * @var array<string, string>
     */
    private array $authTableMap = [
        'kernel'       => 'users',
        'cms'          => 'cms_users',
        'guidance'     => 'gm_users',
        'daily-ledger' => 'dl_admins',
    ];

    private ?array $currentUser = null;
    private bool $resolvingCurrentUser = false;
    private bool $booted = false;
    private ?array $cachedNavItems = null;
    private ?array $cachedGuiContext = null;
    private ?array $cachedGuiDefaults = null;
    private ?string $cachedAppUrl = null;
    private ?string $cachedBaseUrl = null;
    
    public const KERNEL_VERSION = '4.6.0';
    public const KERNEL_CODENAME = 'atlas';

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
        $this->primeRenderBaseCaches();
        
        try {
            $db = $this->db();
            $stmt = $db->query("SELECT DISTINCT trigger_event FROM kernel_integrations WHERE is_active = 1");
            if ($stmt) {
                while ($trigger = $stmt->fetchColumn()) {
                    $this->events()->listen((string)$trigger, [\Ikabud\Kernel\IntegrationBridge::class, 'handle'], 100, 'kernel');
                }
            }
        } catch (\Throwable $e) {
            // Ignore during setup/migrations if table doesn't exist
        }
        
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
            // audit_logs.actor_user_id currently references kernel users only.
            $actorId = ($user && $source === 'kernel') ? (int)($user['id'] ?? $user['sub'] ?? 0) : null;
            if ($actorId !== null && $actorId <= 0) {
                $actorId = null;
            }
            $actorModuleUserId = ($user && $source !== '' && $source !== 'kernel') ? (int)($user['id'] ?? $user['sub'] ?? 0) : null;
            if ($actorModuleUserId !== null && $actorModuleUserId <= 0) {
                $actorModuleUserId = null;
            }
            $actorSource = $source !== '' ? $source : null;

            try {
                KernelPDO::kernelEscalationEnter();
                $stmt = $this->db()->prepare(
                    'INSERT INTO audit_logs (module, actor_user_id, actor_module_user_id, actor_source, branch_id, action, entity_type, entity_id, old_data, new_data) '
                    . 'VALUES (:module, :actor, :actor_mod, :actor_src, :branch, :action, :etype, :eid, :old, :new)'
                );
                $stmt->execute([
                    ':module' => $module,
                    ':actor' => $actorId,
                    ':actor_mod' => $actorModuleUserId,
                    ':actor_src' => $actorSource,
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
            } finally {
                KernelPDO::kernelEscalationLeave();
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

            return $this->buildRenderBaseContext($template);
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
                if (!is_array($row) || !in_array(($row['role'] ?? null), ['admin', 'superadmin'], true) || !password_verify($password, (string)$row['password_hash'])) {
                    return null;
                }
                // Fetch token_version separately: the column was added in migration 015.
                // If the migration has not yet run, default to 0 rather than blocking login.
                $row['token_version'] = 0;
                try {
                    $tvStmt = $this->db()->prepare(
                        'SELECT COALESCE(token_version, 0) AS token_version FROM users WHERE id = :id LIMIT 1'
                    );
                    $tvStmt->execute([':id' => (int)$row['id']]);
                    $tvRow = $tvStmt->fetch(\PDO::FETCH_ASSOC);
                    if (is_array($tvRow)) {
                        $row['token_version'] = (int)$tvRow['token_version'];
                    }
                } catch (\Throwable $tvEx) {
                    // token_version column not yet available — degrade to version 0.
                }
                return ['user' => $row, 'source' => 'kernel'];
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

    private function primeRenderBaseCaches(): void
    {
        $appUrl = external_base_url((string)$this->config('app.url', ''));
        $this->cachedAppUrl = $appUrl;
        $this->cachedBaseUrl = kernel_request_base_path(null, (string)$this->config('app.url', ''));
        $this->cachedGuiDefaults = $this->buildKernelGuiDefaults();
    }

    private function buildKernelGuiDefaults(): array
    {
        $appName = $this->config('app.name', 'Ikabud');
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

        return $kernelDefaults;
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
     * Register a module's auth user table for JWT token-version checks.
     * Modules call this during bootstrap: app()->registerAuthTable('mymod', 'mymod_users');
     */
    public function registerAuthTable(string $source, string $tableName): void
    {
        $source = trim($source);
        $tableName = trim($tableName);
        if ($source !== '' && $tableName !== '' && preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $tableName)) {
            $this->authTableMap[$source] = $tableName;
        }
    }

    /**
     * Get the integration bridge instance.
     */
    public function integrationBridge(): IntegrationBridge
    {
        if ($this->integrationBridge === null) {
            $this->integrationBridge = new IntegrationBridge();
        }
        return $this->integrationBridge;
    }

    /**
     * Get the trigger service (per-request caching and registration state).
     */
    public function triggers(): TriggerService
    {
        if ($this->triggerService === null) {
            $this->triggerService = new TriggerService();
        }
        return $this->triggerService;
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
     * Get the entity context registry (entity type -> context profile composition).
     */
    public function entityContexts(): ContextRegistry
    {
        if ($this->entityContextRegistry === null) {
            $this->entityContextRegistry = new ContextRegistry();
        }

        return $this->entityContextRegistry;
    }

    /**
     * Get the entity authority registry (enforces single-module ownership).
     */
    public function entityAuthority(): EntityAuthorityRegistry
    {
        if ($this->entityAuthorityRegistry === null) {
            $this->entityAuthorityRegistry = new EntityAuthorityRegistry();
        }
        return $this->entityAuthorityRegistry;
    }

    /**
     * Get the sync contract registry (allows modules to register for CRUD-like events against an authoritative entity).
     */
    public function syncContracts(): SyncContractRegistry
    {
        if ($this->syncContractRegistry === null) {
            $this->syncContractRegistry = new SyncContractRegistry();
        }
        return $this->syncContractRegistry;
    }

    /**
     * Kernel platform identity — single source of truth for version, codename, and runtime posture.
     * Used by health API, platform API, CLI, and admin dashboard.
     */
    public function platformIdentity(): array
    {
        static $requestCache = null;
        if (is_array($requestCache)) {
            return $requestCache;
        }

        if (extension_loaded('apcu') && function_exists('apcu_enabled') && apcu_enabled()) {
            $cached = apcu_fetch('kernel:platform_identity:v1', $hit);
            if ($hit && is_array($cached)) {
                $requestCache = $cached;
                return $cached;
            }
        }

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

        $identity = [
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

        $requestCache = $identity;
        if (extension_loaded('apcu') && function_exists('apcu_enabled') && apcu_enabled()) {
            // Short TTL keeps admin/runtime changes fresh while collapsing bursts.
            apcu_store('kernel:platform_identity:v1', $identity, 15);
        }

        return $identity;
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

    // ── DatabaseManager factory & delegation ────────────────────────────────

    private function databaseManager(): Services\DatabaseManager
    {
        if ($this->databaseManager === null) {
            $this->databaseManager = new Services\DatabaseManager(
                $this->config,
                fn(string $msg, string $level = 'info', array $ctx = []) => $this->log($msg, $level, $ctx),
                fn() => $this->resolveCurrentTenantDbTarget(),
                fn() => $this->tenant()->current(),
            );
        }
        return $this->databaseManager;
    }

    /**
     * Resolve whether the current request should use a tenant-specific database.
     * Kept in App because it needs $this->tenant() and $this->user().
     */
    private function resolveCurrentTenantDbTarget(): ?int
    {
        $mt = $this->config['app']['multi_tenant'] ?? [];
        if (empty($mt['enabled'])) {
            return null;
        }

        $tenantId = $this->tenant()->resolve($this->user());
        if ($tenantId === null || $tenantId <= 0) {
            return null;
        }

        $strategy = (string)($mt['strategy'] ?? '');
        if ($strategy !== 'control_host' && $strategy !== 'control' && $strategy !== 'auto') {
            return null;
        }

        return (int)$tenantId;
    }

    public function tenantDbPoolStats(): array
    {
        return $this->databaseManager()->tenantDbPoolStats();
    }

    public function dbRuntimeSnapshot(): array
    {
        return $this->databaseManager()->runtimeSnapshot();
    }

    /** Get primary database connection (lazy loaded, tenant-aware). */
    public function db(): PDO
    {
        return $this->databaseManager()->db();
    }

    /** Get control-plane database connection (lazy loaded). */
    public function controlDb(): PDO
    {
        return $this->databaseManager()->controlDb();
    }

    /** Get a database connection for a specific tenant by ID. */
    public function dbForTenant(int $tenantId): ?PDO
    {
        return $this->databaseManager()->dbForTenant($tenantId);
    }

    public function reconnectDb(): PDO
    {
        return $this->databaseManager()->reconnectDb();
    }

    public function reconnectControlDb(): PDO
    {
        return $this->databaseManager()->reconnectControlDb();
    }

    public function reconnectDbForTenant(int $tenantId): ?PDO
    {
        return $this->databaseManager()->reconnectDbForTenant($tenantId);
    }

    public function templates(): TemplateEngine
    {
        if ($this->templateEngine === null) {
            $this->templateEngine = new TemplateEngine(
                $this->config('paths.templates', TEMPLATES_PATH),
                $this->config('paths.cache', STORAGE_PATH . '/cache/disyl'),
                !$this->config('app.debug', false)
            );

            $this->templateEngine->setSharedOutputCacheTtl(
                (int)$this->config('disyl.shared_output_ttl', 0)
            );

            // Compiled mode: enabled by default in production; opt-out via DISYL_COMPILED_MODE=false.
            // Falls back silently to interpreted mode if v4 pipeline is unavailable.
            $compiledModeEnv = $_ENV['DISYL_COMPILED_MODE'] ?? null;
            $compiledModeDefault = ($this->config('app.env', 'production') !== 'development');
            if (filter_var($compiledModeEnv ?? ($compiledModeDefault ? 'true' : 'false'), FILTER_VALIDATE_BOOL)) {
                $this->templateEngine->enableCompiledMode(true);
            }

            // Strict mode (env-gated): warn on undefined variables, log raw filter usage.
            if (filter_var($_ENV['DISYL_STRICT_MODE'] ?? false, FILTER_VALIDATE_BOOL)) {
                $this->templateEngine->enableStrictMode(true);
            }
            
            $this->templateEngine->setGlobals([
                'app_name' => $this->config('app.name', 'Ikabud System'),
                'app_url' => external_base_url((string)$this->config('app.url', '/guidance')),
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
                $this->config('paths.cache', STORAGE_PATH . '/cache'),
                0,
                (bool) $this->config('app.cache.log_invalidations', false)
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
     * Rotate the CSRF token and optionally regenerate the session identifier.
     */
    public function csrfRotate(bool $regenerateSessionId = false): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE && !headers_sent()) {
            session_start();
        }

        if ($regenerateSessionId && session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
            @session_regenerate_id(true);
        }

        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
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

    private function buildRenderBaseContext(string $template = ''): array
    {
        $appUrl = $this->cachedAppUrl ?? external_base_url((string)$this->config('app.url', ''));
        $baseUrl = $this->cachedBaseUrl ?? kernel_request_base_path(null, (string)$this->config('app.url', ''));

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
            $this->cachedGuiContext = $this->hooks()->filter('kernel.gui_context', $this->cachedGuiDefaults ?? $this->buildKernelGuiDefaults());
        }

        $baseContext = [
            'user' => $user,
            'is_htmx' => $this->isHtmx() && !$this->isHtmxBoosted(),
            'base_url' => $baseUrl,
            'app_url' => $appUrl,
            'cookie_name' => $this->config('app.cookie_name', 'guidance_token'),
            'csrf_token' => $this->csrfToken(),
            'csrf_field' => $this->csrfField(),
            'csp_nonce' => function_exists('kernel_csp_nonce') ? kernel_csp_nonce() : '',
            'nav_items' => $this->cachedNavItems,
            'gui' => $this->cachedGuiContext,
        ];

        if ($template !== '') {
            $baseContext = $this->hooks()->filter('kernel.render_context', $baseContext, $template);
        }

        return $baseContext;
    }

    private function finalizeRenderContext(string $template, array $context): array
    {
        $context = \kernelNormalizeRenderContextContracts($context, $template);
        return $this->hooks()->filter('kernel.render_context.finalize', $context, $template);
    }

    /**
     * Build a compact, contract-aware render failure payload for exception messages.
     * This keeps theme-aware failures debuggable without leaking the full context.
     */
    private function renderFailurePayload(string $template, array $context): array
    {
        $contractTemplate = \kernelRenderTraceContractTemplate($template, $context);
        $matchedContracts = \kernelMatchedRenderContextContracts($contractTemplate);
        $matchedContractIds = [];

        foreach ($matchedContracts as $contract) {
            $contractId = trim((string)($contract['id'] ?? ''));
            if ($contractId !== '') {
                $matchedContractIds[] = $contractId;
            }
        }

        return [
            'template' => $template,
            'contract_template' => $contractTemplate,
            'render_profile_id' => trim((string)($context['render_profile_id'] ?? '')),
            'render_schema_stack' => is_array($context['render_schema_stack'] ?? null) ? array_values($context['render_schema_stack']) : [],
            'matched_contract_ids' => array_values(array_unique($matchedContractIds)),
            'public_route_kind' => trim((string)($context['public_route_kind'] ?? '')),
            'public_presentation_mode' => trim((string)($context['public_presentation_mode'] ?? '')),
        ];
    }

    private function wrapRenderFailure(string $template, array $context, \Throwable $e): \RuntimeException
    {
        $payload = $this->renderFailurePayload($template, $context);
        $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $message = 'Template render failed for ' . $template . ': ' . $e->getMessage();

        if (is_string($payloadJson) && $payloadJson !== '') {
            $message .= ' | context=' . $payloadJson;
        }

        return new \RuntimeException($message, 0, $e);
    }

    private function logRenderFailure(string $template, array $context, \Throwable $e): void
    {
        $payload = $this->renderFailurePayload($template, $context);
        $payload['exception_class'] = get_class($e);
        $payload['exception_message'] = $e->getMessage();
        $this->log('kernel.render_failure', 'error', $payload);
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
     *   'kernel.render_context.finalize' (filter)  array $context, string $template
     */
    public function render(string $template, array $context = []): string
    {
        $renderStartedAt = microtime(true);

        // Caller context overrides base (so handlers can override any key)
        $context = array_merge($this->buildRenderBaseContext($template), $context);
        $context = $this->finalizeRenderContext($template, $context);

        $renderContext = \kernelStripInternalRenderTraceContext($context);
        try {
            // DiSyL 4.3+: tenant-partition the fragment cache so {cache} blocks
            // and dependency tags do not leak across tenants. Safe no-op when
            // multi-tenant is disabled (current() returns null → '_global').
            $currentTenant = $this->tenant()->current();
            $this->templates()->setTenantId($currentTenant === null ? null : (string)$currentTenant);
            $output = $this->templates()->render($template, $renderContext);
        } catch (\Throwable $e) {
            $this->logRenderFailure($template, $context, $e);
            throw $this->wrapRenderFailure($template, $context, $e);
        }

        if (!\kernelRenderTraceCaptureEnabled()) {
            return $output;
        }

        $contractTemplate = \kernelRenderTraceContractTemplate($template, $context);
        $matchedContracts = \kernelMatchedRenderContextContracts($contractTemplate);
        $normalizationActions = \kernelRenderTraceNormalizationActions($context);

        $trace = \kernelBuildRenderTrace($template, $contractTemplate, $context, $matchedContracts, $normalizationActions, $renderStartedAt);
        \kernelRecordRenderTrace($trace);

        return \kernelApplyRenderTraceOutput($output, $trace);
    }
    
    /**
     * Check if current request is HTMX
     */
    public function isHtmx(): bool
    {
        // HX-History-Restore-Request means HTMX needs the full page (back/forward
        // navigation with a cache miss). Treat it as a normal full-page request so
        // the layout (sidebar, etc.) is returned.
        if (!empty($_SERVER['HTTP_HX_HISTORY_RESTORE_REQUEST'])) {
            return false;
        }
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

        if ($this->resolvingCurrentUser) {
            return null;
        }

        $this->resolvingCurrentUser = true;

        try {
            // Try cookies first (for page requests). The kernel has a default cookie_name,
            // but modules may also declare their own auth cookies. Resolve those directly
            // from manifests here to avoid hook/module-context recursion during bootstrap.
            $cookieName = $this->config('app.cookie_name', 'guidance_token');
            $cookieCandidates = [$cookieName];

            if (function_exists('declaredModuleAuthCookieNames')) {
                foreach (declaredModuleAuthCookieNames() as $c) {
                    if (is_string($c) && $c !== '' && !in_array($c, $cookieCandidates, true)) {
                        $cookieCandidates[] = $c;
                    }
                }
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

                // token_version check: reject tokens issued before the last password change.
                // Applies to all authenticated sources (kernel + module users).
                if ($this->currentUser !== null
                    && isset($this->currentUser['token_version'])
                ) {
                    $userId = (int)($this->currentUser['id'] ?? 0);
                    $source = $this->currentUser['source'] ?? 'kernel';
                    if ($userId > 0) {
                        // Map JWT source to the user table that holds token_version.
                        $userTable = $this->authTableMap[$source] ?? null;
                        if ($userTable !== null) {
                            try {
                                // Memoize token_version validation per request to avoid repeated queries
                                static $tokenVersionCache = [];
                                $cacheKey = $source . ':' . $userId;
                                
                                if (!isset($tokenVersionCache[$cacheKey])) {
                                    $stmt = $this->db()->prepare(
                                        'SELECT COALESCE(token_version, 0) AS token_version FROM `' . $userTable . '` WHERE id = ? LIMIT 1'
                                    );
                                    $stmt->execute([$userId]);
                                    $tvRow = $stmt->fetch(\PDO::FETCH_ASSOC);
                                    $tokenVersionCache[$cacheKey] = is_array($tvRow) ? (int)$tvRow['token_version'] : 0;
                                }
                                
                                if ($tokenVersionCache[$cacheKey] !== (int)$this->currentUser['token_version']) {
                                    $this->currentUser = null;
                                    return null;
                                }
                            } catch (\Throwable $ignored) {
                                // Non-fatal: column may not exist yet (pre-migration). Continue.
                            }
                        }
                    }
                }

                return $this->currentUser;
            } catch (\Throwable $e) {
                return null;
            }
        } finally {
            $this->resolvingCurrentUser = false;
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
            $this->log('auth.access_denied', 'warning', [
                'required_role' => $role,
                'user_role' => $user['role'] ?? '',
                'user_id' => $user['id'] ?? null,
                'uri' => $_SERVER['REQUEST_URI'] ?? '',
            ]);
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
            $this->log('auth.access_denied', 'warning', [
                'required_roles' => $roles,
                'user_role' => $user['role'] ?? '',
                'user_id' => $user['id'] ?? null,
                'uri' => $_SERVER['REQUEST_URI'] ?? '',
            ]);
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
            $basePath = kernel_request_base_path(null, (string)$this->config('app.url', ''));
            if ($basePath && $url !== $basePath && strpos($url, $basePath . '/') !== 0) {
                $url = $basePath . $url;
            }
        }

        try {
            $url = \kernel_validate_redirect_target($url);
        } catch (\Throwable $e) {
            $this->log('Blocked invalid redirect target', 'warning', [
                'redirect_target' => $url,
                'exception' => get_class($e),
            ]);
            $url = '/';
        }
        
        if ($this->isHtmx()) {
            \kernel_emit_redirect_header($url, $status, 'HX-Redirect');
        } else {
            \kernel_emit_redirect_header($url, $status);
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
        static $inputSignature = null;

        $currentSignature = null;
        if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') {
            $currentSignature = md5(serialize([
                $_SERVER['REQUEST_METHOD'] ?? 'GET',
                $_SERVER['REQUEST_URI'] ?? '',
                $_SERVER['CONTENT_TYPE'] ?? '',
                $_GET,
                $_POST,
            ]));
        }
        
        if ($input === null || ($currentSignature !== null && $inputSignature !== $currentSignature)) {
            $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
            
            if (strpos($contentType, 'application/json') !== false) {
                $raw = file_get_contents('php://input');
                if ($raw === false || strlen($raw) > self::MAX_INPUT_SIZE) {
                    $input = []; // reject oversized payloads silently
                } else {
                    $decoded = json_decode($raw, true, 32);
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
            $inputSignature = $currentSignature;
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
