<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Workbench\Contracts;

final class WorkbenchTestContractMigrator
{
    /** @return array<string,mixed> */
    public function migrate(string $modulePath): array
    {
        $manifest = $this->json($modulePath . '/module.json');
        $legacyDocument = is_file($modulePath . '/test-contract.json')
            ? $this->json($modulePath . '/test-contract.json') : [];
        $legacy = is_array($legacyDocument['test_contract'] ?? null) ? $legacyDocument['test_contract'] : [];
        $moduleId = (string) ($manifest['id'] ?? $legacy['module'] ?? basename($modulePath));
        $routes = $this->routes($modulePath . '/routes.php');
        $capabilities = array_values(array_filter(array_map(
            static fn($cap): string => is_array($cap) ? (string) ($cap['id'] ?? '') : '',
            is_array($manifest['capabilities']['exposes'] ?? null) ? $manifest['capabilities']['exposes'] : []
        )));
        sort($capabilities);
        $roles = array_values(array_unique(array_map('strval', is_array($legacy['roles'] ?? null) ? $legacy['roles'] : ['admin'])));
        sort($roles);
        $workflows = [];
        foreach ((array) ($legacy['workflows'] ?? []) as $id => $states) {
            $workflows[] = ['id' => (string) $id, 'states' => array_values(array_map('strval', (array) $states)), 'transitions' => []];
        }
        usort($workflows, static fn(array $a, array $b): int => $a['id'] <=> $b['id']);
        $pages = [];
        foreach ((array) ($legacy['page_families'] ?? []) as $family) {
            $pages[] = ['id' => (string) $family, 'family' => (string) $family, 'roles' => $roles, 'required_components' => []];
        }
        if ($pages === []) {
            foreach ($routes['GET'] as $route) {
                if (str_contains($route, '/api/')) continue;
                $pages[] = ['id' => $this->routeId('page', $route), 'family' => 'module-page', 'route' => $route, 'roles' => $roles, 'required_components' => []];
            }
        }
        $actions = [];
        foreach ($routes['POST'] as $route) {
            $actions[] = [
                'id' => $this->routeId('action', $route),
                'route' => $route,
                'method' => 'POST',
                'requires' => ['authenticated' => true, 'tenant_scoped' => true],
                'effects' => [
                    ['step' => 'http.request', 'category' => 'http'],
                    ['step' => 'authorization.checked', 'category' => 'capability'],
                    ['step' => 'state.changed-or-rejected', 'category' => 'service'],
                    ['step' => 'http.response', 'category' => 'http'],
                ],
            ];
        }
        $scenarios = [];
        if ($pages !== []) {
            $scenarios[] = ['id' => 'navigation-smoke', 'description' => 'Owned pages resolve for declared actors and tenants', 'actions' => []];
        }
        $profile = $manifest['application_profile'] ?? ($legacy['application_profile'] ?? 'ark.workbench');
        if (is_array($profile)) $profile = $profile['id'] ?? 'ark.workbench';
        $requiredTests = array_values(array_unique(array_map('strval', (array) ($legacy['required_tests'] ?? ['contract', 'routes', 'ui-smoke']))));
        sort($requiredTests);

        return [
            'schema' => WorkbenchTestContract::SCHEMA,
            'contract_version' => WorkbenchTestContract::VERSION,
            'module' => [
                'id' => $moduleId,
                'version' => (string) ($manifest['version'] ?? $legacy['version'] ?? '0.0.0'),
                'application_profile' => (string) $profile,
            ],
            'ownership' => [
                'routes' => $routes,
                'navigation_dependencies' => array_values((array) ($manifest['navigation_dependencies'] ?? [])),
                'tables' => array_values(array_map('strval', (array) ($manifest['owns_tables'] ?? []))),
                'capabilities' => $capabilities,
                'events' => array_values(array_filter(array_map(static fn($event): string => is_array($event) ? (string) ($event['key'] ?? '') : (string) $event, (array) ($manifest['events'] ?? [])))),
            ],
            'actors' => array_map(static fn(string $role): array => ['id' => $role, 'capabilities' => []], $roles),
            'tenancy' => ['mode' => 'tenant', 'isolation_invariants' => ['records are scoped to the active tenant']],
            'pages' => $pages,
            'workflows' => $workflows,
            'actions' => $actions,
            'invariants' => [[
                'id' => 'navigation-routes-resolve',
                'severity' => 'critical',
                'scope' => 'navigation',
                'description' => 'Every declared internal navigation URL resolves to an owned or explicitly allowed GET route.',
            ]],
            'scenarios' => $scenarios,
            'environments' => ['browsers' => ['chromium'], 'viewports' => ['desktop', 'mobile']],
            'evidence' => [
                'required_identity_fields' => ['module', 'action', 'step', 'tenant', 'role', 'environment', 'outcome'],
                'outcomes' => ['passed', 'failed', 'blocked', 'skipped', 'censored'],
            ],
            'gates' => ['required' => $requiredTests, 'release_blocker_severities' => ['critical', 'major']],
            'test_files' => [
                'php' => array_values(array_map('strval', (array) ($legacy['test_files']['php'] ?? []))),
                'browser' => array_values(array_map('strval', (array) ($legacy['test_files']['browser'] ?? []))),
            ],
            'compatibility' => [
                'migrated_from' => $legacy === [] ? null : 'test-contract.json',
                'minimum_workbench' => '1.0.0',
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function json(string $path): array
    {
        if (!is_file($path)) return [];
        $data = json_decode((string) file_get_contents($path), true);
        return is_array($data) ? $data : [];
    }

    /** @return array<string,list<string>> */
    private function routes(string $path): array
    {
        $routes = ['GET' => [], 'POST' => [], 'PUT' => [], 'PATCH' => [], 'DELETE' => []];
        if (!is_file($path)) return $routes;
        $loaded = include $path;
        if (!is_array($loaded)) return $routes;
        foreach ($routes as $method => $_) {
            $routes[$method] = array_keys(is_array($loaded[$method] ?? null) ? $loaded[$method] : []);
            sort($routes[$method]);
        }
        return $routes;
    }

    private function routeId(string $prefix, string $route): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower(preg_replace('/\{[^}]+\}/', 'item', $route))), '-');
        return $prefix . '-' . ($slug !== '' ? $slug : substr(hash('sha256', $route), 0, 10));
    }
}
