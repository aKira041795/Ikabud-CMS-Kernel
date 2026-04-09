<?php

declare(strict_types=1);

namespace Ikabud\Kernel;

use Throwable;

class IntegrationBridge
{
    private static int $activeDepth = 0;

    private static function withKernelDbUnguarded(callable $callback): mixed
    {
        $previousUnguarded = (bool)kernel_request_context_get('_kernel_db_unguarded', false);
        kernel_request_context_set('_kernel_db_unguarded', true);

        try {
            return $callback();
        } finally {
            kernel_request_context_set('_kernel_db_unguarded', $previousUnguarded);
        }
    }

    public static function validateDefinition(array $definition): array
    {
        $name = trim((string)($definition['name'] ?? ''));
        $triggerEvent = trim((string)($definition['trigger_event'] ?? ''));
        $targetCapability = trim((string)($definition['target_capability'] ?? ''));
        $eventSource = trim((string)($definition['event_source'] ?? 'eventbus')) ?: 'eventbus';
        $mapping = $definition['mapping'] ?? $definition['mapping_json'] ?? null;
        $errors = [];

        if ($name === '') {
            $errors[] = 'Bridge name is required.';
        }

        if ($triggerEvent === '') {
            $errors[] = 'Trigger event is required.';
        }

        if ($targetCapability === '') {
            $errors[] = 'Target capability is required.';
        }

        if (!is_array($mapping) || self::isList($mapping)) {
            $errors[] = 'Bridge mapping must be a JSON object.';
        }

        $resolvedCapability = $targetCapability !== ''
            ? (string)app()->capabilities()->resolve($targetCapability)
            : '';

        if ($resolvedCapability !== '' && !app()->capabilities()->has($resolvedCapability)) {
            $errors[] = 'Capability not registered: ' . $targetCapability . '.';
        }

        $versionLock = self::normalizeVersionLock($targetCapability, $resolvedCapability, $definition['version_lock'] ?? null);
        if ($resolvedCapability !== '' && $versionLock !== null && !self::versionLockMatches($resolvedCapability, $versionLock)) {
            $errors[] = 'Version lock mismatch. Expected ' . $resolvedCapability . ' for target capability ' . $targetCapability . '.';
        }

        $availableVars = $triggerEvent !== '' ? self::eventAvailableVars($triggerEvent) : [];
        $mappingVars = is_array($mapping) ? self::mappingVariables($mapping) : [];
        if ($availableVars !== [] && $mappingVars !== []) {
            $unknownVars = [];
            foreach ($mappingVars as $path) {
                if (!self::mappingVariableAllowed($path, $availableVars)) {
                    $unknownVars[] = $path;
                }
            }
            if ($unknownVars !== []) {
                $errors[] = 'Unknown mapping variables for event ' . $triggerEvent . ': ' . implode(', ', $unknownVars) . '.';
            }
        }

        if ($resolvedCapability !== '') {
            $providers = app()->capabilities()->providers($resolvedCapability);
            $allowedProviders = array_values(array_filter(
                $providers,
                static fn(array $provider): bool => self::providerAllowsCaller($provider, $resolvedCapability, 'kernel')
            ));
            if ($providers !== [] && $allowedProviders === []) {
                $errors[] = 'Capability caller policy denies kernel bridge access for ' . $targetCapability . '.';
            }

            $schema = self::capabilityInputSchema($resolvedCapability);
            if (is_array($schema) && is_array($mapping) && !self::isList($mapping)) {
                self::validateMappingAgainstSchema($mapping, $schema, 'mapping', $errors);
            }
        }

        $mappingJson = null;
        if (is_array($mapping) && !self::isList($mapping)) {
            $mappingJson = json_encode($mapping, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (!is_string($mappingJson) || $mappingJson === '') {
                $errors[] = 'Failed to encode bridge mapping.';
            }
        }

        return [
            'ok' => $errors === [],
            'errors' => $errors,
            'available_vars' => $availableVars,
            'mapping_vars' => $mappingVars,
            'resolved_capability' => $resolvedCapability,
            'normalized' => [
                'name' => $name,
                'trigger_event' => $triggerEvent,
                'target_capability' => $targetCapability,
                'event_source' => $eventSource,
                'mapping' => is_array($mapping) ? $mapping : [],
                'mapping_json' => is_string($mappingJson) ? $mappingJson : null,
                'version_lock' => $versionLock,
                'is_active' => isset($definition['is_active']) ? (int)!empty($definition['is_active']) : 1,
            ],
        ];
    }

    public static function upsertBridge(array $definition): int
    {
        $validation = self::validateDefinition($definition);
        if (empty($validation['ok'])) {
            throw new \InvalidArgumentException(implode(' ', $validation['errors'] ?? ['Invalid bridge definition.']));
        }

        $normalized = is_array($validation['normalized'] ?? null) ? $validation['normalized'] : [];
        $name = (string)($normalized['name'] ?? '');
        $triggerEvent = (string)($normalized['trigger_event'] ?? '');
        $targetCapability = (string)($normalized['target_capability'] ?? '');
        $integrationMode = (string)($normalized['integration_mode'] ?? '');
        $eventSource = (string)($normalized['event_source'] ?? 'eventbus');
        $versionLock = $normalized['version_lock'] ?? null;
        $isActive = (int)($normalized['is_active'] ?? 1);
        $mappingJson = (string)($normalized['mapping_json'] ?? '');

        return self::withKernelDbUnguarded(static function () use (
            $eventSource,
            $integrationMode,
            $isActive,
            $mappingJson,
            $name,
            $targetCapability,
            $triggerEvent,
            $versionLock
        ): int {
            $db = app()->db();
            $existingStmt = $db->prepare('SELECT id FROM kernel_integrations WHERE name = ? LIMIT 1');
            $existingStmt->execute([$name]);
            $existingId = (int)($existingStmt->fetchColumn() ?: 0);

            if ($existingId > 0) {
                $db->prepare(
                    'UPDATE kernel_integrations SET trigger_event = ?, target_capability = ?, mapping_json = ?, is_active = ?, event_source = ?, version_lock = ?, integration_mode = ?, updated_at = NOW() WHERE id = ?'
                )->execute([
                    $triggerEvent,
                    $targetCapability,
                    $mappingJson,
                    $isActive,
                    $eventSource,
                    is_string($versionLock) && $versionLock !== '' ? $versionLock : null,
                    $integrationMode !== '' ? $integrationMode : null,
                    $existingId,
                ]);

                return $existingId;
            }

            $db->prepare(
                'INSERT INTO kernel_integrations (name, trigger_event, target_capability, mapping_json, is_active, event_source, version_lock, integration_mode) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $name,
                $triggerEvent,
                $targetCapability,
                $mappingJson,
                $isActive,
                $eventSource,
                is_string($versionLock) && $versionLock !== '' ? $versionLock : null,
                $integrationMode !== '' ? $integrationMode : null,
            ]);

            return (int)$db->lastInsertId();
        });
    }

