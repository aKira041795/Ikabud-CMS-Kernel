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
 * @version 6.0.0
 */

namespace Ikabud\Kernel;

use Ikabud\Kernel\Capabilities\CapabilityBus;
use Ikabud\Kernel\Capabilities\CapabilityRegistry;
use Ikabud\Kernel\Database\KernelPDO;
use Ikabud\Kernel\Database\MigrationRunner;
use Ikabud\Kernel\EntityContext\ContextRegistry;
use Ikabud\Kernel\EntityAuthority\EntityAuthorityRegistry;
use Ikabud\Kernel\EntityAuthority\SyncContractRegistry;
use Ikabud\Kernel\EntityContext\EntityViewResolver;
use Ikabud\Kernel\EntityContext\EntityRendererInterface;
use Ikabud\Kernel\EntityContext\DefaultEntityRenderer;
use Ikabud\Kernel\EntityContext\CellRendererRegistryInterface;
use Ikabud\Kernel\EntityContext\CellRendererRegistry;
use Ikabud\Kernel\EntityContext\EntitySourceRegistry;
use Ikabud\Kernel\Services\SlotRegistry;

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
    private ?WorkflowEngine $workflowEngine = null;
    private ?TenantResolver $tenantResolver = null;
    private ?CapabilityRegistry $capabilityRegistry = null;
    private ?CapabilityBus $capabilityBus = null;
    private ?ContextRegistry $entityContextRegistry = null;
    private ?EntityAuthorityRegistry $entityAuthorityRegistry = null;
    private ?SyncContractRegistry $syncContractRegistry = null;
    private ?EntityViewResolver $entityViewResolver = null;
    private ?EntityRendererInterface $entityRenderer = null;
    private ?EntitySourceRegistry $entitySourceRegistry = null;
    private ?CellRendererRegistryInterface $entityCellRendererRegistry = null;
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

    // JWT sliding refresh: when a cookie-based token is verified, track the
    // old token and cookie name so we can rotate it after the response.
    private ?string $tokenToRotate = null;
    private ?string $rotateCookieName = null;
    private bool $tokenRotated = false;
    private ?array $cachedNavItems = null;
    private ?array $cachedGuiContext = null;
    private ?array $cachedGuiDefaults = null;
    private ?string $cachedAppUrl = null;
    private ?string $cachedBaseUrl = null;
    
    public const KERNEL_VERSION = '6.1.0';
    public const KERNEL_CODENAME = 'entity-view-extraction';

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
        $this->seedAuthTableMapFromConfig();
        \Ikabud\Kernel\Services\KernelExport::registerDefaults();
        
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
                $db = $this->db();
                $supportsActorColumns = false;
                try {
                    $moduleUserStmt = $db->query("SHOW COLUMNS FROM audit_logs LIKE 'actor_module_user_id'");
                    $hasModuleUserId = $moduleUserStmt && $moduleUserStmt->fetchColumn() !== false;
                    $sourceStmt = $db->query("SHOW COLUMNS FROM audit_logs LIKE 'actor_source'");
                    $hasActorSource = $sourceStmt && $sourceStmt->fetchColumn() !== false;
                    $supportsActorColumns = $hasModuleUserId && $hasActorSource;
                } catch (\Throwable) {
                    $supportsActorColumns = false;
                }

                if ($supportsActorColumns) {
                    $stmt = $db->prepare(
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
                } else {
                    $stmt = $db->prepare(
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
                }
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

        // kernel.auth.delegate@1 (first):
        // Issues a kernel-signed delegation JWT for cross-module identity transfer.
        // Payload: {from_module: string, to_module: string, identity_email: string,
        //           tenant_id: string|int, purpose: string, ttl_seconds?: int}
        // Return: {ok: bool, delegation_token?: string, error?: string}
        $caps->register('kernel.auth.delegate@1', 'kernel', function ($payload): array {
            if (!is_array($payload)) {
                return ['ok' => false, 'error' => 'Invalid payload.'];
            }

            $fromModule = trim((string)($payload['from_module'] ?? ''));
            $toModule = trim((string)($payload['to_module'] ?? ''));
            $identityEmail = trim(strtolower((string)($payload['identity_email'] ?? '')));
            $tenantId = (string)($payload['tenant_id'] ?? '');
            $purpose = trim((string)($payload['purpose'] ?? ''));
            $ttl = isset($payload['ttl_seconds']) ? (int)$payload['ttl_seconds'] : 300;

            if ($fromModule === '' || $toModule === '' || $identityEmail === ''
                || $tenantId === '' || $purpose === '') {
                return ['ok' => false, 'error' => 'Missing required parameters: from_module, to_module, identity_email, tenant_id, purpose.'];
            }

            if ($fromModule === $toModule) {
                return ['ok' => false, 'error' => 'from_module and to_module must differ.'];
            }

            $ttl = max(30, min($ttl, 3600)); // clamp 30s–1h

            try {
                // Create a JWT instance with the delegation-specific TTL
                // (the singleton jwt() has a fixed 86400s default expiration).
                $delegationJwt = new JWT(null, $ttl);
                $token = $delegationJwt->generate([
                    'sub' => 'delegation:' . $fromModule . '->' . $toModule,
                    'del_from_module' => $fromModule,
                    'del_to_module' => $toModule,
                    'del_email' => $identityEmail,
                    'del_tenant' => $tenantId,
                    'del_purpose' => $purpose,
                ]);

                // Audit the delegation issuance
                try {
                    $this->capabilities()->call('kernel.audit.record@1', [
                        'module' => 'kernel',
                        'action' => 'auth.delegate.issued',
                        'entity_type' => 'delegation',
                        'new_data' => [
                            'from_module' => $fromModule,
                            'to_module' => $toModule,
                            'identity_email' => $identityEmail,
                            'tenant_id' => $tenantId,
                            'purpose' => $purpose,
                            'ttl' => $ttl,
                        ],
                    ], ['caller' => ['module' => 'kernel'], 'mode' => 'first']);
                } catch (\Throwable) {
                    // Audit is best-effort
                }

                return ['ok' => true, 'delegation_token' => $token];
            } catch (\Throwable $e) {
                $this->log('kernel.auth.delegate@1 failed: ' . $e->getMessage(), 'error');
                return ['ok' => false, 'error' => 'Failed to issue delegation token.'];
            }
        }, 1000, ['first'], $kernelCapabilityMeta('kernel.auth.delegate@1', ['schema' => [
            'input' => [
                'type' => 'object',
                'required' => ['from_module', 'to_module', 'identity_email', 'tenant_id', 'purpose'],
                'properties' => [
                    'from_module' => ['type' => 'string'],
                    'to_module' => ['type' => 'string'],
                    'identity_email' => ['type' => 'string'],
                    'tenant_id' => ['type' => 'string'],
                    'purpose' => ['type' => 'string'],
                    'ttl_seconds' => ['type' => 'integer'],
                ],
            ],
            'output' => [
                'type' => 'object',
                'required' => ['ok'],
                'properties' => [
                    'ok' => ['type' => 'boolean'],
                    'delegation_token' => ['type' => 'string'],
                    'error' => ['type' => 'string'],
                ],
            ],
        ]]));

        // kernel.auth.validate_delegate@1 (first):
        // Validates a kernel-signed delegation JWT.
        // Payload: {delegation_token: string, expected_module?: string, expected_purpose?: string}
        // Return: {valid: bool, identity_email?: string, from_module?: string, tenant_id?: string, error?: string}
        $caps->register('kernel.auth.validate_delegate@1', 'kernel', function ($payload): array {
            if (!is_array($payload)) {
                return ['valid' => false, 'error' => 'Invalid payload.'];
            }

            $token = trim((string)($payload['delegation_token'] ?? ''));
            $expectedModule = isset($payload['expected_module']) ? trim((string)$payload['expected_module']) : '';
            $expectedPurpose = isset($payload['expected_purpose']) ? trim((string)$payload['expected_purpose']) : '';

            if ($token === '') {
                return ['valid' => false, 'error' => 'Missing delegation_token.'];
            }

            try {
                $data = $this->jwt()->verify($token);
                if (!is_array($data)) {
                    return ['valid' => false, 'error' => 'Invalid delegation token.'];
                }

                // Verify this is a delegation token (check for delegation-specific claims)
                $fromModule = (string)($data['del_from_module'] ?? '');
                $toModule = (string)($data['del_to_module'] ?? '');
                if ($fromModule === '' || $toModule === '') {
                    return ['valid' => false, 'error' => 'Token is not a delegation token.'];
                }

                // Verify subject pattern
                $sub = (string)($data['sub'] ?? '');
                if (!str_starts_with($sub, 'delegation:')) {
                    return ['valid' => false, 'error' => 'Invalid delegation subject.'];
                }

                // Optional: verify target module
                if ($expectedModule !== '' && $toModule !== $expectedModule) {
                    return ['valid' => false, 'error' => 'Delegation token is not intended for this module.'];
                }

                // Optional: verify purpose
                $purpose = (string)($data['del_purpose'] ?? '');
                if ($expectedPurpose !== '' && $purpose !== $expectedPurpose) {
                    return ['valid' => false, 'error' => 'Delegation token purpose mismatch.'];
                }

                return [
                    'valid' => true,
                    'identity_email' => (string)($data['del_email'] ?? ''),
                    'from_module' => $fromModule,
                    'to_module' => $toModule,
                    'tenant_id' => (string)($data['del_tenant'] ?? ''),
                    'purpose' => $purpose,
                ];
            } catch (\Throwable $e) {
                return ['valid' => false, 'error' => 'Token validation failed: ' . $e->getMessage()];
            }
        }, 1000, ['first'], $kernelCapabilityMeta('kernel.auth.validate_delegate@1', ['schema' => [
            'input' => [
                'type' => 'object',
                'required' => ['delegation_token'],
                'properties' => [
                    'delegation_token' => ['type' => 'string'],
                    'expected_module' => ['type' => 'string'],
                    'expected_purpose' => ['type' => 'string'],
                ],
            ],
            'output' => [
                'type' => 'object',
                'required' => ['valid'],
                'properties' => [
                    'valid' => ['type' => 'boolean'],
                    'identity_email' => ['type' => 'string'],
                    'from_module' => ['type' => 'string'],
                    'tenant_id' => ['type' => 'string'],
                    'error' => ['type' => 'string'],
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
                $hasEmailColumn = function_exists('kernelUsersHasEmailColumn')
                    ? kernelUsersHasEmailColumn($this->db())
                    : false;
                $stmt = $this->db()->prepare(
                    $hasEmailColumn
                        ? "SELECT id, username, email, password_hash, full_name, role\n                     FROM users\n                     WHERE username = :username AND is_active = 1\n                     LIMIT 1"
                        : "SELECT id, username, password_hash, full_name, role\n                     FROM users\n                     WHERE username = :username AND is_active = 1\n                     LIMIT 1"
                );
                $stmt->execute([':username' => $username]);
                $row = $stmt->fetch(\PDO::FETCH_ASSOC);
                if (!is_array($row) || !in_array(($row['role'] ?? null), ['admin', 'superadmin'], true) || !password_verify($password, (string)$row['password_hash'])) {
                    return null;
                }
                if (!$hasEmailColumn) {
                    $row['email'] = '';
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

        // Freeze registries to prevent mid-request mutations
        if ($this->entitySourceRegistry !== null) {
            $this->entitySourceRegistry->freeze();
        }

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
     * Multi-step workflow engine — runs ordered capability steps with retry,
     * idempotency, event-triggered auto-start, and cancellation.
     */
    public function workflowEngine(): WorkflowEngine
    {
        if ($this->workflowEngine === null) {
            $this->workflowEngine = new WorkflowEngine($this);
        }
        return $this->workflowEngine;
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
     * Get the full auth source→table map (built-in defaults + module-registered).
     *
     * @return array<string, string>
     */
    public function getAuthTableMap(): array
    {
        return $this->authTableMap;
    }

    /**
     * Seed the auth table map from kernel config.
     * Call during boot() after config is loaded to merge additional
     * entries from config/auth.php auth_table_map without replacing
     * module-registered entries.
     */
    public function seedAuthTableMapFromConfig(): void
    {
        $configMap = $this->config('auth.auth_table_map', []);
        if (!is_array($configMap)) {
            return;
        }
        foreach ($configMap as $source => $tableName) {
            $source = trim((string)$source);
            $tableName = trim((string)$tableName);
            if ($source !== '' && $tableName !== ''
                && preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $tableName)
                && !isset($this->authTableMap[$source])) {
                $this->authTableMap[$source] = $tableName;
            }
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
     * Get the entity view resolver — resolves DiSyL source/view declarations to data.
     */
    public function entityViews(): EntityViewResolver
    {
        if ($this->entityViewResolver === null) {
            $this->entityViewResolver = EntityViewResolver::getInstance();
        }
        return $this->entityViewResolver;
    }

    /**
     * Get the slot registry — manages module-contributed theme slot content.
     */
    public function slotRegistry(): SlotRegistry
    {
        return SlotRegistry::getInstance();
    }

    /**
     * Get the entity renderer — renders resolved entity data to HTML.
     */
    public function entitySources(): EntitySourceRegistry
    {
        if ($this->entitySourceRegistry === null) {
            $this->entitySourceRegistry = new EntitySourceRegistry();
        }
        return $this->entitySourceRegistry;
    }

    public function entityRenderers(): EntityRendererInterface
    {
        if ($this->entityRenderer === null) {
            $this->entityRenderer = new DefaultEntityRenderer($this->entityCellRenderers());
        }
        return $this->entityRenderer;
    }

    /**
     * Get the cell renderer registry — modules register custom cell renderers here.
     */
    public function entityCellRenderers(): CellRendererRegistryInterface
    {
        if ($this->entityCellRendererRegistry === null) {
            $this->entityCellRendererRegistry = new CellRendererRegistry();
        }
        return $this->entityCellRendererRegistry;
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

            // Register ARK Workbench component namespace
            $workbenchComponents = STORAGE_PATH . '/application-profiles/ark-workbench/components';
            if (is_dir($workbenchComponents)) {
                $this->templateEngine->addComponentDirectory('workbench', $workbenchComponents);
            }
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
        return \Ikabud\Kernel\Http\CsrfManager::token();
    }

    /**
     * Rotate the CSRF token and optionally regenerate the session identifier.
     */
    public function csrfRotate(bool $regenerateSessionId = false): string
    {
        return \Ikabud\Kernel\Http\CsrfManager::rotate($regenerateSessionId);
    }

    /**
     * Generate a CSRF hidden input field.
     */
    public function csrfField(): string
    {
        return \Ikabud\Kernel\Http\CsrfManager::field();
    }

    /**
     * Enforce CSRF token validation on the current request.
     * Call this at the top of any state-mutating handler.
     */
    public function csrfEnforce(): void
    {
        \Ikabud\Kernel\Http\CsrfManager::enforce(function (array $data): void {
            $this->json($data, 419);
        });
    }

    /**
     * Rotate the auth cookie JWT after a successful authenticated request.
     *
     * Implements sliding expiration: when the current token is more than halfway
     * through its lifetime, issue a fresh token with a new expiry. This limits
     * the window for stolen token reuse without requiring per-request rotation.
     *
     * Call this once per request, after the response body has been sent
     * (or just before sending), typically from public/index.php.
     */
    public function rotateAuthCookieIfNeeded(): void
    {
        if ($this->tokenRotated || $this->tokenToRotate === null || $this->rotateCookieName === null) {
            return;
        }

        // Only rotate if token is more than halfway through its lifetime.
        // This avoids the cost of rotation on every single request while
        // still limiting the effective stolen-token window.
        try {
            $payload = $this->jwt()->verify($this->tokenToRotate);
            if ($payload === null) {
                return;
            }

            $now = time();
            $iat = (int)($payload['iat'] ?? 0);
            $exp = (int)($payload['exp'] ?? 0);
            $lifetime = $exp - $iat;

            if ($lifetime <= 0) {
                return;
            }

            $halfLife = $iat + (int)($lifetime / 2);
            if ($now < $halfLife) {
                return; // Not yet halfway — skip rotation this request
            }

            // Remove old timestamps and issue a fresh token
            unset($payload['iat'], $payload['exp'], $payload['nbf'], $payload['jti']);
            $newToken = $this->jwt()->generate($payload);

            $cookieParams = $this->authCookieParams();
            $expiry = time() + (int)($this->config('app.jwt.expiration', 86400));

            // Use the 'none' hack to set HttpOnly cookie from PHP
            if (!headers_sent()) {
                setcookie(
                    $this->rotateCookieName,
                    $newToken,
                    [
                        'expires' => $expiry,
                        'path' => '/',
                        'domain' => $cookieParams['domain'] ?? '',
                        'secure' => $cookieParams['secure'] ?? true,
                        'httponly' => true,
                        'samesite' => $cookieParams['samesite'] ?? 'Lax',
                    ]
                );
            }
        } catch (\Throwable $e) {
            // Non-fatal: if rotation fails, the existing token remains valid
            // until its natural expiry.
        }

        $this->tokenRotated = true;
    }

    /**
     * Resolve auth cookie parameters (secure flag, domain, samesite).
     *
     * @return array{secure: bool, domain: string, samesite: string}
     */
    public function authCookieParams(): array
    {
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (int)($_SERVER['SERVER_PORT'] ?? 80) === 443;

        return [
            'secure' => $isHttps,
            'domain' => (string)($this->config('app.cookie.domain', '')),
            'samesite' => (string)($this->config('app.cookie.samesite', 'Lax')),
        ];
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
        return \Ikabud\Kernel\Http\HtmxContext::isRequest();
    }

    /**
     * Check if current request is an HTMX boosted navigation (hx-boost).
     * Boosted requests replace the full <body> so they need the complete layout.
     */
    public function isHtmxBoosted(): bool
    {
        return \Ikabud\Kernel\Http\HtmxContext::isBoosted();
    }

    /**
     * Get HTMX request details
     */
    public function htmx(): array
    {
        return \Ikabud\Kernel\Http\HtmxContext::context();
    }

    /**
     * Send HTMX response headers
     */
    public function htmxResponse(array $headers = []): void
    {
        \Ikabud\Kernel\Http\HtmxContext::sendHeaders($headers);
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
            $resolveVerifiedUser = function (string $token): ?array {
                try {
                    $candidateUser = $this->jwt()->verify($token);

                    // Multi-tenant JWT cross-validation: reject tokens issued for a
                    // different tenant. Skipped when multi-tenancy is disabled.
                    if ($candidateUser !== null && ($this->config['app']['multi_tenant']['enabled'] ?? false)) {
                        $jwtTid = $candidateUser['tenant_id'] ?? null;
                        $curTid = $this->tenant()->current();
                        if ($jwtTid !== null && $curTid !== null && (int) $jwtTid !== $curTid) {
                            return null;
                        }
                    }

                    // token_version check: reject tokens issued before the last password change.
                    // Applies to all authenticated sources (kernel + module users).
                    if ($candidateUser !== null && isset($candidateUser['token_version'])) {
                        $userId = (int)($candidateUser['id'] ?? 0);
                        $source = $candidateUser['source'] ?? 'kernel';
                        if ($userId > 0) {
                            $userTable = $this->authTableMap[$source] ?? null;
                            if ($userTable !== null) {
                                try {
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

                                    if ($tokenVersionCache[$cacheKey] !== (int)$candidateUser['token_version']) {
                                        return null;
                                    }
                                } catch (\Throwable $ignored) {
                                    // Non-fatal: column may not exist yet (pre-migration). Continue.
                                }
                            }
                        }
                    }

                    return $candidateUser;
                } catch (\Throwable $e) {
                    return null;
                }
            };

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

            foreach ($cookieCandidates as $cName) {
                $candidate = $_COOKIE[$cName] ?? null;
                if (is_string($candidate) && $candidate !== '') {
                    $resolvedUser = $resolveVerifiedUser($candidate);
                    if ($resolvedUser !== null) {
                        $this->currentUser = $resolvedUser;
                        // Track for sliding refresh: rotate on every authenticated request
                        // when the token is more than halfway through its lifetime.
                        $this->tokenToRotate = $candidate;
                        $this->rotateCookieName = $cName;
                        return $this->currentUser;
                    }
                }
            }
            
            // Then try Authorization header (for API requests)
            $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
            if (preg_match('/Bearer\s+(.+)$/i', $authHeader, $matches)) {
                $resolvedUser = $resolveVerifiedUser($matches[1]);
                if ($resolvedUser !== null) {
                    $this->currentUser = $resolvedUser;
                    return $this->currentUser;
                }
            }

            return null;
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
     * Check if the current request prefers JSON (API route).
     */
    public function wantsJson(): bool
    {
        return $this->isApiRequest();
    }

    /**
     * Create a Paginator for the current request.
     *
     * @param int $total    Total item count
     * @param int $perPage  Items per page (default 20, max 100)
     * @return \Ikabud\Kernel\Http\Paginator
     */
    public function paginate(int $total, int $perPage = 20): \Ikabud\Kernel\Http\Paginator
    {
        $page = (int)($this->input('page') ?? 1);
        return new \Ikabud\Kernel\Http\Paginator($total, $perPage, $page);
    }

    /**
     * Require authentication
     * Returns 401 JSON for API routes, redirects to /login for web routes.
     */
    public function requireAuth(): array
    {
        $user = $this->user();

        if (!$user) {
            if ($this->isApiRequest()) {
                $this->json(['ok' => false, 'error' => 'Unauthorized'], 401);
            }
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
            if ($this->isApiRequest()) {
                $this->json(['ok' => false, 'error' => 'Forbidden', 'required_role' => $role], 403);
            }
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
            if ($this->isApiRequest()) {
                $this->json(['ok' => false, 'error' => 'Forbidden'], 403);
            }
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
     * Check whether the current request is an API (JSON) request.
     */
    private function isApiRequest(): bool
    {
        if (function_exists('kernel_is_api_request')) {
            return kernel_is_api_request();
        }
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        return \Ikabud\Kernel\Http\RequestContext::matchIsApiRoute($uri);
    }

    /**
     * Send JSON response
     */
    public function json(array $data, int $status = 200): void
    {
        // Auto-include request_id in error responses (status >= 400)
        if ($status >= 400 && !isset($data['request_id']) && function_exists('request_id')) {
            $data['request_id'] = request_id();
        }
        http_response_code($status);
        header('Content-Type: application/json');
        // Always emit X-Request-Id header for correlation
        if (function_exists('request_id') && ($id = request_id())) {
            header('X-Request-Id: ' . $id);
        }
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
        return \Ikabud\Kernel\Http\Input::get($key, $default);
    }

    /**
     * Recursively sanitize input: strip null bytes, enforce depth limit.
     */
    private static function sanitizeInput(mixed $data, int $depth = 0): mixed
    {
        return \Ikabud\Kernel\Http\Input::sanitize($data, $depth);
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
