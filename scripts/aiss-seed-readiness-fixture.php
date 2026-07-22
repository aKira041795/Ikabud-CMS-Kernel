<?php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/../src/helpers/security.php';
require __DIR__ . '/../src/helpers/module-manager.php';
require __DIR__ . '/../modules/academic_similarity/helpers.php';

$tenantId = 582;
$institutionId = 1;
$pdfPath = $argv[1] ?? '/home/kajagogoo/Downloads/Digital Learning Readiness and Academic Performance.pdf';

if (!is_file($pdfPath)) {
    fwrite(STDERR, "PDF not found: {$pdfPath}\n");
    exit(1);
}

app()->tenant()->setTenantId($tenantId);
$ctx = module('academic-similarity');
if (!$ctx) {
    fwrite(STDERR, "Academic Similarity module context unavailable\n");
    exit(1);
}
kernel_request_context_set('_activeModuleContext', $ctx);

$db = academic_similarity_db();
$sourceText = extractFixturePdfText($pdfPath);
if (str_word_count($sourceText) < 200) {
    fwrite(STDERR, "Extracted source text is too short for a strong fixture\n");
    exit(1);
}

cleanupFixture($db, $tenantId);

$sourceService = new AcademicSimilaritySourceService((string)$tenantId);
$sourceResult = $sourceService->create([
    'institution_id' => $institutionId,
    'title' => 'AISS Fixture - Digital Learning Readiness Source',
    'author' => 'AISS Source Fixture',
    'source_type' => 'pasted',
    'classification' => 'published',
    'pasted_text' => $sourceText,
    'metadata_json' => [
        'fixture' => 'digital-learning-readiness',
        'source_pdf_path' => $pdfPath,
    ],
    'actor_name' => 'fixture',
]);
if (!($sourceResult['ok'] ?? false)) {
    fwrite(STDERR, "Source creation failed: " . ($sourceResult['error'] ?? 'unknown') . "\n");
    exit(1);
}
$sourceId = (int)$sourceResult['source_id'];

$submissions = buildFixtureSubmissions($sourceText);
$submissionService = new AcademicSimilaritySubmissionService((string)$tenantId);
$pipeline = new AcademicSimilarityPipelineService((string)$tenantId);
$created = [];

foreach ($submissions as $case) {
    $create = $submissionService->create([
        'institution_id' => $institutionId,
        'submission_title' => $case['title'],
        'author_name' => $case['author'],
        'source_type' => 'pasted',
        'pasted_text' => $case['text'],
        'idempotency_key' => 'aiss-fixture-' . $case['slug'] . '-' . date('YmdHis'),
    ]);
    if (!($create['ok'] ?? false)) {
        $created[] = ['title' => $case['title'], 'error' => $create['error'] ?? 'create failed'];
        continue;
    }

    $submissionId = (int)$create['submission_id'];
    $process = $pipeline->processSubmission($submissionId);
    $created[] = [
        'title' => $case['title'],
        'submission_id' => $submissionId,
        'processed' => (bool)($process['ok'] ?? false),
        'error' => $process['error'] ?? '',
    ];
}

$summary = loadFixtureSummary($db, $tenantId);

echo "Source ID: {$sourceId}\n";
foreach ($created as $row) {
    echo ($row['title'] ?? 'Untitled') . ' => submission ' . ($row['submission_id'] ?? 'n/a');
    echo !empty($row['processed']) ? " processed\n" : ' failed: ' . ($row['error'] ?? 'unknown') . "\n";
}
echo "\nSummary\n";
foreach ($summary as $row) {
    echo implode(' | ', [
        $row['id'],
        $row['submission_title'],
        $row['word_count'],
        $row['raw_similarity_score'] ?? '0.00',
        $row['adjusted_similarity_score'] ?? '0.00',
        $row['match_count'],
        $row['matched_words'] ?? '0',
    ]) . "\n";
}

function extractFixturePdfText(string $pdfPath): string
{
    $tmp = sys_get_temp_dir() . '/aiss_readiness_fixture_' . sha1($pdfPath) . '.txt';
    $binary = '/usr/bin/pdftotext';
    if (is_executable($binary)) {
        $cmd = escapeshellarg($binary) . ' -layout -enc UTF-8 ' . escapeshellarg($pdfPath) . ' ' . escapeshellarg($tmp);
        @exec($cmd, $output, $exitCode);
        if ($exitCode === 0 && is_file($tmp)) {
            $text = trim((string)file_get_contents($tmp));
            if ($text !== '') {
                return preg_replace('/\s+/u', ' ', $text) ?? $text;
            }
        }
    }

    $extractor = new AcademicSimilarityTextExtractor();
    return $extractor->extractPdf($pdfPath);
}

