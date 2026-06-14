<?php

declare(strict_types=1);

namespace Ikabud\Kernel;

use PDO;
use Throwable;

final class WorkflowRuntime
{
    /**
     * Modules that have registered themselves as workflow callers.
     * @var array<string, true>
     */
    private array $registeredCallers = [
        'cms' => true,
        'guidance' => true,
        'workflow' => true,
        'kernel' => true,
    ];

    public function __construct(private readonly App $app)
    {
    }

    /**
     * Register a module as an allowed workflow caller.
     *
     * Modules call this during bootstrap to gain access to workflow
     * capabilities without requiring a code change in WorkflowRuntime.
     */
    public function registerCaller(string $moduleId): void
    {
        $moduleId = trim($moduleId);
        if ($moduleId !== '') {
            $this->registeredCallers[$moduleId] = true;
        }
    }

    public function declaredEvents(): array
    {
        return [[
            'key' => 'workflow.transitioned',
            'description' => 'Workflow transitioned',
            'available_vars' => ['workflow_key', 'module', 'entity_type', 'entity_id', 'from_state', 'to_state', 'action'],
        ]];
    }

    public function capabilityPolicy(): array
    {
        $callers = array_keys($this->registeredCallers);
        return ['capabilities' => [
            'workflow.state.get@1' => ['allow_callers' => $callers],
            'workflow.transition@1' => ['allow_callers' => $callers],
        ]];
    }

