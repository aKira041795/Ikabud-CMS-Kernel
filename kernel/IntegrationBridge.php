<?php

declare(strict_types=1);

namespace Ikabud\Kernel;

use Throwable;

class IntegrationBridge
{
    private static int $activeDepth = 0;

    public static function upsertBridge(array $definition): int
    {
        $name = trim((string)($definition['name'] ?? ''));
        $triggerEvent = trim((string)($definition['trigger_event'] ?? ''));
        $targetCapability = trim((string)($definition['target_capability'] ?? ''));
        $eventSource = trim((string)($definition['event_source'] ?? 'eventbus')) ?: 'eventbus';
        $versionLock = trim((string)($definition['version_lock'] ?? $targetCapability));
        $isActive = isset($definition['is_active']) ? (int)!empty($definition['is_active']) : 1;
        $mapping = $definition['mapping'] ?? $definition['mapping_json'] ?? null;

        if ($name === '' || $triggerEvent === '' || $targetCapability === '') {
            throw new \InvalidArgumentException('Bridge name, trigger_event, and target_capability are required.');
        }

        if (!is_array($mapping)) {
            throw new \InvalidArgumentException('Bridge mapping must be an array.');
        }

        $resolvedCapability = (string)app()->capabilities()->resolve($targetCapability);
        if (!app()->capabilities()->has($resolvedCapability)) {
            throw new \RuntimeException('Capability not registered: ' . $targetCapability);
        }

        $mappingJson = json_encode($mapping, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($mappingJson) || $mappingJson === '') {
            throw new \RuntimeException('Failed to encode bridge mapping.');
        }

        $db = app()->db();
        $existingStmt = $db->prepare('SELECT id FROM kernel_integrations WHERE name = ? LIMIT 1');
        $existingStmt->execute([$name]);
        $existingId = (int)($existingStmt->fetchColumn() ?: 0);

        if ($existingId > 0) {
            $db->prepare(
                'UPDATE kernel_integrations SET trigger_event = ?, target_capability = ?, mapping_json = ?, is_active = ?, event_source = ?, version_lock = ?, updated_at = NOW() WHERE id = ?'
            )->execute([
                $triggerEvent,
                $targetCapability,
                $mappingJson,
                $isActive,
                $eventSource,
                $versionLock !== '' ? $versionLock : null,
                $existingId,
            ]);

            return $existingId;
        }

        $db->prepare(
            'INSERT INTO kernel_integrations (name, trigger_event, target_capability, mapping_json, is_active, event_source, version_lock) VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $name,
            $triggerEvent,
            $targetCapability,
            $mappingJson,
            $isActive,
            $eventSource,
            $versionLock !== '' ? $versionLock : null,
        ]);

        return (int)$db->lastInsertId();
    }

    public static function deleteBridgesByNames(array $names): int
    {
        $names = array_values(array_filter(array_map(static fn(mixed $value): string => trim((string)$value), $names)));
        if ($names === []) {
            return 0;
        }

        $placeholders = implode(', ', array_fill(0, count($names), '?'));
        $stmt = app()->db()->prepare('DELETE FROM kernel_integrations WHERE name IN (' . $placeholders . ')');
        $stmt->execute($names);

        return (int)$stmt->rowCount();
    }

    public static function hasActiveBridge(string $event, string $targetCapability): bool
    {
        try {
            $stmt = app()->db()->prepare(
                'SELECT 1 FROM kernel_integrations WHERE trigger_event = ? AND target_capability = ? AND is_active = 1 LIMIT 1'
            );
            $stmt->execute([$event, $targetCapability]);

            return $stmt->fetchColumn() !== false;
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
}
