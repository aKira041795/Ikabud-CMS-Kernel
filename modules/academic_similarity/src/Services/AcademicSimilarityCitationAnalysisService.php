<?php
declare(strict_types=1);

/**
 * AISS — Citation & Attribution Diagnostics Service (Phase 3)
 *
 * Detects in-text citations, parses bibliography/reference entries,
 * links in-text citations to reference entries, and checks for
 * attribution signals such as quotation marks, citation proximity,
 * and source-support consistency.
 *
 * Currently supports APA-style author-year citations with a framework
 * for expanding to other formats.
 *
 * @see AcademicSimilarityEvidenceTaxonomy for attribution status values
 */

class AcademicSimilarityCitationAnalysisService
{
    private string $tenantId;

    // APA-style author-year regex patterns
    private const PATTERN_AUTHOR_YEAR = '/([A-Z][a-zà-ü]+(?:\s(?:et\s+al\.|&\s+[A-Z][a-zà-ü]+))?)\s*\((\d{4}[a-z]?)\)/';
    private const PATTERN_YEAR_ONLY = '/\((\d{4}[a-z]?)\)/';
    private const PATTERN_PAGE = '/(?:p\.\s*|pp\.\s*|:\s*)(\d+)/';

    public function __construct(string $tenantId)
    {
        $this->tenantId = $tenantId;
    }

    /**
     * Analyze a passage for citation and attribution signals.
     *
     * @param string $submissionPassage The passage from the submission
     * @param string|null $bibliographyText Optional bibliography/references section text
     * @return array{
     *   ok: bool,
     *   has_citation: bool,
     *   citation_count: int,
     *   citations: array,
     *   has_quotation_marks: bool,
     *   citation_proximity: string|null,
     *   attribution_status: string,
     *   reference_entries: array,
     *   missing_references: array,
     *   signals: array,
     * }
     */
    public function analyzePassage(string $submissionPassage, ?string $bibliographyText = null): array
    {
        $signals = [];

        // 1. Detect quotation marks
        $hasQuotes = $this->detectQuotationMarks($submissionPassage);
        $signals[] = $hasQuotes
            ? 'Quotation marks detected in passage'
            : 'No quotation marks detected';

        // 2. Detect in-text citations
        $citations = $this->extractCitations($submissionPassage);
        $hasCitation = count($citations) > 0;
        if ($hasCitation) {
            $signals[] = count($citations) . ' in-text citation(s) detected near the passage';
        } else {
            $signals[] = 'No in-text citation detected in this passage';
        }

        // 3. Check citation proximity (citation near matching text)
        $proximity = $this->checkCitationProximity($submissionPassage, $citations);

        // 4. Parse bibliography if provided
        $referenceEntries = [];
        $missingReferences = [];
        if ($bibliographyText !== null && $bibliographyText !== '') {
            $referenceEntries = $this->parseBibliography($bibliographyText);

            // 5. Cross-reference citations against bibliography
            $missingReferences = $this->findMissingReferences($citations, $referenceEntries);
            if (!empty($missingReferences)) {
                $signals[] = count($missingReferences) . ' citation(s) not found in the provided references';
            }
        }

        // 6. Determine attribution status
        $attributionStatus = $this->determineAttributionStatus(
            $hasCitation,
            $hasQuotes,
            $proximity,
            $missingReferences
        );

        if ($attributionStatus === AcademicSimilarityEvidenceTaxonomy::ATTRIBUTION_PRESENT_SUPPORTED) {
            $signals[] = 'Citation is present and quotation marks support direct attribution';
        } elseif ($attributionStatus === AcademicSimilarityEvidenceTaxonomy::ATTRIBUTION_MISSING) {
            $signals[] = 'No citation found near the matching passage — possible unattributed use';
        }

        return [
            'ok' => true,
            'has_citation' => $hasCitation,
            'citation_count' => count($citations),
            'citations' => $citations,
            'has_quotation_marks' => $hasQuotes,
            'citation_proximity' => $proximity,
            'attribution_status' => $attributionStatus,
            'reference_entries' => $referenceEntries,
            'missing_references' => $missingReferences,
            'signals' => $signals,
        ];
    }

    /**
     * Detect quotation marks in text.
     */
    public function detectQuotationMarks(string $text): bool
    {
        return preg_match('/["""]/u', $text) === 1;
    }

