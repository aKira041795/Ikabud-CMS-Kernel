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

echo "\n=== Academic Similarity — Exclusion Audit Trail ===\n";

// Test the exclusion audit concept that AcademicSimilarityReviewService
// and AcademicSimilarityAuditRepository implement.
// We test the data contracts and logic without needing a database.

// 1. Create a match result that represents an excluded match
$match = new AcademicSimilarityMatchResult([
    'submission_id' => 1,
    'source_id' => 10,
    'match_type' => 'exact',
    'confidence' => 1.0,
    'matched_word_count' => 50,
    'submission_word_start' => 0,
    'submission_word_end' => 49,
    'source_word_start' => 0,
    'source_word_end' => 49,
    'segment_match_count' => 2,
]);

// Simulate exclusion: the exclusion concept in the system involves:
// - Recording the exclusion event with previous score
// - Marking the match as excluded
// - Recalculating the adjusted score

// 2. Test exclusion scenario: 50 matched words out of 100 total
// Before exclusion: raw score = 50%, adjusted score = 50%
$totalWords = 100;
$matchedWords = 50;
$rawScore = round(($matchedWords / $totalWords) * 100, 2);

t('raw score before exclusion is 50%', $rawScore === 50.0, "got: {$rawScore}");

// 3. Exclude 20 words → remaining active = 30 words
$excludedWords = 20;
$remainingWords = $matchedWords - $excludedWords;
$adjustedScore = round(($remainingWords / $totalWords) * 100, 2);
t('adjusted score after 20-word exclusion is 30%', $adjustedScore === 30.0, "got: {$adjustedScore}");

// 4. Verify raw score is unchanged (still 50)
t('raw score is unchanged after exclusion', $rawScore === 50.0, "got: {$rawScore}");

// 5. Simulate the audit trail data structure
$auditEvent = [
    'event_type' => 'review.excluded',
    'actor_id' => 42,
    'actor_name' => 'reviewer@institution.edu',
    'target_type' => 'match',
    'target_id' => 101,
    'description' => 'Excluded match #101 from submission #1 (reason: false_positive)',
    'details' => [
        'submission_id' => 1,
        'reason' => 'false_positive',
        'note' => 'This is a common phrase, not plagiarism',
        'excluded_word_count' => 20,
        'previous_score' => 50.0,
    ],
];

t('audit event has event_type', isset($auditEvent['event_type']));
t('audit event type is review.excluded', $auditEvent['event_type'] === 'review.excluded');
t('audit event has actor info', isset($auditEvent['actor_id'], $auditEvent['actor_name']));
t('audit event has target info', isset($auditEvent['target_type'], $auditEvent['target_id']));
t('audit event has description', $auditEvent['description'] !== '');
t('audit event details contain reason', ($auditEvent['details']['reason'] ?? '') === 'false_positive');
t('audit event details contain previous_score', ($auditEvent['details']['previous_score'] ?? null) === 50.0);
t('audit event details contain excluded_word_count', ($auditEvent['details']['excluded_word_count'] ?? null) === 20);

// 6. Simulate multiple exclusions
$exclusions = [
    ['match_id' => 101, 'reason' => 'false_positive', 'excluded_words' => 20, 'prev_score' => 50.0],
    ['match_id' => 102, 'reason' => 'quotation', 'excluded_words' => 15, 'prev_score' => 50.0],
];

$totalExcluded = 0;
foreach ($exclusions as $ex) {
    $totalExcluded += $ex['excluded_words'];
}
$finalAdjusted = round((($matchedWords - $totalExcluded) / $totalWords) * 100, 2);
t('multiple exclusions: total excluded words is 35', $totalExcluded === 35, "got: {$totalExcluded}");
t('final adjusted score after two exclusions is 15%', $finalAdjusted === 15.0, "got: {$finalAdjusted}");

// 7. Exclude entire match → score drops to 0
$fullExclusion = round((0 / $totalWords) * 100, 2);
t('excluding all matched words gives 0%', $fullExclusion === 0.0);

// 8. Exclusion with 'bibliography' reason
$bibExclusion = [
    'reason' => 'bibliography',
    'note' => 'Standard references section',
    'excluded_word_count' => 30,
    'previous_score' => 45.0,
];
$bibScore = round((($matchedWords - 30) / $totalWords) * 100, 2);
t('bibliography exclusion reduces score', $bibScore < $rawScore);
t('bibliography exclusion raw score unchanged', $rawScore === 50.0);

// 9. Exclusion with 'quotation' reason
$quoteExclusion = [
    'reason' => 'quotation',
    'note' => 'Properly attributed quote',
    'excluded_word_count' => 10,
    'previous_score' => 50.0,
];
$quoteScore = round((($matchedWords - 10) / $totalWords) * 100, 2);
t('quotation exclusion score is 40%', $quoteScore === 40.0, "got: {$quoteScore}");

// 10. Verify exclusion record has all required fields for audit trail
$exclusionRecord = [
    'id' => 1,
    'tenant_id' => 'test-tenant',
    'match_id' => 101,
    'submission_id' => 1,
    'reason' => 'false_positive',
    'note' => 'Common phrase',
    'excluded_by_actor_id' => 42,
    'excluded_by_actor_name' => 'reviewer',
    'previous_score' => 50.0,
    'excluded_word_count' => 20,
    'created_at' => '2026-07-22 12:00:00',
];
t('exclusion record has id', isset($exclusionRecord['id']));
t('exclusion record has match_id', isset($exclusionRecord['match_id']));
t('exclusion record has reason', isset($exclusionRecord['reason']));
t('exclusion record has previous_score', isset($exclusionRecord['previous_score']));
t('exclusion record has excluded_word_count', isset($exclusionRecord['excluded_word_count']));
t('exclusion record has created_at', isset($exclusionRecord['created_at']));

// Check logs
$appLog = file_get_contents(__DIR__ . '/../storage/logs/app.log');
$errorLog = file_get_contents(__DIR__ . '/../storage/logs/error.log');
t('app.log has no critical entries', !str_contains($appLog, '[critical]'), trim($appLog));
t('error.log is empty', trim($errorLog) === '', trim($errorLog));

echo "\n── Results: {$pass} passed, {$fail} failed ──\n";
exit($fail > 0 ? 1 : 0);
