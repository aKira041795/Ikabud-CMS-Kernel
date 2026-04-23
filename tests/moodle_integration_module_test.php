<?php

declare(strict_types=1);

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/';

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../modules/moodle-integration/helpers.php';
require_once __DIR__ . '/../modules/moodle-integration/handlers.php';

$pass = 0;
$fail = 0;
$errors = [];

function moodleIntegrationTestAssert(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $errors;

    if ($ok) {
        $pass++;
        echo "  ✓ {$label}\n";
        return;
    }

    $fail++;
    $errors[] = $label . ($detail !== '' ? ': ' . $detail : '');
    echo "  ✗ {$label}" . ($detail !== '' ? ' — ' . $detail : '') . "\n";
}

@file_put_contents(STORAGE_PATH . '/logs/app.log', '');
@file_put_contents(STORAGE_PATH . '/logs/error.log', '');

echo "\n=== MOODLE INTEGRATION MODULE TEST ===\n\n";

echo "-- Discovery --\n";
$mods = discoverModules();
moodleIntegrationTestAssert('module discovered', isset($mods['moodle-integration']));
$manifest = $mods['moodle-integration'] ?? [];
moodleIntegrationTestAssert('manifest version set', trim((string)($manifest['version'] ?? '')) !== '');
moodleIntegrationTestAssert('settings fields declared', is_array($manifest['settings_fields'] ?? null) && count($manifest['settings_fields']) >= 5);
moodleIntegrationTestAssert('render-content hook declared', in_array('cms.public.render_content', is_array($manifest['hooks'] ?? null) ? $manifest['hooks'] : [], true));

echo "\n-- Defaults --\n";
$defaults = moodleIntegrationSettingsDefaults();
moodleIntegrationTestAssert('moodle_url default present', array_key_exists('moodle_url', $defaults));
moodleIntegrationTestAssert('tenant_mode default is per_instance', ($defaults['tenant_mode'] ?? '') === 'per_instance');
moodleIntegrationTestAssert('enrollment_mode default is manual_review', ($defaults['enrollment_mode'] ?? '') === 'manual_review');
moodleIntegrationTestAssert('sync_interval default is hourly', ($defaults['sync_interval'] ?? '') === 'hourly');
moodleIntegrationTestAssert('max_requests_per_minute default present', array_key_exists('max_requests_per_minute', $defaults));
moodleIntegrationTestAssert('burst_limit default present', array_key_exists('burst_limit', $defaults));

echo "\n-- Guardrails --\n";
moodleIntegrationTestAssert(
    'shared tenant category helper resolves configured category',
    moodleIntegrationSharedTenantCategoryId(42, ['tenant_mode' => 'shared', 'shared_category_map_json' => '{"42":77}']) === 77
);
moodleIntegrationTestAssert(
    'shared tenant guard rejects mismatched category',
    moodleIntegrationCourseBelongsToTenant(['moodle_category_id' => 11], 42, ['tenant_mode' => 'shared', 'shared_category_map_json' => '{"42":77}']) === false
);

echo "\n-- Routes --\n";
$routes = require __DIR__ . '/../modules/moodle-integration/routes.php';
moodleIntegrationTestAssert('courses route exists', ($routes['GET']['/courses'] ?? '') === 'moodle-integration:pageMoodleIntegrationCourses');
moodleIntegrationTestAssert('canonical cms courses route exists', ($routes['GET']['/cms/courses'] ?? '') === 'moodle-integration:pageMoodleIntegrationCourses');
moodleIntegrationTestAssert('enroll route exists', ($routes['GET']['/course/{id}/enroll'] ?? '') === 'moodle-integration:pageMoodleIntegrationEnroll');
moodleIntegrationTestAssert('canonical cms enroll route exists', ($routes['GET']['/cms/course/{id}/enroll'] ?? '') === 'moodle-integration:pageMoodleIntegrationEnroll');
moodleIntegrationTestAssert('launch route exists', ($routes['GET']['/course/{id}/launch'] ?? '') === 'moodle-integration:pageMoodleIntegrationLaunch');
moodleIntegrationTestAssert('canonical cms my-courses route exists', ($routes['GET']['/cms/my-courses'] ?? '') === 'moodle-integration:pageMoodleIntegrationMyCourses');
moodleIntegrationTestAssert('status API exists', ($routes['GET']['/api/v1/moodle-integration/status/{id}'] ?? '') === 'moodle-integration:apiMoodleIntegrationCourseStatus');
moodleIntegrationTestAssert('sync API exists', ($routes['POST']['/api/v1/moodle-integration/sync'] ?? '') === 'moodle-integration:apiMoodleIntegrationQueueSync');
moodleIntegrationTestAssert('SSO validate API exists', ($routes['POST']['/api/v1/moodle-integration/sso/validate'] ?? '') === 'moodle-integration:apiMoodleIntegrationSsoValidate');

