<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Workbench\Comprehension\Analyzers;

use Ikabud\Kernel\Workbench\Comprehension\Contracts\ChainLink;

/**
 * NLP-Enhanced Semantic Scorer.
 *
 * Upgrades the existing regex+Jaccard scorer with:
 *   1. Regex — kept for obvious failure/success signatures (fast path)
 *   2. TF-IDF weighted term overlap — stronger than Jaccard for evidence matching
 *   3. Character n-gram similarity — catches partial/typo matches
 *   4. Weighted source-context similarity — scores evidence against link description
 *      using a lightweight embedding proxy (word frequency vectors)
 *   5. Historical calibration — adjusts scores based on past outcomes
 *
 * Each technique contributes to a weighted ensemble score.
 */
class EmbeddingScorer
{
    /** Known success-signature patterns per category (kept from SemanticScorer) */
    private const SUCCESS_PATTERNS = [
        'ui' => ['/click|visible|rendered|loaded|appear/i'],
        'http' => ['/200|201|ok|success|redirect/i'],
        'service' => ['/return|completed|processed|applied|transition/i'],
        'db' => ['/insert|update|affected|row|commit/i'],
        'event' => ['/fired|trigger|dispatch|emitted|event/i'],
        'audit' => ['/audit|logged|recorded|written/i'],
    ];

    /** Known failure-signature patterns per category */
    private const FAILURE_PATTERNS = [
        'ui' => ['/404|500|forbidden|not found|timeout|error/i'],
        'http' => ['/403|422|500|419|csrf|expired|invalid token/i'],
        'service' => ['/exception|invalid|failed|denied|not allowed/i'],
        'db' => ['/syntax|constraint|duplicate|not null|deadlock|drift|missing/i'],
        'event' => ['/failed to fire|listener error|unhandled/i'],
        'audit' => ['/audit failed|log error|write failed/i'],
    ];

    /** Stop words for tokenization */
    private const STOP_WORDS = [
        'the', 'a', 'an', 'in', 'on', 'at', 'to', 'for', 'of', 'by',
        'is', 'was', 'are', 'were', 'be', 'been', 'being',
        'has', 'have', 'had', 'do', 'does', 'did',
        'this', 'that', 'these', 'those', 'it', 'its',
        'and', 'or', 'but', 'not', 'no', 'if',
    ];

    /**
     * Score evidence similarity for a chain link using ensemble methods.
     *
     * @param ChainLink $link The chain link to score
     * @param array $evidence All runtime evidence
     * @param array $history Optional historical calibration {successes: int, failures: int}
     * @return array{score: float, components: array<string, float>, matched_pattern: ?string, evidence_snippet: ?string}
     */
    public function scoreLink(ChainLink $link, array $evidence, array $history = []): array
    {
        $evidenceText = $this->extractRelevantEvidence($link, $evidence);
        if ($evidenceText === null || $evidenceText === '') {
            return [
                'score' => 0.0,
                'components' => ['regex' => 0.0, 'tfidf' => 0.0, 'ngram' => 0.0, 'vector' => 0.0, 'historical' => 0.0],
                'matched_pattern' => null,
                'evidence_snippet' => null,
            ];
        }

        $snippet = mb_substr(is_string($evidenceText) ? $evidenceText : json_encode($evidenceText), 0, 200);
        $matchedPattern = null;

        // Component scores
        $regexScore = $this->computeRegexScore($link, (string)$evidenceText, $matchedPattern);
        $tfidfScore = $this->computeTfIdfScore($link, (string)$evidenceText);
        $ngramScore = $this->computeNgramScore($link, (string)$evidenceText);
        $vectorScore = $this->computeVectorScore($link, (string)$evidenceText);
        $historicalScore = $this->computeHistoricalCalibration($history);

        // Boolean evidence handling — override everything
        if (is_bool($evidenceText)) {
            $regexScore = $evidenceText ? 1.0 : 0.0;
        }
        if ($evidenceText === true || $evidenceText === 1 || $evidenceText === '1') {
            $regexScore = 1.0;
        }

        // Weighted ensemble
        $weights = ['regex' => 0.40, 'tfidf' => 0.25, 'ngram' => 0.15, 'vector' => 0.10, 'historical' => 0.10];
        $components = [
            'regex' => round($regexScore, 4),
            'tfidf' => round($tfidfScore, 4),
            'ngram' => round($ngramScore, 4),
            'vector' => round($vectorScore, 4),
            'historical' => round($historicalScore, 4),
        ];

        $score = 0.0;
        foreach ($weights as $name => $weight) {
            $score += ($components[$name] ?? 0) * $weight;
        }

        return [
            'score' => round(max(0.0, min(1.0, $score)), 4),
            'components' => $components,
            'matched_pattern' => $matchedPattern,
            'evidence_snippet' => $snippet,
        ];
    }

