<?php

declare(strict_types=1);

function kernelTemplateReplace(string $template, array $data = []): string
{
    $out = $template;
    foreach ($data as $k => $v) {
        if (!is_scalar($v) && $v !== null) {
            continue;
        }
        $out = str_replace('{' . (string)$k . '}', (string)($v ?? ''), $out);
        $out = str_replace('#{' . (string)$k . '}', (string)($v ?? ''), $out);
    }
    $out = preg_replace('/\{[a-zA-Z0-9_]+\}/', '', $out);
    $out = preg_replace('/#\{[a-zA-Z0-9_]+\}/', '', $out);
    return trim((string)$out);
}

function kernelTriggerTemplateVariables(string $template): array
{
    if (trim($template) === '') {
        return [];
    }
    preg_match_all('/#?\{([a-zA-Z0-9_]+)\}/', $template, $matches);
    $vars = $matches[1] ?? [];
    $vars = array_values(array_unique(array_filter($vars, fn($v) => is_string($v) && trim($v) !== '')));
    sort($vars);
    return $vars;
}

function kernelEventAvailableVars(string $eventKey): array
{
    $eventKey = trim($eventKey);
    if ($eventKey === '') {
        return [];
    }
    try {
        $stmt = app()->db()->prepare('SELECT available_vars FROM kernel_events WHERE event_key = ? LIMIT 1');
        $stmt->execute([$eventKey]);
        $raw = $stmt->fetchColumn();
        if ($raw === false || $raw === null || trim((string)$raw) === '') {
            return [];
        }
        $decoded = json_decode((string)$raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        $vars = array_values(array_unique(array_filter($decoded, fn($v) => is_string($v) && trim($v) !== '')));
        sort($vars);
        return $vars;
    } catch (Throwable $e) {
        return [];
    }
}

function kernelCapabilityInputSchema(string $capabilityId, ?string $provider = null): ?array
{
    $capabilityId = trim($capabilityId);
    if ($capabilityId === '') {
        return null;
    }
    try {
        $registry = app()->capabilities();
        $resolvedId = $registry->resolve($capabilityId);
        foreach ($registry->providers($resolvedId) as $entry) {
            if ($provider !== null && $provider !== '' && (string)($entry['provider'] ?? '') !== $provider) {
                continue;
            }
            $meta = is_array($entry['meta'] ?? null) ? $entry['meta'] : [];
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

function kernelTriggerValidatePayloadSchema(mixed $value, array $schema, string $path, array &$errors): bool
{
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
            $errors[] = "{$path} should be {$type}";
            return false;
        }
    }
    if (($schema['type'] ?? null) === 'object') {
        $required = is_array($schema['required'] ?? null) ? $schema['required'] : [];
        foreach ($required as $req) {
            if (is_string($req) && $req !== '' && (!is_array($value) || !array_key_exists($req, $value))) {
                $errors[] = "{$path}.{$req} is required";
            }
        }
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        if (is_array($value)) {
            foreach ($properties as $prop => $propSchema) {
                if (is_string($prop) && is_array($propSchema) && array_key_exists($prop, $value)) {
                    kernelTriggerValidatePayloadSchema($value[$prop], $propSchema, $path . '.' . $prop, $errors);
                }
            }
        }
    }
    if (($schema['type'] ?? null) === 'array' && is_array($value)) {
        $itemSchema = is_array($schema['items'] ?? null) ? $schema['items'] : null;
        if ($itemSchema) {
            foreach ($value as $idx => $item) {
                kernelTriggerValidatePayloadSchema($item, $itemSchema, $path . '[' . $idx . ']', $errors);
            }
        }
    }
    return empty($errors);
}

function kernelBuildTriggerCapabilityPayload(string $eventKey, string $capabilityId, array $payload, ?string $template, array $meta = []): array
{
    $capPayload = array_merge($payload, $meta);
    $templateStr = ($template !== null && trim((string)$template) !== '') ? (string)$template : '';
    if ($capabilityId === 'sms.send@1') {
        $to = trim((string)($capPayload['to'] ?? $capPayload['student_mobile'] ?? $capPayload['student_phone'] ?? $capPayload['client_number'] ?? ''));
        $message = $templateStr !== '' ? kernelTemplateReplace($templateStr, $capPayload) : trim((string)($capPayload['message'] ?? ''));
        $capPayload = ['to' => $to, 'message' => $message, 'recipient_name' => (string)($capPayload['recipient_name'] ?? ''), 'trigger_event' => $eventKey];
    } elseif ($templateStr !== '') {
        $capPayload['_template'] = $templateStr;
    }
    $capPayload['trigger_event'] = $eventKey;
    $ref = $payload['appointment_id'] ?? $payload['id'] ?? $payload['ref_id'] ?? null;
    if ($ref !== null && (is_string($ref) || is_int($ref))) {
        $capPayload['trigger_ref_id'] = (string)$ref;
    }
    return $capPayload;
}

function kernelValidateTriggerConfig(string $eventKey, string $capabilityId, ?string $template = null, ?array $meta = null, ?string $provider = null): array
{
    $errors = [];
    $availableVars = kernelEventAvailableVars($eventKey);
    $templateVars = kernelTriggerTemplateVariables((string)($template ?? ''));
    if (!empty($availableVars) && !empty($templateVars)) {
        $missingVars = array_values(array_diff($templateVars, $availableVars));
        if (!empty($missingVars)) {
            $errors[] = 'Unknown template variables: ' . implode(', ', $missingVars);
        }
    }
    $schema = kernelCapabilityInputSchema($capabilityId, $provider);
    if ($schema !== null) {
        $samplePayload = [];
        foreach ($availableVars as $var) {
            $samplePayload[$var] = '';
        }
        $schemaErrors = [];
        $builtPayload = kernelBuildTriggerCapabilityPayload($eventKey, $capabilityId, $samplePayload, $template, $meta ?? []);
        if (!kernelTriggerValidatePayloadSchema($builtPayload, $schema, 'payload', $schemaErrors)) {
            foreach ($schemaErrors as $schemaError) {
                $errors[] = $schemaError;
            }
        }
    }
    return ['ok' => empty($errors), 'errors' => $errors, 'available_vars' => $availableVars, 'template_vars' => $templateVars];
}

/**
 * Upsert module-declared events into kernel_events.
 *
 * @param string $moduleId
 * @param array<int, array<string, mixed>> $events
 */
function kernelRegisterModuleEvents(string $moduleId, array $events): void
{
    $moduleId = trim($moduleId);
    if ($moduleId === '' || empty($events)) {
        return;
    }

    try {
        $pdo = app()->db();
        $stmt = $pdo->prepare(
            'INSERT INTO kernel_events (module, event_key, description, available_vars) '
            . 'VALUES (:module, :event_key, :description, :available_vars) '
            . 'ON DUPLICATE KEY UPDATE '
            . 'description = VALUES(description), '
            . 'available_vars = VALUES(available_vars), '
            . 'updated_at = CURRENT_TIMESTAMP'
        );

        foreach ($events as $e) {
            if (!is_array($e)) {
                continue;
            }
            $key = trim((string)($e['key'] ?? ''));
            if ($key === '') {
                continue;
            }

            $desc = null;
            if (isset($e['description'])) {
                $d = trim((string)$e['description']);
                if ($d !== '') {
                    $desc = $d;
                }
            }

            $vars = $e['available_vars'] ?? null;
            if ($vars !== null && !is_array($vars)) {
                $vars = null;
            }

            $stmt->execute([
                ':module' => $moduleId,
                ':event_key' => $key,
                ':description' => $desc,
                ':available_vars' => $vars !== null ? json_encode(array_values($vars)) : null,
            ]);
        }
    } catch (Throwable $e) {
        // Non-fatal: event registry is additive.
        write_log('kernelRegisterModuleEvents failed: ' . $e->getMessage(), 'warning', [
            'module' => $moduleId,
        ]);
    }
}

/**
 * Generate a correlation ID for tracing event→trigger→capability chains.
 */
function kernelCorrelationId(): string
{
    try {
        return bin2hex(random_bytes(8));
    } catch (Throwable $e) {
        return uniqid('cor_', true);
    }
}

/**
 * Preview a trigger's resolved payload without executing it.
 * Returns the validation result plus the built payload for operator inspection.
 */
function kernelTriggerPreview(string $eventKey, string $capabilityId, array $samplePayload = [], ?string $template = null, array $meta = [], ?string $provider = null): array
{
    $validation = kernelValidateTriggerConfig($eventKey, $capabilityId, $template, $meta, $provider);
    $builtPayload = kernelBuildTriggerCapabilityPayload($eventKey, $capabilityId, $samplePayload, $template, $meta);

    return [
        'ok' => !empty($validation['ok']),
        'errors' => $validation['errors'] ?? [],
        'available_vars' => $validation['available_vars'] ?? [],
        'template_vars' => $validation['template_vars'] ?? [],
        'resolved_payload' => $builtPayload,
        'target_capability' => $capabilityId,
        'target_provider' => $provider,
        'source_event' => $eventKey,
    ];
}

/**
 * Emit a module event through the kernel trigger system.
 */
function kernelEmitEvent(string $eventKey, array $payload = [], string $module = ''): void
{
    $eventKey = trim($eventKey);
    if ($eventKey === '') {
        return;
    }

    $correlationId = kernelCorrelationId();
    $requestId = function_exists('request_id') ? request_id() : null;

    // Always fire the kernel EventBus so module-to-module listeners work.
    app()->events()->fire($eventKey, $payload, $module);

    // Dispatch capability triggers
    try {
        $stmt = app()->db()->prepare(
            "SELECT * FROM kernel_event_triggers\n"
            . "WHERE event_key = ? AND is_enabled = 1\n"
            . "ORDER BY priority ASC, id ASC"
        );
        $stmt->execute([$eventKey]);
        $triggers = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        write_log("kernelEmitEvent: trigger lookup failed for '{$eventKey}': " . $e->getMessage(), 'error', [
            'correlation_id' => $correlationId,
        ]);
        return;
    }

    foreach ($triggers as $trigger) {
        if (!is_array($trigger)) {
            continue;
        }

        $capId = trim((string)($trigger['capability_id'] ?? ''));
        if ($capId === '') {
            continue;
        }

        $triggerId = (int)($trigger['id'] ?? 0);
        $template = $trigger['template'] ?? null;
        $meta = [];
        if (isset($trigger['meta']) && $trigger['meta'] !== null && $trigger['meta'] !== '') {
            $decoded = json_decode((string)$trigger['meta'], true);
            if (is_array($decoded)) {
                $meta = $decoded;
            }
        }

        $validation = kernelValidateTriggerConfig($eventKey, $capId, is_string($template) ? $template : null, $meta, isset($trigger['provider']) ? (string)$trigger['provider'] : null);
        if (empty($validation['ok'])) {
            write_log("kernelEmitEvent: skipped invalid trigger for '{$eventKey}' -> '{$capId}': " . implode('; ', $validation['errors'] ?? []), 'warning', [
                'event' => $eventKey,
                'capability' => $capId,
                'module' => $module,
                'trigger_id' => $triggerId,
                'correlation_id' => $correlationId,
            ]);
            continue;
        }

        // Rate limiting: skip trigger if max_per_minute exceeded
        $maxPerMin = isset($trigger['max_per_minute']) ? (int)$trigger['max_per_minute'] : 0;
        if ($maxPerMin > 0) {
            try {
                $rlId = 'trigger:' . $triggerId;
                $rlStmt = app()->db()->prepare(
                    'SELECT attempts, window_start FROM rate_limits WHERE identifier = :id AND action = :action LIMIT 1'
                );
                $rlStmt->execute([':id' => $rlId, ':action' => 'trigger_dispatch']);
                $rlRow = $rlStmt->fetch(PDO::FETCH_ASSOC);
                $rlCutoff = date('Y-m-d H:i:s', time() - 60);

                if (is_array($rlRow) && ($rlRow['window_start'] ?? '') >= $rlCutoff && (int)($rlRow['attempts'] ?? 0) >= $maxPerMin) {
                    write_log('trigger.rate_limited', 'warning', [
                        'correlation_id' => $correlationId,
                        'trigger_id' => $triggerId,
                        'event' => $eventKey,
                        'capability' => $capId,
                        'max_per_minute' => $maxPerMin,
                    ]);
                    continue;
                }

                app()->db()->prepare(
                    'INSERT INTO rate_limits (identifier, action, attempts, window_start) '
                    . 'VALUES (:id, :action, 1, CURRENT_TIMESTAMP) '
                    . 'ON DUPLICATE KEY UPDATE '
                    . 'attempts = IF(window_start >= :cutoff, attempts + 1, 1), '
                    . 'window_start = IF(window_start >= :cutoff2, window_start, CURRENT_TIMESTAMP)'
                )->execute([':id' => $rlId, ':action' => 'trigger_dispatch', ':cutoff' => $rlCutoff, ':cutoff2' => $rlCutoff]);
            } catch (Throwable $e) {
                // Non-fatal: allow trigger if rate_limits table doesn't exist
            }
        }

        $capPayload = kernelBuildTriggerCapabilityPayload($eventKey, $capId, $payload, is_string($template) ? $template : null, $meta);

        $t0 = microtime(true);
        $triggerOk = false;
        $triggerError = null;
        try {
            app()->cap()->call($capId, $capPayload, [
                'caller' => $module !== '' ? $module : '_kernel',
                'correlation_id' => $correlationId,
                'request_id' => $requestId,
            ]);
            $triggerOk = true;
        } catch (Throwable $e) {
            $triggerError = $e->getMessage();
            // Continue: one failed trigger must not block others.
        }

        $durationMs = (int)round((microtime(true) - $t0) * 1000);
        write_log('trigger.execution', $triggerOk ? 'info' : 'error', [
            'correlation_id' => $correlationId,
            'request_id' => $requestId,
            'event' => $eventKey,
            'capability' => $capId,
            'trigger_id' => $triggerId,
            'module' => $module,
            'ok' => $triggerOk,
            'duration_ms' => $durationMs,
            'error' => $triggerError,
        ]);
    }
}

/**
 * Check if a trigger is enabled for a given module event + capability pair.
 * Defaults to true (enabled) if the row does not exist yet (opt-out model).
 */
function kernelTriggerEnabled(string $eventKey, string $capabilityId): bool
{
    $eventKey = trim($eventKey);
    $capabilityId = trim($capabilityId);
    if ($eventKey === '' || $capabilityId === '') {
        return true;
    }

    try {
        $stmt = app()->db()->prepare(
            'SELECT is_enabled FROM kernel_event_triggers WHERE event_key = ? AND capability_id = ? LIMIT 1'
        );
        $stmt->execute([$eventKey, $capabilityId]);
        $row = $stmt->fetchColumn();
        if ($row === false) {
            return true;
        }
        return (bool)(int)$row;
    } catch (Throwable $e) {
        return true;
    }
}

/**
 * Get the template string for a trigger, or null if not configured.
 */
function kernelTriggerTemplate(string $eventKey, string $capabilityId): ?string
{
    $eventKey = trim($eventKey);
    $capabilityId = trim($capabilityId);
    if ($eventKey === '' || $capabilityId === '') {
        return null;
    }

    try {
        $stmt = app()->db()->prepare(
            'SELECT template FROM kernel_event_triggers WHERE event_key = ? AND capability_id = ? AND is_enabled = 1 LIMIT 1'
        );
        $stmt->execute([$eventKey, $capabilityId]);
        $raw = $stmt->fetchColumn();
        $tpl = ($raw !== false && $raw !== null) ? trim((string)$raw) : '';
        return $tpl !== '' ? $tpl : null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Get full trigger config row.
 *
 * @return array<string, mixed>|null
 */
function kernelTriggerConfig(string $eventKey, string $capabilityId): ?array
{
    $eventKey = trim($eventKey);
    $capabilityId = trim($capabilityId);
    if ($eventKey === '' || $capabilityId === '') {
        return null;
    }

    try {
        $stmt = app()->db()->prepare(
            'SELECT * FROM kernel_event_triggers WHERE event_key = ? AND capability_id = ? LIMIT 1'
        );
        $stmt->execute([$eventKey, $capabilityId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Upsert a trigger row. Used by admin UI and AI module suggestions.
 */
function kernelTriggerSave(
    string $module,
    string $eventKey,
    string $capabilityId,
    bool $isEnabled,
    ?string $template = null,
    ?array $meta = null,
    ?int $updatedBy = null,
    ?int $priority = null,
    ?int $maxPerMinute = null,
    ?int $retryCount = null,
    ?int $timeoutMs = null,
    ?string $provider = null
): bool {
    $module = trim($module);
    $eventKey = trim($eventKey);
    $capabilityId = trim($capabilityId);

    if ($module === '' || $eventKey === '' || $capabilityId === '') {
        return false;
    }

    $validation = kernelValidateTriggerConfig($eventKey, $capabilityId, $template, $meta, $provider);
    if (empty($validation['ok'])) {
        write_log("kernelTriggerSave rejected invalid trigger '{$eventKey}' -> '{$capabilityId}': " . implode('; ', $validation['errors'] ?? []), 'warning', [
            'module' => $module,
            'event' => $eventKey,
            'capability' => $capabilityId,
        ]);
        return false;
    }

    try {
        $stmt = app()->db()->prepare(
            "INSERT INTO kernel_event_triggers\n"
            . "    (module, event_key, capability_id, provider, is_enabled, priority, template, max_per_minute, retry_count, timeout_ms, meta, updated_by, created_at)\n"
            . "VALUES\n"
            . "    (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())\n"
            . "ON DUPLICATE KEY UPDATE\n"
            . "    provider        = VALUES(provider),\n"
            . "    is_enabled      = VALUES(is_enabled),\n"
            . "    priority        = VALUES(priority),\n"
            . "    template        = VALUES(template),\n"
            . "    max_per_minute  = VALUES(max_per_minute),\n"
            . "    retry_count     = VALUES(retry_count),\n"
            . "    timeout_ms      = VALUES(timeout_ms),\n"
            . "    meta            = VALUES(meta),\n"
            . "    updated_by      = VALUES(updated_by),\n"
            . "    updated_at      = NOW()"
        );

        $stmt->execute([
            $module,
            $eventKey,
            $capabilityId,
            $provider,
            (int)$isEnabled,
            $priority ?? 100,
            $template,
            $maxPerMinute,
            $retryCount ?? 0,
            $timeoutMs ?? 5000,
            $meta !== null ? json_encode($meta) : null,
            $updatedBy,
        ]);

        return true;
    } catch (Throwable $e) {
        return false;
    }
}
