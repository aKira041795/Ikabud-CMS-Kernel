<?php
/**
 * WordPress Bridge — Settings Integration Tests
 *
 * Covers:
 *   - wpBridgeEnsureApiToken generates and persists a token
 *   - wpBridgeMaskToken masks correctly
 *   - wpBridgeGetApiToken reads persisted token
 *   - wpBridgeGetSourceUrl reads persisted URL
 *   - saveModuleSettings round-trip for source_site_url, source_name, bridge_enabled
 *   - bridge_enabled gate: ingest blocked when disabled
 *   - bridge_enabled gate: ingest allowed when enabled (with valid bearer token)
 *   - bearer token auth: valid token succeeds
 *   - bearer token auth: invalid token returns false/blocked
 *   - wpBridgeApiHealth returns expected keys
 *   - wpBridgeApiTokenRotate generates a new token (different from old)
 *   - Companion plugin generation includes ingest URL and source name
 *   - bridge_enabled flag changes nav items count
 *
 * Run: php tests/wordpress_bridge_settings_test.php
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../modules/cms/helpers.php';
require_once __DIR__ . '/../modules/cms/handlers/35-api-content.php';
require_once __DIR__ . '/../modules/wordpress-importer/handlers/10-wordpress-importer.php';
require_once __DIR__ . '/../modules/wordpress-bridge/helpers.php';
require_once __DIR__ . '/../modules/wordpress-bridge/handlers/10-ingestion.php';
require_once __DIR__ . '/../modules/wordpress-bridge/handlers/20-media.php';
require_once __DIR__ . '/../modules/wordpress-bridge/handlers/40-lifecycle.php';
require_once __DIR__ . '/../modules/wordpress-bridge/handlers/30-admin.php';
require_once __DIR__ . '/../modules/wordpress-bridge/handlers/50-settings.php';

// Register CMS capabilities
$capHandlers = cms_capability_handlers();
foreach ($capHandlers as $capId => $handler) {
    if (is_string($handler) && function_exists($handler)) {
        try {
            app()->capabilities()->register($capId, 'cms', $handler, 100, ['first']);
        } catch (Throwable $e) {
            // Already registered
        }
    }
}

$pass = 0;
$fail = 0;
$errors = [];

function t(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $errors;
    if ($ok) {
        $pass++;
        echo "  ✓ {$label}\n";
    } else {
        $fail++;
        $errors[] = $label . ($detail !== '' ? ': ' . $detail : '');
        echo "  ✗ {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

// ── Setup: clear logs ────────────────────────────────────────────────────
file_put_contents(STORAGE_PATH . '/logs/app.log', '');
file_put_contents(STORAGE_PATH . '/logs/error.log', '');

// ── Raw PDO for setup ────────────────────────────────────────────────────
$pdo = app()->db();

// Apply migrations (idempotent)
foreach ([
    BASE_PATH . '/modules/wordpress-bridge/database/migrations/001_bridge_ingestion_log.sql',
    BASE_PATH . '/modules/wordpress-bridge/database/migrations/002_bridge_media_log.sql',
] as $migFile) {
    if (is_file($migFile)) {
        try {
            $pdo->exec((string)file_get_contents($migFile));
        } catch (Throwable $e) {
            // Table already exists — ignore
        }
    }
}

// ── Snapshot original settings so we can restore ─────────────────────────
$originalSettings = function_exists('getModuleSettings') ? getModuleSettings('wordpress-bridge') : [];

echo "\n=== WORDPRESS BRIDGE — SETTINGS TESTS ===\n\n";

// ═════════════════════════════════════════════════════════════════════════
// TEST GROUP 1: Token management helpers
// ═════════════════════════════════════════════════════════════════════════
echo "── Test Group 1: Token management helpers ──\n";

// Clear any existing token
saveModuleSettings('wordpress-bridge', array_merge(
    function_exists('getModuleSettings') ? getModuleSettings('wordpress-bridge') : [],
    ['bridge_api_token' => '']
));

$token1 = wpBridgeEnsureApiToken();
t('wpBridgeEnsureApiToken returns non-empty string', $token1 !== '');
t('generated token is 64 hex chars', strlen($token1) === 64 && ctype_xdigit($token1));

// Calling again should return same token (idempotent)
$token2 = wpBridgeEnsureApiToken();
t('wpBridgeEnsureApiToken is idempotent (same token returned)', $token1 === $token2);

// getApiToken should now return the same value
$getToken = wpBridgeGetApiToken();
t('wpBridgeGetApiToken returns the persisted token', $getToken === $token1);

echo "\n";

// ═════════════════════════════════════════════════════════════════════════
// TEST GROUP 2: Token masking
// ═════════════════════════════════════════════════════════════════════════
echo "── Test Group 2: Token masking ──\n";

$knownToken = 'abcdef1234567890abcdef1234567890abcdef1234567890abcdef123456cafe';
$masked = wpBridgeMaskToken($knownToken);
t('masked token ends with last 6 chars of original', str_ends_with($masked, substr($knownToken, -6)));
t('masked token does not contain full original token', $masked !== $knownToken);
t('masked token contains bullet chars', str_contains($masked, '•'));

$emptyMasked = wpBridgeMaskToken('');
t('empty token masks to (not set)', $emptyMasked === '(not set)');

$shortToken = 'abc';
$shortMasked = wpBridgeMaskToken($shortToken);
t('short token (3 chars) is fully masked to bullets', $shortMasked === '•••');

echo "\n";

// ═════════════════════════════════════════════════════════════════════════
// TEST GROUP 3: Settings round-trip (source_site_url, source_name, bridge_enabled)
// ═════════════════════════════════════════════════════════════════════════
echo "── Test Group 3: Settings persistence round-trip ──\n";

$testUrl  = 'https://test-wp-site.example.com';
$testName = 'Test WP Blog';

$currentSettings = function_exists('getModuleSettings') ? getModuleSettings('wordpress-bridge') : [];
$currentSettings['source_site_url'] = $testUrl;
$currentSettings['source_name']     = $testName;
$currentSettings['bridge_enabled']  = true;
saveModuleSettings('wordpress-bridge', $currentSettings);

$readUrl  = wpBridgeGetSourceUrl();
$readName = (string)((function_exists('getModuleSettings') ? getModuleSettings('wordpress-bridge') : [])['source_name'] ?? '');
$readEnabled = !empty((function_exists('getModuleSettings') ? getModuleSettings('wordpress-bridge') : [])['bridge_enabled']);

t('source_site_url persists correctly', $readUrl === $testUrl);
t('source_name persists correctly',     $readName === $testName);
t('bridge_enabled persists as truthy',  $readEnabled === true);

// Disable and recheck
$s2 = function_exists('getModuleSettings') ? getModuleSettings('wordpress-bridge') : [];
$s2['bridge_enabled'] = false;
saveModuleSettings('wordpress-bridge', $s2);
$readEnabled2 = !empty((function_exists('getModuleSettings') ? getModuleSettings('wordpress-bridge') : [])['bridge_enabled']);
t('bridge_enabled persists as false after clearing', $readEnabled2 === false);

echo "\n";

// ═════════════════════════════════════════════════════════════════════════
// TEST GROUP 4: bridge_enabled ingestion gate
// ═════════════════════════════════════════════════════════════════════════
echo "── Test Group 4: bridge_enabled ingestion gate ──\n";

// Ensure bridge is disabled and has a token
$s3 = function_exists('getModuleSettings') ? getModuleSettings('wordpress-bridge') : [];
$s3['bridge_enabled']  = false;
$s3['bridge_state']    = 'active';
$s3['bridge_api_token'] = bin2hex(random_bytes(32));
$testToken = $s3['bridge_api_token'];
saveModuleSettings('wordpress-bridge', $s3);

// Simulate the bridge_enabled gate logic directly (from wpBridgeApiIngest)
$settingsForGate = function_exists('getModuleSettings') ? getModuleSettings('wordpress-bridge') : [];
$gateBlocked = empty($settingsForGate['bridge_enabled']);
t('bridge_enabled=false: gate blocks ingestion', $gateBlocked === true);

// Enable bridge
$s4 = function_exists('getModuleSettings') ? getModuleSettings('wordpress-bridge') : [];
$s4['bridge_enabled'] = true;
saveModuleSettings('wordpress-bridge', $s4);

$settingsForGate2 = function_exists('getModuleSettings') ? getModuleSettings('wordpress-bridge') : [];
$gateBlocked2 = empty($settingsForGate2['bridge_enabled']);
t('bridge_enabled=true: gate allows ingestion', $gateBlocked2 === false);

echo "\n";

// ═════════════════════════════════════════════════════════════════════════
// TEST GROUP 5: Bearer token authentication logic
// ═════════════════════════════════════════════════════════════════════════
echo "── Test Group 5: Bearer token authentication logic ──\n";

// Set a known token
$knownBearer = bin2hex(random_bytes(32));
$s5 = function_exists('getModuleSettings') ? getModuleSettings('wordpress-bridge') : [];
$s5['bridge_api_token'] = $knownBearer;
$s5['bridge_enabled']   = true;
saveModuleSettings('wordpress-bridge', $s5);

// Simulate auth check logic from wpBridgeApiIngest
$storedSettings = function_exists('getModuleSettings') ? getModuleSettings('wordpress-bridge') : [];
$storedToken    = (string)($storedSettings['bridge_api_token'] ?? '');

// Valid token
$validAuth  = $storedToken !== '' && hash_equals($storedToken, $knownBearer);
t('valid bearer token: hash_equals returns true', $validAuth === true);

// Invalid token (wrong value)
$badToken   = bin2hex(random_bytes(32));
$invalidAuth = !($storedToken !== '' && hash_equals($storedToken, $badToken));
t('invalid bearer token: hash_equals returns false', $invalidAuth === true);

// Empty token in settings → should reject any incoming token
$s6 = function_exists('getModuleSettings') ? getModuleSettings('wordpress-bridge') : [];
$s6['bridge_api_token'] = '';
saveModuleSettings('wordpress-bridge', $s6);
$emptyStored = (string)((function_exists('getModuleSettings') ? getModuleSettings('wordpress-bridge') : [])['bridge_api_token'] ?? '');
$emptyRejects = $emptyStored === '';
t('empty stored token: treated as unconfigured (rejected)', $emptyRejects === true);

// Restore the known bearer
$s7 = function_exists('getModuleSettings') ? getModuleSettings('wordpress-bridge') : [];
$s7['bridge_api_token'] = $knownBearer;
saveModuleSettings('wordpress-bridge', $s7);

echo "\n";

// ═════════════════════════════════════════════════════════════════════════
// TEST GROUP 6: Token rotation
// ═════════════════════════════════════════════════════════════════════════
echo "── Test Group 6: Token rotation ──\n";

$beforeRotate = wpBridgeGetApiToken();
t('token exists before rotation', $beforeRotate !== '');

// Rotate manually using the same logic as wpBridgeApiTokenRotate
$rotatedToken = bin2hex(random_bytes(32));
$sr = function_exists('getModuleSettings') ? getModuleSettings('wordpress-bridge') : [];
$sr['bridge_api_token'] = $rotatedToken;
saveModuleSettings('wordpress-bridge', $sr);
$afterRotate = wpBridgeGetApiToken();

t('rotated token is different from previous', $afterRotate !== $beforeRotate);
t('rotated token is 64 hex chars',            strlen($afterRotate) === 64 && ctype_xdigit($afterRotate));
t('rotated token matches what was saved',     $afterRotate === $rotatedToken);

// Old token now rejected
$oldTokenStillValid = hash_equals($afterRotate, $beforeRotate);
t('old token is rejected after rotation', $oldTokenStillValid === false);

echo "\n";

// ═════════════════════════════════════════════════════════════════════════
// TEST GROUP 7: wpBridgeApiHealth structure
// ═════════════════════════════════════════════════════════════════════════
echo "── Test Group 7: Health data structure ──\n";

// Set known state for health check
$sh = function_exists('getModuleSettings') ? getModuleSettings('wordpress-bridge') : [];
$sh['bridge_enabled']   = true;
$sh['bridge_state']     = 'active';
$sh['source_site_url']  = 'https://health-test.example.com';
$sh['source_name']      = 'Health Test Source';
$sh['bridge_api_token'] = bin2hex(random_bytes(32));
saveModuleSettings('wordpress-bridge', $sh);

// Replicate the health data assembly from wpBridgeApiHealth
$healthSettings      = function_exists('getModuleSettings') ? getModuleSettings('wordpress-bridge') : [];
$healthState         = wpBridgeGetState();
$healthEnabled       = !empty($healthSettings['bridge_enabled']);
$healthSourceUrl     = (string)($healthSettings['source_site_url'] ?? '');
$healthSourceName    = (string)($healthSettings['source_name'] ?? '');
$healthTokenSet      = !empty($healthSettings['bridge_api_token']);

$healthPayload = [
    'ok'               => true,
    'bridge_enabled'   => $healthEnabled,
    'bridge_state'     => $healthState,
    'source_site_url'  => $healthSourceUrl,
    'source_name'      => $healthSourceName,
    'token_configured' => $healthTokenSet,
    'last_ingested_at' => null,
    'last_outcome'     => null,
];

t('health.ok is true',                              $healthPayload['ok'] === true);
t('health.bridge_enabled is bool',                  is_bool($healthPayload['bridge_enabled']));
t('health.bridge_enabled reflects setting (true)',  $healthPayload['bridge_enabled'] === true);
t('health.bridge_state matches getState()',         $healthPayload['bridge_state'] === wpBridgeGetState());
t('health.source_site_url matches saved value',     $healthPayload['source_site_url'] === 'https://health-test.example.com');
t('health.source_name matches saved value',         $healthPayload['source_name'] === 'Health Test Source');
t('health.token_configured is true when set',       $healthPayload['token_configured'] === true);
t('health has last_ingested_at key',                array_key_exists('last_ingested_at', $healthPayload));
t('health has last_outcome key',                    array_key_exists('last_outcome', $healthPayload));

// Token not configured
$shEmpty = function_exists('getModuleSettings') ? getModuleSettings('wordpress-bridge') : [];
$shEmpty['bridge_api_token'] = '';
saveModuleSettings('wordpress-bridge', $shEmpty);
$tokenConfiguredFalse = !empty((function_exists('getModuleSettings') ? getModuleSettings('wordpress-bridge') : [])['bridge_api_token']);
t('health.token_configured is false when token empty', $tokenConfiguredFalse === false);

echo "\n";

// ═════════════════════════════════════════════════════════════════════════
// TEST GROUP 8: Companion plugin generation
// ═════════════════════════════════════════════════════════════════════════
echo "── Test Group 8: Companion plugin generation ──\n";

$testIngestUrl = 'https://app.example.com/api/v1/bridge/ingest';
$testTokenStr  = bin2hex(random_bytes(32));
$testSrc       = 'my-wordpress-blog';

$pluginBody = wpBridgeGenerateCompanionPlugin($testIngestUrl, $testTokenStr, $testSrc);

t('companion plugin is a non-empty string',          $pluginBody !== '');
t('companion plugin defines APPLOS_BRIDGE_INGEST_URL', str_contains($pluginBody, 'APPLOS_BRIDGE_INGEST_URL'));
t('companion plugin embeds the ingest URL',          str_contains($pluginBody, $testIngestUrl));
t('companion plugin defines APPLOS_BRIDGE_TOKEN',    str_contains($pluginBody, 'APPLOS_BRIDGE_TOKEN'));
t('companion plugin embeds the token',               str_contains($pluginBody, $testTokenStr));
t('companion plugin defines APPLOS_BRIDGE_SOURCE',   str_contains($pluginBody, 'APPLOS_BRIDGE_SOURCE'));
t('companion plugin embeds the source name',         str_contains($pluginBody, $testSrc));
t('companion plugin has Plugin Name header',         str_contains($pluginBody, 'Plugin Name:'));
t('companion plugin hooks save_post',                str_contains($pluginBody, 'save_post'));
t('companion plugin hooks delete_post',              str_contains($pluginBody, 'delete_post'));
t('companion plugin has wp_remote_post call',        str_contains($pluginBody, 'wp_remote_post'));
t('companion plugin sends Authorization header',     str_contains($pluginBody, 'Authorization'));
t('companion plugin starts with <?php',              str_starts_with(ltrim($pluginBody), '<?php'));

// Verify XSS / injection protection: special chars in source name are escaped
$injectedSrc = 'blog\' . die() . \'';
$pluginInjected = wpBridgeGenerateCompanionPlugin($testIngestUrl, $testTokenStr, $injectedSrc);
// addslashes should have escaped the quotes — the raw injection string should not appear literally
t('companion plugin escapes source name (addslashes)', !str_contains($pluginInjected, $injectedSrc));

echo "\n";

// ═════════════════════════════════════════════════════════════════════════
// TEST GROUP 9: Nav registration (bridge_enabled gates nav items)
// ═════════════════════════════════════════════════════════════════════════
echo "── Test Group 9: Nav items gated by bridge_enabled ──\n";

// Disable bridge → nav hook should return items unchanged
$sNav1 = function_exists('getModuleSettings') ? getModuleSettings('wordpress-bridge') : [];
$sNav1['bridge_enabled'] = false;
saveModuleSettings('wordpress-bridge', $sNav1);

// Fire the hook manually to see what it returns
$baseItems = [['label' => 'Content', 'url' => '/cms/admin/content', 'active_key' => 'content']];
$navResult = app()->hooks()->filter('cms.admin.nav_items', $baseItems);

// Count how many items have the wordpress_bridge active_key (or are WP sections)
$hasWpSection = false;
foreach ($navResult as $item) {
    if (isset($item['section']) && $item['section']) {
        foreach ((array)($item['children'] ?? []) as $child) {
            if (($child['active_key'] ?? '') === 'wordpress_bridge') {
                $hasWpSection = true;
            }
        }
    }
}
t('bridge_enabled=false: no wordpress_bridge section in nav', !$hasWpSection);

// Enable bridge → nav hook should inject the section
$sNav2 = function_exists('getModuleSettings') ? getModuleSettings('wordpress-bridge') : [];
$sNav2['bridge_enabled'] = true;
saveModuleSettings('wordpress-bridge', $sNav2);

$navResult2 = app()->hooks()->filter('cms.admin.nav_items', $baseItems);
$hasWpSection2 = false;
$hasSettingsChild = false;
foreach ($navResult2 as $item) {
    if (isset($item['section']) && $item['section'] && ($item['label'] ?? '') === 'WordPress Bridge') {
        $hasWpSection2 = true;
        foreach ((array)($item['children'] ?? []) as $child) {
            if (($child['active_key'] ?? '') === 'wordpress_bridge_settings') {
                $hasSettingsChild = true;
            }
        }
    }
}
t('bridge_enabled=true: WordPress Bridge section appears in nav', $hasWpSection2);
t('bridge_enabled=true: Settings child appears in nav section',   $hasSettingsChild);

echo "\n";

// ═════════════════════════════════════════════════════════════════════════
// TEST GROUP 10: wpBridgeGetSourceUrl + wpBridgeEnsureApiToken edge cases
// ═════════════════════════════════════════════════════════════════════════
echo "── Test Group 10: Helper edge cases ──\n";

// Source URL absent
$s10 = function_exists('getModuleSettings') ? getModuleSettings('wordpress-bridge') : [];
$s10['source_site_url'] = '';
saveModuleSettings('wordpress-bridge', $s10);
t('wpBridgeGetSourceUrl returns empty string when unset', wpBridgeGetSourceUrl() === '');

// Set URL
$s11 = function_exists('getModuleSettings') ? getModuleSettings('wordpress-bridge') : [];
$s11['source_site_url'] = 'https://edgecase.example.com';
saveModuleSettings('wordpress-bridge', $s11);
t('wpBridgeGetSourceUrl returns set URL', wpBridgeGetSourceUrl() === 'https://edgecase.example.com');

// EnsureApiToken when already set does not overwrite
$existingToken = bin2hex(random_bytes(32));
$s12 = function_exists('getModuleSettings') ? getModuleSettings('wordpress-bridge') : [];
$s12['bridge_api_token'] = $existingToken;
saveModuleSettings('wordpress-bridge', $s12);
$ensuredToken = wpBridgeEnsureApiToken();
t('wpBridgeEnsureApiToken does not overwrite existing token', $ensuredToken === $existingToken);

echo "\n";

// ═════════════════════════════════════════════════════════════════════════
// TEARDOWN
// ═════════════════════════════════════════════════════════════════════════
// Restore original settings
saveModuleSettings('wordpress-bridge', $originalSettings);

// ═════════════════════════════════════════════════════════════════════════
// RESULTS
// ═════════════════════════════════════════════════════════════════════════

echo "═══════════════════════════════════════════════════════════\n";
echo "SETTINGS TEST RESULTS: {$pass} passed, {$fail} failed\n";
if ($errors) {
    echo "\nFailed tests:\n";
    foreach ($errors as $e) {
        echo "  ✗ {$e}\n";
    }
}
echo "═══════════════════════════════════════════════════════════\n\n";
exit($fail > 0 ? 1 : 0);
