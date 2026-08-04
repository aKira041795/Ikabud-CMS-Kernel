<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/kernel/Contracts/DiagnosticSeverity.php';

use Ikabud\Kernel\Contracts\DiagnosticSeverity;

const MODULE_MANIFEST_SCHEMA_VERSION = '1';

/**
 * @return array{severity:string,code:string,rule:string,field:string,message:string,correction:string}
 */
function moduleManifestDiagnostic(
    DiagnosticSeverity $severity,
    string $code,
    string $rule,
    string $field,
    string $message,
    string $correction
): array {
    return [
        'severity' => $severity->value,
        'code' => $code,
        'rule' => $rule,
        'field' => $field,
        'message' => $message,
        'correction' => $correction,
    ];
}

function moduleManifestCapabilityIdIsValid(string $id): bool
{
    return preg_match('/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)*@\d+$/', $id) === 1;
}

/** @return array<int,array<string,string>> */
function validateModuleEventDeclarationsV1(mixed $events): array
{
    if (!is_array($events) || !array_is_list($events)) {
        return [moduleManifestDiagnostic(DiagnosticSeverity::Fatal, 'manifest_invalid_events', 'manifest.v1.events-list', '/events', 'events must be a list of event declaration objects.', 'Declare events as [{"key":"module.entity.changed"}].')];
    }

    $diagnostics = [];
    foreach ($events as $index => $event) {
        if (!is_array($event) || !is_string($event['key'] ?? null) || trim((string)$event['key']) === '') {
            $diagnostics[] = moduleManifestDiagnostic(DiagnosticSeverity::Fatal, 'manifest_invalid_event', 'manifest.v1.events-entry', "/events/{$index}", 'Each event declaration must be an object with a non-empty key.', 'Replace the entry with {"key":"module.entity.changed"}.');
        }
    }
    return $diagnostics;
}

/**
 * Canonical module manifest schema-v1 validator.
 *
 * @param array<string,mixed> $manifest
 * @param array{module_path?:string} $context
 * @return array{schema_version:string,ok:bool,certifiable:bool,manifest:array<string,mixed>,diagnostics:array<int,array<string,string>>}
 */
