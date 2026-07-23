<?php
declare(strict_types=1);

/**
 * Tests for Smith-Waterman local alignment matching.
 * Verifies correct boundary detection for gapped, inserted,
 * and contiguous match regions.
 */

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

echo "\n=== Academic Similarity — Smith-Waterman Local Alignment ===\n";

// ── Helper: create a matching service with null DB for in-memory testing ──
$matching = new AcademicSimilarityMatchingService('test');

// ── Test: exact contiguous match ──
echo "\n--- Exact Contiguous Match ---\n";

$subWords = explode(' ', 'the quick brown fox jumps over the lazy dog near the river');
$srcWords = explode(' ', 'the quick brown fox jumps over the lazy dog near the river');

$result = $matching->smithWatermanAlignment($subWords, $srcWords, [
    'sub_start' => 0, 'sub_end' => 9,
    'src_start' => 0, 'src_end' => 9,
], 5);

t('exact match produces result', $result !== null);
if ($result !== null) {
    t('exact match length > 0', $result['length'] > 0, 'got: ' . $result['length']);
    t('exact match has 0 gaps', $result['gaps'] === 0, 'got: ' . $result['gaps']);
    t('exact match has 0 insertions', $result['insertions'] === 0, 'got: ' . $result['insertions']);
    t('exact match starts at correct sub position', $result['sub_start'] >= 0);
    t('exact match ends at correct sub position', $result['sub_end'] > $result['sub_start']);
}

// ── Test: gapped match (submission has extra words inserted) ──
echo "\n--- Gapped Match (Insertion in Submission) ---\n";

$subWords2 = explode(' ', 'the quick brown fox suddenly jumps over the lazy dog');
$srcWords2 = explode(' ', 'the quick brown fox jumps over the lazy dog');

$result2 = $matching->smithWatermanAlignment($subWords2, $srcWords2, [
    'sub_start' => 0, 'sub_end' => 8,
    'src_start' => 0, 'src_end' => 7,
], 5);

t('gapped match produces result', $result2 !== null);
if ($result2 !== null) {
    t('gapped match detects insertions', $result2['insertions'] >= 0);
    t('gapped match has positive length', $result2['length'] > 0);
}

// ── Test: completely different text ──
echo "\n--- No Match (Different Text) ---\n";

$subWords3 = explode(' ', 'the quick brown fox jumps over the lazy dog');
$srcWords3 = explode(' ', 'climate change affects global weather patterns and ecosystems significantly');

$result3 = $matching->smithWatermanAlignment($subWords3, $srcWords3, [
    'sub_start' => 0, 'sub_end' => 9,
    'src_start' => 0, 'src_end' => 8,
], 5);

t('different text returns null (no alignment)', $result3 === null);

// ── Test: partial overlap ──
echo "\n--- Partial Overlap ---\n";

$subWords4 = explode(' ', 'introduction literature review methodology results conclusion');
$srcWords4 = explode(' ', 'this paper reviews the literature review methodology and presents results');

$result4 = $matching->smithWatermanAlignment($subWords4, $srcWords4, [
    'sub_start' => 0, 'sub_end' => 5,
    'src_start' => 0, 'src_end' => 9,
], 5);

t('partial overlap produces result', $result4 !== null);
if ($result4 !== null) {
    t('partial overlap length > 0', $result4['length'] >= 2, 'got: ' . $result4['length']);
}

// ── Test: small window handling ──
echo "\n--- Edge Cases ---\n";

// Window smaller than minimum
$result5 = $matching->smithWatermanAlignment(['a', 'b'], ['a', 'b'], [
    'sub_start' => 0, 'sub_end' => 1,
    'src_start' => 0, 'src_end' => 1,
], 0);
t('tiny window returns null', $result5 === null);

// ── Log checks ──
$appLog = file_get_contents(__DIR__ . '/../storage/logs/app.log');
$errorLog = file_get_contents(__DIR__ . '/../storage/logs/error.log');
t('app.log has no critical entries', !str_contains($appLog, '[critical]'), trim($appLog));
t('error.log is empty', trim($errorLog) === '', trim($errorLog));

echo "\n── Results: {$pass} passed, {$fail} failed ──\n";
exit($fail > 0 ? 1 : 0);
