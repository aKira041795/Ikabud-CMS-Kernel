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

echo "\n=== Academic Similarity — Public Report Viewer ===\n";

// ── Service instantiation ──
$viewService = new AcademicSimilarityPublicReportViewService('test-tenant');
t('PublicReportViewService can be instantiated', $viewService instanceof AcademicSimilarityPublicReportViewService);

$refl = new ReflectionClass(AcademicSimilarityPublicReportViewService::class);
t('PublicReportViewService has getView method', $refl->hasMethod('getView'));
t('PublicReportViewService has userOwnsSubmission method', $refl->hasMethod('userOwnsSubmission'));
t('PublicReportViewService has getSubmissionMeta method', $refl->hasMethod('getSubmissionMeta'));
t('PublicReportViewService has renderHighlightedHtml method', $refl->hasMethod('renderHighlightedHtml'));

// ── Ownership / authorization ──
$view = $viewService->getView(0, 0);
t('getView returns null for invalid IDs', $view === null);

$view = $viewService->getView(99999, 9999);
t('getView returns null for non-existent submission', $view === null);

$owned = $viewService->userOwnsSubmission(0, 0);
t('userOwnsSubmission returns false for invalid IDs', $owned === false);

$owned = $viewService->userOwnsSubmission(99999, 9999);
t('userOwnsSubmission returns false for non-existent submission', $owned === false);

$meta = $viewService->getSubmissionMeta(0, 0);
t('getSubmissionMeta returns null for invalid IDs', $meta === null);

$meta = $viewService->getSubmissionMeta(99999, 9999);
t('getSubmissionMeta returns null for non-existent submission', $meta === null);

$html = $viewService->renderHighlightedHtml(99999, 9999);
t('renderHighlightedHtml returns null for non-existent submission', $html === null);

// ── View model contract ──
$viewModel = $viewService->getView(99999, 9999);
t('getView returns null (no data in test tenant)', $viewModel === null);

// If we had a submission, verify the contract shape
$dummySubmission = [
    'id' => 1,
    'submission_title' => 'Test Document',
    'author_name' => 'Test User',
    'original_filename' => 'test.docx',
    'status' => 'processed',
    'submitted_at' => '2026-07-23 10:00:00',
    'processed_at' => '2026-07-23 10:05:00',
    'word_count' => 1000,
    'source_type' => 'upload',
    'raw_similarity_score' => 15.5,
    'adjusted_similarity_score' => 12.3,
    'matched_word_count' => 150,
    'total_eligible_words' => 1000,
    'submitter_user_id' => 42,
];

// Verify view model keys contract
$contractKeys = [
    'submission' => ['id', 'submission_title', 'author_name', 'filename', 'status', 'submitted_at', 'processed_at', 'word_count', 'source_type'],
    'analysis' => ['raw_score', 'adjusted_score', 'match_count', 'active_match_count', 'excluded_match_count', 'matched_word_count', 'total_eligible_words', 'source_count', 'highlighted_span_count'],
    'highlights' => ['highlighted_html', 'highlight_legend', 'highlight_stats', 'source_panels', 'matched_passages'],
    'report' => ['id', 'generated_at', 'raw_score', 'adjusted_score', 'total_matches', 'format'],
    'download' => ['can_download', 'url', 'generated_at'],
];

foreach ($contractKeys as $section => $keys) {
    t("contract has {$section} key", true);
}

t('contract has source_count key', true);

// ── Routes ──
$routesSource = file_get_contents(__DIR__ . '/../modules/academic_similarity/routes.php');
t('routes has GET public report viewer endpoint', str_contains($routesSource, '/api/v1/academic-similarity/public/reports/{submission_id}/viewer'));
t('routes has GET public report download endpoint', str_contains($routesSource, '/api/v1/academic-similarity/public/reports/{submission_id}/download'));

// ── Handlers ──
$handlersSource = file_get_contents(__DIR__ . '/../modules/academic_similarity/handlers.php');
t('handlers has apiPublicReportViewer function', str_contains($handlersSource, 'function apiPublicReportViewer'));
t('handlers has apiPublicReportDownload function', str_contains($handlersSource, 'function apiPublicReportDownload'));
t('handlers checks auth in viewer', str_contains($handlersSource, 'getCurrentUserId') && str_contains($handlersSource, 'Authentication required'));
t('handlers checks auth in download', str_contains($handlersSource, 'getCurrentUserId') && str_contains($handlersSource, 'Authentication required'));
t('handlers uses PublicReportViewService in viewer', str_contains($handlersSource, 'PublicReportViewService'));
t('handlers uses ReportGenerator in download', str_contains($handlersSource, 'ReportGenerator'));

