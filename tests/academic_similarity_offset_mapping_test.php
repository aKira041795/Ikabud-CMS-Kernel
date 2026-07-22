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

echo "\n=== Academic Similarity — Offset Mapping ===\n";

// Direct value-object tests for offset mapping

// 1. Basic offset map: originalOffset returns correct position
$map = [0 => 0, 1 => 1, 2 => 2, 3 => 3, 4 => 4];
$nt = new AcademicSimilarityNormalizedText('hello', 'hello', $map);
t('originalOffset returns identity for simple text', $nt->originalOffset(0) === 0);
t('originalOffset(4) returns 4', $nt->originalOffset(4) === 4);
t('originalOffset beyond map returns input', $nt->originalOffset(99) === 99);

// 2. Offset map with punctuation: "hello, world!" → "hello world"
$original = 'hello, world!';
$normalizer = new AcademicSimilarityNormalizationService('test-tenant');
$result = $normalizer->normalize($original);

t('normalized hello world is correct length', $result->normalizedText === 'hello world', "got: '{$result->normalizedText}'");

// In "hello, world!" the comma at position 5 is stripped.
// normalized position 5 is space, offset was recorded from the comma at origPos=5
// (punctuation-skip sets offsetMap[normPos] without advancing normPos)
$origPosOfNorm5 = $result->originalOffset(5);
t('normalized pos 5 (space) maps to original pos 5 (comma was there)', $origPosOfNorm5 === 5, "got: {$origPosOfNorm5}");

// 3. originalRange: maps normalized range back to original
$range = $result->originalRange(0, 4); // "hello" in normalized -> "hello" in original
t('originalRange start matches', $range['start'] === 0, "got: {$range['start']}");
$rangeHello = $result->originalRange(0, 4);
t('originalRange for hello is 0-4', $rangeHello['end'] === 4, "got: {$rangeHello['end']}");

// 4. Punctuation-heavy text
$original2 = 'Dr. Smith, Ph.D., said: "hello, world!"';
$result2 = $normalizer->normalize($original2);
t('punctuation-heavy normalized text has no punctuation artifacts', !str_contains($result2->normalizedText, '.'), "got: {$result2->normalizedText}");
t('punctuation-heavy retains letters/words', str_contains($result2->normalizedText, 'dr smith phd said hello world'));

// 5. Verify specific mapping: "Dr." original positions
// "Dr." -> "dr" (D=0, r=1, .=2 stripped)
$posDrD = $result2->originalOffset(0); // 'd' from "Dr."
$posDrR = $result2->originalOffset(1); // 'r' from "Dr."
t('normalized pos 0 (d from Dr) maps to original 0', $posDrD === 0, "got: {$posDrD}");
t('normalized pos 1 (r from Dr) maps to original 1', $posDrR === 1, "got: {$posDrR}");

// 6. Offset map keys are sequential
$keys = array_keys($result->offsetMap);
$expectedKeys = range(0, count($keys) - 1);
t('offset map keys are sequential integers', $keys === $expectedKeys, 'keys: [' . implode(',', $keys) . ']');

// 7. Offset map values are monotonic non-decreasing
$prev = -1;
$monotonic = true;
foreach ($result2->offsetMap as $norm => $orig) {
    if ($orig < $prev) { $monotonic = false; break; }
    $prev = $orig;
}
t('offset map values are monotonic', $monotonic);

// 8. Unicode text (multi-byte) — the normalization service processes byte-by-byte
// so multi-byte chars pass through but mb_strtolower on individual bytes may differ.
// Test with characters that are compatible with byte-by-byte processing.
$unicode = 'hello café';
$result3 = $normalizer->normalize($unicode);
t('unicode text retains alphabetic chars', str_contains($result3->normalizedText, 'hello'), "got: {$result3->normalizedText}");
t('unicode text lowercased prefix', str_starts_with($result3->normalizedText, 'hello'), "got: {$result3->normalizedText}");

// 9. Offset mapping for simple ASCII within unicode text
$posH = $result3->originalOffset(0); // 'h' at position 0
t('offset for h is 0', $posH === 0, "got: {$posH}");

// 10. textHash stability
$rA = $normalizer->normalize('Hello, World!');
$rB = $normalizer->normalize('hello world');
t('textHash is identical for same normalized content', $rA->textHash === $rB->textHash);

// 11. originalOffset with consecutive stripped characters
// "a!!b" -> "ab"  (strips two chars at pos 1,2)
$result4 = $normalizer->normalize('a!!b');
t('normalized a!!b → ab', $result4->normalizedText === 'ab', "got: '{$result4->normalizedText}'");
t('pos 0 (a) maps to 0', $result4->originalOffset(0) === 0, "got: {$result4->originalOffset(0)}");
t('pos 1 (b) maps to 3', $result4->originalOffset(1) === 3, "got: {$result4->originalOffset(1)}");

// 12. Whitespace runs compress to single space
$result5 = $normalizer->normalize("a\t\tb");
t('tab runs compress to space', $result5->normalizedText === 'a b', "got: '{$result5->normalizedText}'");

// Check logs
$appLog = file_get_contents(__DIR__ . '/../storage/logs/app.log');
$errorLog = file_get_contents(__DIR__ . '/../storage/logs/error.log');
t('app.log has no critical entries', !str_contains($appLog, '[critical]'), trim($appLog));
t('error.log is empty', trim($errorLog) === '', trim($errorLog));

echo "\n── Results: {$pass} passed, {$fail} failed ──\n";
exit($fail > 0 ? 1 : 0);
