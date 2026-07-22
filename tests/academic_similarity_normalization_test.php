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

echo "\n=== Academic Similarity — Normalization Service ===\n";

$normalizer = new AcademicSimilarityNormalizationService('test-tenant');

// 1. Lowercasing
$result = $normalizer->normalize('Hello World');
t('lowercases text', $result->normalizedText === 'hello world', "got: {$result->normalizedText}");

// 2. Whitespace normalization
$result = $normalizer->normalize('hello   world');
t('normalizes multiple spaces', $result->normalizedText === 'hello world', "got: {$result->normalizedText}");

// 3. Leading/trailing whitespace trimmed
$result = $normalizer->normalize('  spaced out  ');
t('trims leading/trailing whitespace', $result->normalizedText === 'spaced out', "got: {$result->normalizedText}");

// 4. Punctuation stripping
$result = $normalizer->normalize('hello, world!');
t('strips commas and exclamation marks', $result->normalizedText === 'hello world', "got: {$result->normalizedText}");

// 5. Preserve intra-word hyphens
$result = $normalizer->normalize('state-of-the-art');
t('preserves intra-word hyphens', $result->normalizedText === 'state-of-the-art', "got: {$result->normalizedText}");

// 6. Preserve apostrophes
$result = $normalizer->normalize("don't");
t("preserves apostrophes", $result->normalizedText === "don't", "got: {$result->normalizedText}");

// 7. Leading hyphen is preserved (service preserves all hyphens)
$result = $normalizer->normalize('- leading dash');
t('leading hyphen preserved (service preserves all hyphens)', $result->normalizedText === '- leading dash', "got: {$result->normalizedText}");

// 8. Empty string
$result = $normalizer->normalize('');
t('empty string returns empty normalized text', $result->normalizedText === '', "got: '{$result->normalizedText}'");

// 9. Offset map preserves positions — verify map exists
$result = $normalizer->normalize('abc');
t('offset map is populated', count($result->offsetMap) > 0, 'map keys: ' . implode(',', array_keys($result->offsetMap)));

// 10. NormalizedText metadata: word counts
$result = $normalizer->normalize('Hello beautiful world');
t('originalWordCount reflects source', $result->originalWordCount === 3, "got: {$result->originalWordCount}");
t('normalizedWordCount reflects normalized', $result->normalizedWordCount === 3, "got: {$result->normalizedWordCount}");

// 11. NormalizedText textHash
$result = $normalizer->normalize('hello world');
t('textHash is a valid sha256 hash', strlen($result->textHash) === 64, "got length: " . strlen($result->textHash));

// 12. Same input → same hash
$r1 = $normalizer->normalize('Hello World');
$r2 = $normalizer->normalize('Hello World');
t('same input produces same hash', $r1->textHash === $r2->textHash);

// 13. Bibliography detection
t('detects "References" as bibliography', $normalizer->isBibliographyLine('References'));
t('detects "Bibliography" as bibliography', $normalizer->isBibliographyLine('Bibliography'));
t('detects "Works Cited" as bibliography', $normalizer->isBibliographyLine('Works Cited'));
t('detects APA reference as bibliography', $normalizer->isBibliographyLine('Smith, J. (2020). Title. Journal.'));
t('detects "pp. 123-145" reference', $normalizer->isBibliographyLine('Smith, J. (2020). Title. Journal, 12(3), pp. 123-145.'));
t('does not flag normal sentence as bibliography', !$normalizer->isBibliographyLine('This is a normal sentence about a topic.'));

// 14. Quotation detection
t('detects double-quoted string', $normalizer->isQuotation('"hello world"'));
t('detects single-quoted string', $normalizer->isQuotation("'hello world'"));
t('detects japanese quotation marks', $normalizer->isQuotation('「hello world」'));
t('does not flag unquoted string', !$normalizer->isQuotation('hello world'));

// 15. normalizeForComparison returns only string
$str = $normalizer->normalizeForComparison('Hello, World!');
t('normalizeForComparison returns string', is_string($str));
t('normalizeForComparison strips punctuation', $str === 'hello world', "got: {$str}");

// Check logs
$appLog = file_get_contents(__DIR__ . '/../storage/logs/app.log');
$errorLog = file_get_contents(__DIR__ . '/../storage/logs/error.log');
t('app.log has no critical entries', !str_contains($appLog, '[critical]'), trim($appLog));
t('error.log is empty', trim($errorLog) === '', trim($errorLog));

echo "\n── Results: {$pass} passed, {$fail} failed ──\n";
exit($fail > 0 ? 1 : 0);
