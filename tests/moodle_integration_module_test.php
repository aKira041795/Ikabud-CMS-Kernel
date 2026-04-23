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
moodleIntegrationTestAssert('sync_interval default is hourly', ($defaults['sync_interval'] ?? '') === 'hourly');

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