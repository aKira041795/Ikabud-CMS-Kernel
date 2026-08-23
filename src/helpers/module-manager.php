<?php

declare(strict_types=1);

require_once __DIR__ . '/manifest-validation.php';

// ─── Paths ────────────────────────────────────────────────────────────────

function modulesPath(): string
{
    return BASE_PATH . '/modules';
}

function normalizeModuleSuiteId(?string $suiteId): ?string
{
    if (!is_string($suiteId)) {
        return null;
    }

    $suiteId = strtolower(trim($suiteId));
    if ($suiteId === '') {
        return null;
    }

    // Normalize to kebab-case and reject malformed values.
    $suiteId = preg_replace('/[^a-z0-9\-]/', '-', $suiteId);
    $suiteId = preg_replace('/-+/', '-', trim((string)$suiteId, '-'));
    if ($suiteId === '' || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $suiteId) !== 1) {
        return null;
    }

    return $suiteId;
}

function moduleSuiteFromManifest(array $manifest): ?string
{
    $raw = $manifest['suite'] ?? null;
    if (!is_string($raw)) {
        return null;
    }

    return normalizeModuleSuiteId($raw);
}

/**
 * Resolve filesystem target directory for a module id.
 *
 * If a suite container directory already exists (for example `modules/cms-akira`)
 * and the module id follows `<suite>-<submodule>` naming, place new modules under
 * that container (`modules/cms-akira/cms-akira-foo`) to keep module roots clean.
 *
 * @param string $moduleId
 * @return string
 */
function moduleInstallTargetDirForId(string $moduleId, ?string $explicitSuiteId = null): string
{
    $moduleId = trim($moduleId);
    $base = rtrim(modulesPath(), '/');
    if ($moduleId === '') {
        return $base;
    }

    $suiteId = normalizeModuleSuiteId($explicitSuiteId);
    if ($suiteId !== null) {
        return $base . '/' . $suiteId . '/' . $moduleId;
    }

    $parts = array_values(array_filter(explode('-', $moduleId), fn($p) => $p !== ''));
    if (count($parts) >= 3) {
        $suite = $parts[0] . '-' . $parts[1];
        $suiteDir = $base . '/' . $suite;
        // Nest under an existing suite namespace container only. Do NOT invent
        // a suite folder for arbitrary hyphenated ids (e.g. cli-test-tmp,
        // golden-module-<hex>) — that would break flat module creation and
        // install for modules that are not members of a product suite.
        if (is_dir($suiteDir) && !is_file($suiteDir . '/module.json')) {
            return $suiteDir . '/' . $moduleId;
        }
    }

    return $base . '/' . $moduleId;
}

function modulePathForId(string $moduleId): ?string
{
    $moduleId = trim($moduleId);
    if ($moduleId === '') {
        return null;
    }

    $modules = discoverModules();
    if (!isset($modules[$moduleId])) {
        return null;
    }

    $path = trim((string)($modules[$moduleId]['_path'] ?? ''));
    return $path !== '' ? $path : null;
}

function moduleManifestPathForId(string $moduleId): ?string
{
    $modulePath = modulePathForId($moduleId);
    if ($modulePath === null) {
        return null;
    }

    $manifestPath = rtrim($modulePath, '/') . '/module.json';
    return is_file($manifestPath) ? $manifestPath : null;
}

// ─── Product Suite Graph Registry ────────────────────────────────────────
// Physical folder nesting is cosmetic; this registry derives authoritative
// product-suite relationships from module manifests. It is a read-only
// sidecar over discoverModules() — it never changes discovery results.

/**
 * Resolve the authoritative kind for a module, with legacy inference so
 * schema-v1 manifests are classified consistently.
 */
function moduleKindForModule(string $moduleId): string
{
    $modules = discoverModules();
    $manifest = $modules[$moduleId] ?? [];
    return function_exists('moduleManifestKindFromManifest')
        ? moduleManifestKindFromManifest($manifest)
        : MODULE_KIND_STANDALONE;
}

/**
 * Resolve the host module a module extends (kind extension/adapter), or null.
 */
function moduleExtendsForModule(string $moduleId): ?string
{
    $modules = discoverModules();
    $manifest = $modules[$moduleId] ?? [];
    $extends = $manifest['extends'] ?? $manifest['parent'] ?? null;
    return is_string($extends) && trim($extends) !== '' ? trim($extends) : null;
}

/**
 * Build the product suite graph from discovered modules.
 *
 * Returns a map keyed by normalized suite id:
 *   {
 *     'cms-akira' => [
 *       'id'         => 'cms-akira',
 *       'core'       => 'cms-akira-core',          // product-core module (or null)
 *       'name'       => 'CMS Akira',               // from product block / core name
 *       'modules'    => ['cms-akira-core', ...],   // all members
 *       'extensions' => ['cms-akira-seo', ...],    // kind extension|adapter
 *       'profiles'   => ['cms-akira-profile-standard', ...],
 *       'extension_points' => ['cms.sidebar', ...],// union across cores
 *     ],
 *   }
 *
 * @param array<string,array<string,mixed>>|null $modules override discovery (tests)
 * @return array<string,array<string,mixed>>
 */
function moduleSuiteGraph(?array $modules = null): array
{
    if ($modules === null) {
        $modules = discoverModules();
    }

    $graph = [];
    foreach ($modules as $moduleId => $manifest) {
        $suite = moduleSuiteFromManifest($manifest);
        if ($suite === null) {
            continue;
        }
        if (!isset($graph[$suite])) {
            $graph[$suite] = [
                'id'              => $suite,
                'core'            => null,
                'name'            => $suite,
                'modules'         => [],
                'extensions'      => [],
                'adapters'        => [],
                'profiles'        => [],
                'extension_points'=> [],
            ];
        }
        $graph[$suite]['modules'][] = $moduleId;

        $kind = function_exists('moduleManifestKindFromManifest')
            ? moduleManifestKindFromManifest($manifest)
            : MODULE_KIND_STANDALONE;

        if ($kind === MODULE_KIND_PRODUCT_CORE && $graph[$suite]['core'] === null) {
            $graph[$suite]['core'] = $moduleId;
            $product = $manifest['product'] ?? null;
            if (is_array($product) && is_string($product['name'] ?? null) && trim($product['name']) !== '') {
                $graph[$suite]['name'] = $product['name'];
            } else {
                $graph[$suite]['name'] = (string)($manifest['name'] ?? $suite);
            }
            foreach (is_array($manifest['extension_points'] ?? null) ? $manifest['extension_points'] : [] as $point) {
                if (is_string($point) && trim($point) !== '') {
                    $graph[$suite]['extension_points'][] = trim($point);
                }
            }
        } elseif ($kind === MODULE_KIND_EXTENSION) {
            $graph[$suite]['extensions'][] = $moduleId;
        } elseif ($kind === MODULE_KIND_ADAPTER) {
            $graph[$suite]['adapters'][] = $moduleId;
        } elseif ($kind === MODULE_KIND_PROFILE) {
            $graph[$suite]['profiles'][] = $moduleId;
        }
    }

    foreach ($graph as &$suiteEntry) {
        sort($suiteEntry['modules']);
        sort($suiteEntry['extensions']);
        sort($suiteEntry['adapters']);
        sort($suiteEntry['profiles']);
        sort($suiteEntry['extension_points']);
        $suiteEntry['extension_points'] = array_values(array_unique($suiteEntry['extension_points']));
    }
    unset($suiteEntry);

    ksort($graph);
    return $graph;
}

/**
 * List all product suite ids present in the fleet.
 *
 * @return string[]
 */
function moduleSuites(?array $modules = null): array
{
    return array_keys(moduleSuiteGraph($modules));
}

/**
 * Return the full member list (module ids) of a product suite, or [].
 *
 * @return string[]
 */
function moduleSuiteMembers(string $suiteId, ?array $modules = null): array
{
    $graph = moduleSuiteGraph($modules);
    $suiteId = (string)normalizeModuleSuiteId($suiteId);
    return $graph[$suiteId]['modules'] ?? [];
}

/**
 * Return the product-core module id of a suite, or null.
 */
function moduleSuiteCore(string $suiteId, ?array $modules = null): ?string
{
    $graph = moduleSuiteGraph($modules);
    $suiteId = (string)normalizeModuleSuiteId($suiteId);
    $core = $graph[$suiteId]['core'] ?? null;
    return is_string($core) && $core !== '' ? $core : null;
}

/**
 * Return the extension-point ids a suite exposes (union across its cores).
 *
 * @return string[]
 */
function moduleSuiteExtensionPoints(string $suiteId, ?array $modules = null): array
{
    $graph = moduleSuiteGraph($modules);
    $suiteId = (string)normalizeModuleSuiteId($suiteId);
    return $graph[$suiteId]['extension_points'] ?? [];
}

/**
 * Return the suite id a module belongs to (from manifest), or null.
 */
function moduleSuiteForModule(string $moduleId): ?string
{
    $modules = discoverModules();
    $manifest = $modules[$moduleId] ?? [];
    return moduleSuiteFromManifest($manifest);
}

/**
 * Resolve the admin shell host for a suite contribution. The suite core may
 * declare an `admin_host` in its manifest; otherwise the core module id is
 * treated as the default admin host.
 */
function moduleSuiteAdminHost(string $suiteId): ?string
{
    $graph = moduleSuiteGraph();
    $suiteId = (string)normalizeModuleSuiteId($suiteId);
    if (!isset($graph[$suiteId])) {
        return null;
    }
    $coreId = $graph[$suiteId]['core'];
    if ($coreId === null) {
        return null;
    }
    $modules = discoverModules();
    $manifest = $modules[$coreId] ?? [];
    $adminHost = $manifest['admin_host'] ?? null;
    return is_string($adminHost) && trim($adminHost) !== '' ? trim($adminHost) : $coreId;
}

// ─── Dynamic Contribution Registry ──────────────────────────────────────
// Aggregates manifest-declared admin contributions from enabled modules into
// a normalized, host/location-addressed registry. This is the "administration
// is a contribution surface" contract: a module registers its surfaces in its
// manifest, the kernel aggregates and validates them, and admin shells render
// whatever is valid for the current tenant/enablement state.

/**
 * Normalize a raw admin_contributions entry into the canonical shape.
 *
 * A stable `id` is derived when not declared: `<module>.<location>` for the
 * first contribution from a module at a location, or `<module>.<location>.<n>`
 * for subsequent ones (caller supplies the explicit raw id when present).
 *
 * @param array<string,mixed> $raw
 * @return array<string,mixed>
 */
function kernelContributionNormalize(array $raw, string $moduleId): array
{
    $location = trim((string)($raw['location'] ?? ''));
    $declaredId = trim((string)($raw['id'] ?? ''));
    $id = $declaredId !== ''
        ? $declaredId
        : ($moduleId . '.' . ($location !== '' ? $location : 'surface'));

    return [
        'id'         => $id,
        'host'       => trim((string)($raw['host'] ?? '')),
        'location'   => $location,
        'group'      => trim((string)($raw['group'] ?? '')),
        'label'      => trim((string)($raw['label'] ?? '')),
        'icon'       => trim((string)($raw['icon'] ?? '')),
        'route'      => trim((string)($raw['route'] ?? '')),
        'permission' => trim((string)($raw['permission'] ?? '')),
        'roles'      => is_array($raw['roles'] ?? null) ? array_values(array_filter(array_map('strval', $raw['roles']))) : [],
        'order'      => is_int($raw['order'] ?? null) ? $raw['order'] : 0,
        'active_key' => trim((string)($raw['active_key'] ?? '')),
        'guidance'   => trim((string)($raw['guidance'] ?? '')),
        'module'     => $moduleId,
    ];
}

/**
 * Resolve the effective tenant id for contribution filtering from a context
 * array or the current request (multi-tenant mode).
 *
 * @param array<string,mixed>|null $context
 */
function kernelContributionContextTenantId(?array $context = null): ?int
{
    if (is_array($context) && array_key_exists('tenant_id', $context)) {
        $raw = $context['tenant_id'];
        if (is_int($raw) && $raw > 0) {
            return $raw;
        }
        if (is_string($raw) && ctype_digit($raw)) {
            return (int)$raw;
        }
        return null;
    }
    if (function_exists('moduleTenantSettingsTenantId')) {
        $current = moduleTenantSettingsTenantId();
        if (is_int($current) && $current > 0) {
            return $current;
        }
    }
    return null;
}

/**
 * Determine whether a module is allowed to contribute for the given context:
 *  - explicit tenant id → isModuleEnabledForTenant()
 *  - otherwise → manifest _enabled flag (already tenant-aware in tenant mode)
 *
 * @param array<string,mixed>|null $context
 */
function kernelContributionModuleAllowed(string $moduleId, array $manifest, ?array $context = null): bool
{
    if (empty($manifest['_enabled'])) {
        return false;
    }
    $tenantId = kernelContributionContextTenantId($context);
    if ($tenantId !== null && function_exists('isModuleEnabledForTenant')) {
        // Explicit tenant context overrides the ambient _enabled flag so a
        // globally-installed module that is disabled for this tenant does not
        // leak into it.
        return isModuleEnabledForTenant($moduleId, $tenantId);
    }
    return true;
}

/**
 * Apply role-based filtering to a normalized contribution.
 *
 * @param array<string,mixed> $contrib
 * @param array<string,mixed>|null $context
 */
function kernelContributionRoleAllowed(array $contrib, ?array $context = null): bool
{
    $roles = is_array($contrib['roles'] ?? null) ? $contrib['roles'] : [];
    if ($roles === []) {
        return true; // no role restriction
    }
    $user = is_array($context['user'] ?? null) ? $context['user'] : [];
    $role = trim((string)($user['role'] ?? ''));
    if ($role === '') {
        return false; // restricted contribution but no known role
    }
    return in_array($role, $roles, true);
}

/**
 * Build the full contribution registry from enabled modules.
 *
 * Duplicate-contribution rule (documented): a contribution id is unique per
 * (host, location). When two enabled modules declare the same id for the same
 * host+location, the first one wins and the collision is recorded in the
 * returned `_conflicts` list (severity: advisory). This prevents silent UI
 * collisions without hard-failing legacy manifests.
 *
 * @param array<string,array<string,mixed>>|null $modules override discovery
 * @param array<string,mixed>|null $context tenant_id / user filtering
 * @return array<string,array<int,array<string,mixed>>> keyed "host:location"
 */
function kernelContributionRegistry(?array $modules = null, ?array $context = null): array
{
    if ($modules === null) {
        $modules = discoverModules();
    }

    $registry = [];
    $seenIds = [];
    $conflicts = [];
    foreach ($modules as $moduleId => $manifest) {
        if (!kernelContributionModuleAllowed($moduleId, $manifest, $context)) {
            continue; // disabled/tenant-disabled modules must not contribute
        }
        $contribs = $manifest['admin_contributions'] ?? [];
        if (!is_array($contribs)) {
            continue;
        }
        foreach ($contribs as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $normalized = kernelContributionNormalize($raw, $moduleId);
            if ($normalized['host'] === '' || $normalized['location'] === '') {
                continue;
            }
            if (!kernelContributionRoleAllowed($normalized, $context)) {
                continue; // permission/role-gated contribution hidden
            }
            $key = $normalized['host'] . ':' . $normalized['location'];
            if ($normalized['id'] !== '') {
                $idKey = $key . '#' . $normalized['id'];
                if (isset($seenIds[$idKey])) {
                    $conflicts[] = [
                        'id' => $normalized['id'],
                        'host' => $normalized['host'],
                        'location' => $normalized['location'],
                        'module' => $moduleId,
                        'conflicts_with' => $seenIds[$idKey],
                        'severity' => \Ikabud\Kernel\Contracts\DiagnosticSeverity::Advisory->value,
                    ];
                    continue; // first-wins; drop the duplicate
                }
                $seenIds[$idKey] = $moduleId;
            }
            $registry[$key][] = $normalized;
        }
    }

    foreach ($registry as $key => &$items) {
        usort($items, static fn (array $a, array $b): int => ($a['order'] ?? 0) <=> ($b['order'] ?? 0));
    }
    unset($items);

    ksort($registry);

    if ($conflicts !== []) {
        $registry['_conflicts'] = $conflicts;
    }

    return $registry;
}

/**
 * Return contribution conflicts recorded by the last registry build.
 *
 * @return array<int,array<string,mixed>>
 */
function kernelContributionConflicts(?array $modules = null): array
{
    $registry = kernelContributionRegistry($modules);
    return $registry['_conflicts'] ?? [];
}

/**
 * List normalized contributions for a host (optionally filtered by location).
 *
 * @param array<string,array<string,mixed>>|null $modules override discovery
 * @param array<string,mixed>|null $context tenant_id / user filtering
 * @return array<int,array<string,mixed>>
 */
function kernelContributionsForHost(string $host, ?string $location = null, ?array $modules = null, ?array $context = null): array
{
    $host = trim($host);
    if ($host === '') {
        return [];
    }
    $registry = kernelContributionRegistry($modules, $context);
    $result = [];
    foreach ($registry as $key => $items) {
        if ($key === '_conflicts' || !str_contains($key, ':')) {
            continue;
        }
        [$candidateHost, $candidateLocation] = explode(':', $key, 2);
        if ($candidateHost !== $host) {
            continue;
        }
        if ($location !== null && $candidateLocation !== $location) {
            // Both location conventions are accepted: bare ('sidebar') and
            // host-prefixed ('cms.sidebar' for host 'cms'). This closes the
            // drift where a manifest declared location 'cms.sidebar' but the
            // consumer queried 'sidebar' (e.g. the CMS sidebar bridge).
            if ($candidateLocation !== $host . '.' . $location) {
                continue;
            }
        }
        foreach ($items as $item) {
            $result[] = $item;
        }
    }
    return $result;
}

/**
 * Convenience: contributions for a specific host + location.
 *
 * @param array<string,array<string,mixed>>|null $modules override discovery
 * @param array<string,mixed>|null $context tenant_id / user filtering
 * @return array<int,array<string,mixed>>
 */
function kernelContributionsForHostLocation(string $host, string $location, ?array $modules = null, ?array $context = null): array
{
    return kernelContributionsForHost($host, $location, $modules, $context);
}

/**
 * Integration Contract Registry — "Declaration Before Integration."
 *
 * Builds a lookup map of every module's declared integrations from all
 * module.json manifests.  Only modules that explicitly declare an
 * `integrations` block participate.
 *
 * Return shape (static-cached per request):
 *   [
 *     'map' => [ callerModuleId => [ providerModuleId => [ capId, ... ], ... ] ],
 *     'features' => [ callerModuleId => [ providerModuleId => string[], ... ] ],
 *   ]
 *
 * @param array<string,array<string,mixed>>|null $modules override discovery
 * @return array<string,mixed>
 */
function kernelModuleIntegrationMap(?array $modules = null): array
{
    static $cache = null;
    if ($modules === null && is_array($cache)) {
        return $cache;
    }
    if ($modules === null) {
        $modules = discoverModules();
    }

    $map = [];
    $features = [];
    foreach ($modules as $moduleId => $manifest) {
        $integrations = $manifest['integrations'] ?? null;
        if (!is_array($integrations)) { continue; }

        $callerMap = [];
        $callerFeatures = [];
        foreach ($integrations as $providerModuleId => $contract) {
            if (!is_string($providerModuleId) || $providerModuleId === '' || !is_array($contract)) { continue; }

            $uses = $contract['uses'] ?? [];
            if (!is_array($uses)) { $uses = []; }
            $uses = array_values(array_filter($uses, static fn ($u) => is_string($u) && $u !== ''));

            $addsFeatures = $contract['adds_features'] ?? [];
            if (!is_array($addsFeatures)) { $addsFeatures = []; }
            $addsFeatures = array_values(array_filter($addsFeatures, static fn ($f) => is_string($f) && $f !== ''));

            $type = strtolower(trim((string)($contract['type'] ?? 'optional')));
            if ($type === '') { $type = 'optional'; }

            $callerMap[$providerModuleId] = array_merge($uses, ['__type' => $type]);
            if ($addsFeatures !== []) { $callerFeatures[$providerModuleId] = $addsFeatures; }
        }
        if ($callerMap !== []) {
            $map[$moduleId] = $callerMap;
            if ($callerFeatures !== []) { $features[$moduleId] = $callerFeatures; }
        }
    }

    $cache = ['map' => $map, 'features' => $features];
    return $cache;
}

/**
 * Check whether a caller module has a declared integration allowing it to call
 * a specific capability from a specific provider module.
 *
 * @return bool
 */
function integrationIsDeclared(string $callerModuleId, string $providerModuleId, string $capabilityId): bool
{
    $callerModuleId = trim($callerModuleId);
    $providerModuleId = trim($providerModuleId);
    $capabilityId = trim($capabilityId);
    if ($callerModuleId === '' || $providerModuleId === '' || $capabilityId === '') { return false; }

    $registry = kernelModuleIntegrationMap();
    $callerIntegrations = $registry['map'][$callerModuleId] ?? null;
    if (!is_array($callerIntegrations)) { return false; }

    $uses = $callerIntegrations[$providerModuleId] ?? null;
    if (!is_array($uses)) { return false; }

    foreach ($uses as $declared) {
        if ($declared === '__type') { continue; }
        if ($declared === $capabilityId) { return true; }
        $baseDeclared = str_contains((string)$declared, '@') ? explode('@', (string)$declared, 2)[0] : $declared;
        $baseRequested = str_contains($capabilityId, '@') ? explode('@', $capabilityId, 2)[0] : $capabilityId;
        if ($baseDeclared === $baseRequested) { return true; }
    }
    return false;
}

/**
 * Check whether an enabled host module declares a given contribution location.
 * Used by install gates and Workbench checks.
 */
function kernelHostDeclaresLocation(string $hostModuleId, string $location): bool
{
    $modules = discoverModules();
    $manifest = $modules[$hostModuleId] ?? [];
    $points = is_array($manifest['extension_points'] ?? null) ? $manifest['extension_points'] : [];
    return in_array($location, $points, true);
}

/**
 * Resolve a module's routes file path from its manifest (or an explicit
 * module path override, used during install before the module is discovered).
 *
 * @param array<string,mixed> $manifest
 * @param string $modulePath explicit module dir (install-time override)
 */
function moduleRoutesFilePathForManifest(array $manifest, string $modulePath = ''): string
{
    if ($modulePath === '') {
        $modulePath = trim((string)($manifest['_path'] ?? ''));
    }
    if ($modulePath === '') {
        $moduleId = trim((string)($manifest['id'] ?? ''));
        $manifestPath = $moduleId !== '' ? moduleManifestPathForId($moduleId) : null;
        $modulePath = $manifestPath !== null ? dirname($manifestPath) : '';
    }
    if ($modulePath === '' || !is_dir($modulePath)) {
        return '';
    }

    $routesDecl = $manifest['routes'] ?? true;
    if (is_string($routesDecl) && $routesDecl !== '') {
        $routesFile = $modulePath . '/' . ltrim($routesDecl, '/');
    } elseif ($routesDecl === false) {
        return '';
    } else {
        $routesFile = $modulePath . '/routes.php';
    }
    return is_file($routesFile) ? $routesFile : '';
}

/**
 * Verify that a module actually registers a contribution route in its GET
 * route map. Prevents dynamically generated dead links.
 *
 * @param string $moduleId contributing module
 * @param string $route contribution route (absolute path)
 * @param array<string,mixed>|null $manifest optional manifest with _path
 * @return bool true when the module owns a GET route matching the path
 */
function moduleContributionRouteRegistered(string $moduleId, string $route, ?array $manifest = null): bool
{
    $route = trim($route);
    if ($route === '') {
        return false;
    }
    if ($manifest === null) {
        $modules = discoverModules();
        $manifest = $modules[$moduleId] ?? [];
    }
    $routesFile = moduleRoutesFilePathForManifest($manifest);
    if ($routesFile === '') {
        return false;
    }

    $routes = require $routesFile;
    if (!is_array($routes) || !is_array($routes['GET'] ?? null)) {
        return false;
    }

    $path = rtrim((string)(parse_url($route, PHP_URL_PATH) ?: $route), '/') ?: '/';
    foreach (array_keys($routes['GET']) as $pattern) {
        if (moduleRoutePatternMatchesPath((string)$pattern, $path)) {
            return true;
        }
    }
    return false;
}

