<?php
declare(strict_types=1);

/**
 * Tests for false-positive reduction filters:
 * - Bibliography detection (isBibliographyHeader, isBibliographyLine)
 * - Quotation detection (isQuotation)
 * - Citation detection (detectCitations)
 * - Common phrase detection (isCommonPhrase, getCommonPhrases)
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

echo "\n=== Academic Similarity — False-Positive Reduction ===\n";

$normalizer = new AcademicSimilarityNormalizationService('test');

// ── Bibliography header detection ──
echo "\n--- Bibliography Header Detection ---\n";

t('detects "References" as bibliography header', $normalizer->isBibliographyHeader('References'));
t('detects "Bibliography" as bibliography header', $normalizer->isBibliographyHeader('Bibliography'));
t('detects "Works Cited" as bibliography header', $normalizer->isBibliographyHeader('Works Cited'));
t('detects "references" (lowercase) as header', $normalizer->isBibliographyHeader('references'));
t('detects "References:" as header', $normalizer->isBibliographyHeader('References:'));
t('detects "Further Reading" as header', $normalizer->isBibliographyHeader('Further Reading'));
t('detects "Sources" as header', $normalizer->isBibliographyHeader('Sources'));
t('does not flag plain text as header', !$normalizer->isBibliographyHeader('This is a normal sentence'));
t('does not flag empty string as header', !$normalizer->isBibliographyHeader(''));

// ── Bibliography line detection (backward compat) ──
echo "\n--- Bibliography Line Detection ---\n";

t('detects "References" as bibliography line (backward compat)', $normalizer->isBibliographyLine('References'));
t('detects "Bibliography" as bibliography line (backward compat)', $normalizer->isBibliographyLine('Bibliography'));
t('detects "Works Cited" as bibliography line (backward compat)', $normalizer->isBibliographyLine('Works Cited'));
t('detects APA reference', $normalizer->isBibliographyLine('Smith, J. (2020). Title. Journal.'));
t('detects "pp. 123-145" reference', $normalizer->isBibliographyLine('Smith, J. (2020). Title. Journal, 12(3), pp. 123-145.'));
t('detects Author, I. style reference', $normalizer->isBibliographyLine('Smith, J., & Jones, M. (2021). Title.'));
t('detects et al. reference', $normalizer->isBibliographyLine('Johnson et al. (2022) found that...'));
t('does not flag normal sentence', !$normalizer->isBibliographyLine('This is a normal sentence about a topic.'));

// ── Bibliography range detection ──
echo "\n--- Bibliography Range Detection ---\n";

$textWithRefs = "Introduction\nThis is the body text.\nReferences\nSmith, J. (2020). Title.\nJones, M. (2021). Another title.\n";
$range = $normalizer->detectBibliographyRange($textWithRefs);
t('detects bibliography section start', $range['start'] === 2, 'got: ' . ($range['start'] ?? 'null'));
t('detects bibliography section end', $range['end'] === 4 || $range['end'] === 5, 'got: ' . ($range['end'] ?? 'null'));

$textWithoutRefs = "Introduction\nThis is the body text.\nConclusion";
$range2 = $normalizer->detectBibliographyRange($textWithoutRefs);
t('no bibliography in plain text', $range2['start'] === null);

// ── Quotation detection ──
echo "\n--- Quotation Detection ---\n";

t('detects double-quoted text', $normalizer->isQuotation('"This is a quoted passage."'));
t('detects single-quoted text', $normalizer->isQuotation("'This is a quoted passage.'"));
t('detects Japanese quotation marks', $normalizer->isQuotation('「This is a quoted passage」'));
t('detects curly double quotes', $normalizer->isQuotation("\xe2\x80\x9cThis is quoted\xe2\x80\x9d"));
t('does not flag unquoted text', !$normalizer->isQuotation('This is not quoted.'));
t('does not flag single word', !$normalizer->isQuotation('word'));

// ── Citation detection ──
echo "\n--- Citation Detection ---\n";

$citations1 = $normalizer->detectCitations('This is a statement (Smith, 2020).');
t('detects parenthetical citation', count($citations1) >= 1, 'got: ' . count($citations1));

$citations2 = $normalizer->detectCitations('This is a statement [1].');
t('detects numeric bracket citation', count($citations2) >= 1, 'got: ' . count($citations2));

$citations3 = $normalizer->detectCitations('As Smith (2020) argued, this is important.');
t('detects narrative citation', count($citations3) >= 1, 'got: ' . count($citations3));

$citations4 = $normalizer->detectCitations('Normal text without any citations here.');
t('no citations in plain text', count($citations4) === 0, 'got: ' . count($citations4));

// ── Common phrase detection ──
echo "\n--- Common Phrase Detection ---\n";

t('common phrases list is not empty', count(AcademicSimilarityNormalizationService::getCommonPhrases()) > 50);
t('detects "literature review" as common phrase', $normalizer->isCommonPhrase('A literature review was conducted'));
t('detects "research shows" as common phrase', $normalizer->isCommonPhrase('Research shows that this is true'));
t('detects "in conclusion" as common phrase', $normalizer->isCommonPhrase('In conclusion, this study found'));
t('does not flag unique text as common phrase', !$normalizer->isCommonPhrase('Quantum entanglement in superconducting qubits'));

// ── Log checks ──
$appLog = file_get_contents(__DIR__ . '/../storage/logs/app.log');
$errorLog = file_get_contents(__DIR__ . '/../storage/logs/error.log');
t('app.log has no critical entries', !str_contains($appLog, '[critical]'), trim($appLog));
t('error.log is empty', trim($errorLog) === '', trim($errorLog));

echo "\n── Results: {$pass} passed, {$fail} failed ──\n";
exit($fail > 0 ? 1 : 0);