    /**
     * Regex-based scoring (fast path — same as original SemanticScorer).
     */
    private function computeRegexScore(ChainLink $link, string $evidenceText, ?string &$matchedPattern): float
    {
        $score = 0.0;

        // Success patterns
        $successPatterns = self::SUCCESS_PATTERNS[$link->category] ?? [];
        foreach ($successPatterns as $pattern) {
            if (preg_match($pattern, $evidenceText)) {
                $score += 0.4;
                $matchedPattern = 'success:' . $pattern;
                break;
            }
        }

        // Failure patterns (negative)
        $failurePatterns = self::FAILURE_PATTERNS[$link->category] ?? [];
        foreach ($failurePatterns as $pattern) {
            if (preg_match($pattern, $evidenceText)) {
                $score -= 0.5;
                $matchedPattern = 'failure:' . $pattern;
                break;
            }
        }

        // Step-name proximity
        $stepTokens = $this->tokenize($link->step);
        foreach ($stepTokens as $token) {
            if (stripos($evidenceText, $token) !== false) {
                $score += 0.1;
            }
        }

        return max(0.0, min(1.0, $score));
    }

    /**
     * TF-IDF weighted term overlap scoring.
     *
     * Computes term frequency overlap between link description and evidence,
     * weighted by inverse document frequency (rarer terms contribute more).
     */
    private function computeTfIdfScore(ChainLink $link, string $evidenceText): float
    {
        $descTokens = $this->tokenize($link->description);
        $evidTokens = $this->tokenize($evidenceText);

        if (empty($descTokens) || empty($evidTokens)) {
            return 0.0;
        }

        // TF-IDF: score = sum over terms in desc of (tf_in_evidence * idf)
        // idf ≈ 1 / (1 + global_frequency) — since we don't have a corpus,
        // use a simple rarity proxy: shorter words are more common
        $totalScore = 0.0;
        $maxPossible = 0.0;

        foreach ($descTokens as $term) {
            $idf = 1.0 / (1.0 + (strlen($term) <= 4 ? 3.0 : 1.0)); // shorter = more common = lower idf
            $tfInEvidence = count(array_keys($evidTokens, $term, true)) / max(1, count($evidTokens));
            $totalScore += $tfInEvidence * $idf;
            $maxPossible += $idf; // max score if all desc terms appear in evidence
        }

        return $maxPossible > 0 ? min(1.0, $totalScore / $maxPossible) : 0.0;
    }

    /**
     * Character n-gram similarity (trigrams).
     *
     * Catches partial matches, typos, and morphological variants.
     */
    private function computeNgramScore(ChainLink $link, string $evidenceText): float
    {
        $descGrams = $this->ngrams(mb_strtolower($link->description), 3);
        $evidGrams = $this->ngrams(mb_strtolower($evidenceText), 3);

        if (empty($descGrams) || empty($evidGrams)) {
            return 0.0;
        }

        $intersection = array_intersect($descGrams, $evidGrams);
        $union = array_unique(array_merge($descGrams, $evidGrams));

        return count($union) > 0 ? count($intersection) / count($union) : 0.0;
    }