/**
 * Verify that a module registers a contribution route, with explicit method
 * handling. Currently only GET-backed nav contributions are supported; returns
 * the matched method when found.
 *
 * @param array<string,mixed>|null $manifest optional manifest with _path
 * @return string|null matched HTTP method, or null when not registered
 */
function moduleContributionRouteMethod(string $moduleId, string $route, ?array $manifest = null): ?string
{
    $route = trim($route);
    if ($route === '') {
        return null;
    }
    if ($manifest === null) {
        $modules = discoverModules();
        $manifest = $modules[$moduleId] ?? [];
    }
    $routesFile = moduleRoutesFilePathForManifest($manifest);
    if ($routesFile === '') {
        return null;
    }

    $routes = require $routesFile;
    if (!is_array($routes)) {
        return null;
    }

    $path = rtrim((string)(parse_url($route, PHP_URL_PATH) ?: $route), '/') ?: '/';
    foreach (['GET', 'POST'] as $method) {
        $map = $routes[$method] ?? null;
        if (!is_array($map)) {
            continue;
        }
        foreach (array_keys($map) as $pattern) {
            if (moduleRoutePatternMatchesPath((string)$pattern, $path)) {
                return $method;
            }
        }
    }
    return null;
}

/**
 * Resolve the current request context for contribution rendering (tenant +
 * user), used by admin-shell bridges.
 *
 * @return array<string,mixed>
 */
function kernelContributionRequestContext(): array
{
    $context = [];
    $tenantId = kernelContributionContextTenantId();
    if ($tenantId !== null) {
        $context['tenant_id'] = $tenantId;
    }
    if (function_exists('app')) {
        try {
            $user = app()->user();
            if (is_array($user) && ($user['role'] ?? '') !== '') {
                $context['user'] = $user;
            }
        } catch (Throwable $e) {
            // no authenticated user context — role filtering stays permissive
        }
    }
    return $context;
}

/**
 * Bridge: fold manifest-declared sidebar contributions for host "cms" into the
 * existing `cms.admin.nav_items` hook so the CMS admin shell renders them
 * without template changes. Grouped contributions become collapsible sections
 * (matching the admin.disyl rendering contract); ungrouped become flat items.
 * Tenant and role filtering from the current request apply automatically.
 *
 * @param array<string,array<string,mixed>>|null $modules override discovery (tests)
 */
function kernelContributionBridgeCmsNavItems(?array $modules = null): callable
{
    return static function (array $items) use ($modules): array {
        $context = kernelContributionRequestContext();
        $contribs = kernelContributionsForHostLocation('cms', 'sidebar', $modules, $context);
        if ($contribs === []) {
            return $items;
        }

        $flat = [];
        $grouped = [];
        foreach ($contribs as $contrib) {
            if ($contrib['group'] !== '') {
                $grouped[$contrib['group']][] = $contrib;
            } else {
                $flat[] = $contrib;
            }
        }

        foreach ($flat as $contrib) {
            $items[] = [
                'label'      => $contrib['label'],
                'url'        => $contrib['route'],
                'icon'       => $contrib['icon'] !== '' ? $contrib['icon'] : 'box',
                'active_key' => $contrib['active_key'],
                'module'     => $contrib['module'],
            ];
        }
        foreach ($grouped as $groupLabel => $groupItems) {
            $children = [];
            foreach ($groupItems as $contrib) {
                $children[] = [
                    'label'      => $contrib['label'],
                    'url'        => $contrib['route'],
                    'icon'       => $contrib['icon'] !== '' ? $contrib['icon'] : 'box',
                    'active_key' => $contrib['active_key'],
                ];
            }
            $items[] = [
                'section'  => true,
                'label'    => ucfirst($groupLabel),
                'children' => $children,
            ];
        }
        return $items;
    };
}

/**
 * Export a module's owned tables to a SQL file (INSERT statements) in storage/module-exports/.
 * Returns ['ok'=>true,'dir'=>'...','files'=>string[]] or ['ok'=>false,'error'=>'...']
 */
function exportModuleOwnedTables(string $moduleId, array $manifest, ?string $exportDir = null): array
{
    $tables = $manifest['owns_tables'] ?? [];
    if (!is_array($tables) || empty($tables)) {
        return ['ok' => true, 'dir' => $exportDir ?: '', 'files' => []];
    }

    $stamp = date('Ymd-His');
    $base = STORAGE_PATH . '/module-exports';
    $dir = $exportDir;
    if ($dir === null || $dir === '') {
        $dir = $base . '/' . $moduleId . '-' . $stamp;
    } elseif (!str_starts_with($dir, '/')) {
        $dir = BASE_PATH . '/' . ltrim($dir, '/');
    }

    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    $pdo = app()->db();
    $files = [];

    foreach ($tables as $table) {
        if (!is_string($table) || trim($table) === '') {
            continue;
        }
        $table = trim($table);

        try {
            $exists = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($table))->fetchColumn();
            if ($exists === false) {
                continue;
            }

            $colsStmt = $pdo->query("SHOW COLUMNS FROM `{$table}`");
            $cols = $colsStmt ? $colsStmt->fetchAll(PDO::FETCH_ASSOC) : [];
            $colNames = [];
            foreach ($cols as $c) {
                if (!is_array($c) || empty($c['Field'])) continue;
                $colNames[] = (string)$c['Field'];
            }
            if (empty($colNames)) {
                continue;
            }

            $outPath = rtrim($dir, '/') . '/' . $table . '.sql';
            $fh = fopen($outPath, 'wb');
            if ($fh === false) {
                return ['ok' => false, 'error' => "Cannot write export file: {$outPath}"];
            }

            fwrite($fh, "-- Export: {$table}\n");
            fwrite($fh, "-- Generated: " . date('c') . "\n\n");

            $select = $pdo->query("SELECT * FROM `{$table}`");
            if ($select) {
                while ($row = $select->fetch(PDO::FETCH_ASSOC)) {
                    if (!is_array($row)) continue;
                    $colsSql = implode(', ', array_map(fn($c) => '`' . str_replace('`', '``', $c) . '`', $colNames));
                    $vals = [];
                    foreach ($colNames as $c) {
                        $v = $row[$c] ?? null;
                        $vals[] = $v === null ? 'NULL' : $pdo->quote((string)$v);
                    }
                    $valsSql = implode(', ', $vals);
                    fwrite($fh, "INSERT INTO `{$table}` ({$colsSql}) VALUES ({$valsSql});\n");
                }
            }

            fclose($fh);
            $files[] = $outPath;
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => "Export failed for table '{$table}': {$e->getMessage()}"];
        }
    }

    return ['ok' => true, 'dir' => $dir, 'files' => $files];
}

function moduleRegistryPath(): string
{
    return STORAGE_PATH . '/modules.json';
}

/**
 * Return declared auth cookie names without triggering module enablement or tenant-setting resolution.
 * This is used from app()->user(), so it must stay bootstrap-safe and recursion-free.
 *
 * @return array<int, string>
 */
function declaredModuleAuthCookieNames(): array
{
    static $names = null;
    if (is_array($names)) {
        return $names;
    }

    $ttl = max(0, (int)($_ENV['MODULE_AUTH_COOKIE_CACHE_TTL'] ?? 300));
    if ($ttl > 0) {
        $cached = app()->cache()->get('kernel_bootstrap', 'module_auth_cookies:v2');
        if (is_array($cached) && isset($cached['names']) && is_array($cached['names'])) {
            $names = array_values(array_filter($cached['names'], fn($name) => is_string($name) && $name !== ''));
            return $names;
        }
    }

    $names = [];
    $dir = modulesPath();
    if (!is_dir($dir)) {
        return $names;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            static function (SplFileInfo $current): bool {
                $name = $current->getFilename();
                if ($name === '.' || $name === '..') {
                    return false;
                }
                if ($current->isDir() && preg_match('/\.bak_\d{8}_\d{6}$/', $name)) {
                    return false;
                }
                return true;
            }
        ),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile() || $file->getFilename() !== 'module.json') {
            continue;
        }

        $manifest = json_decode((string)file_get_contents($file->getPathname()), true);
        if (!is_array($manifest)) {
            continue;
        }

        $cookie = trim((string)($manifest['auth_cookie'] ?? ''));
        if ($cookie !== '' && !in_array($cookie, $names, true)) {
            $names[] = $cookie;
        }
    }

    sort($names);
    if ($ttl > 0) {
        app()->cache()->set('kernel_bootstrap', 'module_auth_cookies:v2', ['names' => $names], $ttl);
    }
    return $names;
}

function moduleTenantSettingsTable(): string
{
    return 'tenant_module_settings';
}