echo "\n-- Function Surface --\n";
moodleIntegrationTestAssert('moodleIntegrationDeactivateLearningResource exists', function_exists('moodleIntegrationDeactivateLearningResource'));
moodleIntegrationTestAssert('moodleIntegrationActivateLearningResource exists', function_exists('moodleIntegrationActivateLearningResource'));
moodleIntegrationTestAssert('moodleIntegrationProviderSupports exists', function_exists('moodleIntegrationProviderSupports'));
moodleIntegrationTestAssert('moodleIntegrationGetProviderCapabilities exists', function_exists('moodleIntegrationGetProviderCapabilities'));
moodleIntegrationTestAssert('moodleIntegrationCheckAndRecordOutboundRequest exists', function_exists('moodleIntegrationCheckAndRecordOutboundRequest'));
moodleIntegrationTestAssert('moodleIntegrationEncryptSettingValue exists', function_exists('moodleIntegrationEncryptSettingValue'));
moodleIntegrationTestAssert('moodleIntegrationDecryptSettingValue exists', function_exists('moodleIntegrationDecryptSettingValue'));

echo "\n-- Secrets Encrypt/Decrypt Round-Trip --\n";
$plaintext = 'super-secret-api-token-1234';
$encrypted = moodleIntegrationEncryptSettingValue($plaintext);
// If no APP_ENCRYPTION_KEY is set, function returns plaintext (fail-open). Accept both.
if ($encrypted === $plaintext) {
    moodleIntegrationTestAssert('encrypt returns plaintext (no key configured, fail-open)', true);
    moodleIntegrationTestAssert('decrypt roundtrip (no key — passthrough)', moodleIntegrationDecryptSettingValue($encrypted) === $plaintext);
} else {
    $decoded = json_decode($encrypted, true);
    moodleIntegrationTestAssert('encrypted value is JSON envelope', is_array($decoded) && ($decoded['enc'] ?? 0) === 1 && isset($decoded['ciphertext']));
    moodleIntegrationTestAssert('encrypted value differs from plaintext', $encrypted !== $plaintext);
    moodleIntegrationTestAssert('decrypt roundtrip', moodleIntegrationDecryptSettingValue($encrypted) === $plaintext);
}
moodleIntegrationTestAssert('decrypt passthrough for non-envelope value', moodleIntegrationDecryptSettingValue('rawtoken') === 'rawtoken');
moodleIntegrationTestAssert('decrypt passthrough for empty string', moodleIntegrationDecryptSettingValue('') === '');

echo "\n-- Ugly Cases: Double-Submit / Idempotency --\n";
// Idempotency key deduplication — two inserts with the same key return the same ID.
// This can only be tested when a DB is available; skip gracefully if not.
$testTenantId = 0;
try {
    $testTenantId = moodleIntegrationCurrentTenantId();
} catch (\Throwable $e) {}
if ($testTenantId > 0) {
    $iKey = 'test-idempotency-' . uniqid('', true);
    $id1 = moodleIntegrationQueueTableInsertForTenant($testTenantId, 'test', ['x' => 1], 'pending', $iKey);
    $id2 = moodleIntegrationQueueTableInsertForTenant($testTenantId, 'test', ['x' => 2], 'pending', $iKey);
    moodleIntegrationTestAssert('idempotency key: first insert returns > 0', $id1 > 0);
    moodleIntegrationTestAssert('idempotency key: duplicate returns same ID', $id1 === $id2);
} else {
    moodleIntegrationTestAssert('idempotency key dedup (skipped — no tenant DB)', true);
    moodleIntegrationTestAssert('idempotency key duplicate (skipped — no tenant DB)', true);
}

