<?php

declare(strict_types=1);

/**
 * Module Route Loading — extracted from module-manager.php for maintainability.
 *
 * Contains route pattern utilities, conflict detection, and the
 * loadModuleRoutes() function that registers module capabilities,
 * entity contexts, entity sources, and HTTP routes.
 *
 * @package Ikabud\Kernel\ModuleSystem
 */

// ─── Route Pattern Utilities ──────────────────────────────────────────────

function routePatternSegments(string $pattern): array
{
    $trimmed = trim($pattern, '/');
    if ($trimmed === '') {
        return [];
    }
    return array_values(array_filter(explode('/', $trimmed), static fn($seg) => $seg !== ''));
}

function routeSegmentIsDynamic(string $segment): bool
{
    return (bool) preg_match('/^\{[A-Za-z0-9_]+\}$/', $segment);
}

function routePatternsMayConflict(string $left, string $right): bool
{
    $leftSegments = routePatternSegments($left);
    $rightSegments = routePatternSegments($right);

    if (count($leftSegments) !== count($rightSegments)) {
        return false;
    }

    $hasDynamic = false;
    $segmentCount = count($leftSegments);
    for ($i = 0; $i < $segmentCount; $i++) {
        $l = $leftSegments[$i];
        $r = $rightSegments[$i];

        $lDynamic = routeSegmentIsDynamic($l);
        $rDynamic = routeSegmentIsDynamic($r);
        $hasDynamic = $hasDynamic || $lDynamic || $rDynamic;

        if (!$lDynamic && !$rDynamic && $l !== $r) {
            return false;
        }
    }

    return $hasDynamic;
}

function routePatternMatchPriority(string $pattern): array
{
    $segments = routePatternSegments($pattern);
    $segmentCount = count($segments);
    $staticCount = 0;
    $dynamicCount = 0;

    foreach ($segments as $segment) {
        if (routeSegmentIsDynamic($segment)) {
            $dynamicCount++;
        } else {
            $staticCount++;
        }
    }

    $typeRank = 2;
    if ($dynamicCount === 0) {
        $typeRank = 3;
    } elseif ($staticCount === 0) {
        $typeRank = 1;
    }

    return [$typeRank, $segmentCount, $staticCount, -$dynamicCount, $pattern];
}

function compareRoutePatternsForMatching(string $left, string $right): int
{
    $leftPriority = routePatternMatchPriority($left);
    $rightPriority = routePatternMatchPriority($right);

    $max = count($leftPriority);
    for ($i = 0; $i < $max; $i++) {
        $l = $leftPriority[$i];
        $r = $rightPriority[$i];
        if ($l === $r) {
            continue;
        }

        if ($i === $max - 1) {
            return strcmp((string)$l, (string)$r);
        }

        return $r <=> $l;
    }

    return 0;
}

// ─── Route Loading ────────────────────────────────────────────────────────

/**
 * Load routes from all ENABLED modules.
 * @param array<string, array<string, string>> $routes
 * @return array<string, array<string, string>>
 */