function moduleTenantSettingsModeEnabled(): bool
{
    try {
        if (!(bool) app()->config('app.multi_tenant.enabled', false)) {
            return false;
        }

        // In CLI, only enable tenant-scoped settings if a host is explicitly set.
        if (PHP_SAPI === 'cli' && empty($_SERVER['HTTP_HOST'])) {
            return false;
        }

        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function moduleTenantSettingsTenantId(): ?int
{
    if (!moduleTenantSettingsModeEnabled()) {
        return null;
    }

    static $resolvingTenantId = false;

    try {
        $tenant = app()->tenant();
        $tenantId = $tenant->current();
        if ($tenantId === null && !$resolvingTenantId) {
            $resolvingTenantId = true;
            try {
                $tenantId = $tenant->resolve();
            } finally {
                $resolvingTenantId = false;
            }
        }
        if ($tenantId === null || $tenantId <= 0) {
            return null;
        }
        return (int) $tenantId;
    } catch (Throwable $e) {
        return null;
    }
}

function moduleTenantSettingsCanWriteExplicitTenant(int $tenantId): bool
{
    if ($tenantId <= 0) {
        return false;
    }

    $activeContext = kernel_request_context_get('_activeModuleContext');
    if (!is_object($activeContext) || !method_exists($activeContext, 'moduleId')) {
        return true;
    }

    $moduleId = (string)$activeContext->moduleId();
    $currentTenantId = moduleTenantSettingsTenantId();
    if ($currentTenantId !== null && $currentTenantId > 0 && $currentTenantId === $tenantId) {
        return true;
    }

    write_log('Blocked cross-tenant tenant_module_settings write from module context', 'warning', [
        'module' => $moduleId,
        'current_tenant_id' => $currentTenantId,
        'target_tenant_id' => $tenantId,
    ]);

    return false;
}

function moduleTenantSettingsCanReadExplicitTenant(string $moduleId, int $tenantId): bool
{
    if ($tenantId <= 0 || $moduleId === '') {
        return false;
    }

    $activeContext = kernel_request_context_get('_activeModuleContext');
    if (!is_object($activeContext) || !method_exists($activeContext, 'moduleId')) {
        return true;
    }

    $callerModuleId = (string)$activeContext->moduleId();
    $currentTenantId = moduleTenantSettingsTenantId();
    if ($currentTenantId !== null && $currentTenantId > 0 && $currentTenantId === $tenantId) {
        return true;
    }

    $allowedCrossTenantReaders = [
        'guidance' => ['ecommerce'],
    ];

    if (isset($allowedCrossTenantReaders[$callerModuleId]) && in_array($moduleId, $allowedCrossTenantReaders[$callerModuleId], true)) {
        return true;
    }

    write_log('Blocked cross-tenant tenant_module_settings read from module context', 'warning', [
        'module' => $callerModuleId,
        'target_module' => $moduleId,
        'current_tenant_id' => $currentTenantId,
        'target_tenant_id' => $tenantId,
    ]);

    return false;
}

function moduleTenantSettingsTableExists(PDO $db): bool
{
    // Use the driver-aware inspector when available (mysql information_schema,
    // sqlite sqlite_master). It also logs inspection failures instead of
    // silently treating them as "table absent".
    if (function_exists('tenantDatabaseHasTable')) {
        return tenantDatabaseHasTable($db, moduleTenantSettingsTable());
    }

    try {
        $stmt = $db->query("SHOW TABLES LIKE '" . moduleTenantSettingsTable() . "'");
        return $stmt && $stmt->fetchColumn() !== false;
    } catch (Throwable $e) {
        return false;
    }
}

function moduleTenantSettingsEnsureTable(PDO $db): bool
{
    if (moduleTenantSettingsTableExists($db)) {
        return true;
    }

    try {
        $db->exec(
            'CREATE TABLE IF NOT EXISTS ' . moduleTenantSettingsTable() . ' ('
            . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, '
            . 'tenant_id INT UNSIGNED NOT NULL, '
            . 'module_id VARCHAR(100) NOT NULL, '
            . 'setting_key VARCHAR(120) NOT NULL, '
            . 'setting_value JSON NULL, '
            . 'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, '
            . 'updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, '
            . 'UNIQUE KEY uq_tenant_module_setting (tenant_id, module_id, setting_key), '
            . 'KEY idx_tenant_module (tenant_id, module_id), '
            . 'KEY idx_module_key (module_id, setting_key)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * @return array<string, mixed>
 */
function readTenantModuleSettings(string $moduleId): array
{
    $tenantId = moduleTenantSettingsTenantId();
    if ($tenantId === null || $moduleId === '') {
        return [];
    }

    // Use per-request cache if available (populated by preloadAllTenantModuleSettings)
    $cacheKey = '_tenant_module_settings_cache';
    if (isset($GLOBALS[$cacheKey]) && is_array($GLOBALS[$cacheKey])) {
        return $GLOBALS[$cacheKey][$moduleId] ?? [];
    }

    // Single-module fallback (rarely used after preload is in place).
    // Lazily preload ALL settings for this tenant in a single query on first
    // use, so per-module calls (e.g. isModuleEnabled() during module discovery,
    // which runs before the request-level preload in index.php) don't each hit
    // the DB. Bluehost-safe: preload uses one prepared SELECT.
    if (function_exists('preloadAllTenantModuleSettings')) {
        preloadAllTenantModuleSettings();
        return $GLOBALS[$cacheKey][$moduleId] ?? [];
    }
    return _readTenantModuleSettingsSingle($moduleId, $tenantId);
}

/**
 * Preload ALL module settings for the current tenant in a single DB query.
 * Populates a per-request cache used by readTenantModuleSettings().
 */
function preloadAllTenantModuleSettings(): void
{
    $cacheKey = '_tenant_module_settings_cache';
    if (kernel_request_context_has($cacheKey)) {
        return; // Already loaded
    }

    $tenantId = moduleTenantSettingsTenantId();
    if ($tenantId === null) {
        kernel_request_context_set($cacheKey, []);
        return;
    }

    \Ikabud\Kernel\Database\KernelPDO::kernelEscalationEnter();
    try {
        $db = app()->db();
        if (!moduleTenantSettingsEnsureTable($db)) {
            kernel_request_context_set($cacheKey, []);
            return;
        }

        $stmt = $db->prepare(
            'SELECT module_id, setting_key, setting_value '
            . 'FROM ' . moduleTenantSettingsTable() . ' '
            . 'WHERE tenant_id = :tid'
        );
        $stmt->execute([':tid' => $tenantId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $cache = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $mid = trim((string)($row['module_id'] ?? ''));
            $key = trim((string)($row['setting_key'] ?? ''));
            if ($mid === '' || $key === '') continue;
            $raw = (string)($row['setting_value'] ?? 'null');
            $decoded = json_decode($raw, true);
            if (!isset($cache[$mid])) $cache[$mid] = [];
            $cache[$mid][$key] = (json_last_error() === JSON_ERROR_NONE) ? $decoded : $raw;
        }

        kernel_request_context_set($cacheKey, $cache);
    } catch (Throwable $e) {
        kernel_request_context_set($cacheKey, []);
    } finally {
        \Ikabud\Kernel\Database\KernelPDO::kernelEscalationLeave();
    }
}

/**
 * Invalidate the per-request module settings cache (after a save).
 */
function invalidateTenantModuleSettingsCache(): void
{
    kernel_request_context_delete('_tenant_module_settings_cache');
}

/**
 * Single-module DB read (no cache).
 * @internal
 */
function _readTenantModuleSettingsSingle(string $moduleId, int $tenantId, ?PDO $dbOverride = null): array
{
    \Ikabud\Kernel\Database\KernelPDO::kernelEscalationEnter();
    try {
        $db = $dbOverride ?? app()->db();
        if (!moduleTenantSettingsEnsureTable($db)) {
            return [];
        }

        $stmt = $db->prepare(
            'SELECT setting_key, setting_value '
            . 'FROM ' . moduleTenantSettingsTable() . ' '
            . 'WHERE tenant_id = :tid AND module_id = :mid'
        );
        $stmt->execute([
            ':tid' => $tenantId,
            ':mid' => $moduleId,
        ]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $settings = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $key = trim((string)($row['setting_key'] ?? ''));
            if ($key === '') continue;
            $raw = (string)($row['setting_value'] ?? 'null');
            $decoded = json_decode($raw, true);
            $settings[$key] = (json_last_error() === JSON_ERROR_NONE) ? $decoded : $raw;
        }

        return $settings;
    } catch (Throwable $e) {
        return [];
    } finally {
        \Ikabud\Kernel\Database\KernelPDO::kernelEscalationLeave();
    }
}

/**
 * @param array<string, mixed> $settings
 */
function saveTenantModuleSettings(string $moduleId, array $settings): bool
{
    $tenantId = moduleTenantSettingsTenantId();
    if ($tenantId === null || $moduleId === '' || empty($settings)) {
        return false;
    }

    // Kernel-level operation: bypass ModuleDB enforcement so any module
    // can persist its own settings without declaring tenant_module_settings.
    \Ikabud\Kernel\Database\KernelPDO::kernelEscalationEnter();
    try {
        $db = app()->db();
        if (!moduleTenantSettingsEnsureTable($db)) {
            return false;
        }

        $sql = 'INSERT INTO ' . moduleTenantSettingsTable() . ' '
            . '(tenant_id, module_id, setting_key, setting_value, created_at, updated_at) '
            . 'VALUES (:tid, :mid, :skey, :sval, NOW(), NOW()) '
            . 'ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()';
        $stmt = $db->prepare($sql);

        foreach ($settings as $key => $value) {
            $skey = trim((string)$key);
            if ($skey === '') {
                continue;
            }
            $stmt->execute([
                ':tid' => $tenantId,
                ':mid' => $moduleId,
                ':skey' => $skey,
                ':sval' => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]);
        }

        return true;
    } catch (Throwable $e) {
        return false;
    } finally {
        \Ikabud\Kernel\Database\KernelPDO::kernelEscalationLeave();
        invalidateTenantModuleSettingsCache();
    }
}

// ─── Superadmin cross-tenant read/write (explicit tenant_id) ──────────────

/**
 * Read module settings for an explicit tenant ID (superadmin use).
 * Unlike readTenantModuleSettings() this does NOT use the request's
 * implicit tenant context or the per-request cache.
 */
function readTenantModuleSettingsForTenant(string $moduleId, int $tenantId): array
{
    if ($moduleId === '' || $tenantId <= 0) {
        return [];
    }
    if (!moduleTenantSettingsCanReadExplicitTenant($moduleId, $tenantId)) {
        return [];
    }
    $db = app()->dbForTenant($tenantId);
    if ($db === null) {
        return [];
    }
    return _readTenantModuleSettingsSingle($moduleId, $tenantId, $db);
}

/**
 * Save module settings for an explicit tenant ID (superadmin use).
 */
function saveTenantModuleSettingsForTenant(string $moduleId, int $tenantId, array $settings): bool
{
    if ($tenantId <= 0 || $moduleId === '' || empty($settings)) {
        return false;
    }

    if (!moduleTenantSettingsCanWriteExplicitTenant($tenantId)) {
        return false;
    }

    \Ikabud\Kernel\Database\KernelPDO::kernelEscalationEnter();
    try {
        $db = app()->dbForTenant($tenantId);
        if ($db === null || !moduleTenantSettingsEnsureTable($db)) {
            return false;
        }

        $sql = 'INSERT INTO ' . moduleTenantSettingsTable() . ' '
            . '(tenant_id, module_id, setting_key, setting_value, created_at, updated_at) '
            . 'VALUES (:tid, :mid, :skey, :sval, NOW(), NOW()) '
            . 'ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()';
        $stmt = $db->prepare($sql);

        foreach ($settings as $key => $value) {
            $skey = trim((string)$key);
            if ($skey === '') {
                continue;
            }
            $stmt->execute([
                ':tid' => $tenantId,
                ':mid' => $moduleId,
                ':skey' => $skey,
                ':sval' => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]);
        }

        return true;
    } catch (Throwable $e) {
        return false;
    } finally {
        \Ikabud\Kernel\Database\KernelPDO::kernelEscalationLeave();
    }
}

/**
 * Get merged module settings for an explicit tenant ID (superadmin use).
 * Merges lifecycle-only keys from global registry with tenant DB overrides.
 */
function getModuleSettingsForTenant(string $moduleId, int $tenantId): array
{
    $registry = readModuleRegistry();
    $global = $registry[$moduleId]['settings'] ?? [];
    if (!is_array($global)) {
        $global = [];
    }

    $lifecycleKeys = ['allow_kernel_admin'];
    $safeGlobal = array_intersect_key($global, array_flip($lifecycleKeys));

    $tenant = readTenantModuleSettingsForTenant($moduleId, $tenantId);
    foreach (array_keys($tenant) as $tenantKey) {
        if (is_string($tenantKey) && str_starts_with($tenantKey, '_')) {
            unset($tenant[$tenantKey]);
        }
    }
    return array_merge($safeGlobal, $tenant);
}


require_once __DIR__ . '/module-catalog.php';
require_once __DIR__ . '/module-registry.php';
require_once __DIR__ . '/module-routes.php';

// ─── Discovery ────────────────────────────────────────────────────────────

/**
 * Discover ALL modules in modules/ directory (regardless of enabled state).
 * @return array<string, array<string, mixed>>
 */
function discoverModules(): array
{
    // Per-request cache: avoid repeated fs scans + DB queries
    if (isset($GLOBALS['_kernel_discovered_modules']) && is_array($GLOBALS['_kernel_discovered_modules'])) {
        return $GLOBALS['_kernel_discovered_modules'];
    }

    // Cross-request cache of the expensive recursive scan + manifest
    // validation (~700ms across 60+ modules). Only the scan/validation
    // result is cached; the per-call enabled state and ReadContractRegistry
    // registrations below are applied fresh on every request so module
    // enable/disable and table-ownership stay correct.
    $cacheKey = 'kernel.discovered_modules_scan_v1';
    $apcuEnabled = function_exists('apcu_fetch') && function_exists('apcu_store') && ini_get('apc.enabled');
    $result = null;
    if ($apcuEnabled) {
        $cached = apcu_fetch($cacheKey, $success);
        if ($success && is_array($cached)) {
            $result = $cached;
        }
    }

    if ($result === null) {
        $result = discoverModulesScanAll();
        if ($apcuEnabled) {
            // Short TTL (matches page-cache convention) so module.json edits
            // are picked up quickly during development while avoiding the
            // per-request recursive scan + validation across all modules.
            apcu_store($cacheKey, $result, 300);
        }
    }

    // Per-call state: enabled flag + table-ownership registration (fresh every request)
    foreach ($result as $moduleId => $manifest) {
        $manifest['_enabled'] = isModuleEnabled($moduleId);
        $result[$moduleId] = $manifest;

        // Register table ownership for ReadContractRegistry
        $owns = is_array($manifest['owns_tables'] ?? null) ? $manifest['owns_tables'] : [];
        $coOwns = is_array($manifest['co_owns_tables'] ?? null) ? $manifest['co_owns_tables'] : [];
        foreach (array_merge($owns, $coOwns) as $tableName) {
            if (is_string($tableName) && trim($tableName) !== '') {
                $registry = \Ikabud\Kernel\Contracts\ReadContractRegistry::getInstance();
                $normalizedTable = trim($tableName);
                $registry->registerTableOwner($normalizedTable, $moduleId);
                if ($normalizedTable === 'wms_stock') {
                    $registry->registerTableOwner('wms_stocks', $moduleId);
                }
            }
        }
    }

    $GLOBALS['_kernel_discovered_modules'] = $result;
    if (function_exists('kernelRegisterModuleReadContracts')) {
        kernelRegisterModuleReadContracts($result);
    }
    return $result;
}

/**
 * Recursive scan of modules/ for module.json manifests with schema
 * validation. Returns manifests keyed by module id with `_path` set.
 * Does NOT apply per-request state (enabled flag, contract registries) —
 * discoverModules() applies those on every call so cache staleness can
 * never leak enable/disable or ownership changes.
 */
function discoverModulesScanAll(): array
{
    $dir = modulesPath();
    if (!is_dir($dir)) {
        return [];
    }

    $result = [];
    $manifestPaths = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            static function (SplFileInfo $current): bool {
                $name = $current->getFilename();
                if ($name === '.' || $name === '..') {
                    return false;
                }
                if ($current->isDir() && preg_match('/\.bak_\d{8}_\d{6}$/', $name)) {
                    return false;
                }
                return true;
            }
        ),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile() || $file->getFilename() !== 'module.json') {
            continue;
        }
        $manifestPaths[] = $file->getPathname();
    }

    sort($manifestPaths);

    foreach ($manifestPaths as $manifestPath) {
        $validation = validateModuleManifestFileV1($manifestPath);
        if (empty($validation['ok'])) {
            // Fatal for the declaring module only: skip it entirely so one bad
            // drop-in manifest cannot take down every tenant at boot.
            $diagnostic = $validation['diagnostics'][0] ?? [];
            $folderId = basename(dirname($manifestPath));
            $message = '[fatal] Invalid module manifest at ' . $manifestPath . ': '
                . (string)($diagnostic['message'] ?? 'schema-v1 validation failed')
                . ' Correction: ' . (string)($diagnostic['correction'] ?? 'Run the strict manifest guard.');
            if (function_exists('write_log')) {
                write_log($message, 'error', [
                    'severity' => \Ikabud\Kernel\Contracts\DiagnosticSeverity::Fatal->value,
                    'module' => $folderId,
                    'manifest' => $manifestPath,
                ]);
            }
            if (function_exists('recordSkippedModule')) {
                recordSkippedModule($folderId, 'manifest_schema_v1_failed', [
                    'manifest' => $manifestPath,
                    'diagnostic' => $diagnostic,
                ]);
            }
            continue;
        }
        $manifest = $validation['manifest'];

        $moduleId = (string)$manifest['id'];
        if (isset($result[$moduleId])) {
            // Keep the first occurrence; the duplicate is skipped fatally.
            $message = "[fatal] Duplicate module id '{$moduleId}' at '{$manifestPath}'. "
                . 'Correction: assign a unique id or remove the duplicate manifest.';
            if (function_exists('write_log')) {
                write_log($message, 'error', [
                    'severity' => \Ikabud\Kernel\Contracts\DiagnosticSeverity::Fatal->value,
                    'module' => $moduleId,
                    'manifest' => $manifestPath,
                ]);
            }
            if (function_exists('recordSkippedModule')) {
                recordSkippedModule($moduleId . ':' . basename(dirname($manifestPath)), 'duplicate_module_id', [
                    'manifest' => $manifestPath,
                    'kept' => $result[$moduleId]['_path'] ?? '',
                ]);
            }
            continue;
        }

        $manifest['_path'] = dirname($manifestPath);
        $result[$moduleId] = $manifest;
    }

    return $result;
}

/**
 * Resolve the module that owns the auth/login surface for a tenant entry
 * module.
 *
 * Preference order:
 * 1. Manifest-declared `entry_delegate` (explicit routing delegate).
 * 2. Manifest-declared `authentication_provider` (auth owner).
 * 3. Legacy aliases (e.g. `ehr-core` -> `ehr`).
 * 4. The entry module itself.
 *
 * This replaces hard-coded knowledge of specific profile families in the
 * kernel routing layer: relationships are declared in the manifest instead.
 */
function tenantEntryModuleDelegateId(string $entryModuleId): string
{
    $entryModuleId = trim($entryModuleId);
    if ($entryModuleId === '') {
        return $entryModuleId;
    }

    $modules = discoverModules();
    $manifest = $modules[$entryModuleId] ?? null;
    if (is_array($manifest)) {
        $delegate = trim((string)($manifest['entry_delegate'] ?? ''));
        if ($delegate === '') {
            $delegate = trim((string)($manifest['authentication_provider'] ?? ''));
        }
        if ($delegate !== '') {
            return $delegate;
        }
    }

    if ($entryModuleId === 'ehr-core') {
        return 'ehr';
    }

    return $entryModuleId;
}

/**
 * Get only enabled modules.
 * @return array<string, array<string, mixed>>
 */
function getEnabledModules(): array
{
    static $cached = null;
    if (is_array($cached)) {
        return $cached;
    }

    resetSkippedModules();

    $enabled = array_filter(discoverModules(), fn($m) => !empty($m['_enabled']));

    // Parse entity authorities and contracts across all enabled modules 
    // strictly during kernel boot.
    app()->entityAuthority()->reset();
    app()->syncContracts()->reset();
    foreach ($enabled as $id => $mod) {
        if (!empty($mod['entities']) && is_array($mod['entities'])) {
            foreach ($mod['entities'] as $eType => $eDef) {
                if (!empty($eDef['authority']) && $eDef['authority'] === true) {
                    app()->entityAuthority()->registerAuthority($eType, $id, $eDef);
                }
                if (!empty($eDef['sync_contracts']) && is_array($eDef['sync_contracts'])) {
                    foreach ($eDef['sync_contracts'] as $operation => $handlerStr) {
                        app()->syncContracts()->registerContract($eType, $id, $operation, $handlerStr);
                    }
                }
            }
        }
    }
    $declaredCapabilities = [];
    foreach ($enabled as $module) {
        $check = validateModuleCapabilities($module);
        if (empty($check['ok']) || empty($check['exposes']) || !is_array($check['exposes'])) {
            continue;
        }
        foreach ($check['exposes'] as $expose) {
            $capId = is_array($expose) ? (string)($expose['id'] ?? '') : '';
            if ($capId !== '') {
                $declaredCapabilities[$capId] = true;
            }
        }
    }
    // Request-time safety net: if a module declares capability dependencies that
    // are not currently satisfiable, skip loading it rather than breaking the kernel.
    $safe = [];
    foreach ($enabled as $id => $m) {
        // Attach registry settings (from storage/modules.json) to manifest for request-time policy decisions.
        $m['_settings'] = getModuleSettings((string)($m['id'] ?? $id));
        $m['_entitlement'] = moduleTenantEntitlementStatus((string)($m['id'] ?? $id));

        if (!empty($m['_entitlement']['required']) && empty($m['_entitlement']['allowed'])) {
            recordSkippedModule($id, 'tenant_entitlement_required', [
                'approval_status' => (string)($m['_entitlement']['approval_status'] ?? 'unmanaged'),
                'commercial_mode' => (string)($m['_entitlement']['commercial_mode'] ?? 'bundled'),
                'entitlement_status' => (string)($m['_entitlement']['entitlement_status'] ?? 'unknown'),
            ]);
            continue;
        }

        // Kernel version compatibility check
        $requiresKernel = isset($m['requires_kernel']) ? trim((string)$m['requires_kernel']) : '';
        if ($requiresKernel !== '') {
            $currentKernel = \Ikabud\Kernel\App::KERNEL_VERSION;
            if (version_compare($currentKernel, $requiresKernel, '<')) {
                recordSkippedModule($id, 'requires_kernel', [
                    'requires_kernel' => $requiresKernel,
                    'current_kernel' => $currentKernel,
                ]);
                write_log(
                    "Module '{$id}' requires kernel >= {$requiresKernel} but current is {$currentKernel} — skipped",
                    'warning',
                    ['module' => $id, 'requires_kernel' => $requiresKernel, 'current_kernel' => $currentKernel]
                );
                continue;
            }
        }

        $check = validateModuleCapabilities($m);
        if (!$check['ok']) {
            recordSkippedModule($id, 'invalid_capability_manifest', [
                'error' => (string)($check['error'] ?? 'unknown'),
            ]);
            write_log(
                "Module '{$id}' capability manifest invalid — skipped: " . ($check['error'] ?? 'unknown'),
                'warning',
                ['module' => $id]
            );
            continue;
        }

        $entityContextCheck = validateModuleEntityContexts($m);
        if (!$entityContextCheck['ok']) {
            recordSkippedModule($id, 'invalid_entity_context_manifest', [
                'error' => (string)($entityContextCheck['error'] ?? 'unknown'),
            ]);
            write_log(
                "Module '{$id}' entity context manifest invalid — skipped: " . ($entityContextCheck['error'] ?? 'unknown'),
                'warning',
                ['module' => $id]
            );
            continue;
        }

        $deps = $check['depends'] ?? [];
        if (!empty($deps)) {
            $missing = [];
            foreach ($deps as $capId) {
                if (!isset($declaredCapabilities[$capId]) && !app()->capabilities()->has($capId)) {
                    $missing[] = $capId;
                }
            }
            if (!empty($missing)) {
                recordSkippedModule($id, 'missing_capability_providers', [
                    'missing' => $missing,
                ]);
                write_log(
                    "Module '{$id}' missing capability providers — skipped",
                    'warning',
                    ['module' => $id, 'missing' => $missing]
                );
                continue;
            }
        }

        $safe[$id] = $m;
    }

    // Register read contracts and deprecated reads for enabled modules
    kernelRegisterModuleReadContracts($safe);

    $cached = $safe;
    return $cached;
}

/**
 * Return kernel migration files that are safe to run against tenant databases.
 * Control-plane schema must stay in the control DB only.
 *
 * @return array<int, string>
 */

require_once __DIR__ . '/module-migrations.php';

function validateModuleCapabilities(array $manifest): array
{
    $caps = $manifest['capabilities'] ?? null;
    if ($caps === null) {
        return ['ok' => true, 'exposes' => [], 'depends' => [], 'policy' => []];
    }
    if (!is_array($caps)) {
        return ['ok' => false, 'error' => 'capabilities must be an object'];
    }

    $exposes = $caps['exposes'] ?? [];
    $depends = $caps['depends'] ?? [];
    $policy = $caps['policy'] ?? [];

    if (!is_array($exposes) || !is_array($depends)) {
        return ['ok' => false, 'error' => 'capabilities.exposes and capabilities.depends must be arrays'];
    }

    foreach ($depends as $d) {
        if (!is_string($d) || !isValidCapabilityId($d)) {
            return ['ok' => false, 'error' => 'Invalid capabilities.depends entry'];
        }
    }

    foreach ($exposes as $e) {
        if (!is_array($e)) {
            return ['ok' => false, 'error' => 'capabilities.exposes entries must be objects'];
        }
        $id = $e['id'] ?? '';
        if (!is_string($id) || !isValidCapabilityId($id)) {
            return ['ok' => false, 'error' => 'Invalid capability expose id'];
        }
        $modes = $e['modes'] ?? ['first'];
        if (!is_array($modes)) {
            return ['ok' => false, 'error' => 'Capability expose modes must be an array'];
        }
        foreach ($modes as $mode) {
            if (!is_string($mode) || !in_array(strtolower($mode), ['first', 'pipeline', 'fanout'], true)) {
                return ['ok' => false, 'error' => 'Invalid capability mode'];
            }
        }
        $priority = $e['priority'] ?? 10;
        if (!is_int($priority) && !is_numeric($priority)) {
            return ['ok' => false, 'error' => 'Capability expose priority must be numeric'];
        }
        if (isset($e['schema']) && !is_array($e['schema'])) {
            return ['ok' => false, 'error' => 'Capability expose schema must be an object'];
        }
        if (isset($e['schema']) && is_array($e['schema'])) {
            $schema = $e['schema'];
            if (isset($schema['input']) && !is_array($schema['input'])) {
                return ['ok' => false, 'error' => 'Capability expose schema.input must be an object'];
            }
            if (isset($schema['output']) && !is_array($schema['output'])) {
                return ['ok' => false, 'error' => 'Capability expose schema.output must be an object'];
            }
        }
    }

    // Optional policy schema
    if ($policy !== [] && !is_array($policy)) {
        return ['ok' => false, 'error' => 'capabilities.policy must be an object'];
    }
    if (is_array($policy) && $policy !== []) {
        $default = $policy['default'] ?? [];
        $perCap = $policy['capabilities'] ?? [];

        if ($default !== [] && !is_array($default)) {
            return ['ok' => false, 'error' => 'capabilities.policy.default must be an object'];
        }
        if ($perCap !== [] && !is_array($perCap)) {
            return ['ok' => false, 'error' => 'capabilities.policy.capabilities must be an object'];
        }

        $validateProviderList = function ($v): bool {
            if ($v === null) return true;
            if (!is_array($v)) return false;
            foreach ($v as $p) {
                if (!is_string($p) || trim($p) === '') return false;
            }
            return true;
        };

        $validateCallerList = function ($v): bool {
            if ($v === null) return true;
            if (!is_array($v)) return false;
            foreach ($v as $c) {
                if (!is_string($c) || trim($c) === '') return false;
            }
            return true;
        };

        if (is_array($default)) {
            if (!$validateProviderList($default['allow_providers'] ?? null)) {
                return ['ok' => false, 'error' => 'capabilities.policy.default.allow_providers must be an array of strings'];
            }
            if (!$validateProviderList($default['deny_providers'] ?? null)) {
                return ['ok' => false, 'error' => 'capabilities.policy.default.deny_providers must be an array of strings'];
            }
            if (!$validateCallerList($default['allow_callers'] ?? null)) {
                return ['ok' => false, 'error' => 'capabilities.policy.default.allow_callers must be an array of strings'];
            }
            if (!$validateCallerList($default['deny_callers'] ?? null)) {
                return ['ok' => false, 'error' => 'capabilities.policy.default.deny_callers must be an array of strings'];
            }
        }

        if (is_array($perCap)) {
            foreach ($perCap as $capId => $rule) {
                if (!is_string($capId) || !isValidCapabilityId($capId)) {
                    return ['ok' => false, 'error' => 'capabilities.policy.capabilities keys must be valid capability ids'];
                }
                if (!is_array($rule)) {
                    return ['ok' => false, 'error' => 'capabilities.policy.capabilities entries must be objects'];
                }
                if (!$validateProviderList($rule['allow_providers'] ?? null)) {
                    return ['ok' => false, 'error' => 'capabilities.policy.capabilities.allow_providers must be an array of strings'];
                }
                if (!$validateProviderList($rule['deny_providers'] ?? null)) {
                    return ['ok' => false, 'error' => 'capabilities.policy.capabilities.deny_providers must be an array of strings'];
                }
                if (!$validateCallerList($rule['allow_callers'] ?? null)) {
                    return ['ok' => false, 'error' => 'capabilities.policy.capabilities.allow_callers must be an array of strings'];
                }
                if (!$validateCallerList($rule['deny_callers'] ?? null)) {
                    return ['ok' => false, 'error' => 'capabilities.policy.capabilities.deny_callers must be an array of strings'];
                }
            }
        }
    }

    return ['ok' => true, 'exposes' => $exposes, 'depends' => array_values($depends), 'policy' => is_array($policy) ? $policy : []];
}

/**
 * Validate the optional `auth_owned` block in a module.json manifest.
 *
 * The block declares that the module owns its own users table and opts
 * the module into the platform-wide trusted-provisioning + admin-recovery
 * pipelines (see kernel/Services/TenantProvisioner.php and
 * kernelHandleApiTenantAdminPasswordPush() in src/http/admin-handlers.php).
 *
 * Shape:
 *   {
 *     "users_table":                "bakeshop_users",          // required
 *     "username_column":            "username",                  // optional, default 'username'
 *     "email_column":               "email",                     // optional, default 'email'
 *     "password_column":            "password_hash",             // optional, default 'password_hash'
 *     "name_column":                "full_name",                 // optional, default 'full_name'
 *     "active_column":              "is_active",                 // optional, default 'is_active'
 *     "deleted_column":             null,                        // optional, default null
 *     "tenant_id_column":           "tenant_id",                 // optional, default null. When set, the tenant provisioner seeds this column with the provisioned tenant's id so auth_owned users are tenant-scoped correctly.
 *     "admin_roles":                ["admin"],                   // required, non-empty
 *     "default_admin_role":         "admin",                     // optional, default first admin_roles entry
 *     "requires_named_admin_on_provision": true,                 // optional, default false
 *     "blocked_password_hashes":    ["..."],                     // optional, sentinel hashes the auth provider must reject
 *     "touch_updated_at":           true                         // optional, default true (adds updated_at = NOW())
 *   }
 */
function validateAuthOwnedSpec(mixed $raw, bool $strictReservedRoles = false): array
{
    if (!is_array($raw)) {
        return ['ok' => false, 'error' => 'module.json field auth_owned must be an object'];
    }

    $identRegex = '/^[A-Za-z_][A-Za-z0-9_]*$/';
    $reservedKernelRoles = ['superadmin'];

    $usersTable = (string)($raw['users_table'] ?? '');
    if ($usersTable === '' || !preg_match($identRegex, $usersTable)) {
        return ['ok' => false, 'error' => 'module.json field auth_owned.users_table must be a valid identifier'];
    }

    foreach (['username_column', 'email_column', 'password_column', 'name_column', 'active_column', 'deleted_column', 'tenant_id_column'] as $colField) {
        if (!array_key_exists($colField, $raw) || $raw[$colField] === null) {
            continue;
        }
        if (!is_string($raw[$colField]) || !preg_match($identRegex, $raw[$colField])) {
            return ['ok' => false, 'error' => "module.json field auth_owned.{$colField} must be null or a valid column identifier"];
        }
    }

    $adminRoles = $raw['admin_roles'] ?? null;
    if (!is_array($adminRoles) || $adminRoles === []) {
        return ['ok' => false, 'error' => 'module.json field auth_owned.admin_roles must be a non-empty array of role strings'];
    }
    foreach ($adminRoles as $role) {
        if (!is_string($role) || trim($role) === '') {
            return ['ok' => false, 'error' => 'module.json field auth_owned.admin_roles must contain non-empty strings'];
        }

        $normalizedRole = trim($role);
        if ($strictReservedRoles && in_array($normalizedRole, $reservedKernelRoles, true)) {
            return ['ok' => false, 'error' => 'module.json field auth_owned.admin_roles must not contain reserved kernel roles'];
        }
    }

    if (array_key_exists('default_admin_role', $raw)) {
        if (!is_string($raw['default_admin_role']) || trim($raw['default_admin_role']) === '') {
            return ['ok' => false, 'error' => 'module.json field auth_owned.default_admin_role must be a non-empty string when provided'];
        }

        $defaultRole = trim($raw['default_admin_role']);
        if ($strictReservedRoles && in_array($defaultRole, $reservedKernelRoles, true)) {
            return ['ok' => false, 'error' => 'module.json field auth_owned.default_admin_role must not use a reserved kernel role'];
        }
    }

    if (array_key_exists('blocked_password_hashes', $raw)) {
        if (!is_array($raw['blocked_password_hashes'])) {
            return ['ok' => false, 'error' => 'module.json field auth_owned.blocked_password_hashes must be an array of strings'];
        }
        foreach ($raw['blocked_password_hashes'] as $hash) {
            if (!is_string($hash) || $hash === '') {
                return ['ok' => false, 'error' => 'module.json field auth_owned.blocked_password_hashes must contain non-empty strings'];
            }
        }
    }

    return ['ok' => true];
}

/**
 * Normalize an auth_owned spec into a deterministic shape with defaults applied.
 * The returned array uses only validated identifiers, so callers can safely
 * interpolate the values into prepared SQL fragments.
 */
function kernelNormalizeAuthOwnedSpec(string $moduleId, array $raw): array
{
    $adminRoles = array_values(array_filter(array_map(
        static fn($r) => is_string($r) ? trim($r) : '',
        $raw['admin_roles'] ?? []
    ), static fn($r) => $r !== ''));

    if ($adminRoles === []) {
        $adminRoles = ['admin'];
    }

    $defaultRole = isset($raw['default_admin_role']) && is_string($raw['default_admin_role']) && trim($raw['default_admin_role']) !== ''
        ? trim($raw['default_admin_role'])
        : $adminRoles[0];

    $blocked = [];
    if (isset($raw['blocked_password_hashes']) && is_array($raw['blocked_password_hashes'])) {
        foreach ($raw['blocked_password_hashes'] as $hash) {
            if (is_string($hash) && $hash !== '') {
                $blocked[] = $hash;
            }
        }
    }

    return [
        'module_id'                          => $moduleId,
        'users_table'                        => (string)$raw['users_table'],
        'username_column'                    => (string)($raw['username_column'] ?? 'username'),
        'email_column'                       => (string)($raw['email_column'] ?? 'email'),
        'password_column'                    => (string)($raw['password_column'] ?? 'password_hash'),
        'name_column'                        => (string)($raw['name_column'] ?? 'full_name'),
        'active_column'                      => isset($raw['active_column']) && $raw['active_column'] !== null ? (string)$raw['active_column'] : 'is_active',
        'deleted_column'                     => isset($raw['deleted_column']) && $raw['deleted_column'] !== null ? (string)$raw['deleted_column'] : null,
        'tenant_id_column'                   => isset($raw['tenant_id_column']) && $raw['tenant_id_column'] !== null ? (string)$raw['tenant_id_column'] : null,
        'admin_roles'                        => $adminRoles,
        'default_admin_role'                 => $defaultRole,
        'requires_named_admin_on_provision'  => !empty($raw['requires_named_admin_on_provision']),
        'blocked_password_hashes'            => $blocked,
        'touch_updated_at'                   => array_key_exists('touch_updated_at', $raw) ? (bool)$raw['touch_updated_at'] : true,
    ];
}

/**
 * Discover all enabled modules that declare an `auth_owned` block and return
 * normalized specs keyed by module id.
 *
 * @return array<string, array<string, mixed>>
 */
function kernelAuthOwnedModules(): array
{
    static $cached = null;
    if (is_array($cached)) {
        return $cached;
    }

    $result = [];
    foreach (getEnabledModules() as $moduleId => $manifest) {
        if (!is_array($manifest)) {
            continue;
        }
        $raw = $manifest['auth_owned'] ?? null;
        if (!is_array($raw)) {
            continue;
        }
        $check = validateAuthOwnedSpec($raw);
        if (empty($check['ok'])) {
            if (function_exists('write_log')) {
                write_log(
                    'auth_owned manifest ignored for module ' . $moduleId . ': ' . (string)($check['error'] ?? 'invalid'),
                    'warning'
                );
            }
            continue;
        }
        $result[(string)$moduleId] = kernelNormalizeAuthOwnedSpec((string)$moduleId, $raw);
    }

    $cached = $result;
    return $result;
}

/**
 * Reset the kernelAuthOwnedModules() per-request cache. Intended for tests
 * that toggle module enablement mid-request.
 */
function kernelAuthOwnedModulesResetCache(): void
{
    static $resetClosure = null;
    // Reset the static $cached var inside kernelAuthOwnedModules() by
    // re-declaring it via reflection-free trick: call a sentinel that
    // re-initializes — but PHP has no native reset for function statics.
    // Tests should boot a fresh process; this no-op is kept for clarity.
    unset($resetClosure);
}

/**
 * Look up the auth_owned spec for a single module id, or null if the module
 * is not enabled or does not declare auth_owned.
 */
function kernelAuthOwnedSpecForModule(string $moduleId): ?array
{
    $all = kernelAuthOwnedModules();
    return $all[$moduleId] ?? null;
}

/**
 * Canonical on-disk auth_owned resolver.
 *
 * Resolves + validates the auth_owned spec from the module's on-disk module.json
 * (moduleManifestPathForId + json_decode + validateAuthOwnedSpec +
 * kernelNormalizeAuthOwnedSpec). This is the SINGLE resolver shared by
 * requiresSeededAdminCredentials, admin seeding, verification, and repair — it
 * never trusts the enriched/mutable discovery array, so CLI provisioning (where
 * request-context tenant resolution differs from HTTP) and HTTP seeding behave
 * identically.
 */
function kernelAuthOwnedSpecFromDisk(string $moduleId): ?array
{
    $moduleId = trim($moduleId);
    if ($moduleId === '') {
        return null;
    }

    $path = moduleManifestPathForId($moduleId);
    if ($path === null || !is_file($path)) {
        return null;
    }

    try {
        $manifest = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        return null;
    }
    if (!is_array($manifest)) {
        return null;
    }

    $raw = $manifest['auth_owned'] ?? null;
    if (!is_array($raw)) {
        return null;
    }

    if (!function_exists('validateAuthOwnedSpec') || !function_exists('kernelNormalizeAuthOwnedSpec')) {
        return null;
    }

    $check = validateAuthOwnedSpec($raw);
    if (empty($check['ok'])) {
        if (function_exists('write_log')) {
            write_log('auth_owned on-disk manifest invalid for module ' . $moduleId . ': ' . (string)($check['error'] ?? 'invalid'), 'warning');
        }
        return null;
    }

    return kernelNormalizeAuthOwnedSpec($moduleId, $raw);
}

/**
 * Validate optional entity_contexts block in a module manifest.
 * Returns:
 *  - ['ok' => true, 'definitions' => array, 'extensions' => array, 'bindings' => array, 'capability_metadata' => array]
 *  - ['ok' => false, 'error' => '...']
 */
function validateModuleEntityContexts(array $manifest): array
{
    $raw = $manifest['entity_contexts'] ?? null;
    if ($raw === null) {
        return ['ok' => true, 'definitions' => [], 'extensions' => [], 'bindings' => [], 'capability_metadata' => []];
    }

    if (!is_array($raw)) {
        return ['ok' => false, 'error' => 'entity_contexts must be an object'];
    }

    $definitions = $raw['definitions'] ?? [];
    $extensions = $raw['extensions'] ?? [];
    $bindings = $raw['bindings'] ?? [];
    $capabilityMetadata = $raw['capability_metadata'] ?? [];

    foreach ([
        'entity_contexts.definitions' => $definitions,
        'entity_contexts.extensions' => $extensions,
        'entity_contexts.bindings' => $bindings,
        'entity_contexts.capability_metadata' => $capabilityMetadata,
    ] as $label => $value) {
        if (!is_array($value)) {
            return ['ok' => false, 'error' => $label . ' must be an array'];
        }
    }

    foreach ($definitions as $index => $definition) {
        if (!is_array($definition)) {
            return ['ok' => false, 'error' => "entity_contexts.definitions[{$index}] must be an object"];
        }

        $contextId = trim((string)($definition['id'] ?? ''));
        if (!isValidEntityContextId($contextId)) {
            return ['ok' => false, 'error' => "entity_contexts.definitions[{$index}].id must be a valid context id"];
        }
        if (isset($definition['label']) && !is_string($definition['label'])) {
            return ['ok' => false, 'error' => "entity_contexts.definitions[{$index}].label must be a string"];
        }
        if (isset($definition['priority']) && !is_numeric($definition['priority'])) {
            return ['ok' => false, 'error' => "entity_contexts.definitions[{$index}].priority must be numeric"];
        }
        if (isset($definition['meta']) && !is_array($definition['meta'])) {
            return ['ok' => false, 'error' => "entity_contexts.definitions[{$index}].meta must be an object"];
        }
        if (isset($definition['capabilities'])) {
            if (!is_array($definition['capabilities'])) {
                return ['ok' => false, 'error' => "entity_contexts.definitions[{$index}].capabilities must be an array"];
            }
            foreach ($definition['capabilities'] as $capabilityIndex => $capability) {
                if (is_string($capability) && isValidEntityCapabilityName($capability)) {
                    continue;
                }
                if (is_array($capability) && isValidEntityCapabilityName((string)($capability['id'] ?? ''))) {
                    continue;
                }

                return ['ok' => false, 'error' => "entity_contexts.definitions[{$index}].capabilities[{$capabilityIndex}] must reference a valid capability id"];
            }
        }
    }

    foreach ($extensions as $index => $extension) {
        if (!is_array($extension)) {
            return ['ok' => false, 'error' => "entity_contexts.extensions[{$index}] must be an object"];
        }

        $contextId = trim((string)($extension['context'] ?? ''));
        if (!isValidEntityContextId($contextId)) {
            return ['ok' => false, 'error' => "entity_contexts.extensions[{$index}].context must be a valid context id"];
        }
        if (isset($extension['label']) && !is_string($extension['label'])) {
            return ['ok' => false, 'error' => "entity_contexts.extensions[{$index}].label must be a string"];
        }
        if (isset($extension['priority']) && !is_numeric($extension['priority'])) {
            return ['ok' => false, 'error' => "entity_contexts.extensions[{$index}].priority must be numeric"];
        }
        if (isset($extension['meta']) && !is_array($extension['meta'])) {
            return ['ok' => false, 'error' => "entity_contexts.extensions[{$index}].meta must be an object"];
        }
        if (isset($extension['capabilities'])) {
            if (!is_array($extension['capabilities'])) {
                return ['ok' => false, 'error' => "entity_contexts.extensions[{$index}].capabilities must be an array"];
            }
            foreach ($extension['capabilities'] as $capabilityIndex => $capability) {
                if (is_string($capability) && isValidEntityCapabilityName($capability)) {
                    continue;
                }
                if (is_array($capability) && isValidEntityCapabilityName((string)($capability['id'] ?? ''))) {
                    continue;
                }

                return ['ok' => false, 'error' => "entity_contexts.extensions[{$index}].capabilities[{$capabilityIndex}] must reference a valid capability id"];
            }
        }
    }

    foreach ($bindings as $index => $binding) {
        if (!is_array($binding)) {
            return ['ok' => false, 'error' => "entity_contexts.bindings[{$index}] must be an object"];
        }

        $entityType = trim((string)($binding['entity_type'] ?? ''));
        if (!preg_match('/^[a-z][a-z0-9_\-]*$/', $entityType)) {
            return ['ok' => false, 'error' => "entity_contexts.bindings[{$index}].entity_type must be a valid entity type id"];
        }

        $base = trim((string)($binding['base'] ?? ''));
        if ($base !== '' && !isValidEntityContextId($base)) {
            return ['ok' => false, 'error' => "entity_contexts.bindings[{$index}].base must be a valid context id"];
        }
        if (isset($binding['priority']) && !is_numeric($binding['priority'])) {
            return ['ok' => false, 'error' => "entity_contexts.bindings[{$index}].priority must be numeric"];
        }
        if (isset($binding['overrides']) && !is_array($binding['overrides'])) {
            return ['ok' => false, 'error' => "entity_contexts.bindings[{$index}].overrides must be an object"];
        }
        if (isset($binding['extensions'])) {
            if (!is_array($binding['extensions'])) {
                return ['ok' => false, 'error' => "entity_contexts.bindings[{$index}].extensions must be an array"];
            }
            foreach ($binding['extensions'] as $extensionIndex => $extension) {
                if (!is_string($extension) || !isValidEntityContextId($extension)) {
                    return ['ok' => false, 'error' => "entity_contexts.bindings[{$index}].extensions[{$extensionIndex}] must be a valid context id"];
                }
            }
        }
    }

    foreach ($capabilityMetadata as $index => $metadata) {
        if (!is_array($metadata)) {
            return ['ok' => false, 'error' => "entity_contexts.capability_metadata[{$index}] must be an object"];
        }

        $capabilityId = trim((string)($metadata['id'] ?? ''));
        if (!isValidEntityCapabilityName($capabilityId)) {
            return ['ok' => false, 'error' => "entity_contexts.capability_metadata[{$index}].id must be a valid capability id"];
        }
        if (isset($metadata['label']) && !is_string($metadata['label'])) {
            return ['ok' => false, 'error' => "entity_contexts.capability_metadata[{$index}].label must be a string"];
        }
        if (isset($metadata['block']) && !is_string($metadata['block'])) {
            return ['ok' => false, 'error' => "entity_contexts.capability_metadata[{$index}].block must be a string"];
        }
        if (isset($metadata['priority']) && !is_numeric($metadata['priority'])) {
            return ['ok' => false, 'error' => "entity_contexts.capability_metadata[{$index}].priority must be numeric"];
        }
        if (isset($metadata['meta']) && !is_array($metadata['meta'])) {
            return ['ok' => false, 'error' => "entity_contexts.capability_metadata[{$index}].meta must be an object"];
        }
        if (!isset($metadata['customizer'])) {
            continue;
        }
        if (!is_array($metadata['customizer'])) {
            return ['ok' => false, 'error' => "entity_contexts.capability_metadata[{$index}].customizer must be an object"];
        }

        $customizer = $metadata['customizer'];
        if (isset($customizer['section']) && !is_array($customizer['section'])) {
            return ['ok' => false, 'error' => "entity_contexts.capability_metadata[{$index}].customizer.section must be an object"];
        }
        if (isset($customizer['fields'])) {
            if (!is_array($customizer['fields'])) {
                return ['ok' => false, 'error' => "entity_contexts.capability_metadata[{$index}].customizer.fields must be an array"];
            }
            foreach ($customizer['fields'] as $fieldIndex => $field) {
                if (!is_array($field)) {
                    return ['ok' => false, 'error' => "entity_contexts.capability_metadata[{$index}].customizer.fields[{$fieldIndex}] must be an object"];
                }
                if (!is_string($field['name'] ?? null) || trim((string)$field['name']) === '') {
                    return ['ok' => false, 'error' => "entity_contexts.capability_metadata[{$index}].customizer.fields[{$fieldIndex}].name must be a non-empty string"];
                }
                if (isset($field['label']) && !is_string($field['label'])) {
                    return ['ok' => false, 'error' => "entity_contexts.capability_metadata[{$index}].customizer.fields[{$fieldIndex}].label must be a string"];
                }
                if (isset($field['type']) && !is_string($field['type'])) {
                    return ['ok' => false, 'error' => "entity_contexts.capability_metadata[{$index}].customizer.fields[{$fieldIndex}].type must be a string"];
                }
                if (isset($field['priority']) && !is_numeric($field['priority'])) {
                    return ['ok' => false, 'error' => "entity_contexts.capability_metadata[{$index}].customizer.fields[{$fieldIndex}].priority must be numeric"];
                }
                if (isset($field['options']) && !is_array($field['options'])) {
                    return ['ok' => false, 'error' => "entity_contexts.capability_metadata[{$index}].customizer.fields[{$fieldIndex}].options must be an array"];
                }
                if (isset($field['visibility']) && !is_array($field['visibility'])) {
                    return ['ok' => false, 'error' => "entity_contexts.capability_metadata[{$index}].customizer.fields[{$fieldIndex}].visibility must be an object"];
                }
            }
        }
    }

    return [
        'ok' => true,
        'definitions' => array_values($definitions),
        'extensions' => array_values($extensions),
        'bindings' => array_values($bindings),
        'capability_metadata' => array_values($capabilityMetadata),
    ];
}

function isValidCapabilityId(string $capId): bool
{
    return moduleManifestCapabilityIdIsValid($capId);
}

function isValidEntityContextId(string $contextId): bool
{
    return (bool)preg_match('/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)*$/', trim($contextId));
}

function isValidEntityCapabilityName(string $capabilityId): bool
{
    return (bool)preg_match('/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)*$/', trim($capabilityId));
}

// ─── Module Context Accessor ──────────────────────────────────────────────

/** @var array<int, \Ikabud\Kernel\Contracts\ModuleContext|null> */
$_moduleContextStack = [];

/** @var array<string, \Ikabud\Kernel\Contracts\ModuleContext|null> */
$_moduleContextCache = [];

/** @var array<string, bool> */
$_loadedModuleHelpers = [];

/** @var array<string, bool> */
$_loadedModuleHandlers = [];

/** @var array<string, array<string, callable>> */
$_moduleRouteCallableRegistry = [];

/**
 * Clear cached module contexts so the next module() call rebuilds them
 * with a fresh PDO handle. Call after app()->reconnectDb() or reconnectDbForTenant().
 */
function invalidateModuleContextCache(?string $moduleId = null): void
{
    global $_moduleContextCache;
    if ($moduleId !== null) {
        unset($_moduleContextCache[trim($moduleId)]);
    } else {
        $_moduleContextCache = [];
    }
}

function moduleContextFor(string $moduleId): ?\Ikabud\Kernel\Contracts\ModuleContext
{
    global $_moduleContextCache;

    $moduleId = trim($moduleId);
    if ($moduleId === '') {
        return null;
    }

    if (array_key_exists($moduleId, $_moduleContextCache)) {
        return $_moduleContextCache[$moduleId];
    }

    $modules = discoverModules();
    if (!isset($modules[$moduleId]) || !is_array($modules[$moduleId])) {
        $_moduleContextCache[$moduleId] = null;
        return null;
    }

    $_moduleContextCache[$moduleId] = buildModuleContext($moduleId, $modules[$moduleId]);
    return $_moduleContextCache[$moduleId];
}

function moduleCurrentId(): ?string
{
    $ctx = module();
    return $ctx ? $ctx->moduleId() : null;
}

function modulePushContext(string|\Ikabud\Kernel\Contracts\ModuleContext $module): ?\Ikabud\Kernel\Contracts\ModuleContext
{
    $ctx = is_string($module) ? moduleContextFor($module) : $module;
    if (!$ctx) {
        return null;
    }

    kernel_request_context_push('_moduleContextStack', module());
    kernel_request_context_set('_activeModuleContext', $ctx);
    app()->setActiveModule($ctx->moduleId());
    \Ikabud\Kernel\Database\KernelPDO::setActiveModule($ctx->moduleId());
    return $ctx;
}

function modulePopContext(): void
{
    $previous = kernel_request_context_pop('_moduleContextStack');
    kernel_request_context_set('_activeModuleContext', $previous);
    if ($previous instanceof \Ikabud\Kernel\Contracts\ModuleContext) {
        app()->setActiveModule($previous->moduleId());
        \Ikabud\Kernel\Database\KernelPDO::setActiveModule($previous->moduleId());
        return;
    }

    app()->clearActiveModule();
    \Ikabud\Kernel\Database\KernelPDO::setActiveModule(null);
}

function moduleWithContext(string|\Ikabud\Kernel\Contracts\ModuleContext $module, callable $callback): mixed
{
    $ctx = modulePushContext($module);
    try {
        return $callback($ctx);
    } finally {
        if ($ctx) {
            modulePopContext();
        }
    }
}

function loadModuleHelpers(array $module): void
{
    global $_loadedModuleHelpers;

    $moduleId = trim((string)($module['id'] ?? ''));
    if ($moduleId === '' || isset($_loadedModuleHelpers[$moduleId])) {
        return;
    }

    // Service modules declare capabilities but run externally — no PHP helpers to load.
    $moduleType = trim((string)($module['type'] ?? 'php-module'));
    if ($moduleType === 'service-module') {
        $_loadedModuleHelpers[$moduleId] = true;
        return;
    }

    $helpersFile = (string)($module['_path'] ?? '') . '/helpers.php';
    if (is_file($helpersFile)) {
        moduleWithContext($moduleId, static function () use ($helpersFile): void {
            require_once $helpersFile;
        });
    }

    // Auto-register auth-owned module user tables in the kernel auth table map.
    // Modules declaring auth_owned.users_table no longer need to call
    // app()->registerAuthTable() manually during bootstrap.
    $authOwned = $module['auth_owned'] ?? null;
    if (is_array($authOwned) && !empty($authOwned['users_table'])) {
        $usersTable = trim((string)$authOwned['users_table']);
        if ($usersTable !== '' && function_exists('app')) {
            try {
                app()->registerAuthTable($moduleId, $usersTable);
            } catch (\Throwable $e) {
                if (function_exists('write_log')) {
                    write_log("Failed to auto-register auth table for module '{$moduleId}': " . $e->getMessage(), 'warning');
                }
            }
        }
    }

    $_loadedModuleHelpers[$moduleId] = true;
}

function resolveModuleRouteCallable(string $moduleId, string $handlerKey, array $manifest): ?callable
{
    global $_loadedModuleHandlers, $_moduleRouteCallableRegistry;

    if (isset($_moduleRouteCallableRegistry[$moduleId][$handlerKey]) && is_callable($_moduleRouteCallableRegistry[$moduleId][$handlerKey])) {
        return $_moduleRouteCallableRegistry[$moduleId][$handlerKey];
    }

    $handlersFile = (string)($manifest['_path'] ?? '') . '/handlers.php';
    if (!is_file($handlersFile)) {
        return null;
    }

    if (!isset($_loadedModuleHandlers[$moduleId])) {
        $fnsBefore = get_defined_functions()['user'] ?? [];
        moduleWithContext($moduleId, static function () use ($handlersFile): void {
            require_once $handlersFile;
        });
        $fnsAfter = get_defined_functions()['user'] ?? [];
        $newFns = array_diff($fnsAfter, $fnsBefore);
        if (!empty($newFns)) {
            static $functionOwners = [];
            foreach ($newFns as $fn) {
                if (isset($functionOwners[$fn])) {
                    write_log(
                        "Function namespace collision: '{$fn}' already owned by module '{$functionOwners[$fn]}', "
                        . "module '{$moduleId}' attempted to redefine it",
                        'error',
                        ['module' => $moduleId, 'function' => $fn, 'owner' => $functionOwners[$fn]]
                    );
                } else {
                    $functionOwners[$fn] = $moduleId;
                }
            }
        }
        $_loadedModuleHandlers[$moduleId] = true;
    }

    $modulePrefix = preg_replace('/[^a-z0-9]+/i', '_', $moduleId);
    $handlersExportFn = $modulePrefix . '_route_handlers';
    if (function_exists($handlersExportFn)) {
        $resolvedHandlers = $handlersExportFn();
        if (is_array($resolvedHandlers)) {
            foreach ($resolvedHandlers as $key => $callable) {
                if (is_string($key) && is_callable($callable)) {
                    $_moduleRouteCallableRegistry[$moduleId][$key] = $callable;
                }
            }
        }
    }

    if (isset($_moduleRouteCallableRegistry[$moduleId][$handlerKey]) && is_callable($_moduleRouteCallableRegistry[$moduleId][$handlerKey])) {
        return $_moduleRouteCallableRegistry[$moduleId][$handlerKey];
    }

    if (!function_exists($handlerKey)) {
        return null;
    }

    $_moduleRouteCallableRegistry[$moduleId][$handlerKey] = static function (array $params = []) use ($handlerKey): void {
        $handlerKey($params);
    };

    return $_moduleRouteCallableRegistry[$moduleId][$handlerKey];
}

// ─── Module Context Accessor ──────────────────────────────────────────────

/** @var \Ikabud\Kernel\Contracts\ModuleContext|null Active module context during handler execution */
$_activeModuleContext = null;

/**
 * Get the current module context.
 * This is the contract-enforced gateway for module code.
 * Returns null if called outside a module handler.
 */
function module(?string $moduleId = null): ?\Ikabud\Kernel\Contracts\ModuleContext
{
    $activeContext = kernel_request_context_get('_activeModuleContext');

    if ($moduleId === null || trim($moduleId) === '') {
        return $activeContext instanceof \Ikabud\Kernel\Contracts\ModuleContext ? $activeContext : null;
    }

    $moduleId = trim($moduleId);
    if ($activeContext instanceof \Ikabud\Kernel\Contracts\ModuleContext && $activeContext->moduleId() === $moduleId) {
        return $activeContext;
    }

    return moduleContextFor($moduleId);
}

/**
 * Build a ModuleContext for a module, using its manifest declarations.
 *
 * Table ownership rules:
 *   owns_tables   → full CRUD (module's own tables)
 *   reads_tables  → SELECT only (kernel/shared tables the module needs to read)
 *
 * Backward compatibility:
 *   If owns_tables is not declared, falls back to requires_tables (legacy field)
 *   with a deprecation log. New modules MUST use owns_tables + reads_tables.
 */
function buildModuleContext(string $moduleId, array $manifest): \Ikabud\Kernel\Contracts\ModuleContext
{
    // Determine table ownership
    $ownsTables = $manifest['owns_tables'] ?? null;
    $coOwnsTables = is_array($manifest['co_owns_tables'] ?? null) ? $manifest['co_owns_tables'] : [];
    $readsTables = $manifest['reads_tables'] ?? [];

    if ($ownsTables === null) {
        // Legacy fallback: treat requires_tables as owns_tables (backward compat)
        $ownsTables = $manifest['requires_tables'] ?? [];
        if (!empty($ownsTables)) {
            // Only log once per request (static flag)
            static $legacyWarned = [];
            if (!isset($legacyWarned[$moduleId])) {
                write_log(
                    "Module '{$moduleId}' uses legacy 'requires_tables' — migrate to 'owns_tables' + 'reads_tables' in module.json",
                    'warning',
                    ['module' => $moduleId]
                );
                $legacyWarned[$moduleId] = true;
            }
        }
    }

    $scopedDb = new \Ikabud\Kernel\Contracts\ModuleDB(
        app()->db(),
        $moduleId,
        $ownsTables,
        $readsTables,
        $coOwnsTables
    );

    $manifest['_settings'] = getModuleSettings($moduleId);

    return new \Ikabud\Kernel\Contracts\ModuleContext(
        app(),
        $moduleId,
        $scopedDb,
        $manifest
    );
}

// ─── Kernel Companion + Kernel Admin Access Predicates ────────────────────
// These two predicates are the SINGLE source of truth for kernel-admin access
// to module surfaces. They are used by the page handler, the nav builder, and
// the route gate in parity — nav-only parity without the route gate would be a
// vulnerability, so every call site must go through kernelAdminAccessGranted().

/**
 * Canonical kernel-companion predicate (fail-closed).
 *
 * A module is a kernel companion ONLY when its validated on-disk module.json
 * declares `kernel_companion: true`. The enriched discovery array
 * (`$module['kernel_companion']`) and stored settings are never trusted here:
 * the on-disk manifest is the single source of truth so mutable runtime state
 * cannot grant or revoke companion status.
 */
function isDeclaredKernelCompanion(string $moduleId): bool
{
    $moduleId = trim($moduleId);
    if ($moduleId === '') {
        return false;
    }

    $path = moduleManifestPathForId($moduleId);
    if ($path === null || !is_file($path)) {
        return false;
    }

    // Validate the on-disk manifest (skip filesystem checks; we only need the
    // structural + field validation here). Invalid manifests fail closed.
    $check = function_exists('validateModuleManifestFileV1')
        ? validateModuleManifestFileV1($path, ['check_filesystem' => false])
        : ['ok' => false, 'manifest' => null];
    if (empty($check['ok'])) {
        return false;
    }

    $manifest = is_array($check['manifest'] ?? null) ? $check['manifest'] : null;
    if ($manifest === null) {
        return false;
    }

    return !empty($manifest['kernel_companion']);
}

/**
 * Canonical kernel-admin access predicate (fail-closed).
 *
 * A kernel admin may reach a module's routes/nav ONLY when every condition
 * below holds. Any single failure denies access; non-companions are ALWAYS
 * denied regardless of legacy stored allow_kernel_admin settings.
 *
 * Required conditions:
 *   1. $user is a non-empty array
 *   2. source === 'kernel'
 *   3. role === 'admin'
 *   4. tenant binding resolves when multi-tenancy is enabled
 *   5. the module is enabled for the resolved tenant
 *   6. the validated on-disk manifest declares kernel_companion
 *   7. the companion uses kernel users auth OR stored allow_kernel_admin === true
 *
 * @param array<string, mixed> $user
 */
function kernelAdminAccessGranted(array $user, string $moduleId): bool
{
    $moduleId = trim($moduleId);
    if ($moduleId === '' || $user === []) {
        return false;
    }

    // 2. Kernel-origin identity is required — module-issued admin identities
    // are denied even if an outer gate is missed.
    if ((string)($user['source'] ?? '') !== 'kernel') {
        return false;
    }

    // 3. Only the admin role is granted kernel-admin module access.
    if ((string)($user['role'] ?? '') !== 'admin') {
        return false;
    }

    // 4. When multi-tenancy is enabled the kernel user must have a concrete
    // tenant binding; an unresolvable tenant fails closed.
    $tenantResolver = app()->tenant();
    if ($tenantResolver->isEnabled()) {
        $tenantId = $tenantResolver->resolve($user);
        if ($tenantId === null || $tenantId <= 0) {
            return false;
        }
    }

    // 5. The module must be enabled for the resolved tenant.
    $enabledModules = getEnabledModules();
    if (!isset($enabledModules[$moduleId]) || empty($enabledModules[$moduleId]['_enabled'])) {
        return false;
    }

    // 6. Non-companions are ALWAYS denied.
    if (!isDeclaredKernelCompanion($moduleId)) {
        return false;
    }

    // 7. Kernel-users companions are always allowed (their admins ARE kernel
    // admins); other companions require the stored allow_kernel_admin opt-in.
    if (function_exists('tenantEntryModuleUsesKernelUsers') && tenantEntryModuleUsesKernelUsers($moduleId)) {
        return true;
    }

    $settings = getModuleSettings($moduleId);
    return (bool)($settings['allow_kernel_admin'] ?? false);
}

// ─── Handler Execution ────────────────────────────────────────────────────

/**
 * @param array<string, string> $params
 */
function executeModuleHandler(string $handler, array $params = []): void
{
    if (!str_contains($handler, ':')) {
        http_response_code(500);
        echo 'Invalid module handler format';
        return;
    }

    [$moduleId, $handlerKey] = explode(':', $handler, 2);
    $modules = getEnabledModules();

    if (!isset($modules[$moduleId])) {
        http_response_code(404);
        echo app()->render('pages/404.disyl', ['page_title' => 'Module Not Available']);
        return;
    }

    $requestUri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $isModuleLoginRoute = $requestUri === '/' . $moduleId . '/login'
        || $requestUri === '/' . $moduleId . '/auth/login'
        || $requestUri === '/' . $moduleId . '/logout';

    // ── Kernel admin opt-in gate ───────────────────────────────────
    // Policy: a kernel admin can only reach module routes when the canonical
    // access predicate grants it (declared companion + kernel-users auth OR an
    // explicit allow_kernel_admin opt-in). Non-companions are always denied.
    // Module-login routes are exempt so companion/auth surfaces stay reachable.
    $user = app()->user();
    $moduleCookieName = (string)($modules[$moduleId]['auth_cookie'] ?? '');
    if ($moduleCookieName !== '') {
        $moduleCookieToken = kernelCookie($moduleCookieName);
        if (is_string($moduleCookieToken) && $moduleCookieToken !== '') {
            try {
                $moduleCookieUser = app()->jwt()->verify($moduleCookieToken);
                if (is_array($moduleCookieUser) && (($moduleCookieUser['source'] ?? '') === $moduleId)) {
                    $user = $moduleCookieUser;
                }
            } catch (Throwable $ignored) {
            }
        }
    }
    $role = $user ? (string)($user['role'] ?? '') : '';
    $source = $user ? (string)($user['source'] ?? 'kernel') : '';
    if ($role === 'admin' && $source === 'kernel' && !$isModuleLoginRoute && is_array($user)) {
        if (!kernelAdminAccessGranted($user, $moduleId)) {
            $isApiRoute = \Ikabud\Kernel\Http\ContentNegotiator::isApiRoute();

            if (!headers_sent()) {
                http_response_code(403);
            }
            if ($isApiRoute) {
                header('Content-Type: application/json');
                echo json_encode(['ok' => false, 'error' => 'Module not opted-in for kernel admin']);
            } else {
                // Match kernel behavior: page requests redirect home.
                app()->redirect('/');
            }
            return;
        }
    }

    $routeCallable = resolveModuleRouteCallable($moduleId, $handlerKey, $modules[$moduleId]);
    if (!is_callable($routeCallable)) {
        http_response_code(500);
        echo 'Module handler not found';
        return;
    }

    // ── Kernel-enforced CSRF on state-mutating module routes ──────────
    // API routes (Bearer-authenticated) are exempt; browser form posts must pass.
    $requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $isModuleLogin = (bool)preg_match('#^/(?:admin/)?[a-zA-Z0-9\-]+/auth/login$#', $requestUri);
    $isApiRoute = \Ikabud\Kernel\Http\ContentNegotiator::isApiRoute();

    if ($requestMethod === 'POST' && $isModuleLogin) {
        \Ikabud\Kernel\Database\KernelPDO::kernelEscalationEnter();
        try {
            $loginRateLimit = kernelConsumeLoginRateLimit($moduleId);
        } finally {
            \Ikabud\Kernel\Database\KernelPDO::kernelEscalationLeave();
        }
        if (!empty($loginRateLimit['limited'])) {
            kernelEmitLoginRateLimitJson($loginRateLimit);
            modulePopContext();
            kernel_request_context_delete('_capability_call_context');
            return;
        }
    }

    // ── Build scoped ModuleContext ───────────────────────────────────
    $ctx = modulePushContext($moduleId);
    if (!$ctx) {
        http_response_code(500);
        echo 'Module context unavailable';
        return;
    }

    kernel_request_context_set('_capability_call_context', [
        'module' => $moduleId,
        'user' => $user,
        'request_id' => request_id(),
    ]);

    if (in_array($requestMethod, ['POST', 'PUT', 'DELETE'], true) && !$isApiRoute && !$isModuleLogin) {
        if (function_exists('write_log')) {
            write_log('executeModuleHandler: CSRF enforcement triggered', 'warning', [
                'module' => $moduleId,
                'method' => $requestMethod,
                'uri' => $requestUri,
                'is_api' => $isApiRoute,
                'is_login' => $isModuleLogin,
            ]);
        }
        if ($moduleCookieName !== '' && kernelCookie($moduleCookieName) !== '' && function_exists('csrfEnforceFromJwt')) {
            csrfEnforceFromJwt($moduleCookieName);
        } else {
            app()->csrfEnforce();
        }
    }

    // ── Default anti-spam gate for public module web APIs ─────────────
    // When the anti-spam module is enabled, future modules automatically
    // inherit rate limiting / keyword checks for unauthenticated web API
    // traffic unless tenant settings disable it.
    if (
        $isApiRoute
        && function_exists('moduleIsActive')
        && moduleIsActive('anti-spam')
        && function_exists('antispamShouldProtectModuleApiRequest')
        && function_exists('antispamBuildRequestBodyText')
        && app()->capabilities()->has('antispam.check@1')
        && antispamShouldProtectModuleApiRequest($moduleId, is_array($user) ? $user : null, $requestUri, $requestMethod)
    ) {
        try {
            $antiSpamResult = app()->cap()->call('antispam.check@1', [
                'body' => antispamBuildRequestBodyText(app()->input()),
                'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            ], ['mode' => 'first']);

            if (is_array($antiSpamResult) && empty($antiSpamResult['pass'])) {
                $check = (string)($antiSpamResult['check'] ?? 'blocked');
                $detail = (string)($antiSpamResult['detail'] ?? 'Request blocked');
                $status = match ($check) {
                    'rate_limit' => 429,
                    'ip_block' => 403,
                    default => 422,
                };

                if (!headers_sent()) {
                    http_response_code($status);
                    header('Content-Type: application/json');
                }
                echo json_encode([
                    'ok' => false,
                    'error' => 'Request blocked by anti-spam',
                    'check' => $check,
                    'detail' => $detail,
                ]);
                modulePopContext();
                kernel_request_context_delete('_capability_call_context');
                return;
            }
        } catch (\Ikabud\Kernel\Capabilities\CapabilityNotFoundException $e) {
            // anti-spam is not active / has no permitted provider for this
            // tenant, so the default gate simply does not apply. This is the
            // expected degraded state — skip silently instead of warning on
            // every protected request.
        } catch (\Throwable $e) {
            write_log('Default anti-spam gate failed: ' . $e->getMessage(), 'warning', [
                'module' => $moduleId,
                'uri' => $requestUri,
                'method' => $requestMethod,
            ]);
        }
    }

    // ── Page-level cache: serve from cache if available ─────────────
    $pageCacheActive = function_exists('pageCacheShouldCache')
        && pageCacheShouldCache($requestUri, $moduleId);

    if ($pageCacheActive && function_exists('pageCacheServe') && pageCacheServe($requestUri)) {
        // pageCacheServe() already sends X-Page-Cache header + body
        modulePopContext();
        kernel_request_context_delete('_capability_call_context');
        return;
    }

    // ── Stampede protection: prevent concurrent rebuilds of the same page ──
    $pageCacheLock = null;
    if ($pageCacheActive && function_exists('pageCacheLockAcquire')) {
        $pageCacheLock = pageCacheLockAcquire($requestUri);
        if ($pageCacheLock === false) {
            // Another process is building this page — wait for cache
            if (function_exists('pageCacheLockWaitForCache')
                && pageCacheLockWaitForCache($requestUri)
                && pageCacheServe($requestUri)) {
                modulePopContext();
                kernel_request_context_delete('_capability_call_context');
                return;
            }
            $pageCacheLock = null; // Timeout — build without lock
        }
    }

    // ── Output-buffered, exception-safe handler execution ────────────
    // Prevents stray echo/print from corrupting responses and ensures
    // uncaught exceptions produce a clean error page, not a white screen.
    ob_start();
    try {
        // Set active module context for KernelPDO (replaces debug_backtrace)
        app()->setActiveModule($moduleId);
        \Ikabud\Kernel\Database\KernelPDO::setActiveModule($moduleId);

        $routeCallable($params);

        // ── Page-level cache: capture and store on cache-eligible requests ──
        if ($pageCacheActive && function_exists('pageCacheSet')) {
            $html = ob_get_clean();
            $responseCode = http_response_code();
            pageCacheSet($requestUri, $html, $moduleId, (int)$responseCode);
            if ($pageCacheLock) { pageCacheLockRelease($pageCacheLock); $pageCacheLock = null; }
            if (!headers_sent()) {
                header('X-Page-Cache: miss');
            }
            echo $html;
            // Release session lock after GET render so concurrent requests can proceed.
            if (function_exists('releaseSessionAfterRender')) { releaseSessionAfterRender(); }
        } else {
            ob_end_flush(); // success — send captured output
            // Release session lock after GET render so concurrent requests can proceed.
            if (function_exists('releaseSessionAfterRender')) { releaseSessionAfterRender(); }
        }
    } catch (\Throwable $e) {
        ob_end_clean(); // discard any partial output from the bad handler
        if ($pageCacheLock) { pageCacheLockRelease($pageCacheLock); $pageCacheLock = null; }

        write_log("Module handler '{$handler}' threw: " . $e->getMessage(), 'error', [
            'module'  => $moduleId,
            'handler' => $handlerKey,
            'file'    => $e->getFile(),
            'line'    => $e->getLine(),
            'trace'   => $e->getTraceAsString(),
        ]);

        if (!headers_sent()) {
            http_response_code(500);
        }

        // API routes get JSON error; page routes get rendered error page
        if (str_starts_with($requestUri, '/api/')) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'An internal module error occurred.']);
        } else {
            try {
                echo app()->render('pages/500.disyl', ['page_title' => 'Error']);
            } catch (\Throwable $_) {
                echo '<!DOCTYPE html><html><body><h1>Application Error</h1><p>An unexpected error occurred.</p></body></html>';
            }
        }
    } finally {
        app()->clearActiveModule();
        \Ikabud\Kernel\Database\KernelPDO::setActiveModule(null);
        modulePopContext();
        kernel_request_context_delete('_capability_call_context');
    }
}

// ─── Dynamic Navigation ───────────────────────────────────────────────────

/**
 * Build nav items from all enabled modules for the current user's role.
 * Returns flat array: [ ['label'=>..., 'url'=>..., 'icon'=>..., 'module'=>...], ... ]
 */
function getModuleNavItems(?string $role = null, ?array $user = null): array
{
    if ($user === null) {
        $resolvedUser = app()->user();
        $user = is_array($resolvedUser) ? $resolvedUser : null;
    }

    if ($role === null) {
        $role = $user ? (string)($user['role'] ?? '') : '';
    }
    if ($role === '') {
        return [];
    }

    $source = $user ? (string)($user['source'] ?? '') : '';
    $isKernelAdmin = $source === 'kernel' && $role === 'admin';
    $isKernelSuperadmin = $source === 'kernel' && $role === 'superadmin';

    // Kernel superadmin: settings-only role — no module navigation.
    // Return dedicated kernel nav and skip all module items.
    if ($isKernelSuperadmin) {
        return [
            ['label' => 'Feature Settings', 'url' => '/superadmin/settings', 'icon' => 'settings', 'module' => '_kernel', 'target' => null],
            ['label' => 'Performance',       'url' => '/superadmin/perf',     'icon' => 'chart',    'module' => '_kernel', 'target' => '_self'],
            ['label' => 'Cache',             'url' => '/superadmin/cache',    'icon' => 'database', 'module' => '_kernel', 'target' => '_self'],
            ['label' => 'Integrations',      'url' => '/kernel/integrations', 'icon' => 'git-merge', 'module' => '_kernel', 'target' => '_self'],
            ['label' => 'Workbench',         'url' => '/superadmin/workbench','icon' => 'terminal',  'module' => '_kernel', 'target' => null],
            ['label' => 'Profile',           'url' => '/admin/profile',       'icon' => 'user',     'module' => '_kernel', 'target' => null],
        ];
    }

    $navItems = [];
    foreach (getEnabledModules() as $module) {
        $moduleId = (string)($module['id'] ?? '');
        if ($moduleId === '') {
            continue;
        }

        // Kernel admin should not see module links unless the canonical access
        // predicate grants them. This mirrors the route gate exactly — the
        // bypass applies only to modules DECLARED as kernel companions
        // (kernel_companion: true, e.g. gui-settings) that authenticate against
        // the kernel users table (always allowed) or have stored the
        // allow_kernel_admin opt-in. Standalone, entity, and suite
        // extension/adapter modules are always excluded for the kernel admin.
        if ($isKernelAdmin) {
            if (!is_array($user) || !kernelAdminAccessGranted($user, $moduleId)) {
                continue;
            }
        }

        foreach ($module['nav'] ?? [] as $item) {
            $roles = $item['roles'] ?? [];
            if (in_array($role, $roles, true) || in_array('*', $roles, true)) {
                $rawUrl = $item['url'] ?? '#';
                // Kernel admin: keep absolute/admin/api/external URLs unchanged.
                // Only prefix legacy module-local paths (e.g. "/settings" -> "/module-id/settings").
                $isExternal = (bool)preg_match('#^(https?:)?//#', (string)$rawUrl);
                $isAdminPath = strpos((string)$rawUrl, '/admin/') === 0;
                $isApiPath = strpos((string)$rawUrl, '/api/') === 0;
                $isModulePath = strpos((string)$rawUrl, '/' . $moduleId) === 0;

                if ($isKernelAdmin && $rawUrl !== '#' && !$isExternal && !$isAdminPath && !$isApiPath && !$isModulePath) {
                    $rawUrl = '/' . $moduleId . (strpos((string)$rawUrl, '/') === 0 ? $rawUrl : '/' . $rawUrl);
                }
                $navItems[] = [
                    'label'  => $item['label'] ?? '',
                    'url'    => $rawUrl,
                    'icon'   => $item['icon'] ?? 'box',
                    'module' => $moduleId,
                    'target' => $item['target'] ?? null,
                ];
            }
        }
    }

    // Group kernel-accessible companion module links under a labeled section.
    if ($isKernelAdmin && $navItems !== []) {
        array_unshift($navItems, ['label' => '__section__', 'url' => '#', 'icon' => 'separator', 'module' => '_kernel', 'section' => 'Companion Modules']);
    }

    // Kernel-level nav: Modules page (always available to admin, even if no modules enabled)
    if ($isKernelAdmin) {
        $navItems[] = ['label' => '---', 'url' => '#', 'icon' => 'separator', 'module' => '_kernel'];
        $navItems[] = ['label' => 'Platform', 'url' => '/admin/platform', 'icon' => 'server', 'module' => '_kernel'];
        $navItems[] = ['label' => 'Profile', 'url' => '/admin/profile', 'icon' => 'user', 'module' => '_kernel'];
        $navItems[] = ['label' => 'Users', 'url' => '/admin/users', 'icon' => 'users', 'module' => '_kernel'];
        $navItems[] = ['label' => 'Triggers', 'url' => '/admin/kernel/triggers', 'icon' => 'bolt', 'module' => '_kernel'];
        // AI link: only show when the ai module is enabled and allows kernel admin access.
        $allEnabledMods = getEnabledModules();
        if (isset($allEnabledMods['ai'])) {
            $aiSettings = $allEnabledMods['ai']['_settings'] ?? [];
            if (!empty($aiSettings['allow_kernel_admin'])) {
                $navItems[] = ['label' => 'AI', 'url' => '/admin/ai', 'icon' => 'sparkles', 'module' => 'ai'];
            }
        }
        $navItems[] = ['label' => 'Tenants', 'url' => '/admin/tenants', 'icon' => 'building', 'module' => '_kernel'];
        $navItems[] = ['label' => 'Modules', 'url' => '/admin/modules', 'icon' => 'puzzle', 'module' => '_kernel'];
    }

    return $navItems;
}

/**
 * Get the first available URL for a role from enabled modules.
 * Used for smart home redirect when no hardcoded landing page exists.
 */
function getModuleHomeUrl(string $role, ?array $user = null): ?string
{
    $source = (string)($user['source'] ?? 'kernel');
    if ($source === 'kernel' && in_array($role, ['admin', 'superadmin'], true)) {
        return null;
    }

    $items = getModuleNavItems($role, $user);
    foreach ($items as $item) {
        $url = $item['url'] ?? '';
        if ($url !== '' && $url !== '#' && ($item['label'] ?? '') !== '---') {
            return $url;
        }
    }
    return null;
}

// ─── Kernel Hook Registration ─────────────────────────────────────────────
// The module-manager registers itself with the kernel hook system so the kernel
// never calls module functions directly. This is the bridge between kernel OS
// and userland modules.

/**
 * Register all module-manager hooks with the kernel.
 * Called once after the module-manager is loaded.
 */
function registerModuleManagerHooks(): void
{
    $hooks = app()->hooks();

    // kernel.nav_items: inject module navigation items for the current user
    $hooks->on('kernel.nav_items', function (array $items, ?array $user) {
        if (!$user) return $items;
        $role = (string)($user['role'] ?? '');
        return array_merge($items, getModuleNavItems($role));
    });

    // cms.admin.nav_items: fold manifest-declared CMS sidebar contributions
    // into the existing CMS admin nav injection seam (priority 5 = before
    // module-registered hooks at default priority 10).
    $hooks->on('cms.admin.nav_items', kernelContributionBridgeCmsNavItems(), 5);

    // kernel.home_url: resolve the home URL for a role from modules
    $hooks->on('kernel.home_url', function (?string $url, string $role, ?array $user = null) {
        return $url ?? getModuleHomeUrl($role, $user);
    });

    // kernel.auth_cookie_names: allow modules to register additional auth cookie names
    // so kernel-level app()->user() can recognize module-authenticated sessions.
    $hooks->on('kernel.auth_cookie_names', function (array $names, string $defaultCookie) {
        foreach (declaredModuleAuthCookieNames() as $cookie) {
            if ($cookie !== $defaultCookie && !in_array($cookie, $names, true)) {
                $names[] = $cookie;
            }
        }
        return $names;
    });
}

// Auto-register when this file is loaded
registerModuleManagerHooks();

// ─── Module Installer ─────────────────────────────────────────────────────

/**
 * Validate a module.json manifest.
 * Returns ['ok' => true, 'manifest' => [...]] or ['ok' => false, 'error' => '...']
 */
function validateModuleManifest(string $path, array $context = []): array
{
    $schemaValidation = validateModuleManifestFileV1($path, $context);
    if (empty($schemaValidation['ok'])) {
        $diagnostic = $schemaValidation['diagnostics'][0] ?? [];
        return [
            'ok' => false,
            'error' => (string)($diagnostic['message'] ?? 'module.json failed schema-v1 validation')
                . ' Correction: ' . (string)($diagnostic['correction'] ?? 'Run the strict manifest guard.'),
            'error_code' => (string)($diagnostic['code'] ?? 'manifest_invalid'),
            'severity' => (string)($diagnostic['severity'] ?? 'fatal'),
            'rule' => (string)($diagnostic['rule'] ?? 'manifest.v1'),
            'diagnostics' => $schemaValidation['diagnostics'] ?? [],
        ];
    }

    $manifest = $schemaValidation['manifest'];

    if (array_key_exists('auth_cookie', $manifest)) {
        if (!is_string($manifest['auth_cookie']) || trim($manifest['auth_cookie']) === '') {
            return ['ok' => false, 'error' => 'module.json field auth_cookie must be a non-empty string when provided', 'error_code' => 'manifest_invalid_auth_cookie'];
        }
        if (!preg_match('/^[A-Za-z0-9_\-]+$/', $manifest['auth_cookie'])) {
            return ['ok' => false, 'error' => 'module.json field auth_cookie contains invalid characters', 'error_code' => 'manifest_invalid_auth_cookie'];
        }
    }

    if (array_key_exists('auth_owned', $manifest)) {
        $authOwnedValidation = validateAuthOwnedSpec($manifest['auth_owned'], true);
        if (empty($authOwnedValidation['ok'])) {
            return [
                'ok' => false,
                'error' => (string)($authOwnedValidation['error'] ?? 'module.json field auth_owned is invalid'),
                'error_code' => 'manifest_invalid_auth_owned',
            ];
        }
    }

    if (array_key_exists('nav', $manifest)) {
        if (!is_array($manifest['nav'])) {
            return ['ok' => false, 'error' => 'module.json field nav must be an array', 'error_code' => 'manifest_invalid_nav'];
        }
        foreach ($manifest['nav'] as $idx => $item) {
            if (!is_array($item)) {
                return ['ok' => false, 'error' => "module.json nav[{$idx}] must be an object", 'error_code' => 'manifest_invalid_nav'];
            }
            if (array_key_exists('label', $item) && !is_string($item['label'])) {
                return ['ok' => false, 'error' => "module.json nav[{$idx}].label must be a string", 'error_code' => 'manifest_invalid_nav'];
            }
            if (array_key_exists('key', $item) && (!is_string($item['key']) || trim($item['key']) === '')) {
                return ['ok' => false, 'error' => "module.json nav[{$idx}].key must be a non-empty string", 'error_code' => 'manifest_invalid_nav'];
            }
            if (array_key_exists('url', $item) && !is_string($item['url'])) {
                return ['ok' => false, 'error' => "module.json nav[{$idx}].url must be a string", 'error_code' => 'manifest_invalid_nav'];
            }
            if (array_key_exists('description', $item) && !is_string($item['description'])) {
                return ['ok' => false, 'error' => "module.json nav[{$idx}].description must be a string", 'error_code' => 'manifest_invalid_nav'];
            }
            if (array_key_exists('roles', $item)) {
                if (!is_array($item['roles'])) {
                    return ['ok' => false, 'error' => "module.json nav[{$idx}].roles must be an array of strings", 'error_code' => 'manifest_invalid_nav'];
                }
                foreach ($item['roles'] as $role) {
                    if (!is_string($role) || trim($role) === '') {
                        return ['ok' => false, 'error' => "module.json nav[{$idx}].roles must contain non-empty strings", 'error_code' => 'manifest_invalid_nav'];
                    }
                }
            }

            $navUrl = trim((string)($item['url'] ?? ''));
            if (str_starts_with($navUrl, '/admin/ehr')) {
                if (!array_key_exists('key', $item) || trim((string)$item['key']) === '') {
                    return ['ok' => false, 'error' => "module.json nav[{$idx}].key is required for /admin/ehr sidebar items", 'error_code' => 'manifest_invalid_nav'];
                }
                if (!array_key_exists('description', $item) || trim((string)$item['description']) === '') {
                    return ['ok' => false, 'error' => "module.json nav[{$idx}].description is required for /admin/ehr sidebar items", 'error_code' => 'manifest_invalid_nav'];
                }
                if (!array_key_exists('roles', $item) || !is_array($item['roles']) || $item['roles'] === []) {
                    return ['ok' => false, 'error' => "module.json nav[{$idx}].roles is required for /admin/ehr sidebar items", 'error_code' => 'manifest_invalid_nav'];
                }
            }
        }
    }

    if (array_key_exists('navigation_dependencies', $manifest)) {
        if (!is_array($manifest['navigation_dependencies'])) {
            return ['ok' => false, 'error' => 'module.json field navigation_dependencies must be an array of module ids', 'error_code' => 'manifest_invalid_navigation_dependencies'];
        }
        $seenNavigationDependencies = [];
        foreach ($manifest['navigation_dependencies'] as $dependency) {
            if (!is_string($dependency) || !preg_match('/^[a-z0-9]([a-z0-9\-]*[a-z0-9])?$/', $dependency)) {
                return ['ok' => false, 'error' => 'module.json field navigation_dependencies must contain valid module ids', 'error_code' => 'manifest_invalid_navigation_dependencies'];
            }
            if ($dependency === $manifest['id']) {
                return ['ok' => false, 'error' => 'module.json field navigation_dependencies must not include the module itself', 'error_code' => 'manifest_invalid_navigation_dependencies'];
            }
            if (isset($seenNavigationDependencies[$dependency])) {
                return ['ok' => false, 'error' => "module.json field navigation_dependencies contains duplicate module id: {$dependency}", 'error_code' => 'manifest_invalid_navigation_dependencies'];
            }
            $seenNavigationDependencies[$dependency] = true;
        }
    }

    $entityContextValidation = validateModuleEntityContexts($manifest);
    if (empty($entityContextValidation['ok'])) {
        return [
            'ok' => false,
            'error' => (string)($entityContextValidation['error'] ?? 'module.json field entity_contexts is invalid'),
            'error_code' => 'manifest_invalid_entity_contexts',
        ];
    }

    return [
        'ok' => true,
        'manifest' => $manifest,
        'schema_version' => MODULE_MANIFEST_SCHEMA_VERSION,
        'diagnostics' => $schemaValidation['diagnostics'] ?? [],
    ];
}

/**
 * Collect internal navigation URLs declared by a module manifest and by
 * literal sidebar links in module-owned PHP/DiSyL files.
 *
 * @param array<string, mixed> $manifest
 * @return array<int, string>
 */
function moduleNavigationUrls(array $manifest): array
{
    $urls = [];
    $visit = static function (mixed $entry) use (&$visit, &$urls): void {
        if (!is_array($entry)) {
            return;
        }
        $url = trim((string)($entry['url'] ?? ''));
        if (str_starts_with($url, '/')) {
            $urls[] = $url;
        }
        foreach ((array)($entry['children'] ?? []) as $child) {
            $visit($child);
        }
    };
    foreach ((array)($manifest['nav'] ?? $manifest['sidebar'] ?? []) as $entry) {
        $visit($entry);
    }

    $moduleId = trim((string)($manifest['id'] ?? ''));
    $manifestPath = $moduleId !== '' ? moduleManifestPathForId($moduleId) : null;
    $modulePath = trim((string)($manifest['_path'] ?? ''));
    if ($modulePath === '' && is_string($manifestPath)) {
        $modulePath = dirname($manifestPath);
    }
    if ($modulePath !== '' && is_dir($modulePath)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($modulePath, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file->isFile() || !in_array(strtolower($file->getExtension()), ['php', 'disyl'], true)) {
                continue;
            }
            $source = @file_get_contents($file->getPathname());
            if (!is_string($source)) {
                continue;
            }
            preg_match_all('/[\'\"]url[\'\"]\s*=>\s*([\'\"])(\/admin\/[^\'\"]+)\1\s*[,\]]/', $source, $phpMatches);
            preg_match_all('/<a\b[^>]*\bhref\s*=\s*([\'\"])(\/[^\/\'\"][^\'\"]*)\1/i', $source, $hrefMatches);
            foreach (array_merge($phpMatches[2] ?? [], $hrefMatches[2] ?? []) as $url) {
                $urls[] = $url;
            }
        }
    }

    $urls = array_values(array_unique(array_map(static function (string $url): string {
        $path = parse_url($url, PHP_URL_PATH);
        return rtrim(is_string($path) ? $path : $url, '/') ?: '/';
    }, $urls)));
    sort($urls);
    return $urls;
}

function moduleRoutePatternMatchesPath(string $route, string $path): bool
{
    $route = rtrim((string)(parse_url($route, PHP_URL_PATH) ?: $route), '/') ?: '/';
    $path = rtrim((string)(parse_url($path, PHP_URL_PATH) ?: $path), '/') ?: '/';
    $path = preg_replace('/\{[^}]+\}|:[A-Za-z_][A-Za-z0-9_]*/', '1', $path) ?? $path;
    $quoted = preg_quote($route, '#');
    $pattern = preg_replace('/\\\\\{[^}]+\\\\\}|\\:[A-Za-z_][A-Za-z0-9_]*/', '[^/]+', $quoted) ?? $quoted;
    return preg_match('#^' . $pattern . '$#', $path) === 1;
}

/**
 * @param array<string, mixed> $manifest
 * @param array<string, array<string, mixed>>|null $installedModules
 * @return array{ok: bool, severity: string, missing: array<int, string>, undeclared_dependencies: array<string, array<int, string>>, checked: int, detail: string}
 */
function validateModuleNavigationRoutes(array $manifest, ?array $installedModules = null): array
{
    $urls = moduleNavigationUrls($manifest);
    if ($urls === []) {
        return ['ok' => true, 'severity' => \Ikabud\Kernel\Contracts\DiagnosticSeverity::Advisory->value, 'missing' => [], 'undeclared_dependencies' => [], 'checked' => 0, 'detail' => 'No internal navigation URLs declared'];
    }

    $moduleId = trim((string)($manifest['id'] ?? ''));
    $manifestPath = $moduleId !== '' ? moduleManifestPathForId($moduleId) : null;
    $modulePath = trim((string)($manifest['_path'] ?? ''));
    if ($modulePath === '' && is_string($manifestPath)) {
        $modulePath = dirname($manifestPath);
    }
    $routesFile = $modulePath !== '' ? $modulePath . '/routes.php' : '';
    $routes = $routesFile !== '' && is_file($routesFile) ? require $routesFile : [];
    $routeOwners = [
        $moduleId => is_array($routes) && is_array($routes['GET'] ?? null)
            ? array_map('strval', array_keys($routes['GET']))
            : [],
    ];
    // Shell modules may intentionally link to pages owned by companion modules
    // (for example, the EHR shell links to scheduling and encounters routes).
    // Certification validates against the complete installed route registry.
    foreach ($installedModules ?? discoverModules() as $candidateId => $candidate) {
        $candidateId = (string)($candidate['id'] ?? $candidateId);
        $candidatePath = trim((string)($candidate['_path'] ?? ''));
        $candidateRoutesFile = $candidatePath !== '' ? $candidatePath . '/routes.php' : '';
        if ($candidateRoutesFile === '' || $candidateRoutesFile === $routesFile || !is_file($candidateRoutesFile)) {
            continue;
        }
        $candidateRoutes = require $candidateRoutesFile;
        if (is_array($candidateRoutes) && is_array($candidateRoutes['GET'] ?? null)) {
            $routeOwners[$candidateId] = array_map('strval', array_keys($candidateRoutes['GET']));
        }
    }

    $missing = [];
    $undeclaredDependencies = [];
    $allowedDependencies = array_fill_keys(array_map('strval', (array)($manifest['navigation_dependencies'] ?? [])), true);
    foreach ($urls as $url) {
        $owners = [];
        foreach ($routeOwners as $ownerId => $getRoutes) {
            foreach ($getRoutes as $route) {
                if (moduleRoutePatternMatchesPath($route, $url)) {
                    $owners[] = $ownerId;
                    break;
                }
            }
        }
        if ($owners === []) {
            $missing[] = $url;
            continue;
        }
        if (in_array($moduleId, $owners, true)) {
            continue;
        }
        $declaredOwner = array_filter($owners, static fn(string $owner): bool => isset($allowedDependencies[$owner]));
        if ($declaredOwner === []) {
            $undeclaredDependencies[$url] = array_values(array_unique($owners));
        }
    }

    $ok = $missing === [] && $undeclaredDependencies === [];
    if ($missing !== []) {
        $detail = 'Missing GET route(s): ' . implode(', ', $missing);
    } elseif ($undeclaredDependencies !== []) {
        $parts = [];
        foreach ($undeclaredDependencies as $url => $owners) {
            $parts[] = $url . ' (owned by ' . implode('|', $owners) . ')';
        }
        $detail = 'Undeclared navigation dependency: ' . implode(', ', $parts);
    } else {
        $dependencyCount = count($allowedDependencies);
        $detail = count($urls) . ' navigation URL(s) resolve with explicit ownership'
            . ($dependencyCount > 0 ? " ({$dependencyCount} navigation dependencies declared)" : '');
    }

    return [
        'ok' => $ok,
        'severity' => \Ikabud\Kernel\Contracts\DiagnosticSeverity::Advisory->value,
        'missing' => $missing,
        'undeclared_dependencies' => $undeclaredDependencies,
        'checked' => count($urls),
        'detail' => $detail,
    ];
}

/**
 * Validate a module manifest against the Phase 9 certification checklist.
 *
 * Returns an array of certification items with pass/fail status.
 * A module must pass ALL checks to be certified.
 *
 * @param array<string, mixed> $manifest
 * @return array{ok: bool, checks: array<int, array{check: string, passed: bool, severity: string, detail: string}>, score: int, max: int}
 */
function validateModuleCertification(array $manifest): array
{
    $checks = [];
    $passed = 0;
    $total = 0;

    $moduleId = (string)($manifest['id'] ?? 'unknown');
    $type = trim((string)($manifest['type'] ?? 'module'));
    $isServiceModule = ($type === 'service-module');

    // C1: Basic identity
    $total++;
    $ok = !empty($manifest['id']) && !empty($manifest['name']) && !empty($manifest['version']);
    $checks[] = ['check' => 'C1: Identity', 'passed' => $ok, 'detail' => $ok ? "{$manifest['name']} v{$manifest['version']}" : 'Missing id, name, or version'];
    if ($ok) $passed++;

    // C2: Table ownership declared (skip for service-modules)
    $total++;
    $isServiceModule = ($type === 'service-module');
    if ($isServiceModule) {
        $checks[] = ['check' => 'C2: Table ownership', 'passed' => true, 'detail' => 'N/A for service-module'];
        $passed++;
    } else {
        $owns = array_key_exists('owns_tables', $manifest) && is_array($manifest['owns_tables']);
        $reads = array_key_exists('reads_tables', $manifest) && is_array($manifest['reads_tables']);
        $ok = $owns && $reads;
        $checks[] = ['check' => 'C2: Table ownership', 'passed' => $ok, 'detail' => $ok ? 'owns_tables and reads_tables explicitly declared' : 'Missing explicit owns_tables or reads_tables declaration'];
        if ($ok) $passed++;
    }

    // C3: Capabilities exposed (declared capabilities key with exposes array — even if empty)
    $total++;
    $capsExposes = $manifest['capabilities']['exposes'] ?? null;
    // Accept: non-empty array OR explicitly declared empty array OR flat-array format
    $capsDeclared = is_array($capsExposes);
    $capsFlatFormat = is_array($manifest['capabilities'] ?? null) && !isset($manifest['capabilities']['exposes']);
    if ($capsFlatFormat) {
        $capsExposes = $manifest['capabilities'];
        $capsDeclared = is_array($capsExposes) && !empty($capsExposes);
    }
    $ok = $capsDeclared;
    $count = is_array($capsExposes) ? count($capsExposes) : 0;
    $checks[] = ['check' => 'C3: Capabilities', 'passed' => $ok, 'detail' => $ok ? ($count > 0 ? "{$count} capabilities exposed" : 'capabilities declared (none exposed)') : 'No capabilities declared'];
    if ($ok) $passed++;

    // C3b: Every PHP capability declaration resolves to a runtime callable.
    $total++;
    if ($isServiceModule || !is_array($capsExposes) || $capsExposes === []) {
        $checks[] = ['check' => 'C3b: Capability handlers', 'passed' => true, 'detail' => $isServiceModule ? 'N/A for service-module' : 'No handlers required'];
        $passed++;
    } else {
        loadModuleHelpers($manifest);
        $modulePrefix = preg_replace('/[^a-z0-9]+/i', '_', $moduleId);
        $exportFunction = $modulePrefix . '_capability_handlers';
        $handlerMap = [];
        if (function_exists($exportFunction)) {
            $exportedHandlers = $exportFunction();
            if (is_array($exportedHandlers)) {
                $handlerMap = $exportedHandlers;
            }
        }
        $missingHandlers = [];
        foreach ($capsExposes as $expose) {
            if (!is_array($expose) || !is_string($expose['id'] ?? null)) {
                continue;
            }
            $capabilityId = $expose['id'];
            $sanitized = preg_replace('/[^a-z0-9]+/i', '_', $capabilityId);
            $conventionFunction = $modulePrefix . '_cap_' . strtolower(trim((string)$sanitized, '_'));
            if ((!isset($handlerMap[$capabilityId]) || !is_callable($handlerMap[$capabilityId])) && !is_callable($conventionFunction)) {
                $missingHandlers[] = $capabilityId;
            }
        }
        $ok = $missingHandlers === [];
        $checks[] = [
            'check' => 'C3b: Capability handlers',
            'passed' => $ok,
            'detail' => $ok
                ? 'All declared capability handlers resolve'
                : 'Missing handler reference(s): ' . implode(', ', $missingHandlers) . ". Export them from {$exportFunction}() in helpers.php.",
        ];
        if ($ok) $passed++;
    }

    // C4: Events declared (accept empty array — module has declared it, just has none)
    $total++;
    $events = is_array($manifest['events'] ?? null);
    $ok = $events;
    $hasEvents = $events && !empty($manifest['events']);
    $checks[] = ['check' => 'C4: Events', 'passed' => $ok, 'detail' => $ok ? ($hasEvents ? count($manifest['events']) . ' events declared' : 'events key declared (none needed)') : 'No events declared'];
    if ($ok) $passed++;

    // C5: Routes declared (skip for service-modules)
    $total++;
    if ($isServiceModule) {
        $checks[] = ['check' => 'C5: Routes', 'passed' => true, 'detail' => 'N/A for service-module'];
        $passed++;
    } else {
        $routes = (is_array($manifest['routes'] ?? null) && !empty($manifest['routes'])) || !empty($manifest['routes']);
        $ok = $routes;
        $checks[] = ['check' => 'C5: Routes', 'passed' => $ok, 'detail' => $ok ? 'Routes declared' : 'No routes declared'];
        if ($ok) $passed++;
    }

    // C6: Migrations present (accept empty array, skip for service-modules)
    $total++;
    if ($isServiceModule) {
        $checks[] = ['check' => 'C6: Migrations', 'passed' => true, 'detail' => 'N/A for service-module'];
        $passed++;
    } else {
        $migrations = is_array($manifest['migrations'] ?? null);
        $hasMigrations = $migrations && !empty($manifest['migrations']);
        $ok = $migrations;
        $checks[] = ['check' => 'C6: Migrations', 'passed' => $ok, 'detail' => $ok ? ($hasMigrations ? count($manifest['migrations']) . ' migrations' : 'migrations key declared (none needed)') : 'No migrations declared'];
        if ($ok) $passed++;
    }

    // C7: Author declared
    $total++;
    $author = !empty($manifest['author']) && is_string($manifest['author']);
    $ok = $author;
    $checks[] = ['check' => 'C7: Author', 'passed' => $ok, 'detail' => $ok ? (string)$manifest['author'] : 'No author declared'];
    if ($ok) $passed++;

    // C8: Description
    $total++;
    $desc = !empty($manifest['description']) && is_string($manifest['description']);
    $ok = $desc;
    $checks[] = ['check' => 'C8: Description', 'passed' => $ok, 'detail' => $ok ? substr((string)$manifest['description'], 0, 60) . '...' : 'No description'];
    if ($ok) $passed++;

    // C9: Module type valid
    $total++;
    $validTypes = ['php-module', 'module', 'service-module'];
    $ok = in_array($type, $validTypes, true);
    $checks[] = ['check' => 'C9: Module type', 'passed' => $ok, 'detail' => $ok ? $type : "Invalid type: {$type}"];
    if ($ok) $passed++;

    // C10: Every declared/rendered internal navigation URL has a GET route.
    $total++;
    if ($isServiceModule) {
        $checks[] = ['check' => 'C10: Navigation routes', 'passed' => true, 'detail' => 'N/A for service-module'];
        $passed++;
    } else {
        $navigation = validateModuleNavigationRoutes($manifest);
        $ok = true;
        $checks[] = [
            'check' => 'C10: Navigation routes',
            'passed' => true,
            'severity' => \Ikabud\Kernel\Contracts\DiagnosticSeverity::Advisory->value,
            'detail' => $navigation['ok'] ? $navigation['detail'] : 'Advisory: ' . $navigation['detail'],
        ];
        $passed++;
    }

    // C11: Service-module endpoint (only if type=service-module)
    if ($type === 'service-module') {
        $total++;
        $endpoint = !empty($manifest['service']['endpoint']) && is_string($manifest['service']['endpoint']);
        $ok = $endpoint;
        $checks[] = ['check' => 'C11: Service endpoint', 'passed' => $ok, 'detail' => $ok ? (string)$manifest['service']['endpoint'] : 'No service endpoint declared'];
        if ($ok) $passed++;
    }

    // C12: Product suite contract (additive — lenient for legacy modules that
    // do not declare suite/kind fields; strict when the contract is used).
    $total++;
    $suiteContractDiags = function_exists('validateModuleSuiteContractV1') ? validateModuleSuiteContractV1($manifest) : [];
    $suiteContractFatal = array_values(array_filter(
        $suiteContractDiags,
        static fn (array $d): bool => ($d['severity'] ?? '') === \Ikabud\Kernel\Contracts\DiagnosticSeverity::Fatal->value
    ));
    $ok = $suiteContractFatal === [];
    $checks[] = [
        'check' => 'C12: Product suite contract',
        'passed' => $ok,
        'detail' => $ok
            ? (array_key_exists('kind', $manifest) || array_key_exists('suite', $manifest) ? 'Suite contract fields valid' : 'N/A — no suite contract declared')
            : 'Suite contract violation(s): ' . implode('; ', array_map(
                static fn (array $d): string => (string)($d['message'] ?? 'invalid'),
                $suiteContractFatal
            )),
    ];
    if ($ok) $passed++;

    // C13: Dynamic admin contributions well-formed and routes resolvable.
    // Advisory when the module declares contributions; skipped otherwise.
    $total++;
    $contribs = is_array($manifest['admin_contributions'] ?? null) ? $manifest['admin_contributions'] : [];
    $hasContribs = $contribs !== [];
    $badRoutes = [];
    if ($hasContribs) {
        foreach ($contribs as $contribution) {
            if (!is_array($contribution)) {
                continue;
            }
            $route = trim((string)($contribution['route'] ?? ''));
            $host = trim((string)($contribution['host'] ?? ''));
            if ($route === '' || !str_starts_with($route, '/') || $host === '') {
                $badRoutes[] = 'route/host required';
                break;
            }
            // Route ownership: when the module is discoverable, verify the
            // route is actually registered to prevent dead links.
            $moduleId = (string)($manifest['id'] ?? '');
            if ($moduleId !== '' && modulePathForId($moduleId) !== null) {
                if (!moduleContributionRouteRegistered($moduleId, $route)) {
                    $badRoutes[] = "unregistered route {$route}";
                    break;
                }
            }
        }
    }
    $ok = $badRoutes === [];
    $checks[] = [
        'check' => 'C13: Admin contributions',
        'passed' => $ok, // advisory — must not break standalone/legacy modules
        'severity' => \Ikabud\Kernel\Contracts\DiagnosticSeverity::Advisory->value,
        'detail' => $hasContribs
            ? ($ok ? count($contribs) . ' contribution(s) declared with owned routes' : 'Malformed contribution: ' . implode('; ', $badRoutes))
            : 'No admin contributions declared',
    ];
    if ($ok) $passed++;

    foreach ($checks as &$check) {
        $check['severity'] ??= \Ikabud\Kernel\Contracts\DiagnosticSeverity::CertificationBlocker->value;
    }
    unset($check);

    // Only CertificationBlocker checks determine certification success.
    // Advisory checks (e.g. nav route hints, admin contribution shape) inform
    // but never block a module. score/max reflect ALL checks for display
    // compatibility with CLI/Workbench/superadmin consumers.
    $blockingChecks = array_values(array_filter(
        $checks,
        static fn (array $check): bool => ($check['severity'] ?? '') === \Ikabud\Kernel\Contracts\DiagnosticSeverity::CertificationBlocker->value
    ));
    $blockingPassed = count(array_filter(
        $blockingChecks,
        static fn (array $check): bool => !empty($check['passed'])
    ));
    $passedTotal = count(array_filter($checks, static fn (array $check): bool => !empty($check['passed'])));

    return [
        'ok' => $blockingPassed === count($blockingChecks),
        'checks' => $checks,
        'score' => $passedTotal,
        'max' => count($checks),
    ];
}

function moduleInstallFailure(string $errorCode, string $error, array $extra = []): array
{
    return ['ok' => false, 'error_code' => $errorCode, 'error' => $error] + $extra;
}

/**
 * Evaluate whether a semver version satisfies a (possibly compound) range.
 *
 * Supported operators: exact ("1.2.3"), ">=X", ">X", "<=X", "<X", "=X",
 * caret ("^1.2" — compatible release), tilde ("~1.2" — patch-level), and
 * space-separated compound ranges (">=1.0 <2.0").
 */
function kernelSemverRangeSatisfies(string $version, string $range): bool
{
    $version = trim($version);
    $range = trim($range);
    if ($version === '' || $range === '') {
        return false;
    }
    $parts = preg_split('/\s+/', $range);
    $parts = is_array($parts) ? array_values(array_filter($parts, static fn ($p): bool => $p !== '')) : [];
    if ($parts === []) {
        return false;
    }
    foreach ($parts as $part) {
        if (!kernelSemverSingleConstraintSatisfies($version, $part)) {
            return false;
        }
    }
    return true;
}

/**
 * Evaluate a single semver constraint against a version.
 */
function kernelSemverSingleConstraintSatisfies(string $version, string $constraint): bool
{
    $constraint = trim($constraint);
    if ($constraint === '') {
        return false;
    }
    $vParts = explode('.', $version);
    $actualMajor = (int)($vParts[0] ?? 0);
    $actualMinor = (int)($vParts[1] ?? 0);
    $actualPatch = (int)($vParts[2] ?? 0);

    // Operator-prefixed constraints.
    if (preg_match('/^(>=|<=|>|<|=)\s*(\d+(?:\.\d+){0,2})/', $constraint, $m)) {
        return version_compare($version, $m[2], $m[1]);
    }

    // Caret: ^1.2 → >=1.2.0 <2.0.0; ^0.1 → >=0.1.0 <0.2.0.
    if (str_starts_with($constraint, '^')) {
        $min = trim(substr($constraint, 1));
        if ($min === '') {
            return false;
        }
        $minParts = explode('.', $min);
        $minMajor = (int)($minParts[0] ?? 0);
        $minMinor = (int)($minParts[1] ?? 0);
        if (!version_compare($version, $min, '>=')) {
            return false;
        }
        if ($minMajor === 0) {
            return $actualMajor === 0 && $actualMinor === $minMinor;
        }
        return $actualMajor === $minMajor;
    }

    // Tilde: ~1.2 → >=1.2.0 <1.3.0; ~1.2.3 → >=1.2.3 <1.3.0.
    if (str_starts_with($constraint, '~')) {
        $min = trim(substr($constraint, 1));
        if ($min === '') {
            return false;
        }
        $minParts = explode('.', $min);
        $minMajor = (int)($minParts[0] ?? 0);
        $minMinor = (int)($minParts[1] ?? 0);
        return version_compare($version, $min, '>=')
            && $actualMajor === $minMajor
            && $actualMinor === $minMinor;
    }

    // Plain exact version (may be 1, 1.2, or 1.2.3).
    if (preg_match('/^\d+(?:\.\d+){0,2}$/', $constraint)) {
        return version_compare($version, $constraint, '==');
    }

    return false;
}

/**
 * Resolve the suite version for compatibility evaluation. The suite core
 * module's declared version is treated as the suite version.
 *
 * @param string $suiteId normalized suite id
 * @param array<string,array<string,mixed>>|null $fleet
 */
function kernelSuiteVersionFor(string $suiteId, ?array $fleet = null): ?string
{
    if ($fleet === null) {
        $fleet = discoverModules();
    }
    $coreId = moduleSuiteCore($suiteId, $fleet);
    if ($coreId === null) {
        return null;
    }
    $version = (string)($fleet[$coreId]['version'] ?? '');
    return $version !== '' ? $version : null;
}

/**
 * Product suite install gate. Validates cross-module ownership and
 * compatibility before a package is allowed to be installed/enabled.
 *
 * Rules enforced:
 *  - extension/adapter kind requires an `extends` host present in the fleet.
 *  - contribution hosts must exist in the fleet.
 *  - contributed extension points must be declared by the extends host.
 *  - a profile's `installs` list must not reference the profile itself.
 *
 * Standalone/legacy modules (no kind/suite contract) pass through untouched.
 *
 * @param array<string,mixed> $manifest the package manifest
 * @param array<string,array<string,mixed>> $fleet discovered modules
 * @return array{ok:bool,error?:string,error_code?:string,checks?:array}
 */
function validateModuleSuiteContractForInstall(array $manifest, array $fleet): array
{
    $checks = [];
    $passed = 0;
    $total = 0;
    $failures = [];

    $moduleId = (string)($manifest['id'] ?? '');
    $kind = moduleManifestKindFromManifest($manifest);
    $extends = $manifest['extends'] ?? $manifest['parent'] ?? null;
    $extends = is_string($extends) && trim($extends) !== '' ? trim($extends) : null;

    // G1: extension/adapter requires an installed host
    $total++;
    if ($kind === MODULE_KIND_EXTENSION || $kind === MODULE_KIND_ADAPTER) {
        $hostPresent = $extends !== null && isset($fleet[$extends]);
        $checks[] = ['check' => 'G1: Host present', 'passed' => $hostPresent, 'detail' => $hostPresent ? "Host '{$extends}' present" : ($extends !== null ? "Host '{$extends}' is not installed" : 'No extends declared')];
        if ($hostPresent) {
            $passed++;
        } else {
            $failures[] = $extends !== null ? "host '{$extends}' not installed" : 'missing extends';
        }
    } else {
        $checks[] = ['check' => 'G1: Host present', 'passed' => true, 'detail' => 'N/A for kind ' . $kind];
        $passed++;
    }

    // G2: contribution hosts exist in the fleet
    $total++;
    $contribs = is_array($manifest['admin_contributions'] ?? null) ? $manifest['admin_contributions'] : [];
    $unknownHosts = [];
    foreach ($contribs as $contribution) {
        if (!is_array($contribution)) {
            continue;
        }
        $host = trim((string)($contribution['host'] ?? ''));
        if ($host !== '' && !isset($fleet[$host])) {
            $unknownHosts[] = $host;
        }
    }
    $hostsOk = $unknownHosts === [];
    $checks[] = ['check' => 'G2: Contribution hosts', 'passed' => $hostsOk, 'detail' => $hostsOk ? 'All contribution hosts present' : 'Unknown host(s): ' . implode(', ', array_unique($unknownHosts))];
    if ($hostsOk) {
        $passed++;
    } else {
        $failures[] = 'unknown contribution host(s): ' . implode(', ', array_unique($unknownHosts));
    }

    // G3: contributed extension points declared by host
    $total++;
    $contributes = is_array($manifest['contributes'] ?? null) ? $manifest['contributes'] : [];
    $undeclaredPoints = [];
    if ($contributes !== [] && $extends !== null && isset($fleet[$extends])) {
        $declaredPoints = is_array($fleet[$extends]['extension_points'] ?? null) ? $fleet[$extends]['extension_points'] : [];
        foreach ($contributes as $contribution) {
            if (!is_array($contribution) || !is_string($contribution['extension_point'] ?? null)) {
                continue;
            }
            $point = trim($contribution['extension_point']);
            if ($point !== '' && !in_array($point, $declaredPoints, true)) {
                $undeclaredPoints[] = $point;
            }
        }
    }
    $pointsOk = $undeclaredPoints === [];
    $checks[] = ['check' => 'G3: Extension points', 'passed' => $pointsOk, 'detail' => $pointsOk ? 'Contributed points are declared by host' : 'Undeclared point(s): ' . implode(', ', array_unique($undeclaredPoints))];
    if ($pointsOk) {
        $passed++;
    } else {
        $failures[] = 'undeclared extension point(s): ' . implode(', ', array_unique($undeclaredPoints));
    }

    // G4: profile must not install itself
    $total++;
    $selfRef = false;
    if ($kind === MODULE_KIND_PROFILE && is_array($manifest['installs'] ?? null)) {
        $selfRef = in_array($moduleId, $manifest['installs'], true);
    }
    $checks[] = ['check' => 'G4: Profile self-reference', 'passed' => !$selfRef, 'detail' => $selfRef ? "Profile '{$moduleId}' cannot install itself" : 'No self-reference'];
    if (!$selfRef) {
        $passed++;
    } else {
        $failures[] = 'profile installs itself';
    }

    // G5: contribution routes must be owned by the contributing module (GET).
    // Prevents dynamically generated dead links from surfacing in admin nav.
    // When the module's routes file is not resolvable (e.g. install-time path
    // unavailable in a pure in-memory test fleet), the check is skipped.
    $total++;
    $unownedRoutes = [];
    $routesResolvable = moduleRoutesFilePathForManifest($manifest) !== '';
    foreach ($contribs as $contribution) {
        if (!is_array($contribution)) {
            continue;
        }
        $route = trim((string)($contribution['route'] ?? ''));
        if ($route === '') {
            continue;
        }
        if (!moduleContributionRouteRegistered($moduleId, $route, $manifest)) {
            $unownedRoutes[] = $route;
        }
    }
    $routesOwned = $unownedRoutes === [];
    if ($routesResolvable) {
        $checks[] = ['check' => 'G5: Contribution route ownership', 'passed' => $routesOwned, 'detail' => $routesOwned ? 'All contribution routes are registered by the module' : 'Unregistered contribution route(s): ' . implode(', ', array_unique($unownedRoutes))];
        if ($routesOwned) {
            $passed++;
        } else {
            $failures[] = 'unregistered contribution route(s): ' . implode(', ', array_unique($unownedRoutes));
        }
    } else {
        $checks[] = ['check' => 'G5: Contribution route ownership', 'passed' => true, 'detail' => 'Route file not resolvable — ownership check skipped'];
        $passed++;
    }

    // G6: compatibility enforcement — Kernel version and host-suite version
    // ranges declared in the manifest must be satisfiable by the fleet.
    $total++;
    $compatFailures = [];
    $compatibility = is_array($manifest['compatibility'] ?? null) ? $manifest['compatibility'] : [];

    $kernelRange = is_string($compatibility['kernel'] ?? null) ? trim($compatibility['kernel']) : '';
    if ($kernelRange !== '') {
        $currentKernel = \Ikabud\Kernel\App::KERNEL_VERSION;
        if (!kernelSemverRangeSatisfies($currentKernel, $kernelRange)) {
            $compatFailures[] = "kernel {$currentKernel} does not satisfy {$kernelRange}";
        }
    }

    $suiteRange = is_string($compatibility['suite'] ?? null) ? trim($compatibility['suite']) : '';
    if ($suiteRange !== '') {
        $suiteId = moduleSuiteFromManifest($manifest);
        $suiteVersion = $suiteId !== null ? kernelSuiteVersionFor($suiteId, $fleet) : null;
        if ($suiteVersion === null) {
            $compatFailures[] = "suite '{$suiteId}' version unknown — cannot evaluate {$suiteRange}";
        } elseif (!kernelSemverRangeSatisfies($suiteVersion, $suiteRange)) {
            $compatFailures[] = "suite {$suiteVersion} does not satisfy {$suiteRange}";
        }
    }

    $compatOk = $compatFailures === [];
    $checks[] = ['check' => 'G6: Compatibility', 'passed' => $compatOk, 'detail' => $compatOk ? 'Declared compatibility ranges satisfied' : 'Compatibility violation(s): ' . implode('; ', $compatFailures)];
    if ($compatOk) {
        $passed++;
    } else {
        $failures[] = 'compatibility: ' . implode('; ', $compatFailures);
    }

    foreach ($checks as &$check) {
        $check['severity'] ??= \Ikabud\Kernel\Contracts\DiagnosticSeverity::CertificationBlocker->value;
    }
    unset($check);

    if ($passed === $total) {
        return ['ok' => true, 'checks' => $checks];
    }
    return [
        'ok' => false,
        'error_code' => 'module_suite_contract_failed',
        'error' => 'Module failed product suite contract: ' . implode('; ', $failures),
        'checks' => $checks,
    ];
}

/**
 * Install a module from a zip file.
 * Returns ['ok' => true, 'module_id' => '...'] or ['ok' => false, 'error' => '...']
 */
/**
 * Verify an optional Ed25519 package signature (module.sig.json) inside a
 * module ZIP before extraction.
 *
 * A signed package contains a top-level module.sig.json:
 *   { "alg":"Ed25519", "version":1,
 *     "files": {"module.json":"<sha256>", "handlers.php":"<sha256>", ...},
 *     "signature":"<base64url(Ed25519 over canonical {alg,version,files})>" }
 *
 * Policy (fully opt-in; legacy unsigned packages remain valid):
 *   - No signature entry + MODULE_SIGNING_REQUIRED=true   → reject
 *   - Signature entry + public key configured             → verify strictly
 *   - Signature entry + no key configured                 → advisory (accept + log)
 *   - No signature entry + not required                   → accept (legacy)
 *
 * Public key: MODULE_SIGNING_PUBLIC_KEY (base64 Ed25519 key) or
 * MODULE_SIGNING_PUBLIC_KEY_PATH (file containing the base64 key).
 *
 * @param \ZipArchive $zip    Open module zip (not yet extracted)
 * @param string      $prefix Module root prefix inside the zip ('' or '<dir>/')
 * @return array{ok:bool, error?:string, warning?:string}
 */
function moduleVerifyPackageSignature(\ZipArchive $zip, string $prefix): array
{
    $sigName = $prefix . 'module.sig.json';
    $sigIndex = $zip->locateName($sigName);
    $required = filter_var($_ENV['MODULE_SIGNING_REQUIRED'] ?? 'false', FILTER_VALIDATE_BOOL);

    // Public key configuration
    $pubKeyB64 = trim((string)($_ENV['MODULE_SIGNING_PUBLIC_KEY'] ?? ''));
    if ($pubKeyB64 === '') {
        $pubKeyPath = trim((string)($_ENV['MODULE_SIGNING_PUBLIC_KEY_PATH'] ?? ''));
        if ($pubKeyPath !== '' && is_file($pubKeyPath)) {
            $pubKeyB64 = (string)file_get_contents($pubKeyPath);
        }
    }
    $pubKey = '';
    if ($pubKeyB64 !== '') {
        $decoded = base64_decode($pubKeyB64, true);
        $pubKey = is_string($decoded) && $decoded !== '' ? $decoded : trim($pubKeyB64);
    }

    if ($sigIndex === false) {
        if ($required && $pubKey !== '') {
            return ['ok' => false, 'error' => 'Module package is not signed but MODULE_SIGNING_REQUIRED is enabled'];
        }
        return ['ok' => true];
    }

    if (!function_exists('sodium_crypto_sign_verify_detached')) {
        if ($required) {
            return ['ok' => false, 'error' => 'Module signature verification requires the sodium extension (bundled with PHP 7.2+)'];
        }
        return ['ok' => true, 'warning' => 'Package is signed but the sodium extension is unavailable; signature not verified'];
    }

    $raw = $zip->getFromIndex($sigIndex);
    $sig = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($sig)
        || ($sig['alg'] ?? '') !== 'Ed25519'
        || !is_array($sig['files'] ?? null)
        || !is_string($sig['signature'] ?? null)) {
        if ($required) {
            return ['ok' => false, 'error' => 'module.sig.json is malformed'];
        }
        return ['ok' => true, 'warning' => 'module.sig.json is malformed; signature not verified'];
    }

    // Rebuild the canonical signed payload (sorted for determinism).
    $files = $sig['files'];
    ksort($files);
    $canonical = json_encode([
        'alg' => 'Ed25519',
        'version' => (int)($sig['version'] ?? 1),
        'files' => $files,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $signature = base64_decode(strtr((string)$sig['signature'], '-_', '+/'), true);

    if ($pubKey === '') {
        // Signed but no key configured — cannot verify. Advisory unless required.
        if ($required) {
            return ['ok' => false, 'error' => 'Module is signed but MODULE_SIGNING_PUBLIC_KEY is not configured'];
        }
        return ['ok' => true, 'warning' => 'Package is signed but no MODULE_SIGNING_PUBLIC_KEY is configured; signature not verified'];
    }

    if (!is_string($signature) || $signature === '' || !sodium_crypto_sign_verify_detached($signature, $canonical, $pubKey)) {
        return ['ok' => false, 'error' => 'Module signature verification failed (bad signature or key mismatch)'];
    }

    // Full integrity check: every shipped regular file must be listed and hash-match.
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string)$zip->getNameIndex($i);
        $rel = ltrim(substr(str_replace('\\', '/', $name), strlen($prefix)), '/');
        if ($rel === '' || str_ends_with($rel, '/')) {
            continue; // directory entry
        }
        if ($rel === 'module.sig.json') {
            continue; // signature file itself is excluded from the hash manifest
        }
        if (!isset($files[$rel])) {
            return ['ok' => false, 'error' => "Module signature does not cover file: {$rel}"];
        }
        $content = $zip->getFromIndex($i);
        if (!is_string($content) || hash('sha256', $content) !== $files[$rel]) {
            return ['ok' => false, 'error' => "Module file hash mismatch: {$rel}"];
        }
    }
    // Ensure every listed file actually exists in the package.
    foreach (array_keys($files) as $rel) {
        if ($zip->locateName($prefix . $rel) === false) {
            return ['ok' => false, 'error' => "Module signature lists a missing file: {$rel}"];
        }
    }

    return ['ok' => true];
}

