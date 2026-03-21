<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Capabilities;

final class CapabilityBus
{
    public function __construct(
        private readonly CapabilityRegistry $registry
    ) {
    }

    /**
     * Call a capability.
     *
     * Options:
     * - mode: first|pipeline|fanout (default first)
     * - provider: explicit provider id
     * - strict: for fanout, if true then any provider failure throws
     */
    public function call(string $capabilityId, mixed $payload = null, array $options = []): mixed
    {
        $t0 = microtime(true);
        $requestedCapabilityId = $capabilityId;
        $capabilityId = $this->registry->resolve($capabilityId);
        $mode = strtolower((string)($options['mode'] ?? 'first'));
        $provider = isset($options['provider']) ? (string)$options['provider'] : null;
        $strict = (bool)($options['strict'] ?? false);

        $caller = $this->resolveCaller($options);
        $settings = $this->resolveOptions($options, $capabilityId);

        $providers = $this->registry->providers($capabilityId);
        if (empty($providers)) {
            throw new CapabilityNotFoundException("Capability not found: {$capabilityId}");
        }

        // Enforce module-owned provider selection policy (allow/deny lists).
        $providers = $this->applyPolicy($capabilityId, $providers, (string)($caller['module'] ?? ''));
        if (empty($providers)) {
            $this->logDenied($capabilityId, $caller, 'caller_policy');
            throw new CapabilityNotFoundException("No permitted capability providers for: {$capabilityId}");
        }

        if ($provider !== null && $provider !== '') {
            $providers = array_values(array_filter($providers, fn($p) => ($p['provider'] ?? '') === $provider));
            if (empty($providers)) {
                throw new CapabilityNotFoundException("Capability provider '{$provider}' not found for: {$capabilityId}");
            }
        }

        try {
            $result = match ($mode) {
                'pipeline' => $this->callPipeline($capabilityId, $payload, $providers, $settings),
                'fanout' => $this->callFanout($capabilityId, $payload, $providers, $strict, $settings),
                'first' => $this->callFirst($capabilityId, $payload, $providers, $settings),
                default => throw new CapabilityException("Unknown capability call mode: {$mode}"),
            };

            $usedProviders = $this->providersForMode($providers, $mode);
            $this->trace($capabilityId, $mode, $provider, true, microtime(true) - $t0, null, $caller, $usedProviders, $requestedCapabilityId);
            return $result;
        } catch (\Throwable $e) {
            $usedProviders = $this->providersForMode($providers ?? [], $mode);
            $this->trace($capabilityId, $mode, $provider, false, microtime(true) - $t0, $e->getMessage(), $caller, $usedProviders, $requestedCapabilityId);
            throw $e;
        }
    }