function validateModuleManifestV1(array $manifest, array $context = []): array
{
    $diagnostics = [];
    $fatal = static function (string $code, string $rule, string $field, string $message, string $correction) use (&$diagnostics): void {
        $diagnostics[] = moduleManifestDiagnostic(DiagnosticSeverity::Fatal, $code, $rule, $field, $message, $correction);
    };

    foreach (['id', 'name', 'version'] as $field) {
        if (!isset($manifest[$field]) || !is_string($manifest[$field]) || trim($manifest[$field]) === '') {
            $fatal(
                'manifest_missing_required_field',
                'manifest.v1.required.' . $field,
                '/' . $field,
                "module.json requires a non-empty string field '{$field}'.",
                "Add a non-empty string '{$field}' field."
            );
        }
    }

    $id = is_string($manifest['id'] ?? null) ? trim($manifest['id']) : '';
    if ($id !== '' && (strlen($id) > 64 || preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $id) !== 1)) {
        $fatal('manifest_invalid_id', 'manifest.v1.id', '/id', 'Module id must be at most 64 lowercase alphanumeric or hyphen characters.', 'Use a kebab-case id such as daily-ledger.');
    }

    $version = is_string($manifest['version'] ?? null) ? trim($manifest['version']) : '';
    if ($version !== '' && preg_match('/^\d+\.\d+\.\d+(?:[-+][A-Za-z0-9.\-]+)?$/', $version) !== 1) {
        $fatal('manifest_invalid_version', 'manifest.v1.version', '/version', 'Module version must follow semantic versioning.', 'Use a version such as 1.0.0 or 1.0.0-beta.1.');
    }

    foreach (['owns_tables', 'co_owns_tables', 'reads_tables', 'requires_tables'] as $field) {
        if (!array_key_exists($field, $manifest)) {
            continue;
        }
        if (!is_array($manifest[$field])) {
            $fatal('manifest_invalid_table_list', 'manifest.v1.table-list', '/' . $field, "{$field} must be an array of table names.", "Declare '{$field}' as an array, including an empty array for no tables.");
            continue;
        }
        foreach ($manifest[$field] as $index => $table) {
            if (!is_string($table) || preg_match('/^[A-Za-z0-9_]+$/', $table) !== 1) {
                $fatal('manifest_invalid_table_list', 'manifest.v1.table-list', "/{$field}/{$index}", "{$field} entries must be non-empty SQL table identifiers.", 'Replace the entry with a table name containing only letters, digits, and underscores.');
            }
        }
    }

    if (array_key_exists('routes', $manifest)) {
        $routes = $manifest['routes'];
        $validRoutesShape = is_bool($routes) || is_string($routes) || (is_array($routes) && $routes === []);
        if (!$validRoutesShape || (is_string($routes) && trim($routes) === '')) {
            $fatal('manifest_invalid_routes', 'manifest.v1.routes', '/routes', 'routes must be true, false, a route-file path, or an empty array.', "Use true for the conventional routes.php file, false or [] for no routes, or a relative PHP file path.");
        } else {
            $modulePath = rtrim((string)($context['module_path'] ?? ''), '/');
            $routeFile = $routes === true ? 'routes.php' : (is_string($routes) ? ltrim($routes, '/') : '');
            if (is_string($routes) && (
                str_starts_with($routes, '/')
                || preg_match('#(^|/)\.\.(/|$)#', str_replace('\\', '/', $routes)) === 1
                || str_contains($routes, '\\')
                || preg_match('/^[A-Za-z]:/', $routes) === 1
            )) {
                $fatal('manifest_invalid_routes_path', 'manifest.v1.routes.relative-path', '/routes', 'routes file paths must stay inside the module directory.', "Use a relative path such as routes.php or config/routes.php without '..' segments or backslashes.");
            } elseif ($modulePath !== '' && $routeFile !== '' && !is_file($modulePath . '/' . $routeFile)) {
                $fatal('routes_file_missing', 'manifest.v1.routes.file', '/routes', "Declared route file '{$routeFile}' does not exist.", "Create '{$routeFile}' in the module directory or declare routes as false/[] for a route-less module.");
            }
        }
    }

    if (array_key_exists('capabilities', $manifest)) {
        $caps = $manifest['capabilities'];
        if (!is_array($caps)) {
            $fatal('capabilities_invalid', 'manifest.v1.capabilities', '/capabilities', 'capabilities must be an object.', 'Declare capabilities with exposes and depends arrays.');
        } else {
            foreach (['exposes', 'depends'] as $field) {
                if (isset($caps[$field]) && !is_array($caps[$field])) {
                    $fatal('capabilities_invalid', 'manifest.v1.capabilities.' . $field, '/capabilities/' . $field, "capabilities.{$field} must be an array.", "Declare capabilities.{$field} as an array.");
                }
            }
            foreach (is_array($caps['depends'] ?? null) ? $caps['depends'] : [] as $index => $dependency) {
                if (!is_string($dependency) || !moduleManifestCapabilityIdIsValid($dependency)) {
                    $fatal('capabilities_invalid', 'manifest.v1.capabilities.depends-entry', "/capabilities/depends/{$index}", 'Capability dependencies must be versioned capability-id strings.', 'Use a capability id such as module.action@1.');
                }
            }
            foreach (is_array($caps['exposes'] ?? null) ? $caps['exposes'] : [] as $index => $expose) {
                if (!is_array($expose)) {
                    $fatal('capabilities_invalid', 'manifest.v1.capabilities.expose-object', "/capabilities/exposes/{$index}", 'Capability exposes must be objects.', 'Replace the string with an object containing at least an id field.');
                    continue;
                }
                $capId = $expose['id'] ?? null;
                if (!is_string($capId) || !moduleManifestCapabilityIdIsValid($capId)) {
                    $fatal('capabilities_invalid', 'manifest.v1.capabilities.expose-id', "/capabilities/exposes/{$index}/id", 'Capability expose id must be a valid versioned capability id.', 'Use a capability id such as module.action@1.');
                }
                if (isset($expose['modes'])) {
                    if (!is_array($expose['modes']) || array_filter($expose['modes'], static fn ($mode): bool => !is_string($mode) || !in_array(strtolower($mode), ['first', 'pipeline', 'fanout'], true)) !== []) {
                        $fatal('capabilities_invalid', 'manifest.v1.capabilities.modes', "/capabilities/exposes/{$index}/modes", 'Capability modes must contain only first, pipeline, or fanout.', 'Declare modes as an array containing supported call modes.');
                    }
                }
            }
        }
    }

    if (array_key_exists('events', $manifest)) {
        foreach (validateModuleEventDeclarationsV1($manifest['events']) as $eventDiagnostic) {
            $diagnostics[] = $eventDiagnostic;
        }
    }

    $modulePath = rtrim((string)($context['module_path'] ?? ''), '/');
    if ($modulePath !== '' && $id !== '' && basename($modulePath) !== $id) {
        $diagnostics[] = moduleManifestDiagnostic(
            DiagnosticSeverity::Advisory,
            'manifest_folder_id_mismatch',
            'manifest.v1.folder-id-advisory',
            '/id',
            "Module folder '" . basename($modulePath) . "' differs from manifest id '{$id}'.",
            'Prefer matching folder and manifest ids for new modules; preserve established paths when renaming would break compatibility.'
        );
    }

    $fatalCount = count(array_filter($diagnostics, static fn (array $d): bool => $d['severity'] === DiagnosticSeverity::Fatal->value));
    $blockerCount = count(array_filter($diagnostics, static fn (array $d): bool => $d['severity'] === DiagnosticSeverity::CertificationBlocker->value));

    return [
        'schema_version' => MODULE_MANIFEST_SCHEMA_VERSION,
        'ok' => $fatalCount === 0,
        'certifiable' => $fatalCount === 0 && $blockerCount === 0,
        'manifest' => $manifest,
        'diagnostics' => $diagnostics,
    ];
}