/**
 * Sign a module package ZIP with an Ed25519 private key, adding
 * module.sig.json (compatible with moduleVerifyPackageSignature).
 *
 * @param string $zipPath        Path to the module zip (rewritten in place)
 * @param string $privateKeyB64  Base64-encoded Ed25519 secret key
 * @return array{ok:bool, error?:string, files_signed?:int}
 */
function moduleSignPackageForPath(string $zipPath, string $privateKeyB64): array
{
    if (!function_exists('sodium_crypto_sign_detached')) {
        return ['ok' => false, 'error' => 'Signing requires the sodium extension (bundled with PHP 7.2+)'];
    }
    $priv = base64_decode($privateKeyB64, true);
    if (!is_string($priv) || strlen($priv) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
        return ['ok' => false, 'error' => 'Invalid Ed25519 private key (expected ' . SODIUM_CRYPTO_SIGN_SECRETKEYBYTES . '-byte base64)'];
    }
    if (!class_exists('ZipArchive')) {
        return ['ok' => false, 'error' => 'PHP zip extension is required'];
    }

    $zip = new \ZipArchive();
    if ($zip->open($zipPath) !== true) {
        return ['ok' => false, 'error' => 'Cannot open zip file'];
    }

    $prefix = '';
    if ($zip->locateName('module.json') === false) {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $n = (string)$zip->getNameIndex($i);
            if (preg_match('#^([^/]+)/module\.json$#', $n, $m)) {
                $prefix = $m[1] . '/';
                break;
            }
        }
    }
    if ($zip->locateName($prefix . 'module.json') === false) {
        $zip->close();
        return ['ok' => false, 'error' => 'Zip does not contain module.json'];
    }

    $files = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string)$zip->getNameIndex($i);
        $rel = ltrim(substr(str_replace('\\', '/', $name), strlen($prefix)), '/');
        if ($rel === '' || str_ends_with($rel, '/')) {
            continue;
        }
        if ($rel === 'module.sig.json') {
            continue;
        }
        $content = $zip->getFromIndex($i);
        if (!is_string($content)) {
            continue;
        }
        $files[$rel] = hash('sha256', $content);
    }
    ksort($files);

    $payload = json_encode([
        'alg' => 'Ed25519',
        'version' => 1,
        'files' => $files,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $signature = sodium_crypto_sign_detached($payload, $priv);

    $sigJson = json_encode([
        'alg' => 'Ed25519',
        'version' => 1,
        'files' => $files,
        'signature' => rtrim(strtr(base64_encode($signature), '+/', '-_'), '='),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $existing = $zip->locateName($prefix . 'module.sig.json');
    if ($existing !== false) {
        $zip->deleteIndex($existing);
    }
    $zip->addFromString($prefix . 'module.sig.json', $sigJson);
    if (!$zip->close()) {
        return ['ok' => false, 'error' => 'Failed to write signed package'];
    }

    return ['ok' => true, 'files_signed' => count($files)];
}

function installModuleFromZip(string $zipPath): array
{
    if (!is_file($zipPath)) {
        return moduleInstallFailure('zip_not_found', 'Zip file not found');
    }

    $signature = @file_get_contents($zipPath, false, null, 0, 4);
    $zipSignatures = ["PK\x03\x04", "PK\x05\x06", "PK\x07\x08"];
    if (!is_string($signature) || !in_array($signature, $zipSignatures, true)) {
        return moduleInstallFailure('zip_invalid_signature', 'Uploaded file is not a valid ZIP archive');
    }

    if (!class_exists('ZipArchive')) {
        return moduleInstallFailure('zip_extension_missing', 'PHP zip extension is required');
    }

    $zip = new \ZipArchive();
    if ($zip->open($zipPath) !== true) {
        return moduleInstallFailure('zip_open_failed', 'Cannot open zip file');
    }

    $maxEntries = 2000;
    $maxTotalUncompressedBytes = 200 * 1024 * 1024; // 200 MiB
    if ($zip->numFiles > $maxEntries) {
        $zip->close();
        return moduleInstallFailure('zip_too_many_entries', 'Zip contains too many entries');
    }

    // Find module.json in the zip (could be at root or inside a single top-level folder)
    $manifestIndex = null;
    $prefix = '';
    $packagePrefix = '__ikabud_package/';
    $packageTemplatesPrefix = $packagePrefix . 'templates/';
    $hasPackagedTemplates = false;

    // Normalize + validate zip entry names before any extraction.
    // This blocks Zip Slip style traversal, absolute paths, and null-byte names.
    $sanitizeEntryName = static function (string $name): ?string {
        $name = str_replace('\\\\', '/', $name);
        if ($name === '' || str_contains($name, "\0")) {
            return null;
        }
        if (preg_match('/^[A-Za-z]:\//', $name)) {
            return null;
        }
        if (str_contains($name, ':')) {
            return null;
        }
        if (str_starts_with($name, '/')) {
            return null;
        }

        $parts = explode('/', $name);
        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                return null;
            }
        }

        return ltrim($name, '/');
    };

    $isSafeZipEntryType = static function (int $index, bool $isDirectory) use ($zip): bool {
        if (!method_exists($zip, 'getExternalAttributesIndex')) {
            return true;
        }

        $opsys = 0;
        $attr = 0;
        $ok = $zip->getExternalAttributesIndex($index, $opsys, $attr);
        if (!$ok) {
            return true;
        }

        // On Unix creators, upper 16 bits generally store st_mode.
        $mode = ($attr >> 16) & 0xF000;
        if ($mode === 0) {
            return true;
        }

        // Reject symbolic links explicitly.
        if ($mode === 0xA000) {
            return false;
        }

        if ($isDirectory) {
            return $mode === 0x4000;
        }

        // For file entries, allow regular files only.
        return $mode === 0x8000;
    };

    // Check root first
    if ($zip->locateName('module.json') !== false) {
        $manifestIndex = $zip->locateName('module.json');
        $prefix = '';
    } else {
        // Check for single top-level directory containing module.json
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $rawName = (string)$zip->getNameIndex($i);
            $name = $sanitizeEntryName($rawName);
            if ($name === null) {
                continue;
            }
            if (preg_match('#^([^/]+)/module\.json$#', $name, $m)) {
                $manifestIndex = $i;
                $prefix = $m[1] . '/';
                break;
            }
        }
    }

    if ($manifestIndex === null) {
        $zip->close();
        return moduleInstallFailure('manifest_not_found', 'Zip file does not contain module.json');
    }

    // Preflight all entries so malformed archives fail closed.
    $totalUncompressedBytes = 0;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $rawName = (string)$zip->getNameIndex($i);
        $name = $sanitizeEntryName($rawName);
        if ($name === null) {
            $zip->close();
            return moduleInstallFailure('zip_invalid_path', "Zip contains invalid entry path: {$rawName}");
        }

        if ($prefix !== '' && !str_starts_with($name, $prefix)) {
            $zip->close();
            return moduleInstallFailure('zip_outside_module_root', "Zip contains files outside module root: {$name}");
        }

        $relativeName = $prefix !== '' ? substr($name, strlen($prefix)) : $name;
        if ($relativeName === '') {
            continue;
        }

        if (str_starts_with($relativeName, $packagePrefix)) {
            if (!str_starts_with($relativeName, $packageTemplatesPrefix)) {
                $zip->close();
                return moduleInstallFailure('zip_unknown_package_section', "Zip contains an unsupported package section: {$name}");
            }
            $templateRelativeName = substr($relativeName, strlen($packageTemplatesPrefix));
            if ($templateRelativeName !== '') {
                $hasPackagedTemplates = true;
            }
        }

        $isDirectory = str_ends_with($relativeName, '/');
        if (!$isSafeZipEntryType($i, $isDirectory)) {
            $zip->close();
            return moduleInstallFailure('zip_unsupported_entry_type', "Zip contains unsupported entry type: {$name}");
        }

        if (!$isDirectory) {
            $normalizedRelativeName = ltrim(str_replace('\\\\', '/', $relativeName), '/');
            if ($normalizedRelativeName === '' || str_contains($normalizedRelativeName, "\0") || str_contains($normalizedRelativeName, '../')) {
                $zip->close();
                return moduleInstallFailure('zip_invalid_path', "Zip contains invalid file path: {$name}");
            }

            $stat = $zip->statIndex($i);
            if (is_array($stat)) {
                $entrySize = (int)($stat['size'] ?? 0);
                if ($entrySize < 0) {
                    $zip->close();
                    return moduleInstallFailure('zip_invalid_metadata', "Zip contains invalid entry metadata: {$name}");
                }
                $totalUncompressedBytes += $entrySize;
                if ($totalUncompressedBytes > $maxTotalUncompressedBytes) {
                    $zip->close();
                    return moduleInstallFailure('zip_size_limit_exceeded', 'Zip uncompressed size exceeds allowed limit');
                }
            }
        }
    }

    // Read and validate the manifest
    $manifestJson = $zip->getFromIndex($manifestIndex);
    $tempManifest = tempnam(sys_get_temp_dir(), 'mod_manifest_');
    file_put_contents($tempManifest, $manifestJson);
    $validation = validateModuleManifest($tempManifest, ['check_filesystem' => false]);
    @unlink($tempManifest);

    if (!$validation['ok']) {
        $zip->close();
        return $validation + ['error_code' => $validation['error_code'] ?? 'manifest_validation_failed'];
    }

    $manifest = $validation['manifest'];
    $capabilityValidation = validateModuleCapabilities($manifest);
    if (empty($capabilityValidation['ok'])) {
        $zip->close();
        return moduleInstallFailure(
            'manifest_invalid_capabilities',
            (string)($capabilityValidation['error'] ?? 'module.json capabilities block is invalid')
        );
    }

    $moduleId = $manifest['id'];

    // ── Optional package signature verification (Ed25519) ───────────
    // Only activates when the package ships module.sig.json and/or
    // MODULE_SIGNING_REQUIRED / MODULE_SIGNING_PUBLIC_KEY are configured.
    $signatureCheck = moduleVerifyPackageSignature($zip, $prefix);
    if (!empty($signatureCheck['error'])) {
        $zip->close();
        return moduleInstallFailure('module_signature_verification_failed', $signatureCheck['error']);
    }
    if (!empty($signatureCheck['warning'])) {
        if (function_exists('write_log')) {
            write_log('module install signature advisory: ' . $signatureCheck['warning'], 'warning', [
                'module_id' => $moduleId,
                'request_id' => function_exists('request_id') ? request_id() : null,
            ]);
        }
    }

    $suiteId = moduleSuiteFromManifest($manifest);
    if ($suiteId !== null && !str_starts_with($moduleId . '-', $suiteId . '-')) {
        $zip->close();
        return moduleInstallFailure('manifest_invalid_suite', "Module '{$moduleId}' declares suite '{$suiteId}' but id does not use the suite prefix.");
    }

    $targetDir = moduleInstallTargetDirForId($moduleId, $suiteId);
    if ($suiteId !== null) {
        $suiteDir = rtrim(modulesPath(), '/') . '/' . $suiteId;
        if (is_file($suiteDir . '/module.json')) {
            $zip->close();
            return moduleInstallFailure(
                'manifest_invalid_suite_container',
                "Cannot install '{$moduleId}' into suite '{$suiteId}' because modules/{$suiteId}/module.json exists (suite path is a real module)."
            );
        }
    }
    $targetTemplateDir = BASE_PATH . '/templates/modules/' . $moduleId;
    $removeDirectory = static function (string $path): void {
        if (!is_dir($path)) {
            return;
        }

        $it = new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS);
        $files = new \RecursiveIteratorIterator($it, \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            if ($file->isDir()) {
                @rmdir($file->getRealPath());
            } else {
                @unlink($file->getRealPath());
            }
        }
        @rmdir($path);
    };

    // Safety: don't overwrite if already exists (use update flow instead)
    if (is_dir($targetDir)) {
        $zip->close();
        return moduleInstallFailure('module_already_exists', "Module '{$moduleId}' already exists. Remove it first or use update.");
    }
    if ($hasPackagedTemplates && (file_exists($targetTemplateDir) || is_link($targetTemplateDir))) {
        $zip->close();
        return moduleInstallFailure('module_templates_already_exist', "Templates for module '{$moduleId}' already exist. Remove them first or use update.");
    }

    $cleanupInstall = static function () use ($removeDirectory, $targetDir, $targetTemplateDir, $hasPackagedTemplates): void {
        $removeDirectory($targetDir);
        if ($hasPackagedTemplates) {
            $removeDirectory($targetTemplateDir);
        }
    };

    // Extract
    @mkdir($targetDir, 0775, true);
    $targetRoot = realpath($targetDir);
    if ($targetRoot === false) {
        $zip->close();
        return moduleInstallFailure('target_dir_init_failed', 'Failed to initialize module target directory');
    }
    $targetTemplateRoot = null;
    if ($hasPackagedTemplates) {
        @mkdir($targetTemplateDir, 0775, true);
        $targetTemplateRoot = realpath($targetTemplateDir);
        if ($targetTemplateRoot === false) {
            $zip->close();
            $cleanupInstall();
            return moduleInstallFailure('template_target_dir_init_failed', 'Failed to initialize module template target directory');
        }
    }

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $rawName = (string)$zip->getNameIndex($i);
        $name = $sanitizeEntryName($rawName);
        if ($name === null) {
            $zip->close();
            $cleanupInstall();
            return moduleInstallFailure('zip_invalid_path', "Zip contains invalid entry path: {$rawName}");
        }

        // Strip the optional top-level module prefix before interpreting the
        // reserved package section.
        if ($prefix !== '') {
            if (!str_starts_with($name, $prefix)) {
                $zip->close();
                $cleanupInstall();
                return moduleInstallFailure('zip_outside_module_root', "Zip contains files outside module root: {$name}");
            }
            $relativeName = substr($name, strlen($prefix));
        } else {
            $relativeName = $name;
        }

        $isTemplateEntry = str_starts_with($relativeName, $packageTemplatesPrefix);
        $destinationRelativeName = $isTemplateEntry
            ? substr($relativeName, strlen($packageTemplatesPrefix))
            : $relativeName;
        $destinationRoot = $isTemplateEntry ? $targetTemplateRoot : $targetRoot;
        $destinationBase = $isTemplateEntry ? $targetTemplateDir : $targetDir;

        if ($relativeName === '' || str_ends_with($relativeName, '/')) {
            if (!$isSafeZipEntryType($i, true)) {
                $zip->close();
                $cleanupInstall();
                return moduleInstallFailure('zip_unsupported_entry_type', "Zip contains unsupported directory entry type: {$name}");
            }
            if ($isTemplateEntry && $destinationRelativeName !== '') {
                @mkdir($destinationBase . '/' . $destinationRelativeName, 0775, true);
            } elseif (!$isTemplateEntry && !str_starts_with($relativeName, $packagePrefix)) {
                @mkdir($destinationBase . '/' . $destinationRelativeName, 0775, true);
            }
            continue;
        }

        if (!$isSafeZipEntryType($i, false)) {
            $zip->close();
            $cleanupInstall();
            return moduleInstallFailure('zip_unsupported_entry_type', "Zip contains unsupported file entry type: {$name}");
        }

        $destinationRelativeName = ltrim(str_replace('\\\\', '/', $destinationRelativeName), '/');
        if ($destinationRelativeName === '' || str_contains($destinationRelativeName, "\0") || str_contains($destinationRelativeName, '../')) {
            $zip->close();
            $cleanupInstall();
            return moduleInstallFailure('zip_invalid_path', "Zip contains invalid file path: {$name}");
        }

        if (!is_string($destinationRoot) || $destinationRoot === '') {
            $zip->close();
            $cleanupInstall();
            return moduleInstallFailure('target_dir_resolution_failed', "Failed resolving extraction target: {$name}");
        }
        $fullPath = $destinationBase . '/' . $destinationRelativeName;
        if (str_starts_with($fullPath, $destinationRoot . DIRECTORY_SEPARATOR) === false) {
            $zip->close();
            $cleanupInstall();
            return moduleInstallFailure('zip_outside_module_root', "Zip entry escapes module root: {$name}");
        }

        $dir = dirname($fullPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $realDir = realpath($dir);
        if ($realDir === false) {
            $zip->close();
            $cleanupInstall();
            return moduleInstallFailure('target_dir_resolution_failed', "Failed resolving extraction directory: {$name}");
        }
        if (!($realDir === $destinationRoot || str_starts_with($realDir, $destinationRoot . DIRECTORY_SEPARATOR))) {
            $zip->close();
            $cleanupInstall();
            return moduleInstallFailure('zip_outside_module_root', "Zip extraction directory escapes module root: {$name}");
        }

        $contents = $zip->getFromIndex($i);
        if ($contents === false || file_put_contents($fullPath, $contents) === false) {
            $zip->close();
            $cleanupInstall();
            return moduleInstallFailure('zip_extraction_failed', "Failed extracting file: {$name}");
        }
    }
    $zip->close();

    // Re-run the authoritative validator against extracted files. Preflight
    // intentionally disables filesystem checks because the module does not yet
    // exist; install must not certify a package with missing declared files.
    $postExtractionValidation = validateModuleManifest($targetDir . '/module.json');
    if (empty($postExtractionValidation['ok']) || !is_array($postExtractionValidation['manifest'] ?? null)) {
        $cleanupInstall();
        return moduleInstallFailure(
            (string)($postExtractionValidation['error_code'] ?? 'post_extract_manifest_validation_failed'),
            'Extracted module failed manifest validation: ' . (string)($postExtractionValidation['error'] ?? 'unknown error'),
            ['diagnostics' => $postExtractionValidation['diagnostics'] ?? []]
        );
    }

    $installManifest = $postExtractionValidation['manifest'];
    $installManifest['_path'] = $targetDir;
    $certification = validateModuleCertification($installManifest);
    if (empty($certification['ok'])) {
        $cleanupInstall();
        $failedChecks = array_values(array_map(
            static fn(array $check): string => (string)$check['check'] . ': ' . (string)$check['detail'],
            array_filter($certification['checks'], static fn(array $check): bool => empty($check['passed']))
        ));
        return moduleInstallFailure(
            'module_not_certified',
            'Module failed production certification: ' . implode('; ', $failedChecks),
            ['certification' => $certification]
        );
    }

    // Product suite install gate: extension ownership, contribution hosts, and
    // declared extension points are validated against the current fleet before
    // the module may be enabled.
    $suiteGate = validateModuleSuiteContractForInstall($installManifest, discoverModules());
    if (empty($suiteGate['ok'])) {
        $cleanupInstall();
        return moduleInstallFailure(
            (string)($suiteGate['error_code'] ?? 'module_suite_contract_failed'),
            'Module failed product suite contract: ' . (string)($suiteGate['error'] ?? 'unknown error'),
            ['suite_contract' => $suiteGate]
        );
    }

    // Auto-enable the newly installed module if capability dependencies are satisfiable.
    // If not satisfiable, install succeeds but module remains disabled.
    $capCheck = validateModuleCapabilities($manifest);
    if (!empty($capCheck['ok'])) {
        $missing = [];
        foreach (($capCheck['depends'] ?? []) as $capId) {
            if (!app()->capabilities()->has((string)$capId)) {
                $missing[] = (string)$capId;
            }
        }
        if (empty($missing)) {
            enableModule($moduleId);
            return ['ok' => true, 'module_id' => $moduleId, 'enabled' => true, 'manifest' => $manifest];
        }

        // Ensure it is explicitly disabled (in case a default-enabled registry is present)
        disableModule($moduleId);
        return [
            'ok' => true,
            'module_id' => $moduleId,
            'enabled' => false,
            'manifest' => $manifest,
            'warning' => 'Module installed but not enabled: missing required capability providers',
            'missing' => $missing,
        ];
    }

    // Invalid capability manifest: install but keep disabled.
    disableModule($moduleId);
    return [
        'ok' => true,
        'module_id' => $moduleId,
        'enabled' => false,
        'manifest' => $manifest,
        'warning' => 'Module installed but not enabled: invalid capability manifest',
        'error' => $capCheck['error'] ?? 'invalid',
    ];
}

