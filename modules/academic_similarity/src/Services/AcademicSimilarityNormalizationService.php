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
     * Normalize for matching — applies stemming and stop-word removal
     * so that "students", "student", "studying" all reduce to the same root.
     * Used for fingerprint generation and text-level comparison.
     */
    public function normalizeForMatching(string $text): string
    {
        $normalized = $this->normalizeForComparison($text);
        $words = explode(' ', $normalized);
        $stemmed = [];
        foreach ($words as $word) {
            $word = trim($word);
            if ($word === '' || $this->isStopWord($word)) {
                continue;
            }
            $stemmed[] = $this->stem($word);
        }
        return implode(' ', $stemmed);
    }

    /**
     * Simple English stemmer — strips common suffixes.
     * Not a full Porter stemmer, but catches the most common variants.
     */
    public function stem(string $word): string
    {
        if (strlen($word) <= 3) {
            return $word;
        }

        // Step 1: strip common suffixes (ordered by length, longest first)
        $suffixes = [
            'ational' => 'ate', 'tional' => 'tion', 'enci' => 'ence',
            'anci' => 'ance', 'izer' => 'ize', 'bli' => 'ble',
            'alli' => 'al', 'entli' => 'ent', 'eli' => 'e',
            'ousli' => 'ous', 'ization' => 'ize', 'ation' => 'ate',
            'ator' => 'ate', 'alism' => 'al', 'iveness' => 'ive',
            'fulness' => 'ful', 'ousness' => 'ous', 'aliti' => 'al',
            'iviti' => 'ive', 'biliti' => 'ble', 'logi' => 'log',
            'ing' => '', 'edly' => 'ed', 'ment' => '',
            'ness' => '', 'tion' => 't', 'sion' => 's',
            'able' => '', 'ible' => '', 'ment' => '',
            'ful' => '', 'less' => '', 'ous' => '',
            'ive' => '', 'ize' => '', 'ise' => '',
            'ly' => '', 'ed' => '', 'es' => '',
            's' => '', 'er' => '', 'est' => '',
        ];

        foreach ($suffixes as $suffix => $replacement) {
            $suffixLen = strlen($suffix);
            if (strlen($word) > $suffixLen + 1 && substr($word, -$suffixLen) === $suffix) {
                $stem = substr($word, 0, -$suffixLen);
                if ($replacement !== '') {
                    $stem .= $replacement;
                }
                return $stem;
            }
        }

        return $word;
    }

    /**
     * Common English stop words — removed during matching normalization
     * to focus on content-bearing words.
     */
    public function isStopWord(string $word): bool
    {
        static $stopWords = null;
        if ($stopWords === null) {
            $stopWords = array_flip([
                'the', 'a', 'an', 'and', 'or', 'but', 'in', 'on', 'at',
                'to', 'for', 'of', 'with', 'by', 'from', 'is', 'are',
                'was', 'were', 'be', 'been', 'being', 'have', 'has', 'had',
                'do', 'does', 'did', 'will', 'would', 'shall', 'should',
                'may', 'might', 'must', 'can', 'could', 'it', 'its',
                'this', 'that', 'these', 'those', 'not', 'no', 'nor',
                'so', 'very', 'just', 'also', 'such', 'than', 'then',
                'into', 'upon', 'over', 'under', 'after', 'before',
                'between', 'through', 'during', 'about', 'each', 'every',
                'all', 'both', 'few', 'more', 'most', 'other', 'some',
                'one', 'two', 'three', 'he', 'she', 'they', 'we', 'you',
                'his', 'her', 'their', 'our', 'your', 'my', 'me', 'us',
                'as', 'if', 'when', 'where', 'which', 'who', 'whom',
                'how', 'what', 'why', 'while', 'although', 'because',
            ]);
        }
        return isset($stopWords[$word]);
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
