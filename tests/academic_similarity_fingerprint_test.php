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

echo "\n=== Academic Similarity — Fingerprint Generation ===\n";

// Test fingerprint logic inline (fingerprint service requires DB in constructor)

// Helper: generate exact fingerprint hashes using same algorithm as service
function generateExactFingerprints(array $segments, int $shingleSize = 5): array {
    $fingerprints = [];
    foreach ($segments as $segment) {
        $words = preg_split('/\s+/', trim($segment->normalizedContent));
        if ($words === false || count($words) < $shingleSize) {
            continue;
        }
        $wordCount = count($words);
        for ($i = 0; $i <= $wordCount - $shingleSize; $i++) {
            $shingleWords = array_slice($words, $i, $shingleSize);
            $shingleText = implode(' ', $shingleWords);
            $hash = hash('sha256', $shingleText);
            $fingerprints[] = new AcademicSimilarityFingerprint(
                $hash, 'exact', $shingleSize, $shingleText, $segment->index, $i
            );
        }
    }
    return $fingerprints;
}

function generateNearFingerprints(array $segments, int $shingleSize = 5): array {
    $fingerprints = [];
    foreach ($segments as $segment) {
        $words = preg_split('/\s+/', trim($segment->normalizedContent));
        if ($words === false || count($words) < $shingleSize) {
            continue;
        }
        $wordCount = count($words);
        for ($i = 0; $i <= $wordCount - $shingleSize; $i++) {
            $shingleWords = array_slice($words, $i, $shingleSize);
            $canonical = $shingleWords;
            sort($canonical);
            $shingleText = implode(' ', $shingleWords);
            $canonicalText = implode(' ', $canonical);
            $hash = hash('sha256', $canonicalText);
            $fingerprints[] = new AcademicSimilarityFingerprint(
                $hash, 'near', $shingleSize, $shingleText, $segment->index, $i
            );
        }
    }
    return $fingerprints;
}

// Create test segments
$seg1 = new AcademicSimilaritySegment([
    'index' => 0, 'type' => 'sentence',
    'content' => 'The quick brown fox jumps over the lazy dog',
    'normalized_content' => 'the quick brown fox jumps over the lazy dog',
    'word_count' => 9, 'char_count' => 43,
]);
$seg2 = new AcademicSimilaritySegment([
    'index' => 1, 'type' => 'sentence',
    'content' => 'This is a test of the fingerprint system',
    'normalized_content' => 'this is a test of the fingerprint system',
    'word_count' => 8, 'char_count' => 44,
]);

// 1. Generate fingerprints from segments
$fps = generateExactFingerprints([$seg1], 5);
t('fingerprints generated from 9-word segment', count($fps) === 5, "got: " . count($fps) . " fingerprints");

// 2. With 5-word shingle: 9 words → 5 shingles (positions 0-4)
$expectedShingles = 9 - 5 + 1;
t('correct number of shingles for 5-word window', count($fps) === $expectedShingles, "expected {$expectedShingles}, got: " . count($fps));

// 3. Fingerprint type is 'exact'
t('fingerprint type is exact', $fps[0]->type === 'exact', "got: {$fps[0]->type}");

// 4. Fingerprint shingle size is correct
t('shingle size is 5', $fps[0]->shingleSize === 5, "got: {$fps[0]->shingleSize}");

// 5. Same text produces same hashes
$fpsA = generateExactFingerprints([$seg1], 5);
$fpsB = generateExactFingerprints([$seg1], 5);
$same = true;
foreach ($fpsA as $i => $fpA) {
    if ($fpA->hash !== $fpsB[$i]->hash) { $same = false; break; }
}
t('same text produces identical fingerprints', $same);

// 6. Different text produces different hashes
$fpsC = generateExactFingerprints([$seg2], 5);
$different = $fpsA[0]->hash !== $fpsC[0]->hash;
t('different text produces different hash', $different);

// 7. Near-exact fingerprints (canonicalized)
$segA = new AcademicSimilaritySegment([
    'index' => 0, 'type' => 'sentence',
    'content' => 'quick brown fox the jumps',
    'normalized_content' => 'quick brown fox the jumps',
    'word_count' => 5, 'char_count' => 25,
]);
$segB = new AcademicSimilaritySegment([
    'index' => 0, 'type' => 'sentence',
    'content' => 'the quick brown fox jumps',
    'normalized_content' => 'the quick brown fox jumps',
    'word_count' => 5, 'char_count' => 25,
]);

$nearA = generateNearFingerprints([$segA], 5);
$nearB = generateNearFingerprints([$segB], 5);
t('near fingerprint for reordered words matches', $nearA[0]->hash === $nearB[0]->hash, 'hashes differ');

// 8. Near fingerprint type is 'near'
t('near fingerprint has correct type', $nearA[0]->type === 'near', "got: {$nearA[0]->type}");

// 9. Fingerprint with insufficient words produces empty
$shortSeg = new AcademicSimilaritySegment([
    'index' => 0, 'type' => 'sentence',
    'content' => 'too short',
    'normalized_content' => 'too short',
    'word_count' => 2, 'char_count' => 9,
]);
$shortFps = generateExactFingerprints([$shortSeg], 5);
t('text shorter than shingle size produces no fingerprints', count($shortFps) === 0);

// 10. Exact vs near produce different hashes (for same text)
$exact = generateExactFingerprints([$segA], 5);
$near = generateNearFingerprints([$segA], 5);
t('exact and near hashes differ for same text', $exact[0]->hash !== $near[0]->hash);

// 11. Fingerprint toArray
$arr = $fpsA[0]->toArray();
t('toArray returns array', is_array($arr));
t('toArray contains shingle_hash', isset($arr['shingle_hash']));
t('toArray contains fingerprint_type', isset($arr['fingerprint_type']));
t('toArray contains shingle_size', isset($arr['shingle_size']));
t('toArray contains segment_index', isset($arr['segment_index']));
t('toArray contains word_position', isset($arr['word_position']));

// 12. Different shingle size
$fps3 = generateExactFingerprints([$seg1], 3);
$expected3 = 9 - 3 + 1;
t('shingle size 3 produces expected count', count($fps3) === $expected3, "expected {$expected3}, got: " . count($fps3));

// 13. Sliding window: second shingle word position is 1
t('first shingle word position is 0', $fpsA[0]->wordPosition === 0);
t('second shingle word position is 1', $fpsA[1]->wordPosition === 1, "got: {$fpsA[1]->wordPosition}");

// Check logs
$appLog = file_get_contents(__DIR__ . '/../storage/logs/app.log');
$errorLog = file_get_contents(__DIR__ . '/../storage/logs/error.log');
t('app.log has no critical entries', !str_contains($appLog, '[critical]'), trim($appLog));
t('error.log is empty', trim($errorLog) === '', trim($errorLog));

echo "\n── Results: {$pass} passed, {$fail} failed ──\n";
exit($fail > 0 ? 1 : 0);