/**
 * Resolve a module's declared uninstall policy, merged with safe defaults.
 *
 * @param array<string,mixed> $manifest
 * @return array{disable_safe:bool,retain_data_by_default:bool,supports_data_export:bool,requires_confirmation_to_drop_data:bool}
 */
function moduleUninstallPolicyForManifest(array $manifest): array
{
    $raw = $manifest['uninstall'] ?? null;
    $raw = is_array($raw) ? $raw : [];

    return [
        'disable_safe' => !array_key_exists('disable_safe', $raw) ? true : (bool)$raw['disable_safe'],
        'retain_data_by_default' => !array_key_exists('retain_data_by_default', $raw) ? true : (bool)$raw['retain_data_by_default'],
        'supports_data_export' => !array_key_exists('supports_data_export', $raw) ? false : (bool)$raw['supports_data_export'],
        'requires_confirmation_to_drop_data' => !array_key_exists('requires_confirmation_to_drop_data', $raw) ? true : (bool)$raw['requires_confirmation_to_drop_data'],
    ];
}

/**
 * Resolve uninstall policy for a module id (reads its manifest from disk).
 */
function moduleUninstallPolicyForModule(string $moduleId): array
{
    $manifestPath = moduleManifestPathForId($moduleId);
    $manifest = [];
    if ($manifestPath !== null && is_file($manifestPath)) {
        $decoded = json_decode((string)file_get_contents($manifestPath), true);
        $manifest = is_array($decoded) ? $decoded : [];
    }
    return moduleUninstallPolicyForManifest($manifest);
}

