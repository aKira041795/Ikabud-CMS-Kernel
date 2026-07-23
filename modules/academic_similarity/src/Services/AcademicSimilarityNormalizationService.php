<?php
declare(strict_types=1);

/**
 * Text normalization and pre-filtering service.
 *
 * Handles normalization for fingerprint generation, plus false-positive
 * reduction filters: bibliography detection, quotation detection,
 * citation-aware exclusion, and common-phrase filtering.
 */
class AcademicSimilarityNormalizationService
{
    private string $tenantId;

    /** Headers that mark the start of a bibliography/references section */
    private const BIBLIOGRAPHY_HEADERS = [
        'references', 'bibliography', 'works cited', 'sources',
        'further reading', 'references:', 'bibliography:',
        'works cited:', 'sources:', 'references cited',
        'reference list', 'select bibliography',
    ];

    /** @var array|null Cached common academic phrases */
    private static ?array $commonPhrases = null;

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
     * Normalize for matching — applies stemming and stop-word removal.
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
     */
    public function stem(string $word): string
    {
        if (strlen($word) <= 3) {
            return $word;
        }

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
     * Common English stop words.
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

    // ── False-positive reduction filters ─────────────────────────

    /**
     * Detect if a line is a bibliography/reference section header.
     */
    public function isBibliographyHeader(string $line): bool
    {
        $trimmed = trim($line);
        $lower = mb_strtolower($trimmed, 'UTF-8');
        foreach (self::BIBLIOGRAPHY_HEADERS as $header) {
            if ($lower === $header) {
                return true;
            }
        }
        return false;
    }

    /**
     * Detect if a line looks like a bibliography/reference entry or header.
     */
    public function isBibliographyLine(string $line): bool
    {
        // Check for bibliography section headers
        $trimmed = trim($line);
        $lower = mb_strtolower($trimmed, 'UTF-8');
        foreach (self::BIBLIOGRAPHY_HEADERS as $header) {
            if ($lower === $header) {
                return true;
            }
        }

        // Check for reference entry patterns
        $patterns = [
            '/\b(?:pp\.?\s*\d+|Vol\.?\s*\d+|no\.?\s*\d+)\s*$/i',
            '/^\[?\d+\]?\s+[A-Z][a-z]+.*\(\d{4}\)\./',       // APA-style reference
            '/^[A-Z][a-z]+,\s*[A-Z]\./',                       // Author, I. style
            '/^[A-Z][a-z]+,\s*[A-Z][a-z]+,\s*&amp;\s*[A-Z]/', // Author, A., & Author
            '/[A-Z][a-z]+\s+et\s+al\./',                       // Author et al.
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, trim($line))) return true;
        }
        return false;
    }

    /**
     * Detect if text is a quotation (enclosed in quotes).
     */
    public function isQuotation(string $text): bool
    {
        $trimmed = trim($text);
        return (str_starts_with($trimmed, '"') && str_ends_with($trimmed, '"'))
            || (str_starts_with($trimmed, "'") && str_ends_with($trimmed, "'"))
            || (str_starts_with($trimmed, '「') && str_ends_with($trimmed, '」'))
            || (str_starts_with($trimmed, "\xe2\x80\x9c") && str_ends_with($trimmed, "\xe2\x80\x9d")); // curly quotes
    }

    /**
     * Detect in-text citations in a line of text.
     * Returns array of citation strings found.
     *
     * Matches: (Author, YYYY), (Author et al., YYYY), [1], [Author, YYYY]
     */
    public function detectCitations(string $text): array
    {
        $citations = [];

        // Parenthetical: (Author, YYYY) or (Author et al., YYYY)
        if (preg_match_all('/\([A-Z][a-z]+(?:\s+et\s+al\.?)?(?:\s*,\s*(?:19|20)\d{2}[a-z]?)\)/', $text, $m)) {
            foreach ($m[0] as $c) {
                $citations[] = $c;
            }
        }

        // Numeric: [1], [1,2], [1-3]
        if (preg_match_all('/\[[\d,\-\s]+\]/', $text, $m)) {
            foreach ($m[0] as $c) {
                $citations[] = $c;
            }
        }

        // Narrative: Author (YYYY) or Author et al. (YYYY)
        if (preg_match_all('/[A-Z][a-z]+(?:\s+et\s+al\.?)?\s*\((?:19|20)\d{2}[a-z]?\)/', $text, $m)) {
            foreach ($m[0] as $c) {
                $citations[] = $c;
            }
        }

        return $citations;
    }

    /**
     * Detect the word range (line numbers) of a bibliography section in multi-line text.
     *
     * @param string $text Raw text with newlines
     * @return array{start: int|null, end: int|null} Line range of bibliography section
     */
    public function detectBibliographyRange(string $text): array
    {
        $lines = explode("\n", $text);
        $start = null;
        $end = null;

        foreach ($lines as $i => $line) {
            if ($start === null && $this->isBibliographyHeader($line)) {
                $start = $i;
                continue;
            }
            if ($start !== null && $end === null) {
                // Check if we've left the bibliography section
                // (blank line followed by a non-bibliography-looking line)
                if (trim($line) === '' && isset($lines[$i + 1])) {
                    $nextTrimmed = trim($lines[$i + 1]);
                    if ($nextTrimmed !== '' && !$this->isBibliographyLine($nextTrimmed)) {
                        $end = $i;
                    }
                }
            }
        }

        if ($start !== null && $end === null) {
            $end = count($lines) - 1;
        }

        return ['start' => $start, 'end' => $end];
    }

    /**
     * Get built-in common academic phrases for optional exclusion.
     *
     * @return string[] List of common academic phrases (lowercase)
     */
    public static function getCommonPhrases(): array
    {
        if (self::$commonPhrases === null) {
            self::$commonPhrases = [
                'literature review', 'research shows', 'previous studies',
                'according to', 'this study', 'the purpose of',
                'this research', 'data collection', 'data analysis',
                'research methodology', 'research design', 'sample size',
                'ethical considerations', 'limitations of the study',
                'future research', 'further research', 'results showed',
                'findings indicate', 'findings suggest', 'this paper',
                'this article', 'the present study', 'in this study',
                'in this paper', 'as shown in', 'as can be seen',
                'it is important', 'it should be noted', 'it is worth noting',
                'in other words', 'in addition', 'furthermore',
                'moreover', 'however', 'therefore', 'consequently',
                'nevertheless', 'on the other hand', 'in contrast',
                'as a result', 'for example', 'for instance',
                'such as', 'in particular', 'specifically',
                'in general', 'overall', 'in summary',
                'to conclude', 'in conclusion', 'the findings',
                'the results', 'the data', 'the analysis',
                'the methodology', 'the approach', 'the framework',
                'significant difference', 'statistically significant',
                'p value', 'standard deviation', 'mean score',
                'null hypothesis', 'research question', 'research questions',
            ];
        }
        return self::$commonPhrases;
    }

    /**
     * Check if a text snippet is a common academic phrase.
     */
    public function isCommonPhrase(string $text): bool
    {
        $lower = mb_strtolower(trim($text), 'UTF-8');
        foreach (self::getCommonPhrases() as $phrase) {
            if (str_contains($lower, $phrase)) {
                return true;
            }
        }
        return false;
    }
}
