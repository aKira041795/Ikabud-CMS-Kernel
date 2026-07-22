<?php
declare(strict_types=1);

class AcademicSimilarityNormalizationService
{
    private string $tenantId;

    public function __construct(string $tenantId) {
        $this->tenantId = $tenantId;
    }

    /**
     * Normalize extracted text for fingerprint comparison.
     * Returns AcademicSimilarityNormalizedText with offset map.
     */
    public function normalize(string $text): AcademicSimilarityNormalizedText
    {
        $original = $text;
        $offsetMap = [];
        $normalized = '';
        $origPos = 0;
        $normPos = 0;
        $len = strlen($text);

        while ($origPos < $len) {
            $char = $text[$origPos];
            $lower = mb_strtolower($char, 'UTF-8');

            // Skip whitespace runs - normalize to single space
            if (ctype_space($char)) {
                if ($normalized !== '' && substr($normalized, -1) !== ' ') {
                    $normalized .= ' ';
                    $normPos++;
                }
                $offsetMap[$normPos] = $origPos;
                $origPos++;
                continue;
            }

            // Strip punctuation except intra-word hyphens and apostrophes
            $ord = ord($char);
            $isPunct = ($ord < 48) || ($ord > 57 && $ord < 65) || ($ord > 90 && $ord < 97) || ($ord > 122 && $ord < 128);
            if ($isPunct && $char !== '-' && $char !== "'") {
                $offsetMap[$normPos] = $origPos;
                $origPos++;
                continue;
            }

            $normalized .= $lower;
            $offsetMap[$normPos] = $origPos;
            $normPos++;
            $origPos++;
        }

        $normalized = trim($normalized);

        return new AcademicSimilarityNormalizedText($original, $normalized, $offsetMap);
    }

    /**
     * Normalize for comparison (aggressive - for matching, not display).
     */
    public function normalizeForComparison(string $text): string
    {
        $result = $this->normalize($text);
        return $result->normalizedText;
    }

    /**
     * Detect if a line appears to be a bibliography/reference entry.
     */
    public function isBibliographyLine(string $line): bool
    {
        $patterns = [
            '/^references$/im',
            '/^bibliography$/im',
            '/^works cited$/im',
            '/^sources$/im',
            '/^further reading$/im',
            '/\b(?:pp\.?\s*\d+|Vol\.?\s*\d+|no\.?\s*\d+)\s*$/i',
            '/^\[?\d+\]?\s+[A-Z][a-z]+.*\(\d{4}\)\./', // APA-style reference
            '/^[A-Z][a-z]+,\s*[A-Z]\./', // Author, I. style
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, trim($line))) return true;
        }
        return false;
    }

    /**
     * Detect if text appears to be a quotation (enclosed in quotes).
     */
    public function isQuotation(string $text): bool
    {
        $trimmed = trim($text);
        return (str_starts_with($trimmed, '"') && str_ends_with($trimmed, '"'))
            || (str_starts_with($trimmed, "'") && str_ends_with($trimmed, "'"))
            || (str_starts_with($trimmed, '「') && str_ends_with($trimmed, '」'));
    }
}