/**
 * Uninstall a module (remove files + disable).
 * Options:
 *   - purge: drop owned tables (data removal).
 *   - export: export owned tables before purge.
 *   - export_dir: target directory for the export.
 *   - force: bypass disable_safe=false block.
 *   - confirm_purge: explicit operator intent required when the manifest
 *     declares requires_confirmation_to_drop_data.
 */
function uninstallModule(string $moduleId, array $options = []): array
{
    $dir = modulePathForId($moduleId) ?? '';
    if (!is_dir($dir)) {
        return ['ok' => false, 'error' => 'Module not found'];
    }

    $purge = !empty($options['purge']);
    $export = !empty($options['export']);
    $exportDir = is_string($options['export_dir'] ?? null) ? (string)$options['export_dir'] : null;
    $force = !empty($options['force']);
    $confirmPurge = !empty($options['confirm_purge']);

    $manifest = [];
    $manifestPath = $dir . '/module.json';
    if (is_file($manifestPath)) {
        $m = json_decode((string)file_get_contents($manifestPath), true);
        $manifest = is_array($m) ? $m : [];
    }
    $policy = moduleUninstallPolicyForManifest($manifest);

    // Policy: module declares itself unsafe to disable → require force.
    if (!$policy['disable_safe'] && !$force) {
        return ['ok' => false, 'error' => "Module '{$moduleId}' declares disable_safe=false. Re-run with force to uninstall.", 'error_code' => 'uninstall_not_disable_safe'];
    }

    // Policy: export requested but module does not support it → refuse.
    if ($export && !$policy['supports_data_export']) {
        return ['ok' => false, 'error' => "Module '{$moduleId}' does not declare supports_data_export. Re-run without export.", 'error_code' => 'uninstall_export_unsupported'];
    }

    // Policy: purge (data drop) requires explicit confirmation when declared.
    if ($purge && $policy['requires_confirmation_to_drop_data'] && !$confirmPurge) {
        return ['ok' => false, 'error' => "Module '{$moduleId}' requires confirmation to drop owned data. Re-run with confirm_purge=true.", 'error_code' => 'uninstall_purge_requires_confirmation'];
    }

    // Disable first
    disableModule($moduleId);

    $exportResult = null;
    if ($purge && $export) {
        $exportResult = exportModuleOwnedTables($moduleId, $manifest, $exportDir);
        if (empty($exportResult['ok'])) {
            return $exportResult;
        }
    }

    if ($purge) {
        try {
            $pdo = app()->db();
            $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
            $tables = $manifest['owns_tables'] ?? [];

            $dropList = [];
            if (is_array($tables)) {
                foreach ($tables as $t) {
                    if (!is_string($t) || trim($t) === '') continue;
                    $dropList[] = trim($t);
                }
            }

            $dropList = array_values(array_unique($dropList));
            foreach ($dropList as $t) {
                if (!preg_match('/^[A-Za-z0-9_]+$/', $t)) {
                    continue;
                }
                $pdo->exec("DROP TABLE IF EXISTS `{$t}`");
            }

            // Remove migration tracking
            $stmt = $pdo->prepare("DELETE FROM _migrations WHERE module = ?");
            $stmt->execute([$moduleId]);
            $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        } catch (Throwable $e) {
            try {
                app()->db()->exec('SET FOREIGN_KEY_CHECKS=1');
            } catch (Throwable $e2) {
            }
            return ['ok' => false, 'error' => 'Purge failed: ' . $e->getMessage()];
        }
    }

    $modulesRoot = rtrim(modulesPath(), '/');
    $relativeModulePath = str_starts_with($dir, $modulesRoot . '/')
        ? substr($dir, strlen($modulesRoot) + 1)
        : $moduleId;
    $templateDir = BASE_PATH . '/templates/modules/' . $relativeModulePath;

    // Recursively remove
    $it = new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS);
    $files = new \RecursiveIteratorIterator($it, \RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        if ($file->isDir()) {
            @rmdir($file->getRealPath());
        } else {
            @unlink($file->getRealPath());
        }
    }
    @rmdir($dir);

    // Templates mirror the physical module path and are part of the package
    // lifecycle. Remove only that exact module-owned template subtree.
    if (is_link($templateDir)) {
        @unlink($templateDir);
    } elseif (is_dir($templateDir)) {
        $templateIterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($templateDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($templateIterator as $templateEntry) {
            if ($templateEntry->isLink() || $templateEntry->isFile()) {
                @unlink($templateEntry->getPathname());
            } else {
                @rmdir($templateEntry->getPathname());
            }
        }
        @rmdir($templateDir);
    }

    $res = ['ok' => true];
    if (is_array($exportResult)) {
        $res['export'] = $exportResult;
    }
    return $res;
}

