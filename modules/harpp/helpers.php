<?php

declare(strict_types=1);

use Harpp\Services\HarppAuthService;
use Harpp\Services\HarppUserService;
use Harpp\Services\HarppSettingsService;
use Harpp\Services\HarppDecisionService;
use Harpp\Services\HarppMessagingService;
use Harpp\Services\HarppBridgeAuthService;
use Harpp\Services\HarppFoundationService;
use Harpp\Services\HarppCollaborationService;
use Harpp\Services\HarppRunService;
use Harpp\Services\HarppDeployService;
use Ikabud\Kernel\Contracts\ModuleDB;

require_once __DIR__ . '/services/HarppServiceResult.php';
require_once __DIR__ . '/services/HarppAuthService.php';
require_once __DIR__ . '/services/HarppUserService.php';
require_once __DIR__ . '/services/HarppPasswordResetService.php';
require_once __DIR__ . '/services/HarppSettingsService.php';
require_once __DIR__ . '/services/HarppPushService.php';
require_once __DIR__ . '/services/HarppNotificationService.php';
require_once __DIR__ . '/services/HarppFoundationService.php';
require_once __DIR__ . '/services/HarppCollaborationPolicy.php';
require_once __DIR__ . '/services/HarppCollaborationService.php';
require_once __DIR__ . '/services/HarppRunService.php';
require_once __DIR__ . '/services/HarppDecisionService.php';
require_once __DIR__ . '/services/HarppMessagingService.php';
require_once __DIR__ . '/services/HarppAdrService.php';
require_once __DIR__ . '/services/HarppBridgeAuthService.php';
require_once __DIR__ . '/services/HarppBridgeService.php';
require_once __DIR__ . '/services/HarppDeployService.php';

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
        'harpp.lifecycle.transition@2' => 'harpp_cap_lifecycle_transition_2',
        'harpp.archive@1' => 'harpp_cap_archive_1',
        'harpp.purge.request@1' => 'harpp_cap_purge_request_1',
        'harpp.purge.approve@1' => 'harpp_cap_purge_approve_1',
        'harpp.audit.read@1' => 'harpp_cap_audit_read_1',
        'harpp.outbox.dispatch@1' => 'harpp_cap_outbox_dispatch_1',
        'harpp.workspace.read@1' => 'harpp_cap_workspace_read_1',
        'harpp.workspace.manage@1' => 'harpp_cap_workspace_manage_1',
        'harpp.project.read@1' => 'harpp_cap_project_read_1',
        'harpp.project.manage@1' => 'harpp_cap_project_manage_1',
        'harpp.participant.manage@1' => 'harpp_cap_participant_manage_1',
        'harpp.message.receipt@1' => 'harpp_cap_message_receipt_1',
        'harpp.decision.assign@1' => 'harpp_cap_decision_assign_1',
        'harpp.decision.approve@1' => 'harpp_cap_decision_approve_1',
        'harpp.notification.preferences@1' => 'harpp_cap_notification_preferences_1',
        'harpp.deploy.read@1' => 'harpp_cap_deploy_read_1',
        'harpp.deploy.request@1' => 'harpp_cap_deploy_request_1',
        'harpp.deploy.inventory@1' => 'harpp_cap_deploy_inventory_1',
        'harpp.deploy.claim@1' => 'harpp_cap_deploy_claim_1',
        'harpp.deploy.report@1' => 'harpp_cap_deploy_report_1',
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
        'harpp.workspace.read' => ['owner', 'admin', 'member'],
        'harpp.workspace.manage' => ['owner', 'admin', 'member'],
        'harpp.project.read' => ['owner', 'admin', 'member'],
        'harpp.project.manage' => ['owner', 'admin', 'member'],
        'harpp.participant.manage' => ['owner', 'admin', 'member'],
        'harpp.decision.assign' => ['owner', 'admin', 'member'],
        'harpp.decision.approve' => ['owner', 'admin', 'member'],
        'harpp.deploy.read' => ['owner', 'admin', 'member'],
        'harpp.deploy.request' => ['owner', 'admin'],
        'harpp.deploy.inventory' => ['owner', 'admin'],
        'harpp.deploy.claim' => ['owner', 'admin'],
        'harpp.deploy.report' => ['owner', 'admin'],
    ];
    $kernelSuperadmin = $source === 'kernel' && $role === 'superadmin';
    $moduleUser = in_array($source, ['harpp','harpp_bridge'], true) && in_array($role, $roles[$permission] ?? [], true);
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