    public static function deleteBridgesByNames(array $names): int
    {
        $names = array_values(array_filter(array_map(static fn(mixed $value): string => trim((string)$value), $names)));
        if ($names === []) {
            return 0;
        }

        return self::withKernelDbUnguarded(static function () use ($names): int {
            $placeholders = implode(', ', array_fill(0, count($names), '?'));
            $stmt = app()->db()->prepare('DELETE FROM kernel_integrations WHERE name IN (' . $placeholders . ')');
            $stmt->execute($names);

            return (int)$stmt->rowCount();
        });
    }

    public static function hasActiveBridge(string $event, string $targetCapability): bool
    {
        try {
            return self::withKernelDbUnguarded(static function () use ($event, $targetCapability): bool {
                $stmt = app()->db()->prepare(
                    'SELECT 1 FROM kernel_integrations WHERE trigger_event = ? AND target_capability = ? AND is_active = 1 LIMIT 1'
                );
                $stmt->execute([$event, $targetCapability]);

                return $stmt->fetchColumn() !== false;
            });
        } catch (Throwable $e) {
            return false;
        }
    }

    public static function handle(array $payload, string $event): void
    {
        if ($event === '' || str_starts_with($event, 'kernel.database.') || str_starts_with($event, 'integration.result.')) {
            return;
        }

        if (self::$activeDepth > 0) {
            return;
        }

        self::$activeDepth++;
        $previousUnguarded = (bool)kernel_request_context_get('_kernel_db_unguarded', false);
        kernel_request_context_set('_kernel_db_unguarded', true);

        try {
            $app = app();
            $db = $app->db();
            $requestId = function_exists('request_id') ? request_id() : null;
            $correlationId = self::correlationId();

            $stmt = $db->prepare('SELECT * FROM kernel_integrations WHERE trigger_event = ? AND is_active = 1 ORDER BY id ASC');
            $stmt->execute([$event]);
            $integrations = $stmt->fetchAll();

            foreach ($integrations as $intg) {
                $startedAt = microtime(true);
                $logStatus = 'success';
                $logError = null;
                $capResult = ['ok' => true];
                $outPayload = [];

                $mapping = self::decodeMapping((string)($intg['mapping_json'] ?? ''));
                if ($mapping === null) {
                    $logStatus = 'failed';
                    $logError = 'Invalid integration mapping_json. Expected a JSON object.';
                    $capResult = ['ok' => false, 'error' => $logError];
                } else {
                    $outPayload = self::applyMapping($payload, $mapping);
                    if (isset($payload['idempotency_key']) && !array_key_exists('idempotency_key', $outPayload)) {
                        $outPayload['idempotency_key'] = $payload['idempotency_key'];
                    }

                    $targetCapability = (string)($intg['target_capability'] ?? '');
                    $resolvedCapability = (string)$app->capabilities()->resolve($targetCapability);

                    if (!$app->capabilities()->has($resolvedCapability)) {
                        $logStatus = 'failed';
                        $logError = 'Capability not registered: ' . $targetCapability;
                        $capResult = ['ok' => false, 'error' => $logError];
                    } elseif (!self::versionLockMatches($resolvedCapability, (string)($intg['version_lock'] ?? ''))) {
                        $logStatus = 'failed';
                        $logError = 'Capability version lock mismatch. Expected ' . (string)$intg['version_lock'] . ', resolved ' . $resolvedCapability . '.';
                        $capResult = ['ok' => false, 'error' => $logError];
                    } else {
                        try {
                            $capResult = $app->cap()->call($targetCapability, $outPayload, [
                                'caller' => 'kernel',
                                'caller_module' => 'kernel',
                                'correlation_id' => $correlationId,
                                'request_id' => $requestId,
                            ]);
                            if (!is_array($capResult)) {
                                $capResult = ['ok' => true, 'value' => $capResult];
                            } elseif (!array_key_exists('ok', $capResult)) {
                                $capResult['ok'] = true;
                            }
                        } catch (Throwable $e) {
                            $logStatus = 'failed';
                            $logError = $e->getMessage();
                            $capResult = ['ok' => false, 'error' => $logError];
                        }
                    }
                }

                self::emitResultEvent($intg, $event, $outPayload, $capResult, $correlationId, $requestId);
                self::recordLog(
                    (int)($intg['id'] ?? 0),
                    $logStatus,
                    $payload,
                    $outPayload,
                    $logError,
                    $requestId,
                    $correlationId,
                    (int)round((microtime(true) - $startedAt) * 1000)
                );
            }
        } finally {
            kernel_request_context_set('_kernel_db_unguarded', $previousUnguarded);
            self::$activeDepth = max(0, self::$activeDepth - 1);
        }
    }