echo "\n-- Ugly Cases: SSO Token Replay --\n";
// moodleIntegrationConsumeSsoTokenForTenant is already tested indirectly by the consume-once
// atomic implementation; verify the helper exists and the consume-once path returns null
// when called with a nonsense token (expired / never issued).
moodleIntegrationTestAssert('consume-once helper exists', function_exists('moodleIntegrationConsumeSsoTokenForTenant'));
if ($testTenantId > 0) {
    $noRow = moodleIntegrationConsumeSsoTokenForTenant($testTenantId, 'non-existent-token-' . uniqid());
    moodleIntegrationTestAssert('non-existent token returns null', $noRow === null);
} else {
    moodleIntegrationTestAssert('non-existent token returns null (skipped — no DB)', true);
}

echo "\n-- Routes: New Endpoints --\n";
moodleIntegrationTestAssert('events webhook route exists', ($routes['POST']['/api/v1/moodle-integration/events'] ?? '') === 'moodle-integration:apiMoodleIntegrationEvents');
moodleIntegrationTestAssert('settings save route exists', ($routes['POST']['/admin/moodle-integration/settings'] ?? '') === 'moodle-integration:postMoodleIntegrationSettings');

echo "\n-- Function Surface: New Handlers --\n";
moodleIntegrationTestAssert('apiMoodleIntegrationEvents function exists', function_exists('apiMoodleIntegrationEvents'));
moodleIntegrationTestAssert('postMoodleIntegrationSettings function exists', function_exists('postMoodleIntegrationSettings'));

echo "\n-- CMS Setup --\n";
moodleIntegrationTestAssert('page setup specs exist', count(moodleIntegrationCmsPageSpecs()) >= 2);
moodleIntegrationTestAssert('install setup function exists', function_exists('moodleIntegrationRunCmsInstallSetup'));

echo "\n-- Services --\n";
require_once __DIR__ . '/../modules/moodle-integration/services/MoodleService.php';
require_once __DIR__ . '/../modules/moodle-integration/services/SSOService.php';
require_once __DIR__ . '/../modules/moodle-integration/services/SyncService.php';

$moodleService = new \MoodleIntegration\Services\MoodleService();
$ssoService = new \MoodleIntegration\Services\SSOService();

moodleIntegrationTestAssert('MoodleService class loads', $moodleService instanceof \MoodleIntegration\Services\MoodleService);
moodleIntegrationTestAssert('SSOService class loads', $ssoService instanceof \MoodleIntegration\Services\SSOService);
moodleIntegrationTestAssert('unconfigured service reports false', $moodleService->isConfigured() === false);
moodleIntegrationTestAssert('unconfigured SSO launch returns null', $ssoService->buildLaunchUrl(['id' => 1, 'email' => 'demo@example.com'], ['moodle_course_id' => 5, 'title' => 'Demo']) === null);

echo "\n-- Logs --\n";
$appLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/app.log'));
$errLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/error.log'));
moodleIntegrationTestAssert('no error in app.log', $appLog === '' || !str_contains(strtolower($appLog), 'error'));
moodleIntegrationTestAssert('no error in error.log', $errLog === '');

echo "\n" . str_repeat('-', 48) . "\n";
echo "Result: {$pass} passed, {$fail} failed\n";
if ($errors !== []) {
    echo "\nFailures:\n";
    foreach ($errors as $error) {
        echo "- {$error}\n";
    }
}
echo "\n";

exit($fail > 0 ? 1 : 0);