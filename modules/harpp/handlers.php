<?php

declare(strict_types=1);

use Harpp\Services\HarppAuthService;
use Harpp\Services\HarppPasswordResetService;
use Harpp\Services\HarppSettingsService;
use Harpp\Services\HarppDecisionService;
use Harpp\Services\HarppMessagingService;
use Harpp\Services\HarppNotificationService;
use Harpp\Services\HarppPushService;
use Harpp\Services\HarppAdrService;
use Harpp\Services\HarppBridgeAuthService;
use Harpp\Services\HarppBridgeService;

require_once __DIR__ . '/helpers.php';

// Register HARPP's declarative decision/ADR entity-view contracts at module boot.
\Ikabud\Kernel\DiSyL\TemplateEngine::loadViewConfigs(__DIR__ . '/helpers/views');

function harppHandle(callable $handler): void
{
    try {
        $handler();
    } catch (Throwable $e) {
        if (function_exists('write_log')) {
            write_log('HARPP handler failed', 'error', ['module' => 'harpp', 'error' => $e->getMessage()]);
        }
        harppJson(['ok' => false, 'error' => 'HARPP request failed.', 'status' => 500], 500);
    }
}

function harppLoginPageContext(array $overrides = []): array
{
    return array_merge([
        'page_title' => 'HARPP Sign In',
        'login_endpoint' => '/api/v1/harpp/auth/login',
        'login_username_label' => 'Email',
        'login_button_text' => 'Open HARPP',
        'login_loading_text' => 'Signing in...',
        'login_brand_html' => 'HARPP',
        'login_subtitle' => 'Harness decision center and messenger',
        'login_forgot_url' => '/harpp/forgot-password',
        'login_forgot_text' => 'Forgot password?',
    ], $overrides);
}

function harppPageLogin(array $params = []): void
{
    echo app()->render('modules/harpp/login', harppLoginPageContext());
}

function harppPageUser(): ?array
{
    $session = (new HarppAuthService())->authenticateRequest();
    if (empty($session['ok'])) {
        http_response_code(302);
        header('Location: /harpp/login');
        return null;
    }
    return (array)$session['data']['user'];
}

function harppRenderShell(string $template, string $page, array $context = []): void
{
    $user = harppPageUser();
    if ($user === null) return;
    echo app()->render('modules/harpp/' . $template, $context + [
        'page_title' => 'HARPP ' . ucfirst($page),
        'current_page' => $page,
        'user' => $user,
    ]);
}

function harppPageMessenger(array $params = []): void { harppRenderShell('messenger', 'messenger'); }
function harppPageDecisions(array $params = []): void { harppRenderShell('decisions', 'decisions'); }
function harppPageDecisionDetail(array $params = []): void { harppRenderShell('decision-detail', 'decisions', ['decision_id' => max(0, (int)($params['id'] ?? 0))]); }
function harppPageSettings(array $params = []): void { harppRenderShell('settings', 'settings'); }
function harppPageNotifications(array $params = []): void { harppRenderShell('notifications', 'notifications'); }

function harppServiceWorker(array $params = []): void { harppPwaAsset(['name' => 'sw.js']); }
function harppManifest(array $params = []): void { harppPwaAsset(['name' => 'manifest.webmanifest']); }
function harppIcon(array $params = []): void { harppPwaAsset(['name' => 'icon.svg']); }

function harppPwaAsset(array $params = []): void
{
    $name = (string)($params['name'] ?? '');
    $allowed = [
        'sw.js' => 'application/javascript; charset=utf-8',
        'manifest.webmanifest' => 'application/manifest+json; charset=utf-8',
        'icon.svg' => 'image/svg+xml; charset=utf-8',
    ];
    if (!isset($allowed[$name])) { http_response_code(404); return; }
    $path = __DIR__ . '/assets/' . $name;
    if (!is_file($path)) { http_response_code(404); return; }
    header('Content-Type: ' . $allowed[$name]);
    header('Cache-Control: no-cache');
    readfile($path);
}

