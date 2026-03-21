<?php

declare(strict_types=1);

namespace Ikabud\Kernel;

use PDO;
use Throwable;

final class WorkflowRuntime
{
    public function __construct(private readonly App $app)
    {
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
        return ['capabilities' => [
            'workflow.state.get@1' => ['allow_callers' => ['cms', 'guidance', 'workflow', 'kernel']],
            'workflow.transition@1' => ['allow_callers' => ['cms', 'guidance', 'workflow', 'kernel']],
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

    public function ensureDefinition(string $workflowKey, string $module, string $entityType, string $initialState, array $states, array $transitions): void
    {
        try {
            $stmt = $this->app->db()->prepare(
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
        } catch (Throwable $e) {
            $this->log('workflow definition seed failed', [
                'workflow_key' => $workflowKey,
                'module' => $module,
                'entity_type' => $entityType,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function getDefinition(string $workflowKey, string $module, string $entityType): ?array
    {
        try {
            $stmt = $this->app->db()->prepare('SELECT * FROM workflow_definitions WHERE workflow_key = :wk AND module = :m AND entity_type = :et AND is_active = 1 LIMIT 1');
            $stmt->execute([':wk' => $workflowKey, ':m' => $module, ':et' => $entityType]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return is_array($row) ? $row : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    public function allowedActions(array $definition, string $state, ?string $role): array
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

    public function getOrCreateInstance(string $workflowKey, string $module, string $entityType, string $entityId, string $defaultState): ?array
    {
        try {
            $db = $this->app->db();
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
        } catch (Throwable $e) {
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
        foreach ($this->allowedActions($definition, $from, $caller['role']) as $allowedAction) {
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

            $db->prepare('UPDATE workflow_instances SET state = :st, updated_at = NOW() WHERE id = :id')
                ->execute([':st' => $to, ':id' => (int)$instance['id']]);

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
            return ['ok' => false, 'error' => 'Database error'];
        }

        if (function_exists('kernelEmitEvent')) {
            kernelEmitEvent('workflow.transitioned', [
                'workflow_key' => $workflowKey,
                'module' => $module,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'from_state' => $from,
                'to_state' => $to,
                'action' => $action,
            ], 'kernel');
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

    private function log(string $message, array $context = []): void
    {
        if (function_exists('write_log')) {
            write_log($message, 'warning', $context);
        }
    }
}
