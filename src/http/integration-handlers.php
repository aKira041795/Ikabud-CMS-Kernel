<?php

declare(strict_types=1);

if (!function_exists('kernelHandleApiKernelIntegrations')) {
    function kernelHandleApiKernelIntegrations(?string $rawBody = null): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('X-Request-Id: ' . request_id());

        $user = app()->requireAuth();
        if (!in_array($user['role'] ?? '', ['admin', 'superadmin'], true) || ($user['source'] ?? '') !== 'kernel') {
            app()->json(['ok' => false, 'error' => 'Forbidden', 'request_id' => request_id()], 403);
            return;
        }

        $db = app()->db();
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $bridgeAudit = static function (string $action, ?string $entityId = null, mixed $oldData = null, mixed $newData = null): void {
            try {
                app()->cap()->call('kernel.audit.record@1', [
                    'module' => '_kernel',
                    'action' => $action,
                    'entity_type' => 'kernel_integration',
                    'entity_id' => $entityId,
                    'old_data' => $oldData,
                    'new_data' => $newData,
                ]);
            } catch (Throwable $e) {
                write_log('kernel integration audit failed: ' . $e->getMessage(), 'warning', [
                    'module' => '_kernel',
                    'action' => $action,
                    'entity_id' => $entityId,
                    'request_id' => request_id(),
                ]);
            }
        };

        if ($method === 'GET') {
            $catalog = new \Ikabud\Kernel\ControlPlane\IntegrationCatalog(
                $db,
                new \Ikabud\Kernel\Capabilities\CapabilityCatalog(app()->capabilities())
            );
            app()->json([
                'ok' => true,
                'summary' => $catalog->summary(),
                'integrations' => $catalog->integrations(),
                'logs' => $catalog->logs(),
                'request_id' => request_id(),
            ]);
            return;
        }

        if (in_array($method, ['POST', 'DELETE'], true)) {
            app()->csrfEnforce();
        }

        if ($method === 'POST') {
            $body = $rawBody;
            if ($body === null) {
                $readBody = file_get_contents('php://input');
                $body = is_string($readBody) ? $readBody : '';
            }
            $input = json_decode($body, true) ?? [];
            $action = (string)($input['_action'] ?? 'create');

            if ($action === 'validate') {
                $validation = \Ikabud\Kernel\IntegrationBridge::validateDefinition($input);
                $statusCode = !empty($validation['ok']) ? 200 : 422;
                app()->json([
                    'ok' => !empty($validation['ok']),
                    'errors' => array_values(array_filter($validation['errors'] ?? [], static fn(mixed $value): bool => is_string($value) && $value !== '')),
                    'resolved_capability' => $validation['resolved_capability'] ?? null,
                    'available_vars' => $validation['available_vars'] ?? [],
                    'mapping_vars' => $validation['mapping_vars'] ?? [],
                    'version_lock' => $validation['normalized']['version_lock'] ?? null,
                    'request_id' => request_id(),
                ], $statusCode);
                return;
            }

            if ($action === 'toggle') {
                $id = (int)($input['id'] ?? 0);
                if ($id <= 0) {
                    app()->json(['ok' => false, 'error' => 'Missing id', 'request_id' => request_id()], 400);
                    return;
                }
                $existingStmt = $db->prepare('SELECT * FROM kernel_integrations WHERE id = ? LIMIT 1');
                $existingStmt->execute([$id]);
                $existing = $existingStmt->fetch();
                if (!$existing) {
                    app()->json(['ok' => false, 'error' => 'Bridge not found', 'request_id' => request_id()], 404);
                    return;
                }
                $stmt = $db->prepare('UPDATE kernel_integrations SET is_active = NOT is_active, updated_at = NOW() WHERE id = ?');
                $stmt->execute([$id]);
                $toggled = !empty($existing['is_active']) ? 0 : 1;
                $bridgeAudit('kernel.integration.toggle', (string)$id, $existing, ['is_active' => $toggled]);
                app()->json(['ok' => true, 'is_active' => $toggled, 'request_id' => request_id()]);
                return;
            }

            if ($action === 'apply_mode') {
                $mode = (string)($input['mode'] ?? '');
                if (!in_array($mode, ['wms_authoritative_products', 'ecommerce_authoritative_products', 'decoupled'], true)) {
                    app()->json(['ok' => false, 'error' => 'Invalid integration mode', 'request_id' => request_id()], 400);
                    return;
                }

                if (!function_exists('ecSyncWmsFulfillmentBridges') || !function_exists('ecSyncWmsProductAuthorityBridges')) {
                    app()->json(['ok' => false, 'error' => 'Ecommerce bridge helpers are unavailable', 'request_id' => request_id()], 500);
                    return;
                }

                $db->beginTransaction();
                try {
                    \Ikabud\Kernel\IntegrationBridge::deleteBridgesByNames([
                        'WMS ↔ Ecommerce Order Sync',
                        'WMS ↔ Ecommerce Order Cancel',
                        'WMS ↔ Ecommerce Stock Alert',
                        'WMS → Ecommerce Product Update',
                        'Ecommerce → WMS Product Update',
                    ]);
                    $db->prepare("DELETE FROM kernel_integrations WHERE integration_mode IN ('wms_authoritative_products', 'ecommerce_authoritative_products')")
                        ->execute();

                    $bridgeIds = [];
                    if ($mode === 'decoupled') {
                        ecSyncWmsFulfillmentBridges(false);
                        ecSyncWmsProductAuthorityBridges(null);
                    } else {
                        $bridgeIds = array_merge(
                            ecSyncWmsFulfillmentBridges(true, $mode),
                            ecSyncWmsProductAuthorityBridges($mode)
                        );
                    }

                    $currentEcommerceSettings = getModuleSettings('ecommerce');
                    saveModuleSettings('ecommerce', array_merge(
                        is_array($currentEcommerceSettings) ? $currentEcommerceSettings : [],
                        ['wms_fulfillment_bridge_enabled' => $mode !== 'decoupled']
                    ));

                    $bridgeAudit('kernel.integration.apply_mode', null, null, [
                        'mode' => $mode,
                        'bridge_ids' => $bridgeIds,
                    ]);
                    $db->commit();
                    app()->json(['ok' => true, 'mode' => $mode, 'bridge_ids' => $bridgeIds, 'request_id' => request_id()]);
                } catch (Throwable $e) {
                    $db->rollBack();
                    write_log('Failed to apply mode: ' . $e->getMessage(), 'error');
                    app()->json(['ok' => false, 'error' => $e->getMessage(), 'request_id' => request_id()], 500);
                }
                return;
            }

            if ($action === 'promote') {
                $id = (int)($input['id'] ?? 0);
                if ($id <= 0) {
                    app()->json(['ok' => false, 'error' => 'Missing id', 'request_id' => request_id()], 400);
                    return;
                }
                $row = $db->prepare('SELECT * FROM kernel_integrations WHERE id = ?');
                $row->execute([$id]);
                $integration = $row->fetch();
                if (!$integration) {
                    app()->json(['ok' => false, 'error' => 'Bridge not found', 'request_id' => request_id()], 404);
                    return;
                }

                $mapping = json_decode((string)($integration['mapping_json'] ?? '{}'), true) ?: [];
                $tplParts = [];
                foreach ($mapping as $key => $value) {
                    $converted = is_string($value)
                        ? preg_replace_callback('/\{\{([^}]+)\}\}/', function ($matches) {
                            return '{' . str_replace('.', '_', trim($matches[1])) . '}';
                        }, $value)
                        : json_encode($value);
                    $tplParts[] = '"' . addslashes((string)$key) . '":"' . addslashes((string)$converted) . '"';
                }
                $tpl = '{' . implode(',', $tplParts) . '}';

                if (function_exists('kernelTriggerSave')) {
                    $result = kernelTriggerSave([
                        'module' => 'kernel',
                        'event_key' => (string)($integration['trigger_event'] ?? ''),
                        'capability_id' => (string)($integration['target_capability'] ?? ''),
                        'is_enabled' => 1,
                        'priority' => 100,
                        'template' => $tpl,
                        'max_per_minute' => null,
                        'retry_count' => 0,
                        'timeout_ms' => 5000,
                        'meta' => null,
                    ]);

                    if (!empty($result['ok'])) {
                        $db->prepare('UPDATE kernel_integrations SET event_source = ?, updated_at = NOW() WHERE id = ?')
                            ->execute(['promoted', $id]);
                        $bridgeAudit('kernel.integration.promote', (string)$id, $integration, ['event_source' => 'promoted', 'trigger_id' => $result['id'] ?? null]);
                        app()->json(['ok' => true, 'trigger_id' => $result['id'] ?? null, 'request_id' => request_id()]);
                    } else {
                        app()->json(['ok' => false, 'error' => $result['error'] ?? 'Failed to save trigger', 'request_id' => request_id()], 500);
                    }
                } else {
                    app()->json(['ok' => false, 'error' => 'kernelTriggerSave not available', 'request_id' => request_id()], 500);
                }
                return;
            }

            if (!isset($input['name'], $input['trigger_event'], $input['target_capability'], $input['mapping_json'])) {
                app()->json(['ok' => false, 'error' => 'Missing required fields (name, trigger_event, target_capability, mapping_json)', 'request_id' => request_id()], 400);
                return;
            }

            $name = trim((string)$input['name']);
            $triggerEvent = trim((string)$input['trigger_event']);
            $targetCapability = trim((string)$input['target_capability']);
            if ($name === '' || $triggerEvent === '' || $targetCapability === '') {
                app()->json(['ok' => false, 'error' => 'Name, trigger_event, and target_capability must be non-empty.', 'request_id' => request_id()], 422);
                return;
            }

            $mappingInput = $input['mapping_json'];
            if (is_string($mappingInput)) {
                $mappingJson = trim($mappingInput);
                $decodedMapping = json_decode($mappingJson, true);
            } elseif (is_array($mappingInput)) {
                $decodedMapping = $mappingInput;
                $mappingJson = json_encode($mappingInput, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            } else {
                $decodedMapping = null;
                $mappingJson = '';
            }

            if (!is_array($decodedMapping) || $mappingJson === '' || (function_exists('array_is_list') ? array_is_list($decodedMapping) : array_keys($decodedMapping) === range(0, count($decodedMapping) - 1))) {
                app()->json(['ok' => false, 'error' => 'mapping_json must be a valid JSON object.', 'request_id' => request_id()], 400);
                return;
            }

            $validation = \Ikabud\Kernel\IntegrationBridge::validateDefinition(array_merge($input, [
                'mapping_json' => $decodedMapping,
                'event_source' => (string)($input['event_source'] ?? 'eventbus'),
            ]));
            if (empty($validation['ok'])) {
                app()->json([
                    'ok' => false,
                    'error' => implode(' ', array_values(array_filter($validation['errors'] ?? [], static fn(mixed $value): bool => is_string($value) && $value !== ''))),
                    'errors' => $validation['errors'] ?? [],
                    'request_id' => request_id(),
                ], 422);
                return;
            }

            $normalized = is_array($validation['normalized'] ?? null) ? $validation['normalized'] : [];
            $resolvedCapability = (string)($validation['resolved_capability'] ?? '');
            $existingRowsStmt = $db->prepare('SELECT id, target_capability FROM kernel_integrations WHERE trigger_event = ?');
            $existingRowsStmt->execute([$triggerEvent]);
            $existingRows = $existingRowsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $existingId = 0;
            foreach ($existingRows as $existingRow) {
                $existingTarget = trim((string)($existingRow['target_capability'] ?? ''));
                if ($existingTarget === '') {
                    continue;
                }
                if ((string)app()->capabilities()->resolve($existingTarget) === $resolvedCapability) {
                    $existingId = (int)($existingRow['id'] ?? 0);
                    break;
                }
            }
            if ($existingId > 0) {
                app()->json(['ok' => false, 'error' => 'A bridge for this event and capability already exists.', 'id' => $existingId, 'request_id' => request_id()], 409);
                return;
            }

            $versionLock = $normalized['version_lock'] ?? null;
            $eventSource = (string)($normalized['event_source'] ?? 'eventbus');
            $mappingJson = (string)($normalized['mapping_json'] ?? $mappingJson);

            try {
                $stmt = $db->prepare(
                    'INSERT INTO kernel_integrations (name, trigger_event, target_capability, mapping_json, is_active, event_source, version_lock) '
                    . 'VALUES (?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([
                    (string)($normalized['name'] ?? $name),
                    $triggerEvent,
                    (string)($normalized['target_capability'] ?? $targetCapability),
                    $mappingJson,
                    (int)($normalized['is_active'] ?? (isset($input['is_active']) ? (int)$input['is_active'] : 1)),
                    $eventSource,
                    is_string($versionLock) && $versionLock !== '' ? $versionLock : null,
                ]);
            } catch (Throwable $e) {
                $message = str_contains(strtolower($e->getMessage()), 'duplicate') || str_contains(strtolower($e->getMessage()), 'unique')
                    ? 'A bridge for this event and capability already exists.'
                    : 'Failed to create bridge.';
                app()->json(['ok' => false, 'error' => $message, 'request_id' => request_id()], 409);
                return;
            }

            $newId = (int)$db->lastInsertId();
            $bridgeAudit('kernel.integration.create', (string)$newId, null, [
                'name' => (string)($normalized['name'] ?? $name),
                'trigger_event' => $triggerEvent,
                'target_capability' => (string)($normalized['target_capability'] ?? $targetCapability),
                'event_source' => $eventSource,
                'version_lock' => is_string($versionLock) && $versionLock !== '' ? $versionLock : null,
            ]);
            app()->json(['ok' => true, 'id' => $newId, 'request_id' => request_id()]);
            return;
        }

        if ($method === 'DELETE') {
            $id = (int)($_GET['id'] ?? 0);
            if ($id > 0) {
                $existingStmt = $db->prepare('SELECT * FROM kernel_integrations WHERE id = ? LIMIT 1');
                $existingStmt->execute([$id]);
                $existing = $existingStmt->fetch();
                $db->prepare('DELETE FROM kernel_integrations WHERE id = ?')->execute([$id]);
                if ($existing) {
                    $bridgeAudit('kernel.integration.delete', (string)$id, $existing, null);
                }
            }
            app()->json(['ok' => true, 'request_id' => request_id()]);
            return;
        }

        app()->json(['ok' => false, 'error' => 'Method not allowed', 'request_id' => request_id()], 405);
    }
}