function harppPageForgotPassword(array $params = []): void
{
    echo app()->render('pages/forgot-password.disyl', harppLoginPageContext([
        'page_title' => 'HARPP Password Reset',
        'forgot_password_endpoint' => '/api/v1/harpp/auth/forgot-password',
        'login_page_url' => '/harpp/login',
    ]));
}

function harppPageResetPassword(array $params = []): void
{
    echo app()->render('pages/reset-password.disyl', harppLoginPageContext([
        'page_title' => 'Set a New HARPP Password',
        'reset_password_endpoint' => '/api/v1/harpp/auth/reset-password',
        'login_page_url' => '/harpp/login',
        'reset_token' => trim((string)($_GET['token'] ?? '')),
        'token_valid' => preg_match('/^[a-f0-9]{64}$/', trim((string)($_GET['token'] ?? ''))) === 1,
    ]));
}

function harppAuthenticated(string $capability): ?array
{
    $session = (new HarppAuthService())->authenticateRequest();
    if (empty($session['ok'])) {
        harppJson($session);
        return null;
    }
    $user = $session['data']['user'];
    $access = harppAuthorize($capability, $user);
    if (empty($access['ok'])) {
        harppJson($access);
        return null;
    }
    return $user;
}

function harppAuthLogin(array $params = []): void
{
    harppHandle(function (): void {
        $input = harppInput();
        $email = (string)($input['email'] ?? $input['username'] ?? '');
        $result = (new HarppAuthService())->login($email, (string)($input['password'] ?? ''));
        if (!empty($result['ok'])) {
            $result['data']['redirect'] = '/harpp';
            if (method_exists(app(), 'csrfRotate')) {
                app()->csrfRotate(true);
            }
        }
        harppJson($result);
    });
}

function harppAuthRefresh(array $params = []): void
{
    harppHandle(fn() => harppJson((new HarppAuthService())->refresh((string)(harppInput()['token'] ?? ''))));
}

function harppAuthLogout(array $params = []): void
{
    harppHandle(fn() => harppJson((new HarppAuthService())->logout()));
}

function harppAuthMe(array $params = []): void
{
    harppHandle(function (): void {
        $user = harppAuthenticated('harpp.read@1');
        if ($user !== null) {
            harppJson(['ok' => true, 'data' => ['user' => $user, 'store_id' => harppCurrentTenantId()]]);
        }
    });
}

function harppAuthProfile(array $params = []): void
{
    harppHandle(function (): void {
        $user = harppAuthenticated('harpp.read@1');
        if ($user !== null) {
            harppJson((new HarppAuthService())->updateProfile((int)$user['id'], harppInput()));
        }
    });
}

function harppAuthForgotPassword(array $params = []): void
{
    harppHandle(function (): void {
        $input = harppInput();
        harppJson((new HarppPasswordResetService())->forgotPassword((string)($input['email'] ?? '')));
    });
}

function harppAuthResetPassword(array $params = []): void
{
    harppHandle(function (): void {
        $input = harppInput();
        harppJson((new HarppPasswordResetService())->resetPassword(
            (string)($input['token'] ?? ''),
            (string)($input['password'] ?? ''),
            (string)($input['confirm_password'] ?? '')
        ));
    });
}

function harppAuthInvite(array $params = []): void
{
    harppHandle(function (): void {
        $user = harppAuthenticated('harpp.manage@1');
        if ($user !== null) {
            harppJson((new HarppAuthService())->invite($user, harppInput()), null);
        }
    });
}

function harppAuthSelectTenant(array $params = []): void
{
    harppHandle(function (): void {
        $user = harppAuthenticated('harpp.read@1');
        if ($user !== null) {
            $input = harppInput();
            harppJson((new HarppAuthService())->selectTenant($user, (int)($input['store_id'] ?? $input['tenant_id'] ?? 0)));
        }
    });
}

function harppSettingsGet(array $params = []): void
{
    harppHandle(function (): void {
        if (harppAuthenticated('harpp.settings.read@1') !== null) {
            harppJson((new HarppSettingsService())->get(harppCurrentTenantId()));
        }
    });
}