    /**
     * Extract in-text citations using APA author-year pattern.
     */
    public function extractCitations(string $text): array
    {
        $citations = [];

        // Match author-year patterns like "Smith (2020)" or "(Smith, 2020)"
        if (preg_match_all(self::PATTERN_AUTHOR_YEAR, $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $citations[] = [
                    'type' => 'author_year',
                    'author' => trim($match[1]),
                    'year' => $match[2],
                    'full_match' => $match[0],
                ];
            }
        }

        // Also detect standalone parenthetical years "(2020)"
        if (preg_match_all(self::PATTERN_YEAR_ONLY, $text, $yearMatches, PREG_SET_ORDER)) {
            foreach ($yearMatches as $ym) {
                // Avoid duplicates already caught by author-year pattern
                $alreadyFound = false;
                foreach ($citations as $c) {
                    if (str_contains($ym[0], $c['full_match'] ?? '')) {
                        $alreadyFound = true;
                        break;
                    }
                }
                if (!$alreadyFound) {
                    $citations[] = [
                        'type' => 'year_only',
                        'author' => null,
                        'year' => $ym[1],
                        'full_match' => $ym[0],
                    ];
                }
            }
        }

        return $citations;
    }

    /**
     * Check whether citations appear near the matching passage offset.
     * Returns a description of proximity.
     */
    public function checkCitationProximity(string $passage, array $citations): ?string
    {
        if (empty($citations)) {
            return null;
        }

        // Check if citation appears in the first or last 30% of the passage
        $passageLen = strlen($passage);
        $firstThird = $passageLen * 0.3;
        $lastThird = $passageLen * 0.7;

        $hasNearStart = false;
        $hasNearEnd = false;

        foreach ($citations as $citation) {
            $pos = strpos($passage, $citation['full_match']);
            if ($pos !== false) {
                if ($pos <= $firstThird) {
                    $hasNearStart = true;
                }
                if ($pos >= $lastThird) {
                    $hasNearEnd = true;
                }
            }
        }

        if ($hasNearStart && $hasNearEnd) {
            return 'Citations appear throughout the passage, including near the matching text';
        }
        if ($hasNearStart) {
            return 'Citation appears near the beginning of the passage';
        }
        if ($hasNearEnd) {
            return 'Citation appears near the end of the passage — may apply to preceding text';
        }

        return 'Citation positions within the passage';
    }

    /**
     * Parse a bibliography/references section into structured entries.
     * Supports APA-style reference entries.
     */
    public function parseBibliography(string $bibliographyText): array
    {
        $entries = [];
        $lines = preg_split('/\n\s*(?=(?:[A-Z]|\[))/m', $bibliographyText);

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;

            // Try to extract author and year from APA reference format
            if (preg_match('/^([A-Z][^,]+(?:,\s*[A-Z]\.)+[^)]*)\s*\((\d{4}[a-z]?)\)/', $line, $m)) {
                $entries[] = [
                    'raw' => $line,
                    'author' => trim($m[1]),
                    'year' => $m[2],
                    'format' => 'apa',
                ];
            } elseif (preg_match('/\[(\d{4}[a-z]?)\]/', $line, $ym)) {
                // IEEE/numeric style
                $entries[] = [
                    'raw' => $line,
                    'author' => null,
                    'year' => $ym[1],
                    'format' => 'numeric',
                ];
            }
        }

        return $entries;
    }

    /**
     * Find citations that don't have a matching reference entry.
     */
    public function findMissingReferences(array $citations, array $referenceEntries): array
    {
        if (empty($referenceEntries) || empty($citations)) {
            return [];
        }

        $missing = [];
        foreach ($citations as $citation) {
            $found = false;
            foreach ($referenceEntries as $ref) {
                // Match by author name
                if ($citation['author'] !== null && $ref['author'] !== null) {
                    $citeAuthor = strtolower(trim($citation['author']));
                    $refAuthor = strtolower(trim(explode(',', $ref['author'])[0]));
                    if (str_contains($citeAuthor, $refAuthor) || str_contains($refAuthor, $citeAuthor)) {
                        $found = true;
                        break;
                    }
                }
                // Match by year alone if no author match
                if ($citation['year'] === $ref['year'] && !$found) {
                    $found = true; // Weak match — year only
                }
            }
            if (!$found) {
                $missing[] = $citation;
            }
        }

        return $missing;
    }

    /**
     * Determine overall attribution status for a passage.
     */
    public function determineAttributionStatus(
        bool $hasCitation,
        bool $hasQuotes,
        ?string $proximity,
        array $missingReferences
    ): string {
        if (!$hasCitation) {
            return AcademicSimilarityEvidenceTaxonomy::ATTRIBUTION_MISSING;
        }
        if ($hasQuotes && $hasCitation && empty($missingReferences)) {
            return AcademicSimilarityEvidenceTaxonomy::ATTRIBUTION_PRESENT_SUPPORTED;
        }
        if ($hasCitation && !empty($missingReferences)) {
            return AcademicSimilarityEvidenceTaxonomy::ATTRIBUTION_PRESENT_INCOMPLETE;
        }
        if ($proximity === null) {
            return AcademicSimilarityEvidenceTaxonomy::ATTRIBUTION_PRESENT_INCOMPLETE;
        }

        return AcademicSimilarityEvidenceTaxonomy::ATTRIBUTION_PRESENT_SUPPORTED;
    }
}