    /**
     * @param array<int, array{provider: string, modes: string[], handler: callable, meta?: array}> $providers
     * @return array<int, array{provider: string, modes: string[], handler: callable, meta?: array}>
     */
    private function applyPolicy(string $capabilityId, array $providers, string $callerModule): array
    {
        // Policies can be supplied by module manifests via provider meta.
        // IMPORTANT: Provider-selection policy (allow_providers/deny_providers) must NOT be
        // global-by-accident. If we merge allow_providers from arbitrary modules, a single module
        // can unintentionally (or maliciously) hide other modules' providers for shared contracts
        // like kernel.auth.authenticate@1.
        //
        // Therefore:
        // - Provider-selection policy is only honored from the kernel provider.
        // - Caller policy (allow_callers/deny_callers) remains enforced as a global gate.

        $deny = [];
        $allow = null; // null means no whitelist
        // Caller policy is enforced PER-PROVIDER.
        // A module should be able to protect its own provider from certain callers,
        // but it must not be able to globally veto other providers.

        // Only the kernel provider may constrain provider selection.
        $kernelSelectionPolicies = [];
        foreach ($providers as $p) {
            if (($p['provider'] ?? '') !== 'kernel') {
                continue;
            }
            $meta = $p['meta'] ?? [];
            $policy = is_array($meta) ? ($meta['policy'] ?? null) : null;
            if (is_array($policy) && $policy !== []) {
                $kernelSelectionPolicies[] = $policy;
            }
        }

        // Apply kernel-only provider selection policies (if present).
        foreach ($kernelSelectionPolicies as $policy) {
            $default = is_array($policy['default'] ?? null) ? $policy['default'] : [];
            $perCap = is_array($policy['capabilities'] ?? null) ? $policy['capabilities'] : [];
            $rule = [];
            if (isset($perCap[$capabilityId]) && is_array($perCap[$capabilityId])) {
                $rule = $perCap[$capabilityId];
            }

            $denyList = [];
            if (is_array($default) && isset($default['deny_providers']) && is_array($default['deny_providers'])) {
                $denyList = array_merge($denyList, $default['deny_providers']);
            }
            if (isset($rule['deny_providers']) && is_array($rule['deny_providers'])) {
                $denyList = array_merge($denyList, $rule['deny_providers']);
            }
            foreach ($denyList as $d) {
                if (is_string($d) && $d !== '') $deny[$d] = true;
            }

            $allowList = [];
            if (is_array($default) && isset($default['allow_providers']) && is_array($default['allow_providers'])) {
                $allowList = array_merge($allowList, $default['allow_providers']);
            }
            if (isset($rule['allow_providers']) && is_array($rule['allow_providers'])) {
                $allowList = array_merge($allowList, $rule['allow_providers']);
            }
            $allowList = array_values(array_filter($allowList, fn($x) => is_string($x) && $x !== ''));
            if (!empty($allowList)) {
                $allow = $allow ?? [];
                foreach ($allowList as $a) {
                    $allow[$a] = true;
                }
            }
        }

        $out = [];
        foreach ($providers as $p) {
            $pid = (string)($p['provider'] ?? '');
            if ($pid === '') continue;

            // Enforce caller policy per provider
            if ($callerModule !== '') {
                $meta = $p['meta'] ?? [];
                $policy = is_array($meta) ? ($meta['policy'] ?? null) : null;
                if (is_array($policy) && $policy !== []) {
                    $default = is_array($policy['default'] ?? null) ? $policy['default'] : [];
                    $perCap = is_array($policy['capabilities'] ?? null) ? $policy['capabilities'] : [];
                    $rule = [];
                    if (isset($perCap[$capabilityId]) && is_array($perCap[$capabilityId])) {
                        $rule = $perCap[$capabilityId];
                    }

                    $denyCallerList = [];
                    if (is_array($default) && isset($default['deny_callers']) && is_array($default['deny_callers'])) {
                        $denyCallerList = array_merge($denyCallerList, $default['deny_callers']);
                    }
                    if (isset($rule['deny_callers']) && is_array($rule['deny_callers'])) {
                        $denyCallerList = array_merge($denyCallerList, $rule['deny_callers']);
                    }
                    $denyCallerList = array_values(array_filter($denyCallerList, fn($x) => is_string($x) && $x !== ''));
                    if (!empty($denyCallerList) && in_array($callerModule, $denyCallerList, true)) {
                        continue;
                    }

                    $allowCallerList = [];
                    if (is_array($default) && isset($default['allow_callers']) && is_array($default['allow_callers'])) {
                        $allowCallerList = array_merge($allowCallerList, $default['allow_callers']);
                    }
                    if (isset($rule['allow_callers']) && is_array($rule['allow_callers'])) {
                        $allowCallerList = array_merge($allowCallerList, $rule['allow_callers']);
                    }
                    $allowCallerList = array_values(array_filter($allowCallerList, fn($x) => is_string($x) && $x !== ''));
                    if (!empty($allowCallerList) && !in_array($callerModule, $allowCallerList, true)) {
                        continue;
                    }
                }
            }

            if (isset($deny[$pid])) {
                continue;
            }
            if (is_array($allow) && !isset($allow[$pid])) {
                continue;
            }
            $out[] = $p;
        }
        return $out;
    }

    private function trace(
        string $capabilityId,
        string $mode,
        ?string $requestedProvider,
        bool $ok,
        float $durationSec,
        ?string $error,
        array $caller = [],
        array $usedProviders = [],
        ?string $requestedCapabilityId = null
    ): void
    {
        try {
            $requestId = $caller['request_id'] ?? (function_exists('request_id') ? request_id() : null);
            $callerModule = $caller['module'] ?? null;
            $callerUser = $caller['user'] ?? null;
            $callerUserId = is_array($callerUser) ? ($callerUser['id'] ?? $callerUser['sub'] ?? null) : null;
            $callerRole = is_array($callerUser) ? ($callerUser['role'] ?? null) : null;
            $providers = array_values(array_filter($usedProviders, fn($p) => is_string($p) && $p !== ''));

            // app() helper exists in this project via bootstrap.php
            app()->log('capability.call', $ok ? 'info' : 'error', [
                'capability_id' => $capabilityId,
                'requested_capability_id' => $requestedCapabilityId !== $capabilityId ? $requestedCapabilityId : null,
                'mode' => $mode,
                'requested_provider' => $requestedProvider,
                'providers' => $providers,
                'caller_module' => $callerModule,
                'caller_user_id' => $callerUserId,
                'caller_role' => $callerRole,
                'request_id' => $requestId,
                'ok' => $ok,
                'duration_ms' => (int)round($durationSec * 1000),
                'error' => $error,
            ]);
        } catch (\Throwable $e) {
            // Never fail capability calls due to tracing.
        }
    }