/**
 * Update a provider module's capability policy (allow_callers) and validate.
 *
 * This edits module.json on disk in an atomic, validated way and does NOT
 * enable/disable the module. It is intended for admin APIs.
 */
function updateModuleCapabilityPolicy(string $moduleId, string $capabilityId, array $allowCallers): array
{
    $moduleId = trim($moduleId);
    $capabilityId = trim($capabilityId);
    if ($moduleId === '' || $capabilityId === '') {
        return ['ok' => false, 'error' => 'moduleId and capabilityId are required'];
    }

    $manifestPath = moduleManifestPathForId($moduleId);
    if ($manifestPath === null) {
        return ['ok' => false, 'error' => 'Module manifest not found'];
    }

    $raw = file_get_contents($manifestPath);
    $manifest = json_decode((string)$raw, true);
    if (!is_array($manifest)) {
        return ['ok' => false, 'error' => 'Module manifest is not valid JSON'];
    }

    // Normalise allowCallers to a clean list of module ids (lowercase, unique).
    $clean = [];
    foreach ($allowCallers as $id) {
        if (!is_string($id)) {
            continue;
        }
        $id = trim(strtolower($id));
        if ($id === '') {
            continue;
        }
        // Keep same id rules as manifest validation (lowercase, alnum, hyphen)
        if (!preg_match('/^[a-z0-9]([a-z0-9\-]*[a-z0-9])?$/', $id)) {
            continue;
        }
        $clean[$id] = true;
    }
    $allowCallers = array_keys($clean);

    // Safety guardrails for critical capabilities: never allow an admin to
    // accidentally lock out the provider itself or the kernel from calling
    // core kernel capabilities.
    //
    // For kernel.auth.authenticate@1 specifically, we always ensure that:
    // - the provider module (moduleId) can call its own auth provider
    // - the kernel can call it for OS/API login
    if ($capabilityId === 'kernel.auth.authenticate@1') {
        $allowCallers[] = strtolower($moduleId);
        $allowCallers[] = 'kernel';
        // De-duplicate while preserving normalised ids
        $allowCallers = array_values(array_unique($allowCallers));
    }

    if (!isset($manifest['capabilities']) || !is_array($manifest['capabilities'])) {
        $manifest['capabilities'] = [];
    }
    if (!isset($manifest['capabilities']['policy']) || !is_array($manifest['capabilities']['policy'])) {
        $manifest['capabilities']['policy'] = [];
    }
    if (!isset($manifest['capabilities']['policy']['capabilities']) || !is_array($manifest['capabilities']['policy']['capabilities'])) {
        $manifest['capabilities']['policy']['capabilities'] = [];
    }

    $capPolicies = $manifest['capabilities']['policy']['capabilities'];
    $capPolicy = $capPolicies[$capabilityId] ?? [];
    if (!is_array($capPolicy)) {
        $capPolicy = [];
    }

    $capPolicy['allow_callers'] = $allowCallers;
    $capPolicies[$capabilityId] = $capPolicy;
    $manifest['capabilities']['policy']['capabilities'] = $capPolicies;

    // Validate resulting capability manifest using existing validator.
    $capCheck = validateModuleCapabilities($manifest);
    if (empty($capCheck['ok'])) {
        return [
            'ok' => false,
            'error' => $capCheck['error'] ?? 'Capability manifest validation failed',
        ];
    }

    // Atomic write: write to temp file then rename over original.
    $tmpPath = $manifestPath . '.tmp';
    $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return ['ok' => false, 'error' => 'Failed to encode manifest JSON'];
    }
    if (file_put_contents($tmpPath, $json) === false) {
        return ['ok' => false, 'error' => 'Failed to write temporary manifest'];
    }
    if (!@rename($tmpPath, $manifestPath)) {
        @unlink($tmpPath);
        return ['ok' => false, 'error' => 'Failed to persist manifest changes'];
    }

    return ['ok' => true, 'module_id' => $moduleId, 'capability_id' => $capabilityId, 'allow_callers' => $allowCallers];
}

