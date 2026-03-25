<?php

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

require __DIR__ . '/../bootstrap.php';
require SRC_PATH . '/helpers/module-manager.php';

$antiSpamHelpers = BASE_PATH . '/modules/anti-spam/helpers.php';
if (!is_file($antiSpamHelpers)) {
    fwrite(STDERR, "anti-spam helpers not found at {$antiSpamHelpers}\n");
    exit(1);
}
require_once $antiSpamHelpers;

$routes = ['GET' => [], 'POST' => [], 'PUT' => [], 'DELETE' => []];
loadModuleRoutes($routes);

$enabledModules = getEnabledModules();
if (!isset($enabledModules['ticketing'])) {
    fwrite(STDERR, "ticketing module must be enabled for this regression test.\n");
    exit(1);
}
if (!app()->capabilities()->has('antispam.check@1')) {
    fwrite(STDERR, "antispam.check@1 capability is not registered.\n");
    exit(1);
}

$tenantCacheKey = moduleTenantSettingsTenantId() ?? 0;
$originalCache = $GLOBALS['_antispam_settings_cache'] ?? null;
$GLOBALS['_antispam_settings_cache'][$tenantCacheKey] = array_merge(
    antispamDefaultSettings(),
    [
        'enabled' => '1',
        'auto_protect_web_apis' => '1',
        'skip_authenticated_api_users' => '1',
        'honeypot_enabled' => '1',
        'rate_limit_enabled' => '0',
        'keyword_block_enabled' => '1',
        'blocked_keywords' => 'buy now cheap',
    ]
);

$originalServer = $_SERVER;
$originalGet = $_GET;
$originalPost = $_POST;

$_SERVER['REQUEST_URI'] = '/api/v1/tickets/public-submit';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_GET = [];
$_POST = [
    'contact_name' => 'Spam Test',
    'subject' => 'buy now cheap plumbing fix',
    'description' => 'This should be blocked by the kernel anti-spam gate before captcha validation.',
    'captcha_token' => 'ignored-by-gate',
    'captcha_answer' => '0',
];

ob_start();
executeModuleHandler('ticketing:apiPublicSubmitTicket');
$output = ob_get_clean();
$status = http_response_code();
$json = json_decode((string) $output, true);

$_SERVER = $originalServer;
$_GET = $originalGet;
$_POST = $originalPost;

if ($originalCache === null) {
    unset($GLOBALS['_antispam_settings_cache']);
} else {
    $GLOBALS['_antispam_settings_cache'] = $originalCache;
}

$ok = true;
if ($status !== 422) {
    fwrite(STDERR, "Expected HTTP 422, got {$status}.\n");
    $ok = false;
}
if (!is_array($json)) {
    fwrite(STDERR, "Expected JSON response, got: {$output}\n");
    $ok = false;
} else {
    if (($json['ok'] ?? true) !== false) {
        fwrite(STDERR, "Expected ok=false, got: " . json_encode($json) . "\n");
        $ok = false;
    }
    if (($json['check'] ?? '') !== 'keyword') {
        fwrite(STDERR, "Expected check=keyword, got: " . json_encode($json) . "\n");
        $ok = false;
    }
    if (($json['error'] ?? '') !== 'Request blocked by anti-spam') {
        fwrite(STDERR, "Unexpected error message: " . json_encode($json) . "\n");
        $ok = false;
    }
}

if (!$ok) {
    exit(1);
}

fwrite(STDOUT, "PASS: kernel anti-spam gate blocked ticketing public API request before handler execution.\n");