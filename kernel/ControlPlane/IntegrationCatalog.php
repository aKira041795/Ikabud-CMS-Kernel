<?php

declare(strict_types=1);

namespace Ikabud\Kernel\ControlPlane;

use Ikabud\Kernel\Capabilities\CapabilityCatalog;
use PDO;
use Throwable;

final class IntegrationCatalog
{
    private PDO $db;
    private CapabilityCatalog $capabilities;
    private const DEFAULT_EXECUTION_LIMIT = 100;
    private const DEFAULT_TIMELINE_LIMIT = 10;
    private const DEFAULT_TIMELINE_EXECUTION_PREVIEW_LIMIT = 5;

    /** @var array<string, mixed>|null */
    private ?array $built = null;

    public function __construct(?PDO $db = null, ?CapabilityCatalog $capabilities = null)
    {
        $this->db = $db ?? app()->db();
        $this->capabilities = $capabilities ?? new CapabilityCatalog(app()->capabilities());
    }

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        $this->build();

        return [
            'summary' => $this->summary(),
            'events' => $this->events(),
            'triggers' => $this->triggers(),
            'integrations' => $this->integrations(),
            'logs' => $this->logs(),
            'executions' => $this->executions(),
            'timelines' => $this->timelines(),
        ];
    }

    /** @return array<string, int> */
    public function summary(): array
    {
        $this->build();
        return $this->built['summary'] ?? [];
    }

    /** @return array<int, array<string, mixed>> */
    public function events(): array
    {
        $this->build();
        return $this->built['events'] ?? [];
    }

    /** @return array<int, array<string, mixed>> */
    public function triggers(): array
    {
        $this->build();
        return $this->built['triggers'] ?? [];
    }

    /** @return array<int, array<string, mixed>> */
    public function integrations(): array
    {
        $this->build();
        return $this->built['integrations'] ?? [];
    }

    /** @return array<int, array<string, mixed>> */
    public function logs(): array
    {
        $this->build();
        return $this->built['logs'] ?? [];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function executions(array $filters = [], int $limit = self::DEFAULT_EXECUTION_LIMIT): array
    {
        $normalizedFilters = $this->normalizeExecutionFilters($filters);
        $normalizedLimit = $this->normalizeExecutionLimit($limit);

        if ($normalizedFilters === [] && $normalizedLimit === self::DEFAULT_EXECUTION_LIMIT) {
            $this->build();
            return $this->built['executions'] ?? [];
        }

        $rows = $this->fetchExecutionRows($normalizedFilters, $normalizedLimit);
        $triggerIndex = $this->currentTriggerIndex();
        return $this->buildExecutions($rows, $triggerIndex);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function timelines(array $filters = [], int $limit = self::DEFAULT_TIMELINE_LIMIT, int $executionLimit = self::DEFAULT_EXECUTION_LIMIT): array
    {
        $normalizedFilters = $this->normalizeExecutionFilters($filters);
        $normalizedTimelineLimit = $this->normalizeTimelineLimit($limit);
        $normalizedExecutionLimit = $this->normalizeExecutionLimit($executionLimit);

        if ($normalizedFilters === []
            && $normalizedTimelineLimit === self::DEFAULT_TIMELINE_LIMIT
            && $normalizedExecutionLimit === self::DEFAULT_EXECUTION_LIMIT) {
            $this->build();
            return $this->built['timelines'] ?? [];
        }

        $rows = $this->fetchExecutionRows($normalizedFilters, $normalizedExecutionLimit);
        $executions = $this->buildExecutions($rows, $this->currentTriggerIndex());

        return $this->buildTimelines($executions, $normalizedTimelineLimit);
    }

    private function build(): void
    {
        if ($this->built !== null) {
            return;
        }

        $rawEvents = $this->fetchAll(
            'SELECT module, event_key, description, available_vars, updated_at, created_at FROM kernel_events ORDER BY module ASC, event_key ASC'
        );
        $rawTriggers = $this->fetchAll(
            'SELECT id, module, event_key, capability_id, provider, is_enabled, priority, template, max_per_minute, retry_count, timeout_ms, meta, updated_by, updated_at, created_at '
            . 'FROM kernel_event_triggers ORDER BY module ASC, event_key ASC, priority ASC, id ASC'
        );
        $rawIntegrations = $this->fetchAll(
            'SELECT * FROM kernel_integrations ORDER BY created_at DESC, id DESC'
        );
        $rawLogs = $this->fetchAll(
            'SELECT l.*, i.name AS integration_name FROM kernel_integration_logs l '
            . 'LEFT JOIN kernel_integrations i ON i.id = l.integration_id '
            . 'ORDER BY l.created_at DESC, l.id DESC LIMIT 100'
        );
        $rawExecutions = $this->fetchExecutionRows([], self::DEFAULT_EXECUTION_LIMIT);

        $events = $this->buildEvents($rawEvents, $rawTriggers, $rawIntegrations);
        $eventsByModuleKey = [];
        $eventsByEventKey = [];
        foreach ($events as $event) {
            $moduleKey = $this->moduleEventKey((string)($event['module'] ?? ''), (string)($event['event_key'] ?? ''));
            $eventsByModuleKey[$moduleKey] = $event;
            $eventKey = (string)($event['event_key'] ?? '');
            if ($eventKey !== '' && !isset($eventsByEventKey[$eventKey])) {
                $eventsByEventKey[$eventKey] = $event;
            }
        }

        $logs = $this->buildLogs($rawLogs, $rawIntegrations);
        $logsByIntegration = [];
        foreach ($logs as $log) {
            $integrationId = (int)($log['integration_id'] ?? 0);
            if ($integrationId <= 0) {
                continue;
            }
            $logsByIntegration[$integrationId][] = $log;
        }

        $triggers = $this->buildTriggers($rawTriggers, $eventsByModuleKey);
        $integrations = $this->buildIntegrations($rawIntegrations, $eventsByEventKey, $logsByIntegration);
        $executions = $this->buildExecutions($rawExecutions, $this->indexRowsById($rawTriggers));
        $timelines = $this->buildTimelines($executions, self::DEFAULT_TIMELINE_LIMIT);
        $lastExecutionsByTrigger = [];
        foreach ($executions as $execution) {
            $triggerId = (int)($execution['trigger_id'] ?? 0);
            if ($triggerId > 0 && !isset($lastExecutionsByTrigger[$triggerId])) {
                $lastExecutionsByTrigger[$triggerId] = $execution;
            }
        }
        foreach ($triggers as &$trigger) {
            $triggerId = (int)($trigger['id'] ?? 0);
            $lastExecution = $lastExecutionsByTrigger[$triggerId] ?? null;
            $trigger['last_execution_at'] = $lastExecution['created_at'] ?? null;
            $trigger['last_execution_status'] = $lastExecution['status'] ?? null;
            $trigger['last_execution_request_id'] = $lastExecution['request_id'] ?? null;
            $trigger['last_execution_correlation_id'] = $lastExecution['correlation_id'] ?? null;
        }
        unset($trigger);

        $this->built = [
            'summary' => [
                'event_count' => count($events),
                'trigger_count' => count($triggers),
                'active_trigger_count' => count(array_filter($triggers, static fn(array $row): bool => (int)($row['is_enabled'] ?? 0) === 1)),
                'integration_count' => count($integrations),
                'active_integration_count' => count(array_filter($integrations, static fn(array $row): bool => (int)($row['is_active'] ?? 0) === 1)),
                'integration_log_count' => count($logs),
                'trigger_execution_count' => $this->countRows('SELECT COUNT(*) FROM kernel_trigger_executions'),
                'failed_trigger_execution_count' => $this->countRows("SELECT COUNT(*) FROM kernel_trigger_executions WHERE status = 'failed'"),
                'rate_limited_trigger_execution_count' => $this->countRows("SELECT COUNT(*) FROM kernel_trigger_executions WHERE status = 'rate_limited'"),
                'trace_timeline_count' => count($timelines),
                'unregistered_trigger_event_count' => count(array_filter($triggers, static fn(array $row): bool => empty($row['event_registered']))),
                'unresolved_integration_target_count' => count(array_filter($integrations, static fn(array $row): bool => empty($row['target_runtime_registered']) && empty($row['target_declared_provider_count']))),
            ],
            'events' => $events,
            'triggers' => $triggers,
            'integrations' => $integrations,
            'logs' => $logs,
            'executions' => $executions,
            'timelines' => $timelines,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rawEvents
     * @param array<int, array<string, mixed>> $rawTriggers
     * @param array<int, array<string, mixed>> $rawIntegrations
     * @return array<int, array<string, mixed>>
     */
    private function buildEvents(array $rawEvents, array $rawTriggers, array $rawIntegrations): array
    {
        $triggerCounts = [];
        foreach ($rawTriggers as $trigger) {
            $key = $this->moduleEventKey((string)($trigger['module'] ?? ''), (string)($trigger['event_key'] ?? ''));
            $triggerCounts[$key] = ($triggerCounts[$key] ?? 0) + 1;
        }

        $integrationCounts = [];
        foreach ($rawIntegrations as $integration) {
            $eventKey = trim((string)($integration['trigger_event'] ?? ''));
            if ($eventKey === '') {
                continue;
            }
            $integrationCounts[$eventKey] = ($integrationCounts[$eventKey] ?? 0) + 1;
        }

        $events = [];
        foreach ($rawEvents as $row) {
            if (!is_array($row)) {
                continue;
            }

            $module = trim((string)($row['module'] ?? ''));
            $eventKey = trim((string)($row['event_key'] ?? ''));
            if ($module === '' || $eventKey === '') {
                continue;
            }

            $key = $this->moduleEventKey($module, $eventKey);
            $events[$key] = [
                'module' => $module,
                'module_name' => $this->moduleName($module),
                'event_key' => $eventKey,
                'key' => $eventKey,
                'description' => trim((string)($row['description'] ?? '')),
                'available_vars' => $this->decodeJsonList($row['available_vars'] ?? null),
                'registered' => true,
                'created_at' => $row['created_at'] ?? null,
                'updated_at' => $row['updated_at'] ?? null,
                'trigger_count' => $triggerCounts[$key] ?? 0,
                'integration_count' => $integrationCounts[$eventKey] ?? 0,
            ];
        }

        foreach ($this->capabilities->events() as $manifestEvent) {
            if (!is_array($manifestEvent)) {
                continue;
            }

            $module = trim((string)($manifestEvent['module'] ?? ''));
            $eventKey = trim((string)($manifestEvent['key'] ?? $manifestEvent['event_key'] ?? ''));
            if ($module === '' || $eventKey === '') {
                continue;
            }

            $key = $this->moduleEventKey($module, $eventKey);
            $existing = $events[$key] ?? [];
            $events[$key] = array_merge([
                'module' => $module,
                'module_name' => (string)($manifestEvent['module_name'] ?? $this->moduleName($module)),
                'event_key' => $eventKey,
                'key' => $eventKey,
                'description' => trim((string)($manifestEvent['description'] ?? '')),
                'available_vars' => array_values(is_array($manifestEvent['available_vars'] ?? null) ? $manifestEvent['available_vars'] : []),
                'registered' => false,
                'created_at' => null,
                'updated_at' => null,
                'trigger_count' => $triggerCounts[$key] ?? 0,
                'integration_count' => $integrationCounts[$eventKey] ?? 0,
            ], $existing);
        }

        ksort($events);
        return array_values($events);
    }

    /**
     * @param array<int, array<string, mixed>> $rawTriggers
     * @param array<string, array<string, mixed>> $eventsByModuleKey
     * @return array<int, array<string, mixed>>
     */
    private function buildTriggers(array $rawTriggers, array $eventsByModuleKey): array
    {
        $out = [];
        foreach ($rawTriggers as $row) {
            if (!is_array($row)) {
                continue;
            }

            $module = trim((string)($row['module'] ?? ''));
            $eventKey = trim((string)($row['event_key'] ?? ''));
            $capabilityId = trim((string)($row['capability_id'] ?? ''));
            $event = $eventsByModuleKey[$this->moduleEventKey($module, $eventKey)] ?? null;
            $capability = $this->capabilities->inspect($capabilityId);

            $row['is_enabled'] = (int)($row['is_enabled'] ?? 0);
            $row['priority'] = (int)($row['priority'] ?? 100);
            $row['max_per_minute'] = $row['max_per_minute'] !== null ? (int)$row['max_per_minute'] : null;
            $row['retry_count'] = (int)($row['retry_count'] ?? 0);
            $row['timeout_ms'] = (int)($row['timeout_ms'] ?? 5000);
            $row['meta'] = $this->decodeJsonObject($row['meta'] ?? null);
            $row['module_name'] = $this->moduleName($module);
            $row['event_registered'] = $event !== null && !empty($event['registered']);
            $row['event_description'] = (string)($event['description'] ?? ($row['event_description'] ?? ''));
            $row['available_vars'] = array_values(is_array($event['available_vars'] ?? null) ? $event['available_vars'] : []);
            $row['resolved_capability'] = (string)($capability['id'] ?? $capabilityId);
            $row['capability_runtime_registered'] = !empty($capability['runtime_registered']);
            $row['capability_provider_count'] = (int)($capability['provider_count'] ?? 0);
            $row['capability_declared_provider_count'] = (int)($capability['declared_provider_count'] ?? 0);
            $row['capability_dependent_module_count'] = (int)($capability['dependent_module_count'] ?? 0);

            $out[] = $row;
        }

        return $out;
    }

    /**
     * @param array<int, array<string, mixed>> $rawIntegrations
     * @param array<string, array<string, mixed>> $eventsByEventKey
     * @param array<int, array<int, array<string, mixed>>> $logsByIntegration
     * @return array<int, array<string, mixed>>
     */
    private function buildIntegrations(array $rawIntegrations, array $eventsByEventKey, array $logsByIntegration): array
    {
        $out = [];
        foreach ($rawIntegrations as $row) {
            if (!is_array($row)) {
                continue;
            }

            $integrationId = (int)($row['id'] ?? 0);
            $eventKey = trim((string)($row['trigger_event'] ?? ''));
            $capabilityId = trim((string)($row['target_capability'] ?? ''));
            $event = $eventsByEventKey[$eventKey] ?? null;
            $capability = $this->capabilities->inspect($capabilityId);
            $mapping = $this->decodeJsonObject($row['mapping_json'] ?? null);
            $integrationLogs = $logsByIntegration[$integrationId] ?? [];
            $lastLog = $integrationLogs[0] ?? null;

            $row['is_active'] = (int)($row['is_active'] ?? 0);
            $row['mapping_json'] = is_array($mapping) ? json_encode($mapping, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : (string)($row['mapping_json'] ?? '');
            $row['mapping'] = $mapping;
            $row['mapping_valid_json'] = is_array($mapping);
            $row['mapping_vars'] = $this->mappingVariables($mapping);
            $row['event_registered'] = $event !== null && !empty($event['registered']);
            $row['event_module'] = $event['module'] ?? null;
            $row['event_description'] = $event['description'] ?? null;
            $row['event_available_vars'] = array_values(is_array($event['available_vars'] ?? null) ? $event['available_vars'] : []);
            $row['resolved_target_capability'] = (string)($capability['id'] ?? $capabilityId);
            $row['target_runtime_registered'] = !empty($capability['runtime_registered']);
            $row['target_provider_count'] = (int)($capability['provider_count'] ?? 0);
            $row['target_declared_provider_count'] = (int)($capability['declared_provider_count'] ?? 0);
            $row['log_count'] = count($integrationLogs);
            $row['last_log'] = $lastLog;
            $row['last_status'] = $lastLog['status'] ?? null;
            $row['last_error_message'] = $lastLog['error_message'] ?? null;

            $out[] = $row;
        }

        return $out;
    }

    /**
     * @param array<int, array<string, mixed>> $rawLogs
     * @param array<int, array<string, mixed>> $rawIntegrations
     * @return array<int, array<string, mixed>>
     */
    private function buildLogs(array $rawLogs, array $rawIntegrations): array
    {
        $integrationsById = [];
        foreach ($rawIntegrations as $integration) {
            $integrationsById[(int)($integration['id'] ?? 0)] = $integration;
        }

        $out = [];
        foreach ($rawLogs as $row) {
            if (!is_array($row)) {
                continue;
            }

            $integrationId = (int)($row['integration_id'] ?? 0);
            $integration = $integrationsById[$integrationId] ?? [];
            $row['integration_name'] = (string)($row['integration_name'] ?? ($integration['name'] ?? ''));
            $row['trigger_event'] = $integration['trigger_event'] ?? null;
            $row['target_capability'] = $integration['target_capability'] ?? null;
            $out[] = $row;
        }

        return $out;
    }

    /**
     * @param array<int, array<string, mixed>> $rawExecutions
     * @param array<int, array<string, mixed>> $triggersById
     * @return array<int, array<string, mixed>>
     */
    private function buildExecutions(array $rawExecutions, array $triggersById): array
    {
        $out = [];
        foreach ($rawExecutions as $row) {
            if (!is_array($row)) {
                continue;
            }

            $triggerId = (int)($row['trigger_id'] ?? 0);
            $capabilityId = trim((string)($row['capability_id'] ?? ''));
            $trigger = $triggersById[$triggerId] ?? null;
            $capability = $this->capabilities->inspect($capabilityId);

            $row['trigger_id'] = $triggerId > 0 ? $triggerId : null;
            $row['module_name'] = $this->moduleName((string)($row['module'] ?? ''));
            $row['duration_ms'] = $row['duration_ms'] !== null ? (int)$row['duration_ms'] : null;
            $row['event_payload'] = $this->decodeJsonMixed($row['event_payload'] ?? null);
            $row['capability_payload'] = $this->decodeJsonMixed($row['capability_payload'] ?? null);
            $row['result_payload'] = $this->decodeJsonMixed($row['result_payload'] ?? null);
            $row['resolved_capability'] = (string)($capability['id'] ?? $capabilityId);
            $row['capability_runtime_registered'] = !empty($capability['runtime_registered']);
            $row['capability_declared_provider_count'] = (int)($capability['declared_provider_count'] ?? 0);
            $row['trigger_exists'] = is_array($trigger);
            $row['trigger_enabled'] = is_array($trigger) ? (int)($trigger['is_enabled'] ?? 0) === 1 : null;
            $row['trigger_priority'] = is_array($trigger) ? (int)($trigger['priority'] ?? 100) : null;

            $out[] = $row;
        }

        return $out;
    }

    /**
     * @param array<int, array<string, mixed>> $executions
     * @return array<int, array<string, mixed>>
     */
    private function buildTimelines(array $executions, int $limit): array
    {
        $groups = [];

        foreach ($executions as $execution) {
            if (!is_array($execution)) {
                continue;
            }

            $timelineKey = $this->timelineKey($execution);
            $groupId = $timelineKey['type'] . ':' . $timelineKey['key'];
            if (!isset($groups[$groupId])) {
                $groups[$groupId] = [
                    'key_type' => $timelineKey['type'],
                    'key' => $timelineKey['key'],
                    'label' => $timelineKey['label'],
                    'external_reference' => $timelineKey['type'] === 'external_reference' ? $timelineKey['key'] : null,
                    'correlation_id' => $execution['correlation_id'] ?? null,
                    'request_id' => $execution['request_id'] ?? null,
                    'last_execution_at' => $execution['created_at'] ?? null,
                    'latest_status' => $execution['status'] ?? 'unknown',
                    'execution_count' => 0,
                    'success_count' => 0,
                    'failed_count' => 0,
                    'rate_limited_count' => 0,
                    'skipped_invalid_count' => 0,
                    'event_keys' => [],
                    'capability_ids' => [],
                    'modules' => [],
                    'executions' => [],
                ];
            }

            $status = trim((string)($execution['status'] ?? 'unknown'));
            $groups[$groupId]['execution_count']++;
            if ($status === 'success') {
                $groups[$groupId]['success_count']++;
            } elseif ($status === 'failed') {
                $groups[$groupId]['failed_count']++;
            } elseif ($status === 'rate_limited') {
                $groups[$groupId]['rate_limited_count']++;
            } elseif ($status === 'skipped_invalid') {
                $groups[$groupId]['skipped_invalid_count']++;
            }

            $eventKey = trim((string)($execution['event_key'] ?? ''));
            if ($eventKey !== '') {
                $groups[$groupId]['event_keys'][$eventKey] = $eventKey;
            }

            $capabilityId = trim((string)($execution['resolved_capability'] ?? $execution['capability_id'] ?? ''));
            if ($capabilityId !== '') {
                $groups[$groupId]['capability_ids'][$capabilityId] = $capabilityId;
            }

            $module = trim((string)($execution['module'] ?? ''));
            if ($module !== '') {
                $groups[$groupId]['modules'][$module] = $module;
            }

            if (count($groups[$groupId]['executions']) < self::DEFAULT_TIMELINE_EXECUTION_PREVIEW_LIMIT) {
                $groups[$groupId]['executions'][] = [
                    'id' => $execution['id'] ?? null,
                    'trigger_id' => $execution['trigger_id'] ?? null,
                    'module' => $execution['module'] ?? null,
                    'event_key' => $execution['event_key'] ?? null,
                    'capability_id' => $execution['resolved_capability'] ?? ($execution['capability_id'] ?? null),
                    'status' => $execution['status'] ?? 'unknown',
                    'request_id' => $execution['request_id'] ?? null,
                    'correlation_id' => $execution['correlation_id'] ?? null,
                    'external_reference' => $execution['external_reference'] ?? null,
                    'duration_ms' => $execution['duration_ms'] ?? null,
                    'created_at' => $execution['created_at'] ?? null,
                    'error_message' => $execution['error_message'] ?? null,
                ];
            }
        }

        foreach ($groups as &$group) {
            $group['event_keys'] = array_values($group['event_keys']);
            $group['capability_ids'] = array_values($group['capability_ids']);
            $group['modules'] = array_values($group['modules']);
        }
        unset($group);

        return array_slice(array_values($groups), 0, $limit);
    }

    /** @return array<int, array<string, mixed>> */
    private function fetchAll(string $sql): array
    {
        try {
            $stmt = $this->db->query($sql);
            return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    private function fetchExecutionRows(array $filters, int $limit): array
    {
        $sql = 'SELECT * FROM kernel_trigger_executions';
        $conditions = [];
        $params = [];

        $stringFilters = [
            'module' => 'module',
            'event_key' => 'event_key',
            'capability_id' => 'capability_id',
            'status' => 'status',
            'correlation_id' => 'correlation_id',
            'request_id' => 'request_id',
            'external_reference' => 'external_reference',
        ];
        foreach ($stringFilters as $filterKey => $column) {
            $value = trim((string)($filters[$filterKey] ?? ''));
            if ($value === '') {
                continue;
            }

            $conditions[] = $column . ' = :' . $filterKey;
            $params[':' . $filterKey] = $value;
        }

        $triggerId = isset($filters['trigger_id']) ? (int)$filters['trigger_id'] : 0;
        if ($triggerId > 0) {
            $conditions[] = 'trigger_id = :trigger_id';
            $params[':trigger_id'] = $triggerId;
        }

        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        $sql .= ' ORDER BY created_at DESC, id DESC LIMIT ' . $limit;

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function currentTriggerIndex(): array
    {
        return $this->indexRowsById($this->fetchAll(
            'SELECT id, module, event_key, capability_id, is_enabled, priority FROM kernel_event_triggers ORDER BY id ASC'
        ));
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function indexRowsById(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $id = (int)($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            $out[$id] = $row;
        }

        return $out;
    }

    private function countRows(string $sql): int
    {
        try {
            $stmt = $this->db->query($sql);
            return (int)($stmt ? $stmt->fetchColumn() : 0);
        } catch (Throwable $e) {
            return 0;
        }
    }

    /** @return array<int, string> */
    private function decodeJsonList(mixed $value): array
    {
        if (is_array($value)) {
            $decoded = $value;
        } elseif (is_string($value) && trim($value) !== '') {
            $decoded = json_decode($value, true);
        } else {
            $decoded = [];
        }

        if (!is_array($decoded)) {
            return [];
        }

        $decoded = array_values(array_filter($decoded, static fn(mixed $item): bool => is_string($item) && trim($item) !== ''));
        sort($decoded);
        return $decoded;
    }

    /** @return array<string, mixed>|null */
    private function decodeJsonObject(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function decodeJsonMixed(mixed $value): mixed
    {
        if (is_array($value) || is_bool($value) || is_int($value) || is_float($value) || $value === null) {
            return $value;
        }

        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $decoded = json_decode($value, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }

    /**
     * @param array<string, mixed>|null $mapping
     * @return array<int, string>
     */
    private function mappingVariables(?array $mapping): array
    {
        if (!is_array($mapping)) {
            return [];
        }

        $vars = [];
        $collect = static function (mixed $value) use (&$collect, &$vars): void {
            if (is_array($value)) {
                foreach ($value as $child) {
                    $collect($child);
                }
                return;
            }

            if (!is_string($value) || $value === '') {
                return;
            }

            if (preg_match_all('/\{\{\s*([^}]+?)\s*\}\}/', $value, $matches) === 1 || !empty($matches[1])) {
                foreach ($matches[1] ?? [] as $match) {
                    $match = trim((string)$match);
                    if ($match !== '') {
                        $vars[$match] = true;
                    }
                }
            }
        };

        $collect($mapping);
        $out = array_keys($vars);
        sort($out);
        return $out;
    }

    private function moduleName(string $moduleId): string
    {
        $module = $this->capabilities->module($moduleId);
        return (string)($module['name'] ?? $moduleId);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function normalizeExecutionFilters(array $filters): array
    {
        $allowed = ['module', 'event_key', 'capability_id', 'status', 'correlation_id', 'request_id', 'external_reference', 'trigger_id'];
        $normalized = [];
        foreach ($allowed as $key) {
            if (!array_key_exists($key, $filters)) {
                continue;
            }
            $normalized[$key] = $filters[$key];
        }

        return $normalized;
    }

    private function normalizeExecutionLimit(int $limit): int
    {
        if ($limit <= 0) {
            return self::DEFAULT_EXECUTION_LIMIT;
        }

        return min($limit, 200);
    }

    private function normalizeTimelineLimit(int $limit): int
    {
        if ($limit <= 0) {
            return self::DEFAULT_TIMELINE_LIMIT;
        }

        return min($limit, 50);
    }

    /**
     * @param array<string, mixed> $execution
     * @return array{type: string, key: string, label: string}
     */
    private function timelineKey(array $execution): array
    {
        $externalReference = trim((string)($execution['external_reference'] ?? ''));
        if ($externalReference !== '') {
            return [
                'type' => 'external_reference',
                'key' => $externalReference,
                'label' => 'External Ref ' . $externalReference,
            ];
        }

        $correlationId = trim((string)($execution['correlation_id'] ?? ''));
        if ($correlationId !== '') {
            return [
                'type' => 'correlation_id',
                'key' => $correlationId,
                'label' => 'Correlation ' . $correlationId,
            ];
        }

        $requestId = trim((string)($execution['request_id'] ?? ''));
        if ($requestId !== '') {
            return [
                'type' => 'request_id',
                'key' => $requestId,
                'label' => 'Request ' . $requestId,
            ];
        }

        $triggerId = (int)($execution['trigger_id'] ?? 0);
        if ($triggerId > 0) {
            return [
                'type' => 'trigger_id',
                'key' => (string)$triggerId,
                'label' => 'Trigger #' . $triggerId,
            ];
        }

        $eventKey = trim((string)($execution['event_key'] ?? ''));
        $capabilityId = trim((string)($execution['resolved_capability'] ?? $execution['capability_id'] ?? ''));

        return [
            'type' => 'execution',
            'key' => sha1(json_encode([$execution['module'] ?? '', $eventKey, $capabilityId], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: ''),
            'label' => $eventKey !== '' ? $eventKey : ($capabilityId !== '' ? $capabilityId : 'Execution'),
        ];
    }

    private function moduleEventKey(string $moduleId, string $eventKey): string
    {
        return $moduleId . '|' . $eventKey;
    }
}