/**
 * Update a caller module's capabilities.depends list and validate.
 *
 * This edits module.json on disk in an atomic, validated way and does NOT
 * enable/disable the module. It is intended for admin APIs.
 *
 * @param string   $moduleId Module id whose manifest should be updated
 * @param string[] $depends  List of capability ids the module depends on
 */
function updateModuleCapabilityDepends(string $moduleId, array $depends): array
{
    $moduleId = trim($moduleId);
    if ($moduleId === '') {
        return ['ok' => false, 'error' => 'moduleId is required'];
    }

    $manifestPath = moduleManifestPathForId($moduleId);
    if ($manifestPath === null) {
        return ['ok' => false, 'error' => 'Module manifest not found'];
    }

    $raw = file_get_contents($manifestPath);
    $manifest = json_decode((string)$raw, true);
    if (!is_array($manifest)) {
        return ['ok' => false, 'error' => 'Module manifest is not valid JSON'];
    }

    // Normalise depends to a clean list of capability ids.
    $clean = [];
    foreach ($depends as $capId) {
        if (!is_string($capId)) {
            continue;
        }
        $capId = trim($capId);
        if ($capId === '') {
            continue;
        }
        if (!isValidCapabilityId($capId)) {
            continue;
        }
        $clean[$capId] = true;
    }
    $depends = array_values(array_keys($clean));

    if (!isset($manifest['capabilities']) || !is_array($manifest['capabilities'])) {
        $manifest['capabilities'] = [];
    }
    $manifest['capabilities']['depends'] = $depends;

    // Validate resulting capability manifest using existing validator.
    $capCheck = validateModuleCapabilities($manifest);
    if (empty($capCheck['ok'])) {
        return [
            'ok' => false,
            'error' => $capCheck['error'] ?? 'Capability manifest validation failed',
        ];
    }

    // Atomic write: write to temp file then rename over original.
    $tmpPath = $manifestPath . '.tmp';
    $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return ['ok' => false, 'error' => 'Failed to encode manifest JSON'];
    }
    if (file_put_contents($tmpPath, $json) === false) {
        return ['ok' => false, 'error' => 'Failed to write temporary manifest'];
    }
    if (!@rename($tmpPath, $manifestPath)) {
        @unlink($tmpPath);
        return ['ok' => false, 'error' => 'Failed to persist manifest changes'];
    }

    return ['ok' => true, 'module_id' => $moduleId, 'depends' => $depends];
}

// ─── Read Contract Registry Integration ───────────────────────────────────

/**
 * Register read contracts and deprecated reads for all enabled modules.
 * Called from getEnabledModules() after capability validation passes.
 *
 * @param array<string, array<string, mixed>> $enabledModules
 */
function kernelRegisterModuleReadContracts(array $enabledModules): void
{
    $registry = \Ikabud\Kernel\Contracts\ReadContractRegistry::getInstance();

    \Ikabud\Kernel\Database\KernelPDO::kernelEscalationEnter();
    try {
        $db = app()->db();

        foreach ($enabledModules as $moduleId => $manifest) {
            // Register read contracts from reads_tables
            $readsTables = is_array($manifest['reads_tables'] ?? null) ? $manifest['reads_tables'] : [];
            foreach ($readsTables as $tableName) {
                if (is_string($tableName) && trim($tableName) !== '') {
                    $registry->registerReadContract($moduleId, trim($tableName), $db);
                }
            }

            // Register deprecated reads from reads_tables_deprecated
            $deprecatedReads = is_array($manifest['reads_tables_deprecated'] ?? null) ? $manifest['reads_tables_deprecated'] : [];
            foreach ($deprecatedReads as $tableName) {
                if (is_string($tableName) && trim($tableName) !== '') {
                    $registry->markDeprecatedRead($moduleId, trim($tableName));
                }
            }
        }
    } catch (\Throwable $e) {
        if (function_exists('write_log')) {
            write_log(
                'ReadContractRegistry: failed to register read contracts: ' . $e->getMessage(),
                'warning',
                ['exception' => get_class($e)]
            );
        }
    } finally {
        \Ikabud\Kernel\Database\KernelPDO::kernelEscalationLeave();
    }
}

/**
 * Check for schema drift in registered read contracts.
 * Called from loadModuleRoutes() after all modules are loaded.
 * Logs warnings for drift; does not throw or crash.
 */
function kernelCheckReadContractDrift(): void
{
    $registry = \Ikabud\Kernel\Contracts\ReadContractRegistry::getInstance();

    \Ikabud\Kernel\Database\KernelPDO::kernelEscalationEnter();
    try {
        $db = app()->db();
        $registry->checkDrift($db);
    } catch (\Throwable $e) {
        if (function_exists('write_log')) {
            write_log(
                'ReadContractRegistry: drift check failed: ' . $e->getMessage(),
                'warning',
                ['exception' => get_class($e)]
            );
        }
    } finally {
        \Ikabud\Kernel\Database\KernelPDO::kernelEscalationLeave();
    }
}