function harppSettingsSave(array $params = []): void
{
    harppHandle(function (): void {
        if (harppAuthenticated('harpp.settings.manage@1') !== null) {
            $input = harppInput();
            $settings = is_array($input['settings'] ?? null) ? $input['settings'] : $input;
            harppJson((new HarppSettingsService())->save($settings, harppCurrentTenantId()));
        }
    });
}

function harppRequestData(): array { return array_merge(is_array($_GET) ? $_GET : [], harppInput()); }
function harppDecisionCreate(array $params=[]):void { harppHandle(function():void{$u=harppAuthenticated('harpp.manage@1');if($u)harppJson((new HarppDecisionService())->create($u,harppInput(),harppCurrentTenantId()));}); }
function harppDecisionList(array $params=[]):void { harppHandle(function():void{$u=harppAuthenticated('harpp.decision.review@1');if($u)harppJson((new HarppDecisionService())->list($u,harppRequestData(),harppCurrentTenantId()));}); }
function harppDecisionGet(array $params=[]):void { harppHandle(function()use($params):void{$u=harppAuthenticated('harpp.decision.review@1');if($u)harppJson((new HarppDecisionService())->get($u,(int)($params['id']??0),harppCurrentTenantId()));}); }
function harppDecisionTransition(array $params=[]):void { harppHandle(function()use($params):void{$u=harppAuthenticated('harpp.decision.review@1');if($u){$i=harppInput();harppJson((new HarppDecisionService())->transition($u,(int)($params['id']??0),(string)($i['state']??$i['to_state']??''),(string)($i['rationale']??''),$i,harppCurrentTenantId()));}}); }

function harppConversationList(array $params=[]):void { harppHandle(function():void{$u=harppAuthenticated('harpp.read@1');if($u)harppJson((new HarppMessagingService())->listConversations($u,harppCurrentTenantId()));}); }
function harppConversationCreate(array $params=[]):void { harppHandle(function():void{$u=harppAuthenticated('harpp.read@1');if($u)harppJson((new HarppMessagingService())->createConversation($u,harppInput(),harppCurrentTenantId()));}); }
function harppMessageSend(array $params=[]):void { harppHandle(function()use($params):void{$u=harppAuthenticated('harpp.read@1');if($u)harppJson((new HarppMessagingService())->sendMessage($u,(int)($params['id']??0),harppInput(),harppCurrentTenantId()));}); }
function harppMessageList(array $params=[]):void { harppHandle(function()use($params):void{$u=harppAuthenticated('harpp.read@1');if($u)harppJson((new HarppMessagingService())->listMessages($u,(int)($params['id']??0),harppRequestData(),harppCurrentTenantId()));}); }
function harppMessageMarkRead(array $params=[]):void { harppHandle(function()use($params):void{$u=harppAuthenticated('harpp.read@1');if($u){$i=harppInput();harppJson((new HarppMessagingService())->markRead($u,(int)($params['id']??0),(int)($i['through_id']??0),harppCurrentTenantId()));}}); }

function harppNotificationList(array $params=[]):void { harppHandle(function():void{$u=harppAuthenticated('harpp.read@1');if($u)harppJson((new HarppNotificationService())->list($u,harppRequestData(),harppCurrentTenantId()));}); }
function harppNotificationMarkRead(array $params=[]):void { harppHandle(function()use($params):void{$u=harppAuthenticated('harpp.read@1');if($u)harppJson((new HarppNotificationService())->markRead($u,(int)($params['id']??0),harppCurrentTenantId()));}); }
function harppNotificationUnread(array $params=[]):void { harppHandle(function():void{$u=harppAuthenticated('harpp.read@1');if($u)harppJson((new HarppNotificationService())->unreadCount($u,harppCurrentTenantId()));}); }

function harppPushSubscribe(array $params=[]):void { harppHandle(function():void{$u=harppAuthenticated('harpp.read@1');if($u)harppJson((new HarppPushService())->subscribe($u,harppInput(),harppCurrentTenantId()));}); }
function harppPushUnsubscribe(array $params=[]):void { harppHandle(function():void{$u=harppAuthenticated('harpp.read@1');if($u){$i=harppInput();harppJson((new HarppPushService())->unsubscribe($u,(string)($i['endpoint']??''),harppCurrentTenantId()));}}); }
function harppPushPublicKey(array $params=[]):void { harppHandle(function():void{if(harppAuthenticated('harpp.read@1'))harppJson((new HarppPushService())->publicKey(harppCurrentTenantId()));}); }