    private static function emitResultEvent(
        array $integration,
        string $event,
        array $mappedPayload,
        array $result,
        ?string $correlationId,
        ?string $requestId
    ): void {
        if (!function_exists('kernelEmitEvent')) {
            return;
        }

        $targetCapability = (string)($integration['target_capability'] ?? '');
        $resultEvent = 'integration.result.' . str_replace('@', '_v', $targetCapability);

        $chainPayload = [
            'integration_id' => (int)($integration['id'] ?? 0),
            'integration_name' => (string)($integration['name'] ?? ''),
            'trigger_event' => $event,
            'target_capability' => $targetCapability,
            'mapped_payload' => $mappedPayload,
            'result' => $result,
            'correlation_id' => $correlationId,
            'request_id' => $requestId,
        ];

        try {
            kernelEmitEvent($resultEvent, $chainPayload, 'kernel');
        } catch (Throwable $e) {
            if (function_exists('write_log')) {
                write_log('integration bridge result event failed: ' . $e->getMessage(), 'warning', [
                    'module' => 'kernel',
                    'trigger_event' => $event,
                    'target_capability' => $targetCapability,
                    'request_id' => $requestId,
                    'correlation_id' => $correlationId,
                ]);
            }
        }
    }

    private static function recordLog(
        int $integrationId,
        string $status,
        array $payloadIn,
        array $payloadOut,
        ?string $errorMessage,
        ?string $requestId,
        ?string $correlationId,
        int $durationMs
    ): void {
        if ($integrationId <= 0) {
            return;
        }

        try {
            $db = app()->db();
            $logStmt = $db->prepare(
                'INSERT INTO kernel_integration_logs '
                . '(integration_id, status, payload_in, payload_out, error_message, request_id, correlation_id, duration_ms) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $logStmt->execute([
                $integrationId,
                $status,
                json_encode($payloadIn, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                json_encode($payloadOut, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                $errorMessage,
                $requestId,
                $correlationId,
                max(0, $durationMs),
            ]);
        } catch (Throwable $e) {
            if (function_exists('write_log')) {
                write_log('integration bridge log write failed: ' . $e->getMessage(), 'warning', [
                    'module' => 'kernel',
                    'integration_id' => $integrationId,
                    'request_id' => $requestId,
                    'correlation_id' => $correlationId,
                ]);
            }
        }
    }

    private static function decodeMapping(string $rawMapping): ?array
    {
        if (trim($rawMapping) === '') {
            return [];
        }

        $decoded = json_decode($rawMapping, true);
        return is_array($decoded) ? $decoded : null;
    }

    private static function versionLockMatches(string $resolvedCapability, string $versionLock): bool
    {
        $versionLock = trim($versionLock);
        if ($versionLock === '') {
            return true;
        }

        return $resolvedCapability === $versionLock;
    }

    private static function correlationId(): ?string
    {
        if (function_exists('kernelCorrelationId')) {
            return kernelCorrelationId();
        }

        try {
            return bin2hex(random_bytes(8));
        } catch (Throwable $e) {
            return uniqid('intg_', true);
        }
    }

    private static function applyMapping(array $in, array $mapping): array
    {
        $out = [];
        foreach ($mapping as $key => $value) {
            $out[$key] = self::applyMappingValue($in, $value);
        }

        return $out;
    }

    private static function applyMappingValue(array $in, mixed $value): mixed
    {
        if (is_array($value)) {
            $resolved = [];
            foreach ($value as $key => $item) {
                $resolved[$key] = self::applyMappingValue($in, $item);
            }

            return $resolved;
        }

        if (!is_string($value) || preg_match_all('/\{\{([^}]+)\}\}/', $value, $matches) !== 1) {
            return $value;
        }

        $valReplaced = $value;
        foreach ($matches[1] as $i => $path) {
            $resolved = self::resolveDot($in, trim($path));
            if ($value === $matches[0][$i]) {
                return $resolved;
            }

            $replacement = is_scalar($resolved) || $resolved === null
                ? (string)$resolved
                : json_encode($resolved, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            $valReplaced = str_replace($matches[0][$i], $replacement, $valReplaced);
        }

        return $valReplaced;
    }

    private static function resolveDot(array $data, string $path): mixed
    {
        $parts = explode('.', $path);
        foreach ($parts as $part) {
            if (is_array($data) && array_key_exists($part, $data)) {
                $data = $data[$part];
            } else {
                return null;
            }
        }

        return $data;
    }

    private static function normalizeVersionLock(string $targetCapability, string $resolvedCapability, mixed $rawVersionLock): ?string
    {
        $versionLock = trim((string)$rawVersionLock);
        if ($versionLock !== '') {
            return $versionLock;
        }

        if ($targetCapability !== '' && preg_match('/@\d+$/', $targetCapability) === 1) {
            return $targetCapability;
        }

        return null;
    }

    private static function eventAvailableVars(string $triggerEvent): array
    {
        if ($triggerEvent === '') {
            return [];
        }

        if (function_exists('kernelEventAvailableVars')) {
            $vars = kernelEventAvailableVars($triggerEvent);
            if (is_array($vars) && $vars !== []) {
                return $vars;
            }
        }

        try {
            $stmt = app()->db()->prepare('SELECT available_vars FROM kernel_events WHERE event_key = ? LIMIT 1');
            $stmt->execute([$triggerEvent]);
            $raw = $stmt->fetchColumn();
            if ($raw === false || $raw === null || trim((string)$raw) === '') {
                return [];
            }

            $decoded = json_decode((string)$raw, true);
            if (!is_array($decoded)) {
                return [];
            }

            $vars = array_values(array_unique(array_filter($decoded, static fn(mixed $value): bool => is_string($value) && trim($value) !== '')));
            sort($vars);

            return $vars;
        } catch (Throwable $e) {
        }

        if (function_exists('getEnabledModules')) {
            foreach (getEnabledModules() as $module) {
                $events = is_array($module['events'] ?? null) ? $module['events'] : [];
                foreach ($events as $event) {
                    if (!is_array($event) || trim((string)($event['key'] ?? '')) !== $triggerEvent) {
                        continue;
                    }

                    $vars = is_array($event['available_vars'] ?? null) ? $event['available_vars'] : [];
                    $vars = array_values(array_unique(array_filter($vars, static fn(mixed $value): bool => is_string($value) && trim($value) !== '')));
                    sort($vars);

                    return $vars;
                }
            }
        }

        if (function_exists('modulesPath')) {
            $modulesDir = modulesPath();
            if (is_dir($modulesDir)) {
                foreach (scandir($modulesDir) ?: [] as $entry) {
                    if ($entry === '.' || $entry === '..') {
                        continue;
                    }

                    $manifestPath = rtrim($modulesDir, '/') . '/' . $entry . '/module.json';
                    if (!is_file($manifestPath)) {
                        continue;
                    }

                    $manifest = json_decode((string)file_get_contents($manifestPath), true);
                    if (!is_array($manifest)) {
                        continue;
                    }

                    $events = is_array($manifest['events'] ?? null) ? $manifest['events'] : [];
                    foreach ($events as $event) {
                        if (!is_array($event) || trim((string)($event['key'] ?? '')) !== $triggerEvent) {
                            continue;
                        }

                        $vars = is_array($event['available_vars'] ?? null) ? $event['available_vars'] : [];
                        $vars = array_values(array_unique(array_filter($vars, static fn(mixed $value): bool => is_string($value) && trim($value) !== '')));
                        sort($vars);

                        return $vars;
                    }
                }
            }
        }

        return [];
    }

    private static function mappingVariables(mixed $value): array
    {
        $vars = [];

        if (is_array($value)) {
            foreach ($value as $item) {
                $vars = array_merge($vars, self::mappingVariables($item));
            }
        } elseif (is_string($value) && preg_match_all('/\{\{([^}]+)\}\}/', $value, $matches) === 1) {
            foreach ($matches[1] as $path) {
                $path = trim((string)$path);
                if ($path !== '') {
                    $vars[] = $path;
                }
            }
        }

        $vars = array_values(array_unique($vars));
        sort($vars);

        return $vars;
    }

    private static function mappingVariableAllowed(string $path, array $availableVars): bool
    {
        if ($path === '') {
            return true;
        }

        if (in_array($path, $availableVars, true)) {
            return true;
        }

        $parts = explode('.', $path);
        $root = $parts[0] ?? $path;
        $hasNestedDeclarations = false;
        foreach ($availableVars as $availableVar) {
            if (is_string($availableVar) && str_starts_with($availableVar, $root . '.')) {
                $hasNestedDeclarations = true;
                break;
            }
        }

        if (!$hasNestedDeclarations && in_array($root, $availableVars, true)) {
            return true;
        }

        while (count($parts) > 1) {
            array_pop($parts);
            $candidate = implode('.', $parts);
            if ($candidate === $root && $hasNestedDeclarations) {
                continue;
            }
            if (in_array($candidate, $availableVars, true)) {
                return true;
            }
        }

        return false;
    }

    private static function providerAllowsCaller(array $provider, string $capabilityId, string $callerModule): bool
    {
        if ($callerModule === '') {
            return true;
        }

        $meta = is_array($provider['meta'] ?? null) ? $provider['meta'] : [];
        $policy = is_array($meta['policy'] ?? null) ? $meta['policy'] : [];
        if ($policy === []) {
            return true;
        }

        $default = is_array($policy['default'] ?? null) ? $policy['default'] : [];
        $perCapability = is_array($policy['capabilities'] ?? null) ? $policy['capabilities'] : [];
        $rule = isset($perCapability[$capabilityId]) && is_array($perCapability[$capabilityId])
            ? $perCapability[$capabilityId]
            : [];

        $denyCallers = [];
        if (is_array($default['deny_callers'] ?? null)) {
            $denyCallers = array_merge($denyCallers, $default['deny_callers']);
        }
        if (is_array($rule['deny_callers'] ?? null)) {
            $denyCallers = array_merge($denyCallers, $rule['deny_callers']);
        }
        $denyCallers = array_values(array_filter($denyCallers, static fn(mixed $value): bool => is_string($value) && $value !== ''));
        if (in_array($callerModule, $denyCallers, true)) {
            return false;
        }

        $allowCallers = [];
        if (is_array($default['allow_callers'] ?? null)) {
            $allowCallers = array_merge($allowCallers, $default['allow_callers']);
        }
        if (is_array($rule['allow_callers'] ?? null)) {
            $allowCallers = array_merge($allowCallers, $rule['allow_callers']);
        }
        $allowCallers = array_values(array_filter($allowCallers, static fn(mixed $value): bool => is_string($value) && $value !== ''));

        return $allowCallers === [] || in_array($callerModule, $allowCallers, true);
    }

    private static function capabilityInputSchema(string $capabilityId): ?array
    {
        if ($capabilityId === '') {
            return null;
        }

        try {
            foreach (app()->capabilities()->providers($capabilityId) as $provider) {
                $meta = is_array($provider['meta'] ?? null) ? $provider['meta'] : [];
                $schema = is_array($meta['schema'] ?? null) ? $meta['schema'] : null;
                if ($schema === null) {
                    continue;
                }
                if (isset($schema['input']) || isset($schema['output'])) {
                    return is_array($schema['input'] ?? null) ? $schema['input'] : null;
                }

                return $schema;
            }
        } catch (Throwable $e) {
            return null;
        }

        return null;
    }

    private static function validateMappingAgainstSchema(mixed $value, array $schema, string $path, array &$errors): void
    {
        if (self::isExactPlaceholder($value)) {
            return;
        }

        if (is_string($value) && self::containsPlaceholder($value)) {
            $value = (string)$value;
        }

        $type = $schema['type'] ?? null;
        if ($type !== null) {
            $ok = match ($type) {
                'object' => is_array($value),
                'array' => is_array($value),
                'string' => is_string($value),
                'number' => is_int($value) || is_float($value),
                'integer' => is_int($value),
                'boolean' => is_bool($value),
                default => true,
            };
            if (!$ok) {
                $errors[] = $path . ' should be ' . $type . '.';
                return;
            }
        }

        if (($schema['type'] ?? null) === 'object' && is_array($value)) {
            $required = is_array($schema['required'] ?? null) ? $schema['required'] : [];
            foreach ($required as $requiredKey) {
                if (is_string($requiredKey) && $requiredKey !== '' && !array_key_exists($requiredKey, $value)) {
                    $errors[] = $path . '.' . $requiredKey . ' is required.';
                }
            }

            $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
            foreach ($properties as $property => $propertySchema) {
                if (is_string($property) && is_array($propertySchema) && array_key_exists($property, $value)) {
                    self::validateMappingAgainstSchema($value[$property], $propertySchema, $path . '.' . $property, $errors);
                }
            }
        }

        if (($schema['type'] ?? null) === 'array' && is_array($value)) {
            $itemSchema = is_array($schema['items'] ?? null) ? $schema['items'] : null;
            if ($itemSchema !== null) {
                foreach ($value as $index => $item) {
                    self::validateMappingAgainstSchema($item, $itemSchema, $path . '[' . $index . ']', $errors);
                }
            }
        }
    }

    private static function isExactPlaceholder(mixed $value): bool
    {
        return is_string($value) && preg_match('/^\{\{[^}]+\}\}$/', $value) === 1;
    }

    private static function containsPlaceholder(string $value): bool
    {
        return preg_match('/\{\{[^}]+\}\}/', $value) === 1;
    }

    private static function isList(array $value): bool
    {
        if (function_exists('array_is_list')) {
            return array_is_list($value);
        }

        $expected = 0;
        foreach (array_keys($value) as $key) {
            if ($key !== $expected) {
                return false;
            }
            $expected++;
        }

        return true;
    }
}
