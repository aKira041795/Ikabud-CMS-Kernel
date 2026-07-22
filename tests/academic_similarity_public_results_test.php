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

echo "\n=== Academic Similarity — Public Results ===\n";

// ── UserResultService ──

$service = new AcademicSimilarityUserResultService('test-tenant');
t('UserResultService can be instantiated', $service instanceof AcademicSimilarityUserResultService);

// ── getCurrentUserId when no user is logged in ──

$userId = AcademicSimilarityUserResultService::getCurrentUserId();
t('getCurrentUserId returns 0 when not logged in', $userId === 0, "got: {$userId}");

$user = AcademicSimilarityUserResultService::getCurrentUser();
t('getCurrentUser returns null when not logged in', $user === null);

$source = AcademicSimilarityUserResultService::getCurrentUserSource();
t('getCurrentUserSource returns empty when not logged in', $source === '');

// ── getSummaryStats with no submissions ──

$stats = $service->getSummaryStats(9999);
t('getSummaryStats returns array', is_array($stats));
t('getSummaryStats has total_submissions key', isset($stats['total_submissions']));
t('getSummaryStats total_submissions is 0 for unknown user', ($stats['total_submissions'] ?? -1) === 0, 'got: ' . ($stats['total_submissions'] ?? 'unset'));
t('getSummaryStats has processed_count key', isset($stats['processed_count']));
t('getSummaryStats has pending_count key', isset($stats['pending_count']));
t('getSummaryStats has failed_count key', isset($stats['failed_count']));
t('getSummaryStats has avg_adjusted_score key', isset($stats['avg_adjusted_score']));
t('getSummaryStats has highest_adjusted_score key', isset($stats['highest_adjusted_score']));
t('getSummaryStats has total_matches key', isset($stats['total_matches']));
t('getSummaryStats has latest_report_date key', isset($stats['latest_report_date']));

// ── getRecentSubmissions with no data ──

$recent = $service->getRecentSubmissions(9999);
t('getRecentSubmissions returns array', is_array($recent));
t('getRecentSubmissions is empty for unknown user', count($recent) === 0, 'got: ' . count($recent));

// ── getReportSummary with unknown submission ──

$summary = $service->getReportSummary(99999, 9999);
t('getReportSummary returns null for unknown submission', $summary === null);

// ── Render submission form (no user) ──

$html = academic_similarity_render_submission_form();
t('render form returns non-empty string', $html !== '');
t('render form contains form tag', str_contains($html, '<form'));
t('render form contains submit button', str_contains($html, 'Check Similarity'));
t('render form contains CSRF token input', str_contains($html, '_token'));
t('render form contains file upload', str_contains($html, 'type="file"'));
t('render form contains paste textarea', str_contains($html, 'pasted_text'));
t('render form contains JS submit function', str_contains($html, 'acSimPublicSubmit'));

// ── Render form with custom title ──

$htmlWithTitle = academic_similarity_render_submission_form(['title' => 'Upload Your Thesis']);
t('render form with custom title contains title', str_contains($htmlWithTitle, 'Upload Your Thesis'));

// ── Settings defaults ──

$settingsDefaults = [
    'public_results_enabled' => '1',
    'public_results_recent_limit' => '10',
    'public_results_show_scores' => '1',
    'public_results_show_match_count' => '1',
    'public_results_show_report_links' => '1',
    'public_results_allow_anonymous' => '1',
];

// Check helpers.php source for defaults
$helpersSource = file_get_contents(__DIR__ . '/../modules/academic_similarity/helpers.php');
foreach ($settingsDefaults as $key => $expected) {
    $search = "'{$key}' => '{$expected}'";
    t("default setting {$key} = {$expected}", str_contains($helpersSource, $search), "searched for: {$search}");
}

// ── Settings allowlist ──

$allowedKeys = [
    'public_results_enabled', 'public_results_recent_limit',
    'public_results_show_scores', 'public_results_show_match_count',
    'public_results_show_report_links', 'public_results_allow_anonymous',
];
foreach ($allowedKeys as $key) {
    t("settings allowlist includes {$key}", str_contains($helpersSource, $key), "missing: {$key}");
}

// ── Routes ──

$routesSource = file_get_contents(__DIR__ . '/../modules/academic_similarity/routes.php');
t('routes has GET public results endpoint', str_contains($routesSource, '/api/v1/academic-similarity/public/results'), 'missing results route');
t('routes has GET public report summary endpoint', str_contains($routesSource, '/api/v1/academic-similarity/public/reports/{submission_id}'), 'missing report summary route');

// ── Handlers ──

$handlersSource = file_get_contents(__DIR__ . '/../modules/academic_similarity/handlers.php');
t('handlers has apiPublicResults function', str_contains($handlersSource, 'function apiPublicResults'));
t('handlers has apiPublicReportSummary function', str_contains($handlersSource, 'function apiPublicReportSummary'));
t('handlers detects submitter user ID in apiPublicSubmit', str_contains($handlersSource, 'submitter_user_id'));
t('handlers populates author_name from user', str_contains($handlersSource, 'getCurrentUser') && str_contains($handlersSource, 'author_name'));

// ── Migration ──

$migrationSource = file_get_contents(__DIR__ . '/../modules/academic_similarity/migrations/005_academic_similarity_public_results.sql');
t('migration adds submitter_user_id column', str_contains($migrationSource, 'submitter_user_id'));
t('migration adds submitter_source column', str_contains($migrationSource, 'submitter_source'));
t('migration adds index on submitter', str_contains($migrationSource, 'idx_submissions_submitter'));
t('migration uses ALTER TABLE', str_contains($migrationSource, 'ALTER TABLE'));

// ── Module.json ──

$manifest = file_get_contents(__DIR__ . '/../modules/academic_similarity/module.json');
t('module.json registers migration 005', str_contains($manifest, '005_academic_similarity_public_results.sql'));
t('module.json has public_results_enabled setting', str_contains($manifest, '"key": "public_results_enabled"'));
t('module.json has public_results_recent_limit setting', str_contains($manifest, '"key": "public_results_recent_limit"'));

// Check logs
$appLog = file_get_contents(__DIR__ . '/../storage/logs/app.log');
$errorLog = file_get_contents(__DIR__ . '/../storage/logs/error.log');
t('app.log has no critical entries', !str_contains($appLog, '[critical]'), trim($appLog));
t('error.log is empty', trim($errorLog) === '', trim($errorLog));

echo "\n── Results: {$pass} passed, {$fail} failed ──\n";
exit($fail > 0 ? 1 : 0);