/** @return array<string,mixed> */
function validateModuleManifestFileV1(string $path, array $context = []): array
{
    if (!is_file($path)) {
        $diagnostic = moduleManifestDiagnostic(DiagnosticSeverity::Fatal, 'manifest_not_found', 'manifest.v1.file', '/', 'module.json not found.', 'Create module.json at the module root.');
        return ['schema_version' => MODULE_MANIFEST_SCHEMA_VERSION, 'ok' => false, 'certifiable' => false, 'manifest' => [], 'diagnostics' => [$diagnostic]];
    }
    try {
        $manifest = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        $diagnostic = moduleManifestDiagnostic(DiagnosticSeverity::Fatal, 'manifest_invalid_json', 'manifest.v1.json', '/', 'module.json is not valid JSON: ' . $e->getMessage(), 'Correct the JSON syntax and rerun the strict manifest guard.');
        return ['schema_version' => MODULE_MANIFEST_SCHEMA_VERSION, 'ok' => false, 'certifiable' => false, 'manifest' => [], 'diagnostics' => [$diagnostic]];
    }
    if (!is_array($manifest)) {
        $diagnostic = moduleManifestDiagnostic(DiagnosticSeverity::Fatal, 'manifest_invalid_json_root', 'manifest.v1.json-object', '/', 'module.json root must be an object.', 'Replace the JSON root with an object.');
        return ['schema_version' => MODULE_MANIFEST_SCHEMA_VERSION, 'ok' => false, 'certifiable' => false, 'manifest' => [], 'diagnostics' => [$diagnostic]];
    }
    if (!array_key_exists('module_path', $context)) {
        $context['module_path'] = !empty($context['check_filesystem']) || !array_key_exists('check_filesystem', $context)
            ? dirname($path)
            : '';
    }
    return validateModuleManifestV1($manifest, $context);
}

