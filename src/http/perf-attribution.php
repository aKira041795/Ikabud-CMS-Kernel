<?php

declare(strict_types=1);

/**
 * Request-local database and phase attribution.
 *
 * PHP request globals are rebuilt for every web request, including when an
 * Apache/FPM worker is reused. Nothing is persisted in APCu, files, or static
 * process state.
 */
if (!function_exists('kernelPerfProbeRequestAttribution')) {
    $GLOBALS['kernel_perf_request_attribution'] = [
        'started_ns' => isset($GLOBALS['kernel_perf_request_started_ns'])
            ? (int)$GLOBALS['kernel_perf_request_started_ns']
            : hrtime(true),
        // Baseline for "how many scripts did THIS request compile". Taken here because this file is the
        // earliest instrumentation point on the request path, well before any module is registered, so
        // the delta spans module registration - where the 347 helper files are included. Guarded with
        // function_exists because an attribution-only caller (the unit tests) never loads perf-probe.php.
        'opcache_baseline' => function_exists('kernelPerfOpcacheSnapshot')
            ? kernelPerfOpcacheSnapshot()
            : null,
        'db' => [
            'queries' => 0,
            'total_ms' => 0.0,
            'slowest' => [],
            'ddl_queries' => 0,
            'ddl_tables' => [],
        ],
        'phases' => [
            'boot' => null,
            'boot_fastpath' => null,
            'boot_bootstrap' => null,
            'boot_requires' => null,
            'session' => null,
            'core_routes' => null,
            'settings_preload' => null,
            'module_routes_discovery' => null,
            'module_routes_registration' => null,
            'module_reg_helpers_load' => null,
            'module_reg_capability_validate' => null,
            'module_reg_capability_register' => null,
            'module_reg_entity_context' => null,
            'module_reg_entity_sources' => null,
            'module_reg_route_merge' => null,
            'module_routes_event_flush' => null,
            'module_routes_contract_drift' => null,
            'module_routes' => null,
            'dispatch_hooks' => null,
            'route_match_sort' => null,
            'route_match' => null,
            'dispatch' => null,
            'render' => null,
            'shutdown' => null,
        ],
    ];

    /**
     * Milliseconds since the request's own start - the SAME origin as every phase mark.
     *
     * The perf pages used to compute their "Total wall time" from a mark taken inside the handler,
     * after auth, so that number shared no origin with the phases printed beside it. Locally that
     * produced an impossible reading: total_ms = 134.76 sitting under phase:dispatch = 260.79, a total
     * smaller than one of its own phases. Anything a reader naturally compares or subtracts has to be
     * measured from one origin, so this is the helper both pages now use.
     *
     * Returns null when the attribution state is absent (never 0): no state means no measurement, and
     * 0 would claim the request took no time.
     */
    function kernelPerfRequestElapsedMs(): ?float
    {
        $state = $GLOBALS['kernel_perf_request_attribution'] ?? null;
        if (!is_array($state) || !isset($state['started_ns'])) {
            return null;
        }

        return (hrtime(true) - (int)$state['started_ns']) / 1_000_000;
    }

    function kernelPerfMarkRequestPhase(string $phase): void
    {
        try {
            if (!in_array($phase, ['boot', 'boot_fastpath', 'boot_bootstrap', 'boot_requires', 'session', 'core_routes', 'settings_preload', 'module_routes_discovery', 'module_routes_registration', 'module_reg_helpers_load', 'module_reg_capability_validate', 'module_reg_capability_register', 'module_reg_entity_context', 'module_reg_entity_sources', 'module_reg_route_merge', 'module_routes_event_flush', 'module_routes_contract_drift', 'module_routes', 'dispatch_hooks', 'route_match_sort', 'route_match', 'dispatch', 'render', 'shutdown'], true)) {
                return;
            }

            $state = &$GLOBALS['kernel_perf_request_attribution'];
            if (!is_array($state) || !array_key_exists($phase, $state['phases'] ?? [])) {
                return;
            }
            if ($state['phases'][$phase] !== null) {
                return;
            }

            $startedNs = (int)($state['started_ns'] ?? hrtime(true));
            $state['phases'][$phase] = max(0.0, (hrtime(true) - $startedNs) / 1_000_000);
        } catch (Throwable $ignored) {
            // Attribution must never affect request handling.
        }
    }

    /**
     * Publish boot boundaries captured BEFORE this file was loaded.
     *
     * boot was a single opaque mark: kernelPerfMarkRequestPhase() is not defined until this file is
     * required (public/index.php:155), so nothing between the request origin (:6) and the boot mark
     * could be attributed to anything. The front controller therefore reads hrtime() at each
     * boundary and hands the raw values here, once the state exists. They are converted on the same
     * monotonic clock and the same origin as every other phase, so the results are comparable.
     */
    function kernelPerfPublishBootBoundaries(
        int $afterFastPathNs,
        int $afterBootstrapNs,
        int $afterRequiresNs
    ): void {
        try {
            $state = &$GLOBALS['kernel_perf_request_attribution'];
            if (!is_array($state) || !isset($state['started_ns'])) {
                return;
            }
            $startedNs = (int)$state['started_ns'];
            foreach ([
                'boot_fastpath' => $afterFastPathNs,
                'boot_bootstrap' => $afterBootstrapNs,
                'boot_requires' => $afterRequiresNs,
            ] as $phase => $ns) {
                if (!array_key_exists($phase, $state['phases'] ?? []) || $state['phases'][$phase] !== null) {
                    continue;
                }
                $state['phases'][$phase] = max(0.0, ($ns - $startedNs) / 1_000_000);
            }
        } catch (Throwable $ignored) {
            // Attribution must never affect request handling.
        }
    }

    /**
     * Publish accumulated operation durations as synthetic cumulative marks.
     * The final operation absorbs timer/publisher overhead so the six deltas
     * exactly partition the registration parent wall time.
     *
     * @param array<string, int|float> $durationNs
     * @param array<string, int|float> $includeCost
     */
    function kernelPerfPublishModuleRegistrationBreakdown(array $durationNs, array $includeCost = [], ?int $endedNs = null): void
    {
        try {
            $state = &$GLOBALS['kernel_perf_request_attribution'];
            $phases = &$state['phases'];
            if (!is_array($state) || !is_array($phases ?? null) || $phases['module_routes_registration'] !== null) {
                return;
            }

            $names = ['helpers_load', 'capability_validate', 'capability_register', 'entity_context', 'entity_sources', 'route_merge'];
            $startMs = isset($phases['module_routes_discovery']) ? (float)$phases['module_routes_discovery'] : null;
            if ($startMs === null) {
                return;
            }

            $parentMs = max($startMs, (($endedNs ?? hrtime(true)) - (int)$state['started_ns']) / 1_000_000);
            $availableMs = $parentMs - $startMs;
            $measuredMs = 0.0;
            foreach ($names as $name) {
                $measuredMs += max(0.0, (float)($durationNs[$name] ?? 0) / 1_000_000);
            }
            $durationNs['route_merge'] = max(0.0, (float)($durationNs['route_merge'] ?? 0) + (($availableMs - $measuredMs) * 1_000_000));

            $cumulative = $startMs;
            foreach ($names as $name) {
                $cumulative += max(0.0, (float)($durationNs[$name] ?? 0) / 1_000_000);
                $phases['module_reg_' . $name] = min($parentMs, $cumulative);
            }
            $phases['module_reg_route_merge'] = $parentMs;
            $phases['module_routes_registration'] = $parentMs;
            $state['registration_include_cost'] = [
                'files' => max(0, (int)($includeCost['files'] ?? 0)),
                'bytes' => max(0, (int)($includeCost['bytes'] ?? 0)),
            ];
        } catch (Throwable $ignored) {
            // Attribution must never affect request handling.
        }
    }

    function kernelPerfProbeRequestAttribution(): array
    {
        $empty = [
            'db' => ['queries' => 0, 'total_ms' => 0.0, 'slowest' => [], 'ddl_queries' => 0, 'ddl_tables' => []],
            'phases' => [
                'boot' => null,
                'session' => null,
                'core_routes' => null,
                'settings_preload' => null,
                'module_routes_discovery' => null,
                'module_routes_registration' => null,
                'module_reg_helpers_load' => null,
                'module_reg_capability_validate' => null,
                'module_reg_capability_register' => null,
                'module_reg_entity_context' => null,
                'module_reg_entity_sources' => null,
                'module_reg_route_merge' => null,
                'module_routes_event_flush' => null,
                'module_routes_contract_drift' => null,
                'module_routes' => null,
                'dispatch_hooks' => null,
                'route_match' => null,
                'dispatch' => null,
                'render' => null,
                'shutdown' => null,
            ],
            'phase_deltas' => [
                'session' => null,
                'core_routes' => null,
                'settings_preload' => null,
                'module_routes' => null,
                'dispatch_hooks' => null,
                'route_match' => null,
                'dispatch_tail' => null,
            ],
            'module_route_deltas' => [
                'discovery' => null,
                'registration' => null,
                'event_flush' => null,
                'contract_drift' => null,
                'tail' => null,
            ],
            'registration_deltas' => [
                'helpers_load' => null,
                'capability_validate' => null,
                'capability_register' => null,
                'entity_context' => null,
                'entity_sources' => null,
                'route_merge' => null,
            ],
            'registration_include_cost' => ['files' => 0, 'bytes' => 0],
        ];

        try {
            $state = $GLOBALS['kernel_perf_request_attribution'] ?? null;
            if (!is_array($state)) {
                return $empty;
            }

            $db = is_array($state['db'] ?? null) ? $state['db'] : [];
            $phases = is_array($state['phases'] ?? null) ? $state['phases'] : [];

            $phaseValues = [];
            foreach (['boot', 'boot_fastpath', 'boot_bootstrap', 'boot_requires', 'session', 'core_routes', 'settings_preload', 'module_routes_discovery', 'module_reg_helpers_load', 'module_reg_capability_validate', 'module_reg_capability_register', 'module_reg_entity_context', 'module_reg_entity_sources', 'module_reg_route_merge', 'module_routes_registration', 'module_routes_event_flush', 'module_routes_contract_drift', 'module_routes', 'dispatch_hooks', 'route_match_sort', 'route_match', 'dispatch', 'render', 'shutdown'] as $phase) {
                $phaseValues[$phase] = isset($phases[$phase]) ? (float)$phases[$phase] : null;
            }

            $deltaPairs = [
                'session' => ['boot', 'session'],
                'boot_fastpath_to_bootstrap' => ['boot_fastpath', 'boot_bootstrap'],
                'bootstrap_to_requires' => ['boot_bootstrap', 'boot_requires'],
                'requires_to_boot' => ['boot_requires', 'boot'],
                'core_routes' => ['session', 'core_routes'],
                'settings_preload' => ['core_routes', 'settings_preload'],
                'module_routes' => ['settings_preload', 'module_routes'],
                'dispatch_hooks' => ['module_routes', 'dispatch_hooks'],
                'route_match' => ['dispatch_hooks', 'route_match'],
                'route_match_sort' => ['dispatch_hooks', 'route_match_sort'],
                'route_match_scan' => ['route_match_sort', 'route_match'],
                'dispatch_tail' => ['route_match', 'dispatch'],
            ];
            $phaseDeltas = [];
            foreach ($deltaPairs as $segment => [$before, $after]) {
                $phaseDeltas[$segment] = $phaseValues[$before] !== null && $phaseValues[$after] !== null
                    ? max(0.0, $phaseValues[$after] - $phaseValues[$before])
                    : null;
            }

            $moduleRoutePairs = [
                'discovery' => ['settings_preload', 'module_routes_discovery'],
                'registration' => ['module_routes_discovery', 'module_routes_registration'],
                'event_flush' => ['module_routes_registration', 'module_routes_event_flush'],
                'contract_drift' => ['module_routes_event_flush', 'module_routes_contract_drift'],
                'tail' => ['module_routes_contract_drift', 'module_routes'],
            ];
            $moduleRouteDeltas = [];
            foreach ($moduleRoutePairs as $segment => [$before, $after]) {
                $moduleRouteDeltas[$segment] = $phaseValues[$before] !== null && $phaseValues[$after] !== null
                    ? max(0.0, $phaseValues[$after] - $phaseValues[$before])
                    : null;
            }

            $registrationPairs = [
                'helpers_load' => ['module_routes_discovery', 'module_reg_helpers_load'],
                'capability_validate' => ['module_reg_helpers_load', 'module_reg_capability_validate'],
                'capability_register' => ['module_reg_capability_validate', 'module_reg_capability_register'],
                'entity_context' => ['module_reg_capability_register', 'module_reg_entity_context'],
                'entity_sources' => ['module_reg_entity_context', 'module_reg_entity_sources'],
                'route_merge' => ['module_reg_entity_sources', 'module_reg_route_merge'],
            ];
            $registrationDeltas = [];
            foreach ($registrationPairs as $segment => [$before, $after]) {
                $registrationDeltas[$segment] = $phaseValues[$before] !== null && $phaseValues[$after] !== null
                    ? max(0.0, $phaseValues[$after] - $phaseValues[$before])
                    : null;
            }

            return [
                'db' => [
                    'queries' => max(0, (int)($db['queries'] ?? 0)),
                    'total_ms' => max(0.0, (float)($db['total_ms'] ?? 0.0)),
                    'slowest' => array_slice(is_array($db['slowest'] ?? null) ? $db['slowest'] : [], 0, 5),
                    'ddl_queries' => max(0, (int)($db['ddl_queries'] ?? 0)),
                    'ddl_tables' => is_array($db['ddl_tables'] ?? null) ? $db['ddl_tables'] : [],
                ],
                'phases' => $phaseValues,
                'phase_deltas' => $phaseDeltas,
                'module_route_deltas' => $moduleRouteDeltas,
                'registration_deltas' => $registrationDeltas,
                'registration_include_cost' => is_array($state['registration_include_cost'] ?? null)
                    ? $state['registration_include_cost']
                    : ['files' => 0, 'bytes' => 0],
            ];
        } catch (Throwable $ignored) {
            return $empty;
        }
    }

    try {
        \Ikabud\Kernel\EventBus::getInstance()->listen(
            'kernel.database.query.after',
            static function (array $payload): void {
                try {
                    $state = &$GLOBALS['kernel_perf_request_attribution'];
                    if (!is_array($state) || !is_array($state['db'] ?? null)) {
                        return;
                    }

                    $duration = $payload['duration_ms'] ?? null;
                    if (!is_numeric($duration) || !is_finite((float)$duration)) {
                        return;
                    }
                    $duration = max(0.0, (float)$duration);
                    $sql = preg_replace('/\s+/', ' ', trim((string)($payload['sql'] ?? '')));
                    $sql = substr(is_string($sql) ? $sql : '', 0, 200);

                    $state['db']['queries'] = (int)$state['db']['queries'] + 1;
                    $state['db']['total_ms'] = (float)$state['db']['total_ms'] + $duration;
                    if (preg_match('/^CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([a-zA-Z0-9_]+)`?/i', $sql, $ddlMatch) === 1) {
                        $table = (string)$ddlMatch[1];
                        $state['db']['ddl_queries'] = (int)($state['db']['ddl_queries'] ?? 0) + 1;
                        $state['db']['ddl_tables'][$table] = (int)($state['db']['ddl_tables'][$table] ?? 0) + 1;
                    }
                    $state['db']['slowest'][] = ['ms' => $duration, 'sql' => $sql];
                    usort(
                        $state['db']['slowest'],
                        static fn(array $left, array $right): int => $right['ms'] <=> $left['ms']
                    );
                    if (count($state['db']['slowest']) > 5) {
                        $state['db']['slowest'] = array_slice($state['db']['slowest'], 0, 5);
                    }
                } catch (Throwable $ignored) {
                    // Never query or log from this hot-path listener.
                }
            },
            10,
            // No module owner on purpose. EventBus::fire() wraps any listener that
            // declares a module in moduleWithContext(), which calls
            // moduleContextFor() -> discoverModules(). discoverModules() sets its
            // per-request memo only AFTER its per-module loop, so a listener that
            // declares a module and observes a query fired from inside that loop
            // re-enters discovery and recurses (observed in error.log on
            // 2026-10-09 as a 128 MB memory-exhaustion fatal under concurrent
            // load). This accumulator needs no module context, so declaring one is
            // pure risk.
            //
            // listen() auto-resolves moduleCurrentId() when '' is passed, so this
            // is only safe because no module context is active yet at include time
            // (public/index.php:155, before loadModuleRoutes()). That invariant is
            // asserted by tests/kernel_db_attribution_test.php.
            ''
        );
    } catch (Throwable $ignored) {
        // The app/event bus may not yet be usable in narrow CLI contexts.
    }

    register_shutdown_function(static function (): void {
        kernelPerfMarkRequestPhase('render');
        kernelPerfMarkRequestPhase('shutdown');
    });
}