    /**
     * @param array<int, array{provider: string, modes: string[], handler: callable}> $providers
     */
    private function callFirst(string $capabilityId, mixed $payload, array $providers, array $options): mixed
    {
        $p = $providers[0];
        if (!$this->supportsMode($p, 'first')) {
            throw new CapabilityCallException("Provider does not support mode 'first'", $capabilityId, (string)$p['provider']);
        }

        return $this->callProvider($capabilityId, $payload, $p, $options);
    }

    /**
     * Pipeline: each provider receives previous output.
     *
     * Convention: a provider can return null to mean "no change" and the pipeline continues.
     * This is important for authenticate() style chains.
     *
     * @param array<int, array{provider: string, modes: string[], handler: callable}> $providers
     */
    private function callPipeline(string $capabilityId, mixed $payload, array $providers, array $options): mixed
    {
        $value = $payload;
        $changed = false;
        $strict = (bool)($options['strict_pipeline'] ?? false);
        foreach ($providers as $p) {
            if (!$this->supportsMode($p, 'pipeline')) {
                continue;
            }

            try {
                $out = $this->callProvider($capabilityId, $value, $p, $options);
                if ($out !== null) {
                    $value = $out;
                    $changed = true;
                }
            } catch (\Throwable $e) {
                if ($strict) {
                    throw new CapabilityCallException("Capability pipeline provider failed", $capabilityId, (string)$p['provider'], $e);
                }
                // Non-strict pipeline: a failing provider is treated as "no change".
                // This is important for auth pipelines where some providers may be misconfigured
                // or temporarily unhealthy; we should continue to the next provider.
                continue;
            }
        }
        return $changed ? $value : null;
    }

    /**
     * Fanout: call all providers and return a summary.
     *
     * @param array<int, array{provider: string, modes: string[], handler: callable}> $providers
     */
    private function callFanout(string $capabilityId, mixed $payload, array $providers, bool $strict, array $options): array
    {
        $results = [];
        $errors = [];

        foreach ($providers as $p) {
            if (!$this->supportsMode($p, 'fanout')) {
                continue;
            }

            try {
                $results[(string)$p['provider']] = $this->callProvider($capabilityId, $payload, $p, $options);
            } catch (\Throwable $e) {
                $errors[(string)$p['provider']] = $e->getMessage();
                if ($strict) {
                    throw new CapabilityCallException("Capability fanout provider failed", $capabilityId, (string)$p['provider'], $e);
                }
            }
        }

        return ['results' => $results, 'errors' => $errors];
    }