function loadModuleRoutes(array $routes): array
{
    $ambiguityMode = strtolower((string) config('app.modules.route_ambiguity_mode', 'warn'));

    // Track which module owns each route for conflict detection
    $routeOwners = [];
    $methodPatterns = [];
    foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE'] as $m) {
        $methodPatterns[$m] = [];
        foreach ($routes[$m] ?? [] as $pattern => $_) {
            $routeOwners[$m . ':' . $pattern] = '_kernel';
            $methodPatterns[$m][$pattern] = '_kernel';
        }
    }

    foreach (getEnabledModules() as $module) {
        loadModuleHelpers($module);

        // Register capability providers declared by the module.
        // Minimal v1 bridge: modules publish callables via helpers.php.
        // A module may expose:
        //  - $capability_handlers array in global scope (preferred)
        //  - or functions named: <moduleId>_cap_<sanitizedCapabilityId>
        $capCheck = validateModuleCapabilities($module);
        if (!empty($capCheck['ok']) && !empty($capCheck['exposes'])) {
            $moduleId = (string)($module['id'] ?? '');
            $policy = is_array($capCheck['policy'] ?? null) ? $capCheck['policy'] : [];
            $helpersFile = (string)($module['_path'] ?? '') . '/helpers.php';

            // Pull handler map if module provided one
            $handlersMap = [];
            $handlersMapOrigin = [
                'type' => 'none',
                'provider' => $moduleId,
                'module' => $moduleId,
                'file' => $helpersFile,
                'symbol' => null,
            ];
            $modulePrefix = preg_replace('/[^a-z0-9]+/i', '_', $moduleId);
            $handlersExportFn = $modulePrefix . '_capability_handlers';
            if (function_exists($handlersExportFn)) {
                $resolvedHandlersMap = $handlersExportFn();
                if (is_array($resolvedHandlersMap)) {
                    $handlersMap = $resolvedHandlersMap;
                    $handlersMapOrigin = [
                        'type' => 'export_function',
                        'provider' => $moduleId,
                        'module' => $moduleId,
                        'file' => $helpersFile,
                        'symbol' => $handlersExportFn,
                    ];
                }
            } elseif (!empty($module['capability_handlers']) && is_array($module['capability_handlers'])) {
                $handlersMap = $module['capability_handlers'];
                $handlersMapOrigin = [
                    'type' => 'module_handlers_map',
                    'provider' => $moduleId,
                    'module' => $moduleId,
                    'file' => $helpersFile,
                    'symbol' => 'capability_handlers',
                ];
            } elseif (isset($GLOBALS['capability_handlers']) && is_array($GLOBALS['capability_handlers'])) {
                // Backward-compatible: module may have declared a global $capability_handlers
                $handlersMap = $GLOBALS['capability_handlers'];
                $handlersMapOrigin = [
                    'type' => 'global_handlers_map',
                    'provider' => $moduleId,
                    'module' => $moduleId,
                    'file' => $helpersFile,
                    'symbol' => '$GLOBALS[capability_handlers]',
                ];
            }

            foreach ($capCheck['exposes'] as $exp) {
                $capId = (string)($exp['id'] ?? '');
                if ($capId === '') continue;

                $priority = (int)($exp['priority'] ?? 10);
                $modes = is_array($exp['modes'] ?? null) ? $exp['modes'] : ['first'];

                $callable = null;
                $schema = is_array($exp['schema'] ?? null) ? $exp['schema'] : null;
                $origin = $handlersMapOrigin;
                if (isset($handlersMap[$capId]) && is_callable($handlersMap[$capId])) {
                    $callable = $handlersMap[$capId];
                } else {
                    $san = preg_replace('/[^a-z0-9]+/i', '_', $capId);
                    $fn = $modulePrefix . '_cap_' . strtolower(trim((string)$san, '_'));
                    if (function_exists($fn)) {
                        $callable = $fn;
                        $origin = [
                            'type' => 'naming_convention',
                            'provider' => $moduleId,
                            'module' => $moduleId,
                            'file' => $helpersFile,
                            'symbol' => $fn,
                        ];
                    }
                }

                if ($callable && is_callable($callable)) {
                    $wrappedCallable = static function (mixed $payload, string $resolvedCapabilityId = '', string $providerId = '') use ($moduleId, $callable): mixed {
                        return moduleWithContext($moduleId, static function () use ($callable, $payload, $resolvedCapabilityId, $providerId): mixed {
                            return $callable($payload, $resolvedCapabilityId, $providerId);
                        });
                    };
                    app()->capabilities()->register(
                        $capId,
                        $moduleId,
                        $wrappedCallable,
                        $priority,
                        $modes,
                        ['policy' => $policy, 'schema' => $schema, 'origin' => array_merge($origin, ['capability' => $capId])]
                    );
                } else {
                    // Service modules run externally — register an HTTP proxy instead.
                    $moduleType = trim((string)($module['type'] ?? 'php-module'));
                    if ($moduleType === 'service-module') {
                        $serviceProxy = \Ikabud\Kernel\Capabilities\ServiceProxy::fromManifest($module);
                        if ($serviceProxy !== null) {
                            app()->capabilities()->register(
                                $capId,
                                $moduleId,
                                $serviceProxy,
                                $priority,
                                $modes,
                                ['policy' => $policy, 'schema' => $schema, 'origin' => array_merge($origin, ['capability' => $capId, 'type' => 'service_proxy'])]
                            );
                            continue;
                        }
                        write_log(
                            "[cert_blocker] Module '{$moduleId}' is service-module but ServiceProxy could not be built",
                            'warning',
                            ['severity' => \Ikabud\Kernel\Contracts\DiagnosticSeverity::CertificationBlocker->value, 'module' => $moduleId, 'capability' => $capId, 'correction' => 'Declare a valid service endpoint and transport configuration.']
                        );
                    } else {
                        write_log(
                            "[cert_blocker] Module '{$moduleId}' declares capability '{$capId}' but no handler callable was found",
                            'warning',
                            ['severity' => \Ikabud\Kernel\Contracts\DiagnosticSeverity::CertificationBlocker->value, 'module' => $moduleId, 'capability' => $capId, 'correction' => "Export the callable from {$handlersExportFn}() in helpers.php or remove the declaration."]
                        );
                    }
                }
            }
        }

        $entityContextCheck = validateModuleEntityContexts($module);
        if (!empty($entityContextCheck['ok'])) {
            $moduleId = (string)($module['id'] ?? '');

            foreach (($entityContextCheck['definitions'] ?? []) as $definition) {
                if (!is_array($definition) || empty($definition['id'])) {
                    continue;
                }

                app()->entityContexts()->registerContext(
                    (string)$definition['id'],
                    $definition,
                    $moduleId,
                    (int)($definition['priority'] ?? 10)
                );
            }

            foreach (($entityContextCheck['extensions'] ?? []) as $extension) {
                if (!is_array($extension) || empty($extension['context'])) {
                    continue;
                }

                app()->entityContexts()->extendContext(
                    (string)$extension['context'],
                    $extension,
                    $moduleId,
                    (int)($extension['priority'] ?? 10)
                );
            }

            foreach (($entityContextCheck['bindings'] ?? []) as $binding) {
                if (!is_array($binding) || empty($binding['entity_type'])) {
                    continue;
                }

                app()->entityContexts()->bindEntityType(
                    (string)$binding['entity_type'],
                    $binding,
                    $moduleId,
                    (int)($binding['priority'] ?? 10)
                );
            }

            foreach (($entityContextCheck['capability_metadata'] ?? []) as $metadata) {
                if (!is_array($metadata) || empty($metadata['id'])) {
                    continue;
                }

                app()->entityContexts()->registerCapability(
                    (string)$metadata['id'],
                    $metadata,
                    $moduleId,
                    (int)($metadata['priority'] ?? 10)
                );
            }
        }

        // ── Entity Sources (declarative entity-view registration from manifest) ──
        // Modules declare entity_sources in their manifest to auto-register
        // entity views and auto-generate entity.list.* + entity.get.* capability handlers.
        // This eliminates PHP glue code for polyglot service modules.
        //
        // Schema: {
        //   "<entity_type>": {
        //     "qualifiers": { "<qualifier>": { "capability": "...", "result_path": "..." } },
        //     "get_capability": "<optional default get>",
        //     "default_view": "...",
        //     "views": { "<view>": { "fields": [...], ... } }
        //   }
        // }
        $entitySources = $module['entity_sources'] ?? [];
        if (!empty($entitySources) && is_array($entitySources)) {
            foreach ($entitySources as $entityType => $sourceDef) {
                if (!is_array($sourceDef)) continue;

                $entityType  = (string) $entityType;
                if ($entityType === '') continue;

                $qualifiers  = is_array($sourceDef['qualifiers'] ?? null) ? $sourceDef['qualifiers'] : [];
                $getCap      = (string)($sourceDef['get_capability'] ?? '');
                $sanType     = str_replace('.', '_', $entityType);

                // ── Auto-register entity views ──
                $views = $sourceDef['views'] ?? [];
                if (is_array($views) && method_exists(app(), 'entityViews')) {
                    $entityViews = app()->entityViews();
                    foreach ($views as $viewName => $viewDef) {
                        if (!is_array($viewDef)) continue;
                        $entityViews->registerView($entityType, (string)$viewName, $viewDef, $moduleId);
                    }
                }

                // ── Auto-generate entity.list.<type>@1 handler ──
                $listCapId = "entity.list.{$sanType}@1";
                if (!app()->capabilities()->has($listCapId)) {
                    $autoListHandler = static function (mixed $payload) use ($qualifiers, $entityType, $moduleId): mixed {
                        $qualifier = (string)($payload['qualifier'] ?? '');

                        // Look up qualifier-specific route, then fallback to empty-key route
                        $route = $qualifiers[$qualifier] ?? $qualifiers[''] ?? null;
                        if ($route === null && $qualifier !== '') {
                            // Try matching qualifier as substring (e.g., "forecast" matches key "forecast")
                            foreach ($qualifiers as $qk => $qv) {
                                if ($qk !== '' && stripos($qualifier, $qk) !== false) {
                                    $route = $qv;
                                    break;
                                }
                            }
                        }

                        $capability = is_array($route) ? (string)($route['capability'] ?? '') : '';
                        $resultKey  = is_array($route) ? (string)($route['result_path'] ?? '') : '';

                        if ($capability === '') {
                            // No qualifier match — call the first available qualifier capability
                            $first = !empty($qualifiers) ? reset($qualifiers) : null;
                            $capability = is_array($first) ? (string)($first['capability'] ?? '') : '';
                            $resultKey  = is_array($first) ? (string)($first['result_path'] ?? '') : '';
                            if ($capability === '') return null;
                        }

                        $result = \app()->cap()->call($capability, $payload, [
                            'caller'    => ['module' => $moduleId],
                            'timeout_ms' => 10000,
                        ]);
                        if (!is_array($result)) return null;

                        $rows = $result;
                        if ($resultKey !== '' && isset($result[$resultKey]) && is_array($result[$resultKey])) {
                            $rows = $result[$resultKey];
                        } elseif (isset($result['rows']) && is_array($result['rows'])) {
                            $rows = $result['rows'];
                        }

                        $rows = is_array($rows) ? array_values($rows) : [$rows];
                        return ['rows' => $rows, 'total' => count($rows)];
                    };
                    app()->capabilities()->register(
                        $listCapId, $moduleId, $autoListHandler, 90, ['first'],
                        ['origin' => ['type' => 'entity_source', 'module' => $moduleId, 'entity_type' => $entityType]]
                    );
                }

                // ── Auto-generate entity.get.<type>@1 handler ──
                if ($getCap !== '') {
                    $getCapId = "entity.get.{$sanType}@1";
                    if (!app()->capabilities()->has($getCapId)) {
                        $autoGetHandler = static function (mixed $payload) use ($getCap, $moduleId): mixed {
                            return \app()->cap()->call($getCap, $payload, [
                                'caller'    => ['module' => $moduleId],
                                'timeout_ms' => 10000,
                            ]);
                        };
                        app()->capabilities()->register(
                            $getCapId, $moduleId, $autoGetHandler, 90, ['first'],
                            ['origin' => ['type' => 'entity_source', 'module' => $moduleId, 'entity_type' => $entityType]]
                        );
                    }
                }
            }
        }

        $moduleId = $module['id'] ?? 'unknown';

        // Event declarations are validated before route handling so route-less
        // modules cannot bypass fatal manifest synchronization rules. Failure
        // is fatal for the declaring module only: its routes are not loaded.
        if (function_exists('kernelRegisterModuleEvents')) {
            $declaredEvents = $module['events'] ?? [];
            try {
                kernelRegisterModuleEvents((string)$moduleId, is_array($declaredEvents) ? $declaredEvents : []);
            } catch (RuntimeException $eventError) {
                write_log($eventError->getMessage(), 'error', [
                    'severity' => \Ikabud\Kernel\Contracts\DiagnosticSeverity::Fatal->value,
                    'module' => (string)$moduleId,
                ]);
                if (function_exists('recordSkippedModule')) {
                    recordSkippedModule((string)$moduleId, 'malformed_event_declarations', [
                        'error' => $eventError->getMessage(),
                    ]);
                }
                continue;
            }
        }

        // Schema v1 is authoritative for route-file selection. Absent remains
        // legacy-compatible with routes.php; false/[] explicitly disable routes.
        $routesDeclaration = $module['routes'] ?? true;
        if ($routesDeclaration === false || (is_array($routesDeclaration) && $routesDeclaration === [])) {
            continue;
        }
        $routesRelativePath = is_string($routesDeclaration)
            ? ltrim($routesDeclaration, '/')
            : 'routes.php';
        $routesFile = rtrim((string)$module['_path'], '/') . '/' . $routesRelativePath;
        if (!is_file($routesFile)) {
            continue;
        }

        $moduleRoutes = require $routesFile;
        if (!is_array($moduleRoutes)) {
            continue;
        }

        foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            if (!isset($moduleRoutes[$method]) || empty($moduleRoutes[$method]) || !is_array($moduleRoutes[$method])) {
                continue;
            }
            foreach ($moduleRoutes[$method] as $pattern => $handler) {
                $routeKey = $method . ':' . $pattern;
                $blockedByAmbiguity = false;

                // Lint for semantic ambiguity (e.g. /foo/{id} vs /foo/bar).
                foreach ($methodPatterns[$method] as $existingPattern => $owner) {
                    if ($existingPattern === $pattern) {
                        continue;
                    }
                    if (!routePatternsMayConflict($existingPattern, $pattern)) {
                        continue;
                    }

                    // The dispatcher sorts patterns via compareRoutePatternsForMatching()
                    // which ranks literal routes above parameterized ones. When two
                    // conflicting patterns have different priority (e.g. /foo/bar vs
                    // /foo/{id}), the more-specific pattern always wins — no real
                    // ambiguity exists, so skip the warning.
                    if (compareRoutePatternsForMatching($existingPattern, $pattern) !== 0) {
                        continue;
                    }

                    $context = [
                        'severity' => \Ikabud\Kernel\Contracts\DiagnosticSeverity::CertificationBlocker->value,
                        'module' => $moduleId,
                        'method' => $method,
                        'pattern' => $pattern,
                        'existing_pattern' => $existingPattern,
                        'existing_owner' => $owner,
                        'mode' => $ambiguityMode,
                    ];

                    if ($ambiguityMode === 'block') {
                        write_log(
                            "[cert_blocker] Route ambiguity blocked: module '{$moduleId}' {$method} {$pattern} conflicts with '{$owner}' route {$existingPattern}",
                            'warning',
                            $context
                        );
                        $blockedByAmbiguity = true;
                        break;
                    }

                    write_log(
                        "[cert_blocker] Route ambiguity warning: module '{$moduleId}' registered {$method} {$pattern} which may conflict with '{$owner}' route {$existingPattern}",
                        'warning',
                        $context
                    );
                }

                if ($blockedByAmbiguity) {
                    continue;
                }

                if (isset($routeOwners[$routeKey])) {
                    // Conflict detected — reject and log
                    $owner = $routeOwners[$routeKey];
                    write_log(
                        "Route conflict: module '{$moduleId}' tried to register {$method} {$pattern} already owned by '{$owner}' — rejected",
                        'warning',
                        ['module' => $moduleId, 'method' => $method, 'pattern' => $pattern, 'owner' => $owner]
                    );
                    continue; // skip — do NOT overwrite
                }

                $routes[$method][$pattern] = $handler;
                $routeOwners[$routeKey] = $moduleId;
                $methodPatterns[$method][$pattern] = $moduleId;
            }
        }
    }

    // Flush all deferred event registrations in a single batch (1 cache check + 1 batch DB write)
    if (function_exists('kernelFlushPendingEventRegistrations')) {
        kernelFlushPendingEventRegistrations();
    }

    // Check read contract schema drift after all modules are loaded
    kernelCheckReadContractDrift();

    return $routes;
}

// ─── Module Context Accessor ──────────────────────────────────────────────

/** @var array<int, \Ikabud\Kernel\Contracts\ModuleContext|null> */
