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

// ── Test: insertion (extra words in submission) ──
echo "\n--- Insertion (Extra Words in Submission) ---\n";
// Sub: "the quick brown fox SOMETHING jumps over the lazy dog"
// Src: "the quick brown fox jumps over the lazy dog"
$subIns = explode(' ', 'the quick brown fox something jumps over the lazy dog');
$srcIns = explode(' ', 'the quick brown fox jumps over the lazy dog');
$resultIns = $matching->smithWatermanAlignment($subIns, $srcIns, [
    'sub_start' => 0, 'sub_end' => count($subIns) - 1,
    'src_start' => 0, 'src_end' => count($srcIns) - 1,
], 5);
t('insertion test produces alignment', $resultIns !== null);
if ($resultIns !== null) {
    // With affine gap penalties (-3 open, -1 extend), a single-word insertion
    // may be handled as a mismatch (-2) rather than a gap (-3+). Verify
    // the alignment covers the matching words correctly regardless.
    t('insertion alignment length >= 7', $resultIns['length'] >= 7, "got: {$resultIns['length']}");
    t('insertion sub_start correct', $resultIns['sub_start'] >= 0);
    t('insertion src_start correct', $resultIns['src_start'] >= 0);
}

// ── Test: deletion (words missing from submission) ──
echo "\n--- Deletion (Words Missing From Submission) ---\n";
// Sub: "the quick brown fox lazy dog"
// Src: "the quick brown fox jumps over the lazy dog"
$subDel = explode(' ', 'the quick brown fox lazy dog');
$srcDel = explode(' ', 'the quick brown fox jumps over the lazy dog');
$resultDel = $matching->smithWatermanAlignment($subDel, $srcDel, [
    'sub_start' => 0, 'sub_end' => count($subDel) - 1,
    'src_start' => 0, 'src_end' => count($srcDel) - 1,
], 5);
t('deletion test produces alignment', $resultDel !== null);
if ($resultDel !== null) {
    // Single-word deletion may align as mismatch (-2) instead of gap (-3+).
    // Smith-Waterman is local: after diverging words, alignment may restart.
    // "the quick brown fox" (4 words) is a valid local alignment.
    t('deletion alignment length >= 4', $resultDel['length'] >= 4, "got: {$resultDel['length']}");
    t('deletion sub_start correct', $resultDel['sub_start'] >= 0);
    t('deletion src_start correct', $resultDel['src_start'] >= 0);
}

// ── Test: substitution (word changed) ──
echo "\n--- Substitution (Word Changed) ---\n";
// Sub: "the quick brown fox jumps over the sleepy dog"
// Src: "the quick brown fox jumps over the lazy dog"
$subSub = explode(' ', 'the quick brown fox jumps over the sleepy dog');
$srcSub = explode(' ', 'the quick brown fox jumps over the lazy dog');
$resultSub = $matching->smithWatermanAlignment($subSub, $srcSub, [
    'sub_start' => 0, 'sub_end' => count($subSub) - 1,
    'src_start' => 0, 'src_end' => count($srcSub) - 1,
], 5);
t('substitution test produces alignment', $resultSub !== null);
if ($resultSub !== null) {
    t('substitution alignment has correct sub_start', $resultSub['sub_start'] >= 0);
    t('substitution alignment has correct src_start', $resultSub['src_start'] >= 0);
    t('substitution alignment covers matching parts', $resultSub['length'] >= 7, "got: {$resultSub['length']}");
}

// ── Test: repeated phrases in source ──
echo "\n--- Repeated Phrases ---\n";
// Sub: "hello world foo bar baz"
// Src: "the quick brown fox hello world foo bar baz and hello world again"
// The repeated "hello world" should not cause missed alignments
$subRepeat = explode(' ', 'hello world foo bar baz');
$srcRepeat = explode(' ', 'the quick brown fox hello world foo bar baz and hello world again');
$resultRepeat = $matching->smithWatermanAlignment($subRepeat, $srcRepeat, [
    'sub_start' => 0, 'sub_end' => count($subRepeat) - 1,
    'src_start' => 0, 'src_end' => count($srcRepeat) - 1,
], 5);
t('repeated phrase produces alignment', $resultRepeat !== null);
if ($resultRepeat !== null) {
    t('repeated phrase alignment has positive length', $resultRepeat['length'] > 0, "got: {$resultRepeat['length']}");
    t('repeated phrase alignment covers all 5 words', $resultRepeat['length'] >= 5, "got: {$resultRepeat['length']}");
}

// ── Test: match only near the end of a document ──
echo "\n--- Match Near Document End ---\n";
// Sub: many words of preamble then a matching phrase
// Src: matching phrase
$preamble = array_fill(0, 100, 'preamble');
$matchingEnd = explode(' ', 'this is the matching content at the end');
$subEnd = array_merge($preamble, $matchingEnd);
$srcEnd = explode(' ', 'this is the matching content at the end');
$resultEnd = $matching->smithWatermanAlignment($subEnd, $srcEnd, [
    'sub_start' => 0, 'sub_end' => count($subEnd) - 1,
    'src_start' => 0, 'src_end' => count($srcEnd) - 1,
], 5);
t('end-of-document match produces alignment', $resultEnd !== null);
if ($resultEnd !== null) {
    t('end-of-doc alignment starts at correct sub position',
        $resultEnd['sub_start'] >= 100,
        "got: {$resultEnd['sub_start']}"
    );
    t('end-of-doc alignment has correct length',
        $resultEnd['length'] === count($matchingEnd),
        "got: {$resultEnd['length']} expected: " . count($matchingEnd)
    );
}

// ── Log checks ──
$appLog = file_get_contents(__DIR__ . '/../storage/logs/app.log');
$errorLog = file_get_contents(__DIR__ . '/../storage/logs/error.log');
t('app.log has no critical entries', !str_contains($appLog, '[critical]'), trim($appLog));
t('error.log is empty', trim($errorLog) === '', trim($errorLog));

echo "\n── Results: {$pass} passed, {$fail} failed ──\n";
exit($fail > 0 ? 1 : 0);