    private function callProvider(string $capabilityId, mixed $payload, array $provider, array $options): mixed
    {
        $providerId = (string)($provider['provider'] ?? '');
        $settings = $this->resolveProviderOptions($provider, $options);
        $caller = $this->resolveCaller($options);

        $this->assertSchema($capabilityId, $payload, $provider, 'input', $settings);

        if ($this->isBreakerOpen($capabilityId, $providerId)) {
            throw new CapabilityCallException('Capability circuit open', $capabilityId, $providerId);
        }

        $attempts = max(1, 1 + max(0, (int)$settings['retries']));
        $lastError = null;

        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            $t0 = microtime(true);
            $previousContext = $GLOBALS['_capability_call_context'] ?? null;
            $GLOBALS['_capability_call_context'] = [
                'module' => $caller['module'] ?? 'kernel',
                'user' => $caller['user'] ?? null,
                'request_id' => $caller['request_id'] ?? null,
            ];
            try {
                if ($providerId !== '' && $providerId !== 'kernel' && \function_exists('moduleWithContext')) {
                    $result = \moduleWithContext($providerId, static function () use ($provider, $payload, $capabilityId, $providerId): mixed {
                        return ($provider['handler'])($payload, $capabilityId, $providerId);
                    });
                } else {
                    $result = ($provider['handler'])($payload, $capabilityId, $providerId);
                }
                $durationMs = (int)round((microtime(true) - $t0) * 1000);
                if ($durationMs > (int)$settings['timeout_ms']) {
                    throw new CapabilityCallException('Capability call timed out', $capabilityId, $providerId);
                }

                $this->assertSchema($capabilityId, $result, $provider, 'output', $settings);

                $this->recordBreakerSuccess($capabilityId, $providerId);
                $this->updateMetrics($capabilityId, $providerId, $durationMs, true, (int)$settings['metrics_max_samples']);
                return $result;
            } catch (\Throwable $e) {
                $durationMs = (int)round((microtime(true) - $t0) * 1000);
                $this->recordBreakerFailure($capabilityId, $providerId, $settings);
                $this->updateMetrics($capabilityId, $providerId, $durationMs, false, (int)$settings['metrics_max_samples']);

                $lastError = $e instanceof CapabilityCallException
                    ? $e
                    : new CapabilityCallException('Capability call failed', $capabilityId, $providerId, $e);

                if ($attempt < $attempts - 1 && (int)$settings['retry_delay_ms'] > 0) {
                    usleep((int)$settings['retry_delay_ms'] * 1000);
                }
            } finally {
                if ($previousContext === null) {
                    unset($GLOBALS['_capability_call_context']);
                } else {
                    $GLOBALS['_capability_call_context'] = $previousContext;
                }
            }
        }

        if ($lastError instanceof \Throwable) {
            throw $lastError;
        }

