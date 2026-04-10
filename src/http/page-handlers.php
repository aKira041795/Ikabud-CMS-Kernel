<?php

declare(strict_types=1);

if (!function_exists('kernelHandlePageLogin')) {
    function kernelHandlePageLogin(): void
    {
        $loginUser = app()->user();
        if ($loginUser) {
            $loginHome = kernelResolveAuthenticatedHomeRedirect($loginUser, true) ?? '/';
            app()->redirect($loginHome);
            return;
        }

        $loginContext = [
            'page_title' => 'Sign In',
        ];
        $loginTenantId = app()->tenant()->current();
        if ($loginTenantId !== null && function_exists('tenantEntryModuleIdForTenant')) {
            $entryModuleId = tenantEntryModuleIdForTenant((int)$loginTenantId);
            if ($entryModuleId === 'wms' && function_exists('wmsLoginPageContext')) {
                $loginContext = wmsLoginPageContext();
            }
        }

        echo app()->render('pages/login.disyl', $loginContext);
    }
}

if (!function_exists('kernelHandlePageKernelIntegrations')) {
    function kernelHandlePageKernelIntegrations(): void
    {
        $user = app()->requireAuth();
        if (($user['role'] ?? '') !== 'superadmin' || ($user['source'] ?? '') !== 'kernel') {
            app()->redirect('/');
            return;
        }

        $db = app()->db();
        $integrations = $db->query('SELECT * FROM kernel_integrations ORDER BY created_at DESC')->fetchAll();
        $logs = $db->query('SELECT l.*, i.name as integration_name FROM kernel_integration_logs l LEFT JOIN kernel_integrations i ON i.id = l.integration_id ORDER BY l.created_at DESC LIMIT 100')->fetchAll();
        $eventsRows = $db->query(
            'SELECT module, event_key, description, available_vars FROM kernel_events ORDER BY module ASC, event_key ASC'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($eventsRows as &$eventRow) {
            if (!is_array($eventRow)) {
                continue;
            }
            $eventRow['available_vars'] = !empty($eventRow['available_vars'])
                ? (json_decode((string)$eventRow['available_vars'], true) ?: [])
                : [];
            $eventRow['available_vars_csv'] = !empty($eventRow['available_vars'])
                ? implode(',', array_map(static fn($value): string => (string)$value, (array)$eventRow['available_vars']))
                : '';
        }
        unset($eventRow);

        $capabilityInspect = app()->capabilities()->inspectAll();
        $capabilities = [];
        foreach ($capabilityInspect as $capabilityId => $definition) {
            if (is_string($capabilityId) && $capabilityId !== '') {
                $capabilities[] = [
                    'id' => $capabilityId,
                    'label' => $capabilityId,
                    'description' => is_array($definition) ? (string)($definition['description'] ?? '') : '',
                ];
                continue;
            }
            if (is_array($definition) && !empty($definition['id'])) {
                $capabilities[] = [
                    'id' => (string)$definition['id'],
                    'label' => (string)($definition['label'] ?? $definition['id']),
                    'description' => (string)($definition['description'] ?? ''),
                ];
            }
        }
        usort($capabilities, static fn(array $left, array $right): int => strcmp((string)$left['id'], (string)$right['id']));

        echo app()->render('pages/kernel-integrations.disyl', [
            'title' => 'Kernel Integrations',
            'user' => $user,
            'integrations' => $integrations,
            'logs' => $logs,
            'bridge_events' => $eventsRows,
            'bridge_capabilities' => $capabilities,
            'csrf_token' => app()->csrfToken(),
        ]);
    }
}