function harppCapabilityActor(mixed $payload = null): array
{
    // Payload data is never an identity source. Resolve the authenticated request
    // principal from infrastructure, then reload role/active state from HARPP's DB.
    $principal = module('harpp')->user();$moduleAuthenticated=is_array($principal)&&(int)($principal['id']??0)>0;
    if (!$moduleAuthenticated) {
        try {
            $resolved = app()->cap()->call('kernel.auth.user@1', [], ['mode' => 'first', 'caller_module' => 'harpp']);
            $principal = is_array($resolved) ? ($resolved['data']['user'] ?? $resolved['user'] ?? $resolved) : [];
            $source=(string)($principal['source']??(is_array($resolved)?($resolved['source']??'') : ''));$sub=(string)($principal['sub']??'');
            if($source!=='harpp'&&!str_starts_with($sub,'harpp:'))$principal=[];
        } catch (Throwable) {
            $principal = [];
        }
    }
    $id = (int)($principal['id'] ?? 0);
    if ($id <= 0 || harppCurrentTenantId() <= 0) return [];
    $stmt = harppDb()->prepare('SELECT id,role FROM harpp_users WHERE id=:id AND is_active=1 AND deleted_at IS NULL LIMIT 1');
    $stmt->execute([':id'=>$id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($user) ? ['id'=>(int)$user['id'],'role'=>(string)$user['role'],'source'=>'harpp'] : [];
}
function harppCapabilityPermission(array $actor, string $permission): bool
{
    $roles = [
        'harpp.read'=>['owner','admin','member'], 'harpp.manage'=>['owner','admin'],
        'harpp.decision.review'=>['owner','admin','member'],
        'harpp.workspace.read'=>['owner','admin','member'], 'harpp.workspace.manage'=>['owner','admin','member'],
        'harpp.project.read'=>['owner','admin','member'], 'harpp.project.manage'=>['owner','admin','member'],
        'harpp.participant.manage'=>['owner','admin','member'], 'harpp.decision.assign'=>['owner','admin','member'],
        'harpp.decision.approve'=>['owner','admin','member'],
        'harpp.deploy.read'=>['owner','admin','member'], 'harpp.deploy.request'=>['owner','admin'],
        'harpp.deploy.inventory'=>['owner','admin'], 'harpp.deploy.claim'=>['owner','admin'], 'harpp.deploy.report'=>['owner','admin'],
    ];
    return (int)($actor['id'] ?? 0) > 0 && in_array((string)($actor['role'] ?? ''), $roles[$permission] ?? [], true);
}
function harppCapabilityData(mixed $payload, string $permission): array
{
    $data = is_array($payload) ? $payload : [];
    foreach (['user', 'actor', 'actor_user_id', 'tenant_id', 'store_id', '_tenant_id'] as $authorityField) {
        if (array_key_exists($authorityField, $data)) return [$data, [], false];
    }
    $actor = harppCapabilityActor();
    return [$data, $actor, harppCapabilityPermission($actor, $permission)];
}
function harppCapabilityResult(array|Harpp\Services\HarppServiceResult $result): array
{
    $wire = $result instanceof Harpp\Services\HarppServiceResult ? $result->toArray() : $result;
    $convert = static function(mixed $value, ?string $key = null) use (&$convert): mixed {
        if (is_array($value)) {
            foreach ($value as $childKey => $child) $value[$childKey] = $convert($child, is_string($childKey) ? $childKey : null);
            return $value;
        }
        if (($key === 'id' || ($key !== null && str_ends_with($key, '_id'))) && (is_int($value) || (is_string($value) && ctype_digit($value)))) return (string)$value;
        return $value;
    };
    return $convert($wire);
}
function harpp_cap_lifecycle_transition_2(mixed $payload, string $capabilityId='', string $providerId=''): array
{
    [$d,$a,$ok]=harppCapabilityData($payload,'harpp.decision.review');if(!$ok)return['ok'=>false,'status'=>403,'error'=>'Forbidden.'];if(!array_key_exists('expected_version',$d)||(int)$d['expected_version']<1||trim((string)($d['idempotency_key']??''))==='')return['ok'=>false,'status'=>422,'code'=>'mutation_contract_required','error'=>'expected_version and Idempotency-Key are required.'];
    $foundation=new HarppFoundationService(harppDb());$claim=$foundation->claimIdempotency('tenant:'.harppCurrentTenantId().':decision:'.(int)($d['decision_id']??0),$a,'lifecycle.transition',(string)($d['idempotency_key']??''),$d);
    if($claim['state']==='conflict')return['ok'=>false,'status'=>409,'code'=>'idempotency_conflict','error'=>'Idempotency key was already used with a different payload.'];if($claim['state']==='in_progress')return['ok'=>false,'status'=>409,'code'=>'idempotency_in_progress','error'=>'Idempotent operation is still processing.'];if($claim['state']==='replay')return harppCapabilityResult((array)$claim['response']+['idempotent_replay'=>true]);
    $r=(new HarppDecisionService(harppDb()))->transition($a,(int)($d['decision_id']??0),(string)($d['to_state']??''),(string)($d['rationale']??''),$d,harppCurrentTenantId());$wire=harppCapabilityResult($r);$foundation->completeIdempotency((int)($claim['id']??0),$wire,(int)($wire['status']??(!empty($wire['ok'])?200:422)));return$wire;
}
function harpp_cap_archive_1(mixed $payload, string $capabilityId='', string $providerId=''): array
{
    [$d,$a,$ok]=harppCapabilityData($payload,'harpp.manage');if(!$ok)return['ok'=>false,'status'=>403,'error'=>'Forbidden.'];if(!array_key_exists('expected_version',$d)||(int)$d['expected_version']<1)return['ok'=>false,'status'=>422,'code'=>'expected_version_required','error'=>'expected_version is required.'];return harppCapabilityResult((new HarppFoundationService(harppDb()))->archiveDecision($a,(int)($d['decision_id']??0),(int)$d['expected_version']));
}
function harpp_cap_purge_request_1(mixed $payload, string $capabilityId='', string $providerId=''): array
{
    [$d,$a,$ok]=harppCapabilityData($payload,'harpp.manage');if(!$ok||($a['role']??'')!=='owner')return['ok'=>false,'status'=>403,'error'=>'Owner access is required.'];return harppCapabilityResult((new HarppFoundationService(harppDb()))->requestPurge($a,(string)($d['resource_type']??''),(string)($d['resource_id']??''),trim((string)($d['reason']??'')),(int)($d['delay_seconds']??86400)));
}
function harpp_cap_purge_approve_1(mixed $payload, string $capabilityId='', string $providerId=''): array
{
    [$d,$a,$ok]=harppCapabilityData($payload,'harpp.manage');if(!$ok||($a['role']??'')!=='owner')return['ok'=>false,'status'=>403,'error'=>'Owner access is required.'];return harppCapabilityResult((new HarppFoundationService(harppDb()))->approvePurge($a,(int)($d['purge_request_id']??0)));
}
function harpp_cap_audit_read_1(mixed $payload, string $capabilityId='', string $providerId=''): array
{
    [$d,$a,$ok]=harppCapabilityData($payload,'harpp.read');if(!$ok)return['ok'=>false,'status'=>403,'error'=>'Forbidden.'];return harppCapabilityResult((new HarppFoundationService(harppDb()))->listAudit((string)($d['aggregate_type']??''),(string)($d['aggregate_id']??''),(int)($d['after_sequence']??0),(int)($d['limit']??100),$a));
}
function harpp_cap_outbox_dispatch_1(mixed $payload, string $capabilityId='', string $providerId=''): array
{
    [$d,$a,$ok]=harppCapabilityData($payload,'harpp.manage');if(!$ok)return['ok'=>false,'status'=>403,'error'=>'Forbidden.'];return harppCapabilityResult((new HarppFoundationService(harppDb()))->dispatchOutbox((int)($d['limit']??50)));
}
function harpp_cap_workspace_read_1(mixed $payload, string $capabilityId='', string $providerId=''): array
{
    [$d,$a,$ok]=harppCapabilityData($payload,'harpp.workspace.read');if(!$ok)return['ok'=>false,'status'=>403,'error'=>'Forbidden.'];return harppCapabilityResult((new HarppCollaborationService(harppDb()))->listWorkspaces($a));
}
function harpp_cap_workspace_manage_1(mixed $payload, string $capabilityId='', string $providerId=''): array
{
    [$d,$a,$ok]=harppCapabilityData($payload,'harpp.workspace.manage');if(!$ok)return['ok'=>false,'status'=>403,'error'=>'Forbidden.'];return harppCapabilityResult((new HarppCollaborationService(harppDb()))->manageWorkspace($a,$d));
}
function harpp_cap_project_read_1(mixed $payload, string $capabilityId='', string $providerId=''): array
{
    [$d,$a,$ok]=harppCapabilityData($payload,'harpp.project.read');if(!$ok)return['ok'=>false,'status'=>403,'error'=>'Forbidden.'];return harppCapabilityResult((new HarppCollaborationService(harppDb()))->listProjects($a,(int)($d['workspace_id']??0)));
}
function harpp_cap_project_manage_1(mixed $payload, string $capabilityId='', string $providerId=''): array
{
    [$d,$a,$ok]=harppCapabilityData($payload,'harpp.project.manage');if(!$ok)return['ok'=>false,'status'=>403,'error'=>'Forbidden.'];return harppCapabilityResult((new HarppCollaborationService(harppDb()))->manageProject($a,$d));
}
function harpp_cap_participant_manage_1(mixed $payload, string $capabilityId='', string $providerId=''): array
{
    [$d,$a,$ok]=harppCapabilityData($payload,'harpp.participant.manage');if(!$ok)return['ok'=>false,'status'=>403,'error'=>'Forbidden.'];return harppCapabilityResult((new HarppCollaborationService(harppDb()))->manageParticipant($a,$d));
}
function harpp_cap_message_receipt_1(mixed $payload, string $capabilityId='', string $providerId=''): array
{
    [$d,$a,$ok]=harppCapabilityData($payload,'harpp.read');if(!$ok)return['ok'=>false,'status'=>403,'error'=>'Forbidden.'];return harppCapabilityResult((new HarppCollaborationService(harppDb()))->recordReceipt($a,(int)($d['message_id']??0)));
}
function harpp_cap_decision_assign_1(mixed $payload, string $capabilityId='', string $providerId=''): array
{
    [$d,$a,$ok]=harppCapabilityData($payload,'harpp.decision.assign');if(!$ok)return['ok'=>false,'status'=>403,'error'=>'Forbidden.'];return harppCapabilityResult((new HarppCollaborationService(harppDb()))->assignDecision($a,$d));
}
function harpp_cap_decision_approve_1(mixed $payload, string $capabilityId='', string $providerId=''): array
{
    [$d,$a,$ok]=harppCapabilityData($payload,'harpp.decision.approve');if(!$ok)return['ok'=>false,'status'=>403,'error'=>'Forbidden.'];return harppCapabilityResult((new HarppCollaborationService(harppDb()))->approveDecision($a,$d));
}
function harpp_cap_notification_preferences_1(mixed $payload, string $capabilityId='', string $providerId=''): array
{
    [$d,$a,$ok]=harppCapabilityData($payload,'harpp.read');if(!$ok)return['ok'=>false,'status'=>403,'error'=>'Forbidden.'];return harppCapabilityResult((new HarppCollaborationService(harppDb()))->notificationPreferences($a,$d));
}
function harpp_cap_deploy_read_1(mixed $payload, string $capabilityId='', string $providerId=''): array
{
    return harppPermissionResult('harpp.deploy.read', $payload);
}
function harpp_cap_deploy_request_1(mixed $payload, string $capabilityId='', string $providerId=''): array
{
    return harppPermissionResult('harpp.deploy.request', $payload);
}
function harpp_cap_deploy_inventory_1(mixed $payload, string $capabilityId='', string $providerId=''): array
{
    [$d,$a,$ok]=harppCapabilityData($payload,'harpp.bridge');if(!$ok)return['ok'=>false,'status'=>403,'error'=>'Forbidden.'];return harppCapabilityResult((new HarppDeployService(harppDb()))->registerInventory($a,$d));
}
function harpp_cap_deploy_claim_1(mixed $payload, string $capabilityId='', string $providerId=''): array
{
    [$d,$a,$ok]=harppCapabilityData($payload,'harpp.bridge');if(!$ok)return['ok'=>false,'status'=>403,'error'=>'Forbidden.'];return harppCapabilityResult((new HarppDeployService(harppDb()))->claim($a,(int)($d['deploy_id']??0)));
}
function harpp_cap_deploy_report_1(mixed $payload, string $capabilityId='', string $providerId=''): array
{
    [$d,$a,$ok]=harppCapabilityData($payload,'harpp.bridge');if(!$ok)return['ok'=>false,'status'=>403,'error'=>'Forbidden.'];return harppCapabilityResult((new HarppDeployService(harppDb()))->report($a,(int)($d['deploy_id']??0),$d));
}
function harpp_cap_entity_list_conversation_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    $data = is_array($payload) ? $payload : [];
    $actor = harppCapabilityActor($data);
    $result=(new HarppMessagingService(harppDb()))->listConversations($actor,$data,harppCurrentTenantId());$wire=$result->toArray();$rows=(array)($wire['data']['conversations']??[]);
    return $wire+['allowed'=>!empty($wire['ok']),'rows'=>$rows,'total'=>count($rows),'tenant_id'=>harppCurrentTenantId()];
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