        throw new CapabilityCallException('Capability call failed', $capabilityId, $providerId);
    }

    private function assertSchema(string $capabilityId, mixed $value, array $provider, string $direction, array $settings): void
    {
        $meta = is_array($provider['meta'] ?? null) ? $provider['meta'] : [];
        $schema = $this->schemaForDirection(is_array($meta['schema'] ?? null) ? $meta['schema'] : null, $direction);
        if ($schema === null) {
            return;
        }

        $errors = [];
        $rootPath = $direction === 'output' ? 'result' : 'payload';
        if (!$this->validateSchema($value, $schema, $rootPath, $errors)) {
            $this->handleSchemaViolation(
                $capabilityId,
                (string)($provider['provider'] ?? ''),
                $direction,
                $errors,
                $settings
            );
        }
    }

    private function schemaForDirection(?array $schema, string $direction): ?array
    {
        if ($schema === null || $schema === []) {
            return null;
        }

        if (isset($schema['input']) || isset($schema['output'])) {
            $directionSchema = $schema[$direction] ?? null;
            return is_array($directionSchema) ? $directionSchema : null;
        }

        return $direction === 'input' ? $schema : null;
    }

    private function handleSchemaViolation(string $capabilityId, string $providerId, string $direction, array $errors, array $settings): void
    {
        $message = 'Capability ' . $direction . ' schema validation failed: ' . implode('; ', $errors);
        $mode = strtolower((string)($settings['schema_validation_mode'] ?? 'warn'));

        if ($mode === 'enforce') {
            throw new CapabilityCallException($message, $capabilityId, $providerId);
        }

        $this->logSchemaViolation($capabilityId, $providerId, $direction, $errors);
    }

    private function logSchemaViolation(string $capabilityId, string $providerId, string $direction, array $errors): void
    {
        try {
            $ctx = $this->resolveCaller([]);
            app()->log('capability.schema_violation', 'warning', [
                'capability_id' => $capabilityId,
                'provider' => $providerId,
                'direction' => $direction,
                'errors' => array_values($errors),
                'caller_module' => $ctx['module'] ?? null,
                'caller_user_id' => is_array($ctx['user'] ?? null) ? ($ctx['user']['id'] ?? $ctx['user']['sub'] ?? null) : null,
                'request_id' => $ctx['request_id'] ?? (function_exists('request_id') ? request_id() : null),
            ]);
        } catch (\Throwable $e) {
        }
    }

    private function validateSchema(mixed $value, array $schema, string $path, array &$errors): bool
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
                    if (!is_string($prop) || !is_array($propSchema)) {
                        continue;
                    }
                    if (array_key_exists($prop, $value)) {
                        $this->validateSchema($value[$prop], $propSchema, $path . '.' . $prop, $errors);
                    }
                }
            }
        }

        if (($schema['type'] ?? null) === 'array' && is_array($value)) {
            $itemSchema = is_array($schema['items'] ?? null) ? $schema['items'] : null;
            if ($itemSchema) {
                foreach ($value as $idx => $item) {
                    $this->validateSchema($item, $itemSchema, $path . '[' . $idx . ']', $errors);
                }
            }
        }

        return empty($errors);
    }

    private function resolveCaller(array $options): array
    {
        $ctx = function_exists('capability_call_context') ? capability_call_context() : null;
        $module = $options['caller_module'] ?? ($ctx['module'] ?? 'kernel');
        $user = $options['caller_user'] ?? ($ctx['user'] ?? (function_exists('app') ? app()->user() : null));
        $requestId = $options['request_id'] ?? ($ctx['request_id'] ?? (function_exists('request_id') ? request_id() : null));

        return [
            'module' => is_string($module) ? $module : 'kernel',
            'user' => is_array($user) ? $user : null,
            'request_id' => is_string($requestId) ? $requestId : null,
        ];
    }

    public function resolveSchemaMode(string $capabilityId): string
    {
        $config = [];
        try {
            $config = app()->config('app.capabilities', []);
        } catch (\Throwable $e) {
            $config = [];
        }

        $perCap = is_array($config['schema_modes'] ?? null) ? $config['schema_modes'] : [];
        if (isset($perCap[$capabilityId]) && is_string($perCap[$capabilityId]) && $perCap[$capabilityId] !== '') {
            return strtolower($perCap[$capabilityId]);
        }

        return strtolower((string)($config['schema_validation_mode'] ?? 'warn'));
    }

    private function resolveOptions(array $options, ?string $capabilityId = null): array
    {
        $config = [];
        try {
            $config = app()->config('app.capabilities', []);
        } catch (\Throwable $e) {
            $config = [];
        }

        $schemaMode = (string)($options['schema_validation_mode'] ?? '');
        if ($schemaMode === '' && $capabilityId !== null) {
            $schemaMode = $this->resolveSchemaMode($capabilityId);
        }
        if ($schemaMode === '') {
            $schemaMode = (string)($config['schema_validation_mode'] ?? 'warn');
        }

        return [
            'timeout_ms' => (int)($options['timeout_ms'] ?? $config['timeout_ms'] ?? 2000),
            'retries' => (int)($options['retries'] ?? $config['retries'] ?? 0),
            'retry_delay_ms' => (int)($options['retry_delay_ms'] ?? $config['retry_delay_ms'] ?? 100),
            'breaker_threshold' => (int)($options['breaker_threshold'] ?? $config['breaker_threshold'] ?? 5),
            'breaker_window_sec' => (int)($options['breaker_window_sec'] ?? $config['breaker_window_sec'] ?? 30),
            'breaker_cooldown_sec' => (int)($options['breaker_cooldown_sec'] ?? $config['breaker_cooldown_sec'] ?? 60),
            'metrics_max_samples' => (int)($options['metrics_max_samples'] ?? $config['metrics_max_samples'] ?? 200),
            'schema_validation_mode' => $schemaMode,
        ];
    }

    private function resolveProviderOptions(array $provider, array $options): array
    {
        $meta = is_array($provider['meta'] ?? null) ? $provider['meta'] : [];

        $keys = [
            'timeout_ms',
            'retries',
            'retry_delay_ms',
            'breaker_threshold',
            'breaker_window_sec',
            'breaker_cooldown_sec',
            'metrics_max_samples',
        ];

        foreach ($keys as $key) {
            if (isset($meta[$key])) {
                $options[$key] = $meta[$key];
            }
        }

        return $options;
    }

    private function providersForMode(array $providers, string $mode): array
    {
        if ($mode === 'first') {
            $first = $providers[0] ?? null;
            return $first ? [(string)($first['provider'] ?? '')] : [];
        }

        $list = [];
        foreach ($providers as $p) {
            if ($this->supportsMode($p, $mode)) {
                $list[] = (string)($p['provider'] ?? '');
            }
        }
        return $list;
    }

    private function logDenied(string $capabilityId, array $caller, string $reason): void
    {
        try {
            app()->log('capability.denied', 'warning', [
                'capability_id' => $capabilityId,
                'caller_module' => $caller['module'] ?? null,
                'caller_user_id' => is_array($caller['user'] ?? null) ? ($caller['user']['id'] ?? $caller['user']['sub'] ?? null) : null,
                'reason' => $reason,
                'request_id' => $caller['request_id'] ?? (function_exists('request_id') ? request_id() : null),
            ]);
        } catch (\Throwable $e) {
        }
    }

    private function metricsFile(): string
    {
        $storage = app()->config('paths.storage', defined('STORAGE_PATH') ? STORAGE_PATH : __DIR__);
        return rtrim($storage, '/') . '/cache/capability_metrics.json';
    }

    private function breakerFile(): string
    {
        $storage = app()->config('paths.storage', defined('STORAGE_PATH') ? STORAGE_PATH : __DIR__);
        return rtrim($storage, '/') . '/cache/capability_breakers.json';
    }

    private function loadJsonFile(string $path): array
    {
        $data = [];
        if (is_file($path)) {
            $raw = @file_get_contents($path);
            $decoded = $raw ? json_decode($raw, true) : null;
            if (is_array($decoded)) {
                $data = $decoded;
            }
        }
        return $data;
    }

    private function saveJsonFile(string $path, array $data): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents($path, json_encode($data), LOCK_EX);
    }

    private function mutateJsonFile(string $path, callable $mutator): array
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $fh = @fopen($path, 'c+');
        if ($fh === false) {
            $current = $this->loadJsonFile($path);
            $next = $mutator($current);
            if (!is_array($next)) {
                $next = $current;
            }
            $this->saveJsonFile($path, $next);
            return $next;
        }

        $state = [];
        if (@flock($fh, LOCK_EX)) {
            rewind($fh);
            $raw = stream_get_contents($fh);
            $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
            if (is_array($decoded)) {
                $state = $decoded;
            }

            $next = $mutator($state);
            if (!is_array($next)) {
                $next = $state;
            }

            $encoded = json_encode($next);
            if (!is_string($encoded)) {
                $encoded = '{}';
            }

            rewind($fh);
            ftruncate($fh, 0);
            fwrite($fh, $encoded);
            fflush($fh);
            @flock($fh, LOCK_UN);
            fclose($fh);
            return $next;
        }

        fclose($fh);
        $current = $this->loadJsonFile($path);
        $next = $mutator($current);
        if (!is_array($next)) {
            $next = $current;
        }
        $this->saveJsonFile($path, $next);
        return $next;
    }

    private function breakerKey(string $capabilityId, string $providerId): string
    {
        return $capabilityId . '|' . $providerId;
    }

    private function isBreakerOpen(string $capabilityId, string $providerId): bool
    {
        $state = $this->loadJsonFile($this->breakerFile());
        $key = $this->breakerKey($capabilityId, $providerId);
        $entry = $state[$key] ?? null;
        if (!is_array($entry)) {
            return false;
        }
        $openUntil = (int)($entry['open_until'] ?? 0);
        return $openUntil > time();
    }

    private function recordBreakerSuccess(string $capabilityId, string $providerId): void
    {
        $key = $this->breakerKey($capabilityId, $providerId);
        $this->mutateJsonFile($this->breakerFile(), static function (array $state) use ($key): array {
            if (isset($state[$key]) && is_array($state[$key])) {
                $state[$key]['failures'] = 0;
                $state[$key]['first_failure'] = 0;
                $state[$key]['open_until'] = 0;
            }
            return $state;
        });
    }

    private function recordBreakerFailure(string $capabilityId, string $providerId, array $settings): void
    {
        $key = $this->breakerKey($capabilityId, $providerId);
        $now = time();

        $this->mutateJsonFile($this->breakerFile(), static function (array $state) use ($key, $now, $settings): array {
            $entry = $state[$key] ?? [
                'failures' => 0,
                'first_failure' => $now,
                'open_until' => 0,
            ];

            if (!is_array($entry)) {
                $entry = [
                    'failures' => 0,
                    'first_failure' => $now,
                    'open_until' => 0,
                ];
            }

            $window = max(1, (int)$settings['breaker_window_sec']);
            if ((int)($entry['first_failure'] ?? 0) + $window < $now) {
                $entry['failures'] = 0;
                $entry['first_failure'] = $now;
            }

            $entry['failures'] = (int)($entry['failures'] ?? 0) + 1;
            $threshold = max(1, (int)$settings['breaker_threshold']);
            if ($entry['failures'] >= $threshold) {
                $cooldown = max(1, (int)$settings['breaker_cooldown_sec']);
                $entry['open_until'] = $now + $cooldown;
            }

            $state[$key] = $entry;
            return $state;
        });
    }

    private function updateMetrics(string $capabilityId, string $providerId, int $durationMs, bool $ok, int $maxSamples): void
    {
        $key = $this->breakerKey($capabilityId, $providerId);
        $metricsFile = $this->metricsFile();
        $this->mutateJsonFile($metricsFile, function (array $metrics) use ($key, $durationMs, $ok, $maxSamples): array {
            $entry = $metrics[$key] ?? [
                'count' => 0,
                'errors' => 0,
                'durations' => [],
                'p95_ms' => 0,
                'last_ms' => 0,
            ];

            if (!is_array($entry)) {
                $entry = [
                    'count' => 0,
                    'errors' => 0,
                    'durations' => [],
                    'p95_ms' => 0,
                    'last_ms' => 0,
                ];
            }

            $entry['count'] = (int)$entry['count'] + 1;
            if (!$ok) {
                $entry['errors'] = (int)$entry['errors'] + 1;
            }
            $entry['last_ms'] = $durationMs;
            $durations = is_array($entry['durations'] ?? null) ? $entry['durations'] : [];
            $durations[] = $durationMs;
            if (count($durations) > $maxSamples) {
                $durations = array_slice($durations, -$maxSamples);
            }
            $entry['durations'] = $durations;
            $entry['p95_ms'] = $this->calculateP95($durations);

            $metrics[$key] = $entry;
            return $metrics;
        });
    }

    private function calculateP95(array $values): int
    {
        if (empty($values)) {
            return 0;
        }
        $values = array_values($values);
        sort($values);
        $idx = (int)floor(0.95 * (count($values) - 1));
        return (int)$values[$idx];
    }

    public function healthForProvider(string $capabilityId, string $providerId): array
    {
        $key = $this->breakerKey($capabilityId, $providerId);
        $metrics = $this->loadJsonFile($this->metricsFile());
        $breakers = $this->loadJsonFile($this->breakerFile());

        $m = $metrics[$key] ?? null;
        $b = $breakers[$key] ?? null;
        $now = time();

        return [
            'count' => is_array($m) ? (int)($m['count'] ?? 0) : 0,
            'errors' => is_array($m) ? (int)($m['errors'] ?? 0) : 0,
            'p95_ms' => is_array($m) ? (int)($m['p95_ms'] ?? 0) : 0,
            'last_ms' => is_array($m) ? (int)($m['last_ms'] ?? 0) : 0,
            'breaker_open' => is_array($b) && (int)($b['open_until'] ?? 0) > $now,
            'breaker_failures' => is_array($b) ? (int)($b['failures'] ?? 0) : 0,
        ];
    }

    public function healthAll(): array
    {
        $metrics = $this->loadJsonFile($this->metricsFile());
        $breakers = $this->loadJsonFile($this->breakerFile());
        $now = time();
        $out = [];

        $allKeys = array_unique(array_merge(array_keys($metrics), array_keys($breakers)));
        foreach ($allKeys as $key) {
            if (!is_string($key) || !str_contains($key, '|')) {
                continue;
            }
            [$capId, $providerId] = explode('|', $key, 2);
            $m = $metrics[$key] ?? null;
            $b = $breakers[$key] ?? null;

            $out[] = [
                'capability_id' => $capId,
                'provider' => $providerId,
                'count' => is_array($m) ? (int)($m['count'] ?? 0) : 0,
                'errors' => is_array($m) ? (int)($m['errors'] ?? 0) : 0,
                'p95_ms' => is_array($m) ? (int)($m['p95_ms'] ?? 0) : 0,
                'last_ms' => is_array($m) ? (int)($m['last_ms'] ?? 0) : 0,
                'breaker_open' => is_array($b) && (int)($b['open_until'] ?? 0) > $now,
                'breaker_failures' => is_array($b) ? (int)($b['failures'] ?? 0) : 0,
            ];
        }

        return $out;
    }

    private function supportsMode(array $provider, string $mode): bool
    {
        $modes = $provider['modes'] ?? [];
        $modes = is_array($modes) ? array_map('strtolower', $modes) : [];
        return in_array(strtolower($mode), $modes, true);
    }
}
