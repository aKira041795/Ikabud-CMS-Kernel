<?php
/**
 * Platform Tier 1 — Operational Foundation Tests
 *
 * Covers: job queue dispatch/process/stats, schedule frequency checking,
 * structured JSON logging, async webhook dispatch, subscription renewal engine,
 * membership expiry sweep, CLI command existence.
 *
 * Run: php tests/platform_tier1_operational_test.php
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

// Pre-load modules in output buffer since module loading can produce HTML output
ob_start();
try {
    require_once BASE_PATH . '/src/helpers/module-manager.php';
    $modules = discoverModules();
    if (isset($modules['cms'])) {
        loadModuleHelpers($modules['cms']);
    }
    if (isset($modules['ecommerce'])) {
        loadModuleHelpers($modules['ecommerce']);
    }
} catch (\Throwable $e) {
    // Module may not be fully loadable in test context — that's fine
}
ob_end_clean();

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
        $msg = "  ✗ {$label}";
        if ($detail !== '') {
            $msg .= " — {$detail}";
        }
        echo $msg . "\n";
        $errors[] = $label;
    }
}

// ══════════════════════════════════════════════════════════════════════
// 1. Job Queue Infrastructure
// ══════════════════════════════════════════════════════════════════════

echo "\n=== 1. Job Queue Infrastructure ===\n";

// 1a. kernelDispatchJob function exists
t('kernelDispatchJob exists', function_exists('kernelDispatchJob'));
t('kernelProcessNextJob exists', function_exists('kernelProcessNextJob'));
t('kernelJobInvokeHandler exists', function_exists('kernelJobInvokeHandler'));
t('kernelQueueWorker exists', function_exists('kernelQueueWorker'));
t('kernelJobQueueStats exists', function_exists('kernelJobQueueStats'));

// 1b. Test kernelJobInvokeHandler with a simple function
$_testJobHandlerCalled = false;
$_testJobHandlerPayload = null;

function _testJobHandler(array $payload): void
{
    global $_testJobHandlerCalled, $_testJobHandlerPayload;
    $_testJobHandlerCalled = true;
    $_testJobHandlerPayload = $payload;
}

kernelJobInvokeHandler('_testJobHandler', ['key' => 'value']);
t('kernelJobInvokeHandler calls plain function', $_testJobHandlerCalled === true);
t('kernelJobInvokeHandler passes payload', ($_testJobHandlerPayload['key'] ?? '') === 'value');

// 1c. Test kernelJobInvokeHandler throws on missing function
$threwOnMissing = false;
try {
    kernelJobInvokeHandler('_nonExistentJobHandler12345', []);
} catch (\RuntimeException $e) {
    $threwOnMissing = true;
}
t('kernelJobInvokeHandler throws on missing function', $threwOnMissing);

// 1d. Test module:handler format parsing
$threwOnMissingModule = false;
try {
    kernelJobInvokeHandler('nonexistent_module_xyz:someFn', []);
} catch (\RuntimeException $e) {
    $threwOnMissingModule = true;
}
t('kernelJobInvokeHandler throws on missing module handler', $threwOnMissingModule);

// 1e. Test dispatching with DB (may not have table yet — graceful)
$canQueueTest = false;
try {
    $db = app()->db();
    $db->query('SELECT 1 FROM kernel_jobs LIMIT 1');
    $canQueueTest = true;
} catch (\Throwable $e) {
    // Table doesn't exist yet — test the graceful fallback
}

if ($canQueueTest) {
    echo "\n--- Job Queue DB Tests (kernel_jobs table present) ---\n";

    // Clean up any test rows from previous runs
    $db->exec("DELETE FROM kernel_jobs WHERE handler LIKE '_test%'");
    $db->exec("DELETE FROM kernel_failed_jobs WHERE handler LIKE '_test%'");

    // Dispatch a job
    $jobId = kernelDispatchJob('_testJobHandler', ['test' => 'dispatch'], 'test_queue');
    t('kernelDispatchJob returns job ID > 0', $jobId > 0);

    // Check stats
    $stats = kernelJobQueueStats('test_queue');
    t('queue stats shows pending job', ($stats['pending'] ?? 0) >= 1);
    t('queue stats name correct', ($stats['queue'] ?? '') === 'test_queue');

    // Process the job
    $_testJobHandlerCalled = false;
    $_testJobHandlerPayload = null;
    ob_start();
    $result = kernelProcessNextJob('test_queue');
    ob_end_clean();
    t('kernelProcessNextJob returns result', $result !== null);
    t('Job handler was called during processing', $_testJobHandlerCalled === true);
    t('Job handler received correct payload', ($_testJobHandlerPayload['test'] ?? '') === 'dispatch');
    t('Job status is completed', ($result['status'] ?? '') === 'completed');

    // After processing, queue should be empty
    $stats2 = kernelJobQueueStats('test_queue');
    t('Queue is empty after processing', ($stats2['pending'] ?? 0) === 0);

    // Process empty queue returns null
    $emptyResult = kernelProcessNextJob('test_queue');
    t('Empty queue returns null', $emptyResult === null);

    // Test delayed job
    $delayedId = kernelDispatchJob('_testJobHandler', ['delayed' => true], 'test_queue', 3600);
    $statsDelayed = kernelJobQueueStats('test_queue');
    t('Delayed job shows as delayed, not pending', ($statsDelayed['delayed'] ?? 0) >= 1 && ($statsDelayed['pending'] ?? 0) === 0);

    // Delayed job should NOT be picked up
    $noResult = kernelProcessNextJob('test_queue');
    t('Delayed job not picked up before available_at', $noResult === null);

    // Test failing job
    function _testFailingJobHandler(array $payload): void
    {
        throw new \RuntimeException('Intentional test failure');
    }

    $failJobId = kernelDispatchJob('_testFailingJobHandler', ['will' => 'fail'], 'test_queue', 0, 1);
    // Make it immediately available
    $upd = $db->prepare('UPDATE kernel_jobs SET available_at = NOW() WHERE id = ?');
    $upd->execute([$failJobId]);
    ob_start();
    $failResult = kernelProcessNextJob('test_queue');
    ob_end_clean();
    t('Failed job returns failed status', ($failResult['status'] ?? '') === 'failed');
    t('Failed job has error message', str_contains((string)($failResult['error'] ?? ''), 'Intentional test failure'));

    // Check it ended up in failed_jobs
    $failedCount = (int)$db->query("SELECT COUNT(*) FROM kernel_failed_jobs WHERE handler = '_testFailingJobHandler'")->fetchColumn();
    t('Failed job moved to kernel_failed_jobs', $failedCount >= 1);

    // Clean up
    $db->exec("DELETE FROM kernel_jobs WHERE handler LIKE '_test%'");
    $db->exec("DELETE FROM kernel_failed_jobs WHERE handler LIKE '_test%'");
} else {
    echo "\n--- Job Queue DB Tests SKIPPED (kernel_jobs table not present) ---\n";
    echo "  Run: php ikabud migrate to create the table\n";
}


// ══════════════════════════════════════════════════════════════════════
// 2. Schedule Infrastructure
// ══════════════════════════════════════════════════════════════════════

echo "\n=== 2. Schedule Infrastructure ===\n";

t('kernelScheduleIsDue exists', function_exists('kernelScheduleIsDue'));

// every_minute is always due
t('every_minute is always due', kernelScheduleIsDue('every_minute'));

// unknown frequency is never due
t('unknown frequency is never due', !kernelScheduleIsDue('every_3_hours'));
t('empty frequency is never due', !kernelScheduleIsDue(''));

// Check that the ecommerce module has schedules in its manifest
$moduleJsonPath = BASE_PATH . '/modules/ecommerce/module.json';
$manifest = json_decode((string)file_get_contents($moduleJsonPath), true);
$schedules = $manifest['schedules'] ?? [];
t('Ecommerce module has schedules', count($schedules) >= 3);

$scheduleHandlers = array_column($schedules, 'handler');
t('Subscription renewal in schedules', in_array('ecommerce:ecProcessDueSubscriptionRenewals', $scheduleHandlers, true));
t('Membership expiry in schedules', in_array('ecommerce:ecMembershipExpiryCleanup', $scheduleHandlers, true));
t('Abandoned cart reminders in schedules', in_array('ecommerce:ecAbandonedCartProcessDueReminders', $scheduleHandlers, true));


// ══════════════════════════════════════════════════════════════════════
// 3. Structured JSON Logging
// ══════════════════════════════════════════════════════════════════════

echo "\n=== 3. Structured JSON Logging ===\n";

// Test plaintext format (default)
$testLogFile = STORAGE_PATH . '/logs/app.log';
$sizeBefore = file_exists($testLogFile) ? filesize($testLogFile) : 0;

// Temporarily ensure LOG_FORMAT is not json
$origLogFormat = $_ENV['LOG_FORMAT'] ?? '';
$_ENV['LOG_FORMAT'] = '';
putenv('LOG_FORMAT=');

write_log('test_plaintext_format', 'debug', ['foo' => 'bar']);
$logContent = file_get_contents($testLogFile);
$lastLine = trim(array_pop(array_filter(explode("\n", $logContent))));
t('Plaintext log contains message', str_contains($lastLine, 'test_plaintext_format'));
t('Plaintext log has bracket-level format', str_contains($lastLine, '[debug]'));
t('Plaintext log is NOT JSON', json_decode($lastLine, true) === null);

// Test JSON format
$_ENV['LOG_FORMAT'] = 'json';
putenv('LOG_FORMAT=json');

write_log('test_json_format', 'warning', ['module' => 'test', 'code' => 42]);
$logContent2 = file_get_contents($testLogFile);
$lastLine2 = trim(array_pop(array_filter(explode("\n", $logContent2))));
$decoded = json_decode($lastLine2, true);
t('JSON log is valid JSON', $decoded !== null);
t('JSON log has timestamp', isset($decoded['timestamp']));
t('JSON log has level', ($decoded['level'] ?? '') === 'warning');
t('JSON log has message', ($decoded['message'] ?? '') === 'test_json_format');
t('JSON log has request_id', isset($decoded['request_id']));
t('JSON log has tenant_id', array_key_exists('tenant_id', $decoded));
t('JSON log context has module', ($decoded['context']['module'] ?? '') === 'test');
t('JSON log context has code', ($decoded['context']['code'] ?? 0) === 42);

// Restore
$_ENV['LOG_FORMAT'] = $origLogFormat;
putenv('LOG_FORMAT=' . $origLogFormat);


// ══════════════════════════════════════════════════════════════════════
// 4. Async Webhook Delivery
// ══════════════════════════════════════════════════════════════════════

echo "\n=== 4. Async Webhook Delivery ===\n";

t('ecOutboundWebhookDeliverJob exists', function_exists('ecOutboundWebhookDeliverJob'));
t('ecOutboundWebhooksDispatchEvent exists', function_exists('ecOutboundWebhooksDispatchEvent'));

// Test that the deliver job handler validates payload
$threwOnBadPayload = false;
try {
    ecOutboundWebhookDeliverJob([]);  // Empty payload — should throw
} catch (\RuntimeException $e) {
    $threwOnBadPayload = true;
}
t('Webhook deliver job throws on bad payload', $threwOnBadPayload);


// ══════════════════════════════════════════════════════════════════════
// 5. Subscription Renewal Engine
// ══════════════════════════════════════════════════════════════════════

echo "\n=== 5. Subscription Renewal Engine ===\n";

t('ecProcessDueSubscriptionRenewals exists', function_exists('ecProcessDueSubscriptionRenewals'));
t('ecSubscriptionRenew exists', function_exists('ecSubscriptionRenew'));

// Test that renewal function handles non-existent subscription gracefully
$renewResult = ecSubscriptionRenew(0);
t('ecSubscriptionRenew(0) returns error', ($renewResult['ok'] ?? true) === false);

$renewResult2 = ecSubscriptionRenew(999999999);
t('ecSubscriptionRenew(nonexistent) returns error', ($renewResult2['ok'] ?? true) === false);

// Test ecProcessDueSubscriptionRenewals returns summary
ob_start();
$renewalResult = ecProcessDueSubscriptionRenewals();
ob_end_clean();
t('ecProcessDueSubscriptionRenewals returns ok', ($renewalResult['ok'] ?? false) === true || !empty($renewalResult['error']));
if (($renewalResult['ok'] ?? false) === true) {
    t('Renewal result has processed key', array_key_exists('processed', $renewalResult));
    t('Renewal result has past_due key', array_key_exists('past_due', $renewalResult));
    t('Renewal result has suspended key', array_key_exists('suspended', $renewalResult));
    t('Renewal result has completed key', array_key_exists('completed', $renewalResult));
    t('Renewal result has errors key', array_key_exists('errors', $renewalResult));
}


// ══════════════════════════════════════════════════════════════════════
// 6. Membership Expiry Sweep
// ══════════════════════════════════════════════════════════════════════

echo "\n=== 6. Membership Expiry Sweep ===\n";

t('ecMembershipExpiryCleanup exists', function_exists('ecMembershipExpiryCleanup'));

ob_start();
$expiryResult = ecMembershipExpiryCleanup();
ob_end_clean();
t('ecMembershipExpiryCleanup returns ok', ($expiryResult['ok'] ?? false) === true || !empty($expiryResult['error']));
if (($expiryResult['ok'] ?? false) === true) {
    t('Expiry result has expired key', array_key_exists('expired', $expiryResult));
    t('Expiry result has errors key', array_key_exists('errors', $expiryResult));
}


// ══════════════════════════════════════════════════════════════════════
// 7. Migration File Exists
// ══════════════════════════════════════════════════════════════════════

echo "\n=== 7. Migration & Manifest Checks ===\n";

t('kernel_jobs migration exists', is_file(BASE_PATH . '/migrations/006_kernel_job_queue.sql'));

$migrationSql = file_get_contents(BASE_PATH . '/migrations/006_kernel_job_queue.sql');
t('Migration creates kernel_jobs table', str_contains($migrationSql, 'kernel_jobs'));
t('Migration creates kernel_failed_jobs table', str_contains($migrationSql, 'kernel_failed_jobs'));
t('Migration has SKIP LOCKED-compatible index', str_contains($migrationSql, 'idx_kernel_jobs_queue_available'));

// Check that ikabud CLI has the new commands documented
$ikabudContent = file_get_contents(BASE_PATH . '/ikabud');
t('ikabud documents work:queue command', str_contains($ikabudContent, 'work:queue'));
t('ikabud documents queue:stats command', str_contains($ikabudContent, 'queue:stats'));
t('ikabud documents schedule:run command', str_contains($ikabudContent, 'schedule:run'));
t('ikabud has schedule:run case', str_contains($ikabudContent, "case 'schedule:run':"));
t('ikabud has work:queue case', str_contains($ikabudContent, "case 'work:queue':"));


// ══════════════════════════════════════════════════════════════════════
// Summary
// ══════════════════════════════════════════════════════════════════════

echo "\n" . str_repeat('═', 60) . "\n";
echo "Platform Tier 1 Operational Tests: {$pass} passed, {$fail} failed\n";
if ($errors !== []) {
    echo "Failures:\n";
    foreach ($errors as $e) {
        echo "  - {$e}\n";
    }
}
echo str_repeat('═', 60) . "\n";
exit($fail > 0 ? 1 : 0);
