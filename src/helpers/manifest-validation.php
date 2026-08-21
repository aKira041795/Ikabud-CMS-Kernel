<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/kernel/Contracts/DiagnosticSeverity.php';

use Ikabud\Kernel\Contracts\DiagnosticSeverity;

const MODULE_MANIFEST_SCHEMA_VERSION = '1';

// Product Suite & Extension Contract — additive schema-v2 layer.
// The base schema version stays at '1' for backward compatibility. Suite
// contract fields are optional; when present they are validated strictly.
const MODULE_SUITE_CONTRACT_SCHEMA_VERSION = '2';

const MODULE_KIND_PRODUCT_CORE = 'product-core';
const MODULE_KIND_EXTENSION = 'extension';
const MODULE_KIND_ADAPTER = 'adapter';
const MODULE_KIND_PROFILE = 'profile';
const MODULE_KIND_SERVICE = 'service';
const MODULE_KIND_INTEGRATION = 'integration';
const MODULE_KIND_STANDALONE = 'standalone-application';

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

    if (array_key_exists('icon', $manifest)) {
        $icon = is_string($manifest['icon']) ? trim($manifest['icon']) : '';
        if ($icon === '' || preg_match('/^[a-z0-9][a-z0-9-]*$/', $icon) !== 1) {
            $fatal('manifest_invalid_icon', 'manifest.v1.icon', '/icon', "module.json field 'icon' must be a non-empty kebab-case string when provided.", 'Use a kebab-case icon name such as palette or box.');
        }
    }

    if (array_key_exists('kernel_companion', $manifest)) {
        if (!is_bool($manifest['kernel_companion'])) {
            $fatal('manifest_invalid_kernel_companion', 'manifest.v1.kernel-companion', '/kernel_companion', "module.json field 'kernel_companion' must be a boolean when provided.", 'Use true or false.');
        }
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

    // Product suite contract (additive schema-v2 layer). Only validates fields
    // that are present, so existing schema-v1 manifests remain valid.
    foreach (validateModuleSuiteContractV1($manifest) as $suiteDiagnostic) {
        $diagnostics[] = $suiteDiagnostic;
    }
    $fatalCount = count(array_filter($diagnostics, static fn (array $d): bool => $d['severity'] === DiagnosticSeverity::Fatal->value));
    $blockerCount = count(array_filter($diagnostics, static fn (array $d): bool => $d['severity'] === DiagnosticSeverity::CertificationBlocker->value));

    return [
        'schema_version' => MODULE_MANIFEST_SCHEMA_VERSION,
        'suite_contract_version' => MODULE_SUITE_CONTRACT_SCHEMA_VERSION,
        'ok' => $fatalCount === 0,
        'certifiable' => $fatalCount === 0 && $blockerCount === 0,
        'manifest' => $manifest,
        'diagnostics' => $diagnostics,
    ];
}

/**
 * All valid module `kind` values for the product suite contract.
 *
 * @return string[]
 */
function moduleManifestValidKinds(): array
{
    return [
        MODULE_KIND_PRODUCT_CORE,
        MODULE_KIND_EXTENSION,
        MODULE_KIND_ADAPTER,
        MODULE_KIND_PROFILE,
        MODULE_KIND_SERVICE,
        MODULE_KIND_INTEGRATION,
        MODULE_KIND_STANDALONE,
    ];
}

/**
 * Resolve a module's kind, with legacy fallbacks so schema-v1 manifests
 * are interpreted consistently without declaring `kind`.
 */
function moduleManifestKindFromManifest(array $manifest): string
{
    $kind = trim((string)($manifest['kind'] ?? ''));
    if ($kind !== '' && in_array($kind, moduleManifestValidKinds(), true)) {
        return $kind;
    }
    // Legacy inference: profile bundles declare installs.
    if (is_array($manifest['installs'] ?? null) && $manifest['installs'] !== []) {
        return MODULE_KIND_PROFILE;
    }
    // Legacy inference: anything extending a host is an extension.
    if (is_string($manifest['extends'] ?? null) && trim((string)$manifest['extends']) !== '') {
        return MODULE_KIND_EXTENSION;
    }
    return MODULE_KIND_STANDALONE;
}

/**
 * Validate the product suite contract fields of a single manifest.
 *
 * Additive: only fields that are present are validated. This keeps every
 * existing schema-v1 manifest valid while enforcing the v2 contract when
 * modules opt in.
 *
 * @param array<string,mixed> $manifest
 * @return array<int,array<string,string>>
 */
