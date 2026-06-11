<?php

declare(strict_types=1);

if (!function_exists('listTenantEntryModuleOptions')) {
    function listTenantEntryModuleOptions(): array
    {
        $modules = discoverModules();
        $enabled = getEnabledModules();
        $options = [];
        foreach ($modules as $module) {
            if (!is_array($module)) {
                continue;
            }

            $moduleId = trim((string)($module['id'] ?? ''));
            if ($moduleId === '') {
                continue;
            }

            // EHR core is aliased to the EHR suite entry module
            if ($moduleId === 'ehr-core') {
                continue;
            }

            // Only modules that own their authentication can serve as tenant entry points.
            // This means auth_owned (module-owned users table) or auth_cookie (module JWT cookie).
            // Service modules, extensions, and add-ons are excluded.
            $type = trim((string)($module['type'] ?? 'module'));
            if ($type === 'service-module') {
                continue;
            }

            $hasAuthOwned = is_array($module['auth_owned'] ?? null) && !empty($module['auth_owned']);
            $hasAuthCookie = !empty($module['auth_cookie']) && is_string($module['auth_cookie']);
            if (!$hasAuthOwned && !$hasAuthCookie) {
                continue;
            }

            $options[] = [
                'id' => $moduleId,
                'name' => $moduleId === 'ehr'
                    ? 'EHR Suite'
                    : (string)($module['name'] ?? $moduleId),
                'enabled' => !empty($module['_enabled']),
                'loadable' => isset($enabled[$moduleId]),
            ];
        }

        usort($options, static function (array $left, array $right): int {
            return strcmp($left['name'], $right['name']);
        });

        return $options;
    }
}

if (!function_exists('normalizeTenantEntryModuleId')) {
    function normalizeTenantEntryModuleId($value, bool $requireLoadable = false): array
    {
        $entryModuleId = trim((string)$value);
        if ($entryModuleId === '') {
            return ['ok' => true, 'value' => null, 'error' => null];
        }

        if ($entryModuleId === 'ehr-core') {
            $entryModuleId = 'ehr';
        }

        $optionsById = [];
        foreach (listTenantEntryModuleOptions() as $option) {
            $optionId = (string)($option['id'] ?? '');
            if ($optionId === '') {
                continue;
            }

            $optionsById[$optionId] = $option;
        }

        if (!isset($optionsById[$entryModuleId])) {
            return ['ok' => false, 'value' => null, 'error' => 'invalid_entry_module_id'];
        }
        if ($requireLoadable && empty($optionsById[$entryModuleId]['loadable'])) {
            return ['ok' => false, 'value' => null, 'error' => 'entry_module_not_loadable'];
        }

        return ['ok' => true, 'value' => $entryModuleId, 'error' => null];
    }
}