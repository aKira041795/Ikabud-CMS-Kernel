<?php
declare(strict_types=1);

/**
 * Academic Similarity — Document Assessment Bundle.
 *
 * Covers: deterministic structure/claim extraction from a newline-less PDF-style
 * text, evidence + suggestion persistence, idempotent regeneration, sanitized
 * query capture from the internet run metadata, and the reviewer-assist language
 * gate (no forbidden machine conclusions).
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../modules/academic_similarity/helpers.php';

$pass = 0;
$fail = 0;
$errors = [];
function t(string $description, bool $condition, string $detail = ''): void {
    global $pass, $fail, $errors;
    if ($condition) { $pass++; echo "  \033[32m✓\033[0m {$description}\n"; }
    else { $fail++; $errors[] = $description . ($detail ? " — {$detail}" : ''); echo "  \033[31m✗\033[0m {$description}" . ($detail ? " — {$detail}" : '') . "\n"; }
}

file_put_contents(__DIR__ . '/../storage/logs/app.log', '');
file_put_contents(__DIR__ . '/../storage/logs/error.log', '');

$tenantId = 'aiss.test';
$db = academic_similarity_db($tenantId);
$resolved = academic_similarity_resolve_tenant_id($tenantId);

echo "\n=== Academic Similarity — Assessment Bundle ===\n";

// ── Seed a synthetic submission + newline-less manuscript ─────────
// CHAPTER 1 INTRODUCTION / CHAPTER 2 ... heading pattern is the real-world
// PDF-extraction shape (single stream, no line breaks).
$manuscript = 'CHAPTER 1 INTRODUCTION The objective of this study is to examine the influence of study habits on academic persistence. '
    . 'The research question asks how digital distractions shape student outcomes. '
    . 'CHAPTER 2 METHODOLOGY A mixed-methods design was used with survey respondents. Data were analyzed using thematic coding. '
    . 'CHAPTER 3 FINDINGS The findings revealed a significant relationship between planning and persistence. '
    . 'The conclusion suggests that structured planning should be embedded in orientation programs. '
    . 'The study recommends that universities adopt adaptive study planning tools. '
    . 'This study contributes a novel framework for adaptive academic planning.';

$textHash = hash('sha256', $manuscript);

$db->execute(
    "INSERT INTO ac_similarity_submissions (tenant_id, institution_id, submission_title, author_name, source_type, status, word_count, checksum_sha256, text_hash_sha256)
     VALUES (:tid, 1, 'Bundle Test Manuscript', 'Test Author', 'upload', 'processed', 200, :chk, :th)",
    [':tid' => $resolved, ':chk' => $textHash, ':th' => $textHash]
);
$submissionId = (int)$db->lastInsertId();
$db->execute(
    "INSERT INTO ac_similarity_text_versions (tenant_id, submission_id, text_type, extracted_text, text_hash_sha256, extraction_method)
     VALUES (:tid, :sid, 'submission', :txt, :th, 'probe')",
    [':tid' => $resolved, ':sid' => $submissionId, ':txt' => $manuscript, ':th' => $textHash]
);

// ── Seed an internet run with sanitized queries in metadata_json ──
$db->execute(
    "INSERT INTO ac_similarity_internet_search_runs (tenant_id, submission_id, institution_id, provider, status, query_count, candidate_count, imported_count, payload_policy, metadata_json)
     VALUES (:tid, :sid, 1, 'ai', 'completed_partial', 2, 4, 1, 'snippets_only', :meta)",
    [':tid' => $resolved, ':sid' => $submissionId, ':meta' => json_encode([
        'queries' => ['"study habits academic persistence"', 'digital distractions student outcomes academic literature'],
    ])]
);

// ── 1. Generate ───────────────────────────────────────────────────
$svc = new AcademicAssessmentBundleService($tenantId);
$r = $svc->generate($submissionId, ['payload_policy' => 'deterministic_internal_only']);
t('bundle generated', ($r['ok'] ?? false) === true, $r['error'] ?? '');
t('bundle has assessment_run_id', (int)($r['assessment_run_id'] ?? 0) > 0);
t('bundle binds manuscript hash', ($r['manuscript_hash'] ?? '') === $textHash, (string)($r['manuscript_hash'] ?? ''));
$runId = (int)($r['assessment_run_id'] ?? 0);

// ── 2. Structure: CHAPTER-marker headings detected without newlines ──
$sections = $r['structure']['sections'] ?? [];
$keys = array_map(static fn(array $s): string => (string)($s['section_key'] ?? ''), $sections);
t('CHAPTER introduction section detected', in_array('introduction', $keys, true), json_encode($keys));
t('CHAPTER methodology section detected', in_array('methodology', $keys, true), json_encode($keys));
t('CHAPTER findings section detected', in_array('findings', $keys, true), json_encode($keys));
t('no mid-prose false section (conclusion appears in prose only)', !in_array('conclusion', $keys, true), json_encode($keys));
$claims = $r['structure']['claims'] ?? [];
t('claims extracted', count($claims) >= 5, 'got ' . count($claims));
$claimTypes = array_column($claims, 'claim_type');
t('contribution claim extracted', in_array('contribution', $claimTypes, true), json_encode($claimTypes));

// ── 3. Evidence + suggestions persisted with no forbidden language ──
$evidence = $r['evidence'] ?? [];
t('integrity evidence recorded', count(array_filter($evidence, static fn(array $i): bool => ($i['dimension'] ?? '') === 'integrity_and_provenance')) >= 1);
$suggestions = $r['suggestions'] ?? [];
t('suggestions generated', count($suggestions) >= 1);
$forbidden = 0;
foreach ($suggestions as $s) {
    if (\AcademicSimilarityEvidenceTaxonomy::containsForbiddenMachineConclusion(($s['title'] ?? '') . ' ' . ($s['rationale'] ?? '') . ' ' . ($s['reviewer_action'] ?? ''))) {
        $forbidden++;
    }
}
t('no forbidden machine conclusion in suggestions', $forbidden === 0, "forbidden={$forbidden}");

// ── 4. Idempotency ────────────────────────────────────────────────
$r2 = $svc->generate($submissionId, ['payload_policy' => 'deterministic_internal_only']);
t('idempotent regenerate returns same run', (int)($r2['assessment_run_id'] ?? 0) === $runId);

// ── 5. Sanitized queries persisted in the immutable run ───────────
$runRow = $db->prepare("SELECT sanitized_queries_json FROM ac_similarity_assessment_runs WHERE tenant_id = :tid AND id = :id");
$runRow->execute([':tid' => $resolved, ':id' => $runId]);
$storedQueries = json_decode((string)$runRow->fetchColumn(), true) ?: [];
t('sanitized queries captured from metadata_json', count($storedQueries) === 2, json_encode($storedQueries));
t('query disclosure recorded as true', ($r['provenance']['payload_disclosures']['search_queries'] ?? false) === true);

// ── 6. latest() accessor ──────────────────────────────────────────
$latest = $svc->latest($submissionId);
t('latest() returns the newest run', ($latest['assessment_run_id'] ?? 0) === $runId);

// ── Cleanup ───────────────────────────────────────────────────────
foreach ([
    'ac_similarity_reviewer_suggestions',
    'ac_similarity_assessment_evidence',
    'ac_similarity_research_claims',
    'ac_similarity_document_sections',
    'ac_similarity_assessment_runs',
    'ac_similarity_internet_search_runs',
    'ac_similarity_text_versions',
] as $table) {
    $db->execute("DELETE FROM {$table} WHERE tenant_id = :tid AND submission_id = :sid", [':tid' => $resolved, ':sid' => $submissionId]);
}
$db->execute('DELETE FROM ac_similarity_submissions WHERE tenant_id = :tid AND id = :sid', [':tid' => $resolved, ':sid' => $submissionId]);

echo "\n──────────────────────────────────────────────────\n";
echo '  PASS: ' . $pass . '  FAIL: ' . $fail . "\n";
if ($fail > 0) {
    exit(1);
}