/** @return string[] */
function moduleManifestFilesV1(string $modulesRoot): array
{
    if (!is_dir($modulesRoot)) {
        return [];
    }
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($modulesRoot, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getFilename() === 'module.json' && !str_contains($file->getPathname(), '/node_modules/')) {
            $files[] = $file->getPathname();
        }
    }
    sort($files);
    return $files;
}

function validateModuleManifestForGuardV1(string $path): array
{
    return validateModuleManifestFileV1($path);
}

/** @return array<int,array<string,string>> */
function validateModuleManifestArchitecturePoliciesV1(array $manifest): array
{
    $diagnostics = [];

    $depends = $manifest['capabilities']['depends'] ?? [];
    if (is_array($depends)) {
        foreach ($depends as $index => $dependency) {
            if (!is_string($dependency)) {
                continue;
            }
            if ($dependency === 'kernel.auth.authenticate@1') {
                $diagnostics[] = moduleManifestDiagnostic(
                    DiagnosticSeverity::CertificationBlocker,
                    'manifest_arch_dependency_overreach',
                    'manifest.arch.depends.kernel-auth-authenticate',
                    '/capabilities/depends/' . (string)$index,
                    'Do not depend on kernel.auth.authenticate@1 in capabilities.depends; it can pull large transitive module trees during tenant provisioning.',
                    'Remove this dependency and call kernel auth APIs directly (for example app()->auth()) or depend only on true inter-module contracts.'
                );
            }
        }
    }

    $authOwned = $manifest['auth_owned'] ?? null;
    if (is_array($authOwned)) {
        $idColumn = trim((string)($authOwned['id_column'] ?? ''));
        if ($idColumn === '') {
            $diagnostics[] = moduleManifestDiagnostic(
                DiagnosticSeverity::CertificationBlocker,
                'manifest_arch_auth_owned_missing_id_column',
                'manifest.arch.auth-owned.id-column',
                '/auth_owned/id_column',
                'auth_owned.id_column is required for tenant admin password-push/update flows.',
                'Set auth_owned.id_column to the primary key column used by the module users table (for example user_id).'
            );
        }

        $roleColumn = trim((string)($authOwned['role_column'] ?? ''));
        if ($roleColumn === '') {
            $diagnostics[] = moduleManifestDiagnostic(
                DiagnosticSeverity::CertificationBlocker,
                'manifest_arch_auth_owned_missing_role_column',
                'manifest.arch.auth-owned.role-column',
                '/auth_owned/role_column',
                'auth_owned.role_column is required for tenant admin password-push/update role filtering.',
                'Set auth_owned.role_column to the role column in the module users table (for example role).'
            );
        }
    }

    return $diagnostics;
}

function validateModuleManifestForArchitectureV1(string $path): array
{
    $result = validateModuleManifestFileV1($path);
    $manifest = is_array($result['manifest'] ?? null) ? $result['manifest'] : [];
    foreach (validateModuleManifestArchitecturePoliciesV1($manifest) as $diagnostic) {
        $result['diagnostics'][] = $diagnostic;
    }

    $fatalCount = count(array_filter(
        $result['diagnostics'],
        static fn (array $d): bool => ($d['severity'] ?? '') === DiagnosticSeverity::Fatal->value
    ));
    $blockerCount = count(array_filter(
        $result['diagnostics'],
        static fn (array $d): bool => ($d['severity'] ?? '') === DiagnosticSeverity::CertificationBlocker->value
    ));

    $result['ok'] = $fatalCount === 0;
    $result['certifiable'] = $fatalCount === 0 && $blockerCount === 0;

    return $result;
}