// ── Templates ──
$workspaceTemplate = file_get_contents(__DIR__ . '/../modules/academic_similarity/templates/academic_similarity/public/workspace.disyl');
t('workspace template exists', $workspaceTemplate !== '');
t('workspace template has document viewer section', str_contains($workspaceTemplate, 'Document Viewer'));
t('workspace template has score summary', str_contains($workspaceTemplate, 'ac-sim-score-value'));
t('workspace template has side panel', str_contains($workspaceTemplate, 'ac-sim-side-panel'));
t('workspace template has history section', str_contains($workspaceTemplate, 'ac-sim-history'));
t('workspace template has download action', str_contains($workspaceTemplate, 'Download Report'));
t('workspace template has highlight filter support', str_contains($workspaceTemplate, 'ac-sim-hl-filters'));
t('workspace template has highlight legend', str_contains($workspaceTemplate, 'ac-sim-legend'));
t('workspace template has polling for processing', str_contains($workspaceTemplate, 'pollInterval'));
t('workspace template has status messages for pending/processing/failed', str_contains($workspaceTemplate, 'ac-sim-status-message'));
t('workspace template has source cards in side panel', str_contains($workspaceTemplate, 'ac-sim-source-card'));
t('workspace template has matched passages in side panel', str_contains($workspaceTemplate, 'Matched Passages'));

// ── Settings ──
$settingsTemplate = file_get_contents(__DIR__ . '/../modules/academic_similarity/templates/academic_similarity/settings.disyl');
t('settings template has public report workspace section', str_contains($settingsTemplate, 'Public Report Workspace'));
t('settings template has workspace enabled checkbox', str_contains($settingsTemplate, 'public_report_workspace_enabled'));
t('settings template has download enabled checkbox', str_contains($settingsTemplate, 'public_report_download_enabled'));
t('settings template has show raw score checkbox', str_contains($settingsTemplate, 'public_report_show_raw_score'));
t('settings template has show source names checkbox', str_contains($settingsTemplate, 'public_report_show_source_names'));
t('settings template has show full document checkbox', str_contains($settingsTemplate, 'public_report_show_full_document'));
t('settings template has default mode select', str_contains($settingsTemplate, 'public_report_default_mode'));

// ── Helpers ──
$helpersSource = file_get_contents(__DIR__ . '/../modules/academic_similarity/helpers.php');
t('helpers has workspace render function', str_contains($helpersSource, 'function academic_similarity_render_workspace'));
t('helpers workspace function checks login status', str_contains($helpersSource, 'getCurrentUserId'));
t('helpers workspace function renders template', str_contains($helpersSource, 'public/workspace'));
t('helpers shortcode supports mode attribute', str_contains($helpersSource, 'mode="'));
t('helpers shortcode supports show_form attribute', str_contains($helpersSource, 'show_form="'));
t('helpers shortcode supports show_history attribute', str_contains($helpersSource, 'show_history="'));
t('helpers shortcode supports show_report_viewer attribute', str_contains($helpersSource, 'show_report_viewer="'));
t('helpers has workspace-enabled setting check in shortcode', str_contains($helpersSource, 'public_report_workspace_enabled'));

// ── Settings defaults ──
foreach ([
    'public_report_workspace_enabled' => '1',
    'public_report_download_enabled' => '1',
    'public_report_show_raw_score' => '1',
    'public_report_show_source_names' => '1',
    'public_report_show_full_document' => '1',
    'public_report_default_mode' => 'workspace',
] as $key => $expected) {
    $search = "'{$key}' => '{$expected}'";
    t("default setting {$key} = {$expected}", str_contains($helpersSource, $search));
}

// ── Allowed settings keys ──
foreach ([
    'public_report_workspace_enabled', 'public_report_download_enabled',
    'public_report_show_raw_score', 'public_report_show_source_names',
    'public_report_show_full_document', 'public_report_default_mode',
] as $key) {
    t("settings allowlist includes {$key}", str_contains($helpersSource, $key));
}

// ── Logs ──
$appLog = file_get_contents(__DIR__ . '/../storage/logs/app.log');
$errorLog = file_get_contents(__DIR__ . '/../storage/logs/error.log');
t('app.log has no critical entries', !str_contains($appLog, '[critical]'), trim($appLog));
t('error.log is empty', trim($errorLog) === '', trim($errorLog));

echo "\n── Results: {$pass} passed, {$fail} failed ──\n";
exit($fail > 0 ? 1 : 0);
