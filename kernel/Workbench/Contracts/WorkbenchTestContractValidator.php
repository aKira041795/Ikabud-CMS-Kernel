<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Workbench\Contracts;

final class WorkbenchTestContractValidator
{
    /** @param array<string,mixed> $contract @return array<string,mixed> */
    public function validate(array $contract, string $modulePath, string $projectRoot): array
    {
        $errors = [];
        $warnings = [];
        $checks = [];
        $check = static function (bool $ok, string $id, string $detail, bool $warning = false) use (&$errors, &$warnings, &$checks): void {
            $checks[] = ['id' => $id, 'passed' => $ok, 'detail' => $detail, 'severity' => $warning ? 'warning' : 'error'];
            if (!$ok && $warning) $warnings[] = ['code' => $id, 'message' => $detail];
            if (!$ok && !$warning) $errors[] = ['code' => $id, 'message' => $detail];
        };

        $check(($contract['schema'] ?? null) === WorkbenchTestContract::SCHEMA, 'schema', 'schema must be ' . WorkbenchTestContract::SCHEMA);
        $check(preg_match('/^1\./', (string) ($contract['contract_version'] ?? '')) === 1, 'contract-version', 'contract_version must be compatible with v1');
        $manifest = $this->json($modulePath . '/module.json');
        $moduleId = (string) ($contract['module']['id'] ?? '');
        $check($moduleId !== '', 'module-id', 'module.id is required');
        $check($moduleId !== '' && $moduleId === (string) ($manifest['id'] ?? ''), 'module-identity', 'contract module.id must match module.json');
        foreach (['ownership', 'actors', 'tenancy', 'pages', 'workflows', 'actions', 'invariants', 'scenarios', 'environments', 'evidence', 'gates', 'test_files'] as $field) {
            $check(array_key_exists($field, $contract), 'field-' . $field, "{$field} is required");
        }
        $requiredIdentity = (array) ($contract['evidence']['required_identity_fields'] ?? []);
        foreach (['module', 'action', 'step', 'tenant', 'role', 'environment', 'outcome'] as $field) {
            $check(in_array($field, $requiredIdentity, true), 'identity-' . $field, "evidence identity requires {$field}");
        }
        $outcomes = (array) ($contract['evidence']['outcomes'] ?? []);
        $check(in_array('censored', $outcomes, true), 'censored-outcome', 'evidence outcomes must preserve censored results');

        $actualRoutes = $this->routes($modulePath . '/routes.php');
        foreach ((array) ($contract['ownership']['routes'] ?? []) as $method => $paths) {
            foreach ((array) $paths as $path) {
                $found = in_array($this->normalizeRoute((string) $path), array_map([$this, 'normalizeRoute'], $actualRoutes[strtoupper((string) $method)] ?? []), true);
                $check($found, 'route-claim', strtoupper((string) $method) . " {$path} must exist in module routes");
            }
        }
        $exposed = array_values(array_filter(array_map(static fn($cap): string => is_array($cap) ? (string) ($cap['id'] ?? '') : '', (array) ($manifest['capabilities']['exposes'] ?? []))));
        foreach ((array) ($contract['ownership']['capabilities'] ?? []) as $capability) {
            $check(in_array($capability, $exposed, true), 'capability-claim', "{$capability} must be exposed by module.json");
        }
        foreach (['php', 'browser'] as $kind) {
            foreach ((array) ($contract['test_files'][$kind] ?? []) as $file) {
                $check(is_file(rtrim($projectRoot, '/') . '/' . ltrim((string) $file, '/')), 'test-file', "{$file} must exist");
            }
        }
        $ids = [];
        foreach (['actors', 'pages', 'workflows', 'actions', 'invariants', 'scenarios'] as $collection) {
            foreach ((array) ($contract[$collection] ?? []) as $item) {
                $id = is_array($item) ? trim((string) ($item['id'] ?? '')) : '';
                $check($id !== '', 'item-id', "{$collection} entries require an id");
                if ($id !== '') {
                    $key = $collection . ':' . $id;
                    $check(!isset($ids[$key]), 'unique-id', "{$key} must be unique");
                    $ids[$key] = true;
                }
            }
        }
        $passed = count(array_filter($checks, static fn(array $c): bool => $c['passed']));
        return [
            'ok' => $errors === [],
            'schema' => WorkbenchTestContract::SCHEMA,
            'module' => $moduleId,
            'compatibility' => ['status' => ($contract['schema'] ?? '') === WorkbenchTestContract::SCHEMA ? 'compatible' : 'incompatible', 'supported_major' => 1],
            'score' => ['passed' => $passed, 'total' => count($checks)],
            'errors' => $errors,
            'warnings' => $warnings,
            'checks' => $checks,
        ];
    }

    /** @return array<string,mixed> */
    private function json(string $path): array
    {
        $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : [];
        return is_array($data) ? $data : [];
    }

    /** @return array<string,list<string>> */
    private function routes(string $path): array
    {
        $routes = ['GET' => [], 'POST' => [], 'PUT' => [], 'PATCH' => [], 'DELETE' => []];
        if (!is_file($path)) return $routes;
        $loaded = include $path;
        if (!is_array($loaded)) return $routes;
        foreach ($routes as $method => $_) $routes[$method] = array_keys(is_array($loaded[$method] ?? null) ? $loaded[$method] : []);
        return $routes;
    }

    private function normalizeRoute(string $route): string
    {
        return (string) preg_replace('/(?:\{[^}]+\}|:[A-Za-z_][A-Za-z0-9_]*)/', '{param}', rtrim($route, '/') ?: '/');
    }
}
