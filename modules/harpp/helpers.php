<?php

declare(strict_types=1);

use Harpp\Services\HarppAuthService;
use Harpp\Services\HarppUserService;
use Harpp\Services\HarppSettingsService;
use Harpp\Services\HarppDecisionService;
use Harpp\Services\HarppMessagingService;
use Harpp\Services\HarppBridgeAuthService;
use Ikabud\Kernel\Contracts\ModuleDB;

require_once __DIR__ . '/services/HarppServiceResult.php';
require_once __DIR__ . '/services/HarppAuthService.php';
require_once __DIR__ . '/services/HarppUserService.php';
require_once __DIR__ . '/services/HarppPasswordResetService.php';
require_once __DIR__ . '/services/HarppSettingsService.php';
require_once __DIR__ . '/services/HarppPushService.php';
require_once __DIR__ . '/services/HarppNotificationService.php';
require_once __DIR__ . '/services/HarppDecisionService.php';
require_once __DIR__ . '/services/HarppMessagingService.php';
require_once __DIR__ . '/services/HarppAdrService.php';
require_once __DIR__ . '/services/HarppBridgeAuthService.php';
require_once __DIR__ . '/services/HarppBridgeService.php';

app()->registerAuthTable('harpp', 'harpp_users');

function harpp_capability_handlers(): array
{
    return [
        'kernel.auth.authenticate@1' => 'harpp_cap_kernel_auth_authenticate_1',
        'harpp.read@1' => 'harpp_cap_read_1',
        'harpp.manage@1' => 'harpp_cap_manage_1',
        'harpp.users.manage@1' => 'harpp_cap_users_manage_1',
        'harpp.decision.review@1' => 'harpp_cap_decision_review_1',
        'harpp.notify@1' => 'harpp_cap_notify_1',
        'harpp.bridge@1' => 'harpp_cap_bridge_1',
        'harpp.bridge.authenticate@1' => 'harpp_cap_bridge_authenticate_1',
        'harpp.settings.read@1' => 'harpp_cap_settings_read_1',
        'harpp.settings.manage@1' => 'harpp_cap_settings_manage_1',
        'entity.list.harpp_conversation@1' => 'harpp_cap_entity_list_conversation_1',
        'entity.list.harpp_message@1' => 'harpp_cap_entity_list_message_1',
        'entity.list.harpp_decision@1' => 'harpp_cap_entity_list_decision_1',
        'entity.get.harpp_decision@1' => 'harpp_cap_entity_get_decision_1',
        'entity.list.harpp_adr@1' => 'harpp_cap_entity_list_adr_1',
        'entity.get.harpp_adr@1' => 'harpp_cap_entity_get_adr_1',
    ];
}

function harppDb(): ModuleDB
{
    $db = module('harpp')->db();
    if (!$db instanceof ModuleDB) {
        throw new RuntimeException('HARPP module database is unavailable.');
    }
    return $db;
}

function harppInput(): array
{
    $input = module('harpp')->input();
    return is_array($input) ? $input : [];
}