function cleanupFixture(\Ikabud\Kernel\Contracts\ModuleDB $db, int $tenantId): void
{
    $submissionIds = loadIds($db, "SELECT id FROM ac_similarity_submissions WHERE tenant_id = :tid AND submission_title LIKE 'AISS Fixture - %'", [':tid' => $tenantId]);
    if ($submissionIds !== []) {
        deleteByIds($db, 'ac_similarity_match_evidence', 'match_id', loadIds($db, "SELECT id FROM ac_similarity_matches WHERE tenant_id = :tid AND submission_id IN (" . placeholders($submissionIds) . ")", bindIds([':tid' => $tenantId], $submissionIds)));
        foreach (['ac_similarity_reports', 'ac_similarity_matches', 'ac_similarity_candidate_sources', 'ac_similarity_processing_jobs', 'ac_similarity_fingerprints', 'ac_similarity_segments', 'ac_similarity_text_versions'] as $table) {
            deleteByIds($db, $table, 'submission_id', $submissionIds, $tenantId);
        }
        deleteByIds($db, 'ac_similarity_submissions', 'id', $submissionIds, $tenantId);
    }

    $sourceIds = loadIds($db, "SELECT id FROM ac_similarity_sources WHERE tenant_id = :tid AND title = 'AISS Fixture - Digital Learning Readiness Source'", [':tid' => $tenantId]);
    if ($sourceIds !== []) {
        foreach (['ac_similarity_fingerprints', 'ac_similarity_segments', 'ac_similarity_text_versions'] as $table) {
            deleteByIds($db, $table, 'source_id', $sourceIds, $tenantId);
        }
        deleteByIds($db, 'ac_similarity_sources', 'id', $sourceIds, $tenantId);
    }
}

function buildFixtureSubmissions(string $sourceText): array
{
    $exact = excerptWords($sourceText, 0, 170);
    $quote = excerptWords($sourceText, 40, 95);

    return [
        [
            'slug' => 'exact',
            'title' => 'AISS Fixture - Exact Copy Passage',
            'author' => 'Fixture Exact Copy',
            'text' => $exact,
        ],
        [
            'slug' => 'light-edit',
            'title' => 'AISS Fixture - Lightly Edited Passage',
            'author' => 'Fixture Light Edit',
            'text' => str_replace(
                ['continuing integration', 'higher education', 'Universities increasingly rely on', 'This shift has contributed'],
                ['continued integration', 'graduate education', 'Many universities now rely on', 'This transition has contributed'],
                $exact
            ),
        ],
        [
            'slug' => 'paraphrase',
            'title' => 'AISS Fixture - Heavily Paraphrased Passage',
            'author' => 'Fixture Paraphrase',
            'text' => 'Graduate learners in blended programs need more than access to computers. Their success depends on whether they can plan independent study, communicate through online platforms, evaluate digital materials, meet deadlines, and remain motivated when instruction moves between classroom and web-based activities. Differences in work schedules, connectivity, prior academic experience, and confidence with learning technologies can therefore shape participation and achievement even when students are enrolled in the same course.',
        ],
        [
            'slug' => 'quotation',
            'title' => 'AISS Fixture - Proper Quotation Passage',
            'author' => 'Fixture Quotation',
            'text' => 'The review uses a properly attributed quotation from the assigned source: "' . $quote . '" (Digital Learning Readiness and Academic Performance, p. 1). The rest of this submission explains that the quoted material should be detected as overlap while still being distinguishable from unattributed copying.',
        ],
        [
            'slug' => 'unrelated',
            'title' => 'AISS Fixture - Unrelated Control Passage',
            'author' => 'Fixture Control',
            'text' => 'Community gardens can improve neighborhood food access by converting unused land into productive growing spaces. Volunteers often coordinate planting schedules, compost collection, irrigation, seed sharing, and harvest distribution. Local markets may benefit when residents learn practical horticulture skills and build relationships with nearby farmers, schools, and civic groups.',
        ],
    ];
}

function excerptWords(string $text, int $offset, int $length): string
{
    preg_match_all('/[\p{L}\p{N}]+(?:[\'’-][\p{L}\p{N}]+)*/u', $text, $matches);
    return implode(' ', array_slice($matches[0] ?? [], $offset, $length)) . '.';
}

function loadFixtureSummary(\Ikabud\Kernel\Contracts\ModuleDB $db, int $tenantId): array
{
    $stmt = $db->prepare("
        SELECT s.id, s.submission_title, s.word_count, s.raw_similarity_score,
               s.adjusted_similarity_score, COUNT(m.id) AS match_count,
               COALESCE(SUM(m.matched_word_count), 0) AS matched_words
        FROM ac_similarity_submissions s
        LEFT JOIN ac_similarity_matches m ON m.submission_id = s.id AND m.tenant_id = s.tenant_id
        WHERE s.tenant_id = :tid AND s.submission_title LIKE 'AISS Fixture - %'
        GROUP BY s.id
        ORDER BY s.id ASC
    ");
    $stmt->execute([':tid' => $tenantId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function loadIds(\Ikabud\Kernel\Contracts\ModuleDB $db, string $sql, array $params): array
{
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function deleteByIds(\Ikabud\Kernel\Contracts\ModuleDB $db, string $table, string $column, array $ids, ?int $tenantId = null): void
{
    if ($ids === []) {
        return;
    }
    $params = bindIds([], $ids);
    $sql = "DELETE FROM {$table} WHERE {$column} IN (" . placeholders($ids) . ")";
    if ($tenantId !== null) {
        $sql .= ' AND tenant_id = :tid';
        $params[':tid'] = $tenantId;
    }
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
}

function placeholders(array $ids): string
{
    return implode(', ', array_map(static fn(int $i): string => ':id' . $i, array_keys(array_values($ids))));
}

function bindIds(array $params, array $ids): array
{
    foreach (array_values($ids) as $i => $id) {
        $params[':id' . $i] = (int)$id;
    }
    return $params;
}