    /**
     * Lightweight vector similarity (word frequency vector cosine).
     *
     * Builds a word frequency vector from the description and evidence,
     * then computes cosine similarity. Acts as a simple embedding proxy.
     */
    private function computeVectorScore(ChainLink $link, string $evidenceText): float
    {
        $descTokens = $this->tokenize($link->description);
        $evidTokens = $this->tokenize($evidenceText);

        if (empty($descTokens) || empty($evidTokens)) {
            return 0.0;
        }

        // Build vocabulary
        $vocab = array_unique(array_merge($descTokens, $evidTokens));

        // Build frequency vectors
        $descVec = [];
        $evidVec = [];
        foreach ($vocab as $word) {
            $descVec[$word] = count(array_keys($descTokens, $word, true));
            $evidVec[$word] = count(array_keys($evidTokens, $word, true));
        }

        // Cosine similarity
        $dotProduct = 0.0;
        $descMag = 0.0;
        $evidMag = 0.0;

        foreach ($vocab as $word) {
            $dotProduct += $descVec[$word] * $evidVec[$word];
            $descMag += $descVec[$word] ** 2;
            $evidMag += $evidVec[$word] ** 2;
        }

        $magnitude = sqrt($descMag) * sqrt($evidMag);
        return $magnitude > 0 ? min(1.0, $dotProduct / $magnitude) : 0.0;
    }

    /**
     * Historical calibration — adjusts score based on past outcomes.
     *
     * If a link historically fails, lower the score (it needs more evidence).
     * If a link historically succeeds, slightly boost.
     */
    private function computeHistoricalCalibration(array $history): float
    {
        if (empty($history)) {
            return 0.0; // No history = no adjustment
        }

        $successes = (int)($history['successes'] ?? 0);
        $failures = (int)($history['failures'] ?? 0);
        $total = $successes + $failures;

        if ($total === 0) {
            return 0.0;
        }

        $failRate = $failures / $total;

        // Calibrate: high failure rate → lower calibration (need more evidence)
        // Low failure rate → slight boost
        return 1.0 - ($failRate * 0.3); // up to 0.3 adjustment
    }

    /**
     * Generate character n-grams from text.
     *
     * @return array<int, string>
     */
    private function ngrams(string $text, int $n): array
    {
        $grams = [];
        $len = mb_strlen($text);
        for ($i = 0; $i <= $len - $n; $i++) {
            $grams[] = mb_substr($text, $i, $n);
        }
        return $grams;
    }

    /**
     * Extract the most relevant evidence for a given link.
     */
    private function extractRelevantEvidence(ChainLink $link, array $evidence): mixed
    {
        if (array_key_exists($link->step, $evidence)) {
            return $evidence[$link->step];
        }

        if (isset($evidence[$link->category])) {
            $cat = $evidence[$link->category];
            if (is_array($cat) && isset($cat[$link->step])) {
                return $cat[$link->step];
            }
            if (is_string($cat) || is_bool($cat)) {
                return $cat;
            }
        }

        $stepParts = explode('.', $link->step);
        $lastPart = end($stepParts);
        foreach ($evidence as $key => $val) {
            if (stripos((string)$key, (string)$lastPart) !== false) {
                return $val;
            }
        }

        return null;
    }

    /**
     * Tokenize a string into lowercase word tokens (stop-word filtered).
     *
     * @return array<int, string>
     */
    private function tokenize(string $text): array
    {
        $words = preg_split('/\W+/', mb_strtolower($text));
        return array_values(array_filter($words, fn(string $w) =>
            strlen($w) > 2 && !in_array($w, self::STOP_WORDS, true)
        ));
    }

    /**
     * Score an entire action chain and return per-link results.
     *
     * @param array<int, ChainLink> $chain
     * @param array $evidence
     * @param array<string, array{successes: int, failures: int}> $history Map of step -> history
     * @return array<string, array>
     */
    public function scoreChain(array $chain, array $evidence, array $history = []): array
    {
        $results = [];
        foreach ($chain as $link) {
            $linkHistory = $history[$link->step] ?? [];
            $results[$link->step] = $this->scoreLink($link, $evidence, $linkHistory);
        }
        return $results;
    }
}