function harppAdrRecord(array $params=[]):void { harppHandle(function():void{$u=harppAuthenticated('harpp.manage@1');if($u)harppJson((new HarppAdrService())->record($u,harppInput(),harppCurrentTenantId()));}); }
function harppAdrList(array $params=[]):void { harppHandle(function():void{$u=harppAuthenticated('harpp.read@1');if($u)harppJson((new HarppAdrService())->list($u,harppRequestData(),harppCurrentTenantId()));}); }

function harppBridgeOwner(): ?array
{
    $user = harppAuthenticated('harpp.manage@1');
    if ($user !== null && ($user['source'] ?? 'harpp') === 'harpp' && ($user['role'] ?? '') === 'owner') return $user;
    if ($user !== null) harppJson(['ok'=>false,'error'=>'Owner access is required.','status'=>403,'code'=>'owner_required'],403);
    return null;
}

function harppBridgeKeyGet(array $params=[]): void
{
    harppHandle(function():void { if (harppBridgeOwner() !== null) harppJson((new HarppBridgeAuthService(harppDb()))->generate(harppCurrentTenantId())); });
}
function harppBridgeKeyRotate(array $params=[]): void
{
    harppHandle(function():void { if (harppBridgeOwner() !== null) harppJson((new HarppBridgeAuthService(harppDb()))->rotate(harppCurrentTenantId())); });
}

function harppBridgeAuthenticated(): ?array
{
    $key = trim((string)($_SERVER['HTTP_X_HARPP_BRIDGE_KEY'] ?? ''));
    $tenant = (int)($_SERVER['HTTP_X_HARPP_TENANT_ID'] ?? 0);
    $client = trim((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    $result = (new HarppBridgeAuthService(harppDb()))->validate($key, $tenant, $client);
    if (empty($result['ok'])) { harppJson($result); return null; }
    return (array)$result['data']['actor'];
}

function harppBridgeDecisionCreate(array $params=[]):void { harppHandle(function():void{$a=harppBridgeAuthenticated();if($a)harppJson((new HarppBridgeService(harppDb()))->createDecision($a,harppInput(),harppCurrentTenantId()));}); }
function harppBridgeDecisionList(array $params=[]):void { harppHandle(function():void{$a=harppBridgeAuthenticated();if($a)harppJson((new HarppBridgeService(harppDb()))->listDecisions($a,harppRequestData(),harppCurrentTenantId()));}); }
function harppBridgeDecisionAcknowledge(array $params=[]):void { harppHandle(function()use($params):void{$a=harppBridgeAuthenticated();if($a)harppJson((new HarppBridgeService(harppDb()))->acknowledge($a,(int)($params['id']??0),harppInput(),harppCurrentTenantId()));}); }
function harppBridgeDecisionApplied(array $params=[]):void { harppHandle(function()use($params):void{$a=harppBridgeAuthenticated();if($a)harppJson((new HarppBridgeService(harppDb()))->applied($a,(int)($params['id']??0),harppInput(),harppCurrentTenantId()));}); }
function harppBridgeMessageSend(array $params=[]):void { harppHandle(function():void{$a=harppBridgeAuthenticated();if($a)harppJson((new HarppBridgeService(harppDb()))->sendMessage($a,harppInput(),harppCurrentTenantId()));}); }
function harppBridgeMessageList(array $params=[]):void { harppHandle(function():void{$a=harppBridgeAuthenticated();if($a)harppJson((new HarppBridgeService(harppDb()))->pollMessages($a,harppRequestData(),harppCurrentTenantId()));}); }
function harppBridgeStatus(array $params=[]):void { harppHandle(function():void{$a=harppBridgeAuthenticated();if($a)harppJson((new HarppBridgeService(harppDb()))->status($a,harppInput(),harppCurrentTenantId()));}); }
