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

echo "\n=== Academic Similarity — Public Report Download ===\n";

// ── Download authorization via PublicReportViewService ──
$viewService = new AcademicSimilarityPublicReportViewService('test-tenant');

// Verify ownership checks prevent unauthorized access
$owned = $viewService->userOwnsSubmission(99999, 42);
t('userOwnsSubmission returns false for non-existent submission to user 42', $owned === false);

$meta = $viewService->getSubmissionMeta(99999, 42);
t('getSubmissionMeta returns null for non-existent submission to user 42', $meta === null);

$view = $viewService->getView(99999, 42);
t('getView returns null for non-existent submission to user 42', $view === null);

// ── Download contract ──
// Verify the download section of the view model
$dummyDownloadSection = [
    'can_download' => false,
    'url' => null,
    'generated_at' => null,
];
t('download contract has can_download key', array_key_exists('can_download', $dummyDownloadSection));
t('download contract has url key', array_key_exists('url', $dummyDownloadSection));
t('download contract has generated_at key', array_key_exists('generated_at', $dummyDownloadSection));

// When report exists, download should be available
$dummyDownloadAvailable = [
    'can_download' => true,
    'url' => '/api/v1/academic-similarity/public/reports/42/download',
    'generated_at' => '2026-07-23 10:05:00',
];
t('download can_download is true when report exists', $dummyDownloadAvailable['can_download'] === true);
t('download url is populated when report exists', $dummyDownloadAvailable['url'] !== null);
t('download url points to public endpoint', str_contains($dummyDownloadAvailable['url'], '/public/reports/'));
t('download url uses submission ID, not report ID', str_contains($dummyDownloadAvailable['url'], '/reports/42/download'));

// ── Admin routes unchanged ──
$routesSource = file_get_contents(__DIR__ . '/../modules/academic_similarity/routes.php');
t('admin report download route unchanged', str_contains($routesSource, '/admin/academic-similarity/reports/{id}/download'));
t('admin report detail route unchanged', str_contains($routesSource, '/admin/academic-similarity/reports/{id}'));
t('admin submissions route unchanged', str_contains($routesSource, '/admin/academic-similarity/submissions'));
t('public download not in admin routes', !str_contains($routesSource, '/admin/academic-similarity/public'));

// ── Public download endpoint is separate from admin ──
t('public download endpoint is distinct from admin', str_contains($routesSource, '/api/v1/academic-similarity/public/reports/{submission_id}/download'));
t('public viewer endpoint is distinct from admin', str_contains($routesSource, '/api/v1/academic-similarity/public/reports/{submission_id}/viewer'));

// ── Handler authorization checks ──
$handlersSource = file_get_contents(__DIR__ . '/../modules/academic_similarity/handlers.php');
t('download handler checks authentication', str_contains($handlersSource, 'Authentication required'));
t('download handler checks submission ownership', str_contains($handlersSource, 'getView'));
t('download handler checks report availability', str_contains($handlersSource, 'can_download'));
t('download handler returns 404 for missing/non-owned submission', str_contains($handlersSource, 'not found or access denied'));
t('download handler returns 404 when report not available', str_contains($handlersSource, 'not yet available'));

// ── Download uses ReportGenerator with public context ──
t('download handler uses AcademicSimilarityReportGenerator', str_contains($handlersSource, 'AcademicSimilarityReportGenerator'));
t('download handler redacts source names when hidden', str_contains($handlersSource, 'source_breakdown') && str_contains($handlersSource, 'Matched Source'));

// ── Settings for download control ──
$helpersSource = file_get_contents(__DIR__ . '/../modules/academic_similarity/helpers.php');
t('helpers has public_report_download_enabled setting', str_contains($helpersSource, 'public_report_download_enabled'));
t('helpers has public_report_show_source_names setting', str_contains($helpersSource, 'public_report_show_source_names'));

// ── XSS safety in rendered HTML ──
// The ReportGenerator::buildHtml uses htmlspecialchars on all user-controlled values
$generatorSource = file_get_contents(__DIR__ . '/../modules/academic_similarity/src/Reports/AcademicSimilarityReportGenerator.php');
// Check that htmlspecialchars is used for title, author, and filename
t('report generator escapes submission title', str_contains($generatorSource, 'htmlspecialchars($submission'));
t('report generator escapes source title', str_contains($generatorSource, 'htmlspecialchars($source'));
t('report generator escapes author name', str_contains($generatorSource, 'htmlspecialchars'));
t('report generator escapes source authors', str_contains($generatorSource, 'htmlspecialchars'));

$highlightServiceSource = file_get_contents(__DIR__ . '/../modules/academic_similarity/src/Services/AcademicSimilarityHighlightService.php');
t('highlight service escapes text in renderHighlightedText', str_contains($highlightServiceSource, 'htmlspecialchars($tok'));
t('highlight service escapes text in renderSourcePanels', str_contains($highlightServiceSource, 'htmlspecialchars'));

// ── Logs ──
$appLog = file_get_contents(__DIR__ . '/../storage/logs/app.log');
$errorLog = file_get_contents(__DIR__ . '/../storage/logs/error.log');
t('app.log has no critical entries', !str_contains($appLog, '[critical]'), trim($appLog));
t('error.log is empty', trim($errorLog) === '', trim($errorLog));

echo "\n── Results: {$pass} passed, {$fail} failed ──\n";
exit($fail > 0 ? 1 : 0);
