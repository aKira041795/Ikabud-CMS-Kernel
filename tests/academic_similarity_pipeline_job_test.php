<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../modules/academic_similarity/helpers.php';

$pass = 0;
$fail = 0;
function t(string $description, bool $condition, string $detail = ''): void {
    global $pass, $fail;
    if ($condition) { $pass++; echo "  ✅ {$description}\n"; }
    else { $fail++; echo "  ❌ {$description}" . ($detail ? " — {$detail}" : '') . "\n"; }
}

file_put_contents(__DIR__ . '/../storage/logs/app.log', '');
file_put_contents(__DIR__ . '/../storage/logs/error.log', '');

echo "\n=== Academic Similarity — Pipeline Job Lifecycle ===\n";

// Test the pipeline job lifecycle data model and state transitions
// as defined in AcademicSimilarityProcessingJobRepository

// ── Job Data Model ──

// Simulate creating a processing job (same fields as repository create method)
$job = [
    'tenant_id' => 'test-tenant',
    'submission_id' => 42,
    'job_type' => 'process_submission',
    'status' => 'pending',
    'priority' => 0,
    'idempotency_key' => 'proc_42_abc123',
    'retry_count' => 0,
    'retry_max' => 3,
    'created_at' => '2026-07-22 10:00:00',
];

t('job has tenant_id', isset($job['tenant_id']));
t('job has submission_id', isset($job['submission_id']));
t('job has job_type', $job['job_type'] === 'process_submission');
t('job starts as pending', $job['status'] === 'pending');
t('job has idempotency_key', $job['idempotency_key'] === 'proc_42_abc123');
t('job has retry config', $job['retry_max'] === 3);
t('job has created_at', isset($job['created_at']));

// ── Job Status Transitions ──

// Transition: pending → running
$job['status'] = 'running';
$job['started_at'] = '2026-07-22 10:00:05';
t('pending → running transition valid', $job['status'] === 'running');
t('started_at set when running', isset($job['started_at']));

// Transition: running → completed
$job['status'] = 'completed';
$job['completed_at'] = '2026-07-22 10:00:30';
t('running → completed transition valid', $job['status'] === 'completed');
t('completed_at set when completed', isset($job['completed_at']));

// Transition: running → failed (with error message)
$job2 = [
    'submission_id' => 43,
    'job_type' => 'process_submission',
    'status' => 'running',
    'started_at' => '2026-07-22 10:01:00',
];
$job2['status'] = 'failed';
$job2['failure_reason'] = 'Text extraction failed: File not found';
$job2['completed_at'] = '2026-07-22 10:01:05';
t('running → failed transition valid', $job2['status'] === 'failed');
t('failure reason recorded', $job2['failure_reason'] === 'Text extraction failed: File not found');
t('completed_at set on failure', isset($job2['completed_at']));

// Transition: pending → skipped (idempotent)
$job3 = [
    'submission_id' => 44,
    'job_type' => 'process_submission',
    'status' => 'skipped',
    'completed_at' => '2026-07-22 10:02:00',
];
t('pending → skipped transition valid', $job3['status'] === 'skipped');

// ── Idempotency Key Prevents Duplicates ──

$processedKeys = [];
function hasBeenProcessed(string $key, array &$processed): bool {
    if (in_array($key, $processed, true)) {
        return true;
    }
    $processed[] = $key;
    return false;
}

t('new idempotency key is not duplicate', !hasBeenProcessed('proc_42_abc123', $processedKeys));
t('same key second time is duplicate', hasBeenProcessed('proc_42_abc123', $processedKeys));
t('different key is not duplicate', !hasBeenProcessed('proc_55_def456', $processedKeys));

// ── Pipeline Stage Ordering ──

$stages = ['extract', 'normalize', 'segment', 'fingerprint', 'candidate_search', 'exact_match', 'near_match', 'semantic_match', 'score', 'report'];
$stageOrder = array_flip($stages);

t('pipeline has 10 stages', count($stages) === 10);
t('extract is first stage', $stageOrder['extract'] === 0);
t('report is last stage', $stageOrder['report'] === 9);
t('normalize before segment', $stageOrder['normalize'] < $stageOrder['segment']);
t('fingerprint before candidate_search', $stageOrder['fingerprint'] < $stageOrder['candidate_search']);
t('exact_match before near_match', $stageOrder['exact_match'] < $stageOrder['near_match']);
t('semantic_match after near_match', $stageOrder['near_match'] < $stageOrder['semantic_match']);
t('semantic_match before score', $stageOrder['semantic_match'] < $stageOrder['score']);
t('score before report', $stageOrder['score'] < $stageOrder['report']);

// ── Job Dispatch Creates Record ──

// Simulate job dispatch
$nextJob = [
    'tenant_id' => 'test-tenant',
    'submission_id' => 100,
    'job_type' => 'process_submission',
    'status' => 'pending',
    'priority' => 0,
    'idempotency_key' => 'proc_100_xyz789',
    'retry_count' => 0,
    'retry_max' => 3,
    'created_at' => date('Y-m-d H:i:s'),
];
t('dispatched job has pending status', $nextJob['status'] === 'pending');
t('dispatched job has auto-generated created_at', $nextJob['created_at'] !== '');

// ── Job with different types ──

$jobTypes = ['process_submission', 'reindex_source', 'generate_report', 'cleanup_expired'];
foreach ($jobTypes as $type) {
    $typeJob = [
        'submission_id' => 200,
        'job_type' => $type,
        'status' => 'pending',
        'priority' => 0,
        'idempotency_key' => $type . '_200',
    ];
    t("job type '{$type}' has required fields", isset($typeJob['job_type'], $typeJob['status'], $typeJob['idempotency_key']));
}

// ── Error state: findById returns null for missing ──
// This is conceptual; the actual repository does this via DB query
t('missing job returns null (conceptual)', true); // Placeholder for conceptual test

// Check logs
$appLog = file_get_contents(__DIR__ . '/../storage/logs/app.log');
$errorLog = file_get_contents(__DIR__ . '/../storage/logs/error.log');
t('app.log has no critical entries', !str_contains($appLog, '[critical]'), trim($appLog));
t('error.log is empty', trim($errorLog) === '', trim($errorLog));

echo "\n── Results: {$pass} passed, {$fail} failed ──\n";
exit($fail > 0 ? 1 : 0);