function validateModuleSuiteContractV1(array $manifest): array
{
    $diagnostics = [];
    $fatal = static function (string $code, string $rule, string $field, string $message, string $correction) use (&$diagnostics): void {
        $diagnostics[] = moduleManifestDiagnostic(DiagnosticSeverity::Fatal, $code, $rule, $field, $message, $correction);
    };

    // ── suite ────────────────────────────────────────────────────────────
    if (array_key_exists('suite', $manifest)) {
        $suite = $manifest['suite'];
        if (!is_string($suite) || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', trim($suite)) !== 1) {
            $fatal('suite_invalid_id', 'manifest.v2.suite', '/suite', 'suite must be a non-empty kebab-case identifier.', 'Use a kebab-case suite id such as cms-akira or pal.');
        }
    }

    // ── kind ─────────────────────────────────────────────────────────────
    if (array_key_exists('kind', $manifest)) {
        $kind = $manifest['kind'];
        if (!is_string($kind) || !in_array($kind, moduleManifestValidKinds(), true)) {
            $fatal('suite_invalid_kind', 'manifest.v2.kind', '/kind', 'kind must be one of: ' . implode(', ', moduleManifestValidKinds()) . '.', 'Set kind to a supported value or omit it for standalone-application behavior.');
        }
    }
    $kind = moduleManifestKindFromManifest($manifest);

    // ── extends ──────────────────────────────────────────────────────────
    $extends = $manifest['extends'] ?? $manifest['parent'] ?? null;
    if ($extends !== null) {
        if (!is_string($extends) || preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', trim($extends)) !== 1) {
            $fatal('suite_invalid_extends', 'manifest.v2.extends', '/extends', 'extends must be a valid module id (kebab-case).', 'Point extends at the host module id, for example cms-akira-core.');
        }
    }

    // ── kind/extends policy ──────────────────────────────────────────────
    if ($kind === MODULE_KIND_EXTENSION || $kind === MODULE_KIND_ADAPTER) {
        if (!is_string($extends) || trim($extends) === '') {
            $fatal('suite_extends_required', 'manifest.v2.extends.required', '/extends', "kind '{$kind}' requires an extends declaration.", "Add \"extends\": \"<host-module-id>\" to declare the host this module builds on.");
        }
    }
    if ($kind === MODULE_KIND_PRODUCT_CORE) {
        $suite = $manifest['suite'] ?? null;
        if (!is_string($suite) || trim($suite) === '') {
            $fatal('suite_core_requires_suite', 'manifest.v2.suite.required', '/suite', "kind 'product-core' requires a suite declaration.", "Add \"suite\": \"<suite-id>\" to name the product suite this core anchors.");
        }
    }
    if ($kind === MODULE_KIND_PROFILE) {
        $installs = $manifest['installs'] ?? null;
        if (!is_array($installs) || $installs === []) {
            $fatal('suite_profile_requires_installs', 'manifest.v2.installs.required', '/installs', "kind 'profile' requires a non-empty installs list.", "Declare \"installs\": [\"<module-id>\", ...] to define the profile bundle.");
        } else {
            foreach ($installs as $index => $target) {
                if (!is_string($target) || preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', trim($target)) !== 1) {
                    $fatal('suite_profile_installs_invalid', 'manifest.v2.installs.entry', "/installs/{$index}", 'Profile installs entries must be valid module ids.', 'Use kebab-case module ids inside the installs array.');
                }
            }
        }
    }

    // ── extension_points (host-declared) ─────────────────────────────────
    if (array_key_exists('extension_points', $manifest)) {
        $points = $manifest['extension_points'];
        if (!is_array($points) || $points === []) {
            $fatal('suite_extension_points_invalid', 'manifest.v2.extension-points', '/extension_points', 'extension_points must be a non-empty array of point ids.', 'Declare point ids such as "cms.sidebar", "pal.settings.sections".');
        } else {
            foreach ($points as $index => $point) {
                if (!is_string($point) || trim($point) === '' || preg_match('/^[a-z0-9]+(\.[a-z0-9]+)+$/', trim($point)) !== 1) {
                    $fatal('suite_extension_point_invalid', 'manifest.v2.extension-points.entry', "/extension_points/{$index}", 'extension_point ids must be dotted identifiers (e.g. cms.sidebar).', 'Use a dotted point id with at least one dot.');
                }
            }
        }
    }

    // ── contributes ──────────────────────────────────────────────────────
    if (array_key_exists('contributes', $manifest)) {
        $contributes = $manifest['contributes'];
        if (!is_array($contributes) || $contributes === []) {
            $fatal('suite_contributes_invalid', 'manifest.v2.contributes', '/contributes', 'contributes must be a non-empty array.', 'Declare contributions as [{extension_point, provider}].');
        } else {
            foreach ($contributes as $index => $contribution) {
                if (!is_array($contribution)) {
                    $fatal('suite_contributes_entry_invalid', 'manifest.v2.contributes.entry', "/contributes/{$index}", 'Each contributes entry must be an object.', 'Declare {extension_point, provider} objects.');
                    continue;
                }
                $point = $contribution['extension_point'] ?? null;
                $provider = $contribution['provider'] ?? null;
                if (!is_string($point) || trim($point) === '') {
                    $fatal('suite_contributes_point_missing', 'manifest.v2.contributes.extension-point', "/contributes/{$index}/extension_point", 'contributes entries require an extension_point id.', 'Set extension_point to a point declared by the host.');
                }
                if (!is_string($provider) || trim($provider) === '') {
                    $fatal('suite_contributes_provider_missing', 'manifest.v2.contributes.provider', "/contributes/{$index}/provider", 'contributes entries require a provider reference.', 'Set provider to a versioned capability or service id, for example "pal-advanced-reporting.report-provider@1".');
                }
            }
        }
    }

    // ── admin_contributions ──────────────────────────────────────────────
    if (array_key_exists('admin_contributions', $manifest)) {
        $adminContribs = $manifest['admin_contributions'];
        if (!is_array($adminContribs) || $adminContribs === []) {
            $fatal('suite_admin_contributions_invalid', 'manifest.v2.admin-contributions', '/admin_contributions', 'admin_contributions must be a non-empty array.', 'Declare admin contributions as [{host, location, label, route}].');
        } else {
            foreach ($adminContribs as $index => $contribution) {
                if (!is_array($contribution)) {
                    $fatal('suite_admin_contributions_entry_invalid', 'manifest.v2.admin-contributions.entry', "/admin_contributions/{$index}", 'Each admin_contributions entry must be an object.', 'Declare {host, location, label, route} objects.');
                    continue;
                }
                foreach (['host', 'location', 'label'] as $requiredField) {
                    $value = $contribution[$requiredField] ?? null;
                    if (!is_string($value) || trim($value) === '') {
                        $fatal('suite_admin_contributions_field_missing', 'manifest.v2.admin-contributions.' . $requiredField, "/admin_contributions/{$index}/{$requiredField}", "admin_contributions entries require a non-empty '{$requiredField}'.", "Set {$requiredField} on the contribution.");
                    }
                }
                $route = $contribution['route'] ?? null;
                if ($route !== null && (!is_string($route) || trim($route) === '' || !str_starts_with($route, '/'))) {
                    $fatal('suite_admin_contributions_route_invalid', 'manifest.v2.admin-contributions.route', "/admin_contributions/{$index}/route", 'admin_contributions routes must be absolute paths starting with "/".', 'Set route to an absolute path such as "/admin/cms/seo".');
                }
                if (array_key_exists('order', $contribution) && (!is_int($contribution['order']) || $contribution['order'] < 0)) {
                    $fatal('suite_admin_contributions_order_invalid', 'manifest.v2.admin-contributions.order', "/admin_contributions/{$index}/order", 'admin_contributions order must be a non-negative integer.', 'Set order to a sorting integer such as 60.');
                }
                if (array_key_exists('permission', $contribution) && (!is_string($contribution['permission']) || trim((string)$contribution['permission']) === '')) {
                    $fatal('suite_admin_contributions_permission_invalid', 'manifest.v2.admin-contributions.permission', "/admin_contributions/{$index}/permission", 'admin_contributions permission must be a non-empty string.', 'Set permission to a capability id such as cms.seo.manage.');
                }
                if (array_key_exists('id', $contribution) && (!is_string($contribution['id']) || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*(\.[a-z0-9]+(?:-[a-z0-9]+)*)+$/', trim((string)$contribution['id'])) !== 1)) {
                    $fatal('suite_admin_contributions_id_invalid', 'manifest.v2.admin-contributions.id', "/admin_contributions/{$index}/id", 'admin_contributions id must be a dotted identifier (e.g. cms-akira-seo.sidebar).', 'Set a stable dotted id such as "cms-akira-seo.sidebar".');
                }
                if (array_key_exists('roles', $contribution)) {
                    $roles = $contribution['roles'];
                    if (!is_array($roles) || $roles === [] || array_filter($roles, static fn ($role): bool => !is_string($role) || trim($role) === '') !== []) {
                        $fatal('suite_admin_contributions_roles_invalid', 'manifest.v2.admin-contributions.roles', "/admin_contributions/{$index}/roles", 'admin_contributions roles must be a non-empty array of role names.', 'Set roles to an array such as ["admin"] or omit to allow all roles.');
                    }
                }
            }
        }
    }

    // ── integrations ────────────────────────────────────────────────────
    // Declaration Before Integration: a module may declare which provider
    // modules and capabilities it integrates with.  This is an additive,
    // optional manifest field (Suite Extension Contract v1).
    if (array_key_exists('integrations', $manifest)) {
        $integrations = $manifest['integrations'];
        if (!is_array($integrations)) {
            $fatal('manifest_integrations_invalid', 'manifest.integrations', '/integrations', 'integrations must be an object mapping provider module ids to integration contracts.', 'Declare integrations as { "<provider>": { "type": ..., "uses": [...] } }.');
        } else {
            foreach ($integrations as $providerModuleId => $contract) {
                if (!is_string($providerModuleId) || $providerModuleId === '') {
                    $fatal('manifest_integrations_provider_invalid', 'manifest.integrations.provider', "/integrations", 'integration provider keys must be non-empty module ids.', 'Use a valid module id as the integration key.');
                    continue;
                }
                if (!is_array($contract)) {
                    $fatal('manifest_integrations_contract_invalid', 'manifest.integrations.contract', "/integrations/{$providerModuleId}", 'Each integration entry must be an object with at minimum a "uses" array.', 'Declare { "type": "optional", "uses": [...] }.');
                    continue;
                }
                $uses = $contract['uses'] ?? null;
                if ($uses !== null && (!is_array($uses) || $uses === [])) {
                    $fatal('manifest_integrations_uses_invalid', 'manifest.integrations.uses', "/integrations/{$providerModuleId}/uses", 'integration uses must be a non-empty array of capability ids.', 'Declare the capability ids this module uses from the provider.');
                }
                $type = $contract['type'] ?? 'optional';
                if (!is_string($type) || !in_array(strtolower(trim($type)), ['optional', 'required'], true)) {
                    $fatal('manifest_integrations_type_invalid', 'manifest.integrations.type', "/integrations/{$providerModuleId}/type", "integration type must be 'optional' or 'required'.", "Set type to 'optional' (default) or 'required'.");
                }
                $addsFeatures = $contract['adds_features'] ?? null;
                if ($addsFeatures !== null && (!is_array($addsFeatures) || $addsFeatures === [])) {
                    $fatal('manifest_integrations_features_invalid', 'manifest.integrations.features', "/integrations/{$providerModuleId}/adds_features", 'adds_features must be a non-empty array of feature labels.', 'Describe what features the integration adds.');
                }
            }
        }
    }

    // ── compatibility ────────────────────────────────────────────────────
    if (array_key_exists('compatibility', $manifest)) {
        $compatibility = $manifest['compatibility'];
        if (!is_array($compatibility)) {
            $fatal('suite_compatibility_invalid', 'manifest.v2.compatibility', '/compatibility', 'compatibility must be an object.', 'Declare compatibility ranges as {kernel, suite}.');
        } else {
            foreach (['kernel', 'suite'] as $compatKey) {
                $range = $compatibility[$compatKey] ?? null;
                if ($range !== null && (!is_string($range) || preg_match('/^(\d+\.\d+(\.\d+)?([-+][0-9A-Za-z.\-]+)?|>=|<=|>|<|~|\^)/', trim($range)) !== 1)) {
                    $fatal('suite_compatibility_range_invalid', 'manifest.v2.compatibility.' . $compatKey, "/compatibility/{$compatKey}", "compatibility.{$compatKey} must be a semver range string.", 'Use a range such as ">=1.0.0" or "^1.2.0".');
                }
            }
        }
    }

    // ── uninstall policy ─────────────────────────────────────────────────
    if (array_key_exists('uninstall', $manifest)) {
        $uninstall = $manifest['uninstall'];
        if (!is_array($uninstall)) {
            $fatal('suite_uninstall_invalid', 'manifest.v2.uninstall', '/uninstall', 'uninstall must be an object.', 'Declare uninstall policy as {disable_safe, retain_data_by_default, supports_data_export, requires_confirmation_to_drop_data}.');
        } else {
            foreach (['disable_safe', 'retain_data_by_default', 'supports_data_export', 'requires_confirmation_to_drop_data'] as $flagKey) {
                if (array_key_exists($flagKey, $uninstall) && !is_bool($uninstall[$flagKey])) {
                    $fatal('suite_uninstall_flag_invalid', 'manifest.v2.uninstall.' . $flagKey, "/uninstall/{$flagKey}", "uninstall.{$flagKey} must be a boolean.", "Set {$flagKey} to true or false.");
                }
            }
        }
    }

    return $diagnostics;
}

/**
 * Fleet-level product suite contract validation across all discovered
 * manifests. Unlike the per-manifest validator, this checks cross-module
 * relationships: extends targets exist, contribution hosts exist, and
 * contributed extension points are declared by the host.
 *
 * @param array<string,array<string,mixed>> $manifests keyed by module id
 * @return array<int,array<string,string>>
 */
function validateModuleSuiteFleetV1(array $manifests): array
{
    $diagnostics = [];
    $fatal = static function (string $code, string $rule, string $field, string $message, string $correction) use (&$diagnostics): void {
        $diagnostics[] = moduleManifestDiagnostic(DiagnosticSeverity::Fatal, $code, $rule, $field, $message, $correction);
    };

    // Build host → declared extension points map from product-core modules.
    $hostExtensionPoints = [];
    foreach ($manifests as $moduleId => $manifest) {
        $kind = moduleManifestKindFromManifest($manifest);
        if ($kind === MODULE_KIND_PRODUCT_CORE && is_array($manifest['extension_points'] ?? null)) {
            $hostExtensionPoints[$moduleId] = array_values(array_filter(
                array_map('trim', $manifest['extension_points']),
                static fn ($p): bool => $p !== ''
            ));
        }
    }

    // Contribution id uniqueness across the fleet (per host:location).
    $contributionIds = [];
    foreach ($manifests as $moduleId => $manifest) {
        $adminContribs = $manifest['admin_contributions'] ?? null;
        if (!is_array($adminContribs)) {
            continue;
        }
        foreach ($adminContribs as $index => $contribution) {
            if (!is_array($contribution)) {
                continue;
            }
            $host = trim((string)($contribution['host'] ?? ''));
            $location = trim((string)($contribution['location'] ?? ''));
            $declaredId = trim((string)($contribution['id'] ?? ''));
            $id = $declaredId !== ''
                ? $declaredId
                : $moduleId . '.' . ($location !== '' ? $location : 'surface');
            $key = $host . ':' . $location . '#' . $id;
            if (isset($contributionIds[$key])) {
                $fatal('suite_fleet_duplicate_contribution_id', 'manifest.v2.fleet.contribution-id', "/admin_contributions/{$index}/id", "Contribution id '{$id}' for host '{$host}' location '{$location}' is already declared by module '{$contributionIds[$key]}'.", 'Use a unique dotted contribution id per host and location.');
                continue;
            }
            $contributionIds[$key] = $moduleId;
        }
    }

    foreach ($manifests as $moduleId => $manifest) {
        // extends target must exist
        $extends = $manifest['extends'] ?? $manifest['parent'] ?? null;
        if (is_string($extends) && trim($extends) !== '' && !isset($manifests[$extends])) {
            $fatal('suite_fleet_extends_missing', 'manifest.v2.fleet.extends-target', '/extends', "Module '{$moduleId}' extends '{$extends}' but that module is not present in the fleet.", 'Install the host module or correct the extends target.');
        }

        // contribution hosts must exist
        $adminContribs = $manifest['admin_contributions'] ?? null;
        if (is_array($adminContribs)) {
            foreach ($adminContribs as $index => $contribution) {
                if (!is_array($contribution)) {
                    continue;
                }
                $host = trim((string)($contribution['host'] ?? ''));
                if ($host !== '' && !isset($manifests[$host])) {
                    $fatal('suite_fleet_contribution_host_missing', 'manifest.v2.fleet.contribution-host', "/admin_contributions/{$index}/host", "Module '{$moduleId}' contributes to host '{$host}' but that module is not present in the fleet.", 'Point the contribution host at an installed admin shell module.');
                }
            }
        }

        // contributed extension points must be declared by the extends host
        $contributes = $manifest['contributes'] ?? null;
        if (is_array($contributes) && is_string($extends) && trim($extends) !== '') {
            $declaredPoints = $hostExtensionPoints[$extends] ?? [];
            if ($declaredPoints !== []) {
                foreach ($contributes as $index => $contribution) {
                    if (!is_array($contribution) || !is_string($contribution['extension_point'] ?? null)) {
                        continue;
                    }
                    $point = trim($contribution['extension_point']);
                    if ($point !== '' && !in_array($point, $declaredPoints, true)) {
                        $fatal('suite_fleet_extension_point_undeclared', 'manifest.v2.fleet.extension-point', "/contributes/{$index}/extension_point", "Module '{$moduleId}' contributes to extension point '{$point}' which host '{$extends}' does not declare.", "Declare '{$point}' in the host's extension_points or drop the contribution.");
                    }
                }
            }
        }
    }

    return $diagnostics;
}

/** @return array<string,string> */
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
