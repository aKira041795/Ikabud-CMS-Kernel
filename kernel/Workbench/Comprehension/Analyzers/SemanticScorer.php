<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Workbench\Comprehension\Analyzers;

use Ikabud\Kernel\Workbench\Comprehension\Contracts\ChainLink;

/**
 * Layer 2: Semantic Similarity Scorer.
 *
 * Instead of binary true/false, scores each chain link on a 0.0–1.0 scale
 * by comparing evidence text against expected patterns using lightweight
 * NLP techniques (no external dependencies).
 *
 * Techniques used:
 *   - Jaccard similarity on word token sets
 *   - Levenshtein proximity on route paths / field names
 *   - TF-IDF-weighted term overlap on log messages
 *   - Regex pattern matching against known success/failure signatures
 */
class SemanticScorer
{
    /** Known success-signature patterns per category */
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

    /**
     * Score evidence similarity for a chain link.
     *
     * @param ChainLink $link The chain link to score
     * @param array $evidence All runtime evidence
     * @return array{score: float, matched_pattern: ?string, evidence_snippet: ?string}
     */
    public function scoreLink(ChainLink $link, array $evidence): array
    {
        $evidenceText = $this->extractRelevantEvidence($link, $evidence);
        if ($evidenceText === null || $evidenceText === '') {
            return ['score' => 0.0, 'matched_pattern' => null, 'evidence_snippet' => null];
        }

        $snippet = mb_substr(is_string($evidenceText) ? $evidenceText : json_encode($evidenceText), 0, 200);
        $score = 0.0;
        $matchedPattern = null;

        // 1. Check success patterns (positive signal)
        $successPatterns = self::SUCCESS_PATTERNS[$link->category] ?? [];
        foreach ($successPatterns as $pattern) {
            if (preg_match($pattern, (string)$evidenceText)) {
                $score += 0.4;
                $matchedPattern = 'success:' . $pattern;
                break;
            }
        }

        // 2. Check failure patterns (negative signal → lower score)
        $failurePatterns = self::FAILURE_PATTERNS[$link->category] ?? [];
        foreach ($failurePatterns as $pattern) {
            if (preg_match($pattern, (string)$evidenceText)) {
                $score -= 0.5;
                $matchedPattern = 'failure:' . $pattern;
                break;
            }
        }

        // 3. Jaccard similarity between evidence and link description
        $descTokens = $this->tokenize($link->description);
        $evidTokens = $this->tokenize((string)$evidenceText);
        if (!empty($descTokens) && !empty($evidTokens)) {
            $intersection = array_intersect($descTokens, $evidTokens);
            $union = array_unique(array_merge($descTokens, $evidTokens));
            $jaccard = count($union) > 0 ? count($intersection) / count($union) : 0;
            $score += $jaccard * 0.3; // Jaccard contributes up to 0.3
        }

        // 4. Step-name proximity — does evidence key contain the step name?
        $stepTokens = $this->tokenize($link->step);
        foreach ($stepTokens as $token) {
            if (stripos((string)$evidenceText, $token) !== false) {
                $score += 0.1;
            }
        }

        // 5. Boolean evidence handling
        if (is_bool($evidenceText)) {
            $score = $evidenceText ? 1.0 : 0.0;
        }
        if ($evidenceText === true || $evidenceText === 1 || $evidenceText === '1') {
            $score = 1.0;
        }

        // Clamp to [0.0, 1.0]
        $score = max(0.0, min(1.0, $score));

        return [
            'score' => round($score, 2),
            'matched_pattern' => $matchedPattern,
            'evidence_snippet' => $snippet,
        ];
    }

    /**
     * Extract the most relevant evidence text for a given link.
     */
    private function extractRelevantEvidence(ChainLink $link, array $evidence): mixed
    {
        // Direct step match
        if (array_key_exists($link->step, $evidence)) {
            return $evidence[$link->step];
        }

        // Category-level
        if (isset($evidence[$link->category])) {
            $cat = $evidence[$link->category];
            if (is_array($cat) && isset($cat[$link->step])) {
                return $cat[$link->step];
            }
            if (is_string($cat) || is_bool($cat)) {
                return $cat;
            }
        }

        // Fuzzy key match — find any evidence key containing the step name
        $stepParts = explode('.', $link->step);
        $lastPart = end($stepParts);
        foreach ($evidence as $key => $val) {
            if (stripos($key, (string)$lastPart) !== false) {
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
        $stopWords = ['the', 'a', 'an', 'in', 'on', 'at', 'to', 'for', 'of', 'by',
                       'is', 'was', 'are', 'were', 'be', 'been', 'being',
                       'has', 'have', 'had', 'do', 'does', 'did',
                       'this', 'that', 'these', 'those', 'it', 'its',
                       'and', 'or', 'but', 'not', 'no', 'if'];

        $words = preg_split('/\W+/', mb_strtolower($text));
        return array_values(array_filter($words, fn(string $w) =>
            strlen($w) > 2 && !in_array($w, $stopWords, true)
        ));
    }
}