    public function stateSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['workflow_key', 'module', 'entity_type', 'entity_id'],
            'properties' => [
                'workflow_key' => ['type' => 'string'],
                'module' => ['type' => 'string'],
                'entity_type' => ['type' => 'string'],
                'entity_id' => ['type' => 'string'],
            ],
        ];
    }

    public function transitionSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['workflow_key', 'module', 'entity_type', 'entity_id', 'action'],
            'properties' => [
                'workflow_key' => ['type' => 'string'],
                'module' => ['type' => 'string'],
                'entity_type' => ['type' => 'string'],
                'entity_id' => ['type' => 'string'],
                'action' => ['type' => 'string'],
                'actor_user_id' => ['type' => 'integer'],
                'meta' => ['type' => 'object'],
            ],
        ];
    }

    public function ensureCmsContentWorkflow(): void
    {
        $this->ensureDefinition('cms.content', 'cms', 'cms_content', 'draft', [
            ['key' => 'draft', 'label' => 'Draft'],
            ['key' => 'review', 'label' => 'In Review'],
            ['key' => 'approved', 'label' => 'Approved'],
            ['key' => 'published', 'label' => 'Published'],
        ], [
            ['from' => 'draft', 'action' => 'submit', 'to' => 'review', 'roles' => ['contributor', 'author', 'editor', 'administrator', 'superadmin']],
            ['from' => 'review', 'action' => 'approve', 'to' => 'approved', 'roles' => ['editor', 'administrator', 'superadmin']],
            ['from' => 'approved', 'action' => 'publish', 'to' => 'published', 'roles' => ['author', 'editor', 'administrator', 'superadmin']],
            ['from' => 'review', 'action' => 'reject', 'to' => 'draft', 'roles' => ['editor', 'administrator', 'superadmin']],
            ['from' => 'approved', 'action' => 'unapprove', 'to' => 'review', 'roles' => ['editor', 'administrator', 'superadmin']],
        ]);
    }

    private function definitionSyncTtl(): int
    {
        return max(0, (int)($_ENV['WORKFLOW_DEFINITION_SYNC_TTL'] ?? 300));
    }

    private function definitionSyncInstance(): string
    {
        $tenantId = $this->app->tenant()->current();
        return 'workflow_definition_seed_t' . ($tenantId ?? 0);
    }

    private function definitionSyncKey(string $workflowKey, string $module, string $entityType, string $initialState, array $states, array $transitions): string
    {
        return 'definition:' . sha1(json_encode([
            'workflow_key' => $workflowKey,
            'module' => $module,
            'entity_type' => $entityType,
            'initial_state' => $initialState,
            'states' => $states,
            'transitions' => $transitions,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    public function ensureDefinition(string $workflowKey, string $module, string $entityType, string $initialState, array $states, array $transitions): void
    {
        $syncTtl = $this->definitionSyncTtl();
        $syncKey = $this->definitionSyncKey($workflowKey, $module, $entityType, $initialState, $states, $transitions);
        if ($syncTtl > 0 && $this->app->cache()->get($this->definitionSyncInstance(), $syncKey)) {
            return;
        }

        $context = [
            'workflow_key' => $workflowKey,
            'module' => $module,
            'entity_type' => $entityType,
        ];

        try {
            $this->runPrimaryDbOperation(static function (PDO $db) use ($workflowKey, $module, $entityType, $initialState, $states, $transitions): void {
                $stmt = $db->prepare(
                    'INSERT INTO workflow_definitions (workflow_key, module, entity_type, initial_state, states_json, transitions_json, is_active, created_at) '
                    . 'VALUES (:wk, :m, :et, :init, :states, :trans, 1, NOW()) '
                    . 'ON DUPLICATE KEY UPDATE initial_state = VALUES(initial_state), states_json = VALUES(states_json), transitions_json = VALUES(transitions_json), is_active = 1, updated_at = NOW()'
                );
                $stmt->execute([
                    ':wk' => $workflowKey,
                    ':m' => $module,
                    ':et' => $entityType,
                    ':init' => $initialState,
                    ':states' => json_encode($states),
                    ':trans' => json_encode($transitions),
                ]);
            }, 'ensure_definition', $context);

            if ($syncTtl > 0) {
                $this->app->cache()->set($this->definitionSyncInstance(), $syncKey, ['synced' => true], $syncTtl);
            }
        } catch (Throwable $e) {
            $this->logDbFailure('workflow definition seed failed', 'ensure_definition', $e, $context);
        }
    }

    public function getDefinition(string $workflowKey, string $module, string $entityType): ?array
    {
        try {
            return $this->runPrimaryDbOperation(static function (PDO $db) use ($workflowKey, $module, $entityType): ?array {
                $stmt = $db->prepare('SELECT * FROM workflow_definitions WHERE workflow_key = :wk AND module = :m AND entity_type = :et AND is_active = 1 LIMIT 1');
                $stmt->execute([':wk' => $workflowKey, ':m' => $module, ':et' => $entityType]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                return is_array($row) ? $row : null;
            }, 'get_definition', [
                'workflow_key' => $workflowKey,
                'module' => $module,
                'entity_type' => $entityType,
            ]);
        } catch (Throwable $e) {
            $this->logDbFailure('workflow definition lookup failed', 'get_definition', $e, [
                'workflow_key' => $workflowKey,
                'module' => $module,
                'entity_type' => $entityType,
            ]);
            return null;
        }
    }

    public function allowedActions(array $definition, string $state, ?string $role, array $guardContext = []): array
    {
        $decoded = json_decode((string)($definition['transitions_json'] ?? '[]'), true);
        if (!is_array($decoded)) {
            return [];
        }

        $out = [];
        $seen = [];
        foreach ($decoded as $transition) {
            if (!is_array($transition) || (string)($transition['from'] ?? '') !== $state) {
                continue;
            }
            $roles = is_array($transition['roles'] ?? null) ? $transition['roles'] : [];
            if ($role !== null && $roles !== [] && !in_array($role, $roles, true)) {
                continue;
            }

            // 4.7: Evaluate guard if present
            if (!$this->evaluateGuard($transition, $guardContext)) {
                continue;
            }

            $action = [
                'action' => (string)($transition['action'] ?? ''),
                'to' => (string)($transition['to'] ?? ''),
                'label' => ucfirst((string)($transition['action'] ?? '')),
            ];
            $key = $action['action'] . '|' . $action['to'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $action;
        }

        return $out;
    }

    /**
     * Evaluate a guard condition on a transition.
     *
     * Guard types:
     *  - callable: 'guard' => callable — invoked with ($guardContext), must return truthy
     *  - string:   'guard' => 'functionName' — resolved as global function name
     *  - array:    'guard' => ['field' => 'status', 'operator' => 'eq', 'value' => 'paid']
     *              Operators: eq, neq, in, not_in, gt, gte, lt, lte, empty, not_empty
     *
     * No guard key = always passes.
     */
    private function evaluateGuard(array $transition, array $context): bool
    {
        if (!array_key_exists('guard', $transition)) {
            return true;
        }

        $guard = $transition['guard'];

        // Callable guard
        if (is_callable($guard)) {
            try {
                return (bool)$guard($context);
            } catch (Throwable $e) {
                $this->log('workflow guard callable threw: ' . $e->getMessage());
                return false;
            }
        }

        // String guard — global function name
        if (is_string($guard) && $guard !== '') {
            if (function_exists($guard)) {
                try {
                    return (bool)$guard($context);
                } catch (Throwable $e) {
                    $this->log('workflow guard function threw: ' . $e->getMessage());
                    return false;
                }
            }
            // Unknown function — fail closed
            return false;
        }

        // Declarative guard: ['field' => ..., 'operator' => ..., 'value' => ...]
        if (is_array($guard)) {
            return $this->evaluateDeclarativeGuard($guard, $context);
        }

        return true;
    }

    private function evaluateDeclarativeGuard(array $guard, array $context): bool
    {
        $field = (string)($guard['field'] ?? '');
        if ($field === '') {
            return true;
        }

        $actual = $context[$field] ?? null;
        $expected = $guard['value'] ?? null;
        $operator = strtolower(trim((string)($guard['operator'] ?? 'eq')));

        return match ($operator) {
            'eq' => $actual == $expected,
            'neq', 'ne' => $actual != $expected,
            'in' => is_array($expected) && in_array($actual, $expected, false),
            'not_in' => is_array($expected) && !in_array($actual, $expected, false),
            'gt' => $actual > $expected,
            'gte', 'ge' => $actual >= $expected,
            'lt' => $actual < $expected,
            'lte', 'le' => $actual <= $expected,
            'empty' => empty($actual),
            'not_empty' => !empty($actual),
            default => true,
        };
    }

    public function getOrCreateInstance(string $workflowKey, string $module, string $entityType, string $entityId, string $defaultState): ?array
    {
        try {
            return $this->runPrimaryDbOperation(static function (PDO $db) use ($workflowKey, $module, $entityType, $entityId, $defaultState): ?array {
                $stmt = $db->prepare('SELECT * FROM workflow_instances WHERE workflow_key = :wk AND module = :m AND entity_type = :et AND entity_id = :eid LIMIT 1');
                $args = [':wk' => $workflowKey, ':m' => $module, ':et' => $entityType, ':eid' => $entityId];
                $stmt->execute($args);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if (is_array($row)) {
                    return $row;
                }

                try {
                    $db->prepare('INSERT INTO workflow_instances (workflow_key, module, entity_type, entity_id, state, created_at) VALUES (:wk, :m, :et, :eid, :st, NOW())')
                        ->execute($args + [':st' => $defaultState]);
                } catch (Throwable $e) {
                }

                $stmt->execute($args);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                return is_array($row) ? $row : null;
            }, 'get_or_create_instance', [
                'workflow_key' => $workflowKey,
                'module' => $module,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
            ]);
        } catch (Throwable $e) {
            $this->logDbFailure('workflow instance lookup failed', 'get_or_create_instance', $e, [
                'workflow_key' => $workflowKey,
                'module' => $module,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
            ]);
            return null;
        }
    }

    public function stateGet(mixed $payload): array
    {
        if (!is_array($payload)) {
            return ['ok' => false, 'error' => 'Invalid payload'];
        }

        $workflowKey = trim((string)($payload['workflow_key'] ?? ''));
        $module = trim((string)($payload['module'] ?? ''));
        $entityType = trim((string)($payload['entity_type'] ?? ''));
        $entityId = trim((string)($payload['entity_id'] ?? ''));
        if ($workflowKey === '' || $module === '' || $entityType === '' || $entityId === '') {
            return ['ok' => false, 'error' => 'workflow_key, module, entity_type, entity_id are required'];
        }

        $definition = $this->getDefinition($workflowKey, $module, $entityType);
        if (!$definition) {
            return ['ok' => false, 'error' => 'Workflow definition not found'];
        }

        $instance = $this->getOrCreateInstance($workflowKey, $module, $entityType, $entityId, (string)($definition['initial_state'] ?? 'draft'));
        if (!$instance) {
            return ['ok' => false, 'error' => 'Workflow instance not available'];
        }

        $caller = $this->resolveCaller();
        return [
            'ok' => true,
            'workflow' => [
                'workflow_key' => $workflowKey,
                'module' => $module,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'state' => (string)($instance['state'] ?? ''),
                'allowed_actions' => $this->allowedActions($definition, (string)($instance['state'] ?? ''), $caller['role']),
            ],
        ];
    }

    public function transition(mixed $payload): array
    {
        if (!is_array($payload)) {
            return ['ok' => false, 'error' => 'Invalid payload'];
        }

        $workflowKey = trim((string)($payload['workflow_key'] ?? ''));
        $module = trim((string)($payload['module'] ?? ''));
        $entityType = trim((string)($payload['entity_type'] ?? ''));
        $entityId = trim((string)($payload['entity_id'] ?? ''));
        $action = trim((string)($payload['action'] ?? ''));
        if ($workflowKey === '' || $module === '' || $entityType === '' || $entityId === '' || $action === '') {
            return ['ok' => false, 'error' => 'workflow_key, module, entity_type, entity_id, action are required'];
        }

        $definition = $this->getDefinition($workflowKey, $module, $entityType);
        if (!$definition) {
            return ['ok' => false, 'error' => 'Workflow definition not found'];
        }

        $instance = $this->getOrCreateInstance($workflowKey, $module, $entityType, $entityId, (string)($definition['initial_state'] ?? 'draft'));
        if (!$instance) {
            return ['ok' => false, 'error' => 'Workflow instance not available'];
        }

        $caller = $this->resolveCaller($payload);
        $from = (string)($instance['state'] ?? '');
        $to = null;

        // Build guard context from payload + entity state
        $guardContext = is_array($payload['guard_context'] ?? null) ? $payload['guard_context'] : [];
        $guardContext['_from'] = $from;
        $guardContext['_action'] = $action;
        $guardContext['_entity_type'] = $entityType;
        $guardContext['_entity_id'] = $entityId;
        $guardContext['_module'] = $module;
        $guardContext['_caller'] = $caller;

        foreach ($this->allowedActions($definition, $from, $caller['role'], $guardContext) as $allowedAction) {
            if ((string)($allowedAction['action'] ?? '') === $action) {
                $to = (string)($allowedAction['to'] ?? '');
                break;
            }
        }
        if ($to === null || $to === '') {
            return ['ok' => false, 'error' => 'Action not allowed'];
        }

        $db = $this->app->db();
        $startedTransaction = false;
        try {
            if (!$db->inTransaction()) {
                $db->beginTransaction();
                $startedTransaction = true;
            }

            // Conditional UPDATE: only transition if current state matches expected.
            // Prevents lost updates from concurrent transitions on the same instance.
            $updateStmt = $db->prepare(
                'UPDATE workflow_instances SET state = :to, updated_at = NOW() WHERE id = :id AND state = :from'
            );
            $updateStmt->execute([
                ':to'   => $to,
                ':id'   => (int)$instance['id'],
                ':from' => $from,
            ]);

            if ($updateStmt->rowCount() === 0) {
                // State changed concurrently — another transition already committed.
                if ($startedTransaction) {
                    $db->rollBack();
                }
                return ['ok' => false, 'error' => 'State changed concurrently. Please retry.'];
            }

            $metaJson = is_array($payload['meta'] ?? null) ? json_encode($payload['meta']) : null;
            $db->prepare('INSERT INTO workflow_transition_logs (instance_id, action, from_state, to_state, actor_user_id, meta_json, created_at) VALUES (:iid, :action, :from, :to, :actor, :meta, NOW())')
                ->execute([
                    ':iid' => (int)$instance['id'],
                    ':action' => $action,
                    ':from' => $from,
                    ':to' => $to,
                    ':actor' => $caller['actor_id'],
                    ':meta' => $metaJson,
                ]);

            if ($startedTransaction) {
                $db->commit();
            }
        } catch (Throwable $e) {
            if ($startedTransaction && $db->inTransaction()) {
                $db->rollBack();
            }

            $context = [
                'workflow_key' => $workflowKey,
                'module' => $module,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'instance_id' => (int)($instance['id'] ?? 0),
                'action' => $action,
                'from_state' => $from,
                'to_state' => $to,
            ];
            $this->logDbFailure('workflow transition failed', 'transition', $e, $context);

            if (function_exists('dbConnectionLost') && dbConnectionLost($e)) {
                try {
                    $this->app->reconnectDb();
                    $this->log('workflow database reconnected after transition failure', $this->dbLogContext('transition', $context));
                } catch (Throwable $reconnectError) {
                    $this->logDbFailure('workflow reconnect failed after transition failure', 'transition_reconnect', $reconnectError, $context);
                }
            }

            return ['ok' => false, 'error' => 'Database error'];
        }

        if (function_exists('kernelEmitEvent')) {
            $eventPayload = [
                'workflow_key' => $workflowKey,
                'module' => $module,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'from_state' => $from,
                'to_state' => $to,
                'action' => $action,
            ];
            if (is_array($payload['meta'] ?? null)) {
                $eventPayload['meta'] = $payload['meta'];
            }
            kernelEmitEvent('workflow.transitioned', $eventPayload, 'kernel');
        }

        return [
            'ok' => true,
            'from_state' => $from,
            'to_state' => $to,
            'action' => $action,
        ];
    }

    private function resolveCaller(array $payload = []): array
    {
        $context = function_exists('capability_call_context') ? capability_call_context() : null;
        $user = is_array($context['user'] ?? null) ? $context['user'] : $this->app->user();
        $role = is_array($user) ? trim((string)($user['role'] ?? '')) : '';
        $actorId = isset($payload['actor_user_id']) ? (int)$payload['actor_user_id'] : (is_array($user) ? (int)($user['id'] ?? $user['sub'] ?? 0) : 0);

        return [
            'role' => $role !== '' ? $role : null,
            'actor_id' => $actorId > 0 ? $actorId : null,
        ];
    }

    private function runPrimaryDbOperation(callable $operation, string $phase, array $context = [], bool $retryOnDisconnect = true): mixed
    {
        $attempts = $retryOnDisconnect ? 2 : 1;
        $lastError = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                return $operation($this->app->db());
            } catch (Throwable $e) {
                $lastError = $e;
                $lostConnection = function_exists('dbConnectionLost') && dbConnectionLost($e);
                if (!$retryOnDisconnect || !$lostConnection || $attempt >= $attempts) {
                    break;
                }

                $this->log('workflow database reconnect retry scheduled', $this->dbLogContext($phase, $context + ['attempt' => $attempt], $e));

                try {
                    $this->app->reconnectDb();
                } catch (Throwable $reconnectError) {
                    $this->logDbFailure('workflow database reconnect failed', $phase . '_reconnect', $reconnectError, $context + ['attempt' => $attempt]);
                    $lastError = $reconnectError;
                    break;
                }
            }
        }

        if ($lastError instanceof Throwable) {
            throw $lastError;
        }

        throw new \RuntimeException('Workflow database operation failed');
    }

    private function dbLogContext(string $phase, array $context = [], ?Throwable $e = null): array
    {
        $logContext = [
            'phase' => $phase,
            'tenant_id' => $this->app->tenant()->current(),
        ];

        if (function_exists('request_id')) {
            $logContext['request_id'] = request_id();
        }

        if ($e !== null) {
            $logContext['error'] = $e->getMessage();
            $logContext['exception'] = $e::class;
            if (function_exists('dbConnectionLost')) {
                $logContext['db_connection_lost'] = dbConnectionLost($e);
            }
        }

        return array_merge($logContext, $context);
    }

    private function logDbFailure(string $message, string $phase, Throwable $e, array $context = []): void
    {
        $this->log($message, $this->dbLogContext($phase, $context, $e));
    }

    private function log(string $message, array $context = []): void
    {
        if (function_exists('write_log')) {
            write_log($message, 'warning', $context);
        }
    }
}
