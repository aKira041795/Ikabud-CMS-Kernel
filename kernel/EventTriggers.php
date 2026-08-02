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

/** @var array<string, array<int, string>>|null Per-request cache for kernelEventAvailableVars(). Cleared on event registry flush. */
function &kernelEventAvailableVarsCache(): array
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
    }
    return $cache;
}

/** Invalidate the per-request cache for a specific event (or all if empty). */
function kernelEventAvailableVarsCacheClear(string $eventKey = ''): void
{
    $cache = &kernelEventAvailableVarsCache();
    if ($eventKey === '') {
        $cache = [];
        return;
    }
    unset($cache[$eventKey]);
}

function kernelEventAvailableVars(string $eventKey): array
{
    $eventKey = trim($eventKey);
    if ($eventKey === '') {
        return [];
    }

    // Per-request cache: avoid DB query on every trigger validation/fire.
    $cache = &kernelEventAvailableVarsCache();
    if (array_key_exists($eventKey, $cache)) {
        return $cache[$eventKey];
    }

    try {
        \Ikabud\Kernel\Database\KernelPDO::kernelEscalationEnter();
        try {
            $stmt = app()->db()->prepare('SELECT available_vars FROM kernel_events WHERE event_key = ? LIMIT 1');
            $stmt->execute([$eventKey]);
            $raw = $stmt->fetchColumn();
        } finally {
            \Ikabud\Kernel\Database\KernelPDO::kernelEscalationLeave();
        }
        if ($raw === false || $raw === null || trim((string)$raw) === '') {
            // Fallback: check deferred registrations not yet flushed to DB
            $pending = app()->triggers()->getPendingRegistrations();
            foreach ($pending as $moduleEvents) {
                foreach ($moduleEvents as $e) {
                    if (is_array($e) && trim((string)($e['key'] ?? '')) === $eventKey) {
                        $vars = $e['available_vars'] ?? null;
                        if (is_array($vars)) {
                            $vars = array_values(array_unique(array_filter($vars, fn($v) => is_string($v) && trim($v) !== '')));
                            sort($vars);
                            $cache[$eventKey] = $vars;
                            return $vars;
                        }
                    }
                }
            }
            $cache[$eventKey] = [];
            return [];
        }
        $decoded = json_decode((string)$raw, true);
        if (!is_array($decoded)) {
            $cache[$eventKey] = [];
            return [];
        }
        $vars = array_values(array_unique(array_filter($decoded, fn($v) => is_string($v) && trim($v) !== '')));
        sort($vars);
        $cache[$eventKey] = $vars;
        return $vars;
    } catch (Throwable $e) {
        $cache[$eventKey] = [];
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
function kernelEventRegistrySyncTtl(): int
{
    return max(0, (int)($_ENV['KERNEL_EVENT_REGISTRY_SYNC_TTL'] ?? 300));
}

function kernelEventRegistrySyncInstance(): string
{
    $tenantId = app()->tenant()->current();
    return 'kernel_event_registry_t' . ($tenantId ?? 0);
}

function kernelEventRegistrySyncKey(string $moduleId, array $events): string
{
    return 'events:' . $moduleId . ':' . sha1((string)json_encode($events, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

function kernelRegisterModuleEvents(string $moduleId, array $events): void
{
    $moduleId = trim($moduleId);
    if ($moduleId === '' || empty($events)) {
        return;
    }

    require_once dirname(__DIR__) . '/src/helpers/manifest-validation.php';
    $diagnostics = validateModuleEventDeclarationsV1($events);
    if ($diagnostics !== []) {
        $diagnostic = $diagnostics[0];
        throw new RuntimeException(
            "[fatal] Module '{$moduleId}' has malformed events declaration at {$diagnostic['field']}: "
            . $diagnostic['message'] . ' Correction: ' . $diagnostic['correction']
        );
    }

    // Collect events for deferred batch sync instead of syncing per-module
    app()->triggers()->addPendingRegistration($moduleId, $events);
}

/**
 * Flush all pending module event registrations in a single batch.
 * Called once after all modules have been loaded (from loadModuleRoutes).
 */
function kernelFlushPendingEventRegistrations(): void
{
    $pending = app()->triggers()->consumePendingRegistrations();
    if (empty($pending)) {
        return;
    }

    // Build a single composite cache key from all pending modules+events
    $syncTtl = kernelEventRegistrySyncTtl();
    if ($syncTtl > 0) {
        $compositeHash = sha1((string)json_encode($pending, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $compositeKey = 'events:batch:' . $compositeHash;
        $cached = app()->cache()->get(kernelEventRegistrySyncInstance(), $compositeKey);
        if (is_array($cached) && !empty($cached['synced'])) {
            return; // All events already synced
        }
    }

    $register = static function () use ($pending): void {
        $pdo = app()->db();
        $stmt = $pdo->prepare(
            'INSERT INTO kernel_events (module, event_key, description, available_vars) '
            . 'VALUES (:module, :event_key, :description, :available_vars) '
            . 'ON DUPLICATE KEY UPDATE '
            . 'description = VALUES(description), '
            . 'available_vars = VALUES(available_vars), '
            . 'updated_at = CURRENT_TIMESTAMP'
        );

        foreach ($pending as $moduleId => $events) {
            foreach ($events as $e) {
                $key = trim((string)($e['key'] ?? ''));

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
        }
    };

    try {
        $register();
        if ($syncTtl > 0) {
            app()->cache()->set(kernelEventRegistrySyncInstance(), $compositeKey, ['synced' => true], $syncTtl);
        }
        // Invalidate per-request event-vars cache so next kernelEventAvailableVars() reads fresh DB state.
        kernelEventAvailableVarsCacheClear();
    } catch (Throwable $e) {
        if (\dbConnectionLost($e)) {
            try {
                app()->reconnectDb();
                $register();
                if ($syncTtl > 0) {
                    app()->cache()->set(kernelEventRegistrySyncInstance(), $compositeKey, ['synced' => true], $syncTtl);
                }
                write_log('kernelFlushPendingEventRegistrations recovered after DB reconnect', 'info');
                return;
            } catch (Throwable $retryError) {
                $e = $retryError;
            }
        }

        // Registry persistence remains additive; malformed declarations have
        // already failed before they can enter this batch.
        write_log('[advisory] kernelFlushPendingEventRegistrations failed: ' . $e->getMessage(), 'warning', [
            'severity' => \Ikabud\Kernel\Contracts\DiagnosticSeverity::Advisory->value,
            'modules' => array_keys($pending),
            'correction' => 'Restore tenant database connectivity and rerun module boot to synchronize declared events.',
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
 * Persist trigger execution history for control-plane traces.
 * Best-effort only: failures must never block runtime dispatch.
 *
 * @param array<string, mixed> $execution
 */
function kernelTriggerRecordExecution(array $execution): void
{
    try {
        \Ikabud\Kernel\Database\KernelPDO::kernelEscalationEnter();
        try {
            // Retry loop for "2014 Cannot execute queries" errors caused by
            // EventBus listeners that leave unbuffered result sets open.
            $maxAttempts = 3;
            $attempt = 0;
            $lastError = null;

            while ($attempt < $maxAttempts) {
                $attempt++;
                try {
                    app()->db()->prepare(
                        'INSERT INTO kernel_trigger_executions '
                        . '(trigger_id, module, event_key, capability_id, provider, status, request_id, correlation_id, external_reference, duration_ms, error_message, event_payload, capability_payload, result_payload, created_at) '
                        . 'VALUES (:trigger_id, :module, :event_key, :capability_id, :provider, :status, :request_id, :correlation_id, :external_reference, :duration_ms, :error_message, :event_payload, :capability_payload, :result_payload, NOW())'
                    )->execute([
                        ':trigger_id' => isset($execution['trigger_id']) ? (int)$execution['trigger_id'] : null,
                        ':module' => trim((string)($execution['module'] ?? '')),
                        ':event_key' => trim((string)($execution['event_key'] ?? '')),
                        ':capability_id' => trim((string)($execution['capability_id'] ?? '')),
                        ':provider' => ($execution['provider'] ?? null) !== null ? trim((string)$execution['provider']) : null,
                        ':status' => trim((string)($execution['status'] ?? 'unknown')) ?: 'unknown',
                        ':request_id' => ($execution['request_id'] ?? null) !== null ? trim((string)$execution['request_id']) : null,
                        ':correlation_id' => ($execution['correlation_id'] ?? null) !== null ? trim((string)$execution['correlation_id']) : null,
                        ':external_reference' => ($execution['external_reference'] ?? null) !== null ? trim((string)$execution['external_reference']) : null,
                        ':duration_ms' => isset($execution['duration_ms']) ? (int)$execution['duration_ms'] : null,
                        ':error_message' => ($execution['error_message'] ?? null) !== null ? (string)$execution['error_message'] : null,
                        ':event_payload' => json_encode($execution['event_payload'] ?? null, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                        ':capability_payload' => json_encode($execution['capability_payload'] ?? null, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                        ':result_payload' => json_encode($execution['result_payload'] ?? null, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    ]);
                    $lastError = null; // success
                    break;
                } catch (PDOException $pdoe) {
                    $lastError = $pdoe;
                    // Only retry on "2014 Cannot execute queries"
                    if (str_contains($pdoe->getMessage(), '2014') || str_contains($pdoe->getMessage(), 'Cannot execute queries')) {
                        // Consume any pending unbuffered result set
                        try {
                            app()->db()->query('SELECT 1')->fetchAll();
                        } catch (Throwable) {
                            // If this also fails, reconnect
                            try {
                                app()->reconnectDb();
                            } catch (Throwable) {
                            }
                        }
                        continue;
                    }
                    throw $pdoe; // non-2014: re-throw
                }
            }

            if ($lastError !== null) {
                throw $lastError;
            }
        } finally {
            \Ikabud\Kernel\Database\KernelPDO::kernelEscalationLeave();
        }
    } catch (Throwable $e) {
        // Ignore persistence failures: execution logging is additive only.
    }
}

function kernelTriggerExtractExternalReference(array $eventPayload, ?array $capabilityPayload = null, mixed $resultPayload = null): ?string
{
    $extract = static function (mixed $value, string $path = '') use (&$extract): ?string {
        if (!is_array($value)) {
            return null;
        }

        $directKeys = ['external_reference', 'ecommerce_order_number'];
        foreach ($directKeys as $key) {
            if (isset($value[$key]) && (is_string($value[$key]) || is_int($value[$key]))) {
                $candidate = trim((string)$value[$key]);
                if ($candidate !== '') {
                    return $candidate;
                }
            }
        }

        if ($path === 'order.' && isset($value['order_number']) && (is_string($value['order_number']) || is_int($value['order_number']))) {
            $candidate = trim((string)$value['order_number']);
            if ($candidate !== '') {
                return $candidate;
            }
        }

        foreach ($value as $key => $child) {
            if (!is_string($key) || !is_array($child)) {
                continue;
            }

            $nextPath = $path . $key . '.';
            if (substr_count($nextPath, '.') > 4) {
                continue;
            }

            $found = $extract($child, $nextPath);
            if (is_string($found) && $found !== '') {
                return $found;
            }
        }

        return null;
    };

    foreach ([$eventPayload, $capabilityPayload, is_array($resultPayload) ? $resultPayload : null] as $candidate) {
        $found = $extract($candidate, '');
        if (is_string($found) && $found !== '') {
            return $found;
        }
    }

    return null;
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

    // Dispatch capability triggers — use per-request cache to avoid DB query per fire.
    // Do this BEFORE events()->fire() to avoid "2014 Cannot execute queries" errors
    // caused by EventBus listeners that leave unbuffered result sets open.
    try {
        // Check per-request cache first.
        $svc = app()->triggers();
        if ($svc->hasCachedTriggers($eventKey)) {
            $triggers = $svc->getCachedTriggers($eventKey);
        } else {
            // kernel_event_triggers is a kernel-owned table; escalate to bypass
            // module-level ModuleDB access enforcement for this kernel-internal query.
            \Ikabud\Kernel\Database\KernelPDO::kernelEscalationEnter();
            try {
                $stmt = app()->db()->prepare(
                    "SELECT * FROM kernel_event_triggers\n"
                    . "WHERE event_key = ? AND is_enabled = 1\n"
                    . "ORDER BY priority ASC, id ASC"
                );
                $stmt->execute([$eventKey]);
                $triggers = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } finally {
                \Ikabud\Kernel\Database\KernelPDO::kernelEscalationLeave();
            }
            // Cache for subsequent fires of the same event this request.
            $svc->cacheTriggers($eventKey, $triggers);
        }
    } catch (Throwable $e) {
        write_log("kernelEmitEvent: trigger lookup failed for '{$eventKey}': " . $e->getMessage(), 'error', [
            'correlation_id' => $correlationId,
        ]);
        return;
    }

    // Fire the kernel EventBus so module-to-module listeners work.
    // Deliberately after trigger lookup to avoid unbuffered query conflicts.
    app()->events()->fire($eventKey, $payload, $module);

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
            kernelTriggerRecordExecution([
                'trigger_id' => $triggerId,
                'module' => $module !== '' ? $module : '_kernel',
                'event_key' => $eventKey,
                'capability_id' => $capId,
                'provider' => $trigger['provider'] ?? null,
                'status' => 'skipped_invalid',
                'request_id' => $requestId,
                'correlation_id' => $correlationId,
                'external_reference' => kernelTriggerExtractExternalReference($payload),
                'duration_ms' => 0,
                'error_message' => implode('; ', $validation['errors'] ?? []),
                'event_payload' => $payload,
                'capability_payload' => null,
                'result_payload' => ['errors' => $validation['errors'] ?? []],
            ]);
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
                \Ikabud\Kernel\Database\KernelPDO::kernelEscalationEnter();
                try {
                    $rlStmt = app()->db()->prepare(
                        'SELECT attempts, window_start FROM rate_limits WHERE identifier = :id AND action = :action LIMIT 1'
                    );
                    $rlStmt->execute([':id' => $rlId, ':action' => 'trigger_dispatch']);
                    $rlRow = $rlStmt->fetch(PDO::FETCH_ASSOC);
                    $rlCutoff = date('Y-m-d H:i:s', time() - 60);

                    if (is_array($rlRow) && ($rlRow['window_start'] ?? '') >= $rlCutoff && (int)($rlRow['attempts'] ?? 0) >= $maxPerMin) {
                        kernelTriggerRecordExecution([
                            'trigger_id' => $triggerId,
                            'module' => $module !== '' ? $module : '_kernel',
                            'event_key' => $eventKey,
                            'capability_id' => $capId,
                            'provider' => $trigger['provider'] ?? null,
                            'status' => 'rate_limited',
                            'request_id' => $requestId,
                            'correlation_id' => $correlationId,
                            'external_reference' => kernelTriggerExtractExternalReference($payload),
                            'duration_ms' => 0,
                            'error_message' => 'Trigger skipped because max_per_minute limit was reached.',
                            'event_payload' => $payload,
                            'capability_payload' => null,
                            'result_payload' => ['max_per_minute' => $maxPerMin],
                        ]);
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
                } finally {
                    \Ikabud\Kernel\Database\KernelPDO::kernelEscalationLeave();
                }
            } catch (Throwable $e) {
                // Non-fatal: allow trigger if rate_limits table doesn't exist
            }
        }

        $capPayload = kernelBuildTriggerCapabilityPayload($eventKey, $capId, $payload, is_string($template) ? $template : null, $meta);

        $retryCount = max(0, (int)($trigger['retry_count'] ?? 0));
        $timeoutMs  = max(0, (int)($trigger['timeout_ms'] ?? 0));

        $t0 = microtime(true);
        $triggerOk = false;
        $triggerError = null;
        $capResult = null;
        $attempts = 0;
        $maxAttempts = $retryCount + 1; // first attempt + retries

        while ($attempts < $maxAttempts) {
            $attempts++;
            $triggerOk = false;
            $triggerError = null;
            $capResult = null;

            try {
                $capResult = app()->cap()->call($capId, $capPayload, [
                    'caller' => $module !== '' ? $module : '_kernel',
                    'correlation_id' => $correlationId,
                    'request_id' => $requestId,
                ]);
                $triggerOk = true;
                break; // success — no retry needed
            } catch (Throwable $e) {
                $triggerError = $e->getMessage();
                // Continue: one failed trigger must not block others.
            }

            // Enforce per-call timeout: if elapsed time already exceeds the
            // configured timeout, stop retrying even if retries remain.
            if ($timeoutMs > 0) {
                $elapsedMs = (microtime(true) - $t0) * 1000;
                if ($elapsedMs >= $timeoutMs) {
                    $triggerError = ($triggerError ? $triggerError . ' | ' : '')
                        . "Trigger timeout ({$timeoutMs}ms) exceeded after {$attempts} attempt(s)";
                    break;
                }
            }

            // Brief backoff between retries (50ms * attempt, capped at 200ms)
            if ($attempts < $maxAttempts) {
                usleep(min($attempts * 50000, 200000));
            }
        }

        $durationMs = (int)round((microtime(true) - $t0) * 1000);

        // Post-execution timeout warning (even on success)
        if ($timeoutMs > 0 && $durationMs > $timeoutMs && $triggerOk) {
            write_log('trigger.timeout_warning', 'warning', [
                'correlation_id' => $correlationId,
                'trigger_id' => $triggerId,
                'event' => $eventKey,
                'capability' => $capId,
                'timeout_ms' => $timeoutMs,
                'actual_ms' => $durationMs,
            ]);
        }
        kernelTriggerRecordExecution([
            'trigger_id' => $triggerId,
            'module' => $module !== '' ? $module : '_kernel',
            'event_key' => $eventKey,
            'capability_id' => $capId,
            'provider' => $trigger['provider'] ?? null,
            'status' => $triggerOk ? 'success' : 'failed',
            'request_id' => $requestId,
            'correlation_id' => $correlationId,
            'external_reference' => kernelTriggerExtractExternalReference($payload, $capPayload, $capResult),
            'duration_ms' => $durationMs,
            'error_message' => $triggerError,
            'event_payload' => $payload,
            'capability_payload' => $capPayload,
            'result_payload' => $capResult,
        ]);
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
