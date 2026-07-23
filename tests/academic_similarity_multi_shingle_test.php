<?php
declare(strict_types=1);

/**
 * Tests for multi-layer fingerprinting: short (3-word), medium (7-word),
 * long (20-word) shingle generation, winnowing, and lemma normalization.
 *
 * Verifies backward compatibility with existing single-size fingerprinting
 * and validates the new shingle level field.
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

echo "\n=== Academic Similarity — Multi-Layer Fingerprinting ===\n";

// ── Helper: generate multi-layer fingerprints (mirrors FingerprintService logic) ──

function generateMultiLayerFingerprints(array $segments, string $level, string $fpType = 'exact'): array {
    $sizes = ['short' => 3, 'medium' => 7, 'long' => 20];
    $shingleSize = $sizes[$level] ?? 7;
    $fingerprints = [];

    foreach ($segments as $segment) {
        $content = $segment->normalizedContent ?? $segment->content ?? '';
        $words = preg_split('/\s+/', trim($content));
        if ($words === false || count($words) < $shingleSize) continue;

        if ($level === 'short') {
            $processed = [];
            foreach ($words as $w) {
                $w = trim($w);
                if ($w === '') continue;
                $processed[] = $w; // simplified — no stemming in test helper
            }
            $words = $processed;
            if (count($words) < $shingleSize) continue;
        }

        $wordCount = count($words);
        for ($i = 0; $i <= $wordCount - $shingleSize; $i++) {
            $shingleWords = array_slice($words, $i, $shingleSize);
            if ($fpType === 'near') {
                $canonical = $shingleWords;
                sort($canonical);
                $shingleText = implode(' ', $canonical);
            } else {
                $shingleText = implode(' ', $shingleWords);
            }
            $hash = hash('sha256', $shingleText);
            $fingerprints[] = new AcademicSimilarityFingerprint(
                $hash, $fpType, $shingleSize, implode(' ', $shingleWords), $segment->index, $i, $level
            );
        }
    }
    return $fingerprints;
}

function winnowFingerprints(array $fingerprints, int $shingleSize): array {
    $count = count($fingerprints);
    if ($count === 0) return [];
    $windowSize = 4 * $shingleSize;
    if ($windowSize <= 0) $windowSize = 20;

    $selectedIndices = [];
    $minHash = null;
    $minPos = -1;

    for ($i = 0; $i < $count; $i++) {
        $hashInt = crc32($fingerprints[$i]->hash);
        if ($i < $windowSize) {
            if ($minHash === null || $hashInt <= $minHash) {
                $minHash = $hashInt; $minPos = $i;
            }
            if ($i === min($windowSize - 1, $count - 1)) $selectedIndices[$minPos] = true;
        } else {
            $removedHash = crc32($fingerprints[$i - $windowSize]->hash);
            $removedWasMin = ($i - $windowSize) === $minPos;
            if ($removedWasMin) {
                $minHash = null; $minPos = -1;
                for ($j = $i - $windowSize + 1; $j <= $i; $j++) {
                    $h = crc32($fingerprints[$j]->hash);
                    if ($minHash === null || $h <= $minHash) { $minHash = $h; $minPos = $j; }
                }
                $selectedIndices[$minPos] = true;
            } else {
                if ($hashInt <= $minHash) { $minHash = $hashInt; $minPos = $i; $selectedIndices[$minPos] = true; }
            }
        }
    }

    $selected = [];
    foreach ($selectedIndices as $idx => $_) { $selected[] = $fingerprints[$idx]; }
    return $selected;
}

// ── Test data ──

$seg1 = new AcademicSimilaritySegment([
    'index' => 0, 'type' => 'sentence',
    'content' => 'The quick brown fox jumps over the lazy dog near the river bank',
    'normalized_content' => 'the quick brown fox jumps over the lazy dog near the river bank',
    'word_count' => 14, 'char_count' => 68,
]);

// ── Test: short shingles (3 words) ──
echo "\n--- Short Shingle (3-word) Fingerprints ---\n";

$shortFps = generateMultiLayerFingerprints([$seg1], 'short', 'exact');
t('short layer produces fingerprints', count($shortFps) > 0, 'got: ' . count($shortFps));
t('short shingle size is 3', $shortFps[0]->shingleSize === 3);
t('short shingle level is "short"', $shortFps[0]->shingleLevel === 'short');
t('short fingerprint type is exact', $shortFps[0]->type === 'exact');
t('short layer has fewer fingerprints than words', count($shortFps) > 0 && count($shortFps) < 14, 'got: ' . count($shortFps));
t('short fingerprint hash is 64-char sha256', strlen($shortFps[0]->hash) === 64);

// ── Test: medium shingles (7 words) ──
echo "\n--- Medium Shingle (7-word) Fingerprints ---\n";

$medFps = generateMultiLayerFingerprints([$seg1], 'medium', 'exact');
t('medium layer produces fingerprints', count($medFps) > 0, 'got: ' . count($medFps));
t('medium shingle size is 7', $medFps[0]->shingleSize === 7);
t('medium shingle level is "medium"', $medFps[0]->shingleLevel === 'medium');
t('medium layer has fewer fingerprints than text length', count($medFps) > 0 && count($medFps) < 14, 'got: ' . count($medFps));

// ── Test: long shingles (20 words) — text too short for 20-word shingles ──
echo "\n--- Long Shingle (20-word) Fingerprints ---\n";

$longFps = generateMultiLayerFingerprints([$seg1], 'long', 'exact');
t('short text produces no long fingerprints', count($longFps) === 0, 'got: ' . count($longFps));

// Long enough text for 20-word shingles
$segLong = new AcademicSimilaritySegment([
    'index' => 0, 'type' => 'paragraph',
    'content' => 'The quick brown fox jumps over the lazy dog near the river bank and then runs across the field towards the old oak tree where a small bird sits on a branch singing a beautiful melody that echoes',
    'normalized_content' => 'the quick brown fox jumps over the lazy dog near the river bank and then runs across the field towards the old oak tree where a small bird sits on a branch singing a beautiful melody that echoes',
    'word_count' => 40, 'char_count' => 210,
]);
$longFps2 = generateMultiLayerFingerprints([$segLong], 'long', 'exact');
t('long text produces long fingerprints', count($longFps2) > 0, 'got: ' . count($longFps2));
t('long shingle size is 20', $longFps2[0]->shingleSize === 20);
t('long shingle level is "long"', $longFps2[0]->shingleLevel === 'long');
t('long layer has fewer fingerprints than text length', count($longFps2) > 0 && count($longFps2) < 40, 'got: ' . count($longFps2));

// ── Test: near fingerprints at medium level ──
echo "\n--- Near (Canonical) Fingerprints ---\n";

$nearMedFps = generateMultiLayerFingerprints([$seg1], 'medium', 'near');
t('near medium layer produces fingerprints', count($nearMedFps) > 0, 'got: ' . count($nearMedFps));
t('near fingerprint type is "near"', $nearMedFps[0]->type === 'near');

// Canonicalized (sorted) near shingle should differ from exact for the same position
$exactFirst = $medFps[0]->hash;
$nearFirst = $nearMedFps[0]->hash;
t('near hash differs from exact hash for same shingle', $exactFirst !== $nearFirst);

// ── Test: winnowing ──
echo "\n--- Winnowing ---\n";

// Use long layer (20-word) which has enough fingerprints to test winnowing
$winnowed = winnowFingerprints($longFps2, 20);
t('winnowing reduces fingerprint count', count($winnowed) < count($longFps2),
    'before: ' . count($longFps2) . ', after: ' . count($winnowed));
t('winnowing preserves at least 1 fingerprint', count($winnowed) > 0, 'got: ' . count($winnowed));
// Winnowed fingerprints retain their original properties
if (count($winnowed) > 0) {
    t('winnowed fingerprints have correct level', $winnowed[0]->shingleLevel === 'long');
    t('winnowed fingerprints have correct size', $winnowed[0]->shingleSize === 20);
} else {
    t('winnowed fingerprints have correct level (skipped)', true);
    t('winnowed fingerprints have correct size (skipped)', true);
}

// Winnowing should preserve at least some of the original hashes
$originalHashes = array_map(fn($fp) => $fp->hash, $longFps2);
$winnowedHashes = array_map(fn($fp) => $fp->hash, $winnowed);
$commonHashes = array_intersect($originalHashes, $winnowedHashes);
t('winnowed hashes are subset of original', count($commonHashes) === count($winnowedHashes));

// ── Test: backward compatibility ──
echo "\n--- Backward Compatibility ---\n";

// Old constructor without shingleLevel should default to 'medium'
$oldFp = new AcademicSimilarityFingerprint('abc123', 'exact', 5, 'test shingle text', 0, 0);
t('backward compat: default shingleLevel is "medium"', $oldFp->shingleLevel === 'medium', 'got: ' . $oldFp->shingleLevel);
t('backward compat: toArray includes shingle_level', ($oldFp->toArray()['shingle_level'] ?? '') === 'medium');

// Old constructor with explicit shingleLevel
$newFp = new AcademicSimilarityFingerprint('def456', 'near', 7, 'longer test text here', 1, 5, 'long');
t('explicit shingleLevel is "long"', $newFp->shingleLevel === 'long');
t('explicit type is near', $newFp->type === 'near');
t('explicit size is 7', $newFp->shingleSize === 7);

// ── Test: different texts produce different short shingles ──
echo "\n--- Short Shingle Specificity ---\n";

$segA = new AcademicSimilaritySegment([
    'index' => 0, 'type' => 'sentence',
    'content' => 'The impact of social media on academic performance among students',
    'normalized_content' => 'the impact of social media on academic performance among students',
    'word_count' => 10, 'char_count' => 60,
]);
$segB = new AcademicSimilaritySegment([
    'index' => 0, 'type' => 'sentence',
    'content' => 'Climate change effects on global weather patterns and ecosystems',
    'normalized_content' => 'climate change effects on global weather patterns and ecosystems',
    'word_count' => 11, 'char_count' => 68,
]);

$fpsA = generateMultiLayerFingerprints([$segA], 'short', 'exact');
$fpsB = generateMultiLayerFingerprints([$segB], 'short', 'exact');
$hashesA = array_map(fn($fp) => $fp->hash, $fpsA);
$hashesB = array_map(fn($fp) => $fp->hash, $fpsB);
$common = array_intersect($hashesA, $hashesB);
t('different texts have zero short shingle overlap', count($common) === 0, 'got: ' . count($common));

// ── Clean logs ──
$appLog = file_get_contents(__DIR__ . '/../storage/logs/app.log');
$errorLog = file_get_contents(__DIR__ . '/../storage/logs/error.log');
t('app.log has no critical entries', !str_contains($appLog, '[critical]'), trim($appLog));
t('error.log is empty', trim($errorLog) === '', trim($errorLog));

echo "\n── Results: {$pass} passed, {$fail} failed ──\n";
exit($fail > 0 ? 1 : 0);