function harppJson(array|Harpp\Services\HarppServiceResult $result, ?int $status = null): void
{
    if ($result instanceof Harpp\Services\HarppServiceResult) $result = $result->toArray();
    $status ??= !empty($result['ok']) ? 200 : (int)($result['status'] ?? 422);
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function harppCurrentTenantId(): int
{
    return (int)(app()->tenant()->current() ?? 0);
}

function harppTenantPayloadMatches(mixed $payload): bool
{
    if (!is_array($payload)) {
        return true;
    }
    $requested = (int)($payload['_tenant_id'] ?? $payload['store_id'] ?? 0);
    return $requested === 0 || ($requested === harppCurrentTenantId() && $requested > 0);
}

function harppPermissionResult(string $permission, mixed $payload): array
{
    $data = is_array($payload) ? $payload : [];
    $user = is_array($data['user'] ?? null) ? $data['user'] : module('harpp')->user();
    $role = (string)($user['role'] ?? '');
    $source = (string)($user['source'] ?? 'harpp');
    $roles = [
        'harpp.read' => ['owner', 'admin', 'member'],
        'harpp.manage' => ['owner', 'admin'],
        'harpp.users.manage' => ['owner', 'admin'],
        'harpp.decision.review' => ['owner', 'admin', 'member'],
        'harpp.notify' => ['owner', 'admin'],
        'harpp.bridge' => ['owner', 'admin'],
        'harpp.settings.read' => ['owner', 'admin'],
        'harpp.settings.manage' => ['owner', 'admin'],
    ];
    $kernelSuperadmin = $source === 'kernel' && $role === 'superadmin';
    $moduleUser = $source === 'harpp' && in_array($role, $roles[$permission] ?? [], true);
    $data['ok'] = true;
    $data['allowed'] = harppTenantPayloadMatches($data) && ($kernelSuperadmin || $moduleUser);
    $data['permission'] = $permission;
    $data['tenant_id'] = harppCurrentTenantId();
    return $data;
}

function harppAuthorize(string $capabilityId, array $user, array $scope = []): array
{
    try {
        $result = app()->cap()->call($capabilityId, $scope + ['user' => $user, '_tenant_id' => harppCurrentTenantId()], ['mode' => 'first', 'caller_module' => 'harpp']);
        if (is_array($result) && !empty($result['allowed'])) {
            return ['ok' => true, 'data' => ['user' => $user]];
        }
    } catch (Throwable $e) {
        if (function_exists('write_log')) {
            write_log('HARPP permission capability failed', 'error', ['module' => 'harpp', 'capability' => $capabilityId, 'error' => $e->getMessage()]);
        }
    }
    return ['ok' => false, 'error' => 'Forbidden.', 'status' => 403, 'code' => 'forbidden'];
}

function harpp_cap_kernel_auth_authenticate_1(mixed $payload, string $capabilityId = 'kernel.auth.authenticate@1', string $providerId = ''): array
{
    if (!is_array($payload)) {
        return ['ok' => false, 'authenticated' => false, 'source' => 'harpp', 'error' => 'Invalid authentication payload.'];
    }
    $identity = trim((string)($payload['username'] ?? $payload['email'] ?? ''));
    $prefix = '@harpp:';
    if (!str_starts_with($identity, $prefix)) {
        return ['ok' => false, 'authenticated' => false, 'source' => 'harpp', 'skipped' => true];
    }
    $result = (new HarppAuthService())->authenticate(substr($identity, strlen($prefix)), (string)($payload['password'] ?? ''));
    if (empty($result['ok'])) {
        return $result->toArray() + ['authenticated' => false, 'source' => 'harpp'];
    }
    $user = $result['data']['user'];
    $user['sub'] = 'harpp:' . (int)$user['id'];
    return ['ok' => true, 'authenticated' => true, 'user' => $user, 'source' => 'harpp'];
}

function harpp_cap_read_1(mixed $payload, string $capabilityId = 'harpp.read@1', string $providerId = ''): array
{
    return harppPermissionResult('harpp.read', $payload);
}
function harpp_cap_manage_1(mixed $payload, string $capabilityId = 'harpp.manage@1', string $providerId = ''): array
{
    return harppPermissionResult('harpp.manage', $payload);
}
function harpp_cap_users_manage_1(mixed $payload, string $capabilityId = 'harpp.users.manage@1', string $providerId = ''): array
{
    return harppPermissionResult('harpp.users.manage', $payload);
}
function harpp_cap_decision_review_1(mixed $payload, string $capabilityId = 'harpp.decision.review@1', string $providerId = ''): array
{
    return harppPermissionResult('harpp.decision.review', $payload);
}
function harpp_cap_notify_1(mixed $payload, string $capabilityId = 'harpp.notify@1', string $providerId = ''): array
{
    return harppPermissionResult('harpp.notify', $payload);
}
function harpp_cap_bridge_1(mixed $payload, string $capabilityId = 'harpp.bridge@1', string $providerId = ''): array
{
    return harppPermissionResult('harpp.bridge', $payload);
}
function harpp_cap_bridge_authenticate_1(mixed $payload, string $capabilityId = 'harpp.bridge.authenticate@1', string $providerId = ''): array
{
    $data = is_array($payload) ? $payload : [];
    return (new HarppBridgeAuthService(harppDb()))->validate(
        trim((string)($data['key'] ?? $data['bridge_key'] ?? '')),
        (int)($data['tenant_id'] ?? 0),
        trim((string)($data['client_id'] ?? 'capability'))
    )->toArray();
}
function harpp_cap_settings_read_1(mixed $payload, string $capabilityId = 'harpp.settings.read@1', string $providerId = ''): array
{
    return harppPermissionResult('harpp.settings.read', $payload);
}
function harpp_cap_settings_manage_1(mixed $payload, string $capabilityId = 'harpp.settings.manage@1', string $providerId = ''): array
{
    return harppPermissionResult('harpp.settings.manage', $payload);
}

function harppCapabilityActor(mixed $payload): array
{
    $data = is_array($payload) ? $payload : [];
    return is_array($data['user'] ?? null) ? $data['user'] : (array)module('harpp')->user();
}
function harpp_cap_entity_list_conversation_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    $data = is_array($payload) ? $payload : [];
    $actor = harppCapabilityActor($data);
    $access = harppPermissionResult('harpp.read', $data + ['user' => $actor]);
    if (empty($access['allowed'])) { return ['ok' => false, 'allowed' => false, 'rows' => [], 'total' => 0]; }
    $limit = max(1, min(100, (int)($data['limit'] ?? 25)));
    $stmt = harppDb()->query('SELECT id, title, harness_session_id, status, created_by, created_at, updated_at FROM harpp_conversations ORDER BY updated_at DESC, id DESC LIMIT ' . $limit);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return ['ok' => true, 'allowed' => true, 'rows' => $rows, 'total' => count($rows), 'tenant_id' => harppCurrentTenantId()];
}
function harpp_cap_entity_list_message_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    $data = is_array($payload) ? $payload : [];
    $result = (new HarppMessagingService(harppDb()))->listMessages(harppCapabilityActor($data), (int)($data['conversation_id'] ?? 0), $data, harppCurrentTenantId());
    $rows = (array)($result['data']['messages'] ?? []);
    return $result->toArray() + ['allowed' => !empty($result['ok']), 'rows' => $rows, 'total' => count($rows)];
}
function harpp_cap_entity_list_decision_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    $data = is_array($payload) ? $payload : [];
    $result = (new HarppDecisionService(harppDb()))->list(harppCapabilityActor($data), $data, harppCurrentTenantId());
    $rows = (array)($result['data']['decisions'] ?? []);
    return $result->toArray() + ['allowed' => !empty($result['ok']), 'rows' => $rows, 'total' => count($rows)];
}
function harpp_cap_entity_get_decision_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    $data = is_array($payload) ? $payload : [];
    $result = (new HarppDecisionService(harppDb()))->get(harppCapabilityActor($data), (int)($data['id'] ?? $data['entity_id'] ?? 0), harppCurrentTenantId());
    $wire = $result->toArray(); if (!empty($wire['data']['decision'])) $wire['row'] = $wire['data']['decision'];
    return $wire + ['allowed' => !empty($wire['ok'])];
}
function harpp_cap_entity_list_adr_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    $data = is_array($payload) ? $payload : [];
    $result = (new Harpp\Services\HarppAdrService(harppDb()))->list(harppCapabilityActor($data), $data, harppCurrentTenantId());
    $wire = $result->toArray(); $rows = (array)($wire['data']['adrs'] ?? []);
    return $wire + ['allowed' => !empty($wire['ok']), 'rows' => $rows, 'total' => count($rows)];
}
function harpp_cap_entity_get_adr_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    $data = is_array($payload) ? $payload : [];
    $result = (new Harpp\Services\HarppAdrService(harppDb()))->get(harppCapabilityActor($data), (int)($data['id'] ?? $data['entity_id'] ?? 0), harppCurrentTenantId());
    $wire = $result->toArray(); if (!empty($wire['data']['adr'])) $wire['row'] = $wire['data']['adr'];
    return $wire + ['allowed' => !empty($wire['ok'])];
}
